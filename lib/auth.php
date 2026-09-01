<?php
/** Autenticação, MFA, sessões e controlo de permissões. */

declare(strict_types=1);

const ROLES = [
    'admin'      => 'Administrador',
    'gestor'     => 'Gestor de aplicações',
    'supervisor' => 'Utilizador',
    'leitor'     => 'Consulta',
];

/**
 * Matriz de permissões por perfil.
 *
 *   view         abrir as aplicações publicadas
 *   apps.manage  enviar, substituir e remover aplicações
 *   users.manage criar e gerir contas
 *   audit.view   consultar o log de alterações
 */
const PERMISSIONS = [
    'admin'      => ['view', 'apps.manage', 'users.manage', 'audit.view'],
    'gestor'     => ['view', 'apps.manage'],
    'supervisor' => ['view'],
    'leitor'     => ['view'],
];

/** Utilizador autenticado (array) ou null. */
function current_user(): ?array
{
    static $cache = null;
    static $loaded = false;

    if ($loaded) {
        return $cache;
    }
    $loaded = true;

    if (empty($_SESSION['uid'])) {
        return $cache = null;
    }
    $u = q_one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$_SESSION['uid']]);
    if (!$u) {
        session_destroy();
        return $cache = null;
    }
    return $cache = $u;
}

/** O utilizador atual tem esta permissão? */
function can(string $permission): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    return in_array($permission, PERMISSIONS[$u['role']] ?? [], true);
}

/**
 * Exige sessão válida (password + MFA + password alterada).
 * Redireciona para o passo em falta.
 */
function require_login(string $permission = 'view'): array
{
    $prefix = defined('URL_PREFIX') ? URL_PREFIX : '';

    if (empty($_SESSION['uid'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect($prefix . 'login.php');
    }
    if (empty($_SESSION['mfa_passed'])) {
        redirect($prefix . 'mfa.php');
    }
    if (!session_still_valid()) {
        auth_logout('expirada');
        flash('warn', 'A sessão expirou por inatividade. Volte a autenticar-se.');
        redirect($prefix . 'login.php');
    }

    $u = current_user();
    if (!$u) {
        redirect($prefix . 'login.php');
    }
    if ((int)$u['must_change_pw'] === 1 && basename($_SERVER['SCRIPT_NAME']) !== 'password.php') {
        redirect($prefix . 'password.php');
    }
    if (!can($permission)) {
        http_response_code(403);
        exit('Sem permissão para aceder a esta página.');
    }
    session_touch();
    return $u;
}

/** Verifica limites de inactividade e duração absoluta. */
function session_still_valid(): bool
{
    global $CONFIG;
    $idle = (int)($CONFIG['security']['session_idle_minutes'] ?? 120) * 60;
    $abs  = (int)($CONFIG['security']['session_absolute_hours'] ?? 12) * 3600;
    $now  = time();

    if (!empty($_SESSION['last_seen']) && ($now - (int)$_SESSION['last_seen']) > $idle) {
        return false;
    }
    if (!empty($_SESSION['started_at']) && ($now - (int)$_SESSION['started_at']) > $abs) {
        return false;
    }
    return true;
}

/** Actualiza o carimbo de actividade da sessão. */
function session_touch(): void
{
    $_SESSION['last_seen'] = time();
    if (!empty($_SESSION['sid_hash'])) {
        q('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?', [$_SESSION['sid_hash']]);
    }
}

/** Regista uma tentativa de autenticação. */
function log_attempt(?string $username, bool $ok, string $stage = 'password', ?string $reason = null): void
{
    q(
        'INSERT INTO login_attempts (username, ip, successful, stage, reason) VALUES (?,?,?,?,?)',
        [$username, client_ip(), $ok ? 1 : 0, $stage, $reason]
    );
}

/** O IP fez demasiadas tentativas falhadas nos últimos 15 minutos? */
function ip_rate_limited(): bool
{
    global $CONFIG;
    $limit = (int)($CONFIG['security']['ip_attempt_limit'] ?? 25);
    $n = (int)q_val(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND successful = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)',
        [client_ip()]
    );
    return $n >= $limit;
}

/**
 * Passo 1 do login: valida credenciais.
 *
 * @return array{ok:bool, user?:array, error?:string}
 */
function auth_attempt_password(string $username, string $password): array
{
    global $CONFIG;

    if (ip_rate_limited()) {
        log_attempt($username, false, 'password', 'ip_rate_limited');
        return ['ok' => false, 'error' => 'Demasiadas tentativas a partir deste endereço. Tente novamente dentro de 15 minutos.'];
    }

    $u = q_one('SELECT * FROM users WHERE username = ? OR email = ?', [$username, $username]);

    // Comparação sempre executada, para não revelar se o utilizador existe.
    $hash = $u['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
    $valid = password_verify($password, $hash);

    if (!$u || !$valid) {
        log_attempt($username, false, 'password', $u ? 'bad_password' : 'unknown_user');
        if ($u) {
            $max = (int)($CONFIG['security']['max_failed_logins'] ?? 5);
            $mins = (int)($CONFIG['security']['lockout_minutes'] ?? 15);
            q(
                'UPDATE users
                    SET failed_logins = failed_logins + 1,
                        locked_until = IF(failed_logins + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until)
                  WHERE id = ?',
                [$max, $mins, $u['id']]
            );
        }
        return ['ok' => false, 'error' => 'Utilizador ou palavra-passe incorretos.'];
    }

    if ((int)$u['is_active'] !== 1) {
        log_attempt($username, false, 'password', 'inactive');
        return ['ok' => false, 'error' => 'Conta desativada. Contacte o administrador.'];
    }

    if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) {
        log_attempt($username, false, 'password', 'locked');
        return ['ok' => false, 'error' => 'Conta temporariamente bloqueada por excesso de tentativas. Tente mais tarde.'];
    }

    // Rehash se o algoritmo por omissão tiver mudado.
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?',
          [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }

    q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$u['id']]);
    log_attempt($username, true, 'password');

    return ['ok' => true, 'user' => $u];
}

