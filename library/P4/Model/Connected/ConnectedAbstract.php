<?php
/**
 * Provides a base implementation for connected models.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Model\Connected;

use P4\Connection\ConnectionInterface;
use P4\Connection\Connection;
use P4\Exception;

abstract class ConnectedAbstract implements ConnectedInterface
{
    protected $connection = null;

    /**
     * We need a custom sleep to exclude the connection property.
     * Connection objects cannot be serialized.
     *
     * @return  array   list of properties to serialize
     */
    public function __sleep()
    {
        return array_diff(
            array_keys(get_object_vars($this)),
            array('connection')
        );
    }

    /**
     * Instantiate the model and set the connection to use.
     *
     * @param   ConnectionInterface     $connection  optional - a connection to use for this instance.
     */
    public function __construct(ConnectionInterface $connection = null)
    {
        if ($connection) {
            $this->setConnection($connection);
        } elseif (Connection::hasDefaultConnection()) {
            $this->setConnection(Connection::getDefaultConnection());
        }
    }

    /**
     * Set the Perforce connection to use when
     * issuing Perforce commands for this instance.
     *
     * @param   ConnectionInterface     $connection     the connection to use for this instance.
     * @return  ConnectedAbstract       provides fluent interface.
     */
    public function setConnection(ConnectionInterface $connection)
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Get the Perforce connection used by this model.
     *
     * @return  ConnectionInterface      the connection instance used by this model.
     */
    public function getConnection()
    {
        if ($this->connection instanceof ConnectionInterface) {
            return $this->connection;
        }

        throw new Exception("Cannot get connection. No connection is set.");
    }

    /**
     * Get the default Perforce connection to use.
     *
     * @return  ConnectionInterface      the default connection.
     */
    public static function getDefaultConnection()
    {
        return Connection::getDefaultConnection();
    }

    /**
     * Determine if this model has a connection to Perforce.
     *
     * @return  bool  true if the model has a connection to Perforce.
     */
    public function hasConnection()
    {
        try {
            $this->getConnection();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Clear this model's connection. This is primarily for testing purposes.
     */
    public function clearConnection()
    {
        $this->connection = null;
    }
}
