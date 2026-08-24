<?php
/**
 * Leitor de ficheiros .xlsx em PHP puro (ZipArchive + SimpleXML).
 * Sem Composer, sem PhpSpreadsheet — funciona num cPanel normal.
 *
 * Lê apenas valores (não fórmulas calculadas em falta, não formatação).
 * Suporta sharedStrings, inline strings e datas de série do Excel.
 */

declare(strict_types=1);

/**
 * Lê a primeira folha (ou a folha indicada) de um .xlsx.
 *
 * @param string   $path       Caminho do ficheiro.
 * @param int      $sheetIndex Índice da folha (0 = primeira).
 * @return array<int, array<int, string>> Matriz de linhas → colunas (0-based).
 * @throws RuntimeException
 */
function xlsx_read(string $path, int $sheetIndex = 0): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('A extensão PHP "zip" não está disponível no servidor. Ative-a no cPanel (Select PHP Version → Extensions → zip) ou importe os dados em CSV.');
    }
    if (!is_file($path)) {
        throw new RuntimeException('Ficheiro não encontrado.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Não foi possível abrir o ficheiro. Confirme que é um .xlsx válido.');
    }

    try {
        $sharedStrings = xlsx_shared_strings($zip);
        $sheetPath     = xlsx_sheet_path($zip, $sheetIndex);

        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) {
            throw new RuntimeException('Folha de cálculo não encontrada dentro do ficheiro.');
        }

        $prev = libxml_use_internal_errors(true);
        $sheet = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($sheet === false) {
            throw new RuntimeException('Conteúdo XML da folha inválido.');
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rowIndex = (int)($row['r'] ?? (count($rows) + 1)) - 1;
            $cells = [];
            foreach ($row->c as $c) {
                $ref  = (string)($c['r'] ?? '');
                $col  = xlsx_column_index($ref);
                $type = (string)($c['t'] ?? '');

                if ($type === 'inlineStr') {
                    $value = trim((string)($c->is->t ?? ''));
                } elseif ($type === 's') {
                    $idx = (int)((string)$c->v);
                    $value = $sharedStrings[$idx] ?? '';
                } else {
                    $value = trim((string)($c->v ?? ''));
                }

                if ($value !== '') {
                    $cells[$col] = $value;
                }
            }
            if ($cells) {
                ksort($cells);
                $rows[$rowIndex] = $cells;
            }
        }
        ksort($rows);
        return array_values($rows);
    } finally {
        $zip->close();
    }
}

/** Tabela de strings partilhadas do livro. */
function xlsx_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }
    $prev = libxml_use_internal_errors(true);
    $sst = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($sst === false) {
        return [];
    }

    $out = [];
    foreach ($sst->si as $si) {
        if (isset($si->t)) {
            $out[] = trim((string)$si->t);
        } else {
            // Texto com formatação mista (vários <r><t>...</t></r>).
            $buf = '';
            foreach ($si->r as $r) {
                $buf .= (string)$r->t;
            }
            $out[] = trim($buf);
        }
    }
    return $out;
}

/** Caminho interno da folha n (respeita a ordem declarada em workbook.xml). */
function xlsx_sheet_path(ZipArchive $zip, int $index): string
{
    $default = 'xl/worksheets/sheet' . ($index + 1) . '.xml';

    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml === false || $relXml === false) {
        return $default;
    }

    $prev = libxml_use_internal_errors(true);
    $wb  = simplexml_load_string($wbXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    $rel = simplexml_load_string($relXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($wb === false || $rel === false) {
        return $default;
    }

    $map = [];
    foreach ($rel->Relationship as $r) {
        $map[(string)$r['Id']] = (string)$r['Target'];
    }

    $i = 0;
    foreach ($wb->sheets->sheet as $s) {
        if ($i === $index) {
            $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            $target = $map[$rid] ?? '';
            if ($target === '') {
                return $default;
            }
            $target = ltrim($target, '/');
            return strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
        }
        $i++;
    }
    return $default;
}

/** Converte a referência de célula ("C7") no índice de coluna 0-based (2). */
function xlsx_column_index(string $ref): int
{
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $ref) ?? '');
    if ($letters === '') {
        return 0;
    }
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/**
 * Lê um CSV com detecção de separador (; ou ,) e BOM UTF-8.
 * Alternativa ao .xlsx quando a extensão zip não está disponível.
 */
function csv_read(string $path): array
{
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('Não foi possível abrir o ficheiro CSV.');
    }
    $first = fgets($fh) ?: '';
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
    $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    rewind($fh);

    $rows = [];
    $line = 0;
    while (($cells = fgetcsv($fh, 0, $sep)) !== false) {
        if ($line === 0 && isset($cells[0])) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cells[0]);
        }
        $line++;
        $clean = [];
        foreach ($cells as $i => $v) {
            // Um CSV gravado pelo Excel em Portugal vem normalmente em
            // Windows-1252, não em UTF-8.
            $v = trim(to_utf8((string)$v));
            if ($v !== '') {
                $clean[$i] = $v;
            }
        }
        if ($clean) {
            $rows[] = $clean;
        }
    }
    fclose($fh);
    return $rows;
}
