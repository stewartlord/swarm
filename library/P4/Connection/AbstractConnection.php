<?php
/**
 * Abstract class for Perforce Connection implementations.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 * @todo        verify need to call disconnect() on setClient/Password/etc.
 */

namespace P4\Connection;

use P4;
use P4\Connection\Exception\ServiceNotFoundException;
use P4\Counter\Counter;
use P4\Environment\Environment;
use P4\Log\Logger;
use P4\Time\Time;
use P4\Validate;

abstract class AbstractConnection implements ConnectionInterface
{
    const LOG_MAX_STRING_LENGTH     = 1024;
    const DEFAULT_CHARSET           = 'utf8unchecked';

    protected $client;
    protected $info;
    protected $password;
    protected $port;
    protected $ticket;
    protected $ticketUnlocked;
    protected $user;
    protected $charset;
    protected $host;
    protected $appName;
    protected $progName;
    protected $progVersion;
    protected $services;
    protected $disconnectCallbacks  = array();
    protected $preRunCallbacks      = array();
    protected $postRunCallbacks     = array();
    protected $slowCommandLogging   = array();

    /**
     * Create an Interface instance.
     *
     * @param   string  $port        optional - the port to connect to.
     * @param   string  $user        optional - the user to connect as.
     * @param   string  $client      optional - the client spec to use.
     * @param   string  $password    optional - the password to use.
     * @param   string  $ticket      optional - a ticket to use.
     */
    public function __construct(
        $port = null,
        $user = null,
        $client = null,
        $password = null,
        $ticket = null
    ) {
        $this->setPort($port);
        $this->setUser($user);
        $this->setClient($client);
        $this->setPassword($password);
        $this->setTicket($ticket);

        // ensure we disconnect on shutdown.
        Environment::addShutdownCallback(
            array($this, 'disconnect')
        );
    }

    /**
     * Return the p4 port.
     *
     * @return  string  the port.
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Set the p4 port.
     * Forces a disconnect if already connected.
     *
     * @param   string  $port           the port to connect to.
     * @return  ConnectionInterface     provides fluent interface.
     * @todo    validate port using port validator - make validator work with 'rsh:' ports.
     */
    public function setPort($port)
    {
        $this->port = (string) $port;

        // disconnect on port change.
        $this->disconnect();

        return $this;
    }

    /**
     * Return the name of the p4 user.
     *
     * @return  string  the user.
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set the name of the p4 user.
     * Forces a disconnect if already connected.
     *
     * @param   string  $user           the user to connect as.
     * @return  ConnectionInterface     provides fluent interface.
     * @throws  P4\Exception            if the user is not valid.
     */
    public function setUser($user)
    {
        $validator = new Validate\UserName;

        if ($user !== null && !$validator->isValid($user)) {
            throw new P4\Exception("Username: " . implode("\n", $validator->getMessages()));
        }

        $this->user = $user;

        // disconnect on user change.
        $this->disconnect();

        return $this;
    }

    /**
     * Return the p4 user's client.
     *
     * @return  string  the client.
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set the p4 user's client.
     * Forces a disconnect if already connected.
     *
     * @param   string  $client         the name of the client workspace to use.
     * @return  ConnectionInterface     provides fluent interface.
     * @throws  P4\Exception            if the client is not valid.
     */
    public function setClient($client)
    {
        $validator = new Validate\SpecName;

        if ($client !== null && !$validator->isValid($client)) {
            throw new P4\Exception("Client name: " . implode("\n", $validator->getMessages()));
        }

        $this->client = $client;

        // clear cached p4 info
        $this->info = null;

        return $this;
    }

    /**
     * Retrieves the password set for this perforce connection.
     *
     * @return  string  password used to authenticate against perforce server.
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Sets the password to use for this perforce connection.
     *
     * @param   string  $password       the password to use as authentication.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Retrieves the ticket set for this perforce connection.
     *
     * @return  string  ticket as generated by perforce server.
     */
    public function getTicket()
    {
        return $this->ticket;
    }

    /**
     * Check if last login call was to generate a ticket valid for all hosts
     *
     * @return  bool    last login call was for an unlocked ticket
     */
    public function isTicketUnlocked()
    {
        return (bool) $this->ticketUnlocked;
    }

