<?php
/**
 * Dados das aplicações alojadas.
 *
 * Até aqui a plataforma guardava o ficheiro HTML e mais nada: cada
 * aplicação escrevia no localStorage do browser de quem a abria. Isso
 * significa que os dados se perdiam ao limpar o browser, não passavam de
 * um computador para o outro, e duas pessoas nunca viam o mesmo.
 *
 * Este ficheiro põe os dados no servidor. A aplicação declara, num bloco
 * JSON dentro do próprio HTML, que coleções guarda e que campos tem cada
 * uma. A plataforma lê essa declaração quando uma versão é enviada,
 * compara-a com o que já conhecia, avisa do que mudou e escreve o SQL
 * que falta — a criação da tabela nova ou as colunas novas.
 *
 * O SQL é mostrado e só corre quando um administrador carregar no botão.
 * Um ficheiro HTML vem de fora — hoje do ChatGPT, amanhã de quem for — e
 * não pode mandar sozinho na estrutura da base de dados. O que ele pode
 * pedir é limitado a duas coisas: criar uma tabela e acrescentar colunas.
 * Nunca apagar, nunca alterar o que já existe. E os nomes e os tipos
 * passam por uma lista fechada antes de chegarem ao SQL.
 *
 * Enquanto um campo não tiver coluna, o valor não se perde: fica na
 * coluna "extras" e volta a chegar à aplicação tal e qual.
 */

/** Onde vive a declaração dentro do HTML da aplicação. */
const DADOS_MARCA = 'setronix-dados';

/**
 * Coleções que já vinham com a plataforma, e a tabela de cada uma.
 *
 * As que forem criadas a partir de uma declaração ficam registadas em
 * app_colecoes; estas estão aqui porque existem desde o princípio.
 */
const DADOS_TABELAS = [
    'obras'        => 'app_obras',
    'planeamentos' => 'app_planeamentos',
];

/**
 * Campos com coluna própria de origem, por coleção.
 *
 * A chave é o nome que a aplicação usa em JavaScript; o valor é a coluna.
 * As colunas acrescentadas depois ficam registadas em app_campos.
 */
const DADOS_COLUNAS = [
    'obras' => [
        'uid'      => 'uid',
        'client'   => 'client',
        'project'  => 'project',
        'cost'     => 'cost',
        'costDesc' => 'cost_desc',
        'manager'  => 'manager',
        'fps'      => 'fps',
        'fpsEnd'   => 'fps_end',
        'value'    => 'valor',
        'closed'   => 'closed',
        'closedAt' => 'closed_at',
    ],
    'planeamentos' => [
        'uid'            => 'uid',
        'workUid'        => 'work_uid',
        'week'           => 'week',
        'supervisor'     => 'supervisor',
        'set1Leader'     => 'set1_leader',
        'set1Helper1'    => 'set1_helper1',
        'set1Helper2'    => 'set1_helper2',
        'set1Helper3'    => 'set1_helper3',
        'set2Leader'     => 'set2_leader',
        'set2Helper1'    => 'set2_helper1',
        'set2Helper2'    => 'set2_helper2',
        'set2Helper3'    => 'set2_helper3',
        'contractorName' => 'contractor_name',
        'conLeader'      => 'con_leader',
        'conHelper1'     => 'con_helper1',
        'conHelper2'     => 'con_helper2',
        'conHelper3'     => 'con_helper3',
        'progress'       => 'progress',
        'status'         => 'status',
        // "days" não é campo: é uma coleção filha, em app_planeamento_dias.
    ],
];

/** Tipo de cada campo de origem, para converter o que chega do browser. */
const DADOS_TIPOS = [
    'obras' => [
        'uid' => 'inteiro', 'value' => 'decimal', 'closed' => 'booleano',
        'fpsEnd' => 'data', 'closedAt' => 'data',
    ],
    'planeamentos' => [
        'uid' => 'inteiro', 'workUid' => 'inteiro', 'progress' => 'inteiro', 'week' => 'data',
    ],
];

/** Dias da semana aceites, pela ordem em que se lêem. */
const DADOS_DIAS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

/**
 * Tipos que uma declaração pode pedir, e a coluna que cada um gera.
 *
 * É uma lista fechada de propósito: o que vem no ficheiro HTML escolhe de
 * entre estas, nunca escreve o tipo à mão.
 */
