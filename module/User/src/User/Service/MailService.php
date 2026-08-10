<?php

namespace User\Service;

use Base\Manager\ConfigManager;
use Base\Manager\OptionManager;
use Base\Service\AbstractService;
use Base\Service\MailService as BaseMailService;
use User\Entity\User;

class MailService extends AbstractService
{

    protected $baseMailService;
    protected $configManager;
    protected $optionManager;

    public function __construct(BaseMailService $baseMailService, ConfigManager $configManager, OptionManager $optionManager)
    {
        $this->baseMailService = $baseMailService;
        $this->configManager = $configManager;
        $this->optionManager = $optionManager;
    }

    public function sendTo($fromAddress, $fromName, $replyToAddress, $replyToName, User $recipient, $subject, $text, $optionsOrAttachments = array())
    {
        $toAddress = $recipient->need('email');
        $toName = $recipient->get('alias') ?: $recipient->get('name') ?: '';

        // In test environments, redirect all outgoing mail to a catch-all address
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
        if ($host !== '' && strpos($host, 'bookingtest.') === 0) {
            $toAddress = 'sport@kuehn-clan.de';
            $toName = $toName . ' [TEST redirect from ' . $recipient->need('email') . ']';
            $subject = '[TEST] ' . $subject;
        }

        // Compatibility: if 4th argument is not an array or is a numerically indexed array, treat as attachments (old usage)
        $isHtml = false;
        $attachments = array();
        if (is_array($optionsOrAttachments) && (array_keys($optionsOrAttachments) === range(0, count($optionsOrAttachments) - 1))) {
            // Old usage: attachments array
            $attachments = $optionsOrAttachments;
        } elseif (is_array($optionsOrAttachments)) {
            // New usage: options array
            $isHtml = isset($optionsOrAttachments['isHtml']) && $optionsOrAttachments['isHtml'];
            $attachments = isset($optionsOrAttachments['attachments']) ? $optionsOrAttachments['attachments'] : array();
        }

        if ($isHtml) {
            $body = sprintf("%s %s,<br><br>%s<br><br>%s,<br>%s %s<br>%s",
                $this->t('Hello'), $toName, $text, $this->t('Sincerely'), $this->t("Your"), $fromName, $this->optionManager->need('service.website'));
            $this->baseMailService->sendHtml($fromAddress, $fromName, $replyToAddress, $replyToName, $toAddress, $toName, $subject, $body, $attachments);
        } else {
            $body = sprintf("%s %s,\r\n\r\n%s\r\n\r\n%s,\r\n%s %s\r\n%s",
                $this->t('Hello'), $toName, $text, $this->t('Sincerely'), $this->t("Your"), $fromName, $this->optionManager->need('service.website'));
            $this->baseMailService->sendPlain($fromAddress, $fromName, $replyToAddress, $replyToName, $toAddress, $toName, $subject, $body, $attachments);
        }
    }

    public function sendFromTheke(User $recipient, $subject, $text, $optionsOrAttachments = array())
    {
        $fromAddress = "theke@stc-butzbach.de";
        $fromName = "STC-Butzbach-Theke";
        $replyToAddress = "theke@stc-butzbach.de";
        $replyToName = "STC-Butzbach-Theke";
        return $this->sendTo($fromAddress, $fromName, $replyToAddress, $replyToName, $recipient, $subject, $text, $optionsOrAttachments);
    }

    public function send(User $recipient, $subject, $text, $optionsOrAttachments = array())
    {
        $fromAddress = $this->configManager->need('mail.address');
        $fromName = $this->optionManager->need('client.name.short') . ' ' . $this->optionManager->need('service.name.full');
        $replyToAddress = $this->optionManager->need('client.contact.email');
        $replyToName = $this->optionManager->need('client.name.full');
        return $this->sendTo($fromAddress, $fromName, $replyToAddress, $replyToName, $recipient, $subject, $text, $optionsOrAttachments);
    }
}