<?php
declare(strict_types=1);

/**
 * ANOMALY-PUBLIC — admin page `/admin/anomalii/`: every check, in every state.
 *
 * Apache asks for a password (webapp-php/.htaccess) and sets REMOTE_USER, or REDIRECT_REMOTE_USER
 * after the rewrite to index.php (measured on Apache 2.4.71). This guard fails closed: a host
 * without the auth module, or a request that skipped it, gets 403 and no data. It does not read
 * PHP_AUTH_USER: PHP fills it from the Authorization header, so a client can forge it when the
 * server does not check the password.
 * No public page links here; robots.txt disallows /admin/; the page has a noindex tag.
 */

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$anomaly_user = $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? '';
if ($anomaly_user === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acces interzis.\n";
    return;
}

$anomaly_admin = true;
require __DIR__ . '/anomalies_public.php';
