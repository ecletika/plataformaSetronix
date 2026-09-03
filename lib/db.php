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
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st;
    } catch (PDOException $ex) {
        // Tabela em falta: quase sempre um schema.sql por correr depois de
        // uma atualização. Sem esta mensagem o servidor devolve um 500 em
        // branco e não há forma de perceber o que falta.
        if ($ex->getCode() === '42S02') {
            db_fail_missing_table($ex);
        }
        throw $ex;
    }
}

/** Página de erro explícita para quando falta uma tabela na base de dados. */
function db_fail_missing_table(PDOException $ex): void
{
    $table = '';
    if (preg_match("/Table '([^']+)' doesn't exist/", $ex->getMessage(), $m)) {
        $table = (string)preg_replace('/^.*\./', '', $m[1]);
    }

    http_response_code(500);
    if (PHP_SAPI === 'cli') {
        exit('Tabela em falta: ' . $table . "\n");
    }

    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars($table, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Base de dados incompleta</title>'
       . '<style>body{font:15px/1.6 system-ui,Segoe UI,sans-serif;margin:40px auto;max-width:640px;'
       . 'color:#1b1016;padding:0 18px}h1{font-size:20px}code{background:#f1f5f9;padding:2px 6px;'
       . 'border-radius:4px}li{margin-bottom:8px}</style>'
       . '<h1>A base de dados não corresponde à versão do código</h1>'
       . '<p>Falta a tabela <code>' . $t . '</code>.</p>'
       . '<p>Falta correr o esquema da base de dados. Em cPanel &rarr; <i>phpMyAdmin</i>, '
       . 'escolha a base de dados, separador <i>Importar</i>, e execute o ficheiro '
       . '<code>install/schema.sql</code>.</p>'
       . '<p>O ficheiro só cria o que ainda não existe — pode ser executado sem risco '
       . 'numa base de dados já em uso.</p>';
    exit;
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

/**
 * Nome da plataforma, tal como aparece no cabeçalho e nos títulos.
 *
 * Vem da tabela settings, para poder ser alterado na administração sem
 * mexer no config.php do servidor. Sem nada gravado, cai no config.
 */
function app_name(): string
{
    global $CONFIG;
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }
    $nome = '';
    try {
        $nome = trim((string)setting('app_name', ''));
    } catch (Throwable $ex) {
        // Base de dados indisponível: o nome não vale um erro fatal.
        $nome = '';
    }
    if ($nome === '') {
        $nome = trim((string)($CONFIG['app']['name'] ?? ''));
    }
    return $cache = ($nome !== '' ? $nome : 'Planeamento Setronix');
}

/**
 * Preferência pessoal de um utilizador.
 *
 * Vive à parte das definições globais: cada pessoa escolhe a sua e não
 * afeta mais ninguém.
 */
function user_pref(string $key, ?string $default = null): ?string
{
    $u = current_user();
    if (!$u) {
        return $default;
    }
    try {
        $v = q_val('SELECT pvalue FROM user_prefs WHERE user_id = ? AND pkey = ?',
                   [(int)$u['id'], $key]);
    } catch (Throwable $ex) {
        return $default;
    }
    return $v === null ? $default : (string)$v;
}

/** Grava uma preferência pessoal. */
function user_pref_set(int $userId, string $key, string $value): void
{
    q('INSERT INTO user_prefs (user_id, pkey, pvalue) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE pvalue = VALUES(pvalue)', [$userId, $key, $value]);
}

/**
 * Cor da barra de topo. Cada utilizador escolhe a sua em "A minha conta".
 */
function topbar_color(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $c = (string)user_pref('topbar_color', '');
    if (!preg_match('/^#[0-9a-f]{6}$/i', $c)) {
        $c = TOPBAR_DEFAULT;
    }
    return $cache = strtolower($c);
}

/**
 * Preto ou branco por cima de uma cor, conforme o que se lê melhor.
 * Sem isto, uma cor clara escolhida por engano deixava a barra ilegível.
 */
function ink_on(string $hex): string
{
    $rgb = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $lin = array_map(static function (int $v): float {
        $x = $v / 255;
        return $x <= 0.03928 ? $x / 12.92 : pow(($x + 0.055) / 1.055, 2.4);
    }, $rgb);
    $lum = 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    // Contraste contra branco vs contra preto: fica o maior.
    return (1.05 / ($lum + 0.05)) >= (($lum + 0.05) / 0.05) ? '#ffffff' : '#1b1016';
}

/** Grava uma definição. */
function setting_set(string $key, string $value): void
{
    q('INSERT INTO settings (skey, svalue) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
}
