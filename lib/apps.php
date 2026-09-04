<?php
/**
 * Aplicações HTML.
 *
 * A plataforma é apenas a casca: autenticação, contas e um repositório de
 * páginas HTML autónomas. Cada aplicação é um único ficheiro .html enviado
 * por um administrador e servido, tal como está, dentro da sessão.
 *
 * Os ficheiros ficam em storage/apps/ (pasta bloqueada pelo .htaccess) e só
 * são servidos através de app_raw.php, com sessão validada.
 */

declare(strict_types=1);

/** Pasta onde ficam os ficheiros das aplicações. */
function apps_dir(): string
{
    global $CONFIG;
    $dir = $CONFIG['paths']['apps'] ?? (APP_ROOT . '/storage/apps');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

/** Tamanho máximo aceite, em bytes. */
function apps_max_bytes(): int
{
    global $CONFIG;
    return (int)($CONFIG['apps']['max_mb'] ?? 8) * 1024 * 1024;
}

/** Caminho completo do ficheiro de uma versão. */
function app_version_path(string $storageName): string
{
    return apps_dir() . '/' . basename($storageName);
}

/** Lista de aplicações. */
function apps_all(bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM apps';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    return q_all($sql . ' ORDER BY sort_order, name');
}

/** Uma aplicação por id, ou null. */
function app_find(int $id): ?array
{
    return q_one('SELECT * FROM apps WHERE id = ?', [$id]);
}

/** Versões de uma aplicação, da mais recente para a mais antiga. */
function app_versions(int $appId): array
{
    return q_all('SELECT * FROM app_versions WHERE app_id = ? ORDER BY version DESC', [$appId]);
}

/** Uma versão concreta de uma aplicação. */
function app_version_find(int $appId, int $versionId): ?array
{
    return q_one('SELECT * FROM app_versions WHERE id = ? AND app_id = ?', [$versionId, $appId]);
}

/** Versão ativa de uma aplicação, ou null se ainda não tiver ficheiro. */
function app_current_version(array $app): ?array
{
    if (empty($app['current_version_id'])) {
        return null;
    }
    return q_one('SELECT * FROM app_versions WHERE id = ?', [(int)$app['current_version_id']]);
}

// ---------------------------------------------------------------------
// Quem vê o quê
//
// Uma aplicação sem ninguém atribuído é visível para todos. Basta atribuir
// uma pessoa para que passe a ser só dela (e de quem for acrescentado).
// ---------------------------------------------------------------------

/** Ids dos utilizadores com acesso explícito a uma aplicação. */
function app_user_ids(int $appId): array
{
    return array_map('intval', array_column(
        q_all('SELECT user_id FROM user_apps WHERE app_id = ?', [$appId]),
        'user_id'
    ));
}

/** Ids das aplicações atribuídas explicitamente a um utilizador. */
function user_app_ids(int $userId): array
{
    return array_map('intval', array_column(
        q_all('SELECT app_id FROM user_apps WHERE user_id = ?', [$userId]),
        'app_id'
    ));
}

/**
 * Guarda a aplicação predefinida e quem a escolheu.
 *
 * Saber a origem importa: a escolha do próprio utilizador manda sobre a
 * sugestão de quem lhe atribuiu as aplicações, e o administrador deve ver
 * que a está a sobrepor antes de o fazer.
 *
 * @param string $porQuem 'utilizador', 'admin' ou 'automatica'
 */
function app_set_default(int $userId, int $appId, string $porQuem): void
{
    user_pref_set($userId, 'default_app', (string)$appId);
    user_pref_set($userId, 'default_app_by', $porQuem);
}

/** Quem escolheu a predefinida deste utilizador. */
function app_default_origin(int $userId): string
{
    $v = (string)q_val('SELECT pvalue FROM user_prefs WHERE user_id = ? AND pkey = ?',
                       [$userId, 'default_app_by']);
    return $v !== '' ? $v : 'automatica';
}

/**
 * Acerta a aplicação predefinida de um utilizador depois de lhe mudarem os
 * acessos.
 *
 * Com uma aplicação só não há nada a decidir: fica essa, e a pessoa entra
 * diretamente nela. Com duas ou mais, a escolha é de quem atribui — a
 * plataforma não inventa uma, avisa quem está a atribuir.
 *
 * Também limpa uma predefinida que tenha deixado de estar disponível.
 *
 * @return array{apps:int, escolhida:?array, precisa_escolher:bool}
 */
function app_sync_default(int $userId, bool $seesAll = false): array
{
    $apps = apps_for_user($userId, $seesAll);
    $n    = count($apps);

    $atualId = (int)q_val('SELECT pvalue FROM user_prefs WHERE user_id = ? AND pkey = ?',
                          [$userId, 'default_app']);
    $valida = null;
    foreach ($apps as $a) {
        if ((int)$a['id'] === $atualId) {
            $valida = $a;
            break;
        }
    }

    // A escolhida deixou de estar disponível: não vale a pena mantê-la.
    if ($atualId > 0 && !$valida) {
        app_set_default($userId, 0, 'automatica');
        $atualId = 0;
    }

    if ($valida) {
        return ['apps' => $n, 'escolhida' => $valida, 'precisa_escolher' => false];
    }
    if ($n === 1) {
        app_set_default($userId, (int)$apps[0]['id'], 'automatica');
        return ['apps' => 1, 'escolhida' => $apps[0], 'precisa_escolher' => false];
    }
    return ['apps' => $n, 'escolhida' => null, 'precisa_escolher' => $n > 1];
}

/** Define a lista de utilizadores com acesso a uma aplicação. */
function app_set_users(int $appId, array $userIds): void
{
    q('DELETE FROM user_apps WHERE app_id = ?', [$appId]);
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid > 0) {
            q('INSERT INTO user_apps (user_id, app_id, granted_by) VALUES (?,?,?)',
              [$uid, $appId, current_user()['id'] ?? null]);
            user_unhide_app($uid, $appId);
        }
    }
}

