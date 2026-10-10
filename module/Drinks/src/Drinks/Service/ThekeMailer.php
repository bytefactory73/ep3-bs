<?php

namespace Drinks\Service;

/**
 * Mails of the Theke (orders, deposits, transfers), sent from the Theke address. A team account's
 * mail goes to its teamlead_email instead, if one is set.
 */
class ThekeMailer
{
    private $mailService;
    private $dbAdapter;

    public function __construct($mailService, $dbAdapter)
    {
        $this->mailService = $mailService;
        $this->dbAdapter = $dbAdapter;
    }

    public function send($recipient, $subject, $text, $optionsOrAttachments = array())
    {
        return $this->mailService->sendFromTheke($this->resolveRecipient($recipient), $subject, $text, $optionsOrAttachments);
    }

    private function resolveRecipient($recipient)
    {
        if (!is_object($recipient) || !method_exists($recipient, 'need')) {
            return $recipient;
        }

        try {
            $recipientUserId = (int)$recipient->need('uid');
            if ($recipientUserId <= 0) {
                return $recipient;
            }
            $row = $this->dbAdapter->query('SELECT teamlead_email FROM drink_aliases WHERE user_id = ?', [$recipientUserId])->current();
            $teamleadEmail = ($row && isset($row['teamlead_email'])) ? trim((string)$row['teamlead_email']) : '';
            if ($teamleadEmail !== '' && filter_var($teamleadEmail, FILTER_VALIDATE_EMAIL)) {
                $recipientClone = clone $recipient;
                $recipientClone->set('email', $teamleadEmail, true, false);
                return $recipientClone;
            }
        } catch (\Exception $e) {
            // Fall back to original recipient.
        }

        return $recipient;
    }
}
