<?php
/**
 * Provides a common interface for connected models.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Model\Connected;

use P4\Connection\ConnectionInterface;

interface ConnectedInterface
{
    /**
     * Instantiate the model and set the connection to use.
     *
     * @param   ConnectionInterface     $connection  optional - a connection to use for this instance.
     */
    public function __construct(ConnectionInterface $connection = null);

    /**
     * Set the Perforce connection to use when
     * issuing Perforce commands for this instance.
     *
     * @param   ConnectionInterface     $connection     the connection to use for this instance.
     * @return  ConnectedAbstract       provides fluent interface.
     */
    public function setConnection(ConnectionInterface $connection);

    /**
     * Get the Perforce connection used by this model.
     *
     * @return  ConnectionInterface      the connection instance used by this model.
     */
    public function getConnection();

    /**
     * Get the default Perforce connection to use.
     *
     * @return  ConnectionInterface      the default connection.
     */
    public static function getDefaultConnection();

    /**
     * Determine if this model has a connection to Perforce.
     *
     * @return  bool  true if the model has a connection to Perforce.
     */
    public function hasConnection();

    /**
     * Clear this model's connection. This is primarily for testing purposes.
     */
    public function clearConnection();
}
