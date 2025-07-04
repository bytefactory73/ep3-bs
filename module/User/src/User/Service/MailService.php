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

    public function send(User $recipient, $subject, $text, $optionsOrAttachments = array())
    {
        $fromAddress = $this->configManager->need('mail.address');
        $fromName = $this->optionManager->need('client.name.short') . ' ' . $this->optionManager->need('service.name.full');

        $replyToAddress = $this->optionManager->need('client.contact.email');
        $replyToName = $this->optionManager->need('client.name.full');

        $toAddress = $recipient->need('email');
        $toName = $recipient->need('alias');

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

}