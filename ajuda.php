<?php
/**
 * Manual da plataforma, dentro da própria plataforma.
 *
 * O texto vive em ajuda.md, na raiz. Para o alterar basta editar esse
 * ficheiro — não é preciso mexer em PHP. O .htaccess impede que seja
 * descarregado diretamente; só é servido por aqui, com sessão iniciada.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/markdown.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login('view');

$ficheiro = APP_ROOT . '/ajuda.md';
$md = is_file($ficheiro) ? (string)file_get_contents($ficheiro) : '';

$indice = [];
$corpo  = $md === ''
    ? '<p>O ficheiro <code>ajuda.md</code> não foi encontrado no servidor.</p>'
    : md_to_html($md, $indice);

layout_head('Ajuda');
?>
<style>
.ajuda{display:grid;grid-template-columns:250px 1fr;gap:22px;align-items:start}
.ajuda-nav{position:sticky;top:18px;max-height:calc(100vh - 40px);overflow:auto;
  background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px}
.ajuda-nav p.t{margin:0 0 10px;font-size:11.5px;font-weight:600;letter-spacing:.06em;
  text-transform:uppercase;color:var(--muted)}
.ajuda-nav a{display:block;padding:5px 8px;border-radius:6px;text-decoration:none;
  color:var(--ink-2);font-size:13px;line-height:1.35}
.ajuda-nav a:hover{background:var(--rail-soft);color:var(--ink)}
.ajuda-nav a.n3{padding-left:18px;font-size:12.5px;color:var(--muted)}
.doc{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:26px 30px;
  box-shadow:0 1px 2px rgba(27,16,22,.05)}
.doc h1{font-size:24px;margin:0 0 6px}
.doc h2{font-size:19px;margin:32px 0 10px;padding-top:16px;border-top:1px solid var(--line)}
.doc h2:first-of-type{border-top:0;padding-top:0;margin-top:22px}
.doc h3{font-size:15px;margin:22px 0 8px;color:var(--ink);text-transform:none;letter-spacing:0}
.doc p{margin:0 0 12px;max-width:74ch;line-height:1.65}
.doc ul,.doc ol{margin:0 0 14px;padding-left:22px;max-width:74ch}
.doc li{margin-bottom:5px;line-height:1.6}
.doc code{background:var(--surface);border:1px solid var(--line);border-radius:4px;
  padding:1px 5px;font-size:12.5px}
.doc blockquote{margin:0 0 14px;padding:11px 15px;background:var(--rail-soft);
  border-left:3px solid var(--rail);border-radius:0 8px 8px 0;max-width:74ch}
.doc blockquote p{margin:0}
.doc table{margin:0 0 16px;border:1px solid var(--line);border-radius:8px;overflow:hidden}
.doc th{position:static}
.doc hr{border:0;border-top:1px solid var(--line);margin:26px 0}
.doc > h2:target,.doc > h3:target{background:var(--rail-soft);border-radius:6px;
  box-shadow:-8px 0 0 var(--rail-soft),8px 0 0 var(--rail-soft)}
@media (max-width:880px){
  .ajuda{grid-template-columns:1fr}
  .ajuda-nav{position:static;max-height:none}
}
@media print{
  header.topbar,.ajuda-nav,footer.foot,.noprint{display:none !important}
  .ajuda{grid-template-columns:1fr}
  .doc{border:0;box-shadow:none;padding:0}
}
</style>

<div class="wrap">
  <div class="actions noprint" style="margin-bottom:14px">
    <h2 style="margin:0;font-size:17px">Ajuda</h2>
    <span class="muted">Como funciona a plataforma, perfil a perfil e botão a botão.</span>
    <button class="btn" type="button" style="margin-left:auto" onclick="window.print()">Imprimir</button>
  </div>

  <div class="ajuda">
    <nav class="ajuda-nav noprint" aria-label="Índice do manual">
      <p class="t">Índice</p>
      <?php foreach ($indice as $h): ?>
        <?php if ($h['nivel'] === 2 || $h['nivel'] === 3): ?>
          <a class="<?= $h['nivel'] === 3 ? 'n3' : '' ?>" href="#<?= e($h['ancora']) ?>">
            <?= e($h['texto']) ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <article class="doc"><?= $corpo ?></article>
  </div>
</div>
<?php layout_foot(); ?>
