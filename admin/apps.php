<?php
/**
 * Gestão das aplicações HTML: enviar, substituir, repor versões, remover.
 *
 * Cada aplicação é um ficheiro .html autónomo. Substituir a aplicação é
 * simplesmente enviar um ficheiro novo: fica como versão ativa e a
 * anterior continua guardada, pronta a ser reposta.
 */

define('URL_PREFIX', '../');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/apps.php';
require_once __DIR__ . '/../lib/dados.php';
require_once __DIR__ . '/../lib/rh.php';
require_once __DIR__ . '/../lib/layout.php';

$me = require_login('apps.manage');

/**
 * Avisa o administrador do que mudou na declaração de campos.
 *
 * Não impede o envio nem altera tabelas: uma página HTML vem de fora e
 * não manda em ALTER TABLE. Os campos novos ficam registados e os seus
 * valores guardados na coluna "extras", à espera de decisão — nada se
 * perde e nada acontece às escondidas.
 */
function avisar_campos(?array $campos, int $appId, string $nome): void
{
    if (!$campos) {
        return;
    }
    $diz = static function (array $lista): string {
        $t = array_map(static fn($c) => $c['colecao'] . '.' . $c['campo'], $lista);
        return implode(', ', array_slice($t, 0, 12)) . (count($t) > 12 ? ' (e mais ' . (count($t) - 12) . ')' : '');
    };

    if ($campos['novos'] && $campos['conhecidos'] === 0) {
        flash('ok', 'Esta versão declara ' . count($campos['novos']) . ' campo(s) para guardar na '
            . 'base de dados. Ficaram registados: ' . $diz($campos['novos']) . '.');
        return;
    }
    if ($campos['novos']) {
        $msg = 'Atenção: esta versão traz ' . count($campos['novos']) . ' campo(s) que a versão '
             . 'anterior não tinha — ' . $diz($campos['novos']) . '.';
        flash('warn', $msg);
    }
    if ($campos['sql']) {
        $n = count($campos['sql']);
        $t = count($campos['tabelas_novas']);
        flash('warn', 'Falta espaço na base de dados para ' . ($t ? $t . ' tabela(s) e ' : '')
            . ($n - $t) . ' coluna(s). O SQL está escrito e pronto — veja "Estrutura de dados" '
            . 'aqui em baixo e carregue em "Aplicar" para o correr. Até lá os valores ficam '
            . 'guardados na coluna "extras": chegam à aplicação na mesma, mas não dão para '
            . 'pesquisa nem para relatórios.');
    }
    if ($campos['desaparecidos']) {
        flash('warn', 'Esta versão deixou de declarar ' . count($campos['desaparecidos'])
            . ' campo(s) — ' . $diz($campos['desaparecidos']) . '. Os dados já gravados ficam '
            . 'onde estão; não são apagados.');
    }
}

