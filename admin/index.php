<?php
/** Área de administração — resumo. */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/apps.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_login('view');
if (!can('users.manage') && !can('apps.manage')) {
    http_response_code(403);
    exit('Sem permissão para aceder à administração.');
}

$stats = [
    'users_total'  => (int)q_val('SELECT COUNT(*) FROM users'),
    'users_active' => (int)q_val('SELECT COUNT(*) FROM users WHERE is_active = 1'),
    'users_no_mfa' => (int)q_val('SELECT COUNT(*) FROM users WHERE is_active = 1 AND mfa_enabled = 0'),
    'apps_active'  => (int)q_val('SELECT COUNT(*) FROM apps WHERE is_active = 1'),
    'apps_total'   => (int)q_val('SELECT COUNT(*) FROM apps'),
    'versions'     => (int)q_val('SELECT COUNT(*) FROM app_versions'),
];
$lastVersion = q_one('SELECT av.*, a.name AS app_name
                        FROM app_versions av
                        JOIN apps a ON a.id = av.app_id
                       ORDER BY av.id DESC LIMIT 1');
$recent = q_all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 12');
$failed = (int)q_val('SELECT COUNT(*) FROM login_attempts
                      WHERE successful = 0 AND created_at > (NOW() - INTERVAL 24 HOUR)');

layout_head('Administração', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('index'); ?>

<div class="card">
  <h2>Estado da plataforma</h2>
  <div class="grid2" style="margin-top:12px">
    <div><div class="muted">Utilizadores ativos</div><b style="font-size:24px"><?= $stats['users_active'] ?></b>
         <span class="muted">de <?= $stats['users_total'] ?></span></div>
    <div><div class="muted">Aplicações visíveis</div><b style="font-size:24px"><?= $stats['apps_active'] ?></b>
         <span class="muted">de <?= $stats['apps_total'] ?></span></div>
    <div><div class="muted">Versões guardadas</div><b style="font-size:24px"><?= $stats['versions'] ?></b></div>
  </div>
</div>

<?php if ($stats['users_no_mfa'] > 0): ?>
  <div class="alert warn">
    <?= $stats['users_no_mfa'] ?> utilizador(es) ativo(s) ainda não ativaram o MFA.
    A ativação é feita automaticamente no primeiro início de sessão.
  </div>
<?php endif; ?>
<?php if ($failed > 20): ?>
  <div class="alert warn">
    <?= $failed ?> tentativas de autenticação falhadas nas últimas 24 horas. Verifique o log de alterações.
  </div>
<?php endif; ?>

<div class="card">
  <h2>Última publicação</h2>
  <?php if ($lastVersion): ?>
    <table>
      <tr><th style="width:190px">Aplicação</th><td><?= e($lastVersion['app_name']) ?></td></tr>
      <tr><th>Versão</th><td><?= (int)$lastVersion['version'] ?> ·
          <?= e(human_bytes((int)$lastVersion['size_bytes'])) ?></td></tr>
      <tr><th>Ficheiro</th><td class="mono"><?= e($lastVersion['filename']) ?></td></tr>
      <tr><th>Data</th><td class="mono"><?= e((string)$lastVersion['created_at']) ?></td></tr>
    </table>
  <?php else: ?>
    <p class="muted">Ainda não foi publicada nenhuma aplicação.</p>
  <?php endif; ?>
  <?php if (can('apps.manage')): ?>
    <p style="margin-top:12px"><a class="btn primary" href="apps.php">Gerir aplicações</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Últimas alterações</h2>
  <div class="scroll">
    <table>
      <thead><tr><th>Data</th><th>Utilizador</th><th>Ação</th><th>Descrição</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="mono"><?= e($r['created_at']) ?></td>
            <td><?= e((string)$r['username']) ?></td>
            <td><?= e($r['action']) ?></td>
            <td class="muted"><?= e((string)$r['summary']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="4" class="muted">Sem registos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (can('audit.view')): ?>
    <p style="margin-top:12px"><a class="btn" href="audit.php">Ver log completo</a></p>
  <?php endif; ?>
</div>

</div>
<?php layout_foot(); ?>
