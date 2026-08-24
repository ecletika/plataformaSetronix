<?php
/** Registo de auditoria (log de alterações). */

declare(strict_types=1);

/**
 * Regista uma acção no log de auditoria.
 *
 * @param string      $action  login, logout, create, update, delete, import, backup, ...
 * @param string|null $entity  work, plan, user, list_item, system
 * @param array|null  $before  Estado anterior (será guardado em JSON)
 * @param array|null  $after   Estado posterior
 */
function audit(
    string $action,
    ?string $entity = null,
    $entityId = null,
    ?string $summary = null,
    ?array $before = null,
    ?array $after = null,
    ?int $userId = null,
    ?string $username = null
): void {
    try {
        $u = $userId === null ? current_user() : null;
        q(
            'INSERT INTO audit_log
                (user_id, username, action, entity, entity_id, summary,
                 data_before, data_after, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $userId ?? ($u['id'] ?? null),
                $username ?? ($u['username'] ?? null),
                $action,
                $entity,
                $entityId === null ? null : (string)$entityId,
                $summary === null ? null : mb_substr($summary, 0, 255),
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                client_ip(),
                client_agent(),
            ]
        );
    } catch (Throwable $ex) {
        // O log nunca deve quebrar a operação principal.
        error_log('audit falhou: ' . $ex->getMessage());
    }
}

/** Campos que nunca devem entrar no log. */
function audit_scrub(array $row): array
{
    foreach (['password', 'password_hash', 'mfa_secret', 'code_hash', '_csrf'] as $k) {
        if (array_key_exists($k, $row)) {
            $row[$k] = '***';
        }
    }
    return $row;
}
