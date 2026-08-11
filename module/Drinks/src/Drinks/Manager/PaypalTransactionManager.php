<?php

namespace Drinks\Manager;

use Zend\Db\Adapter\AdapterInterface;

class PaypalTransactionManager
{
    /** @var AdapterInterface */
    private $dbAdapter;

    /** @var bool */
    private $connectionUtf8mb4Initialized = false;

    public function __construct(AdapterInterface $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
        $this->ensureUtf8mb4Connection();
    }

    private function ensureUtf8mb4Connection()
    {
        if ($this->connectionUtf8mb4Initialized) {
            return;
        }

        try {
            $this->dbAdapter->query('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', []);
            $this->connectionUtf8mb4Initialized = true;
        } catch (\Throwable $e) {
            // Keep legacy behavior if the DB user cannot run SET NAMES explicitly.
            $this->connectionUtf8mb4Initialized = false;
        }
    }

    /**
     * Return all paypal rows related to a user (by linked_user_id or linked_deposit -> deposit.user_id)
     * @param int $userId
     * @return array
     */
    public function getByUser($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) return [];

        // Fetch rows where linked_user_id = ?
        try {
            $rows = $this->dbAdapter->query('SELECT * FROM drinks_paypal WHERE linked_user_id = ? ORDER BY received_at DESC', [$userId])->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        // Additionally, find paypal rows linked to deposits that belong to this user
        try {
            $extra = $this->dbAdapter->query(
                'SELECT p.* FROM drinks_paypal p INNER JOIN drink_deposits d ON p.linked_deposit_id = d.id WHERE d.user_id = ? ORDER BY p.received_at DESC',
                [$userId]
            )->toArray();
            if (!empty($extra)) {
                // merge unique by id
                $existingIds = array_column($rows, 'id');
                foreach ($extra as $r) {
                    if (!in_array($r['id'], $existingIds, true)) $rows[] = $r;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $rows;
    }

    /**
     * Get unlinked PayPal transactions (no linked_deposit_id)
     * @param int $limit
     * @return array
     */
    public function getUnlinked($limit = 100)
    {
        $limit = (int)$limit;
        if ($limit <= 0) $limit = 100;
        try {
            return $this->dbAdapter->query('SELECT * FROM drinks_paypal WHERE linked_deposit_id IS NULL ORDER BY received_at DESC LIMIT ' . $limit, [])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getById($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }
        try {
            $row = $this->dbAdapter->query('SELECT * FROM drinks_paypal WHERE id = ? LIMIT 1', [$id])->current();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function linkToDeposit($paypalId, $depositId, $linkedUserId = null)
    {
        $paypalId = (int)$paypalId;
        $depositId = (int)$depositId;
        if ($paypalId <= 0 || $depositId <= 0) {
            return false;
        }
        $linkedUserId = $linkedUserId !== null ? (int)$linkedUserId : null;

        // Guard: refuse if this deposit is already claimed by a different PayPal entry
        try {
            $conflict = $this->dbAdapter->query(
                'SELECT id FROM drinks_paypal WHERE linked_deposit_id = ? AND id != ? LIMIT 1',
                [$depositId, $paypalId]
            )->current();
            if ($conflict) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        $sql = 'UPDATE drinks_paypal SET linked_deposit_id = ?, linked_user_id = ?, auto_credited = 1, state = ?, processed_at = NOW() WHERE id = ?';
        $params = [$depositId, $linkedUserId, 'depositassigned', $paypalId];
        try {
            $statement = $this->dbAdapter->createStatement($sql, $params);
            $result = $statement->execute();
            return ($result->getAffectedRows() > 0);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Find an existing deposit for a user matching the given amount and date within ±14 days.
     * Excludes deposits already claimed by another PayPal entry.
     * Returns the deposit row or null.
     */
    private function findMatchingDeposit($userId, $amount, $receivedAt)
    {
        $row = $this->dbAdapter->query(
            'SELECT d.id FROM drink_deposits d
             WHERE d.user_id = ? AND d.amount = ? AND (d.deleted IS NULL OR d.deleted = 0)
             AND d.deposit_time BETWEEN DATE_SUB(?, INTERVAL 14 DAY) AND DATE_ADD(?, INTERVAL 14 DAY)
             AND NOT EXISTS (SELECT 1 FROM drinks_paypal p WHERE p.linked_deposit_id = d.id)
             LIMIT 1',
            [(int)$userId, $amount, $receivedAt, $receivedAt]
        )->current();
        return ($row && !empty($row['id'])) ? $row : null;
    }

    /**
     * Import transactions from PayPal Reporting API for the last $days days.
     * Skips transaction IDs that already exist in drinks_paypal.
     */
    public function importFromReportingApi($clientId, $clientSecret, $days = 30, $startDateStr = null, $endDateStr = null)
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        $clientId = trim((string)$clientId);
        $clientSecret = trim((string)$clientSecret);
        if ($clientId === '' || $clientSecret === '') {
            $result['errors'][] = 'PayPal API credentials are missing.';
            return $result;
        }

        try {
            $accessToken = $this->getPaypalAccessToken($clientId, $clientSecret);
        } catch (\Throwable $e) {
            $result['errors'][] = 'Unable to obtain PayPal access token: ' . $e->getMessage();
            return $result;
        }

        // Use explicit date range if provided, otherwise use days parameter
        if ($startDateStr !== null && $endDateStr !== null) {
            try {
                $start = new \DateTime($startDateStr, new \DateTimeZone('UTC'));
                $now = new \DateTime($endDateStr, new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                $result['errors'][] = 'Invalid date format: ' . $e->getMessage();
                return $result;
            }
        } else {
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            $start = clone $now;
            $start->modify('-' . max(1, (int)$days) . ' days');
            $start->setTime(0, 0, 0);
        }

        $page = 1;
        $totalPages = 1;

        do {
            try {
                $url = 'https://api-m.paypal.com/v1/reporting/transactions?' . http_build_query([
                    'start_date' => $start->format('Y-m-d\TH:i:s\Z'),
                    'end_date'   => $now->format('Y-m-d\TH:i:s\Z'),
                    'fields'     => 'all',
                    'page_size'  => 100,
                    'page'       => $page,
                ]);
                $response = $this->paypalApiRequest($url, 'GET', $accessToken);
            } catch (\Throwable $e) {
                $result['errors'][] = 'Reporting API page ' . $page . ': ' . $e->getMessage();
                break;
            }

            if (!isset($response['transaction_details']) || !is_array($response['transaction_details'])) {
                break;
            }
            $totalPages = isset($response['total_pages']) ? max(1, (int)$response['total_pages']) : 1;

            foreach ($response['transaction_details'] as $detail) {
                try {
                    $info = isset($detail['transaction_info']) ? $detail['transaction_info'] : [];
                    $txId = isset($info['transaction_id']) ? trim($info['transaction_id']) : '';
                    if ($txId === '') continue;

                    // Only import money received (positive amount)
                    $amountRaw = isset($info['transaction_amount']['value']) ? (float)$info['transaction_amount']['value'] : 0.0;
                    if ($amountRaw <= 0) {
                        $result['skipped']++;
                        continue;
                    }

                    // Subtract fee (fee_amount is negative, e.g. -0.54, so adding it reduces the amount)
                    $feeRaw = isset($info['fee_amount']['value']) ? (float)$info['fee_amount']['value'] : 0.0;
                    $amount = round($amountRaw + $feeRaw, 2);

                    // Skip if already exists
                    $existing = $this->dbAdapter->query('SELECT id FROM drinks_paypal WHERE paypal_transaction_id = ? LIMIT 1', [$txId])->current();
                    if ($existing) {
                        $result['skipped']++;
                        continue;
                    }

                    $payer = isset($detail['payer_info']) ? $detail['payer_info'] : [];
                    $payerEmail = isset($payer['email_address']) ? strtolower(trim($payer['email_address'])) : null;
                    $payerName = isset($payer['name']['alternate_full_name']) ? trim($payer['name']['alternate_full_name'])
                        : (isset($payer['name']['full_name']) ? trim($payer['name']['full_name']) : null);
                    $transactionNote = isset($info['transaction_note']) ? trim($info['transaction_note']) : (isset($info['transaction_subject']) ? trim($info['transaction_subject']) : null);
                    $transactionNote = $this->normalizeTransactionNoteForStorage($transactionNote);
                    if ($feeRaw != 0.0) {
                        $feeDisplay = number_format(abs($feeRaw), 2, ',', '.') . ' € Gebühren';
                        $transactionNote = $transactionNote !== null && $transactionNote !== ''
                            ? $transactionNote . ' | ' . $feeDisplay
                            : $feeDisplay;
                    }
                    $transactionStatus = isset($info['transaction_status']) ? $info['transaction_status'] : null;
                    $accountId = isset($info['paypal_reference_id']) ? $info['paypal_reference_id']
                        : (isset($info['paypal_account_id']) ? $info['paypal_account_id'] : null);

                    $receivedAtRaw = isset($info['transaction_initiation_date']) ? trim($info['transaction_initiation_date']) : null;
                    $receivedAt = $receivedAtRaw !== null ? $this->normalizePaypalDate($receivedAtRaw) : null;

                    $sql = 'INSERT INTO drinks_paypal
                        (paypal_transaction_id, state, account_id, payer_name, payer_email, amount, transaction_status, transaction_note, transaction_json, received_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    $params = [
                        $txId,
                        'apifoundsynced',
                        $accountId,
                        $payerName,
                        $payerEmail,
                        $amount,
                        $transactionStatus,
                        $transactionNote,
                        json_encode(['api_import' => $detail], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $receivedAt,
                    ];
                    $statement = $this->dbAdapter->createStatement($sql, $params);
                    $statement->execute();
                    $result['imported']++;
                } catch (\Throwable $e) {
                    $result['errors'][] = 'Import tx ' . ($txId ?? '?') . ': ' . $e->getMessage();
                    $result['skipped']++;
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return $result;
    }

    /**
     * Auto-assign apifoundsynced transactions to users and create deposits.
     * Matches by payer_email:
     *  1. Unique previous assignment in drinks_paypal for that email
     *  2. Unique match of email in bs_users, with no conflicting drinks_paypal assignment
     *
     * If a matching deposit already exists within the matching window, it is linked.
     * Otherwise a new deposit is created from the PayPal row and linked to the user.
     *
     * @param int $createdByUserId admin user id used for createdbyuserid on new deposits
     * @return array result stats
     */
    public function autoAssignSyncedTransactions($depositManager, $createdByUserId, $serviceManager = null, $allowCreateDeposits = true)
    {
        $result = [
            'assigned' => 0,
            'created'  => 0,
            'skipped'  => 0,
            'errors'   => [],
        ];

        try {
            $rows = $this->dbAdapter->query(
                'SELECT * FROM drinks_paypal WHERE state = ? AND linked_deposit_id IS NULL AND payer_email IS NOT NULL AND payer_email != "" ORDER BY received_at ASC',
                ['apifoundsynced']
            )->toArray();
        } catch (\Throwable $e) {
            $result['errors'][] = 'DB error fetching synced rows: ' . $e->getMessage();
            $this->touchLastSyncTimestamp($serviceManager);
            return $result;
        }

        if (empty($rows)) {
            $this->touchLastSyncTimestamp($serviceManager);
            return $result;
        }

        foreach ($rows as $row) {
            try {
                $paypalId = (int)$row['id'];
                $payerEmail = strtolower(trim((string)$row['payer_email']));
                $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                $existingLinkedUserId = isset($row['linked_user_id']) ? (int)$row['linked_user_id'] : 0;

                if ($payerEmail === '' || $amount <= 0 || empty($row['received_at'])) {
                    $result['skipped']++;
                    continue;
                }

                if ($existingLinkedUserId > 0) {
                    // Never auto-change an already assigned user.
                    $matchedUserId = $existingLinkedUserId;
                } else {
                    $matchedUserId = $this->resolveUserForEmail($payerEmail);
                    if ($matchedUserId === null) {
                        $result['skipped']++;
                        continue;
                    }

                    // Persist the resolved user even if no matching deposit exists yet.
                    // This keeps the payer_email -> user assignment visible in drinks_paypal.
                    try {
                        $this->dbAdapter->query(
                            'UPDATE drinks_paypal SET linked_user_id = ?, processed_at = NOW() WHERE id = ?',
                            [$matchedUserId, $paypalId]
                        );
                    } catch (\Throwable $e) {
                        $result['errors'][] = sprintf('PayPal #%d: failed to store linked_user_id: %s', $paypalId, $e->getMessage());
                        $result['skipped']++;
                        continue;
                    }
                }

                // Pass 1: ±7 days; Pass 2: ±14 days
                $existingDeposit = $this->findMatchingDeposit($matchedUserId, $amount, $row['received_at']);

                if ($existingDeposit && !empty($existingDeposit['id'])) {
                    $this->linkToDeposit($paypalId, (int)$existingDeposit['id'], $matchedUserId);
                    $result['assigned']++;
                    continue;
                }

                if (!$allowCreateDeposits) {
                    $result['skipped']++;
                    continue;
                }

                $commentParts = ['PayPal'];
                if (!empty($row['payer_name'])) {
                    $commentParts[] = trim((string)$row['payer_name']);
                }
                if (!empty($row['transaction_note'])) {
                    $commentParts[] = trim((string)$row['transaction_note']);
                }
                $comment = implode(' - ', array_filter($commentParts, function ($value) {
                    return $value !== '';
                }));

                $depositTime = !empty($row['received_at']) ? $row['received_at'] : null;
                $depositResult = $depositManager->addDeposit(
                    $matchedUserId,
                    $amount,
                    $comment,
                    $createdByUserId > 0 ? $createdByUserId : null,
                    null,
                    null,
                    $depositTime
                );
                if (!$depositResult || method_exists($depositResult, 'getAffectedRows') && $depositResult->getAffectedRows() <= 0) {
                    $result['skipped']++;
                    continue;
                }

                $depositId = null;
                if (method_exists($depositResult, 'getGeneratedValue')) {
                    $depositId = (int)$depositResult->getGeneratedValue();
                }
                if (!$depositId) {
                    $depositRow = $this->dbAdapter->query('SELECT id FROM drink_deposits WHERE user_id = ? AND amount = ? AND deposit_time = ? ORDER BY id DESC LIMIT 1', [$matchedUserId, $amount, $depositTime])->current();
                    if ($depositRow && !empty($depositRow['id'])) {
                        $depositId = (int)$depositRow['id'];
                    }
                }
                if (!$depositId) {
                    $result['errors'][] = sprintf('PayPal #%d: created deposit but could not resolve id', $paypalId);
                    $result['skipped']++;
                    continue;
                }

                if (!$this->linkToDeposit($paypalId, $depositId, $matchedUserId)) {
                    $result['errors'][] = sprintf('PayPal #%d: created deposit %d but linking failed', $paypalId, $depositId);
                    $result['skipped']++;
                    continue;
                }

                if ($serviceManager !== null) {
                    try {
                        $this->sendDepositNotification($serviceManager, $matchedUserId, $amount, $comment, $depositTime);
                    } catch (\Throwable $e) {
                        $result['errors'][] = sprintf('PayPal #%d: deposit notification failed: %s', $paypalId, $e->getMessage());
                    }
                }

                $result['created']++;
                $result['assigned']++;
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('PayPal #%d: %s', $row['id'] ?? '?', $e->getMessage());
                $result['skipped']++;
            }
        }

        $this->touchLastSyncTimestamp($serviceManager);
        return $result;
    }

    private function touchLastSyncTimestamp($serviceManager)
    {
        if ($serviceManager === null || !method_exists($serviceManager, 'get')) {
            return;
        }

        try {
            $optionManager = $serviceManager->get('Base\\Manager\\OptionManager');
            $optionManager->set('paypal.last_sync_at', date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            // Never fail auto-assignment because metadata update failed.
        }
    }

    private function sendDepositNotification($serviceManager, $userId, $amount, $comment, $depositTime = null)
    {
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $mailService = $serviceManager->get('User\Service\MailService');
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');

        $recipient = $userManager->get($userId);
        if (!$recipient) {
            return;
        }

        $balance = $drinkManager->calculateUserDrinkBalance($userId, $serviceManager);
        $subject = 'Neue Einzahlung auf Ihr Getränkekonto';
        $body =
            '<p>Es wurde soeben eine Einzahlung auf Dein Getränkekonto vorgenommen:</p>' .
            '<ul>' .
            ($comment ? '<li><strong>Bemerkung:</strong> ' . htmlspecialchars($comment) . '</li>' : '') .
            '<li><strong>Einzahlungsbetrag:</strong> ' . number_format($amount, 2, ',', '.') . ' €</li>' .
            '<li><strong>Neuer Kontostand:</strong> ' . number_format($balance, 2, ',', '.') . ' €</li>' .
            '</ul>' .
            '<p>Viele Grüße<br>Dein Theken-Team</p>';

        $mailService->sendFromTheke($recipient, $subject, $body, ['isHtml' => true]);
    }

    /**
     * Resolve a unique user ID for a given payer email.
     * Returns the user ID if exactly one user can be determined, or null.
     */
    private function resolveUserForEmail($email)
    {
        $email = strtolower(trim((string)$email));
        if ($email === '') {
            return null;
        }

        // Rule 1: unique previous assignment in drinks_paypal for this email
        // Prefer explicit historical user links, even if no deposit was linked yet.
        try {
            $previousRows = $this->dbAdapter->query(
                'SELECT DISTINCT linked_user_id FROM drinks_paypal WHERE payer_email = ? AND linked_user_id IS NOT NULL',
                [$email]
            )->toArray();
            $previousUserIds = array_unique(array_column($previousRows, 'linked_user_id'));
            if (count($previousUserIds) === 1) {
                return (int)$previousUserIds[0];
            }
            if (count($previousUserIds) > 1) {
                // Ambiguous — multiple users previously linked to this email
                return null;
            }
        } catch (\Throwable $e) {
            // fallthrough to rule 2
        }

        // Rule 2: unique match in bs_users by email, and no conflicting paypal assignment for a different user
        try {
            $userRows = $this->dbAdapter->query(
                'SELECT uid FROM bs_users WHERE LOWER(email) = ? AND status IN ("enabled","admin","assist")',
                [$email]
            )->toArray();
            $userIds = array_column($userRows, 'uid');
            if (count($userIds) !== 1) {
                return null;
            }
            $candidateUid = (int)$userIds[0];

            // Make sure no drinks_paypal row with this email is linked to a *different* user
            $conflictRows = $this->dbAdapter->query(
                'SELECT COUNT(*) AS cnt FROM drinks_paypal WHERE payer_email = ? AND linked_user_id IS NOT NULL AND linked_user_id != ?',
                [$email, $candidateUid]
            )->current();
            if ($conflictRows && (int)$conflictRows['cnt'] > 0) {
                return null;
            }

            return $candidateUid;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function reassignTransaction($paypalId, $newUserId)
    {
        $paypalId = (int)$paypalId;
        $newUserId = (int)$newUserId;
        if ($paypalId <= 0 || $newUserId <= 0) {
            return ['success' => false];
        }
        try {
            $row = $this->dbAdapter->query('SELECT * FROM drinks_paypal WHERE id = ? LIMIT 1', [$paypalId])->current();
            if (!$row) {
                return ['success' => false];
            }
            // If already linked to a deposit, update that deposit's user_id too
            $linkedDepositId = isset($row['linked_deposit_id']) ? (int)$row['linked_deposit_id'] : 0;
            if ($linkedDepositId > 0) {
                $this->dbAdapter->query('UPDATE drink_deposits SET user_id = ? WHERE id = ?', [$newUserId, $linkedDepositId]);
            }
            $this->dbAdapter->query(
                'UPDATE drinks_paypal SET linked_user_id = ?, processed_at = NOW() WHERE id = ?',
                [$newUserId, $paypalId]
            );

            // Try to link an existing deposit: pass 1 ±7 days, pass 2 ±14 days
            $depositLinked = false;
            if ($linkedDepositId <= 0 && !empty($row['received_at']) && !empty($row['amount'])) {
                $amount = (float)$row['amount'];
                $existingDeposit = $this->findMatchingDeposit($newUserId, $amount, $row['received_at']);
                if ($existingDeposit && !empty($existingDeposit['id'])) {
                    $this->linkToDeposit($paypalId, (int)$existingDeposit['id'], $newUserId);
                    $depositLinked = true;
                    $linkedDepositId = (int)$existingDeposit['id'];
                }
            }

            return ['success' => true, 'deposit_linked' => $depositLinked, 'linked_deposit_id' => $linkedDepositId];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function importFromImap($imapHost, $imapPort, $imapUser, $imapPassword, $imapSsl = false)
    {
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('IMAP PHP extension is not available.');
        }

        $imapHost = trim((string)$imapHost);
        $imapPort = (int)$imapPort;
        $imapUser = trim((string)$imapUser);
        $imapPassword = trim((string)$imapPassword);
        $imapSsl = ($imapSsl === true || $imapSsl === '1' || $imapSsl === 1);

        if ($imapHost === '' || $imapPort <= 0 || $imapUser === '' || $imapPassword === '') {
            throw new \RuntimeException('PayPal IMAP settings are incomplete.');
        }

        $protocol = '/imap' . ($imapSsl ? '/ssl' : '');
        $mailbox = sprintf('{%s:%d%s/novalidate-cert}INBOX', $imapHost, $imapPort, $protocol);
        $inbox = @imap_open($mailbox, $imapUser, $imapPassword);

        if ($inbox === false) {
            $error = imap_last_error();
            throw new \RuntimeException('IMAP connection failed: ' . ($error ?: 'unknown error'));
        }

        $result = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $messages = @imap_search($inbox, 'UNSEEN FROM "service@paypal.de"');
            if ($messages === false) {
                $messages = @imap_search($inbox, 'UNSEEN');
            }
            if ($messages === false) {
                $messages = [];
            }

            foreach ($messages as $msgNo) {
                try {
                    $header = imap_headerinfo($inbox, $msgNo);
                    $subject = $this->decodeMimeHeader(isset($header->subject) ? $header->subject : '');
                    $fromAddress = $this->parseHeaderAddress($header->from[0] ?? null);
                    $body = $this->fetchMessageBody($inbox, $msgNo);
                    $plainBody = $this->normalizeText($body);

                    if (! $this->isLikelyPaypalMessage($fromAddress['email'], $subject, $plainBody)) {
                        $result['skipped']++;
                        continue;
                    }

                    $transactionId = $this->findTransactionId($plainBody, $subject);
                    $amount = $this->findAmount($plainBody);
                    $payerEmail = $this->findPayerEmail($plainBody, $fromAddress['email']);
                    $payerName = $this->findPayerName($body, $plainBody, $fromAddress['name']);
                    $transactionNote = $this->normalizeTransactionNoteForStorage($this->findTransactionNote($body, $plainBody, $subject));
                    $receivedAt = $this->findReceivedAt($plainBody, $header);
                    $sourceMailId = $this->findMessageId($header, $msgNo);

                    if ($transactionId === '') {
                        $transactionId = 'email-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $sourceMailId);
                    }

                    if ($amount === null) {
                        $result['skipped']++;
                        continue;
                    }

                    $existing = $this->dbAdapter->query('SELECT id FROM drinks_paypal WHERE paypal_transaction_id = ? LIMIT 1', [$transactionId])->current();
                    if ($existing) {
                        @imap_setflag_full($inbox, $msgNo, '\\Seen');
                        $result['skipped']++;
                        continue;
                    }

                    $payload = [
                        'subject' => $subject,
                        'from_name' => $fromAddress['name'],
                        'from_email' => $fromAddress['email'],
                        'body' => $plainBody,
                    ];

                    $sql = 'INSERT INTO drinks_paypal
                        (paypal_transaction_id, state, account_id, payer_name, payer_email, amount, transaction_status, transaction_note, transaction_json, source_mail_id, received_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    $params = [
                        $transactionId,
                        'emailreceived',
                        null,
                        $payerName,
                        $payerEmail,
                        $amount,
                        null,
                        $transactionNote,
                        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $sourceMailId,
                        $receivedAt,
                    ];
                    $statement = $this->dbAdapter->createStatement($sql, $params);
                    $statement->execute();
                    @imap_setflag_full($inbox, $msgNo, '\\Seen');
                    $result['imported']++;
                } catch (\Throwable $e) {
                    $result['errors'][] = $e->getMessage();
                }
            }
        } finally {
            @imap_close($inbox);
        }

        return $result;
    }

    public function syncEmailReceivedTransactions($clientId, $clientSecret, $limit = 100)
    {
        $result = [
            'synced' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $clientId = trim((string)$clientId);
        $clientSecret = trim((string)$clientSecret);

        if ($clientId === '' || $clientSecret === '') {
            $result['errors'][] = 'PayPal API credentials are missing.';
            return $result;
        }

        $limit = max(1, (int)$limit);
        $rows = $this->dbAdapter->query('SELECT * FROM drinks_paypal WHERE state = ? ORDER BY received_at DESC LIMIT ' . $limit, ['emailreceived'])->toArray();
        if (empty($rows)) {
            return $result;
        }

        try {
            $accessToken = $this->getPaypalAccessToken($clientId, $clientSecret);
        } catch (\Throwable $e) {
            $result['errors'][] = 'Unable to obtain PayPal access token: ' . $e->getMessage();
            $result['skipped'] = count($rows);
            return $result;
        }

        foreach ($rows as $row) {
            try {
                $apiData = $this->fetchPaypalTransactionDetails($row, $accessToken);
                if ($apiData === null) {
                    $result['skipped']++;
                    continue;
                }

                $this->applyPaypalApiDataToRow($row, $apiData);
                $result['synced']++;
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('Transaction %s: %s', $row['paypal_transaction_id'], $e->getMessage());
                $result['skipped']++;
            }
        }

        return $result;
    }

    private function getPaypalAccessToken($clientId, $clientSecret)
    {
        $url = 'https://api-m.paypal.com/v1/oauth2/token';
        $body = 'grant_type=client_credentials';
        $response = $this->paypalApiRequest($url, 'POST', null, $body, true, $clientId, $clientSecret);
        if (!isset($response['access_token'])) {
            throw new \RuntimeException('PayPal access token response did not contain access_token.');
        }
        return $response['access_token'];
    }

    private function fetchPaypalTransactionDetails(array $row, $accessToken)
    {
        $transactionId = trim((string)($row['paypal_transaction_id'] ?? ''));
        if ($transactionId === '') {
            return null;
        }

        $rowDate = null;
        if (!empty($row['received_at'])) {
            $timestamp = strtotime($row['received_at']);
            if ($timestamp !== false) {
                $rowDate = new \DateTime('@' . $timestamp);
                $rowDate->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $startDate = $rowDate ?: clone $now;
        $startDate->setTime(0, 0, 0);
        $endDate = $rowDate ? clone $rowDate : clone $now;
        $endDate->setTime(23, 59, 59);

        $url = 'https://api-m.paypal.com/v1/reporting/transactions?' . http_build_query([
            'start_date' => $startDate->format('Y-m-d\TH:i:s\Z'),
            'end_date' => $endDate->format('Y-m-d\TH:i:s\Z'),
            'page_size' => 100,
        ]);
        $response = $this->paypalApiRequest($url, 'GET', $accessToken);

        if ($this->isReportingResult($response) && $this->reportingContainsTransaction($response, $transactionId)) {
            $apiData = ['type' => 'reporting', 'data' => $response];
            $detail = $this->getMatchingReportingTransactionDetail($response, $transactionId);
            if ($detail !== null) {
                $captureId = $this->extractAccountIdFromTransactionInfo($detail['transaction_info'] ?? []);
                if ($captureId !== '') {
                    $payeeEmail = $this->fetchCapturePayeeEmail($captureId, $accessToken);
                    if ($payeeEmail !== null) {
                        $apiData['capture_payee_email'] = $payeeEmail;
                    }
                }
            }
            if ($this->isValidPaypalApiResult($apiData, $row)) {
                return $apiData;
            }
        }

        $url = 'https://api-m.paypal.com/v1/reporting/transactions?' . http_build_query([
            'start_date' => $startDate->format('Y-m-d\TH:i:s\Z'),
            'end_date' => $endDate->format('Y-m-d\TH:i:s\Z'),
            'page_size' => 100,
            'fields' => 'all',
        ]);
        $response = $this->paypalApiRequest($url, 'GET', $accessToken);
        if ($this->isReportingResult($response) && $this->reportingContainsTransaction($response, $transactionId)) {
            $apiData = ['type' => 'reporting', 'data' => $response];
            $detail = $this->getMatchingReportingTransactionDetail($response, $transactionId);
            if ($detail !== null) {
                $captureId = $this->extractAccountIdFromTransactionInfo($detail['transaction_info'] ?? []);
                if ($captureId !== '') {
                    $payeeEmail = $this->fetchCapturePayeeEmail($captureId, $accessToken);
                    if ($payeeEmail !== null) {
                        $apiData['capture_payee_email'] = $payeeEmail;
                    }
                }
            }
            if ($this->isValidPaypalApiResult($apiData, $row)) {
                return $apiData;
            }
        }

        // Only reporting results are considered valid matches.
        return null;
    }

    private function applyPaypalApiDataToRow(array $row, array $apiResult)
    {
        $existingJson = [];
        if (!empty($row['transaction_json'])) {
            $decoded = json_decode($row['transaction_json'], true);
            if (is_array($decoded)) {
                $existingJson = $decoded;
            }
        }

        $parsed = $this->parsePaypalApiResponse($apiResult, $row);
        if (empty($parsed['amount'])) {
            throw new \RuntimeException('PayPal API response did not include a valid amount.');
        }
        if (empty($parsed['account_id'])) {
            throw new \RuntimeException('PayPal API response did not include an account_id.');
        }

        $payload = $existingJson;
        $payload['paypal_api_data'] = $apiResult;

        $payerName = $parsed['payer_name'];
        if ($payerName === null || $payerName === '') {
            $payerName = isset($row['payer_name']) ? $row['payer_name'] : null;
        }

        $payerEmail = $parsed['payer_email'];
        if ($payerEmail === null || $payerEmail === '') {
            $payerEmail = isset($row['payer_email']) ? $row['payer_email'] : null;
        }

        $transactionNote = $parsed['transaction_note'];
        if ($transactionNote === null || $transactionNote === '') {
            $transactionNote = isset($row['transaction_note']) ? $row['transaction_note'] : '';
        }
        $transactionNote = $this->normalizeTransactionNoteForStorage($transactionNote);

        $sql = 'UPDATE drinks_paypal SET state = ?, account_id = ?, payer_name = ?, payer_email = ?, amount = ?, transaction_status = ?, transaction_note = ?, transaction_json = ?, processed_at = NOW() WHERE id = ?';
        $params = [
            'apifoundsynced',
            $parsed['account_id'],
            $payerName,
            $payerEmail,
            $parsed['amount'],
            $parsed['transaction_status'],
            $transactionNote,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (int)$row['id'],
        ];

        $receivedAt = null;
        if (!empty($parsed['received_at'])) {
            $normalizedReceivedAt = $this->normalizePaypalDate($parsed['received_at']);
            if ($normalizedReceivedAt !== null) {
                $receivedAt = $normalizedReceivedAt;
            }
        }

        $sql = 'UPDATE drinks_paypal SET state = ?, account_id = ?, payer_name = ?, payer_email = ?, amount = ?, transaction_status = ?, transaction_note = ?, transaction_json = ?, processed_at = NOW()';
        if ($receivedAt !== null) {
            $sql .= ', received_at = ?';
        }
        $sql .= ' WHERE id = ?';

        $params = [
            'apifoundsynced',
            $parsed['account_id'],
            $payerName,
            $payerEmail,
            $parsed['amount'],
            $parsed['transaction_status'],
            $transactionNote,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($receivedAt !== null) {
            $params[] = $receivedAt;
        }
        $params[] = (int)$row['id'];

        $statement = $this->dbAdapter->createStatement($sql, $params);
        $result = $statement->execute();
        if ($result->getAffectedRows() <= 0) {
            throw new \RuntimeException('Failed to update PayPal transaction row.');
        }
    }

    private function normalizePaypalDate($dateString)
    {
        if (!is_string($dateString) || $dateString === '') {
            return null;
        }

        $date = \DateTime::createFromFormat(\DateTime::ATOM, $dateString);
        if (!$date) {
            try {
                $date = new \DateTime($dateString);
            } catch (\Throwable $e) {
                return null;
            }
        }

        $date->setTimezone(new \DateTimeZone('Europe/Berlin'));
        return $date->format('Y-m-d H:i:s');
    }

    private function parsePaypalApiResponse(array $apiResult, array $row)
    {
        $parsed = [
            'account_id' => null,
            'payer_name' => null,
            'payer_email' => null,
            'amount' => null,
            'transaction_status' => null,
            'transaction_note' => null,
            'received_at' => null,
        ];

        $type = isset($apiResult['type']) ? $apiResult['type'] : null;
        $data = isset($apiResult['data']) ? $apiResult['data'] : [];

        if ($type === 'reporting' && isset($data['transaction_details'][0])) {
            $transactionId = trim((string)$row['paypal_transaction_id']);
            foreach ($data['transaction_details'] as $detail) {
                if (!is_array($detail) || !isset($detail['transaction_info'])) {
                    continue;
                }
                $info = isset($detail['transaction_info']) ? $detail['transaction_info'] : [];
                $reportedId = null;
                if (isset($info['transaction_id'])) {
                    $reportedId = trim($info['transaction_id']);
                }
                if ($reportedId === '' && isset($info['paypal_reference_id'])) {
                    $reportedId = trim($info['paypal_reference_id']);
                }
                if ($reportedId === '' && isset($info['paypal_account_id'])) {
                    $reportedId = trim($info['paypal_account_id']);
                }
                if ($reportedId !== $transactionId) {
                    continue;
                }

                $payer = isset($detail['payer_info']) ? $detail['payer_info'] : [];
                $parsed['transaction_status'] = isset($info['transaction_status']) ? $info['transaction_status'] : null;
                $parsed['account_id'] = isset($info['paypal_reference_id']) ? $info['paypal_reference_id'] : (isset($info['paypal_account_id']) ? $info['paypal_account_id'] : null);
                $parsed['amount'] = isset($info['transaction_amount']['value']) ? (float)$info['transaction_amount']['value'] : null;
                if ($parsed['amount'] === null && isset($info['transaction_amount']['value'])) {
                    $parsed['amount'] = (float)$info['transaction_amount']['value'];
                }
                $parsed['payer_email'] = isset($payer['email_address']) ? strtolower(trim($payer['email_address'])) : null;
                if ($parsed['payer_email'] === null && isset($apiResult['capture_payee_email']) && trim($apiResult['capture_payee_email']) !== '') {
                    $parsed['payer_email'] = strtolower(trim($apiResult['capture_payee_email']));
                }
                $parsed['payer_name'] = isset($payer['name']['alternate_full_name']) ? trim($payer['name']['alternate_full_name']) : (isset($payer['name']['full_name']) ? trim($payer['name']['full_name']) : null);
                $parsed['transaction_note'] = isset($info['transaction_note']) ? trim($info['transaction_note']) : (isset($info['transaction_subject']) ? trim($info['transaction_subject']) : null);
                if ($parsed['transaction_note'] === null && isset($detail['transaction_item_details'][0]['item_details']['name'])) {
                    $parsed['transaction_note'] = trim($detail['transaction_item_details'][0]['item_details']['name']);
                }
                $parsed['received_at'] = isset($info['transaction_initiation_date']) ? trim($info['transaction_initiation_date']) : null;
                break;
            }
        } elseif ($type === 'capture') {
            $parsed['transaction_status'] = isset($data['status']) ? $data['status'] : null;
            $parsed['amount'] = isset($data['amount']['value']) ? (float)$data['amount']['value'] : null;
            $parsed['transaction_note'] = isset($data['invoice_id']) ? trim($data['invoice_id']) : null;
            if ($parsed['transaction_note'] === null && isset($data['supplementary_data']['related_ids']['order_id'])) {
                $parsed['transaction_note'] = trim($data['supplementary_data']['related_ids']['order_id']);
            }
            if (isset($data['seller_receivable_breakdown']['gross_amount']['value'])) {
                $parsed['amount'] = (float)$data['seller_receivable_breakdown']['gross_amount']['value'];
            }
            $parsed['payer_name'] = isset($data['payer']['name']['full_name']) ? trim($data['payer']['name']['full_name']) : null;
            $parsed['payer_email'] = isset($data['payer']['email_address']) ? strtolower(trim($data['payer']['email_address'])) : null;
            if (isset($data['seller_receivable_breakdown']['paypal_fee']['value'])) {
                // keep gross amount only
            }
        } elseif ($type === 'order') {
            $parsed['transaction_status'] = isset($data['status']) ? $data['status'] : null;
            if (isset($data['purchase_units'][0]['amount']['value'])) {
                $parsed['amount'] = (float)$data['purchase_units'][0]['amount']['value'];
            }
            $parsed['payer_name'] = isset($data['payer']['name']['full_name']) ? trim($data['payer']['name']['full_name']) : null;
            $parsed['payer_email'] = isset($data['payer']['email_address']) ? strtolower(trim($data['payer']['email_address'])) : null;
            $parsed['transaction_note'] = isset($data['purchase_units'][0]['description']) ? trim($data['purchase_units'][0]['description']) : null;
            $parsed['account_id'] = isset($data['purchase_units'][0]['payee']['merchant_id']) ? $data['purchase_units'][0]['payee']['merchant_id'] : null;
        }

        if ($parsed['payer_email'] === null) {
            $parsed['payer_email'] = null;
        }
        if ($parsed['payer_name'] === null) {
            $parsed['payer_name'] = null;
        }
        if ($parsed['transaction_note'] === null) {
            $parsed['transaction_note'] = '';
        }
        if ($parsed['transaction_status'] === null) {
            $parsed['transaction_status'] = 'unknown';
        }

        return $parsed;
    }

    private function isValidPaypalApiResult(array $apiResult, array $row)
    {
        $parsed = $this->parsePaypalApiResponse($apiResult, $row);
        return !empty($parsed['account_id']) && !empty($parsed['amount']);
    }

    private function getMatchingReportingTransactionDetail($response, $transactionId)
    {
        if (!$this->isReportingResult($response)) {
            return null;
        }

        foreach ($response['transaction_details'] as $detail) {
            if (!is_array($detail) || !isset($detail['transaction_info'])) {
                continue;
            }

            $info = isset($detail['transaction_info']) ? $detail['transaction_info'] : [];
            $reportedId = null;
            if (isset($info['transaction_id'])) {
                $reportedId = trim($info['transaction_id']);
            }
            if ($reportedId === '' && isset($info['paypal_reference_id'])) {
                $reportedId = trim($info['paypal_reference_id']);
            }
            if ($reportedId === '' && isset($info['paypal_account_id'])) {
                $reportedId = trim($info['paypal_account_id']);
            }
            if ($reportedId === $transactionId) {
                return $detail;
            }
        }

        return null;
    }

    private function extractAccountIdFromTransactionInfo(array $transactionInfo)
    {
        if (!empty($transactionInfo['paypal_reference_id'])) {
            return trim($transactionInfo['paypal_reference_id']);
        }
        if (!empty($transactionInfo['paypal_account_id'])) {
            return trim($transactionInfo['paypal_account_id']);
        }
        return '';
    }

    private function fetchCapturePayeeEmail($captureId, $accessToken)
    {
        if ($captureId === '') {
            return null;
        }

        $url = 'https://api-m.paypal.com/v2/payments/captures/' . urlencode($captureId);
        try {
            $response = $this->paypalApiRequest($url, 'GET', $accessToken);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($response)) {
            return null;
        }

        if (isset($response['payee']['email_address']) && trim($response['payee']['email_address']) !== '') {
            return strtolower(trim($response['payee']['email_address']));
        }

        return null;
    }

    private function isReportingResult($response)
    {
        return is_array($response)
            && isset($response['transaction_details'])
            && is_array($response['transaction_details'])
            && count($response['transaction_details']) > 0;
    }

    private function reportingContainsTransaction($response, $transactionId)
    {
        if (!$this->isReportingResult($response)) {
            return false;
        }

        foreach ($response['transaction_details'] as $detail) {
            if (!is_array($detail) || !isset($detail['transaction_info'])) {
                continue;
            }
            if (isset($detail['transaction_info']['transaction_id']) && trim($detail['transaction_info']['transaction_id']) === $transactionId) {
                return true;
            }
            if (isset($detail['transaction_info']['paypal_reference_id']) && trim($detail['transaction_info']['paypal_reference_id']) === $transactionId) {
                return true;
            }
        }

        return false;
    }

    private function paypalApiRequest($url, $method = 'GET', $accessToken = null, $body = null, $useBasicAuth = false, $clientId = null, $clientSecret = null)
    {
        $headers = ['Accept: application/json'];
        $method = strtoupper($method);

        if ($useBasicAuth) {
            $userpass = rawurlencode($clientId) . ':' . rawurlencode($clientSecret);
            $headers[] = 'Authorization: Basic ' . base64_encode($userpass);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            if ($accessToken !== null) {
                $headers[] = 'Authorization: Bearer ' . $accessToken;
            }
            if ($body !== null && $method !== 'GET') {
                $headers[] = 'Content-Type: application/json';
            }
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL PHP extension is required for PayPal API requests.');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false || $curlError !== '') {
            throw new \RuntimeException('PayPal API request failed: ' . $curlError);
        }

        $response = json_decode($responseBody, true);
        if ($response === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('PayPal API response could not be decoded: ' . json_last_error_msg());
        }

        if ($httpStatus >= 400) {
            $message = isset($response['message']) ? $response['message'] : 'HTTP ' . $httpStatus;
            throw new \RuntimeException('PayPal API returned error: ' . $message);
        }

        return $response;
    }

    private function decodeMimeHeader($header)
    {
        if (!is_string($header) || $header === '') {
            return '';
        }

        $parts = imap_mime_header_decode($header);
        $decoded = '';
        foreach ($parts as $part) {
            $decoded .= isset($part->text) ? $part->text : '';
        }
        return trim($decoded);
    }

    private function parseHeaderAddress($address)
    {
        $result = ['name' => '', 'email' => ''];
        if (!is_object($address)) {
            return $result;
        }

        if (!empty($address->mailbox) && !empty($address->host)) {
            $result['email'] = strtolower($address->mailbox . '@' . $address->host);
        }
        if (!empty($address->personal)) {
            $result['name'] = $this->decodeMimeHeader($address->personal);
        }
        return $result;
    }

    private function fetchMessageBody($stream, $msgNo)
    {
        $body = imap_fetchbody($stream, $msgNo, '1.1');
        if ($body === '' || $body === false) {
            $body = imap_fetchbody($stream, $msgNo, '1');
        }
        if ($body === '' || $body === false) {
            $body = imap_body($stream, $msgNo);
        }
        if (!is_string($body)) {
            return '';
        }
        return $this->decodeQuotedPrintable($body);
    }

    private function decodeQuotedPrintable($text)
    {
        $decoded = @imap_qprint($text);
        if ($decoded !== false) {
            return $decoded;
        }
        return $text;
    }

    private function normalizeText($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = strip_tags($text);
        return trim($text);
    }

    private function isLikelyPaypalMessage($fromEmail, $subject, $body)
    {
        return strtolower(trim((string)$fromEmail)) === 'service@paypal.de';
    }

    private function findTransactionId($body, $subject)
    {
        $patterns = [
            '/Transaktionsnummer\s*[:\-]?\s*([A-Z0-9\-]+)/i',
            '/Transaktions-ID\s*[:\-]?\s*([A-Z0-9\-]+)/i',
            '/Transaction ID\s*[:\-]?\s*([A-Z0-9\-]+)/i',
            '/Transaction(?:\s*number| number)\s*[:\-]?\s*([A-Z0-9\-]+)/i',
            '/ID:\s*([A-Z0-9\-]+)/i',
            '/Transaktionscode\s*[:\-]?\s*([A-Z0-9]{8,})/i',
            '/https?:\/\/[^\s]*paypal\.com\/activity\/payment\/([A-Z0-9]{8,})/i',
            '/paypal\.com\/activity\/payment\/([A-Z0-9]{8,})/i',
            '/payment\/([A-Z0-9]{8,})/i',
            '/\[\s*([A-Z0-9]{8,})\s*\]/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                return trim($matches[1]);
            }
        }
        if (preg_match('/PayPal\s*Transaktion\s*ID\s*[:\-]?\s*([A-Z0-9\-]+)/i', $subject, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function findAmount($body)
    {
        $patterns = [
            '/([0-9]+(?:[\.,][0-9]{2})?)\s*EUR/i',
            '/([0-9]+(?:[\.,][0-9]{2})?)\s*€/',
            '/Amount\s*[:\-]?\s*([0-9]+(?:[\.,][0-9]{2})?)/i',
            '/Betrag\s*[:\-]?\s*([0-9]+(?:[\.,][0-9]{2})?)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                return $this->normalizeAmount($matches[1]);
            }
        }
        return null;
    }

    private function normalizeAmount($amountString)
    {
        $normalized = str_replace([',', ' '], ['.', ''], trim($amountString));
        return round((float)$normalized, 2);
    }

    private function findPayerEmail($body, $defaultEmail)
    {
        $patterns = [
            '/E[- ]?Mail[- ]?Adresse\s*[:\-]?\s*([\w\.\-\+]+@[\w\.\-]+)/i',
            '/From\s*[:\-]?\s*([\w\.\-\+]+@[\w\.\-]+)/i',
            '/Email\s*[:\-]?\s*([\w\.\-\+]+@[\w\.\-]+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                return strtolower(trim($matches[1]));
            }
        }

        $defaultEmail = strtolower(trim($defaultEmail));
        if ($defaultEmail === '' || preg_match('/paypal|kneipe@stc-butzbach\.de/i', $defaultEmail)) {
            return '';
        }
        return $defaultEmail;
    }

    private function findPayerName($body, $defaultName)
    {
        $patterns = [
            '/Mitteilung von\s*(?:[:\-]?\s*)?\n*\s*([^\n]+)/i',
            '/Absender\s*[:\-]?\s*([^\n]+)/i',
            '/From\s*[:\-]?\s*([^<\n]+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $name = trim(strip_tags($matches[1]));
                if ($name !== '') {
                    return $name;
                }
            }
        }
        return trim($defaultName);
    }

    private function findTransactionStatus($body, $subject)
    {
        $patterns = [
            '/Status\s*[:\-]?\s*([A-Za-zäöüÄÖÜ ]+)/i',
            '/Zahlungsstatus\s*[:\-]?\s*([A-Za-zäöüÄÖÜ ]+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                return trim($matches[1]);
            }
        }
        if (preg_match('/completed/i', $subject)) {
            return 'completed';
        }
        return 'unknown';
    }

    private function findTransactionNote($body, $plainBody, $subject)
    {
        // Prefer the sender message text that follows the strong headline in the HTML email.
        if (preg_match('/<strong[^>]*>\s*Mitteilung von\s*.*?<\/strong>\s*(.*?)\s*(?:<br|<\/p|<\/div|$)/is', $body, $matches)) {
            $note = $this->trimEmailNoteText(strip_tags($matches[1]));
            if ($note !== '') {
                return mb_substr($note, 0, 255);
            }
        }

        if ($subject !== '') {
            return trim($subject);
        }

        $plainBody = $this->trimEmailNoteText($plainBody);
        if ($plainBody !== '') {
            return mb_substr($plainBody, 0, 255);
        }

        return '';
    }

    private function trimEmailNoteText($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);
        $text = preg_replace('/(?:\n\s*)*Du siehst das Geld nicht in deinem Konto\?\s*$/u', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
        $lines = preg_split('/\n{2,}/', $text);
        $note = isset($lines[0]) ? trim($lines[0]) : '';
        $note = preg_replace('/[ \t]+$/m', '', $note);
        return preg_replace('/\n+$/', '', $note);
    }

    private function normalizeTransactionNoteForStorage($value)
    {
        if ($value === null) {
            return null;
        }

        $text = (string)$value;
        $normalized = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($normalized !== false) {
            $text = $normalized;
        }

        return $text;
    }

    private function findReceivedAt($body, $header)
    {
        if (!empty($header->date)) {
            $timestamp = strtotime($header->date);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        return date('Y-m-d H:i:s');
    }

    private function findMessageId($header, $msgNo)
    {
        if (!empty($header->message_id)) {
            return trim($header->message_id, ' <>');
        }
        return 'msg-' . (int)$msgNo;
    }
}
