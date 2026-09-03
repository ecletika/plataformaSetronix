<?php
/**
 * Abre uma aplicação: a barra de topo da plataforma, com os menus todos,
 * e a página HTML num <iframe> que ocupa o resto do ecrã.
 *
 * Não há botão "voltar": os menus da barra continuam disponíveis, e é por
 * eles que se muda de aplicação ou se vai à administração.
 *
 * O iframe é servido pela mesma origem, para que o localStorage da
 * aplicação funcione e os dados do utilizador persistam entre visitas.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/apps.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login('view');

$app = app_find((int)($_GET['id'] ?? 0));
if (!$app || !user_can_open_app((int)$user['id'], $app, can('apps.manage'))) {
    http_response_code(404);
    layout_head('Aplicação não encontrada', 'app');
    echo '<div class="wrap"><div class="card"><h2>Aplicação não encontrada</h2>'
       . '<p class="muted">A aplicação foi removida, está desativada, ou retirou-a da '
       . 'sua lista. Um administrador pode repô-la.</p>'
       . '<p><a class="btn" href="index.php?todas=1">Ver as minhas aplicações</a></p></div></div>';
    layout_foot();
    exit;
}

$version = app_current_version($app);
$src     = 'app_raw.php?id=' . (int)$app['id'] . '&t=' . (int)($version['id'] ?? 0);

layout_head($app['name'], 'app');
?>
<style>
/* O ecrã inteiro é da aplicação: a barra em cima, o resto para o iframe. */
html,body{height:100%}
body{display:flex;flex-direction:column;overflow:hidden}
header.topbar{flex:0 0 auto}
.viewer{flex:1 1 auto;display:flex;min-height:0;background:#fff}
.viewer iframe{flex:1 1 auto;width:100%;border:0;display:block;background:#fff}
.viewer .vazio{margin:auto;text-align:center;padding:40px;color:var(--muted)}
footer.foot{display:none}
.wrap{margin:0 auto}
</style>

<div class="viewer">
<?php if ($version): ?>
  <iframe src="<?= e($src) ?>" title="<?= e($app['name']) ?>"
          allow="clipboard-write; fullscreen"></iframe>
<?php else: ?>
  <div class="vazio">
    <p style="font-size:16px;color:var(--ink)"><b>Esta aplicação ainda não tem ficheiro HTML.</b></p>
    <?php if (can('apps.manage')): ?>
      <p style="margin-top:14px">
        <a class="btn primary" href="admin/apps.php?id=<?= (int)$app['id'] ?>">Enviar agora</a>
      </p>
    <?php else: ?>
      <p>Peça a um administrador para a publicar.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<?php layout_foot(); ?>
