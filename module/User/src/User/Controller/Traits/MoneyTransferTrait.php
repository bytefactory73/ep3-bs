<?php

namespace User\Controller\Traits;

trait MoneyTransferTrait
{
    protected $moneyTransferHasReferenceColumns = null;

    protected function canUseTransferReferenceColumns($dbAdapter)
    {
        if ($this->moneyTransferHasReferenceColumns !== null) {
            return $this->moneyTransferHasReferenceColumns;
        }

        try {
            $orderCol = $dbAdapter->query("SHOW COLUMNS FROM drink_orders LIKE 'transfer_reference'", [])->current();
            $depositCol = $dbAdapter->query("SHOW COLUMNS FROM drink_deposits LIKE 'transfer_reference'", [])->current();
            $this->moneyTransferHasReferenceColumns = (bool)$orderCol && (bool)$depositCol;
        } catch (\Exception $e) {
            $this->moneyTransferHasReferenceColumns = false;
        }

        return $this->moneyTransferHasReferenceColumns;
    }

    protected function createMoneyTransferReference()
    {
        try {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
            $hex = bin2hex($bytes);
            return sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20, 12)
            );
        } catch (\Exception $e) {
            // Last-resort fallback: pseudo-random UUID-like string.
            $seed = md5(uniqid((string)mt_rand(), true));
            return sprintf(
                '%s-%s-4%s-%s%s-%s',
                substr($seed, 0, 8),
                substr($seed, 8, 4),
                substr($seed, 13, 3),
                dechex((hexdec(substr($seed, 16, 1)) & 0x3) | 0x8),
                substr($seed, 17, 3),
                substr($seed, 20, 12)
            );
        }
    }

    protected function getInsertIdFromResult($insertResult)
    {
        if (is_object($insertResult) && method_exists($insertResult, 'getGeneratedValue')) {
            return (int)$insertResult->getGeneratedValue();
        }
        return 0;
    }

    protected function executeMoneyTransfer($senderUserId, $receiverUserId, $amount)
    {
        $senderUserId = (int)$senderUserId;
        $receiverUserId = (int)$receiverUserId;
        $amount = round((float)$amount, 2);

        if ($senderUserId <= 0) {
            return [
                'statusCode' => 401,
                'payload' => ['success' => false, 'error' => 'Not authenticated.'],
            ];
        }
        if ($receiverUserId <= 0) {
            return [
                'statusCode' => 400,
                'payload' => ['success' => false, 'error' => 'Empfänger fehlt.'],
            ];
        }
        if ($receiverUserId === $senderUserId) {
            return [
                'statusCode' => 400,
                'payload' => ['success' => false, 'error' => 'Empfänger darf nicht identisch sein.'],
            ];
        }
        if ($amount <= 0) {
            return [
                'statusCode' => 400,
                'payload' => ['success' => false, 'error' => 'Betrag muss positiv sein.'],
            ];
        }

        $serviceManager = $this->getServiceLocator();
        $userManager = $serviceManager->get('User\\Manager\\UserManager');
        $senderUser = $userManager->get($senderUserId);
        $receiverUser = $userManager->get($receiverUserId);

        if (!$senderUser || !$receiverUser) {
            return [
                'statusCode' => 404,
                'payload' => ['success' => false, 'error' => 'Nutzer nicht gefunden.'],
            ];
        }

        $senderName = trim((string)($senderUser->get('alias') ?: $senderUser->get('name')));
        $receiverName = trim((string)($receiverUser->get('alias') ?: $receiverUser->get('name')));

        $drinkDepositManager = $serviceManager->get('Drinks\\Manager\\DrinkDepositManager');
        $drinkOrderManager = $serviceManager->get('Drinks\\Manager\\DrinkOrderManager');
        $drinkManager = $serviceManager->get('Drinks\\Manager\\DrinkManager');
        $dbAdapter = $serviceManager->get('Zend\\Db\\Adapter\\Adapter');
        $transferReference = $this->createMoneyTransferReference();
        $canUseTransferReference = $this->canUseTransferReferenceColumns($dbAdapter);

        try {
            // Sender side: transfer out as an expense order (positive price).
            $orderInsertResult = $drinkOrderManager->addOrder(
                $senderUserId,
                1,
                1,
                $senderUserId,
                0,
                'Geld senden an ' . $receiverName,
                $amount,
                null
            );
            $orderId = $this->getInsertIdFromResult($orderInsertResult);
            if ($canUseTransferReference && $orderId > 0) {
                $dbAdapter->query('UPDATE drink_orders SET transfer_reference = ? WHERE id = ?', [$transferReference, $orderId]);
            }

            // Receiver side: transfer in as positive deposit.
            $depositInsertResult = $drinkDepositManager->addDeposit(
                $receiverUserId,
                $amount,
                'Geld empfangen von ' . $senderName,
                $senderUserId,
                null,
                null
            );
            $depositId = $this->getInsertIdFromResult($depositInsertResult);
            if ($canUseTransferReference && $depositId > 0) {
                $dbAdapter->query('UPDATE drink_deposits SET transfer_reference = ? WHERE id = ?', [$transferReference, $depositId]);
            }

            $userMailService = $serviceManager->get('User\\Service\\MailService');

            $senderSubject = $this->t('Geld versendet');
            $senderText = sprintf(
                $this->t('Du hast %.2f EUR an %s überwiesen.'),
                $amount,
                $receiverName
            );
            $userMailService->sendFromTheke($senderUser, $senderSubject, $senderText, ['isHtml' => false]);

            $receiverSubject = $this->t('Geld erhalten');
            $receiverText = sprintf(
                $this->t('Du hast %.2f EUR von %s erhalten.'),
                $amount,
                $senderName
            );
            $userMailService->sendFromTheke($receiverUser, $receiverSubject, $receiverText, ['isHtml' => false]);
        } catch (\Exception $e) {
            return [
                'statusCode' => 500,
                'payload' => ['success' => false, 'error' => 'Senden fehlgeschlagen.'],
            ];
        }

        $newBalance = (float)$drinkManager->calculateUserDrinkBalance($senderUserId, $serviceManager);
        return [
            'statusCode' => 200,
            'payload' => [
                'success' => true,
                'balance' => $newBalance,
            ],
        ];
    }
}
