<?php
/**
 * Provides validator abstract with basic error message handling.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Validate;

abstract class AbstractValidate implements ValidateInterface
{
    protected $value            = null;
    protected $messages         = array();
    protected $messageTemplates = array();

    /**
     * Get errors for the most recent isValid() check.
     *
     * @return  array   list of error messages.
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Get the message templates for this validator.
     *
     * @return  array   list of error message templates.
     */
    public function getMessageTemplates()
    {
        return $this->messageTemplates;
    }

    /**
     * Record an error detected during validation.
     * Replaces '%value%' with the value being validated.
     *
     * @param   string  $messageKey     the id of the message to add.
     */
    protected function error($messageKey)
    {
        if (!array_key_exists($messageKey, $this->messageTemplates)) {
            throw new \InvalidArgumentException(
                "Cannot set error. Invalid message key given."
            );
        }

        // support %value% substitution.
        $value = is_object($this->value)
            ? get_class($this->value)
            : $this->value;
        $message = $this->messageTemplates[$messageKey];
        $message = str_replace(
            '%value%',
            is_array($value) ? 'Array' : (string) $value,
            $message
        );

        $this->messages[$messageKey] = $message;
    }

    /**
     * Sets the value being validated and clears the messages.
     *
     * @param   mixed   $value  the value being validated.
     */
    protected function set($value)
    {
        $this->value    = $value;
        $this->messages = array();
    }
}
