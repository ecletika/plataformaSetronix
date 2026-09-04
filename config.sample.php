<?php
/**
 * Plataforma Setronix — configuração.
 *
 * Copiar este ficheiro para "config.php" e preencher com os dados reais do
 * cPanel. O ficheiro config.php NÃO deve ser versionado nem partilhado.
 */

return [
    // ---------------------------------------------------------------
    // Base de dados (cPanel → MySQL Databases / phpMyAdmin)
    // ---------------------------------------------------------------
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'utilizador_setronix',   // ex.: cpaneluser_setronix
        'user'    => 'utilizador_setronix',
        'pass'    => 'ALTERAR-ESTA-PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // ---------------------------------------------------------------
    // Chave de aplicação — cifra os segredos MFA na base de dados.
    // Gerar uma vez com:  php -r "echo bin2hex(random_bytes(32));"
    // Se esta chave for perdida, todos os utilizadores têm de voltar a
    // inscrever o MFA.
    // ---------------------------------------------------------------
    'app_key' => '',

    // ---------------------------------------------------------------
    // Aplicação
    // ---------------------------------------------------------------
    'app' => [
        'org'       => 'Setronix',
        'timezone'  => 'Europe/Lisbon',
        'base_url'  => '',            // vazio = detecção automática
        'force_https' => true,         // redireciona http → https
        'debug'     => false,          // true apenas em ambiente de testes
    ],

    // ---------------------------------------------------------------
    // Segurança
    // ---------------------------------------------------------------
    'security' => [
        'session_idle_minutes'  => 120,  // termina sessão por inatividade
        'session_absolute_hours' => 12,  // duração máxima da sessão
        'max_failed_logins'     => 5,    // bloqueio da conta após N falhas
        'lockout_minutes'       => 15,
        'ip_attempt_limit'      => 25,   // tentativas por IP em 15 minutos
        'password_min_length'   => 6,
        'totp_window'           => 1,    // ±1 período de 30s de tolerância
        'recovery_code_count'   => 10,
    ],

    // ---------------------------------------------------------------
    // Aplicações HTML
    // ---------------------------------------------------------------
    'apps' => [
        'max_mb' => 8,   // tamanho máximo de cada ficheiro .html enviado
    ],

    // ---------------------------------------------------------------
    // Caminhos (relativos à raiz da aplicação)
    // ---------------------------------------------------------------
    'paths' => [
        'storage' => __DIR__ . '/storage',
        'apps'    => __DIR__ . '/storage/apps',
    ],
];
