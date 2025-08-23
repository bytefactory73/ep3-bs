<?php
// Web-safe summary email script for drink orders
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$serviceManager = require __DIR__ . '/bootstrap.php';
$dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
$userManager = $serviceManager->get('User\Manager\UserManager');
$mailService = $serviceManager->get('User\Service\MailService');
$drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
$drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
$tCallback = function($str) { return $str; }; // Replace with translation if needed


header('Content-Type: text/html; charset=utf-8');
echo "<html><head><meta charset='utf-8'><title>Drink Order Summary Emails</title></head><body style='font-family:monospace;background:#f9f9f9;color:#222;'><h2>Drink Order Summary Emails</h2><pre style='background:#fff;padding:1em;border:1px solid #ccc;'>";


// 1. Query all users with summary setting and enabled alias
$sql = "SELECT user_id FROM drink_aliases WHERE order_email_option = 'summary' AND enabled = 1";
$users = $dbAdapter->query($sql)->execute();


$userIds = [];
foreach ($users as $row) {
    $userIds[] = $row['user_id'];
}
if (empty($userIds)) {
    echo "<b>No users found for summary emails.</b>\n";
    exit;
}
$users = new ArrayObject(array_map(function($id) { return ['user_id' => $id]; }, $userIds));



foreach ($users as $row) {
    $userId = $row['user_id'];
    $user = $userManager->get($userId);
    if (!$user) continue;
    $userName = $user->get('alias') ?: $user->get('name');
    // 2a. Query all orders of the user from the last 24 hours
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
        continue;
    }
    // Group orders by day, then merge drinks per day
    $ordersByDay = [];
    foreach ($orders as $order) {
        if (!empty($order['deleted'])) continue;
        $date = date('Y-m-d', is_numeric($order['order_time']) ? (int)$order['order_time'] : strtotime($order['order_time']));
        if (!isset($ordersByDay[$date])) $ordersByDay[$date] = [];
        $ordersByDay[$date][] = $order;
    }
    // 2b. Build summary email
    $lines = [];
    $totalSum = 0;
    foreach ($ordersByDay as $date => $ordersForDay) {
        $lines[] = '<b>' . htmlspecialchars($date) . '</b>';
        $drinkSums = [];
        foreach ($ordersForDay as $order) {
            $drink = $drinkManager->get($order['drink_id']);
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
    if (empty($lines)) continue;
    $lines[] = '---------------------';
    $lines[] = sprintf($tCallback('Gesamt:') . ' %.2f EUR', $totalSum);
    $lines[] = '';
    // Calculate balance (all time): sum(deposits) - sum(orders), like AccountController.php
    $drinkDeposits = iterator_to_array($serviceManager->get('Drinks\Manager\DrinkDepositManager')->getByUser($userId));
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
    $balance = $depositSum - $orderSum;
    $lines[] = sprintf($tCallback('Kontostand:') . '<b> %.2f EUR </b>', $balance);
    $text = $tCallback('Deine Getränkebestellungen im Überblick:') . "<br><br>" . implode("<br>", $lines);
    if ($balance < 0) {
        $text .= "<br><br>";
        $text .= '<span style="color:#d32f2f;font-weight:bold;">' . $tCallback('Warnung: Dein Kontostand ist negativ! Bitte überweise Geld auf das Paypal-Konto "kneipe@stc-butzbach.de" oder wirf Geld in den weißen Briefkasten ein.') . '</span>';
    }
    $subject = $tCallback('Deine Getränkebestellungen (Zusammenfassung)');
    $mailService->send($user, $subject, $text, ['isHtml' => true]);
}

