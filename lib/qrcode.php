<?php
/**
 * Gerador de códigos QR, sem dependências.
 *
 * Existe para que o segredo TOTP nunca saia do servidor: gerar a imagem
 * num serviço externo (api.qrserver.com e afins) significaria entregar a
 * chave de dois fatores de cada utilizador a terceiros.
 *
 * Suporta o modo byte (o suficiente para URIs otpauth://) com correção de
 * erros nível M, versões 1 a 20. A saída é SVG — não precisa da extensão
 * GD, que raramente está ligada em alojamento partilhado.
 *
 * Referência: ISO/IEC 18004.
 */

declare(strict_types=1);

/** Códigos de correção de erro por bloco, nível M, versões 1..40. */
const QR_EC_PER_BLOCK_M = [
    0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
    26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28,
];

/** Número de blocos de correção de erro, nível M, versões 1..40. */
const QR_BLOCKS_M = [
    0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16,
    17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49,
];

/** Módulos de dados em bruto (antes de descontar a correção de erros). */
function qr_raw_data_modules(int $version): int
{
    $result = (16 * $version + 128) * $version + 64;
    if ($version >= 2) {
        $numAlign = intdiv($version, 7) + 2;
        $result -= (25 * $numAlign - 10) * $numAlign - 55;
        if ($version >= 7) {
            $result -= 36;
        }
    }
    return $result;
}

/** Codewords disponíveis para dados, no nível M. */
function qr_data_codewords(int $version): int
{
    return intdiv(qr_raw_data_modules($version), 8)
         - QR_EC_PER_BLOCK_M[$version] * QR_BLOCKS_M[$version];
}

/** Multiplicação em GF(256), polinómio 0x11D. */
function qr_gf_mul(int $a, int $b): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($b >> $i) & 1) * $a;
        $z &= 0xFF;
    }
    return $z;
}

/** Divisor de Reed-Solomon de grau $degree. */
function qr_rs_divisor(int $degree): array
{
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;

    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = qr_gf_mul($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = qr_gf_mul($root, 0x02);
    }
    return $result;
}

/** Codewords de correção de erro para um bloco de dados. */
function qr_rs_remainder(array $data, array $divisor): array
{
    $degree = count($divisor);
    $result = array_fill(0, $degree, 0);

    foreach ($data as $b) {
        $factor = $b ^ $result[0];
        array_shift($result);
        $result[] = 0;
        for ($i = 0; $i < $degree; $i++) {
            $result[$i] ^= qr_gf_mul($divisor[$i], $factor);
        }
    }
    return $result;
}

/** Posições centrais dos padrões de alinhamento. */
function qr_alignment_positions(int $version): array
{
    if ($version === 1) {
        return [];
    }
    $numAlign = intdiv($version, 7) + 2;
    $step = $version === 32
        ? 26
        : intdiv(($version * 4 + $numAlign * 2 + 1), ($numAlign * 2 - 2)) * 2;

    $tail = [];
    $pos  = 4 * $version + 10;      // = size - 7
    while (count($tail) < $numAlign - 1) {
        $tail[] = $pos;
        $pos -= $step;
    }
    return array_merge([6], array_reverse($tail));
}

/**
 * Matriz do código QR.
 *
 * @return array<int, array<int, bool>> [linha][coluna], true = módulo escuro
 */
