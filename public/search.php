<?php
/**
 * Fast search endpoint
 *
 * This script is stand-alone to provide quick search results.
 * We anticipate the client to make requests as the user types.
 * Therefore, we want to eliminate as much overhead as possible.
 */

// @codingStandardsIgnoreStart

// in order to unserialize cached project data, we need a fielded iterator
// we need to use a namespace block to properly fake the expected iterator
namespace P4\Model\Fielded { class Iterator extends \ArrayIterator {} }

// everything else goes in the global namespace as per usual
namespace {
    error_reporting(error_reporting() & ~(E_STRICT|E_NOTICE));

    define('BASE_PATH', dirname(__DIR__));
    define(
        'DATA_PATH',
        getenv('SWARM_DATA_PATH') ? rtrim(getenv('SWARM_DATA_PATH'), '/\\') : BASE_PATH . '/data'
    );

    // config is needed for p4 parameters among other things
    $config = include DATA_PATH . '/config.php';

    // all of our responses should be interpreted as json
    header('Content-type: application/json; charset=utf-8');

    // if login required, enforce it
    if (isset($config['security']['require_login'])
        && $config['security']['require_login']
        && !getIdentity($config)
    ) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
        exit;
    }

    // break up query into keywords - no keywords, no results!
    $keywords = preg_split('/[\s\/]+/', isset($_GET['q']) ? $_GET['q'] : '');
    $keywords = array_unique(array_filter($keywords, 'strlen'));
    if (!$keywords) {
        echo json_encode(array());
        exit;
    }

    // supported query parameters:
    //  - specific types of things to search for (required)
    //  - maximum results to return (default 50)
    $max     = isset($_GET['max'])   ? (int) $_GET['max'] : 50;
    $types   = isset($_GET['types']) ? array_flip((array) $_GET['types']) : array();
    $results = array();

    // search projects
    if (isset($types['projects'])) {
        $cache = getLatestCache('projects', $config);
        if ($cache) {
            $projects = unserialize(file_get_contents($cache));
            foreach ($projects as $id => $project) {
                $project = get_object_vars($project) + array('values' => array());
                $score   = getMatchScore($project['values'], 'name', $keywords);
                if ($score !== false) {
                    $results[] = array(
                        'type'   => 'project',
                        'id'     => $id,
                        'label'  => $project['values']['name'],
                        'detail' => substr($project['values']['description'], 0, 250),
                        'score'  => $score + 5
                    );
                }
            }
        }
    }

    // search users
    if (isset($types['users'])) {
        $cache = getLatestCache('users', $config);
        if ($cache) {
            // if filesize is greater than 2MB, stream the data for memory savings
            if (filesize($cache) > 1024 * 1024 * 2) {
                require_once BASE_PATH . '/library/Record/Cache/ArrayReader.php';
                require_once BASE_PATH . '/library/Record/Cache/ArrayWriter.php';
                $users = new \Record\Cache\ArrayReader($cache);
                $users->openFile();
            } else {
                $users = unserialize(file_get_contents($cache));
            }
            foreach ($users as $user) {
                $user  = get_object_vars($user) + array('values' => array());
                $score = getMatchScore($user['values'], array('User', 'FullName'), $keywords);
                if ($score !== false) {
                    $results[] = array(
                        'type'   => 'user',
                        'id'     => $user['values']['User'],
                        'detail' => $user['values']['FullName'],
                        'score'  => $score
                    );
                }
            }
        }
    }

    // search file names (optionally scoped to a path and/or a project)
    $path    = trim($_GET['path'], '/');
    $project = $_GET['project'];
    if (isset($types['files-names'])) {
        $p4              = getP4($config);
        $p4->client      = $project ? 'swarm-project-' . $project : $p4->client;
        $p4->maxlocktime = isset($config['search']['maxlocktime'])
            ? $config['search']['maxlocktime']
            : 5000;

        // if we have no path, search shallow and include dirs
        $path   = trim($project ? $p4->client . "/$path" : $path, "/");
        $dirs   = !$path;
        $path   = $path ? "//$path/..." : "//*/*";

        $lower  = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
        $upper  = function_exists('mb_strtoupper') ? 'mb_strtoupper' : 'strtoupper';
        $filter = '';
        foreach ($keywords as $keyword) {
            $regex = preg_replace_callback('/(.)/u', function($matches) use ($lower, $upper) {
                return '[\\' . $lower($matches[0]) . '\\' . $upper($matches[0]) . ']';
            }, $keyword);
            $filter .= ' depotFile~=' . $regex;
            $filter .= $dirs ? '|dir~=' . $regex : '';
        }

        try {
            $files = runP4(
                $p4,
                'fstat',
                '-m ' . $max * 5,
                $dirs ? '-Dx' : '',
                '-T depotFile' . ($dirs ? ',dir' : ''),
                '-F' .
                $filter,
                $path
            );
            foreach ($files as $file) {
                $file['path']     = isset($file['depotFile']) ? $file['depotFile'] : $file['dir'];
                $file['basename'] = basename($file['path']);
                $score            = getMatchScore($file, 'path',     $keywords) * .5;
                $score           += getMatchScore($file, 'basename', $keywords) * .5;
                if ($score !== false) {
                    $results[] = array(
                        'type'   => 'file',
                        'id'     => $file['path'],
                        'label'  => $file['basename'],
                        'detail' => $file['path'],
                        'score'  => $score
                    );
                }
            }
        } catch (\Exception $e) {
            // ignore errors
        }
    }

    // search file contents
    if (isset($types['files-contents']) && isset($config['search']['p4_search_host'])) {
        $host     = trim($config['search']['p4_search_host'], '/');
        $identity = getIdentity($config);
        if ($identity && strlen($host)) {
            $url   = $host . '/api/search';
            $query = array(
                'userId'       => $identity['id'],
                'ticket'       => $identity['ticket'],
                'query'        => isset($_GET['q']) ? $_GET['q'] : '',
                'paths'        => array(), // empty paths make the query much faster
                'rowCount'     => $max,
                'resultFormat' => 'DETAILED'
            );
            $context  = stream_context_create(
                array(
                    'http' => array(
                        'method'  => 'POST',
                        'header'  => 'Content-Type: application/json',
                        'content' => json_encode($query)
                    )
                )
            );
            $response = json_decode(file_get_contents($url, false, $context), true);
            $payload  = isset($response['payload']) ? $response['payload'] : array();
            $matches  = isset($payload['detailedFilesModels']) ? $payload['detailedFilesModels'] : array();
            $maxScore = isset($payload['maxScore']) ? $payload['maxScore'] : null;
            foreach ($matches as $match) {
                $match    += array('filesModel' => array(), 'score' => 0);
                $file      = $match['filesModel'] + array('depotFile' => null);
                $score     = ($maxScore ? $match['score'] / $maxScore * 100 : $match['score']) * .5;
                $score    += getMatchScore($file, 'depotFile', $keywords) * .5;
                $results[] = array(
                    'type'   => 'file',
                    'id'     => $file['depotFile'],
                    'label'  => basename($file['depotFile']),
                    'detail' => $file['depotFile'],
                    'score'  => $score
                );
            }
        }
    }

    // sort matches by score (secondary sort by label)
    usort($results, function($a, $b) {
        $difference = $b['score'] - $a['score'];
        return $difference ?: strnatcasecmp($a['label'], $b['label']);
    });

    // limit to max results
    $results = $max > 0 ? array_slice($results, 0, $max) : $results;

    echo json_encode($results);

    function getIdentity($config)
    {
        $config = isset($config['session']) ? $config['session'] : array();

        // session cookie name is configurable
        // default is SWARM[-<PORT>] the port is only appended if not 80 or 443
        if (isset($config['name'])) {
            $name = $config['name'];
        } else {
            $server = $_SERVER + array('HTTP_HOST' => '', 'SERVER_PORT' => null);
            preg_match('/:(?P<port>[0-9]+)$/', $server['HTTP_HOST'], $matches);
            $port   = isset($matches['port']) && $matches['port'] ? $matches['port'] : $server['SERVER_PORT'];
            $name   = 'SWARM' . ($port == 80 || $port == 443 ? '' : '-' . $port);
        }

        // if no session cookie, then no identity
        if (!isset($_COOKIE[$name])) {
            return null;
        }

        // session save path is also configurable
        $path    = isset($config['save_path']) ? $config['save_path'] : DATA_PATH . '/sessions';
        $path    = rtrim($path, '/') . '/sess_' . $_COOKIE[$name];
        $session = is_readable($path) ? file_get_contents($path) : null;
        $pattern = '/Zend_Auth[^}]+id";s:[0-9]+:"([^"]+)"[^}]+ticket";(?:s:[0-9]+:"([^"]+)"|N);/';
        preg_match($pattern, $session, $matches);

        // return array containing two elements id and ticket or null if not auth'd
        $matches += array(null, null, null);
        return strlen($matches[1]) ? array('id' => $matches[1], 'ticket' => $matches[2]) : null;
    }

    function getLatestCache($key, $config)
    {
        $port    = isset($config['p4']['port']) ? $config['p4']['port'] : null;
        $pattern = DATA_PATH . '/cache/' . strtoupper(md5($port)) . '-' . strtoupper(bin2hex($key)) . '-*[!a-z]';
        $files   = glob($pattern, GLOB_NOSORT);
        natsort($files);
        return end($files);
    }

    function getMatchScore($values, $fields, array $keywords)
    {
        $score = 100;
        foreach ($keywords as $keyword) {
            $distance = null;
            foreach ((array) $fields as $field) {
                $value = isset($values[$field]) ? $values[$field] : null;
                if (($current = stripos($value, $keyword)) !== false) {
                    $current += (strlen($value) - strlen($keyword)) / 5;
                    if ($distance === null || $current < $distance) {
                        $distance = $current;
                    }
                }
            }
            if ($distance === null) {
                return false;
            }
            $score -= $distance;
        }

        return $score;
    }

    function getP4($config)
    {
        $identity     = (array) getIdentity($config) + array('id' => null, 'ticket' => null);
        $config       = isset($config['p4']) ? (array) $config['p4'] : array();
        $config      += array('port' => null, 'user' => null, 'password' => null);
        $p4           = new \P4;
        $p4->prog     = 'SWARM';
        $p4->port     = $config['port'];
        $p4->user     = $identity['id']     ?: $config['user'];
        $p4->password = $identity['ticket'] ?: $config['password'];
        $p4->tagged   = true;
        $p4->connect();

        return $p4;
    }

    function runP4()
    {
        $arguments = func_get_args();
        $p4        = array_shift($arguments);
        $arguments = array_filter($arguments, 'strlen');

        // detect unicode error and re-run with charset if needed
        try {
            return call_user_func_array(array($p4, 'run'), $arguments);
        } catch (\Exception $e) {
            if (stripos($e->getMessage(), 'unicode server') === false) {
                throw $e;
            }
            $p4->charset = 'utf8unchecked';
            return call_user_func_array(array($p4, 'run'), $arguments);
        }
    }
}
