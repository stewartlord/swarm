<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Attachments\Controller;

use Application\Response\CallbackResponse;
use Attachments\Model\Attachment;
use P4\File\File;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Record\Exception\NotFoundException;

class IndexController extends AbstractActionController
{
    /**
     * Retrieve an attachment and output it to the browser.
     */
    public function indexAction()
    {
        $services = $this->getServiceLocator();
        $depot    = $services->get('depot_storage');
        $p4Admin  = $services->get('p4_admin');
        $id       = $this->getEvent()->getRouteMatch()->getParam('attachment');
        $filename = $this->getEvent()->getRouteMatch()->getParam('filename', null);
        $download = $this->getRequest()->getQuery('download');

        try {
            $attachment = Attachment::fetch($id, $p4Admin);
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        if (!File::exists($attachment->get('depotFile'), $p4Admin, true)) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        // if download flag wasn't provided, choose the default action
        // based on whether the attachment is a web-safe image
        // otherwise, obey the download flag
        if ($download === null) {
            $download = $attachment->isWebSafeImage() ? false : true;
        } elseif (in_array(strtolower($download), array('0', 'false'))) {
            $download = false;
        } else {
            $download = true;
        }

        // if filename was provided in the url but doesn't match the one on record, report it as a 404
        if ($filename && $filename != $attachment->get('name')) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $response    = new CallbackResponse();
        $cacheOffset = 12 * 60 * 60; // 12 hours

        $response->getHeaders()
            ->addHeaderLine('Content-Type', $attachment->get('type'))
            ->addHeaderLine('Content-Transfer-Encoding', 'binary')
            ->addHeaderLine('Expires', strftime('%a, %d %b %Y %H:%M:%S %Z', time() + $cacheOffset))
            ->addHeaderLine('Cache-Control', 'max-age=' . $cacheOffset)
            ->addHeaderLine('Content-Length', $attachment->get('size'));

        // we need a content-disposition header if downloading or if no filename in URL
        if ($download || !$filename) {
            if (!$filename) {
                $filename = strtr($attachment->get('name'), "\",\r\n", '-');
            } else {
                $filename = null;
            }

            $disposition = ($download ? 'attachment' : '')
                . ($download && $filename ? '; ' : '')
                . ($filename ? 'filename="' . $filename . '"' : '');

            $response->getHeaders()->addHeaderLine('Content-Disposition', $disposition);
        }

        // let's stream the response! this will save memory and hopefully improve performance.
        $response->setCallback(
            function () use ($depot, $attachment) {
                return $depot->stream($attachment->get('depotFile'));
            }
        );

        return $response;
    }

    /**
     * Ajax end point for adding an attachment to Swarm.
     *
     * @return JsonModel            metadata about the added attachment, including the newly-assigned attachment ID
     * @throws \RuntimeException    if the upload failed or the file is too large
     */
    public function addAction()
    {
        if (!isset($_FILES['file']['tmp_name']) || !strlen($_FILES['file']['tmp_name'])) {
            throw new \RuntimeException("Cannot add attachment. File did not upload correctly.");
        }

        $services = $this->getServiceLocator();
        $p4Admin  = $services->get('p4_admin');
        $queue    = $services->get('queue');
        $config   = $services->get('config');
        $maxSize  = $config['attachments']['max_file_size'];
        $file     = $_FILES['file'];

        if ($file['size'] > $maxSize) {
            throw new \RuntimeException(
                'Attachment ' . $file['name'] . ' exceeds maximum file size of ' . $maxSize . ' bytes'
            );
        }

        $attachment = new Attachment($p4Admin);
        $attachment->set(
            array(
                'name' => urldecode($file['name']),
                'size' => $file['size'],
                'type' => $file['type'],
            )
        );

        // this local file will be consumed (deleted) as part of ->save()
        $attachment->save($file['tmp_name']);

        // cleanup is required in case the user never posts the comment that the attachment is intended for
        // cleanup only performs a delete, not an obliterate, so the data is still recoverable
        $queue->addTask('cleanup.attachment', $attachment->getId(), null, strtotime('+24 hours'));

        return new JsonModel(
            array(
                'isValid'    => true,
                'attachment' => array(
                    'id'   => $attachment->getId(),
                    'name' => $attachment->get('name'),
                    'type' => $attachment->get('type'),
                    'size' => $attachment->get('size')
                )
            )
        );
    }
}
