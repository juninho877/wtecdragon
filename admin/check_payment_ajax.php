<?php
session_start();
if (!isset($_SESSION["usuario"])) {
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
$months = isset($_POST['months']) ? intval($_POST['months']) : 1;
$userId = $_SESSION['user_id'];

try {
    $user = new User();
    $mercadoPago = new MercadoPago();
    $userData = $user->getUserById($userId);
    
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
            $response['message'] = "Pagamento já foi processado anteriormente. Sua assinatura já foi renovada.";
        } else {
            // Se o usuário já estiver expirado, calcular a partir da data atual
            $isExpired = false;
            if ($userData['expires_at'] && strtotime($userData['expires_at']) < time()) {
                $isExpired = true;
            }
            
            if ($isExpired || empty($userData['expires_at'])) {
                $newExpiryDate = date('Y-m-d', strtotime("+{$months} months"));
            } else {
                // Se não estiver expirado, adicionar meses à data de expiração atual
                $newExpiryDate = date('Y-m-d', strtotime($userData['expires_at'] . " +{$months} months"));
            }
            
            $updateData = [
                'username' => $userData['username'],
                'email' => $userData['email'],
                'role' => $userData['role'],
                'status' => 'active', // Garantir que o status seja ativo
                'expires_at' => $newExpiryDate
            ];
            
            $updateResult = $user->updateUser($userId, $updateData);
            
            if ($updateResult['success']) {
                $response['message'] = "Pagamento confirmado! Sua assinatura foi renovada até {$newExpiryDate}.";
                $response['new_expiry_date'] = $newExpiryDate;
                $response['should_clear_session'] = true;
            } else {
                $response['message'] = "Pagamento aprovado, mas houve um erro ao atualizar sua assinatura: " . $updateResult['message'];
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