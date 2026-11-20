// Dump the holders' salary lines from the monitorul.ai asset declarations (ANI).
// Browser version of ops/integritate-dump.py: same query, same CSV.
//
// Use it when you have no session cookie or API key for the Python script.
// Paste it into the DevTools console of a signed-in tab of kibana.sicap.ai
// (space "integritate"). Nothing leaves the browser except the final download.
//
//   integritateDump.start()    // pages through ~1.4 M declarations, ~15 min
//   integritateDump.status()   // progress
//   integritateDump.stop()     // stops after the current page
//   integritateDump.download() // saves integritate-salarii-raw-<date>.csv.gz
//
// See docs/audits/2026-11-20-salary-declaratii-kibana.md.
(() => {
  const H = {
    'kbn-xsrf': 'x',
    'x-elastic-internal-origin': 'Kibana',
    'Content-Type': 'application/json',
    'elastic-api-version': '1',
  };
  const PAGE = 2000;
  // Keep in step with COLS in ops/integritate-dump.py.
  const COLS = ['aid', 'an', 'data_completare_iso', 'source', 'functie', 'institutie_id',
    'institutie', 'institutie_norm', 'judet_norm', 'localitate', 'tip_institutie',
    'n_lines', 'sursa', 'serviciul_prestat', 'suma', 'valuta'];
  const QUERY = {
    bool: {
      filter: [
        { term: { 'tip.keyword': 'AVERE' } },
        { term: { 'extraction_meta.status.keyword': 'ok' } },
        { term: { 'extraction.venituri.categorie.keyword': 'salarii' } },
      ],
    },
  };
  const SOURCE = ['aid', 'an', 'data_completare_iso', 'source', 'functie', 'institutie',
    'institutie_id', 'institutie_norm', 'judet_norm', 'localitate', 'tip_institutie',
    'extraction.venituri.categorie', 'extraction.venituri.sursa',
    'extraction.venituri.serviciul_prestat', 'extraction.venituri.beneficiar_tip',
    'extraction.venituri.venit_anual'];

  const csv = (v) => {
    const s = v == null ? '' : String(v);
    return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const st = {
    running: false, done: false, stopped: false, error: null, total: null,
    pages: 0, docs: 0, rows: 0, docs_without_holder_line: 0,
    after: null, startedAt: null, finishedAt: null,
  };
  const chunks = [COLS.join(',') + '\n'];

  async function page() {
    // `aid` is unique only within a portal: sort on (aid, source).
    const body = { size: PAGE, query: QUERY, _source: SOURCE, sort: [{ aid: 'asc' }, { 'source.keyword': 'asc' }] };
    if (st.total === null) body.track_total_hits = true;
    if (st.after) body.search_after = st.after;
    for (let attempt = 1; ; attempt++) {
      try {
        const r = await fetch('/s/integritate/internal/search/es', {
          method: 'POST', headers: H,
          body: JSON.stringify({ params: { index: 'integritate', body } }),
        });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return (await r.json()).rawResponse;
      } catch (e) {
        if (attempt >= 5) throw e;
        await sleep(2000 * attempt);
      }
    }
  }

  // One row per income line of category "salarii" whose beneficiary is the holder.
  function rows(src) {
    const lines = ((src.extraction && src.extraction.venituri) || []).filter((v) =>
      v.categorie === 'salarii' && (v.beneficiar_tip || '').toLowerCase() === 'titular');
    if (!lines.length) { st.docs_without_holder_line++; return ''; }
    return lines.map((v) => {
      const va = v.venit_anual || {};
      const row = {
        ...src, n_lines: lines.length, sursa: v.sursa,
        serviciul_prestat: v.serviciul_prestat, suma: va.suma, valuta: va.valuta,
      };
      st.rows++;
      return COLS.map((c) => csv(row[c])).join(',') + '\n';
    }).join('');
  }

  async function run() {
    st.running = true; st.startedAt = new Date().toISOString();
    try {
      while (!st.stopped) {
        const res = await page();
        if (st.total === null) st.total = res.hits.total.value;
        const hits = res.hits.hits;
        if (!hits.length) { st.done = true; break; }
        chunks.push(hits.map((h) => rows(h._source)).join(''));
        st.docs += hits.length; st.pages++;
        st.after = hits[hits.length - 1].sort;
        await sleep(150);
      }
    } catch (e) {
      st.error = String(e);
    } finally {
      st.running = false; st.finishedAt = new Date().toISOString();
    }
  }

  async function download() {
    const blob = new Blob(chunks, { type: 'text/csv' });
    const gz = await new Response(blob.stream().pipeThrough(new CompressionStream('gzip'))).blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(gz);
    a.download = 'integritate-salarii-raw-' + new Date().toISOString().slice(0, 10) + '.csv.gz';
    document.body.appendChild(a); a.click(); a.remove();
    return { name: a.download, rawBytes: blob.size, gzBytes: gz.size };
  }

  window.integritateDump = {
    start: () => { if (!st.running && !st.done) { st.stopped = false; run(); } return 'started'; },
    stop: () => { st.stopped = true; return 'stopping'; },
    status: () => JSON.parse(JSON.stringify(st)),
    download,
  };
})();
