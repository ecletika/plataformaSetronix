<?php
/**
 * Backup e restauro da base de dados.
 *
 * Estratégia: tenta mysqldump (rápido); se shell_exec estiver desativado
 * — cenário comum em cPanel partilhado — faz o dump em PHP puro.
 */

declare(strict_types=1);

/** Tabelas geridas pela aplicação (ordem segura para restauro). */
const BACKUP_TABLES = [
    'settings', 'list_types', 'list_items', 'import_runs',
    'users', 'mfa_recovery_codes', 'user_sessions', 'login_attempts',
    'works', 'plans', 'plan_days',
    'audit_log', 'backups',
];

/** Diretório de backups, criado se necessário. */
function backup_dir(): string
{
    global $CONFIG;
    $dir = $CONFIG['paths']['backups'] ?? (APP_ROOT . '/storage/backups');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

/**
 * Cria um backup completo.
 *
 * @return array{filename:string, path:string, size:int, sha256:string, method:string}
 */
function backup_create(string $kind = 'manual', ?int $userId = null): array
{
    $dir  = backup_dir();
    $name = 'setronix_' . date('Ymd_His') . '_' . $kind . '.sql';
    $path = $dir . '/' . $name;

    $method = backup_try_mysqldump($path) ? 'mysqldump' : 'php';
    if ($method === 'php') {
        backup_dump_php($path);
    }

    // Comprime se a extensão zlib estiver disponível.
    if (function_exists('gzencode')) {
        $raw = file_get_contents($path);
        if ($raw !== false) {
            $gz = gzencode($raw, 6);
            if ($gz !== false && file_put_contents($path . '.gz', $gz) !== false) {
                unlink($path);
                $path .= '.gz';
                $name .= '.gz';
            }
        }
    }

    $size = (int)filesize($path);
    $hash = (string)hash_file('sha256', $path);

    q('INSERT INTO backups (filename, size_bytes, kind, sha256, user_id) VALUES (?,?,?,?,?)',
      [$name, $size, $kind, $hash, $userId]);

    audit('backup', 'system', null, 'Backup criado: ' . $name . ' (' . human_bytes($size) . ', ' . $method . ')');
    backup_prune();

    return ['filename' => $name, 'path' => $path, 'size' => $size, 'sha256' => $hash, 'method' => $method];
}

/** Tenta mysqldump; devolve true se produziu um ficheiro válido. */
function backup_try_mysqldump(string $path): bool
{
    global $CONFIG;

    if (!function_exists('shell_exec')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true) || in_array('proc_open', $disabled, true)) {
        return false;
    }
    // Confirma que o binário existe antes de o invocar, para não poluir a
    // saída com erros do interpretador de comandos.
    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        return false;   // em Windows (ambiente de testes) usa-se sempre o dump em PHP
    }
    $which = @shell_exec('command -v mysqldump 2>/dev/null');
    if (!is_string($which) || trim($which) === '') {
        return false;
    }

    $c = $CONFIG['db'];
    // A password vai por ficheiro temporário, nunca na linha de comandos.
    $cnf = tempnam(sys_get_temp_dir(), 'sx');
    if ($cnf === false) {
        return false;
    }
    file_put_contents($cnf, "[client]\nuser=" . $c['user'] . "\npassword=\"" . $c['pass'] . "\"\nhost=" . $c['host'] . "\n");
    chmod($cnf, 0600);

    $cmd = sprintf(
        'mysqldump --defaults-extra-file=%s --single-transaction --quick --default-character-set=utf8mb4 %s 2>/dev/null',
        escapeshellarg($cnf),
        escapeshellarg($c['name'])
    );
    $out = @shell_exec($cmd);
    @unlink($cnf);

    if (!is_string($out) || strpos($out, 'CREATE TABLE') === false) {
        return false;
    }
    return file_put_contents($path, $out) !== false;
}

