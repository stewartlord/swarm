<?php
/**
 * Abstracts operations against Perforce clients/workspaces.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 * @todo        Add support for 'p4 opened'
 * @todo        Deal with converting updated/created date/times from listing format
 * @todo        Return DateTime objects as appropriate
 */

namespace P4\Spec;

use P4\Validate;
use P4\Connection\ConnectionInterface;

class Client extends PluralAbstract
{
    const SPEC_TYPE         = 'client';
    const ID_FIELD          = 'Client';

    const FETCH_BY_NAME     = 'name';
    const FETCH_BY_OWNER    = 'owner';
    const FETCH_BY_STREAM   = 'stream';

    protected $fields       = array(
        'Update'        => array(
            'accessor'  => 'getUpdateDateTime'
        ),
        'Access'        => array(
            'accessor'  => 'getAccessDateTime'
        ),
        'Owner'         => array(
            'accessor'  => 'getOwner',
            'mutator'   => 'setOwner'
        ),
        'Host'          => array(
            'accessor'  => 'getHost',
            'mutator'   => 'setHost'
        ),
        'Description'   => array(
            'accessor'  => 'getDescription',
            'mutator'   => 'setDescription'
        ),
        'Root'          => array(
            'accessor'  => 'getRoot',
            'mutator'   => 'setRoot'
        ),
        'Options'       => array(
            'accessor'  => 'getOptions',
            'mutator'   => 'setOptions'
        ),
        'SubmitOptions' => array(
            'accessor'  => 'getSubmitOptions',
            'mutator'   => 'setSubmitOptions'
        ),
        'LineEnd'       => array(
            'accessor'  => 'getLineEnd',
            'mutator'   => 'setLineEnd'
        ),
        'View'          => array(
            'accessor'  => 'getView',
            'mutator'   => 'setView'
        ),
        'Stream'        => array(
            'accessor'  => 'getStream',
            'mutator'   => 'setStream'
        )
    );

    /**
     * Get all Clients from Perforce. Adds filtering options.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     * supported options are:
     *
     *   FETCH_MAXIMUM - set to integer value to limit to the first 'max' number
     *                   of entries.
     *   FETCH_BY_NAME - set to client name pattern (e.g. 'pc*').
     *  FETCH_BY_OWNER - set to owner's username (e.g. 'jdoe').
     * FETCH_BY_STREAM - set to stream name (e.g. '//depotname/string').
     *
     * @param   ConnectionInterface     $connection  optional - a specific connection to use.
     * @return  \P4\Model\Fielded\Iterator  all records of this type.
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // simply return parent - method exists to document options.
        return parent::fetchAll($options, $connection);
    }

    /**
     * Save this spec to Perforce.
     * Extends parent to blank out the 'View' if a stream is specified. You cannot
     * edit the view on a stream spec but leaving it can cause errors.
     *
     * @return  SpecAbstract        provides a fluent interface
     */
    public function save()
    {
        if ($this->get('Stream')) {
            $this->set('View', array());
        }

        return parent::save();
    }

    /**
     * Remove this client. Extends parent to offer force delete.
     *
     * @param   boolean     $force      pass true to force delete this client.
     * @return  Client      provides fluent interface.
     */
    public function delete($force = false)
    {
        return parent::delete($force ? array('-f') : null);
    }

    /**
     * Determine if the given client id exists.
     *
     * @param   string                   $id          the id to check for.
     * @param   ConnectionInterface      $connection  optional - a specific connection to use.
     * @return  bool  true if the given id matches an existing client.
     */
    public static function exists($id, ConnectionInterface $connection = null)
    {
        // check id for valid format
        if (!static::isValidId($id)) {
            return false;
        }

        $clients = static::fetchAll(
            array(
                static::FETCH_BY_NAME => $id,
                static::FETCH_MAXIMUM => 1
            ),
            $connection
        );

        return (bool) count($clients);
    }

