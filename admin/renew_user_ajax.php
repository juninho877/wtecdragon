<?php
session_start();
if (!isset($_SESSION["usuario"]) || $_SESSION["role"] !== 'master') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit();
}

require_once 'classes/User.php';
require_once 'classes/CreditTransaction.php';

// Verificar se os parâmetros necessários foram fornecidos
if (!isset($_POST['user_id']) || !isset($_POST['months'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
    exit();
}

$userId = intval($_POST['user_id']);
$months = intval($_POST['months']);
$masterId = $_SESSION['user_id'];

if ($months < 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'O número de meses deve ser pelo menos 1']);
    exit();
}

try {
    $user = new User();
    $creditTransaction = new CreditTransaction();
    
    // Verificar se o usuário existe e pertence ao master
    $userData = $user->getUserById($userId);
    if (!$userData || $userData['parent_user_id'] != $masterId) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado ou não pertence a você']);
        exit();
    }
    
    // Verificar se o master tem créditos suficientes
    $masterCredits = $user->getUserCredits($masterId);
    if ($masterCredits < $months) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Você não tem créditos suficientes. Necessário: {$months}, Disponível: {$masterCredits}"]);
        exit();
    }
    
    // Renovar o usuário
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();
    
    try {
        // Calcular nova data de expiração
        $stmt = $db->prepare("SELECT expires_at FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $currentExpiry = $stmt->fetchColumn();
        
        $newExpiryDate = new DateTime();
        
        // Se o usuário já tem uma data de expiração e ela é futura, adicionar meses a partir dela
        if ($currentExpiry) {
            $expiryDate = new DateTime($currentExpiry);
            $today = new DateTime();
            
            if ($expiryDate > $today) {
                $newExpiryDate = $expiryDate;
            }
        }
        
        // Adicionar meses
        $newExpiryDate->modify("+{$months} months");
        
        // Atualizar usuário
        $stmt = $db->prepare("
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
        
        // Deduzir créditos do master
        $stmt = $db->prepare("UPDATE usuarios SET credits = credits - ? WHERE id = ?");
        $stmt->execute([$months, $masterId]);
        
        // Registrar a transação
        $creditTransaction->recordTransaction(
            $masterId,
            'user_renewal',
            -$months,
            "Renovação do usuário {$userData['username']} por {$months} " . ($months > 1 ? 'meses' : 'mês'),
            $userId,
            null
        );
        
        $db->commit();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => "Usuário renovado com sucesso por {$months} " . ($months > 1 ? 'meses' : 'mês') . ". Nova data de expiração: " . $newExpiryDate->format('d/m/Y'),
            'new_expiry_date' => $newExpiryDate->format('Y-m-d'),
            'remaining_credits' => $masterCredits - $months
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Erro ao renovar usuário: ' . $e->getMessage()
    ]);
}
?>