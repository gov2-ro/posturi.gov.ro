<?php
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
  <meta property="og:image:alt" content="posturi.gov2.ro — anunțuri de angajare din administrația publică">
  <meta name="twitter:card" content="summary_large_image">

  <link rel="alternate" type="application/rss+xml" title="posturi.gov2.ro — RSS" href="/posturi.rss">
  <link rel="alternate" type="application/atom+xml" title="posturi.gov2.ro — Atom" href="/posturi.atom">
  <link rel="alternate" type="application/json" title="posturi.gov2.ro — JSON" href="/posturi.json">

  <?php /* The gov2 design system (ADR 0005): the stylesheet and the font faces, from one
     tag. The version in each URL changes when the copy in static/gov2/ changes, so the
     one-year cache of .htaccess never serves an old file. app.css follows, so that its
     layer order (reset, gov2, utilities, app) is the same as the DS one. */ ?>
  <link rel="stylesheet" href="/static/gov2/gov2.css?v=<?= GOV2_DS_VERSION ?>">
  <link rel="stylesheet" href="/static/gov2/fonts.css?v=<?= GOV2_DS_VERSION ?>">
  <link rel="stylesheet" href="/static/app.css?v=<?= @filemtime(__DIR__ . '/../static/app.css') ?: '1' ?>">
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
  <?php /* UX-09: one shared script for browser-local save/hide, loaded once here
     rather than copied into the list and detail inline scripts. */ ?>
  <script src="/static/saved.js?v=<?= @filemtime(__DIR__ . '/../static/saved.js') ?: '1' ?>" defer></script>
  <?php /* UX-15: saved searches (`posturi.searches.v1`); a separate store from saved.js. */ ?>
  <script src="/static/searches.js?v=<?= @filemtime(__DIR__ . '/../static/searches.js') ?: '1' ?>" defer></script>
  <?= $head_extra ?? '' ?>
</head>
<body class="min-h-screen flex flex-col font-sans">
<?php /* Glyph sprite: the save/hide icon buttons (helpers.php pref_controls)
   and the subscription block on the list page (pages/list.php #urmareste). */ ?>
