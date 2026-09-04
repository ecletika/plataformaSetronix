<?php
/**
 * Leitura e gravação dos dados de uma aplicação alojada.
 *
 * Só é chamado pelo JavaScript da própria aplicação, de dentro do iframe.
 * Exige sessão iniciada e que a pessoa tenha mesmo acesso à aplicação —
 * as duas coisas são verificadas aqui, não do lado do browser.
 *
 *   GET  ?app=N   devolve tudo o que a aplicação precisa para arrancar
 *   POST ?app=N   grava o que a aplicação enviar (corpo JSON)
 *
 * Respostas sempre em JSON, para o erro chegar ao ecrã da aplicação em
 * vez de morrer calado na consola.
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/apps.php';
require_once __DIR__ . '/../lib/dados.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Responde e termina. */
function responder(array $corpo, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

// require_login() redirecciona; aqui um redireccionamento seria lido como
// dados e a aplicação não perceberia o que se passou.
$user = current_user();
if (!$user) {
    responder(['erro' => 'Sessão terminada. Volte a entrar na plataforma.'], 401);
}

$app = app_find((int)($_GET['app'] ?? 0));
if (!$app || !user_can_open_app((int)$user['id'], $app, can('apps.manage'))) {
    responder(['erro' => 'Aplicação não encontrada ou sem acesso.'], 404);
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    responder(['ok' => true] + dados_ler((int)$app['id']));
}

if ($metodo !== 'POST') {
    responder(['erro' => 'Método não permitido.'], 405);
}

// O corpo vem em JSON, por isso $_POST está vazio: o token viaja no
// cabeçalho. csrf_check() aceita os dois.
csrf_check();

$bruto = (string)file_get_contents('php://input');
if (strlen($bruto) > 8 * 1024 * 1024) {
    responder(['erro' => 'Os dados enviados são grandes demais (limite 8 MB).'], 413);
}

$payload = json_decode($bruto, true);
if (!is_array($payload)) {
    responder(['erro' => 'Os dados enviados não são JSON válido.'], 400);
}

try {
    $r = dados_gravar((int)$app['id'], $payload, (int)$user['id']);
} catch (Throwable $ex) {
    error_log('api/dados.php: ' . $ex->getMessage());
    responder(['erro' => 'Não foi possível gravar. Nada foi alterado.'], 500);
}

responder(['ok' => true] + $r);
