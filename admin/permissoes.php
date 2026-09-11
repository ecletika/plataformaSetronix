<?php
/**
 * Permissões: quem entra em cada aplicação, e com que nível.
 *
 * Escolhe-se a aplicação à esquerda e atribui-se o nível pessoa a pessoa à
 * direita. São dois assuntos diferentes no mesmo sítio:
 *
 *   acesso — vê ou não vê a aplicação;
 *   nível  — o que pode fazer lá dentro (viewer, editor, admin).
 *
 * Uma aplicação a que ninguém foi atribuído continua aberta a toda a gente,
 * como sempre foi. Basta dar nível a uma pessoa para passar a reservada.
 *
 * Sem JavaScript funciona na mesma: cada botão é um formulário e a busca é
 * um GET normal.
 */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/apps.php';
require_once __DIR__ . '/../lib/layout.php';

$me = require_login('users.manage');

$error = '';

// ---------------------------------------------------------------------
// Ações
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $appId  = (int)($_POST['app_id'] ?? 0);
    $app    = $appId ? app_find($appId) : null;

    try {
        if (!$app) {
            throw new RuntimeException('Aplicação não encontrada.');
        }

        if ($action === 'nivel') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $nivel  = (string)($_POST['nivel'] ?? '');
            $alvo   = $userId ? q_one('SELECT * FROM users WHERE id = ?', [$userId]) : null;
            if (!$alvo) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            if ($nivel !== 'nenhum' && !isset(APP_NIVEIS[$nivel])) {
                throw new RuntimeException('Nível de permissão desconhecido.');
            }
            // Quem gere aplicações é sempre admin em todas: mexer aqui daria
            // a ideia de que ficou limitado, e não fica.
            if (in_array('apps.manage', PERMISSIONS[$alvo['role']] ?? [], true)) {
                throw new RuntimeException($alvo['username'] . ' gere aplicações na plataforma '
                    . 'e é sempre Admin em todas. Para o limitar, mude-lhe o perfil em Utilizadores.');
            }

            $antes = app_nivel($userId, $appId);
            app_set_nivel($userId, $appId, $nivel);
            app_sync_default($userId, false);

            audit('update', 'app', $appId,
                  'Permissão em "' . $app['name'] . '": ' . $alvo['username'] . ' → '
                  . ($nivel === 'nenhum' ? 'sem acesso' : APP_NIVEIS[$nivel]),
                  ['nivel' => $antes], ['nivel' => $nivel]);
            flash('ok', $nivel === 'nenhum'
                ? $alvo['username'] . ' deixa de ver "' . $app['name'] . '".'
                : $alvo['username'] . ' passa a ' . APP_NIVEIS[$nivel] . ' em "' . $app['name'] . '".');

        } elseif ($action === 'abrir_a_todos') {
            $n = count(app_user_ids($appId));
            q('DELETE FROM user_apps WHERE app_id = ?', [$appId]);
            audit('update', 'app', $appId,
                  '"' . $app['name'] . '" passa a estar aberta a todos (' . $n . ' atribuição(ões) retirada(s))');
            flash('ok', '"' . $app['name'] . '" volta a estar aberta a toda a gente.');

        } elseif ($action === 'repor_app') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $alvo   = $userId ? q_one('SELECT username FROM users WHERE id = ?', [$userId]) : null;
            if (!$alvo) {
                throw new RuntimeException('Utilizador não encontrado.');
            }
            user_unhide_app($userId, $appId);
            audit('update', 'user', $userId,
                  'Reposta a aplicação "' . $app['name'] . '" a ' . $alvo['username']);
            flash('ok', '"' . $app['name'] . '" voltou à lista de ' . $alvo['username'] . '.');
        }

        redirect('permissoes.php?app=' . $appId . (($_POST['q'] ?? '') !== ''
            ? '&q=' . urlencode((string)$_POST['q']) : ''));
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

// ---------------------------------------------------------------------
// O que há para mostrar
// ---------------------------------------------------------------------
$busca = trim((string)($_GET['q'] ?? ''));
$apps  = apps_all(false);