$error  = '';
$openId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $app    = $id ? app_find($id) : null;

    try {
        if ($action === 'create') {
            $campos = null;
            $new = app_create(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['description'] ?? ''),
                $_FILES['file'] ?? [],
                $campos
            );
            audit('create', 'app', $new['id'], 'Aplicação criada: ' . $new['name']);
            flash('ok', 'Aplicação "' . $new['name'] . '" publicada.');
            avisar_campos($campos, (int)$new['id'], $new['name']);
            redirect('apps.php?id=' . (int)$new['id']);
        }

        if (!$app) {
            throw new RuntimeException('Aplicação não encontrada.');
        }

        if ($action === 'upload') {
            $campos = null;
            $v = app_store_version($id, $_FILES['file'] ?? [], (string)($_POST['notes'] ?? ''), $campos);
            audit('update', 'app', $id, 'Nova versão (' . (int)$v['version'] . ') de ' . $app['name']);
            flash('ok', 'Versão ' . (int)$v['version'] . ' publicada. Os utilizadores passam a ver esta.');
            avisar_campos($campos, $id, (string)$app['name']);
            redirect('apps.php?id=' . $id . '&sec=versoes');
        }

        if ($action === 'estrutura') {
            $manifesto = dados_manifesto_da_app($app);
            if (!$manifesto) {
                throw new RuntimeException('Esta aplicação não declara campos.');
            }
            $r = dados_aplicar_sql($id, $manifesto, (int)$me['id']);
            if ($r['feitos']) {
                flash('ok', count($r['feitos']) . ' alteração(ões) aplicadas à base de dados. '
                    . 'Os campos passam a ter coluna própria.');
            }
            foreach ($r['falhados'] as $f) {
                flash('warn', 'Falhou: ' . $f['sql'] . ' — ' . $f['erro']);
            }
            if (!$r['feitos'] && !$r['falhados']) {
                flash('ok', 'Não havia nada por aplicar.');
            }
            redirect('apps.php?id=' . $id . '&sec=estrutura');
        }

        if ($action === 'apagar_dados') {
            if (strtolower(trim((string)($_POST['confirm'] ?? ''))) !== strtolower((string)$app['name'])) {
                throw new RuntimeException('Para apagar os dados, escreva o nome exacto da aplicação.');
            }
            $r = dados_apagar_tudo($id);
            $total = array_sum($r);
            audit('delete', 'app', $id, 'Dados apagados: ' . $app['name'] . ' (' . $total . ' linhas)',
                  $r, null, (int)$me['id'], $me['username']);
            flash('warn', $total . ' linha(s) apagadas. A estrutura ficou de pé: as tabelas e as '
                . 'colunas continuam lá, prontas a receber dados novos.');
            redirect('apps.php?id=' . $id . '&sec=estrutura');
        }

        if ($action === 'rh_mapa') {
            $f = $_FILES['mapa'] ?? [];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Escolha um ficheiro .xlsx para enviar.');
            }
            if (strtolower((string)pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('O mapa de atividade tem de ser um ficheiro .xlsx.');
            }
            // O ficheiro fica no sítio temporário e não é guardado: o que
            // interessa é o que vai lá dentro, e isso passa para tabelas.
            // O nome do ficheiro vem do computador de quem envia e pode
            // não ser UTF-8 — um acento em Windows-1252 chega aqui como
            // um byte solto e faz a gravação rebentar.
            $nomeFicheiro = mb_substr(basename(to_utf8((string)$f['name'])), 0, 255);

            $mapa = rh_interpretar((string)$f['tmp_name']);
            $reg  = rh_gravar($id, $mapa, $nomeFicheiro,
                              hash_file('sha256', (string)$f['tmp_name']), (int)$me['id']);

            audit('update', 'app', $id, 'Mapa de atividade importado: '
                . count($mapa['funcionarios']) . ' funcionários, ' . $mapa['dias'] . ' dias',
                  null, ['ficheiro' => $nomeFicheiro, 'relatorio' => $mapa['data_relatorio']],
                  (int)$me['id'], $me['username']);

            flash('ok', 'Mapa importado: ' . count($mapa['funcionarios']) . ' funcionários, '
                . $mapa['dias'] . ' dias marcados, ' . count($mapa['feriados']) . ' feriados. '
                . 'Substituiu o mapa anterior.');
            foreach (array_slice($mapa['avisos'], 0, 5) as $av) {
                flash('warn', $av);
            }
            if (count($mapa['avisos']) > 5) {
                flash('warn', 'E mais ' . (count($mapa['avisos']) - 5) . ' aviso(s). '
                    . 'Estão guardados no registo desta importação.');
            }
            redirect('apps.php?id=' . $id . '&sec=mapa');
        }

        if ($action === 'edit') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Indique o nome da aplicação.');
            }
            $desc   = trim((string)($_POST['description'] ?? ''));
            $active = isset($_POST['is_active']) ? 1 : 0;
            q('UPDATE apps SET name = ?, description = ?, is_active = ?, slug = ? WHERE id = ?', [
                mb_substr($name, 0, 160),
                $desc === '' ? null : mb_substr($desc, 0, 500),
                $active,
                app_make_slug($name, $id),
                $id,
            ]);
            audit('update', 'app', $id, 'Aplicação atualizada: ' . $name, $app,
                  ['name' => $name, 'description' => $desc, 'is_active' => $active]);
            flash('ok', 'Aplicação atualizada.');
            redirect('apps.php?id=' . $id . '&sec=dados');
        }

        if ($action === 'access') {
            app_set_users($id, (array)($_POST['users'] ?? []));
            $n = count(app_user_ids($id));

            // Quem ficou com mais do que uma aplicação e sem nenhuma escolhida
            // precisa que alguém decida qual abre ao entrar.
            $porEscolher = [];
            foreach (q_all('SELECT id, username, role FROM users WHERE is_active = 1') as $uu) {
                $est = app_sync_default((int)$uu['id'],
                                        in_array($uu['role'], ['admin', 'gestor'], true));
                if ($est['precisa_escolher']) {
                    $porEscolher[] = $uu['username'];
                }
            }
            if ($porEscolher) {
                flash('warn', 'Falta escolher a aplicação que abre ao entrar para: '
                    . implode(', ', $porEscolher)
                    . '. Faça-o em Utilizadores, na ficha de cada um.');
            }
            audit('update', 'app', $id, 'Acesso a ' . $app['name'] . ': '
                  . ($n === 0 ? 'todos os utilizadores' : $n . ' utilizador(es)'));
            flash('ok', $n === 0
                ? 'A aplicação passa a estar visível para todos os utilizadores.'
                : 'Acesso reservado a ' . $n . ' utilizador(es).');
            redirect('apps.php?id=' . $id . '&sec=acessos');
        }

        if ($action === 'rollback') {
            $v = app_rollback($id, (int)($_POST['version_id'] ?? 0));
            audit('update', 'app', $id, 'Reposta a versão ' . (int)$v['version'] . ' de ' . $app['name']);
            flash('ok', 'A versão ' . (int)$v['version'] . ' voltou a ser a versão ativa.');
            redirect('apps.php?id=' . $id . '&sec=versoes');
        }

        if ($action === 'delete_version') {
            $v = app_version_delete($id, (int)($_POST['version_id'] ?? 0));
            audit('delete', 'app', $id, 'Apagada a versão ' . (int)$v['version'] . ' de ' . $app['name']);
            flash('ok', 'Versão ' . (int)$v['version'] . ' apagada.');
            redirect('apps.php?id=' . $id . '&sec=versoes');
        }

        if ($action === 'delete') {
            if (strtolower(trim((string)($_POST['confirm'] ?? ''))) !== strtolower($app['name'])) {
                throw new RuntimeException('Para apagar, escreva o nome exato da aplicação na caixa de confirmação.');
            }
            app_delete($id);
            audit('delete', 'app', $id, 'Aplicação apagada: ' . $app['name'], $app);
            flash('warn', 'Aplicação "' . $app['name'] . '" apagada, com todas as versões.');
            redirect('apps.php');
        }

        throw new RuntimeException('Ação desconhecida.');
    } catch (Throwable $ex) {
        $error  = $ex->getMessage();
        $openId = $id ?: $openId;
    }
}

