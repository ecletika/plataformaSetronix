<?php
/**
 * Mapa de atividade dos funcionários.
 *
 * Lê o .xlsx que sai do sistema de recursos humanos e passa-o para a
 * base de dados: quem são as pessoas, o saldo de férias de cada uma, e
 * o estado de cada dia do ano — férias, baixa, falta, ou a folha de
 * horas fechada.
 *
 * O ficheiro é um retrato do ano inteiro tirado num dia, não um
 * acrescento. Por isso cada importação substitui a anterior: guardar
 * metade de um retrato e metade de outro dava um mapa que nunca existiu.
 *
 * O que o ficheiro NÃO tem: horas. Nem uma. Diz que dias é que a pessoa
 * esteve fora e em que estado está a papelada; não diz quanto tempo
 * trabalhou. Quem quiser horas tem de as ir buscar a outro lado.
 */

/**
 * Os símbolos do mapa, tal como a legenda do próprio ficheiro os explica.
 *
 * A legenda está lá dentro, nas últimas linhas — não foi preciso
 * adivinhar nada. Se um dia aparecer um símbolo que não esteja aqui, a
 * importação guarda-o como "desconhecido" e avisa, em vez de o deitar
 * fora em silêncio.
 */
const RH_SIMBOLOS = [
    '✔✔'   => 'finalizado',
    '✓'    => 'aprovado',
    '⥱'    => 'enviado',
    '❌'    => 'rejeitado',
    '⛱'    => 'ferias',
    '½⛱'   => 'ferias',          // meio dia
    '🛌'    => 'baixa',
    '👶'    => 'parentalidade',
    '💍'    => 'casamento',
    '😢'    => 'nojo',
    '😐⤬'   => 'falta_justificada',
    '🙄❓'   => 'falta_injustificada',
];

/** Como se lê cada estado no ecrã. */
const RH_ESTADOS = [
    'finalizado'          => 'Folha finalizada',
    'aprovado'            => 'Aprovado, a aguardar finalização',
    'enviado'             => 'Enviado, a aguardar aprovação',
    'rejeitado'           => 'Rejeitado, é preciso preencher de novo',
    'ferias'              => 'Férias',
    'baixa'               => 'Baixa médica',
    'parentalidade'       => 'Licença de paternidade',
    'casamento'           => 'Licença de casamento',
    'nojo'                => 'Licença de nojo',
    'falta_justificada'   => 'Falta justificada',
    'falta_injustificada' => 'Falta injustificada',
    'desconhecido'        => 'Símbolo não reconhecido',
];

/** Abreviaturas para a grelha da semana, onde não cabe o nome todo. */
const RH_SIGLAS = [
    'ferias'              => 'F',
    'baixa'               => 'B',
    'parentalidade'       => 'P',
    'casamento'           => 'C',
    'nojo'                => 'N',
    'falta_justificada'   => 'FJ',
    'falta_injustificada' => 'FI',
];

/** Estados em que a pessoa não está disponível para trabalhar. */
const RH_AUSENTE = ['ferias', 'baixa', 'parentalidade', 'casamento', 'nojo',
                    'falta_justificada', 'falta_injustificada'];

/** A cor de fundo com que o ficheiro marca os feriados. */
const RH_COR_FERIADO = 'FF98FB98';

