<?php
declare(strict_types=1);

$slug = $_GET['slug'] ?? '';
if (!$slug) { http_response_code(404); echo "Not found"; exit; }

$stmt = db()->prepare("SELECT id, name, slug FROM employers WHERE slug = ?");
$stmt->execute([$slug]);
$employer = $stmt->fetch();
if (!$employer) { http_response_code(404); echo "Angajator negăsit."; exit; }

$today = date('Y-m-d');
$eid = (int)$employer['id'];

$total = (int)db()->prepare("SELECT COUNT(*) FROM job_postings WHERE employer_id = ?")->execute([$eid]) ? 0 : 0;
$stmt2 = db()->prepare("SELECT COUNT(*) FROM job_postings WHERE employer_id = ?");
$stmt2->execute([$eid]); $total = (int)$stmt2->fetchColumn();

$stmt3 = db()->prepare("SELECT COUNT(*) FROM job_postings WHERE employer_id = ? AND expires_at >= ?");
$stmt3->execute([$eid, $today]); $active_count = (int)$stmt3->fetchColumn();

$stmt4 = db()->prepare("SELECT COUNT(*) FROM job_postings WHERE employer_id = ? AND (expires_at < ? OR expires_at IS NULL)");
$stmt4->execute([$eid, $today]); $expired_count = (int)$stmt4->fetchColumn();

// By judet
$stmt5 = db()->prepare("SELECT judet_name, judet_slug, COUNT(*) AS cnt FROM job_postings WHERE employer_id = ? AND judet_id IS NOT NULL GROUP BY judet_id ORDER BY cnt DESC");
$stmt5->execute([$eid]); $by_judet = $stmt5->fetchAll();
$max_judet = $by_judet ? $by_judet[0]['cnt'] : 1;

// Top category
$stmt6 = db()->prepare("SELECT employer_category, COUNT(*) AS cnt FROM job_postings WHERE employer_id = ? AND employer_category != '' GROUP BY employer_category ORDER BY cnt DESC LIMIT 1");
$stmt6->execute([$eid]); $top_cat_row = $stmt6->fetch();
$top_category = $top_cat_row ? $top_cat_row['employer_category'] : null;

// Active postings (50)
$stmt7 = db()->prepare("SELECT id, title, judet_name, published_at, expires_at, job_level, job_type, categorie FROM job_postings WHERE employer_id = ? AND expires_at >= ? ORDER BY expires_at ASC LIMIT 50");
$stmt7->execute([$eid, $today]); $active_postings = $stmt7->fetchAll();

// Recent expired (25)
$stmt8 = db()->prepare("SELECT id, title, judet_name, published_at, expires_at, job_level, job_type, categorie FROM job_postings WHERE employer_id = ? AND (expires_at < ? OR expires_at IS NULL) ORDER BY published_at DESC LIMIT 25");
$stmt8->execute([$eid, $today]); $expired_postings = $stmt8->fetchAll();

