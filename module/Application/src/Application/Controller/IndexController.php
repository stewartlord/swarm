<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\Controller;

use Application\Permissions\Exception\ForbiddenException;
use Groups\Model\Group;
use P4\Model\Fielded\Iterator;
use P4\Spec\Job;
use P4\File\File;
use P4\Spec\Depot;
use P4\Spec\Change;
use Projects\Model\Project;
use Reviews\Model\Review;
use Users\Model\User;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function aboutAction()
    {
        // only show about to logged in users
        $services    = $this->getServiceLocator();
        $permissions = $services->get('permissions');
        $permissions->enforce('authenticated');

        $format  = $this->getRequest()->getQuery('format');
        $data    = array(
            'version'       => VERSION,
            'year'          => current(explode('.', VERSION_RELEASE)),
            'canAccessInfo' => $this->canAccessInfo()
        );

        // only reveal queue tokens to super users
        if ($permissions->is('super')) {
            $data['tokens'] = $services->get('queue')->getTokens();
        }

        if ($format === 'json') {
            return new JsonModel($data);
        }

        $view = new ViewModel($data);
        $view->setTerminal($format === 'partial');
        return $view;
    }

    public function infoAction()
    {
        $this->restrictInfoAccess();

        // render view
        return new ViewModel(array('p4info' => $this->getServiceLocator()->get('p4')->getInfo()));
    }

    public function phpinfoAction()
    {
        $this->restrictInfoAccess();

        $view = new ViewModel();
        $view->setTerminal(true);
        return $view;
    }

    public function logAction()
    {
        $this->restrictInfoAccess();

        $services = $this->getServiceLocator();
        $format   = $this->getRequest()->getQuery('format');
        $tail     = (int) $this->getRequest()->getQuery('tail');
        $tail     = ($tail > 0) ? $tail : 0;
        $config   = $services->get('config');
        $file     = isset($config['log']['file']) ? $config['log']['file'] : '';
        $view     = new ViewModel;

        $view->setTerminal(true);

        // check that we have a configured log file
        if (!$file) {
            return $view->setVariable('error', 'No swarm log file configured.');
        }

        // check that the file exists and is readable
        if (($file = @fopen($file, 'r')) === false) {
            return $view->setVariable('error', 'Swarm log file doesn\'t exist, or is not readable by the web server.');
        }

        if ($format === 'partial') {
            // default max size to 1M
            $tail         = $tail ?: 1024*1024;
            $splitRegex   = '/(\d\d\d\d-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d \w+ \(\d\)\:.*\n?)/';
            $detailsRegex = '/^(?P<time>\d\d\d\d-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d) '
                          . '(?P<levelName>\w+) \((?P<level>\d)\)\:(?P<message>.*?)$/';
            $entries      = array();

            // read the last $tail bytes of the log
            fseek($file, -$tail, SEEK_END);
            $chunks = preg_split(
                $splitRegex,
                fread($file, $tail),
                -1,
                PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
            );
            fclose($file);

            // parse out individual log entries
            $entryCount = 0;
            for ($i = 0; $i < count($chunks); $i++) {
                if (preg_match($detailsRegex, $chunks[$i], $matches)) {
                    // create a new entry
                    $entries[] = array(
                        'meta'     => trim($chunks[$i]),
                        'details'  => '',
                        'severity' => isset($matches['levelName']) ? $matches['levelName'] : '',
                        'time'     => isset($matches['time']) ? $matches['time'] : '',
                        'message'  => isset($matches['message']) ? $matches['message'] : ''
                    );
                    $entryCount++;
                } elseif ($i > 0) {
                    // fill in the details for the previous entry
                    $entries[$entryCount - 1]['details'] = $chunks[$i];
                }
            }

            // reverse the entries array to put the entries into created descending order
            return $view->setVariable('entries', array_reverse($entries));
        } else {
            // download log file
            $response = new \Zend\Http\Response\Stream;
            $headers  = new \Zend\Http\Headers;
            $headers->addHeaderLine('Content-type', 'application/octet-stream');
            $headers->addHeaderLine(
                'Content-Disposition',
                'attachment; filename=swarm-log-' . date('Y-m-d_H:i:s') . '.txt',
                true
            );
            $response->setHeaders($headers);

            // limit to a specific maximum number of bytes
            if ($tail !== 0) {
                fseek($file, -$tail, SEEK_END);
            }

            $response->setStream($file);
            return $response;
        }
    }

    public function gotoAction()
    {
        $id      = $this->getEvent()->getRouteMatch()->getParam('id');
        $p4      = $this->getServiceLocator()->get('p4');
        $p4Admin = $this->getServiceLocator()->get('p4_admin');

        // normalize id by stripping leading/trailing slashes
        $id = trim($id, '/');

        // nothing to do if they fail to pass an item (shouldn't happen though)
        if (!strlen($id)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // if its purely numeric it could be a review or a change
        if ((string)(int)$id === (string)$id) {
            // first try testing for a review
            if (Review::exists($id, $p4Admin)) {
                return $this->redirect()->toRoute('review', array('review' => $id));
            }

            // actually try and fetch the change. this way if it was re-numbered
            // we'll still get it and can send them to the current location
            try {
                $change = Change::fetch($id, $p4);
            } catch (\Exception $e) {
                $change = false;
            }

            if ($change) {
                return $this->redirect()->toRoute('change', array('change' => $change->getId()));
            }
        }

        // if it has a slash in it, try for a file or directory
        if (strpos($id, '/') !== false) {
            $path = '//' . trim($id, '/');
            try {
                if (File::exists($path, $p4) || File::dirExists($path, $p4)) {
                    return $this->redirect()->toRoute('file', array('path' => trim($path, '/')));
                }
            } catch (\Exception $e) {
                // just eat the exception this occurs for invalid depots on the file exists
            }
        }

        // if it starts with 'job' give that a go
        if (strpos($id, 'job') === 0 && Job::exists($id, $p4)) {
            return $this->redirect()->toRoute('job', array('job' => $id));
        }

        // at this point; we're just guessing :)
        // projects are quite important, start here
        if (Project::exists($id, $p4Admin)) {
            return $this->redirect()->toRoute('project', array('project' => $id));
        }

        // as user ids are expected to be common, start there
        if (User::exists($id, $p4Admin)) {
            return $this->redirect()->toRoute('user', array('user' => $id));
        }

        // groups are also quite common, try it next
        if (Group::exists($id, $p4Admin)) {
            return $this->redirect()->toRoute('group', array('group' => $id));
        }

        // if the string ends in ' or 's it might still be a user id, try trimming that bit
        if (preg_match("/(?P<id>.*)(?:'|'s)$/", $id, $matches) && User::exists($matches['id'], $p4Admin)) {
            return $this->redirect()->toRoute('user', array('user' => $matches['id']));
        }

        // could be a depot if we made it this far and there are no slashes
        if (strpos($id, '/') === false && Depot::exists($id, $p4)) {
            return $this->redirect()->toRoute('file', array('path' => $id));
        }

        // perhaps its a job that doesn't start with job or the the cAsE is wrong.
        // try doing a case insensitive job fetch of the specified id.
        $jobs = Job::fetchAll(array(Job::FETCH_BY_IDS => $id, Job::FETCH_INSENSITIVE => true), $p4Admin);
        if ($jobs->count()) {
            // if we have an exact match use it. otherwise take the first hit
            // which will be an entry that varies in case but otherwise matches.
            $id = in_array($id, $jobs->invoke('getId')) ? $id : $jobs->first()->getId();
            return $this->redirect()->toRoute('job', array('job' => $id));
        }

        // if it looks like a sha1 and we're configured with a git fusion depot look for a commit
        $config = $this->getServiceLocator()->get('config') + array('git-fusion' => array());
        $config['git_fusion'] += array('depot' => null);
        if ($config['git_fusion']['depot'] && preg_match('/[A-Z0-9]{4,40}/i', $id)) {
            try {
                // find the commit(s) that match this sha. if more than one patch is returned
                // we don't know which to use so simply refuse to honor it.
                $commits = $p4Admin->run(
                    'files',
                    array(
                         '-m2',
                         '//' . $config['git_fusion']['depot'] . '/objects/repos/*/commits/'
                         . substr($id, 0, 2) . '/' . substr($id, 2, 2) . '/' . substr($id, 4) . '*'
                    )
                );

                if (count($commits->getData()) == 1
                    && preg_match('/.*\,(?P<change>[0-9]+)$/', $commits->getData(0, 'depotFile'), $matches)
                    && Change::exists($matches['change'], $p4)
                ) {
                    return $this->redirect()->toRoute('change', array('change' => $matches['change']));
                }
            } catch (\Exception $e) {
                // just eat the exception if one occurs (e.g. missing depot)
            }
        }

        // if we make it to the end, we don't know what it is so 404 out
        $this->getResponse()->setStatusCode(404);
    }

    protected function canAccessInfo()
    {
        // don't allow access if the system info is disabled
        $services = $this->getServiceLocator();
        $config   = $services->get('config');
        if (isset($config['security']['disable_system_info']) && $config['security']['disable_system_info']) {
            return false;
        }

        // only show to admins or super users
        if (!$services->get('permissions')->is('admin')) {
            return false;
        }

        // looks like they are an admin/super and info is enabled
        return true;
    }

    protected function restrictInfoAccess()
    {
        if (!$this->canAccessInfo()) {
            throw new ForbiddenException;
        }

        return $this;
    }
}
