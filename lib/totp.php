<?php
/**
 * TOTP (RFC 6238) e Base32 (RFC 4648) em PHP puro.
 * Compatível com Google Authenticator, Microsoft Authenticator, Authy, 1Password.
 * Sem dependências externas — funciona em qualquer cPanel.
 */

declare(strict_types=1);

const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** Gera um segredo aleatório em Base32 (160 bits = 32 caracteres). */
function totp_generate_secret(int $bytes = 20): string
{
    return base32_encode(random_bytes($bytes));
}

/** Codifica bytes em Base32 sem padding. */
function base32_encode(string $data): string
{
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $buffer = ($buffer << 8) | ord($data[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= TOTP_ALPHABET[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= TOTP_ALPHABET[($buffer << (5 - $bits)) & 31];
    }
    return $out;
}

/** Descodifica Base32 (ignora espaços, minúsculas e '='). */
function base32_decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
    $out = '';
    $buffer = 0;
    $bits = 0;
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos(TOTP_ALPHABET, $b32[$i]);
        if ($pos === false) {
            continue;
        }
        $buffer = ($buffer << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $out;
}

/**
 * Calcula o código TOTP para um instante.
 *
 * @param string   $secretB32 Segredo em Base32.
 * @param int|null $timestamp Instante UNIX (por omissão, agora).
 */
function totp_code(string $secretB32, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $key = base32_decode($secretB32);
    if ($key === '') {
        return '';
    }
    $counter = intdiv($timestamp ?? time(), $period);

    // Contador em 8 bytes big-endian (compatível com PHP 32 e 64 bits).
    $binCounter = '';
    for ($i = 7; $i >= 0; $i--) {
        $binCounter = chr($counter & 0xFF) . $binCounter;
        $counter >>= 8;
    }

    $hash   = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $part   = substr($hash, $offset, 4);
    $value  = ((ord($part[0]) & 0x7F) << 24)
            | (ord($part[1]) << 16)
            | (ord($part[2]) << 8)
            | ord($part[3]);

    return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Verifica um código introduzido pelo utilizador, com tolerância de $window
 * períodos para trás e para a frente (relógios dessincronizados).
 */
function totp_verify(string $secretB32, string $input, int $window = 1, int $period = 30): bool
{
    $input = preg_replace('/\D/', '', $input) ?? '';
    if (strlen($input) !== 6) {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $candidate = totp_code($secretB32, $now + ($i * $period), $period);
        if ($candidate !== '' && hash_equals($candidate, $input)) {
            return true;
        }
    }
    return false;
}

/** URI otpauth:// para leitura por QR code ou introdução manual. */
function totp_uri(string $secretB32, string $account, string $issuer): string
{
    return 'otpauth://totp/'
        . rawurlencode($issuer . ':' . $account)
        . '?secret=' . $secretB32
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/** Formata o segredo em grupos de 4 para introdução manual mais fácil. */
function totp_secret_pretty(string $secretB32): string
{
    return trim(chunk_split($secretB32, 4, ' '));
}

/** Gera códigos de recuperação legíveis (ex.: 4F2K-9QX7). */
function mfa_generate_recovery_codes(int $count = 10): array
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem I, O, 0, 1
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $code = '';
        for ($j = 0; $j < 8; $j++) {
            if ($j === 4) {
                $code .= '-';
            }
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = $code;
    }
    return $codes;
}

/** Normaliza um código de recuperação para comparação. */
function mfa_normalize_recovery(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}