    /**
     * Sets the ticket to use for this perforce connection.
     * Forces a disconnect if already connected.
     *
     * @param   string  $ticket         the ticket to use as authentication.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setTicket($ticket)
    {
        $this->ticket = $ticket;

        // disconnect on ticket change.
        $this->disconnect();

        return $this;
    }

    /**
     * Retrieves the character set used by this connection.
     *
     * @return  string  charset used for this connection.
     */
    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * Sets the character set to use for this perforce connection.
     *
     * You should only set a character set when connecting to a
     * 'unicode enabled' server, or when setting the special value
     * of 'none'.
     *
     * @param   string  $charset        the charset to use (e.g. 'utf8').
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setCharset($charset)
    {
        $this->charset = $charset;

        return $this;
    }

    /**
     * Retrieves the client host set for this connection.
     *
     * @return  string  host name used for this connection.
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Sets the client host name overriding the environment.
     *
     * @param   string|null $host       the host name to use.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setHost($host)
    {
        $this->host = $host;

        return $this;
    }

    /**
     * Set the name of the application that is using this connection.
     *
     * The application name will be reported to the server and might
     * be necessary to satisfy certain licensing restrictions.
     *
     * @param   string|null     $name   the app name to report to the server.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setAppName($name)
    {
        $this->appName = is_null($name) ? $name : (string) $name;

        return $this;
    }

    /**
     * Get the application name being reported to the server.
     *
     * @return  string|null     the app name reported to the server.
     */
    public function getAppName()
    {
        return $this->appName;
    }

    /**
     * Set the name of the program that is using this connection.
     *
     * The program name will be reported in the server logs
     *
     * @param   string|null     $name   the program name to report to the server.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setProgName($name)
    {
        $this->progName = is_null($name) ? $name : (string) $name;

        return $this;
    }

    /**
     * Get the program name being reported to the server.
     *
     * @return  string|null     the program name reported to the server.
     */
    public function getProgName()
    {
        return $this->progName;
    }

    /**
     * Set the program version of the program that is using this connection.
     *
     * The program version will be reported in the server logs
     *
     * @param   string|null     $version the program version to report to the server.
     * @return  ConnectionInterface      provides fluent interface.
     */
    public function setProgVersion($version)
    {
        $this->progVersion = is_null($version) ? $version : (string) $version;

        return $this;
    }

    /**
     * Get the program version being reported to the server.
     *
     * @return  string|null     the program version reported to the server.
     */
    public function getProgVersion()
    {
        return $this->progVersion;
    }

    /**
     * Get the current client's root directory with no trailing slash.
     *
     * @return  string  the full path to the current client's root.
     */
    public function getClientRoot()
    {
        $info = $this->getInfo();
        if (isset($info['clientRoot'])) {
            return rtrim($info['clientRoot'], '/\\');
        }
        return false;
    }

    /**
     * Return an array of connection information.
     * Due to caching, server date may be stale.
     *
     * @return  array   the connection information ('p4 info').
     */
    public function getInfo()
    {
        // if info cache is populated and connection is up, return cached info.
        if (isset($this->info) && $this->isConnected()) {
            return $this->info;
        }

        // run p4 info.
        $result      = $this->run("info");
        $this->info = array();

        // gather all data (multiple arrays returned when connecting through broker).
        foreach ($result->getData() as $data) {
            $this->info += $data;
        }

        return $this->info;
    }

    /**
     * Clear the info cache. This method is primarily used during testing,
     * and would not normally be used.
     *
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function clearInfo()
    {
        $this->info = null;

        return $this;
    }

    /**
     * Get the server identity of this connection.
     * Resulting array will contain:
     *  - name
     *  - platform
     *  - version
     *  - build
     *  - apiversion (same value as version, included for consistency)
     *  - apibuild   (same value as build, included for consistency)
     *  - date
     *  - original   (all text for server version from 'info' response)
     *
     * @return  array           an array of server information for this connection
     * @throws  P4\Exception    if the returned server version string is invalid
     */
    public function getServerIdentity()
    {
        $info  = $this->getInfo();
        $parts = isset($info['serverVersion'])
            ? preg_split('/\/| \(|\)/', $info['serverVersion'])
            : null;
        if (count($parts) < 6) {
            $message = 'p4 info returned an invalid server version string';
            throw new P4\Exception($message);
        }

        // build server identity array of version components, including original string
        return array(
            'name'       => $parts[0],
            'platform'   => $parts[1],
            'version'    => $parts[2],
            'build'      => $parts[3],
            'apiversion' => $parts[2],
            'apibuild'   => $parts[3],
            'date'       => $parts[4] . '/' . $parts[5] . '/' . $parts[6],
            'original'   => $info['serverVersion']
        );
    }

