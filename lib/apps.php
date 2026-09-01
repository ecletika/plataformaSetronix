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

/** Versão activa de uma aplicação, ou null se ainda não tiver ficheiro. */
function app_current_version(array $app): ?array
{
    if (empty($app['current_version_id'])) {
        return null;
    }
    return q_one('SELECT * FROM app_versions WHERE id = ?', [(int)$app['current_version_id']]);
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

/** Repõe uma versão anterior como versão activa. */
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

/** Apaga uma versão (nunca a activa). */
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
