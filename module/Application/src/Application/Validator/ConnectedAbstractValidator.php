<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Validator;

use P4\Connection\AbstractConnection;
use Zend\Validator\Exception as ValidatorException;

/**
 * Extends parent by providing method to set/get Perforce connection from options.
 */
abstract class ConnectedAbstractValidator extends AbstractValidator
{
    /**
     * Verify that the 'connection' option holds a Perforce connection instance.
     *
     * @param  AbstractConnection           $connection
     * @return ConnectedAbstractValidator   provides a fluent interface
     */
    public function setConnection(AbstractConnection $connection)
    {
        $this->abstractOptions['connection'] = $connection;
        return $this;
    }

    /**
     * Returns the connection option.
     *
     * @return AbstractConnection                   Perforce connection
     * @throws ValidatorException\RuntimeException  if connection option is not set
     */
    public function getConnection()
    {
        try {
            return $this->getOption('connection');
        } catch (ValidatorException\InvalidArgumentException $e) {
            // temporarily ignore this, we will throw more propriate exception later
        }

        throw new ValidatorException\RuntimeException('connection option is mandatory');
    }
}