    /**
     * Return perforce server version in the form of '<year>.<release>'.
     *
     * @return  string          server version as '<year>.<release>'
     * @throws  P4\Exception    if server version cannot be determined
     */
    public function getServerVersion()
    {
        $identity = $this->getServerIdentity();
        $version  = $identity['version'];

        // keep only '<year>.<release>' of the version
        $parts = explode('.', $version);
        if (count($parts) < 2) {
            throw new P4\Exception(
                'Cannot get version from server identity: unknown version format.'
            );
        }
        $version = implode('.', array_slice($parts, 0, 2));

        return $version;
    }

    /**
     * Check if the server version for this connection is same or higher than
     * the version passed in the parameter.
     *
     * @param   string  $version    version to compare in format <year>.<release>
     * @return  bool    true if server version is same or higher than $version
     */
    public function isServerMinVersion($version)
    {
        return version_compare($this->getServerVersion(), $version) >= 0;
    }

    /**
     * Check if the P4API version for this connection is same or higher than
     * the version passed in the parameter.
     *
     * @param   string  $version    version to compare in format <year>.<release>
     * @return  bool    true if P4API version is same or higher than $version
     * @throws  P4\Exception        if the apiVersion string is invalid
     */
    public function isApiMinVersion($version)
    {
        $identity   = $this->getConnectionIdentity();
        $apiVersion = isset($identity['apiversion']) ? $identity['apiversion'] : '';

        // keep only '<year>.<release>' of the apiVersion
        $parts = explode('.', $apiVersion);
        if (count($parts) < 2) {
            throw new P4\Exception(
                'Cannot get version from connection identity: unknown version format.'
            );
        }
        $apiVersion = implode('.', array_slice($parts, 0, 2));

        return version_compare($apiVersion, $version) >= 0;
    }

