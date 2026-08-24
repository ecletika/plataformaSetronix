<?php
/** Área de administração — resumo. */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_login('view');
if (!can('users.manage') && !can('lists.edit')) {
    http_response_code(403);
    exit('Sem permissão para aceder à administração.');
}

$stats = [
    'users_total'   => (int)q_val('SELECT COUNT(*) FROM users'),
    'users_active'  => (int)q_val('SELECT COUNT(*) FROM users WHERE is_active = 1'),
    'users_no_mfa'  => (int)q_val('SELECT COUNT(*) FROM users WHERE is_active = 1 AND mfa_enabled = 0'),
    'list_items'    => (int)q_val('SELECT COUNT(*) FROM list_items WHERE is_active = 1'),
    'works'         => (int)q_val('SELECT COUNT(*) FROM works WHERE is_archived = 0'),
    'plans'         => (int)q_val('SELECT COUNT(*) FROM plans'),
];
$lastImport = q_one('SELECT * FROM import_runs ORDER BY id DESC LIMIT 1');
$lastBackup = q_one('SELECT * FROM backups ORDER BY id DESC LIMIT 1');
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
    <div><div class="muted">Valores nas listas base</div><b style="font-size:24px"><?= $stats['list_items'] ?></b></div>
    <div><div class="muted">Obras registadas</div><b style="font-size:24px"><?= $stats['works'] ?></b></div>
    <div><div class="muted">Planeamentos semanais</div><b style="font-size:24px"><?= $stats['plans'] ?></b></div>
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
  <h2>Dados base e cópias de segurança</h2>
  <table>
    <tr>
      <th style="width:190px">Última importação</th>
      <td>
        <?php if ($lastImport): ?>
          <?= e($lastImport['filename']) ?> · <?= e($lastImport['created_at']) ?> ·
          <?= (int)$lastImport['items_added'] ?> novos,
          <?= (int)$lastImport['items_deactivated'] ?> desativados
          <?= $lastImport['status'] === 'error' ? '<span class="tag off">erro</span>' : '' ?>
        <?php else: ?><span class="muted">Nunca importado.</span><?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>Último backup</th>
      <td>
        <?php if ($lastBackup): ?>
          <?= e($lastBackup['filename']) ?> · <?= e($lastBackup['created_at']) ?> ·
          <?= human_bytes((int)$lastBackup['size_bytes']) ?>
        <?php else: ?><span class="muted">Nenhum backup criado.</span><?php endif; ?>
      </td>
    </tr>
  </table>
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
