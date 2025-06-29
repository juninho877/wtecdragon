<?php
session_start();
if (!isset($_SESSION["usuario"])) {
    header("Location: login.php");
    exit();
}

require_once 'classes/User.php';
require_once 'classes/MercadoPagoSettings.php';
require_once 'classes/MercadoPagoPayment.php';

$user = new User();
$mercadoPagoSettings = new MercadoPagoSettings();
$mercadoPagoPayment = new MercadoPagoPayment();

$userId = $_SESSION['user_id'];
$currentUserData = $user->getUserById($userId);
$adminSettings = $mercadoPagoSettings->getSettings(1); // Assumindo que o admin tem ID 1

// Verificar se as configurações do Mercado Pago existem
$mercadoPagoConfigured = ($adminSettings !== false && !empty($adminSettings['access_token']));

// Verificar se o usuário tem acesso expirado
$accessExpired = false;
$daysRemaining = 0;

if ($currentUserData && !empty($currentUserData['expires_at'])) {
    $expiryDate = new DateTime($currentUserData['expires_at']);
    $today = new DateTime();
    
    $accessExpired = ($expiryDate < $today);
    
    if (!$accessExpired) {
        $interval = $today->diff($expiryDate);
        $daysRemaining = $interval->days;
    }
}

// Gerar QR Code e Copia e Cola do Mercado Pago
$qrCodeBase64 = '';
$copyPasteCode = '';
$preferenceId = '';

if ($mercadoPagoConfigured && isset($_POST['generate_payment'])) {
    $accessValue = $adminSettings['user_access_value'];
    $description = "Renovação de Acesso - FutBanner";
    
    $result = $mercadoPagoPayment->createPixPayment($userId, $description, $accessValue);
    
    if ($result['success']) {
        $preferenceId = $result['preference_id'];
        $qrCodeBase64 = $result['qr_code_base64'];
        $copyPasteCode = $result['qr_code'];
        
        // Store QR data in session for persistence
        $_SESSION['payment_preference_id_session'] = $preferenceId;
        $_SESSION['qr_code_base64'] = $qrCodeBase64;
        $_SESSION['copy_paste_code'] = $copyPasteCode;
    } else {
        $paymentStatus = 'error';
        $paymentMessage = $result['message'];
    }
}

// Verificar status do pagamento
$paymentStatus = '';
$paymentMessage = '';

if (isset($_POST['check_payment']) && isset($_SESSION['payment_preference_id_session'])) {
    $preferenceId = $_SESSION['payment_preference_id_session'];
    
    $result = $mercadoPagoPayment->checkPaymentStatus($preferenceId);
    
    if ($result['success']) {
        switch ($result['status']) {
            case 'approved':
                $paymentStatus = 'success';
                $paymentMessage = 'Pagamento aprovado! Seu acesso foi renovado.';
                
                // Atualizar dados do usuário após renovação
                $currentUserData = $user->getUserById($userId);
                $accessExpired = false;

        // Clear QR data from session after successful payment
               unset($_SESSION['payment_preference_id_session']);
               unset($_SESSION['qr_code_base64']);
               unset($_SESSION['copy_paste_code']);
                
                // Calcular dias restantes
                $expiryDate = new DateTime($currentUserData['expires_at']);
                $today = new DateTime();
                $interval = $today->diff($expiryDate);
                $daysRemaining = $interval->days;
                break;
                
            case 'pending':
                $paymentStatus = 'warning';
                $paymentMessage = 'Pagamento pendente. Aguardando confirmação.';
                break;
                
            case 'in_process':
                $paymentStatus = 'warning';
                $paymentMessage = 'Pagamento em processamento. Aguarde alguns instantes.';
                break;
                
            case 'rejected':
                $paymentStatus = 'error';
                $paymentMessage = 'Pagamento rejeitado. Por favor, tente novamente.';
                break;
                
            default:
                $paymentStatus = 'warning';
                $paymentMessage = 'Status do pagamento: ' . $result['status'];
                break;
        }
    } else {
        $paymentStatus = 'error';
        $paymentMessage = $result['message'];
    }
}

// Obter histórico de pagamentos
$paymentHistory = $mercadoPagoPayment->getUserPaymentHistory($userId);

$pageTitle = "Pagamento";
include "includes/header.php";
?>

