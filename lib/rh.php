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
 * Quais destes nomes são de gente que hoje não pode trabalhar.
 *
 * O mapa conhece as pessoas pelo nome completo — "Hugo Emanuel Matos
 * Vieira" — e a aplicação pelo nome curto — "Hugo Vieira". Ligam-se por
 * palavras: se todas as palavras do nome curto estiverem no nome
 * completo, é a mesma pessoa.
 *
 * Nome curto que dê em duas pessoas, ou em nenhuma, fica de fora. Um
 * nome a menos na lista é um incómodo; tirar a pessoa errada é uma
 * equipa desfeita sem razão.
 *
 * @return array Os nomes a tirar, com o motivo: ['Hugo Vieira' => 'ferias']
 */
function rh_indisponiveis(int $appId, string $dia, array $nomes): array
{
    $ausentes = [];
    foreach (rh_ausentes($appId, $dia) as $r) {
        $ausentes[] = ['palavras' => rh_palavras((string)$r['nome']), 'estado' => $r['estado']];
    }
    if (!$ausentes) {
        return [];
    }

    $out = [];
    foreach ($nomes as $nome) {
        $curto = rh_palavras((string)$nome);
        if (!$curto) {
            continue;
        }
        $achado = null;
        foreach ($ausentes as $a) {
            if (!array_diff($curto, $a['palavras'])) {
                if ($achado !== null) {
                    $achado = null;   // dois candidatos: não se arrisca
                    break;
                }
                $achado = $a['estado'];
            }
        }
        if ($achado !== null) {
            $out[(string)$nome] = $achado;
        }
    }
    return $out;
}

/**
 * Tira do HTML as pessoas que hoje não podem trabalhar.
 *
 * A aplicação declara, no bloco "setronix-dados", em que variável tem as
 * suas listas e quais delas são de pessoas. Aqui essa variável é lida,
 * as listas declaradas são limpas, e o HTML segue já sem esses nomes:
 * não chegam ao browser, e por isso não há como escolhê-los.
 *
 * Muda sozinho à meia-noite, porque é refeito de cada vez que a página
 * abre e o dia de referência é o de hoje.
 *
 * O que já está gravado não é tocado. Um planeamento antigo com alguém
 * que entretanto ficou de férias continua com essa pessoa: tirá-la seria
 * apagar uma decisão que alguém tomou.
 *
 * @return array Os nomes tirados, com o motivo.
 */
function rh_limpar_html(int $appId, string &$html, array $manifesto, ?string $dia = null): array
{
    $def = $manifesto['pessoas'] ?? null;
    if (!is_array($def)) {
        return [];
    }
    $variavel = (string)($def['variavel'] ?? '');
    $listas   = isset($def['listas']) && is_array($def['listas']) ? $def['listas'] : [];
    if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,40}$/', $variavel) || !$listas) {
        return [];
    }

    $bloco = rh_achar_objecto($html, $variavel);
    if ($bloco === null) {
        return [];
    }
    [$ini, $fim] = $bloco;
    $dados = json_decode(substr($html, $ini, $fim - $ini), true);
    if (!is_array($dados)) {
        return [];
    }

    // Todos os nomes que aparecem nas listas de pessoas.
    $nomes = [];
    foreach ($listas as $lista) {
        foreach ((array)($dados[$lista] ?? []) as $n) {
            if (is_string($n)) {
                $nomes[$n] = true;
            }
        }
    }
    if (!$nomes) {
        return [];
    }

    $fora = rh_indisponiveis($appId, $dia ?? date('Y-m-d'), array_keys($nomes));
    if (!$fora) {
        return [];
    }

    foreach ($listas as $lista) {
        if (!isset($dados[$lista]) || !is_array($dados[$lista])) {
            continue;
        }
        $dados[$lista] = array_values(array_filter(
            $dados[$lista],
            static fn($n) => !is_string($n) || !isset($fora[$n])
        ));
    }

    $novo = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $html = substr($html, 0, $ini) . $novo . substr($html, $fim);
    return $fora;
}

/**
 * Onde começa e acaba o objecto de uma variável no HTML.
 *
 * Conta chavetas, saltando as que estão dentro de texto. É preciso
 * porque as listas têm nomes com aspas e acentos, e um corte a olho
 * partia o JSON ao meio.
 *
 * @return array|null [inicio, fim] ou null se não encontrar.
 */
function rh_achar_objecto(string $html, string $variavel): ?array
{
    if (!preg_match('/\b' . preg_quote($variavel, '/') . '\s*=\s*\{/', $html, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $ini   = (int)$m[0][1] + strlen($m[0][0]) - 1;
    $nivel = 0;
    $texto = false;
    $fuga  = false;

    for ($i = $ini, $n = strlen($html); $i < $n; $i++) {
        $c = $html[$i];
        if ($texto) {
            if ($fuga) {
                $fuga = false;
            } elseif ($c === '\\') {
                $fuga = true;
            } elseif ($c === '"') {
                $texto = false;
            }
            continue;
        }
        if ($c === '"') {
            $texto = true;
        } elseif ($c === '{') {
            $nivel++;
        } elseif ($c === '}') {
            $nivel--;
            if ($nivel === 0) {
                return [$ini, $i + 1];
            }
        }
    }
    return null;
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
