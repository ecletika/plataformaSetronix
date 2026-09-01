<?php
/** Cabeçalho, rodapé e estilos partilhados pelas páginas de gestão. */

declare(strict_types=1);

/**
 * @param string $title    Título da página.
 * @param string $variant  'app' (com barra de navegação) ou 'auth' (ecrã centrado).
 * @param string $base     Prefixo de URL para a raiz da aplicação ('' ou '../').
 */
function layout_head(string $title, string $variant = 'app', string $base = ''): void
{
    global $CONFIG;
    $org = $CONFIG['app']['org'] ?? 'Setronix';
    ?><!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($org) ?></title>
<style>
:root{
  --bg:#f1f5f9; --panel:#ffffff; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0;
  --brand:#0f172a; --accent:#2563eb; --ok:#16a34a; --warn:#d97706; --danger:#dc2626;
}
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink)}
a{color:var(--accent)}
header.topbar{background:var(--brand);color:#fff;padding:12px 20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
header.topbar h1{font-size:16px;margin:0;font-weight:600}
header.topbar nav{display:flex;gap:4px;flex-wrap:wrap;margin-left:auto}
header.topbar nav a{color:#cbd5e1;text-decoration:none;padding:6px 10px;border-radius:6px;font-size:13px}
header.topbar nav a:hover{background:rgba(255,255,255,.1);color:#fff}
header.topbar nav a.active{background:rgba(255,255,255,.16);color:#fff}
.who{font-size:12px;color:#94a3b8}
.wrap{max-width:1180px;margin:22px auto;padding:0 18px}
.wrap.narrow{max-width:520px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:18px;
      box-shadow:0 1px 2px rgba(15,23,42,.04)}
.card h2{margin:0 0 4px;font-size:17px}
.card h3{margin:18px 0 8px;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.muted{color:var(--muted);font-size:13px}
label{display:block;margin-bottom:12px;font-size:13px;color:#334155}
label>span.req::after{content:" *";color:var(--danger)}
input[type=text],input[type=email],input[type=password],input[type=date],input[type=number],input[type=file],select,textarea{
  width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;background:#fff;margin-top:4px}
input:focus,select:focus,textarea:focus{outline:2px solid #bfdbfe;border-color:var(--accent)}
button,.btn{font:inherit;padding:9px 14px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;
  text-decoration:none;display:inline-block;color:var(--ink)}
button:hover,.btn:hover{background:#f8fafc}
button.primary,.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
button.primary:hover{background:#1d4ed8}
button.danger,.btn.danger{background:#fff;border-color:#fca5a5;color:var(--danger)}
button.danger:hover{background:#fef2f2}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px}
.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.actions.right{justify-content:flex-end}
.alert{padding:11px 13px;border-radius:8px;margin-bottom:14px;font-size:13px;border:1px solid}
.alert.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.alert.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
.alert.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
.alert.info{background:#eff6ff;border-color:#bfdbfe;color:#1e40af}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{background:#f8fafc;font-weight:600;color:#475569;position:sticky;top:0}
tbody tr:hover{background:#f8fafc}
.tag{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;border:1px solid}
.tag.admin{background:#ede9fe;border-color:#c4b5fd;color:#5b21b6}
.tag.gestor{background:#dbeafe;border-color:#93c5fd;color:#1e40af}
.tag.supervisor{background:#dcfce7;border-color:#86efac;color:#166534}
.tag.leitor{background:#f1f5f9;border-color:#cbd5e1;color:#475569}
.tag.on{background:#dcfce7;border-color:#86efac;color:#166534}
.tag.off{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.scroll{max-height:60vh;overflow:auto;border:1px solid var(--line);border-radius:8px}
code,.mono{font-family:ui-monospace,"Cascadia Code",Consolas,monospace}
.secret{font-family:ui-monospace,Consolas,monospace;font-size:19px;letter-spacing:2px;background:#f8fafc;
  border:1px dashed #94a3b8;border-radius:8px;padding:14px;text-align:center;user-select:all;word-break:break-all}
/* Dois painéis de transferência (ver transfer_list()) */
.tr{display:grid;grid-template-columns:1fr 108px 1fr;gap:12px;align-items:start}
.tr-panel{border:1px solid var(--line);border-radius:10px;background:#fff;overflow:hidden;min-width:0}
.tr-head{padding:9px 12px;border-bottom:1px solid var(--line);background:#f8fafc;display:flex;
  justify-content:space-between;gap:8px;font-size:11.5px;font-weight:600;letter-spacing:.06em;
  text-transform:uppercase;color:var(--muted)}
.tr-head b{font-variant-numeric:tabular-nums;color:var(--ink)}
.tr-search{padding:9px 10px;border-bottom:1px solid var(--line)}
.tr-search input{margin:0;padding:7px 10px;font-size:13px}
.tr-list{list-style:none;margin:0;padding:6px;min-height:150px;max-height:320px;overflow:auto;
  display:flex;flex-direction:column;gap:3px}
.tr-item{display:flex;align-items:center;gap:9px;padding:8px 9px;border-radius:7px;margin:0;
  font-size:13.5px;color:var(--ink);cursor:pointer;transition:background .15s ease}
.tr-item:hover{background:#eff6ff}
.tr-item input{position:absolute;opacity:0;width:1px;height:1px;margin:0}
.tr-item input:focus-visible + .tr-mark{outline:2px solid var(--accent);outline-offset:2px}
.tr-mark{width:26px;height:26px;border-radius:7px;background:#eff6ff;color:var(--accent);flex:none;
  display:grid;place-items:center;font-weight:700;font-size:11px}
.tr-txt{min-width:0;flex:1}
.tr-txt b{display:block;font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tr-txt small{display:block;color:var(--muted);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tr-item.locked{cursor:default;background:#f8fafc}
.tr-item.locked:hover{background:#f8fafc}
.tr-empty{color:var(--muted);font-size:12.5px;padding:16px 10px;text-align:center}
.tr-mid{display:flex;flex-direction:column;gap:8px;align-items:center;padding-top:56px}
.tr-mid .btn{width:100%;text-align:center;font-size:12.5px;padding:7px 6px}
.tr-hint{color:var(--muted);font-size:12.5px;margin:10px 0 0}
@media (max-width:820px){
  .tr{grid-template-columns:1fr}
  .tr-mid{flex-direction:row;padding-top:0}
}
.qr{display:flex;justify-content:center;margin:14px 0}
.qr svg{border:1px solid var(--line);border-radius:8px;max-width:100%;height:auto}
.codes{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;font-family:ui-monospace,Consolas,monospace}
.codes span{background:#f8fafc;border:1px solid var(--line);border-radius:6px;padding:8px;text-align:center}
.otp{font-size:26px;letter-spacing:10px;text-align:center;font-family:ui-monospace,Consolas,monospace}
.brandmark{width:30px;height:30px;border-radius:8px;background:var(--accent);display:grid;place-items:center;
  color:#fff;font-weight:700;font-size:14px}
footer.foot{text-align:center;color:var(--muted);font-size:12px;padding:20px}
</style>
</head>
<body>
<?php if ($variant === 'app'):
    $u = current_user();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $nav = static function (string $href, string $label, bool $active): void {
        echo '<a href="' . e($href) . '"' . ($active ? ' class="active"' : '') . '>' . e($label) . '</a>';
    };
    ?>
<header class="topbar">
  <div class="brandmark">S</div>
  <h1><?= e($CONFIG['app']['name'] ?? 'Planeamento') ?></h1>
  <nav>
    <?php
    $nav($base . 'index.php', 'Aplicações', $script === 'index.php' && $dir !== 'admin');
    if (can('users.manage') || can('apps.manage')) {
        $nav($base . 'admin/index.php', 'Administração', $dir === 'admin');
    }
    $nav($base . 'perfil.php', 'A minha conta', $script === 'perfil.php');
    $nav($base . 'logout.php', 'Sair', false);
    ?>
  </nav>
  <?php if ($u): ?>
    <div class="who"><?= e($u['full_name']) ?> · <?= e(ROLES[$u['role']] ?? $u['role']) ?></div>
  <?php endif; ?>
</header>
<?php endif;

    foreach (flash_take() as $f) {
        echo '<div class="wrap"><div class="alert ' . e($f['type']) . '">' . e($f['message']) . '</div></div>';
    }
}

/** Rodapé comum. */
function layout_foot(): void
{
    global $CONFIG;
    echo '<footer class="foot">' . e($CONFIG['app']['org'] ?? 'Setronix')
       . ' · ' . e($CONFIG['app']['name'] ?? '') . ' · ' . date('Y') . '</footer></body></html>';
}

/**
 * Dois painéis de transferência: à esquerda o que não tem acesso, à direita
 * o que tem. Carregar num item passa-o para o outro lado.
 *
 * A coluna da direita é, literalmente, o que a pessoa vai ver — por isso os
 * itens fixos (aplicações abertas a toda a gente) aparecem lá também, sem
 * possibilidade de os mover. Foi a falta deles que levou a pensar que uma
 * atribuição tinha dado acesso a mais do que o esperado.
 *
 * Sem JavaScript o item não muda de painel, mas a caixa de verificação
 * escondida continua a alternar: o formulário grava na mesma.
 *
 * @param string $field  nome do campo do formulário (ex.: 'apps')
 * @param array  $items  cada um: id, title, sub, mark, granted, locked, note
 * @param array  $labels left, right, empty_left, empty_right, hint
 */
function transfer_list(string $field, array $items, array $labels): void
{
    $id = 'tr-' . preg_replace('/[^a-z0-9]/i', '', $field);

    $render = static function (array $it) use ($field): void {
        $locked = !empty($it['locked']);
        echo '<li>';
        echo '<label class="tr-item' . ($locked ? ' locked' : '') . '">';
        if (!$locked) {
            echo '<input type="checkbox" name="' . e($field) . '[]" value="' . (int)$it['id'] . '"'
               . (!empty($it['granted']) ? ' checked' : '') . '>';
        }
        echo '<span class="tr-mark">' . e((string)($it['mark'] ?? '?')) . '</span>';
        echo '<span class="tr-txt"><b>' . e((string)$it['title']) . '</b>';
        if (!empty($it['sub'])) {
            echo '<small>' . e((string)$it['sub']) . '</small>';
        }
        echo '</span>';
        if (!empty($it['note'])) {
            echo '<span class="tag leitor">' . e((string)$it['note']) . '</span>';
        }
        echo '</label></li>';
    };

    $left  = array_filter($items, static fn($i) => empty($i['granted']) && empty($i['locked']));
    $right = array_filter($items, static fn($i) => !empty($i['granted']) || !empty($i['locked']));
    ?>
    <div class="tr" id="<?= e($id) ?>">
      <div class="tr-panel" data-side="left">
        <div class="tr-head"><span><?= e($labels['left']) ?></span><b class="tr-n">0</b></div>
        <div class="tr-search">
          <input type="text" placeholder="Procurar…" aria-label="Procurar em <?= e($labels['left']) ?>">
        </div>
        <ul class="tr-list">
          <?php foreach ($left as $it) { $render($it); } ?>
        </ul>
        <p class="tr-empty" hidden><?= e($labels['empty_left']) ?></p>
      </div>

      <div class="tr-mid">
        <span class="muted" style="font-size:12px;text-align:center">
          Carregue num<br>item para o<br>passar ao lado
        </span>
      </div>

      <div class="tr-panel" data-side="right">
        <div class="tr-head"><span><?= e($labels['right']) ?></span><b class="tr-n">0</b></div>
        <div class="tr-search">
          <input type="text" placeholder="Procurar…" aria-label="Procurar em <?= e($labels['right']) ?>">
        </div>
        <ul class="tr-list">
          <?php foreach ($right as $it) { $render($it); } ?>
        </ul>
        <p class="tr-empty" hidden><?= e($labels['empty_right']) ?></p>
      </div>
    </div>
    <?php if (!empty($labels['hint'])): ?>
      <p class="tr-hint"><?= $labels['hint'] ?></p>
    <?php endif; ?>
    <script>
    (function () {
      var root = document.getElementById(<?= json_encode($id) ?>);
      if (!root) { return; }
      var panels = {};
      root.querySelectorAll('.tr-panel').forEach(function (p) { panels[p.dataset.side] = p; });

      function refresh() {
        Object.keys(panels).forEach(function (side) {
          var p = panels[side];
          var items = p.querySelectorAll('.tr-list > li');
          p.querySelector('.tr-n').textContent = items.length;
          p.querySelector('.tr-empty').hidden = items.length > 0;
        });
      }

      root.addEventListener('change', function (ev) {
        var box = ev.target;
        if (!box.matches('.tr-item input')) { return; }
        var li = box.closest('li');
        var to = box.checked ? 'right' : 'left';
        panels[to].querySelector('.tr-list').appendChild(li);
        refresh();
        box.focus();
      });

      root.addEventListener('input', function (ev) {
        var field = ev.target;
        if (!field.matches('.tr-search input')) { return; }
        var termo = field.value.trim().toLowerCase();
        field.closest('.tr-panel').querySelectorAll('.tr-list > li').forEach(function (li) {
          li.hidden = termo !== '' && li.textContent.toLowerCase().indexOf(termo) === -1;
        });
      });

      refresh();
    })();
    </script>
    <?php
}

/** Barra de navegação secundária da área de administração. */
function admin_nav(string $current): void
{
    $items = [
        'index' => ['index.php', 'Resumo',            'view'],
        'apps'  => ['apps.php',  'Aplicações',        'apps.manage'],
        'users' => ['users.php', 'Utilizadores',      'users.manage'],
        'audit' => ['audit.php', 'Log de alterações', 'audit.view'],
    ];
    echo '<div class="card" style="padding:10px 14px"><div class="actions">';
    foreach ($items as $key => [$href, $label, $perm]) {
        if (!can($perm)) {
            continue;
        }
        $cls = $key === $current ? 'btn primary' : 'btn';
        echo '<a class="' . $cls . '" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</div></div>';
}
