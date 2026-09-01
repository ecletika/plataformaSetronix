<?php
/**
 * Assistente de instalação.
 *
 * Passos:
 *   1. Verificação do ambiente (PHP, extensões, permissões)
 *   2. Dados da base de dados → escreve config.php e cria as tabelas
 *   3. Criação do primeiro administrador
 *   4. Conclusão
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
        ['Escrita na raiz da aplicação', is_writable(APP_ROOT), 'criação do config.php'],
        ['Escrita em storage/',        is_dir($storage) && is_writable($storage), 'ficheiros das aplicações'],
    ];
}

/**
 * Traduz os erros de ligação do MySQL em instruções concretas para o cPanel,
 * que é onde a base de dados e o utilizador têm mesmo de ser criados.
 */
function db_error_hint(PDOException $ex, array $db): string
{
    $code = (string)$ex->getCode();
    $msg  = $ex->getMessage();

    // Prefixo da conta cPanel, deduzido do caminho da home.
    $conta = basename(dirname(APP_ROOT));
    $exemplo = preg_match('/^[a-z0-9]+$/i', $conta) ? $conta . '_' : 'conta_';

    if (strpos($msg, '1045') !== false) {
        return 'Utilizador ou palavra-passe da base de dados incorretos (o MySQL recusou "'
            . $db['user'] . '").'
            . ' No cPanel os nomes levam sempre o prefixo da conta — algo como "' . $exemplo . 'app",'
            . ' não "root" nem "sa".'
            . ' Crie a base de dados e o utilizador em cPanel → MySQL® Databases,'
            . ' associe-os com ALL PRIVILEGES, e copie aqui os nomes exatamente como o cPanel os mostra.';
    }
    if (strpos($msg, '1049') !== false) {
        return 'A base de dados "' . $db['name'] . '" não existe.'
            . ' Crie-a primeiro em cPanel → MySQL® Databases; o nome final ficará com o prefixo'
            . ' da conta, por exemplo "' . $exemplo . 'planeamento".';
    }
    if (strpos($msg, '2002') !== false || strpos($msg, '2003') !== false) {
        return 'Não foi possível contactar o servidor MySQL em "' . $db['host'] . ':' . $db['port'] . '".'
            . ' Em alojamento cPanel o servidor é quase sempre "localhost" na porta 3306.';
    }
    if (strpos($msg, '1044') !== false) {
        return 'O utilizador "' . $db['user'] . '" existe mas não tem acesso à base de dados "'
            . $db['name'] . '".'
            . ' No cPanel → MySQL® Databases, secção "Add User to Database", associe os dois'
            . ' e atribua ALL PRIVILEGES.';
    }
    return 'Não foi possível ligar à base de dados: ' . $msg;
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
    'apps' => [
        'max_mb' => 8,
    ],
    'paths' => [
        'storage' => __DIR__ . '/storage',
        'apps'    => __DIR__ . '/storage/apps',
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
            try {
                $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->query('SELECT 1');
            } catch (PDOException $ex) {
                throw new RuntimeException(db_error_hint($ex, $db));
            }

            write_config(
                $db,
                bin2hex(random_bytes(32)),
                trim((string)($_POST['org'] ?? 'Setronix')) ?: 'Setronix',
                trim((string)($_POST['appname'] ?? 'Plataforma Setronix')) ?: 'Plataforma Setronix'
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

        if ($action === 'finish') {
            if (!is_dir(APP_ROOT . '/storage/apps')) {
                mkdir(APP_ROOT . '/storage/apps', 0750, true);
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
  <p class="muted">Contas, autenticação e aplicações HTML · configuração inicial do servidor</p>

  <div class="steps">
    <?php foreach ([1 => 'Ambiente', 2 => 'Base de dados', 3 => 'Administrador', 4 => 'Concluir'] as $n => $label): ?>
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
      <li>Permissões: <code>config.php</code> = 640, <code>storage/</code> e <code>storage/apps/</code> = 750.</li>
      <li>Certificado SSL ativo e redirecionamento para HTTPS ligado.</li>
      <li>Backup periódico da base de dados e da pasta <code>storage/apps/</code> (cPanel &rarr; Backup).</li>
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
          <label>Nome da plataforma <input type="text" name="appname" value="Plataforma Setronix"></label>
        </div>
        <button type="submit">Testar ligação e gravar</button>
      </form>
    <?php endif; ?>
  </div>

<?php elseif ($step === 3): ?>
  <div class="card">
    <h2>3. Administrador inicial</h2>
    <p class="muted">
      Esta conta terá acesso total: gestão de utilizadores, envio das aplicações HTML e
      log de alterações. No primeiro início de sessão será obrigatório ativar o MFA.
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

<?php else: ?>
  <div class="card">
    <h2>4. Concluir</h2>
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