$page_title = $employer['name'];
require __DIR__ . '/../inc/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-6">

  <div class="mb-4 text-sm">
    <a href="/angajatori/" class="text-gov hover:underline">← Angajatori</a>
  </div>

  <div class="border-b border-border-warm pb-5 mb-6">
    <h1 class="font-display text-2xl sm:text-3xl font-semibold italic text-ink leading-tight mb-2">
      <?= e($employer['name']) ?>
    </h1>
    <?php if ($top_category): ?>
      <div class="text-sm text-ink-muted"><?= e($top_category) ?></div>
    <?php endif; ?>
  </div>

  <!-- Stats row -->
  <div class="grid grid-cols-3 gap-4 mb-8">
    <div class="bg-white border border-border-warm rounded-lg p-4 text-center">
      <div class="text-2xl font-display font-semibold text-gov"><?= $total ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Total anunțuri</div>
    </div>
    <div class="bg-white border border-border-warm rounded-lg p-4 text-center">
      <div class="text-2xl font-display font-semibold text-green-700"><?= $active_count ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Active</div>
    </div>
    <div class="bg-white border border-border-warm rounded-lg p-4 text-center">
      <div class="text-2xl font-display font-semibold text-ink-muted"><?= count($by_judet) ?></div>
      <div class="text-xs text-ink-muted font-mono mt-1 uppercase tracking-wide">Județe</div>
    </div>
  </div>

  <div class="flex gap-8 items-start">
    <!-- Sidebar: by judet -->
    <?php if ($by_judet): ?>
    <aside class="w-56 shrink-0 hidden md:block">
      <h2 class="font-display text-base italic font-semibold text-ink mb-3">Distribuție județe</h2>
      <div class="space-y-2">
        <?php foreach ($by_judet as $jd): ?>
        <div>
          <div class="flex justify-between text-xs mb-0.5">
            <a href="/?judet=<?= urlencode($jd['judet_slug']) ?>&employer_id=<?= $eid ?>" class="text-gov hover:underline"><?= e($jd['judet_name']) ?></a>
            <span class="text-ink-faint font-mono"><?= $jd['cnt'] ?></span>
          </div>
          <div class="h-1.5 bg-parchment-dark rounded-full overflow-hidden">
            <div class="h-full bg-gov rounded-full" style="width:<?= round(100 * $jd['cnt'] / $max_judet) ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </aside>
    <?php endif; ?>

    <!-- Listings -->
    <div class="flex-1 min-w-0">
      <?php if ($active_postings): ?>
      <section class="mb-8">
        <h2 class="font-display text-lg italic font-semibold text-ink mb-3 pb-2 border-b border-border-warm">
          Anunțuri active (<?= count($active_postings) ?>)
        </h2>
        <?php foreach ($active_postings as $p): $days = days_until($p['expires_at']); ?>
        <div class="py-3 border-b border-border-warm last:border-0">
          <div class="flex items-start justify-between gap-3">
            <div>
              <a href="/job/<?= $p['id'] ?>/" class="font-display text-sm italic font-semibold text-ink hover:text-gov"><?= e($p['title']) ?></a>
              <div class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-ink-muted">
                <?php if ($p['judet_name']): ?><span><?= e($p['judet_name']) ?></span><?php endif; ?>
                <?php if ($p['job_level']): ?><span>· <?= e($p['job_level']) ?></span><?php endif; ?>
                <?php if ($p['categorie']): ?><span>· <?= e($p['categorie']) ?></span><?php endif; ?>
              </div>
            </div>
            <?php if ($days !== null): ?>
            <div class="shrink-0 text-right">
              <span class="text-xs font-mono <?= $days <= 3 ? 'text-red-600 font-semibold' : ($days <= 7 ? 'text-amber-700' : 'text-ink-muted') ?>">
                <?= $days ?>z
              </span>
              <div class="text-xs text-ink-faint font-mono"><?= fmt_date($p['expires_at']) ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php if ($expired_postings): ?>
      <section>
        <h2 class="font-display text-lg italic font-semibold text-ink mb-3 pb-2 border-b border-border-warm text-ink-muted">
          Anunțuri recente expirate
        </h2>
        <?php foreach ($expired_postings as $p): ?>
        <div class="py-3 border-b border-border-warm last:border-0 opacity-70">
          <a href="/job/<?= $p['id'] ?>/" class="font-display text-sm italic font-semibold text-ink hover:text-gov"><?= e($p['title']) ?></a>
          <div class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-ink-muted">
            <?php if ($p['judet_name']): ?><span><?= e($p['judet_name']) ?></span><?php endif; ?>
            <?php if ($p['expires_at']): ?><span class="font-mono">· expirat <?= fmt_date($p['expires_at']) ?></span><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php if (!$active_postings && !$expired_postings): ?>
        <p class="text-ink-muted italic">Niciun anunț disponibil.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../inc/footer.php'; ?>
