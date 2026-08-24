<?php
/** Ligação PDO à base de dados MySQL. */

declare(strict_types=1);

/** Devolve a ligação PDO partilhada (lazy). */
function db(): PDO
{
    global $CONFIG;
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = $CONFIG['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        (int)($c['port'] ?? 3306),
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $ex) {
        http_response_code(500);
        if (!empty($CONFIG['app']['debug'])) {
            exit('Erro de ligação à base de dados: ' . $ex->getMessage());
        }
        exit('Não foi possível ligar à base de dados. Contacte o administrador.');
    }

    $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

/** Executa uma query preparada e devolve o statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Primeira linha do resultado, ou null. */
function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Todas as linhas do resultado. */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Primeiro valor da primeira linha. */
function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Lê uma definição da tabela settings. */
function setting(string $key, ?string $default = null): ?string
{
    $v = q_val('SELECT svalue FROM settings WHERE skey = ?', [$key]);
    return $v === null ? $default : (string)$v;
}

/** Grava uma definição. */
function setting_set(string $key, string $value): void
{
    q('INSERT INTO settings (skey, svalue) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
}
