<?php
require_once 'config/database.php';
require_once 'MercadoPagoSettings.php';

class MercadoPago {
    private $db;
    private $mercadoPagoSettings;
    
    /**
     * Construtor da classe
     * Inicializa a conexão com o banco de dados e as configurações do Mercado Pago
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->mercadoPagoSettings = new MercadoPagoSettings();
        $this->createPaymentTable();
    }
    
    /**
     * Criar tabela de pagamentos se não existir
     */
    private function createPaymentTable() {
        $sql = "
        CREATE TABLE IF NOT EXISTS mercadopago_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            payment_id VARCHAR(255),
            preference_id VARCHAR(255) NOT NULL,
            external_reference VARCHAR(255),
            status VARCHAR(50) NOT NULL,
            status_detail VARCHAR(255),
            payment_method VARCHAR(50),
            payment_type VARCHAR(50),
            transaction_amount DECIMAL(10, 2) NOT NULL,
            payment_date TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_preference_id (preference_id),
            INDEX idx_payment_id (payment_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        );
        ";
        
        try {
            $this->db->exec($sql);
        } catch (PDOException $e) {
            error_log("Erro ao criar tabela de pagamentos: " . $e->getMessage());
        }
    }
    
    /**
     * Criar um pagamento para assinatura no Mercado Pago
     * 
     * @param int $userId ID do usuário
     * @param float $amount Valor do pagamento
     * @param int $months Número de meses de assinatura
     * @return array Resultado da operação
     */
    public function createSubscriptionPayment($userId, $amount, $months = 1) {
        try {
            // Buscar configurações do admin (ID 1)
            $adminSettings = $this->mercadoPagoSettings->getSettings(1);
            
            if (!$adminSettings || empty($adminSettings['access_token'])) {
                return [
                    'success' => false, 
                    'message' => 'Configurações do Mercado Pago não encontradas'
                ];
            }
            
            $accessToken = $adminSettings['access_token'];
            
            // Buscar dados do usuário
            $stmt = $this->db->prepare("SELECT username, email FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            if (!$userData) {
                return [
                    'success' => false, 
                    'message' => 'Usuário não encontrado'
                ];
            }
            
            $username = $userData['username'];
            $userEmail = $userData['email'] ?? "usuario{$userId}@futbanner.com";
            
            // Criar referência externa única
            $externalReference = "USER_{$userId}_" . time();
            
            // Descrição do pagamento
            $description = "Assinatura FutBanner - {$months} " . ($months > 1 ? "meses" : "mês") . " - Usuário: {$username}";
            
            // Criar pagamento Pix
            $url = "https://api.mercadopago.com/v1/payments";
            
            $paymentData = [
                "transaction_amount" => floatval($amount),
                "description" => $description,
                "external_reference" => $externalReference,
                "payment_method_id" => "pix",
                "payer" => [
                    "email" => $userEmail,
                    "first_name" => $username,
                    "last_name" => "FutBanner"
                ],
                "date_of_expiration" => date('Y-m-d\TH:i:s.000P', strtotime('+1 day'))
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($paymentData),
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($response === false) {
                return [
                    'success' => false, 
                    'message' => 'Erro na conexão com o Mercado Pago: ' . $error
                ];
            }
            
            $payment = json_decode($response, true);
            
            if ($httpCode !== 201) {
                $errorMessage = isset($payment['message']) ? $payment['message'] : 'Erro desconhecido';
                return [
                    'success' => false, 
                    'message' => 'Erro ao criar pagamento: ' . $errorMessage,
                    'http_code' => $httpCode
                ];
            }
            
            if (!isset($payment['id']) || !isset($payment['point_of_interaction']['transaction_data']['qr_code_base64'])) {
                return [
                    'success' => false, 
                    'message' => 'Resposta inválida do Mercado Pago'
                ];
            }
            
            // Extrair dados do QR Code
            $qrCodeBase64 = $payment['point_of_interaction']['transaction_data']['qr_code_base64'];
            $qrCode = "data:image/png;base64," . $qrCodeBase64;
            
            // Registrar o pagamento no banco de dados
            $stmt = $this->db->prepare("
                INSERT INTO mercadopago_payments 
                (user_id, payment_id, preference_id, external_reference, status, transaction_amount) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $payment['id'],
                $payment['id'], // Usando payment_id como preference_id
                $externalReference,
                $payment['status'],
                $amount
            ]);
            
            return [
                'success' => true,
                'payment_id' => $payment['id'],
                'qr_code' => $qrCode,
                'amount' => $amount
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao criar pagamento: " . $e->getMessage());
            return [
                'success' => false, 
                'message' => 'Erro interno: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Criar um pagamento para compra de créditos no Mercado Pago
     * 
     * @param int $userId ID do usuário
     * @param string $description Descrição do pagamento
     * @param float $amount Valor do pagamento
     * @return array Resultado da operação
     */
    public function createCreditPayment($userId, $description, $amount) {
        try {
            // Buscar configurações do admin (ID 1)
            $adminSettings = $this->mercadoPagoSettings->getSettings(1);
            
            if (!$adminSettings || empty($adminSettings['access_token'])) {
                return [
                    'success' => false, 
                    'message' => 'Configurações do Mercado Pago não encontradas'
                ];
            }
            
            $accessToken = $adminSettings['access_token'];
            
            // Buscar dados do usuário
            $stmt = $this->db->prepare("SELECT username, email FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            if (!$userData) {
                return [
                    'success' => false, 
                    'message' => 'Usuário não encontrado'
                ];
            }
            
            $username = $userData['username'];
            $userEmail = $userData['email'] ?? "usuario{$userId}@futbanner.com";
            
            // Criar referência externa única
            $externalReference = "CREDIT_{$userId}_" . time();
            
            // Criar pagamento Pix
            $url = "https://api.mercadopago.com/v1/payments";
            
            $paymentData = [
                "transaction_amount" => floatval($amount),
                "description" => $description,
                "external_reference" => $externalReference,
                "payment_method_id" => "pix",
                "payer" => [
                    "email" => $userEmail,
                    "first_name" => $username,
                    "last_name" => "FutBanner"
                ],
                "date_of_expiration" => date('Y-m-d\TH:i:s.000P', strtotime('+1 day'))
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($paymentData),
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($response === false) {
                return [
                    'success' => false, 
                    'message' => 'Erro na conexão com o Mercado Pago: ' . $error
                ];
            }
            
            $payment = json_decode($response, true);
            
            if ($httpCode !== 201) {
                $errorMessage = isset($payment['message']) ? $payment['message'] : 'Erro desconhecido';
                return [
                    'success' => false, 
                    'message' => 'Erro ao criar pagamento: ' . $errorMessage,
                    'http_code' => $httpCode
                ];
            }
            
            if (!isset($payment['id']) || !isset($payment['point_of_interaction']['transaction_data']['qr_code_base64'])) {
                return [
                    'success' => false, 
                    'message' => 'Resposta inválida do Mercado Pago'
                ];
            }
            
            // Extrair dados do QR Code
            $qrCodeBase64 = $payment['point_of_interaction']['transaction_data']['qr_code_base64'];
            $qrCode = "data:image/png;base64," . $qrCodeBase64;
            
            // Registrar o pagamento no banco de dados
            $stmt = $this->db->prepare("
                INSERT INTO mercadopago_payments 
                (user_id, payment_id, preference_id, external_reference, status, transaction_amount) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $payment['id'],
                $payment['id'], // Usando payment_id como preference_id
                $externalReference,
                $payment['status'],
                $amount
            ]);
            
            return [
                'success' => true,
                'payment_id' => $payment['id'],
                'qr_code' => $qrCode,
                'amount' => $amount
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao criar pagamento para créditos: " . $e->getMessage());
            return [
                'success' => false, 
                'message' => 'Erro interno: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Verificar status de um pagamento
     * 
     * @param string $paymentId ID do pagamento
     * @return array Resultado da operação
     */
    public function checkPaymentStatus($paymentId) {
        try {
            // Buscar configurações do admin (ID 1)
            $adminSettings = $this->mercadoPagoSettings->getSettings(1);
            
            if (!$adminSettings || empty($adminSettings['access_token'])) {
                return [
                    'success' => false, 
                    'message' => 'Configurações do Mercado Pago não encontradas'
                ];
            }
            
            $accessToken = $adminSettings['access_token'];
            
            // Buscar pagamento diretamente pelo ID
            $url = "https://api.mercadopago.com/v1/payments/{$paymentId}";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken
                ],
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($response === false) {
                return [
                    'success' => false, 
                    'message' => 'Erro na conexão com o Mercado Pago: ' . $error
                ];
            }
            
            if ($httpCode !== 200) {
                return [
                    'success' => false, 
                    'message' => 'Erro ao buscar pagamento: ' . $response,
                    'http_code' => $httpCode
                ];
            }
            
            $payment = json_decode($response, true);
            
            // Atualizar o status do pagamento no banco de dados
            $stmt = $this->db->prepare("
                UPDATE mercadopago_payments 
                SET 
                    status = ?, 
                    status_detail = ?, 
                    payment_method = ?, 
                    payment_type = ?, 
                    payment_date = ?
                WHERE payment_id = ?
            ");
            
            $paymentDate = isset($payment['date_approved']) ? $payment['date_approved'] : $payment['date_created'];
            
            $stmt->execute([
                $payment['status'],
                $payment['status_detail'] ?? null,
                $payment['payment_method_id'] ?? null,
                $payment['payment_type_id'] ?? null,
                date('Y-m-d H:i:s', strtotime($paymentDate)),
                $paymentId
            ]);
            
            return [
                'success' => true,
                'status' => $payment['status'],
                'status_detail' => $payment['status_detail'] ?? null,
                'payment_method' => $payment['payment_method_id'] ?? null,
                'payment_type' => $payment['payment_type_id'] ?? null,
                'date' => $paymentDate
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao verificar status do pagamento: " . $e->getMessage());
            return [
                'success' => false, 
                'message' => 'Erro interno: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obter histórico de pagamentos de um usuário
     * 
     * @param int $userId ID do usuário
     * @param int $limit Limite de registros
     * @return array Lista de pagamentos
     */
    public function getUserPaymentHistory($userId, $limit = 5) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    id,
                    payment_id,
                    preference_id,
                    status,
                    transaction_amount,
                    payment_date,
                    created_at
                FROM mercadopago_payments 
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Erro ao buscar histórico de pagamentos: " . $e->getMessage());
            return [];
        }
    }
}
?>