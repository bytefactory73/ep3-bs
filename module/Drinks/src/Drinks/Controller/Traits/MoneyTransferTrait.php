<?php

namespace Drinks\Controller\Traits;

use Drinks\Service\DbSchema;

/**
 * Money transfers between drink accounts. Requires TeamEventTrait and JsonResponseTrait.
 */
trait MoneyTransferTrait
{
    use ThekeMailTrait;

    /**
     * JSON response for a transfer from $senderUserId to the POSTed receiver_user_id
     * (team_event_id for a team receiver, amount, optional transfer_key).
     */
    protected function moneyTransferFromPost($senderUserId)
    {
        $amount = round((float)str_replace(',', '.', trim((string)$this->params()->fromPost('amount', ''))), 2);
        $transferResult = $this->executeMoneyTransfer(
            $senderUserId,
            (int)$this->params()->fromPost('receiver_user_id', 0),
            $amount,
            (int)$this->params()->fromPost('team_event_id', 0),
            false,
            (string)$this->params()->fromPost('transfer_key', '')
        );
        return $this->jsonResponse($transferResult['payload'], $transferResult['statusCode']);
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

    /**
     * $transferKey: optional client-generated UUID (one per submit). It is stored as
     * transfer_reference; a retry with the same key returns success without moving money again.
     */
    protected function executeMoneyTransfer($senderUserId, $receiverUserId, $amount, $receiverTeamEventId = 0, $allowClosedReceiverTeamEvent = false, $transferKey = null)
    {
        $transferKey = is_string($transferKey) ? strtolower(trim($transferKey)) : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $transferKey)) {
            $transferKey = '';
        }
        $dbAdapter = $this->getServiceLocator()->get('Zend\\Db\\Adapter\\Adapter');
        if ($transferKey === '' || !DbSchema::hasTransferReferenceColumns($dbAdapter)) {
            return $this->runMoneyTransfer($senderUserId, $receiverUserId, $amount, $receiverTeamEventId, $allowClosedReceiverTeamEvent, null);
        }

        $lockName = 'drinks_transfer_' . $transferKey;
        $lockRow = $dbAdapter->query('SELECT GET_LOCK(?, 10) AS got_lock', [$lockName])->current();
        if (!$lockRow || (int)$lockRow['got_lock'] !== 1) {
            return [
                'statusCode' => 409,
                'payload' => ['success' => false, 'error' => 'Überweisung wird bereits verarbeitet.'],
            ];
        }
        try {
            $existing = $dbAdapter->query('SELECT id, user_id FROM drink_orders WHERE transfer_reference = ? LIMIT 1', [$transferKey])->current();
            if ($existing) {
                if ((int)$existing['user_id'] !== (int)$senderUserId) {
                    return [
                        'statusCode' => 409,
                        'payload' => ['success' => false, 'error' => 'Ungültige Überweisungs-ID.'],
                    ];
                }
                $drinkManager = $this->getServiceLocator()->get('Drinks\\Manager\\DrinkManager');
                return [
                    'statusCode' => 200,
                    'payload' => [
                        'success' => true,
                        'already_processed' => true,
                        'balance' => (float)$drinkManager->calculateUserDrinkBalance((int)$senderUserId, $this->getServiceLocator()),
                    ],
                ];
            }
            return $this->runMoneyTransfer($senderUserId, $receiverUserId, $amount, $receiverTeamEventId, $allowClosedReceiverTeamEvent, $transferKey);
        } finally {
            try {
                $dbAdapter->query('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Exception $e) {
                // released with the connection anyway
            }
        }
    }

