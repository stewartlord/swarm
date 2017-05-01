<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Mail;

use Activity\Model\Activity;
use P4\Spec\Change;
use P4\Spec\Exception\NotFoundException;
use Users\Model\User;
use Zend\Mail\Message;
use Zend\Mime\Message as MimeMessage;
use Zend\Mime\Part as MimePart;
use Zend\Mvc\MvcEvent;
use Zend\Stdlib\StringUtils;
use Zend\Validator\EmailAddress;
use Zend\View\Model\ViewModel;
use Zend\View\Resolver\TemplatePathStack;

class Module
{
    /**
     * Connect to queue events to send email notifications
     *
     * @param   Event   $event  the bootstrap event
     * @return  void
     */
    public function onBootstrap(MvcEvent $event)
    {
        $application = $event->getApplication();
        $services    = $application->getServiceManager();
        $manager     = $services->get('queue');
        $events      = $manager->getEventManager();

        // send email notifications for task events that prepare mail data.
        // we use a very low priority so that others can influence the message.
        $events->attach(
            '*',
            function ($event) use ($application, $services) {
                $mail             = $event->getParam('mail');
                $activity         = $event->getParam('activity');
                $restrictByChange = $event->getParam('restrictByChange', $activity ? $activity->get('change') : null);
                if (!is_array($mail)) {
                    return;
                }

                // ignore 'quiet' events.
                $data  = (array) $event->getParam('data') + array('quiet' => null);
                $quiet = $event->getParam('quiet', $data['quiet']);
                if ($quiet === true || in_array('mail', (array) $quiet)) {
                    return;
                }

                // normalize and validate message configuration
                $mail += array(
                    'to'           => null,
                    'toUsers'      => null,
                    'subject'      => null,
                    'cropSubject'  => false,
                    'fromAddress'  => null,
                    'fromName'     => null,
                    'fromUser'     => null,
                    'messageId'    => null,
                    'inReplyTo'    => null,
                    'htmlTemplate' => null,
                    'textTemplate' => null,
                );

                // detect bad templates, clear them (to avoid later errors) and log it
                $invalidTemplates = array();
                foreach (array('htmlTemplate', 'textTemplate') as $templateKey) {
                    if ($mail[$templateKey] && !is_readable($mail[$templateKey])) {
                        $invalidTemplates[] = $mail[$templateKey];
                        $mail[$templateKey] = null;
                    }
                }
                if (count($invalidTemplates)) {
                    $services->get('logger')->err(
                        'Invalid mail template(s) specified: ' . implode(', ', $invalidTemplates)
                    );
                }

                // if we don't have any valid templates, we can't send email
                if (!$mail['htmlTemplate'] && !$mail['textTemplate']) {
                    $services->get('logger')->err("Cannot send mail. No valid templates specified.");
                    return;
                }

                // normalize mail configuration, start by ensuring all of the keys are at least present
                $configs = $services->get('config') + array('mail' => array());
                $config  = $configs['mail'] +
                    array(
                        'sender'         => null,
                        'recipients'     => null,
                        'subject_prefix' => null,
                        'use_bcc'        => null,
                        'use_replyto'    => true
                    );

                // if we are configured not to email events involving restricted changes
                // and this event has a change to restrict by, dig into the associated change.
                // if the associated change ends up being restricted, bail.
                if ((!isset($configs['security']['email_restricted_changes'])
                    || !$configs['security']['email_restricted_changes'])
                    && $restrictByChange
                ) {
                    // try and re-use the event's change if it has a matching id otherwise do a fetch
                    $change = $event->getParam('change');
                    if (!$change instanceof Change || $change->getId() != $restrictByChange) {
                        try {
                            $change = Change::fetch($restrictByChange, $services->get('p4_admin'));
                        } catch (NotFoundException $e) {
                            // if we cannot fetch the change, we have to assume
                            // it's restricted and bail out of sending email
                            return;
                        }
                    }

                    // if the change is restricted, don't email just bail
                    if ($change->getType() == Change::RESTRICTED_CHANGE) {
                        return;
                    }
                }

                // if sender has no value use the default
                $config['sender'] = $config['sender'] ?: 'notifications@' . $configs['environment']['hostname'];

                // if subject prefix was specified or is an empty string, use it.
                // for unspecified or null subject prefixes we use the default.
                $config['subject_prefix'] = $config['subject_prefix'] || $config['subject_prefix'] === ''
                    ? $config['subject_prefix'] : '[Swarm]';

                // as a convenience, listeners may specify to/from as usernames
                // and we will resolve these into the appropriate email addresses.
                $to    = (array) $mail['to'];
                $users = array_unique(array_merge((array) $mail['toUsers'], (array) $mail['fromUser']));
                if (count($users)) {
                    $p4Admin = $services->get('p4_admin');
                    $users   = User::fetchAll(array(User::FETCH_BY_NAME => $users), $p4Admin);
                }
                if (is_array($mail['toUsers'])) {
                    foreach ($mail['toUsers'] as $toUser) {
                        if (isset($users[$toUser])) {
                            $to[] = $users[$toUser]->getEmail();
                        }
                    }
                }
                if (isset($users[$mail['fromUser']])) {
                    $fromUser            = $users[$mail['fromUser']];
                    $mail['fromAddress'] = $fromUser->getEmail()    ?: $mail['fromAddress'];
                    $mail['fromName']    = $fromUser->getFullName() ?: $mail['fromName'];
                }

                // remove any duplicate or empty recipient addresses
                $to = array_unique(array_filter($to, 'strlen'));

                // filter out invalid addresses from the list of recipients
                $validator = new EmailAddress;
                $to = array_filter($to, array($validator, 'isValid'));

                // if we don't have any recipients, nothing more to do
                if (!$to && !$config['recipients']) {
                    return;
                }

                // if explicit recipients have been configured (e.g. for testing),
                // log the computed list of recipients for debug purposes.
                if ($config['recipients']) {
                    $services->get('logger')->debug('Mail recipients: ' . implode(', ', $to));
                }

                // prepare view for rendering message template
                // customize view resolver to only look for the specific
                // templates we've been given (note we cloned view, so it's ok)
                $renderer  = clone $services->get('ViewManager')->getRenderer();
                $resolver  = new TemplatePathStack;
                $resolver->addPaths(array(dirname($mail['htmlTemplate']), dirname($mail['textTemplate'])));
                $renderer->setResolver($resolver);
                $viewModel = new ViewModel(
                    array(
                        'services'  => $services,
                        'event'     => $event,
                        'activity'  => $activity
                    )
                );

                // message has up to two parts (html and plain-text)
                $parts = array();
                if ($mail['textTemplate']) {
                    $viewModel->setTemplate(basename($mail['textTemplate']));
                    $text       = new MimePart($renderer->render($viewModel));
                    $text->type = 'text/plain; charset=UTF-8';
                    $parts[]    = $text;
                }
                if ($mail['htmlTemplate']) {
                    $viewModel->setTemplate(basename($mail['htmlTemplate']));
                    $html       = new MimePart($renderer->render($viewModel));
                    $html->type = 'text/html; charset=UTF-8';
                    $parts[]    = $html;
                }

                // prepare subject by applying prefix, collapsing whitespace,
                // trimming whitespace or dashes and optionally cropping
                $subject = $config['subject_prefix'] . ' ' . $mail['subject'];
                if ($mail['cropSubject']) {
                    $utility  = StringUtils::getWrapper();
                    $length   = strlen($subject);
                    $subject  = $utility->substr($subject, 0, (int) $mail['cropSubject']);
                    $subject  = trim($subject, "- \t\n\r\0\x0B");
                    $subject .= strlen($subject) < $length ? '...' : '';
                }
                $subject = preg_replace('/\s+/', " ", $subject);
                $subject = trim($subject, "- \t\n\r\0\x0B");

                // prepare thread-index header for outlook/exchange
                // - thread-index is 6-bytes of FILETIME followed by a 16-byte GUID
                // - time can vary between messages in a thread, but the GUID can't
                // - current time in FILETIME format is the number of 100 nanosecond
                //   intervals since the win32 epoch (January 1, 1601 UTC)
                // - GUID is inReplyTo header md5'd and packed into 16 bytes
                // - the time and GUID are then combined and base-64 encoded
                $fileTime     = (time() + 11644473600) * 10000000;
                $fileTime     = pack('Nn', $fileTime >> 32, $fileTime >> 16);
                $guid         = pack('H*', md5($mail['inReplyTo']));
                $threadIndex  = base64_encode($fileTime . $guid);

                // build the mail message
                $body       = new MimeMessage();
                $body->setParts($parts);
                $message    = new Message();
                $recipients = $config['recipients'] ?: $to;
                if ($config['use_bcc']) {
                    $message->setTo($config['sender'], 'Unspecified Recipients');
                    $message->addBcc($recipients);
                } else {
                    $message->addTo($recipients);
                }
                $message->setSubject($subject);
                $message->setFrom($config['sender'], $mail['fromName']);
                if ($config['use_replyto']) {
                    $message->addReplyTo($mail['fromAddress'] ?: $config['sender'], $mail['fromName']);
                } else {
                    $message->addReplyTo('noreply@' . $configs['environment']['hostname'], 'No Reply');
                }
                $message->setBody($body);
                $message->setEncoding('UTF-8');
                $message->getHeaders()->addHeaders(
                    array_filter(
                        array(
                            'Message-ID'      => $mail['messageId'],
                            'In-Reply-To'     => $mail['inReplyTo'],
                            'References'      => $mail['inReplyTo'],
                            'Thread-Index'    => $threadIndex,
                            'Thread-Topic'    => $subject,
                            'X-Swarm-Host'    => $configs['environment']['hostname'],
                            'X-Swarm-Version' => VERSION,
                        )
                    )
                );

                // set alternative multi-part if we have both html and text templates
                // so that the client knows to show one or the other, not both
                if ($mail['htmlTemplate'] && $mail['textTemplate']) {
                    $message->getHeaders()->get('content-type')->setType('multipart/alternative');
                }

                try {
                    $mailer = $services->get('mailer');
                    $mailer->send($message);

                    // in debug mode, report the subject and recipient information
                    $services->get('logger')->debug(
                        'Email sent. Subject: ' . $subject,
                        array('recipients' => $recipients)
                    );

                    // if we have the option, disconnect to avoid timeouts
                    // unit tests don't have this method so we have to gate the call
                    if (method_exists($mailer, 'disconnect')) {
                        $mailer->disconnect();
                    }
                } catch (\Exception $e) {
                    $services->get('logger')->err($e);
                }
            },
            -200
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
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
