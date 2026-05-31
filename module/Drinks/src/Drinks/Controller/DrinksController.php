<?php

namespace Drinks\Controller;

use DateTime;
use RuntimeException;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\View\Model\JsonModel;
use User\Controller\Traits\MoneyTransferTrait;
use User\Controller\Traits\TeamEventTrait;
use User\Controller\Traits\ThekeMailTrait;

class DrinksController extends AbstractActionController
{
    use MoneyTransferTrait;
    use TeamEventTrait;
    use ThekeMailTrait;

    /**
     * Main drinks page action
     */
    public function indexAction()
    {
        return new ViewModel();
    }

    /**
     * User drinks page - shows drink menu, order history, balance
     */
    public function drinksAction()
    {
        $serviceManager = @$this->getServiceLocator();

        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $drinkCategoryManager = $serviceManager->get('Drinks\Manager\DrinkCategoryManager');
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');

        $user = $userSessionManager->getSessionUser();

        if (! $user) {
            $this->redirectBack()->setOrigin('user/drinks');
            return $this->redirect()->toRoute('user/login');
        }

        // Fetch drinks, drink categories, and drink orders for this user
        $drinks = $drinkManager->getAll($user->need('uid'));
        $drinkCategories = $drinkCategoryManager->getAll();
        $drinkOrders = iterator_to_array($drinkOrderManager->getByUser($user->need('uid')));
        $drinkDeposits = iterator_to_array($drinkDepositManager->getByUser($user->need('uid')));

        // Query drink_aliases for enabled flag
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $userId = $user->need('uid');
        $aliasRow = $dbAdapter->query('SELECT enabled, thekenadmin FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        $drinksEnabled = ($aliasRow && isset($aliasRow['enabled']) && (int)$aliasRow['enabled'] === 1);
        $thekenadmin = ($aliasRow && isset($aliasRow['thekenadmin']) && (int)$aliasRow['thekenadmin'] === 1);
        
        // Check if user is a team account
        $isTeamAccount = ($aliasRow && isset($aliasRow['is_team']) && (int)$aliasRow['is_team'] === 1);

        // Merge and sort by date descending
        $drinkHistory = [];
        foreach ($drinkOrders as $order) {
            $deleted = isset($order['deleted']) ? (int)$order['deleted'] : 0;
            $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : null;
            $quantity = isset($order['quantity']) ? (int)$order['quantity'] : 1;
            $comment = isset($order['comment']) ? trim((string)$order['comment']) : '';
            $drinkName = $order['name'];
            // For special comment-based entries (1, -1), use drink name as fallback when comment is empty
            if (($drinkId === 1 || $drinkId === -1) && $comment === '') {
                $comment = $drinkName;
            }
            $drinkHistory[] = [
                'type' => 'order',
                'id' => $order['id'],
                'name' => $order['name'],
                'drink_id' => $drinkId,
                'quantity' => $quantity,
                'price' => $order['price'],
                'total' => $quantity * $order['price'],
                'datetime' => $order['order_time'],
                'deleted' => $deleted,
                'comment' => $comment,
            ];
        }
        foreach ($drinkDeposits as $deposit) {
            $creatorName = null;
            if (!empty($deposit['createdbyuserid'])) {
                $creatorUser = $userManager->get($deposit['createdbyuserid'], false);
                if ($creatorUser) {
                    $creatorName = $creatorUser->get('alias') ?: $creatorUser->get('name');
                }
            }
            $deleted = isset($deposit['deleted']) ? (int)$deposit['deleted'] : 0;
            $drinkHistory[] = [
                'type' => 'deposit',
                'amount' => $deposit['amount'],
                'datetime' => $deposit['deposit_time'],
                'createdby' => $creatorName,
                'deleted' => $deleted,
                'user_id_deleted' => isset($deposit['user_id_deleted']) ? $deposit['user_id_deleted'] : null,
            ];
        }
        usort($drinkHistory, function($a, $b) {
            return strcmp($b['datetime'], $a['datetime']);
        });

        $drinkStats = [];
        $userId = null;
        $userName = null;
        try {
            $userId = $user ? $user->need('uid') : null;
            if ($userId) {
                $statsResult = $drinkOrderManager->getDrinkStatsByUser($userId);
                foreach ($statsResult as $row) {
                    $drinkStats[] = [
                        'id' => isset($row['id']) ? (int)$row['id'] : null,
                        'name' => $row['name'],
                        'total_count' => $row['total_count'],
                    ];
                }
                $userName = $user->get('alias') ?: $user->get('name');
            }
        } catch (\Exception $e) {
            // In case of DB error, leave $drinkStats empty
        }

        $drinkOrderCounts = array();
        foreach ($drinkHistory as $entry) {
            if ($entry['type'] === 'order' && isset($entry['drink_id']) && empty($entry['deleted'])) {
                $drinkId = $entry['drink_id'];
                $qty = isset($entry['quantity']) ? (int)$entry['quantity'] : 1;
                if (!isset($drinkOrderCounts[$drinkId])) $drinkOrderCounts[$drinkId] = 0;
                $drinkOrderCounts[$drinkId] += $qty;
            }
        }

        $moneyRecipients = [];
        $allUsers = $userManager->getAll('alias ASC');
        foreach ($allUsers as $candidateUser) {
            $status = $candidateUser->get('status');
            if ($status !== 'enabled' && $status !== 'admin' && $status !== 'assist') {
                continue;
            }
            $candidateUid = (int)$candidateUser->get('uid');
            if ($candidateUid <= 0 || $candidateUid === (int)$userId) {
                continue;
            }
            $candidateAlias = trim((string)$candidateUser->get('alias'));
            $candidateName = trim((string)$candidateUser->get('name'));
            $candidateEmail = trim((string)$candidateUser->get('email'));
            $displayName = $candidateAlias !== '' ? $candidateAlias : ($candidateName !== '' ? $candidateName : ('User ' . $candidateUid));
            $moneyRecipients[] = [
                'uid' => $candidateUid,
                'name' => $displayName,
                'email' => $candidateEmail,
            ];
        }

        // Pass cancel window from backend constant
        $drinkOrderCancelWindow = \Drinks\Manager\DrinkOrderManager::CANCEL_WINDOW_SECONDS;
        
        // Get current Spieltag for team accounts
        $currentSpieltag = '';
        $availableSpieltage = [];
        if ($isTeamAccount) {
            try {
                $teamAdminUserId = (int)$dbAdapter->query('SELECT team_admin_user_id FROM drink_teamevents GROUP BY team_admin_user_id ORDER BY MAX(id) DESC LIMIT 1')->current();
                if ($teamAdminUserId) {
                    $teamAliasRow = $dbAdapter->query('SELECT alias FROM drink_aliases WHERE user_id = ?', [$userId])->current();
                    if ($teamAliasRow) {
                        $teamAlias = trim((string)$teamAliasRow['alias']);
                        if ($teamAlias !== '') {
                            $eventRows = $dbAdapter->query(
                                'SELECT DISTINCT te.id, te.comment FROM drink_teamevents te INNER JOIN drink_teamevent_members tm ON te.id = tm.team_event_id WHERE te.team_admin_user_id = ? AND tm.user_id = ? ORDER BY te.id DESC',
                                [$teamAdminUserId, $userId]
                            )->toArray();
                            foreach ($eventRows as $er) {
                                $label = isset($er['comment']) ? trim((string)$er['comment']) : '';
                                if ($label !== '') {
                                    $availableSpieltage[] = $label;
                                    if ($currentSpieltag === '') {
                                        $currentSpieltag = $label;
                                    }
                                }
                            }
                            // Also check direct team event assignments
                            $directEvents = $dbAdapter->query(
                                'SELECT DISTINCT comment FROM drink_teamevents WHERE team_admin_user_id = ? AND LENGTH(comment) > 0 ORDER BY id DESC',
                                [$userId]
                            )->toArray();
                            foreach ($directEvents as $de) {
                                $label = trim((string)$de['comment']);
                                if ($label !== '' && !in_array($label, $availableSpieltage)) {
                                    $availableSpieltage[] = $label;
                                    if ($currentSpieltag === '') {
                                        $currentSpieltag = $label;
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Leave defaults
            }
        }

        // Calculate user drink balance
        $currentBalance = 0;
        try {
            $currentBalance = $drinkManager->calculateUserDrinkBalance($userId, $serviceManager);
        } catch (\Exception $e) {
            // Leave default
        }

        // Check keepLoggedIn setting
        $keepLoggedInActive = false;
        try {
            $keepLoggedInVal = $dbAdapter->query(
                'SELECT meta_value FROM drink_user_meta WHERE user_id = ? AND meta_key = \'keep_logged_in_active\'',
                [$userId]
            )->current();
            $keepLoggedInActive = $keepLoggedInVal && (int)$keepLoggedInVal['meta_value'] === 1;
        } catch (\Exception $e) {
            // Leave default
        }

        // Get party_mode settings
        $partyModeEnabled = false;
        try {
            $partyModeEnabled = (string)$this->option('party_mode.enabled', '0') === '1';
        } catch (\Exception $e) {
            // Leave default
        }

        $viewModel = new ViewModel([
            'now' => new \DateTime(),
            'drinks' => $drinks,
            'drinkCategories' => $drinkCategories,
            'drinkOrders' => $drinkOrders,
            'drinkHistory' => $drinkHistory,
            'drinkStats' => $drinkStats,
            'userId' => $userId,
            'userName' => $userName,
            'drinkOrderCancelWindow' => $drinkOrderCancelWindow,
            'drinksEnabled' => $drinksEnabled,
            'moneyRecipients' => $moneyRecipients,
            'thekenadmin' => $thekenadmin,
            'isTeamAccount' => $isTeamAccount,
            'currentSpieltag' => $currentSpieltag,
            'availableSpieltage' => $availableSpieltage,
            'currentBalance' => $currentBalance,
            'keepLoggedInActive' => $keepLoggedInActive,
            'partyModeEnabled' => $partyModeEnabled,
        ]);
        $viewModel->setTemplate('drinks.phtml');
        return $viewModel;
    }

    /**
     * AJAX endpoint to add a drink booking for a user
     * POST: uid, drink_id, count
     * Returns JSON: { success: true } or { error: ... }
     */
    public function addDrinkBookingAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $admin = $userSessionManager->getSessionUser();
        if (!$admin || $admin->get('status') !== 'admin') {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['success' => false, 'error' => 'No permission']));
        }
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->getResponse()->setStatusCode(405)->setContent(json_encode(['success' => false, 'error' => 'POST required']));
        }

        // Support both JSON and form POST
        $contentType = $request->getHeaders('Content-Type');
        $isJson = false;
        if ($contentType) {
            $contentTypeStr = $contentType->toString();
            if (stripos($contentTypeStr, 'application/json') !== false) {
                $isJson = true;
            }
        }

        if ($isJson) {
            $data = json_decode($request->getContent(), true);
            $uid = isset($data['uid']) ? (int)$data['uid'] : 0;
            $orders = isset($data['orders']) && is_array($data['orders']) ? $data['orders'] : [];
            $requestedTeamEventId = isset($data['team_event_id']) ? (int)$data['team_event_id'] : 0;
            $requestedNewTeamEventLabel = isset($data['new_spieltag']) ? $data['new_spieltag'] : '';
            if (!$uid || empty($orders)) {
                return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Invalid input']));
            }
            $userManager = $serviceManager->get('User\Manager\UserManager');
            $user = $userManager->get($uid);
            if (!$user) {
                return $this->getResponse()->setStatusCode(404)->setContent(json_encode(['success' => false, 'error' => 'User not found']));
            }
            $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
            $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
            $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
            $aliasRow = $dbAdapter->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$uid])->current();
            $isTeamAccount = ($aliasRow && !empty($aliasRow['is_team'])) ? true : false;
            $teamEventId = null;
            if ($isTeamAccount) {
                $teamEvent = $this->resolveTeamEventForSelection($uid, $requestedTeamEventId, $requestedNewTeamEventLabel);
                if (!$teamEvent || empty($teamEvent['id'])) {
                    return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Bitte gueltigen Spieltag auswaehlen.']));
                }
                $teamEventId = (int)$teamEvent['id'];
            }
            try {
                foreach ($orders as $order) {
                    $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : 0;
                    $count = isset($order['count']) ? (int)$order['count'] : 1;
                    $comment = isset($order['comment']) ? $order['comment'] : null;
                    if ($drinkId && $count > 0) {
                        if (($drinkId === 1 || $drinkId === -1) && isset($order['price'])) {
                            $customPrice = (float)$order['price'];
                            $drinkOrderManager->addOrder($uid, $drinkId, $count, $admin ? $admin->get('uid') : null, 0, $comment, $customPrice, $teamEventId);
                        } else {
                            $drinkOrderManager->addOrder($uid, $drinkId, $count, $admin ? $admin->get('uid') : null, 0, $comment, null, $teamEventId);
                        }
                    }
                }
                // Send notification email to user (HTML) only if order_email_option allows
                $drinks = [];
                $total = 0;
                foreach ($orders as $order) {
                    $drink = $drinkManager->get($order['drink_id']);
                    if ($drink) {
                        if ((int)$order['drink_id'] === 1 || (int)$order['drink_id'] === -1) {
                            // Fallback to drink name if comment is empty
                            $drinkName = $drink ? $drink['name'] : $order['drink_id'];
                            $label = '';
                            if ((int)$order['count'] > 1) {
                                $label = $order['count'] . 'x ';
                            }
                            $comment = isset($order['comment']) ? trim((string)$order['comment']) : '';
                            $label .= ($comment !== '') ? $comment : $drinkName;
                            // Use custom price from order for special custom-priced entries (1, -1)
                            $customPrice = isset($order['price']) ? (float)$order['price'] : 0.0;
                            $line = sprintf('%s = %.2f EUR', $label, $order['count'] * $customPrice);
                            $total += $order['count'] * $customPrice;
                        } else {
                            $line = sprintf('%s x %d = %.2f EUR', $drink['name'], $order['count'], $order['count'] * $drink['price']);
                            $total += $order['count'] * $drink['price'];
                        }
                        $drinks[] = $line;
                    }
                }
                $drinkOrderList = implode('<br>', $drinks);
                // Calculate new balance
                $balance = $drinkManager->calculateUserDrinkBalance($uid, $serviceManager);
                // Fetch order_email_option from drink_aliases
                $aliasRow = $dbAdapter->query('SELECT order_email_option FROM drink_aliases WHERE user_id = ?', [$uid])->current();
                $orderEmailOption = $aliasRow && isset($aliasRow['order_email_option']) ? $aliasRow['order_email_option'] : null;
                $shouldSend = false;
                if ($orderEmailOption === 'order') {
                    $shouldSend = true;
                } elseif ($orderEmailOption === 'negative' && $balance <= 0) {
                    $shouldSend = true;
                }
                if ($shouldSend) {
                    $adminName = '';
                    if (isset($admin) && $admin) {
                        $adminName = $admin->get('alias') ?: $admin->get('name');
                    }
                    if (!$adminName) {
                        $adminName = 'Administrator';
                    }
                    $subject = 'Bestätigung Ihrer Getränkebuchung (' . $adminName . ')';
                    $body = 'Folgende Buchung(en) wurden von ' . htmlspecialchars($adminName) . ' für Sie hinzugefügt:<br><br>' . $drinkOrderList . '<br>---------------------<br>Gesamt: ' . number_format($total, 2, ',', '.') . ' EUR<br><br>Kontostand nach Buchung: <b>' . number_format($balance, 2, ',', '.') . ' EUR</b>';
                    if ($balance < 0) {
                        $body .= "<br><br>";
                        $body .= '<span style="color:#d32f2f;font-weight:bold;">' . call_user_func([$this, 't'], 'Warnung: Dein Kontostand ist negativ! Bitte überweise Geld auf das Paypal-Konto "kneipe@stc-butzbach.de" oder wirf Geld in den weißen Briefkasten ein.') . '</span>';
                    }
                    $mailService = $serviceManager->get('User\Service\MailService');
                    $this->sendFromTheke($mailService, $dbAdapter, $user, $subject, $body, ['isHtml' => true]);
                }
            } catch (\Exception $e) {
                return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['success' => false, 'error' => $e->getMessage()]));
            }
            return $this->getResponse()->setContent(json_encode(['success' => true]));
        } else {
            // Fallback: legacy single order POST
            $uid = (int)$this->params()->fromPost('uid');
            $drinkId = (int)$this->params()->fromPost('drink_id');
            $count = (int)$this->params()->fromPost('count', 1);
            $requestedTeamEventId = (int)$this->params()->fromPost('team_event_id', 0);
            $requestedNewTeamEventLabel = $this->params()->fromPost('new_spieltag', '');
            if (!$uid || !$drinkId || $count < 1) {
                return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Invalid input']));
            }
            $userManager = $serviceManager->get('User\Manager\UserManager');
            $user = $userManager->get($uid);
            if (!$user) {
                return $this->getResponse()->setStatusCode(404)->setContent(json_encode(['success' => false, 'error' => 'User not found']));
            }
            $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
            $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
            $aliasRow = $dbAdapter->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$uid])->current();
            $isTeamAccount = ($aliasRow && !empty($aliasRow['is_team'])) ? true : false;
            $teamEventId = null;
            if ($isTeamAccount) {
                $teamEvent = $this->resolveTeamEventForSelection($uid, $requestedTeamEventId, $requestedNewTeamEventLabel);
                if (!$teamEvent || empty($teamEvent['id'])) {
                    return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Bitte gueltigen Spieltag auswaehlen.']));
                }
                $teamEventId = (int)$teamEvent['id'];
            }
            try {
                $drinkOrderManager->addOrder($uid, $drinkId, $count, $admin ? $admin->get('uid') : null, 0, null, null, $teamEventId);
            } catch (\Exception $e) {
                return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['success' => false, 'error' => $e->getMessage()]));
            }
            return $this->getResponse()->setContent(json_encode(['success' => true]));
        }
    }

    /**
     * AJAX: Submit order for current user
     * POST: drink_counts (JSON), is_auto_order
     * Returns JSON: { success: true, balance: X } or { error: ... }
     */
    public function submitOrderAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');

        $serviceManager = @$this->getServiceLocator();

        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        if (!$user) {
            return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
        }

        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $drinkCounts = $this->params()->fromPost('drink_counts', []);
        $isAutoOrder = (int)$this->params()->fromPost('is_auto_order', 0);
        $comment = $this->params()->fromPost('comment', null);
        $result = $drinkManager->addOrdersAndNotify($user, $drinkCounts, [$this, 't'], $serviceManager, $isAutoOrder, $comment);

        if ($result['success']) {
            return $this->getResponse()->setContent(json_encode(['success' => true, 'balance' => $result['balance']]))->setStatusCode(200);
        } else {
            return $this->getResponse()->setContent(json_encode(['success' => false, 'error' => $result['error']]))->setStatusCode(400);
        }
    }

    /**
     * AJAX: Drop (cancel) an existing order
     * POST: order_id
     * Returns JSON: { success: true } or { success: false }
     */
    public function dropOrderAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');

        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        if (!$user) {
            return $this->getResponse()->setStatusCode(403);
        }
        $orderId = (int)$this->params()->fromPost('order_id');
        if (!$orderId) {
            return $this->getResponse()->setStatusCode(400);
        }
        $success = $drinkManager->dropOrderAndNotify($orderId, $user, [$this, 't'], $serviceManager);
        if ($success) {
            return $this->getResponse()->setContent(json_encode(['success' => true]))->setStatusCode(200);
        } else {
            return $this->getResponse()->setContent(json_encode(['success' => false]))->setStatusCode(404);
        }
    }

    /**
     * AJAX: Send money to another user
     * POST: receiver_user_id, amount
     * Returns JSON: { success: true } or { error: ... }
     */
    public function sendMoneyAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->getResponse()->setStatusCode(405)->setContent(json_encode(['success' => false, 'error' => 'POST required.']));
        }

        $serviceManager = $this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $sessionUser = $userSessionManager->getSessionUser();
        if (!$sessionUser) {
            return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
        }

        $senderUserId = (int)$sessionUser->need('uid');
        $receiverUserId = (int)$this->params()->fromPost('receiver_user_id', 0);
        $receiverTeamEventId = (int)$this->params()->fromPost('team_event_id', 0);
        $amountRaw = trim((string)$this->params()->fromPost('amount', ''));
        $amountRaw = str_replace(',', '.', $amountRaw);
        $amount = round((float)$amountRaw, 2);

        $transferResult = $this->executeMoneyTransfer($senderUserId, $receiverUserId, $amount, $receiverTeamEventId);
        return $this->getResponse()
            ->setStatusCode($transferResult['statusCode'])
            ->setContent(json_encode($transferResult['payload']));
    }

    /**
     * Admin: Drinks management page
     */
    public function drinksAdminAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        if (!$user || $user->get('status') !== 'admin') {
            return $this->redirect()->toRoute('user/settings');
        }
        // Provide party mode options to view (defensive retrieval)
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');
        $partyModeEnabled = false;
        $partyModeMessage = '';
        $partyModeStart = '';
        $partyModeEnd = '';
        try {
            $rawEnabled = $optionManager->get('party_mode.enabled', false);
            $partyModeEnabled = ($rawEnabled === '1' || $rawEnabled === 1 || $rawEnabled === true);
        } catch (\RuntimeException $e) {}
        try {
            $partyModeMessage = (string)$optionManager->get('party_mode.message', '');
        } catch (\RuntimeException $e) {}
        try { $partyModeStart = (string)$optionManager->get('party_mode.start', ''); } catch (\RuntimeException $e) {}
        try { $partyModeEnd = (string)$optionManager->get('party_mode.end', ''); } catch (\RuntimeException $e) {}

        // Time window enforcement
        $now = time();
        $activeWithinWindow = true;
        $startTs = null; $endTs = null;
        if ($partyModeStart && ($ts = strtotime($partyModeStart)) !== false) { $startTs = $ts; }
        if ($partyModeEnd && ($ts = strtotime($partyModeEnd)) !== false) { $endTs = $ts; }
        if ($startTs && $now < $startTs) { $activeWithinWindow = false; }
        if ($endTs && $now > $endTs) { $activeWithinWindow = false; }
        $partyModeActiveComputed = $partyModeEnabled && $activeWithinWindow;
        // For the settings page we must show the TRUE stored value of the checkbox (unfiltered by timeframe).
        // Provide both: raw stored flag (partyModeEnabled) and currently active state (partyModeActive).
        $viewModel = new ViewModel([
            'partyModeEnabled' => $partyModeEnabled,          // raw stored value for checkbox
            'partyModeActive' => $partyModeActiveComputed,    // computed active (may be false outside window)
            'partyModeMessage' => $partyModeMessage,
            'partyModeStart' => $partyModeStart,
            'partyModeEnd' => $partyModeEnd,
        ]);
        $viewModel->setTemplate('drinks-admin.phtml');
        return $viewModel;
    }

    /**
     * Admin: Manage drinks (add/edit/delete drinks and prices)
     */
    public function manageDrinksAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        if (!$user || $user->get('status') !== 'admin') {
            return $this->redirect()->toRoute('user/settings');
        }
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $drinkCategoryManager = $serviceManager->get('Drinks\Manager\DrinkCategoryManager');
        
        // Fetch all drinks (getAll returns a Traversable result set)
        $drinksRaw = $drinkManager->getAll();
        $drinks = is_array($drinksRaw) ? $drinksRaw : iterator_to_array($drinksRaw);
        
        // Fetch all categories (getAll returns an array directly)
        $drinkCategoriesRaw = $drinkCategoryManager->getAll();
        $drinkCategories = is_array($drinkCategoriesRaw) ? $drinkCategoriesRaw : iterator_to_array($drinkCategoriesRaw);
        
        $viewModel = new ViewModel([
            'drinks' => $drinks,
            'drinkCategories' => $drinkCategories,
        ]);
        $viewModel->setTemplate('drinks/manage-drinks');
        return $viewModel;
    }

    /**
     * Admin: Save party mode settings
     */
    public function savePartyModeAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->redirect()->toRoute('user/drinks-admin');
        }

        $serviceManager = @$this->getServiceLocator();
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');

        $enabled = $this->params()->fromPost('party_mode_enabled') ? '1' : '0';
        $message = trim($this->params()->fromPost('party_mode_message', ''));
        $startRaw = trim($this->params()->fromPost('party_mode_start', ''));
        $endRaw = trim($this->params()->fromPost('party_mode_end', ''));

        // sanitize message (strip all tags)
        $message = strip_tags($message);

        // Normalize and validate datetimes (allow empty). Accept formats: 'Y-m-dTH:i', 'Y-m-d H:i', optionally with :ss
        $normalize = function($val) {
            if ($val === '' || $val === null) return '';
            $val = str_replace('T', ' ', $val);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $val .= ' 00:00:00';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $val)) {
                $val .= ':00';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $val)) {
                // already fine
            } else {
                return ''; // invalid format -> drop
            }
            return $val;
        };
        $start = $normalize($startRaw);
        $end = $normalize($endRaw);

        // If both set and end before start -> swap
        if ($start && $end && strtotime($end) < strtotime($start)) {
            $tmp = $start; $start = $end; $end = $tmp;
        }

        $optionManager->set('party_mode.enabled', $enabled);
        $optionManager->set('party_mode.message', $message);
        $optionManager->set('party_mode.start', $start);
        $optionManager->set('party_mode.end', $end);

        if (method_exists($this, 'flashMessenger')) {
            $this->flashMessenger()->addSuccessMessage($this->t('Party-Mode Einstellungen gespeichert.'));
        }

        return $this->redirect()->toRoute('user/drinks-admin');
    }

    /**
     * Admin: Deposits management page
     */
    public function depositsAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        if (!$user || $user->get('status') !== 'admin') {
            return $this->redirect()->toRoute('user/settings');
        }
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $users = $userManager->getAll('alias ASC');
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $drinks = iterator_to_array($drinkManager->getAll('name ASC'));
        $message = null;
        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            if (isset($post['add_deposit'])) {
                $depositUserId = intval($post['deposit_user_id']);
                $depositAmount = floatval($post['deposit_amount']);
                $depositComment = isset($post['deposit_comment']) ? trim($post['deposit_comment']) : null;
                $requestedTeamEventId = isset($post['deposit_teamevent_id']) ? (int)$post['deposit_teamevent_id'] : 0;
                $requestedNewTeamEventLabel = isset($post['deposit_new_spieltag']) ? $post['deposit_new_spieltag'] : '';
                $createdByUserId = $user ? $user->need('uid') : null;
                if ($depositUserId > 0 && $depositAmount > 0) {
                    $teamEventId = null;
                    $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
                    $aliasRow = $dbAdapter->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$depositUserId])->current();
                    if ($aliasRow && !empty($aliasRow['is_team'])) {
                        $eventRow = $this->resolveTeamEventForSelection($depositUserId, $requestedTeamEventId, $requestedNewTeamEventLabel);
                        if (!$eventRow || empty($eventRow['id'])) {
                            $message = 'Bitte gueltigen Spieltag auswaehlen.';
                            return new \Zend\View\Model\ViewModel([
                                'users' => $users,
                                'drinks' => $drinks,
                                'message' => $message,
                            ]);
                        }
                        $teamEventId = (int)$eventRow['id'];
                    }
                    $serviceManager->get('Drinks\Manager\DrinkDepositManager')->addDeposit($depositUserId, $depositAmount, $depositComment, $createdByUserId, null, $teamEventId);
                    // Use DrinkManager for balance calculation
                    $balance = $drinkManager->calculateUserDrinkBalance($depositUserId, $serviceManager);
                    // E-Mail an den Nutzer senden
                    try {
                        $userManager = $serviceManager->get('User\Manager\UserManager');
                        $mailService = $serviceManager->get('User\Service\MailService');
                        $empfaenger = $userManager->get($depositUserId);
                        $adminAlias = $user ? $user->get('alias') : 'Admin';
                        $subject = 'Neue Einzahlung auf Ihr Getränkekonto';
                        $body =
                            '<p>Es wurde soeben eine Einzahlung auf Dein Getränkekonto vorgenommen:</p>' .
                            '<ul>' .
                            ($depositComment ? '<li><strong>Bemerkung:</strong> ' . htmlspecialchars($depositComment) . '</li>' : '') .
                            '<li><strong>Hinzugefügt von:</strong> ' . htmlspecialchars($adminAlias) . '</li>' .
                            '<li><strong>Einzahlungsbetrag:</strong> ' . number_format($depositAmount, 2, ',', '.') . ' €</li>' .
                            '<li><strong>Neuer Kontostand:</strong> ' . number_format($balance, 2, ',', '.') . ' €</li>' .
                            '</ul>' .
                            '<p>Viele Grüße<br>Dein Theken-Team</p>';
                        $this->sendFromTheke($mailService, $dbAdapter, $empfaenger, $subject, $body, ['isHtml' => true]);
                    } catch (\Exception $e) {
                        error_log('Fehler beim Senden der Einzahlungsbenachrichtigung: ' . $e->getMessage());
                    }
                    return $this->redirect()->toRoute(null, [], ['query' => ['message' => 'Deposit added.']], true);
                } else {
                    $message = 'Invalid deposit data.';
                }
            }
        }
        $viewModel = new ViewModel([
            'users' => $users,
            'drinks' => $drinks,
            'message' => $message,
        ]);
        $viewModel->setTemplate('deposits.phtml');
        return $viewModel;
    }

    /**
     * AJAX: Get user deposits data
     */
    public function getUserDepositsDataAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $admin = $userSessionManager->getSessionUser();
        if (!$admin || $admin->get('status') !== 'admin') {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['error' => 'No permission']));
        }
        $uid = (int)$this->params()->fromQuery('uid');
        if (!$uid) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['error' => 'No user selected']));
        }
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $user = $userManager->get($uid);
        if (!$user) {
            return $this->getResponse()->setStatusCode(404)->setContent(json_encode(['error' => 'User not found']));
        }
        // Query drinks_enabled and alias from drink_aliases
        $drinksAliasRow = $dbAdapter->query('SELECT enabled, alias, thekenadmin, is_team, order_email_option, teamlead_email FROM drink_aliases WHERE user_id = ?', [$uid])->current();
        $drinksEnabled = $drinksAliasRow ? (bool)$drinksAliasRow['enabled'] : false;
        $drinksAlias = $drinksAliasRow ? $drinksAliasRow['alias'] : null;
        $thekenadmin = ($drinksAliasRow && isset($drinksAliasRow['thekenadmin']) && (int)$drinksAliasRow['thekenadmin'] === 1);
        $isTeam = ($drinksAliasRow && isset($drinksAliasRow['is_team']) && (int)$drinksAliasRow['is_team'] === 1);
        $orderEmailOption = ($drinksAliasRow && isset($drinksAliasRow['order_email_option']) && $drinksAliasRow['order_email_option'] !== '') ? $drinksAliasRow['order_email_option'] : 'order';
        $teamleadEmail = ($drinksAliasRow && isset($drinksAliasRow['teamlead_email'])) ? trim((string)$drinksAliasRow['teamlead_email']) : '';
        $teamEvents = [];
        $teamEventLabelById = [];
        $latestTeamEventId = null;
        $currentTeamEventId = null;
        $preferredTeamEventId = (int)$this->params()->fromQuery('selected_teamevent_id', 0);
        if ($isTeam) {
            $teamEvents = $this->getTeamEventsWithBalances($uid, true, true);
            foreach ($teamEvents as $teamEvent) {
                $eventId = $teamEvent['id'];
                $label = $teamEvent['label'];
                if ($eventId > 0 && $label !== '') {
                    if ($latestTeamEventId === null) {
                        $latestTeamEventId = $eventId;
                    }
                    $teamEventLabelById[$eventId] = $label;
                }
            }
            if ($preferredTeamEventId > 0 && isset($teamEventLabelById[$preferredTeamEventId])) {
                $currentTeamEventId = $preferredTeamEventId;
            } else {
                $currentTeamEventId = $latestTeamEventId;
            }
        }

        // Check if showStorno is requested (from query param)
        $showStorno = $this->params()->fromQuery('showStorno') === '1';
        $orders = iterator_to_array($drinkOrderManager->getByUser($uid, $showStorno));
        $deposits = iterator_to_array($drinkDepositManager->getByUser($uid, $showStorno));
        $history = [];
        foreach ($deposits as $d) {
            $creatorName = null;
            if (!empty($d['createdbyuserid'])) {
                $creatorUser = $userManager->get($d['createdbyuserid'], false);
                if ($creatorUser) {
                    $creatorName = $creatorUser->get('alias') ?: $creatorUser->get('name');
                }
            }
            $history[] = [
                'type' => 'Einzahlung',
                'id' => isset($d['id']) ? (int)$d['id'] : null,
                'amount' => $d['amount'],
                'desc' => $d['comment'],
                'datetime' => $d['deposit_time'],
                'deleted' => isset($d['deleted']) ? (int)$d['deleted'] : 0,
                'createdby' => $creatorName,
                'teamevent_id' => isset($d['teamevent_id']) ? (int)$d['teamevent_id'] : 0,
                'spieltag_label' => (
                    isset($d['teamevent_id'])
                    && (int)$d['teamevent_id'] > 0
                    && isset($teamEventLabelById[(int)$d['teamevent_id']])
                ) ? $teamEventLabelById[(int)$d['teamevent_id']] : '',
            ];
        }
        foreach ($orders as $o) {
            $drinkId = isset($o['drink_id']) ? (int)$o['drink_id'] : null;
            $comment = isset($o['comment']) ? trim((string)$o['comment']) : '';
            $drinkName = isset($o['name']) ? $o['name'] : '';
            $desc = $o['quantity'] . ' x ' . $drinkName;
            // For special comment-based entries (1, -1), use drink name as fallback for desc and comment
            if (($drinkId === 1 || $drinkId === -1) && $comment === '') {
                $desc = $drinkName;
                $comment = $drinkName;
            }
            $history[] = [
                'type' => empty($o['deleted']) ? 'Buchung' : 'Storno',
                'id' => isset($o['id']) ? (int)$o['id'] : null,
                'amount' => -1 * $o['quantity'] * $o['price'],
                'desc' => $desc,
                'datetime' => $o['order_time'],
                'deleted' => empty($o['deleted']) ? 0 : 1,
                'comment' => $comment,
                'drink_id' => $drinkId,
                'quantity' => isset($o['quantity']) ? (int)$o['quantity'] : null,
                'teamevent_id' => isset($o['teamevent_id']) ? (int)$o['teamevent_id'] : 0,
                'spieltag_label' => (
                    isset($o['teamevent_id'])
                    && (int)$o['teamevent_id'] > 0
                    && isset($teamEventLabelById[(int)$o['teamevent_id']])
                ) ? $teamEventLabelById[(int)$o['teamevent_id']] : '',
            ];
        }
        usort($history, function($a, $b) { return strcmp($a['datetime'], $b['datetime']); });
        // Group by day and calculate running balance
        $days = [];
        $balance = 0;
        foreach ($history as $entry) {
            $date = substr($entry['datetime'], 0, 10);
            if (empty($entry['deleted'])) {
                $balance += $entry['amount'];
                $entry['balance'] = $balance;
            }
            if (!isset($days[$date])) $days[$date] = [];
            $days[$date][] = $entry;
        }
        $userBalance = $balance;
        $userHistory = [];
        foreach ($days as $date => $entries) {
            $userHistory[] = [
                'date' => $date,
                'entries' => $entries,
            ];
        }
        return $this->getResponse()->setContent(json_encode([
            'balance' => $userBalance,
            'history' => $userHistory,
            'drinks_enabled' => $drinksEnabled,
            'drinks_alias' => $drinksAlias,
            'is_team' => $isTeam,
            'order_email_option' => $orderEmailOption,
            'teamlead_email' => $teamleadEmail,
            'team_events' => $teamEvents,
            'current_teamevent_id' => $currentTeamEventId,
        ]));
    }

    /**
     * AJAX: Get user team event stats data
     */
    public function getUserTeamEventStatsDataAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');

        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $admin = $userSessionManager->getSessionUser();
        if (!$admin || $admin->get('status') !== 'admin') {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['success' => false, 'error' => 'No permission']));
        }

        $uid = (int)$this->params()->fromQuery('uid', 0);
        $teamEventId = (int)$this->params()->fromQuery('team_event_id', 0);
        if ($uid <= 0) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'No user selected']));
        }

        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $aliasRow = $dbAdapter->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$uid])->current();
        $isTeam = ($aliasRow && isset($aliasRow['is_team']) && (int)$aliasRow['is_team'] === 1);
        if (!$isTeam) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Selected user is not a team account']));
        }

        $teamEvents = $this->getTeamEventsWithBalances($uid, true, true);
        if (empty($teamEvents)) {
            return $this->getResponse()->setContent(json_encode([
                'success' => true,
                'team_uid' => $uid,
                'team_events' => [],
                'team_event_id' => 0,
                'spieltag' => '',
                'rows' => [],
                'members' => [],
                'total_sum' => 0.0,
                'settlement_total_sum' => 0.0,
            ]));
        }

        $selectedTeamEvent = null;
        foreach ($teamEvents as $eventRow) {
            if ((int)$eventRow['id'] === $teamEventId) {
                $selectedTeamEvent = $eventRow;
                break;
            }
        }
        if (!$selectedTeamEvent) {
            $selectedTeamEvent = $teamEvents[0];
            $teamEventId = (int)$selectedTeamEvent['id'];
        }

        $teamEventLabel = isset($selectedTeamEvent['label']) ? trim((string)$selectedTeamEvent['label']) : '';
        if ($teamEventLabel === '') {
            return $this->getResponse()->setStatusCode(404)->setContent(json_encode(['success' => false, 'error' => 'Team event not found']));
        }

        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $accountBalance = (float)$drinkManager->calculateUserDrinkBalance($uid, $serviceManager);

        return $this->getResponse()->setContent(json_encode(array_merge([
            'success' => true,
            'team_uid' => $uid,
            'team_event_id' => $teamEventId,
            'team_events' => $teamEvents,
            'account_balance' => $accountBalance,
        ], $this->buildTeamStatsPayload($uid, $teamEventLabel))));
    }

    /**
     * AJAX: Get teamlead team stats (GET endpoint for team stats modal)
     */
    public function teamleadTeamStatsAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');

        $serviceManager = @$this->getServiceLocator();
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');

        $teamUid = (int)$this->params()->fromQuery('team_uid', 0);
        if ($teamUid <= 0) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'No team_uid provided']));
        }

        // Check if team account
        $aliasRow = $dbAdapter->query('SELECT is_team, alias FROM drink_aliases WHERE user_id = ?', [$teamUid])->current();
        if (!$aliasRow || empty($aliasRow['is_team'])) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Not a team account']));
        }

        // Get team events - look for events where this user is the admin OR where this user is a member
        $teamEvents = [];
        try {
            $hasClosedColumn = false;
            try {
                $closedCol = $dbAdapter->query("SHOW COLUMNS FROM drinks_teamevents LIKE 'closed'", [])->current();
                $hasClosedColumn = (bool)$closedCol;
            } catch (\Throwable $e) {
                // Column doesn't exist
            }

            // First try: events where this user is the team admin
            $adminEvents = [];
            if ($hasClosedColumn) {
                $adminEventRows = $dbAdapter->query(
                    'SELECT id, comment, closed FROM drinks_teamevents WHERE team_admin_user_id = ? ORDER BY id DESC',
                    [$teamUid]
                )->toArray();
            } else {
                $adminEventRows = $dbAdapter->query(
                    'SELECT id, comment FROM drinks_teamevents WHERE team_admin_user_id = ? ORDER BY id DESC',
                    [$teamUid]
                )->toArray();
            }
            foreach ($adminEventRows as $row) {
                $adminEvents[] = (int)$row['id'];
            }

            // Second: events where this user is a member (for shared team events)
            $memberEventIds = [];
            try {
                $memberEventRows = $dbAdapter->query(
                    'SELECT DISTINCT team_event_id FROM drinks_teamevent_members WHERE user_id = ? ORDER BY team_event_id DESC',
                    [$teamUid]
                )->toArray();
                foreach ($memberEventRows as $mer) {
                    $eid = (int)$mer['team_event_id'];
                    if ($eid > 0 && !in_array($eid, $adminEvents)) {
                        $memberEventIds[] = $eid;
                    }
                }
            } catch (\Throwable $e) {
                // Table might not exist yet
            }

            // Combine all event IDs
            $allEventIds = array_merge($adminEvents, $memberEventIds);
            if (!empty($allEventIds)) {
                $placeholders = implode(', ', array_fill(0, count($allEventIds), '?'));
                $eventSql = 'SELECT id, comment';
                if ($hasClosedColumn) {
                    $eventSql .= ', closed';
                }
                $eventSql .= ' FROM drinks_teamevents WHERE id IN (' . $placeholders . ') ORDER BY id DESC';
                
                $finalEventRows = $dbAdapter->query($eventSql, $allEventIds)->toArray();
                foreach ($finalEventRows as $row) {
                    $eventId = isset($row['id']) ? (int)$row['id'] : 0;
                    $eventLabel = isset($row['comment']) ? trim((string)$row['comment']) : '';
                    if ($eventId <= 0 || $eventLabel === '') continue;
                    $eventClosed = $hasClosedColumn && isset($row['closed']) ? (int)$row['closed'] : 0;
                    $teamEvents[] = [
                        'id' => $eventId,
                        'label' => $eventLabel,
                        'balance' => 0.0,
                        'closed' => $eventClosed,
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('teamleadTeamStatsAction error: ' . $e->getMessage());
            $teamEvents = [];
        }

        // Get members from selected event or all events
        $members = [];
        try {
            $eventIdsForMemberQuery = !empty($teamEvents) ? array_map(function($e) { return $e['id']; }, $teamEvents) : [0];
            $memberRows = $dbAdapter->query(
                'SELECT DISTINCT tm.user_id, u.alias, u.name, u.email
                 FROM drinks_teamevent_members tm
                 INNER JOIN bs_users u ON u.uid = tm.user_id
                 WHERE tm.team_event_id IN (' . implode(',', $eventIdsForMemberQuery) . ')
                 ORDER BY u.alias ASC',
                []
            )->toArray();
            foreach ($memberRows as $mr) {
                $members[] = [
                    'user_id' => (int)$mr['user_id'],
                    'alias' => $mr['alias'],
                    'name' => $mr['name'],
                    'email' => $mr['email'],
                ];
            }
        } catch (\Throwable $e) {
            $members = [];
        }

        // Get selected team event stats - use spieltag query param if provided
        $selectedSpieltag = trim((string)$this->params()->fromQuery('spieltag', ''));
        $selectedEventId = isset($teamEvents[0]['id']) ? $teamEvents[0]['id'] : 0;
        $selectedEventLabel = isset($teamEvents[0]['label']) ? $teamEvents[0]['label'] : '';
        if ($selectedSpieltag !== '' && !empty($teamEvents)) {
            foreach ($teamEvents as $event) {
                if (trim((string)$event['label']) === $selectedSpieltag) {
                    $selectedEventId = (int)$event['id'];
                    $selectedEventLabel = trim((string)$event['label']);
                    break;
                }
            }
        }
        $accountBalance = (float)$drinkManager->calculateUserDrinkBalance($teamUid, $serviceManager);

        $rows = [];
        $totalSum = 0.0;
        $settlementTotalSum = 0.0;

        if ($selectedEventId > 0 && $selectedEventLabel !== '') {
            try {
                $stats = $this->buildTeamStatsPayload($teamUid, $selectedEventLabel);
                if (isset($stats['rows'])) {
                    $rows = $stats['rows'];
                    $totalSum = (float)$stats['total_sum'];
                    $settlementTotalSum = (float)$stats['settlement_total_sum'];
                }
            } catch (\Throwable $e) {
                error_log('buildTeamStatsPayload error: ' . $e->getMessage());
                // Leave empty
            }
        }

        // Build spieltage (all events) and open_spieltage (only open events) for the dropdown
        $spieltage = [];
        $openSpieltage = [];
        foreach ($teamEvents as $event) {
            $label = $event['label'];
            $spieltage[] = $label;
            if (empty($event['closed'])) {
                $openSpieltage[] = $label;
            }
        }
        
        return $this->getResponse()->setContent(json_encode([
            'success' => true,
            'team_uid' => $teamUid,
            'team_events' => $teamEvents,
            'team_event_id' => $selectedEventId,
            'spieltag' => $selectedEventLabel,
            'spieltage' => $spieltage,
            'open_spieltage' => $openSpieltage,
            'rows' => $rows,
            'members' => $members,
            'account_balance' => $accountBalance,
            'total_sum' => $totalSum,
            'settlement_total_sum' => $settlementTotalSum,
        ]));
    }

    /**
     * AJAX: Get money recipient team events
     */
    public function moneyRecipientTeamEventsAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        try {
            $serviceManager = @$this->getServiceLocator();
            if (!is_object($serviceManager) || !method_exists($serviceManager, 'get')) {
                return $this->getResponse()->setContent(json_encode([
                    'success' => true,
                    'is_team' => false,
                    'team_events' => [],
                ]));
            }

            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            $sessionUser = $userSessionManager->getSessionUser();

            if (!$sessionUser) {
                $sessionManager = $serviceManager->get('Zend\Session\SessionManager');
                $sessionManager->start();
                $simpleSession = new \Zend\Session\Container('SimpleLogin');
                if (empty($simpleSession->user_id)) {
                    return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
                }
            }

            $receiverUserId = (int)$this->params()->fromQuery('receiver_user_id', $this->params()->fromPost('receiver_user_id', 0));
            if ($receiverUserId <= 0) {
                return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Empfänger fehlt.']));
            }

            $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
            $aliasRow = $dbAdapter->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$receiverUserId])->current();
            $isTeam = ($aliasRow && !empty($aliasRow['is_team'])) ? true : false;
            if (!$isTeam) {
                return $this->getResponse()->setContent(json_encode([
                    'success' => true,
                    'is_team' => false,
                    'team_events' => [],
                ]));
            }

            $teamEvents = [];
            try {
                // Check if closed column exists
                $hasClosedColumn = false;
                try {
                    $closedCol = $dbAdapter->query("SHOW COLUMNS FROM drinks_teamevents LIKE 'closed'", [])->current();
                    $hasClosedColumn = (bool)$closedCol;
                } catch (\Throwable $e) {
                    // Column doesn't exist
                }

                if ($hasClosedColumn) {
                    $rows = $dbAdapter->query(
                        'SELECT id, comment, closed FROM drinks_teamevents WHERE team_admin_user_id = ? AND (closed IS NULL OR closed = 0) ORDER BY id DESC',
                        [$receiverUserId]
                    )->toArray();
                } else {
                    $rows = $dbAdapter->query(
                        'SELECT id, comment FROM drinks_teamevents WHERE team_admin_user_id = ? ORDER BY id DESC',
                        [$receiverUserId]
                    )->toArray();
                }

                foreach ($rows as $row) {
                    $eventId = isset($row['id']) ? (int)$row['id'] : 0;
                    $eventLabel = isset($row['comment']) ? trim((string)$row['comment']) : '';
                    if ($eventId <= 0 || $eventLabel === '') {
                        continue;
                    }
                    $eventClosed = $hasClosedColumn && isset($row['closed']) ? (int)$row['closed'] : 0;
                    $teamEvents[] = [
                        'id' => $eventId,
                        'label' => $eventLabel,
                        'balance' => 0.0,
                        'closed' => $eventClosed,
                    ];
                }
            } catch (\Throwable $e) {
                $teamEvents = [];
            }

            return $this->getResponse()->setContent(json_encode([
                'success' => true,
                'is_team' => true,
                'team_events' => is_array($teamEvents) ? $teamEvents : [],
            ]));
        } catch (\Throwable $e) {
            return $this->getResponse()->setContent(json_encode([
                'success' => true,
                'is_team' => false,
                'team_events' => [],
            ]));
        }
    }

    /**
     * AJAX: Store a new drink check event in drink_checks
     */
    public function storeCheckDateAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $request = $this->getRequest();
        if ($request->isPost()) {
            $data = json_decode($request->getContent(), true);
            $datetime = isset($data['datetime']) ? $data['datetime'] : null;
            if ($datetime) {
                $serviceManager = $this->getServiceLocator();
                $db = $serviceManager->get('Zend\Db\Adapter\Adapter');
                $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
                $user = $userSessionManager->getSessionUser();
                $userId = null;
                if ($user) {
                    $userId = $user->need('uid');
                } elseif (class_exists('Zend\Session\Container')) {
                    $simpleSession = new \Zend\Session\Container('SimpleLogin');
                    if (!empty($simpleSession->user_id)) {
                        $userId = (int)$simpleSession->user_id;
                    }
                }
                try {
                    $dt = str_replace('T', ' ', $datetime);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                        $dt .= ' 00:00:00';
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dt)) {
                        $dt .= ':00';
                    }
                    $db->query('INSERT INTO drink_checks (user_id, check_time) VALUES (?, ?)', [$userId, $dt]);
                    echo json_encode(['success' => true]);
                    return $this->getResponse();
                } catch (\Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'DB error']);
                    return $this->getResponse()->setStatusCode(500);
                }
            }
        }
        echo json_encode(['success' => false]);
        return $this->getResponse();
    }

    /**
     * Admin: Drinks summary page
     */
    public function drinksSummaryAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        $isSimple = false;
        $thekenadmin = false;
        $simpleSession = null;
        if (!$user || $user->get('status') !== 'admin') {
            if (class_exists('Zend\Session\Container')) {
                $simpleSession = new \Zend\Session\Container('SimpleLogin');
                if (!empty($simpleSession->user_id)) {
                    $isSimple = true;
                    $db = $serviceManager->get('Zend\Db\Adapter\Adapter');
                    $row = $db->query('SELECT thekenadmin FROM drink_aliases WHERE user_id = ?', [$simpleSession->user_id])->current();
                    $thekenadmin = ($row && isset($row['thekenadmin']) && (int)$row['thekenadmin'] === 1) ? true : false;
                }
            }
            if (!$isSimple || !$thekenadmin) {
                return $this->redirect()->toRoute('user/settings');
            }
        }
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $drinks = iterator_to_array($drinkManager->getAll());
        $users = $userManager->getAll('alias ASC');

        $group = $this->params()->fromQuery('group', 'date');
        $quick = $this->params()->fromQuery('quick');
        $from = $this->params()->fromQuery('from');
        $to = $this->params()->fromQuery('to');
        $showUsers = $this->params()->fromQuery('show_users', '1');
        $showEmptyCols = $this->params()->fromQuery('show_emptycols', '0');

        $lastCheckDate = null;
        $lastCheckUserName = null;
        $lastUserCheckDate = null;

        $gf = function($row, $key) {
            if (!$row) return null;
            if (is_array($row)) return array_key_exists($key, $row) ? $row[$key] : null;
            if ($row instanceof \ArrayAccess && isset($row[$key])) return $row[$key];
            if (is_object($row) && isset($row->$key)) return $row->$key;
            $tmp = (array)$row;
            return array_key_exists($key, $tmp) ? $tmp[$key] : null;
        };

        try {
            $row = $dbAdapter->query(
                'SELECT dc.user_id, dc.check_time, u.alias AS user_alias, da.alias AS drink_alias
                 FROM drink_checks dc
                 LEFT JOIN bs_users u ON u.uid = dc.user_id
                 LEFT JOIN drink_aliases da ON da.user_id = dc.user_id
                 ORDER BY dc.check_time DESC LIMIT 1', []
            )->current();
            if ($row) {
                $lastCheckDate = $gf($row, 'check_time');
                $uid = $gf($row, 'user_id');
                $lastCheckUserName = $gf($row, 'user_alias') ?: $gf($row, 'drink_alias');
                if (!$lastCheckUserName && $uid) {
                    $fallbackAliasRow = $dbAdapter->query('SELECT alias FROM drink_aliases WHERE user_id = ? LIMIT 1', [$uid])->current();
                    $fa = $gf($fallbackAliasRow, 'alias');
                    $lastCheckUserName = $fa ?: ('UID ' . $uid);
                }
            }
        } catch (\Exception $e) { /* ignore */ }

        $actorUid = null;
        if ($user) {
            $actorUid = $user->need('uid');
        } elseif ($isSimple && !empty($simpleSession->user_id)) {
            $actorUid = (int)$simpleSession->user_id;
        }
        if ($actorUid) {
            try {
                $rowMy = $dbAdapter->query('SELECT check_time FROM drink_checks WHERE user_id = ? ORDER BY check_time DESC LIMIT 1', [$actorUid])->current();
                $lastUserCheckDate = $gf($rowMy, 'check_time');
            } catch (\Exception $e) { /* ignore */ }
        }

        $normalize = function($dt) {
            if (!$dt) return null;
            $raw = trim($dt);
            $raw = str_replace('T', ' ', $raw);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                $raw .= ' 00:00:00';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
                $raw .= ':00';
            }
            return $raw;
        };
        $formatMinutesT = function($dt) use ($normalize) {
            $n = $normalize($dt);
            if (!$n) return null;
            return str_replace(' ', 'T', substr($n, 0, 16));
        };
        $lastCheckNormalized = $normalize($lastCheckDate);
        $lastUserCheckNormalized = $normalize($lastUserCheckDate);
        $lastCheckDisplay = $formatMinutesT($lastCheckNormalized);
        $lastUserCheckDisplay = $formatMinutesT($lastUserCheckNormalized);

        $relativeAge = function($dt) {
            if (!$dt) return null;
            try {
                $now = new \DateTime();
                $base = new \DateTime($dt);
            } catch (\Exception $e) { return null; }
            $diff = $now->getTimestamp() - $base->getTimestamp();
            if ($diff < 0) $diff = 0;
            $seconds = $diff;
            $minutes = (int) floor($seconds / 60);
            $hours = (int) floor($minutes / 60);
            $days = (int) floor($hours / 24);
            $minutesR = $minutes % 60;
            $hoursR = $hours % 24;
            if ($days > 0) {
                if ($days <= 14) {
                    $s = $days . 'd';
                    if ($hoursR > 0) $s .= ' ' . $hoursR . 'h';
                    return $s;
                }
                return $days . 'd';
            }
            if ($hours > 0) {
                $s = $hours . 'h';
                if ($minutesR > 0) $s .= ' ' . $minutesR . 'm';
                return $s;
            }
            if ($minutes > 0) {
                $s = $minutes . 'm';
                $secondsR = $seconds % 60;
                if ($minutes < 5 && $secondsR > 0) $s .= ' ' . $secondsR . 's';
                return $s;
            }
            return '0m';
        };

        $lastCheckRelative = $relativeAge($lastCheckNormalized);
        $lastUserCheckRelative = $relativeAge($lastUserCheckNormalized);

        if ($quick) {
            $now = new \DateTime();
            $todayStr = $now->format('Y-m-d');
            if ($quick === 'sinceLastCheck') {
                if (empty($from) && $lastCheckNormalized) {
                    $from = $lastCheckNormalized;
                }
            } elseif ($quick === 'sinceMyLastCheck') {
                $lastMyNorm = $normalize($lastUserCheckDate);
                if (empty($from) && $lastMyNorm) {
                    $from = $lastMyNorm;
                }
            } elseif ($quick === 'cw' || $quick === 'lw') {
                $monday = clone $now;
                $dow = (int)$monday->format('N');
                $monday->modify('-' . ($dow - 1) . ' days');
                if ($quick === 'lw') $monday->modify('-7 days');
                $sunday = clone $monday; $sunday->modify('+6 days');
                if (empty($from)) $from = $monday->format('Y-m-d') . ' 00:00:00';
                if (empty($to)) $to = $sunday->format('Y-m-d') . ' 23:59:59';
            } elseif ($quick === 'cm' || $quick === 'lm') {
                $year = (int)$now->format('Y');
                $month = (int)$now->format('n');
                if ($quick === 'lm') {
                    $month -= 1; if ($month === 0) { $month = 12; $year -= 1; }
                }
                $first = new \DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
                $last = clone $first; $last->modify('+1 month -1 second');
                if (empty($from)) $from = $first->format('Y-m-d H:i:s');
                if (empty($to)) $to = $last->format('Y-m-d H:i:s');
            } elseif ($quick === 'cy' || $quick === 'ly') {
                $year = (int)$now->format('Y');
                if ($quick === 'ly') $year -= 1;
                if (empty($from)) $from = sprintf('%04d-01-01 00:00:00', $year);
                if (empty($to)) $to = sprintf('%04d-12-31 23:59:59', $year);
            }
        }

        $from = $normalize($from);
        $to = $normalize($to);

        $groupSql = 'DATE(order_time)';
        $labelFormat = 'Y-m-d';
        if ($group === 'week') {
            $groupSql = 'YEAR(order_time), WEEK(order_time, 1)';
            $labelFormat = 'o-\KWW';
        } elseif ($group === 'month') {
            $groupSql = 'YEAR(order_time), MONTH(order_time)';
            $labelFormat = 'Y-m';
        } elseif ($group === 'year') {
            $groupSql = 'YEAR(order_time)';
            $labelFormat = 'Y';
        }
        $sql = 'SELECT ' . $groupSql . ' as grp, user_id, drink_id, SUM(quantity) as quantity, MIN(order_time) as min_time, SUM(quantity * price) as total_amount
                FROM drink_orders
                WHERE deleted = 0
                  AND drink_id <> -1';
        $params = [];
        if ($from) {
            $sql .= ' AND order_time >= ?';
            $params[] = $from;
        }
        if ($to) {
            $sql .= ' AND order_time <= ?';
            $params[] = $to;
        }
        $sql .= ' GROUP BY grp, user_id, drink_id
                ORDER BY min_time ASC, user_id ASC, drink_id ASC';
        $statement = $dbAdapter->query($sql);
        $orders = [];
        foreach ($statement->execute($params) as $row) {
            $orders[] = $row;
        }
        $orderMap = [];
        $drinkPriceMap = [];
        foreach ($drinks as $drink) {
            $drinkPriceMap[$drink['id']] = (float)$drink['price'];
        }
        foreach ($orders as $row) {
            $grp = $row['grp'];
            if ($group === 'week') {
                $dt = new \DateTime($row['min_time']);
                $grp = $dt->format('o') . '-KW' . $dt->format('W');
            } elseif ($group === 'month') {
                $dt = new \DateTime($row['min_time']);
                $grp = $dt->format('Y-m');
            } elseif ($group === 'year') {
                $dt = new \DateTime($row['min_time']);
                $grp = $dt->format('Y');
            }
            $uid = $row['user_id'];
            $did = $row['drink_id'];
            $count = (int)$row['quantity'];
            $amount = isset($row['total_amount']) ? (float)$row['total_amount'] : 0;
            $orderMap[$grp][$uid][$did] = [
                'count' => $count > 0 ? $count : '',
                'amount' => $count > 0 ? number_format($amount, 2, ',', '.') : '',
            ];
        }

        $viewVars = [
            'drinks' => $drinks,
            'users' => $users,
            'orders' => $orderMap,
            'mode' => $this->params()->fromQuery('mode', 'count'),
            'from' => $from,
            'to' => $to,
            'quick' => $quick,
            'show_users' => $showUsers,
            'show_emptycols' => $showEmptyCols,
            'group' => $group,
            'lastCheckDate' => $lastCheckDate,
            'lastCheckUserName' => $lastCheckUserName,
            'lastUserCheckDate' => $lastUserCheckDate,
            'lastCheckDateDisplay' => $lastCheckDisplay,
            'lastUserCheckDateDisplay' => $lastUserCheckDisplay,
            'lastCheckRelative' => $lastCheckRelative,
            'lastUserCheckRelative' => $lastUserCheckRelative,
        ];
        if ($isSimple && $thekenadmin) {
            $viewVars['simpleOrderMode'] = true;
        }
        $viewModel = new ViewModel($viewVars);
        $viewModel->setTemplate('drinks-summary.phtml');
        return $viewModel;
    }

    /**
     * Admin: Balance list - overview of all users with balances
     */
    public function balanceListAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        if (!$user || $user->get('status') !== 'admin') {
            return $this->redirect()->toRoute('user/settings');
        }
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');

        $users = $userManager->getAll('alias ASC');
        $userList = [];
        foreach ($users as $u) {
            $uid = $u->get('uid');
            $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
            $balance = $drinkManager->calculateUserDrinkBalance($uid, $serviceManager);
            $deposits = iterator_to_array($drinkDepositManager->getByUser($uid, true));
            $lastDeposit = null;
            foreach ($deposits as $d) {
                if (empty($d['deleted'])) {
                    if (!$lastDeposit || (isset($d['deposit_time']) && $d['deposit_time'] > $lastDeposit)) {
                        $lastDeposit = $d['deposit_time'];
                    }
                }
            }
            $orders = iterator_to_array($drinkOrderManager->getByUser($uid));
            $lastOrder = null;
            $ordersTotal = 0.0;
            foreach ($orders as $o) {
                if (empty($o['deleted'])) {
                    if ((int)($o['drink_id'] ?? 0) === -1) {
                        continue;
                    }
                    if (!$lastOrder || (isset($o['order_time']) && $o['order_time'] > $lastOrder)) {
                        $lastOrder = $o['order_time'];
                    }
                    if (isset($o['price'])) {
                        $qty = isset($o['quantity']) ? (float)$o['quantity'] : 1;
                        $ordersTotal += ((float)$o['price']) * $qty;
                    }
                }
            }
            $lastActivity = null;
            if ($lastDeposit && $lastOrder) {
                $lastActivity = max($lastDeposit, $lastOrder);
            } elseif ($lastDeposit) {
                $lastActivity = $lastDeposit;
            } elseif ($lastOrder) {
                $lastActivity = $lastOrder;
            }
            if (count($deposits) > 0 || count($orders) > 0) {
                $userList[] = [
                    'uid' => $u->get('uid'),
                    'alias' => $u->get('alias'),
                    'name' => $u->get('name'),
                    'email' => $u->get('email'),
                    'balance' => $balance,
                    'last_activity' => $lastActivity,
                    'orders_total' => $ordersTotal,
                ];
            }
        }

        usort($userList, function($a, $b) {
            return $a['balance'] <=> $b['balance'];
        });

        $totalBalance = 0;
        foreach ($userList as $user) {
            $totalBalance += $user['balance'];
        }

        $viewModel = new ViewModel([
            'users' => $userList,
            'total_balance' => $totalBalance,
        ]);
        $viewModel->setTemplate('balance-list.phtml');
        return $viewModel;
    }

    /**
     * Toggle deleted status of deposit or order entries
     */
    public function toggleDepositOrderDeletedAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $admin = $userSessionManager->getSessionUser();
        if (!$admin || $admin->get('status') !== 'admin') {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['success' => false, 'error' => 'No permission']));
        }
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->getResponse()->setStatusCode(405)->setContent(json_encode(['success' => false, 'error' => 'POST required']));
        }
        $entryId = $this->params()->fromPost('entry_id');
        $entryType = $this->params()->fromPost('entry_type');
        if (!$entryId || !$entryType) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'No entry_id or entry_type']));
        }
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $canUseTransferReference = $this->canUseTransferReferenceColumns($dbAdapter);
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $mailService = $serviceManager->get('User\Service\MailService');
        if ($entryType === 'deposit') {
            $row = $dbAdapter->query('SELECT * FROM drink_deposits WHERE id = ?', [$entryId])->current();
            if ($row) {
                $newDeleted = empty($row['deleted']) ? 1 : 0;
                $transferReference = ($canUseTransferReference && isset($row['transfer_reference'])) ? trim((string)$row['transfer_reference']) : '';
                if ($newDeleted) {
                    $dbAdapter->query('UPDATE drink_deposits SET deleted = 1, user_id_deleted = ? WHERE id = ?', [$admin->get('uid'), $entryId]);
                    if ($transferReference !== '') {
                        $dbAdapter->query('UPDATE drink_orders SET deleted = 1, user_id_deleted = ? WHERE transfer_reference = ?', [$admin->get('uid'), $transferReference]);
                    }
                } else {
                    $dbAdapter->query('UPDATE drink_deposits SET deleted = 0, user_id_deleted = NULL WHERE id = ?', [$entryId]);
                    if ($transferReference !== '') {
                        $dbAdapter->query('UPDATE drink_orders SET deleted = 0, user_id_deleted = NULL WHERE transfer_reference = ?', [$transferReference]);
                    }
                }
                $action = $newDeleted ? 'storniert' : 'wiederhergestellt';
                $adminName = ($admin->get('alias') ?: $admin->get('name')) ?: 'Administrator';
                $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
                $user = $userManager->get($row['user_id']);
                if ($user) {
                    $balance = $drinkManager->calculateUserDrinkBalance($row['user_id'], $serviceManager);
                    $isTransferDeposit = ($transferReference !== '');
                    $subject = $isTransferDeposit ? ('Geldüberweisung ' . ucfirst($action)) : ('Einzahlung ' . ucfirst($action));
                    $body = $isTransferDeposit
                        ? ('Eine Geldüberweisung auf Ihr Konto wurde von ' . htmlspecialchars($adminName) . ' ' . $action . '.<br><br>Kontostand nach Änderung: <b>' . number_format($balance, 2, ',', '.') . ' EUR</b>')
                        : ('Ihre Einzahlung am ' . $row['deposit_time'] . ' wurde von ' . htmlspecialchars($adminName) . ' ' . $action . '.<br><br>Kontostand nach Änderung: <b>' . number_format($balance, 2, ',', '.') . ' EUR</b>');
                    $this->sendFromTheke($mailService, $dbAdapter, $user, $subject, $body, ['isHtml' => true]);
                }
                if ($transferReference !== '') {
                    $counterRow = $dbAdapter->query('SELECT * FROM drink_orders WHERE transfer_reference = ? LIMIT 1', [$transferReference])->current();
                    if ($counterRow && (int)$counterRow['user_id'] !== (int)$row['user_id']) {
                        $counterUser = $userManager->get($counterRow['user_id']);
                        if ($counterUser) {
                            $counterBalance = $drinkManager->calculateUserDrinkBalance($counterRow['user_id'], $serviceManager);
                            $counterSubject = 'Geldüberweisung ' . ucfirst($action);
                            $counterBody = 'Eine Geldüberweisung, die Ihr Konto betrifft, wurde von ' . htmlspecialchars($adminName) . ' ' . $action . '.<br><br>Kontostand nach Änderung: <b>' . number_format($counterBalance, 2, ',', '.') . ' EUR</b>';
                            $this->sendFromTheke($mailService, $dbAdapter, $counterUser, $counterSubject, $counterBody, ['isHtml' => true]);
                        }
                    }
                }
                return $this->getResponse()->setContent(json_encode(['success' => true]));
            }
        } elseif ($entryType === 'order') {
            $row = $dbAdapter->query('SELECT * FROM drink_orders WHERE id = ?', [$entryId])->current();
            if ($row) {
                $newDeleted = empty($row['deleted']) ? 1 : 0;
                $transferReference = ($canUseTransferReference && isset($row['transfer_reference'])) ? trim((string)$row['transfer_reference']) : '';
                if ($newDeleted) {
                    $dbAdapter->query('UPDATE drink_orders SET deleted = 1, user_id_deleted = ? WHERE id = ?', [$admin->get('uid'), $entryId]);
                    if ($transferReference !== '') {
                        $dbAdapter->query('UPDATE drink_deposits SET deleted = 1, user_id_deleted = ? WHERE transfer_reference = ?', [$admin->get('uid'), $transferReference]);
                    }
                } else {
                    $dbAdapter->query('UPDATE drink_orders SET deleted = 0, user_id_deleted = NULL WHERE id = ?', [$entryId]);
                    if ($transferReference !== '') {
                        $dbAdapter->query('UPDATE drink_deposits SET deleted = 0, user_id_deleted = NULL WHERE transfer_reference = ?', [$transferReference]);
                    }
                }
                $user = $userManager->get($row['user_id']);
                if ($user) {
                    $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
                    $balance = $drinkManager->calculateUserDrinkBalance($row['user_id'], $serviceManager);
                    $aliasRow = $dbAdapter->query('SELECT order_email_option FROM drink_aliases WHERE user_id = ?', [$row['user_id']])->current();
                    $orderEmailOption = $aliasRow && isset($aliasRow['order_email_option']) ? $aliasRow['order_email_option'] : null;
                    $shouldSend = false;
                    if ($orderEmailOption === 'order') {
                        $shouldSend = true;
                    } elseif ($orderEmailOption === 'negative' && $balance <= 0) {
                        $shouldSend = true;
                    }
                    if ($shouldSend) {
                        $action = $newDeleted ? 'storniert' : 'wiederhergestellt';
                        $isTransferOrder = ($transferReference !== '') || ((int)$row['drink_id'] === -1);
                        $subject = $isTransferOrder ? ('Geldüberweisung ' . ucfirst($action)) : ('Buchung ' . ucfirst($action) . ' (Admin)');
                        $label = '';
                        if ((int)$row['drink_id'] === 1 || (int)$row['drink_id'] === -1) {
                            $drinkName = '';
                            if (isset($row['drink_id'])) {
                                $drinkRow = $dbAdapter->query('SELECT name FROM drinks WHERE id = ?', [$row['drink_id']])->current();
                                if ($drinkRow && isset($drinkRow['name'])) {
                                    $drinkName = $drinkRow['name'];
                                } else {
                                    $drinkName = $row['drink_id'];
                                }
                            }
                            if ((int)$row['quantity'] > 1) {
                                $label = $row['quantity'] . 'x ';
                            } else {
                                $label = '';
                            }
                            $comment = trim((string)$row['comment']);
                            $label .= ($comment !== '') ? $comment : $drinkName;
                        } else {
                            $drinkName = '';
                            if (isset($row['drink_id'])) {
                                $drinkRow = $dbAdapter->query('SELECT name FROM drinks WHERE id = ?', [$row['drink_id']])->current();
                                if ($drinkRow && isset($drinkRow['name'])) {
                                    $drinkName = $drinkRow['name'];
                                } else {
                                    $drinkName = $row['drink_id'];
                                }
                            }
                            $label = $row['quantity'] . 'x ' . $drinkName;
                        }
                        $adminName = '';
                        if (isset($admin) && $admin) {
                            $adminName = $admin->get('alias') ?: $admin->get('name');
                        }
                        if (!$adminName) {
                            $adminName = 'Administrator';
                        }
                        $body = $isTransferOrder
                            ? ('Ihre Geldüberweisung (' . $label . ') wurde von ' . htmlspecialchars($adminName) . ' ' . $action . '.<br><br>Kontostand nach Änderung: <b>' . number_format($balance, 2, ',', '.') . ' EUR</b>')
                            : ('Ihre Getränkebuchung (' . $label . ') am ' . $row['order_time'] . ' wurde von ' . htmlspecialchars($adminName) . ' ' . $action . '.<br><br>Kontostand nach Änderung: <b>' . number_format($balance, 2, ',', '.') . ' EUR</b>');
                        $this->sendFromTheke($mailService, $dbAdapter, $user, $subject, $body, ['isHtml' => true]);
                    }
                }
                if ($transferReference !== '') {
                    $drinkManagerInner = $serviceManager->get('Drinks\Manager\DrinkManager');
                    $counterRow = $dbAdapter->query('SELECT * FROM drink_deposits WHERE transfer_reference = ? LIMIT 1', [$transferReference])->current();
                    if ($counterRow && (int)$counterRow['user_id'] !== (int)$row['user_id']) {
                        $counterUser = $userManager->get($counterRow['user_id']);
                        if ($counterUser) {
                            $action2 = $newDeleted ? 'storniert' : 'wiederhergestellt';
                            $adminName2 = ($admin->get('alias') ?: $admin->get('name')) ?: 'Administrator';
                            $counterBalance = $drinkManagerInner->calculateUserDrinkBalance($counterRow['user_id'], $serviceManager);
                            $counterSubject = 'Geldüberweisung ' . ucfirst($action2);
                            $counterBody = 'Eine Geldüberweisung, die Ihr Konto betrifft, wurde von ' . htmlspecialchars($adminName2) . ' ' . $action2 . '.<br><br>Kontostand nach Änderung: <b>' . number_format($counterBalance, 2, ',', '.') . ' EUR</b>';
                            $this->sendFromTheke($mailService, $dbAdapter, $counterUser, $counterSubject, $counterBody, ['isHtml' => true]);
                        }
                    }
                }
                return $this->getResponse()->setContent(json_encode(['success' => true]));
            }
        } else {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Invalid entry_type']));
        }
        return $this->getResponse()->setStatusCode(404)->setContent(json_encode(['success' => false, 'error' => 'Entry not found']));
    }

   /**
     * AJAX endpoint to update drinks_enabled and drinks_alias for a user
          * POST: uid, drinks_enabled (bool), drinks_alias (string), order_email_option (string), teamlead_email (string)
     * Returns JSON: { success: true } or { error: ... }
     */
    public function setUserDrinksSettingsAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $admin = $userSessionManager->getSessionUser();
        if (!$admin || $admin->get('status') !== 'admin') {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['error' => 'No permission']));
        }
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->getResponse()->setStatusCode(405)->setContent(json_encode(['error' => 'POST required']));
        }
        $uid = (int)$this->params()->fromPost('uid');
        $drinksEnabled = $this->params()->fromPost('drinks_enabled', null);
        $drinksAlias = $this->params()->fromPost('drinks_alias', null);
        $orderEmailOption = $this->params()->fromPost('order_email_option', null);
        $teamleadEmail = $this->params()->fromPost('teamlead_email', null);
        $isTeam = $this->params()->fromPost('is_team', null);
        if (!$uid) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['error' => 'No user selected']));
        }
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        // Validate alias: allow empty or up to 50 chars, no dangerous chars
        if ($drinksAlias !== null) {
            $drinksAlias = trim($drinksAlias);
            if ($drinksAlias !== '' && !preg_match('/^[\w\-\s]{1,50}$/u', $drinksAlias)) {
                return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['error' => 'Invalid alias']));
            }
        }
        if ($orderEmailOption !== null) {
            $orderEmailOption = trim((string)$orderEmailOption);
            $allowedOrderEmailOptions = ['order', 'summary', 'negative'];
            if (!in_array($orderEmailOption, $allowedOrderEmailOptions, true)) {
                return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['error' => 'Invalid order email option']));
            }
        }
        if ($teamleadEmail !== null) {
            $teamleadEmail = trim((string)$teamleadEmail);
            if ($teamleadEmail !== '') {
                $rawEmails = preg_split('/[;,]+/', $teamleadEmail);
                $normalizedEmails = [];
                foreach ((array)$rawEmails as $rawEmail) {
                    $email = strtolower(trim((string)$rawEmail));
                    if ($email === '') {
                        continue;
                    }
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['error' => 'Invalid teamlead email address']));
                    }
                    if (!in_array($email, $normalizedEmails, true)) {
                        $normalizedEmails[] = $email;
                    }
                }
                $teamleadEmail = implode(', ', $normalizedEmails);
            }
        }
        // Normalize is_team
        $isTeamVal = null;
        if ($isTeam !== null) {
            $isTeamVal = ($isTeam === '1' || $isTeam === 1 || $isTeam === true || $isTeam === 'true') ? 1 : 0;
        }
        try {
            $row = $dbAdapter->query('SELECT * FROM drink_aliases WHERE user_id = ?', [$uid])->current();
            if ($row) {
                // Build dynamic update
                $fields = [];
                $params = [];
                if ($drinksEnabled !== null) {
                    $enabledVal = ($drinksEnabled === '1' || $drinksEnabled === 1 || $drinksEnabled === true || $drinksEnabled === 'true') ? 1 : 0;
                    $fields[] = 'enabled = ?';
                    $params[] = $enabledVal;
                }
                if ($drinksAlias !== null) {
                    $fields[] = 'alias = ?';
                    $params[] = $drinksAlias;
                }
                if ($orderEmailOption !== null) {
                    $fields[] = 'order_email_option = ?';
                    $params[] = $orderEmailOption;
                }
                if ($teamleadEmail !== null) {
                    $fields[] = 'teamlead_email = ?';
                    $params[] = $teamleadEmail;
                }
                if (isset($thekenadmin)) {
                    $fields[] = 'thekenadmin = ?';
                    $params[] = $thekenadmin ? 1 : 0;
                }
                if ($isTeamVal !== null) {
                    $fields[] = 'is_team = ?';
                    $params[] = $isTeamVal;
                }
                if (!empty($fields)) {
                    $params[] = $uid;
                    // Build upsert query for drink_aliases
                    $columns = [];
                    $values = [];
                    $updates = [];
                    if ($drinksEnabled !== null) {
                        $columns[] = 'enabled';
                        $values[] = $enabledVal;
                        $updates[] = 'enabled = VALUES(enabled)';
                    }
                    if ($drinksAlias !== null) {
                        $columns[] = 'alias';
                        $values[] = $drinksAlias;
                        $updates[] = 'alias = VALUES(alias)';
                    }
                    if ($orderEmailOption !== null) {
                        $columns[] = 'order_email_option';
                        $values[] = $orderEmailOption;
                        $updates[] = 'order_email_option = VALUES(order_email_option)';
                    }
                    if ($teamleadEmail !== null) {
                        $columns[] = 'teamlead_email';
                        $values[] = $teamleadEmail;
                        $updates[] = 'teamlead_email = VALUES(teamlead_email)';
                    }
                    if (isset($thekenadmin)) {
                        $columns[] = 'thekenadmin';
                        $values[] = $thekenadmin ? 1 : 0;
                        $updates[] = 'thekenadmin = VALUES(thekenadmin)';
                    }
                    if ($isTeamVal !== null) {
                        $columns[] = 'is_team';
                        $values[] = $isTeamVal;
                        $updates[] = 'is_team = VALUES(is_team)';
                    }
                    $columns = array_merge(['user_id'], $columns);
                    $values = array_merge([$uid], $values);
                    $sql = 'INSERT INTO drink_aliases (' . implode(', ', $columns) . ') VALUES (' . rtrim(str_repeat('?, ', count($columns)), ', ') . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
                    $dbAdapter->query($sql, $values);
                }
            } else {
                // Insert: require all fields
                if ($drinksAlias === null && $drinksEnabled === null && $orderEmailOption === null && $teamleadEmail === null && !isset($thekenadmin) && $isTeamVal === null) {
                    return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['error' => 'Alias, enabled, and thekenadmin required for new entry']));
                }
                $enabledVal = ($drinksEnabled === '1' || $drinksEnabled === 1 || $drinksEnabled === true || $drinksEnabled === 'true') ? 1 : 0;
                $thekenadminVal = isset($thekenadmin) ? ($thekenadmin ? 1 : 0) : 0;
                $isTeamInsert = $isTeamVal !== null ? $isTeamVal : 0;
                $orderEmailOptionInsert = $orderEmailOption !== null ? $orderEmailOption : 'order';
                $teamleadEmailInsert = $teamleadEmail !== null ? $teamleadEmail : '';
                $dbAdapter->query('INSERT INTO drink_aliases (user_id, alias, enabled, thekenadmin, is_team, order_email_option, teamlead_email) VALUES (?, ?, ?, ?, ?, ?, ?)', [$uid, $drinksAlias, $enabledVal, $thekenadminVal, $isTeamInsert, $orderEmailOptionInsert, $teamleadEmailInsert]);
            }
        } catch (\Exception $e) {
            return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['error' => 'DB error', 'details' => $e->getMessage()]));
        }
        return $this->getResponse()->setContent(json_encode(['success' => true]));
    }
    
    /**
     * Helper: Check if transfer reference columns exist
     */
    protected function canUseTransferReferenceColumns($dbAdapter)
    {
        try {
            $orderCol = $dbAdapter->query("SHOW COLUMNS FROM drink_orders LIKE 'transfer_reference'", [])->current();
            $depositCol = $dbAdapter->query("SHOW COLUMNS FROM drink_deposits LIKE 'transfer_reference'", [])->current();
            return (bool)$orderCol && (bool)$depositCol;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Helper: Send email from theke system
     */
    private function sendFromTheke($mailService, $dbAdapter, $user, $subject, $body, $options = [])
    {
        if ($mailService && $user) {
            $mailService->send($user, $subject, $body, $options);
        }
    }
}