<?php
require_once 'config/database.php';
require_once 'MercadoPagoSettings.php';

class MercadoPagoPayment {
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
     * Criar uma preferência de pagamento no Mercado Pago
     * 
     * @param int $userId ID do usuário
     * @param string $description Descrição do pagamento
     * @param float $amount Valor do pagamento
     * @return array Resultado da operação
     */
    public function createPaymentPreference($userId, $description, $amount) {
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
            $stmt = $this->db->prepare("SELECT email FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            $userEmail = $userData['email'] ?? "usuario{$userId}@futbanner.com";
            
            // Criar referência externa única
            $externalReference = "USER_{$userId}_" . time();
            
            // Criar preferência de pagamento
            $url = "https://api.mercadopago.com/checkout/preferences";
            
            $preferenceData = [
                "items" => [
                    [
                        "title" => $description,
                        "quantity" => 1,
                        "currency_id" => "BRL",
                        "unit_price" => floatval($amount)
                    ]
                ],
                "payer" => [
                    "email" => $userEmail
                ],
                "payment_methods" => [
                    "excluded_payment_types" => [
                        ["id" => "credit_card"],
                        ["id" => "debit_card"],
                        ["id" => "bank_transfer"]
                    ]
                ],
                "external_reference" => $externalReference,
                "statement_descriptor" => "FUTBANNER",
                "expires" => true,
                "expiration_date_to" => (new DateTime())->modify('+1 day')->format('c')
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
                CURLOPT_POSTFIELDS => json_encode($preferenceData),
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
            
            if ($httpCode !== 201) {
                return [
                    'success' => false, 
                    'message' => 'Erro ao criar preferência de pagamento: ' . $response,
                    'http_code' => $httpCode
                ];
            }
            
            $preference = json_decode($response, true);
            
            if (!isset($preference['id'])) {
                return [
                    'success' => false, 
                    'message' => 'Resposta inválida do Mercado Pago'
                ];
            }
            
            // Registrar a preferência no banco de dados
            $stmt = $this->db->prepare("
                INSERT INTO mercadopago_payments 
                (user_id, preference_id, external_reference, status, transaction_amount) 
                VALUES (?, ?, ?, 'pending', ?)
            ");
            
            $stmt->execute([
                $userId,
                $preference['id'],
                $externalReference,
                $amount
            ]);
            
            // Obter QR Code
            $qrCodeUrl = "https://api.mercadopago.com/checkout/preferences/{$preference['id']}/qr";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $qrCodeUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $qrResponse = curl_exec($ch);
            curl_close($ch);
            
            $qrData = json_decode($qrResponse, true);
            
            return [
                'success' => true,
                'preference_id' => $preference['id'],
                'external_reference' => $externalReference,
                'qr_code_base64' => $qrData['qr_data_base64'] ?? '',
                'qr_code' => $qrData['qr_data'] ?? '',
                'amount' => $amount
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao criar preferência de pagamento: " . $e->getMessage());
            return [
                'success' => false, 
                'message' => 'Erro interno: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Verificar status de um pagamento
     * 
     * @param string $preferenceId ID da preferência de pagamento
     * @return array Resultado da operação
     */
    public function checkPaymentStatus($preferenceId) {
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
            
            // Buscar pagamentos associados à preferência
            $url = "https://api.mercadopago.com/v1/payments/search?preference_id={$preferenceId}";
            
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
                    'message' => 'Erro ao buscar pagamentos: ' . $response,
                    'http_code' => $httpCode
                ];
            }
            
            $searchResult = json_decode($response, true);
            
            if (!isset($searchResult['results'])) {
                return [
                    'success' => false, 
                    'message' => 'Resposta inválida do Mercado Pago'
                ];
            }
            
            if (empty($searchResult['results'])) {
                return [
                    'success' => true,
                    'status' => 'pending',
                    'message' => 'Nenhum pagamento encontrado para esta preferência'
                ];
            }
            
            // Pegar o pagamento mais recente
            $payment = $searchResult['results'][0];
            
            // Atualizar o status do pagamento no banco de dados
            $stmt = $this->db->prepare("
                UPDATE mercadopago_payments 
                SET 
                    payment_id = ?, 
                    status = ?, 
                    status_detail = ?, 
                    payment_method = ?, 
                    payment_type = ?, 
                    payment_date = ?
                WHERE preference_id = ?
            ");
            
            $stmt->execute([
                $payment['id'],
                $payment['status'],
                $payment['status_detail'],
                $payment['payment_method_id'] ?? null,
                $payment['payment_type_id'] ?? null,
                date('Y-m-d H:i:s', strtotime($payment['date_approved'] ?? $payment['date_created'])),
                $preferenceId
            ]);
            
            // Verificar se o pagamento foi aprovado
            if ($payment['status'] === 'approved') {
                // Buscar o usuário associado a este pagamento
                $stmt = $this->db->prepare("
                    SELECT user_id FROM mercadopago_payments 
                    WHERE preference_id = ?
                ");
                $stmt->execute([$preferenceId]);
                $paymentData = $stmt->fetch();
                
                if ($paymentData) {
                    $userId = $paymentData['user_id'];
                    
                    // Renovar acesso do usuário
                    $this->renewUserAccess($userId);
                }
            }
            
            return [
                'success' => true,
                'status' => $payment['status'],
                'status_detail' => $payment['status_detail'],
                'payment_id' => $payment['id'],
                'payment_method' => $payment['payment_method_id'] ?? null,
                'payment_type' => $payment['payment_type_id'] ?? null,
                'date' => $payment['date_approved'] ?? $payment['date_created']
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
     * @return bool Sucesso da operação
     */
    public function renewUserAccess($userId) {
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
            
            // Se o usuário já tem uma data de expiração e ela é futura, adicionar 30 dias a partir dela
            if ($userData && !empty($userData['expires_at'])) {
                $currentExpiry = new DateTime($userData['expires_at']);
                $today = new DateTime();
                
                if ($currentExpiry > $today) {
                    $newExpiryDate = $currentExpiry;
                }
            }
            
            // Adicionar 30 dias
            $newExpiryDate->modify('+30 days');
            
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
    
    /**
     * Obter estatísticas de pagamentos
     * 
     * @return array Estatísticas
     */
    public function getPaymentStats() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_payments,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_payments,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_payments,
                    SUM(CASE WHEN status = 'approved' THEN transaction_amount ELSE 0 END) as total_amount,
                    COUNT(DISTINCT user_id) as total_users
                FROM mercadopago_payments
            ");
            
            $stmt->execute();
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Erro ao obter estatísticas de pagamentos: " . $e->getMessage());
            return [
                'total_payments' => 0,
                'approved_payments' => 0,
                'pending_payments' => 0,
                'rejected_payments' => 0,
                'total_amount' => 0,
                'total_users' => 0
            ];
        }
    }
    
    /**
     * Processar notificação de pagamento (webhook)
     * 
     * @param array $data Dados da notificação
     * @return array Resultado do processamento
     */
    public function processPaymentNotification($data) {
        try {
            // Verificar se é uma notificação de pagamento
            if (!isset($data['action']) || $data['action'] !== 'payment.created') {
                return [
                    'success' => false,
                    'message' => 'Tipo de notificação não suportado'
                ];
            }
            
            // Buscar configurações do admin (ID 1)
            $adminSettings = $this->mercadoPagoSettings->getSettings(1);
            
            if (!$adminSettings || empty($adminSettings['access_token'])) {
                return [
                    'success' => false, 
                    'message' => 'Configurações do Mercado Pago não encontradas'
                ];
            }
            
            $accessToken = $adminSettings['access_token'];
            
            // Obter detalhes do pagamento
            $paymentId = $data['data']['id'];
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
            curl_close($ch);
            
            if ($httpCode !== 200) {
                return [
                    'success' => false, 
                    'message' => 'Erro ao obter detalhes do pagamento',
                    'http_code' => $httpCode
                ];
            }
            
            $payment = json_decode($response, true);
            
            if (!isset($payment['preference_id'])) {
                return [
                    'success' => false, 
                    'message' => 'Resposta inválida do Mercado Pago'
                ];
            }
            
            // Buscar o pagamento no banco de dados
            $stmt = $this->db->prepare("
                SELECT user_id FROM mercadopago_payments 
                WHERE preference_id = ?
            ");
            $stmt->execute([$payment['preference_id']]);
            $paymentData = $stmt->fetch();
            
            if (!$paymentData) {
                // Pagamento não encontrado, tentar buscar pelo external_reference
                if (isset($payment['external_reference'])) {
                    $externalRef = $payment['external_reference'];
                    
                    // Formato esperado: USER_123_1234567890
                    if (preg_match('/^USER_(\d+)_/', $externalRef, $matches)) {
                        $userId = $matches[1];
                        
                        // Registrar o pagamento
                        $stmt = $this->db->prepare("
                            INSERT INTO mercadopago_payments 
                            (user_id, payment_id, preference_id, external_reference, status, status_detail, payment_method, payment_type, transaction_amount, payment_date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $userId,
                            $payment['id'],
                            $payment['preference_id'],
                            $payment['external_reference'],
                            $payment['status'],
                            $payment['status_detail'],
                            $payment['payment_method_id'] ?? null,
                            $payment['payment_type_id'] ?? null,
                            $payment['transaction_amount'],
                            date('Y-m-d H:i:s', strtotime($payment['date_approved'] ?? $payment['date_created']))
                        ]);
                        
                        // Se o pagamento foi aprovado, renovar o acesso
                        if ($payment['status'] === 'approved') {
                            $this->renewUserAccess($userId);
                        }
                        
                        return [
                            'success' => true,
                            'message' => 'Pagamento registrado com sucesso',
                            'status' => $payment['status'],
                            'user_id' => $userId
                        ];
                    }
                }
                
                return [
                    'success' => false, 
                    'message' => 'Pagamento não encontrado no sistema'
                ];
            }
            
            $userId = $paymentData['user_id'];
            
            // Atualizar o status do pagamento
            $stmt = $this->db->prepare("
                UPDATE mercadopago_payments 
                SET 
                    payment_id = ?, 
                    status = ?, 
                    status_detail = ?, 
                    payment_method = ?, 
                    payment_type = ?, 
                    payment_date = ?
                WHERE preference_id = ?
            ");
            
            $stmt->execute([
                $payment['id'],
                $payment['status'],
                $payment['status_detail'],
                $payment['payment_method_id'] ?? null,
                $payment['payment_type_id'] ?? null,
                date('Y-m-d H:i:s', strtotime($payment['date_approved'] ?? $payment['date_created'])),
                $payment['preference_id']
            ]);
            
            // Se o pagamento foi aprovado, renovar o acesso
            if ($payment['status'] === 'approved') {
                $this->renewUserAccess($userId);
            }
            
            return [
                'success' => true,
                'message' => 'Pagamento atualizado com sucesso',
                'status' => $payment['status'],
                'user_id' => $userId
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao processar notificação de pagamento: " . $e->getMessage());
            return [
                'success' => false, 
                'message' => 'Erro interno: ' . $e->getMessage()
            ];
        }
    }
}
?>