function qr_matrix(string $text): array
{
    $len = strlen($text);

    // 1. Versão mais pequena que comporta o texto.
    $version = 0;
    for ($v = 1; $v <= 20; $v++) {
        $charBits = $v <= 9 ? 8 : 16;
        $capacity = qr_data_codewords($v) * 8 - 4 - $charBits;
        if ($len * 8 <= $capacity) {
            $version = $v;
            break;
        }
    }
    if ($version === 0) {
        throw new RuntimeException('Texto demasiado longo para um código QR (máximo suportado: versão 20).');
    }

    $size = 17 + 4 * $version;

    // 2. Bits: modo byte (0100) + contagem + dados.
    $bits = '';
    $bits .= '0100';
    $bits .= str_pad(decbin($len), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    }

    $capacityBits = qr_data_codewords($version) * 8;
    $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

    // Preenchimento alternado até encher a capacidade.
    $pad = ['11101100', '00010001'];
    for ($i = 0; strlen($bits) < $capacityBits; $i++) {
        $bits .= $pad[$i % 2];
    }

    $dataCodewords = [];
    foreach (str_split($bits, 8) as $byte) {
        $dataCodewords[] = bindec($byte);
    }

    // 3. Blocos + Reed-Solomon, intercalados.
    $numBlocks = QR_BLOCKS_M[$version];
    $ecLen     = QR_EC_PER_BLOCK_M[$version];
    $totalCw   = intdiv(qr_raw_data_modules($version), 8);
    $shortLen  = intdiv($totalCw, $numBlocks) - $ecLen;
    $numShort  = $numBlocks - $totalCw % $numBlocks;

    $divisor = qr_rs_divisor($ecLen);
    $blocks = [];
    $offset = 0;
    for ($i = 0; $i < $numBlocks; $i++) {
        $take = $shortLen + ($i < $numShort ? 0 : 1);
        $dat  = array_slice($dataCodewords, $offset, $take);
        $offset += $take;
        $blocks[] = ['data' => $dat, 'ec' => qr_rs_remainder($dat, $divisor)];
    }

    $interleaved = [];
    $maxData = $shortLen + 1;
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as $bi => $b) {
            // O codeword extra dos blocos curtos não existe: salta-se.
            if ($i === $shortLen && $bi < $numShort) {
                continue;
            }
            if (isset($b['data'][$i])) {
                $interleaved[] = $b['data'][$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($blocks as $b) {
            $interleaved[] = $b['ec'][$i];
        }
    }

    // 4. Desenho: padrões de função primeiro, para saber onde não escrever.
    $m    = array_fill(0, $size, array_fill(0, $size, false));
    $used = array_fill(0, $size, array_fill(0, $size, false));

    $set = static function (int $r, int $c, bool $dark) use (&$m, &$used, $size): void {
        if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
            return;
        }
        $m[$r][$c]    = $dark;
        $used[$r][$c] = true;
    };

    // Localizadores + separadores.
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$fr, $fc]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $inRing = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                       || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                $inCore = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                $set($fr + $r, $fc + $c, $inRing || $inCore);
            }
        }
    }

    // Temporização.
    for ($i = 8; $i < $size - 8; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }

    // Alinhamento (não sobrepõe os localizadores).
    $aligns = qr_alignment_positions($version);
    $n = count($aligns);
    foreach ($aligns as $i => $ar) {
        foreach ($aligns as $j => $ac) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                continue;
            }
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $set($ar + $dr, $ac + $dc, max(abs($dr), abs($dc)) !== 1);
                }
            }
        }
    }

    // Espaço reservado ao formato (preenchido depois) e módulo escuro.
    // O índice 6 é saltado: pertence às linhas de temporização, já desenhadas.
    for ($i = 0; $i <= 8; $i++) {
        if ($i === 6) {
            continue;
        }
        $set(8, $i, false);
        $set($i, 8, false);
    }
    for ($i = 0; $i < 8; $i++) {
        $set(8, $size - 1 - $i, false);
        $set($size - 1 - $i, 8, false);
    }
    $set($size - 8, 8, true);

    // Informação de versão (v >= 7).
    if ($version >= 7) {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $vbits = ($version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($vbits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $set($a, $b, $bit);
            $set($b, $a, $bit);
        }
    }

    // 5. Dados em ziguezague, de baixo para cima, da direita para a esquerda.
    $bitIndex = 0;
    $total    = count($interleaved) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;   // a coluna 6 é de temporização
        }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($k = 0; $k < 2; $k++) {
                $c  = $right - $k;
                $up = ((($right + 1) & 2) === 0);
                $r  = $up ? $size - 1 - $vert : $vert;
                if ($used[$r][$c]) {
                    continue;
                }
                $dark = false;
                if ($bitIndex < $total) {
                    $dark = (($interleaved[$bitIndex >> 3] >> (7 - ($bitIndex & 7))) & 1) === 1;
                    $bitIndex++;
                }
                $m[$r][$c] = $dark;
            }
        }
    }

    // 6. Máscara: aplica as 8 e fica com a de menor penalização.
    $best = null;
    $bestPenalty = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $cand = qr_apply_mask($m, $used, $size, $mask);
        qr_draw_format($cand, $size, $mask);
        $p = qr_penalty($cand, $size);
        if ($p < $bestPenalty) {
            $bestPenalty = $p;
            $best = $cand;
        }
    }
    return $best;
}

/** Aplica uma máscara aos módulos de dados. */
function qr_apply_mask(array $m, array $used, int $size, int $mask): array
{
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($used[$r][$c]) {
                continue;
            }
            switch ($mask) {
                case 0: $inv = ($r + $c) % 2 === 0; break;
                case 1: $inv = $r % 2 === 0; break;
                case 2: $inv = $c % 3 === 0; break;
                case 3: $inv = ($r + $c) % 3 === 0; break;
                case 4: $inv = (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0; break;
                case 5: $inv = ($r * $c) % 2 + ($r * $c) % 3 === 0; break;
                case 6: $inv = (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0; break;
                default: $inv = ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0; break;
            }
            if ($inv) {
                $m[$r][$c] = !$m[$r][$c];
            }
        }
    }
    return $m;
}

