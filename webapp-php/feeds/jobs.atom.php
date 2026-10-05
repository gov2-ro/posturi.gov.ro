<?php
declare(strict_types=1);

require_once __DIR__ . '/_feed.php';

header('Content-Type: application/atom+xml; charset=utf-8');

$rows = feed_item_rows();
$ch = feed_channel();
$updated = feed_updated($rows);

echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title><?= xml_text($ch['title']) ?></title>
  <link href="<?= xml_text($ch['alternate']) ?>" rel="alternate" type="text/html"/>
  <link href="<?= xml_text($ch['origin'] . '/posturi.atom' . current_qs()) ?>" rel="self" type="application/atom+xml"/>
  <id><?= xml_text($ch['id']) ?></id>
  <updated><?= xml_text(($updated ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM)) ?></updated>
  <subtitle><?= xml_text($ch['subtitle']) ?></subtitle>
<?php foreach ($rows as $r):
    $employer = $r['employer_name_disp'] ?? $r['employer_name'] ?? '';
    [$pub, $upd] = feed_row_times($r);
    $summary = implode(' | ', feed_summary_lines($r)) . ' | ' . feed_source_line($r);
?>
  <entry>
    <title><?= xml_text(feed_item_title($r)) ?></title>
    <link href="<?= xml_text(feed_local_url($r)) ?>" rel="alternate" type="text/html"/>
    <link href="<?= xml_text($r['url']) ?>" rel="related" title="Sursa oficială (posturi.gov.ro)"/>
    <?php /* The id stays the official URL so readers do not see every entry as new. */ ?>
    <id><?= xml_text($r['url']) ?></id>
<?php if ($pub): ?>
    <published><?= xml_text($pub->format(DATE_ATOM)) ?></published>
<?php endif; ?>
    <updated><?= xml_text(($upd ?? $updated ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM)) ?></updated>
<?php if ($employer): ?>
    <author><name><?= xml_text($employer) ?></name></author>
<?php endif; ?>
    <summary type="text"><?= xml_text($summary) ?></summary>
  </entry>
<?php endforeach; ?>
</feed>
