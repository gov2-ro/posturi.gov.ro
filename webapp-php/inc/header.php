<?php
// $page_title is set by the calling page
$_title = isset($page_title) ? $page_title . ' — Posturi publice' : 'Posturi publice';
?><!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($_title) ?></title>
  <link rel="alternate" type="application/atom+xml" title="Posturi publice — Atom" href="/posturi.atom">
  <link rel="alternate" type="application/json" title="Posturi publice — JSON" href="/posturi.json">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..700&family=DM+Sans:ital,opsz,wght@0,9..40,300..600;1,9..40,300..600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        fontFamily: {
          display: ["'Fraunces'", "Georgia", "serif"],
          sans: ["'DM Sans'", "system-ui", "sans-serif"],
          mono: ["'DM Mono'", "monospace"],
        },
        extend: {
          colors: {
            parchment: "#F5F0E8",
            "parchment-dark": "#EDE7D9",
            ink: "#1C1917",
            "ink-muted": "#78716C",
            "ink-faint": "#A8A29E",
            "gov": "#1B3A6B",
            "gov-light": "#EEF2FF",
            "border-warm": "#D6CFC4",
          }
        }
      }
    }
  </script>

  <style type="text/css">
    body { background-color: #F5F0E8; color: #1C1917; }
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: #EDE7D9; }
    ::-webkit-scrollbar-thumb { background: #B8B0A4; border-radius: 3px; }
    #results.htmx-request { opacity: 0.5; transition: opacity 200ms ease; }
    .facet-check:checked + label { color: #1B3A6B; font-weight: 500; }
    .prose-body h1, .prose-body h2, .prose-body h3 { font-family: 'Fraunces', Georgia, serif; font-weight: 600; margin-top: 1.25em; margin-bottom: 0.4em; line-height: 1.2; }
    .prose-body h1 { font-size: 1.35rem; }
    .prose-body h2 { font-size: 1.15rem; }
    .prose-body h3 { font-size: 1rem; }
    .prose-body p { margin-bottom: 0.75em; line-height: 1.65; }
    .prose-body ul, .prose-body ol { margin-bottom: 0.75em; padding-left: 1.4em; }
    .prose-body li { margin-bottom: 0.25em; line-height: 1.6; }
    .prose-body strong { font-weight: 600; }
    .prose-body table { border-collapse: collapse; width: 100%; margin-bottom: 1em; font-size: 0.85rem; }
    .prose-body th, .prose-body td { border: 1px solid #D6CFC4; padding: 0.4em 0.6em; text-align: left; }
    .prose-body th { background: #EDE7D9; font-weight: 600; }
  </style>

  <script src="https://unpkg.com/htmx.org@2.0.4/dist/htmx.min.js" defer></script>
</head>
<body class="min-h-screen flex flex-col font-sans">

<header class="bg-gov text-white border-b border-gov">
  <div class="max-w-screen-xl mx-auto px-4 sm:px-6 flex items-center justify-between h-14">
    <a href="/" class="flex items-center gap-3 group">
      <span class="font-display italic text-xl font-semibold text-white leading-none tracking-tight">
        posturi<span class="text-blue-300">.</span>gov<span class="text-blue-300">.</span>ro
      </span>
      <span class="hidden sm:inline text-xs text-blue-200 font-mono uppercase tracking-widest mt-0.5 opacity-70">
        / explorator independent
      </span>
    </a>
    <nav class="flex items-center gap-4 text-sm text-blue-100">
      <a href="/" class="hover:text-white transition-colors">Căutare</a>
      <a href="/statistici/" class="hover:text-white transition-colors">Statistici</a>
      <a href="/angajatori/" class="hover:text-white transition-colors">Angajatori</a>
      <a href="/despre/" class="hover:text-white transition-colors">Despre</a>
    </nav>
  </div>
</header>

<main class="flex-1">
