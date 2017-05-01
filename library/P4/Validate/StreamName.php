<?php
/**
 * Validates string for suitability as a Perforce stream name.
 * Extends spec-name to verify we lead with two slashes and contain a
 * third. Also toggles some default settings to match p4ds validation.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Validate;

class StreamName extends KeyName
{
    protected $allowRelative    = true;
    protected $allowPercent     = false;
    protected $allowCommas      = false;
    protected $allowSlashes     = true;

    const INVALID_FORMAT        = 'format';

    /**
     * Revised message templates upon instantiation.
     */
    public function __construct()
    {
        $message = "Value is not the correct format of '//depotname/string'";
        $this->messageTemplates[self::INVALID_FORMAT] = $message;
    }

    /**
     * Checks if the given string is a valid perforce spec name.
     *
     * @param   string|int  $value  spec name value to validate.
     * @return  boolean     true if value is a valid spec name, false otherwise.
     */
    public function isValid($value)
    {
        if (!parent::isValid($value)) {
            return false;
        }

        // verify stream is in the format //depotname/string
        if (!preg_match('#^//[^/]+/[^/]+$#', $value)) {
            $this->error(static::INVALID_FORMAT);
            return false;
        }

        return true;
    }
}