    /**
     * Check if the user is authenticated
     *
     * Note: if the user has no password, but one has been set on the connection, we consider that not authenticated.
     *
     * @return bool     true if user is authenticated, false otherwise
     */
    public function isAuthenticated()
    {
        try {
            $result = $this->run('login', '-s', null, false);
            $result = implode($result->getData());
        } catch (Exception\CommandException $e) {
            return false;
        }

        // if a password is not required but one was provided, we should reject that connection on principle
        if (strpos("'login' not necessary, no password set for this user.", $result) !== false
            && (strlen($this->password) || strlen($this->ticket))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Return option limit (server-side limit on the number of flags)
     * based on the server version.
     *
     * @return  int     option limit
     */
    public function getOptionLimit()
    {
        $limit = 20;
        if ($this->isServerMinVersion('2012.1')) {
            $limit = 256;
        }

        return $limit;
    }

    /**
     * Authenticate the user with 'p4 login'.
     *
     * @param   bool|null       $all    get a ticket valid for all hosts (false by default)
     * @return  string|null     the ticket issued by the server or null if
     *                          no ticket issued (ie. user has no password).
     * @throws  Exception\LoginException    if login fails.
     */
    public function login($all = false)
    {
        // record whether or not caller requested an unlocked ticket
        $this->ticketUnlocked = (bool) $all;

        // ensure user name is set.
        if (!strlen($this->getUser())) {
            throw new Exception\LoginException(
                "Login failed. Username is empty.",
                Exception\LoginException::IDENTITY_AMBIGUOUS
            );
        }

        // try to login.
        try {
            $result = $this->run('login', $all ? array('-a', '-p') : array('-p'), $this->password ?: '');
        } catch (Exception\CommandException $e) {

            // user doesn't exist.
            if (stristr($e->getMessage(), "doesn't exist") ||
                stristr($e->getMessage(), "has not been enabled by 'p4 protect'")
            ) {
                throw new Exception\LoginException(
                    "Login failed. " . $e->getMessage(),
                    Exception\LoginException::IDENTITY_NOT_FOUND
                );
            }

            // invalid password.
            if (stristr($e->getMessage(), "password invalid")) {
                throw new Exception\LoginException(
                    "Login failed. " . $e->getMessage(),
                    Exception\LoginException::CREDENTIAL_INVALID
                );
            }

            // generic login exception.
            throw new Exception\LoginException(
                "Login failed. " . $e->getMessage()
            );
        }

        // we can get several output blocks
        // we want the first block that looks like a ticket
        // if user has no password, the last block will be a message
        // if using external auth, early blocks could be trigger output
        // if talking to a replica, the last block will be a ticket for the master
        $response = end($result->getData());
        foreach ($result->getData() as $data) {
            if (preg_match('/^[A-F0-9]{32}$/', $data)) {
                $response = $data;
                break;
            }
        }

        // check if no password set for this user.
        // fail if a password was provided - succeed otherwise.
        if (stristr($response, "no password set for this user")) {
            if ($this->password) {
                throw new Exception\LoginException(
                    "Login failed. " . $response,
                    Exception\LoginException::CREDENTIAL_INVALID
                );
            } else {
                return null;
            }
        }

        // capture ticket from output.
        $this->ticket = $response;

        // if ticket wasn't captured correctly, fail with unknown code.
        if (!$this->ticket) {
            throw new Exception\LoginException(
                "Login failed. Unable to capture login ticket."
            );
        }

        return $this->ticket;
    }

    /**
     * Executes the specified command and returns a perforce result object.
     * No need to call connect() first. Run will connect automatically.
     *
     * Performs common pre/post-run work. Hands off to doRun() for the
     * actual mechanics of running commands.
     *
     * @param   string          $command        the command to run.
     * @param   array|string    $params         optional - one or more arguments.
     * @param   array|string    $input          optional - input for the command - should be provided
     *                                          in array form when writing perforce spec records.
     * @param   boolean         $tagged         optional - true/false to enable/disable tagged output.
     *                                          defaults to true.
     * @param   boolean         $ignoreErrors   optional - true/false to ignore errors - default false
     * @return  P4\Connection\CommandResult     the perforce result object.
     */
    public function run(
        $command,
        $params = array(),
        $input = null,
        $tagged = true,
        $ignoreErrors = false
    ) {
        // establish connection to perforce server.
        if (!$this->isConnected()) {
            $this->connect();
        }

        // ensure params is an array.
        if (!is_array($params)) {
            if (!empty($params)) {
                $params = array($params);
            } else {
                $params = array();
            }
        }

        // log the start of the command w. params.
        $message = "P4 (" . spl_object_hash($this) . ") start command: "
                 . $command . " " . implode(" ", $params);
        Logger::log(
            Logger::DEBUG,
            substr($message, 0, static::LOG_MAX_STRING_LENGTH)
        );

        // prepare input for passing to perforce.
        $input = $this->prepareInput($input, $command);

        // run any 'pre-run' callbacks
        foreach ($this->preRunCallbacks as $callback) {
            $callback($this, $command, $params, $input, $tagged);
        }

        // defer to sub-classes to actually issue the command.
        $start  = microtime(true);
        $result = $this->doRun($command, $params, $input, $tagged);
        $lapse  = microtime(true) - $start;

        // run any 'post-run' callbacks
        foreach ($this->postRunCallbacks as $callback) {
            $callback($this, $result);
        }

        // if the command was slow, log a warning
        // we determine the threshold for slow based on the command being run
        $slow = 0;
        foreach ($this->getSlowCommandLogging() as $key => $value) {
            if (!is_array($value) && ctype_digit((string) $value)) {
                $slow = max($slow, $value);
            } elseif (in_array($command, (array) $value)) {
                $slow = max($slow, (int) $key);
            }
        }
        if ($slow && $lapse >= $slow) {
            $message = "P4 (" . spl_object_hash($this) . ") slow command (" . round($lapse, 3) . "s): "
                     . $command . " " . implode(" ", $params);
            Logger::log(
                Logger::WARN,
                substr($message, 0, static::LOG_MAX_STRING_LENGTH)
            );
        }

        // log errors - log them and throw an exception.
        if ($result->hasErrors() && !$ignoreErrors) {

            // if we have no charset, and the command failed because we are
            // talking to a unicode server, automatically use the default
            // charset and run the command again.
            $errors = $result->getErrors();
            $needle = 'Unicode server permits only unicode enabled clients.';
            if (!$this->getCharset() && stripos($errors[0], $needle) !== false) {
                $this->setCharset(static::DEFAULT_CHARSET);

                // run the command again now that we have a charset.
                return call_user_func_array(
                    array($this, 'run'),
                    func_get_args()
                );
            }

            // if connect failed due to an untrusted server, trust it and retry
            $needle = "To allow connection use the 'p4 trust' command";
            if (stripos($errors[0], $needle) !== false && !$this->hasTrusted) {
                // add a property to avoid re-recursing on this test
                $this->hasTrusted = true;

                // work around @job066722 by disconnecting to clear the argument buffer
                // -fixed in P4API 2013.2
                if (!$this->isApiMinVersion('2013.2')) {
                    $this->disconnect();
                }

                // trust the connection as this is the first time we have seen it
                $this->run('trust', '-y');

                // run the command again now that we have trusted it
                return call_user_func_array(
                    array($this, 'run'),
                    func_get_args()
                );
            }

            $message = "P4 (" . spl_object_hash($this) . ") command failed: "
                     . implode("\n", $result->getErrors());
            Logger::log(
                Logger::DEBUG,
                substr($message, 0, static::LOG_MAX_STRING_LENGTH)
            );

            $this->handleError($result);
        }

        return $result;
    }

    /**
     * Runs the specified command using the passed output handler.
     * Ensures the output handler is turned back off at completion.
     *
     * If the handler has a 'reset' method it will be called. This is intended
     * to give the handler an opportunity to prepare itself for a fresh run.
     *
     * @param   P4_OutputHandlerAbstract    $handler        the output handler to use
     * @param   string                      $command        the command to run.
     * @param   array|string                $params         optional - one or more arguments.
     * @param   array|string                $input          optional - input for the command - should be provided
     *                                                      in array form when writing perforce spec records.
     * @param   boolean                     $tagged         optional - true/false to enable/disable tagged output.
     *                                                      defaults to true.
     * @param   boolean                     $ignoreErrors   optional - true/false to ignore errors - default false
     * @throws  \P4\Exception                               if the implementation doesn't define a runHandler
     * @return  \P4\Connection\CommandResult                the perforce result object.
     */
    public function runHandler(
        $handler,
        $command,
        $params = array(),
        $input = null,
        $tagged = true,
        $ignoreErrors = false
    ) {
        throw new P4\Exception('Implementing class must define a runHandler implementation!');
    }

    /**
     * Check if the user we are connected as has super user privileges.
     *
     * @return  bool    true if the user has super, false otherwise.
     * @throws  Exception\CommandException  if unanticipated error from protects -m.
     */
    public function isSuperUser()
    {
        return $this->getMaxAccess() === 'super';
    }

    /**
     * Check if the user we are connected as has admin user privileges.
     * By default, 'super' connection will return false on this check.
     * This behaviour can be modified by optional $allowSuper flag
     * to also include 'super' users.
     *
     * @param   bool    $allowSuper     optional - if true, then this check will
     *                                  return true also if the connection is super
     * @return  bool    true if the user is admin (or super if $allowSuper is true),
     *                  false otherwise.
     * @throws  Exception\CommandException  if unanticipated error from protects -m.
     */
    public function isAdminUser($allowSuper = false)
    {
        $maxAccess = $this->getMaxAccess();
        return ($allowSuper && $maxAccess === 'super') || $maxAccess === 'admin';
    }

    /**
     * Check if the server we are connected to is case sensitive.
     *
     * @return  bool            true if the server is case sensitive, false otherwise.
     * @throws  P4\Exception    if unable to determine server case handling.
     */
    public function isCaseSensitive()
    {
        $info = $this->getInfo();

        // throw exception if case handling unknown.
        if (!isset($info['caseHandling'])) {
            throw new P4\Exception("Cannot determine server case-handling.");
        }

        return $info['caseHandling'] === 'sensitive';
    }

    /**
     * Tests if a candidate matches any of the provided values accounting for case sensitivity.
     * If the server is case insensitive so is this; otherwise case sensitive.
     *
     * @param   string          $candidate  the value to test for a match
     * @param   string|array    $values     one or more values to compare to
     * @return  bool            true if the candidate matches any of the provided values
     */
    public function stringMatches($candidate, $values)
    {
        $values = (array) $values;

        // if the server is case insensitive; lowercase everything.
        if (!$this->isCaseSensitive()) {
            $candidate = strtolower($candidate);
            $values    = array_map('strtolower', $values);
        }

        return in_array($candidate, $values);
    }

    /**
     * Check if the server we are connected to is using external authentication
     *
     * @return  bool    true if the server is using external authentication, false otherwise.
     */
    public function hasExternalAuth()
    {
        $info = $this->getInfo();

        if (isset($info['externalAuth']) && ($info['externalAuth'] === 'enabled')) {
            return true;
        }
        return false;
    }

    /**
     * Check if the server we are connected to has a auth-set trigger configured.
     *
     * @return  bool    true, if the server has configured an auth-set trigger,
     *                  false, otherwise.
     */
    public function hasAuthSetTrigger()
    {
        // exit early if the server is not using external authentication
        if (!$this->hasExternalAuth()) {
            return false;
        }

        try {
            // try to set the password, the server without an auth-set trigger
            // throws a Exception\CommandException with the error message:
            //   "Command unavailable: external authentication 'auth-set' trigger not found."
            $this->run('passwd');
        } catch (Exception\CommandException $e) {
            if (stristr($e->getMessage(), "'auth-set' trigger not found.")) {
                return false;
            }
        }

        return true;
    }

    /**
     * Connect to a Perforce Server.
     * Hands off to doConnect() for the actual mechanics of connecting.
     *
     * @return  ConnectionInterface         provides fluent interface.
     * @throws  Exception\ConnectException  if the connection fails.
     */
    public function connect()
    {
        if (!$this->isConnected()) {

            // refuse to connect if no port or no user set.
            if (!strlen($this->getPort()) || !strlen($this->getUser())) {
                throw new Exception\ConnectException(
                    "Cannot connect. You must specify both a port and a user."
                );
            }

            $this->doConnect();
        }

        return $this;
    }

    /**
     * Run disconnect callbacks.
     *
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function disconnect()
    {
        return $this->runDisconnectCallbacks();
    }

    /**
     * Add a function to run when connection is closed.
     * Callbacks are removed after they are executed
     * unless persistent is set to true.
     *
     * @param   callable    $callback   the function to execute on disconnect
     *                                  (will be passed connection).
     * @param   bool        $persistent optional - defaults to false - set to true to
     *                                  run callback on repeated disconnects.
     * @return  ConnectionInterface     provides fluent interface.
     * @throws  \InvalidArgumentException  if callback supplied is not callable
     */
    public function addDisconnectCallback($callback, $persistent = false)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException(
                "Cannot add disconnect callback. Not callable"
            );
        }

        $this->disconnectCallbacks[] = array(
            'callback'      => $callback,
            'persistent'    => $persistent
        );

        return $this;
    }