/** Escreve os bits de formato (nível M + máscara). */
function qr_draw_format(array &$m, int $size, int $mask): void
{
    $data = (0 << 3) | $mask;          // nível M = 0
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
    }
    $bitsVal = (($data << 10) | $rem) ^ 0x5412;

    $bit = static fn(int $i): bool => (($bitsVal >> $i) & 1) === 1;

    // Primeira cópia, à volta do localizador superior esquerdo.
    for ($i = 0; $i <= 5; $i++) {
        $m[$i][8] = $bit($i);
    }
    $m[7][8] = $bit(6);
    $m[8][8] = $bit(7);
    $m[8][7] = $bit(8);
    for ($i = 9; $i < 15; $i++) {
        $m[8][14 - $i] = $bit($i);
    }

    // Segunda cópia, repartida pelos outros dois cantos.
    for ($i = 0; $i < 8; $i++) {
        $m[8][$size - 1 - $i] = $bit($i);
    }
    for ($i = 8; $i < 15; $i++) {
        $m[$size - 15 + $i][8] = $bit($i);
    }
    $m[$size - 8][8] = true;   // módulo sempre escuro
}

/** Penalização de uma matriz mascarada (regras N1..N4). */
function qr_penalty(array $m, int $size): int
{
    $penalty = 0;

    // N1: cinco ou mais módulos iguais em linha/coluna.
    for ($r = 0; $r < $size; $r++) {
        $run = 1;
        for ($c = 1; $c < $size; $c++) {
            if ($m[$r][$c] === $m[$r][$c - 1]) {
                $run++;
                if ($run === 5) {
                    $penalty += 3;
                } elseif ($run > 5) {
                    $penalty++;
                }
            } else {
                $run = 1;
            }
        }
    }
    for ($c = 0; $c < $size; $c++) {
        $run = 1;
        for ($r = 1; $r < $size; $r++) {
            if ($m[$r][$c] === $m[$r - 1][$c]) {
                $run++;
                if ($run === 5) {
                    $penalty += 3;
                } elseif ($run > 5) {
                    $penalty++;
                }
            } else {
                $run = 1;
            }
        }
    }

    // N2: blocos 2x2 da mesma cor.
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                $penalty += 3;
            }
        }
    }

    // N3: padrão 1:1:3:1:1 rodeado de claro (falso localizador).
    $needle  = [true, false, true, true, true, false, true];
    $light4  = [false, false, false, false];
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c <= $size - 7; $c++) {
            if (array_slice($m[$r], $c, 7) !== $needle) {
                continue;
            }
            if ($c >= 4 && array_slice($m[$r], $c - 4, 4) === $light4) {
                $penalty += 40;
            }
            if (array_slice($m[$r], $c + 7, 4) === $light4) {
                $penalty += 40;
            }
        }
    }
    for ($c = 0; $c < $size; $c++) {
        $col = [];
        for ($r = 0; $r < $size; $r++) {
            $col[] = $m[$r][$c];
        }
        for ($r = 0; $r <= $size - 7; $r++) {
            if (array_slice($col, $r, 7) !== $needle) {
                continue;
            }
            if ($r >= 4 && array_slice($col, $r - 4, 4) === $light4) {
                $penalty += 40;
            }
            if (array_slice($col, $r + 7, 4) === $light4) {
                $penalty += 40;
            }
        }
    }

    // N4: desequilíbrio entre escuros e claros.
    $dark = 0;
    for ($r = 0; $r < $size; $r++) {
        $dark += count(array_filter($m[$r]));
    }
    $total = $size * $size;
    $k = (int)(abs($dark * 20 - $total * 10) / $total);
    $penalty += $k * 10;

    return $penalty;
}

/**
 * Código QR em SVG, pronto a ser embebido numa página.
 *
 * @param int $scale  lado de cada módulo, em pixels
 * @param int $border zona clara à volta, em módulos (o mínimo da norma é 4)
 */
function qr_svg(string $text, int $scale = 4, int $border = 4, string $alt = ''): string
{
    $m    = qr_matrix($text);
    $size = count($m);
    $dim  = ($size + $border * 2) * $scale;

    $path = '';
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($m[$r][$c]) {
                $path .= 'M' . (($c + $border) * $scale) . ',' . (($r + $border) * $scale)
                       . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '"'
         . ' viewBox="0 0 ' . $dim . ' ' . $dim . '" role="img"'
         . ' aria-label="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">'
         . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
         . '<path d="' . $path . '" fill="#000000"/>'
         . '</svg>';
}
