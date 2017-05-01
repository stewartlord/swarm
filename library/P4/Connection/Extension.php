<?php
/**
 * P4PHP Perforce connection implementation.
 *
 * This client implementation provides access to the P4PHP extension in a way
 * that conforms to P4\Connection\ConnectionInterface. This allows the P4PHP extension
 * and the Perforce Command-Line Client wrapper to be used interchangeably.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Connection;

use P4;
use P4\Connection\CommandResult;

class Extension extends AbstractConnection
{
    protected $instance;

    /**
     * Constructs a P4 connection object.
     *
     * @param   string  $port        optional - the port to connect to.
     * @param   string  $user        optional - the user to connect as.
     * @param   string  $client      optional - the client spec to use.
     * @param   string  $password    optional - the password to use.
     * @param   string  $ticket      optional - a ticket to use.
     * @throws  P4\Exception         if P4PHP is not loaded
     */
    public function __construct(
        $port = null,
        $user = null,
        $client = null,
        $password = null,
        $ticket = null
    ) {
        // ensure that p4-php is installed.
        if (!extension_loaded('perforce')) {
            throw new P4\Exception(
                'Cannot create P4 API extension instance. Perforce extension not loaded.'
            );
        }

        // create an instance of p4-php.
        $this->instance = new P4;

        // disable automatic sequence expansion (call expandSequences on result object if desired)
        $this->instance->expand_sequences = false;

        // prevent command exceptions from being thrown by P4.
        // we throw our own so that we can attach the result.
        $this->instance->exception_level = 0;

        parent::__construct($port, $user, $client, $password, $ticket);
    }

    /**
     * Disconnect from the Perforce Server.
     *
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function disconnect()
    {
        // call parent to run disconnect callbacks.
        parent::disconnect();

        if ($this->isConnected()) {
            $this->instance->disconnect();
        }

        return $this;
    }

    /**
     * Check connected state.
     *
     * @return  bool    true if connected, false otherwise.
     */
    public function isConnected()
    {
        return $this->instance->connected();
    }

    /**
     * Extends parent to set our instance's password to the returned
     * ticket value if login succeeds.
     *
     * @param   bool|null       $all    get a ticket valid for all hosts (false by default)
     * @return  string|null     the ticket issued by the server or null if
     *                          no ticket issued (ie. user has no password).
     * @throws  Exception\LoginException    if login fails.
     */
    public function login($all = false)
    {
        $ticket = parent::login($all);

        if ($ticket) {
            $this->instance->password = $ticket;
        }

        return $ticket;
    }

    /**
     * Extend set port to update p4-php.
     *
     * @param   string      $port       the port to connect to.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setPort($port)
    {
        parent::setPort($port);
        $this->instance->port = $this->getPort();

        return $this;
    }

    /**
     * Extend set user to update p4-php.
     *
     * @param   string      $user       the user to connect as.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setUser($user)
    {
        parent::setUser($user);
        $this->instance->user = $this->getUser();

        return $this;
    }

    /**
     * Extend set client to update p4-php.
     *
     * @param   string      $client     the name of the client workspace to use.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setClient($client)
    {
        parent::setClient($client);

        // if no client is specified, normally the host name is used.
        // this can collide with an existing depot or client name, so
        // we use a temp id to avoid errors.
        $this->instance->client = $this->getClient() ?: P4\Spec\Client::makeTempId();

        return $this;
    }

    /**
     * Extend set password to update p4-php.
     *
     * @param   string  $password       the password to use as authentication.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setPassword($password)
    {
        parent::setPassword($password);
        $this->instance->password = $this->getPassword();

        return $this;
    }

    /**
     * Extend set ticket to update p4-php.
     * Note: the ticket is stored in the password field in p4-php.
     *
     * @param   string  $ticket         the ticket to use as authentication.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setTicket($ticket)
    {
        parent::setTicket($ticket);
        if ($ticket) {
            $this->instance->password = $this->getTicket();
        }

        return $this;
    }

    /**
     * Extended to set charset in p4-php.
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
        $this->instance->charset = $charset;

        return parent::setCharset($charset);
    }

    /**
     * Extended to set host name in p4-php.
     * Sets the client host name overriding the environment.
     *
     * @param   string|null $host       the host name to use.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setHost($host)
    {
        $this->instance->host = $host;

        return parent::setHost($host);
    }

    /**
     * Extended to set app name in p4-php.
     * Set the name of the application that is using this connection.
     *
     * @param   string|null     $name   the app name to report to the server.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setAppName($name)
    {
        $this->instance->set_protocol('app', (string) $name);

        return parent::setAppName($name);
    }

    /**
     * Extended to set program name in p4-php.
     * Set the name of the program that is using this connection.
     *
     * @param   string|null     $name   the program name to report to the server.
     * @return  ConnectionInterface     provides fluent interface.
     */
    public function setProgName($name)
    {
        $this->instance->prog = (string) $name;

        return parent::setProgName($name);
    }

    /**
     * Extended to set program version in p4-php.
     * Set the version of the program that is using this connection.
     *
     * @param   string|null     $version the program version to report to the server.
     * @return  ConnectionInterface      provides fluent interface.
     */
    public function setProgVersion($version)
    {
        $this->instance->version = (string) $version;

        return parent::setProgVersion($version);
    }

    /**
     * Get the identity of this Connection implementation.
     *
     * Resulting array will contain:
     *  - name
     *  - platform
     *  - version    (p4-php version)
     *  - build      (p4-php build)
     *  - apiversion (p4-api version)
     *  - apibuild   (p4-api build)
     *  - date
     *  - original   (all text following 'Rev. ' from original response)
     *
     * @return  array           an array of client Connection information
     * @throws  P4\Exception    if the returned version string is invalid
     */
    public function getConnectionIdentity()
    {
        // obtain the extension's identification
        $output = $this->instance->identify();

        // extract the version string and split into components
        preg_match('/\nRev. (.*)\.$/', $output, $matches);
        $parts = isset($matches[1]) ? preg_split('/\/| \(| API\) \(|\)/', $matches[1]) : null;
        if (count($parts) < 8) {
            $message = 'p4php returned an invalid version string';
            throw new P4\Exception($message);
        }

        // build identity array of version components, including original string
        $identity = array(
            'name'       => $parts[0],
            'platform'   => $parts[1],
            'version'    => $parts[2],
            'build'      => $parts[3],
            'apiversion' => $parts[4],
            'apibuild'   => $parts[5],
            'date'       => $parts[6] . '/' . $parts[7] . '/' . $parts[8],
            'original'   => $matches[1]
        );

        return $identity;
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
     * @return  P4\Connection\CommandResult the perforce result object.
     * @throws  \Exception    if the command produced an exception
     */
    public function runHandler(
        $handler,
        $command,
        $params = array(),
        $input = null,
        $tagged = true,
        $ignoreErrors = false
    ) {
        // if the handler has a 'reset' method call it to ensure its ready
        if (method_exists($handler, 'reset')) {
            $handler->reset();
        }

        // set the handler and run our command.
        $this->instance->handler = $handler;
        try {
            $result = $this->run($command, $params, $input, $tagged, $ignoreErrors);
        } catch (\Exception $e) {
            // just catch the exception for now; we'll rethrow later
        }

        // if the handler 'cancelled' the command, there is a chance the connection has been severed
        // we run a test command to check the connection and disconnect if that fails
        // there are a number of oddities to be aware of:
        // - the handler might not have a wasCancelled() method, in which case we assume it cancelled
        // - running any command on a severed connection produces no errors/exceptions/data (bug in P4PHP)
        // - we run 'p4 help' because it locks no tables, produces no errors and has modest output
        // - by explicitly disconnecting any future commands will automatically reconnect
        if ((!method_exists($handler, 'wasCancelled') || $handler->wasCancelled())
            && !$this->doRun('help')->hasData()
        ) {
            $this->disconnect();
        }

        $this->instance->handler = null;

        // if an exception occurred rethrow it now that we've cleared the handler
        if (isset($e)) {
            throw $e;
        }

        return $result;
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
     * @return  CommandResult   the perforce result object.
     */
    protected function doRun($command, $params = array(), $input = null, $tagged = true)
    {
        // push command to front of parameters array
        array_unshift($params, $command);

        // set input for the command.
        if ($input !== null) {
            $this->instance->input = $input;
        }

        // toggle tagged output.
        $this->instance->tagged = (bool) $tagged;

        // establish connection to perforce server.
        if (!$this->isConnected()) {
            $this->connect();
        }

        // run command.
        $data = call_user_func_array(array($this->instance, "run"), $params);

        // collect data in result object and ensure output is in array form.
        $result = new CommandResult($command, $data, $tagged);
        $result->setErrors($this->instance->errors);
        $result->setWarnings($this->instance->warnings);

        return $result;
    }

    /**
     * Prepare input for passing to the p4 extension.
     * Ensure input is either a string or an array of strings.
     *
     * @param   string|array    $input      the input to prepare for p4.
     * @param   string          $command    the command to prepare input for.
     * @return  string|array    the prepared input.
     */
    protected function prepareInput($input, $command)
    {
        // if input is not an array, cast to string and return.
        if (!is_array($input)) {
            return (string) $input;
        }

        // ensure each element of array is a string.
        $stringify = function (&$input) {
            $input = (string) $input;
        };
        array_walk_recursive($input, $stringify);

        return $input;
    }

    /**
     * Does real work of establishing connection. Called by connect().
     *
     * @throws  Exception\ConnectException    if the connection fails.
     */
    protected function doConnect()
    {
        // temporarily enable exceptions to catch connection failure.
        $this->instance->exception_level = 1;
        try {
            $this->instance->connect();
            $this->instance->exception_level = 0;
        } catch (\P4_Exception $e) {
            $this->instance->exception_level = 0;
            throw new Exception\ConnectException(
                "Connect failed: " . $e->getMessage()
            );
        }
    }
}
