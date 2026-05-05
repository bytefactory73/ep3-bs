<?php

namespace User\Controller\Traits;

trait ThekeMailTrait
{
    protected function sendFromTheke($mailService, $dbAdapter, $recipient, $subject, $text, $optionsOrAttachments = array())
    {
        return $mailService->sendFromTheke(
            $this->resolveThekeMailRecipient($recipient, $dbAdapter),
            $subject,
            $text,
            $optionsOrAttachments
        );
    }

    protected function resolveThekeMailRecipient($recipient, $dbAdapter)
    {
        if (!is_object($recipient) || !method_exists($recipient, 'need')) {
            return $recipient;
        }

        try {
            $recipientUserId = (int)$recipient->need('uid');
            if ($recipientUserId <= 0) {
                return $recipient;
            }
            $row = $dbAdapter->query('SELECT teamlead_email FROM drink_aliases WHERE user_id = ?', [$recipientUserId])->current();
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