<div class="page-header">
    <h1 class="page-title">
        <i class="fas fa-credit-card text-primary-500 mr-3"></i>
        Pagamento e Renovação de Acesso
    </h1>
    <p class="page-subtitle">Gerencie seu acesso ao sistema e realize pagamentos</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Informações de Acesso -->
    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Status do Seu Acesso</h3>
                <p class="card-subtitle">Informações sobre seu acesso atual</p>
            </div>
            <div class="card-body">
                <div class="access-status-container">
                    <div class="access-status-icon <?php echo $accessExpired ? 'expired' : 'active'; ?>">
                        <i class="fas fa-<?php echo $accessExpired ? 'times-circle' : 'check-circle'; ?>"></i>
                    </div>
                    <div class="access-status-info">
                        <h3 class="access-status-title">
                            <?php echo $accessExpired ? 'Acesso Expirado' : 'Acesso Ativo'; ?>
                        </h3>
                        <p class="access-status-message">
                            <?php if ($accessExpired): ?>
                                Seu acesso expirou em <?php echo date('d/m/Y', strtotime($currentUserData['expires_at'])); ?>. 
                                Renove agora para continuar utilizando o sistema.
                            <?php else: ?>
                                Seu acesso está ativo e expira em <?php echo date('d/m/Y', strtotime($currentUserData['expires_at'])); ?>.
                                <?php if ($daysRemaining <= 5): ?>
                                    <span class="text-warning-600">Faltam apenas <?php echo $daysRemaining; ?> dias para expirar.</span>
                                <?php else: ?>
                                    <span class="text-success-600">Você tem <?php echo $daysRemaining; ?> dias restantes.</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($accessExpired || $daysRemaining <= 5): ?>
                <div class="mt-6">
                    <?php if ($mercadoPagoConfigured): ?>
                        <form method="post" action="">
                            <button type="submit" name="generate_payment" class="btn btn-primary w-full">
                                <i class="fas fa-sync-alt"></i>
                                <?php echo $accessExpired ? 'Renovar Acesso Agora' : 'Renovar Antecipadamente'; ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            O sistema de pagamento não está configurado. Entre em contato com o administrador.
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($qrCodeBase64) || !empty($copyPasteCode)): ?>
        <!-- Área de Pagamento -->
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Realizar Pagamento</h3>
                <p class="card-subtitle">Escaneie o QR Code ou use o código para pagar</p>
            </div>
            <div class="card-body">
                <?php if (!empty($paymentStatus)): ?>
                <div class="alert alert-<?php echo $paymentStatus; ?> mb-6">
                    <i class="fas fa-<?php echo $paymentStatus === 'success' ? 'check-circle' : ($paymentStatus === 'warning' ? 'exclamation-circle' : 'exclamation-triangle'); ?>"></i>
                    <?php echo $paymentMessage; ?>
                </div>
                <?php endif; ?>
                
                <div class="payment-methods">
                    <div class="payment-qrcode">
                        <h4 class="payment-method-title">
                            <i class="fas fa-qrcode text-primary-500 mr-2"></i>
                            QR Code Pix
                        </h4>
                        <?php if (!empty($qrCodeBase64)): ?>
                            <div class="qrcode-container">
                                <img src="data:image/png;base64,<?php echo $qrCodeBase64; ?>" alt="QR Code Pix" class="qrcode-image">
                            </div>
                        <?php else: ?>
                            <div class="qrcode-placeholder">
                                <i class="fas fa-qrcode"></i>
                                <span>QR Code não disponível</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="payment-copy-paste">
                        <h4 class="payment-method-title">
                            <i class="fas fa-copy text-primary-500 mr-2"></i>
                            Pix Copia e Cola
                        </h4>
                        <?php if (!empty($copyPasteCode)): ?>
                            <div class="copy-paste-container">
                                <textarea id="copyPasteCode" class="copy-paste-code" readonly><?php echo $copyPasteCode; ?></textarea>
                                <button type="button" class="btn btn-secondary copy-btn" onclick="copyToClipboard()">
                                    <i class="fas fa-copy"></i>
                                    Copiar
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="copy-paste-placeholder">
                                <i class="fas fa-file-alt"></i>
                                <span>Código não disponível</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="payment-actions mt-6">
                    <form method="post" action="">
                        <input type="hidden" name="preference_id" value="<?php echo $preferenceId; ?>">
                        <button type="submit" name="check_payment" class="btn btn-success w-full">
                            <i class="fas fa-sync-alt"></i>
                            Verificar Status do Pagamento
                        </button>
                    </form>
                </div>
                
                <div class="payment-info mt-4">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <p class="font-medium">Instruções de Pagamento</p>
                            <ol class="payment-instructions">
                                <li>Abra o aplicativo do seu banco</li>
                                <li>Escolha a opção "Pix"</li>
                                <li>Escaneie o QR Code ou cole o código</li>
                                <li>Confirme as informações e finalize o pagamento</li>
                                <li>Após o pagamento, clique em "Verificar Status do Pagamento"</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Informações Adicionais -->
    <div class="space-y-6">
        <!-- Detalhes do Plano -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Detalhes do Plano</h3>
            </div>
            <div class="card-body">
                <div class="plan-details">
                    <div class="plan-price">
                        <span class="price-currency">R$</span>
                        <span class="price-value"><?php echo number_format($adminSettings['user_access_value'] ?? 29.90, 2, ',', '.'); ?></span>
                        <span class="price-period">/mês</span>
                    </div>
                    
                    <div class="plan-features">
                        <div class="plan-feature">
                            <i class="fas fa-check text-success-500"></i>
                            <span>Acesso a todos os geradores de banner</span>
                        </div>
                        <div class="plan-feature">
                            <i class="fas fa-check text-success-500"></i>
                            <span>Personalização de logos e fundos</span>
                        </div>
                        <div class="plan-feature">
                            <i class="fas fa-check text-success-500"></i>
                            <span>Integração com Telegram</span>
                        </div>
                        <div class="plan-feature">
                            <i class="fas fa-check text-success-500"></i>
                            <span>Suporte técnico</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Histórico de Pagamentos -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Histórico de Pagamentos</h3>
            </div>
            <div class="card-body">
                <div class="payment-history">
                    <?php if (empty($paymentHistory)): ?>
                        <div class="payment-history-empty">
                            <i class="fas fa-receipt text-muted"></i>
                            <p>Nenhum pagamento registrado</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($paymentHistory as $payment): ?>
                            <div class="payment-history-item">
                                <div class="payment-history-date">
                                    <i class="fas fa-calendar-alt text-primary-500"></i>
                                    <span><?php echo date('d/m/Y', strtotime($payment['created_at'])); ?></span>
                                </div>
                                <div class="payment-history-details">
                                    <span class="payment-history-amount">R$ <?php echo number_format($payment['transaction_amount'], 2, ',', '.'); ?></span>
                                    <span class="payment-history-status <?php echo $payment['status']; ?>">
                                        <?php 
                                        switch ($payment['status']) {
                                            case 'approved':
                                                echo 'Aprovado';
                                                break;
                                            case 'pending':
                                                echo 'Pendente';
                                                break;
                                            case 'rejected':
                                                echo 'Rejeitado';
                                                break;
                                            default:
                                                echo ucfirst($payment['status']);
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Suporte -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Precisa de Ajuda?</h3>
            </div>
            <div class="card-body">
                <div class="support-info">
                    <p class="mb-4">Se você tiver problemas com o pagamento ou dúvidas sobre seu acesso, entre em contato com o suporte:</p>
                    
                    <div class="support-contact">
                        <i class="fas fa-envelope text-primary-500"></i>
                        <span>suporte@futbanner.com</span>
                    </div>
                    
                    <div class="support-contact">
                        <i class="fab fa-whatsapp text-success-500"></i>
                        <span>(11) 99999-9999</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .access-status-container {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        padding: 1.5rem;
        background: var(--bg-secondary);
        border-radius: var(--border-radius);
    }

    .access-status-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        flex-shrink: 0;
    }

    .access-status-icon.active {
        background: var(--success-50);
        color: var(--success-500);
    }

    .access-status-icon.expired {
        background: var(--danger-50);
        color: var(--danger-500);
    }

    .access-status-info {
        flex: 1;
    }

    .access-status-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    .access-status-message {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    .payment-methods {
        display: grid;
        grid-template-columns: 1fr;
        gap: 2rem;
    }

    @media (min-width: 768px) {
        .payment-methods {
            grid-template-columns: 1fr 1fr;
        }
    }

    .payment-method-title {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
    }

    .qrcode-container {
        display: flex;
        justify-content: center;
        padding: 1rem;
        background: white;
        border-radius: var(--border-radius);
        border: 1px solid var(--border-color);
    }

    .qrcode-image {
        max-width: 200px;
        height: auto;
    }

    .qrcode-placeholder,
    .copy-paste-placeholder {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        background: var(--bg-tertiary);
        border-radius: var(--border-radius);
        color: var(--text-muted);
        gap: 0.5rem;
        font-size: 0.875rem;
    }

    .qrcode-placeholder i,
    .copy-paste-placeholder i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }

    .copy-paste-container {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .copy-paste-code {
        width: 100%;
        height: 100px;
        padding: 0.75rem;
        background: var(--bg-tertiary);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-sm);
        font-family: monospace;
        font-size: 0.75rem;
        resize: none;
        color: var(--text-primary);
    }

    .plan-details {
        text-align: center;
    }

    .plan-price {
        margin-bottom: 1.5rem;
    }

    .price-currency {
        font-size: 1.5rem;
        font-weight: 600;
        vertical-align: top;
        color: var(--primary-500);
    }

    .price-value {
        font-size: 3rem;
        font-weight: 700;
        color: var(--primary-500);
    }

    .price-period {
        font-size: 1rem;
        color: var(--text-muted);
    }

    .plan-features {
        text-align: left;
        margin-top: 1.5rem;
    }

    .plan-feature {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .plan-feature:last-child {
        border-bottom: none;
    }

    .payment-history {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .payment-history-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        color: var(--text-muted);
        gap: 0.5rem;
        text-align: center;
    }

    .payment-history-empty i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }

    .payment-history-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        background: var(--bg-secondary);
        border-radius: var(--border-radius);
    }

    .payment-history-date {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
    }

    .payment-history-details {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }

    .payment-history-amount {
        font-weight: 600;
    }

    .payment-history-status {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        border-radius: 9999px;
    }

    .payment-history-status.approved {
        background: var(--success-50);
        color: var(--success-600);
    }

    .payment-history-status.pending {
        background: var(--warning-50);
        color: var(--warning-600);
    }

    .payment-history-status.rejected {
        background: var(--danger-50);
        color: var(--danger-600);
    }

    .support-info {
        font-size: 0.875rem;
    }

    .support-contact {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 0;
    }

    .alert {
        padding: 1rem;
        border-radius: 12px;
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        font-weight: 500;
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

    .payment-instructions {
        margin-top: 0.5rem;
        padding-left: 1.5rem;
        font-size: 0.875rem;
    }

    .payment-instructions li {
        margin-bottom: 0.25rem;
    }

    .space-y-6 > * + * {
        margin-top: 1.5rem;
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

    .mr-2 {
        margin-right: 0.5rem;
    }

    .mr-3 {
        margin-right: 0.75rem;
    }

    .w-full {
        width: 100%;
    }

    /* Dark theme adjustments */
    [data-theme="dark"] .access-status-icon.active {
        background: rgba(34, 197, 94, 0.1);
        color: var(--success-400);
    }

    [data-theme="dark"] .access-status-icon.expired {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger-400);
    }

    [data-theme="dark"] .qrcode-container {
        background: var(--bg-tertiary);
    }

    [data-theme="dark"] .text-success-600 {
        color: var(--success-400);
    }

    [data-theme="dark"] .text-warning-600 {
        color: var(--warning-400);
    }

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

    [data-theme="dark"] .payment-history-status.approved {
        background: rgba(34, 197, 94, 0.1);
        color: var(--success-400);
    }

    [data-theme="dark"] .payment-history-status.pending {
        background: rgba(245, 158, 11, 0.1);
        color: var(--warning-400);
    }

    [data-theme="dark"] .payment-history-status.rejected {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger-400);
    }
