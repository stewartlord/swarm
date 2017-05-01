<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Groups\Filter;

use Application\Filter\ArrayValues;
use Application\Filter\StringToId;
use Application\InputFilter\InputFilter;
use Application\Validator\FlatArray as FlatArrayValidator;
use Groups\Model\Group as GroupModel;
use P4\Connection\ConnectionInterface as Connection;

class Group extends InputFilter
{
    protected $verifyNameAsId = false;

    /**
     * Enable/disable behavior where the name must produce a valid id.
     *
     * @param   bool    $verifyNameAsId     optional - pass true to verify the name makes a good id
     * @return  Group   provides fluent interface
     */
    public function verifyNameAsId($verifyNameAsId = null)
    {
        // doubles as an accessor
        if (func_num_args() === 0) {
            return $this->verifyNameAsId;
        }

        $this->verifyNameAsId = (bool) $verifyNameAsId;

        // if id comes from the name, then name is required and id is not
        $this->get('Group')->setRequired(!$this->verifyNameAsId);
        $this->get('name')->setRequired($this->verifyNameAsId);

        return $this;
    }

    /**
     * Generate an id from the given name.
     *
     * @param   string  $name   the name to turn into an id
     * @return  string  the resulting id
     */
    public function nameToId($name)
    {
        $toId = new StringToId;
        return $toId($name);
    }

    /**
     * Extends parent to add all of the group filters and setup the p4 connection.
     *
     * @param   Connection  $p4     connection to use for validation
     */
    public function __construct(Connection $p4)
    {
        $filter     = $this;
        $reserved   = array('add', 'edit', 'delete');
        $translator = $p4->getService('translator');

        // validate id for uniqueness on add, unless id comes from name
        // in that case the name field does all the validation for us
        $this->add(
            array(
                'name'       => 'Group',
                'required'   => true,
                'filters'    => array('trim'),
                'validators' => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($p4, $reserved, $filter) {
                                // if adding and name does not inform id, check if the group already exists
                                if ($filter->isAdd() && !$filter->verifyNameAsId()
                                    && (in_array($value, $reserved) || GroupModel::exists($value, $p4))
                                ) {
                                    return 'This Group ID is taken. Please pick a different Group ID.';
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // if id comes from name, then we need to ensure name produces a usable/unique id
        $this->add(
            array(
                'name'       => 'name',
                'required'   => false,
                'filters'    => array('trim'),
                'validators' => array(
                    array(
                        'name'    => 'NotEmpty',
                        'options' => array(
                            'message' => "Name is required and can't be empty."
                        )
                    ),
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) use ($p4, $reserved, $filter) {
                                // nothing to do if name does not inform the id
                                if (!$filter->verifyNameAsId()) {
                                    return true;
                                }

                                $id = $filter->nameToId($value);
                                if (!$id) {
                                    return 'Name must contain at least one letter or number.';
                                }

                                // when adding, check if the group already exists
                                if ($filter->isAdd() && (in_array($id, $reserved) || GroupModel::exists($id, $p4))) {
                                    return 'This name is taken. Please pick a different name.';
                                }

                                return true;
                            }
                        )
                    )
                )
            )
        );

        // add users field
        $this->add(
            array(
                'name'              => 'Users',
                'continue_if_empty' => true,
                'filters'           => array(new ArrayValues),
                'validators' => array(
                    array(
                        'name'                   => '\Application\Validator\FlatArray',
                        'break_chain_on_failure' => true
                    ),
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value, $context) {
                                $context += array('Owners' => array(), 'Users' => array(), 'Subgroups' => array());
                                return $context['Owners'] || $context['Users'] || $context['Subgroups']
                                    ? true
                                    : 'Group must have at least one owner, user or subgroup.';
                            }
                        )
                    )
                )
            )
        );

        // add subgroups field
        $this->add(
            array(
                'name'       => 'Subgroups',
                'required'   => false,
                'filters'    => array(new ArrayValues),
                'validators' => array(new FlatArrayValidator)
            )
        );

        // add owners field
        $this->add(
            array(
                'name'       => 'Owners',
                'required'   => false,
                'filters'    => array(new ArrayValues),
                'validators' => array(new FlatArrayValidator)
            )
        );

        // ensure description is a string
        $this->add(
            array(
                'name'       => 'description',
                'required'   => false,
                'filters'    => array(array('name' => 'StringTrim')),
                'validators' => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) {
                                return is_string($value) ?: "Description must be a string.";
                            }
                        )
                    )
                )
            )
        );

        // ensure emailFlags is an array containing keys for the flags we want to set
        $this->add(
            array(
                'name'     => 'emailFlags',
                'required' => false,
                'filters'  => array(
                    array(
                        'name'    => 'Callback',
                        'options' => array(
                            'callback' => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }

                                return array(
                                    'reviews' => isset($value['reviews']) ? $value['reviews'] : true,
                                    'commits' => isset($value['commits']) ? $value['commits'] : false,
                                );
                            }
                        )
                    )
                ),
                'validators' => array(
                    array(
                        'name'    => '\Application\Validator\Callback',
                        'options' => array(
                            'callback' => function ($value) {
                                $flatArrayValidator = new FlatArrayValidator;
                                return $flatArrayValidator->isValid($value)
                                    ?: "Email flags must be an associative array of scalar values.";
                            }
                        )
                    )
                )
            )
        );
    }
}
