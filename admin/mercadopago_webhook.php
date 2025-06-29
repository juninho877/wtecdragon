<?php
/**
 * Webhook para receber notificações do Mercado Pago
 * 
 * Este arquivo deve ser configurado como o endpoint de notificações no Mercado Pago:
 * https://seusite.com/admin/mercadopago_webhook.php
 */

// Configurar cabeçalhos
header('Content-Type: application/json');

// Incluir classes necessárias
require_once 'classes/MercadoPagoPayment.php';

// Obter dados da requisição
$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true);

// Verificar se os dados são válidos
if (!$data || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Dados inválidos',
        'error' => json_last_error_msg()
    ]);
    exit;
}

// Registrar a notificação para debug
$logFile = __DIR__ . '/logs/mercadopago_webhook.log';
$logDir = dirname($logFile);

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'data' => $data,
    'headers' => getallheaders()
];

file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

// Processar a notificação
try {
    $mercadoPagoPayment = new MercadoPagoPayment();
    $result = $mercadoPagoPayment->processPaymentNotification($data);
    
    if ($result['success']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $result['message']
        ]);
    } else {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor',
        'error' => $e->getMessage()
    ]);
    
    // Registrar erro
    error_log("Erro no webhook do Mercado Pago: " . $e->getMessage());
}
?>