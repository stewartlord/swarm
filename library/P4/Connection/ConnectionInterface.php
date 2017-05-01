<?php
/**
 * Provides a mechanism for running Perforce commands.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Connection;

interface ConnectionInterface
{
    /**
     * Create a ConnectionInterface instance.
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
    );

    /**
     * Connect to a Perforce server.
     *
     * @return  ConnectionInterface     provides fluent interface.
     * @throws  ConnectException        if the connection fails.
     */
    public function connect();

    /**
     * Disconnect from a Perforce server.
     *
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function disconnect();

    /**
     * Check connected state.
     *
     * @return  bool    true if connected, false otherwise.
     */
    public function isConnected();

    /**
     * Executes the specified command and returns a perforce result object.
     * No need to call connect() first. Run will connect automatically.
     *
     * @param   string          $command        the command to run.
     * @param   array|string    $params         optional - one or more arguments.
     * @param   array|string    $input          optional - input for the command - should be provided
     *                                          in array form when writing perforce spec records.
     * @param   boolean         $tagged         optional - true/false to enable/disable tagged output.
     *                                          defaults to true.
     * @param   boolean         $ignoreErrors   optional - true/false to ignore errors - default false
     * @return  \P4\Connection\CommandResult    the perforce result object.
     * @throws  CommandException                if the command fails.
     */
    public function run(
        $command,
        $params = array(),
        $input = null,
        $tagged = true,
        $ignoreErrors = false
    );

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
     * @return  \P4\Connection\CommandResult                the perforce result object.
     */
    public function runHandler(
        $handler,
        $command,
        $params = array(),
        $input = null,
        $tagged = true,
        $ignoreErrors = false
    );

    /**
     * Return the p4 port.
     *
     * @return  string  the port.
     */
    public function getPort();

    /**
     * Set the p4 port.
     *
     * @param   string  $port           the port to connect to.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setPort($port);

    /**
     * Return the name of the p4 user.
     *
     * @return  string  the user.
     */
    public function getUser();

    /**
     * Set the name of the p4 user.
     *
     * @param   string  $user           the user to connect as.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setUser($user);

    /**
     * Return the p4 user's client.
     *
     * @return  string  the client.
     */
    public function getClient();

    /**
     * Set the p4 user's client.
     *
     * @param   string  $client         the name of the client workspace to use.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setClient($client);

    /**
     * Retrieves the password set for this perforce connection.
     *
     * @return  string  password used to authenticate against perforce server.
     */
    public function getPassword();

    /**
     * Sets the password to use for this perforce connection.
     *
     * @param   string  $password       the password to use as authentication.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setPassword($password);

    /**
     * Retrieves the ticket set for this perforce connection.
     *
     * @return  string  ticket as generated by perforce server.
     */
    public function getTicket();

    /**
     * Check if last login call was to generate a ticket valid for all hosts
     *
     * @return  bool    last login call was for an unlocked ticket
     */
    public function isTicketUnlocked();

    /**
     * Sets the ticket to use for this perforce connection.
     *
     * @param   string  $ticket         the ticket to use as authentication.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setTicket($ticket);

    /**
     * Retrieves the character set used by this connection.
     *
     * @return  string  charset used for this connection.
     */
    public function getCharset();

    /**
     * Sets the character set to use for this perforce connection.
     *
     * You should only set a character set when connecting to a
     * 'unicode enabled' server.
     *
     * @param   string  $charset        the charset to use (e.g. 'utf8').
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setCharset($charset);

    /**
     * Retrieves the client host set for this connection.
     *
     * @return  string  charset used for this connection.
     */
    public function getHost();

    /**
     * Sets the client host name overriding the environment.
     *
     * @param   string|null $host       the host name to use.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setHost($host);

    /**
     * Get the current client's root directory.
     *
     * @return  string  the full path to the current client's root.
     */
    public function getClientRoot();

    /**
     * Return an array of connection information.
     *
     * @return  array   the connection information ('p4 info').
     */
    public function getInfo();

    /**
     * Get the identity of this Connection implementation.
     *
     * @return  array   an array of client Connection information containing the name,
     *                  platform, version, build and date of the client library.
     */
    public function getConnectionIdentity();

    /**
     * Check if the P4API version for this connection is same or higher than
     * the version passed in the parameter.
     *
     * @param   string  $version    version to compare in format <year>.<release>
     * @return  bool    true if P4API version is same or higher than $version
     */
    public function isApiMinVersion($version);

    /**
     * Check if the user is authenticated
     *
     * @return bool     true if user is authenticated, false otherwise
     */
    public function isAuthenticated();

    /**
     * Authenticate the user with 'p4 login'.
     *
     * @param   bool|null       $all    get a ticket valid for all hosts (false by default)
     * @return  string|null     the ticket issued by the server or null if
     *                          no ticket issued (user has no password).
     * @throws  LoginException  if login fails.
     */
    public function login($all = false);

    /**
     * Check if the user we are connected as has super user privileges.
     *
     * @return  bool    true if the user has super, false otherwise.
     */
    public function isSuperUser();

    /**
     * Check if the user we are connected as has admin user privileges.
     * Note: super users will return false; this is a targeted check.
     *
     * @return  bool    true if the user has admin, false otherwise.
     */
    public function isAdminUser();

    /**
     * Check if the server we are connected to is case sensitive.
     *
     * @return  bool    true if the server is case sensitive, false otherwise.
     */
    public function isCaseSensitive();

    /**
     * Tests if a candidate matches any of the provided values accounting for case sensitivity.
     * If the server is case insensitive so is this; otherwise case sensitive.
     *
     * @param   string          $candidate  the value to test for a match
     * @param   string|array    $values     one or more values to compare to
     * @return  bool            true if the candidate matches any of the provided values
     */
    public function stringMatches($candidate, $values);

    /**
     * Check if the server we are connected to is using external authentication
     *
     * @return  bool    true if the server is using external authentication, false otherwise.
     */
    public function hasExternalAuth();

    /**
     * Check if the server we are connected to has a auth-set trigger configured.
     *
     * @return  bool    true, if the server has configured an auth-set trigger,
     *                  false, otherwise.
     */
    public function hasAuthSetTrigger();

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
     */
    public function addDisconnectCallback($callback, $persistent = false);

    /**
     * Add a function to call immediately before commands are run.
     *
     * @param   callable    $callback   the function to execute just prior to running commands.
     *                                  args are: $connection, $command, $params, $input, $tagged
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function addPreRunCallback($callback);

    /**
     * Add a function to call immediately after commands are run.
     *
     * @param   callable    $callback   the function to execute just after running commands.
     *                                  args are: $connection, $result
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function addPostRunCallback($callback);

    /**
     * Get the server's security level.
     *
     * @return  int     the security level of the server (e.g. 0, 1, 2, 3)
     */
    public function getSecurityLevel();

    /**
     * Get the maximum allowable length of all command arguments.
     *
     * @return  int     the max length of combined arguments - zero for no limit
     */
    public function getArgMax();

    /**
     * Return option limit (server-side limit on the number of flags)
     * based on the server version.
     *
     * @return  int     option limit
     */
    public function getOptionLimit();

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
     * @throws  \P4\Exception           if a argument (or set of arguments) exceed arg-max.
     */
    public function batchArgs(array $arguments, array $prefixArgs = null, array $suffixArgs = null, $groupSize = 1);

    /**
     * Set the name of the application that is using this connection.
     *
     * The application name will be reported to the server and might
     * be necessary to satisfy certain licensing restrictions.
     *
     * @param   string|null     $name       the app name to report to the server.
     * @return  ConnectionInterface         provides fluent interface.
     */
    public function setAppName($name);

    /**
     * Get the application name being reported to the server.
     *
     * @return  string|null     the app name reported to the server.
     */
    public function getAppName();

    /**
     * Set the name of the program that is using this connection.
     *
     * The program name will be reported to the server log.
     *
     * @param   string|null     $name       the program name to report to the server.
     * @return  ConnectionInterface         provides fluent interface.
     */
    public function setProgName($name);

    /**
     * Get the program name to report in the server logs.
     *
     * @return  string|null     the program name reported to the server.
     */
    public function getProgName();

    /**
     * Set the version of the program that is using this connection.
     *
     * The program version will be reported to the server log.
     *
     * @param   string|null     $version    the program version to report to the server.
     * @return  ConnectionInterface         provides fluent interface.
     */
    public function setProgVersion($version);

    /**
     * Get the program name being reported to the server.
     *
     * @return  string|null     the program name reported to the server.
     */
    public function getProgVersion();

    /**
     * Get the server's timezone.
     *
     * @return  DateTimeZone    the server's timezone
     */
    public function getTimeZone();

    /**
     * Attach a service to this connection.
     * Allows the connection to act as a service locator (e.g. for logging, caching, etc.)
     *
     * @param   string          $name       the name of the service to set (e.g. 'cache')
     * @param   object|callable $service    the service instance or factory function
     *                                      (factory is called with the connection and service name)
     * @return  ConnectionInterface to maintain a fluent interface
     * @throws  \InvalidArgumentException   if the name or service is invalid
     */
    public function setService($name, $service);

    /**
     * Retrieve a service from this connection.
     *
     * @param   string  $name       the name of the service to get (e.g. 'cache')
     * @return  object  the service instance (factory functions are resolved automatically)
     * @throws  ServiceNotFoundException    if the requested service does not exist
     */
    public function getService($name);

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
    public function setSlowCommandLogging($thresholds);

    /**
     * Return the currently specified slow command thresholds.
     *
     * @return  array   the slow command thresholds, see setSlowCommandLimits for format details
     */
    public function getSlowCommandLogging();

    /**
     * Get maximum access level for this connection.
     *
     * @param   string|null     $host   optional - if set, max access level will be determined for the given host
     * @return  string|false    maximum access level or false
     */
    public function getMaxAccess($host = null);
}
