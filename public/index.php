<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

// enable profiling if xhprof is present
extension_loaded('xhprof') && xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);

use Zend\Loader\AutoloaderFactory;
use Zend\Mvc\Application;

define('BASE_PATH', dirname(__DIR__));

// allow DATA_PATH to be overridden via an environment variable
define(
    'DATA_PATH',
    getenv('SWARM_DATA_PATH') ? rtrim(getenv('SWARM_DATA_PATH'), '/\\') : BASE_PATH . '/data'
);

// sanity check things first
sanityCheck();

// setup autoloading
set_include_path(BASE_PATH);
include 'library/Zend/Loader/AutoloaderFactory.php';
AutoloaderFactory::factory(
    array(
        'Zend\Loader\StandardAutoloader' => array(
            'namespaces' => array(
                'P4'     => BASE_PATH . '/library/P4',
                'Record' => BASE_PATH . '/library/Record',
                'Zend'   => BASE_PATH . '/library/Zend'
            )
        )
    )
);

// ensure strict and notice is disabled; otherwise keep the existing levels
error_reporting(error_reporting() & ~(E_STRICT|E_NOTICE));

// configure and run the application
Application::init(
    array(
        'modules' => array_map(
            'basename',
            array_map('dirname',  glob(BASE_PATH . '/module/*/Module.php'))
        ),
        'module_listener_options' => array(
            'module_paths'      => array(BASE_PATH . '/module'),
            'config_glob_paths' => array(DATA_PATH . '/config.php')
        ),
    )
)->run();

// do what we can to report what we can detect might be misconfigured
function sanityCheck()
{
    $config       = DATA_PATH . '/config.php';

    // check what could be misconfigured
    $badPhp       = (!defined('PHP_VERSION_ID') || (PHP_VERSION_ID < 50303));
    $noP4php      = !extension_loaded('perforce');
    $noIconv      = !extension_loaded('iconv');
    $noJson       = !extension_loaded('json');
    $noSession    = !extension_loaded('session');
    $numPhpIssues = $badPhp + $noP4php + $noIconv + $noJson + $noSession;
    $badDataDir   = !is_writeable(DATA_PATH);
    $noConfig     = !file_exists($config);
    $threadSafe   = defined('ZEND_THREAD_SAFE') ? ZEND_THREAD_SAFE : false;
    $numIssues    = $numPhpIssues + $badDataDir + $noConfig + $threadSafe;

    // if anything is misconfigured, compose error page and then die
    if ($numIssues) {
        $html = '<html><body>'
            . '<h1>Swarm has detected a configuration error</h1>'
            . '<p>Problem' . ($numIssues > 1 ? 's' : '') . ' detected:</p>';

        // compose message per condition
        $html                .= '<ul>';
        $badPhp     && $html .= '<li>Perforce Swarm requires PHP 5.3.3 or higher; you have ' . PHP_VERSION . '.</li>';
        $noP4php    && $html .= '<li>The Perforce PHP extension (P4PHP) is not installed or enabled.</li>';
        $noIconv    && $html .= '<li>The iconv PHP extension is not installed or enabled.</li>';
        $noJson     && $html .= '<li>The json PHP extension is not installed or enabled.</li>';
        $noSession  && $html .= '<li>The session PHP extension is not installed or enabled.</li>';
        $badDataDir && $html .= '<li>The data directory (' . DATA_PATH . ') is not writeable.</li>';
        $noConfig   && $html .= '<li>Swarm configuration file does not exist (' . $config . ').</li>';
        $threadSafe && $html .= '<li>Thread-safe PHP detected -- Swarm does not support running with thread-safe PHP.'
            . ' To remedy, install or rebuild a non-thread-safe variant of PHP and Apache (prefork).</li>';
        $html                .= '</ul>';

        // display further information if there were any PHP issues
        if ($numPhpIssues) {
            // tell the user where the php.ini file is
            $php_ini_file = php_ini_loaded_file();
            if ($php_ini_file) {
                $html .= '<p>The php.ini file loaded is ' . $php_ini_file . '.</p>';
            } else {
                $html .= '<p>There is no php.ini loaded (expected to find one in ' . PHP_SYSCONFDIR . ').</p>';
            }

            // if there are additional php.ini files, list them here
            if (php_ini_scanned_files()) {
                $html .= '<p>Other scanned php.ini files (in ' . PHP_CONFIG_FILE_SCAN_DIR . ') include:</p>'
                    . '<ul><li>' . implode('</li><li>', explode(",\n", php_ini_scanned_files())) . '</li></ul>';
            }
        }

        // wrap it up with links to the docs
        $html .= '<p>For more information, please see the'
            . ' <a href="/docs/chapter.setup.html">Setting Up</a> documentation;'
            . ' in particular:</p>'
            . '<ul>'
            . '<li><a href="/docs/setup.installation.html">Initial Installation</a></li>'
            . '<li><a href="/docs/setup.dependencies.html">Runtime dependencies</a></li>'
            . '<li><a href="/docs/setup.php.html">PHP configuration</a></li>'
            . '<li><a href="/docs/setup.swarm.html">Swarm configuration</a></li>'
            . '</ul>'
            . '<p>Please ensure you restart your web server after making any PHP changes.</p>'
            . '</body></html>';

        // goodbye cruel world
        die($html);
    }
}
