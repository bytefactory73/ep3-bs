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
        $originalToAddress = $toAddress;

        // In test environments, redirect all outgoing mail to a catch-all address.
        $mailMode = $this->getMailMode();
        if ($mailMode === 'test' || ($mailMode === 'auto' && $this->isTestEnvironment())) {
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

        $finalBody = $isHtml
            ? sprintf("%s %s,<br><br>%s<br><br>%s,<br>%s %s<br>%s",
                $this->t('Hello'), $toName, $text, $this->t('Sincerely'), $this->t("Your"), $fromName, $this->optionManager->need('service.website'))
            : sprintf("%s %s,\r\n\r\n%s\r\n\r\n%s,\r\n%s %s\r\n%s",
                $this->t('Hello'), $toName, $text, $this->t('Sincerely'), $this->t("Your"), $fromName, $this->optionManager->need('service.website'));

            // Debug mail delivery removed

        if ($isHtml) {
            $this->baseMailService->sendHtml($fromAddress, $fromName, $replyToAddress, $replyToName, $toAddress, $toName, $subject, $finalBody, $attachments);
        } else {
            $this->baseMailService->sendPlain($fromAddress, $fromName, $replyToAddress, $replyToName, $toAddress, $toName, $subject, $finalBody, $attachments);
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

    private function isTestEnvironment()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim((string)$_SERVER['HTTP_HOST'])) : '';
        if ($host !== '' && strpos($host, 'bookingtest.') === 0) {
            return true;
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? strtolower(trim((string)$_SERVER['REQUEST_URI'])) : '';
        if ($requestUri !== '' && (strpos($requestUri, '/bookingtest/') !== false || strpos($requestUri, '/bookingtest') === 0 || strpos($requestUri, '/bookingTest') !== false)) {
            return true;
        }

        try {
            $serviceWebsite = strtolower(trim((string)$this->optionManager->get('service.website', '')));
        } catch (\Throwable $e) {
            $serviceWebsite = '';
        }

        if ($serviceWebsite === '') {
            return false;
        }

        $parsedHost = parse_url($serviceWebsite, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '' && strpos(strtolower($parsedHost), 'bookingtest.') === 0) {
            return true;
        }

        return strpos($serviceWebsite, 'bookingtest.') !== false;
    }

    private function getMailMode()
    {
        $value = getenv('EP3_BS_MAIL_MODE');
        if ($value === false) {
            $value = getenv('MAIL_MODE');
        }

        $value = strtolower(trim((string)$value));
        if (in_array($value, array('test', 'live', 'auto'), true)) {
            return $value;
        }

        return 'auto';
    }

}