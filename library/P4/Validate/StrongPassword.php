<?php
/**
 * Provides password strength validation according
 * to server security requirements
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Validate;

class StrongPassword extends AbstractValidate
{
    const WEAK_PASSWORD         = 'weakPassword';

    protected $messageTemplates = null;

    /**
     * Initialize the message templates.
     */
    public function __construct()
    {
        $this->messageTemplates = array(
            self::WEAK_PASSWORD =>
                "Passwords must be at least 8 characters long and contain "
              . "mixed case or both alphabetic and non-alphabetic characters."
        );
    }

    /**
     * Check if value is strong password according
     * to server requirements for strong password.
     *
     * @param string $value password
     * @return boolean
     */
    public function isValid($value)
    {
        $this->set($value);

        $conditionsMet = 0;
        if ($this->containsUppercaseLetter($value)) {
            $conditionsMet++;
        }
        if ($this->containsLowercaseLetter($value)) {
            $conditionsMet++;
        }
        if ($this->containsNonAlphabetic($value)) {
            $conditionsMet++;
        }

        if (strlen($value) < 8 || $conditionsMet < 2) {
            $this->error(self::WEAK_PASSWORD);
            return false;
        }

        return true;
    }

    /**
     * Return true if value contains at least one uppercase letter,
     * otherwise return false.
     *
     * @param string $value password
     * @return boolean
     */
    protected function containsUppercaseLetter($value)
    {
        return preg_match("/[A-Z]+/", $value);
    }

    /**
     * Return true if value contains at least one lowercase letter,
     * otherwise return false.
     *
     * @param string $value password
     * @return boolean
     */
    protected function containsLowercaseLetter($value)
    {
        return preg_match("/[a-z]+/", $value);
    }

    /**
     * Return true if value contains at least one nonalphabetic character,
     * otherwise return false.
     *
     * @param string $value password
     * @return boolean
     */
    protected function containsNonAlphabetic($value)
    {
        return preg_match("/[^a-zA-Z]+/", $value);
    }
}
