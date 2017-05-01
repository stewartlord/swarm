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
use Zend\View\Model\JsonModel;

/**
 * Swarm Activity List
 *
 * @SWG\Resource(
 *   apiVersion="v2",
 *   basePath="/api/v2/"
 * )
 */
class ActivityController extends AbstractApiController
{
    /**
     * @SWG\Api(
     *     path="activity",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Create Activity Entry",
     *         notes="Creates an entry in the Activity List.
     *                Note: admin-level privileges are required for this action.",
     *         nickname="addActivity",
     *         @SWG\Parameter(
     *             name="type",
     *             paramType="form",
     *             description="Type of activity, used for filtering activity streams
     *                          (values can include 'change', 'comment', 'job', 'review').",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="user",
     *             paramType="form",
     *             description="User who performed the action.",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="action",
     *             paramType="form",
     *             description="Action that was performed - past-tense, e.g., 'created' or 'commented on'.",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="target",
     *             paramType="form",
     *             description="Target that the action was performed on, e.g., 'issue 1234'.",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="topic",
     *             paramType="form",
     *             description="Optional topic for the activity entry. Topics are essentially comment thread IDs.
     *                          Examples: 'reviews/1234' or 'jobs/job001234'.",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="description",
     *             paramType="form",
     *             description="Optional description of object or activity to provide context.",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="change",
     *             paramType="form",
     *             description="Optional changelist ID this activity is related to. Used to filter activity related to
     *                          restricted changes.",
     *             type="integer",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="streams[]",
     *             paramType="form",
     *             description="Optional array of streams to display on. This can include user-initiated actions
     *                          ('user-alice'), activity relating to a user's followed projects/users
     *                          ('personal-alice'), review streams ('review-1234'), and project streams
     *                          ('project-exampleproject'). ",
     *             type="array",
     *             @SWG\Items("string"),
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="link",
     *             paramType="form",
     *             description="Optional URL for 'target'.",
     *             type="string",
     *             required=false
     *         )
     *     )
     *  )
     *
     * @apiUsageExample Creating an activity entry
     *
     *   To create a plain activity entry:
     *
     *   ```bash
     *   curl -u "username:password" -d "type=job" -d "user=jira" -d "action=punted" -d "target=review 123" \
     *        "https://myswarm.url/api/v2/activity"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "activity": {
     *       "id": 1375,
     *       "action": "punted",
     *       "behalfOf": null,
     *       "change": null,
     *       "depotFile": null,
     *       "description": "",
     *       "details": [],
     *       "followers": [],
     *       "link": "",
     *       "preposition": "for",
     *       "projects": [],
     *       "streams": [],
     *       "target": "review 123",
     *       "time": 1461607739,
     *       "topic": "",
     *       "type": "job",
     *       "user": "jira"
     *     }
     *   }
     *   ```
     *
     * @apiUsageExample Linking an activity entry to a review
     *
     *   Linking activity entries to reviews is useful. This involves providing "link", "stream", and "topic" fields in
     *   the activity data. The "link" field is used to make the "review 123" string in the activity entry clickable.
     *   The "stream" field is needed so that the activity entry can be attached to the review in the Swarm interface.
     *   The "topic" field is used to link the activity entry to the comment thread for that topic, in the event that a
     *   user wants to comment on the activity.
     *
     *   To create a fully linked activity entry:
     *
     *   ```bash
     *   curl -u "username:password" -d "type=job" -d "user=jira" -d "action=punted" -d "target=review 123" \
     *        -d "streams[]=review-123" \
     *        -d "link=reviews/123" \
     *        -d "topic=reviews/123" \
     *        "https://myswarm.url/api/v2/activity"
     *   ```
     *
     *   Swarm responds with an activity entity:
     *
     *   ```json
     *   {
     *     "activity": {
     *       "id": 1375,
     *       "action": "punted",
     *       "behalfOf": null,
     *       "change": null,
     *       "depotFile": null,
     *       "description": "",
     *       "details": [],
     *       "followers": [],
     *       "link": "reviews/123",
     *       "preposition": "for",
     *       "projects": [],
     *       "streams": ['review-123],
     *       "target": "review 123",
     *       "time": 1461607739,
     *       "topic": "reviews/123",
     *       "type": "job",
     *       "user": "jira"
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "activity": {
     *         "id": 123,
     *         "action": "ate",
     *         "behalfOf": null,
     *         "change": null,
     *         "depotFile": null,
     *         "details": [],
     *         "description": "",
     *         "followers": [],
     *         "link": "",
     *         "preposition": "for",
     *         "projects": [],
     *         "streams": [],
     *         "target": "the manual",
     *         "time": 1404776681,
     *         "topic": "",
     *         "type": "comment",
     *         "user": "A dingo"
     *       }
     *     }
     *
     * @apiErrorResponse Errors if fields are missing:
     *     HTTP/1.1 400 Bad Request
     *
     *     {
     *       "details": {
     *         "target": "Value is required and can't be empty",
     *         "action": "Value is required and can't be empty",
     *         "user": "Value is required and can't be empty"
     *       },
     *       "error": "Bad Request"
     *     }
     *
     * @param   mixed   $data   an array built from the JSON body, if submitted
     * @return  JsonModel
     */
    public function create($data)
    {
        // only allow expected inputs
        $data = array_intersect_key(
            $data,
            array_flip(array('action', 'change', 'description', 'link', 'streams', 'target', 'topic', 'type', 'user'))
        );

        $result = $this->forward(
            'Activity\Controller\Index',
            'add',
            null,
            null,
            $data
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel(array('activity' => $result->getVariable('activity')));
    }

    /**
     * @SWG\Api(
     *     path="activity",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="List Activity Entries",
     *         notes="Retrieve the Activity List.",
     *         nickname="listActivity",
     *         @SWG\Parameter(
     *             name="change",
     *             paramType="form",
     *             description="Optionally filter activity entries by associated Changelist ID. This will only include
     *                          records for which there is an activity entry in Swarm.",
     *             type="integer",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="stream",
     *             paramType="form",
     *             description="Optional activity stream to query for entries. This can include user-initiated
     *                          actions ('user-alice'), activity relating to a user's followed projects/users
     *                          ('personal-alice'), review streams ('review-1234'), and project streams
     *                          ('project-exampleproject').",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="type",
     *             paramType="form",
     *             description="Type of activity, e.g., 'change', 'comment', 'job', or 'review'.",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="after",
     *             description="An activity ID to seek to. Activity entries up to and including the specified id will be
     *                          excluded from the results and do not count towards max. Useful for pagination. Commonly
     *                          set to the 'lastSeen' property from a previous query.",
     *             paramType="query",
     *             type="integer"
     *         ),
     *         @SWG\Parameter(
     *             name="max",
     *             description="Maximum number of activity entries to return. This does not guarantee that 'max' entries
     *                          will be returned. It does guarantee the number of entries returned won't exceed 'max'.
     *                          Server-side filtering may exclude some activity entries for permissions reasons.",
     *             paramType="query",
     *             type="integer",
     *             defaultValue="100"
     *         ),
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show. Omitting this parameter
     *                          or passing an empty value will show all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         )
     *     )
     *  )
     *
     * @apiUsageExample Fetching review history
     *
     *   To get the latest activity entries on a review:
     *
     *   ```bash
     *   curl -u "username:password" "https://myswarm.url/api/v2/activity?stream=review-1234\
     *        &fields=id,date,description,type\
     *        &max=2"
     *   ```
     *
     *   You can tweak "max" and "fields" to fetch the data that works best for you.
     *
     *   Swarm responds with an array of activity entities, and a "lastSeen" value that can be used for pagination:
     *
     *   ```json
     *   {
     *     "activity": [
     *       {
     *         "id": 10,
     *         "date": "2016-04-15T16:10:32-07:00",
     *         "description": "This is a test comment.",
     *         "type": "comment"
     *       },
     *       {
     *         "id": 9,
     *         "date": "2016-03-31T13:48:15-07:00",
     *         "description": "Updating RELNOTE review",
     *         "type": "review"
     *       }
     *     ],
     *     "lastSeen": 9
     *   }
     *   ```
     *
     * @apiUsageExample Activity pagination
     *
     *   To get the second page of activity entries for a review (based on the previous example):
     *
     *   ```bash
     *   curl -u "username:password" "https://myswarm.url/api/v2/activity?stream=review-1234\
     *        &fields=id,date,description,type\
     *        &max=2\
     *        &lastSeen=9"
     *   ```
     *
     *   Swarm again responds with a list of activity entities and a "lastSeen" value:
     *
     *   ```json
     *   {
     *     "activity": [
     *       {
     *         "id": 8,
     *         "date": "2016-03-30T12:12:12-07:00",
     *         "description": "This is the first test comment.",
     *         "type": "comment"
     *       },
     *       {
     *         "id": 7,
     *         "date": "2016-03-29T12:13:14-07:00",
     *         "description": "Updating RELNOTE review",
     *         "type": "review"
     *       }
     *     ],
     *     "lastSeen": 7
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "activity": [
     *         {
     *           "id": 123,
     *           "action": "committed",
     *           "behalfOf": null,
     *           "behalfOfExists": false,
     *           "change": 1,
     *           "date": "2016-01-15T12:12:12-08:00",
     *           "depotFile": null,
     *           "description": "test\n",
     *           "details": [],
     *           "followers": [],
     *           "link": ["change", {"change": 1}],
     *           "preposition": "into",
     *           "projectList": {"restricted": ["main"]},
     *           "projects": {"restricted": ["main"]},
     *           "streams": ["review-2", "user-foo", "personal-foo", "project-restricted"],
     *           "target": "change 1",
     *           "time": 1404776681,
     *           "topic": "changes/1",
     *           "type": "change",
     *           "url": "/changes/1",
     *           "user": "bruno",
     *           "userExists": true
     *         }
     *       ],
     *       "lastSeen": 1
     *     }
     *
     * @return  JsonModel
     */
    public function getList()
    {
        $request = $this->getRequest();
        $fields  = $request->getQuery('fields');
        $stream  = $request->getQuery('stream');

        $result  = $this->forward(
            'Activity\Controller\Index',
            'index',
            null,
            array(
                'stream'      => $stream,
                'change'      => $request->getQuery('change'),
                'type'        => $request->getQuery('type'),
                'max'         => $request->getQuery('max', 100),
                'after'       => $request->getQuery('after'),
                'disableHtml' => true,
            )
        );

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * Extends parent to provide special preparation of activity data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits activity entries to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        // detect if activity contains an individual event or a stream of events and normalize appropriately
        $activity = $model->getVariable('activity');
        if ($activity) {
            if (isset($activity['id'])) {
                $model->setVariable('activity', $this->normalizeActivity($activity, $limitEntityFields));
            } elseif (isset($activity[0]['id'])) {
                $activities = array();
                foreach ($activity as $key => $entry) {
                    $activities[$key] = $this->normalizeActivity($entry, $limitEntityFields);
                }
                $model->setVariable('activity', $activities);
            }
        }

        return $model;
    }

    protected function normalizeActivity($activity, $limitEntityFields = null)
    {
        // exit early if an invalid activity entry is detected
        if (!isset($activity['id'])) {
            return array();
        }

        unset($activity['avatar']);

        $activity = $this->limitEntityFields($activity, $limitEntityFields);
        return $this->sortEntityFields($activity);
    }
}