/** Define a lista de aplicações atribuídas a um utilizador. */
function user_set_apps(int $userId, array $appIds): void
{
    q('DELETE FROM user_apps WHERE user_id = ?', [$userId]);
    foreach (array_unique(array_map('intval', $appIds)) as $aid) {
        if ($aid > 0) {
            q('INSERT INTO user_apps (user_id, app_id, granted_by) VALUES (?,?,?)',
              [$userId, $aid, current_user()['id'] ?? null]);
            user_unhide_app($userId, $aid);
        }
    }
}

// ---------------------------------------------------------------------
// Arrumação do lado do utilizador
//
// Retirar uma aplicação da lista não retira o acesso: é só arrumação. Um
// administrador repõe-na na ficha do utilizador.
// ---------------------------------------------------------------------

/** Ids das aplicações que este utilizador retirou da sua lista. */
function user_hidden_app_ids(int $userId): array
{
    return array_map('intval', array_column(
        q_all('SELECT app_id FROM user_apps_hidden WHERE user_id = ?', [$userId]),
        'app_id'
    ));
}

/** Retira uma aplicação da lista de um utilizador. */
function user_hide_app(int $userId, int $appId): void
{
    q('INSERT IGNORE INTO user_apps_hidden (user_id, app_id) VALUES (?,?)', [$userId, $appId]);
    // Se era a predefinida, deixa de o ser — senão continuava a abrir sozinha.
    if ((int)user_pref('default_app', '0') === $appId) {
        app_set_default($userId, 0, 'utilizador');
    }
}

/** Repõe uma aplicação na lista de um utilizador. */
function user_unhide_app(int $userId, int $appId): void
{
    q('DELETE FROM user_apps_hidden WHERE user_id = ? AND app_id = ?', [$userId, $appId]);
}

/** Aplicações que um utilizador retirou e continuam a estar-lhe disponíveis. */
function user_hidden_apps(int $userId): array
{
    return q_all(
        'SELECT a.* FROM apps a
           JOIN user_apps_hidden h ON h.app_id = a.id AND h.user_id = ?
          ORDER BY a.sort_order, a.name',
        [$userId]
    );
}

/**
 * Aplicações que um utilizador pode abrir.
 *
 * Quem gere aplicações vê sempre todas — de outra forma um administrador
 * podia deixar-se a si próprio de fora e ficar sem acesso ao que publicou.
 */
