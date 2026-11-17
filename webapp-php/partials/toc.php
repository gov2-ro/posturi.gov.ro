<?php
/**
 * The table of contents of a long page (Statistici, Despre): one link for each section heading (`<h2>`).
 * The page builds `$toc` once and prints each heading with toc_h2(), so the list and the headings cannot differ.
 * From `lg` it is a sticky left column (see `.pg-toc-layout` in assets/app.css); below `lg` a block above the content.
 * static/toc.js marks the link of the section in view with `aria-current="true"`; without script the list works.
 * The server output is the same for each reader.
 *
 * In: $toc = array<string heading id, string label>, in page order
 */
declare(strict_types=1);
?>
<nav aria-label="Cuprins" class="pg-toc" data-toc>
  <p class="pg-toc-title">Cuprins</p>
  <ul>
    <?php foreach ($toc as $_toc_id => $_toc_label): ?>
    <li><a href="#<?= e($_toc_id) ?>"><?= e($_toc_label) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
