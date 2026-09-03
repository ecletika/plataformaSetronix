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
            // Sem MFA exigido e sem dispositivo associado, não há segundo passo.
            if (mfa_required_for($r['user'])) {
                redirect('mfa.php');
            }
            finish_login_and_redirect($r['user']);
        }
        $error = $r['error'];
    }
}

if ($error !== '') {
    no_store();
}
layout_head('Entrar', 'auth');
?>
<div class="wrap narrow" style="margin-top:8vh">
  <div class="card">
    <div class="authlogo">
      <img src="assets/logo-setronix.png" alt="Setronix" width="718" height="277">
    </div>
    <h2 class="authname"><?= e(app_name()) ?></h2>
    <p class="authsub">Entrada reservada a utilizadores registados</p>

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
        <input type="password" name="password" required autocomplete="current-password"
               value="<?= e(keep_password('password')) ?>">
      </label>
      <button class="primary" type="submit" style="width:100%">Entrar</button>
    </form>

  </div>
</div>
<?php layout_foot(); ?>
