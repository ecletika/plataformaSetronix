<?php
/**
 * Backup automático — para agendar no cPanel → Cron Jobs.
 *
 *   0 3 * * *  /usr/local/bin/php /home/UTILIZADOR/public_html/install/cron_backup.php
 *
 * Mova este ficheiro para fora de install/ antes de apagar essa pasta
 * (por exemplo para uma pasta bin/ fora do public_html).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só corre a partir da linha de comandos.');
}

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/backup.php';

try {
    $r = backup_create('auto', null);
    fwrite(STDOUT, sprintf(
        "[%s] Backup criado: %s (%s, método %s)\n",
        date('Y-m-d H:i:s'),
        $r['filename'],
        human_bytes($r['size']),
        $r['method']
    ));
    exit(0);
} catch (Throwable $ex) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERRO no backup: ' . $ex->getMessage() . "\n");
    exit(1);
}
