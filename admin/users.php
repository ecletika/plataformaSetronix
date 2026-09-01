<?php
/** Gestão de utilizadores: criar, editar, repor palavra-passe, repor MFA. */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/apps.php';
require_once __DIR__ . '/../lib/layout.php';

$me = require_login('users.manage');

$error = '';
$generatedPassword = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/** Gera uma palavra-passe inicial legível. */
function suggest_password(): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pw = '';
    for ($i = 0; $i < 14; $i++) {
        $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $pw . random_int(10, 99);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $target = $id ? q_one('SELECT * FROM users WHERE id = ?', [$id]) : null;

    try {
        if ($action === 'create' || $action === 'update') {
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $email    = trim((string)($_POST['email'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $role     = (string)($_POST['role'] ?? 'leitor');
            $active   = isset($_POST['is_active']) ? 1 : 0;

            if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
                throw new RuntimeException('Utilizador inválido: use 3 a 64 caracteres (letras minúsculas, números, ponto, hífen ou underscore).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('E-mail inválido.');
            }
            if ($fullName === '') {
                throw new RuntimeException('Indique o nome completo.');
            }
            if (!isset(ROLES[$role])) {
                throw new RuntimeException('Perfil inválido.');
            }

            $clash = q_one('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?',
                           [$username, $email, $id]);
            if ($clash) {
                throw new RuntimeException('Já existe um utilizador com esse nome de utilizador ou e-mail.');
            }

            if ($action === 'create') {
                $pw = trim((string)($_POST['password'] ?? '')) ?: suggest_password();
                if ($problems = password_problems($pw)) {
                    throw new RuntimeException('A palavra-passe inicial deve ' . implode(', ', $problems) . '.');
                }
                $exigeMfa = isset($_POST['mfa_required']) ? 1 : 0;
                q(
                    'INSERT INTO users (username, email, full_name, password_hash, role, is_active,
                                        must_change_pw, mfa_required, created_by)
                     VALUES (?,?,?,?,?,?,1,?,?)',
                    [$username, $email, $fullName, password_hash($pw, PASSWORD_DEFAULT), $role, $active,
                     $exigeMfa, (int)$me['id']]
                );
                $newId = (int)db()->lastInsertId();
                if (isset($_POST['apps_submitted'])) {
                    user_set_apps($newId, (array)($_POST['apps'] ?? []));
                }
                audit('create', 'user', $newId, 'Utilizador criado: ' . $username, null,
                      ['username' => $username, 'email' => $email, 'role' => $role, 'is_active' => $active]);
                $generatedPassword = ['username' => $username, 'password' => $pw];
                flash('ok', 'Utilizador "' . $username . '" criado.');
            } else {
                if (!$target) {
                    throw new RuntimeException('Utilizador não encontrado.');
                }
                // Salvaguarda: não permitir ficar sem administradores ativos.
                if ($target['role'] === 'admin' && ($role !== 'admin' || $active === 0)) {
                    $admins = (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$id]);
                    if ($admins === 0) {
                        throw new RuntimeException('Tem de existir pelo menos um administrador ativo. Crie outro antes de alterar este.');
                    }
                }
                q('UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, is_active = ?,
                          mfa_required = ? WHERE id = ?',
                  [$username, $email, $fullName, $role, $active,
                   isset($_POST['mfa_required']) ? 1 : 0, $id]);
                if (isset($_POST['apps_submitted'])) {
                    user_set_apps($id, (array)($_POST['apps'] ?? []));
                }
                audit('update', 'user', $id, 'Utilizador atualizado: ' . $username,
                      audit_scrub($target),
                      ['username' => $username, 'email' => $email, 'full_name' => $fullName,
                       'role' => $role, 'is_active' => $active]);
                flash('ok', 'Utilizador atualizado.');
                redirect('users.php');
            }
        } elseif ($action === 'reset_password') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $pw = suggest_password();
            q('UPDATE users SET password_hash = ?, must_change_pw = 1, failed_logins = 0, locked_until = NULL WHERE id = ?',
              [password_hash($pw, PASSWORD_DEFAULT), $id]);
            audit('password_reset', 'user', $id, 'Palavra-passe reposta por administrador');
            $generatedPassword = ['username' => $target['username'], 'password' => $pw];
            flash('warn', 'Palavra-passe reposta. Entregue-a ao utilizador por um canal seguro.');
        } elseif ($action === 'reset_mfa') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            user_set_mfa_secret($id, null);
            q('UPDATE users SET mfa_enabled = 0, mfa_confirmed_at = NULL WHERE id = ?', [$id]);
            q('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$id]);
            audit('mfa_reset', 'user', $id, 'MFA reposto por administrador: ' . $target['username']);
            flash('ok', 'MFA removido. O utilizador irá associar um novo dispositivo no próximo início de sessão.');
            redirect('users.php');
        } elseif ($action === 'unlock') {
            q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]);
            audit('unlock', 'user', $id, 'Conta desbloqueada por administrador');
            flash('ok', 'Conta desbloqueada.');
            redirect('users.php');
        } elseif ($action === 'revoke_sessions') {
            q('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            audit('revoke_sessions', 'user', $id, 'Sessões terminadas por administrador');
            flash('ok', 'Sessões do utilizador terminadas.');
            redirect('users.php');
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$editing = $editId ? q_one('SELECT * FROM users WHERE id = ?', [$editId]) : null;

// Se uma edição falhou, continuar em modo de edição mesmo sem o ?edit= no URL.
if ($error !== '' && ($_POST['action'] ?? '') === 'update' && !$editing && !empty($_POST['id'])) {
    $editing = q_one('SELECT * FROM users WHERE id = ?', [(int)$_POST['id']]);
}

/**
 * Valores a mostrar no formulário.
 *
 * Quando uma submissão falha, o que foi escrito tem precedência sobre o que
 * está na base de dados — caso contrário o formulário voltava vazio e era
 * preciso escrever tudo outra vez só porque a palavra-passe era curta.
 */
$repost = $error !== '' && in_array((string)($_POST['action'] ?? ''), ['create', 'update'], true);
$form = [
    'username'  => $repost ? (string)($_POST['username'] ?? '')   : (string)($editing['username'] ?? ''),
    'email'     => $repost ? (string)($_POST['email'] ?? '')      : (string)($editing['email'] ?? ''),
    'full_name' => $repost ? (string)($_POST['full_name'] ?? '')  : (string)($editing['full_name'] ?? ''),
    'role'      => $repost ? (string)($_POST['role'] ?? 'leitor') : (string)($editing['role'] ?? 'leitor'),
    'password'  => $repost ? (string)($_POST['password'] ?? '')   : '',
    'is_active' => $repost
        ? isset($_POST['is_active'])
        : (!$editing || (int)$editing['is_active'] === 1),
    'mfa_required' => $repost
        ? isset($_POST['mfa_required'])
        : ($editing ? (int)$editing['mfa_required'] === 1 : mfa_enforced_globally()),
    'apps' => $repost
        ? array_map('intval', (array)($_POST['apps'] ?? []))
        : ($editing ? user_app_ids((int)$editing['id']) : []),
];

$todasApps = apps_all(false);

// Quantos utilizadores estao atribuidos a cada aplicacao: sem nenhum, a
// aplicacao e visivel para todos.
$restritas = [];
foreach (q_all('SELECT app_id, COUNT(*) AS n FROM user_apps GROUP BY app_id') as $r) {
    $restritas[(int)$r['app_id']] = (int)$r['n'];
}

$users = q_all('SELECT * FROM users ORDER BY is_active DESC, role, username');

layout_head('Utilizadores', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('users'); ?>

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

<?php if ($generatedPassword): ?>
  <div class="card">
    <h2>Palavra-passe inicial</h2>
    <div class="alert warn">
      Anote agora — não voltará a ser mostrada. Entregue-a ao utilizador por um canal seguro
      (pessoalmente ou por telefone, nunca no corpo de um e-mail juntamente com o utilizador).
    </div>
    <p>Utilizador: <b class="mono"><?= e($generatedPassword['username']) ?></b></p>
    <div class="secret"><?= e($generatedPassword['password']) ?></div>
    <p class="muted" style="margin-top:8px">
      No primeiro início de sessão será obrigatório alterar a palavra-passe e ativar o MFA.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <h2><?= $editing ? 'Editar utilizador' : 'Criar utilizador' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="grid2">
      <label><span class="req">Nome de utilizador</span>
        <input type="text" name="username" required pattern="[a-z0-9._\-]{3,64}"
               value="<?= e($form['username']) ?>" placeholder="ex.: jsilva">
      </label>
      <label><span class="req">E-mail</span>
        <input type="email" name="email" required value="<?= e($form['email']) ?>">
      </label>
      <label><span class="req">Nome completo</span>
        <input type="text" name="full_name" required value="<?= e($form['full_name']) ?>">
      </label>
      <label><span class="req">Perfil</span>
        <select name="role">
          <?php foreach (ROLES as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $form['role'] === $code ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if (!$editing): ?>
        <label>Palavra-passe inicial
          <input type="text" name="password" value="<?= e($form['password']) ?>"
                 placeholder="deixar vazio para gerar automaticamente">
        </label>
      <?php endif; ?>
      <label style="align-self:end">
        <input type="checkbox" name="is_active" value="1" style="width:auto;margin-right:6px"
               <?= $form['is_active'] ? 'checked' : '' ?>>
        Conta ativa
      </label>
      <?php if (!mfa_enforced_globally()): ?>
        <label style="align-self:end">
          <input type="checkbox" name="mfa_required" value="1" style="width:auto;margin-right:6px"
                 <?= $form['mfa_required'] ? 'checked' : '' ?>>
          Exigir MFA a esta conta
        </label>
      <?php else: ?>
        <input type="hidden" name="mfa_required" value="1">
      <?php endif; ?>
    </div>
    <?php if ($todasApps): ?>
      <h3>Acesso às aplicações</h3>
      <input type="hidden" name="apps_submitted" value="1">
      <?php
        $itens = [];
        foreach ($todasApps as $a) {
            $aberta = empty($restritas[(int)$a['id']]);
            $itens[] = [
                'id'      => (int)$a['id'],
                'title'   => $a['name'],
                'sub'     => (string)$a['description'],
                'mark'    => mb_strtoupper(mb_substr($a['name'], 0, 1)),
                'granted' => in_array((int)$a['id'], $form['apps'], true),
                // Abertas a toda a gente aparecem à direita, fixas: quem está
                // a ver esta página precisa de saber que a pessoa também as vê.
                'locked'  => $aberta,
                'note'    => $aberta ? 'aberta a todos' : '',
            ];
        }
        transfer_list('apps', $itens, [
            'left'        => 'Sem acesso',
            'right'       => 'Vê estas aplicações',
            'empty_left'  => 'Nada por atribuir.',
            'empty_right' => 'Este utilizador não vê nenhuma aplicação.',
            'hint'        => 'As aplicações marcadas <b>aberta a todos</b> não se retiram aqui: '
                           . 'estão assim porque ninguém lhes foi atribuído. Para as reservar, '
                           . 'abra a aplicação em <a href="apps.php">Aplicações</a> e escolha quem a pode ver.',
        ]);
      ?>
    <?php endif; ?>

    <p class="muted">
      <b>Permissões:</b>
      Administrador — tudo, incluindo contas e log de alterações ·
      Gestor de aplicações — envia e substitui as aplicações HTML ·
      Utilizador e Consulta — abrem as aplicações publicadas.
    </p>
    <div class="actions">
      <button class="primary" type="submit"><?= $editing ? 'Guardar alterações' : 'Criar utilizador' ?></button>
      <?php if ($editing): ?><a class="btn" href="users.php">Cancelar</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>Utilizadores (<?= count($users) ?>)</h2>
  <div class="scroll">
    <table>
      <thead>
        <tr><th>Utilizador</th><th>Nome</th><th>Perfil</th><th>Estado</th><th>MFA</th>
            <th>Último acesso</th><th>Ações</th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
          $locked = !empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time(); ?>
        <tr>
          <td class="mono"><?= e($u['username']) ?><br><span class="muted"><?= e($u['email']) ?></span></td>
          <td><?= e($u['full_name']) ?></td>
          <td><span class="tag <?= e($u['role']) ?>"><?= e(ROLES[$u['role']] ?? $u['role']) ?></span></td>
          <td>
            <?= (int)$u['is_active'] === 1 ? '<span class="tag on">Ativo</span>' : '<span class="tag off">Inativo</span>' ?>
            <?= $locked ? '<br><span class="tag off">Bloqueado</span>' : '' ?>
          </td>
          <td><?= (int)$u['mfa_enabled'] === 1 ? '<span class="tag on">Ativo</span>' : '<span class="tag off">Por ativar</span>' ?></td>
          <td class="muted mono"><?= e((string)($u['last_login_at'] ?? '—')) ?></td>
          <td>
            <div class="actions">
              <a class="btn" href="users.php?edit=<?= (int)$u['id'] ?>">Editar</a>
              <form method="post" onsubmit="return confirm('Repor a palavra-passe de <?= e($u['username']) ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button type="submit">Repor palavra-passe</button>
              </form>
              <form method="post" onsubmit="return confirm('Remover o MFA de <?= e($u['username']) ?>? Vai ter de associar um novo dispositivo.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_mfa">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button type="submit">Repor MFA</button>
              </form>
              <?php if ($locked): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unlock">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button type="submit">Desbloquear</button>
                </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Terminar todas as sessões deste utilizador?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke_sessions">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button type="submit">Terminar sessões</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="margin-top:10px">
    As contas não são apagadas — são desativadas, para que o log de alterações continue coerente.
  </p>
</div>

</div>
<?php layout_foot(); ?>
