<?php
/**
 * GET api/lists.php → listas base em JSON.
 *
 * Usado pelo front-end para recarregar as caixas de seleção sem refrescar
 * a página. Requer sessão autenticada.
 */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/lists.php';

// Em API respondemos com 401 em vez de redirecionar para o ecrã de login.
if (empty($_SESSION['uid']) || empty($_SESSION['mfa_passed']) || !current_user()) {
    json_out(['ok' => false, 'error' => 'Sessão não autenticada.'], 401);
}
session_touch();

json_out([
    'ok'         => true,
    'lists'      => lists_all(true),
    'updated_at' => q_val('SELECT MAX(updated_at) FROM list_items'),
]);