function apps_for_user(int $userId, bool $seesAll = false, bool $incluirEscondidas = false): array
{
    $sql = 'SELECT a.* FROM apps a WHERE a.is_active = 1';
    $arg = [];

    if (!$seesAll) {
        $sql .= ' AND (NOT EXISTS (SELECT 1 FROM user_apps ua WHERE ua.app_id = a.id)
                       OR EXISTS (SELECT 1 FROM user_apps ua WHERE ua.app_id = a.id AND ua.user_id = ?))';
        $arg[] = $userId;
    }
    if (!$incluirEscondidas) {
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM user_apps_hidden h
                                   WHERE h.app_id = a.id AND h.user_id = ?)';
        $arg[] = $userId;
    }
    return q_all($sql . ' ORDER BY a.sort_order, a.name', $arg);
}

/**
 * Este utilizador pode abrir esta aplicação?
 *
 * Retirar uma aplicação da lista não conta aqui: é arrumação, não uma
 * retirada de acesso. Quem tiver o endereço continua a poder abri-la — e
 * o botão "Abrir" da administração continua a funcionar.
 */
function user_can_open_app(int $userId, array $app, bool $seesAll = false): bool
{
    if ((int)$app['is_active'] !== 1) {
        return false;
    }
    if ($seesAll) {
        return true;
    }
    $restricted = (int)q_val('SELECT COUNT(*) FROM user_apps WHERE app_id = ?', [(int)$app['id']]);
    if ($restricted === 0) {
        return true;
    }
    return (int)q_val('SELECT COUNT(*) FROM user_apps WHERE app_id = ? AND user_id = ?',
                      [(int)$app['id'], $userId]) > 0;
}

/**
 * Aplicação predefinida de um utilizador — a que abre logo a seguir ao
 * início de sessão.
 *
 * Devolve null quando não escolheu nenhuma, quando a que escolheu foi
 * entretanto removida, ocultada, ou deixou de lhe estar atribuída.
 */
function user_default_app(?int $userId = null, ?bool $seesAll = null): ?array
{
    static $cache = [];

    $u = current_user();
    if (!$u) {
        return null;
    }
    $userId  = $userId ?? (int)$u['id'];
    $seesAll = $seesAll ?? in_array('apps.manage', PERMISSIONS[$u['role']] ?? [], true);

    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $id = (int)user_pref('default_app', '0');
    if ($id <= 0) {
        return $cache[$userId] = null;
    }
    $app = app_find($id);
    if (!$app || !user_can_open_app($userId, $app, $seesAll)) {
        return $cache[$userId] = null;
    }
    return $cache[$userId] = $app;
}

/** Identificador curto e único para a aplicação (usado no URL). */
function app_make_slug(string $name, ?int $ignoreId = null): string
{
    $slug = (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    $slug = strtolower($slug);
    $slug = (string)preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)preg_replace('/-+/', '-', $slug), '-');
    if ($slug === '') {
        $slug = 'app';
    }
    $base = substr($slug, 0, 56);
    $slug = $base;

    for ($n = 2; ; $n++) {
        $row = $ignoreId === null
            ? q_one('SELECT id FROM apps WHERE slug = ?', [$slug])
            : q_one('SELECT id FROM apps WHERE slug = ? AND id <> ?', [$slug, $ignoreId]);
        if (!$row) {
            return $slug;
        }
        $slug = $base . '-' . $n;
    }
}

/**
 * Valida um upload de HTML e devolve o conteúdo do ficheiro.
 *
 * @throws RuntimeException com uma mensagem pronta a mostrar ao utilizador.
 */
function app_read_upload(array $file): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Escolha o ficheiro HTML a enviar.');
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('O ficheiro é maior do que o limite do servidor. Máximo aceite: '
            . human_bytes(apps_max_bytes()) . '.');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no envio do ficheiro (código ' . $err . '). Tente novamente.');
    }

    $name = to_utf8((string)($file['name'] ?? ''));
    $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['html', 'htm'], true)) {
        throw new RuntimeException('Só são aceites ficheiros .html ou .htm. Peça um ficheiro HTML único, '
            . 'com o CSS e o JavaScript lá dentro.');
    }
    if ((int)$file['size'] > apps_max_bytes()) {
        throw new RuntimeException('O ficheiro tem ' . human_bytes((int)$file['size'])
            . '; o máximo aceite é ' . human_bytes(apps_max_bytes()) . '.');
    }

    $html = (string)file_get_contents((string)$file['tmp_name']);
    if (trim($html) === '') {
        throw new RuntimeException('O ficheiro está vazio.');
    }
    if (stripos($html, '<html') === false
        && stripos($html, '<body') === false
        && stripos($html, '<!doctype') === false) {
        throw new RuntimeException('O ficheiro não parece ser uma página HTML.');
    }
    return $html;
}

