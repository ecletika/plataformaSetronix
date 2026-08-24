<?php
/** Log de alterações e tentativas de autenticação. */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

$me = require_login('audit.view');

$fUser   = trim((string)($_GET['u'] ?? ''));
$fAction = trim((string)($_GET['a'] ?? ''));
$fEntity = trim((string)($_GET['e'] ?? ''));
$fFrom   = trim((string)($_GET['from'] ?? ''));
$fTo     = trim((string)($_GET['to'] ?? ''));
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 60;

$where = [];
$args  = [];
if ($fUser !== '')   { $where[] = 'username = ?';            $args[] = $fUser; }
if ($fAction !== '') { $where[] = 'action = ?';              $args[] = $fAction; }
if ($fEntity !== '') { $where[] = 'entity = ?';              $args[] = $fEntity; }
if ($fFrom !== '')   { $where[] = 'created_at >= ?';         $args[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')     { $where[] = 'created_at <= ?';         $args[] = $fTo . ' 23:59:59'; }
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Exportação CSV do resultado filtrado.
if (($_GET['export'] ?? '') === 'csv') {
    $rows = q_all('SELECT created_at, username, action, entity, entity_id, summary, ip
                   FROM audit_log' . $sqlWhere . ' ORDER BY id DESC LIMIT 5000', $args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="log_alteracoes_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data', 'Utilizador', 'Ação', 'Entidade', 'ID', 'Descrição', 'IP'], ';');
    foreach ($rows as $r) {
        fputcsv($out, array_values($r), ';');
    }
    fclose($out);
    audit('export', 'system', null, 'Exportação do log de alterações (' . count($rows) . ' linhas)');
    exit;
}

$total = (int)q_val('SELECT COUNT(*) FROM audit_log' . $sqlWhere, $args);
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$rows  = q_all('SELECT * FROM audit_log' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $perPage
               . ' OFFSET ' . (($page - 1) * $perPage), $args);

$actions  = array_column(q_all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action');
$entities = array_column(q_all('SELECT DISTINCT entity FROM audit_log WHERE entity IS NOT NULL ORDER BY entity'), 'entity');
$users    = array_column(q_all('SELECT DISTINCT username FROM audit_log WHERE username IS NOT NULL ORDER BY username'), 'username');

$attempts = q_all('SELECT * FROM login_attempts WHERE successful = 0
                   ORDER BY id DESC LIMIT 25');

/** Constrói um URL preservando os filtros. */
function link_with(array $over): string
{
    $q = array_merge($_GET, $over);
    unset($q['export']);
    return 'audit.php?' . http_build_query($q);
}

layout_head('Log de alterações', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('audit'); ?>

<div class="card">
  <h2>Log de alterações</h2>
  <form method="get" class="grid2">
    <label>Utilizador
      <select name="u">
        <option value="">Todos</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= e($u) ?>" <?= $u === $fUser ? 'selected' : '' ?>><?= e($u) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Ação
      <select name="a">
        <option value="">Todas</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a) ?>" <?= $a === $fAction ? 'selected' : '' ?>><?= e($a) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Entidade
      <select name="e">
        <option value="">Todas</option>
        <?php foreach ($entities as $en): ?>
          <option value="<?= e($en) ?>" <?= $en === $fEntity ? 'selected' : '' ?>><?= e($en) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>De <input type="date" name="from" value="<?= e($fFrom) ?>"></label>
    <label>Até <input type="date" name="to" value="<?= e($fTo) ?>"></label>
    <div class="actions" style="align-self:end;margin-bottom:12px">
      <button class="primary" type="submit">Filtrar</button>
      <a class="btn" href="audit.php">Limpar</a>
      <a class="btn" href="<?= e(link_with(['export' => 'csv'])) ?>">Exportar CSV</a>
    </div>
  </form>
  <p class="muted"><?= $total ?> registo(s) · página <?= $page ?> de <?= $pages ?></p>
</div>

<div class="card">
  <div class="scroll">
    <table>
      <thead><tr><th>Data</th><th>Utilizador</th><th>Ação</th><th>Entidade</th>
                 <th>Descrição</th><th>IP</th><th>Detalhe</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="mono" style="white-space:nowrap"><?= e($r['created_at']) ?></td>
          <td><?= e((string)$r['username']) ?></td>
          <td><?= e($r['action']) ?></td>
          <td class="muted"><?= e((string)$r['entity']) ?><?= $r['entity_id'] !== null ? ' #' . e((string)$r['entity_id']) : '' ?></td>
          <td><?= e((string)$r['summary']) ?></td>
          <td class="mono muted"><?= e((string)$r['ip']) ?></td>
          <td>
            <?php if ($r['data_before'] !== null || $r['data_after'] !== null): ?>
              <details>
                <summary class="muted" style="cursor:pointer">ver</summary>
                <?php if ($r['data_before'] !== null): ?>
                  <div class="muted">Antes</div>
                  <pre class="mono" style="font-size:11px;white-space:pre-wrap"><?= e((string)$r['data_before']) ?></pre>
                <?php endif; ?>
                <?php if ($r['data_after'] !== null): ?>
                  <div class="muted">Depois</div>
                  <pre class="mono" style="font-size:11px;white-space:pre-wrap"><?= e((string)$r['data_after']) ?></pre>
                <?php endif; ?>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">Sem registos para os filtros escolhidos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="actions" style="margin-top:12px">
    <?php if ($page > 1): ?><a class="btn" href="<?= e(link_with(['p' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn" href="<?= e(link_with(['p' => $page + 1])) ?>">Seguinte →</a><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Últimas tentativas de autenticação falhadas</h2>
  <div class="scroll" style="max-height:34vh">
    <table>
      <thead><tr><th>Data</th><th>Utilizador indicado</th><th>Fase</th><th>Motivo</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($attempts as $a): ?>
        <tr>
          <td class="mono"><?= e($a['created_at']) ?></td>
          <td><?= e((string)$a['username']) ?></td>
          <td><?= e($a['stage']) ?></td>
          <td class="muted"><?= e((string)$a['reason']) ?></td>
          <td class="mono"><?= e((string)$a['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$attempts): ?><tr><td colspan="5" class="muted">Sem tentativas falhadas registadas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
<?php layout_foot(); ?>
