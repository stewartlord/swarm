<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

return array(
    'environment' => array(
        'mode'      => getenv('SWARM_MODE') ?: 'production',
        'hostname'  => getenv('SWARM_HOST') ?: null
    ),
    'http_client_options' => array(
        'timeout'   => 5,
        'hosts'     => array()              // optional, per-host overrides; host as key, array of options as value
    ),
    'session' => array(
        'cookie_lifetime'            => 0,          // session cookie lifetime to use when remember me isn't checked
        'remembered_cookie_lifetime' => 30*24*60*60 // session cookie lifetime to use when remember me is checked
    ),
    'security' => array(
        'require_login'          => true,   // if enabled only the login screen will be accessible for anonymous users
        'disable_autojoin'       => false,  // if enabled user will not auto-join the swarm group on login
        'https_strict'           => false,  // if enabled, we'll tell clients to pin on https for 30 days
        'https_strict_redirect'  => true,   // if both https_strict and this are enabled; we meta-refresh HTTP to HTTPS
        'https_port'             => null,   // optionally, specify a non-standard port to use for https
        'emulate_ip_protections' => true,   // if enabled, ip-based protections matching user's remote ip are applied
        'disable_system_info'    => false,  // if enabled, system info is disabled (results in a 403 if accessed)
        'x_frame_options'        => 'SAMEORIGIN', // x-frame-options header to send - set to false to disable
        'csrf_exempt'            => array('goto'),
    ),
    'git_fusion' => array(
        'depot' => '.git-fusion',
        'user'  => 'git-fusion-user',
        'reown' => array(                   // git-fusion commits as its user then re-owns the change to the real author
            'retries'  => 20,               // we'll retry processing up to this many times to get the actual author
            'max_wait' => 60                // the delay between tries starts at 2 seconds and grows up to this limit
        )
    ),
    'css' => array(
        '/build/min.css' => array(
            '/vendor/bootstrap/css/bootstrap.min.css',
            '/vendor/prettify/prettify.css',
            '/swarm/css/style.css'
        )
    ),
    'p4' => array(
        'slow_command_logging'  => array(
            3,    // commands without a specific rule get a 3 second limit
            10 => array('print', 'shelve', 'submit', 'sync', 'unshelve')
        ),
        'max_changelist_files'  => 1000, // limit the number of files displayed in a change or a review
        'auto_register_url'     => true  // set to false to disable P4.Swarm.URL registration as a p4 property
    ),
    'js' => array(
        '/build/min.js' => array(
            '/vendor/jquery/jquery-1.11.1.min.js',
            '/vendor/jquery-sortable/jquery-sortable-min.js',
            '/vendor/bootstrap/js/bootstrap.min.js',
            '/vendor/diff_match_patch/diff_match_patch.js',
            '/vendor/jquery.expander/jquery.expander.min.js',
            '/vendor/jquery.timeago/jquery.timeago.js',
            '/vendor/jsrender/jsrender.js',
            '/vendor/prettify/prettify.js',
            '/vendor/jed/jed.js',
            '/swarm/js/jquery-plugins.js',
            '/swarm/js/bootstrap-extensions.js',
            '/swarm/js/application.js',
            '/swarm/js/activity.js',
            '/swarm/js/users.js',
            '/swarm/js/projects.js',
            '/swarm/js/groups.js',
            '/swarm/js/files.js',
            '/swarm/js/changes.js',
            '/swarm/js/comments.js',
            '/swarm/js/attachments.js',
            '/swarm/js/reviews.js',
            '/swarm/js/jobs.js',
            '/swarm/js/3dviewer.js',
            '/swarm/js/i18n.js',
            '/swarm/js/search.js',
            '/swarm/js/init.js'
        )
    ),
    'router' => array(
        'routes' => array(
            'about' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/about[/]',
                    'defaults' => array(
                        'controller' => 'Application\Controller\Index',
                        'action'     => 'about',
                    ),
                ),
            ),
            'goto'  => array(
                'type' => 'Application\Router\Regex',
                'options' => array(
                    'regex'    => '/(@+)?(?P<id>.+)',
                    'spec'     => '/@%id%',
                    'defaults' => array(
                        'controller' => 'Application\Controller\Index',
                        'action'     => 'goto',
                        'id'         => null
                    ),
                ),
                'priority' => -1000     // we'll catch anything that falls through by setting a late priority
            ),
            'info' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/info[/]',
                    'defaults' => array(
                        'controller' => 'Application\Controller\Index',
                        'action'     => 'info',
                    ),
                ),
            ),
            'log' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/info/log[/]',
                    'defaults' => array(
                        'controller' => 'Application\Controller\Index',
                        'action'     => 'log',
                    ),
                ),
            ),
            'phpinfo' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/info/phpinfo[/]',
                    'defaults' => array(
                        'controller' => 'Application\Controller\Index',
                        'action'     => 'phpinfo',
                    ),
                ),
            ),
            'upgrade' => array(
                'type' => 'Zend\Mvc\Router\Http\Segment',
                'options' => array(
                    'route'    => '/upgrade[/]',
                    'defaults' => array(
                        'controller' => 'Reviews\Controller\Index',
                        'action'     => 'upgrade',
                    ),
                ),
            ),
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'Application\Controller\Index' => 'Application\Controller\IndexController'
        ),
    ),
    'service_manager' => array(
        'aliases'   => array(
            'translator' => 'MvcTranslator',
        ),
        'factories' => array(
            'logger'    => function ($services) {
                // @todo    update to use logger factory when available
                //          see PR #2725 (milestone 2.1.0)
                $config = $services->get('config');
                $logger = new Zend\Log\Logger;
                $file   = isset($config['log']['file']) ? $config['log']['file'] : null;

                // if a file was specified but doesn't exist attempt to create
                // it (unless we are running on the command line).
                // for cli usage we don't want to risk the log being owned by
                // a user other than the web-server so we won't touch it here.
                if ($file && !file_exists($file) && php_sapi_name() !== 'cli') {
                    touch($file);
                }

                // if a writable file was specified use it, otherwise just use null
                if ($file && is_writable($file)) {
                    $writer = new Zend\Log\Writer\Stream($file);
                    if (isset($config['log']['priority'])) {
                        $writer->addFilter((int) $config['log']['priority']);
                    }
                    $logger->addWriter($writer);
                } else {
                    $logger->addWriter(new Zend\Log\Writer\Null);
                }

                // register a custom error handler; we can not use the logger's as
                // it would log 'context' which gets vastly too noisy
                set_error_handler(
                    function ($level, $message, $file, $line) use ($logger) {
                        if (error_reporting() & $level) {
                            $map = Zend\Log\Logger::$errorPriorityMap;
                            $logger->log(
                                isset($map[$level]) ? $map[$level] : $logger::INFO,
                                $message,
                                array(
                                     'errno'   => $level,
                                     'file'    => $file,
                                     'line'    => $line
                                )
                            );
                        }

                        return false;
                    }
                );

                return $logger;
            },
            'p4'    => function ($services) {
                // if we have a logged in user, we want to use their connection
                // to perforce. otherwise, we will use the admin connection
                if ($services->get('permissions')->is('authenticated')) {
                    return $services->get('p4_user');
                }

                // doesn't appear anyone is logged in, run as admin
                return $services->get('p4_admin');
            },
            'p4_admin' => function ($services) {
                $config  = $services->get('config') + array('p4' => array());
                $p4      = (array) $config['p4'];

                $factory = new \Application\Connection\ConnectionFactory($p4);
                return $factory->createService($services);
            },
            'p4_user' => function ($services) {
                $config   = $services->get('config') + array('p4' => array());
                $p4       = (array) $config['p4'];
                $auth     = $services->get('auth');
                $identity = $auth->hasIdentity() ? (array) $auth->getIdentity() : array();

                // can't get a user specific connection if user is not authenticated
                if (!isset($identity['id']) || !strlen($identity['id'])) {
                    throw new \Application\Permissions\Exception\UnauthorizedException;
                }

                // tweak the 'p4' settings to use the users id/ticket and ensure password isn't present
                $p4['user']   = $identity['id'];
                $p4['ticket'] = isset($identity['ticket']) ? $identity['ticket'] : null;
                unset($p4['password']);

                $factory    = new \Application\Connection\ConnectionFactory($p4);
                $connection = $factory->createService($services);

                // share a cache with the 'admin' connection
                $connection->setService(
                    'cache',
                    function () use ($services) {
                        return $services->get('p4_admin')->getService('cache');
                    }
                );

                // verify the user is authenticated.
                // if the ticket/password is invalid, try to clean up the auth and user
                // services to reflect the anonymous state (someone may have fetched them
                // before us leaving them otherwise in a bad state).
                if (!$connection->isAuthenticated()) {
                    // if our bad connection is the default; clear it
                    if (P4\Connection\Connection::hasDefaultConnection()
                        && P4\Connection\Connection::getDefaultConnection() === $connection
                    ) {
                        P4\Connection\Connection::clearDefaultConnection();
                    }

                    // if using session-based auth, empty/destroy the session
                    if ($auth->getStorage() instanceof Zend\Authentication\Storage\Session) {
                        $session = $services->get('session');
                        $session->start();
                        $auth->getStorage()->write(null);
                        $session->destroy(array('send_expire_cookie' => true, 'clear_storage' => true));
                        $session->writeClose();
                    }

                    // if the user service is already instantiated, clear
                    // the existing object; we want to try and clear out
                    // anyone who already has a copy.
                    $registered = $services->getRegisteredServices();
                    if (in_array('user', $registered['instances'])) {
                        $services->get('user')
                            ->setId(null)
                            ->setEmail(null)
                            ->setFullName(null)
                            ->setJobView(null)
                            ->setReviews(array())
                            ->setConfig(new \Users\Model\Config);
                    }

                    throw new \Application\Permissions\Exception\UnauthorizedException;
                }

                return $connection;
            },
            'session'   => function ($services) {
                $config  = $services->get('config') + array('session' => array());
                $strict  = isset($config['security']['https_strict']) && $config['security']['https_strict'];
                $config  = $config['session'] + array(
                    'name'                       => null,
                    'save_path'                  => null,
                    'cookie_lifetime'            => null,
                    'remembered_cookie_lifetime' => null
                );

                // detect if we're on https, if we are (or we're strict) we'll set the cookie to secure
                $request = $services->get('request');
                $https   = $request instanceof Zend\Http\Request && $request->getUri()->getScheme() == 'https';

                // by default, relocate session storage if we can to avoid mixing with
                // other php apps using different/default session clean settings.
                $config['save_path'] = $config['save_path'] ?: DATA_PATH . '/sessions';
                is_dir($config['save_path']) ?: @mkdir($config['save_path'], 0700, true);
                if (!is_writable($config['save_path'])) {
                    unset($config['save_path']);
                }

                // by default, we name the session id SWARM and, if its running on a
                // non-standard port, we add the port number. This allows separate
                // Swarm instances to run on a given domain using different ports.
                if (!$config['name']) {
                    // we try to extract the port from the HTTP_HOST if possible.
                    // if we fail to find it there we fall back to the SERVER_PORT variable
                    // SERVER_PORT is fairly certain to be present but known to report 80
                    // even when another port is in use under some apache configurations.
                    $server = $_SERVER + array('HTTP_HOST' => '', 'SERVER_PORT' => null);
                    preg_match('/:(?P<port>[0-9]+)$/', $server['HTTP_HOST'], $matches);
                    $port = isset($matches['port']) && $matches['port']
                        ? $matches['port']
                        : $server['SERVER_PORT'];
                    $config['name'] = 'SWARM'
                                    . ($port && $port != 80 && $port != 443 ? '-' . $port : '');
                }

                // verify the session isn't already started (shouldn't be) and adjust
                // the settings. attempting an adjustment post start produces errors.
                $sessionConfig = new \Zend\Session\Config\SessionConfig;
                if (!session_id()) {
                    // if the user has a 'remember me' cookie utilize the 'remembered' cookie lifetime
                    // note we have to clear the made up remembered_cookie_lifetime regardless as it
                    // would cause an exception if it makes it into the session config.
                    if (isset($_COOKIE['remember']) && $_COOKIE['remember']) {
                        $config['cookie_lifetime'] = $config['remembered_cookie_lifetime'];
                    }
                    unset($config['remembered_cookie_lifetime']);

                    // set the session config by mixing any user provided config
                    // values with our defaults
                    $sessionConfig->setOptions(
                        $config +
                        array(
                            'cookie_httponly'  => true,
                            'cookie_secure'    => $https || $strict,
                            'gc_probability'   => 1,
                            'gc_divisor'       => 100,
                            'gc_maxlifetime'   => 24*60*60 * 30    // 1 month
                        )
                    );
                }

                $session = new Application\Session\SessionManager($sessionConfig);

                // a couple conditions require the session id pre-start, get it if possible
                $sessionName = $sessionConfig->getOption('name');
                $sessionId   = isset($_COOKIE[$sessionName]) ? $_COOKIE[$sessionName] : null;

                // if we have no session cookie, no need to deal with session expiry or
                // read current values from disk; just bail!
                if (!strlen($sessionId)) {
                    return $session;
                }

                // we want to actually enforce the gc lifetime for file-based sessions.
                // to support this, pull the mtime from the session file before we start.
                $sessionFile = strlen($sessionId) && $sessionConfig->getOption('save_handler') == 'files'
                    ? $sessionConfig->getOption('save_path') . '/sess_' . $sessionId
                    : false;
                $sessionTime = $sessionFile && file_exists($sessionFile) ? filemtime($sessionFile) : false;

                // ensure the session is started (to populate session storage data)
                // but promptly close it to minimize locking - anytime we need
                // to update the session later, we need to explicitly open/close it.
                $session->start();

                // if we found a session file mod-time and its expired, destroy the session
                if ($sessionTime
                    && (time() - $sessionTime) > $sessionConfig->getOption('gc_maxlifetime')
                ) {
                    $session->destroy(array('send_expire_cookie' => true, 'clear_storage' => true));
                }

                $session->writeClose();

                return $session;
            },
            'permissions'   => function ($services) {
                return new Application\Permissions\Permissions($services);
            },
            'ip_protects' => function ($services) {
                $config   = $services->get('config');
                $remoteIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
                $enabled  = isset($config['security']['emulate_ip_protections'])
                    && $config['security']['emulate_ip_protections'];

                // create and configure ip protections emulation
                $protections = new Application\Permissions\Protections;
                $protections->setEnabled(false);

                if ($enabled && $remoteIp) {
                    $p4 = $services->get('p4');

                    // determine whether connected server is case sensitive or case insensitive
                    // if we can't puzzle it out, treat is as case sensitive (more restrictive)
                    try {
                        $isCaseSensitive = $p4->isCaseSensitive();
                    } catch (P4\Exception $e) {
                        $isCaseSensitive = true;
                    }

                    // collect lines from the protections table to apply
                    // we take non-proxy rules for user's IP, but we also take proxy rules to
                    // express we treat Swarm as an intermediary
                    try {
                        $protectionsData = array_merge(
                            $p4->run('protects', array('-h',            $remoteIp))->getData(),
                            $p4->run('protects', array('-h', 'proxy-' . $remoteIp))->getData()
                        );

                        // sort merged protections data to preserve their original order in the protections table
                        usort(
                            $protectionsData,
                            function (array $a, array $b) {
                                return (int) $a['line'] - (int) $b['line'];
                            }
                        );

                        $protections->setProtections($protectionsData, $isCaseSensitive);
                        $protections->setEnabled(true);
                    } catch (P4\Connection\Exception\CommandException $e) {
                        if (strpos($e->getMessage(), 'Protections table is empty.') === false) {
                            // we don't recognize the message, so re-throw the exception
                            throw $e;
                        }
                    }
                }

                return $protections;
            },
            'depot_storage' => function ($services) {
                $config = $services->get('config');
                $config = $config['depot_storage'] + array('base_path'=>null);

                $depot = new Record\File\FileService($services->get('p4_admin'));
                $depot->setConfig($config);

                return $depot;
            },
            'changes_filter' => function ($services) {
                return new Application\Permissions\RestrictedChanges($services->get('p4'));
            },
            'csrf'  => function ($services) {
                return new Application\Permissions\Csrf\Service($services);
            },
            'MvcTranslator' => function ($services) {
                $config     = $services->get('config');
                $config     = isset($config['translator']) ? $config['translator'] : array();
                $translator = \Application\I18n\Translator::factory($config);

                $translator->setEscaper(new \Application\Escaper\Escaper);

                // add event listener for context fallback on missing translations
                $translator->enableEventManager();
                $translator->getEventManager()->attach(
                    $translator::EVENT_MISSING_TRANSLATION,
                    array($translator, 'handleMissingTranslation')
                );

                // establish default locale settings
                $translator->setLocale($translator->getLocale() ?: 'en_US');
                $translator->setFallbackLocale($translator->getFallbackLocale() ?: 'en_US');

                // try to guess locale from browser language header (using intl if available)
                if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
                    && (!isset($config['detect_locale']) || $config['detect_locale'] !== false)
                ) {
                    $locale = extension_loaded('intl')
                        ? \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE'])
                        : str_replace('-', '_', current(explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])));

                    // if we can't find an exact match, venture a guess based on language prefix
                    if (!$translator->isSupportedLocale($locale)) {
                        $language = current(preg_split('/[^a-z]/i', $locale));
                        $locale   = $translator->isSupportedLanguage($language) ?: $locale;
                    }

                    $translator->setLocale(strlen($locale) ? $locale : $translator->getLocale());
                }

                return $translator;
            },
        ),
    ),
    'translator' => array(
        'locale'                    => 'en_US',
        'detect_locale'             => true,
        'translation_file_patterns' => array(
            array(
                'type'        => 'gettext',
                'base_dir'    => BASE_PATH . '/language',
                'pattern'     => '%s/default.mo',
            ),
        ),
    ),
    'view_manager' => array(
        'display_not_found_reason' => false,
        'display_exceptions'       => false,
        'doctype'                  => 'HTML5',
        'not_found_template'       => 'error/index',
        'exception_template'       => 'error/index',
        'template_map' => array(
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'layout/toolbar'          => __DIR__ . '/../view/layout/toolbar.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
        ),
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
        'strategies' => array(
            'ViewJsonStrategy', 'ViewFeedStrategy'
        ),
    ),
    'log' => array(
        'file'      => DATA_PATH . '/log',
        'priority'  => 3 // just log errors by default
    ),
    'view_helpers' => array(
        'invokables' => array(
            'breadcrumbs'       => 'Application\View\Helper\Breadcrumbs',
            'bodyClass'         => 'Application\View\Helper\BodyClass',
            'csrf'              => 'Application\View\Helper\Csrf',
            'escapeFullUrl'     => 'Application\View\Helper\EscapeFullUrl',
            'headLink'          => 'Application\View\Helper\HeadLink',
            'headScript'        => 'Application\View\Helper\HeadScript',
            'linkify'           => 'Application\View\Helper\Linkify',
            'permissions'       => 'Application\View\Helper\Permissions',
            'preformat'         => 'Application\View\Helper\Preformat',
            'qualifiedUrl'      => 'Application\View\Helper\QualifiedUrl',
            'request'           => 'Application\View\Helper\Request',
            'shortenStackTrace' => 'Application\View\Helper\ShortenStackTrace',
            'truncate'          => 'Application\View\Helper\Truncate',
            'utf8Filter'        => 'Application\View\Helper\Utf8Filter',
            'wordify'           => 'Application\View\Helper\Wordify',
            'wordWrap'          => 'Application\View\Helper\WordWrap',
            't'                 => 'Application\View\Helper\Translate',
            'te'                => 'Application\View\Helper\TranslateEscape',
            'tp'                => 'Application\View\Helper\TranslatePlural',
            'tpe'               => 'Application\View\Helper\TranslatePluralEscape',
        ),
    ),
    'controller_plugins' => array(
        'invokables' => array(
            'Disconnect'    => 'Application\Controller\Plugin\Disconnect'
        )
    ),
    'depot_storage' => array(
        'base_path' => '//.swarm'
    )
);
