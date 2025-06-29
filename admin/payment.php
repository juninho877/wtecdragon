<?php
session_start();
if (!isset($_SESSION["usuario"])) {
    header("Location: login.php");
    exit();
}

require_once 'classes/User.php';
require_once 'classes/MercadoPago.php';

$user = new User();
$mercadoPago = new MercadoPago();

$userId = $_SESSION['user_id'];
$userData = $user->getUserById($userId);

// Verificar se o usuário está expirado
$isExpired = false;
if ($userData['expires_at'] && strtotime($userData['expires_at']) < time()) {
    $isExpired = true;
}

// Verificar se há um pagamento em andamento
$paymentInProgress = isset($_SESSION['payment_qr_code']) && !empty($_SESSION['payment_qr_code']);
$paymentCreatedAt = isset($_SESSION['payment_created_at']) ? $_SESSION['payment_created_at'] : null;

// Verificar se o QR code expirou (aumentado para 30 minutos)
$qrCodeExpired = false;
if ($paymentCreatedAt && (time() - $paymentCreatedAt > 1800)) { // 30 minutos em segundos
    $qrCodeExpired = true;
    // Limpar dados do pagamento expirado
    unset($_SESSION['payment_qr_code']);
    unset($_SESSION['payment_created_at']);
    unset($_SESSION['payment_id']);
    $paymentInProgress = false;
}

// Processar solicitação de pagamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_payment') {
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 30.00; // Valor padrão
        $months = isset($_POST['months']) ? intval($_POST['months']) : 1; // Meses padrão
        
        // Calcular valor total
        $totalAmount = $amount * $months;
        
        // Criar pagamento
        $result = $mercadoPago->createPayment($userId, $totalAmount, $months);
        
        if ($result['success']) {
            $_SESSION['payment_qr_code'] = $result['qr_code'];
            $_SESSION['payment_id'] = $result['payment_id'];
            $_SESSION['payment_created_at'] = time();
            $_SESSION['payment_months'] = $months;
            $_SESSION['payment_amount'] = $totalAmount;
            
            // Redirecionar para evitar reenvio do formulário
            header('Location: payment.php');
            exit;
        } else {
            $errorMessage = $result['message'];
        }
    } elseif ($_POST['action'] === 'check_payment') {
        if (isset($_SESSION['payment_id'])) {
            $result = $mercadoPago->checkPaymentStatus($_SESSION['payment_id']);
            
            if ($result['success'] && $result['status'] === 'approved') {
                // Atualizar data de expiração do usuário
                $months = $_SESSION['payment_months'] ?? 1;
                
                // Se o usuário já estiver expirado, calcular a partir da data atual
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
                    // Limpar dados do pagamento
                    unset($_SESSION['payment_qr_code']);
                    unset($_SESSION['payment_created_at']);
                    unset($_SESSION['payment_id']);
                    unset($_SESSION['payment_months']);
                    unset($_SESSION['payment_amount']);
                    
                    // Definir mensagem de sucesso
                    $_SESSION['payment_success'] = true;
                    $_SESSION['payment_message'] = "Pagamento confirmado! Sua assinatura foi renovada até {$newExpiryDate}.";
                    
                    // Redirecionar para evitar reenvio do formulário
                    header('Location: payment.php');
                    exit;
                } else {
                    $errorMessage = "Pagamento aprovado, mas houve um erro ao atualizar sua assinatura: " . $updateResult['message'];
                }
            } elseif ($result['success'] && $result['status'] === 'pending') {
                $infoMessage = "Pagamento pendente. Aguardando confirmação do Mercado Pago.";
            } elseif ($result['success'] && $result['status'] === 'rejected') {
                $errorMessage = "Pagamento rejeitado. Por favor, tente novamente com outro método de pagamento.";
                
                // Limpar dados do pagamento rejeitado
                unset($_SESSION['payment_qr_code']);
                unset($_SESSION['payment_created_at']);
                unset($_SESSION['payment_id']);
            } else {
                $errorMessage = $result['message'] ?? "Erro ao verificar status do pagamento.";
            }
        } else {
            $errorMessage = "Nenhum pagamento em andamento.";
        }
    } elseif ($_POST['action'] === 'cancel_payment') {
        // Limpar dados do pagamento
        unset($_SESSION['payment_qr_code']);
        unset($_SESSION['payment_created_at']);
        unset($_SESSION['payment_id']);
        unset($_SESSION['payment_months']);
        unset($_SESSION['payment_amount']);
        
        // Redirecionar para evitar reenvio do formulário
        header('Location: payment.php');
        exit;
    }
}

