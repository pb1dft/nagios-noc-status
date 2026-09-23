<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';

$statusFile = NAGIOS_STATUS_FILE;
$commandFile = NAGIOS_COMMAND_FILE;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function getAuthenticatedUsername(): string
{
    $remoteUser = isset($_SERVER['REMOTE_USER'])
        ? trim((string)$_SERVER['REMOTE_USER'])
        : '';

    if ($remoteUser !== '') {
        return $remoteUser;
    }

    if (isset($_SESSION['noc_auth_user'])) {
        return trim((string)$_SESSION['noc_auth_user']);
    }

    return '';
}

$username = getAuthenticatedUsername();

function getNotificationState(string $file): bool
{
    if (!is_readable($file)) {
        throw new RuntimeException('Cannot read Nagios status file');
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Cannot read Nagios status file');
    }

    $insideProgramStatus = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === 'programstatus {') {
            $insideProgramStatus = true;
            continue;
        }

        if ($insideProgramStatus && $line === '}') {
            break;
        }

        if ($insideProgramStatus && preg_match('/^enable_notifications\s*=\s*([01])$/', $line, $matches)) {
            return $matches[1] === '1';
        }
    }

    throw new RuntimeException('enable_notifications not found in Nagios status file');
}

function writeNagiosCommand(string $file, string $command): void
{
    if (!file_exists($file)) {
        throw new RuntimeException('Nagios command file does not exist');
    }

    $fh = fopen($file, 'w');
    if ($fh === false) {
        throw new RuntimeException('Cannot open Nagios command file');
    }

    $written = fwrite($fh, '[' . time() . '] ' . $command . PHP_EOL);
    fflush($fh);
    fclose($fh);

    if ($written === false) {
        throw new RuntimeException('Failed to write Nagios command');
    }
}

function cleanCommandField(string $value, string $name, int $maxLength = 1000): string
{
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException($name . ' is required');
    }

    if (strlen($value) > $maxLength) {
        throw new InvalidArgumentException($name . ' is too long');
    }

    if (strpos($value, "\0") !== false) {
        throw new InvalidArgumentException($name . ' contains invalid characters');
    }

    if (strpos($value, ';') !== false) {
        throw new InvalidArgumentException($name . ' cannot contain semicolons');
    }

    return str_replace(["\r", "\n"], ' ', $value);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $enabled = getNotificationState($statusFile);

        echo json_encode([
            'success' => true,
            'notifications_enabled' => $enabled,
            'authenticated' => $username !== '',
            'username' => $username
        ]);
    } catch (Throwable $e) {
        error_log(sprintf(
            'NOC notifications API exception: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        http_response_code(500);
        echo json_encode(['error' => 'Unable to read notification state']);
    }

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'GET or POST required']);
    exit;
}

if ($username === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== 0) {
    http_response_code(415);
    echo json_encode(['error' => 'Content-Type must be application/json']);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON');
    }

    if (!array_key_exists('enabled', $input)) {
        throw new InvalidArgumentException('enabled is required');
    }

    if (!is_bool($input['enabled'])) {
        throw new InvalidArgumentException('enabled must be boolean');
    }

    $requestedState = $input['enabled'];
    $reason = null;

    if ($requestedState === false) {
        $rawReason = isset($input['reason']) ? (string)$input['reason'] : '';
        $reason = cleanCommandField($rawReason, 'reason', 1000);
    }

    $currentState = getNotificationState($statusFile);

    if ($currentState === $requestedState) {
        echo json_encode([
            'success' => true,
            'notifications_enabled' => $currentState,
            'changed' => false,
            'authenticated' => true,
            'username' => $username
        ]);
        exit;
    }

    if ($requestedState) {
        $command = 'ENABLE_NOTIFICATIONS';
    } else {
        $author = cleanCommandField($username, 'username', 255);
        $reasonText = cleanCommandField((string)($reason ?? ''), 'reason', 1000);

        $comment = cleanCommandField('Notifications disabled: ' . $reasonText, 'reason comment', 1500);
        $command = sprintf(
            'DISABLE_NOTIFICATIONS;%s;%s',
            $author,
            $comment
        );
    }

    writeNagiosCommand($commandFile, $command);

    $confirmedState = $requestedState;

    for ($i = 0; $i < 50; $i++) {
        usleep(100000);

        try {
            $confirmedState = getNotificationState($statusFile);
            if ($confirmedState === $requestedState) {
                break;
            }
        } catch (Throwable $e) {
            // Continue polling while Nagios updates status.dat.
        }
    }

    if ($confirmedState !== $requestedState) {
        http_response_code(202);
        echo json_encode([
            'success' => true,
            'pending' => true,
            'message' => 'Notification change queued; waiting for Nagios confirmation',
            'notifications_enabled' => $requestedState,
            'confirmed_notifications_enabled' => $confirmedState,
            'authenticated' => true,
            'username' => $username
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'notifications_enabled' => $confirmedState,
        'changed' => true,
        'authenticated' => true,
        'username' => $username
    ]);
} catch (InvalidArgumentException $e) {
    error_log(sprintf(
        'NOC notifications API validation error: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log(sprintf(
        'NOC notifications API exception: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    http_response_code(500);
    echo json_encode(['error' => 'Unable to change notification state']);
}
