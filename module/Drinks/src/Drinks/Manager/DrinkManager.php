<?php

namespace Drinks\Manager;

use RuntimeException;
use Zend\Db\Adapter\Adapter;

class DrinkManager
{
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
     * Returns true on success, false on failure.
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
            $subject = call_user_func($tCallback, 'Stornierung Deiner Getränkebestellung');
            $lines = [
                sprintf('%s x %d = %.2f EUR', $order['drink_name'], $order['quantity'], $order['quantity'] * $order['price']),
                '---------------------',
                sprintf(call_user_func($tCallback, 'Storniert am:') . ' %s', date('d.m.Y H:i')),
                '',
                sprintf(call_user_func($tCallback, 'Kontostand nach Stornierung:') . '<b> %.2f EUR </b>', $balance),
            ];
            $text = call_user_func($tCallback, 'Deine Getränkebestellung wurde erfolgreich storniert.') . "<br><br>" . implode("<br>", $lines);
            if ($balance < 0) {
                $text .= "<br><br>";
                $text .= '<span style="color:#d32f2f;font-weight:bold;">' . call_user_func($tCallback, 'Warnung: Dein Kontostand ist negativ! Bitte überweise Geld auf das Paypal-Konto "kneipe@stc-butzbach.de" oder wirf Geld in den weißen Briefkasten ein.') . '</span>';
            }
            $userMailService = $serviceManager->get('User\Service\MailService');
            $userMailService->send($user, $subject, $text, ['isHtml' => true]);
            return true;
        }
        return false;
    }

    /**
     * Adds drink orders for a user, sends confirmation email, and returns the new balance.
     * Returns array: ['success' => bool, 'balance' => float, 'error' => string|null]
     */
    public function addOrdersAndNotify($user, $drinkCounts, $tCallback, $serviceManager, $isAutoOrder = 0, $comment = null)
    {
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $anyOrdered = false;
        $orderedDrinks = [];
        foreach ($drinkCounts as $drinkId => $quantity) {
            $drinkId = (int)$drinkId;
            $quantity = (int)$quantity;
            if ($drinkId > 0 && $quantity > 0) {
                $drinkOrderManager->addOrder($user->need('uid'), $drinkId, $quantity, null, $isAutoOrder, $comment);
                $anyOrdered = true;
                $drink = $this->get($drinkId);
                if ($drink) {
                    $orderedDrinks[] = [
                        'name' => $drink['name'],
                        'quantity' => $quantity,
                        'price' => $drink['price'],
                        'total' => $quantity * $drink['price'],
                    ];
                }
            }
        }
        if ($anyOrdered) {
            $balance = $this->calculateUserDrinkBalance($user->need('uid'), $serviceManager);
            // Fetch order_email_option from drink_aliases
            $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
            $aliasRow = $dbAdapter->query('SELECT order_email_option FROM drink_aliases WHERE user_id = ?', [$user->need('uid')])->current();
            $orderEmailOption = $aliasRow && isset($aliasRow['order_email_option']) ? $aliasRow['order_email_option'] : null;
            $shouldSend = false;
            if ($orderEmailOption === 'order') {
                $shouldSend = true;
            } elseif ($orderEmailOption === 'negative' && $balance <= 0) {
                $shouldSend = true;
            }
            if ($shouldSend) {
                $subject = call_user_func($tCallback, 'Bestätigung Deiner Getränkebestellung');
                $lines = [];
                $totalSum = 0;
                foreach ($orderedDrinks as $item) {
                    $lines[] = sprintf('%s x %d = %.2f EUR', $item['name'], $item['quantity'], $item['total']);
                    $totalSum += $item['total'];
                }
                $lines[] = '---------------------';
                $lines[] = sprintf(call_user_func($tCallback, 'Gesamt:') . ' %.2f EUR', $totalSum);
                $lines[] = '';
                $lines[] = sprintf(call_user_func($tCallback, 'Kontostand nach Bestellung:') . '<b> %.2f EUR </b>', $balance);
                $text = call_user_func($tCallback, 'Vielen Dank für Deine Getränkebestellung!') . "<br><br>" . implode("<br>", $lines);
                if ($balance < 0) {
                    $text .= "<br><br>";
                    $text .= '<span style="color:#d32f2f;font-weight:bold;">' . call_user_func($tCallback, 'Warnung: Dein Kontostand ist negativ! Bitte überweise Geld auf das Paypal-Konto "kneipe@stc-butzbach.de" oder wirf Geld in den weißen Briefkasten ein.') . '</span>';
                }
                $userMailService = $serviceManager->get('User\Service\MailService');
                $userMailService->send($user, $subject, $text, ['isHtml' => true]);
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
        $user = $userManager->get($userId);
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
                $drink = $this->get($order['drink_id']);
                $drinkName = $drink ? $drink['name'] : ('ID ' . $order['drink_id']);
                if (!isset($drinkSums[$drinkName])) {
                    $drinkSums[$drinkName] = ['quantity' => 0, 'total' => 0.0];
                }
                $drinkSums[$drinkName]['quantity'] += $order['quantity'];
                $drinkSums[$drinkName]['total'] += $order['quantity'] * $order['price'];
            }
            foreach ($drinkSums as $drinkName => $sum) {
                $lines[] = sprintf('%s x %d = %.2f EUR', $drinkName, $sum['quantity'], $sum['total']);
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
            $text .= "<br><br>";
            $text .= '<span style="color:#d32f2f;font-weight:bold;">' . $tCallback('Warnung: Dein Kontostand ist negativ! Bitte überweise Geld auf das Paypal-Konto "kneipe@stc-butzbach.de" oder wirf Geld in den weißen Briefkasten ein.') . '</span>';
        }
        $subject = $tCallback('Deine Getränkebestellungen (Zusammenfassung)');

        $mailService = $serviceManager->get('User\Service\MailService');
        $mailService->send($user, $subject, $text, ['isHtml' => true]);
        return true;
    }
}
