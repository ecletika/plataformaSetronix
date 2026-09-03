<?php
/** Cabeçalho, rodapé e estilos partilhados pelas páginas de gestão. */

declare(strict_types=1);

// A barra de topo mostra o nome da aplicação predefinida do utilizador.
require_once __DIR__ . '/apps.php';

/**
 * @param string $title    Título da página.
 * @param string $variant  'app' (com barra de navegação) ou 'auth' (ecrã centrado).
 * @param string $base     Prefixo de URL para a raiz da aplicação ('' ou '../').
 */
function layout_head(string $title, string $variant = 'app', string $base = ''): void
{
    global $CONFIG;
    $org  = $CONFIG['app']['org'] ?? 'Setronix';
    $bar  = topbar_color();
    $bark = ink_on($bar);
    ?><!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e(app_name()) ?></title>
<style>
:root{
  /* Paleta Setronix, medida no logotipo:
     #A80030 carmim do letreiro (7,76:1 sobre branco), #F89818 laranja,
     #F8D000 amarelo. O laranja tem 2,21:1 e o amarelo 1,50:1 -- nunca
     levam texto por cima nem servem de fundo a botoes: so realce. */
  --bg:#f7f5f6; --panel:#ffffff; --ink:#1b1016; --muted:#6b5c62; --line:#e7dfe2;
  --accent:#a80030; --accent-hi:#8a0027;
  --accent-soft:#fdf0f3; --accent-line:#f2c4d0; --accent-ink:#7a0022;
  --rail:#f89818; --rail-soft:#fff6e8; --rail-line:#f8d000; --rail-ink:#7a4a00;
  --ok:#16a34a; --warn:#b45309; --danger:#8a0027;
  --danger-line:#e0a3b2; --danger-soft:#fdf0f3;
  /* Neutros com um desvio para o quente: os cinzentos azulados de origem
     liam-se como um resto da paleta anterior. */
  --surface:#faf7f8; --field:#d6cdd1; --ink-2:#3d3238; --ink-3:#6b5c62;
}
/* A barra de topo é escolhida por cada utilizador; a cor do texto vem
   calculada do lado do servidor para nunca ficar ilegível. */
:root{--brand:<?= e($bar) ?>; --brand-ink:<?= e($bark) ?>}
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink)}
a{color:var(--accent)}
header.topbar{background:var(--brand);color:var(--brand-ink);padding:12px 20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
header.topbar h1{font-size:16px;margin:0;font-weight:600}
header.topbar nav{display:flex;gap:4px;flex-wrap:wrap;margin-left:auto}
header.topbar nav a{color:var(--brand-ink);opacity:.72;text-decoration:none;padding:6px 10px;border-radius:6px;font-size:13px}
header.topbar nav a:hover{background:rgba(127,127,127,.25);opacity:1}
header.topbar nav a.active{background:rgba(127,127,127,.3);opacity:1}
/* Menu suspenso das aplicações, na barra de topo */
.navdrop{position:relative;display:inline-flex}
header.topbar nav a.drop-t{display:inline-flex;align-items:center;gap:5px}
header.topbar nav a.drop-t svg{width:15px;height:15px;transition:transform .15s ease}
.navdrop[data-open="true"] a.drop-t{background:rgba(127,127,127,.3);opacity:1}
.navdrop[data-open="true"] a.drop-t svg{transform:rotate(180deg)}
.drop{position:absolute;top:calc(100% + 7px);left:0;min-width:260px;z-index:40;display:none;
  background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:5px;
  box-shadow:0 14px 34px -14px rgba(27,16,22,.5)}
.navdrop[data-open="true"] .drop{display:block}
/* Especificidade acima de "header.topbar nav a", que de outra forma pinta
   estas ligações com a cor da barra — texto branco sobre fundo branco. */
header.topbar nav .drop a{display:flex;align-items:center;gap:9px;padding:8px 9px;border-radius:7px;
  color:var(--ink);text-decoration:none;font-size:13.5px;opacity:1}
header.topbar nav .drop a:hover{background:var(--rail-soft);color:var(--ink);opacity:1}
header.topbar nav .drop a[aria-current="true"]{background:var(--accent-soft);font-weight:600}
.drop .mk{width:26px;height:26px;border-radius:7px;background:var(--accent-soft);color:var(--accent);
  display:grid;place-items:center;font-weight:700;font-size:11px;flex:none}
.drop .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.drop .st{width:15px;height:15px;color:#f5a800;flex:none}
.drop hr{border:0;border-top:1px solid var(--line);margin:5px 0}
header.topbar nav .drop a:last-child{font-size:12.5px;color:var(--muted)}

/* O ícone traz o seu próprio círculo: um contorno em CSS por cima dava
   dois círculos concêntricos. */
header.topbar nav a.info{display:grid;place-items:center;width:32px;height:32px;padding:0;
  border-radius:8px;opacity:.78}
header.topbar nav a.info svg{width:21px;height:21px;display:block}
header.topbar nav a.info:hover,header.topbar nav a.info.active{opacity:1;background:rgba(127,127,127,.28)}
.who{font-size:12px;color:var(--brand-ink);opacity:.62}
.wrap{max-width:1180px;margin:22px auto;padding:0 18px}
.wrap.narrow{max-width:520px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:18px;
      box-shadow:0 1px 2px rgba(27,16,22,.05)}
.card h2{margin:0 0 4px;font-size:17px}
.card h3{margin:18px 0 8px;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.muted{color:var(--muted);font-size:13px}
label{display:block;margin-bottom:12px;font-size:13px;color:var(--ink-2)}
label>span.req::after{content:" *";color:var(--danger)}
input[type=text],input[type=email],input[type=password],input[type=date],input[type=number],input[type=file],select,textarea{
  width:100%;padding:9px 10px;border:1px solid var(--field);border-radius:8px;font:inherit;background:#fff;margin-top:4px}
input:focus,select:focus,textarea:focus{outline:2px solid var(--accent-line);border-color:var(--accent)}
button,.btn{font:inherit;padding:9px 14px;border-radius:8px;border:1px solid var(--field);background:#fff;cursor:pointer;
  text-decoration:none;display:inline-block;color:var(--ink)}
button:hover,.btn:hover{background:var(--surface)}
button.primary,.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
button.primary:hover,.btn.primary:hover{background:var(--accent-hi)}
button.danger,.btn.danger{background:#fff;border-color:var(--danger-line);color:var(--danger)}
button.danger:hover,.btn.danger:hover{background:var(--danger-soft)}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px}
.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.actions.right{justify-content:flex-end}
.alert{padding:11px 13px;border-radius:8px;margin-bottom:14px;font-size:13px;border:1px solid}
.alert.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.alert.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
.alert.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
.alert.info{background:var(--accent-soft);border-color:var(--accent-line);color:var(--accent-ink)}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{background:var(--surface);font-weight:600;color:var(--ink-3);position:sticky;top:0}
tbody tr:hover{background:var(--surface)}
.tag{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;border:1px solid}
.tag.admin{background:var(--accent-soft);border-color:var(--accent-line);color:var(--accent-ink)}
.tag.gestor{background:var(--rail-soft);border-color:var(--rail-line);color:var(--rail-ink)}
.tag.supervisor{background:#f1f2f5;border-color:#cfd3da;color:#4a515c}
.tag.leitor{background:#f7f7f8;border-color:#dfe1e5;color:#6b7280}
.tag.on{background:#dcfce7;border-color:#86efac;color:#166534}
.tag.off{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.scroll{max-height:60vh;overflow:auto;border:1px solid var(--line);border-radius:8px}
code,.mono{font-family:ui-monospace,"Cascadia Code",Consolas,monospace}
.secret{font-family:ui-monospace,Consolas,monospace;font-size:19px;letter-spacing:2px;background:var(--surface);
  border:1px dashed #a99aa0;border-radius:8px;padding:14px;text-align:center;user-select:all;word-break:break-all}
/* Escolha da cor da barra de topo, em "A minha conta" */
.cores{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.cores form{margin:0;display:flex}
.cores button.cor{width:38px;height:38px;border-radius:10px;padding:0;cursor:pointer;
  border:2px solid var(--line);position:relative}
.cores button.cor:hover{border-color:var(--ink-3)}
.cores button.cor[aria-current="true"]{border-color:var(--ink);box-shadow:0 0 0 3px var(--accent-soft)}
.cores button.cor[aria-current="true"]::after{content:"";position:absolute;inset:0;margin:auto;
  width:11px;height:11px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(0,0,0,.35)}

/* Bolinha de presença na lista de utilizadores */
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;flex:none;
  box-shadow:0 0 0 2px #fff}
.dot.on{background:#16a34a}
.dot.off{background:#c8bcc1}
.presenca{display:flex;align-items:center;gap:7px;white-space:nowrap}
.presenca span.txt{font-size:12px;color:var(--muted)}

/* Lista de utilizadores: linha selecionável + ficha por baixo */
tr.rowlink{cursor:pointer}
tr.rowlink td:first-child{box-shadow:inset 3px 0 0 transparent}
tr.rowlink[aria-current="true"]{background:var(--rail-soft)}
tr.rowlink[aria-current="true"]:hover{background:#fdf0dd}
tr.rowlink[aria-current="true"] td:first-child{box-shadow:inset 3px 0 0 var(--rail)}
tr.rowlink a.rowname{color:var(--ink);text-decoration:none;font-weight:600}
tr.rowlink a.rowname:hover{color:var(--accent);text-decoration:underline}
.ficha-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 12px;margin-bottom:2px}
.ficha-head h3{margin:0;font-size:17px;text-transform:none;letter-spacing:0;color:var(--ink)}
.ficha-sub{color:var(--muted);font-size:12.5px;margin:0 0 16px}
.ficha-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(236px,1fr));gap:14px}
.dbox{border:1px solid var(--line);border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:8px}
.dbox .t{font-size:11.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:0}
.dbox p{margin:0;font-size:12.5px;color:var(--muted)}
.dbox form{margin:0}
/* O interruptor fica de fora: não é um botão de largura total. */
.dbox .btn,.dbox button:not(.sw){width:100%;text-align:center}
.dbox .spacer{margin-top:auto}
/* Interruptor: é um botão de submissão, funciona sem JavaScript */
.sw,.dbox .sw{position:relative;width:40px;min-width:40px;height:23px;border-radius:999px;background:var(--field);
  border:0;cursor:pointer;padding:0;flex:none;transition:background .18s ease}
.sw::after{content:"";position:absolute;top:3px;left:3px;width:17px;height:17px;border-radius:50%;background:#fff;
  box-shadow:0 1px 2px rgba(27,16,22,.3);transition:transform .18s ease}
.sw[aria-checked="true"]{background:var(--accent)}
.sw[aria-checked="true"]::after{transform:translateX(17px)}
.sw:hover{filter:brightness(.97)}
.swrow{display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--ink)}
.swrow form{margin:0;display:flex}

/* Dois painéis de transferência (ver transfer_list()) */
.tr{display:grid;grid-template-columns:1fr 108px 1fr;gap:12px;align-items:start}
.tr-panel{border:1px solid var(--line);border-radius:10px;background:#fff;overflow:hidden;min-width:0}
.tr-head{padding:9px 12px;border-bottom:1px solid var(--line);background:var(--surface);display:flex;
  justify-content:space-between;gap:8px;font-size:11.5px;font-weight:600;letter-spacing:.06em;
  text-transform:uppercase;color:var(--muted)}
.tr-head b{font-variant-numeric:tabular-nums;color:var(--ink)}
.tr-search{padding:9px 10px;border-bottom:1px solid var(--line)}
.tr-search input{margin:0;padding:7px 10px;font-size:13px}
.tr-list{list-style:none;margin:0;padding:6px;min-height:150px;max-height:320px;overflow:auto;
  display:flex;flex-direction:column;gap:3px}
.tr-item{display:flex;align-items:center;gap:9px;padding:8px 9px;border-radius:7px;margin:0;
  font-size:13.5px;color:var(--ink);cursor:pointer;transition:background .15s ease}
.tr-item:hover{background:var(--rail-soft)}
.tr-item input{position:absolute;opacity:0;width:1px;height:1px;margin:0}
.tr-item input:focus-visible + .tr-mark{outline:2px solid var(--accent);outline-offset:2px}
.tr-mark{width:26px;height:26px;border-radius:7px;background:var(--accent-soft);color:var(--accent);flex:none;
  display:grid;place-items:center;font-weight:700;font-size:11px}
.tr-txt{min-width:0;flex:1}
.tr-txt b{display:block;font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tr-txt small{display:block;color:var(--muted);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tr-item.locked{cursor:default;background:var(--surface)}
.tr-item.locked:hover{background:var(--surface)}
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
.codes span{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:8px;text-align:center}
.otp{font-size:26px;letter-spacing:10px;text-align:center;font-family:ui-monospace,Consolas,monospace}
/* O logotipo tem texto vermelho escuro: sobre a barra azul-escura precisa
   de uma placa clara para ter contraste suficiente. */
.brandplate{background:#fff;border-radius:8px;padding:5px 9px;display:flex;align-items:center;flex:none}
.brandplate img{height:27px;width:auto;display:block}
.authlogo{display:flex;justify-content:center;margin-bottom:6px}
.authlogo img{height:58px;width:auto;max-width:100%;display:block}
.authname{text-align:center;font-size:20px;font-weight:700;letter-spacing:-.01em;margin:0 0 2px}
.authsub{text-align:center;color:var(--muted);font-size:13px;margin:0 0 18px}
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
  <span class="brandplate">
    <img src="<?= e($base) ?>assets/logo-setronix.png" alt="Setronix" width="718" height="277">
  </span>
  <?php
    // O nome ao lado do logótipo é o da aplicação que a pessoa escolheu
    // como predefinida; sem escolha, é o nome da plataforma.
    $appBarra = user_default_app();
  ?>
  <h1><?= e($appBarra ? $appBarra['name'] : app_name()) ?></h1>
  <nav>
    <?php
    // "Aplicações" só faz sentido com mais do que uma: com uma só, não há
    // nada para escolher e o botão seria um beco sem saída.
    $minhasApps = $u ? apps_for_user((int)$u['id'], can('apps.manage')) : [];
    if (count($minhasApps) > 1):
        $naLista = $script === 'index.php' && $dir !== 'admin';
        $abertaId = ($script === 'app.php') ? (int)($_GET['id'] ?? 0) : 0;
    ?>
      <span class="navdrop">
        <a class="drop-t<?= $naLista ? ' active' : '' ?>" href="<?= e($base) ?>index.php?todas=1"
           aria-haspopup="true" aria-expanded="false">
          Aplicações
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5.5 8l4.5 4.5L14.5 8"/>
          </svg>
        </a>
        <div class="drop" role="menu">
          <?php foreach ($minhasApps as $ma): ?>
            <a role="menuitem" href="<?= e($base) ?>app.php?id=<?= (int)$ma['id'] ?>"
               <?= $abertaId === (int)$ma['id'] ? 'aria-current="true"' : '' ?>>
              <span class="mk"><?= e(mb_strtoupper(mb_substr($ma['name'], 0, 1))) ?></span>
              <span class="nm"><?= e($ma['name']) ?></span>
              <?php if ($appBarra && (int)$appBarra['id'] === (int)$ma['id']): ?>
                <svg class="st" viewBox="0 0 20 20" fill="currentColor" aria-label="Predefinida">
                  <path d="M10 1.8l2.47 5.01 5.53.8-4 3.9.94 5.5L10 14.42l-4.94 2.6.94-5.5-4-3.9
                           5.53-.8z"/>
                </svg>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
          <hr>
          <a role="menuitem" href="<?= e($base) ?>index.php?todas=1">Ver todas e escolher a predefinida</a>
        </div>
      </span>
    <?php endif;
    if (can('users.manage') || can('apps.manage')) {
        $nav($base . 'admin/index.php', 'Administração', $dir === 'admin');
    }
    $nav($base . 'perfil.php', 'A minha conta', $script === 'perfil.php');
    $nav($base . 'logout.php', 'Sair', false);
    ?>
    <a class="info<?= $script === 'ajuda.php' ? ' active' : '' ?>" href="<?= e($base) ?>ajuda.php"
       title="Ajuda e informações sobre a plataforma" aria-label="Ajuda">
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" clip-rule="evenodd"
              d="M12 2.6A9.4 9.4 0 1 0 12 21.4 9.4 9.4 0 0 0 12 2.6Zm1.05 6.02a1.05 1.05 0
                 1 1-2.1 0 1.05 1.05 0 0 1 2.1 0Zm-2.1 2.93a1.05 1.05 0 0 1 2.1 0v5.1a1.05
                 1.05 0 0 1-2.1 0v-5.1Z"/>
      </svg>
    </a>
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

/**
 * Abre e fecha o menu das aplicações.
 *
 * Sem JavaScript o botão continua a ser uma ligação normal para a lista
 * completa — não se perde o acesso a nada.
 */
function layout_dropdown_script(): void
{
    ?>
    <script>
    (function () {
      var d = document.querySelector('.navdrop');
      if (!d) { return; }
      var t = d.querySelector('.drop-t');
      t.addEventListener('click', function (ev) {
        ev.preventDefault();
        var aberto = d.dataset.open === 'true';
        d.dataset.open = aberto ? 'false' : 'true';
        t.setAttribute('aria-expanded', aberto ? 'false' : 'true');
      });
      document.addEventListener('click', function (ev) {
        if (!d.contains(ev.target)) { d.dataset.open = 'false'; t.setAttribute('aria-expanded', 'false'); }
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { d.dataset.open = 'false'; t.setAttribute('aria-expanded', 'false'); }
      });
    })();
    </script>
    <?php
}

/** Rodapé comum. */
function layout_foot(): void
{
    global $CONFIG;
    layout_dropdown_script();
    echo '<footer class="foot">' . e($CONFIG['app']['org'] ?? 'Setronix')
       . ' · ' . e(app_name()) . ' · ' . date('Y') . '</footer></body></html>';
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
