<?php
session_start();
if (!isset($_SESSION["usuario"]) || $_SESSION["role"] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once 'classes/MercadoPagoSettings.php';

$mercadoPagoSettings = new MercadoPagoSettings();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_settings':
                $accessToken = trim($_POST['access_token']);
                $userAccessValue = floatval(str_replace(',', '.', $_POST['user_access_value']));
                
                $result = $mercadoPagoSettings->saveSettings(
                    $userId, 
                    $accessToken, 
                    $userAccessValue
                );
                
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'test_connection':
                $accessToken = trim($_POST['access_token']);
                $result = $mercadoPagoSettings->testConnection($accessToken);
                
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
                
            case 'delete_settings':
                $result = $mercadoPagoSettings->deleteSettings($userId);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
        }
    }
}

// Buscar configurações atuais
$currentSettings = $mercadoPagoSettings->getSettings($userId);
$hasSettings = $currentSettings !== false;

// Obter estatísticas
$stats = $mercadoPagoSettings->getUsageStats();

$pageTitle = "Configurações do Mercado Pago";
include "includes/header.php";
?>

<div class="page-header">
    <h1 class="page-title">
        <i class="fas fa-money-bill-wave text-primary-500 mr-3"></i>
        Configurações do Mercado Pago
    </h1>
    <p class="page-subtitle">Configure a integração com o Mercado Pago para pagamentos</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Formulário de Configuração -->
    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <?php echo $hasSettings ? 'Atualizar' : 'Configurar'; ?> Mercado Pago
                </h3>
                <p class="card-subtitle">
                    <?php echo $hasSettings ? 'Suas configurações atuais' : 'Configure a integração com o Mercado Pago'; ?>
                </p>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> mb-6">
                        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="mercadoPagoForm">
                    <input type="hidden" name="action" value="save_settings">
                    
                    <div class="form-group">
                        <label for="access_token" class="form-label required">
                            <i class="fas fa-key mr-2"></i>
                            Token de Acesso
                        </label>
                        <input type="text" id="access_token" name="access_token" class="form-input" 
                               value="<?php echo htmlspecialchars($currentSettings['access_token'] ?? ''); ?>" 
                               placeholder="APP_USR-XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX" required>
                        <p class="text-xs text-muted mt-1">
                            Obtenha o token de acesso nas configurações da sua conta do Mercado Pago
                        </p>
                        <button type="button" class="btn btn-secondary btn-sm mt-2" id="testConnectionBtn">
                            <i class="fas fa-vial"></i>
                            Testar Conexão
                        </button>
                    </div>

                    <div class="form-group">
                        <label for="user_access_value" class="form-label required">
                            <i class="fas fa-dollar-sign mr-2"></i>
                            Valor do Acesso
                        </label>
                        <div class="input-with-prefix">
                            <span class="input-prefix">R$</span>
                            <input type="text" id="user_access_value" name="user_access_value" class="form-input with-prefix" 
                                   value="<?php echo htmlspecialchars(number_format($currentSettings['user_access_value'] ?? 29.90, 2, ',', '.')); ?>" 
                                   placeholder="29,90" required>
                        </div>
                        <p class="text-xs text-muted mt-1">
                            Valor que será cobrado para renovação de acesso dos usuários
                        </p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?php echo $hasSettings ? 'Atualizar' : 'Salvar'; ?> Configurações
                        </button>
                        
                        <button type="button" class="btn btn-success" id="testConnectionBtnFull" 
                                <?php echo !$hasSettings ? 'disabled' : ''; ?>>
                            <i class="fas fa-plug"></i>
                            Testar Integração
                        </button>
                        
                        <?php if ($hasSettings): ?>
                        <button type="button" class="btn btn-danger" id="deleteBtn">
                            <i class="fas fa-trash"></i>
                            Remover Configurações
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Instruções de Configuração -->
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                    Como Configurar o Mercado Pago
                </h3>
            </div>
            <div class="card-body">
                <div class="space-y-4">
                    <div class="step-item">
                        <div class="step-number">1</div>
                        <div>
                            <h4 class="font-semibold">Acesse sua conta do Mercado Pago</h4>
                            <p class="text-sm text-muted">Entre na sua conta ou crie uma nova em <a href="https://www.mercadopago.com.br" target="_blank" class="text-primary-500 hover:underline">mercadopago.com.br</a></p>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">2</div>
                        <div>
                            <h4 class="font-semibold">Acesse as configurações de desenvolvedor</h4>
                            <p class="text-sm text-muted">Vá para "Seu perfil" > "Desenvolvedor" > "Credenciais"</p>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">3</div>
                        <div>
                            <h4 class="font-semibold">Crie uma aplicação</h4>
                            <p class="text-sm text-muted">Clique em "Criar aplicação" e preencha os dados necessários</p>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">4</div>
                        <div>
                            <h4 class="font-semibold">Obtenha o token de acesso</h4>
                            <p class="text-sm text-muted">Copie o "Access Token" de produção e cole no campo acima</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Painel de Informações -->
    <div class="space-y-6">
        <!-- Status -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 Status</h3>
            </div>
            <div class="card-body">
                <div class="status-item">
                    <div class="status-icon <?php echo $hasSettings ? 'status-success' : 'status-warning'; ?>">
                        <i class="fas fa-<?php echo $hasSettings ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    </div>
                    <div>
                        <p class="font-medium">
                            <?php echo $hasSettings ? 'Configurado' : 'Não Configurado'; ?>
                        </p>
                        <p class="text-sm text-muted">
                            <?php echo $hasSettings ? 'Integração pronta para uso' : 'Configure a integração primeiro'; ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($hasSettings): ?>
                <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                    <p class="text-sm font-medium mb-2">Última atualização:</p>
                    <p class="text-xs text-muted">
                        <?php echo date('d/m/Y H:i', strtotime($currentSettings['updated_at'] ?? $currentSettings['created_at'])); ?>
                    </p>
                </div>
                
                <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                    <p class="text-sm font-medium mb-2">Valor do Acesso:</p>
                    <p class="text-xl font-bold text-success-600">
                        R$ <?php echo number_format($currentSettings['user_access_value'] ?? 0, 2, ',', '.'); ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Estatísticas -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📈 Estatísticas</h3>
            </div>
            <div class="card-body">
                <div class="space-y-3">
                    <div class="stat-item">
                        <span class="stat-label">Total de usuários configurados:</span>
                        <span class="stat-value"><?php echo $stats['total_users_configured']; ?></span>
                    </div>
                    
                    <?php if ($stats['total_users_configured'] > 0): ?>
                    <div class="stat-item">
                        <span class="stat-label">Valor médio de acesso:</span>
                        <span class="stat-value">R$ <?php echo number_format($stats['avg_access_value'], 2, ',', '.'); ?></span>
                    </div>
                    
                    <div class="stat-item">
                        <span class="stat-label">Valor mínimo:</span>
                        <span class="stat-value">R$ <?php echo number_format($stats['min_access_value'], 2, ',', '.'); ?></span>
                    </div>
                    
                    <div class="stat-item">
                        <span class="stat-label">Valor máximo:</span>
                        <span class="stat-value">R$ <?php echo number_format($stats['max_access_value'], 2, ',', '.'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Dicas -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">💡 Dicas</h3>
            </div>
            <div class="card-body">
                <div class="space-y-3 text-sm">
                    <div class="tip-item">
                        <i class="fas fa-info-circle text-primary-500"></i>
                        <p>Use o token de produção para pagamentos reais</p>
                    </div>
                    <div class="tip-item">
                        <i class="fas fa-shield-alt text-success-500"></i>
                        <p>Seu token é criptografado e armazenado com segurança</p>
                    </div>
                    <div class="tip-item">
                        <i class="fas fa-money-bill-wave text-warning-500"></i>
                        <p>Configure um valor justo para o acesso dos usuários</p>
                    </div>
                    <div class="tip-item">
                        <i class="fas fa-clock text-info-500"></i>
                        <p>Teste a integração antes de usar em produção</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .required::after {
        content: ' *';
        color: var(--danger-500);
    }

    .alert {
        padding: 1rem;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 0.5rem;
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

    .form-actions {
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
        margin-top: 2rem;
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.75rem;
    }

    .status-item {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .status-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .status-success {
        background: var(--success-50);
        color: var(--success-600);
    }

    .status-warning {
        background: var(--warning-50);
        color: var(--warning-600);
    }

    .step-item {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
    }

    .step-number {
        width: 24px;
        height: 24px;
        background: var(--primary-500);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.75rem;
        flex-shrink: 0;
    }

    .tip-item {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .tip-item i {
        margin-top: 0.125rem;
        flex-shrink: 0;
    }
    
    .stat-item {
        display: flex;
        justify-content: space-between;
        padding: 0.75rem;
        background: var(--bg-tertiary);
        border-radius: var(--border-radius-sm);
    }
    
    .stat-label {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }
    
    .stat-value {
        font-weight: 600;
        color: var(--text-primary);
    }
    
    .input-with-prefix {
        position: relative;
    }
    
    .input-prefix {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-secondary);
        font-weight: 500;
    }
    
    .form-input.with-prefix {
        padding-left: 2.5rem;
    }

    .space-y-3 > * + * {
        margin-top: 0.75rem;
    }

    .space-y-4 > * + * {
        margin-top: 1rem;
    }

    .space-y-6 > * + * {
        margin-top: 1.5rem;
    }

    .mt-2 {
        margin-top: 0.5rem;
    }

    .mt-3 {
        margin-top: 0.75rem;
    }

    .mt-4 {
        margin-top: 1rem;
    }

    .mt-6 {
        margin-top: 1.5rem;
    }

    .mb-2 {
        margin-bottom: 0.5rem;
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

    .p-3 {
        padding: 0.75rem;
    }

    .bg-gray-50 {
        background-color: var(--bg-tertiary);
    }

    .rounded-lg {
        border-radius: var(--border-radius);
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

    [data-theme="dark"] .bg-gray-50 {
        background-color: var(--bg-tertiary);
    }

    [data-theme="dark"] .status-success {
        background: rgba(34, 197, 94, 0.1);
        color: var(--success-400);
    }

    [data-theme="dark"] .status-warning {
        background: rgba(245, 158, 11, 0.1);
        color: var(--warning-400);
    }
    
    [data-theme="dark"] .text-success-600 {
        color: var(--success-400);
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const testConnectionBtn = document.getElementById('testConnectionBtn');
    const testConnectionBtnFull = document.getElementById('testConnectionBtnFull');
    const deleteBtn = document.getElementById('deleteBtn');
    const accessTokenInput = document.getElementById('access_token');
    const userAccessValueInput = document.getElementById('user_access_value');

    // Formatar valor monetário
    userAccessValueInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        value = (parseInt(value) / 100).toFixed(2);
        e.target.value = value.replace('.', ',');
    });

    // Testar Conexão
    testConnectionBtn.addEventListener('click', function() {
        const accessToken = accessTokenInput.value.trim();
        
        if (!accessToken) {
            showAlert('error', 'Digite o token de acesso primeiro');
            return;
        }

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';

        fetch('mercadopago.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=test_connection&access_token=${encodeURIComponent(accessToken)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const userInfo = data.user_info;
                showAlert('success', `Conexão estabelecida com sucesso! Usuário: ${userInfo.nickname} (${userInfo.email})`);
            } else {
                showAlert('error', data.message);
            }
        })
        .catch(error => {
            showAlert('error', 'Erro na conexão: ' + error.message);
        })
        .finally(() => {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-vial"></i> Testar Conexão';
        });
    });

    // Testar Integração Completa
    if (testConnectionBtnFull) {
        testConnectionBtnFull.addEventListener('click', function() {
            const accessToken = accessTokenInput.value.trim();
            
            if (!accessToken) {
                showAlert('error', 'Digite o token de acesso primeiro');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';

            fetch('mercadopago.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=test_connection&access_token=${encodeURIComponent(accessToken)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const userInfo = data.user_info;
                    Swal.fire({
                        title: 'Integração Funcionando!',
                        html: `
                            <div class="text-left">
                                <p><strong>Usuário:</strong> ${userInfo.nickname}</p>
                                <p><strong>Email:</strong> ${userInfo.email}</p>
                                <p><strong>País:</strong> ${userInfo.country_id}</p>
                                <p><strong>Status:</strong> ${userInfo.site_status}</p>
                            </div>
                        `,
                        icon: 'success',
                        background: document.body.getAttribute('data-theme') === 'dark' ? '#1e293b' : '#ffffff',
                        color: document.body.getAttribute('data-theme') === 'dark' ? '#f1f5f9' : '#1e293b'
                    });
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'Erro na conexão: ' + error.message);
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-plug"></i> Testar Integração';
            });
        });
    }

    // Deletar Configurações
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            Swal.fire({
                title: 'Remover Configurações?',
                text: 'Isso irá remover todas as suas configurações do Mercado Pago. Você precisará configurar novamente.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sim, remover',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
                background: document.body.getAttribute('data-theme') === 'dark' ? '#1e293b' : '#ffffff',
                color: document.body.getAttribute('data-theme') === 'dark' ? '#f1f5f9' : '#1e293b'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = '<input type="hidden" name="action" value="delete_settings">';
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
    }

    function showAlert(type, message) {
        Swal.fire({
            title: type === 'success' ? 'Sucesso!' : 'Erro!',
            text: message,
            icon: type,
            background: document.body.getAttribute('data-theme') === 'dark' ? '#1e293b' : '#ffffff',
            color: document.body.getAttribute('data-theme') === 'dark' ? '#f1f5f9' : '#1e293b',
            confirmButtonColor: type === 'success' ? '#22c55e' : '#ef4444'
        });
    }
});
</script>

<?php include "includes/footer.php"; ?>