// Quantas pessoas têm nível atribuído em cada aplicação. Zero quer dizer
// aberta a todos — a mesma regra de sempre.
$quantos = [];
foreach (q_all('SELECT app_id, COUNT(*) AS n FROM user_apps GROUP BY app_id') as $r) {
    $quantos[(int)$r['app_id']] = (int)$r['n'];
}

$visiveis = $apps;
if ($busca !== '') {
    $visiveis = array_values(array_filter($apps, static function (array $a) use ($busca): bool {
        return mb_stripos($a['name'] . ' ' . (string)$a['description'], $busca) !== false;
    }));
}

$appId = isset($_GET['app']) ? (int)$_GET['app'] : 0;
$app   = null;
foreach ($apps as $a) {
    if ((int)$a['id'] === $appId) {
        $app = $a;
        break;
    }
}
// Sem escolha, abre a primeira das que estão à vista: evita o painel vazio.
if (!$app && $visiveis) {
    $app = $visiveis[0];
}

$niveis     = $app ? app_niveis((int)$app['id']) : [];
$reservada  = $niveis !== [];
$escondidas = [];
if ($app) {
    foreach (q_all('SELECT user_id FROM user_apps_hidden WHERE app_id = ?', [(int)$app['id']]) as $r) {
        $escondidas[(int)$r['user_id']] = true;
    }
}
$pessoas = q_all('SELECT * FROM users WHERE is_active = 1 ORDER BY role, username');

/** Os quatro estados possíveis de uma pessoa numa aplicação. */
$ESTADOS = [
    'nenhum' => ['Sem acesso', 'risco'],
    'viewer' => ['Viewer',     'olho'],
    'editor' => ['Editor',     'lapis'],
    'admin'  => ['Admin',      'escudo'],
];

