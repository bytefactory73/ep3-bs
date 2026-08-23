<?php
// Web-safe summary email script for drink orders.
// The bundled Zend Framework version emits PHP 8.x compatibility deprecations.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
set_error_handler(function ($severity) {
    return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
});

$serviceManager = require __DIR__ . '/bootstrap.php';
$dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
$drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
$tCallback = function($str) { return $str; }; // Replace with translation if needed

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
}
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
}
$users = new ArrayObject(array_map(function($id) { return ['user_id' => $id]; }, $userIds));

foreach ($users as $row) {
    $userId = $row['user_id'];
    $drinkManager->sendDailySummary($userId, $serviceManager, $tCallback);
}

if ((int)date('j') === 1 || (int)date('j') === 15) {
    $reminderUsers = $dbAdapter->query(
        'SELECT user_id FROM drink_aliases WHERE enabled = 1 AND is_team = 0'
    )->execute();
    foreach ($reminderUsers as $row) {
        $drinkManager->sendBalanceReminder($row['user_id'], $serviceManager, $tCallback);
    }
}

