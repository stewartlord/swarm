<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Queue\Controller;

use Application\Filter\ShorthandBytes;
use P4\Connection\Exception\CommandException;
use P4\Key\Key;
use Zend\EventManager\Event;
use Zend\Log\Writer\Stream as StreamWriter;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class IndexController extends AbstractActionController
{
    const   DEFAULT_WORKERS     = 3;
    const   DEFAULT_LIFETIME    = 600;  // 10 minutes

    protected $configMd5        = null; // used by pre-flight to test if data/config.php has been modified

    /**
     * Workers process tasks in the queue.
     *
     * The role of a worker is very simple. It grabs the oldest task from the
     * queue and triggers an event for the task. Each event has two params:
     * id and time. The name of the event is task.type (e.g. task.change or
     * or task.change). The event manager and listeners handle the rest.
     *
     * Immediately after processing a task, the worker attempts to grab another
     * task. If there are no task in the queue, the worker will sleep for
     * a second and then try again (unless the retire flag is set in which case
     * the worker will shutdown). When the worker's running time exceeds the
     * worker lifetime setting, it exits (to avoid potential memory leakage).
     * However, any given task can run as long as it wants and consume as much
     * memory as is available (to accommodate large changes for instance).
     *
     * Workers run in the background by default (they hang-up immediately).
     * You can pass a 'debug' query parameter to prevent the worker from
     * closing the connection. When run in this mode, the worker will write
     * its log output to the client (in addition to the log file).
     *
     * @triggers worker.startup
     *           When a worker first starts up
     * @triggers task.<type>
     *           When a <type> task is processed (e.g. task.change)
     * @see Queue\Manager for more details.
     * @todo consider setting p4 service to p4_admin and clearing user identity info.
     */
    public function workerAction()
    {
        $request  = $this->getRequest();
        $response = $this->getResponse();
        $services = $this->getServiceLocator();
        $logger   = $services->get('logger');
        $manager  = $services->get('queue');
        $config   = $manager->getConfig();
        $events   = $manager->getEventManager();

        // if retire flag is set, worker will quit when queue is empty
        $retire   = $request->getQuery('retire');
        $retire   = $retire !== null && $retire !== '0';

        // if debug flag is set and the user has super rights, send log output
        // to the client otherwise, hang-up on client and run headless.
        $debug    = $request->getQuery('debug');
        $debug    = $debug !== null && $debug !== '0';
        $debug    = $debug && ($services->get('permissions')->is('super') || $request->isTest);
        if ($debug) {
            $logger->addWriter(new StreamWriter('php://output'));

            // output headers now, so the response object won't
            // try to send them later and fail due to our output.
            $response->sendHeaders();

            // flush automatically so the client gets output immediately.
            ob_implicit_flush();
        } else {
            $this->disconnect();
        }

        // as we've likely disconnected at this point, catch any exceptions and simply log them
        try {
            // attempt to get a worker slot.
            $slot = $manager->getWorkerSlot();
            if (!$slot) {
                $logger->debug('All worker slots (' . (int) $config['workers'] . ') in use.');
                if ($request->isTest) {
                    return $response;
                }
                exit;
            }

            // log worker startup.
            $logger->info("Worker $slot startup.");

            // workers have a (typically) more generous memory limit and we'll apply it if needed:
            // - if the current limit is negative its already unlimited leave it be
            // - otherwise, if the new limit is unlimited or at least larger, use it
            // in the end if the new limit would have been lower we leave it as is
            $currentLimit = ShorthandBytes::toBytes(ini_get('memory_limit'));
            $newLimit     = ShorthandBytes::toBytes($config['worker_memory_limit']);
            if ($currentLimit >= 0 && ($newLimit < 0 || $newLimit > $currentLimit)) {
                ini_set('memory_limit', $newLimit);
            }

            // do an initial preflight to ensure everything looks good and also to record
            // the starting md5 of the config.php file.
            if (!$this->preflight()) {
                $logger->err("Worker $slot initial preflight failure. Aborting.");
                if ($request->isTest) {
                    return $response;
                }
                exit;
            }

            // fire startup event so external code can perform periodic tasks.
            $event = new Event;
            $event->setName('worker.startup')
                  ->setParam('slot', $slot)
                  ->setTarget($this);

            $events->trigger($event);

            // start pulling tasks from the queue.
            // track our runtime so we can honor worker lifetime limit.
            $birth = time();
            while ((time() - $birth) < $config['worker_lifetime']) {
                // reset max_execution_time for each task
                ini_set('max_execution_time', $config['worker_task_timeout']);

                // if the worker lock has gone away we consider this a signal to shutdown
                // log the justification for bailing and shut 'er down.
                if (!$manager->hasWorkerSlot($slot)) {
                    $logger->info("Worker $slot has been unlocked or removed.");
                    break;
                }

                // if we can't get a task, take a nap and spin again.
                $task = $manager->grabTask();
                if (!$task) {
                    $logger->debug("Worker $slot idle. No tasks in queue.");
                    if ($retire) {
                        break;
                    } else {
                        sleep(1);
                        continue;
                    }
                }

                // we got a task, let's process it!
                // @codingStandardsIgnoreStart
                $logger->info("Worker $slot event: " . print_r($task, true));
                // @codingStandardsIgnoreEnd

                // verify we have a working connection to p4d, that (if applicable) our replica is
                // up to date and clear our cache invalidation counters (to ensure we re-read them).
                // if things look too out of whack, put the task back for another worker and exit.
                if (!$this->preflight()) {
                    $logger->err("Worker $slot preflight failure. Requeuing task and aborting.");
                    $manager->addTask($task['type'], $task['id'], $task['data'], $task['time']);
                    break;
                }

                // turn simple task into a rich event object and trigger it
                $event = new Event();
                $event->setName('task.' . $task['type'])
                      ->setParam('id',    $task['id'])
                      ->setParam('type',  $task['type'])
                      ->setParam('time',  $task['time'])
                      ->setParam('data',  $task['data'])
                      ->setTarget($this);

                $events->trigger($event);
            }

            // log worker shutdown.
            $logger->info("Worker $slot shutdown.");

            // fire shutdown event
            $event = new Event;
            $event->setName('worker.shutdown')
                  ->setParam('slot', $slot)
                  ->setTarget($this);

            $events->trigger($event);

            // release our worker slot - helpful for tests
            $manager->releaseWorkerSlot($slot);
        } catch (\Exception $e) {
            // we're likely disconnected just log any exceptions
            $logger->err($e);
        }

        return $response;
    }

    /**
     * Report on the status of the queue (number of tasks, workers, etc.)
     *
     * @return \Zend\View\Model\JsonModel
     */
    public function statusAction()
    {
        // only allow logged in users to see status
        $services = $this->getServiceLocator();
        $services->get('permissions')->enforce('authenticated');

        $manager  = $services->get('queue');
        $config   = $manager->getConfig();
        $tasks    = $manager->getTaskCounts();

        return new JsonModel(
            array(
                'tasks'          => $tasks['current'],
                'futureTasks'    => $tasks['future'],
                'workers'        => $manager->getWorkerCount(),
                'maxWorkers'     => $config['workers'],
                'workerLifetime' => $config['worker_lifetime'] . 's',
            )
        );
    }

    /**
     * Before processing a task we want to ensure we have a functional environment.
     *
     * We want to check a number of things:
     * - we are able to run commands against the p4d server
     * - if we're behind a replica, the replica's data is up to date
     * - our cache invalidation counters are cleared (forcing a re-check if we later access cache)
     *
     * @param   bool    $retry  optional - true (default) will retry once on 'partner exited unexpectedly' errors
     * @return  bool    true if preflight went ok, false if problems were encountered
     */
    protected function preflight($retry = true)
    {
        $services = $this->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');

        // ensure the config.php file hasn't been modified since we last saw it.
        // skip if testing, the config.php may not exist.
        if (!$services->get('request')->isTest) {
            $configMd5       = md5(file_get_contents(DATA_PATH . '/config.php'));
            $this->configMd5 = $this->configMd5 !== null ? $this->configMd5 : $configMd5;
            if ($this->configMd5 !== $configMd5) {
                // if the config is modified, simply bail retrying here won't help
                return false;
            }
        }

        // the first thing we want to do is verify we have a working connection
        // to the perforce server. if we're behind a replica we already need to
        // run a command so rely on that, for direct connections we use p4 help.
        try {
            // get the info to see if we're behind a replica. this command is
            // likely to be cached so doesn't count as verification p4d is up.
            $info = $p4Admin->getInfo();

            if (isset($info['replica'])) {
                // if we're talking to a replica, we need to ensure it is up-to-date
                // otherwise a trigger could reference data it doesn't have yet
                // make sure it's current by incrementing a counter which incurs a
                // 'journalwait' until the update has round-tripped.
                $key = new Key($p4Admin);
                $key->setId('swarm-journalwait')->increment();
            } else {
                // looks like we are not on a replica, p4 help is cheap so run it.
                $p4Admin->run('help');
            }
        } catch (\Exception $e) {
            // if retry is true and this looks like a bad connection error; force a
            // reconnect and re-run preflight as that may clear things up.
            if ($retry
                && $e instanceof CommandException
                && strpos($e->getMessage(), 'Partner exited unexpectedly') !== false
            ) {
                $p4Admin->disconnect();
                return $this->preflight(false);
            }

            // oh oh; something is awry, log failure and return
            $services->get('logger')->err($e);
            return false;
        }

        // reset cache (clears in-memory items and refreshes cache counters)
        try {
            $p4Admin->getService('cache')->reset();
        } catch (\Exception $e) {
            // log the cache clearing failure but carry on
            $services->get('logger')->err($e);
        }

        return true;
    }
}
