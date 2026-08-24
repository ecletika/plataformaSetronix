<?php
/** "A minha conta": dados pessoais, MFA e códigos de recuperação. */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login('view');
$newCodes = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'new_codes') {
        // Exige a palavra-passe para gerar novos códigos.
        if (!password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
            $error = 'Palavra-passe incorreta.';
        } else {
            $newCodes = mfa_reset_recovery_codes((int)$user['id'],
                            (int)($CONFIG['security']['recovery_code_count'] ?? 10));
            audit('mfa_recovery_regen', 'user', $user['id'], 'Novos códigos de recuperação gerados');
        }
    } elseif ($action === 'reset_mfa') {
        if (!password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
            $error = 'Palavra-passe incorreta.';
        } else {
            user_set_mfa_secret((int)$user['id'], null);
            q('UPDATE users SET mfa_enabled = 0, mfa_confirmed_at = NULL WHERE id = ?', [(int)$user['id']]);
            q('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [(int)$user['id']]);
            audit('mfa_reset', 'user', $user['id'], 'MFA reposto pelo próprio (novo dispositivo)');
            auth_logout('reposição MFA');
            session_start();
            flash('ok', 'MFA removido. Volte a entrar para associar o novo dispositivo.');
            redirect('login.php');
        }
    } elseif ($action === 'profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Indique um nome e um e-mail válidos.';
        } else {
            $taken = q_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int)$user['id']]);
            if ($taken) {
                $error = 'Esse e-mail já está associado a outra conta.';
            } else {
                q('UPDATE users SET full_name = ?, email = ? WHERE id = ?',
                  [$fullName, $email, (int)$user['id']]);
                audit('update', 'user', $user['id'], 'Perfil atualizado pelo próprio',
                      ['full_name' => $user['full_name'], 'email' => $user['email']],
                      ['full_name' => $fullName, 'email' => $email]);
                flash('ok', 'Dados atualizados.');
                redirect('perfil.php');
            }
        }
    }
}

$user = q_one('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
$left = mfa_recovery_codes_left((int)$user['id']);
$sessions = q_all(
    'SELECT ip, user_agent, created_at, last_seen_at FROM user_sessions
      WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 10',
    [(int)$user['id']]
);

layout_head('A minha conta');
?>
<div class="wrap">

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

<?php if ($newCodes): ?>
  <div class="card">
    <h2>Novos códigos de recuperação</h2>
    <div class="alert warn">Os códigos anteriores deixaram de funcionar. Guarde estes agora — não voltarão a ser mostrados.</div>
    <div class="codes"><?php foreach ($newCodes as $c): ?><span><?= e($c) ?></span><?php endforeach; ?></div>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Dados da conta</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="profile">
    <div class="grid2">
      <label>Utilizador
        <input type="text" value="<?= e($user['username']) ?>" disabled>
      </label>
      <label>Perfil
        <input type="text" value="<?= e(ROLES[$user['role']] ?? $user['role']) ?>" disabled>
      </label>
      <label><span class="req">Nome completo</span>
        <input type="text" name="full_name" required value="<?= e($user['full_name']) ?>">
      </label>
      <label><span class="req">E-mail</span>
        <input type="email" name="email" required value="<?= e($user['email']) ?>">
      </label>
    </div>
    <div class="actions">
      <button class="primary" type="submit">Guardar alterações</button>
      <a class="btn" href="password.php">Alterar palavra-passe</a>
    </div>
    <p class="muted" style="margin-top:10px">
      Último início de sessão:
      <?= $user['last_login_at'] ? e($user['last_login_at']) . ' · ' . e((string)$user['last_login_ip']) : '—' ?>
    </p>
  </form>
</div>

<div class="card">
  <h2>Verificação em duas etapas (MFA)</h2>
  <p class="muted">
    Estado: <?= (int)$user['mfa_enabled'] === 1
        ? '<span class="tag on">Ativo desde ' . e((string)$user['mfa_confirmed_at']) . '</span>'
        : '<span class="tag off">Inativo</span>' ?>
    · Códigos de recuperação por usar: <b><?= $left ?></b>
  </p>
  <?php if ($left <= 2 && (int)$user['mfa_enabled'] === 1): ?>
    <div class="alert warn">Tem poucos códigos de recuperação. Gere um novo conjunto.</div>
  <?php endif; ?>

  <div class="grid2">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="new_codes">
      <h3>Gerar novos códigos</h3>
      <label>Confirme a palavra-passe
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button type="submit">Gerar novos códigos</button>
    </form>

    <form method="post" onsubmit="return confirm('Vai remover o MFA e terminar a sessão. Continuar?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_mfa">
      <h3>Trocar de telemóvel</h3>
      <label>Confirme a palavra-passe
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button class="danger" type="submit">Remover MFA e reassociar</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>Sessões ativas</h2>
  <div class="scroll">
    <table>
      <thead><tr><th>Início</th><th>Última atividade</th><th>IP</th><th>Dispositivo</th></tr></thead>
      <tbody>
      <?php foreach ($sessions as $s): ?>
        <tr>
          <td><?= e($s['created_at']) ?></td>
          <td><?= e($s['last_seen_at']) ?></td>
          <td class="mono"><?= e((string)$s['ip']) ?></td>
          <td class="muted"><?= e(mb_substr((string)$s['user_agent'], 0, 70)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$sessions): ?><tr><td colspan="4" class="muted">Sem sessões registadas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
<?php layout_foot(); ?>
