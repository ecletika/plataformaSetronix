<?php
/**
 * Arranque da aplicação: configuração, ligação à BD, sessão e helpers.
 * Todos os pontos de entrada devem começar com:
 *     require_once __DIR__ . '/lib/bootstrap.php';
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('É necessário PHP 7.4 ou superior. Versão atual: ' . PHP_VERSION);
}

define('APP_ROOT', dirname(__DIR__));

// ---------------------------------------------------------------------
// Configuração
// ---------------------------------------------------------------------
$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    // Ainda não instalado: encaminha para o instalador (exceto se já lá estamos).
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/install/') === false) {
        header('Location: install/index.php');
        exit;
    }
    $CONFIG = require APP_ROOT . '/config.sample.php';
    $CONFIG['__not_installed'] = true;
} else {
    $CONFIG = require $configFile;
}

date_default_timezone_set($CONFIG['app']['timezone'] ?? 'Europe/Lisbon');
mb_internal_encoding('UTF-8');

if (!empty($CONFIG['app']['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/auth.php';

// ---------------------------------------------------------------------
// HTTPS obrigatório
// ---------------------------------------------------------------------
if (!empty($CONFIG['app']['force_https']) && !is_https() && PHP_SAPI !== 'cli') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '' && $host !== 'localhost' && strpos($host, '127.0.0.1') !== 0) {
        header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
}

// ---------------------------------------------------------------------
// Cabeçalhos de segurança
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// ---------------------------------------------------------------------
// Sessão
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('SETRONIX_SID');
    $params = [
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($params);
    } else {
        session_set_cookie_params(0, '/', '', $params['secure'], true);
    }
    session_start();
}