    /**
     * Extends the parent temp cleanup callback to try reverting any
     * files prior to client deletion. This won't always be successful,
     * but it will reduce the number of temp clients that cannot be
     * deleted due to open files.
     *
     * @return callable     A callback function with the signature function($entry)
     */
    protected static function getTempCleanupCallback()
    {
        $parentCallback = parent::getTempCleanupCallback();

        return function ($entry) use ($parentCallback) {
            $p4       = $entry->getConnection();
            $original = $p4->getClient();
            $p4->setClient($entry->getId());

            // try to revert any open files - if this fails we
            // want to carry on so the original client gets restored
            // and we still attempt to delete the client spec.
            try {
                $p4->run('revert', array('-k', '//...'));
            } catch (\Exception $e) {
                // carry on!
            }

            // restore the original client
            $p4->setClient($original);

            // let parent delete the spec entry.
            return $parentCallback($entry);
        };
    }

    /**
     * Get the last update time for this client spec.
     * This value is read only, no setUpdateTime function is provided.
     *
     * If this is a brand new spec, null will be returned in lieu of a time.
     *
     * @return  string|null  Date/Time of last update, formatted "2009/11/23 12:57:06" or null
     */
    public function getUpdateDateTime()
    {
        return $this->getRawValue('Update');
    }

    /**
     * Get the last access time for this client spec.
     * This value is read only, no setAccessTime function is provided.
     *
     * If this is a brand new spec, null will be returned in lieu of a time.
     *
     * @return  string|null  Date/Time of last access, formatted "2009/11/23 12:57:06" or null
     */
    public function getAccessDateTime()
    {
        return $this->getRawValue('Access');
    }

    /**
     * Get the owner of this client.
     *
     * @return  string|null User who owns this record.
     */
    public function getOwner()
    {
        return $this->getRawValue('Owner');
    }

    /**
     * Set the owner of this client to passed value.
     *
     * @param   string|null $owner  A string containing username
     * @return  Client      provides a fluent interface.
     * @throws  \InvalidArgumentException Owner is incorrect type.
     */
    public function setOwner($owner)
    {
        if (!is_string($owner) && !is_null($owner)) {
            throw new \InvalidArgumentException('Owner must be a string or null.');
        }

        return $this->setRawValue('Owner', $owner);
    }

    /**
     * Get the host setting for this client.
     *
     * @return  string|null Host name set for this client, empty string for any.
     */
    public function getHost()
    {
        return $this->getRawValue('Host');
    }

    /**
     * If set, restricts access to the named host. Specify a blank string or null
     * to allow access from all hosts.
     *
     * @param   string|null $host   Host name for this client, empty string or null for any
     * @return  Client      provides a fluent interface.
     * @throws  \InvalidArgumentException Host is incorrect type.
     */
    public function setHost($host)
    {
        if (!is_string($host) && !is_null($host)) {
            throw new \InvalidArgumentException('Host must be a string or null.');
        }

        return $this->setRawValue('Host', $host);
    }

    /**
     * Get the description for this client.
     *
     * @return  string|null description for this client.
     */
    public function getDescription()
    {
        return $this->getRawValue('Description');
    }

    /**
     * Set a description for this client.
     *
     * @param   string|null $description    description for this client.
     * @return  Client      provides a fluent interface.
     * @throws  \InvalidArgumentException   Description is incorrect type.
     */
    public function setDescription($description)
    {
        if (!is_string($description) && !is_null($description)) {
            throw new \InvalidArgumentException('Description must be a string or null.');
        }

        return $this->setRawValue('Description', $description);
    }

    /**
     * Get the base directory of the client workspace.
     *
     * @return  string|null Base directory of the client workspace.
     */
    public function getRoot()
    {
        return $this->getRawValue('Root');
    }

    /**
     * Set the base directory of the client workspace.
     *
     * @param   string|null $root   Base directory for the client workspace.
     * @return  Client      provides a fluent interface.
     * @throws  \InvalidArgumentException Root is incorrect type.
     */
    public function setRoot($root)
    {
        if (!is_string($root) && !is_null($root)) {
            throw new \InvalidArgumentException('Root must be a string or null.');
        }

        return $this->setRawValue('Root', $root);
    }

    /**
     * Get options for this client.
     * Returned array will contain one option per element e.g.:
     * array (
     *     0 => 'noallwrite',
     *     1 => 'noclobber',
     *     2 => 'nocompress',
     *     3 => 'unlocked',
     *     4 => 'nomodtime',
     *     5 => 'rmdir'
     * )
     *
     * @return  array  options which are set on this client.
     */
    public function getOptions()
    {
        $options = $this->getRawValue('Options');
        $options = explode(' ', $options);

        // Explode will set key 0 to null for empty input; clean it up.
        if (count($options) == 1 && empty($options[0])) {
            $options = array();
        }

        return $options;
    }

