<?php
/**
 * Página inicial: as aplicações disponíveis para o utilizador autenticado.
 *
 * A plataforma não tem lógica de negócio própria. Cada aplicação é um
 * ficheiro HTML autónomo (feito à medida, por exemplo com o ChatGPT),
 * enviado em Administração → Aplicações e aberto aqui.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/apps.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login('view');
$apps = apps_all(true);

layout_head('Aplicações', 'app');
?>
<style>
.applist{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.appcard{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;
  display:flex;flex-direction:column;gap:8px;text-decoration:none;color:inherit;
  box-shadow:0 1px 2px rgba(15,23,42,.04);transition:border-color .12s,box-shadow .12s}
.appcard:hover{border-color:var(--accent);box-shadow:0 4px 14px rgba(37,99,235,.12)}
.appcard .icon{width:38px;height:38px;border-radius:10px;background:#eff6ff;color:var(--accent);
  display:grid;place-items:center;font-weight:700;font-size:15px}
.appcard b{font-size:15px}
.appcard .meta{margin-top:auto;font-size:12px;color:var(--muted)}
.empty{text-align:center;padding:38px 20px;color:var(--muted)}
</style>

<div class="wrap">

<?php if (!$apps): ?>
  <div class="card">
    <div class="empty">
      <p style="font-size:16px;color:var(--ink)"><b>Ainda não há nenhuma aplicação publicada.</b></p>
      <p>Uma aplicação é um único ficheiro <code>.html</code> com tudo lá dentro.</p>
      <?php if (can('apps.manage')): ?>
        <p style="margin-top:18px"><a class="btn primary" href="admin/apps.php">Enviar a primeira aplicação</a></p>
      <?php else: ?>
        <p>Peça a um administrador para a publicar.</p>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="actions" style="margin-bottom:14px">
    <h2 style="margin:0;font-size:17px">Aplicações</h2>
    <?php if (can('apps.manage')): ?>
      <a class="btn" style="margin-left:auto" href="admin/apps.php">Gerir aplicações</a>
    <?php endif; ?>
  </div>

  <div class="applist">
    <?php foreach ($apps as $app):
        $v = app_current_version($app);
        $initial = mb_strtoupper(mb_substr($app['name'], 0, 1)); ?>
      <a class="appcard" href="app.php?id=<?= (int)$app['id'] ?>">
        <div class="icon"><?= e($initial) ?></div>
        <b><?= e($app['name']) ?></b>
        <?php if (!empty($app['description'])): ?>
          <span class="muted"><?= e($app['description']) ?></span>
        <?php endif; ?>
        <span class="meta">
          <?php if ($v): ?>
            versão <?= (int)$v['version'] ?> · <?= e(substr((string)$v['created_at'], 0, 10)) ?>
          <?php else: ?>
            sem ficheiro
          <?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</div>
<?php layout_foot(); ?>
