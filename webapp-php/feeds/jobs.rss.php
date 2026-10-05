<?php
declare(strict_types=1);

require_once __DIR__ . '/_feed.php';

header('Content-Type: application/rss+xml; charset=utf-8');

$rows = feed_item_rows();
$ch = feed_channel();
$updated = feed_updated($rows);

echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?= xml_text($ch['title']) ?></title>
    <link><?= xml_text($ch['alternate']) ?></link>
    <description><?= xml_text($ch['subtitle']) ?></description>
    <language>ro</language>
<?php if ($updated): ?>
    <lastBuildDate><?= xml_text($updated->format(DATE_RSS)) ?></lastBuildDate>
<?php endif; ?>
    <atom:link href="<?= xml_text($ch['origin'] . '/posturi.rss' . current_qs()) ?>" rel="self" type="application/rss+xml"/>
<?php foreach ($rows as $r):
    [$pub] = feed_row_times($r);
    // RSS descriptions are HTML by convention, so source text is escaped once
    // as HTML here and once more as XML by xml_text(): a title that happens to
    // contain markup is shown, never interpreted, by a reader.
    $html = '<p>' . htmlspecialchars(implode(' | ', feed_summary_lines($r)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
          . '<p>Sursa oficială (posturi.gov.ro): <a href="' . htmlspecialchars($r['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
          . htmlspecialchars($r['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';
?>
    <item>
      <title><?= xml_text(feed_item_title($r)) ?></title>
      <link><?= xml_text(feed_local_url($r)) ?></link>
      <?php /* Same identity as the Atom entry id: the official URL, not a permalink. */ ?>
      <guid isPermaLink="false"><?= xml_text($r['url']) ?></guid>
<?php if ($pub): ?>
      <pubDate><?= xml_text($pub->format(DATE_RSS)) ?></pubDate>
<?php endif; ?>
      <description><?= xml_text($html) ?></description>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
