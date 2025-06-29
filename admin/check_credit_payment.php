<?php
session_start();
if (!isset($_SESSION["usuario"]) || $_SESSION["role"] !== 'master') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit();
}

require_once 'classes/User.php';
require_once 'classes/MercadoPago.php';

// Verificar se os parâmetros necessários foram fornecidos
if (!isset($_POST['payment_id']) || empty($_POST['payment_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID do pagamento não fornecido']);
    exit();
}

$paymentId = $_POST['payment_id'];
$credits = isset($_POST['credits']) ? intval($_POST['credits']) : 1;
$userId = $_SESSION['user_id'];

try {
    $user = new User();
    $mercadoPago = new MercadoPago();
    
    // Verificar status do pagamento
    $result = $mercadoPago->checkPaymentStatus($paymentId);
    
    if (!$result['success']) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Erro ao verificar status do pagamento'
        ]);
        exit();
    }
    
    $response = [
        'success' => true,
        'status' => $result['status'],
        'message' => ''
    ];
    
    // Processar com base no status
    if ($result['status'] === 'approved') {
        // Verificar se o pagamento já foi processado
        if ($result['is_processed']) {
            $response['message'] = "Pagamento já foi processado anteriormente. Seus créditos já foram adicionados.";
        } else {
            // Adicionar créditos ao usuário
            $addCreditsResult = $user->purchaseCredits($userId, $credits, $paymentId);
            
            if ($addCreditsResult['success']) {
                $response['message'] = "Pagamento confirmado! {$credits} créditos foram adicionados à sua conta.";
                $response['should_clear_session'] = true;
                
                // Marcar o pagamento como processado
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("
                    UPDATE mercadopago_payments 
                    SET is_processed = TRUE
                    WHERE payment_id = ?
                ");
                $stmt->execute([$paymentId]);
            } else {
                $response['message'] = "Pagamento aprovado, mas houve um erro ao adicionar os créditos: " . $addCreditsResult['message'];
            }
        }
    } elseif ($result['status'] === 'pending') {
        $response['message'] = "Pagamento pendente. Aguardando confirmação do Mercado Pago.";
    } elseif ($result['status'] === 'rejected' || $result['status'] === 'cancelled') {
        $response['message'] = "Pagamento " . ($result['status'] === 'rejected' ? 'rejeitado' : 'cancelado') . ". Por favor, tente novamente com outro método de pagamento.";
        $response['should_clear_session'] = true;
    } else {
        $response['message'] = "Status do pagamento: " . $result['status'];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao processar pagamento: ' . $e->getMessage()
    ]);
    exit();
}
?>