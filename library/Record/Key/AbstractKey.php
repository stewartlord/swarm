<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Record\Key;

use P4\Connection\ConnectionInterface as Connection;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Key\Key;
use P4\Model\Fielded\FieldedAbstract as FieldedModel;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\OutputHandler\Limit;
use Record\Exception\Exception;
use Record\Exception\NotFoundException;

/**
 * Provides persistent storage and indexing of data using perforce keys
 * and, optionally, perforce index/search functionality.
 */
abstract class AbstractKey extends FieldedModel
{
    /**
     * Key prefix should be akin to 'swarm-type-'
     * Key count should be in a separate namespace e.g. 'swarm-type:count'
     */
    const KEY_PREFIX            = null;
    const KEY_COUNT             = null;

    /**
     * The empty index value will be used for indexed fields with no/empty value.
     * This allows us to do a search for empty values efficiently.
     * Normal values will be hex encoded, the empty value will not and
     * should be selected so it can never collide with hex.
     */
    const EMPTY_INDEX_VALUE     = 'empty';

    const FETCH_SEARCH          = 'search';
    const FETCH_BY_KEYWORDS     = 'keywords';
    const FETCH_KEYWORDS_FIELDS = 'keywordsFields';
    const FETCH_MAXIMUM         = 'maximum';
    const FETCH_AFTER           = 'after';
    const FETCH_BY_IDS          = 'ids';
    const FETCH_TOTAL_COUNT     = 'totalCount';

    protected $p4               = null;
    protected $id               = null;
    protected $original         = null;
    protected $isFromKey        = false;

    /**
     * Define the fields that make up this model.
     *
     * By default we map an id 'field' to getId/setId during construction.
     * If you declare fields in a sub-class, you don't need to redeclare
     * the id field unless you desire different settings.
     *
     * If you want a field to be automatically indexed (so that you
     * can use it in a search expression), set a 'index' property
     * on the field to a unique index code (e.g. 'index' => 1001).
     *
     * @var array   the pre-defined fields that make up this model.
     */
    protected $fields           = array();

    /**
     * Instantiate the model and set the connection to use.
     * Extends parent to automatically add an id field if one
     * hasn't already been defined.
     *
     * @param   Connection  $connection     optional - a connection to use for this instance.
     */
    public function __construct(Connection $connection = null)
    {
        parent::__construct($connection);

        if (!in_array('id', $this->fields) && !isset($this->fields['id'])) {
            $this->fields =
                array(
                    'id'    => array(
                        'accessor'  => 'getId',
                        'mutator'   => 'setId',
                        'unstored'  => true
                    )
                )
                + $this->fields;
        }
    }

    /**
     * Returns the id or null if none set.
     *
     * @return int|string|null
     */
    public function getId()
    {
        return static::decodeId($this->id);
    }

    /**
     * Set an id for this record. If creating a new record you must
     * leave the id blank and allow an auto-incrementing value to be
     * generated on save.
     *
     * @param   int|string|null     $id     the id to use or null for auto
     * @return  AbstractKey         to maintain a fluent interface
     */
    public function setId($id)
    {
        $this->id = strlen($id) ? static::encodeId($id) : null;

        return $this;
    }

    /**
     * Set a field's raw value (avoids mutator).
     * Extended to ensure string values are valid utf8.
     *
     * @param   string  $field      the name of the field to set the value of.
     * @param   mixed   $value      the value to set in the field.
     * @return  AbstractKey         provides a fluent interface
     */
    public function setRawValue($field, $value)
    {
        $utf8  = new Utf8Filter;
        $value = $utf8->filter($value);

        return parent::setRawValue($field, $value);
    }

    /**
     * Retrieves the specified record. Throws if an invalid/unknown
     * id is specified.
     *
     * @param   string|int      $id     the entry id to be retrieved
     * @param   Connection      $p4     the connection to use
     * @return  AbstractKey     to maintain a fluent interface
     * @throws  NotFoundException           on unknown id
     * @throws  \InvalidArgumentException   on badly formatted/typed id
     */
    public static function fetch($id, Connection $p4)
    {
        try {
            return static::keyToModel(Key::fetch(static::encodeId($id), $p4));
        } catch (\P4\Counter\Exception\NotFoundException $e) {
            throw new NotFoundException($e->getMessage());
        }
    }

