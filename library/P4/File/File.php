<?php
/**
 * Abstracts operations against Perforce files.
 *
 * THEORY OF OPERATION
 *
 * Unlike a typical database, all changes to Perforce files must be pended to
 * the current client workspace before they can be committed.
 *
 * The file model provides access to two copies of file data: the submitted
 * depot copy and the client workspace copy. When you are accessing file data
 * (be it file contents or file attributes), you must consider which of these
 * sources you want to get the data from.
 *
 * For example, if you call getDepotContents() you will get the submitted depot
 * copy of the file; whereas, if you call getLocalContents() you will get the
 * contents of the client workspace file.
 *
 * The class attempts to faithfully represent the behavior of Perforce. There
 * is, however, some simplification at work. In particular, the open() method
 * will automatically add or edit a file as appropriate. It will also sync the
 * file to the client if necessary.
 *
 * Similarly, if a file is open for delete, the add, edit and open methods will
 * revert the file and reopen it. Conversely, if delete() is called on a file
 * that is opened (but not for delete), the file will be reverted and then
 * deleted(). To suppress this behavior, pass false as the force option.
 *
 *
 * COMMON USAGE
 *
 * To fetch a file from Perforce, call fetch() and pass the filespec of the
 * file you wish to retrieve. For example:
 *
 *  $file = \P4\File\File::fetch('//depot/file');
 *
 * To fetch several files, call fetchAll() and pass a file query object
 * representing the fstat options that you wish to use. For example:
 *
 *  $files = \P4\File\File::fetchAll(
 *      new \P4\File\Query(array('filespecs' => '//depot/path/...'))
 *  );
 *
 * The query class also has options to filter, sort and limit files. See the
 * \P4\File\Query class for additional details.
 *
 * To submit a file:
 *
 *   $file = new P4\File\File;
 *   $file->setFilespec('//depot/file');
 *   $file->open();
 *   $file->setLocalContents('new file content');
 *   $file->submit('Description of change');
 *
 * To delete a file:
 *
 *   $file->delete();
 *   $file->submit('Description of change');
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 * @todo        make fluent.
 * @todo        give submit a clobber option.
 * @todo
 *   diff($file)
 *   getFixes()
 *   integrate()
 *   getIntegrations()
 *   getInterchanges()
 *   getLabels()
 *   getProtections()
 *   move()
 *   getReviewers()
 *   getSize()
 *   tag($rev)
 *   untag($rev)
 */

namespace P4\File;

use P4;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Validate;
use P4\Spec\Change;
use P4\File\Exception\Exception;
use P4\File\Exception\NotFoundException;
use P4\Connection\ConnectionInterface;
use P4\Connection\Exception\CommandException;
use P4\Connection\Exception\ConflictException;
use P4\Model\Resolvable\ResolvableInterface;
use P4\Model\Connected\ConnectedAbstract;
use P4\Model\Fielded\FieldedInterface;
use P4\Model\Fielded\Iterator as FieldedIterator;
use P4\OutputHandler\Limit;

class File extends ConnectedAbstract implements FieldedInterface, ResolvableInterface
{
    const       ALL_FILES           = '//...';
    const       MAX_FILESIZE        = 'maxSize';
    const       REVERT_UNCHANGED    = 'unchanged';

    const       UTF8_CONVERT        = 'convert';
    const       UTF8_SANITIZE       = 'sanitize';

    const       ANNOTATE_CHANGES    = 'changes';
    const       ANNOTATE_INTEG      = 'integ';
    const       ANNOTATE_CONTENT    = 'content';

    protected $cache                = array();
    protected $filespec             = null;

    /**
     * Implement FieldedInterface.
     * Get the file info as an array.
     *
     * @return  array   the file info as an array.
     */
    public function toArray()
    {
        $values = array();
        foreach ($this->getFields() as $field) {
            $values[$field] = $this->get($field);
        }

        return $values;
    }

    /**
     * Implement FieldedInterface.
     * Check if given field is valid model field.
     *
     * @param  string  $field  model field to check
     * @return boolean
     */
    public function hasField($field)
    {
        return $this->hasStatusField($field);
    }

    /**
     * Implement FieldedInterface.
     * Return array with all model fields.
     *
     * @return array
     */
    public function getFields()
    {
        return array_keys($this->getStatus());
    }

    /**
     * Implement FieldedInterface.
     * Return value of given field of the model.
     *
     * @param  string  $field  model field to retrieve
     * @return mixed
     */
    public function get($field)
    {
        return $this->getStatus($field);
    }

    /**
     * Set the filespec identifier for the file/revision.
     * Filespec may be given in depot, client or local file-system
     * syntax. The filename may be followed by a revision specifier.
     * Wildcards are not permitted in the filespec.
     *
     * For more information on filespecs visit:
     * http://perforce.com/perforce/doc.current/manuals/cmdref/o.fspecs.html
     *
     * Note: The instance cache is cleared when the filespec changes.
     *
     * @param   string  $filespec   the filespec of the file.
     * @return  File    provide fluent interface.
     */
    public function setFilespec($filespec)
    {
        static::validateFilespec($filespec);
        $this->filespec = $filespec;

        // identity has changed - clear all of the instance caches.
        $this->cache = array();

        return $this;
    }

    /**
     * Get the filespec used to identify this file.
     * If a revision specifier was passed to setFilespec or fetch, it
     * will be returned here; otherwise, no revision specifier will
     * be present.
     *
     * @param   bool    $stripRevspec   optional - revspecs will be removed, if present, when true
     * @return  string  the filespec of the file.
     */
    public function getFilespec($stripRevspec = false)
    {
        return $stripRevspec ? static::stripRevspec($this->filespec) : $this->filespec;
    }

    /**
     * Get the filespec used to identify this file including
     * a revision specification if one is known.
     *
     * If getFilespec includes a revspec, this value is used.
     * Otherwise, if we have fetched file contents or status
     * the corresponding numeric revision is used.
     *
     * @return  string  the filespec with a revision specifier if one is known.
     */
    public function getFilespecWithRevision()
    {
        $filespec = $this->getFilespec();

        if ($filespec === null || static::hasRevspec($filespec)) {
            return $filespec;
        }

        $revision = '';
        if (isset($this->cache['revision'])) {
            $revision = '#' . $this->cache['revision'];
        }

        return $this->filespec . $revision;
    }

    /**
     * Get the revision specifier of this file.
     *
     * If getFilespec includes a revspec, this value is used.
     * Otherwise, if we have fetched file contents or status
     * the corresponding numeric revision is used.
     *
     * @return  string  the revspec of the file.
     */
    public function getRevspec()
    {
        return static::extractRevspec($this->getFilespecWithRevision());
    }

    /**
     * If any of the characters @#%* occur in a filename they will be
     * encoded as %40 %23 %25 %2A respectively when using depot or client
     * syntax. If these files are synced to the local disc p4api will
     * automatically unescape the filename. Running a depot path through
     * this method will provide the unescaped filename as it would appear
     * on local disc.
     *
     * @param   string  $filespec   the filespec to decode
     * @return  string  the decoded filespec
     */
    public static function decodeFilespec($filespec)
    {
        return rawurldecode($filespec);
    }

    /**
     * Fetch a model of the given filespec.
     *
     * @param   string  $filespec       a filespec with no wildcards - the filespec may
     *                                  be in any one of depot, client or local file syntax.
     * @param   ConnectionInterface     $connection  optional - a specific connection to use.
     * @param   bool    $excludeDeleted optional - exclude deleted files (defaults to false).
     */
    public static function fetch($filespec, ConnectionInterface $connection = null, $excludeDeleted = false)
    {
        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // determine whether the file exists.
        $info = self::exists($filespec, $connection, $excludeDeleted);
        if ($info === false) {
            throw new NotFoundException(
                "Cannot fetch file '$filespec'. File does not exist."
            );
        }

        // create new file instance and set the key.
        $file = new static($connection);
        $file->setFilespec($filespec);
        $file->_cache['revision']  = isset($info['rev'])       ? $info['rev']       : null;
        $file->_cache['depotFile'] = isset($info['depotFile']) ? $info['depotFile'] : null;

        return $file;
    }

