<?php
/**
 * Gestão das aplicações HTML: enviar, substituir, repor versões, remover.
 *
 * Cada aplicação é um ficheiro .html autónomo. Substituir a aplicação é
 * simplesmente enviar um ficheiro novo: fica como versão activa e a
 * anterior continua guardada, pronta a ser reposta.
 */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/apps.php';
require_once __DIR__ . '/../lib/layout.php';

$me = require_login('apps.manage');

$error  = '';
$openId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $app    = $id ? app_find($id) : null;

    try {
        if ($action === 'create') {
            $new = app_create(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['description'] ?? ''),
                $_FILES['file'] ?? []
            );
            audit('create', 'app', $new['id'], 'Aplicação criada: ' . $new['name']);
            flash('ok', 'Aplicação "' . $new['name'] . '" publicada.');
            redirect('apps.php?id=' . (int)$new['id']);
        }

        if (!$app) {
            throw new RuntimeException('Aplicação não encontrada.');
        }

        if ($action === 'upload') {
            $v = app_store_version($id, $_FILES['file'] ?? [], (string)($_POST['notes'] ?? ''));
            audit('update', 'app', $id, 'Nova versão (' . (int)$v['version'] . ') de ' . $app['name']);
            flash('ok', 'Versão ' . (int)$v['version'] . ' publicada. Os utilizadores passam a ver esta.');
            redirect('apps.php?id=' . $id);
        }

        if ($action === 'edit') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Indique o nome da aplicação.');
            }
            $desc   = trim((string)($_POST['description'] ?? ''));
            $active = isset($_POST['is_active']) ? 1 : 0;
            q('UPDATE apps SET name = ?, description = ?, is_active = ?, slug = ? WHERE id = ?', [
                mb_substr($name, 0, 160),
                $desc === '' ? null : mb_substr($desc, 0, 500),
                $active,
                app_make_slug($name, $id),
                $id,
            ]);
            audit('update', 'app', $id, 'Aplicação atualizada: ' . $name, $app,
                  ['name' => $name, 'description' => $desc, 'is_active' => $active]);
            flash('ok', 'Aplicação atualizada.');
            redirect('apps.php?id=' . $id);
        }

        if ($action === 'access') {
            app_set_users($id, (array)($_POST['users'] ?? []));
            $n = count(app_user_ids($id));
            audit('update', 'app', $id, 'Acesso a ' . $app['name'] . ': '
                  . ($n === 0 ? 'todos os utilizadores' : $n . ' utilizador(es)'));
            flash('ok', $n === 0
                ? 'A aplicação passa a estar visível para todos os utilizadores.'
                : 'Acesso reservado a ' . $n . ' utilizador(es).');
            redirect('apps.php?id=' . $id);
        }

        if ($action === 'rollback') {
            $v = app_rollback($id, (int)($_POST['version_id'] ?? 0));
            audit('update', 'app', $id, 'Reposta a versão ' . (int)$v['version'] . ' de ' . $app['name']);
            flash('ok', 'A versão ' . (int)$v['version'] . ' voltou a ser a versão activa.');
            redirect('apps.php?id=' . $id);
        }

        if ($action === 'delete_version') {
            $v = app_version_delete($id, (int)($_POST['version_id'] ?? 0));
            audit('delete', 'app', $id, 'Apagada a versão ' . (int)$v['version'] . ' de ' . $app['name']);
            flash('ok', 'Versão ' . (int)$v['version'] . ' apagada.');
            redirect('apps.php?id=' . $id);
        }

        if ($action === 'delete') {
            if (strtolower(trim((string)($_POST['confirm'] ?? ''))) !== strtolower($app['name'])) {
                throw new RuntimeException('Para apagar, escreva o nome exato da aplicação na caixa de confirmação.');
            }
            app_delete($id);
            audit('delete', 'app', $id, 'Aplicação apagada: ' . $app['name'], $app);
            flash('warn', 'Aplicação "' . $app['name'] . '" apagada, com todas as versões.');
            redirect('apps.php');
        }

        throw new RuntimeException('Ação desconhecida.');
    } catch (Throwable $ex) {
        $error  = $ex->getMessage();
        $openId = $id ?: $openId;
    }
}

$apps = apps_all(false);
$open = $openId ? app_find($openId) : null;