const DADOS_TIPOS_SQL = [
    'texto'    => "VARCHAR(255) NOT NULL DEFAULT ''",
    'texto_longo' => 'TEXT NULL',
    'inteiro'  => 'INT NULL',
    'decimal'  => 'DECIMAL(14,2) NULL',
    'data'     => 'DATE NULL',
    'datahora' => 'DATETIME NULL',
    'booleano' => 'TINYINT(1) NOT NULL DEFAULT 0',
];

// ---------------------------------------------------------------------
// A declaração que vem dentro do HTML
// ---------------------------------------------------------------------

/**
 * Lê a declaração de campos de um HTML enviado.
 *
 * Procura <script type="application/json" id="setronix-dados">. É um
 * bloco JSON e não JavaScript de propósito: assim a plataforma lê-o com
 * json_decode e nunca tem de interpretar código de terceiros.
 *
 * @return array|null null quando o ficheiro não declara nada — nesse caso
 *                    é uma aplicação que continua a guardar no browser.
 */
function dados_manifesto(string $html): ?array
{
    $re = '~<script[^>]*\bid=["\']' . preg_quote(DADOS_MARCA, '~') . '["\'][^>]*>(.*?)</script>~is';
    if (!preg_match($re, $html, $m)) {
        return null;
    }
    $json = json_decode(trim($m[1]), true);
    if (!is_array($json) || !isset($json['colecoes']) || !is_array($json['colecoes'])) {
        return null;
    }
    return $json;
}

/** Campos declarados, achatados em "coleção" => ['campo' => tipo]. */
function dados_campos_declarados(array $manifesto): array
{
    $out = [];
    foreach ($manifesto['colecoes'] as $nome => $def) {
        if (!is_array($def) || !dados_nome_valido((string)$nome)) {
            continue;
        }
        $campos = isset($def['campos']) && is_array($def['campos']) ? $def['campos'] : [];
        $lista  = [];
        foreach ($campos as $campo => $tipo) {
            $tipo = is_string($tipo) ? $tipo : 'texto';
            $lista[(string)$campo] = isset(DADOS_TIPOS_SQL[$tipo]) ? $tipo : 'texto';
        }
        $out[(string)$nome] = $lista;
    }
    return $out;
}

/**
 * Nome aceitável para coleção, campo ou coluna.
 *
 * Tudo o que venha do ficheiro passa por aqui antes de chegar perto do
 * SQL. Letras, dígitos e underscore, a começar por letra: não há aspas,
 * espaços nem ponto e vírgula que sobrevivam a isto.
 */
function dados_nome_valido(string $n): bool
{
    return (bool)preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/', $n);
}

/** Nome da coluna para um campo: "costDesc" fica "cost_desc". */
function dados_coluna_para(string $campo): string
{
    $c = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $campo));
    $c = preg_replace('/[^a-z0-9_]/', '_', $c);
    $c = trim(preg_replace('/_+/', '_', $c), '_');
    // Não colidir com as colunas que todas as tabelas têm.
    if (in_array($c, ['id', 'app_id', 'extras', 'criado_em', 'alterado_em', 'alterado_por'], true)) {
        $c .= '_campo';
    }
    return substr($c, 0, 60);
}

// ---------------------------------------------------------------------
// O que a plataforma já conhece
// ---------------------------------------------------------------------

