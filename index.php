<?php
/**
 * Aplicação de planeamento.
 *
 * Serve o protótipo HTML (legacy/) já autenticado, substituindo as listas
 * fixas do ficheiro pelas listas geridas na base de dados.
 *
 * Fase 1 (atual): obras e planeamentos continuam a ser guardados no browser
 * (localStorage), tal como no protótipo — mas as listas base já vêm do servidor
 * e o acesso é controlado por conta/MFA.
 * Fase 2: os endpoints em api/ passam a guardar obras e planeamentos na BD.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/lists.php';

$user = require_login('view');

$appFile = __DIR__ . '/legacy/planeamento_obras_teste_web_v3_18_logotipo_setronix_oficial.html';
if (!is_file($appFile)) {
    http_response_code(500);
    exit('Ficheiro da aplicação não encontrado em legacy/.');
}

$html = (string)file_get_contents($appFile);

// -------------------------------------------------------------------
// 1. Substituir as listas fixas pelas listas da base de dados
// -------------------------------------------------------------------
$lists = lists_all(true);
// Se a base de dados ainda estiver vazia, mantém as listas do protótipo.
$hasData = false;
foreach ($lists as $values) {
    if ($values) {
        $hasData = true;
        break;
    }
}

if ($hasData) {
    $json = json_encode($lists, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // A declaração ocupa exatamente uma linha no ficheiro original:
    //   const LISTS={...};
    // Usa-se um callback para que o JSON não seja interpretado como
    // referências de substituição (\0, \1, $1...).
    $replaced = preg_replace_callback(
        '/^const LISTS=.*$/m',
        static fn(): string => 'const LISTS=' . $json . ';',
        $html,
        1,
        $count
    );
    if ($replaced !== null && $count === 1) {
        $html = $replaced;
    }
}

// -------------------------------------------------------------------
// 2. Barra de sessão e contexto do utilizador
// -------------------------------------------------------------------
$canEdit = can('plan.edit') ? 'true' : 'false';
$adminLink = (can('users.manage') || can('lists.edit'))
    ? '<a href="admin/index.php">Administração</a>'
    : '';

$bar = '<div id="sxSessionBar">'
     . '<span class="sx-user">' . e($user['full_name']) . ' · ' . e(ROLES[$user['role']] ?? $user['role']) . '</span>'
     . '<span class="sx-links">'
     . $adminLink
     . '<a href="perfil.php">A minha conta</a>'
     . '<a href="logout.php">Sair</a>'
     . '</span></div>'
     . '<style>'
     . '#sxSessionBar{background:#0b1220;color:#94a3b8;font:13px system-ui,-apple-system,"Segoe UI",sans-serif;'
     . 'padding:7px 18px;display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}'
     . '#sxSessionBar a{color:#e2e8f0;text-decoration:none;margin-left:14px}'
     . '#sxSessionBar a:hover{text-decoration:underline}'
     . '</style>'
     . '<script>window.SETRONIX = '
     . json_encode([
         'user'    => ['id' => (int)$user['id'], 'name' => $user['full_name'], 'role' => $user['role']],
         'canEdit' => can('plan.edit'),
         'apiBase' => 'api/',
       ], JSON_UNESCAPED_UNICODE)
     . ';</script>';

$html = preg_replace_callback('/<body>/', static fn(): string => '<body>' . $bar, $html, 1);

// Impede indexação e caching do conteúdo autenticado.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

echo $html;
