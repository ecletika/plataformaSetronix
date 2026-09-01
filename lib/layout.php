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
