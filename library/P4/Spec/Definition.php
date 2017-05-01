<?php
/**
 * This class provides access to the definition for a Perforce spec type.
 * This includes: field names, field types, field options, preset values,
 * comments, etc.
 *
 * Fields with the dataType 'text' have several issues with whitespace:
 * - Any trailing whitespace will be stripped
 * - A trailing new-line will be added if not present
 * - Any leading/intermediate lines will have trailing whitespace removed
 * - Any line with non-whitespace content will preserve all trailing whitespace
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Spec;

use P4;
use P4\Spec\Exception\Exception;
use P4\Connection\ConnectionInterface;
use P4\Model\Connected\ConnectedAbstract;

class Definition extends ConnectedAbstract
{
    protected $type         = null;
    protected $data         = array();
    protected $isPopulated  = false;

    protected static $cache = array();

    /**
     * Get the type of spec that this defines.
     *
     * @return  string  the type of spec this defines.
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set the type of spec this defines.
     *
     * @param   string  $type   the type of spec to define.
     * @return  Definition      provides a fluent interface
     */
    public function setType($type)
    {
        if (!is_string($type)) {
            throw new \InvalidArgumentException("Type must be a string.");
        }

        $this->type = $type;

        return $this;
    }

    /**
     * Get the definition for a given spec type from Perforce.
     *
     * @param   string                  $type       the type of the spec to get the definition for.
     * @param   ConnectionInterface     $connection optional - a specific connection to use.
     * @returns Definition              instance containing details about this spec type.
     */
    public static function fetch($type, ConnectionInterface $connection = null)
    {
        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // cache is per-server - ensure cache initialized for this server.
        $port = $connection->getPort();
        if (!array_key_exists($port, static::$cache)) {
            static::$cache[$port] = array();
        }

        // create and populate spec definition if not cached.
        if (!array_key_exists($type, static::$cache[$port]) ||
            !static::$cache[$port][$type] instanceof Definition) {

            // construct spec def instance.
            $definition = new static($connection);
            $definition->setType($type);

            // call get fields to force a populate and ensure type is valid.
            $definition->getFields();

            static::$cache[$port][$type] = $definition;

        }

        // return cloned copy so that changes don't pollute the cache.
        return clone static::$cache[$port][$type];
    }

    /**
     * Get multi-dimensional array of detailed field information for this spec type.
     *
     * @return  array   detailed field information for this spec type.
     */
    public function getFields()
    {
        // only populate if fields array is unset.
        if (!array_key_exists('fields', $this->data)) {
            $this->populate();
        }

        return $this->data['fields'];
    }

    /**
     * Get array of detailed information about a particular field.
     *
     * @param   string  $field  the field to get information about.
     * @return  array   detailed field information for this spec type.
     * @throws  Exception   if the field does not exist.
     */
    public function getField($field)
    {
        // verify field exists.
        if (!$this->hasField($field)) {
            throw new Exception("Can't get field '$field'. Field does not exist.");
        }

        $fields = $this->getFields();

        return $fields[$field];
    }

    /**
     * Check if this spec definition has a particular field.
     *
     * @param   string      $field  the field to check for the existence of.
     * @return  boolean     true if the spec has the named field, false otherwise.
     */
    public function hasField($field)
    {
        $fields = array_keys($this->getFields());
        return in_array((string)$field, $fields);
    }

    /**
     * Determine if the given field is required.
     *
     * @param   string  $field  the field to check if required.
     * @return  bool    true if the field is required, false otherwise.
     */
    public function isRequiredField($field)
    {
        $field = $this->getField($field);
        if ($field['fieldType'] === 'required' || $field['fieldType'] === 'key') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Determine if the given field is read-only.
     *
     * @param   string  $field  the field to check if required.
     * @return  bool    true if the field is read-only, false otherwise.
     */
    public function isReadOnlyField($field)
    {
        $field = $this->getField($field);

        if ($field['fieldType'] == 'once') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Set the fields that define this specification.
     *
     * @param   array   $fields     multi-dimensional array of detailed field information.
     * @return  Definition          provides a fluent interface
     * @todo    better validate fields array format.
     */
    public function setFields($fields)
    {
        if (!is_array($fields)) {
            throw new \InvalidArgumentException("Fields must be an array.");
        }

        $this->data['fields'] = $fields;

        return $this;
    }

    /**
     * Get comments for this spec type.
     *
     * @return  string  comments describing this spec type.
     */
    public function getComments()
    {
        // only populate if comments are unset.
        if (!array_key_exists('comments', $this->data)) {
            $this->populate();
        }

        return $this->data['comments'];
    }

    /**
     * Set the comments for this specification.
     *
     * Comments are stored as 'text' fields which causes whitespace issues.
     * See Definition for details.
     *
     * @param   string  $comments   comments describing this spec type.
     * @return  Definition          provides a fluent interface
     * @todo    validate comments format more thoroughly.
     */
    public function setComments($comments)
    {
        if (!is_string($comments)) {
            throw new \InvalidArgumentException("Comments must be a string.");
        }

        $this->data['comments'] = $comments;

        return $this;
    }

    /**
     * Save this spec definition to Perforce.
     *
     * @return  Definition  provides a fluent interface
     */
    public function save()
    {
        // save spec definition in Perforce.
        $connection = static::getDefaultConnection();
        $result     = $connection->run(
            'spec',
            array("-i", $this->getType()),
            $this->toSpecArray($this->data)
        );

        $this->clearCache();

        return $this;
    }

    /**
     * Given a field name this function will return the associated field code.
     *
     * @param   string  $name   String representing the field's name.
     * @return  int     The field code associated with the passed name.
     */
    public function fieldNameToCode($name)
    {
        $field = $this->getField($name);

        return (int) $field['code'];
    }

    /**
     * Given a field code this function will return the associated field name.
     *
     * @param   int|string  $code   Int or string representing code
     * @return  string  The field name associated with the passed code
     * @throws  \InvalidArgumentException   If passed an invalid or non-existent field code
     */
    public function fieldCodeToName($code)
    {
        // if we are passed a string, and casting through int doesn't change it,
        // it is purely numeric, cast to an int.
        if (is_string($code) &&
            $code === (string)(int)$code) {
            $code = (int)$code;
        }

        // if we made it this far, fail unless we have an int
        if (!is_int($code)) {
            throw new \InvalidArgumentException('Field must be a purely numeric string or int.');
        }

        $fields = $this->getFields();

        foreach ($fields as $name => $field) {
            if ($field['code'] == $code) {
                return $name;
            }
        }

        throw new \InvalidArgumentException('Specified field code does not exist.');
    }

    /**
     * Clear the shared 'fetch' class and also clear this instances fields/comments.
     *
     * @todo    If clearCache is called and this instance is subsequently populated, the shared
     *          fetch cache won't be updated. If fetch is later called an additional populate will
     *          be executed. This could be optimized but is a fairly narrow case.
     */
    public function clearCache()
    {
        $type = $this->getType();

        // Remove the static cache; helps with future 'fetch' calls
        unset(static::$cache[$this->getConnection()->getPort()][$type]);

        // Remove our instances values
        $this->data = array();

        // Ensure our instance will re-populate
        $this->isPopulated = false;
    }

    /**
     * Expand preset values that are expected to be interpreted client side.
     * For example, '$user' should be set to the name of the current Perforce
     * user. '$now' should be set to the current time. See 'p4 help undoc'
     * for additional details.
     *
     * @param   string                  $default    the default value to be expanded.
     * @param   ConnectionInterface     $connection optional - a specific connection to use
     *                                              when expanding default values.
     * @return  string|null             the expanded default value.
     * @throws  \InvalidArgumentException   if default value is not a string
     * @todo    job specs have additional 'expansions'; there may be more outside jobs too.
     */
    public static function expandDefault($default, ConnectionInterface $connection = null)
    {
        if (!is_string($default)) {
            throw new \InvalidArgumentException('Default value must be a string.');
        }

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        switch ($default) {
            case '$user':
                return $connection->getUser();
                break;
            case '$blank':
                return null;
                break;
            default:
                return $default;
                break;
        }
    }

    /**
     * Get the spec definition from Perforce if not already populated.
     */
    protected function populate()
    {
        // only populate once.
        if ($this->isPopulated) {
            return;
        }

        // query perforce to get spec definition.
        $connection = $this->getConnection();
        $result     = $connection->run(
            'spec',
            array("-o", $this->getType())
        );

        // ensure all sequences are expanded into arrays
        $result->expandSequences();

        // ensure spec output is an array.
        $spec = $result->getData(-1);

        if (!is_array($spec) || empty($spec)) {
            throw new Exception(
                "Failed to populate spec definition. Perforce result invalid."
            );
        }

        // convert spec to internal format.
        $data = $this->fromSpecArray($spec);

        // don't clobber fields/comments if already set.
        if (!array_key_exists('fields', $this->data)) {
            $this->data['fields'] = $data['fields'];
        }
        if (!array_key_exists('comments', $this->data)) {
            $this->data['comments'] = $data['comments'];
        }

        // flag as populated.
        $this->isPopulated = true;
    }

    /**
     * Convert 'p4 spec -o' output into the format of the internal data structure
     * used by this class.
     *
     * The data structure groups field metadata by field name and breaks multi-word
     * fields into their component parts. For example:
     *
     *  array (
     *    'fields' => array (
     *      'Field1' => array (
     *        'code'          => '310',
     *        'dataType'      => 'select',
     *        'displayLength' => '12',
     *        'fieldType'     => 'optional',
     *        'order'         => '0',
     *        'position'      => 'L',
     *        'options'       => array (
     *          0 => 'local',
     *          1 => 'unix',
     *        )
     *      ),
     *      'Field2' => array (
     *        'code'          => '311',
     *        'dataType'      => 'wlist',
     *        'displayLength' => '64',
     *        'fieldType'     => 'optional',
     *        'wordCount'     => '2'
     *      )
     *    ),
     *    'comments' => '# Comments for this spec.'
     *  )
     *
     * @param   array   $spec   the raw output from 'p4 spec -o'
     * @return  array   the converted spec definition data structure.
     */
    protected function fromSpecArray($spec)
    {
        $data = array(
            'fields'    => array(),
            'comments'  => null
        );

        // break apart fields word-list in spec array.
        foreach ($spec['Fields'] as $fieldInfo) {
            list($code, $name, $dataType, $length, $fieldType) = explode(" ", $fieldInfo);

            $data['fields'][$name] = array(
                'code'          => $code,
                'dataType'      => $dataType,
                'displayLength' => $length,
                'fieldType'     => $fieldType
            );

            // hack because Perforce doesn't provide word count
            // for single column wlist's.
            if ($dataType == 'wlist') {
                $data['fields'][$name]['wordCount'] = 1;
            }
        }

        // add word count information for multi-word fields.
        if (isset($spec['Words']) && is_array($spec['Words'])) {
            foreach ($spec['Words'] as $wordInfo) {
                list($fieldName, $wordCount) = explode(" ", $wordInfo);

                $data['fields'][$fieldName]['wordCount'] = $wordCount;
            }
        }

        // add format information.
        if (isset($spec['Formats']) && is_array($spec['Formats'])) {
            foreach ($spec['Formats'] as $formatInfo) {
                list($fieldName, $order, $position) = explode(" ", $formatInfo);

                $data['fields'][$fieldName]['order']    = $order;
                $data['fields'][$fieldName]['position'] = $position;
            }
        }

        // add options for select fields.
        if (isset($spec['Values']) && is_array($spec['Values'])) {
            foreach ($spec['Values'] as $selectInfo) {
                list($fieldName, $options) = explode(" ", $selectInfo);

                $data['fields'][$fieldName]['options'] = explode('/', $options);
            }
        }

        // add default field values.
        if (isset($spec['Presets']) && is_array($spec['Presets'])) {
            foreach ($spec['Presets'] as $defaultInfo) {
                list($fieldName, $default) = explode(" ", $defaultInfo);

                $data['fields'][$fieldName]['default'] = $default;
            }
        }

        // add spec comments to data structure.
        if (isset($spec['Comments']) && is_string($spec['Comments'])) {
            $data['comments'] = $spec['Comments'];
        }

        return $data;
    }

    /**
     * Convert the internal data structure of this class into a 'p4 spec -i'
     * compatible array of comments and field details. See fromSpecArray for
     * data structure format.
     *
     * @param   array   $data   an internal spec definition data structure.
     * @return  array   spec definition array suitable for 'p4 spec -i'.
     */
    protected function toSpecArray($data)
    {
        if (!is_array($data) ||
            !array_key_exists('fields', $data) ||
            !array_key_exists('comments', $data)) {
            throw InvalidArgumentException("Data must be array with fields and comments.");
        }

        $spec = array(
            'Fields'    => array(),
            'Words'     => array(),
            'Formats'   => array(),
            'Values'    => array(),
            'Presets'   => array(),
            'Comments'  => null
        );

        // convert fields back into spec array format.
        foreach ($data['fields'] as $name => $field) {

            $spec['Fields'][] = implode(
                " ",
                array(
                    $field['code'],
                    $name,
                    $field['dataType'],
                    $field['displayLength'],
                    $field['fieldType']
                )
            );

            // only include word count if > 1.
            if (isset($field['wordCount']) && $field['wordCount'] > 1) {
                $spec['Words'][] = implode(" ", array($name, $field['wordCount']));
            }

            if (isset($field['order'], $field['position'])) {
                $spec['Formats'][] = implode(" ", array($name, $field['order'], $field['position']));
            }

            if (isset($field['options']) && is_array($field['options'])) {
                $spec['Values'][] = implode(" ", array($name, implode("/", $field['options'])));
            }

            if (isset($field['default'])) {
                $spec['Presets'][] = implode(" ", array($name, $field['default']));
            }
        }

        // remove empty elements.
        foreach ($spec as $key => $value) {
            if (empty($value)) {
                unset($spec[$key]);
            }
        }

        // add comments to spec array.
        // Perforce will keep existing value if no comments entry is present.
        // We ensure it is at least blank to force an update.
        if (isset($data['comments']) && is_string($data['comments'])) {
            $spec['Comments'] = $data['comments'];
        }

        return $spec;
    }
}