    /**
     * Fetch all files matching the given query.
     *
     * @param   Query|array                 $query          A query object or array expressing fstat options.
     * @param   ConnectionInterface         $connection     optional - a specific connection to use.
     * @return  FieldedIterator             List of retrieved files.
     * @throws  \InvalidArgumentException   if no filespec is given.
     */
    public static function fetchAll($query, ConnectionInterface $connection = null)
    {
        if (!$query instanceof Query && !is_array($query)) {
            throw new \InvalidArgumentException(
                'Query must be a P4\File\Query or array.'
            );
        }

        // normalize array input to a query
        if (is_array($query)) {
            $query = new Query($query);
        }

        // ensure caller provided a filespec.
        if (!count($query->getFilespecs())) {
            throw new \InvalidArgumentException(
                'Cannot fetch files. No filespecs provided in query.'
            );
        }

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // get fstat flags for given query options and run fstat command.
        $flags = array_merge($query->getFstatFlags(), $query->getFilespecs());

        // check server version to see if attribute sort is supported
        if (in_array('-S', $flags) && !$connection->isServerMinVersion('2011.1')) {
            throw new Exception('Cannot sort by attributes for server versions < 2011.1');
        }

        // try/catch parent to deal with the exception we get on non-existend depots
        try {
            $result = $connection->run('fstat', $flags);
        } catch (CommandException $e) {
            // if the 'depot' has been interpreted as an invalid client, just return no matches
            if (preg_match("/Command failed: .+ - must refer to client/", $e->getMessage())) {
                return new FieldedIterator;
            }

            // unexpected error; rethrow it
            throw $e;
        }

        // if fetching by change, the last block of data contains
        // the change description - remove it (unless we're fetching
        // from the default changelist)
        $dataBlocks = $result->getData();
        if ($query->getLimitToChangelist() !== null
            && $query->getLimitToChangelist() !== Change::DEFAULT_CHANGE) {
            array_pop($dataBlocks);
        }

        // generate file models from fstat output.
        $files = new FieldedIterator;
        foreach ($dataBlocks as $data) {
            $file = new static($connection);
            $file->setFilespec($data['depotFile']);
            $file->setStatusCache($data);

            $files[] = $file;
        }

        return $files;
    }

    /**
     * Count files matching the given query.
     * This is a faster alternative to counting the result of fetchAll().
     *
     * @param   Query|array             $query          A query object or array expressing fstat options.
     * @param   ConnectionInterface     $connection     optional - a specific connection to use.
     * @return  FieldedIterator         count of matching files.
     * @todo    optimize to only fetch a single field per file.
     */
    public static function count($query, ConnectionInterface $connection = null)
    {
        if (!$query instanceof Query && !is_array($query)) {
            throw new \InvalidArgumentException(
                'Query must be a P4\File\Query or array.'
            );
        }

        // normalize array input to a query
        if (is_array($query)) {
            $query = new Query($query);
        }

        // ensure caller provided a filespec.
        if (!count($query->getFilespecs())) {
            throw new \InvalidArgumentException(
                'Cannot count files. No filespecs provided in query.'
            );
        }

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // remove options that cause unnecessary work for the server
        $query = clone $query;
        $query->setSortBy(null)->setReverseOrder(false);

        // only fetch a single field for performance.
        $query->setLimitFields('depotFile');

        // get fstat flags for given query and run fstat command.
        $flags  = array_merge($query->getFstatFlags(), $query->getFilespecs());
        $result = $connection->run('fstat', $flags);
        $count  = count($result->getData());

        // if fetching by change, the last block of data contains
        // the change description - remove it (unless we're fetching
        // from the default changelist)
        if ($query->getLimitToChangelist() !== null
            && $query->getLimitToChangelist() !== Change::DEFAULT_CHANGE
        ) {
            $count--;
        }

        return $count;
    }

    /**
     * Check if the given filespec is known to Perforce.
     *
     * @param   string                  $filespec           a filespec with no wildcards.
     * @param   ConnectionInterface     $connection         optional - a specific connection to use.
     * @param   bool                    $excludeDeleted     optional - exclude deleted files (defaults to false).
     * @return  bool|array              info about the file or false if filespec doesn't exist
     */
    public static function exists($filespec, ConnectionInterface $connection = null, $excludeDeleted = false)
    {
        static::validateFilespec($filespec);

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // run files to see if file exists.
        try {
            $result = $connection->run('files', $filespec);
        } catch (CommandException $e) {
            if (strpos($e->getMessage(), ' - must refer to client')) {
                return false;
            }
            throw $e;
        }
        if ($result->hasWarnings()) {
            return false;
        } elseif ($excludeDeleted && strstr($result->getData(-1, 'action'), 'delete') !== false) {
            return false;
        } else {
            // grab the last block - can get multiple files if overlay mappings in use.
            $info = $result->getData(-1);

            // this really shouldn't happen; just being defensive
            if (!is_array($info) || !$info) {
                throw new Exception('Failed to capture file info during existence test');
            }

            return $info;
        }
    }

    /**
     * Check if the given filespec is a directory known to Perforce.
     *
     * @param   string                  $filespec           a filespec with no wildcards.
     * @param   ConnectionInterface     $connection         optional - a specific connection to use.
     * @param   bool                    $excludeDeleted     optional - exclude deleted files (defaults to false).
     * @return  bool|int                head revision number or false if filespec doesn't exist
     */
    public static function dirExists($filespec, ConnectionInterface $connection = null)
    {
        static::validateFilespec($filespec);

        // if no connection given, use default.
        $connection = $connection ?: static::getDefaultConnection();

        // run files to see if file exists.
        $result = $connection->run('dirs', $filespec);

        return $result->getData(0, 'dir') == $filespec;
    }

    /**
     * Open file for add or edit as appropriate.
     *
     * If the file is open for delete, revert and edit unless force=false.
     * Will sync the file before opening it for edit.
     *
     * @param   int     $change     optional - a numbered pending change to open the file in.
     * @param   string  $fileType   optional - the file-type to open the file as.
     * @param   bool    $force      optional - defaults to true - reverts files that are
     *                              open for delete then reopens them. if false, files that are
     *                              open for delete will result in an exception being thrown.
     * @return  File    provide fluent interface.
     */
    public function open($change = null, $fileType = null, $force = true)
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        // add the file if it doesn't exist or is deleted at head - otherwise edit.
        if (!static::exists($this->getFilespecWithRevision(), $this->getConnection()) ||
            $this->getStatus('headAction') == 'delete') {
            $this->add($change, $fileType);
        } else {
            $this->sync(true);
            $this->edit($change, $fileType, $force);
        }

