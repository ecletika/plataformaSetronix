<?php
/**
 * Leitor de folhas .xlsx, só com o que vem no PHP.
 *
 * Um .xlsx é um ZIP com XML lá dentro. Isso chega para ler o que
 * precisamos — valores, datas e a cor de fundo de cada célula — sem
 * trazer uma biblioteca de fora, que nesta plataforma não há.
 *
 * Não é um leitor de Excel: não faz fórmulas, não faz gráficos, não
 * escreve nada. Lê uma folha e devolve as células.
 *
 * A cor de fundo importa: no mapa de atividade é ela que distingue um
 * feriado de um dia normal, e essa informação não está em mais lado
 * nenhum do ficheiro.
 */

/**
 * Abre o ZIP e devolve o conteúdo dos ficheiros de que precisamos.
 *
 * Usa a extensão zip quando existe. Quando não existe — acontece em
 * alojamentos partilhados — lê o ZIP à mão. O formato do ZIP é simples
 * e o gzinflate() vem sempre com o PHP, por isso não vale a pena
 * depender de uma extensão que pode faltar no dia do arranque.
 */
function xlsx_ler_zip(string $caminho, array $queremos): array
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($caminho) === true) {
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nome = (string)$zip->getNameIndex($i);
                if (xlsx_interessa($nome, $queremos)) {
                    $out[$nome] = (string)$zip->getFromIndex($i);
                }
            }
            $zip->close();
            if ($out) {
                return $out;
            }
        }
    }
    return xlsx_ler_zip_a_mao($caminho, $queremos);
}

/** O nome interessa-nos? Aceita prefixos, para apanhar as folhas todas. */
function xlsx_interessa(string $nome, array $queremos): bool
{
    foreach ($queremos as $q) {
        if ($nome === $q || (substr($q, -1) === '/' && strncmp($nome, $q, strlen($q)) === 0)) {
            return true;
        }
    }
    return false;
}

/**
 * ZIP sem a extensão zip.
 *
 * Percorre o directório central, que está no fim do ficheiro e lista
 * tudo o que lá está dentro. Só se descomprime o que interessa.
 */
function xlsx_ler_zip_a_mao(string $caminho, array $queremos): array
{
    $dados = (string)file_get_contents($caminho);
    $fim   = strrpos($dados, "PK\x05\x06");
    if ($fim === false) {
        throw new RuntimeException('O ficheiro não é um .xlsx válido (não parece um ZIP).');
    }
    $total  = unpack('v', substr($dados, $fim + 10, 2))[1];
    $inicio = unpack('V', substr($dados, $fim + 16, 4))[1];

    $out = [];
    $p   = $inicio;
    for ($i = 0; $i < $total; $i++) {
        if (substr($dados, $p, 4) !== "PK\x01\x02") {
            break;
        }
        $c        = unpack('vmetodo/vtempo/vdata/Vcrc/Vcomp/Vdescomp/vnome/vextra/vcoment',
                           substr($dados, $p + 10, 20));
        $desloca  = unpack('V', substr($dados, $p + 42, 4))[1];
        $nome     = substr($dados, $p + 46, $c['nome']);
        $p       += 46 + $c['nome'] + $c['extra'] + $c['coment'];

        if (!xlsx_interessa($nome, $queremos)) {
            continue;
        }
        // No cabeçalho local os campos de nome e extra podem ter outro
        // tamanho: os dados começam a seguir a eles, não onde o directório
        // central diria.
        $lh = unpack('vnome/vextra', substr($dados, $desloca + 26, 4));
        $ini = $desloca + 30 + $lh['nome'] + $lh['extra'];
        $bruto = substr($dados, $ini, $c['comp']);
        $out[$nome] = $c['metodo'] === 0 ? $bruto : (string)gzinflate($bruto);
    }
    return $out;
}

/**
 * Lê uma folha e devolve as células.
 *
 * @return array{celulas: array, linhas: int, colunas: int}
 *         celulas[$linha][$coluna] = ['v' => valor, 'cor' => 'FFRRGGBB'|null]
 *         O valor é string, float, ou DateTimeImmutable quando a célula
 *         está formatada como data.
 */
function xlsx_folha(string $caminho, int $indice = 0): array
{
    $f = xlsx_ler_zip($caminho, [
        'xl/sharedStrings.xml', 'xl/styles.xml', 'xl/workbook.xml', 'xl/worksheets/',
    ]);

    $folhas = [];
    foreach ($f as $nome => $_) {
        if (strncmp($nome, 'xl/worksheets/sheet', 19) === 0) {
            $folhas[] = $nome;
        }
    }
    sort($folhas, SORT_NATURAL);
    if (!isset($folhas[$indice])) {
        throw new RuntimeException('O ficheiro não tem folha nenhuma para ler.');
    }

    $textos = xlsx_textos($f['xl/sharedStrings.xml'] ?? '');
    [$ehData, $corDoEstilo] = xlsx_estilos($f['xl/styles.xml'] ?? '');

    return xlsx_celulas($f[$folhas[$indice]], $textos, $ehData, $corDoEstilo);
}

/** A tabela de textos partilhados: o Excel guarda-os fora das células. */
function xlsx_textos(string $xml): array
{
    if ($xml === '') {
        return [];
    }
    $out = [];
    $doc = xlsx_xml($xml);
    foreach ($doc->si as $si) {
        // Um texto pode vir partido em pedaços com formatações diferentes.
        $t = '';
        foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $p) {
            $t .= (string)$p;
        }
        $out[] = $t;
    }
    return $out;
}

