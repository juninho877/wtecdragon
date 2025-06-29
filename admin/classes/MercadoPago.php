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
            payment_purpose VARCHAR(50) DEFAULT 'subscription',
            related_quantity INT DEFAULT 1,
            is_processed BOOLEAN DEFAULT FALSE,
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
                (user_id, payment_id, preference_id, external_reference, status, transaction_amount, payment_purpose, related_quantity) 
                VALUES (?, ?, ?, ?, ?, ?, 'subscription', ?)
            ");
            
            $stmt->execute([
                $userId,
                $payment['id'],
                $payment['id'], // Usando payment_id como preference_id
                $externalReference,
                $payment['status'],
                $amount,
                $months
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
     * @param int $credits Quantidade de créditos sendo comprados
     * @return array Resultado da operação
     */
    public function createCreditPayment($userId, $description, $amount, $credits = 1) {
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
                (user_id, payment_id, preference_id, external_reference, status, transaction_amount, payment_purpose, related_quantity) 
                VALUES (?, ?, ?, ?, ?, ?, 'credit_purchase', ?)
            ");
            
            $stmt->execute([
                $userId,
                $payment['id'],
                $payment['id'], // Usando payment_id como preference_id
                $externalReference,
                $payment['status'],
                $amount,
                $credits
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
            
            // Buscar informações do pagamento no banco de dados
            $stmt = $this->db->prepare("
                SELECT payment_purpose, related_quantity, is_processed
                FROM mercadopago_payments 
                WHERE payment_id = ?
            ");
            $stmt->execute([$paymentId]);
            $paymentInfo = $stmt->fetch();
            
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
            
            // Processar pagamento aprovado se ainda não foi processado
            if ($payment['status'] === 'approved' && $paymentInfo && !$paymentInfo['is_processed']) {
                // Buscar o usuário associado a este pagamento
                $stmt = $this->db->prepare("
                    SELECT user_id FROM mercadopago_payments 
                    WHERE payment_id = ?
                ");
                $stmt->execute([$paymentId]);
                $paymentData = $stmt->fetch();
                
                if ($paymentData) {
                    $userId = $paymentData['user_id'];
                    
                    // Processar com base no tipo de pagamento
                    if ($paymentInfo['payment_purpose'] === 'subscription') {
                        // Renovar acesso do usuário
                        $this->renewUserAccess($userId, $paymentInfo['related_quantity']);
                    } elseif ($paymentInfo['payment_purpose'] === 'credit_purchase') {
                        // Adicionar créditos ao usuário
                        require_once 'User.php';
                        $user = new User();
                        $user->purchaseCredits($userId, $paymentInfo['related_quantity'], $paymentId);
                    }
                    
                    // Marcar como processado
                    $stmt = $this->db->prepare("
                        UPDATE mercadopago_payments 
                        SET is_processed = TRUE
                        WHERE payment_id = ?
                    ");
                    $stmt->execute([$paymentId]);
                }
            }
            
            return [
                'success' => true,
                'status' => $payment['status'],
                'status_detail' => $payment['status_detail'] ?? null,
                'payment_method' => $payment['payment_method_id'] ?? null,
                'payment_type' => $payment['payment_type_id'] ?? null,
                'date' => $paymentDate,
                'payment_purpose' => $paymentInfo['payment_purpose'] ?? 'subscription',
                'related_quantity' => $paymentInfo['related_quantity'] ?? 1,
                'is_processed' => $paymentInfo['is_processed'] ?? false
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
     * Renovar acesso do usuário
     * 
     * @param int $userId ID do usuário
     * @param int $months Número de meses a adicionar
     * @return bool Sucesso da operação
     */
    public function renewUserAccess($userId, $months = 1) {
        try {
            // Buscar dados atuais do usuário
            $stmt = $this->db->prepare("
                SELECT expires_at FROM usuarios 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            // Calcular nova data de expiração
            $newExpiryDate = new DateTime();
            
            // Se o usuário já tem uma data de expiração e ela é futura, adicionar meses a partir dela
            if ($userData && !empty($userData['expires_at'])) {
                $currentExpiry = new DateTime($userData['expires_at']);
                $today = new DateTime();
                
                if ($currentExpiry > $today) {
                    $newExpiryDate = $currentExpiry;
                }
            }
            
            // Adicionar meses
            $newExpiryDate->modify("+{$months} months");
            
            // Atualizar usuário
            $stmt = $this->db->prepare("
                UPDATE usuarios 
                SET 
                    status = 'active',
                    expires_at = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $newExpiryDate->format('Y-m-d'),
                $userId
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Erro ao renovar acesso do usuário: " . $e->getMessage());
            return false;
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
                    payment_purpose,
                    related_quantity,
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