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
 * compara-a com o que já conhecia e avisa se aparecer campo novo.
 *
 * O que a declaração NÃO faz é alterar tabelas sozinha. Um ficheiro HTML
 * vem de fora — hoje do ChatGPT, amanhã de quem for — e não pode mandar
 * em ALTER TABLE. Campo novo é registado, mostrado ao administrador, e os
 * seus valores ficam guardados na coluna "extras" até alguém decidir
 * dar-lhe coluna própria. Nada se perde e nada acontece às escondidas.
 */

/** Onde vive a declaração dentro do HTML da aplicação. */
const DADOS_MARCA = 'setronix-dados';

/**
 * Campos com coluna própria, por coleção.
 *
 * A chave é o nome que a aplicação usa em JavaScript; o valor é a coluna.
 * Tudo o que a aplicação enviar e não estiver aqui vai para "extras".
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

/** Dias da semana aceites, pela ordem em que se lêem. */
const DADOS_DIAS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

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

/**
 * Campos declarados, achatados em pares "coleção" => ['campo' => tipo].
 */
function dados_campos_declarados(array $manifesto): array
{
    $out = [];
    foreach ($manifesto['colecoes'] as $nome => $def) {
        if (!is_array($def)) {
            continue;
        }
        $campos = isset($def['campos']) && is_array($def['campos']) ? $def['campos'] : [];
        $lista  = [];
        foreach ($campos as $campo => $tipo) {
            $lista[(string)$campo] = is_string($tipo) ? $tipo : 'texto';
        }
        $out[(string)$nome] = $lista;
    }
    return $out;
}

/**
 * Compara a declaração de uma versão com o que já estava registado.
 *
 * Não grava nada: serve para mostrar ao administrador o que muda, antes
 * de ele confirmar o envio.
 *
 * @return array{novos: array, desaparecidos: array, conhecidos: int}
 */
