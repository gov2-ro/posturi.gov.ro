<?php
require_once __DIR__ . "/skins.php";

// Set by the calling page: $page_title, and optionally $meta_description,
// $canonical_path, $head_extra (raw markup, e.g. JSON-LD).
$_title = isset($page_title) ? $page_title . ' — posturi.gov2.ro' : 'posturi.gov2.ro';

$_description = $meta_description
    ?? 'Explorator independent al anunțurilor de angajare din sectorul public românesc, '
     . 'construit peste datele publice de pe posturi.gov.ro.';

// Canonical drops the query string unless the page asks for a specific path, so
// the thousands of filter permutations all fold into one indexable URL.
$_canonical = site_origin() . ($canonical_path ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$_canonical = preg_replace('/\?.*$/', '', $_canonical);

// Freshness stamp — used in header and footer. FIX-06: the visible stamp is
// the DATABASE GENERATION time (build_meta.built_at, full precision, Bucharest),
// honestly labelled, and the source check is a separate observation —
// build_meta.detail_fetched_at_max, written only from real successful detail
// retrievals (FIX-03). Combining the two — the old behaviour — made a cached
// import read as a source verification that never happened.
if (!isset($_build_stamp)) {
    $_build_stamp = build_time('d.m.Y, H:i');     // '' on exports without build_meta
    $_build_stamp_iso = build_time('Y-m-d\TH:i'); // for <time datetime>
    $_source_label = source_check_label();        // null = no provenance at all
    $_build_tooltip = build_tooltip();
}
?><!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($_title) ?></title>
  <meta name="description" content="<?= e($_description) ?>">
  <link rel="canonical" href="<?= e($_canonical) ?>">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="posturi.gov2.ro">
  <meta property="og:locale" content="ro_RO">
  <meta property="og:title" content="<?= e($_title) ?>">
  <meta property="og:description" content="<?= e($_description) ?>">
  <meta property="og:url" content="<?= e($_canonical) ?>">
  <?php /* UX-04 (D13): one static share image for every page; per-posting cards later. */ ?>
  <meta property="og:image" content="<?= e(site_origin()) ?>/static/og-image.png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="posturi.gov2.ro — posturile din sectorul public, ușor de căutat și filtrat">
  <meta name="twitter:card" content="summary_large_image">

  <link rel="alternate" type="application/rss+xml" title="posturi.gov2.ro — RSS" href="/posturi.rss">
  <link rel="alternate" type="application/atom+xml" title="posturi.gov2.ro — Atom" href="/posturi.atom">
  <link rel="alternate" type="application/json" title="posturi.gov2.ro — JSON" href="/posturi.json">

  <?php /* Preloads follow the default skin, which is what a first visit gets. A
     returning visitor on hartie or govuk preloads a face it will not use — the
     alternative is a cookie round-trip, which would break shared-host page
     caching for the sake of ~45KB on one request.

     Manrope is one variable face doing display, sans and mono in the posturi
     skin, so this is a single preload where the parchment default needed two. */ ?>
  <link rel="preload" href="/static/fonts/manrope-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/static/app.css?v=<?= @filemtime(__DIR__ . '/../static/app.css') ?: '1' ?>">
  <?= pg_skin_links() ?>
  <?= pg_skin_boot() ?>
  <?php /* Result view preference (list / compact = grid / table), applied before first
     paint (no flash). The list page's script owns the switch; the CSS only acts on the
     list at lg+. */ ?>
  <script>try{var l=localStorage.getItem('posturi.layout');if(l==='compact'||l==='table')document.documentElement.dataset.layout=l}catch(e){}</script>
  <?php /* D10: welcome on a first visit or after 90 days away. Every page view
     records the visit; the close button only hides the box for this page. */ ?>
  <script>try{var k='posturi.lastVisit',n=Date.now(),v=+localStorage.getItem(k)||0;if(!v||n-v>7776e6)document.documentElement.dataset.welcome='1';localStorage.setItem(k,String(n))}catch(e){}</script>
  <?php /* getCacheBusterParam gives every HTMX GET its own URL, so a cached
     results fragment can never be served as a page (index.php, BETA-CACHE). */ ?>
  <meta name="htmx-config" content='{"getCacheBusterParam":true}'>
  <script src="/static/htmx.min.js" defer></script>
  <script src="/static/prefs.js?v=<?= @filemtime(__DIR__ . '/../static/prefs.js') ?: '1' ?>" defer></script>
  <?php /* UX-09: one shared script for browser-local save/hide, loaded once here
     rather than copied into the list and detail inline scripts. */ ?>
  <script src="/static/saved.js?v=<?= @filemtime(__DIR__ . '/../static/saved.js') ?: '1' ?>" defer></script>
  <?= $head_extra ?? '' ?>
</head>
<body class="min-h-screen flex flex-col font-sans">
<?php /* Glyph sprite for the save/hide icon buttons (helpers.php pref_controls). */ ?>
<svg width="0" height="0" class="absolute" aria-hidden="true" focusable="false"><defs>
  <symbol id="i-bookmark" viewBox="0 0 24 24"><path d="M6 3h12v18l-6-4.5L6 21z"/></symbol>
  <symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
</defs></svg>

<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-gov focus:px-4 focus:py-2 focus:text-on-gov">
  Sari la conținut
</a>

<?php /* One row: brand + version, the database stamp, nav (BETA-CHROME, D9).
   ────────────────────────────────────────────────────────────────────────────
   The masthead says only what is true of the whole site right now: which
   version this is and when its database was generated. The "not official"
   claim moved to where it matters — the start and end of every posting
   (robots_disclaimer()), the first-visit welcome below, the footer and /despre/
   — and feedback to the [?!] button in the footer. Below sm the stamp and the
   version's commit drop out; the footer still carries the stamp. */ ?>
<?php $_stamp = build_stamp_relative(); $_code = code_version(); ?>
<header class="bg-gov-bar text-on-bar border-b border-gov-bar">
  <div class="w-full px-3 sm:px-6 flex items-center gap-2 sm:gap-4 h-14">
    <a href="/" class="flex shrink-0 items-baseline gap-2 py-2 group">
      <span class="font-display italic text-base sm:text-lg md:text-xl font-semibold text-on-bar leading-none tracking-tight">
        posturi<span class="text-on-bar-accent">.</span>gov<span class="text-on-bar-accent">2</span><span class="text-on-bar-accent">.</span>ro
      </span>
      <span class="text-[11px] text-on-bar-muted font-mono leading-none"<?= $_code !== '' ? ' title="Versiunea codului: ' . e($_code) . '"' : '' ?>>beta<?php if ($_code !== ''): ?><span class="hidden sm:inline"> · <?= e($_code) ?></span><?php endif; ?></span>
    </a>

    <?php /* Below sm there is no room beside the nav; the footer and /despre/ carry the stamp there. */ ?>
    <p class="min-w-0 flex-1 truncate text-center text-xs leading-none text-on-bar-muted">
      <?php if ($_stamp): ?>
      <a href="/despre/#versiunea-datelor" class="hover:text-on-bar"<?= $_build_tooltip ? ' title="' . e($_build_tooltip) . '"' : '' ?>>
        <span class="hidden sm:inline">Bază generată: <time datetime="<?= e($_stamp['iso']) ?>"><?= e($_stamp['long']) ?></time></span>
      </a>
      <?php endif; ?>
    </p>

    <?php /* Statistici and Angajatori moved to /despre/ — they are things you
       read once, not places you navigate between, and the masthead is worth
       more as one unambiguous way back to the listing. */ ?>
    <nav aria-label="Navigare principală" class="shrink-0 flex items-center gap-3 sm:gap-4 text-sm text-on-bar-muted">
      <?php /* UX-09: the count is the browser-local number of saved items; it
         stays hidden until saved.js has read the state. */ ?>
      <a href="/salvate/" class="py-2 hover:text-on-bar transition-colors">Salvate<span data-saved-count hidden class="ml-1 font-mono text-xs"></span></a>
      <a href="/despre/" class="py-2 hover:text-on-bar transition-colors">Despre</a>
    </nav>
  </div>
</header>

<?php /* First-visit welcome (D10). In every cached page, shown only by the
   pre-paint script in <head> (data-welcome on <html>), so a returning reader
   never sees it flash. Shown on a first visit and again after 90 days away;
   any page view counts as a visit. Browser storage, no cookie. */ ?>
<section id="welcome" aria-labelledby="welcome-title" class="welcome border-b border-info-line bg-info text-info-ink">
  <div class="w-full px-4 sm:px-6 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-6 text-sm leading-snug">
    <p class="flex-1">
      <b id="welcome-title" class="font-semibold">Bun venit!</b>
      posturi.gov2.ro este un explorator independent, <b class="font-semibold">neoficial</b>, al anunțurilor de angajare
      din sectorul public, preluate de pe posturi.gov.ro. Suntem în versiune beta: anunțurile sunt restructurate
      automat și pot conține erori — verificați întotdeauna anunțul oficial înainte de a aplica.
      <a href="/despre/" class="underline underline-offset-2 hover:no-underline">Despre site</a>
    </p>
    <button type="button" data-welcome-close
            class="self-start sm:self-auto min-h-[2.5rem] shrink-0 border border-info-line bg-surface px-3 py-2 text-xs font-medium text-ink hover:border-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">Am înțeles</button>
  </div>
</section>
<?php /* Storage problems (unavailable, corrupt, unknown version, nothing persisted)
   are announced here by saved.js; empty and hidden otherwise. */ ?>
<div data-pref-status hidden role="status" class="border-b border-note-line bg-note px-4 py-2 text-center text-xs leading-snug text-note-ink"></div>
<noscript>
  <p class="border-b border-line bg-sunken px-4 py-2 text-center text-xs text-ink-muted">Salvarea și ascunderea anunțurilor se păstrează în browser și necesită JavaScript. Legăturile către anunțuri funcționează normal.</p>
</noscript>

<main id="main" class="flex-1">
