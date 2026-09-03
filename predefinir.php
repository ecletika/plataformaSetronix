<?php
/**
 * Marca (ou desmarca) a aplicação predefinida do utilizador.
 *
 * Existe à parte porque a estrela vive no menu da barra de topo, que
 * aparece em todas as páginas: o pedido tem de ser tratado num sítio só e
 * devolver a pessoa à página onde estava.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/apps.php';

$user = require_login('view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?todas=1');
}
csrf_check();

/**
 * Destino de regresso, aceite apenas se for um caminho desta plataforma.
 * Sem esta verificação, o campo servia para atirar quem carrega na estrela
 * para um site qualquer.
 */
$voltar = (string)($_POST['voltar'] ?? '');
if ($voltar === ''
    || $voltar[0] === '/'
    || strpos($voltar, ':') !== false
    || strpos($voltar, '//') !== false
    || !preg_match('#^(\.\./)?[a-z0-9_/-]+\.php(\?[a-z0-9_=&%.-]*)?$#i', $voltar)) {
    $voltar = 'index.php?todas=1';
}

$id  = (int)($_POST['app_id'] ?? 0);
$app = $id ? app_find($id) : null;

if (!$app || !user_can_open_app((int)$user['id'], $app, can('apps.manage'))) {
    flash('warn', 'Essa aplicação não está disponível para si.');
    redirect($voltar);
}

if ((int)user_pref('default_app', '0') === $id) {
    app_set_default((int)$user['id'], 0, 'utilizador');
    flash('ok', '"' . $app['name'] . '" deixou de abrir automaticamente.');
} else {
    app_set_default((int)$user['id'], $id, 'utilizador');
    flash('ok', '"' . $app['name'] . '" passa a abrir automaticamente ao entrar.');
}

redirect($voltar);
