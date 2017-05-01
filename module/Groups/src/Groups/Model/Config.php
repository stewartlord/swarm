<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Groups\Model;

use Record\Key\AbstractKey;

class Config extends AbstractKey
{
    const KEY_PREFIX   = 'swarm-group-';

    protected $fields  = array(
        'name'          => array(
            'accessor'  => 'getName',
            'mutator'   => 'setName'
        ),
        'description'   => array(
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ),
        'emailFlags'    => array(
            'accessor'  => 'getEmailFlags',
            'mutator'   => 'setEmailFlags'
        )
    );

    /**
     * Get the friendlier name for the group.
     *
     * @return  string  group name
     */
    public function getName()
    {
        return strlen($this->getRawValue('name'))
            ? $this->getRawValue('name')
            : $this->getId();
    }

    /**
     * Set a friendlier name for the group.
     *
     * @param   string|null     $name   the name for the group or null
     * @return  Config          to maintain a fluent interface
     */
    public function setName($name)
    {
        return $this->setRawValue('name', $name);
    }

    /**
     * The description for the group.
     *
     * @return  string|null     the description for the group
     */
    public function getDescription()
    {
        return $this->getRawValue('description');
    }

    /**
     * Set a description for the group.
     *
     * @param   string|null     $description    the description for the group or null
     * @return  Config          to maintain a fluent interface
     */
    public function setDescription($description)
    {
        return $this->setRawValue('description', $description);
    }

    /**
     * Returns an array of email/notification flags.
     *
     * @return  array   names for all email flags
     */
    public function getEmailFlags()
    {
        return (array) $this->getRawValue('emailFlags');
    }

    /**
     * Returns the value of the specified email flag, if it exists, or null if it could not be found.
     *
     * @param   string      $flag   specific email flag we are looking for
     * @return  mixed|null  value of the flag if found, or null if the flag was not found
     */
    public function getEmailFlag($flag)
    {
        $emailFlags = $this->getEmailFlags();
        return isset($emailFlags[$flag]) ? $emailFlags[$flag] : null;
    }

    /**
     * Set an array of active email/notification flags.
     *
     * @param   array|null  $flags    an array of flags or null
     * @return  Config      to maintain a fluent interface
     */
    public function setEmailFlags($flags)
    {
        return $this->setRawValue('emailFlags', (array) $flags);
    }
}