</style>

<script>
function copyToClipboard() {
    const copyText = document.getElementById("copyPasteCode");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    document.execCommand("copy");
    
    // Mostrar feedback
    const copyBtn = document.querySelector(".copy-btn");
    const originalText = copyBtn.innerHTML;
    copyBtn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
    copyBtn.classList.add("btn-success");
    copyBtn.classList.remove("btn-secondary");
    
    setTimeout(() => {
        copyBtn.innerHTML = originalText;
        copyBtn.classList.remove("btn-success");
        copyBtn.classList.add("btn-secondary");
    }, 2000);
}

// Verificar status do pagamento automaticamente a cada 30 segundos
+<?php if (!empty($preferenceId) && empty($paymentStatus) && !isset($_SESSION['payment_approved'])): // Only auto-check if QR is displayed and payment not yet approved ?>
let checkCount = 0;
const maxChecks = 20; // Máximo de 10 minutos (20 * 30 segundos)

function checkPaymentStatus() {
    if (checkCount >= maxChecks) {
        clearInterval(checkInterval);
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'check_payment';
    input.value = '1';
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    
    checkCount++;
}

const checkInterval = setInterval(checkPaymentStatus, 30000); // Verificar a cada 30 segundos
<?php endif; ?>
</script>

<?php include "includes/footer.php"; ?>