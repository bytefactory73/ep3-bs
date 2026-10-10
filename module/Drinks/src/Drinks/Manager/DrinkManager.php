<?php

namespace Drinks\Manager;

use Drinks\Controller\Traits\ThekeMailTrait;
use Zend\Db\Adapter\Adapter;

class DrinkManager
{
    use ThekeMailTrait;

    /**
     * Drink ids priced per order: 1 = "Sonstiges", -1 = money transfer.
     */
    const CUSTOM_PRICE_DRINK_IDS = [1, -1];

    protected $dbAdapter;

    public function __construct(Adapter $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    /**
     * Calculates the current drink account balance for a user (sum deposits - sum orders).
     * @param int $userId
     * @param object $serviceManager
     * @return float
     */
    public function calculateUserDrinkBalance($userId, $serviceManager)
    {
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
        $drinkDeposits = iterator_to_array($drinkDepositManager->getByUser($userId));
        $allOrdersForBalance = iterator_to_array($drinkOrderManager->getByUser($userId));
        $depositSum = 0;
        foreach ($drinkDeposits as $deposit) {
            $depositSum += $deposit['amount'];
        }
        $orderSum = 0;
        foreach ($allOrdersForBalance as $order) {
            if (empty($order['deleted'])) {
                $orderSum += $order['quantity'] * $order['price'];
            }
        }
        return $depositSum - $orderSum;
    }

    public function getMinimumAccountBalance($serviceManager)
    {
        return $this->getMoneyOption($serviceManager, 'drinks.minimum_account_balance');
    }

    private function getMoneyOption($serviceManager, $key)
    {
        try {
            $optionManager = $serviceManager->get('Base\\Manager\\OptionManager');
            $value = str_replace(',', '.', trim((string)$optionManager->get($key, '0')));
            return round((float)$value, 2);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    public function getPendingPaypalAmount($userId)
    {
        try {
            $row = $this->dbAdapter->query(
                'SELECT COALESCE(SUM(amount), 0) AS total FROM drinks_paypal WHERE linked_user_id = ? AND linked_deposit_id IS NULL AND state != ?',
                [(int)$userId, 'ignored']
            )->current();
            return round($row && isset($row['total']) ? (float)$row['total'] : 0.0, 2);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    public function isTeamAccount($userId)
    {
        try {
            $row = $this->dbAdapter->query(
                'SELECT is_team FROM drink_aliases WHERE user_id = ?',
                [(int)$userId]
            )->current();
            return $row && !empty($row['is_team']);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isThekenadmin($userId)
    {
        $row = $this->dbAdapter->query('SELECT thekenadmin FROM drink_aliases WHERE user_id = ?', [(int)$userId])->current();
        return $row && isset($row['thekenadmin']) && (int)$row['thekenadmin'] === 1;
    }

    /**
     * Team accounts whose teamlead_email list contains $email: [['user_id' => int, 'alias' => string], ...].
     * The alias is the team's display name (bs_users.alias), never its Theken-ID.
     */
    public function getLedTeams($email)
    {
        $email = trim((string)$email);
        if ($email === '') {
            return [];
        }
        $rows = $this->dbAdapter->query(
            'SELECT da.user_id, u.alias
             FROM drink_aliases da
             LEFT JOIN bs_users u ON u.uid = da.user_id
             WHERE da.is_team = 1
                 AND FIND_IN_SET(LOWER(TRIM(?)), REPLACE(REPLACE(LOWER(COALESCE(da.teamlead_email, "")), " ", ""), ";", ",")) > 0
             ORDER BY da.user_id ASC',
            [$email]
        )->toArray();
        $teams = [];
        foreach ($rows as $row) {
            if (!empty($row['user_id'])) {
                $teams[] = [
                    'user_id' => (int)$row['user_id'],
                    'alias' => isset($row['alias']) ? trim((string)$row['alias']) : '',
                ];
            }
        }
        return $teams;
    }

    public function getLedTeamUids($email)
    {
        return array_column($this->getLedTeams($email), 'user_id');
    }

    /**
     * True if the user takes part in at least one Spieltag.
     */
    public function isTeamEventMember($userId)
    {
        $row = $this->dbAdapter->query(
            'SELECT COUNT(*) AS cnt FROM drinks_teamevent_members WHERE user_id = ?',
            [(int)$userId]
        )->current();
        return $row && (int)$row['cnt'] > 0;
    }

    /**
     * Per-order confirmation mails follow drink_aliases.order_email_option:
     * 'order' always, 'negative' only at a balance of zero or below.
     */
    public function shouldSendOrderEmail($userId, $balance)
    {
        $row = $this->dbAdapter->query('SELECT order_email_option FROM drink_aliases WHERE user_id = ?', [(int)$userId])->current();
        $option = $row && isset($row['order_email_option']) ? $row['order_email_option'] : null;
        return $option === 'order' || ($option === 'negative' && $balance <= 0);
    }

    public static function isCustomPriceDrink($drinkId)
    {
        return in_array((int)$drinkId, self::CUSTOM_PRICE_DRINK_IDS, true);
    }

    /**
     * Label of a "Sonstiges" / transfer entry: "3x comment", falling back to the drink name.
     */
    public static function formatCustomEntryLabel($quantity, $comment, $fallbackName)
    {
        $comment = trim((string)$comment);
        return ((int)$quantity > 1 ? $quantity . 'x ' : '') . ($comment !== '' ? $comment : $fallbackName);
    }

    public function negativeBalanceWarningHtml($tCallback)
    {
        return '<span style="color:#d32f2f;font-weight:bold;">'
            . call_user_func($tCallback, 'Warnung: Dein Kontostand ist negativ! Bitte überweise Geld auf das Paypal-Konto "kneipe@stc-butzbach.de" oder wirf Geld in den weißen Briefkasten ein.')
            . '</span>';
    }

    /**
     * Mail the user about a new deposit and the resulting balance. Never throws.
     */
    public function notifyDeposit($userId, $amount, $comment, $serviceManager)
    {
        try {
            $recipient = $serviceManager->get('User\Manager\UserManager')->get($userId, false);
            if (!$recipient) {
                return;
            }
            $balance = $this->calculateUserDrinkBalance($userId, $serviceManager);
            $body =
                '<p>Es wurde soeben eine Einzahlung auf Dein Getränkekonto vorgenommen:</p>' .
                '<ul>' .
                ($comment ? '<li><strong>Bemerkung:</strong> ' . htmlspecialchars($comment) . '</li>' : '') .
                '<li><strong>Einzahlungsbetrag:</strong> ' . number_format($amount, 2, ',', '.') . ' €</li>' .
                '<li><strong>Neuer Kontostand:</strong> ' . number_format($balance, 2, ',', '.') . ' €</li>' .
                '</ul>' .
                '<p>Viele Grüße<br>Dein Theken-Team</p>';
            $mailService = $serviceManager->get('User\Service\MailService');
            $this->sendFromTheke($mailService, $this->dbAdapter, $recipient, 'Neue Einzahlung auf Ihr Getränkekonto', $body, ['isHtml' => true]);
        } catch (\Throwable $e) {
            error_log('Fehler beim Senden der Einzahlungsbenachrichtigung: ' . $e->getMessage());
        }
    }

    /**
     * Active users a money transfer can be sent to: [['uid', 'name', 'email'], ...].
     */
    public function getMoneyRecipients($userManager, $excludeUserId)
    {
        $recipients = [];
        foreach ($userManager->getAll('alias ASC') as $candidateUser) {
            if (!in_array($candidateUser->get('status'), ['enabled', 'admin', 'assist'], true)) {
                continue;
            }
            $candidateUid = (int)$candidateUser->get('uid');
            if ($candidateUid <= 0 || $candidateUid === (int)$excludeUserId) {
                continue;
            }
            $candidateAlias = trim((string)$candidateUser->get('alias'));
            $candidateName = trim((string)$candidateUser->get('name'));
            $recipients[] = [
                'uid' => $candidateUid,
                'name' => $candidateAlias !== '' ? $candidateAlias : ($candidateName !== '' ? $candidateName : ('User ' . $candidateUid)),
                'email' => trim((string)$candidateUser->get('email')),
            ];
        }
        return $recipients;
    }

    /**
     * Party mode options: 'enabled' is the stored switch, 'active' additionally respects the
     * optional start/end window.
     */
    public function getPartyMode($serviceManager)
    {
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');
        $read = function ($key, $default) use ($optionManager) {
            try {
                return $optionManager->get($key, $default);
            } catch (\RuntimeException $e) {
                return $default;
            }
        };
        $rawEnabled = $read('party_mode.enabled', false);
        $enabled = ($rawEnabled === '1' || $rawEnabled === 1 || $rawEnabled === true);
        $start = (string)$read('party_mode.start', '');
        $end = (string)$read('party_mode.end', '');

        $now = time();
        $startTs = $start !== '' ? strtotime($start) : false;
        $endTs = $end !== '' ? strtotime($end) : false;
        $withinWindow = !($startTs && $now < $startTs) && !($endTs && $now > $endTs);

        return [
            'enabled' => $enabled,
            'active' => $enabled && $withinWindow,
            'message' => (string)$read('party_mode.message', ''),
            'start' => $start,
            'end' => $end,
        ];
    }

    public function isOrderAllowed($userId, $orderTotal, $serviceManager, $allowBelowMinimum = false)
    {
        if ($allowBelowMinimum || $this->isTeamAccount($userId)) {
            return true;
        }

        $minimumBalance = $this->getMinimumAccountBalance($serviceManager);
        if ($minimumBalance == 0.0) {
            return true;
        }

        $newBalance = round(
            $this->calculateUserDrinkBalance($userId, $serviceManager)
            + $this->getPendingPaypalAmount($userId)
            - (float)$orderTotal,
            2
        );
        return $newBalance >= $minimumBalance;
    }

    public function getAll($userId = null)
    {
        if ($userId) {
            $sql = 'SELECT d.*, COALESCE(SUM(do.quantity), 0) AS user_total_count '
                . 'FROM drinks d '
                . 'LEFT JOIN drink_orders do ON d.id = do.drink_id AND do.user_id = ? '
                . 'LEFT JOIN drink_categories c ON d.category = c.id '
                . 'GROUP BY d.id '
                . 'ORDER BY c.sort_priority ASC, c.name ASC, d.name ASC';
            $statement = $this->dbAdapter->createStatement($sql, [$userId]);
        } else {
            $sql = 'SELECT d.*, 0 AS user_total_count FROM drinks d '
                . 'LEFT JOIN drink_categories c ON d.category = c.id '
                . 'ORDER BY c.sort_priority ASC, c.name ASC, d.name ASC';
            $statement = $this->dbAdapter->query($sql);
        }
        return $statement->execute();
    }

    public function get($id)
    {
        $sql = 'SELECT * FROM drinks WHERE id = ?';
        $statement = $this->dbAdapter->createStatement($sql, [$id]);
        $result = $statement->execute();
        return $result->current();
    }

    /**
     * Fetches and returns order details for a given order and user, drops the order, recalculates balance, and sends cancellation email.
     */
    public function dropOrderAndNotify($orderId, $user, $tCallback, $serviceManager)
    {
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $dbAdapter = $this->dbAdapter;
        // Fetch order details before deletion
        $sql = 'SELECT do.*, d.name as drink_name FROM drink_orders do JOIN drinks d ON do.drink_id = d.id WHERE do.id = ? AND do.user_id = ?';
        $statement = $dbAdapter->createStatement($sql, [$orderId, $user->need('uid')]);
        $order = $statement->execute()->current();
        $result = $drinkOrderManager->dropOrder($orderId, $user->need('uid'), $user->need('uid'));
        if ($result->getAffectedRows() > 0 && $order) {
            // Recalculate balance after cancellation
            $balance = $this->calculateUserDrinkBalance($user->need('uid'), $serviceManager);
            // Send cancellation email
            $isTransferOrder = !empty($order['transfer_reference']) || (int)$order['drink_id'] === -1;
            $subject = $isTransferOrder
                ? call_user_func($tCallback, 'Stornierung Deiner Geldüberweisung')
                : call_user_func($tCallback, 'Stornierung Deiner Getränkebestellung');
            $lines = [];
            if (self::isCustomPriceDrink($order['drink_id'])) {
                // Sonstiges (1) and money transfers (-1): only show the comment
                $drinkName = isset($order['drink_name']) ? $order['drink_name'] : ('ID ' . $order['drink_id']);
                $label = self::formatCustomEntryLabel($order['quantity'], isset($order['comment']) ? $order['comment'] : '', $drinkName);
                $lines[] = sprintf('%s = %.2f EUR', $label, $order['quantity'] * $order['price']);
            } else {
                $lines[] = sprintf('%s x %d = %.2f EUR', $order['drink_name'], $order['quantity'], $order['quantity'] * $order['price']);
            }
            $lines[] = '---------------------';
            $lines[] = sprintf(call_user_func($tCallback, 'Storniert am:') . ' %s', date('d.m.Y H:i'));
            $lines[] = '';
            $lines[] = sprintf(call_user_func($tCallback, 'Kontostand nach Stornierung:') . '<b> %.2f EUR </b>', $balance);
            $text = ($isTransferOrder
                ? call_user_func($tCallback, 'Deine Geldüberweisung wurde erfolgreich storniert.')
                : call_user_func($tCallback, 'Deine Getränkebestellung wurde erfolgreich storniert.'))
                . "<br><br>" . implode("<br>", $lines);
            if ($balance < 0) {
                $text .= '<br><br>' . $this->negativeBalanceWarningHtml($tCallback);
            }
            $userMailService = $serviceManager->get('User\Service\MailService');
            $this->sendFromTheke($userMailService, $this->dbAdapter, $user, $subject, $text, ['isHtml' => true]);

            // If this was a transfer order, notify the counterpart (deposit side)
            if (!empty($order['transfer_reference'])) {
                try {
                    $counterRow = $dbAdapter->query(
                        'SELECT * FROM drink_deposits WHERE transfer_reference = ? LIMIT 1',
                        [$order['transfer_reference']]
                    )->current();
                    if ($counterRow && (int)$counterRow['user_id'] !== (int)$user->need('uid')) {
                        $userManager = $serviceManager->get('User\Manager\UserManager');
                        $counterUser = $userManager->get($counterRow['user_id'], false);
                        if ($counterUser) {
                            $counterBalance = $this->calculateUserDrinkBalance($counterRow['user_id'], $serviceManager);
                            $senderName = $user->get('alias') ?: $user->get('name');
                            $counterSubject = call_user_func($tCallback, 'Geldüberweisung storniert');
                            $counterText = sprintf(
                                call_user_func($tCallback, 'Eine Geldüberweisung von %s an Dich wurde storniert.') . '<br><br>' .
                                call_user_func($tCallback, 'Kontostand nach Stornierung:') . '<b> %.2f EUR </b>',
                                htmlspecialchars($senderName),
                                $counterBalance
                            );
                            $this->sendFromTheke($userMailService, $this->dbAdapter, $counterUser, $counterSubject, $counterText, ['isHtml' => true]);
                        }
                    }
                } catch (\Exception $e) {
                    // Do not fail primary notification if counterpart email fails.
                }
            }

            return true;
        }
        return false;
    }

    /**
     * Adds drink orders for a user, sends confirmation email, and returns the new balance.
     * Returns array: ['success' => bool, 'balance' => float, 'error' => string|null]
     */
    public function addOrdersAndNotify($user, $drinkCounts, $tCallback, $serviceManager, $isAutoOrder = 0, $comment = null, $teamEventId = null, $allowBelowMinimum = false)
    {
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $anyOrdered = false;
        $orderedDrinks = [];
        $orderTotal = 0.0;
        $orderItems = [];
        foreach ($drinkCounts as $drinkId => $quantity) {
            $drinkId = (int)$drinkId;
            $quantity = (int)$quantity;
            if ($drinkId > 0 && $quantity > 0) {
                $drink = $this->get($drinkId);
                if ($drink) {
                    $orderTotal += $quantity * (float)$drink['price'];
                    $orderItems[] = [$drinkId, $quantity];
                }
            }
        }
        if (!$this->isOrderAllowed($user->need('uid'), $orderTotal, $serviceManager, $allowBelowMinimum)) {
            return [
                'success' => false,
                'balance' => $this->calculateUserDrinkBalance($user->need('uid'), $serviceManager),
                'error' => call_user_func($tCallback, 'Keine Buchung möglich bis Guthaben aufgeladen ist'),
            ];
        }
        foreach ($orderItems as $orderItem) {
            $drinkId = $orderItem[0];
            $quantity = $orderItem[1];
            $drinkOrderManager->addOrder($user->need('uid'), $drinkId, $quantity, null, $isAutoOrder, $comment, null, $teamEventId);
            $anyOrdered = true;
            $drink = $this->get($drinkId);
            if ($drink) {
                $orderedDrinks[] = [
                    'id' => $drinkId,
                    'name' => $drink['name'],
                    'quantity' => $quantity,
                    'price' => $drink['price'],
                    'total' => $quantity * $drink['price'],
                    'comment' => $comment,
                ];
            }
        }
        if ($anyOrdered) {
            $balance = $this->calculateUserDrinkBalance($user->need('uid'), $serviceManager);
            if ($this->shouldSendOrderEmail($user->need('uid'), $balance)) {
                $subject = call_user_func($tCallback, 'Bestätigung Deiner Getränkebestellung');
                $lines = [];
                $totalSum = 0;
                foreach ($orderedDrinks as $item) {
                    if ((int)$item['id'] === 1) {
                        $label = self::formatCustomEntryLabel($item['quantity'], isset($item['comment']) ? $item['comment'] : '', $item['name']);
                        $lines[] = sprintf('%s = %.2f EUR', $label, $item['total']);
                    } else {
                        $lines[] = sprintf('%s x %d = %.2f EUR', $item['name'], $item['quantity'], $item['total']);
                    }
                    $totalSum += $item['total'];
                }
                $lines[] = '---------------------';
                $lines[] = sprintf(call_user_func($tCallback, 'Gesamt:') . ' %.2f EUR', $totalSum);
                $lines[] = '';
                $lines[] = sprintf(call_user_func($tCallback, 'Kontostand nach Bestellung:') . '<b> %.2f EUR </b>', $balance);
                $text = call_user_func($tCallback, 'Vielen Dank für Deine Getränkebestellung!') . "<br><br>" . implode("<br>", $lines);
                if ($balance < 0) {
                    $text .= '<br><br>' . $this->negativeBalanceWarningHtml($tCallback);
                }
                $userMailService = $serviceManager->get('User\Service\MailService');
                $this->sendFromTheke($userMailService, $this->dbAdapter, $user, $subject, $text, ['isHtml' => true]);
            }
            return ['success' => true, 'balance' => $balance, 'error' => null];
        }
        return ['success' => false, 'balance' => 0, 'error' => call_user_func($tCallback, 'Bitte mindestens ein Getränk auswählen.')];
    }

    /**
     * Sends a daily summary email for a user with grouped/merged drinks and correct balance.
     * @param int $userId
     * @param object $serviceManager
     * @param callable $tCallback
     * @return bool True if sent, false if no orders or user not found
     */
    public function sendDailySummary($userId, $serviceManager, $tCallback)
    {
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $user = $userManager->get($userId, false);
        if (!$user) return false;

        // 1. Query all orders of the user from the last 24 hours
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $allOrders = iterator_to_array($drinkOrderManager->getByUser($userId));
        $orders = array_filter(
            $allOrders,
            function($order) {
                if (empty($order['order_time'])) return false;
                $orderTime = is_numeric($order['order_time']) ? (int)$order['order_time'] : strtotime($order['order_time']);
                return $orderTime >= (time() - 86400);
            }
        );
        if (empty($orders)) {
            return false;
        }
        // Group orders by day, then merge drinks per day
        $ordersByDay = [];
        foreach ($orders as $order) {
            if (!empty($order['deleted'])) continue;
            $date = date('Y-m-d', is_numeric($order['order_time']) ? (int)$order['order_time'] : strtotime($order['order_time']));
            if (!isset($ordersByDay[$date])) $ordersByDay[$date] = [];
            $ordersByDay[$date][] = $order;
        }
        // Build summary email
        $lines = [];
        $totalSum = 0;
        foreach ($ordersByDay as $date => $ordersForDay) {
            $lines[] = '<b>' . htmlspecialchars($date) . '</b>';
            $drinkSums = [];
            foreach ($ordersForDay as $order) {
                if (self::isCustomPriceDrink($order['drink_id'])) {
                    // Sonstiges (1) and money transfers (-1): group by comment
                    $key = $order['comment'];
                    if (!isset($drinkSums[$key])) {
                        $drinkSums[$key] = ['quantity' => 0, 'total' => 0.0, 'comment' => $order['comment']];
                    }
                    $drinkSums[$key]['quantity'] += $order['quantity'];
                    $drinkSums[$key]['total'] += $order['quantity'] * $order['price'];
                } else {
                    $drink = $this->get($order['drink_id']);
                    $drinkName = $drink ? $drink['name'] : ('ID ' . $order['drink_id']);
                    if (!isset($drinkSums[$drinkName])) {
                        $drinkSums[$drinkName] = ['quantity' => 0, 'total' => 0.0];
                    }
                    $drinkSums[$drinkName]['quantity'] += $order['quantity'];
                    $drinkSums[$drinkName]['total'] += $order['quantity'] * $order['price'];
                }
            }
            foreach ($drinkSums as $key => $sum) {
                if (isset($sum['comment'])) {
                    $label = self::formatCustomEntryLabel($sum['quantity'], $sum['comment'], $key);
                    $lines[] = sprintf('%s = %.2f EUR', $label, $sum['total']);
                } else {
                    $lines[] = sprintf('%s x %d = %.2f EUR', $key, $sum['quantity'], $sum['total']);
                }
                $totalSum += $sum['total'];
            }
            $lines[] = '';
        }
        if (empty($lines)) return false;
        $lines[] = '---------------------';
        $lines[] = sprintf($tCallback('Gesamt:') . ' %.2f EUR', $totalSum);
        $lines[] = '';
        // Calculate balance (all time): sum(deposits) - sum(orders)
        $balance = $this->calculateUserDrinkBalance($userId, $serviceManager);
        $lines[] = sprintf($tCallback('Kontostand:') . '<b> %.2f EUR </b>', $balance);
        $text = $tCallback('Deine Getränkebestellungen im Überblick:') . "<br><br>" . implode("<br>", $lines);
        if ($balance < 0) {
            $text .= '<br><br>' . $this->negativeBalanceWarningHtml($tCallback);
        }
        $subject = $tCallback('Deine Getränkebestellungen (Zusammenfassung)');

        $mailService = $serviceManager->get('User\Service\MailService');
        $this->sendFromTheke($mailService, $this->dbAdapter, $user, $subject, $text, ['isHtml' => true]);
        return true;
    }

    public function sendBalanceReminder($userId, $serviceManager, $tCallback)
    {
        $userManager = $serviceManager->get('User\\Manager\\UserManager');
        $user = $userManager->get($userId, false);
        if (!$user || $this->isTeamAccount($userId) || trim((string)$user->get('email')) === '') {
            return false;
        }

        $balance = round($this->calculateUserDrinkBalance($userId, $serviceManager), 2);
        if ($balance >= $this->getMoneyOption($serviceManager, 'drinks.account_balance_reminder_threshold')) {
            return false;
        }

        $subject = $tCallback('Erinnerung: Bitte Guthaben aufladen');
        $text = $tCallback('Dein Kneipen-Konto ist ins negative gerutscht.') . "\n\n"
            . $tCallback('Bitte lade dein Guthaben auf.') . "\n\n"
            . $tCallback('Liebe Grüße') . "\n"
            . $tCallback('Dein Theken-Team');
        $mailService = $serviceManager->get('User\\Service\\MailService');
        $this->sendFromTheke($mailService, $this->dbAdapter, $user, $subject, $text, ['isHtml' => false]);
        return true;
    }
}
