<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

use Zend\Mail\Transport;

return array(
    'mail' => array(
        'sender'         => null,
        'recipients'     => null,
        'subject_prefix' => '[Swarm]',
        'use_bcc'        => false,
        'use_replyto'    => true,
        'transport'      => array(),
    ),
    'security' => array(
        'email_restricted_changes' => false
    ),
    'service_manager' => array(
        'factories' => array(
            'mailer' => function ($serviceManager) {
                $config = $serviceManager->get('Configuration');
                $config = $config['mail']['transport'];

                if (isset($config['path']) && $config['path']) {
                    return new Transport\File(new Transport\FileOptions($config));
                } elseif (isset($config['host']) && $config['host']) {
                    return new \Mail\Transport\Smtp(new Transport\SmtpOptions($config));
                }

                return new Transport\Sendmail(
                    isset($config['sendmail_parameters']) ? $config['sendmail_parameters'] : null
                );
            },
        ),
    ),
);
