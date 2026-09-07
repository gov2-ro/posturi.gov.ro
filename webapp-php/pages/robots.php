<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$origin = site_origin();
echo <<<TXT
User-agent: *
Allow: /

# Filter permutations are near-infinite and add nothing to the index; the
# canonical link on each page points crawlers back at the unfiltered list.
Disallow: /*?

Sitemap: {$origin}/sitemap.xml

TXT;
