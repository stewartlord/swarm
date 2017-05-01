<?php
/**
 * Validates input for suitability as a Perforce change number.
 * Permits the literal string 'default' and integers or strings
 * (that are all digits) with an integer value > 0.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Validate;

use P4\Spec\Change;

class ChangeNumber extends AbstractValidate
{
    const INVALID_TYPE              = 'invalidType';
    const INVALID_NUMBER            = 'invalidNumber';

    protected $messageTemplates     = array(
        self::INVALID_TYPE          => 'Change number must be an integer or purely numeric string.',
        self::INVALID_NUMBER        => 'Change numbers must be greater than zero.'
    );

    /**
     * Checks if the given string is a valid change number.
     *
     * @param   string|int  $value  change number to validate.
     * @return  boolean     true if value is a valid change number, false otherwise.
     */
    public function isValid($value)
    {
        $this->set($value);

        // 'default' change is a special case that we allow.
        if ($value === Change::DEFAULT_CHANGE) {
            return true;
        }

        // test for valid type.
        if (!is_int($value) && !is_string($value)) {
            $this->error(self::INVALID_TYPE);
            return false;
        }

        // test for a string with anything other than digits.
        if (is_string($value) && preg_match('/[^0-9]/', $value)) {
            $this->error(self::INVALID_TYPE);
            return false;
        }

        // test for value less than one.
        if (intval($value) < 1) {
            $this->error(self::INVALID_NUMBER);
            return false;
        }

        return true;
    }
}