$apps = apps_all(false);
$open = $openId ? app_find($openId) : null;

// Depois de uma submissão falhada, o formulário volta a ser desenhado com o
// que foi escrito. O ficheiro em si não pode ser reposto — o browser não
// deixa preencher um <input type="file"> — mas o resto fica.
$failed = $error !== '' ? (string)($_POST['action'] ?? '') : '';
$novo = [
    'name'        => $failed === 'create' ? (string)($_POST['name'] ?? '') : '',
    'description' => $failed === 'create' ? (string)($_POST['description'] ?? '') : '',
];

layout_head('Aplicações', 'app', '../');
?>
<div class="wrap">
<?php admin_nav('apps'); ?>

<?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

<?php if (!$open): ?>
<div class="card">
  <h2>Aplicações publicadas</h2>
  <p class="muted">
    Uma aplicação é um único ficheiro <code>.html</code> — com o CSS e o JavaScript lá dentro.
    Envie-o aqui e fica imediatamente disponível para os utilizadores, sem intervenção nossa.
    Máximo <?= e(human_bytes(apps_max_bytes())) ?> por ficheiro.
  </p>
  <table>
    <thead><tr><th>Nome</th><th>Versão</th><th>Tamanho</th><th>Atualizada</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($apps as $a): $v = app_current_version($a); ?>
        <tr>
          <td>
            <b><?= e($a['name']) ?></b>
            <?php if (!empty($a['description'])): ?>
              <div class="muted"><?= e($a['description']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= $v ? (int)$v['version'] : '<span class="muted">—</span>' ?></td>
          <td class="muted"><?= $v ? e(human_bytes((int)$v['size_bytes'])) : '—' ?></td>
          <td class="muted mono"><?= e(substr((string)$a['updated_at'], 0, 16)) ?></td>
          <td>
            <?= (int)$a['is_active'] === 1
                ? '<span class="tag on">ativa</span>'
                : '<span class="tag off">oculta</span>' ?>
            <?php $nAcesso = count(app_user_ids((int)$a['id'])); ?>
            <?php if ($nAcesso): ?>
              <br><span class="tag gestor" title="Reservada a utilizadores escolhidos"><?= $nAcesso ?> utilizador(es)</span>
            <?php else: ?>
              <br><span class="tag leitor">todos</span>
            <?php endif; ?>
          </td>
          <td class="actions">
            <a class="btn" href="../app.php?id=<?= (int)$a['id'] ?>">Abrir</a>
            <a class="btn" href="apps.php?id=<?= (int)$a['id'] ?>">Gerir</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$apps): ?>
        <tr><td colspan="6" class="muted">Ainda não há aplicações. Crie a primeira abaixo.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($open):
    $versions = app_versions((int)$open['id']);
    $cur      = app_current_version($open);
    $ed = [
        'name'        => $failed === 'edit' ? (string)($_POST['name'] ?? '') : (string)$open['name'],
        'description' => $failed === 'edit' ? (string)($_POST['description'] ?? '') : (string)$open['description'],
        'is_active'   => $failed === 'edit' ? isset($_POST['is_active']) : (int)$open['is_active'] === 1,
    ];

    // Tudo o que a ficha mostra é calculado aqui em cima, porque o índice
    // precisa dos números antes de se saber que secção vai ser desenhada.
    $manifesto = dados_manifesto_da_app($open);
    $dif = $regs = $colecoes = $contagem = null;
    if ($manifesto) {
        $dif      = dados_diferencas((int)$open['id'], $manifesto);
        $regs     = dados_campos_registados((int)$open['id']);
        $colecoes = dados_colecoes((int)$open['id']);
        $contagem = dados_contagens((int)$open['id']);
    }
    $rhMapa   = rh_mapa_atual((int)$open['id']);
    $nAcessos = (int)q_val('SELECT COUNT(*) FROM user_apps WHERE app_id = ?', [(int)$open['id']]);
    $nCampos  = 0;
    foreach ((array)$regs as $lista) {
        $nCampos += count($lista);
    }

    // As secções da ficha. Cada uma é um endereço: sem JavaScript, a
    // página volta para a mesma secção depois de guardar, e um separador
    // aberto de propósito continua a ser um endereço que se guarda.
    $seccoes = [
        'versoes'   => ['Versões', count($versions), false],
        'acessos'   => ['Quem pode abrir', $nAcessos ?: null, false],
        'dados'     => ['Nome e visibilidade', null, false],
    ];
    if ($manifesto) {
        $seccoes['estrutura'] = ['Estrutura de dados', $nCampos, (bool)$dif['sql']];
    }
    $seccoes['mapa'] = ['Mapa de atividade',
                        $rhMapa ? (int)$rhMapa['funcionarios'] : null,
                        !$rhMapa];

    $sec = (string)($_GET['sec'] ?? '');
    if (!isset($seccoes[$sec])) {
        $sec = 'versoes';
    }
    $liga = static function (string $k) use ($open): string {
        return 'apps.php?id=' . (int)$open['id'] . '&sec=' . $k;
    };
