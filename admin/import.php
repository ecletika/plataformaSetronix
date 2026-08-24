<?php
/** Importação das listas base a partir de .xlsx ou .csv. */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/lists.php';
require_once __DIR__ . '/../lib/backup.php';

$me = require_login('import');

$error = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = ($_POST['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';

    try {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecione um ficheiro válido. ' . upload_error_text((int)($_FILES['file']['error'] ?? -1)));
        }

        $name = (string)$_FILES['file']['name'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            throw new RuntimeException('Formato não suportado. Utilize .xlsx ou .csv.');
        }
        if ((int)$_FILES['file']['size'] > 8 * 1024 * 1024) {
            throw new RuntimeException('O ficheiro excede 8 MB.');
        }

        $dir = $CONFIG['paths']['uploads'] ?? (APP_ROOT . '/storage/uploads');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $dest = $dir . '/' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            throw new RuntimeException('Não foi possível guardar o ficheiro no servidor.');
        }

        // Backup preventivo antes de mexer nos dados base.
        backup_create('pre-import', (int)$me['id']);

        $result = lists_import($dest, $name, $mode, (int)$me['id']);
        flash('ok', 'Importação concluída.');
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

/** Texto legível para os códigos de erro de upload do PHP. */
function upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'O ficheiro excede o limite de upload do servidor.';
        case UPLOAD_ERR_PARTIAL:    return 'O envio foi interrompido.';
        case UPLOAD_ERR_NO_FILE:    return 'Nenhum ficheiro foi enviado.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Falta o diretório temporário no servidor.';
        case UPLOAD_ERR_CANT_WRITE: return 'O servidor não conseguiu escrever o ficheiro.';
        default:                    return '';
    }
}

$types = q_all('SELECT * FROM list_types ORDER BY sort_order');
$history = q_all('SELECT r.*, u.username FROM import_runs r
                  LEFT JOIN users u ON u.id = r.user_id
                  ORDER BY r.id DESC LIMIT 15');

layout_head('Importar dados', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('import'); ?>

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

<?php if ($result): ?>
  <div class="card">
    <h2>Resultado da importação</h2>
    <p>
      <?= (int)$result['rows'] ?> linhas lidas ·
      <b><?= (int)$result['added'] ?></b> valores novos ·
      <b><?= (int)$result['reactivated'] ?></b> reativados ·
      <b><?= (int)$result['deactivated'] ?></b> desativados
    </p>
    <?php if ($result['unknown_headers']): ?>
      <div class="alert warn">
        Colunas ignoradas (cabeçalho não reconhecido):
        <?= e(implode(', ', $result['unknown_headers'])) ?>
      </div>
    <?php endif; ?>
    <table>
      <thead><tr><th>Lista</th><th>No ficheiro</th><th>Novos</th><th>Reativados</th><th>Desativados</th></tr></thead>
      <tbody>
      <?php foreach ($result['per_list'] as $code => $r): ?>
        <tr>
          <td><?= e($code) ?></td>
          <td><?= (int)$r['no_ficheiro'] ?></td>
          <td><?= (int)$r['novos'] ?></td>
          <td><?= (int)$r['reativados'] ?></td>
          <td><?= (int)$r['desativados'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Importar listas base</h2>
  <p class="muted">
    Formato do ficheiro: a <b>primeira linha</b> contém os cabeçalhos e cada coluna é uma
    lista independente, lida de cima para baixo. Células vazias são ignoradas, pelo que
    as colunas podem ter comprimentos diferentes — exatamente como o ficheiro
    <i>LISTA DE SELEÇÃO.xlsx</i>.
  </p>

  <h3>Cabeçalhos reconhecidos</h3>
  <table style="max-width:640px">
    <thead><tr><th>Cabeçalho no ficheiro</th><th>Lista de destino</th></tr></thead>
    <tbody>
      <?php foreach ($types as $t): ?>
        <tr><td class="mono"><?= e((string)$t['excel_header']) ?></td><td><?= e($t['label']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted">
    Os ajudantes podem vir em várias colunas (AJUDANTE SETRONIX 1/2/3) — são todos
    agregados na mesma lista, sem duplicados.
  </p>

  <h3>Enviar ficheiro</h3>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label><span class="req">Ficheiro (.xlsx ou .csv, máx. 8 MB)</span>
      <input type="file" name="file" accept=".xlsx,.csv" required>
    </label>
    <label>Modo de atualização
      <select name="mode">
        <option value="merge">Acrescentar — mantém os valores existentes (recomendado)</option>
        <option value="replace">Substituir — desativa os valores que não constem do ficheiro</option>
      </select>
    </label>
    <div class="alert info">
      É criado automaticamente um backup da base de dados antes de cada importação.
    </div>
    <button class="primary" type="submit">Importar</button>
  </form>
</div>

<div class="card">
  <h2>Histórico de importações</h2>
  <div class="scroll">
    <table>
      <thead><tr><th>Data</th><th>Ficheiro</th><th>Utilizador</th><th>Modo</th>
                 <th>Novos</th><th>Reativados</th><th>Desativados</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td class="mono"><?= e($h['created_at']) ?></td>
          <td><?= e($h['filename']) ?></td>
          <td><?= e((string)$h['username']) ?></td>
          <td><?= $h['mode'] === 'replace' ? 'Substituir' : 'Acrescentar' ?></td>
          <td><?= (int)$h['items_added'] ?></td>
          <td><?= (int)$h['items_reactivated'] ?></td>
          <td><?= (int)$h['items_deactivated'] ?></td>
          <td><?= $h['status'] === 'ok'
                ? '<span class="tag on">OK</span>'
                : '<span class="tag off">Erro</span><br><span class="muted">' . e(mb_substr((string)$h['message'], 0, 120)) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$history): ?><tr><td colspan="8" class="muted">Ainda não foram feitas importações.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
<?php layout_foot(); ?>
