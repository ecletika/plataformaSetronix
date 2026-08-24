<?php
/** Ecrã de autenticação — passo 1 (utilizador + palavra-passe). */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/layout.php';

// Já autenticado por completo? Segue para a aplicação.
if (!empty($_SESSION['uid']) && !empty($_SESSION['mfa_passed'])) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Preencha o utilizador e a palavra-passe.';
    } else {
        $r = auth_attempt_password($username, $password);
        if ($r['ok']) {
            auth_start_session($r['user']);
            redirect('mfa.php');
        }
        $error = $r['error'];
    }
}

layout_head('Entrar', 'auth');
?>
<div class="wrap narrow" style="margin-top:8vh">
  <div class="card">
    <div class="actions" style="margin-bottom:16px">
      <div class="brandmark">S</div>
      <div>
        <h2 style="margin:0"><?= e($CONFIG['app']['org'] ?? 'Setronix') ?></h2>
        <div class="muted"><?= e($CONFIG['app']['name'] ?? '') ?></div>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <label><span class="req">Utilizador ou e-mail</span>
        <input type="text" name="username" required autofocus autocomplete="username"
               value="<?= e($_POST['username'] ?? '') ?>">
      </label>
      <label><span class="req">Palavra-passe</span>
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button class="primary" type="submit" style="width:100%">Entrar</button>
    </form>

    <p class="muted" style="margin-top:16px">
      Após a palavra-passe será pedido o código de verificação em duas etapas (MFA).
      Se perdeu o acesso, contacte um administrador da plataforma.
    </p>
  </div>
</div>
<?php layout_foot(); ?>