<svg width="0" height="0" class="absolute" aria-hidden="true" focusable="false"><defs>
  <symbol id="i-bookmark" viewBox="0 0 24 24"><path d="M6 3h12v18l-6-4.5L6 21z"/></symbol>
  <symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
  <symbol id="i-note" viewBox="0 0 24 24"><path d="M4 20h4L19 9a2.1 2.1 0 0 0-4-4L4 16z M13.5 6.5l4 4"/></symbol>
  <symbol id="i-feed" viewBox="0 0 24 24"><path d="M5 11a8 8 0 0 1 8 8 M5 4a15 15 0 0 1 15 15"/><circle cx="6" cy="18" r="1.5"/></symbol>
  <symbol id="i-copy" viewBox="0 0 24 24"><path d="M9 9h11v11H9z M15 9V4H4v11h5"/></symbol>
  <symbol id="i-calendar" viewBox="0 0 24 24"><path d="M4 6h16v14H4z M4 10h16 M8 3v5 M16 3v5"/></symbol>
  <symbol id="i-calendar-add" viewBox="0 0 24 24"><path d="M4 6h16v14H4z M4 10h16 M8 3v5 M16 3v5 M12 13v5 M9.5 15.5h5"/></symbol>
  <symbol id="i-mail" viewBox="0 0 24 24"><path d="M3 6h18v12H3z M3 7l9 6 9-6"/></symbol>
  <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z M10 21h4"/></symbol>
  <symbol id="i-sector-altele" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M11 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M18 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></symbol>
  <symbol id="i-sector-asistenta_sociala" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19.5 12.572l-7.5 7.428l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572"/><path d="M12 6l-3.293 3.293a1 1 0 0 0 0 1.414l.543 .543c.69 .69 1.81 .69 2.5 0l1 -1a3.182 3.182 0 0 1 4.5 0l2.25 2.25"/><path d="M12.5 15.5l2 2"/><path d="M15 13l2 2"/></symbol>
  <symbol id="i-sector-cultura" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13.192 9h6.616a2 2 0 0 1 1.992 2.183l-.567 6.182a4 4 0 0 1 -3.983 3.635h-1.5a4 4 0 0 1 -3.983 -3.635l-.567 -6.182a2 2 0 0 1 1.992 -2.183"/><path d="M15 13h.01"/><path d="M18 13h.01"/><path d="M15 16.5c1 .667 2 .667 3 0"/><path d="M8.632 15.982a4.037 4.037 0 0 1 -.382 .018h-1.5a4 4 0 0 1 -3.983 -3.635l-.567 -6.182a2 2 0 0 1 1.992 -2.183h6.616a2 2 0 0 1 2 2"/><path d="M6 8h.01"/><path d="M9 8h.01"/><path d="M6 12c.764 -.51 1.528 -.63 2.291 -.36"/></symbol>
  <symbol id="i-sector-educatie" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 9l-10 -4l-10 4l10 4l10 -4v6"/><path d="M6 10.6v5.4a6 3 0 0 0 12 0v-5.4"/></symbol>
  <symbol id="i-sector-justitie" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 20l10 0"/><path d="M6 6l6 -1l6 1"/><path d="M12 3l0 17"/><path d="M9 12l-3 -6l-3 6a3 3 0 0 0 6 0"/><path d="M21 12l-3 -6l-3 6a3 3 0 0 0 6 0"/></symbol>
  <symbol id="i-sector-ordine_publica" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"/></symbol>
  <symbol id="i-sector-sanatate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21l18 0"/><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16"/><path d="M9 21v-4a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v4"/><path d="M10 9l4 0"/><path d="M12 7l0 4"/></symbol>
  <symbol id="i-domain-administratie" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2"/><path d="M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2"/><path d="M9 12h6"/><path d="M9 16h6"/></symbol>
  <symbol id="i-domain-meseriasi" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h4l13 -13a1.5 1.5 0 0 0 -4 -4l-13 13v4"/><path d="M14.5 5.5l4 4"/><path d="M12 8l-5 -5l-4 4l5 5"/><path d="M7 8l-1.5 1.5"/><path d="M16 12l5 5l-4 4l-5 -5"/><path d="M16 17l-1.5 1.5"/></symbol>
  <symbol id="i-domain-paza" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 21v-2a4 4 0 0 1 4 -4h2"/><path d="M22 16c0 4 -2.5 6 -3.5 6s-3.5 -2 -3.5 -6c1 0 2.5 -.5 3.5 -1.5c1 1 2.5 1.5 3.5 1.5"/><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/></symbol>
  <symbol id="i-domain-soferi" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M12 14l0 7"/><path d="M10 12l-6.75 -2"/><path d="M14 12l6.75 -2"/></symbol>
  <symbol id="i-domain-ingineri" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3l4 4l-14 14l-4 -4l14 -14"/><path d="M16 7l-1.5 -1.5"/><path d="M13 10l-1.5 -1.5"/><path d="M10 13l-1.5 -1.5"/><path d="M7 16l-1.5 -1.5"/></symbol>
  <symbol id="i-domain-necalificati" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 4l3 3"/><path d="M18.5 5.5l-8 8"/><path d="M8.276 11.284l4.44 4.44a.968 .968 0 0 1 0 1.369l-2.704 2.704a4.108 4.108 0 0 1 -5.809 -5.81l2.704 -2.703a.968 .968 0 0 1 1.37 0l-.001 0"/></symbol>
  <symbol id="i-domain-financiar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M14.8 9a2 2 0 0 0 -1.8 -1h-2a2 2 0 1 0 0 4h2a2 2 0 1 1 0 4h-2a2 2 0 0 1 -1.8 -1"/><path d="M12 7v10"/></symbol>
  <symbol id="i-domain-it" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8l-4 4l4 4"/><path d="M17 8l4 4l-4 4"/><path d="M14 4l-4 16"/></symbol>
  <symbol id="i-chart-bar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 13a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -6"/><path d="M15 9a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -10"/><path d="M9 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -14"/><path d="M4 20h14"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0"/><path d="M21 21l-6 -6"/></symbol>
  <symbol id="i-brand-github" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-4.3 1.4 -4.3 -2.5 -6 -3m12 5v-3.5c0 -1 .1 -1.4 -.5 -2c2.8 -.3 5.5 -1.4 5.5 -6a4.6 4.6 0 0 0 -1.3 -3.2a4.2 4.2 0 0 0 -.1 -3.2s-1.1 -.3 -3.5 1.3a12.3 12.3 0 0 0 -6.2 0c-2.4 -1.6 -3.5 -1.3 -3.5 -1.3a4.2 4.2 0 0 0 -.1 3.2a4.6 4.6 0 0 0 -1.3 3.2c0 4.6 2.7 5.7 5.5 6c-.6 .6 -.6 1.2 -.5 2v3.5"/></symbol>
