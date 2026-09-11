<?php

require_once __DIR__ . '/../config.php';

$statusFile = NAGIOS_STATUS_FILE;
$commandFile = NAGIOS_COMMAND_FILE;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* ============================================================
   REQUEST VALIDATION
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'POST required']);
    exit;
}

$username = $_SERVER['REMOTE_USER'] ?? '';

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

/* ============================================================
   HELPERS
   ============================================================ */

/**
 * Parse Nagios status.dat into hoststatus and servicestatus objects.
 */
function parseNagiosStatus($file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Cannot read Nagios status file');
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $objects = [];
    $current = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (preg_match('/^(hoststatus|servicestatus)\s*\{$/', $line, $m)) {
            $current = [
                'type' => $m[1],
                'data' => []
            ];
            continue;
        }

        if ($line === '}') {
            if ($current !== null) {
                $objects[] = $current;
            }
            $current = null;
            continue;
        }

        if ($current !== null && preg_match('/^([A-Za-z0-9_]+)=(.*)$/', $line, $m)) {
            $current['data'][$m[1]] = $m[2];
        }
    }

    return $objects;
}

/**
 * Return an integer value from a Nagios status object.
 */
function intValue($data, $key, $default = 0) {
    return isset($data[$key]) && is_numeric($data[$key])
        ? (int)$data[$key]
        : $default;
}

/**
 * Validate a field used in a Nagios external command.
 */
function cleanCommandField($value, $name, $maxLength = 255) {
    $value = trim((string)$value);

    if ($value === '') {
        throw new InvalidArgumentException("$name is required");
    }

    if (strlen($value) > $maxLength) {
        throw new InvalidArgumentException("$name is too long");
    }

    if (strpos($value, "\0") !== false) {
        throw new InvalidArgumentException("$name contains invalid characters");
    }

    if (strpos($value, ';') !== false) {
        throw new InvalidArgumentException("$name cannot contain semicolons");
    }

    return str_replace(["\r", "\n"], ' ', $value);
}

/* ============================================================
   RECHECK
   ============================================================ */

try {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON');
    }

    $type = $input['type'] ?? '';

    if ($type !== 'host' && $type !== 'service') {
        throw new InvalidArgumentException('Invalid problem type');
    }

    $host = cleanCommandField($input['host'] ?? '', 'Host');
    $service = null;

    if ($type === 'service') {
        $service = cleanCommandField($input['service'] ?? '', 'Service');
    }

    /* ------------------------------------------------------------
       Read current Nagios status and find the requested target.
       The target must still be in a non-OK state.
       ------------------------------------------------------------ */

    $objects = parseNagiosStatus($statusFile);
    $target = null;

    foreach ($objects as $object) {
        $d = $object['data'];

        if ($type === 'host' && $object['type'] === 'hoststatus') {
            if (($d['host_name'] ?? '') === $host) {
                $target = $d;
                break;
            }
        }

        if ($type === 'service' && $object['type'] === 'servicestatus') {
            if (
                ($d['host_name'] ?? '') === $host &&
                ($d['service_description'] ?? '') === $service
            ) {
                $target = $d;
                break;
            }
        }
    }

    if ($target === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Problem no longer exists']);
        exit;
    }

    $state = intValue($target, 'current_state');

    if ($state === 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Problem is no longer active']);
        exit;
    }

    /* ============================================================
       BUILD NAGIOS COMMAND

       SCHEDULE_SVC_CHECK:
           host;service;check_time;force_check

       SCHEDULE_HOST_CHECK:
           host;check_time;force_check

       Using time() with force_check=1 makes this an immediate
       forced check rather than waiting for the normal schedule.
       ============================================================ */

    $timestamp = time();

    if ($type === 'service') {
        $command = sprintf(
            '[%d] SCHEDULE_SVC_CHECK;%s;%s;%d;1',
            $timestamp,
            $host,
            $service,
            $timestamp
        );
    } else {
        $command = sprintf(
            '[%d] SCHEDULE_HOST_CHECK;%s;%d;1',
            $timestamp,
            $host,
            $timestamp
        );
    }

    /* ============================================================
       WRITE COMMAND TO NAGIOS
       ============================================================ */

    if (!file_exists($commandFile)) {
        throw new RuntimeException('Nagios command file does not exist');
    }

    $fh = fopen($commandFile, 'w');

    if ($fh === false) {
        throw new RuntimeException('Cannot open Nagios command file');
    }

    $written = fwrite($fh, $command . PHP_EOL);
    fflush($fh);
    fclose($fh);

    if ($written === false) {
        throw new RuntimeException('Failed to write Nagios command');
    }

    echo json_encode([
        'success' => true,
        'authenticated' => true,
        'username' => $username,
        'message' => 'Problem recheck scheduled'
    ]);

} catch (InvalidArgumentException $e) {
    error_log(
        sprintf(
            'NOC recheck API validation error: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        )
    );

    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid recheck request'
    ]);
} catch (Throwable $e) {
    error_log(
        sprintf(
            'NOC recheck API exception: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        )
    );

    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to process recheck request'
    ]);
}

