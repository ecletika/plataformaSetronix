<?php
/**
 * Ecrã de autenticação — passo 2 (MFA / TOTP).
 *
 * Trata dois casos:
 *  a) Inscrição: o utilizador ainda não tem MFA activo → mostra o segredo,
 *     pede confirmação com um código e entrega os códigos de recuperação.
 *  b) Verificação: o utilizador já tem MFA → pede o código de 6 dígitos
 *     (ou um código de recuperação).
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/qrcode.php';

if (empty($_SESSION['uid'])) {
    redirect('login.php');
}
if (!empty($_SESSION['mfa_passed'])) {
    redirect('index.php');
}

$user = q_one('SELECT * FROM users WHERE id = ?', [(int)$_SESSION['uid']]);
if (!$user || (int)$user['is_active'] !== 1) {
    auth_logout('conta inválida');
    redirect('login.php');
}

$org      = $CONFIG['app']['org'] ?? 'Setronix';
$window   = (int)($CONFIG['security']['totp_window'] ?? 1);
$codeCount = (int)($CONFIG['security']['recovery_code_count'] ?? 10);
$enrolled = (int)$user['mfa_enabled'] === 1 && !empty($user['mfa_secret']);

$error = '';
$showCodes = $_SESSION['show_recovery_codes'] ?? null;

// -------------------------------------------------------------------
// Inscrição: prepara um segredo provisório guardado na sessão
// -------------------------------------------------------------------
if (!$enrolled && empty($_SESSION['mfa_enroll_secret'])) {
    $_SESSION['mfa_enroll_secret'] = totp_generate_secret();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'codes_ack') {
        // O utilizador confirmou que guardou os códigos de recuperação.
        unset($_SESSION['show_recovery_codes']);
        auth_complete_login($user);
        $to = $_SESSION['redirect_after_login'] ?? 'index.php';
        unset($_SESSION['redirect_after_login']);
        redirect($to !== '' ? $to : 'index.php');
    }

    if (ip_rate_limited()) {
        $error = 'Demasiadas tentativas a partir deste endereço. Tente novamente dentro de 15 minutos.';
    } elseif ($action === 'enroll') {
        // ---- Confirmação da inscrição ----
        $secret = (string)($_SESSION['mfa_enroll_secret'] ?? '');
        $code   = (string)($_POST['code'] ?? '');

        if ($secret === '') {
            $error = 'A sessão de inscrição expirou. Volte a iniciar sessão.';
        } elseif (!totp_verify($secret, $code, $window)) {
            log_attempt($user['username'], false, 'mfa', 'enroll_bad_code');
            $error = 'Código inválido. Confirme a hora do telemóvel e tente com o código seguinte.';
        } else {
            user_set_mfa_secret((int)$user['id'], $secret);
            q('UPDATE users SET mfa_enabled = 1, mfa_confirmed_at = NOW() WHERE id = ?', [(int)$user['id']]);
            unset($_SESSION['mfa_enroll_secret']);

            $codes = mfa_reset_recovery_codes((int)$user['id'], $codeCount);
            $_SESSION['show_recovery_codes'] = $codes;
            $showCodes = $codes;

            log_attempt($user['username'], true, 'mfa', 'enrolled');
            audit('mfa_enroll', 'user', $user['id'], 'MFA activado', null, null,
                  (int)$user['id'], $user['username']);
        }
    } elseif ($action === 'verify') {
        // ---- Verificação normal ----
        $code   = trim((string)($_POST['code'] ?? ''));
        $secret = user_mfa_secret($user);

        if ($secret === null) {
            $error = 'Não foi possível ler o segredo MFA desta conta. Contacte um administrador.';
        } elseif (totp_verify($secret, $code, $window)) {
            log_attempt($user['username'], true, 'mfa');
            auth_complete_login($user);
            $to = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);
            redirect($to !== '' ? $to : 'index.php');
        } elseif (strlen(mfa_normalize_recovery($code)) === 8 && mfa_consume_recovery_code((int)$user['id'], $code)) {
            log_attempt($user['username'], true, 'recovery');
            audit('mfa_recovery_used', 'user', $user['id'],
                  'Código de recuperação utilizado (restam ' . mfa_recovery_codes_left((int)$user['id']) . ')',
                  null, null, (int)$user['id'], $user['username']);
            auth_complete_login($user);
            flash('warn', 'Entrou com um código de recuperação. Restam '
                        . mfa_recovery_codes_left((int)$user['id'])
                        . '. Gere novos códigos em "A minha conta".');
            redirect('index.php');
        } else {
            log_attempt($user['username'], false, 'mfa', 'bad_code');
            $error = 'Código incorreto ou já utilizado.';
        }
    }
}

layout_head('Verificação em duas etapas', 'auth');
?>
<div class="wrap narrow" style="margin-top:6vh">

<?php if ($showCodes): ?>
  <!-- ---------- Códigos de recuperação ---------- -->
  <div class="card">
    <h2>Guarde os códigos de recuperação</h2>
    <p class="muted">
      Estes códigos permitem entrar se perder o telemóvel. Cada um serve
      <b>uma única vez</b> e não voltam a ser mostrados. Guarde-os num local seguro
      (gestor de palavras-passe ou envelope fechado).
    </p>
    <div class="codes" style="margin:16px 0">
      <?php foreach ($showCodes as $c): ?><span><?= e($c) ?></span><?php endforeach; ?>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="codes_ack">
      <label class="muted">
        <input type="checkbox" required style="width:auto;margin-right:6px">
        Confirmo que guardei os códigos num local seguro.
      </label>
      <button class="primary" type="submit" style="width:100%">Continuar para a plataforma</button>
    </form>
  </div>

<?php elseif (!$enrolled): ?>
  <!-- ---------- Inscrição MFA ---------- -->
  <div class="card">
    <h2>Ativar a verificação em duas etapas</h2>
    <p class="muted">
      Olá, <b><?= e($user['full_name']) ?></b>. Antes de entrar pela primeira vez é
      necessário associar a conta a uma aplicação autenticadora
      (Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden…).
    </p>

    <?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

    <h3>1. Ler o código com a aplicação</h3>
    <?php $uri = totp_uri((string)$_SESSION['mfa_enroll_secret'], (string)$user['username'], (string)$org); ?>
    <p class="muted">
      Na aplicação autenticadora escolha <b>Adicionar conta &rarr; Ler código QR</b>
      e aponte a câmara para a imagem.
    </p>
    <div class="qr"><?= qr_svg($uri, 4, 4, 'Código QR para configurar a verificação em duas etapas') ?></div>
    <p class="muted" style="text-align:center">
      Conta: <code><?= e($org . ':' . $user['username']) ?></code> ·
      SHA1 · 6 dígitos · 30 segundos
    </p>

    <details style="margin:14px 0">
      <summary class="muted" style="cursor:pointer">Não consigo ler o código — introduzir a chave à mão</summary>
      <p class="muted" style="margin-top:10px">
        Na aplicação, escolha <b>Adicionar conta &rarr; Introduzir chave de configuração</b>,
        com tipo de conta <b>baseada em tempo (TOTP)</b>, e copie a chave:
      </p>
      <div class="secret"><?= e(totp_secret_pretty((string)$_SESSION['mfa_enroll_secret'])) ?></div>
      <p class="mono" style="word-break:break-all;font-size:12px;background:#f8fafc;padding:10px;border-radius:6px;margin-top:10px">
        <?= e($uri) ?>
      </p>
    </details>

    <h3>2. Confirmar com o código gerado</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="enroll">
      <label>
        <input class="otp" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}"
               maxlength="6" required autofocus autocomplete="one-time-code" placeholder="000000">
      </label>
      <button class="primary" type="submit" style="width:100%">Confirmar e ativar</button>
    </form>
  </div>

<?php else: ?>
  <!-- ---------- Verificação ---------- -->
  <div class="card">
    <h2>Verificação em duas etapas</h2>
    <p class="muted">
      Introduza o código de 6 dígitos apresentado na sua aplicação autenticadora.
    </p>

    <?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify">
      <label>
        <input class="otp" type="text" name="code" maxlength="9" required autofocus
               autocomplete="one-time-code" placeholder="000000">
      </label>
      <button class="primary" type="submit" style="width:100%">Verificar</button>
    </form>

    <p class="muted" style="margin-top:14px">
      Sem acesso ao telemóvel? Introduza acima um dos seus
      <b>códigos de recuperação</b> (formato <code>XXXX-XXXX</code>).
    </p>
    <p class="muted"><a href="logout.php">Cancelar e voltar ao início</a></p>
  </div>
<?php endif; ?>

</div>
<?php layout_foot(); ?>
