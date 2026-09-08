</main>

<footer class="border-t border-line mt-12 py-6 text-center text-xs text-ink-muted font-mono space-y-1">
  <?php if (isset($_last_updated_fmt) && $_last_updated_fmt): ?>
  <p>Date actualizate la
    <time datetime="<?= e($_last_updated_iso ?? '') ?>"<?= !empty($_build_tooltip) ? ' title="' . e($_build_tooltip) . '" class="cursor-help"' : '' ?>><?= $_last_updated_fmt ?></time>
    · sursă: <a href="https://posturi.gov.ro" rel="noopener" class="text-gov hover:underline">posturi.gov.ro</a></p>
  <?php endif; ?>
  <p>Explorator independent, fără afiliere oficială</p>
  <p class="px-4">WIP / MVP — versiune în lucru. <b>Nu este un proiect oficial</b> al Guvernului României.
    <a href="https://forms.gle/96WusM2qr4pbUXhW7" class="text-gov underline hover:no-underline" target="_blank" rel="noopener">Acceptăm sugestii</a> (gForm) ·
    <a href="https://github.com/gov2-ro/posturi.gov.ro" class="text-gov underline hover:no-underline" target="_blank" rel="noopener">cod sursă</a> ·
    <a href="/despre/" class="text-gov underline hover:no-underline">metodologie</a>
  </p>
  <p class="flex items-center justify-center gap-2 pt-1">
    <label for="skin-picker" class="text-ink-faint">Stil vizual</label>
    <?= pg_skin_select() ?>
  </p>
</footer>

</body>
</html>
