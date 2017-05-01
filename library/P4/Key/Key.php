<?php
/**
 * Abstracts operations against Perforce keys.
 * We just extend the counter abstract, tweak the type and implement a set
 * and a delete method which don't accept the force parameter.
 *
 * This class is somewhat unique as calling set will immediately write the new value
 * to perforce; no separate save step is required.
 * When reading values out we do attempt to use cached results, to ensure you read
 * out the value directly from perforce set $force to true when calling get.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Key;

use P4\Connection\ConnectionInterface;
use P4\Counter\AbstractCounter;
use P4\Model\Connected;

class Key extends AbstractCounter
{
    const   FETCH_BY_IDS        = 'ids';

    // ensure -u is included to all p4 counter and p4 counters calls
    protected static $flags     = array('-u');

    /**
     * Get all Counters from Perforce.
     *
     * @param   array   $options    optional - array of options to augment fetch behavior.
     *                              supported options are:
     *                                   FETCH_MAXIMUM - set to integer value to limit to the first
     *                                                   'max' number of entries.
     *                                                   Note: Max limit is imposed client side on <2013.1.
     *                                   FETCH_BY_NAME - set to string value to limit to counters
     *                                                   matching the given name/pattern.
     *                                    FETCH_BY_IDS - provide an array of ids to fetch.
     *                                                   not compatible with FETCH_BY_NAME or FETCH_AFTER.
     *                                     FETCH_AFTER - set to an id _after_ which we start collecting
     * @param   ConnectionInterface     $connection  optional - a specific connection to use.
     * @return  Connected\Iterator      all counters matching passed option(s).
     */
    public static function fetchAll($options = array(), ConnectionInterface $connection = null)
    {
        // normalize options to make our lives a bit easier
        $options += array(
            static::FETCH_BY_IDS  => null,
            static::FETCH_AFTER   => null,
            static::FETCH_BY_NAME => null,
            static::FETCH_MAXIMUM => null
        );

        // if fetch by ids wasn't passed just let parent handle it.
        if (!is_array($options[static::FETCH_BY_IDS])) {
            return parent::fetchAll($options, $connection);
        }

        // if no ids were specified just return an empty iterator
        // continuing on would otherwise return everything.
        if (empty($options[static::FETCH_BY_IDS])) {
            return new Connected\Iterator;
        }

        // if the included fetch after or fetch by name blow up, we don't support it.
        if ($options[static::FETCH_AFTER] || $options[static::FETCH_BY_NAME]) {
            throw new \InvalidArgumentException(
                'It is not valid to pass fetch by ids and also specify fetch after or fetch by name.'
            );
        }

        $connection = $connection ?: static::getDefaultConnection();
        $max        = (int) $options[static::FETCH_MAXIMUM];
        $ids        = (array) $options[static::FETCH_BY_IDS];
        $keys       = new Connected\Iterator;
        $params     = static::$flags;

        // Older servers (<13.1) do not support multiple -e args. So we issue one command per ID.
        // Because IDs can contain wildcards we need to enforce max limiting here (client side).
        if (!$connection->isServerMinVersion('2013.1')) {
            $seen = 0;
            foreach ($ids as $id) {
                // populate a key and add it to the iterator
                try {
                    $keys->merge(
                        static::fetchAll(
                            array(
                                static::FETCH_BY_NAME => $id,
                                static::FETCH_MAXIMUM => $max ? $max - $seen : $max
                            ),
                            $connection
                        )
                    );
                } catch (\Exception $e) {
                    // assume id was invalid or key doesn't exist, ignore
                }

                // if max is enabled and we've seen enough; we're done
                $seen = count($keys);
                if ($max && $seen >= $max) {
                    break;
                }
            }

            return $keys;
        }

        // if we made it here our p4d server supports specifying multiple -e args
        // start by setting up all of the args so we can batch them
        $args = array();
        foreach ($ids as $id) {
            $args[] = '-e';
            $args[] = $id;
        }

        // add max to our prefix args if a value has been specified
        if ($max) {
            $params[] = '-m';
            $params[] = $max;
        }

        // run each batch of arguments
        $batches = $connection->batchArgs($args, $params, null, 2);
        foreach ($batches as $batch) {
            // if we have a max update the batch's limit as we progress
            if ($max) {
                $key = array_search('-m', $batch) + 1;
                $batch[$key] = $max - count($keys);
            }

            // execute this batch and process results into keys
            $result = $connection->run('counters', $batch);
            foreach ($result->getData() as $data) {
                // populate a key and add it to the iterator
                try {
                    $key = new static($connection);
                    $key->setId($data['counter']);
                    $key->value = $data['value'];
                } catch (\InvalidArgumentException $e) {
                    // assume id was invalid - ignore.
                    continue;
                }

                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Set key's value. The value will be immediately written to perforce.
     *
     * @param   mixed   $value  the value to set in the key.
     * @return  Key             provides a fluent interface
     * @throws  Exception       if no Id has been set
     */
    public function set($value)
    {
        return parent::doSet($value);
    }

    /**
     * Delete this key entry.
     *
     * @return  Key             provides a fluent interface
     * @throws  Exception       if no id has been set.
     */
    public function delete()
    {
        return parent::doDelete();
    }
}
