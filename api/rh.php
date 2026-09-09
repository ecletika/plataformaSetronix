<?php
/**
 * Quem não pode ser escolhido numa dada semana.
 *
 * A aplicação manda a semana que está a planear e os nomes que tem nas
 * suas listas; recebe de volta os que devem desaparecer, com o motivo.
 *
 * A regra está do lado do servidor, onde estão os dados: fica de fora
 * quem não tiver um único dia de trabalho livre nessa semana. Um dia
 * livre chega para a pessoa continuar a poder ser escolhida.
 *
 * É por semana e não por dia porque é a semana que se planeia. Alguém de
 * férias hoje mas de volta na quinta continua a ser uma escolha legítima
 * para essa semana.
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/apps.php';
require_once __DIR__ . '/../lib/rh.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Responde e termina. */
function responder(array $corpo, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) {
    responder(['erro' => 'Sessão terminada. Volte a entrar na plataforma.'], 401);
}

$app = app_find((int)($_GET['app'] ?? 0));
if (!$app || !user_can_open_app((int)$user['id'], $app, can('apps.manage'))) {
    responder(['erro' => 'Aplicação não encontrada ou sem acesso.'], 404);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(['erro' => 'Método não permitido.'], 405);
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    responder(['erro' => 'Os dados enviados não são JSON válido.'], 400);
}

$semana = (string)($payload['semana'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $semana)) {
    responder(['erro' => 'Semana inválida.'], 400);
}
$nomes = isset($payload['nomes']) && is_array($payload['nomes'])
       ? array_slice($payload['nomes'], 0, 500)
       : [];

// Sem mapa importado não há nada a esconder — e dizê-lo é melhor do que
// devolver uma lista vazia, que a aplicação leria como "está tudo bem".
if (!rh_mapa_atual((int)$app['id'])) {
    responder(['ok' => true, 'mapa' => false, 'fora' => (object)[]]);
}

responder([
    'ok'    => true,
    'mapa'  => true,
    'dias'  => rh_dias_de_trabalho((int)$app['id'], $semana),
    'fora'  => (object)rh_fora_na_semana((int)$app['id'], $semana, $nomes),
]);
