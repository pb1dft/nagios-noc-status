<?php
declare(strict_types=1);

session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function getAuthenticatedUsername(): string
{
    $phpAuthUser = isset($_SERVER['PHP_AUTH_USER'])
        ? trim((string)$_SERVER['PHP_AUTH_USER'])
        : '';

    if ($phpAuthUser !== '') {
        return $phpAuthUser;
    }

    $remoteUser = isset($_SERVER['REMOTE_USER'])
        ? trim((string)$_SERVER['REMOTE_USER'])
        : '';

    return $remoteUser;
}

function getSessionUsername(): string
{
    if (!isset($_SESSION['noc_auth_user'])) {
        return '';
    }

    return trim((string)$_SESSION['noc_auth_user']);
}

function storeSessionUsername(string $username): void
{
    $_SESSION['noc_auth_user'] = $username;
}

function clearSessionUsername(): void
{
    unset($_SESSION['noc_auth_user']);
}

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

    $username = getAuthenticatedUsername();

    if ($username !== '') {
        storeSessionUsername($username);
    } else {
        $username = getSessionUsername();
    }

    if ($username === '') {
        clearSessionUsername();
        http_response_code(401);
        echo json_encode([
            'authenticated' => false
        ]);
        exit;
    }

    echo json_encode([
        'authenticated' => true,
        'username' => $username
    ]);
    exit;
}

/*
 * Direct browser navigation to /api/noc_auth/.
 *
 * If already authenticated, return to the dashboard.
 * Otherwise issue the Basic Auth challenge.
 */
$username = getAuthenticatedUsername();

if ($username === '') {
    clearSessionUsername();
    header('WWW-Authenticate: Basic realm="Nagios NOC"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Authentication required.';
    exit;
}

storeSessionUsername($username);

header('Location: ../../');
exit;