/** Dump em PHP puro (funciona sempre, mesmo sem shell). */
function backup_dump_php(string $path): void
{
    global $CONFIG;

    $fh = fopen($path, 'w');
    if (!$fh) {
        throw new RuntimeException('Não foi possível escrever no diretório de backups.');
    }

    fwrite($fh, "-- Backup Plataforma Setronix\n");
    fwrite($fh, '-- Base de dados: ' . $CONFIG['db']['name'] . "\n");
    fwrite($fh, '-- Data: ' . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach (BACKUP_TABLES as $table) {
        $exists = q_val('SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema = ? AND table_name = ?',
                        [$CONFIG['db']['name'], $table]);
        if (!$exists) {
            continue;
        }

        $create = q_one('SHOW CREATE TABLE `' . $table . '`');
        fwrite($fh, "\n-- ---------- $table ----------\n");
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fh, ($create['Create Table'] ?? '') . ";\n");

        $st = db()->query('SELECT * FROM `' . $table . '`');
        $buffer = [];
        $cols = null;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) {
                $cols = '`' . implode('`,`', array_keys($row)) . '`';
            }
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = (string)$v;
                } else {
                    $vals[] = backup_quote((string)$v);
                }
            }
            $buffer[] = '(' . implode(',', $vals) . ')';
            if (count($buffer) >= 200) {
                fwrite($fh, "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $buffer) . ";\n");
                $buffer = [];
            }
        }
        if ($buffer && $cols !== null) {
            fwrite($fh, "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $buffer) . ";\n");
        }
    }

    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
}

/** Escapa um valor para literal SQL (incluindo binários como o segredo MFA). */
function backup_quote(string $v): string
{
    if (!mb_check_encoding($v, 'UTF-8')) {
        return '0x' . bin2hex($v);   // colunas VARBINARY
    }
    return db()->quote($v);
}

/** Apaga backups mais antigos do que a política de retenção. */
function backup_prune(): void
{
    $days = (int)(setting('backup_retention_days', '90') ?? 90);
    if ($days <= 0) {
        return;
    }
    $old = q_all('SELECT id, filename FROM backups WHERE created_at < (NOW() - INTERVAL ? DAY)', [$days]);
    foreach ($old as $b) {
        $p = backup_dir() . '/' . basename($b['filename']);
        if (is_file($p)) {
            @unlink($p);
        }
        q('DELETE FROM backups WHERE id = ?', [$b['id']]);
    }
}

/**
 * Restaura a base de dados a partir de um ficheiro .sql ou .sql.gz.
 * Cria automaticamente um backup de segurança antes de tocar nos dados.
 */
function backup_restore(string $path, ?int $userId = null): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Ficheiro de backup não encontrado.');
    }

    $safety = backup_create('pre-restore', $userId);

    $sql = substr($path, -3) === '.gz'
        ? (string)gzdecode((string)file_get_contents($path))
        : (string)file_get_contents($path);

    if (stripos($sql, 'CREATE TABLE') === false) {
        throw new RuntimeException('O ficheiro não parece ser um backup SQL válido.');
    }

    $statements = backup_split_sql($sql);
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $count = 0;
    try {
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0) {
                continue;
            }
            $pdo->exec($stmt);
            $count++;
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    audit('restore', 'system', null,
          'Restauro a partir de ' . basename($path) . ' (' . $count . ' instruções). Backup de segurança: ' . $safety['filename']);

    return ['statements' => $count, 'safety_backup' => $safety['filename']];
}

/** Divide um dump em instruções, respeitando aspas e escapes. */
function backup_split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $inString = false;
    $quote = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inString) {
            if ($ch === '\\') {
                $buf .= $ch . ($sql[$i + 1] ?? '');
                $i++;
                continue;
            }
            if ($ch === $quote) {
                $inString = false;
            }
            $buf .= $ch;
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inString = true;
            $quote = $ch;
            $buf .= $ch;
            continue;
        }
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && ($buf === '' || substr($buf, -1) === "\n")) {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($ch === ';') {
            $out[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $out[] = $buf;
    }
    return $out;
}
