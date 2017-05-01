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
 * Validates that the given value is an array or null.
 */
class IsArray extends AbstractValidator
{
    const INVALID = 'invalid';

    protected $messageTemplates = array(
        self::INVALID => "Invalid type given. Array required.",
    );

    /**
     * Returns true if $value is an array or null.
     *
     * @param   mixed   $value  value to check for array type.
     * @return  boolean         true if type is an array; false otherwise.
     */
    public function isValid($value)
    {
        if ($value !== null && !is_array($value)) {
            $this->error(self::INVALID);
            return false;
        }

        return true;
    }
}
