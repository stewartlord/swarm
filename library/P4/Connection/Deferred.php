<?php
/**
 * A mechanism for providing an connection that will be loaded on-demand.
 * This is particularly useful if creating the connection will have notable
 * expense you wish to avoid, or if you expect the connection may change.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Connection;

use P4;

class Deferred implements ConnectionInterface
{
    protected $callback = null;

    /**
     * Create a new deferred connection from the given callback.
     * A callback must be provided.
     *
     * @param   callable    $callback       the callback function to get the real connection.
     * @param   string      $ignored1       unused argument required by interface.
     * @param   string      $ignored2       unused argument required by interface.
     * @param   string      $ignored3       unused argument required by interface.
     * @param   string      $ignored4       unused argument required by interface.
     * @throws  \InvalidArgumentException   if the given callback is not callable.
     */
    public function __construct(
        $callback = null,
        $ignored1 = null,
        $ignored2 = null,
        $ignored3 = null,
        $ignored4 = null
    ) {
        $this->setCallback($callback);
    }

    /**
     * Set the callback to use to get the real connection to use.
     *
     * @param   callable    $callback       the callback function to get the real connection.
     *                                      the callback will be called with no arguments
     *                                      and must return a connection instance.
     * @return  Deferred                    provides fluent interface.
     * @throws  \InvalidArgumentException   if the given callback is not callable.
     */
    public function setCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException(
                "Cannot set callback. Given callback is not callable."
            );
        }

        $this->callback = $callback;

        return $this;
    }

    /**
     * Resolves the callback to a real connection.
     *
     * @return  ConnectionInterface     the real connection to use.
     * @throws  P4\Exception            if callback fails to return a proper connection.
     */
    public function getConnection()
    {
        $adapter = call_user_func($this->callback);

        if (!$adapter instanceof ConnectionInterface) {
            throw new P4\Exception(
                "Cannot resolve deferred connection. Callback failed to return a proper connection."
            );
        }

        return $adapter;
    }

    // @codingStandardsIgnoreStart
    public function connect()               { return $this->getConnection()->connect(); }
    public function disconnect()            { return $this->getConnection()->disconnect(); }
    public function isConnected()           { return $this->getConnection()->isConnected(); }
    public function getPort()               { return $this->getConnection()->getPort(); }
    public function setPort($port)          { return $this->getConnection()->setPort($port); }
    public function getUser()               { return $this->getConnection()->getUser(); }
    public function setUser($user)          { return $this->getConnection()->setUser($user); }
    public function getClient()             { return $this->getConnection()->getClient(); }
    public function setClient($client)      { return $this->getConnection()->setClient($client); }
    public function getPassword()           { return $this->getConnection()->getPassword(); }
    public function setPassword($password)  { return $this->getConnection()->setPassword($password); }
    public function getTicket()             { return $this->getConnection()->getTicket(); }
    public function setTicket($ticket)      { return $this->getConnection()->setTicket($ticket); }
    public function getCharset()            { return $this->getConnection()->getCharset(); }
    public function setCharset($charset)    { return $this->getConnection()->setCharset($charset); }
    public function getHost()               { return $this->getConnection()->getHost(); }
    public function setHost($host)          { return $this->getConnection()->setHost($host); }
    public function getClientRoot()         { return $this->getConnection()->getClientRoot(); }
    public function getInfo()               { return $this->getConnection()->getInfo(); }
    public function getConnectionIdentity() { return $this->getConnection()->getConnectionIdentity(); }
    public function login($all = false)     { return $this->getConnection()->login($all); }
    public function isSuperUser()           { return $this->getConnection()->isSuperUser(); }
    public function isCaseSensitive()       { return $this->getConnection()->isCaseSensitive(); }
    public function hasExternalAuth()       { return $this->getConnection()->hasExternalAuth(); }
    public function hasAuthSetTrigger()     { return $this->getConnection()->hasAuthSetTrigger();}
    public function getSecurityLevel()      { return $this->getConnection()->getSecurityLevel(); }
    public function getArgMax()             { return $this->getConnection()->getArgMax(); }
    public function setAppName($name)       { return $this->getConnection()->setAppName($name); }
    public function getAppName()            { return $this->getConnection()->getAppName(); }
    public function setProgName($name)      { return $this->getConnection()->setProgName($name); }
    public function getProgName()           { return $this->getConnection()->getProgName(); }
    public function setProgVersion($version){ return $this->getConnection()->setProgVersion($version); }
    public function getProgVersion()        { return $this->getConnection()->getProgVersion(); }
    public function run($command, $params = array(), $input = null, $tagged = true)
                                            { return $this->getConnection()->run($command, $params, $input, $tagged); }
    public function batchArgs(array $arguments, array $prefixArgs = null, array $suffixArgs = null, $groupSize = 1)
                                            { return $this->getConnection()->batchArgs($arguments, $prefixArgs, $suffixArgs, $groupSize); }
    public function addDisconnectCallback($callback, $persistent = false)
                                            { return $this->getConnection()->addDisconnectCallback($callback, $persistent); }
    // @codingStandardsIgnoreEnd
}