        return $this;
    }

    /**
     * Open this file for delete.
     *
     * If the file is open, but not for delete, the file will be
     * reverted and then deleted unless the force flag has been
     * set to false.
     *
     * @param   int     $change     optional - a numbered pending change to open the file in.
     * @param   bool    $force      optional - defaults to true - reverts files that are
     *                              open then deletes them. if false, files that are
     *                              open (not for delete) will result in an exception
     *                              being thrown.
     * @return  File    provide fluent interface.
     */
    public function delete($change = null, $force = true)
    {
        return $this->openForAction('delete', $change, null, $force);
    }

    /**
     * Delete the local file from the workspace.
     *
     * @throws  Exception   if the local file cannot be deleted.
     * @return  File        provide fluent interface.
     */
    public function deleteLocalFile()
    {
        $localFile = $this->getLocalFilename();
        if (!file_exists($localFile)) {
            throw new Exception("Cannot delete local file. File does not exist.");
        }
        chmod($localFile, 0777);
        if (unlink($localFile) === false) {
            throw new Exception("Failed to delete local file.");
        }

        return $this;
    }

    /**
     * Open the file for add.
     *
     * @param   int     $change     optional - a numbered pending change to open the file in.
     * @param   string  $fileType   optional - the file-type to open the file as.
     * @return  File    provides fluent interface.
     */
    public function add($change = null, $fileType = null)
    {
        return $this->openForAction('add', $change, $fileType, false);
    }

    /**
     * Open the file for edit.
     *
     * If the file is opened for delete, the file will be reverted
     * and then edited unless the force flag has been set to false.
     *
     * @param   int     $change     optional - a numbered pending change to open the file in.
     * @param   string  $fileType   optional - the file-type to open the file as.
     * @param   bool    $force      optional - defaults to true - set to false to avoid reopening.
     * @return  File    provide fluent interface.
     * @todo    make force work against branch/delete, etc.
     */
    public function edit($change = null, $fileType = null, $force = true)
    {
        // If our 'have' rev and our 'head' revision aren't the
        // same value throw an exception (caller needs to sync).
        if (!$this->hasStatusField('haveRev')
            || $this->getStatus('headRev') != $this->getStatus('haveRev')
        ) {
            throw new Exception(
                'Workspace file is not at specified revision; unable to edit'
            );
        }

        return $this->openForAction('edit', $change, $fileType, $force);
    }

    /**
     * Flush the file - tells the server we have the file.
     *
     * @return  File        provide fluent interface.
     * @throws  Exception   if the flush fails.
     */
    public function flush()
    {
        return $this->sync(false, true);
    }

    /**
     * Resolves the file based on the passed option(s).
     *
     * You must specify one of the below:
     *  RESOLVE_ACCEPT_MERGED
     *   Automatically accept the Perforce-recom mended file revision:
     *   if theirs is identical to base, accept yours; if yours is identical
     *   to base, accept theirs; if yours and theirs are different from base,
     *   and there are no conflicts between yours and theirs; accept merge;
     *   other wise, there are conflicts between yours and theirs, so skip this file.
     *  RESOLVE_ACCEPT_YOURS
     *   Accept Yours, ignore theirs.
     *  RESOLVE_ACCEPT_THEIRS
     *   Accept Theirs. Use this flag with caution!
     *  RESOLVE_ACCEPT_SAFE
     *   Safe Accept. If either yours or theirs is different from base,
     *   (and the changes are in common) accept that revision. If both
     *   are different from base, skip this file.
     *  RESOLVE_ACCEPT_FORCE
     *   Force Accept. Accept the merge file no matter what. If the merge file
     *   has conflict markers, they will be left in, and you'll need to remove
     *   them by editing the file.
     *
     * Additionally, one of the following whitespace options can, optionally, be passed:
     *  IGNORE_WHITESPACE_CHANGES
     *   Ignore whitespace-only changes (for instance, a tab replaced by eight spaces)
     *  IGNORE_WHITESPACE
     *   Ignore whitespace altogether (for instance, deletion of tabs or other whitespace)
     *  IGNORE_LINE_ENDINGS
     *   Ignore differences in line-ending convention
     *
     * @param   array|string    $options    Resolve option(s); must include a RESOLVE_* preference.
     * @return  File            provide fluent interface.
     * @todo implement a way to accept edit
     */
    public function resolve($options)
    {
        if (is_string($options)) {
            $options = array($options);
        }

        if (!is_array($options)) {
            throw new \InvalidArgumentException('Expected a string or array of options.');
        }

        // limit the resolve to just our file and let change do the work
        $options[Change::RESOLVE_FILE] = $this->getFilespec(true);
        $this->getChange()->resolve($options);

        return $this;
    }

    /**
     * Used to check if the file requires resolve or not. This function
     * will return true only when a resolve is scheduled. It doesn't attempt to
     * look at the current state and estimate if calling 'submit' would result in
     * an unresolved exception.
     *
     * @return  bool    true if file is resolved, false otherwise
     */
    public function needsResolve()
    {
        $this->validateHasFilespec();

        $result = $this->getConnection()->run(
            'resolve',
            '-n',
            $this->getFilespecWithRevision()
        );

        return (bool) $result->hasData();
    }

    /**
     * Check if the file has the named attribute.
     *
     * @param   string  $attribute  the name of the attribute to check for.
     * @return  bool    true if the file has an attribute with this name.
     */
    public function hasAttribute($attribute)
    {
        return array_key_exists($attribute, $this->getAttributes());
    }

    /**
     * Check if the file has the named open attribute.
     *
     * @param   string  $attribute  the name of the open attribute to check for.
     * @return  bool    true if the file has an open attribute with this name.
     */
    public function hasOpenAttribute($attribute)
    {
        return array_key_exists($attribute, $this->getOpenAttributes());
    }

    /**
     * Get all submitted attributes of this file.
     * Submitted attributes are attributes that have been committed to the depot.
     *
     * @param   bool    $open   optional - get open attributes - defaults to false.
     * @return  array   all attributes of the file.
     */
    public function getAttributes($open = false)
    {
        $attributes = array();
        foreach ($this->getStatus() as $field => $value) {
            if (!$open && substr($field, 0, 5) == 'attr-') {
                $attributes[substr($field, 5)] = $value;
            } elseif ($open && substr($field, 0, 9) == 'openattr-') {
                $attributes[substr($field, 9)] = $value;
            }
        }
        return $attributes;
    }

    /**
     * Get all pending attributes for this file.
     * Pending attributes are attributes that have been written to the client
     * but are not yet submitted to the depot.
     *
     * @return  array   all pending attributes of the file.
     */
    public function getOpenAttributes()
    {
        return $this->getAttributes(true);
    }

    /**
     * Get the named attribute from the set of submitted attributes on this file.
     * Submitted attributes are attributes that have been committed to the depot.
     *
     * @param   string  $attribute  the name of the attribute to get the value of.
     * @return  string  the value of the attribute.
     */
    public function getAttribute($attribute)
    {
        return $this->getStatus('attr-' . $attribute);
    }

    /**
     * Get the named attribute from the set of pending attributes on this file.
     * Pending attributes are attributes that have been written to the client
     * but are not yet submitted to the depot.
     *
     * @param   string  $attribute  the name of the open attribute to get the value of.
     * @return  string  the value of the attribute.
     */
    public function getOpenAttribute($attribute)
    {
        return $this->getStatus('openattr-' . $attribute);
    }

    /**
     * Set attributes on this file. Does not clear existing attributes.
     *
     * @param array $attributes the set of key/value pairs to set on the file.
     * @param bool  $propagate  optional - defaults to true - automatically propagate
     *                          the attributes to new revisions.
     * @param bool  $force      optional - write the attributes to the depot directly
     *                          by default attributes are pended to the client workspace.
     * @return  File            provide fluent interface.
     */
    public function setAttributes($attributes, $propagate = true, $force = false)
    {
        if (!is_array($attributes)) {
            throw new \InvalidArgumentException(
                "Can't set attributes. Attributes must be an array."
            );
        }

        // if no attributes to set, nothing to do.
        if (empty($attributes)) {
            return $this;
        }

        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        $params = array();
        foreach ($attributes as $key => $value) {
            $value = is_null($value) ? '' : $value;

            // ensure value is a string.
            if (!is_string($value)) {
                throw new \InvalidArgumentException("Cannot set attribute. Value must be a string.");
            }

            // ensure attribute key name is valid.
            $validator = new Validate\AttributeName;
            if (!$validator->isValid($key)) {
                throw new \InvalidArgumentException("Cannot set attribute. Attribute name is invalid.");
            }

            // add params for attribute name/value.
            $params[] = '-n';
            $params[] = $key;
            $params[] = '-v';
            $params[] = bin2hex($value);
        }

        // setup shared inital parameters
        $prefixParams = array();
        if ($propagate) {
            $prefixParams[] = '-p';
        }
        if ($force) {
            $prefixParams[] = '-f';
        }

        // write value in binhex to avoid problems with binary data.
        $prefixParams[] = '-e';

        // permit revspec only if force writing attribute.
        $filespec = $force
            ? $this->getFilespecWithRevision()
            : $this->getFilespec(true);

        // see if we can set multiple attributes at once (for performance)
        // if we're unable (e.g. a value exceeds arg-max), set individually via input.
        $batches    = array();
        $connection = $this->getConnection();
        try {
            $batches = $connection->batchArgs($params, $prefixParams, array($filespec), 4);
        } catch (P4\Exception $e) {
            $prefixParams[] = '-i';
            foreach ($attributes as $key => $value) {
                $value  = is_null($value) ? '' : $value;
                $result = $this->getConnection()->run(
                    'attribute',
                    array_merge($prefixParams, array('-n', $key, $filespec)),
                    bin2hex($value)
                );

                // stop processing if we encounter warnings.
                if ($result->hasWarnings()) {
                    break;
                }
            }
        }

        // if we were able to batch the arguments, process them now.
        foreach ($batches as $batch) {
            $result = $this->getConnection()->run('attribute', $batch);

            // stop processing if we encounter warnings.
            if ($result->hasWarnings()) {
                break;
            }
        }

        if ($result->hasWarnings()) {
            throw new Exception(
                "Failed to set attribute(s) on file: " . implode(", ", $result->getWarnings())
            );
        }

        // status has changed - clear the status cache.
        $this->clearStatusCache();

        return $this;
    }

    /**
     * Set the given attribute/value on the file.
     *
     * By default attributes will propagate to new revisions of the file
     * To disable this, set the propagate argument to false.
     *
     * By default attributes will be pended. To write attributes to the depot
     * directly, set the force flag to true.
     *
     * @param string        $key        the name of the attribute to write.
     * @param string|null   $value      the value to write.
     * @param bool          $propagate  optional - defaults to true - propagate the attribute
     *                                  to new revisions.
     * @param bool          $force      optional - defaults to false - write the attribute
     *                                  to the depot directly.
     * @return  File        provide fluent interface.
     */
    public function setAttribute($key, $value, $propagate = true, $force = false)
    {
        // ensure attribute key name is valid.
        // we do this prior to forming the array as an
        // invalid key (e.g. an array) would cause an error.
        $validator = new Validate\AttributeName;
        if (!$validator->isValid($key)) {
            throw new \InvalidArgumentException("Cannot set attribute. Attribute name is invalid.");
        }

        return $this->setAttributes(array($key => $value), $propagate, $force);
    }

    /**
     * Clear the specified attributes on this file.
     *
     * @param array $attributes the set of attributes to clear.
     * @param bool  $force      optional - clear the attributes in the depot directly
     *                          by default attributes are pended to the client workspace.
     * @return  File            provide fluent interface.
     */
    public function clearAttributes($attributes, $force = false)
    {
        if (!is_array($attributes)) {
            throw new \InvalidArgumentException(
                "Can't clear attributes. Attributes must be an array."
            );
        }

        // if no attributes given, nothing to clear.
        if (empty($attributes)) {
            return $this;
        }

        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();
        $filespec = $force
            ? $this->getFilespecWithRevision()
            : $this->getFilespec(true);

        // make -n/attr-name argument pairs.
        $params = array();
        foreach ($attributes as $attribute) {
            $params[] = "-n";
            $params[] = $attribute;
        }

        // there is a potential to exceed the arg-max/option-limit;
        // run attribute command as few times as possible
        $connection   = $this->getConnection();
        $prefixParams = $force ? array('-f') : array();
        foreach ($connection->batchArgs($params, $prefixParams, array($filespec), 2) as $batch) {
            $connection->run('attribute', $batch);
        }

        // status has changed - clear the status cache.
        $this->clearStatusCache();

        return $this;
    }

    /**
     * Clear the given attribute on the file.
     *
     * By default the cleared attribute will be pended. To clear attributes in the depot
     * directly, set the force flag to true.
     *
     * @param string    $attribute  the name of the attribute to clear.
     * @param bool      $force      optional - defaults to false - clear the attribute
     *                              in the depot directly.
     * @return  File    provide fluent interface.
     */
    public function clearAttribute($attribute, $force = false)
    {
        return $this->clearAttributes(array($attribute), $force);
    }

    /**
     * Get file status (run fstat on file).
     *
     * File status is fetched once and then cached in the instance.
     * The cache can be primed via setStatusCache(). It can be cleared
     * via clearStatusCache().
     *
     * Attributes are fetched along with the status.
     *
     * @param   string  $field  optional - a specific status field to get.
     *                          by default all fields are returned.
     * @throws  Exception       if the requested status field does not exist.
     */
    public function getStatus($field = null)
    {
        // if cache is not primed, run fstat.
        if (!array_key_exists('status', $this->cache) || !isset($this->cache['status'])) {
            // verify we have a filespec set; throws if invalid/missing
            $this->validateHasFilespec();

            $result = $this->getConnection()->run(
                'fstat',
                array('-Oal', $this->getFilespecWithRevision())
            );
            if ($result->hasWarnings()) {
                throw new Exception(
                    "Cannot get status: " . implode(", ", $result->getWarnings())
                );
            }

            // grab the last block - can get multiple files if overlay mappings in use.
            if (is_array($result->getData(-1))) {
                $this->setStatusCache($result->getData(-1));
            } else {
                $this->setStatusCache(array());
            }
        }

        // return a specific field or all fields as appropriate.
        if ($field) {
            if (!array_key_exists($field, $this->cache['status'])) {
                throw new Exception(
                    "Can't fetch status. The requested field ('"
                    . $field . "') does not exist."
                );
            } else {
                return $this->cache['status'][$field];
            }
        } else {
            return $this->cache['status'];
        }
    }

    /**
     * Determine if this file has the named status field.
     *
     * @param   string  $field  the name of the field to check for.
     * @return  bool    true if the field exists.
     */
    public function hasStatusField($field)
    {
        try {
            $this->getStatus($field);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Set the file status cache to the given array of fields/values.
     *
     * @param   array   $status an array of field/value pairs.
     * @throws  \InvalidArgumentException   if the given value is not an array.
     * @return  File    provide fluent interface.
     */
    public function setStatusCache($status)
    {
        if (!is_array($status)) {
            throw new \InvalidArgumentException('Cannot set status cache. Status must be an array.');
        }
        $this->cache['status'] = $status;

        if (isset($status['headRev'])) {
            $this->cache['revision'] = $status['headRev'];
        }

        return $this;
    }

    /**
     * Clear the file status cache.
     *
     * @return  File    provide fluent interface.
     */
    public function clearStatusCache()
    {
        $this->cache['status'] = null;

        return $this;
    }

    /**
     * Lock this file in the depot.
     *
     * @return  File    provide fluent interface.
     */
    public function lock()
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        $this->getConnection()->run('lock', $this->getFilespec(true));

        // status has changed - clear the status cache.
        $this->clearStatusCache();

        return $this;
    }

    /**
     * Unlock this file in the depot.
     *
     * @return  File    provide fluent interface.
     */
    public function unlock()
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        $this->getConnection()->run('unlock', $this->getFilespec(true));

        // status has changed - clear the status cache.
        $this->clearStatusCache();

        return $this;
    }

    /**
     * Check if the file is opened in Perforce by the current client.
     *
     * @return  bool    true if the file is opened by the current client.
     */
    public function isOpened()
    {
        if ($this->hasStatusField('action')) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks if this file is at the head revision or not.
     *
     * @return  bool    true if the file is at head, false otherwise
     */
    public function isHead()
    {
        $info = static::exists($this->getFilespec(true), $this->getConnection());

        if (isset($info['rev']) && $info['rev'] === $this->getStatus('headRev')) {
            return true;
        }

        return false;
    }

    /**
     * Test if a file is deleted in the depot.
     * Note: this method reports the deleted status based on the
     * filespec, which could be a non-head revision.
     *
     * @return boolean indicated whether the file is deleted.
     */
    public function isDeleted()
    {
        $headAction = $this->getStatus('headAction');
        if (preg_match('/delete/', $headAction)) {
            return true;
        }
        return false;
    }

    /**
     * Test if the file was purged at this revision in the depot.
     *
     * @return  bool    true if the file was purged at this revision.
     */
    public function isPurged()
    {
        return $this->getStatus('headAction') == 'purge';
    }

    /**
     * Test if the file was deleted or purged at this revision in the depot.
     *
     * @return  bool    true if the file was deleted or purged at this revision.
     */
    public function isDeletedOrPurged()
    {
        return $this->isDeleted() || $this->isPurged();
    }

    /**
     * Test if the file was added at this revision in the depot.
     *
     * @return  bool    true if the file was added at this revision.
     */
    public function isAdded()
    {
        $headAction = $this->getStatus('headAction');
        if (preg_match('/add|branch|import/', $headAction)) {
            return true;
        }
        return false;
    }

    /**
     * Test if the file has a text type in the depot.
     *
     * @return boolean indicated whether the file is text.
     */
    public function isText()
    {
        return (bool) preg_match('/text|unicode|utf/', $this->getStatus('headType'));
    }

    /**
     * Test if the file is a symbolic link locally or in the depot.
     *
     * Symlinked files can accidentally leak server data if operations are
     * performed on them, such as previews or archiving as Zip files.
     *
     * @return boolean indicating whether the file is a symlink
     */
    public function isSymlink()
    {
        return is_link($this->getFilespec(true))
            || ($this->hasStatusField('headType') && $this->getStatus('headType') === 'symlink');
    }

    /**
     * Test if the file has a binary type in the depot.
     *
     * @return boolean indicated whether the file is binary.
     */
    public function isBinary()
    {
        return !$this->isText();
    }

    /**
     * Get the contents of the file in Perforce.
     *
     * File content is fetched once and then cached in the instance
     * (unless the file is truncated due to max-filesize).
     *
     * The cache can be primed via setContentCache().
     * It can be cleared via clearContentCache().
     *
     * @param   null|array  options to influence behaviour
     *                          MAX_FILESIZE - crop the file after this many bytes - won't split
     *                                         multi-byte chars if UTF8_SANITIZE is set
     *                          UTF8_CONVERT - attempt to covert non UTF-8 to UTF-8
     *                         UTF8_SANITIZE - replace invalid UTF-8 sequences with �
     * @param   bool        $cropped    updated by reference, indicates the file contents
     *                                  exceeded max-filesize and were truncated.
     * @return  string      the contents of the file in the depot.
     * @throws  Exception   if the print command fails.
     */
    public function getDepotContents(array $options = null, &$cropped = false)
    {
        $cropped  = false;
        $options  = (array) $options + array(
            static::MAX_FILESIZE  => null,
            static::UTF8_CONVERT  => false,
            static::UTF8_SANITIZE => false
        );
        $maxSize  = $options[static::MAX_FILESIZE];
        $convert  = $options[static::UTF8_CONVERT];
        $sanitize = $options[static::UTF8_SANITIZE];

        // if cache is empty, get content from the server
        if (!array_key_exists('content', $this->cache)) {
            // verify we have a filespec set; throws if invalid/missing
            $this->validateHasFilespec();

            // setup output handler to support limiting file content length
            // this is necessary to avoid running out of memory.
            $content = "";
            $handler = new Limit;
            $handler->setOutputCallback(
                function ($data, $type) use (&$content, $maxSize) {
                    if ($type !== 'text' && $type !== 'binary') {
                        return Limit::HANDLER_REPORT;
                    }
                    if (is_array($data)) {
                        return Limit::HANDLER_HANDLED;
                    }

                    // using isset instead of strlen, because it was surprisingly much faster
                    if ($maxSize && isset($content[$maxSize + 1])) {
                        return Limit::HANDLER_HANDLED | Limit::HANDLER_CANCEL;
                    }

                    $content .= $data;

                    return Limit::HANDLER_HANDLED;
                }
            );

            // run the print command with our output handler
            // ensure depot syntax to avoid multiple file output if overlay mappings in use.
            $result = $this->getConnection()->runHandler($handler, 'print', $this->getDepotFilenameWithRevision());

            // check for warnings.
            if ($result->hasWarnings()) {
                throw new Exception(
                    "Failed to get depot contents: " . implode(", ", $result->getWarnings())
                );
            }

            // don't cache truncated contents
            if (!$maxSize || !isset($content[$maxSize + 1])) {
                $this->cache['content'] = $content;
            }
        } else {
            $content = $this->cache['content'];
        }

        // need to do a final crop if maxSize is set and exceeded.
        if ($maxSize && isset($content[$maxSize + 1])) {
            $content = substr($content, 0, $maxSize);
            $cropped = true;
        }

        // if we are requested to convert or replace; return filtered
        if ($convert || $sanitize) {
            $filter  = new Utf8Filter;
            $content = $filter->setConvertEncoding($convert)
                              ->setReplaceInvalid($sanitize)
                              ->filter($content);

            // if we cropped the file and the caller requested sanitized output,
            // check if the last character is '�' and remove it (likely our fault)
            if ($cropped && $sanitize && substr($content, -3) === "\xEF\xBF\xBD") {
                $content = substr($content, 0, -3);
            }
        }

        // if no options; just return cached directly
        return $content;
    }

    /**
     * Stream the contents of the file in Perforce to stdout via echo.
     *
     * File content is streamed for each call; no caching occurs.
     *
     * @return  File    to maintain a fluent interface
     */
    public function streamDepotContents()
    {
        // we anticipate the output of print will lead with a meta-data block in array format
        // followed by zero or more strings representing the data of the file. our handler
        // simply echo's anything which isn't an array to stream the contents.
        $handler = new Limit;
        $handler->setOutputCallback(
            function ($data) {
                if (!is_array($data)) {
                    echo $data;
                }

                return Limit::HANDLER_HANDLED;
            }
        );

        // run the print command with our output handler
        // ensure depot syntax to avoid multiple file output if overlay mappings in use.
        $this->getConnection()->runHandler($handler, 'print', $this->getDepotFilenameWithRevision());

        return $this;
    }


    /**
     * Return the contents of the file in Perforce limited by the provided line range(s).
     *
     * File content is returned for each call; no caching occurs.
     *
     * @param   array|string    line range(s) to scan between. ranges can be specified
     *                          as either a string in the format start-end (e.g. 1-2)
     *                          or an array with the keys start => 1, end => 2.
     *                          passing a single range or an array of ranges is supported.
     * @return  array           array of captured lines keyed on line number,
     *                          each line includes its line ending
     * @throws  \InvalidArgumentException   if malformed line ranges are specified
     */
    public function getDepotContentLines($lines)
    {
        // normalize to an array of line ranges (we may have received a single input)
        if (is_string($lines) || isset($lines['start'], $lines['end'])) {
            $lines = array($lines);
        }

        // as we've normalized, non-array inputs at this point are complaint worthy
        if (!is_array($lines)) {
            throw new \InvalidArgumentException('String or array input expected');
        }

        // normalize to a list of ranges with start/end keys
        $ranges = array();
        foreach ($lines as $line) {
            // validate string inputs and normalize them to array format
            if (is_string($line)) {
                if (!preg_match('/^\s*([0-9]+)-([0-9]+)\s*$/', $line, $matches)) {
                    throw new \InvalidArgumentException('String arguments must be in the format 1-2');
                }
                $line = array('start' => $matches[1], 'end' => $matches[2]);
            }

            // should surely be an array at this point
            if (!is_array($line)) {
                throw new \InvalidArgumentException('Expected range to be in string or array format');
            }

            // verify start and end are present and numeric
            if (!isset($line['start'], $line['end'])
                || !ctype_digit((string) $line['start'])
                || !ctype_digit((string) $line['end'])
            ) {
                throw new \InvalidArgumentException('Array arguments must have a numeric start and end key');
            }

            if ($line['start'] < 1) {
                throw new \InvalidArgumentException('Line numbers cannot be lower than 1');
            }

            if ($line['end'] < $line['start']) {
                throw new \InvalidArgumentException('Range end must be greater than or equal to range start');
            }

            $ranges[] = array('start' => (int) $line['start'], 'end' => (int) $line['end']);
        }

        // primary sort by start, secondary sort by end
        usort(
            $ranges,
            function ($a, $b) {
                return ($a['start'] - $b['start']) ?: ($a['end'] - $b['end']);
            }
        );

        // no ranges? no problem, just return
        if (!$ranges) {
            return array();
        }

        // ok we've got at least one valid range; lets setup an output handler
        // to collect the line(s) of interest
        $lines   = array();
        $lineNum = 1;
        $handler = new Limit;
        $handler->setOutputCallback(
            function ($data) use (&$ranges, &$lines, &$lineNum) {
                // cancel if we have more data but have run out of ranges
                if (!$ranges) {
                    return Limit::HANDLER_HANDLED | Limit::HANDLER_CANCEL;
                }

                // we anticipate the output of print will lead with a meta-data block in array format
                // followed by zero or more strings representing the data of the file. our handler
                // ignores array data in order to stream the contents.
                // it also ignores empty blocks so we don't add lines for empty files.
                if (is_array($data) || !strlen($data)) {
                    return Limit::HANDLER_HANDLED;
                }

                // split on newlines, but keep the line ending on each line
                $pieces = preg_split("/(\r\n|\n|\r)/", $data, null, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($pieces as $piece) {
                    $range     = reset($ranges);
                    $isNewLine = preg_match("/\r\n|\n|\r/", $piece) === 1;

                    // if we're within the active range capture the data
                    if ($lineNum >= $range['start'] && $lineNum <= $range['end']) {
                        $lines           += array($lineNum => '');
                        $lines[$lineNum] .= $piece;
                    }

                    // if this is a newline; increment the line number
                    if ($isNewLine) {
                        $lineNum++;
                    }

                    // if we just traversed a line and that takes us past our range,
                    // remove the active range as its done with
                    if ($lineNum > $range['end']) {
                        array_shift($ranges);
                    }
                }

                return Limit::HANDLER_HANDLED;
            }
        );

        // run the print command with our output handler
        $this->getConnection()->runHandler($handler, 'print', $this->getDepotFilenameWithRevision());

        return $lines;
    }

    /**
     * Get the annotated contents of the file in Perforce.
     *
     *  array(
     *    'upper' => <upper version number>,
     *    'lower' => <lower version number>,
     *    'data'  => <text data for the current line>
     *  )
     *
     * @param   array   $options    optional - influence annotate results
     *                               ANNOTATE_CHANGES - get change numbers instead of revs (defaults to false)
     *                                 ANNOTATE_INTEG - follow integrations to source via -I (defaults to false)
     *                               ANNOTATE_CONTENT - include line content (defaults to true)
     * @return  array   an array of the file's lines with upper/lower rev and data if content option is true
     */
    public function getAnnotatedContent(array $options = array())
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        // normalize options
        $options += array(
            static::ANNOTATE_CHANGES => false,
            static::ANNOTATE_INTEG   => false,
            static::ANNOTATE_CONTENT => true
        );

        // setup output handler to (optionally) filter file content
        // this is more memory efficient than doing it after the fact
        $result  = array();
        $handler = new Limit;
        $content = $options[static::ANNOTATE_CONTENT];
        $handler->setOutputCallback(
            function ($data) use (&$result, $content) {
                if (is_array($data) && isset($data['upper'], $data['lower'], $data['data'])) {
                    $line = array(
                        'upper' => $data['upper'],
                        'lower' => $data['lower']
                    );
                    if ($content) {
                        $line['data'] = $data['data'];
                    }

                    $result[] = $line;
                }

                return Limit::HANDLER_HANDLED;
            }
        );

        $flags = array(
            $options[static::ANNOTATE_CHANGES] ? '-c' : null,
            $options[static::ANNOTATE_INTEG]   ? '-I' : null,
            $this->getFilespec()
        );
        $this->getConnection()->runHandler($handler, 'annotate', array_filter($flags));

        return $result;
    }

    /**
     * Prime the depot file content cache with the given value.
     *
     * @param   string  $content    the contents of the file in the depot.
     * @return  File    provide fluent interface.
     */
    public function setContentCache($content)
    {
        $this->cache['content'] = $content;

        return $this;
    }

    /**
     * Clear the depot file content cache.
     *
     * @return  File    provide fluent interface.
     */
    public function clearContentCache()
    {
        unset($this->cache['content']);

        return $this;
    }

    /**
     * Get the contents of the local file in the client workspace.
     *
     * @return  string  the contents of the local client file.
     */
    public function getLocalContents()
    {
        if (!file_exists($this->getLocalFilename())) {
            throw new Exception(
                'Cannot get local file contents. Local file does not exist.'
            );
        }
        return file_get_contents($this->getLocalFilename());
    }

    /**
     * Write contents to the local client file.
     * If the file does not exist, it will be created.
     *
     * @param   string $content     the content to write to the file
     * @throws  Exception           if the file cannot be written.
     * @return  File                provide fluent interface.
     */
    public function setLocalContents($content)
    {
        $this->touchLocalFile();
        if (!is_writable($this->getLocalFilename())) {
            if (!chmod($this->getLocalFilename(), 0644)) {
                $message = "Failed to make local file writable.";
                throw new Exception($message);
            }
        }
        if (file_put_contents($this->getLocalFilename(), $content) === false) {
            $message = "Failed to write local file.";
            throw new Exception($message);
        }

        return $this;
    }

    /**
     * Touch the local client file.
     * If the file does not exist, it will be created.
     *
     * @throws  Exception   if the file cannot be touched.
     * @return  File        provide fluent interface.
     */
    public function touchLocalFile()
    {
        if (!is_dir($this->getLocalPath())) {
            $this->createLocalPath();
        }
        if (!is_file($this->getLocalFilename())) {
            if (!touch($this->getLocalFilename())) {
                $message = "Failed to touch local file.";
                throw new Exception($message);
            }
        }

        return $this;
    }

    /**
     * Open the file in another change and/or as a different filetype.
     *
     * @param   string  $change the change list to open the file in.
     * @param   string  $type   the filetype to open the file as.
     * @throws  \InvalidArgumentException   if neither a change nor a type are given.
     * @return  File    provide fluent interface.
     */
    public function reopen($change = null, $type = null)
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        // ensure user has specified a change and/or a type
        if (!$change && !$type) {
            throw new \InvalidArgumentException(
                'Cannot reopen file. You must provide a change and/or a filetype.'
            );
        }

        $params = array();
        if ($change) {
            $params[] = '-c';
            $params[] = $change;
        }
        if ($type) {
            $params[] = '-t';
            $params[] = $type;
        }
        $params[] = $this->getFilespec(true);
        $this->getConnection()->run('reopen', $params);

        // status has changed - clear the status cache.
        $this->clearStatusCache();

        return $this;
    }

    /**
     * Revert the file.
     *
     * @param   string|array|null   $options    options to influence the operation:
     *                                              REVERT_UNCHANGED - only revert if unchanged
     * @return  File    provides fluent interface.
     */
    public function revert($options = null)
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        // if the unchanged option is given, add -a flag.
        $params = array();
        $unchanged = in_array(static::REVERT_UNCHANGED, (array) $options);
        if ($unchanged) {
            $params[] = "-a";
        }

        $params[] = $this->getFilespec(true);

        $this->getConnection()->run('revert', $params);

        // status has changed - clear the status cache.
        $this->clearStatusCache();

        return $this;
    }

    /**
     * Submit the file to perforce.
     * If the optional resolve flags are passed, an attempt will be made to automatically
     * resolve/resubmit should a conflict occur.
     *
     * @param   string              $description    the change description.
     * @param   null|string|array   $options        optional resolve flags, to be used if conflict
     *                                              occurs. See resolve() for details.
     * @throws  \InvalidArgumentException           if no description is given.
     * @return  File    provide fluent interface.
     */
    public function submit($description, $options = null)
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        // ensure that we have a description.
        if (!is_string($description) || !strlen($description)) {
            throw new \InvalidArgumentException(
                'Cannot submit. Description must be a non-empty string.'
            );
        }

        // ensure the file is in the default pending change.
        // this is required to avoid inadvertently affecting
        // a numbered pending change description and its files.
        if ($this->hasStatusField('change') && $this->getStatus('change') != 'default') {
            $this->reopen('default');
        }

        // setup the submit options
        $params   = array();
        $params[] = '-d';
        $params[] = $description;
        $params[] = $this->getFilespec(true);

        try {
            $this->getConnection()->run('submit', $params);
        } catch (ConflictException $e) {
            // if there are no resolve options; re-throw the resolve exception
            if (empty($options)) {
                throw $e;
            }

            // re-do submit via our change as this will
            // attempt to do the resolve. note change presently
            // does a wasted try prior to resolve but hopefully
            // the use is seldom enough we don't take a notable
            // performance hit on it.
            $e->getChange()->submit(null, $options);
        }

        // file has changed - clear all of the instance caches.
        $this->cache = array();

        // if we had a rev-spec previously, take it off
        $this->setFilespec($this->getFilespec(true));

        return $this;
    }

    /**
     * Sync the file from the depot.
     * Note when the File is fetched, or if made via new the first time it is
     * accessed and has a valid filespec, the revision is pinned at that point in
     * time. Sync will always use the pinned revision which is not necessarily head.
     *
     * @param   bool    $force      optional - defaults to false - force sync the file.
     * @param   bool    $flush      optional - defaults to false - don't transfer the file.
     * @return  File                provide fluent interface.
     * @throws  Exception           if sync fails.
     */
    public function sync($force = false, $flush = false)
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        $params = array();
        if ($force) {
            $params[] = '-f';
        }
        if ($flush) {
            $params[] = '-k';
        }
        $params[] = $this->getFilespecWithRevision();
        $result = $this->getConnection()->run('sync', $params);

        // status has changed - clear the status cache.
        $this->clearStatusCache();

        // verify sync was successful.
        if ($result->hasWarnings()) {
            // if we had warnings throw if the haveRev doesn't equal the headRev
            // unless it is a deleted file in which case we expect a warning
            $haveRev = $this->hasStatusField('haveRev') ? $this->getStatus('haveRev') : -1;
            $headRev = $this->hasStatusField('headRev') ? $this->getStatus('headRev') : 0;
            if (!$this->isDeleted() && $headRev !== $haveRev) {
                throw new Exception(
                    "Failed to sync file: " . implode(", ", $result->getWarnings())
                );
            }
        }

        return $this;
    }

    /**
     * Get the file's size in the depot.
     *
     * @return  int  the depot file's size in bytes, or zero.
     * @todo make this work properly.
     */
    public function getFileSize()
    {
        if (!$this->hasStatusField('fileSize')) {
            throw new Exception('The file does not have a fileSize attribute.');
        }
        return (int) $this->getStatus('fileSize');
    }

    /**
     * Get the size of the local client file.
     *
     * @return  int the local file's size in bytes, or zero.
     */
    public function getLocalFileSize()
    {
        if (!file_exists($this->getLocalFilename())) {
            throw new Exception('The local file does not exist.');
        }
        return (int) filesize($this->getLocalFilename());
    }

    /**
     * Get the path to the file in local file syntax.
     *
     * @return  string  the path to the file in local file syntax.
     */
    public function getLocalFilename()
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        $filespec = $this->getFilespec(true);

        // if filespec is in local-file syntax return it.
        if (strlen($filespec) >=2 && substr($filespec, 0, 2) != '//') {
            return $filespec;
        }

        // otherwise, get local filename from p4 where.
        $where = $this->where();
        return $where[2];
    }

    /**
     * Get the local path to the file.
     *
     * @return  string  the local path to the file.
     */
    public function getLocalPath()
    {
        return dirname($this->getLocalFilename());
    }

    /**
     * Get the path to the file in depot syntax.
     *
     * We try several different means of getting the filespec in depot syntax:
     *  1. Take the filespec itself if it leads with '//' and is not '//<client>'
     *  2. Check the depotFile cache which gets set on fetch()
     *  3. Try getStatus('depotFile') - this is free if cached and more accurate than where
     *  4. Run 'p4 where' as a last resort - necessary if file doesn't exist in the depot
     *
     * @return  string  the path to the file in depot file syntax.
     */
    public function getDepotFilename()
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        $filespec = $this->getFilespec(true);

        // if filespec is already in depot-file syntax, return it.
        // note, we must verify that it doesn't start with the client name.
        $clientPrefix = "//" . $this->getConnection()->getClient() . "/";
        if (strlen($filespec) >= 2 && substr($filespec, 0, 2) == '//' &&
            substr($filespec, 0, strlen($clientPrefix)) != $clientPrefix) {
            return $filespec;
        }

        // if we have previously cached the depotFile (e.g. on fetch), use it.
        if (isset($this->cache['depotFile'])) {
            return $this->cache['depotFile'];
        }

        // if no depotFile in cache, check file status for depotFile
        // we favor status (fstat) over where because it is more accurate.
        if ($this->hasStatusField('depotFile')) {
            return $this->getStatus('depotFile');
        }

        // otherwise, get depot file from p4 where.
        $where = $this->where();
        return $where[0];
    }

    /**
     * Get the path to the file in depot syntax and append revision.
     *
     * @return  string  the path to the file in depot syntax with revision.
     */
    public function getDepotFilenameWithRevision()
    {
        return $this->getDepotFilename() . $this->getRevspec();
    }

    /**
     * Get the depot path to the file.
     *
     * @return  string  the depot path to the file.
     */
    public function getDepotPath()
    {
        return dirname($this->getDepotFilename());
    }

    /**
     * Get the basename of the file.
     *
     * @param   string  $suffix if filename ends in this suffix it will be cut off.
     * @return  string  the basename of the file.
     */
    public function getBasename($suffix = null)
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        return basename($this->getFilespec(true), $suffix);
    }

    /**
     * Get the file extension of the file.
     *
     * @return  string  the extension of the file.
     */
    public function getExtension()
    {
        return pathinfo($this->getBasename(), PATHINFO_EXTENSION);
    }

    /**
     * Determine how this file maps through the client view.
     *
     * Produces an array with three variations on the filespec.
     * Depot-syntax, client-syntax and local file-system syntax
     * (in that order).
     *
     * Caches the result so that subsequent lookups do not incur
     * the 'p4 where' command overhead.
     *
     * @return  array   three variations of the filespec: depot-syntax
     *                  client-syntax and local-syntax (respectively).
     * @throws  Exception         if the file is not mapped by the client.
     */
    public function where()
    {
        if (!array_key_exists('where', $this->cache) || !isset($this->cache['where'])) {
            // verify we have a filespec set; throws if invalid/missing
            $this->validateHasFilespec();

            $result = $this->getConnection()->run('where', $this->getFilespec(true));
            if ($result->hasWarnings()) {
                throw new Exception("Where failed. File is not mapped.");
            }

            // take the last valid looking response. normally we only get back a
            // single data block with the keys depotFile, clientFile and path.
            // if the client view maps multiple paths into one folder we may also get
            // blocks containing 'unmap' or 'remap' -- we ignore unmaps because they
            // indicate paths that are not mapped, but we honor remaps because they
            // actually give us a more accurate path (and tend to come last).
            foreach ($result->getData() as $data) {
                if (isset($data['depotFile'], $data['clientFile'], $data['path']) && !isset($data['unmap'])) {
                    $this->cache['where'] = array(
                        $data['depotFile'], $data['clientFile'], $data['path']
                    );
                }
            }

            // double check we located a valid response; throw if we didn't
            if (!array_key_exists('where', $this->cache)) {
                throw new Exception("Where failed. File is not mapped.");
            }
        }
        return $this->cache['where'];
    }

    /**
     * Convienence function to return all changes associated with this file.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are the same as Change, except for
     *                              the use of FETCH_BY_FILESPEC which is not permitted here.
     * @return  FieldedIterator     Iterator of Changes
     */
    public function getChanges(array $options = null)
    {
        $this->validateHasFilespec();

        $options = array_merge(
            (array) $options,
            array(Change::FETCH_BY_FILESPEC => $this->getFilespec(true))
        );

        return Change::fetchAll($options, $this->getConnection());
    }

    /**
     * Get the filelog (list of revisions) for this file.
     * Ordered with the most recent revisions first.
     *
     * @return  array   list of revisions.
     * @todo    add options to control filelog flags.
     */
    public function getFilelog(array $options = null)
    {
        $this->validateHasFilespec();

        // note that due to a bug (job004873), we have to pass depot file name
        // as filelog won't work with path prefixed by the client if the client
        // was not synchronized
        $result = $this->getConnection()->run(
            'filelog',
            array(
                '-i',   // include inherited history
                '-l',   // get full changelist descriptions
                '-s',   // only include contributing integrations
                $this->getDepotFilename()
            )
        );

        // filelog has one data-block per file
        // (multiple files represent inherited history)
        $files = array();
        foreach ($result->getData() as $file => $log) {

            // each file block must have a depotFile property
            if (!isset($log['depotFile'])) {
                continue;
            }
            $file = $log['depotFile'];

            // explode filelog result into multi-dimensional array of revisions
            // initial output is a flat list of keys/values where the keys have
            // a trailing number to group them by revision (e.g. rev0, rev1)
            // keys with comma-separated trailing numbers indicate integrations
            // into or out of that revision (e.g. file0,0 file0,1 ... file1,0)
            foreach ($log as $key => $value) {
                if (!preg_match('/(.*?)(([0-9]+,)?[0-9]+)$/', $key, $matches)) {
                    continue;
                }

                // pull out the key's base, index and optional integ-index
                $base  = $matches[1];
                $index = current(explode(',', $matches[2]));
                $integ = strpos($matches[2], ',') ? end(explode(',', $matches[2])) : null;

                if ($integ !== null) {
                    $files[$file][$index]['integrations'][$integ][$base] = $value;
                } else {
                    $files[$file][$index][$base] = $value;
                }
            }
        }

        return $files;
    }

    /**
     * Convenience function to return the change object associated with the file at its current revspec.
     *
     * @return  Change  The associated change object.
     */
    public function getChange()
    {
        return Change::fetch($this->getStatus('headChange'), $this->getConnection());
    }

    /**
     * Strip the revision specifier from a file specification.
     * This removes the \#rev, \@change, etc. component from a filespec.
     *
     * @param   string  $filespec   the filespec to strip the revspec from.
     * @return  string  the filespec without the revspec.
     */
    public static function stripRevspec($filespec)
    {
        $revPos = strpos($filespec, "#");
        if ($revPos !== false) {
            $filespec = substr($filespec, 0, $revPos);
        }
        $revPos = strpos($filespec, "@");
        if ($revPos !== false) {
            $filespec = substr($filespec, 0, $revPos);
        }
        return $filespec;
    }

    /**
     * Extracts the revision specifier from a file specification.
     * This removes the filename leaving just the revspec (e.g. \#rev).
     *
     * @param   string          $filespec   the filespec to extract the revspec from.
     * @return  string|false    the revspec or false if filespec contains no revision.
     */
    public static function extractRevspec($filespec)
    {
        $revPos = strpos($filespec, "#");
        if ($revPos !== false) {
            return substr($filespec, $revPos);
        }
        $revPos = strpos($filespec, "@");
        if ($revPos !== false) {
            return substr($filespec, $revPos);
        }
        return false;
    }

    /**
     * Check if the given filespec has a revision specifier.
     *
     * @param   string  $filespec   the filespec to check for a revspec.
     * @return  bool    true if the filespec has a revspec component.
     */
    public static function hasRevspec($filespec)
    {
        if (strpos($filespec, "#") !== false ||
            strpos($filespec, "@") !== false) {
            return true;
        }
        return false;
    }

    /**
     * Strip trailing wildcards from a file specification.
     * This removes '/...', '/*' or positional argument (e.g. /%%1) from the end of filespec.
     *
     * @param   string  $filespec   the filespec to strip the wildcards from
     * @return  string  the filespec without trailing wildcards
     */
    public static function stripWildcards($filespec)
    {
        // remove trailing wildcards from $filespec matching following patterns:
        //  /...
        //  /*
        //  /%%\d+
        return preg_replace('/\/(\.{3}|\*|%%\d+)$/', '', $filespec);
    }

    /**
     * Open the file for the specified action.
     *
     * @param   string  $action     the action to open the file for ('add', 'edit' or 'delete').
     * @param   int     $change     optional - a numbered pending change to open the file in.
     * @param   string  $fileType   optional - the file-type to open the file as.
     * @param   bool    $force      optional - defaults to true - set to false to avoid reopening.
     * @return  File    provide fluent interface.
     * @todo    better handling of files open for branch operations - currently, such files
     *          will be reverted because the action won't match - this is not correct.
     */
    protected function openForAction($action, $change = null, $fileType = null, $force = true)
    {
        // verify we have a filespec set; throws if invalid/missing
        $this->validateHasFilespec();

        // action must be one of: add, edit or delete.
        if (!in_array($action, array('add', 'edit', 'delete'))) {
            throw new Exception("Cannot open file. Invalid open 'action' specified.");
        }

        // if already opened for specified action, verify change and type, then return.
        if ($this->isOpenForAction($action)) {
            if (($change && $this->getStatus('change') !== $change)
                || ($fileType && $this->getStatus('type') !== $fileType)
            ) {
                $this->reopen($change, $fileType);
            }

            return $this;
        }

        $p4   = $this->getConnection();
        $file = $this->getFilespec(true);

        // if force is true, revert files opened for the wrong action
        // unless it's open for integrate and we are trying to edit
        // or it's open for branch and we are trying to add (to keep
        // the integration credit).
        if ($force
            && $this->isOpened()
            && !$this->isOpenForAction($action)
            && !($action == 'edit' && $this->isOpenForAction('integrate'))
            && !($action == 'add'  && $this->isOpenForAction('branch'))
        ) {
            $result = $p4->run('revert', $file);

            // if a file was opened for 'virtual' delete (was not in the client
            // workspace) and subsequently synced (e.g. edit() called), the above
            // p4 revert won't sync the file to the workspace, but the server will
            // think we 'have' it -- detect this case and force sync to correct.
            if ($result->getData(0, 'oldAction') === 'delete'
                && $result->getData(0, 'action') === 'cleared'
                && $result->getData(0, 'haveRev') !== 'none'
            ) {
                $p4->run(
                    'sync',
                    array('-f', $this->getFilespecWithRevision())
                );
            }
        }

        // setup command flags.
        $flags = array();
        if ($change) {
            $flags[] = '-c';
            $flags[] = $change;
        }
        if ($fileType) {
            $flags[] = '-t';
            $flags[] = $fileType;
        }

        // allows delete to work without having to sync file.
        if ($action === 'delete') {
            $flags[] = '-v';
        }
        $flags[] = $file;

        // throw for edit or delete of a deleted file, and for add/edit/delete
        // on a stream depot from a non-stream client (these are dead ends!)
        // use the -n flag to see what would happen without actually opening file.
        $result = $p4->run($action, array_merge(array('-n'), $flags));
        foreach ($result->getData() as $data) {
            if (is_string($data)
                && (preg_match("/warning: $action of deleted file/", $data)
                || preg_match('/warning: cannot submit from non-stream client/', $data))
            ) {
                throw new Exception(
                    "Failed to open file for $action: " . $data
                );
            }
        }

        // open file for specified action.
        $result = $p4->run($action, $flags);

        // check for warnings.
        if ($result->hasWarnings()) {
            throw new Exception(
                "Failed to open file for $action: " . implode(", ", $result->getWarnings())
            );
        }

        // status has changed - clear the status cache.
        $this->clearStatusCache();

        // verify file was opened for specified action.
        if (!$this->hasStatusField('action') || $this->getStatus('action') !== $action) {
            throw new Exception(
                "Failed to open file for $action: " . $result->getData(0)
            );
        }

        return $this;
    }

    /**
     * Checks if the file is open for the given action.
     *
     * Applies a bit of fuzzy logic to consider move/add to be open for
     * edit since a file must be opened for edit before it can be moved.
     *
     * @param   string  $action     the action to check for
     * @return  bool    true if the file is open for the given action
     */
    protected function isOpenForAction($action)
    {
        // if not opened at all, nothing more to check
        if (!$this->isOpened()) {
            return false;
        }

        $openAction = $this->getStatus('action');
        if ($openAction == $action) {
            return true;
        }

        // consider move/add to also be open for edit - a file must be opened
        // for edit before it can be moved; therefore, a move/add file is open
        // for edit - without this, calling edit() on the target of a move
        // would incur a revert unless force is explicitly set to false.
        if ($openAction == 'move/add' && $action == 'edit') {
            return true;
        }

        return false;
    }

    /**
     * Ensure that a valid, non-empty, filespec has been set on this instance.
     * Will throw an exception if the filespec has wildcards or is unset.
     *
     * @throws  Exception   if the filespec is empty or invalid
     */
    private function validateHasFilespec()
    {
        $filespec = $this->getFilespec();

        if (empty($filespec)) {
            throw new Exception("Cannot complete operation, no filespec has been specified");
        }

        $this->validateFilespec($filespec);
    }

    /**
     * Ensure that the given filespec has no wildcards.
     * Will throw an exception if the filespec has wildcards
     *
     * @param   string  $filespec   a filespec key to check for wildcards.
     * @throws  Exception           if the filespec has wildcards.
     */
    private static function validateFilespec($filespec)
    {
        if (!is_string($filespec) ||
            !strlen($filespec) ||
            strpos($filespec, "*")   !== false ||
            strpos($filespec, "...") !== false) {
            throw new Exception(
                "Invalid filespec provided. In this context, "
                . "filespecs must be a reference to a single file."
            );
        }
    }

    /**
     * Create the directory structure for the local file.
     */
    public function createLocalPath()
    {
        if (!is_dir($this->getLocalPath())) {
            if (!mkdir($this->getLocalPath(), 0755, true)) {
                throw new Exception("Unable to create path: " . $this->getLocalPath());
            }
        }
    }
}
