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
    } elseif ($action === 'cor') {
        $cor = strtolower(trim((string)($_POST['topbar_color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $cor)) {
            $error = 'Cor inválida.';
        } else {
            user_pref_set((int)$user['id'], 'topbar_color', $cor);
            flash('ok', 'Cor da barra alterada.');
            redirect('perfil.php');
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
$mfa  = (int)$user['mfa_enabled'] === 1;

// Se a submissão falhou, mantém o que foi escrito em vez de o deitar fora.
$repost   = $error !== '' && ($_POST['action'] ?? '') === 'profile';
$fullName = $repost ? (string)($_POST['full_name'] ?? '') : (string)$user['full_name'];
$email    = $repost ? (string)($_POST['email'] ?? '') : (string)$user['email'];

$sessions = q_all(
    'SELECT ip, user_agent, created_at, last_seen_at FROM user_sessions
      WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 10',
    [(int)$user['id']]
);

if ($error !== '') {
    no_store();
}
layout_head('A minha conta');
?>
<div class="wrap">

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

<?php if ($newCodes): ?>
  <div class="card">
    <h2>Novos códigos de recuperação</h2>
    <div class="alert warn">
      Os códigos anteriores deixaram de funcionar. Guarde estes agora — não voltarão a ser mostrados.
    </div>
    <div class="codes"><?php foreach ($newCodes as $c): ?><span><?= e($c) ?></span><?php endforeach; ?></div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="ficha-head">
    <h3><?= e($user['full_name']) ?></h3>
    <span class="tag <?= e($user['role']) ?>"><?= e(ROLES[$user['role']] ?? $user['role']) ?></span>
  </div>
  <p class="ficha-sub mono"><?= e($user['username']) ?></p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="profile">
    <div class="grid2">
      <label><span class="req">Nome completo</span>
        <input type="text" name="full_name" required value="<?= e($fullName) ?>">
      </label>
      <label><span class="req">E-mail</span>
        <input type="email" name="email" required value="<?= e($email) ?>">
      </label>
    </div>
    <div class="actions">
      <button class="primary" type="submit">Guardar alterações</button>
      <a class="btn" href="password.php">Alterar palavra-passe</a>
      <a class="btn" href="index.php?todas=1">As minhas aplicações</a>
      <span class="muted" style="margin-left:auto">
        Último início de sessão:
        <?= $user['last_login_at']
            ? e((string)$user['last_login_at']) . ' · ' . e((string)$user['last_login_ip'])
            : '—' ?>
      </span>
    </div>
  </form>
</div>

<div class="card">
  <h2>Verificação em duas etapas</h2>

<?php if (!$mfa): ?>
  <p class="muted">
    A conta está protegida apenas pela palavra-passe. Com a verificação em duas etapas,
    quem souber a sua palavra-passe continua sem conseguir entrar.
  </p>
  <!-- Uma caixa só: sem limite ficaria uma faixa esticada de ponta a ponta. -->
  <div class="ficha-grid" style="margin-top:14px;max-width:520px">
    <div class="dbox">
      <p class="t">Estado</p>
      <p style="font-size:13px"><span class="tag off">Por ativar</span></p>
      <p>
        Precisa de uma aplicação autenticadora no telemóvel — Google Authenticator,
        Microsoft Authenticator, Authy, 1Password ou Bitwarden. Leva menos de um minuto:
        lê um código QR e confirma com os seis dígitos.
      </p>
      <div class="spacer"></div>
      <a class="btn primary" href="mfa.php?ativar=1">Ativar agora</a>
    </div>
  </div>
<?php else: ?>
  <?php if ($left <= 2): ?>
    <div class="alert warn">
      Restam-lhe <?= $left ?> código(s) de recuperação. Gere um conjunto novo antes que
      acabem — sem eles, perder o telemóvel significa perder o acesso.
    </div>
  <?php endif; ?>

  <div class="ficha-grid">
    <div class="dbox">
      <p class="t">Estado</p>
      <p style="font-size:13px">
        <span class="tag on">Ativa</span>
        <span class="muted">desde <?= e(substr((string)$user['mfa_confirmed_at'], 0, 10)) ?></span>
      </p>
      <p>
        <b><?= $left ?></b> código(s) de recuperação por usar. Servem para entrar quando
        não tem o telemóvel à mão, e cada um só funciona uma vez.
      </p>
    </div>

    <div class="dbox">
      <p class="t">Gerar novos códigos</p>
      <p>
        Substitui todos os códigos por um conjunto novo. Os antigos deixam de funcionar
        imediatamente.
      </p>
      <div class="spacer"></div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="new_codes">
        <label style="margin-bottom:8px">Confirme a palavra-passe
          <input type="password" name="password" required autocomplete="current-password"
                 value="<?= e(($_POST['action'] ?? '') === 'new_codes' ? keep_password('password') : '') ?>">
        </label>
        <button type="submit">Gerar novos códigos</button>
      </form>
    </div>

    <div class="dbox">
      <p class="t">Trocar de telemóvel</p>
      <p>
        Remove o dispositivo associado e termina a sessão. A seguir volta a entrar e
        associa o telemóvel novo.
      </p>
      <div class="spacer"></div>
      <form method="post" onsubmit="return confirm('Vai remover o MFA e terminar a sessão. Continuar?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_mfa">
        <label style="margin-bottom:8px">Confirme a palavra-passe
          <input type="password" name="password" required autocomplete="current-password"
                 value="<?= e(($_POST['action'] ?? '') === 'reset_mfa' ? keep_password('password') : '') ?>">
        </label>
        <button class="danger" type="submit">Remover MFA e reassociar</button>
      </form>
    </div>
  </div>
<?php endif; ?>
</div>

<div class="card">
  <h2>Aparência</h2>
  <p class="muted">
    <b>Cor da barra de topo.</b> Só isso: o resto da plataforma não muda.
    A escolha é sua e não altera o que os outros veem. O texto da barra passa a
    preto ou a branco conforme o que se lê melhor sobre a cor escolhida.
  </p>
  <div class="cores" style="margin-top:14px">
    <?php $atual = topbar_color(); ?>
    <?php foreach (TOPBAR_CHOICES as $hex => $nome): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cor">
        <input type="hidden" name="topbar_color" value="<?= e($hex) ?>">
        <button class="cor" type="submit" style="background:<?= e($hex) ?>"
                title="<?= e($nome) ?>" aria-label="<?= e($nome) ?>"
                <?= $atual === $hex ? 'aria-current="true"' : '' ?>></button>
      </form>
    <?php endforeach; ?>

    <form method="post" class="actions" style="gap:6px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cor">
      <input type="color" name="topbar_color" value="<?= e($atual) ?>"
             style="width:44px;height:38px;padding:2px;margin:0;border-radius:10px;
                    border:2px solid var(--line);background:#fff;cursor:pointer"
             aria-label="Escolher outra cor">
      <button type="submit">Usar esta</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>Sessões ativas</h2>
  <p class="muted">Onde a sua conta está aberta neste momento. Se vir algo que não reconhece,
     altere a palavra-passe.</p>
  <div class="scroll" style="margin-top:12px">
    <table>
      <thead><tr><th>Início</th><th>Última atividade</th><th>IP</th><th>Dispositivo</th></tr></thead>
      <tbody>
      <?php foreach ($sessions as $s): ?>
        <tr>
          <td class="mono"><?= e($s['created_at']) ?></td>
          <td class="mono"><?= e($s['last_seen_at']) ?></td>
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
