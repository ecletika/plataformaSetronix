<?php
/**
 * Assistente de instalação.
 *
 * Passos:
 *   1. Verificação do ambiente (PHP, extensões, permissões)
 *   2. Dados da base de dados → escreve config.php
 *   3. Criação das tabelas (schema.sql)
 *   4. Criação do primeiro administrador
 *   5. Importação opcional do ficheiro de listas base
 *
 * Depois de concluído, APAGUE ou bloqueie esta pasta.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/lib/helpers.php';

session_name('SETRONIX_INSTALL');
session_start();

$configFile   = APP_ROOT . '/config.php';
$installed    = is_file($configFile);
$lockFile     = APP_ROOT . '/storage/.installed';
$alreadyDone  = is_file($lockFile);

$step  = (int)($_GET['step'] ?? ($installed ? 3 : 1));
$error = '';
$notes = [];

// Se já estiver instalado e bloqueado, só permite ver instruções de remoção.
if ($alreadyDone && !isset($_GET['force'])) {
    $step = 99;
}

// ---------------------------------------------------------------------
// Verificações de ambiente
// ---------------------------------------------------------------------
function env_checks(): array
{
    $storage = APP_ROOT . '/storage';
    return [
        ['PHP 7.4 ou superior',        PHP_VERSION_ID >= 70400, PHP_VERSION],
        ['Extensão pdo_mysql',         extension_loaded('pdo_mysql'), 'ligação à base de dados'],
        ['Extensão mbstring',          extension_loaded('mbstring'), 'texto com acentos'],
        ['Extensão openssl',           extension_loaded('openssl'), 'cifra dos segredos MFA'],
        ['Extensão zip',               class_exists('ZipArchive'), 'leitura de ficheiros .xlsx (opcional — sem ela, use CSV)'],
        ['Extensão zlib',              function_exists('gzencode'), 'compressão dos backups (opcional)'],
        ['Escrita na raiz da aplicação', is_writable(APP_ROOT), 'criação do config.php'],
        ['Escrita em storage/',        is_dir($storage) && is_writable($storage), 'backups e uploads'],
    ];
}

/** Escreve o config.php a partir dos dados do formulário. */
function write_config(array $db, string $appKey, string $org, string $appName): void
{
    $tpl = <<<'PHPTPL'
<?php
/** Configuração gerada pelo assistente de instalação. NÃO partilhar este ficheiro. */

return [
    'db' => [
        'host'    => '%HOST%',
        'port'    => %PORT%,
        'name'    => '%NAME%',
        'user'    => '%USER%',
        'pass'    => '%PASS%',
        'charset' => 'utf8mb4',
    ],
    'app_key' => '%KEY%',
    'app' => [
        'name'        => '%APPNAME%',
        'org'         => '%ORG%',
        'timezone'    => 'Europe/Lisbon',
        'base_url'    => '',
        'force_https' => true,
        'debug'       => false,
    ],
    'security' => [
        'session_idle_minutes'   => 120,
        'session_absolute_hours' => 12,
        'max_failed_logins'      => 5,
        'lockout_minutes'        => 15,
        'ip_attempt_limit'       => 25,
        'password_min_length'    => 10,
        'totp_window'            => 1,
        'recovery_code_count'    => 10,
    ],
    'paths' => [
        'storage' => __DIR__ . '/storage',
        'backups' => __DIR__ . '/storage/backups',
        'uploads' => __DIR__ . '/storage/uploads',
    ],
];
PHPTPL;

    $esc = static fn(string $s): string => str_replace(["\\", "'"], ["\\\\", "\\'"], $s);

    $out = strtr($tpl, [
        '%HOST%'    => $esc($db['host']),
        '%PORT%'    => (string)(int)$db['port'],
        '%NAME%'    => $esc($db['name']),
        '%USER%'    => $esc($db['user']),
        '%PASS%'    => $esc($db['pass']),
        '%KEY%'     => $esc($appKey),
        '%APPNAME%' => $esc($appName),
        '%ORG%'     => $esc($org),
    ]);

    if (file_put_contents(APP_ROOT . '/config.php', $out) === false) {
        throw new RuntimeException('Não foi possível escrever o config.php. Verifique as permissões da pasta.');
    }
    @chmod(APP_ROOT . '/config.php', 0640);
}

