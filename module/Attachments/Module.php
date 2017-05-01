<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Attachments;

use Application\Filter\ShorthandBytes;
use Attachments\Model\Attachment;
use Zend\Mvc\MvcEvent;

class Module
{
    /**
     * Connect to queue event manager to handle attachment cleanup.
     *
     * @param   MvcEvent    $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $events      = $services->get('queue')->getEventManager();

        $events->attach(
            'task.cleanup.attachment',
            function ($event) use ($services) {
                $p4Admin = $services->get('p4_admin');
                $id      = $event->getParam('id');

                try {
                    $attachment = Attachment::fetch($id, $p4Admin);
                    $event->setParam('attachment', $attachment);

                    if (!$attachment->getReferences()) {
                        $attachment->delete();
                    }
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            }
        );
    }

    public function getConfig()
    {
        $config = include __DIR__ . '/config/module.config.php';

        // set default max size to php's upload_max_filesize (in bytes - e.g., "8M" must be converted to 8388608)
        if (empty($config['attachments']['max_file_size'])) {
            $config['attachments']['max_file_size'] = ShorthandBytes::toBytes(ini_get('upload_max_filesize'));
        }

        return $config;
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
}