function dados_diferencas(int $appId, array $manifesto): array
{
    $declarados = dados_campos_declarados($manifesto);
    $registados = [];
    foreach (q_all('SELECT colecao, campo FROM app_campos WHERE app_id = ?', [$appId]) as $r) {
        $registados[$r['colecao']][$r['campo']] = true;
    }

    $novos = $desaparecidos = [];
    foreach ($declarados as $colecao => $campos) {
        foreach ($campos as $campo => $tipo) {
            if (!isset($registados[$colecao][$campo])) {
                $novos[] = ['colecao' => $colecao, 'campo' => $campo, 'tipo' => $tipo,
                            'tem_coluna' => isset(DADOS_COLUNAS[$colecao][$campo])];
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
    return ['novos' => $novos, 'desaparecidos' => $desaparecidos, 'conhecidos' => $conhecidos];
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
        foreach ($campos as $campo => $tipo) {
            q(
                'INSERT INTO app_campos (app_id, colecao, campo, tipo, tem_coluna, visto_em)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), visto_em = VALUES(visto_em)',
                [$appId, $colecao, $campo, mb_substr($tipo, 0, 32),
                 isset(DADOS_COLUNAS[$colecao][$campo]) ? 1 : 0, $versaoId]
            );
        }
    }
}

/** Campos registados de uma aplicação, agrupados por coleção. */
function dados_campos_registados(int $appId): array
{
    $out = [];
    foreach (q_all('SELECT colecao, campo, tipo, tem_coluna FROM app_campos
                     WHERE app_id = ? ORDER BY colecao, campo', [$appId]) as $r) {
        $out[$r['colecao']][] = $r;
    }
    return $out;
}

// ---------------------------------------------------------------------
// Ler e gravar
// ---------------------------------------------------------------------

/** Converte uma linha da base de dados na forma que a aplicação espera. */
function dados_linha_para_app(array $linha, string $colecao): array
{
    $out = [];
    foreach (DADOS_COLUNAS[$colecao] as $campo => $coluna) {
        $v = $linha[$coluna] ?? null;
        if ($campo === 'closed') {
            $out[$campo] = (int)$v === 1;
        } elseif (in_array($campo, ['uid', 'workUid', 'progress'], true)) {
            $out[$campo] = (int)$v;
        } elseif ($campo === 'value') {
            $out[$campo] = $v === null ? '' : (string)(float)$v;
        } else {
            $out[$campo] = $v === null ? '' : (string)$v;
        }
    }
    // Campos que a aplicação passou a enviar e ainda não têm coluna.
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
    $obras = [];
    foreach (q_all('SELECT * FROM app_obras WHERE app_id = ? ORDER BY uid', [$appId]) as $r) {
        $obras[] = dados_linha_para_app($r, 'obras');
    }

    $dias = [];
    foreach (q_all('SELECT d.plano_id, d.dia, d.descricao
                      FROM app_planeamento_dias d
                      JOIN app_planeamentos p ON p.id = d.plano_id
                     WHERE p.app_id = ?', [$appId]) as $r) {
        $dias[(int)$r['plano_id']][$r['dia']] = (string)$r['descricao'];
    }

    $planos = [];
    foreach (q_all('SELECT * FROM app_planeamentos WHERE app_id = ? ORDER BY uid', [$appId]) as $r) {
        $p = dados_linha_para_app($r, 'planeamentos');
        // Pela ordem da semana, para o ficheiro sair sempre igual.
        $p['days'] = [];
        foreach (DADOS_DIAS as $d) {
            if (isset($dias[(int)$r['id']][$d])) {
                $p['days'][$d] = $dias[(int)$r['id']][$d];
            }
        }
        $planos[] = $p;
    }

    $defs = [];
    foreach (q_all('SELECT chave, valor FROM app_definicoes WHERE app_id = ?', [$appId]) as $r) {
        $defs[$r['chave']] = $r['valor'];
    }

    return ['obras' => $obras, 'planeamentos' => $planos, 'definicoes' => (object)$defs];
}

/** Normaliza um valor para a coluna a que se destina. */
function dados_valor(string $campo, $v)
{
    if ($campo === 'closed') {
        return ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
    }
    if (in_array($campo, ['uid', 'workUid', 'progress'], true)) {
        return (int)$v;
    }
    if ($campo === 'value') {
        $s = trim((string)$v);
        return $s === '' ? null : (float)str_replace(',', '.', $s);
    }
    if (in_array($campo, ['fpsEnd', 'closedAt', 'week'], true)) {
        $s = trim((string)$v);
        // A aplicação usa ISO (aaaa-mm-dd); qualquer outra coisa vira NULL,
        // que é honesto: melhor vazio do que uma data inventada.
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }
    return mb_substr(trim((string)$v), 0, 255);
}

/**
 * Grava tudo o que a aplicação enviou.
 *
 * A aplicação manda sempre as coleções inteiras — é assim que ela
 * própria funciona do lado do browser. Aqui isso traduz-se em: inserir ou
 * actualizar o que veio, e apagar o que deixou de vir. Tudo numa
 * transacção, para nunca ficar meio gravado.
 *
 * @return array{obras:int, planeamentos:int, apagados:int, extras:array}
 */
function dados_gravar(int $appId, array $payload, ?int $userId): array
{
    // Uma coleção que não vem no pedido não é uma coleção vazia: é uma
    // coleção sobre a qual não foi dito nada. A diferença importa, porque
    // "vazia" aqui significa apagar tudo. Quem só muda uma definição
    // manda só a definição, e as obras ficam onde estão.
    $temObras  = array_key_exists('obras', $payload) && is_array($payload['obras']);
    $temPlanos = array_key_exists('planeamentos', $payload) && is_array($payload['planeamentos']);
    $obras  = $temObras ? $payload['obras'] : [];
    $planos = $temPlanos ? $payload['planeamentos'] : [];
    $defs   = isset($payload['definicoes']) && is_array($payload['definicoes'])
            ? $payload['definicoes'] : [];

    $extrasVistos = [];
    $db = db();
    $db->beginTransaction();
    try {
        $uidsObras = $temObras
            ? dados_gravar_colecao($appId, 'obras', 'app_obras', $obras, $userId, $extrasVistos)
            : [];
        $uidsPlanos = $temPlanos
            ? dados_gravar_colecao($appId, 'planeamentos', 'app_planeamentos', $planos,
                                   $userId, $extrasVistos)
            : [];

        // Dias de cada planeamento.
        foreach ($temPlanos ? $planos : [] as $p) {
            if (!is_array($p) || !isset($p['uid'])) {
                continue;
            }
            $planoId = (int)q_val('SELECT id FROM app_planeamentos WHERE app_id = ? AND uid = ?',
                                  [$appId, (int)$p['uid']]);
            if (!$planoId) {
                continue;
            }
            $days = isset($p['days']) && is_array($p['days']) ? $p['days'] : [];
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

        $apagados = ($temObras ? dados_apagar_ausentes($appId, 'app_obras', $uidsObras) : 0)
                  + ($temPlanos ? dados_apagar_ausentes($appId, 'app_planeamentos', $uidsPlanos) : 0);

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

    return ['obras' => count($uidsObras), 'planeamentos' => count($uidsPlanos),
            'apagados' => $apagados, 'extras' => array_values(array_unique($extrasVistos))];
}

/** Insere ou actualiza uma coleção inteira. Devolve os uid que ficaram. */
function dados_gravar_colecao(int $appId, string $colecao, string $tabela, array $linhas,
                              ?int $userId, array &$extrasVistos): array
{
    $mapa  = DADOS_COLUNAS[$colecao];
    $uids  = [];

    foreach ($linhas as $linha) {
        if (!is_array($linha) || !isset($linha['uid'])) {
            continue;
        }
        $uid    = (int)$linha['uid'];
        $uids[] = $uid;

        $cols = ['app_id'];
        $vals = [$appId];
        foreach ($mapa as $campo => $coluna) {
            $cols[] = $coluna;
            $vals[] = dados_valor($campo, $linha[$campo] ?? '');
        }

        // O que a aplicação enviou e ainda não tem coluna fica em extras,
        // com o nome que ela lhe deu.
        $extras = [];
        foreach ($linha as $campo => $v) {
            if (isset($mapa[$campo]) || $campo === 'days') {
                continue;
            }
            $extras[$campo]  = $v;
            $extrasVistos[]  = $colecao . '.' . $campo;
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
