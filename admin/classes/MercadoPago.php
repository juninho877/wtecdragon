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
            error_log("MercadoPago::createSubscriptionPayment - Starting payment creation for user ID: $userId, amount: $amount, months: $months");
            
            // Buscar configurações do admin (ID 1)
            $adminSettings = $this->mercadoPagoSettings->getSettings(1);
            
            if (!$adminSettings || empty($adminSettings['access_token'])) {
                error_log("MercadoPago::createSubscriptionPayment - Admin settings or access token missing");
                return [
                    'success' => false, 
                    'message' => 'Configurações do Mercado Pago não encontradas'
                ];
            }
            
            $accessToken = $adminSettings['access_token'];
            error_log("MercadoPago::createSubscriptionPayment - Using admin access token: " . substr($accessToken, 0, 10) . "...");
            
            // Buscar dados do usuário
            $stmt = $this->db->prepare("SELECT username, email FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            if (!$userData) {
                error_log("MercadoPago::createSubscriptionPayment - User not found for ID: $userId");
                return [
                    'success' => false, 
                    'message' => 'Usuário não encontrado'
                ];
            }
            
            $username = $userData['username'];
            $userEmail = $userData['email'] ?? "usuario{$userId}@futbanner.com";
            error_log("MercadoPago::createSubscriptionPayment - User data: username=$username, email=$userEmail");
            
            // Criar referência externa única
            $externalReference = "USER_{$userId}_" . time();
            
            // Descrição do pagamento
            $description = "Assinatura FutBanner - {$months} " . ($months > 1 ? "meses" : "mês") . " - Usuário: {$username}";
            error_log("MercadoPago::createSubscriptionPayment - Payment description: $description");
            
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
            
            error_log("MercadoPago::createSubscriptionPayment - Payment data: " . json_encode($paymentData));
            
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
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_VERBOSE => true,
                CURLOPT_STDERR => $verbose = fopen('php://temp', 'w+')
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            // Get verbose information for debugging
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            error_log("MercadoPago::createSubscriptionPayment - cURL verbose log: " . $verboseLog);
            
            curl_close($ch);
            
            if ($response === false) {
                error_log("MercadoPago::createSubscriptionPayment - cURL error: $error");
                return [
                    'success' => false, 
                    'message' => 'Erro na conexão com o Mercado Pago: ' . $error
                ];
            }
            
            error_log("MercadoPago::createSubscriptionPayment - API response HTTP code: $httpCode");
            error_log("MercadoPago::createSubscriptionPayment - API response: $response");
            
            $payment = json_decode($response, true);
            error_log("MercadoPago::createSubscriptionPayment - Decoded payment response: " . print_r($payment, true));
            
            if ($httpCode !== 201) {
                $errorMessage = isset($payment['message']) ? $payment['message'] : 'Erro desconhecido';
                error_log("MercadoPago::createSubscriptionPayment - Error creating payment: $errorMessage");
                return [
                    'success' => false, 
                    'message' => 'Erro ao criar pagamento: ' . $errorMessage,
                    'http_code' => $httpCode
                ];
            }
            
            if (!isset($payment['id']) || !isset($payment['point_of_interaction']['transaction_data']['qr_code_base64'])) {
                error_log("MercadoPago::createSubscriptionPayment - Invalid response structure. Missing required fields.");
                error_log("MercadoPago::createSubscriptionPayment - Payment ID exists: " . (isset($payment['id']) ? 'Yes' : 'No'));
                error_log("MercadoPago::createSubscriptionPayment - QR code exists: " . (isset($payment['point_of_interaction']['transaction_data']['qr_code_base64']) ? 'Yes' : 'No'));
                
                if (isset($payment['point_of_interaction'])) {
                    error_log("MercadoPago::createSubscriptionPayment - point_of_interaction: " . json_encode($payment['point_of_interaction']));
                }
                
                return [
                    'success' => false, 
                    'message' => 'Resposta inválida do Mercado Pago'
                ];
            }
            
            // Extrair dados do QR Code
            $qrCodeBase64 = $payment['point_of_interaction']['transaction_data']['qr_code_base64'];
            $qrCode = "data:image/png;base64," . $qrCodeBase64;
            
            error_log("MercadoPago::createSubscriptionPayment - QR code generated successfully");
            
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
            
            error_log("MercadoPago::createSubscriptionPayment - Payment record saved to database");
            
            $result = [
                'success' => true,
                'payment_id' => $payment['id'],
                'qr_code' => $qrCode,
                'amount' => $amount
            ];
            
            error_log("MercadoPago::createSubscriptionPayment - Returning success result: " . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            error_log("MercadoPago::createSubscriptionPayment - Exception: " . $e->getMessage());
            error_log("MercadoPago::createSubscriptionPayment - Stack trace: " . $e->getTraceAsString());
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
            error_log("MercadoPago::createCreditPayment - Starting credit payment creation for user ID: $userId, amount: $amount, credits: $credits");
            
            // Buscar configurações do admin (ID 1)
            $adminSettings = $this->mercadoPagoSettings->getSettings(1);
            
            if (!$adminSettings || empty($adminSettings['access_token'])) {
                error_log("MercadoPago::createCreditPayment - Admin settings or access token missing");
                return [
                    'success' => false, 
                    'message' => 'Configurações do Mercado Pago não encontradas'
                ];
            }
            
            $accessToken = $adminSettings['access_token'];
            error_log("MercadoPago::createCreditPayment - Using admin access token: " . substr($accessToken, 0, 10) . "...");
            
            // Buscar dados do usuário
            $stmt = $this->db->prepare("SELECT username, email FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            
            if (!$userData) {
                error_log("MercadoPago::createCreditPayment - User not found for ID: $userId");
                return [
                    'success' => false, 
                    'message' => 'Usuário não encontrado'
                ];
            }
            
            $username = $userData['username'];
            $userEmail = $userData['email'] ?? "usuario{$userId}@futbanner.com";
            error_log("MercadoPago::createCreditPayment - User data: username=$username, email=$userEmail");
            
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
            
            error_log("MercadoPago::createCreditPayment - Payment data: " . json_encode($paymentData));
            
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
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_VERBOSE => true,
                CURLOPT_STDERR => $verbose = fopen('php://temp', 'w+')
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            // Get verbose information for debugging
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            error_log("MercadoPago::createCreditPayment - cURL verbose log: " . $verboseLog);
            
            curl_close($ch);
            
            if ($response === false) {
                error_log("MercadoPago::createCreditPayment - cURL error: $error");
                return [
                    'success' => false, 
                    'message' => 'Erro na conexão com o Mercado Pago: ' . $error
                ];
            }
            
            error_log("MercadoPago::createCreditPayment - API response HTTP code: $httpCode");
            error_log("MercadoPago::createCreditPayment - API response: $response");
            
            $payment = json_decode($response, true);
            error_log("MercadoPago::createCreditPayment - Decoded payment response: " . print_r($payment, true));
            
            if ($httpCode !== 201) {
                $errorMessage = isset($payment['message']) ? $payment['message'] : 'Erro desconhecido';
                error_log("MercadoPago::createCreditPayment - Error creating payment: $errorMessage");
                return [
                    'success' => false, 
                    'message' => 'Erro ao criar pagamento: ' . $errorMessage,
                    'http_code' => $httpCode
                ];
            }
            
            if (!isset($payment['id']) || !isset($payment['point_of_interaction']['transaction_data']['qr_code_base64'])) {
                error_log("MercadoPago::createCreditPayment - Invalid response structure. Missing required fields.");
                error_log("MercadoPago::createCreditPayment - Payment ID exists: " . (isset($payment['id']) ? 'Yes' : 'No'));
                error_log("MercadoPago::createCreditPayment - QR code exists: " . (isset($payment['point_of_interaction']['transaction_data']['qr_code_base64']) ? 'Yes' : 'No'));
                
                if (isset($payment['point_of_interaction'])) {
                    error_log("MercadoPago::createCreditPayment - point_of_interaction: " . json_encode($payment['point_of_interaction']));
                }
                
                return [
                    'success' => false, 
                    'message' => 'Resposta inválida do Mercado Pago'
                ];
            }
            
            // Extrair dados do QR Code
            $qrCodeBase64 = $payment['point_of_interaction']['transaction_data']['qr_code_base64'];
            $qrCode = "data:image/png;base64," . $qrCodeBase64;
            
            error_log("MercadoPago::createCreditPayment - QR code generated successfully");
            
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
            
            error_log("MercadoPago::createCreditPayment - Payment record saved to database");
            
            $result = [
                'success' => true,
                'payment_id' => $payment['id'],
                'qr_code' => $qrCode,
                'amount' => $amount
            ];
            
            error_log("MercadoPago::createCreditPayment - Returning success result: " . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            error_log("MercadoPago::createCreditPayment - Exception: " . $e->getMessage());
            error_log("MercadoPago::createCreditPayment - Stack trace: " . $e->getTraceAsString());
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
            error_log("MercadoPago::checkPaymentStatus - Checking payment status for ID: $paymentId");
            
            // Buscar configurações do admin (ID 1)
            $adminSettings = $this->mercadoPagoSettings->getSettings(1);
            
            if (!$adminSettings || empty($adminSettings['access_token'])) {
                error_log("MercadoPago::checkPaymentStatus - Admin settings or access token missing");
                return [
                    'success' => false, 
                    'message' => 'Configurações do Mercado Pago não encontradas'
                ];
            }
            
            $accessToken = $adminSettings['access_token'];
            error_log("MercadoPago::checkPaymentStatus - Using admin access token: " . substr($accessToken, 0, 10) . "...");
            
            // Buscar pagamento diretamente pelo ID
            $url = "https://api.mercadopago.com/v1/payments/{$paymentId}";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_VERBOSE => true,
                CURLOPT_STDERR => $verbose = fopen('php://temp', 'w+')
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            // Get verbose information for debugging
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            error_log("MercadoPago::checkPaymentStatus - cURL verbose log: " . $verboseLog);
            
            curl_close($ch);
            
            if ($response === false) {
                error_log("MercadoPago::checkPaymentStatus - cURL error: $error");
                return [
                    'success' => false, 
                    'message' => 'Erro na conexão com o Mercado Pago: ' . $error
                ];
            }
            
            error_log("MercadoPago::checkPaymentStatus - API response HTTP code: $httpCode");
            error_log("MercadoPago::checkPaymentStatus - API response: $response");
            
            if ($httpCode !== 200) {
                error_log("MercadoPago::checkPaymentStatus - Error fetching payment: HTTP $httpCode");
                return [
                    'success' => false, 
                    'message' => 'Erro ao buscar pagamento: ' . $response,
                    'http_code' => $httpCode
                ];
            }
            
            $payment = json_decode($response, true);
            error_log("MercadoPago::checkPaymentStatus - Decoded payment response: " . print_r($payment, true));
            
            return [
                'success' => true,
                'payment' => $payment
            ];
        } catch (Exception $e) {
            error_log("MercadoPago::checkPaymentStatus - Exception: " . $e->getMessage());
            error_log("MercadoPago::checkPaymentStatus - Stack trace: " . $e->getTraceAsString());
            return [
                'success' => false, 
                'message' => 'Erro interno: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obter histórico de pagamentos de um usuário ou lista de usuários
     * 
     * @param int|array $userIds ID do usuário ou array de IDs de usuários
     * @param int $limit Limite de registros
     * @return array Lista de pagamentos
     */
    public function getUserPaymentHistory($userIds, $limit = 5) {
        try {
            error_log("MercadoPago::getUserPaymentHistory - Getting payment history for user(s): " . (is_array($userIds) ? implode(',', $userIds) : $userIds));
            
            // Verificar se é um único ID ou um array de IDs
            $isArray = is_array($userIds);
            
            if ($isArray && empty($userIds)) {
                error_log("MercadoPago::getUserPaymentHistory - Empty user IDs array provided");
                return [];
            }
            
            // Construir a consulta SQL com base no tipo de entrada
            if ($isArray) {
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $sql = "
                    SELECT 
                        id,
                        user_id,
                        payment_id,
                        preference_id,
                        status,
                        transaction_amount,
                        payment_purpose,
                        related_quantity,
                        payment_date,
                        created_at
                    FROM mercadopago_payments 
                    WHERE user_id IN ({$placeholders})
                    ORDER BY created_at DESC
                    LIMIT ?
                ";
                $params = array_merge($userIds, [$limit]);
            } else {
                $sql = "
                    SELECT 
                        id,
                        user_id,
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
                ";
                $params = [$userIds, $limit];
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll();
            
            error_log("MercadoPago::getUserPaymentHistory - Found " . count($result) . " payment records");
            return $result;
        } catch (Exception $e) {
            error_log("MercadoPago::getUserPaymentHistory - Exception: " . $e->getMessage());
            error_log("MercadoPago::getUserPaymentHistory - Stack trace: " . $e->getTraceAsString());
            return [];
        }
    }
}
?>