    /**
     * Clear disconnect callbacks.
     *
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function clearDisconnectCallbacks()
    {
        $this->disconnectCallbacks = array();
        return $this;
    }

    /**
     * Run disconnect callbacks.
     *
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function runDisconnectCallbacks()
    {
        foreach ($this->disconnectCallbacks as $key => $callback) {
            call_user_func($callback['callback'], $this);
            if (!$callback['persistent']) {
                unset($this->disconnectCallbacks[$key]);
            }
        }

        return $this;
    }

    /**
     * Add a function to call immediately before commands are run.
     *
     * @param   callable    $callback   the function to execute just prior to running commands.
     *                                  args are: $connection, $command, $params, $input, $tagged
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function addPreRunCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException(
                "Cannot add pre-run callback. Not callable"
            );
        }

        $this->preRunCallbacks[] = $callback;

        return $this;
    }

    /**
     * Add a function to call immediately after commands are run.
     *
     * @param   callable    $callback   the function to execute just after running commands.
     *                                  args are: $connection, $result
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function addPostRunCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException(
                "Cannot add post-run callback. Not callable"
            );
        }

        $this->postRunCallbacks[] = $callback;

        return $this;
    }

    /**
     * Get the server's security level.
     *
     * @return  int     the security level of the server (e.g. 0, 1, 2, 3)
     */
    public function getSecurityLevel()
    {
        if (!Counter::exists('security', $this)) {
            return 0;
        }

        return (int) Counter::fetch('security', $this)->get();
    }

