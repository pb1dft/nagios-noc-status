<?php
declare(strict_types=1);

/*
 * ================================================================
 * NAGIOS NOC STATUS API
 * ================================================================
 *
 * Reads:
 *     Nagios status file defined in config
 *
 * Returns:
 *     JSON for the NOC dashboard
 *
 * No daemon.
 * No Flask.
 * No database.
 * Apache + PHP only.
 *
 * ================================================================
 */

/* ================================================================
 * CONFIGURATION
 * ================================================================ */

require_once __DIR__ . '/../config.php';

$statusFile = NAGIOS_STATUS_FILE;


/* ================================================================
 * HTTP HEADERS
 * ================================================================ */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');


/* ================================================================
 * ERROR RESPONSE
 * ================================================================ */

function apiError(
    string $message,
    int $httpCode = 500
): never {
    http_response_code($httpCode);

    echo json_encode(
        [
            'error' => true,
            'message' => $message,
            'generated_at' => time()
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* ================================================================
 * INTEGER VALUE
 * ================================================================ */

function intValue(
    array $record,
    string $key
): int {
    return isset($record[$key])
        ? (int) $record[$key]
        : 0;
}


/* ================================================================
 * PENDING STATE
 *
 * Nagios does not use a special current_state value for PENDING.
 *
 * PENDING means:
 *
 *     has_been_checked = 0
 *
 * current_state must therefore only be interpreted after the
 * object has been checked.
 * ================================================================ */

function hasBeenChecked(
    array $record
): bool {
    return intValue(
        $record,
        'has_been_checked'
    ) !== 0;
}


/* ================================================================
 * HOST STATE
 *
 * Returns the dashboard state name.
 *
 * Nagios:
 *
 *     has_been_checked = 0 -> PENDING
 *
 * Checked hosts:
 *
 *     0 = UP
 *     1 = DOWN
 *     2 = UNREACHABLE
 * ================================================================ */

function hostStateName(
    array $record
): string {
    if (!hasBeenChecked($record)) {
        return 'PENDING';
    }

    return match (
        intValue($record, 'current_state')
    ) {
        0 => 'UP',
        1 => 'DOWN',
        2 => 'UNREACHABLE',
        default => 'PENDING'
    };
}


/* ================================================================
 * SERVICE STATE
 *
 * Returns the dashboard state name.
 *
 * Nagios:
 *
 *     has_been_checked = 0 -> PENDING
 *
 * Checked services:
 *
 *     0 = OK
 *     1 = WARNING
 *     2 = CRITICAL
 *     3 = UNKNOWN
 * ================================================================ */

function serviceStateName(
    array $record
): string {
    if (!hasBeenChecked($record)) {
        return 'PENDING';
    }

    return match (
        intValue($record, 'current_state')
    ) {
        0 => 'OK',
        1 => 'WARNING',
        2 => 'CRITICAL',
        3 => 'UNKNOWN',
        default => 'PENDING'
    };
}


/* ================================================================
 * STATE TYPE
 *
 * Nagios:
 *
 *     0 = SOFT
 *     1 = HARD
 *
 * PENDING objects do not represent a problem state, so state_type
 * should not be used for them.
 * ================================================================ */

function stateTypeName(
    array $record
): string {
    return intValue(
        $record,
        'state_type'
    ) === 0
        ? 'SOFT'
        : 'HARD';
}


/* ================================================================
 * IS UNHANDLED PROBLEM
 *
 * Included:
 *
 *     - SOFT problems
 *     - HARD problems
 *
 * Excluded:
 *
 *     - OK
 *     - PENDING
 *     - acknowledged problems
 *     - scheduled downtime
 *
 * ================================================================ */

function isUnhandledProblem(
    array $record
): bool {

    /*
     * Never treat an unchecked object as a problem.
     */
    if (!hasBeenChecked($record)) {
        return false;
    }

    $state =
        intValue(
            $record,
            'current_state'
        );

    $acknowledged =
        intValue(
            $record,
            'problem_has_been_acknowledged'
        );

    $downtime =
        intValue(
            $record,
            'scheduled_downtime_depth'
        );


    /*
     * State 0 is OK for both hosts and services.
     */
    if ($state === 0) {
        return false;
    }


    /*
     * Acknowledged problems are not unhandled.
     */
    if ($acknowledged !== 0) {
        return false;
    }


    /*
     * Scheduled downtime is not unhandled.
     */
    if ($downtime !== 0) {
        return false;
    }


    return true;
}


/* ================================================================
 * PARSE STATUS.DAT
 * ================================================================ */

function parseStatusFile(
    string $filename
): array {

    if (!is_readable($filename)) {
        apiError(
            'Nagios status file is not readable: ' .
            $filename
        );
    }


    $handle = fopen(
        $filename,
        'r'
    );


    if ($handle === false) {
        apiError(
            'Unable to open Nagios status file: ' .
            $filename
        );
    }


    $hosts = [];
    $services = [];

    $currentType = null;
    $currentRecord = [];


    while (
        ($line = fgets($handle)) !== false
    ) {

        $line = trim($line);


        /*
         * Ignore empty lines.
         */
        if ($line === '') {
            continue;
        }


        /*
         * HOST STATUS BLOCK
         */
        if ($line === 'hoststatus {') {

            $currentType = 'host';
            $currentRecord = [];

            continue;
        }


        /*
         * SERVICE STATUS BLOCK
         */
        if ($line === 'servicestatus {') {

            $currentType = 'service';
            $currentRecord = [];

            continue;
        }


        /*
         * END OF BLOCK
         */
        if ($line === '}') {

            if ($currentType === 'host') {
                $hosts[] = $currentRecord;

            } elseif ($currentType === 'service') {
                $services[] = $currentRecord;
            }

            $currentType = null;
            $currentRecord = [];

            continue;
        }


        /*
         * Ignore anything outside a status block.
         */
        if ($currentType === null) {
            continue;
        }


        /*
         * Split only at the first "=".
         *
         * Plugin output can itself contain "=".
         */
        $separator = strpos(
            $line,
            '='
        );


        if ($separator === false) {
            continue;
        }


        $key = trim(
            substr(
                $line,
                0,
                $separator
            )
        );


        $value = trim(
            substr(
                $line,
                $separator + 1
            )
        );


        $currentRecord[$key] = $value;
    }


    fclose($handle);


    return [
        'hosts' => $hosts,
        'services' => $services
    ];
}


/* ================================================================
 * READ STATUS FILE
 * ================================================================ */

$data = parseStatusFile(
    $statusFile
);

$hostRecords =
    $data['hosts'];

$serviceRecords =
    $data['services'];


/* ================================================================
 * HOST TOTALS
 * ================================================================ */

$hostTotals = [
    'up' => 0,
    'down' => 0,
    'unreachable' => 0,
    'pending' => 0
];


/* ================================================================
 * HOST STATES
 *
 * Used to suppress service problems when their parent host is:
 *
 *     DOWN
 *     UNREACHABLE
 *     PENDING
 *
 * Services are still counted in service totals.
 * ================================================================ */

$hostStates = [];


foreach (
    $hostRecords as $record
) {

    $hostName =
        $record['host_name'] ?? '';

    $state =
        hostStateName($record);


    /*
     * Save the actual dashboard state.
     *
     * This is important because PENDING must not become UP simply
     * because current_state happens to be 0.
     */
    if ($hostName !== '') {
        $hostStates[$hostName] = $state;
    }


    /*
     * Count host state.
     */
    switch ($state) {

        case 'UP':
            $hostTotals['up']++;
            break;

        case 'DOWN':
            $hostTotals['down']++;
            break;

        case 'UNREACHABLE':
            $hostTotals['unreachable']++;
            break;

        case 'PENDING':
            $hostTotals['pending']++;
            break;
    }
}


/* ================================================================
 * SERVICE TOTALS
 *
 * EVERYTHING is counted here.
 *
 * This happens BEFORE suppressing services belonging to
 * DOWN/UNREACHABLE/PENDING hosts.
 * ================================================================ */

$serviceTotals = [
    'ok' => 0,
    'warning' => 0,
    'unknown' => 0,
    'critical' => 0,
    'pending' => 0
];


foreach (
    $serviceRecords as $record
) {

    $state =
        serviceStateName($record);


    switch ($state) {

        case 'OK':
            $serviceTotals['ok']++;
            break;

        case 'WARNING':
            $serviceTotals['warning']++;
            break;

        case 'UNKNOWN':
            $serviceTotals['unknown']++;
            break;

        case 'CRITICAL':
            $serviceTotals['critical']++;
            break;

        case 'PENDING':
            $serviceTotals['pending']++;
            break;
    }
}


/* ================================================================
 * UNHANDLED PROBLEMS
 * ================================================================ */

$unhandled = [];


/* ================================================================
 * HOST PROBLEMS
 *
 * SOFT + HARD included.
 * ================================================================ */

foreach (
    $hostRecords as $record
) {

    if (!isUnhandledProblem($record)) {
        continue;
    }


    $unhandled[] = [

        'type' => 'host',

        'host' =>
            $record['host_name'] ?? '',

        'service' => null,

        'state' =>
            hostStateName($record),

        'state_type' =>
            stateTypeName($record),

        'message' =>
            $record['plugin_output'] ?? '',

        'last_check' =>
            intValue(
                $record,
                'last_check'
            )
    ];
}


/* ================================================================
 * SERVICE PROBLEMS
 *
 * If parent host is not UP:
 *
 *     Do NOT display the service problem.
 *
 * The service was already counted in service totals.
 * ================================================================ */

foreach (
    $serviceRecords as $record
) {

    $host =
        $record['host_name'] ?? '';


    /*
     * Get parent host state.
     *
     * A missing parent host is treated as PENDING rather than UP.
     * This avoids accidentally displaying a service problem when
     * the host record cannot be found.
     */
    $hostState =
        $hostStates[$host] ?? 'PENDING';


    /*
     * Only show service problems for hosts that are UP.
     */
    if ($hostState !== 'UP') {
        continue;
    }


    /*
     * Only actual unhandled problems.
     */
    if (!isUnhandledProblem($record)) {
        continue;
    }


    $unhandled[] = [

        'type' => 'service',

        'host' =>
            $host,

        'service' =>
            $record['service_description']
            ?? '',

        'state' =>
            serviceStateName($record),

        'state_type' =>
            stateTypeName($record),

        'message' =>
            $record['plugin_output'] ?? '',

        'last_check' =>
            intValue(
                $record,
                'last_check'
            )
    ];
}


/* ================================================================
 * SORT UNHANDLED PROBLEMS
 *
 * Priority:
 *
 *     1. CRITICAL / DOWN
 *     2. UNREACHABLE
 *     3. WARNING
 *     4. UNKNOWN
 *     5. PENDING
 *
 * HARD before SOFT.
 *
 * Newest check first.
 * ================================================================ */

$severity = [

    'CRITICAL' => 1,
    'DOWN' => 1,

    'UNREACHABLE' => 2,

    'WARNING' => 3,

    'UNKNOWN' => 4,

    'PENDING' => 5
];


usort(
    $unhandled,
    function (
        array $a,
        array $b
    ) use ($severity): int {

        $stateA =
            $a['state']
            ?? 'UNKNOWN';

        $stateB =
            $b['state']
            ?? 'UNKNOWN';


        $severityA =
            $severity[$stateA]
            ?? 99;

        $severityB =
            $severity[$stateB]
            ?? 99;


        /*
         * Severity.
         */
        if ($severityA !== $severityB) {

            return
                $severityA
                <=>
                $severityB;
        }


        /*
         * HARD before SOFT.
         */
        $typeA =
            (
                ($a['state_type'] ?? 'HARD')
                === 'HARD'
            )
                ? 0
                : 1;

        $typeB =
            (
                ($b['state_type'] ?? 'HARD')
                === 'HARD'
            )
                ? 0
                : 1;


        if ($typeA !== $typeB) {

            return
                $typeA
                <=>
                $typeB;
        }


        /*
         * Newest check first.
         */
        return
            (int) (
                $b['last_check'] ?? 0
            )
            <=>
            (int) (
                $a['last_check'] ?? 0
            );
    }
);


/* ================================================================
 * SHORT HOSTNAME
 *
 * Example:
 *
 *     monhost2.example.com
 *
 * becomes:
 *
 *     MONHOST2
 * ================================================================ */

$hostname =
    gethostname();


if (
    $hostname === false ||
    $hostname === ''
) {
    $hostname = 'UNKNOWN';
}


/*
 * Remove domain/FQDN and uppercase.
 */
$hostname =
    strtoupper(
        explode(
            '.',
            $hostname
        )[0]
    );


if ($hostname === '') {
    $hostname = 'UNKNOWN';
}


/* ================================================================
 * FINAL RESPONSE
 * ================================================================ */

$response = [

    'hostname' =>
        $hostname,

    'hosts' =>
        $hostTotals,

    'services' =>
        $serviceTotals,

    'unhandled' =>
        $unhandled,

    'generated_at' =>
        time()
];


/* ================================================================
 * OUTPUT JSON
 * ================================================================ */

echo json_encode(
    $response,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);