    private function runMoneyTransfer($senderUserId, $receiverUserId, $amount, $receiverTeamEventId, $allowClosedReceiverTeamEvent, $transferKey)
    {
        $senderUserId = (int)$senderUserId;
        $receiverUserId = (int)$receiverUserId;
        $amount = round((float)$amount, 2);
        $receiverTeamEventId = (int)$receiverTeamEventId;
        $allowClosedReceiverTeamEvent = (bool)$allowClosedReceiverTeamEvent;

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
        $senderUser = $userManager->get($senderUserId, false);
        $receiverUser = $userManager->get($receiverUserId, false);

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
        if (!$allowClosedReceiverTeamEvent && !$drinkManager->isOrderAllowed($senderUserId, $amount, $serviceManager)) {
            return [
                'statusCode' => 400,
                'payload' => ['success' => false, 'error' => 'Kein Geld senden möglich bis Guthaben aufgeladen ist'],
            ];
        }
        $receiverIsTeam = $drinkManager->isTeamAccount($receiverUserId);
        $transferTeamEventId = ($receiverTeamEventId > 0) ? $receiverTeamEventId : null;
        if ($receiverIsTeam) {
            if ($receiverTeamEventId <= 0) {
                return [
                    'statusCode' => 400,
                    'payload' => ['success' => false, 'error' => 'Bitte Spieltag auswählen.'],
                ];
            }
            $receiverTeamEvent = null;
            if ($allowClosedReceiverTeamEvent) {
                $receiverTeamEvent = $this->getTeamEventById($receiverUserId, $receiverTeamEventId);
            }
            if (!$receiverTeamEvent) {
                $receiverTeamEvent = $this->resolveTeamEventForSelection($receiverUserId, $receiverTeamEventId, '');
            }
            if (!$receiverTeamEvent || empty($receiverTeamEvent['id'])) {
                return [
                    'statusCode' => 400,
                    'payload' => ['success' => false, 'error' => 'Bitte gültigen Spieltag auswählen.'],
                ];
            }
            $transferTeamEventId = (int)$receiverTeamEvent['id'];
        }
        $transferReference = $transferKey !== null ? $transferKey : $this->createMoneyTransferReference();
        $canUseTransferReference = DbSchema::hasTransferReferenceColumns($dbAdapter);

        // Order (sender) and deposit (receiver) are written atomically: either both or neither.
        $connection = $dbAdapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            // Sender side: transfer out as an expense order (positive price).
            $orderInsertResult = $drinkOrderManager->addOrder(
                $senderUserId,
                -1,
                1,
                $senderUserId,
                0,
                'Geld senden an ' . $receiverName,
                $amount,
                $transferTeamEventId
            );
            $orderId = $this->getInsertIdFromResult($orderInsertResult);
            if ($orderId <= 0) {
                throw new \RuntimeException('Transfer order insert failed');
            }
            if ($canUseTransferReference) {
                $dbAdapter->query('UPDATE drink_orders SET transfer_reference = ? WHERE id = ?', [$transferReference, $orderId]);
            }

            // Receiver side: transfer in as positive deposit.
            $depositInsertResult = $drinkDepositManager->addDeposit(
                $receiverUserId,
                $amount,
                'Geld empfangen von ' . $senderName,
                $senderUserId,
                null,
                $transferTeamEventId
            );
            $depositId = $this->getInsertIdFromResult($depositInsertResult);
            if ($depositId <= 0) {
                throw new \RuntimeException('Transfer deposit insert failed');
            }
            if ($canUseTransferReference) {
                $dbAdapter->query('UPDATE drink_deposits SET transfer_reference = ? WHERE id = ?', [$transferReference, $depositId]);
            }

            $connection->commit();
        } catch (\Exception $e) {
            try {
                $connection->rollback();
            } catch (\Exception $rollbackException) {
                error_log('Money transfer rollback failed: ' . $rollbackException->getMessage());
            }
            error_log('Money transfer failed: ' . $e->getMessage());
            return [
                'statusCode' => 500,
                'payload' => ['success' => false, 'error' => 'Senden fehlgeschlagen.'],
            ];
        }

        // Notifications after commit: a mail failure must not report a completed transfer as failed.
        try {
            $userMailService = $serviceManager->get('User\\Service\\MailService');

            $senderSubject = $this->t('Geld versendet');
            $senderText = sprintf(
                $this->t('Du hast %.2f EUR an %s überwiesen.'),
                $amount,
                $receiverName
            );
            $this->sendFromTheke($userMailService, $dbAdapter, $senderUser, $senderSubject, $senderText, ['isHtml' => false]);
        } catch (\Exception $e) {
            error_log('Money transfer notification (sender) failed: ' . $e->getMessage());
        }
        try {
            $userMailService = $serviceManager->get('User\\Service\\MailService');
            $receiverSubject = $this->t('Geld erhalten');
            $receiverText = sprintf(
                $this->t('Du hast %.2f EUR von %s erhalten.'),
                $amount,
                $senderName
            );
            $this->sendFromTheke($userMailService, $dbAdapter, $receiverUser, $receiverSubject, $receiverText, ['isHtml' => false]);
        } catch (\Exception $e) {
            error_log('Money transfer notification (receiver) failed: ' . $e->getMessage());
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