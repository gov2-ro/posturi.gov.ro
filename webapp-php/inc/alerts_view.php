<?php
/**
 * UX-14 — shared markup helpers of the /alerte/ pages.
 *
 * Every alerts page is private to one reader and carries a token in its URL.
 * It must never be cached at the edge, indexed, or passed on in a Referer.
 */
declare(strict_types=1);

/** Headers for every /alerte/ response. Call before any output. */
function al_headers(): void {
    header('Cache-Control: no-store');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
}

function al_cls_btn(): string {
    return 'inline-flex min-h-[2.5rem] items-center border border-line bg-surface px-3 py-2 text-xs font-medium '
         . 'text-ink-muted transition-colors hover:border-gov hover:text-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus';
}

function al_cls_primary(): string {
    return 'inline-flex min-h-[2.5rem] items-center border border-gov bg-gov px-3 py-2 text-xs font-medium '
         . 'text-on-gov focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2';
}

function al_cls_input(): string {
    return 'min-h-[2.5rem] w-full max-w-md border border-line-strong bg-surface px-2 py-1 text-sm text-ink '
         . 'focus:border-gov focus:outline-none focus:ring-1 focus:ring-focus';
}

/** A boxed message. $family is one of info, ok, note, alert. */
function al_notice(string $family, string $html): string {
    return '<div role="status" class="mt-4 max-w-2xl border border-' . $family . '-line bg-' . $family
         . ' px-3 py-2 text-sm leading-snug text-' . $family . '-ink">' . $html . '</div>';
}

/** The two-line consent text of the subscribe form (privacy text: /despre/#confidentialitate). */
function al_consent_html(): string {
    return '<p class="mt-3 max-w-md text-xs leading-snug text-ink-muted">'
         . 'Prin abonare ești de acord să primești un rezumat la fiecare 3 zile, când apar anunțuri noi. '
         . 'Adresa se păstrează criptată, până o ștergi.<br>'
         . 'Brevo, furnizorul de email, numără anonim deschiderile și clicurile. '
         . 'Detalii: <a href="/despre/#confidentialitate" class="text-gov underline">confidențialitate</a>.</p>';
}

/** The list URL of a search key. */
function al_list_href(string $key): string {
    return '/?' . $key;
}