/**
 * Grava uma nova versão de uma aplicação a partir de um upload.
 *
 * @return array A versão criada.
 */
function app_store_version(int $appId, array $file, ?string $notes = null): array
{
    $html     = app_read_upload($file);
    $original = substr(basename(to_utf8((string)($file['name'] ?? 'app.html'))), 0, 255);

    $version     = (int)q_val('SELECT COALESCE(MAX(version), 0) + 1 FROM app_versions WHERE app_id = ?', [$appId]);
    $storageName = sprintf('app%d-v%d-%s.html', $appId, $version, bin2hex(random_bytes(6)));
    $path        = app_version_path($storageName);

    if (file_put_contents($path, $html) === false) {
        throw new RuntimeException('Não foi possível gravar o ficheiro em storage/apps/. '
            . 'Verifique as permissões da pasta (750).');
    }
    @chmod($path, 0640);

    $notes = $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 255);
    q(
        'INSERT INTO app_versions (app_id, version, filename, storage_name, size_bytes, sha256, notes, created_by)
         VALUES (?,?,?,?,?,?,?,?)',
        [$appId, $version, $original, $storageName, strlen($html), hash('sha256', $html), $notes,
         current_user()['id'] ?? null]
    );
    $versionId = (int)db()->lastInsertId();

    q('UPDATE apps SET current_version_id = ? WHERE id = ?', [$versionId, $appId]);

    return q_one('SELECT * FROM app_versions WHERE id = ?', [$versionId]) ?? [];
}

/** Cria uma aplicação nova a partir do primeiro ficheiro enviado. */
function app_create(string $name, ?string $description, array $file): array
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Indique o nome da aplicação.');
    }
    // Valida o ficheiro antes de criar o registo, para não deixar lixo na BD.
    app_read_upload($file);

    q(
        'INSERT INTO apps (slug, name, description, is_active, sort_order, created_by)
         VALUES (?,?,?,1,?,?)',
        [
            app_make_slug($name),
            mb_substr($name, 0, 160),
            $description === null || trim($description) === '' ? null : mb_substr(trim($description), 0, 500),
            (int)q_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM apps'),
            current_user()['id'] ?? null,
        ]
    );
    $appId = (int)db()->lastInsertId();
    app_store_version($appId, $file, 'Primeira versão');

    return app_find($appId) ?? [];
}

/** Repõe uma versão anterior como versão ativa. */
function app_rollback(int $appId, int $versionId): array
{
    $v = app_version_find($appId, $versionId);
    if (!$v) {
        throw new RuntimeException('Versão não encontrada.');
    }
    if (!is_file(app_version_path($v['storage_name']))) {
        throw new RuntimeException('O ficheiro dessa versão já não existe em storage/apps/.');
    }
    q('UPDATE apps SET current_version_id = ? WHERE id = ?', [$versionId, $appId]);
    return $v;
}

/** Apaga uma versão (nunca a ativa). */
function app_version_delete(int $appId, int $versionId): array
{
    $app = app_find($appId);
    $v   = app_version_find($appId, $versionId);
    if (!$app || !$v) {
        throw new RuntimeException('Versão não encontrada.');
    }
    if ((int)$app['current_version_id'] === $versionId) {
        throw new RuntimeException('Não é possível apagar a versão que está a ser usada. '
            . 'Reponha outra versão primeiro.');
    }
    @unlink(app_version_path($v['storage_name']));
    q('DELETE FROM app_versions WHERE id = ?', [$versionId]);
    return $v;
}

/** Apaga uma aplicação e todos os seus ficheiros. */
function app_delete(int $appId): void
{
    foreach (app_versions($appId) as $v) {
        @unlink(app_version_path($v['storage_name']));
    }
    q('DELETE FROM apps WHERE id = ?', [$appId]);
}
