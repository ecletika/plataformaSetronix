<?php
/** Listas base (clientes, projetos, pessoal, tarefas) e importação de ficheiros. */

declare(strict_types=1);

require_once __DIR__ . '/xlsx.php';

/**
 * Devolve todas as listas activas no formato usado pelo front-end:
 *   ['clients' => [...], 'projects' => [...], ...]
 */
function lists_all(bool $onlyActive = true): array
{
    $types = q_all('SELECT code FROM list_types ORDER BY sort_order, code');
    $out = [];
    foreach ($types as $t) {
        $out[$t['code']] = [];
    }
    $sql = 'SELECT type_code, value FROM list_items'
         . ($onlyActive ? ' WHERE is_active = 1' : '')
         . ' ORDER BY type_code, sort_order, value';
    foreach (q_all($sql) as $row) {
        $out[$row['type_code']][] = $row['value'];
    }
    foreach ($out as $code => $values) {
        usort($out[$code], static fn($a, $b) => strcoll_pt($a, $b));
    }
    return $out;
}

/** Ordenação alfabética tolerante a acentos (À = A). */
function strcoll_pt(string $a, string $b): int
{
    $norm = static function (string $s): string {
        $from = ['á','à','â','ã','ä','é','è','ê','í','ì','î','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'];
        $to   = ['a','a','a','a','a','e','e','e','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
        return str_replace($from, $to, mb_strtolower($s));
    };
    return strcmp($norm($a), $norm($b));
}

/** Mapa cabeçalho-do-Excel → código de lista. */
function lists_header_map(): array
{
    $map = [];
    foreach (q_all('SELECT code, excel_header FROM list_types') as $t) {
        if (!empty($t['excel_header'])) {
            $map[lists_norm_header($t['excel_header'])] = $t['code'];
        }
    }
    // Sinónimos aceites nos ficheiros enviados.
    $aliases = [
        'CLIENTES'                  => 'clients',
        'PROJETOS'                  => 'projects',
        'PROJECTO'                  => 'projects',
        'GESTOR'                    => 'managers',
        'GESTORES DE PROJETO'       => 'managers',
        'FPS'                       => 'fps',
        'PSS'                       => 'fps',
        'SUPERVISORES'              => 'supervisors',
        'CHEFE DE EQUIPA'           => 'setLeaders',
        'CHEFES DE EQUIPA'          => 'setLeaders',
        'CHEFE DE EQUIPA SETRONIX'  => 'setLeaders',
        'AJUDANTE SETRONIX'         => 'setHelpers',
        'AJUDANTE SETRONIX 1'       => 'setHelpers',
        'AJUDANTE SETRONIX 2'       => 'setHelpers',
        'AJUDANTE SETRONIX 3'       => 'setHelpers',
        'AJUDANTES'                 => 'setHelpers',
        'TAREFA TIPO'               => 'tasks',
        'TAREFAS TIPO'              => 'tasks',
        'TAREFAS'                   => 'tasks',
    ];
    foreach ($aliases as $header => $code) {
        $map[lists_norm_header($header)] = $code;
    }
    return $map;
}

/** Normaliza um cabeçalho para comparação (maiúsculas, sem acentos nem espaços extra). */
function lists_norm_header(string $h): string
{
    $h = mb_strtoupper(trim($h));
    $from = ['Á','À','Â','Ã','Ä','É','È','Ê','Í','Ì','Î','Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ'];
    $to   = ['A','A','A','A','A','E','E','E','I','I','I','O','O','O','O','O','U','U','U','U','C','N'];
    $h = str_replace($from, $to, $h);
    return preg_replace('/\s+/', ' ', $h) ?? $h;
}

/**
 * Importa listas base a partir de um ficheiro .xlsx ou .csv.
 *
 * Formato esperado: a linha 1 contém os cabeçalhos (CLIENTE, PROJETO, ...)
 * e cada coluna é uma lista independente, lida de cima para baixo.
 * Células vazias são ignoradas — as colunas podem ter comprimentos diferentes.
 *
 * @param string $mode 'merge'   = acrescenta novos valores, mantém os existentes
 *                     'replace' = desactiva os valores que já não constam do ficheiro
 * @return array Resumo da importação.
 */
function lists_import(string $path, string $originalName, string $mode = 'merge', ?int $userId = null): array
{
    // O nome do ficheiro vem do computador do utilizador e pode não ser UTF-8.
    $originalName = mb_substr(to_utf8($originalName), 0, 255);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $rows = $ext === 'csv' ? csv_read($path) : xlsx_read($path);

    if (!$rows) {
        throw new RuntimeException('O ficheiro está vazio ou não foi possível ler nenhuma linha.');
    }

    $headerRow = array_shift($rows);
    $map = lists_header_map();

    // coluna → código de lista
    $columns = [];
    $unknown = [];
    foreach ($headerRow as $col => $header) {
        $key = lists_norm_header((string)$header);
        if (isset($map[$key])) {
            $columns[$col] = $map[$key];
        } elseif ($header !== '') {
            $unknown[] = (string)$header;
        }
    }
    if (!$columns) {
        throw new RuntimeException(
            'Nenhum cabeçalho reconhecido na primeira linha. Cabeçalhos esperados: '
            . implode(', ', array_column(q_all('SELECT excel_header FROM list_types WHERE excel_header IS NOT NULL'), 'excel_header'))
            . '.'
        );
    }

    // Recolha dos valores por lista.
    $collected = [];
    $rowsRead = 0;
    foreach ($rows as $row) {
        $rowsRead++;
        foreach ($columns as $col => $code) {
            $value = trim((string)($row[$col] ?? ''));
            if ($value === '') {
                continue;
            }
            $collected[$code][$value] = true;
        }
    }

    $stats = ['added' => 0, 'reactivated' => 0, 'deactivated' => 0, 'rows' => $rowsRead,
              'unknown_headers' => $unknown, 'per_list' => []];

    db()->beginTransaction();
    try {
        // Várias colunas podem alimentar a mesma lista (ex.: AJUDANTE 1/2/3),
        // por isso processa-se cada lista uma única vez.
        foreach (array_unique(array_values($columns)) as $code) {
            $values = array_keys($collected[$code] ?? []);
            $existing = [];
            foreach (q_all('SELECT id, value, is_active FROM list_items WHERE type_code = ?', [$code]) as $r) {
                $existing[$r['value']] = $r;
            }

            $listAdded = 0;
            $listReact = 0;
            foreach ($values as $v) {
                if (!isset($existing[$v])) {
                    q('INSERT INTO list_items (type_code, value, source) VALUES (?,?,\'import\')', [$code, $v]);
                    $stats['added']++;
                    $listAdded++;
                } elseif ((int)$existing[$v]['is_active'] === 0) {
                    q('UPDATE list_items SET is_active = 1 WHERE id = ?', [$existing[$v]['id']]);
                    $stats['reactivated']++;
                    $listReact++;
                }
            }

            $listDeact = 0;
            if ($mode === 'replace') {
                foreach ($existing as $value => $r) {
                    if (!in_array($value, $values, true) && (int)$r['is_active'] === 1) {
                        q('UPDATE list_items SET is_active = 0 WHERE id = ?', [$r['id']]);
                        $stats['deactivated']++;
                        $listDeact++;
                    }
                }
            }

            $stats['per_list'][$code] = [
                'no_ficheiro'  => count($values),
                'novos'        => $listAdded,
                'reativados'   => $listReact,
                'desativados'  => $listDeact,
            ];
        }

        q(
            'INSERT INTO import_runs
                (user_id, filename, file_hash, mode, rows_read, items_added, items_reactivated, items_deactivated, status)
             VALUES (?,?,?,?,?,?,?,?,\'ok\')',
            [$userId, $originalName, hash_file('sha256', $path), $mode,
             $stats['rows'], $stats['added'], $stats['reactivated'], $stats['deactivated']]
        );

        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        q(
            'INSERT INTO import_runs (user_id, filename, mode, status, message) VALUES (?,?,?,\'error\',?)',
            [$userId, $originalName, $mode, $ex->getMessage()]
        );
        throw $ex;
    }

    audit('import', 'list_item', null,
          sprintf('Importação de listas (%s): %d novos, %d reativados, %d desativados',
                  $originalName, $stats['added'], $stats['reactivated'], $stats['deactivated']),
          null, $stats['per_list']);

    return $stats;
}