// Verificar se há mensagem de sucesso
$successMessage = '';
if (isset($_SESSION['payment_success']) && $_SESSION['payment_success']) {
    $successMessage = $_SESSION['payment_message'] ?? "Pagamento confirmado com sucesso!";
    unset($_SESSION['payment_success']);
    unset($_SESSION['payment_message']);
}

$pageTitle = "Pagamento";
include "includes/header.php";
?>

<div class="page-header">
    <h1 class="page-title">
        <i class="fas fa-credit-card text-primary-500 mr-3"></i>
        <?php echo $isExpired ? 'Renovar Assinatura' : 'Gerenciar Assinatura'; ?>
    </h1>
    <p class="page-subtitle">
        <?php echo $isExpired ? 'Sua assinatura expirou. Renove para continuar usando o sistema.' : 'Gerencie sua assinatura e métodos de pagamento'; ?>
    </p>
</div>

<?php if ($isExpired): ?>
<div class="alert alert-warning mb-6">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
        <p class="font-medium">Sua assinatura expirou em <?php echo date('d/m/Y', strtotime($userData['expires_at'])); ?></p>
        <p class="text-sm mt-1">Renove sua assinatura para continuar utilizando o sistema.</p>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($successMessage)): ?>
<div class="alert alert-success mb-6">
    <i class="fas fa-check-circle"></i>
    <div>
        <p class="font-medium">Pagamento Confirmado!</p>
        <p class="text-sm mt-1"><?php echo $successMessage; ?></p>
    </div>
</div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
<div class="alert alert-error mb-6">
    <i class="fas fa-exclamation-circle"></i>
    <div>
        <p class="font-medium">Erro no Pagamento</p>
        <p class="text-sm mt-1"><?php echo $errorMessage; ?></p>
    </div>
</div>
<?php endif; ?>

