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

// Last data update — used in header and footer
if (!isset($_last_updated)) {
    try {
        $_last_updated = db()->query("SELECT MAX(last_seen_at) FROM job_postings")->fetchColumn();
    } catch (Exception $e) {
        $_last_updated = null;
    }
}
if ($_last_updated) {
    $_last_updated_fmt = (new DateTime(substr($_last_updated, 0, 10)))->format('d.m.Y');
} else {
    $_last_updated_fmt = null;
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
  <meta name="twitter:card" content="summary">

  <link rel="alternate" type="application/atom+xml" title="posturi.gov2.ro — Atom" href="/posturi.atom">
  <link rel="alternate" type="application/json" title="posturi.gov2.ro — JSON" href="/posturi.json">

  <?php /* Preloads follow the default skin, which is what a first visit gets. A
     returning visitor on govuk or posturi preloads two faces it will not use —
     the alternative is a cookie round-trip, which would break shared-host page
     caching for the sake of ~90KB on one request. */ ?>
  <link rel="preload" href="/static/fonts/dm-sans-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/static/fonts/fraunces-italic-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/static/app.css?v=<?= @filemtime(__DIR__ . '/../static/app.css') ?: '1' ?>">
  <?= pg_skin_links() ?>
  <?= pg_skin_boot() ?>
  <script src="/static/htmx.min.js" defer></script>
  <script src="/static/prefs.js?v=<?= @filemtime(__DIR__ . '/../static/prefs.js') ?: '1' ?>" defer></script>
  <?= $head_extra ?? '' ?>
</head>
<body class="min-h-screen flex flex-col font-sans">

<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-gov focus:px-4 focus:py-2 focus:text-on-gov">
  Sari la conținut
</a>

<div id="wip-banner" class="bg-note border-b border-note-line text-note-ink text-sm px-4 py-2 flex items-center justify-between gap-4">
  <span class="font-mono text-xs uppercase tracking-wide font-semibold shrink-0">WIP / MVP</span>
  <span class="flex-1 text-xs text-center">Acesta <b>NU ESTE UN PROIECT OFICIAL</b> al Guvernului României. <a href="https://forms.gle/96WusM2qr4pbUXhW7" class="underline font-medium hover:no-underline" target="_blank" rel="noopener">Acceptăm sugestii</a> (gForm)</span>
  <button type="button" onclick="document.getElementById('wip-banner').remove()" class="shrink-0 -m-1 p-1 text-lg leading-none text-note-ink hover:opacity-70" aria-label="Închide anunțul">&times;</button>
</div>

<header class="bg-gov-bar text-on-bar border-b border-gov-bar">
  <div class="max-w-screen-xl mx-auto px-4 sm:px-6 flex items-center justify-between gap-3 h-14">
    <a href="/" class="flex shrink-0 items-center gap-3 py-2 group">
      <span class="font-display italic text-lg sm:text-xl font-semibold text-on-bar leading-none tracking-tight">
        posturi<span class="text-on-bar-accent">.</span>gov<span class="text-on-bar-accent">2</span><span class="text-on-bar-accent">.</span>ro
      </span>
      <span class="hidden md:inline text-xs text-on-bar-muted font-mono uppercase tracking-widest mt-0.5">
        / alpha · WIP
      </span>
      <?php if ($_last_updated_fmt): ?>
      <span class="hidden lg:inline text-xs text-on-bar-muted font-mono mt-0.5">
        actualizat <?= $_last_updated_fmt ?>
      </span>
      <?php endif; ?>
    </a>
    <nav aria-label="Navigare principală" class="flex items-center gap-3 sm:gap-4 text-sm text-on-bar-muted">
      <a href="/statistici/" class="py-2 hover:text-on-bar transition-colors">Statistici</a>
      <a href="/angajatori/" class="hidden py-2 sm:inline hover:text-on-bar transition-colors">Angajatori</a>
      <a href="/despre/" class="py-2 hover:text-on-bar transition-colors">Despre</a>
    </nav>
  </div>
</header>

<main id="main" class="flex-1">