    /**
     * Set the options for this client.
     * Accepts an array, format detailed in getOptions, or a single string containing
     * a space seperated list of options.
     *
     * @param   array|string  $options  options to set on this client in array or string.
     * @return  Client        provides a fluent interface.
     * @throws  \InvalidArgumentException Options are incorrect type.
     */
    public function setOptions($options)
    {
        if (is_array($options)) {
            $options = implode(' ', $options);
        }

        if (!is_string($options)) {
            throw new \InvalidArgumentException('Options must be an array or string');
        }

        return $this->setRawValue('Options', $options);
    }

    /**
     * Get the submit options for this client.
     * Returned array will contain one option per element e.g.:
     * array (
     *     0 => 'submitunchanged'
     * )
     *
     * @return  array  submit options which are set on this client.
     */
    public function getSubmitOptions()
    {
        $options = $this->getRawValue('SubmitOptions');
        $options = explode(' ', $options);

        // Explode will set key 0 to null for empty input; clean it up.
        if (count($options) == 1 && empty($options[0])) {
            $options = array();
        }

        return $options;
    }

    /**
     * Set the submit options for this client.
     * Accepts an array, format detailed in getSubmitOptions, or a single string
     * containing a space seperated list of options.
     *
     * @param   array|string  $options  submit options to set on this client in array or string
     * @return  Client        provides a fluent interface.
     * @throws  \InvalidArgumentException Submit Options are incorrect type.
     */
    public function setSubmitOptions($options)
    {
        if (is_array($options)) {
            $options = implode(' ', $options);
        }

        if (!is_string($options)) {
            throw new \InvalidArgumentException('Submit Options must be an array or string');
        }

        return $this->setRawValue('SubmitOptions', $options);
    }

    /**
     * Get the line ending setting for this client.
     * Will be one of: local/unix/mac/win/share
     *
     * @return  string|null Line ending setting for this client.
     */
    public function getLineEnd()
    {
        return $this->getRawValue('LineEnd');
    }

    /**
     * Set the line ending setting for this client.
     * See getLineEnd for available options.
     *
     * @param   string|null $lineEnd    Line ending setting for this client.
     * @return  Client      provides a fluent interface.
     * @throws  \InvalidArgumentException lineEnd is incorrect type.
     */
    public function setLineEnd($lineEnd)
    {
        if (!is_string($lineEnd) && !is_null($lineEnd)) {
            throw new \InvalidArgumentException('Line End must be a string or null.');
        }

        return $this->setRawValue('LineEnd', $lineEnd);
    }

    /**
     * Get the view for this client.
     * View entries will be returned as an array with 'depot' and 'client' entries, e.g.:
     * array (
     *      0 => array (
     *          'depot'  => '//depot/example/with space/...',
     *          'client' => '//client.name/...'
     *      )
     *  )
     *
     * @return  array  list view entries for this client.
     */
    public function getView()
    {
        // The raw view data is formatted as:
        //  array (
        //      0 => '"//depot/example/with space/..." //client.name/...',
        //  )
        //
        // We split this into 'depot' and 'client' components via the str_getcsv function
        // and key the two resulting entries as 'depot' and 'client'
        $view = array();
        // The ?: translates empty views into an empty array
        foreach ($this->getRawValue('View') ?: array() as $entry) {
            $entry = str_getcsv($entry, ' ');
            $view[] = array_combine(array('depot','client'), $entry);
        }

        return $view;
    }

