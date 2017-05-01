<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Validator;

/**
 * Validates that the given value is JSON or null.
 */
class Json extends AbstractValidator
{
    const INVALID = 'invalid';

    protected $messageTemplates = array(
        self::INVALID => "Invalid input given. JSON string required.",
    );

    /**
     * Returns true if $value is valid JSON or null.
     *
     * @param   mixed   $value  value to check.
     * @return  boolean         true if valid JSON; false otherwise.
     */
    public function isValid($value)
    {
        if ($value !== null && json_decode($value) === null) {
            $this->error(self::INVALID);
            return false;
        }

        return true;
    }
}
