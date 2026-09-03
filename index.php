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
$apps = apps_for_user((int)$user['id'], can('apps.manage'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'predefinida') {
    csrf_check();
    $id = (int)($_POST['default_app'] ?? 0);

    // Só aceita uma aplicação que esta pessoa possa mesmo abrir.
    $valida = $id === 0;
    foreach ($apps as $a) {
        if ((int)$a['id'] === $id) {
            $valida = true;
            break;
        }
    }
    if (!$valida) {
        flash('warn', 'Essa aplicação não está disponível para si.');
    } else {
        user_pref_set((int)$user['id'], 'default_app', (string)$id);
        flash('ok', $id === 0
            ? 'Deixou de haver uma aplicação a abrir automaticamente.'
            : 'Ao entrar passa a abrir diretamente essa aplicação.');
    }
    redirect('index.php');
}

$predefinida   = user_default_app();
$predefinidaId = $predefinida ? (int)$predefinida['id'] : 0;

layout_head('Aplicações', 'app');
?>
<style>
.applist{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.appcard{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;
  display:flex;flex-direction:column;gap:8px;text-decoration:none;color:inherit;
  box-shadow:0 1px 2px rgba(27,16,22,.05);transition:border-color .12s,box-shadow .12s}
.appcard:hover{border-color:var(--accent);box-shadow:0 4px 14px rgba(168,0,48,.14)}
.appcard.predef{border-color:var(--rail);box-shadow:0 0 0 1px var(--rail)}
.appcard .icon{width:38px;height:38px;border-radius:10px;background:var(--accent-soft);color:var(--accent);
  display:grid;place-items:center;font-weight:700;font-size:15px}
.appcard b{font-size:15px}
.appcard .meta{margin-top:auto;font-size:12px;color:var(--muted)}
.appcard .marca{align-self:flex-start;font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;
  background:var(--rail-soft);border:1px solid var(--rail-line);color:var(--rail-ink)}
.empty{text-align:center;padding:38px 20px;color:var(--muted)}
.escolha{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-top:14px}
.escolha label{margin:0;flex:1;min-width:240px;max-width:420px}
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

  <div class="card">
    <h2>Aplicação a abrir ao entrar</h2>
    <p class="muted">
      Escolha a que usa todos os dias: passa a abrir sozinha assim que iniciar sessão e
      dá o nome à barra de topo. Para chegar às outras, carregue em <b>Aplicações</b>.
    </p>
    <form method="post" class="escolha">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="predefinida">
      <label>Aplicação predefinida
        <select name="default_app">
          <option value="0" <?= $predefinidaId === 0 ? 'selected' : '' ?>>
            Nenhuma — mostrar esta lista
          </option>
          <?php foreach ($apps as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $predefinidaId === (int)$a['id'] ? 'selected' : '' ?>>
              <?= e($a['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="primary" type="submit">Guardar</button>
    </form>
  </div>

  <h2 style="margin:0 0 14px;font-size:17px">Aplicações</h2>

  <div class="applist">
    <?php foreach ($apps as $app):
        $v = app_current_version($app);
        $predef  = $predefinidaId === (int)$app['id'];
        $initial = mb_strtoupper(mb_substr($app['name'], 0, 1)); ?>
      <a class="appcard<?= $predef ? ' predef' : '' ?>" href="app.php?id=<?= (int)$app['id'] ?>">
        <div class="icon"><?= e($initial) ?></div>
        <b><?= e($app['name']) ?></b>
        <?php if ($predef): ?>
          <span class="marca">abre ao entrar</span>
        <?php endif; ?>
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