/** Marca a sessão como "primeiro passo concluído", à espera de MFA. */
function auth_start_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid']        = (int)$user['id'];
    $_SESSION['mfa_passed'] = false;
    $_SESSION['started_at'] = time();
    $_SESSION['last_seen']  = time();
    $_SESSION['sid_hash']   = hash('sha256', session_id());

    q(
        'INSERT INTO user_sessions (id, user_id, ip, user_agent, mfa_passed)
         VALUES (?,?,?,?,0)
         ON DUPLICATE KEY UPDATE last_seen_at = NOW()',
        [$_SESSION['sid_hash'], (int)$user['id'], client_ip(), client_agent()]
    );
}

/** Conclui o login depois do MFA validado. */
function auth_complete_login(array $user): void
{
    $_SESSION['mfa_passed'] = true;
    $_SESSION['last_seen']  = time();

    q('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
      [client_ip(), (int)$user['id']]);
    q('UPDATE user_sessions SET mfa_passed = 1 WHERE id = ?', [$_SESSION['sid_hash'] ?? '']);

    audit('login', 'user', $user['id'], 'Início de sessão', null, null,
          (int)$user['id'], $user['username']);
}

/** Termina a sessão actual. */
function auth_logout(string $reason = 'manual'): void
{
    $u = current_user();
    if ($u) {
        audit('logout', 'user', $u['id'], 'Fim de sessão (' . $reason . ')', null, null,
              (int)$u['id'], $u['username']);
    }
    if (!empty($_SESSION['sid_hash'])) {
        q('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ?', [$_SESSION['sid_hash']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Segredo TOTP decifrado de um utilizador, ou null. */
function user_mfa_secret(array $user): ?string
{
    return app_decrypt($user['mfa_secret'] ?? null);
}

/** Grava (cifrado) o segredo TOTP de um utilizador. */
function user_set_mfa_secret(int $userId, ?string $secretB32): void
{
    $blob = $secretB32 === null ? null : app_encrypt($secretB32);
    $st = db()->prepare('UPDATE users SET mfa_secret = ? WHERE id = ?');
    $st->bindValue(1, $blob, $blob === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $st->bindValue(2, $userId, PDO::PARAM_INT);
    $st->execute();
}

/** Cria novos códigos de recuperação, substituindo os anteriores. */
function mfa_reset_recovery_codes(int $userId, int $count = 10): array
{
    q('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
    $codes = mfa_generate_recovery_codes($count);
    foreach ($codes as $code) {
        q('INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)',
          [$userId, password_hash(mfa_normalize_recovery($code), PASSWORD_DEFAULT)]);
    }
    return $codes;
}

/** Consome um código de recuperação. Devolve true se era válido. */
function mfa_consume_recovery_code(int $userId, string $input): bool
{
    $needle = mfa_normalize_recovery($input);
    if ($needle === '') {
        return false;
    }
    $rows = q_all('SELECT id, code_hash FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL',
                  [$userId]);
    foreach ($rows as $row) {
        if (password_verify($needle, $row['code_hash'])) {
            q('UPDATE mfa_recovery_codes SET used_at = NOW() WHERE id = ?', [$row['id']]);
            return true;
        }
    }
    return false;
}

/** Quantos códigos de recuperação continuam disponíveis. */
function mfa_recovery_codes_left(int $userId): int
{
    return (int)q_val('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL',
                      [$userId]);
}

/** Valida a robustez de uma palavra-passe. Devolve lista de problemas. */
function password_problems(string $pw): array
{
    global $CONFIG;
    $min = (int)($CONFIG['security']['password_min_length'] ?? 10);
    $out = [];
    if (mb_strlen($pw) < $min) {
        $out[] = 'ter pelo menos ' . $min . ' caracteres';
    }
    if (!preg_match('/[A-Za-zÀ-ÿ]/u', $pw)) {
        $out[] = 'incluir pelo menos uma letra';
    }
    if (!preg_match('/\d/', $pw)) {
        $out[] = 'incluir pelo menos um algarismo';
    }
    if (preg_match('/^(?:12345|senha|password|setronix)/i', $pw)) {
        $out[] = 'não começar por uma sequência óbvia';
    }
    return $out;
}