<?php if (isset($infoMessage)): ?>
<div class="alert alert-info mb-6">
    <i class="fas fa-info-circle"></i>
    <div>
        <p class="font-medium">Informação</p>
        <p class="text-sm mt-1"><?php echo $infoMessage; ?></p>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Informações da Assinatura -->
    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Informações da Assinatura</h3>
                <p class="card-subtitle">Detalhes da sua assinatura atual</p>
            </div>
            <div class="card-body">
                <div class="subscription-info">
                    <div class="subscription-status <?php echo $isExpired ? 'expired' : 'active'; ?>">
                        <div class="status-icon">
                            <i class="fas fa-<?php echo $isExpired ? 'times' : 'check'; ?>"></i>
                        </div>
                        <div class="status-text">
                            <h4><?php echo $isExpired ? 'Assinatura Expirada' : 'Assinatura Ativa'; ?></h4>
                            <p>
                                <?php 
                                if ($isExpired) {
                                    echo "Expirou em " . date('d/m/Y', strtotime($userData['expires_at']));
                                } elseif (!empty($userData['expires_at'])) {
                                    echo "Válida até " . date('d/m/Y', strtotime($userData['expires_at']));
                                    
                                    // Calcular dias restantes
                                    $expiryDate = new DateTime($userData['expires_at']);
                                    $today = new DateTime();
                                    $daysRemaining = $today->diff($expiryDate)->days;
                                    
                                    echo " ({$daysRemaining} dias restantes)";
                                } else {
                                    echo "Sem data de expiração definida";
                                }
                                ?>
                            </p>
                        </div>
                    </div>

                    <div class="subscription-details">
                        <div class="detail-item">
                            <span class="detail-label">Usuário:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($userData['username']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Plano:</span>
                            <span class="detail-value">Acesso Premium</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value <?php echo $isExpired ? 'text-danger-500' : 'text-success-500'; ?>">
                                <?php echo $isExpired ? 'Expirado' : 'Ativo'; ?>
                            </span>
                        </div>
                        <?php if (!empty($userData['last_login'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Último acesso:</span>
                            <span class="detail-value"><?php echo date('d/m/Y H:i', strtotime($userData['last_login'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($paymentInProgress && !$qrCodeExpired): ?>
        <!-- QR Code de Pagamento -->
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Pagamento em Andamento</h3>
                <p class="card-subtitle">Escaneie o QR Code para finalizar o pagamento</p>
            </div>
            <div class="card-body">
                <div class="qr-code-container">
                    <div class="qr-code">
                        <img src="<?php echo $_SESSION['payment_qr_code']; ?>" alt="QR Code de Pagamento">
                    </div>
                    <div class="qr-code-info">
                        <p class="qr-code-amount">R$ <?php echo number_format($_SESSION['payment_amount'] ?? 0, 2, ',', '.'); ?></p>
                        <p class="qr-code-description">
                            Assinatura por <?php echo $_SESSION['payment_months'] ?? 1; ?> 
                            <?php echo ($_SESSION['payment_months'] ?? 1) > 1 ? 'meses' : 'mês'; ?>
                        </p>
                        <p class="qr-code-expiry">
                            <i class="fas fa-clock"></i>
                            QR Code válido por 30 minutos
                        </p>
                        <div class="qr-code-actions">
                            <form method="post" action="">
                                <input type="hidden" name="action" value="check_payment">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sync-alt"></i>
                                    Verificar Pagamento
                                </button>
                            </form>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="cancel_payment">
                                <button type="submit" class="btn btn-secondary">
                                    <i class="fas fa-times"></i>
                                    Cancelar
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Formulário de Pagamento -->
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title"><?php echo $isExpired ? 'Renovar Assinatura' : 'Estender Assinatura'; ?></h3>
                <p class="card-subtitle">Escolha o período e método de pagamento</p>
            </div>
            <div class="card-body">
                <form method="post" action="" id="paymentForm">
                    <input type="hidden" name="action" value="create_payment">
                    
                    <div class="form-group">
                        <label for="months" class="form-label">Período de Assinatura</label>
                        <select id="months" name="months" class="form-input form-select">
                            <option value="1" data-price="30.00">1 mês - R$ 30,00</option>
                            <option value="3" data-price="85.00">3 meses - R$ 85,00 (5% de desconto)</option>
                            <option value="6" data-price="162.00">6 meses - R$ 162,00 (10% de desconto)</option>
                            <option value="12" data-price="300.00">12 meses - R$ 300,00 (15% de desconto)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_method" class="form-label">Método de Pagamento</label>
                        <div class="payment-methods">
                            <div class="payment-method active">
                                <input type="radio" name="payment_method" id="pix" value="pix" checked>
                                <label for="pix">
                                    <img src="https://logopng.com.br/logos/pix-106.png" alt="PIX">
                                    <span>PIX</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="payment-summary">
                        <div class="summary-item">
                            <span>Subtotal:</span>
                            <span id="subtotal">R$ 30,00</span>
                        </div>
                        <div class="summary-item discount">
                            <span>Desconto:</span>
                            <span id="discount">R$ 0,00</span>
                        </div>
                        <div class="summary-item total">
                            <span>Total:</span>
                            <span id="total">R$ 30,00</span>
                        </div>
                    </div>
                    
                    <input type="hidden" name="amount" id="amount" value="30.00">
                    
                    <button type="submit" class="btn btn-primary w-full mt-4">
                        <i class="fas fa-lock"></i>
                        Gerar QR Code para Pagamento
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- Benefícios -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Benefícios da Assinatura</h3>
            </div>
            <div class="card-body">
                <div class="benefits-list">
                    <div class="benefit-item">
                        <div class="benefit-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="benefit-text">
                            <h4>Acesso Completo</h4>
                            <p>Todos os geradores de banners</p>
                        </div>
                    </div>
                    <div class="benefit-item">
                        <div class="benefit-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="benefit-text">
                            <h4>Sem Limites</h4>
                            <p>Gere quantos banners quiser</p>
                        </div>
                    </div>
                    <div class="benefit-item">
                        <div class="benefit-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="benefit-text">
                            <h4>Personalização</h4>
                            <p>Logos e fundos personalizados</p>
                        </div>
                    </div>
                    <div class="benefit-item">
                        <div class="benefit-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="benefit-text">
                            <h4>Suporte Prioritário</h4>
                            <p>Atendimento exclusivo</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQ -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Perguntas Frequentes</h3>
            </div>
            <div class="card-body">
                <div class="faq-list">
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>Como funciona o pagamento?</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>O pagamento é feito via PIX. Após gerar o QR Code, você pode escaneá-lo com seu aplicativo bancário para finalizar o pagamento.</p>
                        </div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>Quanto tempo leva para confirmar o pagamento?</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>Pagamentos via PIX são confirmados em poucos minutos. Após o pagamento, clique em "Verificar Pagamento" para atualizar seu status.</p>
                        </div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>Posso cancelar minha assinatura?</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>Não é necessário cancelar, pois não há renovação automática. Sua assinatura simplesmente expira na data indicada.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Suporte -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Precisa de Ajuda?</h3>
            </div>
            <div class="card-body">
                <p class="mb-4">Se você tiver dúvidas ou problemas com o pagamento, entre em contato com nosso suporte:</p>
                <a href="https://wa.me/5511999999999" target="_blank" class="btn btn-success w-full">
                    <i class="fab fa-whatsapp"></i>
                    Suporte via WhatsApp
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    .alert {
        padding: 1rem;
        border-radius: 12px;
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
    }
    
    .alert-success {
        background: var(--success-50);
        color: var(--success-600);
        border: 1px solid rgba(34, 197, 94, 0.2);
    }
    
    .alert-error {
        background: var(--danger-50);
        color: var(--danger-600);
        border: 1px solid rgba(239, 68, 68, 0.2);
    }
    
    .alert-warning {
        background: var(--warning-50);
        color: var(--warning-600);
        border: 1px solid rgba(245, 158, 11, 0.2);
    }
    
    .alert-info {
        background: var(--primary-50);
        color: var(--primary-600);
        border: 1px solid rgba(59, 130, 246, 0.2);
    }
    
    .subscription-info {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    
    .subscription-status {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.5rem;
        border-radius: var(--border-radius);
    }
    
    .subscription-status.active {
        background: var(--success-50);
    }
    
    .subscription-status.expired {
        background: var(--danger-50);
    }
    
    .status-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    
    .subscription-status.active .status-icon {
        background: var(--success-500);
        color: white;
    }
    
    .subscription-status.expired .status-icon {
        background: var(--danger-500);
        color: white;
    }
    
    .status-text h4 {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }
    
    .subscription-status.active .status-text h4 {
        color: var(--success-700);
    }
    
    .subscription-status.expired .status-text h4 {
        color: var(--danger-700);
    }
    
    .status-text p {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }
    
    .subscription-details {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .detail-item {
        padding: 1rem;
        background: var(--bg-secondary);
        border-radius: var(--border-radius);
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .detail-label {
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    
    .detail-value {
        font-weight: 600;
        color: var(--text-primary);
    }
    
    .payment-methods {
        display: flex;
        gap: 1rem;
        margin-top: 0.5rem;
    }
    
    .payment-method {
        flex: 1;
        border: 2px solid var(--border-color);
        border-radius: var(--border-radius);
        padding: 1rem;
        text-align: center;
        cursor: pointer;
        transition: var(--transition);
    }
    
    .payment-method.active {
        border-color: var(--primary-500);
        background: var(--primary-50);
    }
    
    .payment-method input {
        display: none;
    }
    
    .payment-method label {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
    }
    
    .payment-method img {
        height: 40px;
        object-fit: contain;
    }
    
    .payment-summary {
        margin-top: 1.5rem;
        padding: 1.5rem;
        background: var(--bg-secondary);
        border-radius: var(--border-radius);
    }
    
    .summary-item {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid var(--border-color);
    }
    
    .summary-item:last-child {
        border-bottom: none;
    }
    
    .summary-item.total {
        font-weight: 700;
        font-size: 1.125rem;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 2px solid var(--border-color);
    }
    
    .summary-item.discount {
        color: var(--success-600);
    }
    
    .qr-code-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1.5rem;
    }
    
    @media (min-width: 768px) {
        .qr-code-container {
            flex-direction: row;
            align-items: flex-start;
        }
    }
    
    .qr-code {
        padding: 1.5rem;
        background: white;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-md);
        text-align: center;
    }
    
    .qr-code img {
        max-width: 200px;
        height: auto;
    }
    
    .qr-code-info {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .qr-code-amount {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary-600);
    }
    
    .qr-code-description {
        color: var(--text-secondary);
    }
    
    .qr-code-expiry {
        color: var(--warning-600);
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .qr-code-actions {
        display: flex;
        gap: 1rem;
        margin-top: 1rem;
    }
    
    .benefits-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .benefit-item {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .benefit-icon {
        width: 24px;
        height: 24px;
        background: var(--primary-500);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
    }
    
    .benefit-text h4 {
        font-weight: 600;
        margin-bottom: 0.25rem;
    }
    
    .benefit-text p {
        font-size: 0.875rem;
        color: var(--text-secondary);
    }
    
    .faq-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .faq-item {
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius);
        overflow: hidden;
    }
    
    .faq-question {
        padding: 1rem;
        background: var(--bg-secondary);
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        font-weight: 500;
    }
    
    .faq-question i {
        transition: transform 0.3s ease;
    }
    
    .faq-item.active .faq-question i {
        transform: rotate(180deg);
    }
    
    .faq-answer {
        padding: 0;
        max-height: 0;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .faq-item.active .faq-answer {
        padding: 1rem;
        max-height: 200px;
    }
    
    .faq-answer p {
        font-size: 0.875rem;
        color: var(--text-secondary);
    }
    
    .text-danger-500 {
        color: var(--danger-500);
    }
    
    .text-success-500 {
        color: var(--success-500);
    }
    
    .mt-1 {
        margin-top: 0.25rem;
    }
    
    .mt-4 {
        margin-top: 1rem;
    }
    
    .mt-6 {
        margin-top: 1.5rem;
    }
    
    .mb-4 {
        margin-bottom: 1rem;
    }
    
    .mb-6 {
        margin-bottom: 1.5rem;
    }
    
    .w-full {
        width: 100%;
    }
    
    /* Dark theme adjustments */
    [data-theme="dark"] .alert-success {
        background: rgba(34, 197, 94, 0.1);
        color: var(--success-400);
    }
    
    [data-theme="dark"] .alert-error {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger-400);
    }
    
    [data-theme="dark"] .alert-warning {
        background: rgba(245, 158, 11, 0.1);
        color: var(--warning-400);
    }
    
    [data-theme="dark"] .alert-info {
        background: rgba(59, 130, 246, 0.1);
        color: var(--primary-400);
    }
    
    [data-theme="dark"] .subscription-status.active {
        background: rgba(34, 197, 94, 0.1);
    }
    
    [data-theme="dark"] .subscription-status.expired {
        background: rgba(239, 68, 68, 0.1);
    }
    
    [data-theme="dark"] .subscription-status.active .status-text h4 {
        color: var(--success-400);
    }
    
    [data-theme="dark"] .subscription-status.expired .status-text h4 {
        color: var(--danger-400);
    }
    
    [data-theme="dark"] .payment-method.active {
        background: rgba(59, 130, 246, 0.1);
    }
    
    [data-theme="dark"] .qr-code {
        background: var(--bg-secondary);
    }
    
    [data-theme="dark"] .summary-item.discount {
        color: var(--success-400);
    }
    
    [data-theme="dark"] .qr-code-amount {
        color: var(--primary-400);
    }
    
    [data-theme="dark"] .qr-code-expiry {
        color: var(--warning-400);
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Atualizar valores do formulário de pagamento
    const monthsSelect = document.getElementById('months');
    const subtotalElement = document.getElementById('subtotal');
    const discountElement = document.getElementById('discount');
    const totalElement = document.getElementById('total');
    const amountInput = document.getElementById('amount');
    
    if (monthsSelect) {
        monthsSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const price = parseFloat(selectedOption.getAttribute('data-price'));
            const months = parseInt(this.value);
            
            // Calcular valores
            let regularPrice = 30.00 * months;
            let discount = regularPrice - price;
            
            // Atualizar elementos
            subtotalElement.textContent = `R$ ${regularPrice.toFixed(2).replace('.', ',')}`;
            discountElement.textContent = `R$ ${discount.toFixed(2).replace('.', ',')}`;
            totalElement.textContent = `R$ ${price.toFixed(2).replace('.', ',')}`;
            amountInput.value = price.toFixed(2);
        });
    }
    
    // Seleção de método de pagamento
    const paymentMethods = document.querySelectorAll('.payment-method');
    paymentMethods.forEach(method => {
        method.addEventListener('click', function() {
            // Remover classe active de todos
            paymentMethods.forEach(m => m.classList.remove('active'));
            
            // Adicionar classe active ao clicado
            this.classList.add('active');
            
            // Marcar radio button
            const radio = this.querySelector('input[type="radio"]');
            radio.checked = true;
        });
    });
    
    // Toggle FAQ
    window.toggleFaq = function(element) {
        const faqItem = element.parentElement;
        faqItem.classList.toggle('active');
    };
    
    // Auto-refresh para verificar pagamento a cada 30 segundos
    <?php if ($paymentInProgress && !$qrCodeExpired): ?>
    const checkPaymentInterval = setInterval(function() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'check_payment';
        
        form.appendChild(actionInput);
        document.body.appendChild(form);
        form.submit();
    }, 30000); // Verificar a cada 30 segundos
    
    // Limpar intervalo quando a página for fechada
    window.addEventListener('beforeunload', function() {
        clearInterval(checkPaymentInterval);
    });
    <?php endif; ?>
    
    // Limpar sessão de QR code quando a página for fechada
    window.addEventListener('beforeunload', function() {
        // Enviar requisição para limpar a sessão
        navigator.sendBeacon('clear_qr_session.php');
    });
});
</script>

<?php include "includes/footer.php"; ?>