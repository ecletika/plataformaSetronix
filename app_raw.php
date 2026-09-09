<?php
/**
 * Serve o ficheiro HTML de uma aplicação, tal como foi enviado.
 *
 * Nunca é acedido directamente pelo browser do utilizador: é o destino do
 * <iframe> de app.php (ou de um separador aberto a partir dele). O ficheiro
 * vive em storage/apps/, pasta bloqueada pelo .htaccess, por isso esta é a
 * única forma de o obter — e exige sessão iniciada.
 *
 * ?id=N              versão ativa
 * ?id=N&v=ID         uma versão concreta (pré-visualização, só para gestores)
 * ?id=N&transferir=1 descarrega o ficheiro tal como foi enviado
 *
 * Se a página declarar campos (o bloco JSON "setronix-dados"), os dados
 * dessa aplicação são injectados no topo do HTML antes de ser entregue.
 * É de propósito que vão dentro da página e não por um pedido à parte: a
 * aplicação lê-os na primeira linha do seu próprio JavaScript, sem ter de
 * esperar por nada nem de ser reescrita para funcionar de forma assíncrona.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/apps.php';
require_once __DIR__ . '/lib/dados.php';
require_once __DIR__ . '/lib/rh.php';

$user = require_login('view');

$app = app_find((int)($_GET['id'] ?? 0));
if (!$app || !user_can_open_app((int)$user['id'], $app, can('apps.manage'))) {
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

$html = (string)file_get_contents($path);

// A transferir é o ficheiro que interessa, não a página a correr: vai
// tal como foi enviado, sem os dados injectados. É o que se leva para o
// ChatGPT para pedir a versão seguinte — com os dados lá dentro, iria de
// caminho o trabalho da empresa para fora.
if (isset($_GET['transferir'])) {
    if (!can('apps.manage')) {
        http_response_code(403);
        exit('Sem permissão para descarregar o ficheiro.');
    }
    $nome = (string)($version['filename'] ?? 'aplicacao.html');
    // Só o nome do ficheiro, sem caminho, sem aspas nem quebras de linha:
    // é isto que vai dentro de um cabeçalho HTTP.
    $nome = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($nome));
    if ($nome === '' || !preg_match('/\.html?$/i', $nome)) {
        $nome = 'aplicacao.html';
    }
    $nome = 'v' . (int)$version['version'] . '-' . $nome;

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Length: ' . (string)strlen($html));
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    header('Cache-Control: private, no-store, must-revalidate');
    echo $html;
    exit;
}

// Aplicações que declaram campos recebem os dados já dentro da página.
// As outras seguem tal e qual foram enviadas — continuam a guardar no
// browser, como sempre fizeram.
$manifesto = dados_manifesto($html);
if ($manifesto !== null) {
    $boot = [
        'app'   => (int)$app['id'],
        'csrf'  => csrf_token(),
        'dados' => dados_ler((int)$app['id']),
    ];
    // json_encode escapa os acentos para \uXXXX: fica ASCII puro e entra
    // em qualquer página, seja qual for a codificação que ela declare.
    $script = '<script>window.SETRONIX_BOOT=' . json_encode($boot) . ';</script>';

    // A seguir ao <head>, para estar pronto antes de qualquer script da
    // aplicação. Sem <head> à vista, vai para o princípio do ficheiro.
    if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos  = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $pos) . $script . substr($html, $pos);
    } else {
        $html = $script . $html;
    }
}

// A página é privada e pode mudar a qualquer momento: nunca a guardar em cache.
header('Content-Type: text/html; charset=utf-8');
header('Content-Length: ' . (string)strlen($html));
header('Cache-Control: private, no-store, must-revalidate');
header('Pragma: no-cache');
// Só pode ser embebida pela própria plataforma (app.php).
header("Content-Security-Policy: frame-ancestors 'self'");

echo $html;
