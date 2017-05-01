<?php
/**
 * Very simple queuing system.
 *
 * This is intentionally simple to be fast. We want to queue events
 * quickly so as not to slow down the client (ie. the Perforce Server).
 *
 * To add something to the queue, POST to this script. Up to 1024kB
 * of raw post data will be written to a file in the queue folder.
 * No assumptions are made about the nature of the data (at least not
 * by this script).
 *
 * Each file in the queue is named for the current microtime. It is
 * possible to get collisions under high load or if time moves backward.
 * Therefore, we make 1000 attempts to get a unique name by incrementing
 * a trailing number.
 */

// path can come from three possible locations:
// 1) if a path is passed as the first cli argument, it will be used
// 2) otherwise, if the SWARM_DATA_PATH environment variable is set, it will be used
// 3) otherwise, we'll go up a folder from this script then into data/queue
$path = getenv('SWARM_DATA_PATH')
        ? (rtrim(getenv('SWARM_DATA_PATH'), '/\\') . '/queue')
        : (__DIR__ . '/../data/queue');
$path = isset($argv[1]) ? $argv[1] : $path;
if (!is_dir($path)) {
    mkdir($path, 0700);
}

// bail if we didn't get a valid auth token - can be passed as
// second arg for testing, normally passed via get param
$token = isset($argv[2]) ? $argv[2] : $_GET['token'];
$token = preg_replace('/[^a-z0-9\-]/i', '', $token);
if (!strlen($token) || !file_exists($path . '/tokens/' . $token)) {
    header('HTTP/1.0 401 Unauthorized', true, 401);
    echo "Missing or invalid token. View 'About Swarm' as a super user for a list of valid tokens.\n";

    // try and get this failure into the logs to assist diagnostics
    // don't display the triggered error to the user, we've already done that bit
    ini_set('display_errors', 0);
    trigger_error('queue/add attempted with invalid/missing token: "' . $token . '"', E_USER_ERROR);
    exit;
}

// 1000 attempts to get a unique filename.
$path = $path . '/' . sprintf('%015.4F', microtime(true)) . '.';
for ($i = 0; $i < 1000 && !($file = @fopen($path . $i, 'x')); $i++);

// write up to 1024 bytes of input.
// takes from stdin when CLI invoked for testing.
if ($file) {
    $input = fopen(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input', 'r');
    fwrite($file, fread($input, 1024));
}
