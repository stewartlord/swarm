<?php
// @codingStandardsIgnoreFile
/**
 * Provide a validate interface that is compatible with Zend_Validate
 * but does not require it. If Zend_Validate is present, extends from
 * Zend_Validate_Interface. Otherwise, declares a compatible interface
 * from scratch.
 */

namespace P4\Validate;

if (interface_exists('\Zend\Validator\ValidatorInterface')) {

    /**
     * Zend_Validate is present, use it. This is important if
     * outside code is type-checking against this interface.
     *
     * @copyright   2013-2016 Perforce Software. All rights reserved.
     * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
     * @version     2016.1/1400259
     */
    interface ValidateInterface extends \Zend\Validator\ValidatorInterface
    {
    }

} else {

    /**
     * Define a interface compatible with Zend_Validate.
     *
     * @copyright   2013-2016 Perforce Software. All rights reserved.
     * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
     * @version     2016.1/1400259
     */
    interface ValidateInterface
    {
        /**
         * Check if value meets validation requirements.
         *
         * If the given value is invalid, this method will return false
         * and getMessages() will provide an array of errors.
         *
         * @param   mixed   $value  the value to validate
         * @return  bool    true if the value is valid; false otherwise.
         */
        public function isValid($value);

        /**
         * Get errors for the most recent isValid() check.
         *
         * @return  array   list of error messages.
         */
        public function getMessages();
    }
}
