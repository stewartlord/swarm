<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Api\Controller;

use Api\AbstractApiController;
use Zend\Http\Request;
use Zend\View\Model\JsonModel;

/**
 * Swarm Groups
 *
 * @SWG\Resource(
 *   apiVersion="v2",
 *   basePath="/api/v2/"
 * )
 */
class GroupsController extends AbstractApiController
{
    /**
     * @SWG\Api(
     *     path="groups/",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get List of Groups",
     *         notes="Returns the complete list of groups in Swarm.",
     *         nickname="listGroups",
     *         @SWG\Parameter(
     *             name="after",
     *             description="A group ID to seek to. Groups up to and including the specified id will be excluded
     *                          from the results and do not count towards max. Useful for pagination. Commonly set to
     *                          the 'lastSeen' property from a previous query.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="max",
     *             description="Maximum number of groups to return. This does not guarantee that 'max' groups will
     *                          be returned. It does guarantee the number of groups returned won't exceed 'max'.",
     *             paramType="query",
     *             type="integer",
     *             defaultValue="100",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show for each group.
     *                          Omitting this parameter or passing an empty value shows all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="keywords",
     *             description="Keywords to limit groups on. Only groups where the group ID, group name (if set), or
     *                          description contain the specified keywords will be returned.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         )
     *     )
     * )
     *
     * @apiUsageExample Listing groups
     *
     *   To list groups:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        "https://myswarm.url/api/v2/groups?keywords=test-group&fields=Group,Owners,Users,config&max=2"
     *   ```
     *
     *   Swarm responds with a list of groups:
     *
     *   ```json
     *   {
     *     "groups": [
     *       {
     *         "Group": "test-group",
     *         "Owners": [],
     *         "Users": ["nonadmin"],
     *         "config": {
     *           "description": "Our testing group",
     *           "emailFlags": {
     *             "reviews": "1",
     *             "commits": "0"
     *           },
     *           "name": "Test Group"
     *         }
     *       },
     *       {
     *         "Group": "test-group2",
     *         "Owners": [],
     *         "Users": ["nonadmin"],
     *         "config": {
     *           "description": "Our second testing group",
     *           "emailFlags": [],
     *           "name": "Test Group 2"
     *         }
     *       }
     *     ],
     *     "lastSeen": "test-group2"
     *   }
     *   ```
     *
     * @apiUsageExample Paginating the groups list
     *
     *   Based on the previous example, we can pass a lastSeen value of "test-group2" to see if there are any subsequent
     *   groups in Swarm.
     *
     *   ```bash
     *   curl -u "username:password" \
     *        "https://myswarm.url/api/v2/groups?keywords=test-group&fields=Group,config&max=2&lastSeen=test-group2"
     *   ```
     *
     *   Swarm responds with a list of groups (minus the Owners and Users fields, as we haven't requested them):
     *
     *   ```json
     *   {
     *     "groups": [
     *       {
     *         "Group": "test-group3",
     *         "config": {
     *           "description": "Our 3rd testing group",
     *           "emailFlags": [],
     *           "name": "Test Group 3"
     *         }
     *       }
     *     ],
     *     "lastSeen": "test-group3"
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "groups": [
     *         {
     *           "Group": "test-group",
     *           "MaxLockTime": null,
     *           "MaxResults": null,
     *           "MaxScanRows": null,
     *           "Owners": [],
     *           "PasswordTimeout": null,
     *           "Subgroups": [],
     *           "Timeout": 43200,
     *           "Users": ["nonadmin"],
     *           "config": {
     *             "description": "Our testing group",
     *             "emailFlags": [],
     *             "name": "Test Group"
     *           }
     *         }
     *       ]
     *     }
     *
     * @return mixed
     */
    public function getList()
    {
        $request = $this->getRequest();
        $fields  = $this->getRequest()->getQuery('fields');
        $result  = $this->forward(
            'Groups\Controller\Index',
            'groups',
            null,
            array(
                'max'      => $request->getQuery('max', 100),
                'after'    => $request->getQuery('after'),
                'keywords' => $request->getQuery('keywords'),
                'fields'   => is_string($fields) ? explode(',', $fields) : $fields,
            )
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * @SWG\Api(
     *     path="groups/{id}",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get Group Information",
     *         notes="Retrieve information about a group.",
     *         nickname="getGroup",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Group ID",
     *             paramType="path",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show for each group.
     *                          Omitting this parameter or passing an empty value shows all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\ResponseMessage(code=404, message="Not Found")
     *     )
     * )
     *
     * @apiUsageExample Fetching a group
     *
     *   To fetch an individual group:
     *
     *   ```bash
     *   curl -u "username:password" "https://myswarm.url/api/v2/groups/my-group"
     *   ```
     *
     *   Swarm responds with the group entity:
     *
     *   ```json
     *   {
     *     "group": {
     *       "Group": "test-group",
     *       "LdapConfig": null,
     *       "LdapSearchQuery": null,
     *       "LdapUserAttribute": null,
     *       "MaxLockTime": null,
     *       "MaxResults": null,
     *       "MaxScanRows": null,
     *       "Owners": [],
     *       "Users": ["nonadmin"],
     *       "config": {
     *         "description": "Our testing group",
     *         "emailFlags": [],
     *         "name": "Test Group"
     *       }
     *     }
     *   }
     *   ```
     *
     * @apiUsageExample Limiting returned fields
     *
     *   To limit the returned fields when fetching an individual group:
     *
     *   ```bash
     *   curl -u "username:password" "https://myswarm.url/api/v2/groups/my-group?fields=Group,Owners,Users,config"
     *   ```
     *
     *   Swarm responds with the group entity:
     *
     *   ```json
     *   {
     *     "group": {
     *       "Group": "test-group",
     *       "Owners": [],
     *       "Users": ["nonadmin"],
     *       "config": {
     *         "description": "Our testing group",
     *         "emailFlags": [],
     *         "name": "Test Group"
     *       }
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "group": {
     *         "Group": "test-group",
     *         "MaxLockTime": null,
     *         "MaxResults": null,
     *         "MaxScanRows": null,
     *         "Owners": [],
     *         "PasswordTimeout": null,
     *         "Subgroups": [],
     *         "Timeout": 43200,
     *         "Users": ["nonadmin"],
     *         "config": {
     *           "description": "Our testing group",
     *           "emailFlags": [],
     *           "name": "Test Group"
     *         }
     *       }
     *     }
     *
     * @param   string  $id     Group ID to fetch
     * @return  mixed
     */
    public function get($id)
    {
        $fields = $this->getRequest()->getQuery('fields');
        $result = $this->forward(
            'Groups\Controller\Index',
            'group',
            array('group' => $id)
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * @SWG\Api(
     *     path="groups/",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Create a new Group",
     *         notes="Creates a new group in Swarm.",
     *         nickname="createGroup",
     *         @SWG\Parameter(
     *             name="Group",
     *             description="Group identifier string.",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="Users",
     *             description="An optional array of group users.
     *                          At least one of Users, Owners, or Subgroups is required.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="Owners",
     *             description="An optional array of group owners.
     *                          At least one of Users, Owners, or Subgroups is required.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="Subgroups",
     *             description="An optional array of subgroups.
     *                          At least one of Users, Owners, or Subgroups is required.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="config[name]",
     *             description="An optional full name for the group.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="config[description]",
     *             description="An optional group description.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="config[emailFlags][commits]",
     *             description="Email members when a change is committed.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="config[emailFlags][reviews]",
     *             description="Email members when a new review is requested.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         )
     *     )
     * )
     *
     * @apiUsageExample Creating a group
     *
     *   Important:
     *
     *   ```
     *     - Only users with super privileges in the Helix Versioning Engine (p4d),
     *       or users with admin privileges in p4d versions 2012.1 or newer, can
     *       create groups.
     *     - This API version is only capable of setting specific fields:
     *          Group, Users, Owners, Subgroups, config
     *       Any other fields specified in the creation request are ignored.
     *   ```
     *
     *   To create a new group:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -d "Group=my-group" \
     *        -d "Owners[]=alice" \
     *        -d "Owners[]=bob" \
     *        -d "Users[]=bruno" \
     *        -d "Users[]=user2" \
     *        -d "config[description]=This group is special to me." \
     *        -d "config[name]=My Group" \
     *        -d "config[emailFlags][reviews]=1" \
     *        -d "config[emailFlags][commits]=0" \
     *        "https://myswarm.url/api/v2/groups"
     *   ```
     *
     *   Assuming that the authenticated user has permission, Swarm responds with the new group entity:
     *
     *   ```json
     *   {
     *     "group": {
     *       "Group": "my-group",
     *       "MaxLockTime": null,
     *       "MaxResults": null,
     *       "MaxScanRows": null,
     *       "Owners": ["username"],
     *       "PasswordTimeout": null,
     *       "Subgroups": [],
     *       "Timeout": null,
     *       "Users": [],
     *       "config": {
     *         "description": "This group is special to me.",
     *         "emailFlags": {
     *           "reviews": "1",
     *           "commits": "0"
     *         },
     *         "name": "My Group"
     *       }
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "group": {
     *         "Group": "test-group",
     *         "MaxLockTime": null,
     *         "MaxResults": null,
     *         "MaxScanRows": null,
     *         "Owners": [],
     *         "PasswordTimeout": null,
     *         "Subgroups": [],
     *         "Timeout": null,
     *         "Users": ["alice"],
     *         "config": {
     *           "description": "Test test test",
     *           "emailFlags": [],
     *           "name": "TestGroup"
     *         }
     *       }
     *     }
     *
     * @param mixed $data
     * @return JsonModel
     */
    public function create($data)
    {
        $data   = $this->flattenGroupInput($data);
        $result = $this->forward('Groups\Controller\Index', 'add', array('idFromName' => false), null, $data);

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="groups/{id}",
     *     @SWG\Operation(
     *         method="PATCH",
     *         summary="Edit a Group",
     *         notes="Change the settings of a group in Swarm.
     *                Only super users and group owners can perform this action.",
     *         nickname="patchGroup",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Group ID",
     *             paramType="path",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="Users",
     *             description="An optional array of group users.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="Owners",
     *             description="An optional array of group owners.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="Subgroups",
     *             description="An optional array of group subgroups.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="config[name]",
     *             description="An optional full name for the group.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="config[description]",
     *             description="An optional group description.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="config[emailFlags][commits]",
     *             description="Email members when a change is committed.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="config[emailFlags][reviews]",
     *             description="Email members when a new review is requested.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         )
     *     )
     * )
     *
     * @apiUsageExample Editing a group
     *
     *   Important:
     *
     *   ```
     *     - Only user with super privileges in the Helix Versioning Engine, or group
     *       owners, can edit groups.
     *     - This API version is only capable of modifying specific fields:
     *          Users, Owners, Subgroups, config
     *       An error can occur if other fields are specified in the edit request.
     *   ```
     *
     *   Here is how to update the name, description, and email flags of the group 'my-group':
     *
     *   ```bash
     *   curl -u "username:password" -X PATCH \
     *        -d "config[description]=This group is special to me." \
     *        -d "config[name]=My Group" \
     *        -d "config[emailFlags][commit]=1" \
     *        "https://myswarm.url/api/v2/groups/my-group"
     *   ```
     *
     *   Assuming that the authenticated user has permission, Swarm responds with the modified group entity:
     *
     *   ```json
     *   {
     *     "group": {
     *       "Group": "my-group",
     *       "Users": [],
     *       "Owners": [],
     *       "Subgroups": [],
     *       "config": {
     *         "description": "This group is special to me.",
     *         "emailFlags": {
     *           "reviews": "1",
     *           "commits": "1"
     *         },
     *         "name": "My Group"
     *       }
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "group": {
     *         "Group": "test-group",
     *         "Users": [],
     *         "Owners": [],
     *         "Subgroups": [],
     *         "config": {
     *           "description": "New Group Description",
     *           "name": "TestGroup"
     *         }
     *       }
     *     }
     *
     * @param mixed $data
     * @return JsonModel
     */
    public function patch($id, $data)
    {
        $this->getRequest()->setMethod(Request::METHOD_POST);
        $data   = $this->flattenGroupInput($data);
        $result = $this->forward('Groups\Controller\Index', 'edit', array('group' => $id), null, $data);

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="groups/{id}",
     *     @SWG\Operation(
     *         method="DELETE",
     *         summary="Delete a Group",
     *         notes="Delete a group. Only super users and group owners can perform this action.",
     *         nickname="deleteGroup",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Group ID.",
     *             paramType="path",
     *             type="string",
     *             required=true
     *         )
     *     )
     * )
     *
     * @apiUsageExample Deleting a group
     *
     *   Important: Only super users and group owners can delete groups.
     *
     *   ```bash
     *   curl -u "username:password" -X DELETE "https://myswarm.url/api/v2/groups/my-group"
     *   ```
     *
     *   Assuming that the authenticated user has permission, Swarm responds with the id of the deleted group:
     *
     *   ```json
     *   {
     *     "id": "my-group"
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "id": "test-group"
     *     }
     *
     * @param mixed $id
     * @return JsonModel
     */
    public function delete($id)
    {
        $result = $this->forward('Groups\Controller\Index', 'delete', array('group' => $id));

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Extends parent to provide special preparation of group data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits entity output to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);
        $group = $model->getVariable('group');

        if ($group) {
            $model->setVariable('group', $this->normalizeGroup($group, $limitEntityFields));
        }

        // if a list of groups is present, normalize each one
        $groups = $model->getVariable('groups');
        if ($groups) {
            foreach ($groups as $key => $group) {
                $groups[$key] = $this->normalizeGroup($group, $limitEntityFields);
            }

            $model->setVariable('groups', $groups);
        }

        return $model;
    }

    protected function normalizeGroup($group, $limitEntityFields = null)
    {
        $group = $this->sortEntityFields($group);
        unset(
            $group['name'],
            $group['description'],
            $group['emailFlags'],
            $group['isEmailEnabled'],
            $group['isMember'],
            $group['isInGroup'],
            $group['memberCount'],
            $group['ownerAvatars']
        );

        // move config to the end and sub-sort
        $config = isset($group['config']) && is_array($group['config']) ? $group['config'] : array();
        unset($group['config'], $config['id']);
        $group['config'] = $this->sortEntityFields($config);

        return $this->limitEntityFields($group, $limitEntityFields);
    }

    protected function flattenGroupInput($data)
    {
        if (isset($data['config'])) {
            $data += array_intersect_key($data['config'], array_flip(array('name', 'description', 'emailFlags')));
            unset($data['config']);
        }

        return $data;
    }
}
