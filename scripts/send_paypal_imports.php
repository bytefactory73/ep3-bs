<?php
// Web-safe cron script for PayPal imports.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$serviceManager = require __DIR__ . '/bootstrap.php';
$optionManager = $serviceManager->get('Base\Manager\OptionManager');
$paypalManager = $serviceManager->get('Drinks\Manager\PaypalTransactionManager');
$depositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
$createdByUserId = 0;

$mailMode = '';
if (isset($_GET['EP3_BS_MAIL_MODE'])) {
    $mailMode = strtolower(trim((string)$_GET['EP3_BS_MAIL_MODE']));
} elseif (isset($_GET['MAIL_MODE'])) {
    $mailMode = strtolower(trim((string)$_GET['MAIL_MODE']));
}
if (in_array($mailMode, array('test', 'live', 'auto'), true)) {
    putenv('EP3_BS_MAIL_MODE=' . $mailMode);
    $_ENV['EP3_BS_MAIL_MODE'] = $mailMode;
}

$loadOption = function ($key) use ($optionManager) {
    try {
        $value = (string)$optionManager->get('paypal.' . $key, '');
        if ($value === '' && strpos($key, '_') !== false) {
            $value = (string)$optionManager->get('paypal.' . str_replace('_', '.', $key), '');
        }
        return trim($value);
    } catch (\Throwable $e) {
        return '';
    }
};

$imapHost = $loadOption('imap_host');
$imapPort = $loadOption('imap_port');
$imapUser = $loadOption('imap_user');
$imapPassword = $loadOption('imap_password');
$imapSsl = $loadOption('imap_ssl');
$clientId = $loadOption('paypal_client_id');
$clientSecret = $loadOption('paypal_client_secret');

if ($imapHost === '' || $imapPort === '' || $imapUser === '' || $imapPassword === '') {
    fwrite(STDERR, "PayPal IMAP settings are incomplete.\n");
    exit(1);
}

if ($clientId === '' || $clientSecret === '') {
    fwrite(STDERR, "PayPal API credentials are missing.\n");
    exit(1);
}

$hadErrors = false;
$report = function ($label, array $result, array $fields) use (&$hadErrors) {
    $parts = [];
    foreach ($fields as $field) {
        if (isset($result[$field])) {
            $parts[] = $field . '=' . $result[$field];
        }
    }
    if (!empty($result['errors'])) {
        $hadErrors = true;
        $parts[] = 'errors=' . implode(' | ', array_slice($result['errors'], 0, 3));
    }
    echo $label . ': ' . implode(', ', $parts) . "\n";
};

echo "Starting PayPal IMAP fetch...\n";
$fetchResult = $paypalManager->importFromImap($imapHost, $imapPort, $imapUser, $imapPassword, $imapSsl === '1');
$report('IMAP import', $fetchResult, ['imported', 'skipped']);

echo "Syncing newly received PayPal emails...\n";
$syncResult = $paypalManager->syncEmailReceivedTransactions($clientId, $clientSecret, 100);
$report('Email sync', $syncResult, ['synced', 'skipped']);

echo "Auto-assigning synced transactions...\n";
$autoResult = $paypalManager->autoAssignSyncedTransactions($depositManager, $createdByUserId, $serviceManager);
$report('Auto-assign', $autoResult, ['assigned', 'created', 'skipped']);

$days = 14;
echo 'Starting PayPal history import for the last ' . $days . " days...\n";
$historyResult = $paypalManager->importFromReportingApi($clientId, $clientSecret, $days);
$report('History import', $historyResult, ['imported', 'skipped']);

echo "Syncing again after history import...\n";
$syncAfterHistoryResult = $paypalManager->syncEmailReceivedTransactions($clientId, $clientSecret, 100);
$report('Post-history email sync', $syncAfterHistoryResult, ['synced', 'skipped']);

echo "Auto-assigning again after history import...\n";
$autoAfterHistoryResult = $paypalManager->autoAssignSyncedTransactions($depositManager, $createdByUserId, $serviceManager);
$report('Post-history auto-assign', $autoAfterHistoryResult, ['assigned', 'created', 'skipped']);

exit($hadErrors ? 1 : 0);