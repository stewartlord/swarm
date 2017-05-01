<?php
/**
 * P4PHP Perforce limit handler.
 *
 * This handler allows the user to specify a maximum number of output blocks
 * to report. If more are received, they will go unreported and the command
 * cancelled.
 *
 * Additionally, you can specify an 'filter' callback. If set, blocks failing
 * the filter will not be reported in the result and won't count against max
 * if a limit has been specified.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\OutputHandler;

class Limit extends \P4_OutputHandlerAbstract
{
    // these constants are also defined by the perforce extension
    // but we duplicate a copy here for clarity and convenience.
    const HANDLER_REPORT        = 0;
    const HANDLER_HANDLED       = 1;
    const HANDLER_CANCEL        = 2;

    const FILTER_INCLUDE        = true;
    const FILTER_EXCLUDE        = false;
    const FILTER_SKIP           = null;

    protected $outputCallback   = null;
    protected $filterCallback   = null;
    protected $countAll         = false;

    protected $max              = 0;
    protected $count            = 0;
    protected $total            = 0;
    protected $cancelled        = false;

    /**
     * The output function will be called for each output block.
     * The passed 'callable' will receive two params:
     *  $data - string or array of data being output
     *  $type - one of stat, info, text, binary
     *
     * The callback should return one or more bit flags to control
     * reporting/cancelling:
     *  HANDLER_REPORT  = 0;
     *  HANDLER_HANDLED = 1;
     *  HANDLER_CANCEL  = 2;
     *
     * The output callback won't see any data blocks that have been
     * blocked via the filter callback. It will also stop being called
     * once 'max' blocks have been seen if a max is in use.
     *
     * @param   callable|null   $callback   the output function to use or null
     * @return  Limit           to maintain a fluent interface
     * @throws  \InvalidArgumentException   if callback isn't callable or null
     */
    public function setOutputCallback($callback = null)
    {
        if (!is_callable($callback) && !is_null($callback)) {
            throw new \InvalidArgumentException('Output callback must be callable or null.');
        }

        $this->outputCallback = $callback;

        return $this;
    }

    /**
     * Return the current output callback or null if none set.
     *
     * @return  callable|null   the current output callback or null
     */
    public function getOutputCallback()
    {
        return $this->outputCallback;
    }

    /**
     * The filter function will be called for each output block and can return
     * true to allow the output block into the result or false to screen it out.
     * The passed 'callable' will receive two params:
     *  $data   - string or array of data being output
     *  $type   - one of stat, info, text, binary
     *
     * Filtered results will not count towards 'max' if a limit is set.
     *
     * @param   callable|null   $filter     the filter to use or null
     * @return  Limit           to maintain a fluent interface
     * @throws  \InvalidArgumentException   if filter isn't callable or null
     */
    public function setFilterCallback($filter = null)
    {
        if (!is_callable($filter) && !is_null($filter)) {
            throw new \InvalidArgumentException('Filter must be callable or null.');
        }

        $this->filterCallback = $filter;

        return $this;
    }

    /**
     * Return the current filter callback or null if none set.
     *
     * @return  callable|null   the current 'filter' callback or null
     */
    public function getFilterCallback()
    {
        return $this->filterCallback;
    }

    /**
     * Specify a the maximum number of entries to return or leave 0/null for all.
     * If a 'filter' callback is in place values failing the filter don't count.
     *
     * @param   int|null    $max    the maximum number of entries to return (or 0/null for unlimited)
     * @return  Limit       to maintain a fluent interface
     */
    public function setMax($max)
    {
        $this->max = (int) $max;

        return $this;
    }

    /**
     * Returns the current max setting.
     *
     * @return  int|null    the current 'max' setting (0/null for unlimited)
     */
    public function getMax()
    {
        return $this->max;
    }

    /**
     * Returns the 'total' value.
     *
     * @return  int     the current 'total' value
     */
    public function getTotalCount()
    {
        return $this->total;
    }

    /**
     * Specify whether output will be cancelled after maximum entries was reached
     *
     * @param   bool    $countAll   if true then output will not be cancelled after
     *                              reaching maximum entries
     * @return  Limit   to maintain a fluent interface
     */
    public function setCountAll($countAll)
    {
        $this->countAll = (bool) $countAll;

        return $this;
    }

    /**
     * Return the current countAll setting.
     *
     * @return  bool    the current countAll setting
     */
    public function getCountAll()
    {
        return $this->countAll;
    }

    /**
     * Called automatically by 'runHandler' to reset per-run tracking
     * variables. (i.e. our max progress).
     *
     * @return  Limit   to maintain a fluent interface
     */
    public function reset()
    {
        $this->count     = 0;
        $this->total     = 0;
        $this->cancelled = false;

        return $this;
    }

    /**
     * Simply calls through to handle output for stat blocks.
     *
     * @param   mixed   $data   the data being output
     * @return  int     handler bit flags controlling reporting/cancelling
     */
    public function outputStat($data)
    {
        return $this->handleOutput($data, 'stat');
    }

    /**
     * Simply calls through to handle output for info blocks.
     *
     * @param   mixed   $data   the data being output
     * @return  int     handler bit flags controlling reporting/cancelling
     */
    public function outputInfo($data)
    {
        return $this->handleOutput($data, 'info');
    }

    /**
     * Simply calls through to handle output for text blocks.
     *
     * @param   string  $data   the data being output
     * @return  int     handler bit flags controlling reporting/cancelling
     */
    public function outputText($data)
    {
        return $this->handleOutput($data, 'text');
    }

    /**
     * Simply calls through to handle output for binary blocks.
     *
     * @param   string  $data   the data being output
     * @return  int     handler bit flags controlling reporting/cancelling
     */
    public function outputBinary($data)
    {
        return $this->handleOutput($data, 'binary');
    }

    /**
     * Check if the last command was cancelled.
     *
     * @return  bool    true if the last command was cancelled, false otherwise
     */
    public function wasCancelled()
    {
        return $this->cancelled;
    }

    /**
     * We tell the output function not to report if we have a filter and
     * the data does not pass. These skipped entries won't count against max.
     *
     * If a output callback is in use, it will be invoked for every
     * block that passes the filter callback (up to the max limit).
     *
     * Lastly, if we received more than 'max' entries (assuming a limit
     * has been set) we will skip reporting additional data and cancel.
     *
     * @param   mixed   $data   the data being output
     * @param   string  $type   one of stat, info, text or binary
     * @return  int     handler bit flags controlling reporting/cancelling
     */
    protected function handleOutput($data, $type)
    {
        $isMaxReached = $this->max && $this->count >= $this->max;

        // if we get more entries back than needed and we are not counting all,
        // skip them and cancel
        if ($isMaxReached && !$this->getCountAll()) {
            $this->cancelled = true;
            return self::HANDLER_HANDLED | self::HANDLER_CANCEL;
        }

        // filter entries prior to counting them or passing to output callback
        $filterCallback = $this->getFilterCallback();
        $filterResult   = $filterCallback ? $filterCallback($data, $type) : static::FILTER_INCLUDE;

        // update 'count' and 'total' counters based on the filter result
        $this->count += $filterResult === static::FILTER_INCLUDE ? 1 : 0;
        $this->total += $filterResult !== static::FILTER_EXCLUDE ? 1 : 0;

        // return handled if we don't hit good entry or are over maximum
        if ($filterResult !== static::FILTER_INCLUDE || $isMaxReached) {
            return self::HANDLER_HANDLED;
        }

        // if we have an output callback return its result otherwise return REPORT
        $outputCallback  = $this->getOutputCallback();
        $outputResult    = $outputCallback ? $outputCallback($data, $type) : self::HANDLER_REPORT;
        $this->cancelled = $this->cancelled || ($outputResult & self::HANDLER_CANCEL);

        return $outputResult;
    }
}