</defs></svg>

<a class="g2-skip-link" href="#main">Sari la conținut</a>

<?php /* One row: brand + version, the database stamp, nav (BETA-CHROME, D9).
   ────────────────────────────────────────────────────────────────────────────
   The masthead says only what is true of the whole site right now: which
   version this is and when its database was generated. The "not official"
   claim moved to where it matters — the start and end of every posting
   (robots_disclaimer()), the first-visit welcome below, the footer and /despre/
   — and feedback to the [?!] button in the footer. Below sm the stamp and the
   version's commit drop out; the footer still carries the stamp.
   ADR 0005: the DS header (.g2-header, .g2-wordmark, .g2-nav). The row wraps at
   320 px, with no menu button. */ ?>
<?php $_stamp = build_stamp_relative(); $_code = code_version(); ?>
<header class="g2-header">
  <span class="g2-ro-corner" aria-hidden="true"></span>
  <div class="g2-header-in g2-container">
    <a href="/" class="g2-wordmark min-h-[44px]"><span>posturi<em>.gov2.ro</em></span></a>
    <span class="font-mono text-[11px] leading-none text-on-bar-muted"<?= $_code !== '' ? ' title="Versiunea codului: ' . e($_code) . '"' : '' ?>>beta<?php if ($_code !== ''): ?><span class="hidden sm:inline"> · <?= e($_code) ?></span><?php endif; ?></span>

    <?php /* Below sm there is no room beside the nav; the footer and /despre/ carry the stamp there. */ ?>
    <p class="hidden min-w-0 flex-1 truncate text-center text-xs leading-none sm:block">
      <?php if ($_stamp): ?>
      <a href="/despre/#versiunea-datelor" class="g2-link-inverse inline-block py-4"<?= $_build_tooltip ? ' title="' . e($_build_tooltip) . '"' : '' ?>>
        Bază generată: <time datetime="<?= e($_stamp['iso']) ?>"><?= e($_stamp['long']) ?></time>
      </a>
      <?php endif; ?>
    </p>

    <?php /* 2026-11-16 (UX-05): four links, all visible at each width; the row wraps
       at 320 px. "Pot aplica?" and Angajatori moved to the landing page and the
       footer. The saved count is the browser-local number of saved items (UX-09);
       it stays hidden until saved.js has read the state. */ ?>
    <nav aria-label="Navigare principală" class="g2-nav ml-auto">
      <a href="/posturi/">Posturi</a>
      <a href="/salvate/"><?= icon('i-bell', 'pref-ico mr-1 shrink-0') ?>Salvate<span data-saved-count hidden class="ml-1 font-mono text-xs"></span></a>
      <a href="/statistici/">Statistici</a>
      <a href="/despre/">Despre</a>
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

<main id="main" tabindex="-1" class="flex-1">
