<?php
/** Gestão manual das listas base (clientes, projetos, pessoal, tarefas). */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/lists.php';

$me = require_login('lists.edit');

$types = q_all('SELECT * FROM list_types ORDER BY sort_order, code');
$current = (string)($_GET['t'] ?? ($types[0]['code'] ?? 'clients'));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $type   = (string)($_POST['type_code'] ?? $current);

    try {
        if ($action === 'add') {
            $values = preg_split('/\r\n|\r|\n/', (string)($_POST['values'] ?? '')) ?: [];
            $added = 0;
            foreach ($values as $v) {
                $v = trim($v);
                if ($v === '') {
                    continue;
                }
                $exists = q_one('SELECT id, is_active FROM list_items WHERE type_code = ? AND value = ?', [$type, $v]);
                if (!$exists) {
                    q('INSERT INTO list_items (type_code, value, source) VALUES (?,?,\'manual\')', [$type, $v]);
                    $added++;
                } elseif ((int)$exists['is_active'] === 0) {
                    q('UPDATE list_items SET is_active = 1 WHERE id = ?', [$exists['id']]);
                    $added++;
                }
            }
            audit('create', 'list_item', $type, "Adicionados $added valores à lista \"$type\"");
            flash('ok', "$added valor(es) adicionado(s).");
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $item = q_one('SELECT * FROM list_items WHERE id = ?', [$id]);
            if ($item) {
                $new = (int)$item['is_active'] === 1 ? 0 : 1;
                q('UPDATE list_items SET is_active = ? WHERE id = ?', [$new, $id]);
                audit('update', 'list_item', $id,
                      ($new ? 'Reativado' : 'Desativado') . ': ' . $item['value'],
                      ['is_active' => (int)$item['is_active']], ['is_active' => $new]);
            }
        } elseif ($action === 'rename') {
            $id = (int)($_POST['id'] ?? 0);
            $newValue = trim((string)($_POST['value'] ?? ''));
            $item = q_one('SELECT * FROM list_items WHERE id = ?', [$id]);
            if (!$item || $newValue === '') {
                throw new RuntimeException('Valor inválido.');
            }
            q('UPDATE list_items SET value = ? WHERE id = ?', [$newValue, $id]);
            audit('update', 'list_item', $id, 'Renomeado: ' . $item['value'] . ' → ' . $newValue,
                  ['value' => $item['value']], ['value' => $newValue]);
            flash('warn', 'Valor renomeado na lista. As obras e planeamentos já gravados mantêm o texto antigo — verifique-os se necessário.');
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
    redirect('lists.php?t=' . urlencode($type));
}

$items = q_all('SELECT * FROM list_items WHERE type_code = ? ORDER BY is_active DESC, value', [$current]);
$counts = [];
foreach (q_all('SELECT type_code, SUM(is_active) AS act, COUNT(*) AS tot FROM list_items GROUP BY type_code') as $r) {
    $counts[$r['type_code']] = $r;
}

layout_head('Listas base', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('lists'); ?>

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <h2>Listas base</h2>
  <p class="muted">
    Estes valores alimentam as caixas de seleção da plataforma de planeamento.
    Podem ser geridos aqui manualmente ou atualizados em bloco através de
    <a href="import.php">Importar dados</a>.
  </p>
  <div class="actions" style="margin-top:12px">
    <?php foreach ($types as $t):
        $c = $counts[$t['code']] ?? ['act' => 0, 'tot' => 0]; ?>
      <a class="btn <?= $t['code'] === $current ? 'primary' : '' ?>"
         href="lists.php?t=<?= urlencode($t['code']) ?>">
        <?= e($t['label']) ?> <span class="muted">(<?= (int)$c['act'] ?>)</span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Adicionar valores</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="type_code" value="<?= e($current) ?>">
    <label>Um valor por linha
      <textarea name="values" rows="4" placeholder="Novo cliente&#10;Outro cliente"></textarea>
    </label>
    <button class="primary" type="submit">Adicionar</button>
  </form>
</div>

<div class="card">
  <?php
    $currentLabel = $current;
    foreach ($types as $t) {
        if ($t['code'] === $current) {
            $currentLabel = $t['label'];
            break;
        }
    }
  ?>
  <h2><?= e($currentLabel) ?> (<?= count($items) ?>)</h2>
  <div class="scroll">
    <table>
      <thead><tr><th style="width:55%">Valor</th><th>Origem</th><th>Estado</th><th>Ações</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td>
            <form method="post" class="actions">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="type_code" value="<?= e($current) ?>">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <input type="text" name="value" value="<?= e($it['value']) ?>" style="max-width:380px">
              <button type="submit">Guardar</button>
            </form>
          </td>
          <td class="muted"><?= $it['source'] === 'import' ? 'Importado' : 'Manual' ?></td>
          <td><?= (int)$it['is_active'] === 1 ? '<span class="tag on">Ativo</span>' : '<span class="tag off">Inativo</span>' ?></td>
          <td>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="type_code" value="<?= e($current) ?>">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <button type="submit"><?= (int)$it['is_active'] === 1 ? 'Desativar' : 'Reativar' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="4" class="muted">Lista vazia.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="margin-top:10px">
    Os valores não são apagados, apenas desativados: deixam de aparecer nas caixas de seleção
    mas os registos históricos continuam legíveis.
  </p>
</div>

</div>
<?php layout_foot(); ?>