/** Meses abreviados, como o ficheiro os escreve nos cabeçalhos. */
const RH_MESES = ['jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6,
                  'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12];

/**
 * Lê o ficheiro e devolve o que lá está, sem gravar nada.
 *
 * Separado da gravação de propósito: assim dá para mostrar ao
 * administrador o que vem no ficheiro antes de ele substituir o mapa
 * que já lá está.
 *
 * @return array{funcionarios: array, feriados: array, data_relatorio: ?string,
 *               periodo: array, avisos: array, dias: int}
 */
function rh_interpretar(string $caminho): array
{
    require_once __DIR__ . '/xlsx.php';
    $folha   = xlsx_folha($caminho);
    $celulas = $folha['celulas'];
    $avisos  = [];

    if (!isset($celulas[1][1]) || stripos((string)$celulas[1][1]['v'], 'funcion') === false) {
        throw new RuntimeException('A primeira célula devia dizer "Funcionário". '
            . 'Isto não parece um mapa de atividade.');
    }

    // ---------------------------------------------------------------
    // Cabeçalho: a partir da coluna 8, uma coluna por dia
    // ---------------------------------------------------------------
    // Umas colunas trazem data a sério, outras só texto ("01 Fev") --
    // depende de como o Excel formatou cada uma. O ano só está nas
    // primeiras, por isso lêem-se essas antes de tentar as outras: o ano
    // vem do ficheiro e não do relógio do servidor, senão em janeiro
    // importar o mapa do ano passado dava datas do ano corrente.
    $datas = [];
    $ano   = null;
    for ($c = 8; $c <= $folha['colunas']; $c++) {
        $v = $celulas[1][$c]['v'] ?? null;
        if ($v instanceof DateTimeInterface) {
            $datas[$c] = $v->format('Y-m-d');
            $ano = $ano ?? (int)$v->format('Y');
        }
    }
    if ($ano === null) {
        throw new RuntimeException('Nenhuma coluna do cabeçalho traz uma data completa, '
            . 'por isso não dá para saber de que ano é o mapa.');
    }
    for ($c = 8; $c <= $folha['colunas']; $c++) {
        if (isset($datas[$c])) {
            continue;
        }
        $d = rh_data_do_cabecalho($celulas[1][$c]['v'] ?? null, $ano);
        if ($d !== null) {
            $datas[$c] = $d;
        }
    }
    ksort($datas);
    if (count($datas) < 300) {
        throw new RuntimeException('Só foram reconhecidas ' . count($datas)
            . ' colunas de dias. Esperava-se um ano inteiro.');
    }

    // ---------------------------------------------------------------
    // Feriados: estão na cor de fundo e em mais lado nenhum
    // ---------------------------------------------------------------
    $feriados = [];
    foreach ($datas as $c => $d) {
        $quantos = 0;
        foreach ($celulas as $r => $linha) {
            if ($r > 1 && ($linha[$c]['cor'] ?? null) === RH_COR_FERIADO) {
                $quantos++;
            }
        }
        // Um feriado é para toda a gente. Uma célula verde solta é outra
        // coisa qualquer e não se toma por feriado.
        if ($quantos > 1) {
            $feriados[] = $d;
        }
    }

    // ---------------------------------------------------------------
    // Funcionários
    // ---------------------------------------------------------------
    $funcionarios = [];
    $dataRel      = null;
    $totalDias    = 0;

    for ($r = 2; $r <= $folha['linhas']; $r++) {
        $nome = trim((string)($celulas[$r][1]['v'] ?? ''));
        if ($nome === '') {
            continue;
        }
        // A partir da legenda deixa de haver funcionários.
        if (stripos($nome, 'Data do Relat') === 0) {
            $dataRel = rh_data_do_relatorio($nome);
            break;
        }
        if ($nome === 'Legendas') {
            break;
        }

        $rh = $celulas[$r][2]['v'] ?? null;
        if (!is_numeric($rh)) {
            $avisos[] = 'Linha ' . $r . ' (' . $nome . ') ignorada: sem número de RH.';
            continue;
        }

        $dias = [];
        foreach ($datas as $c => $d) {
            $v = trim((string)($celulas[$r][$c]['v'] ?? ''));
            if ($v === '') {
                continue;
            }
            $estado = RH_SIMBOLOS[$v] ?? null;
            if ($estado === null) {
                $estado   = 'desconhecido';
                $avisos[] = 'Símbolo desconhecido em ' . $nome . ', ' . $d . ': "' . $v . '".';
            }
            $dias[] = ['dia' => $d, 'estado' => $estado,
                       'meio_dia' => strpos($v, '½') === 0 ? 1 : 0, 'simbolo' => $v];
        }
        $totalDias += count($dias);

        $funcionarios[] = [
            'rh'          => (int)$rh,
            'nome'        => $nome,
            'transitados' => rh_numero($celulas[$r][3]['v'] ?? null),
            'atribuidos'  => rh_numero($celulas[$r][4]['v'] ?? null),
            'por_gozar'   => rh_numero($celulas[$r][5]['v'] ?? null),
            'por_marcar'  => rh_numero($celulas[$r][6]['v'] ?? null),
            'marcados'    => rh_numero($celulas[$r][7]['v'] ?? null),
            'dias'        => $dias,
        ];
    }

    if (!$funcionarios) {
        throw new RuntimeException('Não foi encontrado nenhum funcionário no ficheiro.');
    }

    // Números de RH repetidos dariam duas pessoas com a mesma chave.
    $vistos = [];
    foreach ($funcionarios as $f) {
        if (isset($vistos[$f['rh']])) {
            throw new RuntimeException('O número de RH ' . $f['rh'] . ' aparece duas vezes ('
                . $vistos[$f['rh']] . ' e ' . $f['nome'] . '). O ficheiro não está bom.');
        }
        $vistos[$f['rh']] = $f['nome'];
    }

    return [
        'funcionarios'   => $funcionarios,
        'feriados'       => $feriados,
        'data_relatorio' => $dataRel,
        'periodo'        => [min($datas), max($datas)],
        'dias'           => $totalDias,
        'avisos'         => $avisos,
    ];
}

/**
 * A data de uma coluna do cabeçalho.
 *
 * Umas vêm como data a sério, outras como texto ("01 Fev") — depende de
 * como o Excel decidiu formatar a coluna. As duas contam.
 */
function rh_data_do_cabecalho($v, int $ano): ?string
{
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d');
    }
    $t = trim((string)$v);
    if ($t === '' || !preg_match('/^(\d{1,2})\s+([A-Za-zçÇ]{3,})/u', $t, $m)) {
        return null;
    }
    $mes = RH_MESES[mb_strtolower(mb_substr($m[2], 0, 3))] ?? null;
    if (!$mes) {
        return null;
    }
    $dia = (int)$m[1];
    return checkdate($mes, $dia, $ano) ? sprintf('%04d-%02d-%02d', $ano, $mes, $dia) : null;
}

