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

/**
 * Guarda a aplicação escolhida no formulário, se for uma das que a pessoa
 * ficou a ter. Um valor que não corresponda é ignorado em silêncio: vem de
 * um formulário submetido antes de a lista de aplicações mudar.
 */
function escolher_predefinida(int $userId, string $role, int $appId): void
{
    if ($appId <= 0) {
        return;
    }
    foreach (apps_for_user($userId, in_array($role, ['admin', 'gestor'], true)) as $a) {
        if ((int)$a['id'] === $appId) {
            app_set_default($userId, $appId, 'admin');
            return;
        }
    }
}

/**
 * Avisa quem está a atribuir aplicações de que falta escolher qual abre.
 *
 * Com uma aplicação só, a plataforma escolhe sozinha e não há nada a dizer.
 * Com duas ou mais, a decisão é de quem atribui — e sem aviso ficaria por
 * fazer sem ninguém dar por isso.
 */
function aviso_predefinida(array $estado, string $username, int $userId): void
{
    if ($estado['precisa_escolher']) {
        flash('warn', $username . ' passa a ter ' . $estado['apps'] . ' aplicações e nenhuma '
            . 'escolhida para abrir ao entrar — vai encontrar a lista. Escolha uma na ficha, '
            . 'em "Aplicação a abrir ao entrar".');
    } elseif ($estado['escolhida'] && $estado['apps'] === 1) {
        flash('ok', 'Como é a única aplicação de ' . $username . ', "'
            . $estado['escolhida']['name'] . '" passa a abrir logo ao entrar.');
    }
}

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
                escolher_predefinida($newId, $role, (int)($_POST['default_app'] ?? 0));
                $dflt = app_sync_default($newId, in_array($role, ['admin', 'gestor'], true));
                audit('create', 'user', $newId, 'Utilizador criado: ' . $username, null,
                      ['username' => $username, 'email' => $email, 'role' => $role, 'is_active' => $active]);
                $generatedPassword = ['username' => $username, 'password' => $pw];
                flash('ok', 'Utilizador "' . $username . '" criado.');
                aviso_predefinida($dflt, $username, $newId);
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
                escolher_predefinida($id, $role, (int)($_POST['default_app'] ?? 0));
                $dflt = app_sync_default($id, in_array($role, ['admin', 'gestor'], true));
                audit('update', 'user', $id, 'Utilizador atualizado: ' . $username,
                      audit_scrub($target),
                      ['username' => $username, 'email' => $email, 'full_name' => $fullName,
                       'role' => $role, 'is_active' => $active]);
                flash('ok', 'Utilizador atualizado.');
                aviso_predefinida($dflt, $username, $id);
                redirect('users.php?ficha=' . $id);
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
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'toggle_mfa_required') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $novo = (int)$target['mfa_required'] === 1 ? 0 : 1;
            q('UPDATE users SET mfa_required = ? WHERE id = ?', [$novo, $id]);
            audit('update', 'user', $id,
                  ($novo ? 'MFA passa a ser exigido a ' : 'MFA deixa de ser exigido a ') . $target['username']);
            flash('ok', $novo
                ? 'O MFA passa a ser exigido a ' . $target['username'] . ' no próximo início de sessão.'
                : 'O MFA deixa de ser exigido a ' . $target['username'] . '.');
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'predefinida') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $appId = (int)($_POST['default_app'] ?? 0);
            $disponiveis = apps_for_user($id, in_array($target['role'], ['admin', 'gestor'], true));

            $valida = $appId === 0;
            $nome   = '';
            foreach ($disponiveis as $da) {
                if ((int)$da['id'] === $appId) {
                    $valida = true;
                    $nome   = (string)$da['name'];
                    break;
                }
            }
            if (!$valida) {
                throw new RuntimeException('Essa aplicação não está disponível para este utilizador.');
            }
            app_set_default($id, $appId, 'admin');
            audit('update', 'user', $id, $appId === 0
                ? 'Sem aplicação a abrir ao entrar: ' . $target['username']
                : 'Aplicação a abrir ao entrar de ' . $target['username'] . ': ' . $nome);
            flash('ok', $appId === 0
                ? $target['username'] . ' passa a ver a lista ao entrar.'
                : $target['username'] . ' passa a abrir "' . $nome . '" ao entrar.');
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'repor_app') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $appId = (int)($_POST['app_id'] ?? 0);
            $app   = $appId ? app_find($appId) : null;
            if (!$app) {
                throw new RuntimeException('Aplicação não encontrada.');
            }
            user_unhide_app($id, $appId);
            app_sync_default($id, in_array($target['role'], ['admin', 'gestor'], true));
            audit('update', 'user', $id,
                  'Reposta a aplicação "' . $app['name'] . '" a ' . $target['username']);
            flash('ok', '"' . $app['name'] . '" voltou à lista de ' . $target['username'] . '.');
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'repor_todas') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $n = count(user_hidden_apps($id));
            q('DELETE FROM user_apps_hidden WHERE user_id = ?', [$id]);
            app_sync_default($id, in_array($target['role'], ['admin', 'gestor'], true));
            audit('update', 'user', $id,
                  'Repostas ' . $n . ' aplicação(ões) a ' . $target['username']);
            flash('ok', $n . ' aplicação(ões) reposta(s) na lista de ' . $target['username'] . '.');
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'repor_app') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $appId = (int)($_POST['app_id'] ?? 0);
            $app   = $appId ? app_find($appId) : null;
            if (!$app) {
                throw new RuntimeException('Aplicação não encontrada.');
            }
            user_unhide_app($id, $appId);
            audit('update', 'user', $id,
                  'Reposta a aplicação "' . $app['name'] . '" a ' . $target['username']);
            flash('ok', '"' . $app['name'] . '" voltou à lista de ' . $target['username'] . '.');
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'repor_todas') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $n = count(user_hidden_apps($id));
            q('DELETE FROM user_apps_hidden WHERE user_id = ?', [$id]);
            audit('update', 'user', $id,
                  'Repostas ' . $n . ' aplicação(ões) a ' . $target['username']);
            flash('ok', $n . ' aplicação(ões) reposta(s) na lista de ' . $target['username'] . '.');
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'toggle_active') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $novo = (int)$target['is_active'] === 1 ? 0 : 1;
            if ($novo === 0) {
                if ((int)$target['id'] === (int)$me['id']) {
                    throw new RuntimeException('Não pode desativar a sua própria conta.');
                }
                if ($target['role'] === 'admin') {
                    $admins = (int)q_val("SELECT COUNT(*) FROM users
                                           WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$id]);
                    if ($admins === 0) {
                        throw new RuntimeException('Tem de existir pelo menos um administrador ativo.');
                    }
                }
            }
            q('UPDATE users SET is_active = ? WHERE id = ?', [$novo, $id]);
            if ($novo === 0) {
                q('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            }
            audit('update', 'user', $id, ($novo ? 'Conta reativada: ' : 'Conta desativada: ') . $target['username']);
            flash('ok', $novo
                ? 'Conta de ' . $target['username'] . ' reativada.'
                : 'Conta de ' . $target['username'] . ' desativada e sessões terminadas.');
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'unlock') {
            q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]);
            audit('unlock', 'user', $id, 'Conta desbloqueada por administrador');
            flash('ok', 'Conta desbloqueada.');
            redirect('users.php?ficha=' . $id);
        } elseif ($action === 'revoke_sessions') {
            q('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            audit('revoke_sessions', 'user', $id, 'Sessões terminadas por administrador');
            flash('ok', 'Sessões do utilizador terminadas.');
            redirect('users.php?ficha=' . $id);
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
    'default_app' => $repost
        ? (int)($_POST['default_app'] ?? 0)
        : ($editing
            ? (int)q_val('SELECT pvalue FROM user_prefs WHERE user_id = ? AND pkey = ?',
                         [(int)$editing['id'], 'default_app'])
            : 0),
];

$todasApps = apps_all(false);

// Quantos utilizadores estao atribuidos a cada aplicacao: sem nenhum, a
// aplicacao e visivel para todos.
$restritas = [];
foreach (q_all('SELECT app_id, COUNT(*) AS n FROM user_apps GROUP BY app_id') as $r) {
    $restritas[(int)$r['app_id']] = (int)$r['n'];
}

$users = q_all('SELECT * FROM users ORDER BY is_active DESC, role, username');

// Quem esta em linha: sessao por revogar, com atividade nos ultimos 5 minutos.
// O carimbo e atualizado a cada pedido feito com sessao iniciada.
$online = [];
foreach (q_all('SELECT DISTINCT user_id FROM user_sessions
                 WHERE revoked_at IS NULL AND mfa_passed = 1
                   AND last_seen_at > (NOW() - INTERVAL 5 MINUTE)') as $r) {
    $online[(int)$r['user_id']] = true;
}

// Ficha da pessoa escolhida na tabela. Sem escolha, mostra-se a primeira —
// evita o ecrã com um espaço vazio à espera de um clique.
$fichaId = isset($_GET['ficha']) ? (int)$_GET['ficha'] : 0;
if ($generatedPassword) {
    // Acabou de ser gerada uma palavra-passe: fica na ficha desse utilizador.
    $fichaId = (int)(q_val('SELECT id FROM users WHERE username = ?',
                           [$generatedPassword['username']]) ?? $fichaId);
}
$ficha = null;
foreach ($users as $u) {
    if ((int)$u['id'] === $fichaId) {
        $ficha = $u;
        break;
    }
}
if (!$ficha && $users) {
    $ficha = $users[0];
}
$fichaSessoes = $ficha
    ? (int)q_val('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL
                   AND last_seen_at > (NOW() - INTERVAL 12 HOUR)', [(int)$ficha['id']])
    : 0;
$fichaCodigos = $ficha && (int)$ficha['mfa_enabled'] === 1
    ? mfa_recovery_codes_left((int)$ficha['id'])
    : 0;
// Aplicações que esta pessoa retirou da própria lista, para poderem ser repostas.
$fichaEscondidas = $ficha ? user_hidden_apps((int)$ficha['id']) : [];

// Aplicações desta pessoa e qual delas abre ao entrar.
$fichaGere  = $ficha && in_array($ficha['role'], ['admin', 'gestor'], true);
$fichaApps  = $ficha ? apps_for_user((int)$ficha['id'], $fichaGere) : [];
$fichaDflt  = 0;
$fichaQuem  = 'automatica';
if ($ficha) {
    $fichaDflt = (int)q_val('SELECT pvalue FROM user_prefs WHERE user_id = ? AND pkey = ?',
                            [(int)$ficha['id'], 'default_app']);
    $fichaQuem = app_default_origin((int)$ficha['id']);
}
// Aplicações que esta pessoa retirou da própria lista, para poderem ser repostas.
$fichaEscondidas = $ficha ? user_hidden_apps((int)$ficha['id']) : [];

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

<div class="card" id="form">
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

      <label style="max-width:420px;margin-top:14px">Aplicação a abrir ao entrar
        <select name="default_app" id="default_app">
          <option value="0">Nenhuma — mostrar a lista</option>
          <?php foreach ($todasApps as $a): ?>
            <option value="<?= (int)$a['id'] ?>"
                    <?= $form['default_app'] === (int)$a['id'] ? 'selected' : '' ?>>
              <?= e($a['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="muted" style="margin:-6px 0 14px">
        Com uma aplicação só, é essa automaticamente. Com duas ou mais, escolha aqui qual
        abre — a pessoa pode trocá-la depois, na estrela do menu <i>Aplicações</i>.
      </p>

      <script>
      // A lista de escolhas segue o painel "Vê estas aplicações": não faz
      // sentido oferecer uma aplicação que se acabou de retirar.
      (function () {
        var sel = document.getElementById('default_app');
        var dir = document.querySelector('#tr-apps .tr-panel[data-side="right"]');
        if (!sel || !dir) { return; }
        function sincronizar() {
          var antes = sel.value;
          var itens = dir.querySelectorAll('.tr-item');
          sel.innerHTML = '';
          var nenhuma = new Option('Nenhuma — mostrar a lista', '0');
          sel.appendChild(nenhuma);
          itens.forEach(function (it) {
            sel.appendChild(new Option(it.dataset.nome, it.dataset.id));
          });
          sel.value = antes;
          if (!sel.value) { sel.value = '0'; }
        }
        document.querySelector('#tr-apps').addEventListener('change', function (ev) {
          if (ev.target.matches('.tr-item input')) { setTimeout(sincronizar, 0); }
        });
        sincronizar();
      })();
      </script>
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
  <p class="muted">Escolha uma linha para ver a ficha e agir sobre a conta.</p>
  <div class="scroll">
    <table>
      <thead>
        <tr><th>Utilizador</th><th>Nome</th><th>Perfil</th><th>Estado</th><th>MFA</th>
            <th>Presença</th><th>Último acesso</th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
          $locked = !empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time();
          $sel    = $ficha && (int)$ficha['id'] === (int)$u['id']; ?>
        <tr class="rowlink"<?= $sel ? ' aria-current="true"' : '' ?>>
          <td class="mono">
            <a class="rowname" href="users.php?ficha=<?= (int)$u['id'] ?>#ficha"><?= e($u['username']) ?></a>
            <br><span class="muted"><?= e($u['email']) ?></span>
          </td>
          <td><?= e($u['full_name']) ?></td>
          <td><span class="tag <?= e($u['role']) ?>"><?= e(ROLES[$u['role']] ?? $u['role']) ?></span></td>
          <td>
            <?= (int)$u['is_active'] === 1
                ? '<span class="tag on">Ativo</span>'
                : '<span class="tag off">Inativo</span>' ?>
            <?= $locked ? '<br><span class="tag off">Bloqueado</span>' : '' ?>
          </td>
          <td>
            <?php if ((int)$u['mfa_enabled'] === 1): ?>
              <span class="tag on">Associado</span>
            <?php else: ?>
              <span class="tag off">Por associar</span>
            <?php endif; ?>
            <?php if ((int)$u['mfa_required'] === 1 || mfa_enforced_globally()): ?>
              <br><span class="muted" style="font-size:11px">exigido</span>
            <?php endif; ?>
          </td>
          <td>
            <?php $emLinha = isset($online[(int)$u['id']]); ?>
            <span class="presenca">
              <span class="dot <?= $emLinha ? 'on' : 'off' ?>"></span>
              <span class="txt"><?= $emLinha ? 'Em linha' : 'Ausente' ?></span>
            </span>
          </td>
          <td class="muted mono"><?= e((string)($u['last_login_at'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="margin-top:12px">
    As contas não são apagadas &mdash; são desativadas, para que o log de alterações continue coerente.
  </p>
</div>

<?php if ($ficha):
    $fLocked = !empty($ficha['locked_until']) && strtotime((string)$ficha['locked_until']) > time();
    $fMfa    = (int)$ficha['mfa_enabled'] === 1;
    $fExige  = (int)$ficha['mfa_required'] === 1;
    $fEu     = (int)$ficha['id'] === (int)$me['id'];
    $accao   = static function (string $nome, int $id): void {
        echo csrf_field()
           . '<input type="hidden" name="action" value="' . e($nome) . '">'
           . '<input type="hidden" name="id" value="' . $id . '">';
    }; ?>
<div class="card" id="ficha">
  <div class="ficha-head">
    <h3><?= e($ficha['full_name']) ?></h3>
    <span class="tag <?= e($ficha['role']) ?>"><?= e(ROLES[$ficha['role']] ?? $ficha['role']) ?></span>
    <span class="ficha-sub mono" style="margin:0"><?= e($ficha['username']) ?> ·
      <?= e($ficha['email']) ?></span>
  </div>

  <!-- Estado: o que há para ler sobre esta conta -->
  <div class="chips">
    <?php if ($fMfa): ?>
      <span class="chip bom">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
             aria-hidden="true"><rect x="3" y="7" width="10" height="6.5" rx="1.4"></rect>
          <path d="M5.4 7V4.9a2.6 2.6 0 0 1 5.2 0V7"></path></svg>
        MFA associado desde <?= e(substr((string)$ficha['mfa_confirmed_at'], 0, 10)) ?>
      </span>
      <span class="chip"><?= $fichaCodigos ?> código(s) de recuperação</span>
    <?php else: ?>
      <span class="chip mau">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
             aria-hidden="true"><rect x="3" y="7" width="10" height="6.5" rx="1.4"></rect>
          <path d="M5.4 7V4.9a2.6 2.6 0 0 1 5.2 0"></path></svg>
        MFA por associar
      </span>
    <?php endif; ?>

    <?php if (mfa_enforced_globally() || $fExige): ?>
      <span class="chip">MFA exigido</span>
    <?php endif; ?>

    <span class="chip">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
           aria-hidden="true"><circle cx="8" cy="8" r="5.6"></circle><path d="M8 4.9V8l2.1 1.5"></path></svg>
      <?= $fichaSessoes ?> sessão(ões) aberta(s)
    </span>

    <?php if ($fichaDflt > 0): ?>
      <?php foreach ($fichaApps as $fa): if ((int)$fa['id'] !== $fichaDflt) { continue; } ?>
        <span class="chip star">
          <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
            <path d="M8 1.6l1.94 3.93 4.34.63-3.14 3.06.74 4.32L8 11.5l-3.88 2.04.74-4.32L1.72 6.16l4.34-.63z"></path>
          </svg>
          Abre: <?= e($fa['name']) ?>
        </span>
      <?php endforeach; ?>
    <?php elseif (count($fichaApps) > 1): ?>
      <span class="chip mau">Sem aplicação escolhida — vê a lista</span>
    <?php elseif (!$fichaApps): ?>
      <span class="chip mau">Sem aplicações atribuídas</span>
    <?php endif; ?>

    <span class="chip">
      <?= $fichaEscondidas
          ? count($fichaEscondidas) . ' aplicação(ões) retirada(s)'
          : 'Nenhuma aplicação retirada' ?>
    </span>

    <?php if ($fLocked): ?><span class="chip mau">Bloqueada por tentativas</span><?php endif; ?>
    <?php if ((int)$ficha['is_active'] !== 1): ?><span class="chip mau">Conta desativada</span><?php endif; ?>
  </div>

  <!-- Ações: o que há para fazer a esta conta -->
  <div class="fila">
    <a class="btn" href="users.php?edit=<?= (int)$ficha['id'] ?>#form">Editar dados</a>

    <form method="post" onsubmit="return confirm('Repor a palavra-passe de <?= e($ficha['username']) ?>?')">
      <?php $accao('reset_password', (int)$ficha['id']); ?>
      <button type="submit">Repor palavra-passe</button>
    </form>

    <form method="post" onsubmit="return confirm('Terminar todas as sessões de <?= e($ficha['username']) ?>?')">
      <?php $accao('revoke_sessions', (int)$ficha['id']); ?>
      <button type="submit">Terminar sessões</button>
    </form>

    <?php if (!mfa_enforced_globally()): ?>
      <form method="post">
        <?php $accao('toggle_mfa_required', (int)$ficha['id']); ?>
        <button type="submit"><?= $fExige ? 'Deixar de exigir MFA' : 'Exigir MFA' ?></button>
      </form>
    <?php endif; ?>

    <?php if ($fMfa): ?>
      <form method="post" onsubmit="return confirm('Remover o MFA de <?= e($ficha['username']) ?>? Vai ter de associar um novo dispositivo.')">
        <?php $accao('reset_mfa', (int)$ficha['id']); ?>
        <button type="submit">Repor MFA</button>
      </form>
    <?php endif; ?>

    <?php if ($fLocked): ?>
      <form method="post">
        <?php $accao('unlock', (int)$ficha['id']); ?>
        <button type="submit">Desbloquear conta</button>
      </form>
    <?php endif; ?>

    <?php if (count($fichaApps) > 1): ?>
      <details class="gaveta" style="width:auto">
        <summary>Mudar aplicação</summary>
        <div class="gaveta-corpo">
          <form method="post">
            <?php $accao('predefinida', (int)$ficha['id']); ?>
            <select name="default_app">
              <option value="0" <?= $fichaDflt === 0 ? 'selected' : '' ?>>Nenhuma — mostrar a lista</option>
              <?php foreach ($fichaApps as $fa): ?>
                <option value="<?= (int)$fa['id'] ?>" <?= $fichaDflt === (int)$fa['id'] ? 'selected' : '' ?>>
                  <?= e($fa['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="primary" type="submit">Guardar</button>
          </form>
          <p class="nota">
            <?php if ($fichaQuem === 'utilizador'): ?>
              Foi <b>o próprio</b> que escolheu. Pode alterar aqui, mas ele volta a mudá-la
              quando quiser, na estrela do menu <i>Aplicações</i>.
            <?php else: ?>
              A pessoa pode trocá-la quando quiser, na estrela do menu <i>Aplicações</i>.
            <?php endif; ?>
          </p>
        </div>
      </details>
    <?php endif; ?>

    <?php if ($fichaEscondidas): ?>
      <details class="gaveta" style="width:auto">
        <summary>Repor aplicações</summary>
        <div class="gaveta-corpo">
          <?php foreach ($fichaEscondidas as $ea): ?>
            <form method="post">
              <?php $accao('repor_app', (int)$ficha['id']); ?>
              <input type="hidden" name="app_id" value="<?= (int)$ea['id'] ?>">
              <button type="submit"><?= e($ea['name']) ?></button>
            </form>
          <?php endforeach; ?>
          <?php if (count($fichaEscondidas) > 1): ?>
            <form method="post">
              <?php $accao('repor_todas', (int)$ficha['id']); ?>
              <button class="primary" type="submit">Repor todas</button>
            </form>
          <?php endif; ?>
          <p class="nota">
            Retirou-as da lista dele. Continua a ter acesso — só deixou de as ver.
          </p>
        </div>
      </details>
    <?php endif; ?>

    <?php if (!$fEu): ?>
      <form class="empurra" method="post"
            onsubmit="return confirm('<?= (int)$ficha['is_active'] === 1 ? 'Desativar' : 'Reativar' ?> a conta de <?= e($ficha['username']) ?>?')">
        <?php $accao('toggle_active', (int)$ficha['id']); ?>
        <button class="<?= (int)$ficha['is_active'] === 1 ? 'danger' : '' ?>" type="submit">
          <?= (int)$ficha['is_active'] === 1 ? 'Desativar conta' : 'Reativar conta' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

</div>
<script>
// A linha inteira segue a ligação do nome. Sem JavaScript, o nome
// continua a ser uma ligação normal.
document.querySelectorAll('tr.rowlink').forEach(function (tr) {
  tr.addEventListener('click', function (ev) {
    if (ev.target.closest('a, button, input, label')) { return; }
    var link = tr.querySelector('a.rowname');
    if (link) { window.location = link.href; }
  });
});
</script>
<?php layout_foot(); ?>