    /**
     * Verifies if the specified record(s) exists.
     * Its better to call 'fetch' directly in a try block if you will
     * be retrieving the record on success.
     *
     * @param   string|int|array    $id     the entry id or an array of ids to filter
     * @param   Connection          $p4     the connection to use
     * @return  bool|array          true/false for single arg, array of existent ids for array input
     */
    public static function exists($id, Connection $p4)
    {
        // before we muck with things; capture if it's plural or singular mode
        $plural = is_array($id);

        // normalize the input to an array of non-empty encoded ids
        $ids = array_filter((array) $id, 'strlen');
        foreach ($ids as &$id) {
            $id = static::encodeId($id);
        }

        // fetch the potential IDs, do this at key level to save a little object overhead
        // after fetching, translate the key ids back into public ids
        $keys = Key::fetchAll(array(Key::FETCH_BY_IDS => $ids), $p4)->invoke('getId');
        $ids  = array();
        foreach ($keys as $key) {
            $ids[] = static::decodeId($key);
        }

        // return the list of ids or simply a bool
        return $plural ? $ids : count($ids) != 0;
    }

    /**
     * Retrieves all records that match the passed options.
     *
     * @param   array       $options    an optional array of search conditions and/or options
     *                                  supported options are:
     *                                    FETCH_SEARCH - set to a search expression to fetch by.
     *                                                   only indexed fields can be searched and
     *                                                   they must be referred to by index code.
     *                               FETCH_BY_KEYWORDS - set to a string to match against all indexed fields
     *                           FETCH_KEYWORDS_FIELDS - set to a list of fields to limit keywords search
     *                                   FETCH_MAXIMUM - set to integer value to limit to the first
     *                                                   'max' number of entries.
     *                                     FETCH_AFTER - set to an id _after_ which we start collecting
     *                                    FETCH_BY_IDS - provide an array of ids to fetch.
     *                                                   not compatible with FETCH_SEARCH or FETCH_AFTER.
     *                               FETCH_TOTAL_COUNT - valid only for searching, set to true to keep track
     *                                                   of all valid entries (including those skipped due to
     *                                                   FETCH_MAXIMUM or FETCH_AFTER options), number of total
     *                                                   'counted' entries will be set into 'totalCount'
     *                                                   property of the models iterator
     * @param   Connection  $p4         the perforce connection to run on
     * @return  FieldedIterator         the list of zero or more matching record objects
     * @throws  \InvalidArgumentException   invalid combinations of options
     */
    public static function fetchAll(array $options, Connection $p4)
    {
        // normalize options
        $options += array(
            static::FETCH_SEARCH          => null,
            static::FETCH_BY_KEYWORDS     => null,
            static::FETCH_KEYWORDS_FIELDS => null,
            static::FETCH_MAXIMUM         => null,
            static::FETCH_AFTER           => null,
            static::FETCH_BY_IDS          => null,
            static::FETCH_TOTAL_COUNT     => false
        );

        // prepare search expression for keyword search
        if (strlen($options[static::FETCH_BY_KEYWORDS])) {
            $query = array();
            $words = static::splitIntoWords($options[static::FETCH_BY_KEYWORDS]);

            // limit the fields we actually search keywords against either to the set
            // specified in options or, if not specified, to the fields of the model
            $model  = new static;
            $fields = $options[static::FETCH_KEYWORDS_FIELDS] ?: $model->getFields();
            $fields = array_filter((array) $fields, array($model, 'getIndexCode'));

            // make search expression for searching words in given fields
            $queries = array();
            foreach ($words as $word) {
                $query = array();
                foreach ($fields as $field) {
                    // lowercase the word if field is a word index (as they are case-insensitive)
                    $searchValue = $model->isWordIndex($field)
                        ? static::lowercase($word)
                        : $word;
                    $code    = $model->getIndexCode($field);
                    $query[] = $code . '=' . static::encodeIndexValue($searchValue) . '*';
                }
                $queries[] = $query ? '(' . implode(' | ', $query) . ')' : null;
            }
            $options[static::FETCH_SEARCH] .= $queries ? ' (' . implode(' ', $queries) . ')' : '';
        }

        // throw if options are clearly invalid.
        if ($options[static::FETCH_AFTER] && is_array($options[static::FETCH_BY_IDS])) {
            throw new \InvalidArgumentException(
                'It is not valid to pass fetch by ids and also specify fetch after or fetch search.'
            );
        }

        // fetch total count is valid only if searching
        if ($options[static::FETCH_TOTAL_COUNT] && $options[static::FETCH_SEARCH] === null) {
            throw new \InvalidArgumentException(
                'totalCount option may be enabled only if search option is not null'
            );
        }

        // must adjust 'after' constraint to use internal id encoding.
        $options[static::FETCH_AFTER] = static::encodeId($options[static::FETCH_AFTER]);

        // fetch the models:
        // - if a search expression was specified, run a search and return those results
        // - otherwise, add a fetch by name filter to limit the results to only our key prefix
        //   assuming the user hasn't specified explicit IDs to fetch.
        if (strlen($options[static::FETCH_SEARCH])) {
            $models = static::fetchAllBySearch($options, $p4);
        } else {
            if (!is_array($options[static::FETCH_BY_IDS])) {
                $options[Key::FETCH_BY_NAME] = static::KEY_PREFIX . '*';
            }

            $models = static::fetchAllNoSearch($options, $p4);
        }

        // set 'lastSeen' property to indicate the id of the last model fetched
        // this can be useful if the list is later filtered to remove some entries
        $models->setProperty('lastSeen', $models->count() ? $models->last()->getId() : null);

        return $models;
    }

