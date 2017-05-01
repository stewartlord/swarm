<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\InputFilter;

use Application\Validator\Callback as CallbackValidator;
use Zend\InputFilter\InputFilter as ZendInputFilter;
use Zend\Validator\ValidatorChain;

/**
 * Extends parent by adding support for:
 *  - add/edit modes
 *  - mark fields as 'notAllowed' forcing the validation to always fail (unless
 *    the value is empty and the field is not required)
 */
class InputFilter extends ZendInputFilter
{
    const MODE_ADD  = 'add';
    const MODE_EDIT = 'edit';

    protected $mode;

    /**
     * Mark given element as 'not allowed'. Validation of such element will always
     * fail. Given element will also be marked 'not required' to avoid failing if
     * value is not present.
     *
     * @param   string          $element    element to mark as not-allowed
     * @return  InputFilter     provides fluent interface
     */
    public function setNotAllowed($element)
    {
        $input = isset($this->inputs[$element]) ? $this->inputs[$element] : null;
        if (!$input) {
            throw new \InvalidArgumentException(
                "Cannot set '$element' element NotAllowed - element not found."
            );
        }

        // tweak the element to:
        //  - make it not required (also sets allow empty)
        //  - don't allow empty values to overrule the opposite after making it not required
        //  - set our own validator chain containing only one validator always failing
        $validatorChain = new ValidatorChain;
        $validatorChain->attach(
            new CallbackValidator(
                function ($value) {
                    return 'Value is not allowed.';
                }
            )
        );
        $input->setRequired(false)
              ->setAllowEmpty(false)
              ->setValidatorChain($validatorChain);

        return $this;
    }

    /**
     * Set the filter mode (one of add or edit).
     *
     * @param   string          $mode   'add' or 'edit'
     * @return  InputFilter     provides fluent interface
     * @throws  \InvalidArgumentException
     */
    public function setMode($mode)
    {
        if ($mode !== static::MODE_ADD && $mode !== static::MODE_EDIT) {
            throw new \InvalidArgumentException('Invalid mode specified. Must be add or edit.');
        }

        $this->mode = $mode;

        return $this;
    }

    /**
     * Get the current mode (add or edit)
     *
     * @return  string  'add' or 'edit'
     * @throws  \RuntimeException   if mode has not been set
     */
    public function getMode()
    {
        if (!$this->mode) {
            throw new \RuntimeException("Cannot get mode. No mode has been set.");
        }

        return $this->mode;
    }

    /**
     * Return true if in add mode, false otherwise.
     *
     * @return  boolean     true if in add mode, false otherwise
     */
    public function isAdd()
    {
        return $this->getMode() === static::MODE_ADD;
    }

    /**
     * Return true if in edit mode, false otherwise.
     *
     * @return  boolean     true if in edit mode, false otherwise
     */
    public function isEdit()
    {
        return $this->getMode() === static::MODE_EDIT;
    }
}