?>
<div class="card ficha-app">
  <div class="ficha-cab">
    <a class="voltar" href="apps.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
      Todas as aplicações
    </a>
    <h2><?= e($open['name']) ?></h2>
    <p class="muted">
      Endereço: <code>app.php?id=<?= (int)$open['id'] ?></code>
      <?= (int)$open['is_active'] === 1 ? '' : ' · <b>oculta dos utilizadores</b>' ?>
    </p>
  </div>

  <div class="ficha-corpo">
    <nav class="ficha-indice" aria-label="Secções da aplicação">
      <?php foreach ($seccoes as $k => [$rotulo, $conta, $alerta]): ?>
        <a href="<?= e($liga($k)) ?>"<?= $sec === $k ? ' aria-current="page"' : '' ?>>
          <span><?= e($rotulo) ?></span>
          <?php if ($alerta): ?>
            <span class="pinta" title="Há coisas por fazer aqui"></span>
          <?php elseif ($conta !== null): ?>
            <span class="conta"><?= (int)$conta ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="ficha-painel">

<?php if ($sec === 'versoes'): ?>
  <h3>Enviar nova versão</h3>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <label><span class="req">Ficheiro HTML</span>
      <input type="file" name="file" accept=".html,.htm" required></label>
    <label>Nota (opcional)
      <input type="text" name="notes" maxlength="255" placeholder="ex.: relatórios de produção"></label>
    <div class="actions">
      <button class="primary" type="submit">Publicar esta versão</button>
      <span class="muted">A versão anterior fica guardada e pode ser reposta.</span>
    </div>
  </form>

  <h3>Versões</h3>
  <div class="scroll">
    <table>
      <thead><tr><th style="width:66px">#</th><th>Ficheiro</th>
        <th style="width:120px">Enviada</th><th style="width:158px"></th></tr></thead>
      <tbody>
        <?php foreach ($versions as $v): $isCur = $cur && (int)$cur['id'] === (int)$v['id']; ?>
          <tr>
            <td><b><?= (int)$v['version'] ?></b>
                <?= $isCur ? ' <span class="tag on">ativa</span>' : '' ?></td>
            <td class="ficheiro">
              <span class="mono" title="<?= e($v['filename']) ?>"><?= e($v['filename']) ?></span>
              <?php if ((string)$v['notes'] !== ''): ?>
                <span class="nota-v"><?= e((string)$v['notes']) ?></span>
              <?php endif; ?>
            </td>
            <td class="muted mono quando">
              <?= e(substr((string)$v['created_at'], 0, 10)) ?>
              <span><?= e(substr((string)$v['created_at'], 11, 5)) ?>
                &middot; <?= e(human_bytes((int)$v['size_bytes'])) ?></span>
            </td>
            <td class="actions">
              <a class="btn" target="_blank" rel="noopener"
                 href="../app_raw.php?id=<?= (int)$open['id'] ?>&v=<?= (int)$v['id'] ?>">Pré-ver</a>
              <a class="btn" download
                 href="../app_raw.php?id=<?= (int)$open['id'] ?>&v=<?= (int)$v['id'] ?>&transferir=1"
                 title="Guardar este ficheiro no computador">Transferir</a>
              <?php if (!$isCur): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="rollback">
                  <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                  <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                  <button type="submit">Repor</button>
                </form>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Apagar a versão <?= (int)$v['version'] ?>? Não há forma de a recuperar.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_version">
                  <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                  <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                  <button class="danger" type="submit">Apagar</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php if ($sec === 'acessos'): ?>
  <h3>Quem pode abrir</h3>
  <?php
    $comAcesso = $failed === 'access'
        ? array_map('intval', (array)($_POST['users'] ?? []))
        : app_user_ids((int)$open['id']);
    $utilizadores = q_all('SELECT id, username, full_name, role, is_active
                             FROM users ORDER BY is_active DESC, full_name');
  ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="access">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <p class="muted" style="margin:0 0 8px">
      <?php if (!$comAcesso): ?>
        Neste momento <b>todos os utilizadores</b> veem esta aplicação.
        Assinale pessoas para a reservar só a elas.
      <?php else: ?>
        Reservada a <b><?= count($comAcesso) ?></b> utilizador(es).
        Desmarque todos para a voltar a abrir a toda a gente.
      <?php endif; ?>
      Quem gere aplicações vê sempre todas.
    </p>
    <?php
      $itens = [];
      foreach ($utilizadores as $u) {
          $itens[] = [
              'id'      => (int)$u['id'],
              'title'   => $u['full_name'],
              'sub'     => $u['username'] . ' · ' . (ROLES[$u['role']] ?? $u['role']),
              'mark'    => mb_strtoupper(mb_substr($u['full_name'], 0, 1)),
              'granted' => in_array((int)$u['id'], $comAcesso, true),
              'note'    => (int)$u['is_active'] === 1 ? '' : 'inativo',
          ];
      }
      transfer_list('users', $itens, [
          'left'        => 'Não vê esta aplicação',
          'right'       => 'Pode abrir',
          'empty_left'  => 'Toda a gente tem acesso.',
          'empty_right' => 'Ninguém escolhido: a aplicação está aberta a todos.',
          'hint'        => 'Com a coluna da direita vazia, a aplicação fica visível para '
                         . '<b>todos os utilizadores</b>. Assim que lá estiver alguém, passa a '
                         . 'ser só dessas pessoas. Quem gere aplicações vê-a sempre.',
      ]);
    ?>
    <div class="actions" style="margin-top:10px">
      <button class="primary" type="submit">Guardar acesso</button>
    </div>
  </form>

<?php endif; ?>

<?php if ($sec === 'dados'): ?>
  <h3>Dados da aplicação</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <div class="grid2">
      <label><span class="req">Nome</span>
        <input type="text" name="name" maxlength="160" required value="<?= e($ed['name']) ?>"></label>
      <label>Descrição
        <input type="text" name="description" maxlength="500" value="<?= e($ed['description']) ?>"></label>
    </div>
    <label style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="is_active" style="width:auto;margin:0"
             <?= $ed['is_active'] ? 'checked' : '' ?>>
      Visível para os utilizadores
    </label>
    <button class="primary" type="submit">Guardar</button>
  </form>

  <h3>Apagar</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <p class="muted">
      Apaga a aplicação e todas as versões do servidor. Para ocultar sem apagar, desmarque
      "Visível para os utilizadores" acima.
    </p>
    <label>Escreva <code><?= e($open['name']) ?></code> para confirmar
      <input type="text" name="confirm" autocomplete="off"></label>
    <button class="danger" type="submit">Apagar definitivamente</button>
  </form>
<?php endif; ?>

<?php if ($sec === 'estrutura' && $manifesto): ?>
  <h3>Estrutura de dados</h3>
  <p class="muted">
    O que esta aplicação declara guardar, e o que existe na base de dados para o receber.
  </p>

  <?php if ($dif['sql']): ?>
    <div class="alert warn" style="margin-top:14px">
      <b>Falta espaço para <?= count($dif['sql']) ?> coisa(s).</b>
      Até isto ser aplicado, os valores desses campos ficam guardados na coluna
      <code>extras</code> — chegam à aplicação na mesma, mas não dão para pesquisa
      nem para relatórios feitos na base de dados.
    </div>

    <p class="nota" style="margin:12px 0 6px">
      Este é o SQL que vai correr. Só cria tabelas e acrescenta colunas: não apaga
      nem altera nada do que já existe.
    </p>
    <pre class="sql"><?php foreach ($dif['sql'] as $p) { echo e($p['sql']) . ";\n\n"; } ?></pre>

    <form method="post" onsubmit="return confirm('Vai correr este SQL na base de dados. Continuar?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="estrutura">
      <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
      <button class="primary" type="submit">Aplicar à base de dados</button>
    </form>
  <?php else: ?>
    <div class="alert ok" style="margin-top:14px">
      A base de dados tem tudo o que esta versão declara. Não há nada por aplicar.
    </div>
  <?php endif; ?>

  <?php foreach ($regs as $colecao => $lista): ?>
    <h3 style="margin:20px 0 8px"><?= e($colecao) ?>
      <span class="muted" style="font-weight:400;font-size:13px">
        <?php if (isset($contagem[$colecao])): ?>
          &middot; <b><?= (int)$contagem[$colecao] ?></b> linha(s) gravadas
        <?php endif; ?>
        <?php if ($colecao === 'definicoes'): ?>
          &middot; <code>app_definicoes</code> (chave e valor)
        <?php elseif (isset($colecoes[$colecao])): ?>
          &middot; <code><?= e($colecoes[$colecao]['tabela']) ?></code>
        <?php else: ?>
          &middot; <b>sem tabela</b>
        <?php endif; ?>
      </span>
    </h3>
    <div class="scroll">
      <table>
        <thead><tr><th>Campo</th><th>Tipo</th><th>Coluna</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $c):
            $temColuna = $colecao === 'definicoes'
                       || $c['coluna'] !== null
                       || isset(DADOS_COLUNAS[$colecao][$c['campo']]); ?>
          <tr>
            <td class="mono"><?= e($c['campo']) ?></td>
            <td class="muted"><?= e($c['tipo']) ?></td>
            <td>
              <?php if ($temColuna): ?>
                <span class="tag on">tem coluna</span>
              <?php else: ?>
                <span class="tag off">em <code>extras</code></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>

  <details class="gaveta" style="width:auto;margin-top:20px">
    <summary class="perigo">Apagar todos os dados desta aplicação</summary>
    <div class="gaveta-corpo">
      <p class="nota" style="margin:0 0 8px">
        Apaga as linhas de todas as coleções — o trabalho de toda a gente, não só o seu.
        <b>Não há como desfazer.</b> A estrutura fica de pé: tabelas, colunas e o registo
        de campos continuam lá, prontos a receber dados novos.
      </p>
      <p class="nota" style="margin:0 0 8px">
        É aqui e não dentro da aplicação de propósito. Um botão dentro da página é
        carregado por qualquer pessoa que a abra.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apagar_dados">
        <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
        <input type="text" name="confirm" autocomplete="off" style="margin:0;max-width:260px"
               placeholder="escreva <?= e($open['name']) ?>" aria-label="Confirmar o nome da aplicação">
        <button class="danger" type="submit">Apagar os dados</button>
      </form>
    </div>
  </details>

  <p class="nota" style="margin-top:16px">
    Quem escreve a declaração é quem faz a aplicação. Se pedir uma versão nova ao
    ChatGPT, peça que mantenha o bloco <code>setronix-dados</code> e que lhe acrescente
    os campos novos — é por aí que a plataforma sabe que existem.
  </p>
