<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/*
 * Dashboard AJAX authentication check.
 *
 * The dashboard sends X-NOC-AUTH-CHECK: 1.
 * If the browser has authenticated credentials, PHP receives
 * PHP_AUTH_USER and returns JSON.
 *
 * If not authenticated, return 401. Because this request is made
 * by fetch(), the browser will not necessarily display a login
 * prompt; the visible LOGIN link below is what starts authentication.
 */
$isAuthCheck = isset($_SERVER['HTTP_X_NOC_AUTH_CHECK']);

if ($isAuthCheck) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        http_response_code(401);
        echo json_encode([
            'authenticated' => false
        ]);
        exit;
    }

    echo json_encode([
        'authenticated' => true,
        'username' => (string)$_SERVER['PHP_AUTH_USER']
    ]);
    exit;
}

/*
 * Direct browser navigation to /api/noc_auth/.
 *
 * If already authenticated, return to the dashboard.
 * Otherwise issue the Basic Auth challenge.
 */
if (!isset($_SERVER['PHP_AUTH_USER'])) {
    header('WWW-Authenticate: Basic realm="NOC"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Authentication required.';
    exit;
}

header('Location: /');
exit;