    /**
     * Saves the records values and updates indexes as needed.
     *
     * @return  AbstractKey     to maintain a fluent interface
     */
    public function save()
    {
        // if we have no id attempt to generate one.
        if (!strlen($this->id)) {
            $this->id = $this->makeId();
        }

        // attempt to fetch the currently stored version of this record if one exists.
        try {
            $stored = static::fetch($this->getId(), $this->getConnection());
        } catch (NotFoundException $e) {
            $stored = null;
        }

        // allow extending classes to implement upgrade logic
        $this->upgrade($stored);

        // get raw values to write to storage
        // if we started with a copy from storage, only overwrite fields that have been changed
        // this minimizes race conditions where other processes are updating the same record
        $values   = $this->getRawValues();
        $original = $this->original;
        $stored   = $stored ? $stored->getRawValues() : null;
        if ($original && $stored !== null) {
            $unset = array_diff_key($original, $values);
            foreach ($values as $key => $value) {
                if (array_key_exists($key, $original) && $original[$key] === $value) {
                    unset($values[$key]);
                }
            }
            $values += $stored;
            $values  = array_diff_key($values, $unset);
        }

        // exclude any 'unstored' fields
        $unstored = array();
        foreach ($this->fields as $field => $properties) {
            if (isset($properties['unstored']) && $properties['unstored'] && array_key_exists($field, $values)) {
                $unstored[$field] = $values[$field];
                unset($values[$field]);
            }
        }

        // take care of indexing
        $this->updateIndexedFields($values, $stored);

        // save the actual record data
        $key = new Key($this->getConnection());
        $key->setId($this->id);
        $key->set(
            // follow zend's json::encode approach to options for consistency
            json_encode($values, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
        );

        // update values to reflect the latest merged plus unstored values
        // update original so future saves correctly identify changed fields
        $this->values   = $values + $unstored;
        $this->original = $values;

        return $this;
    }

    /**
     * Deletes the current record and attempts to remove indexes.
     *
     * @return  AbstractKey     to maintain a fluent interface
     */
    public function delete()
    {
        // attempt to fetch the currently stored version of this record if one exists.
        try {
            $stored = static::fetch($this->getId(), $this->getConnection())->getRawValues();
        } catch (NotFoundException $e) {
            $stored = null;
        }

        // remove the indices first (once the key value is
        // gone we lose the data we need to clear indices)
        $this->updateIndexedFields(null, $stored);

        // now nuke the key - avoid fetch to skip a redundant exists call.
        $key = new Key($this->getConnection());
        $key->setId($this->id)
            ->delete();

        return $this;
    }

    /**
     * Hook for implementing upgrade logic in concrete records.
     * This method is called near the beginning of save(), just after we fetch the old record.
     *
     * @param   AbstractKey|null    $stored     an instance of the old record from storage or null if adding
     */
    protected function upgrade(AbstractKey $stored = null)
    {
        // nothing to do here -- concrete classes may extend to implement upgrades
    }

    /**
     * This method should be called just prior to saving out new values.
     * It will de-index the currently stored index fields for this record
     * and save new indexes for the passed values.
     *
     * @param   array       $values     an array of new values
     * @param   array|null  $stored     an array of old values
     * @return  AbstractKey             to maintain a fluent interface
     */
    protected function updateIndexedFields($values, array $stored = null)
    {
        $values = (array) $values;
        $isAdd  = !is_array($stored);

        // if its an add, pass false as default 'old' value to indicate de-indexing can be skipped
        // if its an edit, we pass null as default 'old' value to indicate the old value was empty
        $oldDefault = $isAdd ? false : null;

        // we now want to update our index fields which takes a few steps:
        // - loop each field skipping any that lack 'index codes'
        // - calculate the new and old values
        // - if its an add or the values differ, update index
        foreach ($this->getFields() as $field) {
            $code = $this->getIndexCode($field);
            if ($code !== false) {
                $new = isset($values[$field]) ? $values[$field] : null;
                $old = isset($stored[$field]) ? $stored[$field] : $oldDefault;

                // if this is an add or the value has changed, update index
                if ($isAdd || $new !== $old) {
                    $this->index($code, $field, $new, $old);
                }
            }
        }

        return $this;
    }

    /**
     * Index this record under a given name with given value(s).
     * This makes it easy for us to find the record in the future
     * by searching the named index for the given values.
     *
     * @param   int                     $code   the index code/number of the field
     * @param   string                  $name   the field/name of the index
     * @param   string|array|null       $value  one or more values to index
     * @param   string|array|null|false $remove one or more old values that need to be de-indexed
     *                                          pass false if this is an add and de-index can be skipped.
     * @return  AbstractKey     provides fluent interface
     * @throws  \Exception      if no id has been set
     */
    protected function index($code, $name, $value, $remove)
    {
        if (!strlen($this->getId())) {
            throw new \Exception('Cannot index, no ID has been set.');
        }

        // split $value into lowercase words if configured to index individual words
        if ($this->isWordIndex($name)) {
            $value  = static::splitIntoWords($value,  true);
            $remove = static::splitIntoWords($remove, true);
        }

        // flatten associative arrays into 'key:value' strings if indexFlatten is set
        if (isset($this->fields[$name]['indexFlatten']) && $this->fields[$name]['indexFlatten']) {
            $value  = static::flattenForIndex((array) $value);
            $remove = $remove !== false ? static::flattenForIndex((array) $remove) : false;
        }

        // only index keys (ignore values) if indexOnlyKeys is set
        if (isset($this->fields[$name]['indexOnlyKeys']) && $this->fields[$name]['indexOnlyKeys']) {
            $value  = array_keys((array) $value);
            $remove = $remove !== false ? array_keys((array) $remove) : $remove;
        }

        // if old indices were specified filter out null or empty values
        // and de-index anything left over.
        if ($remove !== false) {
            $remove = array_filter((array) $remove, 'strlen');

            // encode the value(s) for index removal
            $remove = array_map(array($this, 'encodeIndexValue'), $remove);

            // if no old values are present, de-index the empty value
            // this isn't encoded to avoid collisions with actual entries.
            $remove = $remove ?: array(static::EMPTY_INDEX_VALUE);

            // remove the index - note we need to use numeric codes for the name
            $this->getConnection()->run(
                'index',
                array('-a', $code, '-d', $this->id),
                implode(' ', $remove)
            );
        }

        // add indexes for all non-empty/null values in our new version
        $value = array_filter((array) $value, 'strlen');

        // encode the value(s) for index storage - we need to do this because
        // we want literal matches and the indexer splits on hyphens, etc.
        $value = array_map(array($this, 'encodeIndexValue'), $value);

        // if no values are present, index the empty value this
        // isn't encoded to avoid collisions with actual entries.
        $value = $value ?: array(static::EMPTY_INDEX_VALUE);

        // write the index - note we need to use numeric codes for the name
        $this->getConnection()->run(
            'index',
            array('-a', $code, $this->id),
            implode(' ', $value)
        );

        return $this;
    }

    /**
     * Return $value split into unique words suitable for indexing or searching.
     * If $value is array of strings, return list of all unique words from all
     * values of the array.
     *
     * @param   null|false|string|array     $value      string or list of strings to split into words
     * @return  boolean                     $lowercase  optional - whether to also lowercase words (false by default)
     * @param   bool                        $trim       optional - whether to trim the words (true by default), we need
     *                                                  this for upgrading purposes to get the words in the 'old' way
     * @param   null|false|string|array     $value      string or list of strings to split into words
     */
    protected static function splitIntoWords($value, $lowercase = false, $trim = true)
    {
        // do nothing if passed null or false
        if ($value === null || $value === false) {
            return $value;
        }

        $words = array();
        foreach ((array) $value as $string) {
            $candidates = preg_split('/[\s,\.]+/', $string);
            foreach ($candidates as $word) {
                // trim the word if requested - remove the leading and trailing punctuation, parenthesis, etc.
                $words[] = $trim ? trim($word, '`”’"\'!?*~:;_()<>[]{}') : $word;
            }
        }

        // remove duplicates and empty words
        $words = array_unique(array_filter($words, 'strlen'));

        return $lowercase ? array_map(array(get_class(), 'lowercase'), $words) : $words;
    }

    /**
     * Convert associative array into a list of strings with 'key:value'
     * as a preparation for indexing. If value is array with values
     * val1, val2,... then output list will contain all strings
     * 'key:val1', 'key:val2', etc.
     *
     * @param   array   $array  array value to flatten
     * @return  array           input value converted to a list
     *                          of 'key:value' strings
     */
    protected static function flattenForIndex(array $array)
    {
        $result = array();
        foreach ($array as $key => $values) {
            // include all non-empty values
            $values = array_filter((array) $values, 'strlen');
            foreach ($values as $value) {
                $result[] = $key . ':' . $value;
            }
        }

        return $result;
    }

    /**
     * Convert given string to lowercase using UTF-8 safe conversion if possible.
     *
     * @param   string  $value  string to lowercase
     * @return  string          lowercase value
     */
    protected static function lowercase($value)
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    /**
     * Returns the index code to use for a specified field.
     *
     * @param   string      $field  the field name to find a code for
     * @return  int|bool    the code for the requested field or false
     */
    protected function getIndexCode($field)
    {
        if (isset($this->fields[$field]['index'])
            && ctype_digit($this->fields[$field]['index'])
        ) {
            return (int) $this->fields[$field]['index'];
        }

        return false;
    }

    /**
     * Check whether given field is configured to index individual words (returns true)
     * or not (returns false).
     *
     * @param   string  $field  the field name to check for indexing words
     * @return  bool    true if field is configured to index words, false otherwise
     */
    protected function isWordIndex($field)
    {
        return isset($this->fields[$field])
            && is_array($this->fields[$field])
            && isset($this->fields[$field]['indexWords'])
            && $this->fields[$field]['indexWords'];
    }

    /**
     * Breaks out the case of fetching by doing a 'p4 search' and then populating
     * the resulting records. Options such as max/after will still be honored.
     *
     * @param  array            $options    normalized fetch options (e.g. search/max/after)
     * @param  Connection       $p4         the perforce connection to run on
     * @return FieldedIterator  the list of zero or more matching record objects
     */
    protected static function fetchAllBySearch(array $options, Connection $p4)
    {
        // pull out search/max/after/countAll
        $max            = $options[static::FETCH_MAXIMUM];
        $after          = $options[static::FETCH_AFTER];
        $search         = $options[static::FETCH_SEARCH];
        $countAll       = $options[static::FETCH_TOTAL_COUNT];
        $params         = array($search);
        $isAfter        = false;

        // if we are not counting all and we have a max but no after
        // we can use -m on new enough servers as an optimization
        if (!$countAll && $max && !$after && $p4->isServerMinVersion('2013.1')) {
            array_unshift($params, $max);
            array_unshift($params, '-m');
        }

        // setup an output handler to ensure max and after are honored
        $prefix  = static::KEY_PREFIX;
        $handler = new Limit;
        $handler->setMax($max)
            ->setCountAll($countAll)
            ->setFilterCallback(
                function ($data) use ($after, &$isAfter, $prefix) {
                    // be defensive, exclude any ids that lack our key prefix
                    if (strpos($data, $prefix) !== 0) {
                        return Limit::FILTER_EXCLUDE;
                    }

                    if ($after && !$isAfter) {
                        $isAfter = $after == $data;
                        return Limit::FILTER_SKIP;
                    }

                    return Limit::FILTER_INCLUDE;
                }
            );

        $ids    = $p4->runHandler($handler, 'search', $params)->getData();
        $keys   = Key::fetchAll(array(Key::FETCH_BY_IDS => $ids), $p4);
        $models = new FieldedIterator;
        foreach ($keys as $key) {
            $model = static::keyToModel($key);
            $models[$model->getId()] = $model;
        }

        // if caller asks for total count, set 'totalCount' property on the iterator
        // total count also includes entries skipped by the output handler and thus
        // this number may be different from number of entries in the iterator
        if ($countAll) {
            $models->setProperty('totalCount', $handler->getTotalCount());
        }

        return $models;
    }

    /**
     * Breaks out the case of fetching everything sans 'p4 search' filters.
     * We could still have options for max, after and our name filter will
     * be present to limit the returned counter results.
     *
     * @param   array           $options    a normalized array of filters
     * @param   Connection      $p4         the perforce connection to run on
     * @return  FieldedIterator the list of zero or more matching record objects
     */
    protected static function fetchAllNoSearch(array $options, Connection $p4)
    {
        // if ids were specified, encode them so key fetchall knows what to do
        foreach ((array) $options[static::FETCH_BY_IDS] as $key => $id) {
            $options[static::FETCH_BY_IDS][$key] = static::encodeId($options[static::FETCH_BY_IDS][$key]);
        }

        $keys   = Key::fetchAll($options, $p4);
        $models = new FieldedIterator;
        foreach ($keys as $key) {
            $model = static::keyToModel($key);
            $models[$model->getId()] = $model;
        }

        return $models;
    }

    /**
     * Turn the passed key into a record.
     *
     * If a callable is passed for the optional class name param it will be passed the
     * raw record data and the key object. The callable is expected to return the class
     * name to be used or null to fallback to static.
     *
     * @param   Key             $key        the key to 'record'ize
     * @param   string|callable $className  optional - class name to use, static by default
     * @return  AbstractKey     the record based on the passed key's data
     */
    protected static function keyToModel($key, $className = null)
    {
        // get the value from the key and json decode to an array
        $data       = json_decode($key->get(), true);

        // determine the class we are instantiating
        $className  = is_callable($className) ? $className($data, $key) : $className;
        $className  = $className ?: get_called_class();

        // actually instantiate and setup the model
        $model      = new $className($key->getConnection());
        $model->setRawValues((array) $data);
        $model->id  = $key->getId();

        // we want to record the original values that we fetched
        // so that we can determine what has changed on save
        $model->original = (array) $data;

        // record the fact that this model was generated from a key
        // most likely this implies it came from storage.
        $model->isFromKey = true;

        return $model;
    }

    /**
     * Takes a friendly id (e.g. 2) and encodes it to the actual storage id
     * used for the underlying key (e.g. swarm-type-00000002)
     *
     * If a KEY_COUNT is defined for this model numeric IDs will be 0 padded
     * to 10 digits. For model's lacking a KEY_COUNT we assume you want
     * manually selected IDs and skip padding. Non numeric ids won't be padded
     * regardless.
     *
     * @param   string|int  $id     the user facing id
     * @return  string      the stored id used by p4 key
     */
    protected static function encodeId($id)
    {
        // just leave null enough alone
        if (!strlen($id)) {
            return $id;
        }

        // if we have a KEY_COUNT and its a purley numeric ID, pad it!
        if (static::KEY_COUNT && $id == (string) (int) $id) {
            $id = str_pad($id, 10, '0', STR_PAD_LEFT);
        }

        // prefix and return regardless of padding needs
        return static::KEY_PREFIX . $id;
    }

    /**
     * Takes a storage id used for the underlying key and turns it into
     * a friendly id for external consumption.
     *
     * If the ID appears to be 0 padded and we have a KEY_COUNT we'll
     * return an int cast version which ends up stripping off the leading
     * zeros (which we most likely put there to start with).
     *
     * If this model is configured with a key prefix, but the given id
     * does not have a matching prefix, we can't decode it and return null.
     *
     * @param   string  $id     the stored id used by p4 key
     * @return  string|int      the user facing id
     */
    protected static function decodeId($id)
    {
        // just leave null enough alone
        if ($id === null) {
            return $id;
        }

        // if we have a key prefix, but id does not start with it, return null
        $prefix = static::KEY_PREFIX;
        if ($prefix && strpos($id, $prefix) !== 0) {
            return null;
        }

        // always need to strip the prefix
        $id = substr($id, strlen($prefix));

        // if we have a key prefix and a 10 digit numeric id,
        // int cast it which effectively removes the leading 0's
        if ($prefix && strlen($id) == 10 && $id == (string) (int) $id) {
            $id = (int) $id;
        }

        return $id;
    }

    /**
     * Called when an auto-generated ID is required for an entry.
     *
     * @return  string  a new auto-generated id. the id will be 'encoded'.
     * @throws  Exception   if called when no KEY_COUNT has been specified for the model.
     */
    protected function makeId()
    {
        // if we lack a KEY_COUNT we can't do much so blow up
        if (!static::KEY_COUNT) {
            throw new Exception(
                'Cannot generate an auto-incrementing id. No key count has been set.'
            );
        }

        // get an auto-incrementing id via our 'count' counter
        $key = new Key($this->getConnection());
        $key->setId(static::KEY_COUNT);
        $id = $key->increment();

        return static::encodeId($id);
    }

    /**
     * Encodes the passed index value. This is needed to avoid having
     * the value break on word boundaries (e.g. the '-' character) and
     * produce un-intended sub matches.
     *
     * @param   string  $value  the raw value
     * @return  string  an encoded version of the value
     */
    protected static function encodeIndexValue($value)
    {
        return strtoupper(bin2hex($value));
    }

    /**
     * Decodes the passed index value.
     *
     * @param   string  $value  the encoded index value
     * @return  string  a decoded version of the value
     */
    protected static function decodeIndexValue($value)
    {
        return pack('H*', $value);
    }

    /**
     * Produces a 'p4 search' expression for the given field/value pairs.
     *
     * The conditions array should contain 'indexed' field names as
     * keys and the strings or array of strings to search for as values.
     * Array values will be converted into an OR-joined conditions
     * and appended to the expression as a sub-query.
     *
     * @param   array   $conditions     field/value pairs to search for
     * @return  string  a query expression suitable for use with p4 search
     */
    protected static function makeSearchExpression($conditions)
    {
        $query = "";
        $model = new static;
        foreach ($conditions as $field => $value) {
            $code = $model->getIndexCode($field);
            // skip non-indexed fields
            if ($code === false) {
                continue;
            }

            // normalize value to an array and remove empty/null entries
            $values = array_filter((array) $value, 'strlen');

            // lowercase values if field is a word index (as they are case-insensitive)
            if ($model->isWordIndex($field)) {
                $values = array_map(array(get_class(), 'lowercase'), $values);
            }

            // encode the value(s) to compare with indexed data
            $values = array_map(array(get_class(), 'encodeIndexValue'), $values);

            // if normalization results in no search values and we weren't
            // specifically passed false, nothing to do for this one.
            if (!$values && $value !== false) {
                continue;
            }

            // if we made it here and values is false; must have been
            // passed false. Use the empty value.
            $values = $values ?: array(static::EMPTY_INDEX_VALUE);

            // turn our values array into conditions using the field's code
            $conditions = array();
            foreach ($values as $value) {
                $conditions[] = $code . '=' . $value;
            }
            $expression = implode(' | ', $conditions);
            $query .= count($conditions) > 1 ? '(' . $expression . ') ' : $expression . ' ';
        }

        return trim($query);
    }
}
