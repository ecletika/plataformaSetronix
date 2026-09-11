<?php
/**
 * Contas: criar em cima, tratar da lista em baixo.
 *
 * Cada linha da tabela é um formulário. O nome, o e-mail e o utilizador
 * escrevem-se por cima; o perfil, o estado e a aplicação de arranque são
 * botões; e as seis ações da conta são os seis ícones da coluna Ações.
 *
 * Quem vê cada aplicação, e com que nível, decide-se em Permissões — aqui
 * trata-se só da conta em si.
 *
 * Sem JavaScript funciona tudo: os formulários vivem fora da tabela e os
 * controlos apontam-lhes com o atributo form=. O JavaScript só acrescenta
 * duas comodidades — marcar a linha por guardar e aplicar a estrela sem
 * carregar no botão ao lado.
 */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/apps.php';
require_once __DIR__ . '/../lib/layout.php';

$me = require_login('users.manage');

$error = '';
$generatedPassword = null;

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

/**
 * Impede que a plataforma fique sem nenhum administrador ativo.
 *
 * É a única salvaguarda que não se pode contornar: sem administradores não
 * há como voltar a criar um.
 */
function exige_outro_admin(array $alvo, int $ignorar): void
{
    if ($alvo['role'] !== 'admin') {
        return;
    }
    $restantes = (int)q_val("SELECT COUNT(*) FROM users
                              WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$ignorar]);
    if ($restantes === 0) {
        throw new RuntimeException('Tem de existir pelo menos um administrador ativo. '
            . 'Crie outro antes de mexer neste.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $target = $id ? q_one('SELECT * FROM users WHERE id = ?', [$id]) : null;

    // O perfil vem colado à ação porque um botão só consegue enviar um par
    // nome/valor, e aqui são precisos dois: o que fazer e para que perfil.
    $perfilNovo = '';
    if (strpos($action, 'perfil:') === 0) {
        $perfilNovo = substr($action, 7);
        $action     = 'perfil';
    }

    try {
        if ($action === 'create') {
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $email    = trim((string)($_POST['email'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $role     = (string)($_POST['role'] ?? 'supervisor');
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
            if (q_one('SELECT id FROM users WHERE username = ? OR email = ?', [$username, $email])) {
                throw new RuntimeException('Já existe um utilizador com esse nome de utilizador ou e-mail.');
            }

            $pw = trim((string)($_POST['password'] ?? '')) ?: suggest_password();
            if ($problems = password_problems($pw)) {
                throw new RuntimeException('A palavra-passe inicial deve ' . implode(', ', $problems) . '.');
            }
            $exigeMfa = mfa_enforced_globally() || isset($_POST['mfa_required']) ? 1 : 0;

            q(
                'INSERT INTO users (username, email, full_name, password_hash, role, is_active,
                                    must_change_pw, mfa_required, created_by)
                 VALUES (?,?,?,?,?,?,1,?,?)',
                [$username, $email, $fullName, password_hash($pw, PASSWORD_DEFAULT), $role, $active,
                 $exigeMfa, (int)$me['id']]
            );
            $newId = (int)db()->lastInsertId();
            app_sync_default($newId, in_array($role, ['admin', 'gestor'], true));
            audit('create', 'user', $newId, 'Utilizador criado: ' . $username, null,
                  ['username' => $username, 'email' => $email, 'role' => $role, 'is_active' => $active]);
            $generatedPassword = ['username' => $username, 'password' => $pw];
            flash('ok', 'Utilizador "' . $username . '" criado. Dê-lhe acesso às aplicações em '
                . 'Permissões.');

        } elseif ($action === 'linha') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $email    = trim((string)($_POST['email'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));

            if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
                throw new RuntimeException('Utilizador inválido: use 3 a 64 caracteres (letras minúsculas, números, ponto, hífen ou underscore).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('E-mail inválido.');
            }
            if ($fullName === '') {
                throw new RuntimeException('Indique o nome completo.');
            }
            if (q_one('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?',
                      [$username, $email, $id])) {
                throw new RuntimeException('Já existe um utilizador com esse nome de utilizador ou e-mail.');
            }

            q('UPDATE users SET username = ?, email = ?, full_name = ? WHERE id = ?',
              [$username, $email, $fullName, $id]);
            audit('update', 'user', $id, 'Utilizador atualizado: ' . $username,
                  audit_scrub($target),
                  ['username' => $username, 'email' => $email, 'full_name' => $fullName]);
            flash('ok', 'Dados de ' . $username . ' guardados.');
            redirect('users.php');

        } elseif ($action === 'perfil') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            if (!isset(ROLES[$perfilNovo])) {
                throw new RuntimeException('Perfil inválido.');
            }
            if ($perfilNovo !== $target['role']) {
                if ($perfilNovo !== 'admin') {
                    exige_outro_admin($target, $id);
                }
                q('UPDATE users SET role = ? WHERE id = ?', [$perfilNovo, $id]);
                app_sync_default($id, in_array($perfilNovo, ['admin', 'gestor'], true));
                audit('update', 'user', $id,
                      'Perfil de ' . $target['username'] . ': ' . (ROLES[$target['role']] ?? $target['role'])
                      . ' → ' . ROLES[$perfilNovo],
                      ['role' => $target['role']], ['role' => $perfilNovo]);
                flash('ok', $target['username'] . ' passa a ' . ROLES[$perfilNovo] . '.');
            }
            redirect('users.php');

        } elseif ($action === 'arranque') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $appId = (int)($_POST['default_app'] ?? 0);
            $nome  = '';
            $valida = $appId === 0;
            foreach (apps_for_user($id, in_array($target['role'], ['admin', 'gestor'], true)) as $da) {
                if ((int)$da['id'] === $appId) {
                    $valida = true;
                    $nome   = (string)$da['name'];
                    break;
                }
            }
            if (!$valida) {
                throw new RuntimeException('Essa aplicação não está disponível para este utilizador. '
                    . 'Dê-lhe acesso em Permissões primeiro.');
            }
            app_set_default($id, $appId, 'admin');
            audit('update', 'user', $id, $appId === 0
                ? 'Sem aplicação a abrir ao entrar: ' . $target['username']
                : 'Aplicação a abrir ao entrar de ' . $target['username'] . ': ' . $nome);
            flash('ok', $appId === 0
                ? $target['username'] . ' passa a ver a lista ao entrar.'
                : $target['username'] . ' passa a abrir "' . $nome . '" ao entrar.');
            redirect('users.php');

        } elseif ($action === 'reset_password') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $pw = suggest_password();
            q('UPDATE users SET password_hash = ?, must_change_pw = 1, failed_logins = 0,
                      locked_until = NULL WHERE id = ?',
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
            flash('ok', 'MFA removido. ' . $target['username'] . ' irá associar um novo dispositivo '
                . 'no próximo início de sessão.');
            redirect('users.php');

        } elseif ($action === 'toggle_mfa_required') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            if (mfa_enforced_globally()) {
                throw new RuntimeException('O MFA está a ser exigido a toda a gente. '
                    . 'Mude isso em Administração → Segurança.');
            }
            $novo = (int)$target['mfa_required'] === 1 ? 0 : 1;
            q('UPDATE users SET mfa_required = ? WHERE id = ?', [$novo, $id]);
            audit('update', 'user', $id,
                  ($novo ? 'MFA passa a ser exigido a ' : 'MFA deixa de ser exigido a ') . $target['username']);
            flash('ok', $novo
                ? 'O MFA passa a ser exigido a ' . $target['username'] . ' no próximo início de sessão.'
                : 'O MFA deixa de ser exigido a ' . $target['username'] . '.');
            redirect('users.php');

        } elseif ($action === 'toggle_active') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            $novo = (int)$target['is_active'] === 1 ? 0 : 1;
            if ($novo === 0) {
                if ($id === (int)$me['id']) {
                    throw new RuntimeException('Não pode desativar a sua própria conta.');
                }
                exige_outro_admin($target, $id);
            }
            q('UPDATE users SET is_active = ? WHERE id = ?', [$novo, $id]);
            if ($novo === 0) {
                q('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            }
            audit('update', 'user', $id,
                  ($novo ? 'Conta reativada: ' : 'Conta desativada: ') . $target['username']);
            flash('ok', $novo
                ? 'Conta de ' . $target['username'] . ' reativada.'
                : 'Conta de ' . $target['username'] . ' desativada e sessões terminadas.');
            redirect('users.php');

        } elseif ($action === 'unlock') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]);
            audit('unlock', 'user', $id, 'Conta desbloqueada por administrador');
            flash('ok', 'Conta de ' . $target['username'] . ' desbloqueada.');
            redirect('users.php');

        } elseif ($action === 'revoke_sessions') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            q('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            audit('revoke_sessions', 'user', $id, 'Sessões terminadas por administrador');
            flash('ok', 'Sessões de ' . $target['username'] . ' terminadas.');
            redirect('users.php');

        } elseif ($action === 'apagar') {
            if (!$target) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            if ($id === (int)$me['id']) {
                throw new RuntimeException('Não pode apagar a sua própria conta.');
            }
            exige_outro_admin($target, $id);
            if (strtolower(trim((string)($_POST['confirm'] ?? ''))) !== strtolower($target['username'])) {
                throw new RuntimeException('Para apagar, escreva o nome de utilizador exato na '
                    . 'caixa de confirmação.');
            }
            // O registo é escrito ANTES de apagar: depois já não há de onde
            // tirar os dados. O log guarda o nome como texto e não aponta
            // para a tabela de contas, por isso sobrevive ao apagamento.
            audit('delete', 'user', $id, 'Conta apagada: ' . $target['username'],
                  audit_scrub($target), null, (int)$me['id'], $me['username']);
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('warn', 'Conta de ' . $target['username'] . ' apagada. '
                . 'O que ela fez continua no log de alterações.');
            redirect('users.php');
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

// ---------------------------------------------------------------------
// O que há para mostrar
// ---------------------------------------------------------------------

// Formulário de criação: o que foi escrito tem precedência sobre o vazio,
// para não obrigar a reescrever tudo só porque a palavra-passe era curta.
$repost = $error !== '' && (string)($_POST['action'] ?? '') === 'create';
$form = [
    'username'  => $repost ? (string)($_POST['username'] ?? '')  : '',
    'email'     => $repost ? (string)($_POST['email'] ?? '')     : '',
    'full_name' => $repost ? (string)($_POST['full_name'] ?? '') : '',
    'password'  => $repost ? (string)($_POST['password'] ?? '')  : '',
    'role'      => $repost ? (string)($_POST['role'] ?? 'supervisor') : 'supervisor',
    'is_active' => $repost ? isset($_POST['is_active']) : true,
    'mfa_required' => $repost ? isset($_POST['mfa_required']) : mfa_enforced_globally(),
];

$users = q_all('SELECT * FROM users ORDER BY is_active DESC, role, username');

// Quem está em linha: sessão por revogar, com atividade nos últimos 5
// minutos. O carimbo é atualizado a cada pedido feito com sessão iniciada.
$online = [];
foreach (q_all('SELECT DISTINCT user_id FROM user_sessions
                 WHERE revoked_at IS NULL AND mfa_passed = 1
                   AND last_seen_at > (NOW() - INTERVAL 5 MINUTE)') as $r) {
    $online[(int)$r['user_id']] = true;
}

// Aplicação de arranque de cada um, e as que tem para escolher.
$arranque = [];
foreach (q_all("SELECT user_id, pvalue FROM user_prefs WHERE pkey = 'default_app'") as $r) {
    $arranque[(int)$r['user_id']] = (int)$r['pvalue'];
}

// A conta a apagar, quando se chega pelo caixote do lixo.
$apagarId = isset($_GET['apagar']) ? (int)$_GET['apagar'] : 0;
$apagar   = $apagarId ? q_one('SELECT * FROM users WHERE id = ?', [$apagarId]) : null;

/** Os perfis, como ícones. A coroa manda em tudo; o bonequinho usa. */
$PERFIS = [
    'admin'      => ['coroa',   'Administrador'],
    'gestor'     => ['escudo',  'Gestor de aplicações'],
    'supervisor' => ['boneco',  'Utilizador'],
    'leitor'     => ['olho',    'Consulta'],
];

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

<?php if ($apagar): ?>
  <div class="card">
    <h2>Apagar <?= e($apagar['username']) ?>?</h2>
    <p>
      Apaga a conta e tudo o que lhe pertence: sessões, dispositivo de MFA, códigos de
      recuperação, aplicações atribuídas e preferências. <b>Não há como desfazer.</b>
      O que <?= e($apagar['username']) ?> fez continua no log de alterações.
    </p>
    <p class="muted">
      Se só quer impedir o acesso, <b>desative</b> a conta no botão Ativo da lista —
      mantém o histórico ligado a ela.
    </p>
    <form method="post" class="actions" style="align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="apagar">
      <input type="hidden" name="id" value="<?= (int)$apagar['id'] ?>">
      <input type="text" name="confirm" autocomplete="off" style="margin:0;max-width:240px"
             placeholder="escreva <?= e($apagar['username']) ?>"
             aria-label="Confirmar o nome de utilizador">
      <button class="danger" type="submit">Apagar definitivamente</button>
      <a class="btn" href="users.php">Cancelar</a>
    </form>
  </div>
<?php endif; ?>

<!-- ================================================ criar utilizador -->
<div class="card" id="form">
  <h2>Criar utilizador</h2>
  <p class="muted">Só a conta. O que cada pessoa vê e pode fazer em cada aplicação
    decide-se em <a href="permissoes.php">Permissões</a>.</p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="grid2">
      <label><span class="req">Nome de utilizador</span>
        <input type="text" name="username" required pattern="[a-z0-9._\-]{3,64}"
               value="<?= e($form['username']) ?>" placeholder="ex.: rita.campos">
      </label>
      <label><span class="req">E-mail</span>
        <input type="email" name="email" required value="<?= e($form['email']) ?>"
               placeholder="rita.campos@setronix.pt">
      </label>
      <label><span class="req">Nome completo</span>
        <input type="text" name="full_name" required value="<?= e($form['full_name']) ?>"
               placeholder="Rita Campos">
      </label>
      <label>Palavra-passe inicial
        <input type="text" name="password" value="<?= e($form['password']) ?>"
               placeholder="deixar vazio para gerar automaticamente">
      </label>
    </div>

    <label style="display:block;margin:4px 0 10px">Perfil</label>
    <div class="niveis" role="radiogroup" aria-label="Perfil do novo utilizador">
      <?php foreach ($PERFIS as $cod => [$ic, $rot]): ?>
        <label style="margin:0">
          <input type="radio" name="role" value="<?= e($cod) ?>" class="sr-radio"
                 <?= $form['role'] === $cod ? 'checked' : '' ?>>
          <span class="falso-botao" data-n="<?= e($cod) ?>" title="<?= e($rot) ?>">
            <?= icone($ic) ?><span class="rot"><?= e($rot) ?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="actions" style="margin:16px 0 10px">
      <label style="margin:0;display:inline-flex;align-items:center;gap:7px">
        <input type="checkbox" name="is_active" value="1" style="width:auto;margin:0"
               <?= $form['is_active'] ? 'checked' : '' ?>> Conta ativa
      </label>
      <?php if (!mfa_enforced_globally()): ?>
        <label style="margin:0;display:inline-flex;align-items:center;gap:7px">
          <input type="checkbox" name="mfa_required" value="1" style="width:auto;margin:0"
                 <?= $form['mfa_required'] ? 'checked' : '' ?>> Exigir MFA a esta conta
        </label>
      <?php endif; ?>
    </div>

    <p class="muted">
      <b>Perfis:</b>
      Administrador — tudo, incluindo contas e log de alterações ·
      Gestor de aplicações — envia e substitui as aplicações HTML ·
      Utilizador e Consulta — abrem as aplicações a que têm acesso.
    </p>
    <div class="actions">
      <button class="primary" type="submit">Criar utilizador</button>
    </div>
  </form>
</div>

<!-- ===================================================== a lista -->
<div class="card">
  <h2>Utilizadores (<?= count($users) ?>)</h2>
  <p class="muted">Escreva por cima para corrigir os dados. A linha fica marcada até guardar
    no lápis.</p>

  <div class="scroll">
    <table class="tabela-contas">
      <thead>
        <tr><th>Utilizador</th><th>Nome</th><th>Perfil</th><th>Estado</th><th>MFA</th>
            <th>Presença</th><th>Ações</th><th>App de arranque</th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
          $uid    = (int)$u['id'];
          $f      = 'u' . $uid;
          $locked = !empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time();
          $ativo  = (int)$u['is_active'] === 1;
          $temMfa = (int)$u['mfa_enabled'] === 1;
          $exige  = (int)$u['mfa_required'] === 1 || mfa_enforced_globally();
          $eu     = $uid === (int)$me['id'];
          $suas   = apps_for_user($uid, in_array($u['role'], ['admin', 'gestor'], true));
          $dflt   = $arranque[$uid] ?? 0; ?>
        <tr data-linha="<?= $f ?>">
          <td class="td-edit conta col-conta">
            <input class="u" form="<?= $f ?>" name="username" value="<?= e($u['username']) ?>"
                   pattern="[a-z0-9._\-]{3,64}" required aria-label="Nome de utilizador">
            <input class="m" form="<?= $f ?>" name="email" type="email" required
                   value="<?= e($u['email']) ?>" aria-label="E-mail">
          </td>
          <td class="td-edit col-nome">
            <input form="<?= $f ?>" name="full_name" required value="<?= e($u['full_name']) ?>"
                   aria-label="Nome completo">
          </td>

          <td class="col-perfil">
            <?php
            // Só os dois que se usam no dia a dia. Gestor e Consulta entram
            // na lista apenas quando é esse o perfil de quem está na linha:
            // ficam à vista, e sai-se deles com um clique.
            $mostrar = ['admin', 'supervisor'];
            if (!in_array($u['role'], $mostrar, true) && isset($PERFIS[$u['role']])) {
                $mostrar[] = $u['role'];
            } ?>
            <div class="perfil-sw" role="group" aria-label="Perfil de <?= e($u['full_name']) ?>">
              <?php foreach ($mostrar as $cod): [$ic, $rot] = $PERFIS[$cod]; ?>
                <button form="<?= $f ?>" type="submit" name="action" value="perfil:<?= e($cod) ?>"
                        data-perfil="<?= $cod === 'admin' || $cod === 'gestor' ? 'manda' : 'usa' ?>"
                        aria-pressed="<?= $u['role'] === $cod ? 'true' : 'false' ?>"
                        title="<?= e($rot) ?>"><?= icone($ic) ?></button>
              <?php endforeach; ?>
            </div>
          </td>

          <td class="col-estado">
            <button form="<?= $f ?>" type="submit" name="action" value="toggle_active"
                    class="chip <?= $ativo ? 'bom' : '' ?>" style="cursor:pointer"
                    <?= $eu && $ativo ? 'disabled title="Não pode desativar a sua própria conta."' : '' ?>>
              <?= $ativo ? 'Ativo' : 'Inativo' ?>
            </button>
            <?php if ($locked): ?>
              <button form="<?= $f ?>" type="submit" name="action" value="unlock"
                      class="chip mau" style="cursor:pointer;margin-top:4px"
                      title="Bloqueada por tentativas falhadas — carregue para desbloquear">
                <?= icone('cadeado') ?> Desbloquear
              </button>
            <?php endif; ?>
          </td>

          <td>
            <span class="chip <?= $temMfa ? 'bom' : 'mau' ?>">
              <?= $temMfa ? 'Associado' : 'Por associar' ?>
            </span>
            <?php if ($exige): ?>
              <br><span class="muted" style="font-size:11px">exigido</span>
            <?php endif; ?>
          </td>

          <td>
            <?php $emLinha = isset($online[$uid]); ?>
            <span class="presenca">
              <span class="dot <?= $emLinha ? 'on' : 'off' ?>"></span>
              <span class="txt"><?= $emLinha ? 'Em linha' : 'Ausente' ?></span>
            </span>
          </td>

          <td class="col-acoes">
            <div class="acoes-conta">
              <button form="<?= $f ?>" type="submit" name="action" value="linha"
                      class="ico-btn" data-guardar title="Guardar as alterações desta linha"
                      aria-label="Guardar as alterações desta linha"><?= icone('lapis') ?></button>

              <button form="<?= $f ?>" type="submit" name="action" value="reset_password"
                      class="ico-btn" title="Repor palavra-passe" aria-label="Repor palavra-passe"
                      onclick="return confirm('Repor a palavra-passe de <?= e($u['username']) ?>?')"><?= icone('chave') ?></button>

              <button form="<?= $f ?>" type="submit" name="action" value="revoke_sessions"
                      class="ico-btn" title="Terminar sessões abertas" aria-label="Terminar sessões abertas"
                      onclick="return confirm('Terminar todas as sessões de <?= e($u['username']) ?>?')"><?= icone('sair') ?></button>

              <button form="<?= $f ?>" type="submit" name="action" value="toggle_mfa_required"
                      class="ico-btn" <?= mfa_enforced_globally() ? 'disabled' : '' ?>
                      title="<?= mfa_enforced_globally()
                          ? 'O MFA está a ser exigido a toda a gente'
                          : ((int)$u['mfa_required'] === 1 ? 'Deixar de exigir MFA' : 'Exigir MFA') ?>"
                      aria-label="Exigir MFA"><?= icone('escudo') ?></button>

              <button form="<?= $f ?>" type="submit" name="action" value="reset_mfa"
                      class="ico-btn" <?= $temMfa ? '' : 'disabled' ?>
                      title="<?= $temMfa ? 'Repor MFA' : 'Ainda não tem MFA associado' ?>"
                      aria-label="Repor MFA"
                      onclick="return confirm('Remover o MFA de <?= e($u['username']) ?>? Vai ter de associar um novo dispositivo.')"><?= icone('repor') ?></button>

              <?php if ($eu): ?>
                <span class="ico-btn" aria-disabled="true" title="Não pode apagar a sua própria conta"
                      style="opacity:.35"><?= icone('caixote') ?></span>
              <?php else: ?>
                <a class="ico-btn perigo" href="users.php?apagar=<?= $uid ?>"
                   title="Apagar conta" aria-label="Apagar conta"><?= icone('caixote') ?></a>
              <?php endif; ?>
            </div>
          </td>

          <td class="col-arranque">
            <div class="arranque <?= $dflt > 0 ? 'tem' : '' ?>">
              <span class="estrela"><?= icone('estrela') ?></span>
              <select form="<?= $f ?>" name="default_app"
                      aria-label="Aplicação de arranque de <?= e($u['full_name']) ?>">
                <option value="0">Nenhuma — mostra a lista</option>
                <?php foreach ($suas as $sa): ?>
                  <option value="<?= (int)$sa['id'] ?>" <?= $dflt === (int)$sa['id'] ? 'selected' : '' ?>>
                    <?= e($sa['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button form="<?= $f ?>" type="submit" name="action" value="arranque"
                      class="btn aplicar">Aplicar</button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="muted" style="margin-top:12px">
    <b>Ações:</b> lápis guarda a linha · chave repõe a palavra-passe · o botão redondo termina
    as sessões abertas · escudo exige MFA · seta repõe o MFA · caixote apaga a conta.
    Desativar impede o acesso e mantém o histórico ligado à conta; apagar remove-a de vez.
    Em qualquer dos casos, o log de alterações guarda o que a pessoa fez.
  </p>
</div>

<?php
// Um formulário por linha, fora da tabela. Uma linha de tabela não pode
// conter um <form>, mas os controlos podem apontar-lhe com form="...".
foreach ($users as $u): ?>
  <form id="u<?= (int)$u['id'] ?>" method="post" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
  </form>
<?php endforeach; ?>

</div>

<script>
// Duas comodidades, e nada mais: sem isto a página funciona na mesma.
(function () {
  // 1. Marcar a linha enquanto houver coisa por guardar.
  document.querySelectorAll('tr[data-linha]').forEach(function (tr) {
    tr.addEventListener('input', function (ev) {
      if (!ev.target.matches('.td-edit input')) { return; }
      tr.classList.add('por-guardar');
      var lapis = tr.querySelector('[data-guardar]');
      if (lapis) { lapis.classList.add('guardar'); }
    });
  });

  // 2. A estrela aplica-se sozinha ao escolher. O botão ao lado só existe
  //    para quem não tem JavaScript, por isso desaparece aqui.
  document.querySelectorAll('.arranque').forEach(function (cx) {
    var sel = cx.querySelector('select');
    var bt  = cx.querySelector('button.aplicar');
    if (!sel || !bt) { return; }
    bt.hidden = true;
    sel.addEventListener('change', function () { bt.click(); });
  });
})();
</script>
<?php layout_foot(); ?>