    /**
     * This function will throw the appropriate exception for the error(s) found
     * in the passed result object.
     *
     * @param   P4\Connection\CommandResult     $result     The result containing errors
     * @throws  Exception\ConflictException     if there are file conflicts to resolve.
     * @throws  Exception\CommandException      if there are any other command errors.
     */
    public function handleError($result)
    {
        $message = "Command failed: " . implode("\n", $result->getErrors());

        // create appropriate exception based on error condition
        if (preg_match("/must (sync\/ )?(be )?resolved?/", $message) ||
            preg_match("/Merges still pending/", $message)) {
            $e = new Exception\ConflictException($message);
        } else {
            $e = new Exception\CommandException($message);
        }

        $e->setConnection($this);
        $e->setResult($result);
        throw $e;
    }

    /**
     * Get the maximum allowable length of all command arguments.
     *
     * @return  int     the max length of combined arguments - zero for no limit
     */
    public function getArgMax()
    {
        return 0;
    }

    /**
     * Return arguments split into chunks (batches) where each batch contains as many
     * arguments as possible to not exceed ARG_MAX or option limit.
     *
     * ARG_MAX is a character limit that affects command line programs (p4).
     * Option limit is a server-side limit on the number of flags (e.g. '-n').
     *
     * @param   array       $arguments  list of arguments to split into chunks.
     * @param   array|null  $prefixArgs arguments to begin all batches with.
     * @param   array|null  $suffixArgs arguments to end all batches with.
     * @param   int         $groupSize  keep arguments together in groups of this size
     *                                  for example, when clearing attributes you want to
     *                                  keep pairs of -n and attr-name together.
     * @return  array                   list of batches of arguments where every batch contains as many
     *                                  arguments as possible and arg-max is not exceeded.
     * @throws  P4\Exception            if a argument (or set of arguments) exceed arg-max.
     */
    public function batchArgs(array $arguments, array $prefixArgs = null, array $suffixArgs = null, $groupSize = 1)
    {
        // determine size of leading and trailing arguments.
        $initialLength  = 0;
        $initialOptions = 0;
        $argMax         = $this->getArgMax();
        $optionLimit    = $this->getOptionLimit();
        $prefixArgs     = (array) $prefixArgs;
        $suffixArgs     = (array) $suffixArgs;
        foreach (array_merge($prefixArgs, $suffixArgs) as $argument) {
            // if we have an arg-max limit, determine length of common args.
            // compute length by adding length of escaped argument + 1 space
            if ($argMax) {
                $initialLength += strlen(static::escapeArg($argument)) + 1;
            }

            // if the first character is a dash ('-'), it's an option
            if (substr($argument, 0, 1) === '-') {
                $initialOptions++;
            }
        }

        $batches = array();
        while (!empty($arguments)) {
            // determine how many arguments we can move into this batch.
            $count   = 0;
            $length  = $initialLength;
            $options = $initialOptions;
            foreach ($arguments as $argument) {

                // if we have an arg-max limit, enforce it.
                // compute length by adding length of escaped argument + 1 space
                if ($argMax) {
                    $length += strlen(static::escapeArg($argument)) + 1;

                    // if we exceed arg-max, break
                    if ($length >= $argMax) {
                        break;
                    }
                }

                // if we exceed the option-limit, break
                if ($options > $optionLimit) {
                    break;
                }

                // if the first character is a dash ('-'), it's an option
                if (substr($argument, 0, 1) === '-') {
                    $options++;
                }

                $count++;
            }

            // adjust count down to largest divisible group size
            // and move that number of arguments into this batch.
            $count    -= $count % $groupSize;
            $batches[] = array_merge($prefixArgs, array_splice($arguments, 0, $count), $suffixArgs);

            // handle the case of a given argument group not fitting in a batch
            // this informs the caller of indivisble args and avoids infinite loops
            if (!empty($arguments) && $count < $groupSize) {
                throw new P4\Exception(
                    "Cannot batch arguments. Arguments exceed arg-max and/or option-limit."
                );
            }
        }

        return $batches;
    }