// ---------------------------------------------------------------------
// Processamento dos passos
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'config') {
            $db = [
                'host' => trim((string)($_POST['host'] ?? 'localhost')),
                'port' => (int)($_POST['port'] ?? 3306),
                'name' => trim((string)($_POST['name'] ?? '')),
                'user' => trim((string)($_POST['user'] ?? '')),
                'pass' => (string)($_POST['pass'] ?? ''),
            ];
            if ($db['name'] === '' || $db['user'] === '') {
                throw new RuntimeException('Indique o nome da base de dados e o utilizador.');
            }

            // Testa a ligação antes de gravar seja o que for.
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                           $db['host'], $db['port'], $db['name']);
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query('SELECT 1');

            write_config(
                $db,
                bin2hex(random_bytes(32)),
                trim((string)($_POST['org'] ?? 'Setronix')) ?: 'Setronix',
                trim((string)($_POST['appname'] ?? 'Planeamento Operacional de Obras')) ?: 'Planeamento Operacional de Obras'
            );
            header('Location: index.php?step=2');
            exit;
        }

        if ($action === 'schema') {
            $CONFIG = require $configFile;
            $c = $CONFIG['db'];
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']),
                $c['user'], $c['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $sql = (string)file_get_contents(__DIR__ . '/schema.sql');
            $pdo->exec($sql);
            header('Location: index.php?step=3');
            exit;
        }

        if ($action === 'admin') {
            require_once APP_ROOT . '/lib/bootstrap.php';
            require_once APP_ROOT . '/lib/auth.php';

            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $email    = trim((string)($_POST['email'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $pw       = (string)($_POST['password'] ?? '');

            if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
                throw new RuntimeException('Nome de utilizador inválido (3-64 caracteres: letras minúsculas, números, . _ -).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('E-mail inválido.');
            }
            if ($fullName === '') {
                throw new RuntimeException('Indique o nome completo.');
            }
            if ($problems = password_problems($pw)) {
                throw new RuntimeException('A palavra-passe deve ' . implode(', ', $problems) . '.');
            }
            if (q_val('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?', [$username, $email])) {
                throw new RuntimeException('Já existe um utilizador com esse nome ou e-mail.');
            }

            q('INSERT INTO users (username, email, full_name, password_hash, role, is_active, must_change_pw, mfa_required)
               VALUES (?,?,?,?,\'admin\',1,0,1)',
              [$username, $email, $fullName, password_hash($pw, PASSWORD_DEFAULT)]);

            audit('install', 'user', db()->lastInsertId(), 'Administrador inicial criado: ' . $username,
                  null, null, null, $username);

            header('Location: index.php?step=4');
            exit;
        }

        if ($action === 'seed') {
            require_once APP_ROOT . '/lib/bootstrap.php';
            require_once APP_ROOT . '/lib/lists.php';

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Selecione o ficheiro de listas base (.xlsx ou .csv).');
            }
            $name = (string)$_FILES['file']['name'];
            $tmp  = (string)$_FILES['file']['tmp_name'];
            $stats = lists_import($tmp, $name, 'merge', null);
            $_SESSION['seed_stats'] = $stats;
            header('Location: index.php?step=5');
            exit;
        }

        if ($action === 'finish') {
            if (!is_dir(APP_ROOT . '/storage')) {
                mkdir(APP_ROOT . '/storage', 0750, true);
            }
            file_put_contents($lockFile, date('c') . " instalação concluída\n");
            header('Location: index.php?step=99');
            exit;
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
        $step = (int)($_POST['step'] ?? $step);
    }
}

// ---------------------------------------------------------------------
// Apresentação
// ---------------------------------------------------------------------
$checks = env_checks();
$blocking = array_filter($checks, static fn($c) => !$c[1] && strpos($c[2], 'opcional') === false);
?><!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Instalação · Plataforma Setronix</title>
<style>
body{margin:0;font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f1f5f9;color:#0f172a}
.wrap{max-width:720px;margin:40px auto;padding:0 18px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:18px}
h1{font-size:20px;margin:0 0 4px}h2{font-size:17px;margin:0 0 12px}
h3{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin:18px 0 8px}
.muted{color:#64748b;font-size:13px}
label{display:block;margin-bottom:12px;font-size:13px}
input,select{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;margin-top:4px}
button{font:inherit;padding:10px 16px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;cursor:pointer}
button.ghost{background:#fff;color:#0f172a;border-color:#cbd5e1}
.alert{padding:11px 13px;border-radius:8px;margin-bottom:14px;font-size:13px;border:1px solid}
.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
.info{background:#eff6ff;border-color:#bfdbfe;color:#1e40af}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:7px 8px;border-bottom:1px solid #e2e8f0;text-align:left}
.steps{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap}
.steps span{font-size:12px;padding:5px 10px;border-radius:99px;background:#e2e8f0;color:#475569}
.steps span.on{background:#2563eb;color:#fff}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-family:ui-monospace,Consolas,monospace}
</style>
</head>
<body>
<div class="wrap">
  <h1>Plataforma Setronix — instalação</h1>
  <p class="muted">Planeamento operacional de obras · configuração inicial do servidor</p>

  <div class="steps">
    <?php foreach ([1 => 'Ambiente', 2 => 'Base de dados', 3 => 'Administrador', 4 => 'Listas base', 5 => 'Concluir'] as $n => $label): ?>
      <span class="<?= $step === $n ? 'on' : '' ?>"><?= $n ?>. <?= htmlspecialchars($label) ?></span>
    <?php endforeach; ?>
  </div>

  <?php if ($error !== ''): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($step === 99): ?>
  <div class="card">
    <h2>Instalação concluída</h2>
    <div class="alert warn">
      <b>Passo obrigatório:</b> apague a pasta <code>install/</code> do servidor
      (ou renomeie-a). Enquanto existir, qualquer pessoa pode voltar a correr o assistente.
    </div>
    <p>Verificações finais recomendadas no cPanel:</p>
    <ul class="muted">
      <li>Permissões: <code>config.php</code> = 640, <code>storage/</code> = 750.</li>
      <li>Certificado SSL ativo e redirecionamento para HTTPS ligado.</li>
      <li>Cron job diário para <code>install/cron_backup.php</code> (mova-o para fora de install/ antes de apagar a pasta).</li>
    </ul>
    <p><a href="../login.php"><button>Ir para o início de sessão</button></a></p>
  </div>

<?php elseif ($step === 1): ?>
  <div class="card">
    <h2>1. Verificação do ambiente</h2>
    <table>
      <?php foreach ($checks as [$name, $ok, $why]): ?>
        <tr>
          <td style="width:26px"><?= $ok ? '✅' : (strpos($why, 'opcional') !== false ? '⚠️' : '❌') ?></td>
          <td><?= htmlspecialchars($name) ?></td>
          <td class="muted"><?= htmlspecialchars($why) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($blocking): ?>
      <div class="alert err" style="margin-top:14px">
        Existem requisitos por cumprir. No cPanel, vá a <b>Select PHP Version → Extensions</b>
        para ativar as extensões em falta, e corrija as permissões das pastas.
      </div>
    <?php else: ?>
      <div class="alert ok" style="margin-top:14px">Ambiente pronto.</div>
    <?php endif; ?>
    <p><a href="index.php?step=2"><button <?= $blocking ? 'disabled' : '' ?>>Continuar</button></a></p>
  </div>

<?php elseif ($step === 2): ?>
  <div class="card">
    <h2>2. Base de dados</h2>
    <?php if ($installed): ?>
      <div class="alert info">
        Já existe um <code>config.php</code>. Se quiser recomeçar, apague-o do servidor.
      </div>
      <form method="post">
        <input type="hidden" name="action" value="schema">
        <p class="muted">Criar (ou atualizar) as tabelas na base de dados configurada.</p>
        <button type="submit">Criar tabelas</button>
      </form>
    <?php else: ?>
      <p class="muted">
        Crie primeiro a base de dados e o utilizador no cPanel
        (<b>MySQL® Databases</b>), atribuindo <b>ALL PRIVILEGES</b> ao utilizador.
        Depois preencha aqui os mesmos dados.
      </p>
      <form method="post">
        <input type="hidden" name="action" value="config">
        <input type="hidden" name="step" value="2">
        <div class="grid">
          <label>Servidor <input type="text" name="host" value="localhost" required></label>
          <label>Porto <input type="number" name="port" value="3306" required></label>
        </div>
        <label>Nome da base de dados <input type="text" name="name" required placeholder="ex.: setronix_planeamento"></label>
        <label>Utilizador <input type="text" name="user" required placeholder="ex.: setronix_app"></label>
        <label>Palavra-passe <input type="password" name="pass"></label>
        <h3>Identificação</h3>
        <div class="grid">
          <label>Organização <input type="text" name="org" value="Setronix"></label>
          <label>Nome da aplicação <input type="text" name="appname" value="Planeamento Operacional de Obras"></label>
        </div>
        <button type="submit">Testar ligação e gravar</button>
      </form>
    <?php endif; ?>
  </div>

<?php elseif ($step === 3): ?>
  <div class="card">
    <h2>3. Administrador inicial</h2>
    <p class="muted">
      Esta conta terá acesso total: gestão de utilizadores, listas base, importações,
      log de alterações e backups. No primeiro início de sessão será obrigatório ativar o MFA.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="admin">
      <input type="hidden" name="step" value="3">
      <div class="grid">
        <label>Nome de utilizador <input type="text" name="username" required pattern="[a-z0-9._\-]{3,64}" placeholder="ex.: admin"></label>
        <label>E-mail <input type="email" name="email" required></label>
      </div>
      <label>Nome completo <input type="text" name="full_name" required></label>
      <label>Palavra-passe <input type="password" name="password" required minlength="10"></label>
      <p class="muted">Mínimo 10 caracteres, com pelo menos uma letra e um algarismo.</p>
      <button type="submit">Criar administrador</button>
    </form>
    <p class="muted" style="margin-top:12px">
      Se as tabelas ainda não existirem, volte ao
      <a href="index.php?step=2">passo 2</a> e execute "Criar tabelas".
    </p>
  </div>

<?php elseif ($step === 4): ?>
  <div class="card">
    <h2>4. Listas base (opcional)</h2>
    <p class="muted">
      Envie o ficheiro <i>LISTA DE SELEÇÃO.xlsx</i> para preencher clientes, projetos,
      gestores, supervisores, chefes de equipa, ajudantes e tarefas tipo.
      Pode fazê-lo mais tarde em <b>Administração → Importar dados</b>.
    </p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="seed">
      <input type="hidden" name="step" value="4">
      <label>Ficheiro (.xlsx ou .csv) <input type="file" name="file" accept=".xlsx,.csv" required></label>
      <button type="submit">Importar</button>
      <a href="index.php?step=5"><button type="button" class="ghost">Saltar este passo</button></a>
    </form>
  </div>

<?php else: ?>
  <div class="card">
    <h2>5. Concluir</h2>
    <?php if (!empty($_SESSION['seed_stats'])):
        $s = $_SESSION['seed_stats']; unset($_SESSION['seed_stats']); ?>
      <div class="alert ok">
        Listas importadas: <?= (int)$s['added'] ?> valores novos a partir de <?= (int)$s['rows'] ?> linhas.
      </div>
    <?php endif; ?>
    <p class="muted">
      Ao concluir, o assistente fica bloqueado. Depois disso apague a pasta
      <code>install/</code> do servidor.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="finish">
      <button type="submit">Concluir instalação</button>
    </form>
  </div>
<?php endif; ?>

</div>
</body>
</html>
