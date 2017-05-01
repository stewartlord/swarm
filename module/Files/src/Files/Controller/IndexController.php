<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Files\Controller;

use Application\Filter\Preformat;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Protections;
use Application\Response\CallbackResponse;
use Files\MimeType;
use P4\Connection\ConnectionInterface as Connection;
use P4\File\Diff;
use P4\File\File;
use P4\File\Exception\Exception as FileException;
use Projects\Model\Project;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function fileAction()
    {
        $services = $this->getServiceLocator();
        $p4       = $services->get('p4');
        $archiver = $services->get('archiver');
        $route    = $this->getEvent()->getRouteMatch();
        $path     = trim($route->getParam('path'), '/');
        $history  = $route->getParam('history');
        $project  = $route->getParam('project');
        $client   = $project ? $project->getClient() : null;
        $request  = $this->getRequest();
        $format   = $request->getQuery('format');
        $version  = $request->getQuery('v');
        $range    = $request->getQuery('range');
        $version  = ctype_digit($version) ? '#' . $version : $version;
        $download = $request->getQuery('download', $route->getParam('download')) !== null;
        $view     = $request->getQuery('view',     $route->getParam('view'))     !== null;
        $annotate = $request->getQuery('annotate')                               !== null;
        $lines    = (array) $request->getQuery('lines');

        // if we have a client, set it on the connection
        if ($client) {
            $p4->setClient($client);
        }

        // attempt to treat path as a file
        try {
            // if path is empty, no point querying perforce, throw now
            // and we'll turn it into a list action when we catch it below
            if (!strlen($path)) {
                throw new \Exception;
            }

            $file = File::fetch(
                '//' . ($client ? $client . '/' : '') . $path . $version,
                $p4
            );

            // deny access if user doesn't have read access to the file from his/her client IP
            $ipProtects = $services->get('ip_protects');
            if (!$ipProtects->filterPaths($file->getDepotFilename(), Protections::MODE_READ)) {
                throw new ForbiddenException(
                    "You don't have permission to read this file."
                );
            }

            // early exit if annotate requested.
            if ($annotate) {
                $annotate = $file->getAnnotatedContent(
                    array(
                        File::ANNOTATE_CHANGES => true,
                        File::ANNOTATE_INTEG   => true,
                        File::ANNOTATE_CONTENT => false
                    )
                );

                // fetch information for each referenced change
                $changes = array();
                $params  = array();
                foreach ($annotate as $line) {
                    $params[] = '@=' . $line['lower'];
                }

                // empty files won't generate output, avoid running changes with no params
                if ($params) {
                    $result = $p4->run('changes', array_merge(array('-L', '-t'), $params));

                    // format change information
                    $preformat = new Preformat($request->getBaseUrl());
                    foreach ($result->getData() as $change) {
                        $changes[$change['change']] = array(
                            'user' => $change['user'],
                            'desc' => $preformat->filter($change['desc']),
                            'time' => date('c', $change['time'])
                        );
                    }
                }

                return new JsonModel(
                    array(
                        'annotate' => $annotate,
                        'changes'  => $changes
                    )
                );
            }

            // determine file's content type.
            $type = MimeType::getTypeFromName($file->getBasename());
            $type = !$type && $file->isText() ? 'text/plain' : $type;
            $type = $type ?: 'application/octet-stream';
            if (preg_match('/unicode|utf/', $file->getStatus('headType'))) {
                $type .= '; charset=utf-8';
            }

            if ($download || $view) {
                if ($format === 'json' && $type !== 'application/json') {
                    // though it is plausible to show json without
                    // the line range, we don't support it yet
                    if (!$lines) {
                        $this->getResponse()->setStatusCode(400);
                        return new JSONModel(
                            array(
                                'isValid'   => false,
                                'error'     => "JSON only supported when line range specified"
                            )
                        );
                    }

                    $type = 'application/json';
                }

                $response = new CallbackResponse();
                $response->getHeaders()
                    ->addHeaderLine('Content-Type', $type)
                    ->addHeaderLine('Content-Transfer-Encoding', 'binary')
                    ->addHeaderLine('Expires', '@0')
                    ->addHeaderLine('Cache-Control', 'must-revalidate')
                    ->addHeaderLine(
                        'Content-Disposition',
                        ( $download ? 'attachment; ' : '' ) . 'filename="' . $file->getBasename() . '"'
                    );

                // if requested, only get the content between the specified ranges
                if ($lines) {
                    // if lines were passed, but the file is binary, error out
                    if ($file->isBinary()) {
                        $this->getResponse()->setStatusCode(400);
                        return new JSONModel(
                            array(
                                'isValid'   => false,
                                'error'     => "Cannot apply line range to binary files"
                            )
                        );
                    }

                    try {
                        $contents = $file->getDepotContentLines($lines);
                    } catch (\InvalidArgumentException $e) {
                        // Request Range Not Satisfiable
                        $this->getResponse()->setStatusCode(416);
                        return new JSONModel(
                            array(
                                'isValid'   => false,
                                'error'     => "Invalid Range Specified: " . $e->getMessage()
                            )
                        );
                    }

                    $response->setCallback(
                        function () use ($format, $contents) {
                            echo $format === 'json' ? json_encode($contents) : implode($contents);
                        }
                    );

                    return $response;
                }

                // we are sending back the full content, add length
                $response->getHeaders()->addHeaderLine('Content-Length', $file->getFileSize());

                // let's stream the response! this will save memory and hopefully improve performance.
                $response->setCallback(
                    function () use ($file) {
                        return $file->streamDepotContents();
                    }
                );

                return $response;
            }

            $partial  = $format === 'partial';
            $maxSize  = $this->getArchiveMaxInputSize();
            $fileFits = $file->hasStatusField('fileSize') && (int)$file->get('fileSize') <= $maxSize;
            $model    = new ViewModel;
            $model->setTerminal($partial);
            $model->setVariables(
                array(
                    'path'       => $path,
                    'file'       => $file,
                    'type'       => $type,
                    'version'    => $version,
                    'partial'    => $partial,
                    'history'    => $history,
                    'project'    => $project,
                    'range'      => $range,
                    'formats'    => $services->get('formats'),
                    'canArchive' => $archiver->canArchive() && (!$maxSize || $fileFits)
                )
            );

            return $model;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (\Exception $e) {
            // show 404 for download/view as we couldn't get the file, otherwise forward to list action
            if ($download || $view) {
                $this->getResponse()->setStatusCode(404);
                return;
            }

            return $this->forward()->dispatch(
                'Files\Controller\Index',
                array(
                    'action'  => 'list',
                    'path'    => $path,
                    'history' => $history,
                    'project' => $project,
                    'client'  => $client,
                )
            );
        }
    }

    /**
     * Action for creating, downloading and checking status of an archive for the given path.
     *
     * @todo   at the moment this works only for depot paths
     *         in particular, it doesn't work for projects
     */
    public function archiveAction()
    {
        $services   = $this->getServiceLocator();
        $config     = $services->get('config');
        $p4         = $services->get('p4');
        $route      = $this->getEvent()->getRouteMatch();
        $path       = $route->getParam('path');
        $digest     = $route->getParam('digest');
        $project    = $route->getParam('project');
        $version    = $this->getRequest()->getQuery('v');
        $version    = ctype_digit($version) ? '#' . $version : $version;
        $request    = $this->getRequest();
        $response   = $this->getResponse();
        $background = $request->getQuery('background') !== null;
        $archiver   = $services->get('archiver');
        $cacheDir   = DATA_PATH . '/cache/archives';

        // set protections on the archiver to filter out files user doesn't have access to
        $archiver->setProtections($services->get('ip_protects'));

        // if status requested for a given archive digest, return it
        $statusFile = $cacheDir . '/' . $digest . '.status';
        if ($digest && $archiver->hasStatus($statusFile)) {
            return new JsonModel($archiver->getStatus($statusFile));
        } elseif ($digest) {
            $response->setStatusCode(404);
            return;
        }

        // if we have a project, set its client on the connection so we are mapping the depot appropriately
        if ($project) {
            $p4->setClient($project->getClient());
            $path = $project->getClient() . '/' . $path;
        }

        // translate path to filespec
        $filespec = File::exists('//' . $path . $version, $p4)
            ? '//' . $path . $version
            : '//' . $path . '/...';

        try {
            $filesInfo = $archiver->getFilesInfo($filespec, $p4);
        } catch (\InvalidArgumentException $e) {
            if (strpos($e->getMessage(), 'contains no files') !== false) {
                $response->setStatusCode(404);
            }

            throw $e;
        }

        // throw if files to compress are over the maximum size limit (if set)
        $maxSize = $this->getArchiveMaxInputSize();
        if ($maxSize && $filesInfo['size'] > $maxSize) {
            $response->setStatusCode(413);
            throw new \Exception(
                "Cannot archive '$filespec'. Files are " . $filesInfo['size'] .
                " bytes (max size is " . $maxSize . " bytes)."
            );
        }

        // if background processing requested, return json response with file info and disconnect
        if ($background) {
            $json = new JsonModel($filesInfo);
            $response->getHeaders()->addHeaderLine('Content-Type: application/json; charset=utf-8');
            $response->setContent($json->serialize());
            $this->disconnect();
        }

        // compressing files can take a while
        ini_set(
            'max_execution_time',
            isset($config['archives']['archive_timeout'])
            ? (int) $config['archives']['archive_timeout']
            : 1800
        );

        // archive files matching filespec
        \Record\Cache\Cache::ensureWritable($cacheDir);
        $archiveFile = $cacheDir . '/' . $filesInfo['digest'] . '.zip';
        $statusFile  = $cacheDir . '/' . $filesInfo['digest'] . '.status';
        $archiver->archive($filespec, $archiveFile, $statusFile, $p4);

        // add a future task to remove archive file after its lifetime set in config (defaults to 1 day)
        $config        = $services->get('config');
        $cacheLifetime = isset($config['archives']['cache_lifetime'])
            ? $config['archives']['cache_lifetime']
            : 60 * 60 * 24;

        $services->get('queue')->addTask(
            'cleanup.archive',
            $archiveFile,
            array('statusFile' => $statusFile),
            time() + $cacheLifetime
        );

        // if we were archiving in the background, no need to send archive
        if ($background) {
            return $response;
        }

        // download
        $response = new CallbackResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'application/zip')
            ->addHeaderLine('Content-Transfer-Encoding', 'binary')
            ->addHeaderLine('Content-Length', filesize($archiveFile))
            ->addHeaderLine('Expires', '@0')
            ->addHeaderLine('Cache-Control', 'must-revalidate')
            ->addHeaderLine(
                'Content-Disposition',
                'attachment; ' . 'filename="' . basename($path) . '.zip"'
            );

        // let's stream the response, this will save memory and hopefully improve performance
        $response->setCallback(
            function () use ($archiveFile) {
                return readfile($archiveFile);
            }
        );

        return $response;
    }

    public function listAction()
    {
        $services   = $this->getServiceLocator();
        $p4         = $services->get('p4');
        $ipProtects = $services->get('ip_protects');
        $archiver   = $services->get('archiver');
        $config     = $services->get('config');
        $mainlines  = isset($config['projects']['mainlines']) ? (array) $config['projects']['mainlines'] : array();
        $route      = $this->getEvent()->getRouteMatch();
        $path       = $route->getParam('path');
        $history    = $route->getParam('history');
        $project    = $route->getParam('project');
        $client     = $project ? ($route->getParam('client') ?: $project->getClient()) : null;
        $request    = $this->getRequest();
        $partial    = $request->getQuery('format') === 'partial';
        $deleted    = $request->getQuery('showDeleted');
        $range      = $request->getQuery('range');
        $deleted    = $deleted !== null && $deleted != '0';

        // if we have a client, set it on the connection
        if ($client) {
            $p4->setClient($client);
        }

        try {
            $dirs  = $this->getDirs($path, $deleted, $ipProtects, $p4, $mainlines, $project);
            $files = $this->getFiles($path, $client, $deleted, $ipProtects, $p4);

            // if we have no dirs and no files, include deleted and try again
            // (we consider this analogous to accessing a deleted file directly)
            if (!$dirs && !$files && !$deleted) {
                $deleted = true;
                $dirs    = $this->getDirs($path, $deleted, $ipProtects, $p4, $mainlines, $project);
                $files   = $this->getFiles($path, $client, $deleted, $ipProtects, $p4);
            }
        } catch (\P4\Connection\Exception\CommandException $e) {
            // a command exception with the message:
            //  <path> - must refer to client '<client-id>'.
            // indicates an invalid depot - produce a 404 if this happens.
            $errors = implode("", $e->getResult()->getErrors());
            if (stripos($errors, " - must refer to client ") !== false) {
                $this->getResponse()->setStatusCode(404);
                return;
            }

            throw $e;
        }

        // if we encountered an invalid folder, we need to flag it as a 404
        // (any path with an embedded / is a folder). missing depots already throw
        // and empty depots are valid so we don't deal with them here.
        if (strpos(trim($path, '/'), '/') && empty($dirs) && empty($files)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $view = new ViewModel;
        $view->setTerminal($partial);
        $view->setVariables(
            array(
                'path'       => $path,
                'dirs'       => $dirs,
                'files'      => $files,
                'partial'    => $partial,
                'history'    => $history,
                'project'    => $project,
                'client'     => $client,
                'mainlines'  => $mainlines,
                'range'      => $range,
                'canArchive' => $archiver->canArchive() && strlen($path) > 0
            )
        );

        return $view;
    }

    public function diffAction()
    {
        $services = $this->getServiceLocator();
        $p4       = $services->get('p4');
        $request  = $this->getRequest();
        $left     = $request->getQuery('left');
        $right    = $request->getQuery('right');
        $diff     = new Diff($p4);
        $options  = array(
            $diff::IGNORE_WS    => (bool) $request->getQuery('ignoreWs'),
            $diff::UTF8_CONVERT => true
        );

        // return 404 if either file could not be fetched (due to invalid or non-existent filespec)
        try {
            $left  = $left  ? File::fetch($left,  $p4) : null;
            $right = $right ? File::fetch($right, $p4) : null;
        } catch (FileException $e) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // ensure user has access to the files from his/her client IP
        $ipProtects    = $services->get('ip_protects');
        $noLeftAccess  = $left  && !$ipProtects->filterPaths($left->getDepotFilename(),  Protections::MODE_READ);
        $noRightAccess = $right && !$ipProtects->filterPaths($right->getDepotFilename(), Protections::MODE_READ);
        if ($noLeftAccess || $noRightAccess) {
            throw new ForbiddenException("You don't have permission to diff these file(s).");
        }

        // action can be explicitly passed in (useful for diffing versions of a review)
        // if no action is given, we favor the action of the right and fallback to the left
        $action = $request->getQuery('action');
        $action = $action ?: ($right ? $right->getStatus('headAction') : null);
        $action = $action ?: ($left  ? $left->getStatus('headAction')  : null);

        $view = new ViewModel(
            array(
                'left'      => $left,
                'right'     => $right,
                'action'    => $action,
                'diff'      => $diff->diff($right, $left, $options),
                'ignoreWs'  => (bool) $request->getQuery('ignoreWs'),
                'formats'   => $services->get('formats')
            )
        );
        return $view->setTerminal(true);
    }

    /**
     * Get dirs for the given path (applies ip-filters and a 'natural' sort)
     *
     * @param   string          $path           the path we are currently browsing
     * @param   boolean         $deleted        whether or not deleted directories are included
     * @param   Protections     $ipProtects     filter dirs according to given protections
     * @param   Connection      $p4             the perforce connection to use
     * @param   array           $mainlines      common 'mainline' branch names
     * @param   Project         $project        optional project to get branch paths from
     * @return  array           list of directories under the given path
     */
    protected function getDirs($path, $deleted, $ipProtects, Connection $p4, array $mainlines, Project $project = null)
    {
        // four discrete cases to handle:
        // - no path and no project (report depots as dirs)
        // - no path, but we have a project (report branches as dirs)
        // - path for a project (run our fancy project branch/dir logic)
        // - plain path (just run dirs)
        $dirs = array();
        if (!$path && !$project) {
            foreach ($p4->run('depots')->getData() as $depot) {
                $dirs[] = array('dir' => $depot['name']);
            }
        } elseif (!$path && $project) {
            foreach ($project->getBranches('id', $mainlines) as $branch) {
                // only list branches with paths
                if (count($branch['paths'])) {
                    $dirs[] = array('dir' => $branch['id']);
                }
            }
        } elseif ($path && $project) {
            $dirs = $this->getProjectDirs($project, $path, $deleted, $ipProtects, $p4);
        } else {
            $flags   = $deleted ? array('-D') : array();
            $flags[] = '//' . $path . '/*';
            $dirs    = $p4->run('dirs', $flags)->getData();
        }

        // apply ip-protections (if we have a project, protections are already applied)
        if (!$project) {
            $dirs = $ipProtects->filterPaths(
                $dirs,
                Protections::MODE_LIST,
                function ($dir) {
                    return '//' . trim($dir['dir'], '/') . '/';
                }
            );
        }

        // sort directories unless we got them from project branches that already handles sorting
        if ($path || !$project) {
            usort(
                $dirs,
                function ($a, $b) {
                    // put hidden (.foo) dirs last - otherwise, just a natural case-insensitive sort
                    return (($a['dir'][0] === '.') - ($b['dir'][0] === '.'))
                        ?: strnatcasecmp($a['dir'], $b['dir']);
                }
            );
        }

        // flag deleted directories
        // if not fetching deleted directories, then none are deleted
        // if fetching deleted dirs, then recurse (excluding deleted) and flag the disjoint set
        if (!$deleted) {
            foreach ($dirs as $key => $dir) {
                $dirs[$key]['isDeleted'] = false;
            }
        } else {
            $notDeleted = $this->getDirs($path, false, $ipProtects, $p4, $mainlines, $project);
            $notDeleted = array_map('current', $notDeleted);
            foreach ($dirs as $key => $dir) {
                $dirs[$key]['isDeleted'] = !in_array($dir['dir'], $notDeleted);
            }
        }

        return $dirs;
    }

    /**
     * Get list of directory basenames in the given path and project.
     *
     * This method is needed because project branches can map multiple paths
     * and the 'p4 dirs' command does not support client-syntax very well.
     * To work around this, we merge results from 'p4 dirs' run with multiple
     * arguments (one argument for each mapping).
     *
     * @param   Project         $project        project to get branch paths from
     * @param   string          $path           the project path we are currently browsing
     * @param   boolean         $deleted        whether or not deleted directories are included
     * @param   Protections     $ipProtects     dirs are filtered according to given protections
     * @param   Connection      $p4             the perforce connection to use
     * @return  array           list of unique/merged directory basenames for given path
     */
    protected function getProjectDirs(Project $project, $path, $deleted, Protections $ipProtects, Connection $p4)
    {
        // split the browse path into branch-id and sub-path (e.g. //<branch-id>/<sub-path>)
        $parts   = explode('/', trim($path, '/'));
        $branch  = array_shift($parts);
        $subPath = implode('/', $parts);

        // collect paths to run p4 dirs on (early exit for non-existent branch)
        try {
            $branch = $project->getBranch($branch);
        } catch (\InvalidArgumentException $e) {
            return array();
        }
        foreach ($branch['paths'] as $branchPath) {
            // only run dirs on paths ending with '...'
            // @todo revisit this if we relax wildcard restrictions in branch path validator
            if (substr($branchPath, -3) !== '...') {
                continue;
            }

            // before we can run dirs, we need to convert the recursive '...' into a shallow '*'.
            // if still at the branch root (ie. no sub-path), just swap the trailing '...' for '*'
            // if down a sub-path, we need to trim back to the last '/', then add subPath/*
            // (trimming back to '/', makes a difference if the user mapped '/path/foo...')
            $paths[] = $subPath
                ? dirname($branchPath) . "/$subPath/*"
                : substr($branchPath, 0, -3) . "*";
        }

        // early exit if we have no paths to run dirs on
        if (!$paths) {
            return array();
        }

        // get dir paths and filter them according to given protections
        $dirs = $ipProtects->filterPaths(
            $p4->run('dirs', array_merge($deleted ? array('-D') : array(), $paths))->getData(),
            Protections::MODE_LIST,
            function ($dir) {
                return rtrim($dir['dir'], '/') . '/';
            }
        );

        // convert dir paths to basenames and return unique entries
        $unique = array();
        foreach ($dirs as $dir) {
            $dir = basename($dir['dir']);
            $unique[$dir] = array('dir' => $dir);
        }

        return array_values($unique);
    }

    /**
     * Get files in the given path (applies ip-filters and a 'natural' sort)
     *
     * @param   string          $path           the path we are currently browsing
     * @param   string          $client         optional client to map files through
     * @param   boolean         $deleted        whether or not deleted files are included
     * @param   Protections     $ipProtects     filter files according to given protections
     * @param   Connection      $p4             the perforce connection to use
     * @return  array           list of files under the given path
     */
    protected function getFiles($path, $client, $deleted, Protections $ipProtects, Connection $p4)
    {
        // no files in the root
        if (!$path) {
            return array();
        }

        $flags   = $deleted ? array() : array('-F', '^headAction=...delete');
        $flags[] = '-Ol';
        $flags[] = '-T';
        $flags[] = ($client ? 'clientFile,' : '') . 'depotFile,headTime,fileSize,headAction';
        $flags[] = $client ? "//$client/$path/*" : "//$path/*";

        $files   = $p4->run('fstat', $flags)->getData();
        $files   = $ipProtects->filterPaths($files, Protections::MODE_LIST, 'depotFile');

        usort(
            $files,
            function ($a, $b) use ($client) {
                $a = basename($client ? $a['clientFile'] : $a['depotFile']);
                $b = basename($client ? $b['clientFile'] : $b['depotFile']);

                // put hidden (.foo) files last - otherwise, just a natural case-insensitive sort
                return (($a[0] === '.') - ($b[0] === '.')) ?: strnatcasecmp($a, $b);
            }
        );

        return $files;
    }

    /**
     * Helper method to get config value for archives 'max_input_size'.
     *
     * @return  int|null    value for archives 'max_input_size' from config or null if not set
     */
    protected function getArchiveMaxInputSize()
    {
        $services = $this->getServiceLocator();
        $config   = $services->get('config') + array('archives' => array());
        return isset($config['archives']['max_input_size'])
            ? (int) $config['archives']['max_input_size']
            : null;
    }
}
