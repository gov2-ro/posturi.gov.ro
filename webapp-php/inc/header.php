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

// Last data update — used in header and footer. This is when the source was last
// scraped, not when the database file was built; build_meta() holds the latter and
// $_build_tooltip carries it wherever the stamp appears.
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
$_last_updated_iso = $_last_updated ? substr($_last_updated, 0, 10) : '';
$_build_tooltip = build_tooltip();
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
     returning visitor on hartie or govuk preloads a face it will not use — the
     alternative is a cookie round-trip, which would break shared-host page
     caching for the sake of ~45KB on one request.

     Manrope is one variable face doing display, sans and mono in the posturi
     skin, so this is a single preload where the parchment default needed two. */ ?>
  <link rel="preload" href="/static/fonts/manrope-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
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

<?php /* One row: brand, disclaimer, nav.
   ────────────────────────────────────────────────────────────────────────────
   The disclaimer used to be a dismissible amber strip above the masthead. It is
   the first thing the site has to say and the easiest thing to lose — a band
   above the header reads as an ad, and it was one click from being gone for
   good — so it lives in the bar itself now, and has no close button.

   Fitting three things on one 56px line down to 320px means the middle one has
   to give. It is written at three lengths and the breakpoints pick one, so the
   claim survives at every width even when the sentence cannot:

     < 640    "Neoficial"                       ~70px
     640–1023 "Nu este un proiect oficial"
     ≥ 1024   the full sentence + the gForm link

   The brand block sheds its own extras on the way down (the alpha/WIP marker at
   <lg, the update stamp at <xl) so the disclaimer keeps the slack. `min-w-0` on
   the middle is what lets it shrink at all — a flex item defaults to
   `min-width:auto` and would otherwise push the nav off-screen rather than
   truncate — and `truncate` is the backstop if a translation ever outgrows its
   slot. Measured at 320/375/390/768/1024/1280/1536; see the activity log. */ ?>
<header class="bg-gov-bar text-on-bar border-b border-gov-bar">
  <div class="max-w-screen-xl mx-auto px-3 sm:px-6 flex items-center gap-2 sm:gap-4 h-14">
    <a href="/" class="flex shrink-0 items-center gap-2 sm:gap-3 py-2 group">
      <span class="font-display italic text-base sm:text-lg md:text-xl font-semibold text-on-bar leading-none tracking-tight">
        posturi<span class="text-on-bar-accent">.</span>gov<span class="text-on-bar-accent">2</span><span class="text-on-bar-accent">.</span>ro
      </span>
      <span class="hidden lg:inline text-xs text-on-bar-muted font-mono uppercase tracking-widest mt-0.5">
        / alpha · WIP
      </span>
      <?php if ($_last_updated_fmt): ?>
      <time datetime="<?= e($_last_updated_iso) ?>"
            class="hidden xl:inline text-xs text-on-bar-muted font-mono mt-0.5<?= $_build_tooltip ? ' cursor-help' : '' ?>"
            <?= $_build_tooltip ? 'title="' . e($_build_tooltip) . '"' : '' ?>>
        actualizat <?= $_last_updated_fmt ?>
      </time>
      <?php endif; ?>
    </a>

    <p class="min-w-0 flex-1 truncate text-center text-[11px] sm:text-xs leading-none text-on-bar-muted">
      <?php /* Below sm the word alone carries it, and it points at the page that
         explains — there is no room for a sentence, and a cryptic label with
         nowhere to go would be worse than the short one. */ ?>
      <a href="/despre/" class="sm:hidden font-semibold uppercase tracking-wide text-on-bar-accent hover:text-on-bar">Neoficial</a>
      <span class="hidden sm:inline lg:hidden">Nu este un <b class="font-semibold text-on-bar">proiect oficial</b></span>
      <span class="hidden lg:inline">Acesta <b class="font-semibold text-on-bar">nu este un proiect oficial</b> al Guvernului României.</span>
      <a href="https://forms.gle/96WusM2qr4pbUXhW7"
         class="hidden lg:inline underline decoration-on-bar/40 underline-offset-2 hover:text-on-bar hover:decoration-current"
         target="_blank" rel="noopener">Acceptăm sugestii</a><span class="hidden lg:inline"> (gForm)</span>
    </p>

    <?php /* Statistici and Angajatori moved to /despre/ — they are things you
       read once, not places you navigate between, and the masthead is worth
       more as one unambiguous way back to the listing. */ ?>
    <nav aria-label="Navigare principală" class="shrink-0 flex items-center text-sm text-on-bar-muted">
      <a href="/despre/" class="py-2 hover:text-on-bar transition-colors">Despre</a>
    </nav>
  </div>
</header>

<main id="main" class="flex-1">