// Depois de uma submissão falhada, o formulário volta a ser desenhado com o
// que foi escrito. O ficheiro em si não pode ser reposto — o browser não
// deixa preencher um <input type="file"> — mas o resto fica.
$failed = $error !== '' ? (string)($_POST['action'] ?? '') : '';
$novo = [
    'name'        => $failed === 'create' ? (string)($_POST['name'] ?? '') : '',
    'description' => $failed === 'create' ? (string)($_POST['description'] ?? '') : '',
];

layout_head('Aplicações', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('apps'); ?>

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <h2>Aplicações publicadas</h2>
  <p class="muted">
    Uma aplicação é um único ficheiro <code>.html</code> — com o CSS e o JavaScript lá dentro.
    Envie-o aqui e fica imediatamente disponível para os utilizadores, sem intervenção nossa.
    Máximo <?= e(human_bytes(apps_max_bytes())) ?> por ficheiro.
  </p>
  <table>
    <thead><tr><th>Nome</th><th>Versão</th><th>Tamanho</th><th>Atualizada</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($apps as $a): $v = app_current_version($a); ?>
        <tr>
          <td>
            <b><?= e($a['name']) ?></b>
            <?php if (!empty($a['description'])): ?>
              <div class="muted"><?= e($a['description']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= $v ? (int)$v['version'] : '<span class="muted">—</span>' ?></td>
          <td class="muted"><?= $v ? e(human_bytes((int)$v['size_bytes'])) : '—' ?></td>
          <td class="muted mono"><?= e(substr((string)$a['updated_at'], 0, 16)) ?></td>
          <td>
            <?= (int)$a['is_active'] === 1
                ? '<span class="tag on">ativa</span>'
                : '<span class="tag off">oculta</span>' ?>
            <?php $nAcesso = count(app_user_ids((int)$a['id'])); ?>
            <?php if ($nAcesso): ?>
              <br><span class="tag gestor" title="Reservada a utilizadores escolhidos"><?= $nAcesso ?> utilizador(es)</span>
            <?php else: ?>
              <br><span class="tag leitor">todos</span>
            <?php endif; ?>
          </td>
          <td class="actions">
            <a class="btn" href="../app.php?id=<?= (int)$a['id'] ?>">Abrir</a>
            <a class="btn" href="apps.php?id=<?= (int)$a['id'] ?>">Gerir</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$apps): ?>
        <tr><td colspan="6" class="muted">Ainda não há aplicações. Crie a primeira abaixo.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($open):
    $versions = app_versions((int)$open['id']);
    $cur      = app_current_version($open);
    $ed = [
        'name'        => $failed === 'edit' ? (string)($_POST['name'] ?? '') : (string)$open['name'],
        'description' => $failed === 'edit' ? (string)($_POST['description'] ?? '') : (string)$open['description'],
        'is_active'   => $failed === 'edit' ? isset($_POST['is_active']) : (int)$open['is_active'] === 1,
    ];
?>
<div class="card">
  <h2><?= e($open['name']) ?></h2>
  <p class="muted">Endereço: <code>app.php?id=<?= (int)$open['id'] ?></code></p>

  <h3>Enviar nova versão</h3>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <label><span class="req">Ficheiro HTML</span>
      <input type="file" name="file" accept=".html,.htm" required></label>
    <label>Nota (opcional)
      <input type="text" name="notes" maxlength="255" placeholder="ex.: relatórios de produção"></label>
    <div class="actions">
      <button class="primary" type="submit">Publicar esta versão</button>
      <span class="muted">A versão anterior fica guardada e pode ser reposta.</span>
    </div>
  </form>

  <h3>Versões</h3>
  <div class="scroll">
    <table>
      <thead><tr><th>#</th><th>Ficheiro</th><th>Tamanho</th><th>Data</th><th>Nota</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($versions as $v): $isCur = $cur && (int)$cur['id'] === (int)$v['id']; ?>
          <tr>
            <td><b><?= (int)$v['version'] ?></b>
                <?= $isCur ? ' <span class="tag on">activa</span>' : '' ?></td>
            <td class="mono"><?= e($v['filename']) ?></td>
            <td class="muted"><?= e(human_bytes((int)$v['size_bytes'])) ?></td>
            <td class="muted mono"><?= e(substr((string)$v['created_at'], 0, 16)) ?></td>
            <td class="muted"><?= e((string)$v['notes']) ?></td>
            <td class="actions">
              <a class="btn" target="_blank" rel="noopener"
                 href="../app_raw.php?id=<?= (int)$open['id'] ?>&v=<?= (int)$v['id'] ?>">Pré-ver</a>
              <?php if (!$isCur): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="rollback">
                  <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                  <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                  <button type="submit">Repor</button>
                </form>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Apagar a versão <?= (int)$v['version'] ?>? Não há forma de a recuperar.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_version">
                  <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                  <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                  <button class="danger" type="submit">Apagar</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h3>Quem pode abrir</h3>
  <?php
    $comAcesso = $failed === 'access'
        ? array_map('intval', (array)($_POST['users'] ?? []))
        : app_user_ids((int)$open['id']);
    $utilizadores = q_all('SELECT id, username, full_name, role, is_active
                             FROM users ORDER BY is_active DESC, full_name');
  ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="access">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <p class="muted" style="margin:0 0 8px">
      <?php if (!$comAcesso): ?>
        Neste momento <b>todos os utilizadores</b> veem esta aplicação.
        Assinale pessoas para a reservar só a elas.
      <?php else: ?>
        Reservada a <b><?= count($comAcesso) ?></b> utilizador(es).
        Desmarque todos para a voltar a abrir a toda a gente.
      <?php endif; ?>
      Quem gere aplicações vê sempre todas.
    </p>
    <?php
      $itens = [];
      foreach ($utilizadores as $u) {
          $itens[] = [
              'id'      => (int)$u['id'],
              'title'   => $u['full_name'],
              'sub'     => $u['username'] . ' · ' . (ROLES[$u['role']] ?? $u['role']),
              'mark'    => mb_strtoupper(mb_substr($u['full_name'], 0, 1)),
              'granted' => in_array((int)$u['id'], $comAcesso, true),
              'note'    => (int)$u['is_active'] === 1 ? '' : 'inativo',
          ];
      }
      transfer_list('users', $itens, [
          'left'        => 'Não vê esta aplicação',
          'right'       => 'Pode abrir',
          'empty_left'  => 'Toda a gente tem acesso.',
          'empty_right' => 'Ninguém escolhido: a aplicação está aberta a todos.',
          'hint'        => 'Com a coluna da direita vazia, a aplicação fica visível para '
                         . '<b>todos os utilizadores</b>. Assim que lá estiver alguém, passa a '
                         . 'ser só dessas pessoas. Quem gere aplicações vê-a sempre.',
      ]);
    ?>
    <div class="actions" style="margin-top:10px">
      <button class="primary" type="submit">Guardar acesso</button>
    </div>
  </form>

  <h3>Dados da aplicação</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <div class="grid2">
      <label><span class="req">Nome</span>
        <input type="text" name="name" maxlength="160" required value="<?= e($ed['name']) ?>"></label>
      <label>Descrição
        <input type="text" name="description" maxlength="500" value="<?= e($ed['description']) ?>"></label>
    </div>
    <label style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="is_active" style="width:auto;margin:0"
             <?= $ed['is_active'] ? 'checked' : '' ?>>
      Visível para os utilizadores
    </label>
    <button class="primary" type="submit">Guardar</button>
  </form>

  <h3>Apagar</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <p class="muted">
      Apaga a aplicação e todas as versões do servidor. Para ocultar sem apagar, desmarque
      "Visível para os utilizadores" acima.
    </p>
    <label>Escreva <code><?= e($open['name']) ?></code> para confirmar
      <input type="text" name="confirm" autocomplete="off"></label>
    <button class="danger" type="submit">Apagar definitivamente</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Nova aplicação</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="grid2">
      <label><span class="req">Nome</span>
        <input type="text" name="name" maxlength="160" required value="<?= e($novo['name']) ?>"
               placeholder="ex.: Planeamento de obras"></label>
      <label>Descrição
        <input type="text" name="description" maxlength="500" value="<?= e($novo['description']) ?>"
               placeholder="uma linha, mostrada no ecrã inicial"></label>
    </div>
    <label><span class="req">Ficheiro HTML</span>
      <input type="file" name="file" accept=".html,.htm" required></label>
    <button class="primary" type="submit">Publicar</button>
  </form>
</div>

</div>
<?php layout_foot(); ?>
