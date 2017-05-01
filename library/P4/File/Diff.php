<?php
/**
 * Diffs two arbitrary files in the depot.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\File;

use P4\File\File;
use P4\Filter\Utf8 as Utf8Filter;
use P4\Model\Connected\ConnectedAbstract;

class Diff extends ConnectedAbstract
{
    const   MAX_FILESIZE        = 1048576;      // 1 MB

    const   IGNORE_WS           = 'ignoreWs';
    const   UTF8_CONVERT        = 'convert';
    const   UTF8_SANITIZE       = 'sanitize';

    /**
     * Compare left/right files.
     *
     * @param   File    $right      optional - right-hand file
     * @param   File    $left       optional - left-hand file
     * @param   array   $options    optional - influence diff behavior
     *                                IGNORE_WS - ignore whitespace and line-ending
     *                                            changes (defaults to false)
     *                             UTF8_CONVERT - attempt to covert non UTF-8 to UTF-8
     *                            UTF8_SANITIZE - replace invalid UTF-8 sequences with �
     * @return  array   array with three elements:
     *                     lines - added/deleted and contextual (common) lines
     *                     isCut - true if lines exceed max filesize (>1MB)
     *                    isSame - true if left and right file contents are equal
     * @throws  \InvalidArgumentException   if no right-hand file is given
     */
    public function diff(File $right = null, File $left = null, array $options = array())
    {
        $options = $options + array(
            static::IGNORE_WS => false, static::UTF8_CONVERT => false, static::UTF8_SANITIZE => false
        );

        if (!$right && !$left) {
            throw new \InvalidArgumentException(
                "Cannot diff. Must specify at least one file to diff."
            );
        }

        $diff = array(
            'lines'  => array(),
            'isCut'  => false,
            'isSame' => false
        );

        // only examine contents if both sides are non-binary and at least one has content
        $leftIsBinary    = $left  && $left->isBinary();
        $leftHasContent  = $left  && !$left->isDeletedOrPurged();
        $rightIsBinary   = $right && $right->isBinary();
        $rightHasContent = $right && !$right->isDeletedOrPurged();
        if (!$leftIsBinary && !$rightIsBinary && ($leftHasContent || $rightHasContent)) {
            // if only one file given or either file was deleted/purged,
            // can't use diff2, must print the file contents instead.
            if (!$left || !$right || $left->isDeletedOrPurged() || $right->isDeletedOrPurged()) {
                $diff = $this->diffAddDelete($diff, $right, $left, $options);
            } else {
                $diff = $this->diffEdit($diff, $right, $left, $options);
            }
        }

        // compare digests if we have no diff lines (need both sides)
        if (!$diff['lines'] && $left && $right) {
            $leftDigest     = $left->hasStatusField('digest')  ? $left->getStatus('digest')  : null;
            $rightDigest    = $right->hasStatusField('digest') ? $right->getStatus('digest') : null;
            $diff['isSame'] = $leftDigest === $rightDigest;
        }

        return $diff;
    }

    /**
     * Run p4 diff2 against left/right files and parse output into array.
     *
     * @param   array   $diff       diff result array we are building.
     * @param   File    $right      right-hand file.
     * @param   File    $left       left-hand file.
     * @param   array   $options    influences diff behavior.
     * @return  array   diff result with lines added.
     */
    protected function diffEdit(array $diff, File $right, File $left, array $options)
    {
        $mode  = $options[static::IGNORE_WS] ? '-dwu5' : '-du5';
        $flags = array($mode, $left->getFilespec(), $right->getFilespec());
        $data  = $this->getConnection()->run('diff2', $flags, null, false)->getData();

        // diff output puts a file header in the first data block
        // (which we skip) and the diffs in one or more following blocks.
        $diffs = "";
        for ($i = 1; $i < count($data); $i++) {
            $diffs .= $data[$i];
        }

        // if we are requested to convert or replace; do so prior to split
        if ($options[static::UTF8_CONVERT] || $options[static::UTF8_SANITIZE]) {
            $filter = new Utf8Filter;
            $diffs = $filter->setConvertEncoding($options[static::UTF8_CONVERT])
                ->setReplaceInvalid($options[static::UTF8_SANITIZE])
                ->filter($diffs);
        }

        // parse diff block into lines
        // capture line-ending so we can detect line-end changes.
        $types = array('@' => 'meta', ' ' => 'same', '-' => 'delete', '+' => 'add');
        $lines = preg_split("/(\r\n|\n|\r)/", $diffs, null, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 0; $i < count($lines); $i = $i+2) {
            $line = $lines[$i];
            $end  = isset($lines[$i+1]) ? $lines[$i+1] : '';

            // skip empty or unexpected output
            if (!strlen($line) || !isset($types[$line[0]])) {
                continue;
            }

            $type = $types[$line[0]];

            // extract starting left/right line numbers from meta block
            // meta block has the format of "@@ -133,29 +133,27 @@"
            if ($type === 'meta') {
                preg_match('/@@ \-([0-9]+),[0-9]+ \+([0-9]+),[0-9]+ @@/', $line, $matches);
                $leftLine  = $matches[1];
                $rightLine = $matches[2];
            }

            $diff['lines'][] = array(
                'value'     => $line,
                'type'      => $type,
                'lineEnd'   => $end,
                'leftLine'  => ($type === 'same' || $type === 'delete') ? $leftLine++  : null,
                'rightLine' => ($type === 'same' || $type === 'add')    ? $rightLine++ : null
            );
        }

        return $diff;
    }

    /**
     * Get file contents of added/deleted files.
     *
     * @param   array       $diff       diff result array we are building.
     * @param   File        $right      optional - right-hand file.
     * @param   File        $left       optional - left-hand file.
     * @param   array|null  $options    influences diff behavior.
     * @return  array   diff result with lines added.
     */
    protected function diffAddDelete(array $diff, File $right = null, File $left = null, $options = null)
    {
        // contents must come from the side we have, or the side that is not deleted/purged
        // contents from right imply add, contents from left imply delete
        $file  = $right && !$right->isDeletedOrPurged() ? $right : $left;
        $isAdd = $file == $right;

        // get file contents truncated to max filesize to avoid consuming too much memory.
        $options += array(File::MAX_FILESIZE => static::MAX_FILESIZE);
        $content  = $file->getDepotContents($options, $cropped);

        $lines = preg_split("/\r\n|\n|\r/", $content);
        $count = count($lines);
        $meta  = '@@ ' . ($isAdd ? '-1,0 +1,' . $count : '-1,' . $count . '+1,0') . ' @@';
        $diff['isCut']   = $cropped ? static::MAX_FILESIZE : false;
        $diff['lines'][] = array(
            'value'     => $meta,
            'type'      => 'meta',
            'leftLine'  => null,
            'rightLine' => null
        );
        foreach ($lines as $i => $line) {
            $diff['lines'][] = array(
                'value'     => $isAdd ? '+' . $line : '-' . $line,
                'type'      => $isAdd ? 'add' : 'delete',
                'leftLine'  => $isAdd ? null : $i+1,
                'rightLine' => $isAdd ? $i+1 : null
            );
        }

        return $diff;
    }
}
