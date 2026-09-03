<?php
/** Alteração obrigatória / voluntária da palavra-passe. */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/layout.php';

if (empty($_SESSION['uid']) || empty($_SESSION['mfa_passed'])) {
    redirect('login.php');
}

$user = current_user();
if (!$user) {
    redirect('login.php');
}

$forced = (int)$user['must_change_pw'] === 1;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string)($_POST['current'] ?? '');
    $new     = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if (!password_verify($current, $user['password_hash'])) {
        $error = 'A palavra-passe atual está incorreta.';
    } elseif ($new !== $confirm) {
        $error = 'A confirmação não coincide com a nova palavra-passe.';
    } elseif ($new === $current) {
        $error = 'A nova palavra-passe tem de ser diferente da atual.';
    } elseif ($problems = password_problems($new)) {
        $error = 'A palavra-passe deve ' . implode(', ', $problems) . '.';
    } else {
        q('UPDATE users SET password_hash = ?, must_change_pw = 0, password_changed_at = NOW() WHERE id = ?',
          [password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
        audit('password_change', 'user', $user['id'], 'Palavra-passe alterada pelo próprio');
        flash('ok', 'Palavra-passe alterada com sucesso.');
        redirect('index.php');
    }
}

if ($error !== '') {
    no_store();
}
layout_head('Alterar palavra-passe', $forced ? 'auth' : 'app');
?>
<div class="wrap narrow"<?= $forced ? ' style="margin-top:8vh"' : '' ?>>
  <div class="card">
    <h2>Alterar palavra-passe</h2>
    <?php if ($forced): ?>
      <div class="alert warn">
        Esta é a sua primeira sessão (ou a palavra-passe foi reposta por um administrador).
        Defina uma palavra-passe pessoal antes de continuar.
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label><span class="req">Palavra-passe atual</span>
        <input type="password" name="current" required autocomplete="current-password" autofocus
               value="<?= e(keep_password('current')) ?>">
      </label>
      <label><span class="req">Nova palavra-passe</span>
        <input type="password" name="new" required autocomplete="new-password"
               value="<?= e(keep_password('new')) ?>">
      </label>
      <label><span class="req">Confirmar nova palavra-passe</span>
        <input type="password" name="confirm" required autocomplete="new-password"
               value="<?= e(keep_password('confirm')) ?>">
      </label>
      <p class="muted">
        Mínimo <?= password_min_length() ?> caracteres,
        com pelo menos uma letra e um algarismo.
      </p>
      <button class="primary" type="submit">Guardar</button>
      <?php if (!$forced): ?><a class="btn" href="index.php">Cancelar</a><?php endif; ?>
    </form>
  </div>
</div>
<?php layout_foot(); ?>
