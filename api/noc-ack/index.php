<?php

require_once __DIR__ . '/../config.php';

$statusFile = NAGIOS_STATUS_FILE;
$commandFile = NAGIOS_COMMAND_FILE;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');


/* ============================================================
   REQUEST VALIDATION
   ============================================================ */

/**
 * Only allow POST requests.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'POST required']);
    exit;
}


/**
 * Require an authenticated user.
 */
$username = $_SERVER['REMOTE_USER'] ?? '';

if ($username === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}


/**
 * Require a JSON request body.
 */
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== 0) {
    http_response_code(415);
    echo json_encode(['error' => 'Content-Type must be application/json']);
    exit;
}


/* ============================================================
   NAGIOS STATUS PARSING
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
 * Validate and sanitize a field used in a Nagios command.
 */
function cleanCommandField($value, $name, $maxLength = 1000) {
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

    $value = str_replace(["\r", "\n"], ' ', $value);

    return $value;
}


/* ============================================================
   ACKNOWLEDGEMENT
   ============================================================ */

try {
    /* ------------------------------------------------------------
       Parse request body
       ------------------------------------------------------------ */

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON');
    }


    /* ------------------------------------------------------------
       Validate problem type
       ------------------------------------------------------------ */

    $type = $input['type'] ?? '';

    if ($type !== 'host' && $type !== 'service') {
        throw new InvalidArgumentException('Invalid problem type');
    }


    /* ------------------------------------------------------------
       Validate host/service
       ------------------------------------------------------------ */

    $host = cleanCommandField($input['host'] ?? '', 'Host', 255);
    $service = null;

    if ($type === 'service') {
        $service = cleanCommandField($input['service'] ?? '', 'Service', 255);
    }


    /* ------------------------------------------------------------
       Validate comment
       ------------------------------------------------------------ */

    $comment = trim((string)($input['comment'] ?? ''));

    if ($comment === '') {
        throw new InvalidArgumentException('Comment is required');
    }

    if (strlen($comment) > 2000) {
        throw new InvalidArgumentException('Comment is too long');
    }

    if (strpos($comment, "\0") !== false) {
        throw new InvalidArgumentException('Comment contains invalid characters');
    }

    $comment = str_replace(["\r", "\n"], ' ', $comment);
    $comment = str_replace(';', ',', $comment);


    /* ------------------------------------------------------------
       Validate authenticated username
       ------------------------------------------------------------ */

    $author = cleanCommandField($username, 'Username', 255);


    /* ------------------------------------------------------------
       Persistent acknowledgement
       
       Default is FALSE.

       The dashboard sends:
           persistent: false
       or:
           persistent: true

       Nagios expects:
           0 = non-persistent comment
           1 = persistent comment
       ------------------------------------------------------------ */

    $persistent = false;

    if (array_key_exists('persistent', $input)) {
        if (!is_bool($input['persistent'])) {
            throw new InvalidArgumentException('Persistent must be a boolean');
        }

        $persistent = $input['persistent'];
    }

    $persistentFlag = $persistent ? 1 : 0;


    /* ------------------------------------------------------------
       Read current Nagios status
       ------------------------------------------------------------ */

    $objects = parseNagiosStatus($statusFile);

    $target = null;
    $hosts = [];


    /* ------------------------------------------------------------
       Build host lookup
       ------------------------------------------------------------ */

    foreach ($objects as $object) {
        if ($object['type'] === 'hoststatus') {
            $name = $object['data']['host_name'] ?? '';

            if ($name !== '') {
                $hosts[$name] = $object['data'];
            }
        }
    }


    /* ------------------------------------------------------------
       Find requested problem
       ------------------------------------------------------------ */

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


    /* ------------------------------------------------------------
       Make sure the problem still exists
       ------------------------------------------------------------ */

    if ($target === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Problem no longer exists']);
        exit;
    }


    /* ------------------------------------------------------------
       Validate current problem state
       ------------------------------------------------------------ */

    $state = intValue($target, 'current_state');
    $stateType = intValue($target, 'state_type');
    $acknowledged = intValue($target, 'problem_has_been_acknowledged');
    $downtime = intValue($target, 'scheduled_downtime_depth');

    if ($state === 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Problem is no longer active']);
        exit;
    }

    if ($stateType !== 1) {
        http_response_code(409);
        echo json_encode(['error' => 'Only HARD problems can be acknowledged']);
        exit;
    }

    if ($acknowledged !== 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Problem is already acknowledged']);
        exit;
    }

    if ($downtime !== 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Problem is in scheduled downtime']);
        exit;
    }


    /* ------------------------------------------------------------
       Service problems require the host to be UP
       ------------------------------------------------------------ */

    if ($type === 'service') {
        $hostState = isset($hosts[$host])
            ? intValue($hosts[$host], 'current_state')
            : 2;

        if ($hostState !== 0) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Service problem is suppressed because the host is not UP'
            ]);
            exit;
        }
    }


    /* ============================================================
       BUILD NAGIOS COMMAND
       ============================================================ */

    $timestamp = time();

    if ($type === 'service') {
        /*
         * ACKNOWLEDGE_SVC_PROBLEM format:
         *
         * host
         * service
         * sticky acknowledgement
         * send notification
         * persistent comment
         * author
         * comment
         */
        $command = sprintf(
            '[%d] ACKNOWLEDGE_SVC_PROBLEM;%s;%s;1;1;%d;%s;%s',
            $timestamp,
            $host,
            $service,
            $persistentFlag,
            $author,
            $comment
        );
    } else {
        /*
         * ACKNOWLEDGE_HOST_PROBLEM format:
         *
         * host
         * sticky acknowledgement
         * send notification
         * persistent comment
         * author
         * comment
         */
        $command = sprintf(
            '[%d] ACKNOWLEDGE_HOST_PROBLEM;%s;1;1;%d;%s;%s',
            $timestamp,
            $host,
            $persistentFlag,
            $author,
            $comment
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


    /* ============================================================
       SUCCESS RESPONSE
       ============================================================ */

    echo json_encode([
        'success' => true,
        'authenticated' => true,
        'username' => $username,
        'persistent' => $persistent,
        'message' => 'Problem acknowledged'
    ]);

} catch (InvalidArgumentException $e) {
    error_log(
        sprintf(
            'NOC acknowledgement API validation error: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        )
    );

    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid acknowledgement request'
    ]);
} catch (Throwable $e) {
    error_log(
        sprintf(
            'NOC acknowledgement API exception: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        )
    );

    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to process acknowledgement request'
    ]);
}

