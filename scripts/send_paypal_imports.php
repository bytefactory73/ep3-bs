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

// STDERR only exists in the CLI SAPI; hosting cron may run this script via CGI.
$fail = function ($message) {
    error_log('send_paypal_imports: ' . $message);
    if (PHP_SAPI === 'cli' && defined('STDERR')) {
        fwrite(STDERR, $message . "\n");
    } else {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo $message . "\n";
    }
    exit(1);
};

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
}

$settings = \Drinks\Manager\PaypalTransactionManager::loadSettings($optionManager);
if (!\Drinks\Manager\PaypalTransactionManager::hasImapSettings($settings)) {
    $fail('PayPal IMAP settings are incomplete.');
}
if (!\Drinks\Manager\PaypalTransactionManager::hasApiCredentials($settings)) {
    $fail('PayPal API credentials are missing.');
}
$clientId = $settings['paypal_client_id'];
$clientSecret = $settings['paypal_client_secret'];

$hadErrors = false;
$report = function ($label, array $result, array $fields) use (&$hadErrors) {
    $parts = [];
    foreach ($fields as $field) {
        if (isset($result[$field])) {
            $parts[] = $field . '=' . $result[$field];
        }
    }
    if (!empty($result['needs_review'])) {
        $parts[] = 'needs_review=#' . implode(', #', $result['needs_review']);
    }
    if (!empty($result['errors'])) {
        $hadErrors = true;
        $parts[] = 'errors=' . implode(' | ', array_slice($result['errors'], 0, 3));
    }
    echo $label . ': ' . implode(', ', $parts) . "\n";
};

echo "Starting PayPal IMAP fetch...\n";
$fetchResult = $paypalManager->importFromImap($settings);
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