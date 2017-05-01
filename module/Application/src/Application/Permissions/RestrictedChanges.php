<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Permissions;

use P4\Model\Connected\ConnectedAbstract;
use P4\Model\Fielded\FieldedInterface;
use Traversable;

class RestrictedChanges extends ConnectedAbstract
{
    /**
     * Filter the given list to remove elements that refer to restricted changes
     * that the current user/connection is not permitted to access. If a element
     * refers to a change that does not exist, it will also be filtered out.
     *
     * @param   array|Traversable       $items  list to filter
     * @param   int|string|callable     $pluck  a field name, item method or callable to extract change id from item
     * @return  array|Traversable       the original list, with elements that refer to forbidden changes removed
     * @throws  \InvalidArgumentException   if items or pluck are not valid
     */
    public function filter($items, $pluck)
    {
        if (!is_array($items) && !$items instanceof Traversable) {
            throw new \InvalidArgumentException("Items must be an array or traversable object.");
        }
        if (!is_callable($pluck) && !strlen($pluck)) {
            throw new \InvalidArgumentException("Pluck must be an field name, method name or a callable.");
        }

        // extract change ids to build params for a single (batched) p4 changes run
        $params = array();
        foreach ($items as $item) {
            $change = $this->pluckChange($item, $pluck);
            if (is_int($change) || ctype_digit($change)) {
                $params[] = "@$change,@$change";
            }
        }

        // if we managed to extract any change ids, filter them by running changes
        // changes will only return changes that the current user/connection has access to
        $changes = $params
            ? array_map('current', $this->connection->run('changes', $params)->getData())
            : array();

        // now filter original list - items with a null change are exempt
        $remove = array();
        foreach ($items as $key => $item) {
            $change = $this->pluckChange($item, $pluck);
            if ($change !== null && !in_array($change, $changes)) {
                $remove[] = $key;
            }
        }
        foreach ($remove as $key) {
            unset($items[$key]);
        }

        reset($items);
        return $items;
    }

    /**
     * Check if the current user/connection is permitted to access the given change.
     *
     * @param   int|string|null     the number of the change to check for access on
     *                              always returns true for null to be consistent with filter()
     * @return  bool                true if the user can access the change, false otherwise.
     */
    public function canAccess($change)
    {
        if (is_null($change)) {
            return true;
        }
        if (!is_int($change) && !ctype_digit($change)) {
            throw new \InvalidArgumentException("Change must be purely numeric.");
        }

        return $this->connection->run('changes', "@$change,@$change")->hasData();
    }

    protected function pluckChange($item, $pluck)
    {
        if (!is_scalar($pluck) && is_callable($pluck)) {
            return call_user_func($pluck, $item);
        } elseif (is_object($item) && is_string($pluck) && method_exists($item, $pluck)) {
            return $item->$pluck();
        } elseif ($item instanceof FieldedInterface && is_string($pluck) && $item->hasField($pluck)) {
            return $item->get($pluck);
        } elseif (is_array($item) && isset($item[$pluck])) {
            return $item[$pluck];
        }

        return null;
    }
}