/** Coleções desta aplicação: as de origem mais as que foram criadas. */
function dados_colecoes(int $appId): array
{
    $out = [];
    foreach (DADOS_TABELAS as $colecao => $tabela) {
        $out[$colecao] = ['tabela' => $tabela, 'dias' => $colecao === 'planeamentos', 'origem' => true];
    }
    foreach (q_all('SELECT colecao, tabela FROM app_colecoes WHERE app_id = ?', [$appId]) as $r) {
        if (!isset($out[$r['colecao']])) {
            $out[$r['colecao']] = ['tabela' => $r['tabela'], 'dias' => false, 'origem' => false];
        }
    }

    // Uma tabela registada pode ter sido apagada à mão na base de dados.
    // Ler dela rebentaria a aplicação inteira; ignorá-la deixa-a a
    // funcionar, e o painel volta a propor a criação.
    $existem = [];
    foreach (q_all('SELECT table_name AS t FROM information_schema.tables
                     WHERE table_schema = DATABASE()') as $r) {
        $existem[$r['t']] = true;
    }
    foreach ($out as $colecao => $def) {
        if (!isset($existem[$def['tabela']])) {
            unset($out[$colecao]);
        }
    }
    return $out;
}

/** Campos com coluna, numa coleção: os de origem mais os acrescentados. */
function dados_colunas(int $appId, string $colecao): array
{
    static $cache = [];
    $ck = $appId . '|' . $colecao;
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $out = DADOS_COLUNAS[$colecao] ?? [];
    foreach (q_all('SELECT campo, coluna FROM app_campos
                     WHERE app_id = ? AND colecao = ? AND coluna IS NOT NULL', [$appId, $colecao]) as $r) {
        $out[$r['campo']] = $r['coluna'];
    }
    return $cache[$ck] = $out;
}

/** Tipo de um campo, para converter o valor que chega do browser. */
function dados_tipo(int $appId, string $colecao, string $campo): string
{
    static $cache = [];
    if (!isset($cache[$appId])) {
        $cache[$appId] = [];
        foreach (q_all('SELECT colecao, campo, tipo FROM app_campos WHERE app_id = ?', [$appId]) as $r) {
            $cache[$appId][$r['colecao']][$r['campo']] = $r['tipo'];
        }
    }
    return $cache[$appId][$colecao][$campo]
        ?? DADOS_TIPOS[$colecao][$campo]
        ?? 'texto';
}

/**
 * A declaração da versão que está no ar.
 *
 * @return array|null null quando a aplicação não tem ficheiro ou não
 *                    declara nada.
 */
function dados_manifesto_da_app(array $app): ?array
{
    require_once __DIR__ . '/apps.php';
    $v = app_current_version($app);
    if (!$v) {
        return null;
    }
    $caminho = app_version_path($v['storage_name']);
    if (!is_file($caminho)) {
        return null;
    }
    return dados_manifesto((string)file_get_contents($caminho));
}

// ---------------------------------------------------------------------
// Comparar a declaração com o que existe
// ---------------------------------------------------------------------

/**
 * O que muda quando esta versão entrar.
 *
 * Não grava nem altera nada: é o que se mostra ao administrador.
 *
 * @return array{novos:array, desaparecidos:array, conhecidos:int,
 *               tabelas_novas:array, sql:array}
 */
function dados_diferencas(int $appId, array $manifesto): array
{
    $declarados = dados_campos_declarados($manifesto);
    $colecoes   = dados_colecoes($appId);

    $registados = [];
    foreach (q_all('SELECT colecao, campo FROM app_campos WHERE app_id = ?', [$appId]) as $r) {
        $registados[$r['colecao']][$r['campo']] = true;
    }

    $novos = $desaparecidos = $tabelasNovas = $sql = [];

    foreach ($declarados as $colecao => $campos) {
        // "definicoes" é uma tabela de chave/valor: não leva colunas.
        $temTabela = isset($colecoes[$colecao]) || $colecao === 'definicoes';
        $colunas   = $temTabela && $colecao !== 'definicoes' ? dados_colunas($appId, $colecao) : [];

        if (!$temTabela) {
            $tabelasNovas[] = $colecao;
            $sql[] = dados_sql_criar_tabela($colecao, $campos);
        }

        foreach ($campos as $campo => $tipo) {
            if (!dados_nome_valido($campo)) {
                continue;
            }
            $temColuna = $temTabela && ($colecao === 'definicoes' || isset($colunas[$campo]));
            if (!isset($registados[$colecao][$campo])) {
                $novos[] = ['colecao' => $colecao, 'campo' => $campo, 'tipo' => $tipo,
                            'tem_coluna' => $temColuna];
            }
            if ($temTabela && $colecao !== 'definicoes' && !isset($colunas[$campo])) {
                $sql[] = dados_sql_acrescentar_coluna($colecoes[$colecao]['tabela'], $campo, $tipo);
            }
        }
    }

    foreach ($registados as $colecao => $campos) {
        foreach ($campos as $campo => $_) {
            if (!isset($declarados[$colecao][$campo])) {
                $desaparecidos[] = ['colecao' => $colecao, 'campo' => $campo];
            }
        }
    }

    $conhecidos = 0;
    foreach ($registados as $campos) {
        $conhecidos += count($campos);
    }

    return ['novos' => $novos, 'desaparecidos' => $desaparecidos, 'conhecidos' => $conhecidos,
            'tabelas_novas' => $tabelasNovas, 'sql' => $sql];
}

/**
 * Regista os campos declarados por uma versão.
 *
 * Os campos que desapareceram NÃO são apagados do registo: as linhas
 * antigas ainda os têm, e apagar o registo era esconder isso.
 */
function dados_registar_campos(int $appId, array $manifesto, ?int $versaoId = null): void
{
    foreach (dados_campos_declarados($manifesto) as $colecao => $campos) {
        $colunas = dados_colunas($appId, $colecao);
        foreach ($campos as $campo => $tipo) {
            if (!dados_nome_valido($campo)) {
                continue;
            }
            q(
                'INSERT INTO app_campos (app_id, colecao, campo, tipo, coluna, visto_em)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), visto_em = VALUES(visto_em),
                     coluna = COALESCE(VALUES(coluna), coluna)',
                [$appId, $colecao, $campo, $tipo, $colunas[$campo] ?? null, $versaoId]
            );
        }
    }
}

/**
 * Quantas linhas tem cada coleção.
 *
 * Serve para responder de relance à pergunta que aparece sempre que
 * alguém desconfia: "isto está mesmo a gravar?". Sem isto, a resposta
 * exigia ir à base de dados.
 */
function dados_contagens(int $appId): array
{
    $out = [];
    foreach (dados_colecoes($appId) as $colecao => $def) {
        $out[$colecao] = (int)q_val('SELECT COUNT(*) FROM ' . $def['tabela'] . ' WHERE app_id = ?',
                                    [$appId]);
    }
    $out['definicoes'] = (int)q_val('SELECT COUNT(*) FROM app_definicoes WHERE app_id = ?', [$appId]);
    return $out;
}

/** Campos registados de uma aplicação, agrupados por coleção. */
function dados_campos_registados(int $appId): array
{
    $out = [];
    foreach (q_all('SELECT colecao, campo, tipo, coluna FROM app_campos
                     WHERE app_id = ? ORDER BY colecao, campo', [$appId]) as $r) {
        $out[$r['colecao']][] = $r;
    }
    return $out;
}

// ---------------------------------------------------------------------
// O SQL que falta
// ---------------------------------------------------------------------

/** Nome da tabela de uma coleção criada a partir de uma declaração. */
function dados_tabela_para(string $colecao): string
{
    return 'app_' . substr(strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $colecao)), 0, 50);
}

/** CREATE TABLE de uma coleção nova. */
function dados_sql_criar_tabela(string $colecao, array $campos): array
{
    $tabela = dados_tabela_para($colecao);
    $linhas = [
        '  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        '  app_id       INT UNSIGNED NOT NULL',
        '  uid          INT UNSIGNED NOT NULL',
    ];
    foreach ($campos as $campo => $tipo) {
        if ($campo === 'uid' || !dados_nome_valido($campo)) {
            continue;
        }
        $linhas[] = '  ' . str_pad(dados_coluna_para($campo), 12) . ' ' . DADOS_TIPOS_SQL[$tipo];
    }
    $linhas[] = '  extras       LONGTEXT NULL';
    $linhas[] = '  criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
    $linhas[] = '  alterado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
    $linhas[] = '  alterado_por INT UNSIGNED NULL';
    $linhas[] = '  PRIMARY KEY (id)';
    $linhas[] = '  UNIQUE KEY uq_' . substr($colecao, 0, 40) . ' (app_id, uid)';
    $linhas[] = '  CONSTRAINT fk_' . substr($colecao, 0, 40)
              . '_app FOREIGN KEY (app_id) REFERENCES apps (id) ON DELETE CASCADE';

    return [
        'tipo'    => 'tabela',
        'colecao' => $colecao,
        'tabela'  => $tabela,
        'sql'     => "CREATE TABLE IF NOT EXISTS $tabela (\n" . implode(",\n", $linhas)
                   . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** ALTER TABLE ... ADD COLUMN de um campo novo. */
function dados_sql_acrescentar_coluna(string $tabela, string $campo, string $tipo): array
{
    $coluna = dados_coluna_para($campo);
    return [
        'tipo'    => 'coluna',
        'tabela'  => $tabela,
        'campo'   => $campo,
        'coluna'  => $coluna,
        'sql'     => "ALTER TABLE $tabela ADD COLUMN $coluna " . DADOS_TIPOS_SQL[$tipo],
    ];
}

/**
 * Corre o SQL em falta.
 *
 * Só cria tabelas e acrescenta colunas — as duas únicas formas que a
 * função sabe escrever. Não há aqui caminho para DROP nem para ALTER de
 * uma coluna que já exista, e o SQL não vem do ficheiro: é gerado a
 * partir de nomes e tipos que passaram pela lista fechada.
 *
 * @return array{feitos:array, falhados:array}
 */
function dados_aplicar_sql(int $appId, array $manifesto, ?int $userId): array
{
    $d = dados_diferencas($appId, $manifesto);
    $feitos = $falhados = [];

    foreach ($d['sql'] as $passo) {
        try {
            db()->exec($passo['sql']);

            if ($passo['tipo'] === 'tabela') {
                q('INSERT INTO app_colecoes (app_id, colecao, tabela) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE tabela = VALUES(tabela)',
                  [$appId, $passo['colecao'], $passo['tabela']]);
                // As colunas da tabela nova passam a estar registadas.
                foreach (dados_campos_declarados($manifesto)[$passo['colecao']] ?? [] as $campo => $tipo) {
                    if (!dados_nome_valido($campo)) {
                        continue;
                    }
                    q('INSERT INTO app_campos (app_id, colecao, campo, tipo, coluna) VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE coluna = VALUES(coluna)',
                      [$appId, $passo['colecao'], $campo, $tipo,
                       $campo === 'uid' ? 'uid' : dados_coluna_para($campo)]);
                }
            } else {
                q('UPDATE app_campos SET coluna = ? WHERE app_id = ? AND colecao = ? AND campo = ?',
                  [$passo['coluna'], $appId, dados_colecao_da_tabela($appId, $passo['tabela']),
                   $passo['campo']]);
            }
            $feitos[] = $passo;
        } catch (PDOException $ex) {
            $falhados[] = $passo + ['erro' => $ex->getMessage()];
        }
    }

    if ($feitos) {
        audit('update', 'app', $appId, 'Estrutura de dados actualizada: '
            . count($feitos) . ' alteração(ões)', null,
            ['sql' => array_column($feitos, 'sql')], $userId);
    }
    return ['feitos' => $feitos, 'falhados' => $falhados];
}

/** Coleção a que pertence uma tabela. */
function dados_colecao_da_tabela(int $appId, string $tabela): string
{
    foreach (dados_colecoes($appId) as $colecao => $def) {
        if ($def['tabela'] === $tabela) {
            return $colecao;
        }
    }
    return '';
}

// ---------------------------------------------------------------------
// Ler e gravar
// ---------------------------------------------------------------------

/** Converte uma linha da base de dados na forma que a aplicação espera. */
function dados_linha_para_app(int $appId, array $linha, string $colecao): array
{
    $out = [];
    foreach (dados_colunas($appId, $colecao) as $campo => $coluna) {
        if (!array_key_exists($coluna, $linha)) {
            continue;   // coluna registada mas ainda não criada
        }
        $v    = $linha[$coluna];
        $tipo = dados_tipo($appId, $colecao, $campo);
        if ($tipo === 'booleano') {
            $out[$campo] = (int)$v === 1;
        } elseif ($tipo === 'inteiro') {
            $out[$campo] = (int)$v;
        } elseif ($tipo === 'decimal') {
            $out[$campo] = $v === null ? '' : (string)(float)$v;
        } else {
            $out[$campo] = $v === null ? '' : (string)$v;
        }
    }
    // Campos que a aplicação enviou e ainda não têm coluna.
    $extras = json_decode((string)($linha['extras'] ?? ''), true);
    if (is_array($extras)) {
        foreach ($extras as $k => $v) {
            $out[(string)$k] = $v;
        }
    }
    return $out;
}

/** Tudo o que a aplicação precisa para arrancar. */
function dados_ler(int $appId): array
{
    $out = [];

    foreach (dados_colecoes($appId) as $colecao => $def) {
        $linhas = [];
        foreach (q_all('SELECT * FROM ' . $def['tabela'] . ' WHERE app_id = ? ORDER BY uid',
                       [$appId]) as $r) {
            $linhas[(int)$r['id']] = dados_linha_para_app($appId, $r, $colecao);
        }

        if ($def['dias'] && $linhas) {
            $dias = [];
            foreach (q_all('SELECT d.plano_id, d.dia, d.descricao
                              FROM app_planeamento_dias d
                              JOIN app_planeamentos p ON p.id = d.plano_id
                             WHERE p.app_id = ?', [$appId]) as $r) {
                $dias[(int)$r['plano_id']][$r['dia']] = (string)$r['descricao'];
            }
            foreach ($linhas as $id => $_) {
                // Pela ordem da semana, para sair sempre igual.
                $linhas[$id]['days'] = [];
                foreach (DADOS_DIAS as $d) {
                    if (isset($dias[$id][$d])) {
                        $linhas[$id]['days'][$d] = $dias[$id][$d];
                    }
                }
            }
        }
        $out[$colecao] = array_values($linhas);
    }

    $defs = [];
    foreach (q_all('SELECT chave, valor FROM app_definicoes WHERE app_id = ?', [$appId]) as $r) {
        $defs[$r['chave']] = $r['valor'];
    }
    $out['definicoes'] = (object)$defs;

    return $out;
}

/** Normaliza um valor para a coluna a que se destina. */
function dados_valor(string $tipo, $v)
{
    if ($tipo === 'booleano') {
        return ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
    }
    if ($tipo === 'inteiro') {
        return (int)$v;
    }
    if ($tipo === 'decimal') {
        $s = trim((string)$v);
        return $s === '' ? null : (float)str_replace(',', '.', $s);
    }
    if ($tipo === 'data') {
        $s = trim((string)$v);
        // A aplicação usa ISO (aaaa-mm-dd); qualquer outra coisa vira NULL,
        // que é honesto: melhor vazio do que uma data inventada.
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }
    if ($tipo === 'datahora') {
        $s = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $s) ? str_replace('T', ' ', substr($s, 0, 19)) : null;
    }
    if ($tipo === 'texto_longo') {
        return (string)$v;
    }
    return mb_substr(trim((string)$v), 0, 255);
}

/**
 * Grava tudo o que a aplicação enviou.
 *
 * A aplicação manda as coleções inteiras — é assim que ela própria
 * funciona do lado do browser. Aqui isso traduz-se em: inserir ou
 * actualizar o que veio, e apagar o que deixou de vir. Tudo numa
 * transacção, para nunca ficar meio gravado.
 *
 * Uma coleção que não vem no pedido não é uma coleção vazia: é uma
 * coleção sobre a qual não foi dito nada, e fica intacta.
 */
function dados_gravar(int $appId, array $payload, ?int $userId): array
{
    $colecoes = dados_colecoes($appId);
    $extrasVistos = [];
    $contagem = [];
    $apagados = 0;

    $db = db();
    $db->beginTransaction();
    try {
        foreach ($colecoes as $colecao => $def) {
            if (!array_key_exists($colecao, $payload) || !is_array($payload[$colecao])) {
                continue;
            }
            $linhas = $payload[$colecao];
            $uids = dados_gravar_colecao($appId, $colecao, $def['tabela'], $linhas,
                                         $userId, $extrasVistos);
            $contagem[$colecao] = count($uids);

            if ($def['dias']) {
                dados_gravar_dias($appId, $linhas);
            }
            $apagados += dados_apagar_ausentes($appId, $def['tabela'], $uids);
        }

        $defs = isset($payload['definicoes']) && is_array($payload['definicoes'])
              ? $payload['definicoes'] : [];
        foreach ($defs as $chave => $valor) {
            q('INSERT INTO app_definicoes (app_id, chave, valor) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
              [$appId, mb_substr((string)$chave, 0, 64), (string)$valor]);
        }

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }

    return $contagem + ['apagados' => $apagados,
                        'extras' => array_values(array_unique($extrasVistos))];
}

/** Dias em obra de cada planeamento. */
function dados_gravar_dias(int $appId, array $linhas): void
{
    foreach ($linhas as $p) {
        if (!is_array($p) || !isset($p['uid'])) {
            continue;
        }
        $planoId = (int)q_val('SELECT id FROM app_planeamentos WHERE app_id = ? AND uid = ?',
                              [$appId, (int)$p['uid']]);
        if (!$planoId) {
            continue;
        }
        $days   = isset($p['days']) && is_array($p['days']) ? $p['days'] : [];
        $manter = [];
        foreach (DADOS_DIAS as $d) {
            if (!array_key_exists($d, $days)) {
                continue;
            }
            $manter[] = $d;
            q('INSERT INTO app_planeamento_dias (plano_id, dia, descricao) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE descricao = VALUES(descricao)',
              [$planoId, $d, (string)$days[$d]]);
        }
        if ($manter) {
            $in = implode(',', array_fill(0, count($manter), '?'));
            q("DELETE FROM app_planeamento_dias WHERE plano_id = ? AND dia NOT IN ($in)",
              array_merge([$planoId], $manter));
        } else {
            q('DELETE FROM app_planeamento_dias WHERE plano_id = ?', [$planoId]);
        }
    }
}

/** Insere ou actualiza uma coleção inteira. Devolve os uid que ficaram. */
function dados_gravar_colecao(int $appId, string $colecao, string $tabela, array $linhas,
                              ?int $userId, array &$extrasVistos): array
{
    $mapa = dados_colunas($appId, $colecao);
    $uids = [];

    foreach ($linhas as $linha) {
        if (!is_array($linha) || !isset($linha['uid'])) {
            continue;
        }
        $uids[] = (int)$linha['uid'];

        $cols = ['app_id'];
        $vals = [$appId];
        foreach ($mapa as $campo => $coluna) {
            $cols[] = $coluna;
            $vals[] = dados_valor(dados_tipo($appId, $colecao, $campo), $linha[$campo] ?? '');
        }

        // O que a aplicação enviou e ainda não tem coluna fica em extras,
        // com o nome que ela lhe deu.
        $extras = [];
        foreach ($linha as $campo => $v) {
            if (isset($mapa[$campo]) || $campo === 'days') {
                continue;
            }
            $extras[$campo] = $v;
            $extrasVistos[] = $colecao . '.' . $campo;
        }
        $cols[] = 'extras';
        $vals[] = $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null;
        $cols[] = 'alterado_por';
        $vals[] = $userId;

        $ph  = implode(',', array_fill(0, count($cols), '?'));
        $upd = [];
        foreach ($cols as $c) {
            if ($c !== 'app_id') {
                $upd[] = "$c = VALUES($c)";
            }
        }
        q('INSERT INTO ' . $tabela . ' (' . implode(',', $cols) . ") VALUES ($ph)
           ON DUPLICATE KEY UPDATE " . implode(',', $upd), $vals);
    }
    return $uids;
}

/**
 * Apaga todos os dados de uma aplicação.
 *
 * Existe porque a aplicação em si não deve poder fazer isto: um botão
 * dentro da página é carregado por qualquer pessoa que a abra, e o que
 * ela apagaria é o trabalho de toda a gente. Aqui é preciso ser
 * administrador, escrever o nome da aplicação, e fica no log.
 *
 * A estrutura fica de pé — tabelas, colunas e o registo de campos. O que
 * desaparece são as linhas.
 *
 * @return array Quantas linhas foram apagadas, por coleção.
 */
function dados_apagar_tudo(int $appId): array
{
    $contagem = [];
    $db = db();
    $db->beginTransaction();
    try {
        foreach (dados_colecoes($appId) as $colecao => $def) {
            // Os dias saem por arrasto da chave estrangeira do planeamento.
            $contagem[$colecao] = (int)q('DELETE FROM ' . $def['tabela'] . ' WHERE app_id = ?',
                                         [$appId])->rowCount();
        }
        $contagem['definicoes'] = (int)q('DELETE FROM app_definicoes WHERE app_id = ?',
                                         [$appId])->rowCount();
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    return $contagem;
}

/** Apaga as linhas que a aplicação deixou de enviar. */
function dados_apagar_ausentes(int $appId, string $tabela, array $uids): int
{
    if (!$uids) {
        return (int)q('DELETE FROM ' . $tabela . ' WHERE app_id = ?', [$appId])->rowCount();
    }
    $in = implode(',', array_fill(0, count($uids), '?'));
    return (int)q('DELETE FROM ' . $tabela . " WHERE app_id = ? AND uid NOT IN ($in)",
                  array_merge([$appId], $uids))->rowCount();
}
