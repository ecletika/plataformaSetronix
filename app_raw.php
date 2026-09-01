<?php
/**
 * Serve o ficheiro HTML de uma aplicação, tal como foi enviado.
 *
 * Nunca é acedido directamente pelo browser do utilizador: é o destino do
 * <iframe> de app.php (ou de um separador aberto a partir dele). O ficheiro
 * vive em storage/apps/, pasta bloqueada pelo .htaccess, por isso esta é a
 * única forma de o obter — e exige sessão iniciada.
 *
 * ?id=N              versão activa
 * ?id=N&v=ID         uma versão concreta (pré-visualização, só para gestores)
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/apps.php';

$user = require_login('view');

$app = app_find((int)($_GET['id'] ?? 0));
if (!$app || (int)$app['is_active'] !== 1) {
    http_response_code(404);
    exit('Aplicação não encontrada.');
}

if (isset($_GET['v'])) {
    if (!can('apps.manage')) {
        http_response_code(403);
        exit('Sem permissão para ver versões anteriores.');
    }
    $version = app_version_find((int)$app['id'], (int)$_GET['v']);
} else {
    $version = app_current_version($app);
}

if (!$version) {
    http_response_code(404);
    exit('Esta aplicação ainda não tem ficheiro.');
}

$path = app_version_path($version['storage_name']);
if (!is_file($path)) {
    http_response_code(500);
    exit('O ficheiro da aplicação não foi encontrado em storage/apps/.');
}

// A página é privada e pode mudar a qualquer momento: nunca a guardar em cache.
header('Content-Type: text/html; charset=utf-8');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, no-store, must-revalidate');
header('Pragma: no-cache');
// Só pode ser embebida pela própria plataforma (app.php).
header("Content-Security-Policy: frame-ancestors 'self'");

readfile($path);
