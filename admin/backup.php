<?php
/** Backups: criar, descarregar, restaurar e apagar. */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/backup.php';

$me = require_login('backup.manage');

$error = '';
$created = null;

// ---- Descarregar (GET, para não sujar o histórico de POST) ----
if (isset($_GET['download'])) {
    $file = basename((string)$_GET['download']);
    $row = q_one('SELECT * FROM backups WHERE filename = ?', [$file]);
    $path = backup_dir() . '/' . $file;
    if (!$row || !is_file($path)) {
        http_response_code(404);
        exit('Backup não encontrado.');
    }
    audit('backup_download', 'system', null, 'Descarregado: ' . $file);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $created = backup_create('manual', (int)$me['id']);
            flash('ok', 'Backup criado: ' . $created['filename'] . ' (' . human_bytes($created['size']) . ')');
            redirect('backup.php');
        } elseif ($action === 'delete') {
            $file = basename((string)($_POST['filename'] ?? ''));
            $path = backup_dir() . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
            q('DELETE FROM backups WHERE filename = ?', [$file]);
            audit('backup_delete', 'system', null, 'Backup apagado: ' . $file);
            flash('ok', 'Backup apagado.');
            redirect('backup.php');
        } elseif ($action === 'restore') {
            $file = basename((string)($_POST['filename'] ?? ''));
            if (strtolower(trim((string)($_POST['confirm'] ?? ''))) !== 'restaurar') {
                throw new RuntimeException('Para confirmar o restauro, escreva a palavra RESTAURAR.');
            }
            if (!password_verify((string)($_POST['password'] ?? ''), $me['password_hash'])) {
                throw new RuntimeException('Palavra-passe incorreta.');
            }
            $r = backup_restore(backup_dir() . '/' . $file, (int)$me['id']);
            flash('warn', 'Restauro concluído (' . $r['statements'] . ' instruções). '
                        . 'Foi guardado um backup do estado anterior: ' . $r['safety_backup'] . '. '
                        . 'Volte a iniciar sessão.');
            redirect('../logout.php');
        } elseif ($action === 'upload') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Selecione um ficheiro de backup válido.');
            }
            $name = basename((string)$_FILES['file']['name']);
            if (!preg_match('/\.sql(\.gz)?$/i', $name)) {
                throw new RuntimeException('O ficheiro tem de ser .sql ou .sql.gz.');
            }
            $dest = backup_dir() . '/' . date('Ymd_His') . '_carregado_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                throw new RuntimeException('Não foi possível guardar o ficheiro.');
            }
            q('INSERT INTO backups (filename, size_bytes, kind, sha256, user_id) VALUES (?,?,?,?,?)',
              [to_utf8(basename($dest)), (int)filesize($dest), 'manual', hash_file('sha256', $dest), (int)$me['id']]);
            audit('backup_upload', 'system', null, 'Backup carregado: ' . basename($dest));
            flash('ok', 'Ficheiro carregado. Pode agora restaurá-lo a partir da lista.');
            redirect('backup.php');
        } elseif ($action === 'retention') {
            $days = max(0, (int)($_POST['days'] ?? 90));
            setting_set('backup_retention_days', (string)$days);
            audit('update', 'system', null, 'Retenção de backups definida para ' . $days . ' dias');
            flash('ok', 'Política de retenção atualizada.');
            redirect('backup.php');
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$backups = q_all('SELECT b.*, u.username FROM backups b LEFT JOIN users u ON u.id = b.user_id
                  ORDER BY b.id DESC LIMIT 60');
$retention = (int)(setting('backup_retention_days', '90') ?? 90);
$dirWritable = is_writable(backup_dir());

layout_head('Backups', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('backup'); ?>

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
<?php if (!$dirWritable): ?>
  <div class="alert err">
    O diretório <code><?= e(backup_dir()) ?></code> não tem permissões de escrita.
    No cPanel, defina 0750 (ou 0755) na pasta <code>storage/backups</code>.
  </div>
<?php endif; ?>

<div class="card">
  <h2>Cópias de segurança</h2>
  <p class="muted">
    Cada backup é um dump SQL completo (estrutura + dados), comprimido em .gz.
    São criados automaticamente antes de cada importação de dados e antes de cada restauro.
  </p>
  <div class="actions" style="margin-top:12px">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <button class="primary" type="submit">Criar backup agora</button>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="retention">
      <div class="actions">
        <label style="margin:0">Apagar automaticamente após
          <input type="number" name="days" min="0" max="3650" value="<?= $retention ?>" style="width:90px">
        </label>
        <span class="muted">dias (0 = nunca)</span>
        <button type="submit">Guardar</button>
      </div>
    </form>
  </div>
  <div class="alert info" style="margin-top:14px">
    <b>Backup automático diário:</b> no cPanel → <i>Cron Jobs</i>, agende
    <code>/usr/local/bin/php <?= e(APP_ROOT) ?>/install/cron_backup.php</code> uma vez por dia.
    Mantenha também os backups nativos do cPanel como segunda linha de defesa.
  </div>
</div>

<div class="card">
  <h2>Carregar um backup externo</h2>
  <form method="post" enctype="multipart/form-data" class="actions">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="file" name="file" accept=".sql,.gz" required style="max-width:340px">
    <button type="submit">Carregar</button>
  </form>
</div>

<div class="card">
  <h2>Backups disponíveis (<?= count($backups) ?>)</h2>
  <div class="scroll">
    <table>
      <thead><tr><th>Ficheiro</th><th>Data</th><th>Tipo</th><th>Tamanho</th><th>Criado por</th><th>Ações</th></tr></thead>
      <tbody>
      <?php foreach ($backups as $b):
          $exists = is_file(backup_dir() . '/' . $b['filename']); ?>
        <tr>
          <td class="mono"><?= e($b['filename']) ?>
              <?= $exists ? '' : '<br><span class="tag off">ficheiro em falta</span>' ?></td>
          <td class="mono"><?= e($b['created_at']) ?></td>
          <td><?= e($b['kind']) ?></td>
          <td><?= human_bytes((int)$b['size_bytes']) ?></td>
          <td><?= e((string)$b['username']) ?></td>
          <td>
            <?php if ($exists): ?>
              <div class="actions">
                <a class="btn" href="backup.php?download=<?= urlencode($b['filename']) ?>">Descarregar</a>
                <details>
                  <summary class="btn danger" style="list-style:none">Restaurar</summary>
                  <form method="post" style="margin-top:8px;min-width:260px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="filename" value="<?= e($b['filename']) ?>">
                    <div class="alert warn" style="font-size:12px">
                      O restauro substitui <b>todos</b> os dados atuais. Será criado um backup
                      do estado anterior e a sua sessão terminará.
                    </div>
                    <label>Escreva RESTAURAR <input type="text" name="confirm" required></label>
                    <label>A sua palavra-passe <input type="password" name="password" required></label>
                    <button class="danger" type="submit">Confirmar restauro</button>
                  </form>
                </details>
                <form method="post" onsubmit="return confirm('Apagar definitivamente este backup?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="filename" value="<?= e($b['filename']) ?>">
                  <button type="submit">Apagar</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$backups): ?><tr><td colspan="6" class="muted">Ainda não existem backups.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
<?php layout_foot(); ?>
