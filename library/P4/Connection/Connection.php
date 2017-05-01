<?php
/**
 * Perforce connection factory.
 *
 * A Factory used to create a Perforce Connection instance. This class is
 * responsible for deciding the specific implementation to use.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Connection;

use P4;

class Connection
{
    protected static $defaultConnection;
    protected static $appName;
    protected static $progName;
    protected static $progVersion;

    /**
     * Factory method that creates and returns a single instance of a Perforce Connection
     * implementation. The caller should not need to worry about the specific implemenation
     * used, only that it implements ConnectionInterface.
     *
     * @param   string  $port        optional - the port to connect to.
     * @param   string  $user        optional - the user to connect as.
     * @param   string  $client      optional - the client spec to use.
     * @param   string  $password    optional - the password to use.
     * @param   string  $ticket      optional - a ticket to use.
     * @param   string  $type        optional - a specific client implementation to use.
     *
     * @return  ConnectionInterface  a perforce client implementation.
     * @throws  P4\Exception         if an invalid API type is given.
     */
    public static function factory(
        $port = null,
        $user = null,
        $client = null,
        $password = null,
        $ticket = null,
        $type = null
    ) {
        // use the type parameter if it was provided.
        // throw an exception if it specifies an invalid type.
        if ($type) {
            if (!self::isValidType($type)) {
                throw new P4\Exception("Invalid Perforce Connection Type: " . $type);
            }
        } else {
            if (extension_loaded("perforce")) {
                $type = "P4\Connection\Extension";
            } else {
                throw new P4\Exception('P4-PHP extension is not loaded.');
            }
        }

        // create instance of desired type.
        $connection = new $type(
            $port,
            $user,
            $client,
            $password,
            $ticket
        );

        // if we have an app name, set it.
        if (static::$appName) {
            $connection->setAppName(static::$appName);
        }

        // if we have a program name, set it.
        if (static::$progName) {
            $connection->setProgName(static::$progName);
        }

        // if we have a program version, set it.
        if (static::$progVersion) {
            $connection->setProgVersion(static::$progVersion);
        }

        // if no default connection has been set, use this one.
        if (!self::hasDefaultConnection()) {
            self::setDefaultConnection($connection);
        }

        return $connection;
    }

    /**
     * Get the identity of the current default Connection implementation.
     *
     * @return  array   an array of client Connection information containing the name,
     *                  platform, version, build and date of the client library.
     */
    public static function getConnectionIdentity()
    {
        $p4 = self::factory();
        return $p4->getConnectionIdentity();
    }

    /**
     * Determine if the given Connection type is valid.
     *
     * @param   string  $type  the Connection implementation class to use.
     * @return  bool    true if the given Connection class exists and is valid.
     */
    public static function isValidType($type)
    {
        if (!class_exists($type)) {
            return false;
        }

        if (!in_array('P4\\Connection\\ConnectionInterface', class_implements($type))) {
            return false;
        }
        return true;
    }

    /**
     * Set a default connection for the environment.
     *
     * @param   ConnectionInterface      $connection     the default connection to use.
     * @throws  P4\Exception  if the given connection is not a valid Connection instance.
     */
    public static function setDefaultConnection(ConnectionInterface $connection)
    {
        self::$defaultConnection = $connection;
    }

    /**
     * Unset the default connection.
     */
    public static function clearDefaultConnection()
    {
        self::$defaultConnection = null;
    }

    /**
     * Get the default connection for the environment.
     *
     * @return  ConnectionInterface      the default connection.
     * @throws  P4\Exception  if no default connection has been set.
     */
    public static function getDefaultConnection()
    {
        if (!self::$defaultConnection instanceof ConnectionInterface) {
            throw new P4\Exception(
                "Failed to get connection. A default connection has not been set."
            );
        }

        return self::$defaultConnection;
    }

    /**
     * Check if a default connection has been set.
     *
     * @return  bool    true if a default connection is set.
     */
    public static function hasDefaultConnection()
    {
        try {
            self::getDefaultConnection();
            return true;
        } catch (P4\Exception $e) {
            return false;
        }
    }

    /**
     * Provide a application name to set on any new connections.
     *
     * @param   string|null     $name   app name to report to the server
     */
    public static function setAppName($name)
    {
        static::$appName = is_null($name) ? $name : (string) $name;
    }

    /**
     * Get the application name that will be set on any new connections.
     *
     * @return  string|null     app name to be set on new connections.
     */
    public static function getAppName()
    {
        return static::$appName;
    }

    /**
     * Provide a program name to set on any new connections.
     *
     * @param   string|null     $name   program name to report to the server
     */
    public static function setProgName($name)
    {
        static::$progName = is_null($name) ? $name : (string) $name;
    }

    /**
     * Get the program name that will be set on any new connections.
     *
     * @return  string|null     program name to be set on new connections.
     */
    public static function getProgName()
    {
        return static::$progName;
    }

    /**
     * Provide a program version to set on any new connections.
     *
     * @param   string|null     $version   program version to report to the server
     */
    public static function setProgVersion($version)
    {
        static::$progVersion = is_null($version) ? $version : (string) $version;
    }

    /**
     * Get the program version that will be set on any new connections.
     *
     * @return  string|null     program version to be set on new connections.
     */
    public static function getProgVersion()
    {
        return static::$progVersion;
    }

    /**
     * Private constructor. Prevents callers from creating a factory instance.
     */
    private function __construct()
    {
    }
}
