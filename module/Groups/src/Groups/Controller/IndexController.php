<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Groups\Controller;

use P4\Filter\Utf8 as Utf8Filter;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;
use Groups\Filter\Group as GroupFilter;
use Groups\Model\Group;
use Projects\Model\Project;
use Record\Cache\ArrayReader;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function groupsAction()
    {
        $services    = $this->getServiceLocator();
        $permissions = $services->get('permissions');
        $p4          = $services->get('p4_admin');
        $user        = $services->get('user')->getId();
        $request     = $this->getRequest();
        $format      = $request->getQuery('format');
        $keywords    = $request->getQuery('keywords');
        $viewHelpers = $services->get('viewhelpermanager');

        // for non-json requests, render the template and exit
        if ($format !== 'json') {
            return new ViewModel(
                array(
                    'keywords'     => $keywords,
                    'canAddGroups' => $permissions->is('groupAddAllowed')
                )
            );
        }

        $max             = $request->getQuery('max');
        $after           = $request->getQuery('after');
        $fields          = $request->getQuery('fields');
        $sort            = $request->getQuery('sort');
        $excludeProjects = $request->getQuery('excludeProjects');

        // normalize sort parameter(s)
        $sort   = is_array($sort) ? $sort : array_filter(explode(',', $sort));
        $sortBy = array();
        foreach ($sort as $field) {
            $reverse = substr($field, 0, 1) === '-';
            $field   = $reverse ? substr($field, 1) : $field;
            $sortBy += array($field => $reverse);
        }

        // do not allow sub-sorting by isInGroup (can only be a primary sort)
        if (isset($sortBy['isInGroup']) && key($sortBy) !== 'isInGroup') {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(array('error' => "Cannot sub-sort by isInGroup field."));
        }

        // fetch cached groups - note sorting groups can be quite slow, so we try
        // to cache the sorted list (ignoring isInGroup since that is user specific)
        $groups = $sortBy
            ? Group::getSortedCachedData(array_diff_key($sortBy, array('isInGroup' => null)), $p4)
            : Group::getCachedData($p4);

        // optionally sort by membership (we don't cache the in-group sort because it's per-user)
        if ($user && isset($sortBy['isInGroup'])) {
            $reverse    = $sortBy['isInGroup'];
            $groupIndex = $groups->getIndex();
            $userGroups = $p4->run('groups', array('-u', '-o', $user))->getData(null, 'group');
            $userGroups = array_intersect_key($groupIndex, array_flip($userGroups));
            $groups->setIndex($reverse ? $userGroups + $groupIndex : $groupIndex + $userGroups);
        }

        // now that we have groups (optionally sorted), let's seek past 'after'
        // we do this outside of the groups loop below because it avoids unserializing
        if ($after) {
            $position = $groups->getKeyPosition($after);
            $groups->slice($position ? ($position + 1) : $groups->count());
        }

        // split keywords into words.
        $keywords = array_filter(preg_split('/[\s,]+/', $keywords), 'strlen');

        // prepare list of fields to include in result
        $groupFields = array_keys($groups->current() ?: array());
        $extraFields = array(
            'name',
            'description',
            'emailFlags',
            'ownerAvatars',
            'memberCount',
            'isEmailEnabled',
            'isMember',
            'isInGroup'
        );
        $fields = (array) $fields ?: array_merge($groupFields, $extraFields);

        // build the result set
        $result    = array();
        $utf8      = new Utf8Filter;
        $avatar    = $viewHelpers->get('avatar');
        $preformat = $viewHelpers->get('preformat');

        foreach ($groups as $group) {
            // if we have surpassed our max limit, bail.
            if ($max && count($result) >= $max) {
                break;
            }

            // optionally exclude projects
            if ($excludeProjects && strpos($group['Group'], Project::KEY_PREFIX) === 0) {
                continue;
            }

            $group  = Group::fromArray($group, $p4, true);
            $config = $group->getConfig();

            // optionally match keywords against id, name and description
            if ($keywords) {
                foreach ($keywords as $keyword) {
                    if (stripos($group->getId(), $keyword) === false
                        && stripos($config->getName(), $keyword) === false
                        && stripos($config->getDescription(), $keyword) === false
                    ) {
                        continue 2;
                    }
                }
            }

            $data = array();
            foreach ($fields as $field) {
                switch ($field) {
                    case 'config':
                        $value = $config->get();
                        break;
                    case 'name':
                        $value = $config->getName();
                        break;
                    case 'description':
                        $value = (string) $preformat($config->getDescription());
                        break;
                    case 'emailFlags':
                        $value = $group->getConfig()->getEmailFlags();
                        break;
                    case 'isEmailEnabled':
                        $value = (bool) array_filter($config->getEmailFlags());
                        break;
                    case 'isMember':
                        $value = $user
                            ? Group::isMember($user, $group->getId(), true, $p4)
                            : null;
                        break;
                    case 'isInGroup':
                        if (!$user) {
                            $value = null;
                        } elseif (isset($userGroups)) {
                            $value = isset($userGroups[$group->getId()]);
                        } else {
                            $value = Group::isMember($user, $group->getId(), true, $p4)
                                || in_array($user, $group->getOwners());
                        }
                        break;
                    case 'memberCount':
                        $value = count(Group::fetchAllMembers($group->getId(), false, null, null, $p4));
                        break;
                    case 'ownerAvatars':
                        $value = array();
                        foreach ($group->getOwners() as $owner) {
                            $value[] = $avatar($owner, 32, true, null, false);
                        }
                        break;
                    default:
                        // skip invalid fields
                        if (!$group->hasField($field)) {
                            continue 2;
                        }

                        // though unexpected, some fields (Group) can include invalid UTF-8 sequences
                        // so we filter them, otherwise json encoding could crash with an error
                        $value = $utf8->filter($group->get($field));
                }
                $data[$field] = $value;
            }

            $result[] = $data;
            $lastSeen = $group->getId();
        }

        return new JsonModel(
            array(
                'groups'   => $result,
                'lastSeen' => isset($lastSeen) ? $lastSeen : null
            )
        );
    }

    public function addAction()
    {
        // ensure user is permitted to add groups
        $this->getServiceLocator()->get('permissions')->enforce('groupAddAllowed');

        // by default add generates the id from the name
        $route      = $this->getEvent()->getRouteMatch();
        $idFromName = $route->getParam('idFromName', true);

        return $this->doAddEdit(GroupFilter::MODE_ADD, null, $idFromName);
    }

    public function editAction()
    {
        // before we call the doAddEdit method we need to ensure the
        // group exists and the user has rights to edit it.
        $group = $this->getRequestedGroup();
        if (!$group) {
            return;
        }

        // only Perforce super users or group owners can edit the group
        $this->getServiceLocator()->get('permissions')->enforceOne(array('super', 'owner' => $group));

        // ensure the id in the post is the value passed in the url.
        // we don't want to risk having differing opinions.
        $this->getRequest()->getPost()->set('Group', $group->getId());

        return $this->doAddEdit(GroupFilter::MODE_EDIT, $group);
    }

    public function groupAction()
    {
        $group = $this->getRequestedGroup();
        if (!$group) {
            return;
        }

        if ($this->getRequest()->getQuery('format') !== 'json') {
            return new ViewModel(array('group' => $group));
        }

        return new JsonModel(
            array('group' => $group->get() + array('config' => $group->getConfig()->get()))
        );
    }

    public function deleteAction()
    {
        $services    = $this->getServiceLocator();
        $translator  = $services->get('translator');
        $permissions = $services->get('permissions');
        $p4Admin     = $services->get('p4_admin');
        $p4User      = $services->get('p4_user');

        // request must be a post or delete
        $request = $this->getRequest();
        if (!$request->isPost() && !$request->isDelete()) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => $translator->t('Invalid request method. HTTP POST or HTTP DELETE required.')
                )
            );
        }

        // attempt to retrieve the specified group to delete
        $group = $this->getRequestedGroup();
        if (!$group) {
            return new JsonModel(
                array(
                    'isValid'   => false,
                    'error'     => $translator->t('Cannot delete group: group not found.')
                )
            );
        }

        // ensure only super users or group owners can delete the entry
        $this->getServiceLocator()->get('permissions')->enforceOne(array('super', 'owner' => $group));

        // delete the group in Perforce and associated config
        // pass the user's connection for spec delete so the -a flag is used if necessary
        $group->delete($p4User);

        // invalidate groups cache since groups have changed
        $p4Admin->getService('cache')->invalidateItem('groups');

        return new JsonModel(
            array(
                'isValid' => true,
                'id'      => $group->getId()
            )
        );
    }

    public function reviewsAction()
    {
        $query = $this->getRequest()->getQuery();
        $group = $this->getRequestedGroup();
        if (!$group) {
            return;
        }

        // forward json requests to the reviews module
        if ($query->get('format') === 'json') {
            // if query doesn't already contain a filter for group, add one
            $query->set('group', $query->get('group') ?: $group->getId());

            return $this->forward()->dispatch(
                'Reviews\Controller\Index',
                array('action' => 'index')
            );
        }

        return new ViewModel(
            array(
                'group' => $group
            )
        );
    }

    /**
     * This is a shared method to power both add and edit actions.
     *
     * @param   string          $mode           one of 'add' or 'edit'
     * @param   Group|null      $group          only passed on edit, the group for starting values
     * @param   bool            $idFromName     only passed on add, use the name to generate an id
     * @return  ViewModel       the data needed to render an add/edit view
     */
    protected function doAddEdit($mode, Group $group = null, $idFromName = false)
    {
        $services    = $this->getServiceLocator();
        $permissions = $services->get('permissions');
        $p4User      = $services->get('p4_user');
        $p4Admin     = $services->get('p4_admin');
        $config      = $services->get('config');
        $request     = $this->getRequest();

        // decide whether user can edit group name
        $nameAdminOnly = isset($config['groups']['edit_name_admin_only'])
            ? (bool) $config['groups']['edit_name_admin_only']
            : false;
        $canEditName = !$nameAdminOnly || $services->get('permissions')->is('admin');

        if ($request->isPost()) {
            $data   = $request->getPost();
            $filter = $services->get('InputFilterManager')->get('GroupFilter');

            // optionally set the id from the name
            if (isset($data['name']) && $idFromName) {
                $data['Group'] = $filter->nameToId($data['name']);
            }

            // set up our filter with data and the add/edit mode
            $filter->setMode($mode)
                   ->verifyNameAsId($idFromName)
                   ->setData($data);

            // mark name field not-allowed if user cannot modify it
            // this will cause an error if data for this field is posted
            if ($group && !$canEditName) {
                $filter->setNotAllowed('name');
            }

            // if we are in edit mode, set the validation group to process
            // only defined fields we received posted data for
            if ($filter->isEdit()) {
                $filter->setValidationGroup(array_keys($data->toArray()));
            }

            // if the data is valid, setup the group and save it
            $isValid = $filter->isValid();
            if ($isValid) {
                $values = $filter->getValues();

                // save the group
                // limit the values we set to just those that are explicitly defined
                // this keeps spec fields and config fields separate and avoids tainting the config
                $group        = $group ?: new Group($p4Admin);
                $config       = $group->getConfig();
                $editAsOwner  = $filter->isEdit() && $permissions->is(array('owner' => $group));
                $addAsAdmin   = $filter->isAdd()  && $permissions->is('admin') && !$permissions->is('super');
                $groupFields  = array_flip($group->getDefinedFields());
                $configFields = array_flip($config->getDefinedFields());
                $config->set(array_intersect_key($values, $configFields));
                $group->setId($values['Group'])
                      ->set(array_intersect_key($values, $groupFields))
                      ->save($editAsOwner, $addAsAdmin, $p4User);

                // invalidate groups cache since groups have changed
                $p4Admin->getService('cache')->invalidateItem('groups');
            }

            return new JsonModel(
                array(
                    'isValid'  => $isValid,
                    'messages' => $filter->getMessages(),
                    'group'    => $group ? $group->get() + array('config' => $group->getConfig()->get()) : null,
                    'redirect' => $this->url()->fromRoute('group', array('group' => $filter->getValue('Group')))
                )
            );
        }

        // prepare view for form
        $view = new ViewModel;
        $view->setVariables(
            array(
                'mode'        => $mode,
                'group'       => $group ?: new Group,
                'canEditName' => $canEditName
            )
        );

        return $view;
    }

    /**
     * Helper method to return model of requested group or false if group
     * id is missing or invalid.
     *
     * @return  Group|false  group model or false if group id is missing or invalid
     */
    protected function getRequestedGroup()
    {
        $id      = $this->getEvent()->getRouteMatch()->getParam('group');
        $p4Admin = $this->getServiceLocator()->get('p4_admin');

        // attempt to retrieve the specified group
        // translate invalid/missing id's into a 404
        try {
            return Group::fetch($id, $p4Admin);
        } catch (SpecNotFoundException $e) {
        } catch (\InvalidArgumentException $e) {
        }

        $this->getResponse()->setStatusCode(404);
        return false;
    }
}