    /**
     * Escape a string for use as a command argument.
     * Escaping is a no-op for the abstract implementation,
     * but is needed by batchArgs.
     *
     * @param   string  $arg    the string to escape
     * @return  string          the escaped string
     */
    public static function escapeArg($arg)
    {
        return $arg;
    }

    /**
     * Get the server's timezone.
     *
     * @return DateTimeZone the server's timezone
     * @throws \Exception   if the server's timezone isn't parsable
     */
    public function getTimeZone()
    {
        // the 'serverDate' object lists the offset in the format -0800 and the short timezone name as the
        // last two components. strip these off and convert to a long name if possible.
        $info = $this->getInfo();
        if (isset($info['serverDate'])
            && preg_match('#^[0-9/]+ [0-9:]+ (?P<offset>[0-9-+]+) (?P<name>.+)$#', $info['serverDate'], $timezone)
        ) {
            // converting the string to a DateTimeZone is tricky; outsource the heavy lifting
            return Time::toDateTimeZone($timezone['name'], $timezone['offset']);
        }

        // if we couldn't preg match out the details; throw
        throw new \Exception('Unable to get timezone, p4 info does not contain a parsable serverDate');
    }

    /**
     * Attach a service to this connection.
     * Allows the connection to act as a service locator (e.g. for logging, caching, etc.)
     *
     * @param   string                  $name       the name of the service to set (e.g. 'cache')
     * @param   object|callable|null    $service    the service instance or factory function (or null to clear)
     *                                              factory is called with the connection and service name
     * @return  ConnectionInterface to maintain a fluent interface
     * @throws  \InvalidArgumentException   if the name or service is invalid
     */
    public function setService($name, $service)
    {
        if (!is_string($name) || !strlen($name)) {
            throw new \InvalidArgumentException("Cannot set service. Name must be a non-empty string.");
        }

        // if service is null, remove it
        if ($service === null) {
            unset($this->services[$name]);
            return $this;
        }

        if (!is_object($service) && !is_callable($service)) {
            throw new \InvalidArgumentException("Cannot set service. Service must be an object or callable.");
        }

        $this->services[$name] = array(
            'instance' => is_callable($service) ? null     : $service,
            'factory'  => is_callable($service) ? $service : null
        );

        return $this;
    }