    /**
     * Set the view for this client.
     * View is passed as an array of view entries. Each view entry can be an array with
     * 'depot' and 'client' entries or a raw string.
     *
     * @param   array  $view  View entries, formatted into depot/client sub-arrays.
     * @return  Client        provides a fluent interface.
     * @throws  \InvalidArgumentException View array, or a view entry, is incorrect type.
     */
    public function setView($view)
    {
        if (!is_array($view)) {
            throw new \InvalidArgumentException('View must be passed as array.');
        }

        // The View array contains either:
        // - Child arrays keyed on depot/client which we glue together
        // - Raw strings which we simply leave as is
        // The below foreach run will normalize the whole thing for storage
        $parsedView = array();
        foreach ($view as $entry) {
            if (is_array($entry) &&
                isset($entry['depot'], $entry['client']) &&
                is_string($entry['depot']) &&
                is_string($entry['client'])) {
                $entry = '"'. $entry['depot'] .'" "'. $entry['client'] .'"';
            }

            if (!is_string($entry)) {
                throw new \InvalidArgumentException(
                    "Each view entry must be a 'depot' and 'client' array or a string."
                );
            }

            $validate = str_getcsv($entry, ' ');
            if (count($validate) != 2 || trim($validate[0]) === '' || trim($validate[1]) === '') {
                throw new \InvalidArgumentException(
                    "Each view entry must contain two paths, no more, no less."
                );
            }

            $parsedView[] = $entry;
        };

        return $this->setRawValue('View', $parsedView);
    }

    /**
     * Add a view mapping to this client.
     *
     * @param   string  $depot      the depot half of the view mapping.
     * @param   string  $client     the client half of the view mapping.
     * @return  Client      provides a fluent interface.
     */
    public function addView($depot, $client)
    {
        $mappings   = $this->getView();
        $mappings[] = array("depot" => $depot, "client" => $client);

        return $this->setView($mappings);
    }

    /**
     * Updates the 'client' half of the view to ensure the
     * current client ID is used.
     *
     * @return Client       provides a fluent interface.
     */
    public function touchUpView()
    {
        $view = $this->getView();
        foreach ($view as &$mapping) {
            $mapping['client'] = preg_replace(
                "#//[^/]*/#",
                '//' . $this->getId() . '/',
                $mapping['client']
            );
        }
        $this->setView($view);

        return $this;
    }

    /**
     * Get the stream this client is dedicated to.
     *
     * @return  string|null     Stream setting for this client.
     */
    public function getStream()
    {
        return $this->getRawValue('Stream');
    }

    /**
     * Set the stream this client is dedicated to.
     *
     * @param   string|null $stream         stream setting for this client.
     * @return  Client                      provides a fluent interface.
     * @throws  \InvalidArgumentException   stream is incorrect type.
     * @todo    Validate stream id
     */
    public function setStream($stream)
    {
        if (!is_string($stream) && !is_null($stream)) {
            throw new \InvalidArgumentException('Stream must be a string or null.');
        }

        return $this->setRawValue('Stream', $stream);
    }

    /**
     * Check if the given id is in a valid format for this spec type.
     *
     * @param   string      $id     the id to check
     * @return  bool        true if id is valid, false otherwise
     */
    protected static function isValidId($id)
    {
        $validator = new Validate\SpecName;
        $validator->allowRelative(true);
        $validator->allowPercent(false);
        $validator->allowCommas(false);
        return $validator->isValid($id);
    }

    /**
     * Produce set of flags for the spec list command, given fetch all options array.
     * Extends parent to add support for filter option.
     *
     * @param   array   $options    array of options to augment fetch behavior.
     *                              see fetchAll for documented options.
     * @return  array   set of flags suitable for passing to spec list command.
     */
    protected static function getFetchAllFlags($options)
    {
        $flags = parent::getFetchAllFlags($options);

        if (isset($options[static::FETCH_BY_NAME])) {
            $name = $options[static::FETCH_BY_NAME];

            if (!is_string($name) || trim($name) === '') {
                throw new \InvalidArgumentException(
                    'Filter by Name expects a non-empty string as input'
                );
            }

            $flags[] = '-e';
            $flags[] = $name;
        }

        if (isset($options[static::FETCH_BY_OWNER])) {
            $owner = $options[static::FETCH_BY_OWNER];

            // We allow empty values as this returns clients with no owner
            if (!is_string($owner) || trim($owner) === '') {
                throw new \InvalidArgumentException(
                    'Filter by Owner expects a non-empty string as input'
                );
            }

            $flags[] = '-u';
            $flags[] = $owner;
        }

        if (isset($options[static::FETCH_BY_STREAM])) {
            $stream = $options[static::FETCH_BY_STREAM];

            if (!is_string($stream) || trim($stream) === '') {
                throw new \InvalidArgumentException(
                    'Filter by Stream expects a non-empty string as input'
                );
            }

            $flags[] = '-S';
            $flags[] = $stream;
        }

        return $flags;
    }
}
