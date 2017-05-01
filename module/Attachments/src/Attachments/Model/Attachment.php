<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Attachments\Model;

use Files\MimeType;
use P4\File\File;
use Record\Key\AbstractKey as KeyRecord;

/**
 * Provides file attachment functionality and manages blob records in tandem with attachment records.
 */
class Attachment extends KeyRecord
{
    const KEY_PREFIX            = 'swarm-attachment-';
    const FILE_PREFIX           = 'attachments/';
    const KEY_COUNT             = 'swarm-attachment:count';

    public $fields = array(
        'name',
        'type',
        'size',
        'depotFile',
        'references' => array(
            'accessor' => 'getReferences',
            'mutator'  => 'setReferences',
        ),
    );

    /**
     * Extends parent to write the data to a depot file,
     * with the filespec derived from the attachment ID.
     *
     * @oaran   string|null      $inputFile file to write to the depot (will be moved and deleted during the save)
     * @return  Attachment                  to maintain a fluent interface
     * @throws  \InvalidArgumentException   if the local file cannot be found
     */
    public function save($inputFile = null)
    {
        $depot = $this->getConnection()->getService('depot_storage');

        // if we have no id attempt to generate one.
        // we have to do this here instead of relying on parent::save() so we can generate the depot file name.
        if (!strlen($this->id)) {
            $this->id = $this->makeId();
        }

        // if depotFile and inputFile are unset, we've essentially got nothing to do.
        if (!strlen($this->get('depotFile')) && !strlen($inputFile)) {
            throw new \InvalidargumentException(
                "Attachment must have a depotFile, or ->save(...) must be given an inputFile."
            );
        }

        // build the filename
        $depotFile  = str_pad($this->get('id'), 10, '0', STR_PAD_LEFT);
        $suffix     = $this->cleanFilename($this->values['name']);

        // maximum filename length on windows is 255, and since we are prepending another string (which could be a
        // variable number of characters), we have to build our substr starting point carefully.
        $maxSuffix  = 255 - strlen($depotFile . '-');
        $depotFile  = $suffix ? $depotFile . '-' . substr($suffix, $maxSuffix * -1) : $depotFile;

        // we also need to prefix the path, as a way of namespacing the attachments
        if (!strlen($this->get('depotFile'))) {
            $this->set('depotFile', $depot->absolutize(self::FILE_PREFIX . $depotFile));
        }

        parent::save();

        // write the data from localFile to depotFile
        // the "true" flag causes the storage service to attempt to move the file to the temporary workspace
        // since it's an uploaded file, this shouldn't be an issue
        if (strlen($inputFile) && is_writable($inputFile)) {
            $depot->writeFromFile($this->get('depotFile'), $inputFile, true);
        }

        return $this;
    }

    /**
     * Extends parent to ensure depot file is deleted in tandem with attachment record.
     *
     * @return  Attachment  to maintain a fluent interface
     */
    public function delete()
    {
        $depot    = $this->getConnection()->getService('depot_storage');
        $filespec = $this->get('depotFile');

        if (strlen($filespec)) {
            try {
                $depot->delete($filespec);
            } catch (\Exception $e) {
                // if the file doesn't exist, we can ignore this
                if (File::exists($filespec, $this->getConnection(), true)) {
                    throw $e;
                }
            }
        }

        return parent::delete();
    }

    /**
     * Get an array of references.
     *
     * Example format:
     *
     *  array(
     *      'comment' => array(21)
     *  )
     *
     * If the array is empty, the attachment does not have any recorded references.
     *
     * @return  array   An array of items that reference this attachment, aggregated by item type.
     */
    public function getReferences()
    {
        return $this->normalizeReferences($this->getRawValues('references'));
    }

    /**
     * Set references using array format:
     *
     * $attachment->setReferences(
     *      array(
     *          'comment'=> array(21)
     *      )
     *  );
     *
     * @param   $references     array   An array of items that reference this attachment, aggregated by item type.
     * @return  Attachment              to maintain a fluent interface
     */
    public function setReferences($references)
    {
        return $this->setRawValue('references', $this->normalizeReferences($references));
    }

    /**
     * Normalize references to discard invalid values. Expected format:
     *
     * $attachment->normalizeReferences(
     *      array(
     *          'comment'=> array(21)
     *      )
     *  );
     *
     * @param $references   array   An array of IDs, aggregated by type
     * @return array                the normalized result - a two-dimensional array with no duplicates.
     */
    protected function normalizeReferences($references)
    {
        return array_map(
            'array_unique',
            array_filter(
                (array)$references,
                function ($ids) {
                    return is_array($ids) && $ids;
                }
            )
        );
    }

    /**
     * Shortcut for setting references. Properly formats data structure.
     *
     * @param   $type       type of record referencing this attachment (e.g. 'comment')
     * @param   $id         ID of the record that references this attachment
     * @return  Attachment  to maintain a fluent interface
     */
    public function addReference($type, $id)
    {
        $references          = $this->getReferences();
        $references[$type][] = $id;

        return $this->setReferences($references);
    }

    /**
     * Clean the provided filename so that it can be used as a depot filespec
     *
     * @param   string  $name   filename to clean
     * @return  string          this filename is clean
     */
    protected function cleanFilename($name)
    {
        $safePattern = '/[^a-zA-Z0-9_.]/';
        $dashPattern = '/[-]+/';
        $dotPattern  = '/[.]+/';

        $safeName = preg_replace($safePattern, '-', $name);
        $safeName = preg_replace($dashPattern, '-', $safeName);
        $safeName = preg_replace($dotPattern,  '.', $safeName);

        return trim($safeName, '-.');
    }


    /**
     * Check if the mimetype of an attachment is a web-safe image.
     *
     * @return  bool    true if attachment is a web-safe image, false otherwise
     */
    public function isWebSafeImage()
    {
        return MimeType::isWebSafeImage($this->get('type'));
    }
}