layout_head('Permissões', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('perms'); ?>

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

<?php if (!$apps): ?>
  <div class="card">
    <h2>Permissões</h2>
    <p class="muted">Ainda não há aplicações. Envie a primeira em
      <a href="apps.php">Aplicações</a> e depois volte aqui para dizer quem a pode abrir.</p>
  </div>
<?php else: ?>

<div class="card ficha-app">
  <div class="ficha-cab">
    <h2>Permissões</h2>
    <p class="muted">Escolha a aplicação à esquerda e dê o nível a cada pessoa à direita.
      <b>Sem acesso</b> não a vê; <b>Viewer</b> só consulta; <b>Editor</b> trabalha nela;
      <b>Admin</b> pode tudo lá dentro.</p>
  </div>

  <div class="perm-corpo">
    <!-- ------------------------------------------------- aplicações -->
    <div class="perm-coluna">
      <form method="get" class="perm-busca">
        <input type="search" name="q" value="<?= e($busca) ?>" placeholder="Procurar aplicação..."
               aria-label="Procurar aplicação">
        <button class="btn" type="submit" title="Procurar" aria-label="Procurar"><?= icone('lupa') ?></button>
      </form>
      <?php if ($busca !== ''): ?>
        <p style="margin:0;padding:8px 12px 0;font-size:12.5px">
          <a href="permissoes.php<?= $app ? '?app=' . (int)$app['id'] : '' ?>">Mostrar todas</a>
        </p>
      <?php endif; ?>
      <div class="perm-lista">
        <?php if (!$visiveis): ?>
          <p class="vazio">Nenhuma aplicação com “<?= e($busca) ?>”.</p>
        <?php endif; ?>
        <?php foreach ($visiveis as $a):
            $n   = $quantos[(int)$a['id']] ?? 0;
            $sel = $app && (int)$app['id'] === (int)$a['id']; ?>
          <a href="permissoes.php?app=<?= (int)$a['id'] ?><?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>"
             <?= $sel ? 'aria-current="true"' : '' ?>>
            <span class="mk"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span>
            <span class="nome"><?= e($a['name']) ?></span>
            <span class="qt" title="<?= $n === 0
                ? 'Aberta a todos' : $n . ' pessoa(s) com nível atribuído' ?>"><?= $n ?: '—' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ------------------------------------------------------ quem -->
    <div class="perm-painel">
      <?php if (!$app): ?>
        <p class="muted">Escolha uma aplicação à esquerda.</p>
      <?php else: ?>
        <h3 class="perm-titulo"><?= e($app['name']) ?></h3>
        <?php if (!$reservada): ?>
          <div class="alert info" style="margin:10px 0 14px">
            <b>Aberta a todos.</b> Ninguém foi escolhido, por isso toda a gente a vê, como Editor.
            Assim que der nível a uma pessoa, passa a <b>reservada</b>: só quem estiver aqui a verá.
          </div>
        <?php else: ?>
          <div class="muted" style="margin:0 0 14px;display:flex;align-items:center;gap:9px;flex-wrap:wrap">
            <span>Reservada a <?= count($niveis) ?> pessoa(s). Mais ninguém a vê.</span>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="abrir_a_todos">
              <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
              <input type="hidden" name="q" value="<?= e($busca) ?>">
              <button class="btn" type="submit" style="padding:3px 9px;font-size:12.5px"
                      onclick="return confirm('Abrir «<?= e($app['name']) ?>» a toda a gente? Os níveis atribuídos são apagados.')">
                Abrir a todos
              </button>
            </form>
          </div>
        <?php endif; ?>

        <div class="scroll">
          <table class="perm-tabela">
            <thead>
              <tr><th>Pessoa</th><th>Nível nesta aplicação</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pessoas as $p):
                $pid   = (int)$p['id'];
                $gere  = in_array('apps.manage', PERMISSIONS[$p['role']] ?? [], true);
                $atual = $gere ? 'admin' : ($niveis[$pid] ?? ($reservada ? 'nenhum' : 'editor')); ?>
              <tr>
                <td class="pessoa">
                  <b><?= e($p['full_name']) ?></b>
                  <br><span class="muted mono" style="font-size:12px"><?= e($p['username']) ?></span>
                  <?php if (isset($escondidas[$pid])): ?>
                    <span class="muted" style="font-size:11.5px;display:flex;gap:4px;margin-top:2px">
                      retirou-a da lista dele —
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="repor_app">
                        <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                        <input type="hidden" name="user_id" value="<?= $pid ?>">
                        <input type="hidden" name="q" value="<?= e($busca) ?>">
                        <button class="linkish" type="submit">repor</button>
                      </form>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="nivel">
                  <?php if ($gere): ?>
                    <span class="chip star"><?= icone('escudo') ?> Admin — gere aplicações</span>
                    <span class="muted" style="font-size:11.5px;display:block;margin-top:3px">
                      O perfil da plataforma manda. Mude-o em <a href="users.php">Utilizadores</a>.
                    </span>
                  <?php else: ?>
                    <div class="niveis" role="group"
                         aria-label="Nível de <?= e($p['full_name']) ?> em <?= e($app['name']) ?>">
                      <?php foreach ($ESTADOS as $cod => [$rot, $ic]): ?>
                        <form method="post">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="nivel">
                          <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                          <input type="hidden" name="user_id" value="<?= $pid ?>">
                          <input type="hidden" name="nivel" value="<?= e($cod) ?>">
                          <input type="hidden" name="q" value="<?= e($busca) ?>">
                          <button type="submit" data-n="<?= e($cod) ?>" title="<?= e($rot) ?>"
                                  aria-pressed="<?= $atual === $cod ? 'true' : 'false' ?>">
                            <?= icone($ic) ?><span class="rot"><?= e($rot) ?></span>
                          </button>
                        </form>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <p class="muted" style="margin-top:12px">
          A aplicação recebe o nível em <span class="mono">SETRONIX_BOOT.nivel</span> e desliga
          sozinha o que a pessoa não pode fazer. Contas desativadas não aparecem aqui.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php endif; ?>
</div>
<?php layout_foot(); ?>
