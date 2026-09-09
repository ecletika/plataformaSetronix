<?php
/**
 * Quem está fora, semana a semana.
 *
 * É o atalho da barra de topo, para se ver quem está disponível sem sair
 * do planeamento. Serve as duas formas: página inteira quando se abre o
 * endereço, e só o miolo (?fragmento=1) quando é a janela a pedi-lo.
 *
 * Sem JavaScript continua a funcionar: o atalho é uma ligação normal e
 * esta página existe por si.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/apps.php';
require_once __DIR__ . '/lib/rh.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login('apps.manage');

// A aplicação com mapa a que esta pessoa tem acesso. Sem ela não há nada
// para mostrar — e é a mesma condição que decide mostrar o atalho.
$app = rh_app_do_utilizador((int)$user['id']);
if (!$app) {
    http_response_code(404);
    if (isset($_GET['fragmento'])) {
        exit('<p class="muted">Não há nenhum mapa de atividade importado.</p>');
    }
    layout_head('Ausências');
    echo '<div class="wrap"><div class="card"><h2>Ausências</h2>'
       . '<p class="muted">Não há nenhum mapa de atividade importado, ou não tem acesso à '
       . 'aplicação que o tem. Um administrador importa-o em Administração → Aplicações.</p>'
       . '</div></div>';
    layout_foot();
    exit;
}

$semana = (string)($_GET['semana'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $semana)) {
    $semana = date('Y-m-d');
}
$seg = date('Y-m-d', strtotime('monday this week', strtotime($semana)));
$sem = rh_ausencias_semana((int)$app['id'], $seg);

$dias = implode(', ', array_map(
    static fn($d) => substr($d, 8, 2) . '/' . substr($d, 5, 2),
    $sem['dias']
));

// ---------------------------------------------------------------------
// Só o miolo, para a janela
// ---------------------------------------------------------------------
if (isset($_GET['fragmento'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?>
    <p class="nota" style="margin:0 0 12px">
      <?= count($sem['dias']) ?> dia(s) de trabalho nesta semana: <?= e($dias) ?>.
      Quem não tiver <b>um único dia livre</b> não aparece nas listas do planeamento.
    </p>
    <?php semana_ausencias($sem); ?>
    <?php
    exit;
}

// ---------------------------------------------------------------------
// Página inteira
// ---------------------------------------------------------------------
layout_head('Ausências');
?>
<div class="wrap">
  <div class="card">
    <h2>Quem está fora, semana a semana</h2>
    <p class="muted">
      Do mapa de atividade de <b><?= e($app['name']) ?></b>. Quem não tiver um único dia
      de trabalho livre na semana não aparece nas listas do planeamento.
    </p>

    <form method="get" class="actions" style="align-items:flex-end;margin:14px 0 12px">
      <label style="margin:0">Semana de
        <input type="date" name="semana" value="<?= e($seg) ?>">
      </label>
      <button class="primary" type="submit">Ver</button>
      <span class="muted" style="margin-left:auto">
        <?= count($sem['dias']) ?> dia(s) de trabalho: <?= e($dias) ?>
      </span>
    </form>

    <?php semana_ausencias($sem); ?>
  </div>
</div>
<?php layout_foot(); ?>
