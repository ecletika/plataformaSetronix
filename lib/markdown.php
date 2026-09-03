<?php
/**
 * Renderizador de Markdown, do subconjunto usado no manual (ajuda.md).
 *
 * Existe para haver uma só fonte da documentação: o texto vive num ficheiro
 * .md, que se edita como texto simples, e a página de ajuda mostra-o. Sem
 * isto, o manual passaria a existir em dois sítios e ficariam diferentes.
 *
 * Suporta: títulos, parágrafos, listas, tabelas, citações, traços de
 * separação, e — dentro da linha — negrito, itálico e código.
 */

declare(strict_types=1);

/** Formatação dentro de uma linha, já com o HTML escapado. */
function md_inline(string $text): string
{
    $out = e($text);
    $out = preg_replace('/`([^`]+)`/', '<code>$1</code>', $out);
    $out = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $out);
    $out = preg_replace('/(?<![\w*])\*([^*]+)\*(?![\w*])/', '<i>$1</i>', $out);
    return (string)$out;
}

/** Um cabeçalho em texto, transformado em identificador para âncoras. */
function md_slug(string $text): string
{
    $s = (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    $s = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $s));
    return trim($s, '-') ?: 'seccao';
}

/**
 * Converte Markdown em HTML.
 *
 * @param array|null $index Preenchido com [nivel, texto, âncora] de cada
 *                          título, para quem quiser montar um sumário.
 */
function md_to_html(string $md, ?array &$index = null): string
{
    $index = [];
    $linhas = preg_split("/\r\n|\n|\r/", $md) ?: [];
    $html   = '';
    $lista  = null;   // 'ul' ou 'ol' enquanto uma lista está aberta
    $paragrafo = [];

    $fecharLista = static function () use (&$lista, &$html): void {
        if ($lista !== null) {
            $html .= '</' . $lista . ">\n";
            $lista = null;
        }
    };
    $fecharParagrafo = static function () use (&$paragrafo, &$html): void {
        if ($paragrafo) {
            $html .= '<p>' . md_inline(implode(' ', $paragrafo)) . "</p>\n";
            $paragrafo = [];
        }
    };

    for ($i = 0; $i < count($linhas); $i++) {
        $linha = rtrim($linhas[$i]);
        $t     = trim($linha);

        if ($t === '') {
            $fecharParagrafo();
            $fecharLista();
            continue;
        }

        // Título
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $fecharParagrafo();
            $fecharLista();
            $n    = strlen($m[1]);
            $txt  = trim($m[2]);
            $slug = md_slug($txt);
            $index[] = ['nivel' => $n, 'texto' => $txt, 'ancora' => $slug];
            $html .= '<h' . $n . ' id="' . e($slug) . '">' . md_inline($txt) . '</h' . $n . ">\n";
            continue;
        }

        // Traço de separação
        if (preg_match('/^-{3,}$/', $t)) {
            $fecharParagrafo();
            $fecharLista();
            $html .= "<hr>\n";
            continue;
        }

        // Tabela: linha de cabeçalho seguida da linha de separação
        if (strpos($t, '|') === 0 && isset($linhas[$i + 1])
            && preg_match('/^\s*\|[\s:|-]+\|\s*$/', $linhas[$i + 1])) {
            $fecharParagrafo();
            $fecharLista();
            $celulas = static fn(string $l): array
                => array_map('trim', explode('|', trim(trim($l), '|')));

            $html .= "<table><thead><tr>";
            foreach ($celulas($t) as $c) {
                $html .= '<th>' . md_inline($c) . '</th>';
            }
            $html .= "</tr></thead><tbody>\n";
            $i += 2;
            while ($i < count($linhas) && strpos(trim($linhas[$i]), '|') === 0) {
                $html .= '<tr>';
                foreach ($celulas($linhas[$i]) as $c) {
                    $html .= '<td>' . md_inline($c) . '</td>';
                }
                $html .= "</tr>\n";
                $i++;
            }
            $i--;
            $html .= "</tbody></table>\n";
            continue;
        }

        // Citação
        if (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $fecharParagrafo();
            $fecharLista();
            $bloco = [trim($m[1])];
            while (isset($linhas[$i + 1]) && preg_match('/^>\s?(.*)$/', trim($linhas[$i + 1]), $m2)) {
                $bloco[] = trim($m2[1]);
                $i++;
            }
            $html .= '<blockquote>' . md_inline(implode(' ', $bloco)) . "</blockquote>\n";
            continue;
        }

        // Lista
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m) || preg_match('/^\d+\.\s+(.*)$/', $t, $m)) {
            $tipo = preg_match('/^\d/', $t) ? 'ol' : 'ul';
            $fecharParagrafo();
            if ($lista !== $tipo) {
                $fecharLista();
                $html .= '<' . $tipo . ">\n";
                $lista = $tipo;
            }
            $html .= '<li>' . md_inline(trim($m[1])) . "</li>\n";
            continue;
        }

        // Texto corrido
        $fecharLista();
        $paragrafo[] = $t;
    }

    $fecharParagrafo();
    $fecharLista();
    return $html;
}
