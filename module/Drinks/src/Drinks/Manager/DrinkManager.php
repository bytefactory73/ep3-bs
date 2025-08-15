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
        $result = $drinkOrderManager->dropOrder($orderId, $user->need('uid'));
        if ($result->getAffectedRows() > 0 && $order) {
            // Recalculate balance after cancellation
            $drinkOrders = iterator_to_array($drinkOrderManager->getByUser($user->need('uid')));
            $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
            $drinkDeposits = iterator_to_array($drinkDepositManager->getByUser($user->need('uid')));
            $balance = 0;
            foreach ($drinkDeposits as $deposit) {
                $balance += $deposit['amount'];
            }
            foreach ($drinkOrders as $o) {
                if (empty($o['deleted'])) {
                    $balance -= $o['quantity'] * $o['price'];
                }
            }
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
                $text .= '<span style="color:#d32f2f;font-weight:bold;">' . call_user_func($tCallback, 'Warnung: Dein Kontostand ist negativ! Bitte überweise Geld auf das STC Paypal-Konto.') . '</span>';
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
    public function addOrdersAndNotify($user, $drinkCounts, $tCallback, $serviceManager)
    {
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
        $anyOrdered = false;
        $orderedDrinks = [];
        foreach ($drinkCounts as $drinkId => $quantity) {
            $drinkId = (int)$drinkId;
            $quantity = (int)$quantity;
            if ($drinkId > 0 && $quantity > 0) {
                $drinkOrderManager->addOrder($user->need('uid'), $drinkId, $quantity);
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
            $drinkOrders = iterator_to_array($drinkOrderManager->getByUser($user->need('uid')));
            $drinkDeposits = iterator_to_array($drinkDepositManager->getByUser($user->need('uid')));
            $balance = 0;
            foreach ($drinkDeposits as $deposit) {
                $balance += $deposit['amount'];
            }
            foreach ($drinkOrders as $order) {
                if (empty($order['deleted'])) {
                    $balance -= $order['quantity'] * $order['price'];
                }
            }
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
                $text .= '<span style="color:#d32f2f;font-weight:bold;">' . call_user_func($tCallback, 'Warnung: Dein Kontostand ist negativ! Bitte überweise Geld auf das STC Paypal-Konto.') . '</span>';
            }
            $userMailService = $serviceManager->get('User\Service\MailService');
            $userMailService->send($user, $subject, $text, ['isHtml' => true]);
            return ['success' => true, 'balance' => $balance, 'error' => null];
        }
        return ['success' => false, 'balance' => 0, 'error' => call_user_func($tCallback, 'Bitte mindestens ein Getränk auswählen.')];
    }
}
