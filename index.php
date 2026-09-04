<?php
/**
 * Lista das aplicações do utilizador.
 *
 * Quem tem uma aplicação predefinida entra diretamente nela; esta lista
 * chega-se pelo menu "Aplicações" na barra de topo, ou por ?todas=1.
 *
 * Aqui escolhe-se a predefinida (a estrela) e arruma-se a lista (remover),
 * que só esconde a aplicação desta pessoa — um administrador pode repô-la.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/apps.php';
require_once __DIR__ . '/lib/layout.php';

$user   = require_login('view');
$gereApps = can('apps.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accao = (string)($_POST['action'] ?? '');
    $id    = (int)($_POST['app_id'] ?? 0);
    $app   = $id ? app_find($id) : null;

    if (!$app || !user_can_open_app((int)$user['id'], $app, $gereApps)) {
        flash('warn', 'Essa aplicação não está disponível para si.');
    } elseif ($accao === 'predefinir') {
        app_set_default((int)$user['id'], $id, 'utilizador');
        flash('ok', '"' . $app['name'] . '" passa a abrir automaticamente ao entrar.');
    } elseif ($accao === 'limpar') {
        app_set_default((int)$user['id'], 0, 'utilizador');
        flash('ok', 'Deixou de haver uma aplicação a abrir automaticamente.');
    } elseif ($accao === 'remover') {
        user_hide_app((int)$user['id'], $id);
        // Se ficou com uma só, essa passa a abrir ao entrar.
        app_sync_default((int)$user['id'], $gereApps);
        audit('update', 'app', $id, 'Utilizador retirou "' . $app['name'] . '" da sua lista');
        flash('ok', '"' . $app['name'] . '" foi retirada da sua lista. '
                  . 'Um administrador pode repô-la.');
    }
    redirect('index.php?todas=1');
}

$apps          = apps_for_user((int)$user['id'], $gereApps);
$predefinida   = user_default_app();
$predefinidaId = $predefinida ? (int)$predefinida['id'] : 0;

// O ecrã principal é o da aplicação: quem tem uma escolhida entra nela.
if ($predefinida && !isset($_GET['todas'])) {
    redirect('app.php?id=' . $predefinidaId);
}

layout_head('Aplicações', 'app');
?>
<style>
.applist{display:flex;flex-direction:column;gap:10px}
.approw{display:flex;align-items:center;gap:14px;background:var(--panel);border:1px solid var(--line);
  border-radius:12px;padding:14px 16px;box-shadow:0 1px 2px rgba(27,16,22,.05);
  transition:border-color .12s,box-shadow .12s}
.approw:hover{border-color:var(--accent);box-shadow:0 4px 14px rgba(168,0,48,.14)}
.approw.predef{border-color:#f5a800;box-shadow:0 0 0 1px #f5a800}
.approw .icon{width:44px;height:44px;border-radius:12px;background:var(--accent-soft);color:var(--accent);
  display:grid;place-items:center;font-weight:700;font-size:17px;flex:none}
.approw .txt{flex:1;min-width:0;text-decoration:none;color:inherit}
.approw .txt b{display:block;font-size:15px}
.approw .txt small{display:block;color:var(--muted);font-size:12.5px;margin-top:1px}
.approw .acts{display:flex;align-items:center;gap:6px;flex:none}
.approw form{margin:0;display:flex}
.iconbtn{width:38px;height:38px;border-radius:10px;border:1px solid transparent;background:transparent;
  cursor:pointer;padding:0;display:grid;place-items:center;color:var(--muted);
  transition:background .13s,color .13s}
.iconbtn svg{width:20px;height:20px;display:block}
.iconbtn:hover{background:var(--surface);color:var(--ink)}
.iconbtn.star:hover{color:#f5a800}
.iconbtn.star.on{color:#f5a800}
.iconbtn.star.on:hover{background:#fff8e6}
.iconbtn.del:hover{background:var(--danger-soft);color:var(--danger)}
.iconbtn:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
.empty{text-align:center;padding:38px 20px;color:var(--muted)}
</style>

<div class="wrap">

<?php if (!$apps): ?>
  <div class="card">
    <div class="empty">
      <p style="font-size:16px;color:var(--ink)"><b>Não tem nenhuma aplicação disponível.</b></p>
      <?php if (can('apps.manage')): ?>
        <p>Publique a primeira em Administração.</p>
        <p style="margin-top:18px"><a class="btn primary" href="admin/apps.php">Enviar uma aplicação</a></p>
      <?php else: ?>
        <p>
          Ou ainda não foi publicada nenhuma, ou retirou-as todas da sua lista.
          Peça a um administrador para lhe atribuir ou repor uma aplicação.
        </p>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

  <div class="actions" style="margin-bottom:6px">
    <h2 style="margin:0;font-size:17px">As minhas aplicações</h2>
    <?php if ($predefinidaId): ?>
      <form method="post" style="margin-left:auto">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="limpar">
        <input type="hidden" name="app_id" value="<?= $predefinidaId ?>">
        <button type="submit">Deixar de abrir automaticamente</button>
      </form>
    <?php endif; ?>
  </div>
  <p class="muted" style="margin:0 0 16px">
    A estrela marca a aplicação que abre sozinha ao entrar.
    O caixote retira a aplicação desta lista — não lhe tira o acesso, e um administrador
    pode repô-la.
  </p>

  <div class="applist">
    <?php foreach ($apps as $a):
        $v      = app_current_version($a);
        $predef = $predefinidaId === (int)$a['id']; ?>
      <div class="approw<?= $predef ? ' predef' : '' ?>">
        <span class="icon"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span>
        <a class="txt" href="app.php?id=<?= (int)$a['id'] ?>">
          <b><?= e($a['name']) ?></b>
          <small>
            <?php if (!empty($a['description'])): ?>
              <?= e($a['description']) ?> ·
            <?php endif; ?>
            <?= $v ? 'versão ' . (int)$v['version'] . ' · ' . e(substr((string)$v['created_at'], 0, 10))
                   : 'sem ficheiro' ?>
          </small>
        </a>
        <span class="acts">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $predef ? 'limpar' : 'predefinir' ?>">
            <input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
            <button class="iconbtn star<?= $predef ? ' on' : '' ?>" type="submit"
                    title="<?= $predef ? 'Deixar de abrir automaticamente' : 'Abrir esta ao entrar' ?>"
                    aria-label="<?= $predef ? 'Deixar de abrir automaticamente' : 'Definir como predefinida' ?>">
              <?php if ($predef): ?>
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 1.6l2.55 5.17 5.7.83-4.12 4.02.97
                     5.68L10 14.62l-5.1 2.68.97-5.68L1.75 7.6l5.7-.83z"/></svg>
              <?php else: ?>
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"
                     stroke-linejoin="round"><path d="M10 1.6l2.55 5.17 5.7.83-4.12 4.02.97
                     5.68L10 14.62l-5.1 2.68.97-5.68L1.75 7.6l5.7-.83z"/></svg>
              <?php endif; ?>
            </button>
          </form>
          <form method="post"
                onsubmit="return confirm('Retirar &quot;<?= e($a['name']) ?>&quot; da sua lista? Não perde o acesso — um administrador pode repô-la.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remover">
            <input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
            <button class="iconbtn del" type="submit" title="Retirar da minha lista"
                    aria-label="Retirar <?= e($a['name']) ?> da minha lista">
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"
                   stroke-linecap="round" stroke-linejoin="round">
                <path d="M3.5 5.5h13M8 5.5V3.6h4v1.9M5.4 5.5l.8 10.4h7.6l.8-10.4M8.4 8.4v5M11.6 8.4v5"/>
              </svg>
            </button>
          </form>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</div>
<?php layout_foot(); ?>
