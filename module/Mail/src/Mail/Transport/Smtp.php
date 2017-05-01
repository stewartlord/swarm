<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Mail\Transport;

use Zend\Mail\Headers;
use Zend\Mail\Message;
use Zend\Mail\Protocol;
use Zend\Mail\Transport\Smtp as ZendSmtp;
use Zend\Mail\Protocol\Exception;

class Smtp extends ZendSmtp
{
    /**
     * Send an email via the SMTP connection protocol
     *
     * Extends parent to ignore individual addresses failing server validation.
     * Note, the bulk of this method is a copy/paste from parent, the try/catch
     * in the foreach rcpt loop is our only change of substance.
     *
     * We anticipate caller will screen out blatantly bad email addresses but
     * we also ignore server complaints so valid users have a good shot at
     * getting messages even if they follow invalid users.
     *
     * @param   Message     $message    the message to send
     * @throws  Exception\RuntimeException
     */
    public function send(Message $message)
    {
        // If sending multiple messages per session use existing adapter
        $connection = $this->getConnection();

        if (!($connection instanceof Protocol\Smtp) || !$connection->hasSession()) {
            $connection = $this->connect();
        } else {
            // Reset connection to ensure reliable transaction
            $connection->rset();
        }

        // Prepare message
        $from       = $this->prepareFromAddress($message);
        $recipients = $this->prepareRecipients($message);
        $headers    = $this->prepareHeaders($message);
        $body       = $this->prepareBody($message);

        if ((count($recipients) == 0) && (!empty($headers) || !empty($body))) {
            // Per RFC 2821 3.3 (page 18)
            throw new Exception\RuntimeException(
                sprintf(
                    '%s transport expects at least one recipient if the message has at least one header or body',
                    __CLASS__
                )
            );
        }

        // Set sender email address
        $connection->mail($from);

        // Set recipient forward paths
        foreach ($recipients as $recipient) {
            // This try/catch is our only modification of note to parent
            try {
                $connection->rcpt($recipient);
            } catch (Exception\RuntimeException $e) {
                // Just skip it, data will throw if no valid recipients were found
            }
        }

        // @todo: remove this fix once ZF2 mailer is updated
        // note, the ZF2 fix currently only works on Linux not Windows :( so we cannot rely on it
        // ensure newlines are "\r\n" for ZF2 mailer
        $body = str_replace("\n", "\r\n", str_replace("\r", '', $body));

        // Issue DATA command to client
        $connection->data($headers . Headers::EOL . $body);
    }
}
