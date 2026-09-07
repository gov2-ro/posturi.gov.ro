</main>

<footer class="border-t border-border-warm mt-12 py-6 text-center text-xs text-ink-muted font-mono space-y-1">
  <?php if (isset($_last_updated_fmt) && $_last_updated_fmt): ?>
  <p>Date actualizate la <?= $_last_updated_fmt ?> · sursă: <a href="https://posturi.gov.ro" rel="noopener" class="text-gov hover:underline">posturi.gov.ro</a></p>
  <?php endif; ?>
  <p>Explorator independent, fără afiliere oficială</p>
  <p class="px-4">WIP / MVP — versiune în lucru. <b>Nu este un proiect oficial</b> al Guvernului României.
    <a href="https://forms.gle/96WusM2qr4pbUXhW7" class="text-gov underline hover:no-underline" target="_blank" rel="noopener">Acceptăm sugestii</a> (gForm) ·
    <a href="https://github.com/gov2-ro/posturi.gov.ro" class="text-gov underline hover:no-underline" target="_blank" rel="noopener">cod sursă</a> ·
    <a href="/despre/" class="text-gov underline hover:no-underline">metodologie</a>
  </p>
</footer>

</body>
</html>
