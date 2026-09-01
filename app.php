<?php
/**
 * Abre uma aplicação: barra fina no topo + a página HTML num <iframe>
 * que ocupa o resto do ecrã.
 *
 * O iframe é servido pela mesma origem, para que o localStorage da
 * aplicação funcione e os dados do utilizador persistam entre visitas.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/apps.php';

$user = require_login('view');

$app = app_find((int)($_GET['id'] ?? 0));
if (!$app || !user_can_open_app((int)$user['id'], $app, can('apps.manage'))) {
    http_response_code(404);
    require_once __DIR__ . '/lib/layout.php';
    layout_head('Aplicação não encontrada', 'app');
    echo '<div class="wrap"><div class="card"><h2>Aplicação não encontrada</h2>'
       . '<p class="muted">A aplicação foi removida ou está desativada.</p>'
       . '<p><a class="btn" href="index.php">Voltar às aplicações</a></p></div></div>';
    layout_foot();
    exit;
}

$version = app_current_version($app);
$src     = 'app_raw.php?id=' . (int)$app['id'] . '&t=' . (int)($version['id'] ?? 0);
$org     = app_name();
?><!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($app['name']) ?> · <?= e($org) ?></title>
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;display:flex;flex-direction:column;background:#5c0019;
     font:13px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.bar{flex:0 0 auto;background:#5c0019;color:#fff;padding:8px 14px;display:flex;align-items:center;
     gap:12px;flex-wrap:wrap}
.bar a{color:rgba(255,255,255,.75);text-decoration:none;padding:5px 10px;border-radius:6px;font-size:12px}
.bar a:hover{background:rgba(255,255,255,.1);color:#fff}
.bar .name{font-weight:600;font-size:14px}
.bar .plate{background:#fff;border-radius:6px;padding:4px 7px;display:flex;align-items:center;flex:none}
.bar .plate img{height:18px;width:auto;display:block}
.bar .sep{color:rgba(255,255,255,.35)}
.bar .who{margin-left:auto;color:rgba(255,255,255,.6);font-size:12px}
iframe{flex:1 1 auto;width:100%;border:0;background:#fff;display:block}
.err{padding:40px;color:#fff;text-align:center}
</style>
</head>
<body>
<div class="bar">
  <span class="plate"><img src="assets/logo-setronix.png" alt="Setronix" width="718" height="277"></span>
  <a href="index.php" title="Voltar às aplicações">&larr; Aplicações</a>
  <span class="sep">|</span>
  <span class="name"><?= e($app['name']) ?></span>
  <?php if ($version): ?>
    <span class="sep">v<?= (int)$version['version'] ?></span>
  <?php endif; ?>
  <a href="<?= e($src) ?>" target="_blank" rel="noopener">Abrir em separador</a>
  <?php if (can('apps.manage')): ?>
    <a href="admin/apps.php?id=<?= (int)$app['id'] ?>">Gerir</a>
  <?php endif; ?>
  <span class="who"><?= e($user['full_name']) ?> · <a href="logout.php">Sair</a></span>
</div>

<?php if ($version): ?>
  <iframe src="<?= e($src) ?>" title="<?= e($app['name']) ?>"
          allow="clipboard-write; fullscreen"></iframe>
<?php else: ?>
  <div class="err">
    Esta aplicação ainda não tem ficheiro HTML.
    <?php if (can('apps.manage')): ?>
      <br><a href="admin/apps.php?id=<?= (int)$app['id'] ?>" style="color:#f8d000">Enviar agora</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
</body>
</html>
