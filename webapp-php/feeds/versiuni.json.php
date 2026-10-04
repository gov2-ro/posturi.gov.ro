<?php
/**
 * Read-only version endpoint (FIX-06): the deployed CODE marker and the DATA
 * artifact's provenance, as two separate facts. deploy-php.sh verifies against
 * this after pushing — a data-only push must change `data`, a code-only push
 * must change `code`, and HTTP 200 alone proves neither. `source_host` is
 * deliberately absent.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$meta = build_meta();
$data = null;
if ($meta) {
    $data = [
        'built_at'             => $meta['built_at'] ?? null,
        'run_id'               => $meta['run_id'] ?? null,
        'git_sha'              => $meta['git_sha'] ?? null,
        'postings'             => (int)($meta['job_postings'] ?? 0),
        'active_only'          => (bool)($meta['active_only'] ?? false),
        'detail_fetched_at_max' => $meta['detail_fetched_at_max'] ?? null,
        'detail_fetched_rows'  => (int)($meta['detail_fetched_rows'] ?? 0),
        'index_checked_at'     => $meta['index_checked_at'] ?? null,
    ];
}

echo json_encode([
    'code' => code_version(),
    'data' => $data,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
