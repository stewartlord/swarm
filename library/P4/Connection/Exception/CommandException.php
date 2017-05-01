<?php
/**
 * Exception to be thrown when an error occurs running a Perforce
 * command. Holds the associated Connection instance and result object.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Connection\Exception;

use P4\Connection\CommandResult;
use P4\Connection\ConnectionInterface;

class CommandException extends \P4\Exception
{
    private $connection;
    private $result;

    /**
     * Set the perforce Connection instance.
     *
     * @param   ConnectionInterface     $connection     the perforce Connection instance.
     */
    public function setConnection(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get the perforce Connection instance if one is set.
     *
     * @return  ConnectionInterface     the perforce Connection instance.
     */
    public function getConnection()
    {
        if (isset($this->connection)) {
            return $this->connection;
        }
    }

    /**
     * Set the perforce result object.
     *
     * @param   CommandResult   $result     the perforce result object.
     */
    public function setResult($result)
    {
        if ($result instanceof CommandResult) {
            $this->result = $result;
        }
    }

    /**
     * Get the perforce result object if one is set.
     *
     * @return  CommandResult   the perforce result object.
     */
    public function getResult()
    {
        if (isset($this->result)) {
            return $this->result;
        }
    }
}
