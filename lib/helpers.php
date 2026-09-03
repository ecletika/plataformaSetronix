<?php
/** Funções utilitárias transversais. */

declare(strict_types=1);

/** Escapa texto para HTML. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A ligação está em HTTPS? (considera proxies do cPanel) */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return strtolower((string)$proto) === 'https';
}

/** IP do cliente (string, já truncada ao tamanho da coluna). */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return substr((string)$ip, 0, 45);
}

/** User-agent truncado. */
function client_agent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/** Token CSRF da sessão atual. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Campo oculto com o token CSRF. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Valida o token CSRF de um POST; termina o pedido se for inválido. */
function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Sessão expirada ou pedido inválido. Volte a carregar a página.');
    }
}

/**
 * Devolve o que foi escrito num campo de palavra-passe, para a submissão
 * falhada não apagar tudo e obrigar a escrever de novo.
 *
 * Só devolve valor depois de um POST: assim uma palavra-passe nunca vai
 * no HTML de uma página aberta de fresco. Quem chama tem de marcar a
 * resposta com no_store() — a palavra-passe vai no corpo e não deve ficar
 * guardada em disco pelo browser nem por nenhum intermediário.
 */
function keep_password(string $campo): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return '';
    }
    return (string)($_POST[$campo] ?? '');
}

/** Impede que esta resposta seja guardada em cache ou em disco. */
function no_store(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/** Redireciona e termina. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Mensagem flash para o próximo pedido. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Consome e devolve as mensagens flash pendentes. */
function flash_take(): array
{
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

/** Cifra um texto com a chave da aplicação (AES-256-GCM). */
function app_encrypt(string $plain): string
{
    global $CONFIG;
    $key = hex2bin_key($CONFIG['app_key'] ?? '');
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('Falha ao cifrar o segredo.');
    }
    return $iv . $tag . $ct;
}

/** Decifra um valor produzido por app_encrypt(). Devolve null se falhar. */
function app_decrypt(?string $blob): ?string
{
    global $CONFIG;
    if ($blob === null || strlen($blob) < 29) {
        return null;
    }
    $key = hex2bin_key($CONFIG['app_key'] ?? '');
    $iv  = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $ct  = substr($blob, 28);
    $out = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $out === false ? null : $out;
}

/** Converte a app_key hexadecimal em 32 bytes; falha se estiver por definir. */
function hex2bin_key(string $hex): string
{
    $hex = trim($hex);
    if ($hex === '') {
        throw new RuntimeException('app_key não definida no config.php.');
    }
    $bin = @hex2bin($hex);
    if ($bin === false || strlen($bin) < 32) {
        // Aceita também chaves em texto livre, derivando-as.
        $bin = hash('sha256', $hex, true);
    }
    return substr($bin, 0, 32);
}

/**
 * Garante que um texto é UTF-8 válido antes de chegar à base de dados.
 *
 * Nomes de ficheiros enviados por upload chegam na codificação do sistema do
 * utilizador (frequentemente Windows-1252 em Portugal), e o MySQL rejeita
 * bytes inválidos numa coluna utf8mb4 com o erro 1366.
 */
function to_utf8(?string $text): string
{
    $text = (string)$text;
    if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }
    $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252, ISO-8859-1');
    if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
        return $converted;
    }
    // Último recurso: descarta os bytes que não formam UTF-8 válido.
    return (string)preg_replace('/[^\x00-\x7F]/', '?', $text);
}

/** Normaliza uma data para a segunda-feira dessa semana (Y-m-d). */
function monday_of(string $date): string
{
    $d = new DateTimeImmutable($date);
    $dow = (int)$d->format('N'); // 1 = segunda
    return $d->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
}

/** Formata bytes de forma legível. */
function human_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return ($i === 0 ? (string)(int)$v : number_format($v, 1, ',', ' ')) . ' ' . $units[$i];
}

/** Resposta JSON e fim do pedido. */
function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
