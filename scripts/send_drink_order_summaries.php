<?php
// Web-safe summary email script for drink orders
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$serviceManager = require __DIR__ . '/bootstrap.php';
$dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
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
    $drinkManager->sendDailySummary($userId, $serviceManager, $tCallback);
}

