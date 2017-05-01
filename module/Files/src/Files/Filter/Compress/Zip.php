<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Files\Filter\Compress;

use Zend\Filter\Compress\AbstractCompressionAlgorithm;
use Zend\Filter\Compress\Zip as ZendZip;

/**
 * Zip files under a given path.
 *
 * It will use 'zip' command if available, otherwise throws an exception.
 */
class Zip extends ZendZip
{
    protected $outputCallback = null;

    /**
     * Ensure that zip is available via command line when this instance is created.
     *
     * @param  null|array|\Traversable  $options (Optional) Options to set
     */
    public function __construct($options = null)
    {
        // bypass parent's check for 'zip' extension that we don't need, but since we
        // still want to process options we call grand-parent
        AbstractCompressionAlgorithm::__construct($options);
    }

    /**
     * Set compression level.
     *
     * @param   int|null    $compression    compression level
     */
    public function setCompression($compression)
    {
        $compression = (int) $compression;
        if ($compression < 0 || $compression > 9) {
            throw new \InvalidArgumentException("Compression level must be in the range of 0 and 9.");
        }

        $this->options['compression'] = $compression;
    }

    /**
     * Get compression level.
     *
     * @param   int     compression level
     */
    public function getCompression()
    {
        return (int) $this->getOptions('compression');
    }

    /**
     * Set output callback. It will be called for each file processed by zip command when compressing.
     *
     * @param   callable    $callback   zip output callback
     */
    public function setOutputCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException("Callback must be a callable function.");
        }

        $this->outputCallback = $callback;
    }

    /**
     * Compresses path or a string with the defined settings.
     *
     * @param   string  $value  path or string to compress
     * @return  string  path to the zip archive
     */
    public function compress($value)
    {
        if (!$this->hasZip()) {
            throw new \RuntimeException('Cannot compress files: zip command is not available.');
        }

        $compression = $this->getCompression();
        $archive     = $this->getArchive();

        // ensure that archive has extension as zip will silently add '.zip' if the archive
        // doesn't contain one; we need to return the full path to archive after compressing
        // so we add '.zip' extension if archive doesn't have any already
        $extension = pathinfo($archive, PATHINFO_EXTENSION);
        $archive  .= $extension ? '' : '.zip';

        // create a temporary file name to store stderr output
        $stdErrFile = tempnam(sys_get_temp_dir(), "stderr-");

        // zip files under the given path
        // by default, the files in archive will contain the path as part of their names
        // (e.g. if the path is '/a/b/c' then all files in archive will be in form of
        // 'a/b/c/...') - we don't want that; rather we want to archive files with names
        // relative to path, so we execute the 'zip' command from the parent path
        $pathBase    = basename($value);
        $pathParent  = dirname($value);
        $command     = "zip --symlinks -$compression -r " . $this->escapeshellarg($archive)
                     . " " . $this->escapeshellarg($pathBase);
        $descriptors = array(
            0 => array("pipe", "r"),               // stdin
            1 => array("pipe", "w"),               // stdout
            2 => array("file", $stdErrFile, "w")   // strerr
        );

        $pipes   = array();
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $pathParent
        );

        // check for proc_open error
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to archive '$value'.");
        }

        // read stdout pipe line by line and pass it to output callback if set
        $stdOutError = '';
        while ($line = fgets($pipes[1])) {
            // in some cases (e.g. when invalid command option) zip sends error message to stdout
            // so we try to detect errors here and if we find any, stop processing
            if (stripos($line, 'zip error:') === 0) {
                $stdOutError = $line;
                break;
            }

            // zip output contains status info for both files and dirs (those lines end with '/ (stored 0%)')
            // we skip output for dirs and pass only output for files to the callback
            if ($this->outputCallback && strpos($line, '/ (stored 0%)') === false) {
                call_user_func($this->outputCallback, $line);
            }
        }

        // close pipes
        @fclose($pipes[0]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $status = proc_close($process);
        $error  = file_get_contents($stdErrFile) ?: $stdOutError;
        unlink($stdErrFile);

        // check for failure (non-zero status)
        if ($status) {
            throw new \RuntimeException(
                "Failed to archive '$value'. Exit status: $status. Output: " . $error
            );
        }

        return $archive;
    }

    /**
     * Decompresses $value with the defined settings.
     *
     * @param   string  $value  data to decompress
     */
    public function decompress($value)
    {
        // this method is not implemented for command line version
        throw new \BadMethodCallException("Decompressing zip archives via command line is not implemented.");
    }

    /**
     * Determine whether the 'zip' command is installed
     *
     * @return bool     whether compression can be performed or not
     */
    public function hasZip()
    {
        // determine whether we can zip via command line
        exec('which zip', $output, $result);
        return $result === 0;
    }

    /**
     * UTF-8 compatible escapeshellarg. PHP's built-in function strips non-ascii characters.
     *
     * @param   string  $arg    string to escape
     * @return  string  escaped $arg
     * @todo    move it to more central location
     */
    protected function escapeshellarg($arg)
    {
        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }
}