<?php endif; ?>

<?php if ($sec === 'mapa'): ?>
  <h3>Mapa de atividade</h3>
  <p class="muted">
    O ficheiro que sai do sistema de recursos humanos, com o ano inteiro: um funcionário
    por linha, um dia por coluna. Traz férias, baixas, faltas e os saldos de férias —
    <b>não traz horas</b>.
  </p>

  <?php if ($rhMapa): ?>
    <?php $avisos = json_decode((string)$rhMapa['avisos'], true) ?: []; ?>
    <div class="ficha-grid" style="margin-top:14px">
      <div class="dbox">
        <p class="t">Mapa em uso</p>
        <p class="mono" style="font-size:12px"><?= e($rhMapa['ficheiro']) ?></p>
        <p>
          Relatório de <b><?= e((string)$rhMapa['data_relatorio'] ?: '—') ?></b>,
          a cobrir de <?= e((string)$rhMapa['periodo_ini']) ?>
          a <?= e((string)$rhMapa['periodo_fim']) ?>.
        </p>
        <p class="muted" style="font-size:12px">
          Importado em <?= e(substr((string)$rhMapa['criado_em'], 0, 16)) ?>
        </p>
      </div>
      <div class="dbox">
        <p class="t">O que entrou</p>
        <p>
          <b><?= (int)$rhMapa['funcionarios'] ?></b> funcionários ·
          <b><?= (int)$rhMapa['dias'] ?></b> dias marcados ·
          <b><?= (int)$rhMapa['feriados'] ?></b> feriados
        </p>
        <?php if ($avisos): ?>
          <p class="muted" style="font-size:12px"><?= count($avisos) ?> aviso(s) na importação.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php $resumo = rh_resumo((int)$open['id']); if ($resumo): ?>
      <h3 style="margin:20px 0 8px">Dias por estado</h3>
      <div class="scroll">
        <table>
          <thead><tr><th>Estado</th><th style="width:120px">Dias</th></tr></thead>
          <tbody>
          <?php foreach ($resumo as $r): ?>
            <tr>
              <td><?= e(RH_ESTADOS[$r['estado']] ?? $r['estado']) ?>
                <?php if (in_array($r['estado'], RH_AUSENTE, true)): ?>
                  <span class="tag off">ausência</span>
                <?php endif; ?>
              </td>
              <td class="mono"><?= (int)$r['total'] ?><?php if ((int)$r['meios']): ?>
                <span class="muted">(<?= (int)$r['meios'] ?> meios dias)</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php
      // A semana que se está a consultar. Por omissão, a de hoje.
      $semana = (string)($_GET['semana'] ?? '');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $semana)) {
          $semana = date('Y-m-d');
      }
      $seg = date('Y-m-d', strtotime('monday this week', strtotime($semana)));
      $sem = rh_ausencias_semana((int)$open['id'], $seg);
      $diasSemana = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
    ?>
    <h3 style="margin:22px 0 8px">Quem está fora, semana a semana</h3>
    <p class="nota" style="margin:0 0 10px">
      Escolha uma data e vê a semana dela. Quem não tiver <b>um único dia livre</b> na semana
      não aparece nas listas do planeamento; quem tiver pelo menos um continua a aparecer.
    </p>
    <form method="get" class="actions" style="align-items:flex-end;margin-bottom:12px">
      <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
      <label style="margin:0">Semana de
        <input type="date" name="semana" value="<?= e($seg) ?>">
      </label>
      <button type="submit">Ver</button>
      <span class="muted" style="margin-left:auto">
        <?= count($sem['dias']) ?> dia(s) de trabalho:
        <?= e(implode(', ', array_map(static fn($d) => substr($d, 8, 2) . '/' . substr($d, 5, 2),
                                      $sem['dias']))) ?>
      </span>
    </form>

    <?php semana_ausencias($sem); ?>
  <?php else: ?>
    <div class="alert warn" style="margin-top:14px">
      Ainda não foi importado nenhum mapa para esta aplicação.
    </div>
  <?php endif; ?>

  <h3 style="margin:22px 0 8px"><?= $rhMapa ? 'Substituir o mapa' : 'Importar o mapa' ?></h3>
  <p class="nota" style="margin:0 0 10px">
    O ficheiro é um retrato do ano inteiro, não um acrescento: <b>cada importação substitui
    a anterior por completo</b>. O ficheiro em si não fica guardado — o que fica são os
    dados que vêm lá dentro.
  </p>
  <form method="post" enctype="multipart/form-data" class="actions" style="align-items:flex-end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="rh_mapa">
    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
    <label style="flex:1;min-width:240px;max-width:420px;margin:0">Ficheiro .xlsx
      <input type="file" name="mapa" accept=".xlsx" required>
    </label>
    <button class="primary" type="submit">Importar</button>
  </form>
<?php endif; ?>

    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$open): ?>
<div class="card">
  <h2>Nova aplicação</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="grid2">
      <label><span class="req">Nome</span>
        <input type="text" name="name" maxlength="160" required value="<?= e($novo['name']) ?>"
               placeholder="ex.: Planeamento de obras"></label>
      <label>Descrição
        <input type="text" name="description" maxlength="500" value="<?= e($novo['description']) ?>"
               placeholder="uma linha, mostrada no ecrã inicial"></label>
    </div>
    <label><span class="req">Ficheiro HTML</span>
      <input type="file" name="file" accept=".html,.htm" required></label>
    <button class="primary" type="submit">Publicar</button>
  </form>
</div>

</div>
<?php endif; ?>
<?php layout_foot(); ?>