/**
 * Dos estilos, duas coisas: se o estilo é uma data, e a sua cor de fundo.
 *
 * @return array{0: array<int,bool>, 1: array<int,?string>}
 */
function xlsx_estilos(string $xml): array
{
    $ehData = [];
    $cores  = [];
    if ($xml === '') {
        return [$ehData, $cores];
    }
    $doc = xlsx_xml($xml);

    // Formatos de número. Os 14..22 e 45..47 são datas e horas do próprio
    // Excel; os outros dizem-se pelo formato, que tem d, m ou y.
    $formatos = [];
    foreach ($doc->xpath('//*[local-name()="numFmt"]') ?: [] as $n) {
        $formatos[(int)$n['numFmtId']] = (string)$n['formatCode'];
    }
    $dataQ = static function (int $id) use ($formatos): bool {
        if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47)) {
            return true;
        }
        $f = $formatos[$id] ?? '';
        // Tirar o texto entre aspas antes de procurar as letras da data.
        $f = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $f);
        return $f !== '' && preg_match('/[dmyhs]/i', $f) === 1;
    };

    // Preenchimentos, pela ordem em que aparecem: é o índice que conta.
    $fills = [];
    foreach ($doc->xpath('//*[local-name()="fills"]/*[local-name()="fill"]') ?: [] as $fill) {
        $cor = null;
        $pat = $fill->xpath('./*[local-name()="patternFill"]');
        if ($pat) {
            $tipo = (string)$pat[0]['patternType'];
            $fg   = $pat[0]->xpath('./*[local-name()="fgColor"]');
            if ($tipo !== '' && $tipo !== 'none' && $fg && isset($fg[0]['rgb'])) {
                $cor = strtoupper((string)$fg[0]['rgb']);
            }
        }
        $fills[] = $cor;
    }

    $i = 0;
    foreach ($doc->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]') ?: [] as $xf) {
        $ehData[$i] = $dataQ((int)$xf['numFmtId']);
        $cores[$i]  = $fills[(int)$xf['fillId']] ?? null;
        $i++;
    }
    return [$ehData, $cores];
}

/** Percorre as células da folha. */
function xlsx_celulas(string $xml, array $textos, array $ehData, array $cores): array
{
    $doc     = xlsx_xml($xml);
    $celulas = [];
    $maxL = $maxC = 0;

    foreach ($doc->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $c) {
            [$linha, $coluna] = xlsx_ref((string)$c['r']);
            if ($linha === 0) {
                continue;
            }
            $estilo = isset($c['s']) ? (int)$c['s'] : -1;
            $tipo   = (string)$c['t'];

            $valor = null;
            if ($tipo === 'inlineStr') {
                $t = $c->xpath('.//*[local-name()="t"]');
                $valor = $t ? (string)$t[0] : null;
            } else {
                $v = $c->xpath('./*[local-name()="v"]');
                if ($v) {
                    $bruto = (string)$v[0];
                    if ($tipo === 's') {
                        $valor = $textos[(int)$bruto] ?? '';
                    } elseif ($tipo === 'b') {
                        $valor = $bruto === '1';
                    } elseif ($bruto !== '' && is_numeric($bruto)) {
                        $valor = ($ehData[$estilo] ?? false)
                               ? xlsx_data((float)$bruto)
                               : (float)$bruto;
                    } else {
                        $valor = $bruto;
                    }
                }
            }

            $cor = $cores[$estilo] ?? null;
            if ($valor === null && $cor === null) {
                continue;   // célula sem nada: não vale a pena guardar
            }
            $celulas[$linha][$coluna] = ['v' => $valor, 'cor' => $cor];
            $maxL = max($maxL, $linha);
            $maxC = max($maxC, $coluna);
        }
    }
    return ['celulas' => $celulas, 'linhas' => $maxL, 'colunas' => $maxC];
}

/** "BC12" fica [12, 55]. */
function xlsx_ref(string $ref): array
{
    if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
        return [0, 0];
    }
    $col = 0;
    foreach (str_split(strtoupper($m[1])) as $ch) {
        $col = $col * 26 + (ord($ch) - 64);
    }
    return [(int)$m[2], $col];
}

/**
 * O número de série do Excel em data.
 *
 * O Excel conta os dias a partir de 1900-01-01 e acredita que 1900 foi
 * bissexto, o que não foi. O erro é antigo e está lá de propósito, para
 * compatibilidade; a correcção é descontar um dia acima do 59.
 */
function xlsx_data(float $serie): ?DateTimeImmutable
{
    if ($serie < 1) {
        return null;
    }
    $dias = (int)floor($serie);
    if ($dias > 59) {
        $dias--;
    }
    $base = new DateTimeImmutable('1899-12-31 00:00:00', new DateTimeZone('UTC'));
    $seg  = (int)round(($serie - floor($serie)) * 86400);
    return $base->modify('+' . $dias . ' day')->modify('+' . $seg . ' second');
}

/** XML sem deixar entrar entidades externas. */
function xlsx_xml(string $xml): SimpleXMLElement
{
    $antes = libxml_use_internal_errors(true);
    $doc   = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
    libxml_clear_errors();
    libxml_use_internal_errors($antes);
    if ($doc === false) {
        throw new RuntimeException('O ficheiro está corrompido: não foi possível ler o XML lá dentro.');
    }
    return $doc;
}