    /**
     * Retrieve a service from this connection.
     *
     * @param   string  $name       the name of the service to get (e.g. 'cache')
     * @return  object  the service instance (factory functions are resolved automatically)
     * @throws  ServiceNotFoundException    if the requested service does not exist
     */
    public function getService($name)
    {
        if (!isset($this->services[$name])) {
            throw new ServiceNotFoundException("Cannot get service. No such service ('$name').");
        }

        // construct the service instance if necessary
        $service = $this->services[$name];
        if (!isset($service['instance'])) {
            $service['instance']   = $service['factory']($this, $name);
            $this->services[$name] = $service;
        }

        return $service['instance'];
    }

    /**
     * Set the threshold(s) for logging slow commands.
     * Pass false or an empty array to disable logging.
     *
     * You may specify a default limit (in seconds) as well as limits that
     * apply to only specific commands. The longest applicable limit is used
     * for a given command if more than one candidate occurs.
     *
     * The format is:
     * $limits => array(
     *    3,                                // numeric value is a default (any command) limit
     *    30 => array('print', 'submit')    // seconds as key with command(s) as value for command specific limit
     *    60 => 'unshelve'
     * );
     *
     * In the above example, the command fstat would have a limit of 3, print 30 and unshelve 60.
     *
     * @param   array|bool $thresholds  the limit(s) to trigger slow command logging or false
     * @return  ConnectionInterface     to maintain a fluent interface
     */
    public function setSlowCommandLogging($thresholds)
    {
        $this->slowCommandLogging = (array) $thresholds;
        return $this;
    }

    /**
     * Return the currently specified slow command thresholds.
     *
     * @return  array   the slow command thresholds, see setSlowCommandLimits for format details
     */
    public function getSlowCommandLogging()
    {
        return (array) $this->slowCommandLogging;
    }

    /**
     * Get maximum access level for this connection.
     *
     * @param   string|null     $host   optional - if set, max access level will be determined
     *                                  for the given host
     * @return  string|false    maximum access level or false
     */
    public function getMaxAccess($host = null)
    {
        // get max access level from Perforce
        $flags = array('-m');
        if ($host) {
            $flags[] = '-h';
            $flags[] = $host;
        }

        try {
            $result = $this->run("protects", $flags);
        } catch (Exception\CommandException $e) {
            // if protections table is empty, everyone is super
            $errors = $e->getResult()->getErrors();
            if (stristr($errors[0], "empty")) {
                return 'super';
            } elseif (stristr($errors[0], "password must be set")) {
                return false;
            }

            throw $e;
        }

        return $result->getData(0, "permMax");
    }

    /**
     * Actually issues a command. Called by run() to perform the dirty work.
     *
     * @param   string          $command    the command to run.
     * @param   array           $params     optional - arguments.
     * @param   array|string    $input      optional - input for the command - should be provided
     *                                      in array form when writing perforce spec records.
     * @param   boolean         $tagged     optional - true/false to enable/disable tagged output.
     *                                      defaults to true.
     * @return  P4\Connection\CommandResult     the perforce result object.
     */
    abstract protected function doRun($command, $params = array(), $input = null, $tagged = true);

    /**
     * Prepare input for passing to Perforce.
     *
     * @param   string|array    $input      the input to prepare for p4.
     * @param   string          $command    the command to prepare input for.
     * @return  string|array    the prepared input.
     */
    abstract protected function prepareInput($input, $command);

    /**
     * Does real work of establishing connection. Called by connect().
     *
     * @throws  Exception\ConnectException  if the connection fails.
     */
    abstract protected function doConnect();
}
