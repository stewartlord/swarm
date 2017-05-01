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
 * Swarm Projects
 *
 * @SWG\Resource(
 *   apiVersion="v2",
 *   basePath="/api/v2/"
 * )
 */
class ProjectsController extends AbstractApiController
{
    /**
     * @SWG\Api(
     *     path="projects/",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get List of Projects",
     *         notes="Returns the complete list of projects in Swarm.",
     *         nickname="listProjects",
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show for each project.
     *                          Omitting this parameter or passing an empty value shows all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         )
     *     )
     * )
     *
     * @apiUsageExample Listing projects
     *
     *   To list all projects:
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v2/projects?fields=id,description,members,name"
     *   ```
     *
     *   Pagination is not currently supported by this endpoint. Swarm responds with a list of all projects:
     *
     *   ```json
     *   {
     *     "projects": [
     *       {
     *         "id": "testproject",
     *         "description": "Test test test",
     *         "members": ["alice"],
     *         "name": "TestProject"
     *       },
     *       {
     *         "id": "testproject2",
     *         "description": "Test test test",
     *         "members": ["alice"],
     *         "name": "TestProject"
     *       }
     *     ]
     *   }
     *   ```
     *
     *   Project admins wishing to see the "tests" and "deploy" fields must fetch projects individually.
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "projects": [
     *         {
     *           "id": "testproject",
     *           "branches": [
     *             {
     *               "id": "main",
     *               "name": "main",
     *               "paths": ["//depot/main/TestProject/..."],
     *               "moderators": []
     *             }
     *           ],
     *           "deleted": false,
     *           "description": "Test test test",
     *           "followers": [],
     *           "jobview": "subsystem=testproject",
     *           "members": ["alice"],
     *           "name": "TestProject",
     *           "owners": [],
     *           "subgroups": []
     *         }
     *       ]
     *     }
     *
     * @return mixed
     */
    public function getList()
    {
        $fields = $this->getRequest()->getQuery('fields');
        $result = $this->forward(
            'Projects\Controller\Index',
            'projects',
            null,
            array(
                'disableHtml' => true,
                'listUsers'   => true,
                'allFields'   => true
            )
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel(array('projects' => $result->getVariables()), $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * @SWG\Api(
     *     path="projects/{id}",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get Project Information",
     *         notes="Retrieve information about a project.",
     *         nickname="getProject",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Project ID",
     *             paramType="path",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show for each project.
     *                          Omitting this parameter or passing an empty value shows all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\ResponseMessage(code=404, message="Not Found")
     *     )
     * )
     *
     * @apiUsageExample Fetching a project
     *
     *   To fetch an individual project:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        "https://my-swarm-host/api/v2/projects/testproject2?fields=id,description,members,name"
     *   ```
     *
     *   Swarm responds with a project entity:
     *
     *   ```json
     *   {
     *     "project": {
     *       "id": "testproject2",
     *       "description": "Test test test",
     *       "members": ["alice"],
     *       "name": "TestProject 2"
     *     }
     *   }
     *   ```
     *
     *   Project admins have access to additional fields ("tests" and "deploy") when fetching individual projects
     *   using this endpoint.
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "project": {
     *         "id": "testproject",
     *         "branches": [
     *           {
     *             "id": "main",
     *             "name": "main",
     *             "paths": ["//depot/main/TestProject/..."],
     *             "moderators": []
     *           }
     *         ],
     *         "deleted": false,
     *         "description": "Test test test",
     *         "jobview": "subsystem=testproject",
     *         "members": ["alice"],
     *         "name": "TestProject",
     *         "owners": [],
     *         "subgroups": []
     *       }
     *     }
     *
     * @param   string  $id     Project ID to fetch
     * @return  mixed
     */
    public function get($id)
    {
        $fields = $this->getRequest()->getQuery('fields');
        $result = $this->forward(
            'Projects\Controller\Index',
            'project',
            array('project' => $id)
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * @SWG\Api(
     *     path="projects/",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Create a new Project",
     *         notes="Creates a new project in Swarm.",
     *         nickname="createProject",
     *         @SWG\Parameter(
     *             name="name",
     *             description="Project Name (will also be used to generate the Project ID)",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="members",
     *             description="An array of project members.",
     *             paramType="form",
     *             type="array",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="subgroups",
     *             description="An optional array of project subgroups.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="owners",
     *             description="An optional array of project owners.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="description",
     *             description="An optional project description.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="deploy",
     *             description="Configuration for automated deployment.
     *                          Example: {&quot;enabled&quot;: true,
     *                          &quot;url&quot;: &quot;http://localhost/?change={change}&quot;}",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="tests",
     *             description="Configuration for testing/continuous integration.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="branches",
     *             description="Optional branch definitions for this project.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="jobview",
     *             description="An optional jobview for associating certain jobs with this project.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="emailFlags[change_email_project_users]",
     *             description="Email members, moderators and followers when a change is committed.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="emailFlags[review_email_project_members]",
     *             description="Email members and moderators when a new review is requested.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         )
     *     )
     * )
     *
     * @apiUsageExample Creating a new project
     *
     *   To create a project:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -d "name=TestProject 3" \
     *        -d "description=The third iteration of our test project." \
     *        -d "members[]=alice" \
     *        -d "members[]=bob" \
     *        "https://my-swarm-host/api/v2/projects/"
     *   ```
     *
     *   Swarm responds with the new project entity:
     *
     *   ```json
     *   {
     *     "project": {
     *       "id": "testproject3",
     *       "branches": [],
     *       "deleted": false,
     *       "deploy": {"url": "", "enabled": false},
     *       "description": "The third iteration of our test project.",
     *       "followers": [],
     *       "jobview": "subsystem=testproject",
     *       "members": ["alice", "bob"],
     *       "name": "TestProject 3",
     *       "owners": [],
     *       "subgroups": [],
     *       "tests": {"url": "", "enabled": false}
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "project": {
     *         "id": "testproject",
     *         "branches": [
     *           {
     *             "id": "main",
     *             "name": "main",
     *             "paths": ["//depot/main/TestProject/..."],
     *             "moderators": []
     *           }
     *         ],
     *         "deleted": false,
     *         "deploy": {"url": "", "enabled": false},
     *         "description": "Test test test",
     *         "followers": [],
     *         "jobview": "subsystem=testproject",
     *         "members": ["alice"],
     *         "name": "TestProject",
     *         "owners": [],
     *         "subgroups": [],
     *         "tests": {"url": "", "enabled": false}
     *       }
     *     }
     *
     * @param mixed $data
     * @return JsonModel
     */
    public function create($data)
    {
        $result = $this->forward('Projects\Controller\Index', 'add', null, null, $data);

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="projects/{id}",
     *     @SWG\Operation(
     *         method="PATCH",
     *         summary="Edit a Project",
     *         notes="Change the settings of a project in Swarm.
     *                If a project has owners set, only the owners can perform this action.",
     *         nickname="patchProject",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Project ID",
     *             paramType="path",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="name",
     *             description="Project Name (changing the project name does not change the project ID)",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="members",
     *             description="An array of project members.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="subgroups",
     *             description="An optional array of project subgroups.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="owners",
     *             description="An optional array of project owners.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="description",
     *             description="Your project description.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="deploy",
     *             description="Configuration for automated deployment.
     *                          Example: {&quot;enabled&quot;: true,
     *                          &quot;url&quot;: &quot;http://localhost/?change={change}&quot;}",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="tests",
     *             description="Configuration for testing/continuous integration.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="branches",
     *             description="Optional branch definitions for this project.",
     *             paramType="form",
     *             type="array",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="jobview",
     *             description="A jobview for associating certain jobs with this project.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="emailFlags[change_email_project_users]",
     *             description="Email members, moderators and followers when a change is committed.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="emailFlags[review_email_project_members]",
     *             description="Email members and moderators when a new review is requested.",
     *             paramType="form",
     *             type="boolean",
     *             required=false
     *         )
     *     )
     * )
     *
     * @apiUsageExample Editing a project
     *
     *   To edit a project:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -X PATCH
     *        -d "description=Witness the power of a fully operational Swarm project." \
     *        "https://my-swarm-host/api/v2/projects/testproject3"
     *   ```
     *
     *   Swarm responds with the updated project entity:
     *
     *   ```json
     *   {
     *     "project": {
     *       "id": "testproject3",
     *       "branches": [],
     *       "deleted": false,
     *       "deploy": {"url": "", "enabled": false},
     *       "description": "Witness the power of a fully operational Swarm project.",
     *       "followers": [],
     *       "jobview": "subsystem=testproject",
     *       "members": ["alice"],
     *       "name": "TestProject 3",
     *       "owners": [],
     *       "subgroups": [],
     *       "tests": {"url": "", "enabled": false}
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "project": {
     *         "id": "testproject",
     *         "branches": [
     *           {
     *             "id": "main",
     *             "name": "main",
     *             "paths": ["//depot/main/TestProject/..."],
     *             "moderators": []
     *           }
     *         ],
     *         "deleted": false,
     *         "deploy": {"url": "", "enabled": false},
     *         "description": "New Project Description",
     *         "followers": [],
     *         "jobview": "subsystem=testproject",
     *         "members": ["alice"],
     *         "name": "TestProject",
     *         "owners": [],
     *         "subgroups": [],
     *         "tests": {"url": "", "enabled": false}
     *       }
     *     }
     *
     * @param mixed $data
     * @return JsonModel
     */
    public function patch($id, $data)
    {
        $request  = $this->getRequest();
        $response = $this->getResponse();
        $request->setMethod(Request::METHOD_POST);
        $result   = $this->forward('Projects\Controller\Index', 'edit', array('project' => $id), null, $data);

        if (!$result->getVariable('isValid')) {
            if ($response->isOK()) {
                $this->getResponse()->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="projects/{id}",
     *     @SWG\Operation(
     *         method="DELETE",
     *         summary="Delete a Project",
     *         notes="Mark a Swarm project as deleted. The project ID and name cannot be reused.
     *                If a project has owners set, only the owners can perform this action.",
     *         nickname="deleteProject",
     *         @SWG\Parameter(
     *             name="id",
     *             description="Project ID",
     *             paramType="path",
     *             type="string",
     *             required=true
     *         )
     *     )
     * )
     *
     * @apiUsageExample Deleting a project
     *
     *   Super users, admins, and owners can delete projects. Members can delete projects that have no owners set.
     *
     *   ```bash
     *   curl -u "username:password" -X DELETE "https://my-swarm-host/api/v2/projects/testproject3"
     *   ```
     *
     *   Assuming that the authenticated user has permission, Swarm responds with the id of the deleted project:
     *
     *   ```json
     *   {
     *     "id": "testproject3"
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "id": "testproject"
     *     }
     *
     * @param mixed $id
     * @return JsonModel
     */
    public function delete($id)
    {
        $response = $this->getResponse();
        $result   = $this->forward('Projects\Controller\Index', 'delete', array('project' => $id));

        if (!$result->getVariable('isValid')) {
            if ($response->isOK()) {
                $this->getResponse()->setStatusCode(400);
            }

            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Extends parent to provide special preparation of project data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits entity output to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        $project = $model->getVariable('project');
        if ($project) {
            $model->setVariable('project', $this->normalizeProject($project, $limitEntityFields));
        }

        // if a list of projects is present, normalize each one
        $projects = $model->getVariable('projects');
        if ($projects) {
            foreach ($projects as $key => $project) {
                $projects[$key] = $this->normalizeProject($project, $limitEntityFields);
            }

            $model->setVariable('projects', $projects);
        }

        return $model;
    }

    protected function normalizeProject($project, $limitEntityFields = null)
    {
        unset($project['isMember']);
        $project = $this->limitEntityFields($project, $limitEntityFields);

        return $this->sortEntityFields($project);
    }
}