/** "Data do Relatório: 04-09-2026" fica 2026-09-04. */
function rh_data_do_relatorio(string $texto): ?string
{
    if (preg_match('/(\d{2})-(\d{2})-(\d{4})/', $texto, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return null;
}

/** Um número do ficheiro, ou null quando a célula está vazia. */
function rh_numero($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    return is_numeric($v) ? (float)$v : null;
}

/**
 * Grava o mapa, substituindo o anterior.
 *
 * @return array O registo da importação.
 */
function rh_gravar(int $appId, array $mapa, string $ficheiro, string $sha, ?int $userId): array
{
    $db = db();
    $db->beginTransaction();
    try {
        q('INSERT INTO rh_mapas (app_id, ficheiro, sha256, data_relatorio, periodo_ini,
                                 periodo_fim, funcionarios, dias, feriados, avisos, criado_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [$appId, mb_substr($ficheiro, 0, 255), $sha, $mapa['data_relatorio'],
           $mapa['periodo'][0], $mapa['periodo'][1], count($mapa['funcionarios']),
           $mapa['dias'], count($mapa['feriados']),
           $mapa['avisos'] ? json_encode($mapa['avisos'], JSON_UNESCAPED_UNICODE) : null,
           $userId]);
        $mapaId = (int)$db->lastInsertId();

        // Fora o retrato anterior. Os dias saem por arrasto.
        q('DELETE FROM rh_funcionarios WHERE app_id = ?', [$appId]);
        q('DELETE FROM rh_feriados WHERE app_id = ?', [$appId]);

        foreach ($mapa['feriados'] as $d) {
            q('INSERT IGNORE INTO rh_feriados (app_id, dia) VALUES (?,?)', [$appId, $d]);
        }

        foreach ($mapa['funcionarios'] as $f) {
            q('INSERT INTO rh_funcionarios (app_id, rh, nome, transitados, atribuidos,
                                            por_gozar, por_marcar, marcados, mapa_id)
               VALUES (?,?,?,?,?,?,?,?,?)',
              [$appId, $f['rh'], mb_substr($f['nome'], 0, 160), $f['transitados'],
               $f['atribuidos'], $f['por_gozar'], $f['por_marcar'], $f['marcados'], $mapaId]);
            $fid = (int)$db->lastInsertId();

            foreach ($f['dias'] as $d) {
                q('INSERT INTO rh_dias (funcionario_id, dia, estado, meio_dia, simbolo)
                   VALUES (?,?,?,?,?)',
                  [$fid, $d['dia'], $d['estado'], $d['meio_dia'], $d['simbolo']]);
            }
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    return q_one('SELECT * FROM rh_mapas WHERE id = ?', [$mapaId]) ?? [];
}

/** O último mapa importado para esta aplicação. */
function rh_mapa_atual(int $appId): ?array
{
    return q_one('SELECT * FROM rh_mapas WHERE app_id = ? ORDER BY id DESC LIMIT 1', [$appId]) ?: null;
}

/**
 * A aplicação com mapa de atividade que esta pessoa pode abrir.
 *
 * Serve para decidir se vale a pena mostrar-lhe o atalho das ausências:
 * sem mapa não há nada para ver, e sem acesso à aplicação o atalho seria
 * uma porta para uma sala onde ela não entra.
 *
 * Devolve a primeira que servir — hoje só há uma aplicação com mapa.
 *
 * O acesso é o real, não o de gestor: quem gere aplicações vê-as todas na
 * administração, mas isso não é ter a aplicação atribuída. Um gestor sem
 * o Planeamento de Obras não tem que ver este atalho.
 */
function rh_app_do_utilizador(int $userId): ?array
{
    require_once __DIR__ . '/apps.php';
    foreach (q_all('SELECT DISTINCT a.* FROM apps a
                      JOIN rh_mapas m ON m.app_id = a.id
                     WHERE a.is_active = 1
                     ORDER BY a.sort_order, a.name') as $app) {
        if (user_can_open_app($userId, $app)) {
            return $app;
        }
    }
    return null;
}

/** Resumo por estado, para o ecrã da administração. */
function rh_resumo(int $appId): array
{
    return q_all(
        'SELECT d.estado, COUNT(*) AS total, SUM(d.meio_dia) AS meios
           FROM rh_dias d
           JOIN rh_funcionarios f ON f.id = d.funcionario_id
          WHERE f.app_id = ?
          GROUP BY d.estado
          ORDER BY total DESC',
        [$appId]
    );
}

/**
 * Palavras de um nome, sem acentos e sem as de ligação.
 *
 * "Hugo Emanuel Matos Vieira" fica ["hugo","emanuel","matos","vieira"].
 */
function rh_palavras(string $nome): array
{
    $n = @iconv('UTF-8', 'ASCII//TRANSLIT', $nome);
    $n = strtolower((string)$n);
    $p = preg_split('/[^a-z]+/', $n) ?: [];
    return array_values(array_diff(array_filter($p), ['de', 'da', 'do', 'dos', 'das', 'e']));
}

/**
 * Dias de trabalho de uma semana.
 *
 * Segunda a sexta, tirando feriados. O sábado fica de fora: no mapa de
 * recursos humanos as férias são marcadas de segunda a sexta e o sábado
 * fica em branco, por isso contá-lo dava sempre "um dia livre" e ninguém
 * chegaria a ser filtrado.
 *
 * São cinco dias por escolha, não por acaso — mudar para seis é mudar
 * este número.
 */
const RH_DIAS_UTEIS = 5;

function rh_dias_de_trabalho(int $appId, string $semana): array
{
    $seg = date('Y-m-d', strtotime('monday this week', strtotime($semana)));
    $feriados = [];
    foreach (q_all('SELECT dia FROM rh_feriados WHERE app_id = ?', [$appId]) as $r) {
        $feriados[$r['dia']] = true;
    }
    $out = [];
    for ($i = 0; $i < RH_DIAS_UTEIS; $i++) {
        $d = date('Y-m-d', strtotime($seg . ' +' . $i . ' day'));
        if (!isset($feriados[$d])) {
            $out[] = $d;
        }
    }
    return $out;
}

/**
 * Quem não tem um único dia livre numa semana.
 *
 * A regra é essa: basta um dia de trabalho livre para a pessoa poder ser
 * escolhida. Quem estiver de férias, de baixa ou em falta em todos os
 * dias de trabalho da semana é que não deve aparecer.
 *
 * @return array Nome completo => estado do primeiro dia.
 */
function rh_fora_a_semana_toda(int $appId, string $semana): array
{
    $dias = rh_dias_de_trabalho($appId, $semana);
    if (!$dias) {
        return [];
    }
    $inDias = implode(',', array_fill(0, count($dias), '?'));
    $inEst  = implode(',', array_fill(0, count(RH_AUSENTE), '?'));

    $out = [];
    foreach (q_all(
        "SELECT f.nome, MIN(d.estado) AS estado, COUNT(*) AS n
           FROM rh_dias d
           JOIN rh_funcionarios f ON f.id = d.funcionario_id
          WHERE f.app_id = ? AND d.dia IN ($inDias) AND d.estado IN ($inEst)
          GROUP BY f.id
         HAVING n >= ?",
        array_merge([$appId], $dias, RH_AUSENTE, [count($dias)])
    ) as $r) {
        $out[$r['nome']] = $r['estado'];
    }
    return $out;
}

/**
 * Todas as ausências de uma semana, pessoa a pessoa, dia a dia.
 *
 * Para o ecrã de quem planeia: quem falta, em que dias, e se falta a
 * semana inteira — que é o caso em que deixa de aparecer nas listas.
 */
function rh_ausencias_semana(int $appId, string $semana): array
{
    $dias = rh_dias_de_trabalho($appId, $semana);
    if (!$dias) {
        return ['dias' => [], 'pessoas' => []];
    }
    $inDias = implode(',', array_fill(0, count($dias), '?'));
    $inEst  = implode(',', array_fill(0, count(RH_AUSENTE), '?'));

    $pessoas = [];
    foreach (q_all(
        "SELECT f.rh, f.nome, d.dia, d.estado, d.meio_dia
           FROM rh_dias d
           JOIN rh_funcionarios f ON f.id = d.funcionario_id
          WHERE f.app_id = ? AND d.dia IN ($inDias) AND d.estado IN ($inEst)
          ORDER BY f.nome, d.dia",
        array_merge([$appId], $dias, RH_AUSENTE)
    ) as $r) {
        $k = (int)$r['rh'];
        if (!isset($pessoas[$k])) {
            $pessoas[$k] = ['rh' => $k, 'nome' => $r['nome'], 'dias' => []];
        }
        $pessoas[$k]['dias'][$r['dia']] = ['estado' => $r['estado'], 'meio' => (int)$r['meio_dia']];
    }
    foreach ($pessoas as $k => $p) {
        $pessoas[$k]['semana_toda'] = count($p['dias']) >= count($dias);
        $pessoas[$k]['livres']      = count($dias) - count($p['dias']);
    }
    return ['dias' => $dias, 'pessoas' => array_values($pessoas)];
}

/**
 * Quais destes nomes ficam de fora numa semana.
 *
 * Recebe os nomes curtos que a aplicação usa e devolve os que devem
 * desaparecer das listas, com o motivo.
 */
function rh_fora_na_semana(int $appId, string $semana, array $nomes): array
{
    $fora = rh_fora_a_semana_toda($appId, $semana);
    if (!$fora) {
        return [];
    }
    $completos = [];
    foreach ($fora as $nome => $estado) {
        $completos[] = ['palavras' => rh_palavras((string)$nome), 'estado' => $estado];
    }

    $out = [];
    foreach ($nomes as $nome) {
        if (!is_string($nome)) {
            continue;
        }
        $curto = rh_palavras($nome);
        if (!$curto) {
            continue;
        }
        $achado = null;
        foreach ($completos as $c) {
            if (!array_diff($curto, $c['palavras'])) {
                if ($achado !== null) {
                    $achado = null;   // dois candidatos: não se arrisca
                    break;
                }
                $achado = $c['estado'];
            }
        }
        if ($achado !== null) {
            $out[$nome] = $achado;
        }
    }
    return $out;
}

/** Quem está ausente num dia, e porquê. */
function rh_ausentes(int $appId, string $dia): array
{
    $in = implode(',', array_fill(0, count(RH_AUSENTE), '?'));
    return q_all(
        "SELECT f.rh, f.nome, d.estado, d.meio_dia
           FROM rh_dias d
           JOIN rh_funcionarios f ON f.id = d.funcionario_id
          WHERE f.app_id = ? AND d.dia = ? AND d.estado IN ($in)
          ORDER BY f.nome",
        array_merge([$appId, $dia], RH_AUSENTE)
    );
}
