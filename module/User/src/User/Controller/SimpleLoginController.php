<?php
namespace User\Controller;

use User\Controller\Traits\MoneyTransferTrait;
use User\Controller\Traits\TeamEventTrait;
use Zend\Crypt\Password\Bcrypt;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class SimpleLoginController extends AbstractActionController
{
    use TeamEventTrait;
    use MoneyTransferTrait;

    /**
     * Number of hours to look back for recent orders
     */
    const RECENT_ORDERS_CUTOFF_HOURS = 2;

    const TEAM_SPIELTAG_NEW_OPTION = '__new__';

    public function loginAction()
    {
        $request = $this->getRequest();
        $error = null;
        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $recentOrders = [];
        try {
            $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
            $cutoff = (new \DateTime('-' . self::RECENT_ORDERS_CUTOFF_HOURS . ' hours'))->format('Y-m-d H:i:s');
            $sql = 'SELECT o.order_time, o.user_id, u.alias, d.name AS drink_name, o.quantity, o.deleted FROM drink_orders o JOIN bs_users u ON o.user_id = u.uid JOIN drinks d ON o.drink_id = d.id WHERE o.deleted = false AND o.order_time >= ? ORDER BY o.order_time DESC';
            $recentOrders = $db->query($sql, [$cutoff])->toArray();
        } catch (\Exception $e) {
            // Leave $recentOrders empty on error
        }
        // Party Mode variables (with time window)
        $partyModeEnabled = false; $partyModeMessage = ''; $partyModeStart=''; $partyModeEnd='';
        try {
            $optionManager = $this->getServiceLocator()->get('Base\\Manager\\OptionManager');
            $rawEnabled = $optionManager->get('party_mode.enabled', false);
            $partyModeEnabledBase = ($rawEnabled === '1' || $rawEnabled === 1 || $rawEnabled === true);
            try { $partyModeMessage = (string)$optionManager->get('party_mode.message', ''); } catch (\RuntimeException $e) {}
            try { $partyModeStart = (string)$optionManager->get('party_mode.start', ''); } catch (\RuntimeException $e) {}
            try { $partyModeEnd = (string)$optionManager->get('party_mode.end', ''); } catch (\RuntimeException $e) {}
            $now = time(); $activeWithin = true;
            $sTs = $partyModeStart && ($ts=strtotime($partyModeStart))!==false ? $ts : null;
            $eTs = $partyModeEnd && ($ts=strtotime($partyModeEnd))!==false ? $ts : null;
            if ($sTs && $now < $sTs) $activeWithin = false;
            if ($eTs && $now > $eTs) $activeWithin = false;
            $partyModeEnabled = $partyModeEnabledBase && $activeWithin;
        } catch (\Exception $e) {}
        if ($request->isPost()) {
            $alias = trim($request->getPost('alias'));
            if ($alias) {
                $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
                $row = $db->query('SELECT user_id, enabled FROM drink_aliases WHERE alias = ?', [$alias])->current();
                if ($row && $row['user_id']) {
                    if ((int)$row['enabled'] === 1) {
                        $session = new \Zend\Session\Container('SimpleLogin');
                        $session->user_id = $row['user_id'];
                        return $this->redirect()->toRoute('user/simple-order');
                    } else {
                        $error = 'Benutzer gesperrt.';
                    }
                } else {
                    $error = 'Theken-ID nicht gefunden.';
                }
            } else {
                $error = 'Bitte geben Sie eine Theken-ID ein.';
            }
        }
        $viewModel = new ViewModel([
            'error' => $error,
            'recentOrders' => $recentOrders,
            'recentOrdersCutoffHours' => self::RECENT_ORDERS_CUTOFF_HOURS,
            'partyModeEnabled' => $partyModeEnabled,
            'partyModeMessage' => $partyModeMessage,
        ]);
        $viewModel->setTerminal(true);
        return $viewModel;
    }

    public function orderAction()
    {
        $viewModel = new ViewModel();
        $viewModel->setTerminal(true);
        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $session = new \Zend\Session\Container('SimpleLogin');
        if (empty($session->user_id)) {
            return $this->redirect()->toRoute('user/simple-login');
        }
        $userId = $session->user_id;
        $drinkManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkManager');
        $userManager = $this->getServiceLocator()->get('User\Manager\UserManager');
        $user = $userManager->get($userId);
        $userName = $user->get('alias');
        $drinks = $drinkManager->getAll($userId);
        $drinkCategoryManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkCategoryManager');
        $drinkCategories = $drinkCategoryManager->getAll();
        $drinkOrderManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkDepositManager');
        // Fetch deposits and orders for the user
        $drinkDeposits = iterator_to_array($drinkDepositManager->getByUser($userId));
        $drinkOrders = iterator_to_array($drinkOrderManager->getByUser($userId));
        // Use DrinkManager for balance calculation
        $currentBalance = $drinkManager->calculateUserDrinkBalance($userId, $this->getServiceLocator());
        // Fetch flags from drink_aliases
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $row = $db->query('SELECT thekenadmin, is_team FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        $thekenadmin = ($row && !empty($row['thekenadmin'])) ? true : false;
        $isTeamAccount = ($row && !empty($row['is_team'])) ? true : false;
        $teamAdminUserId = $userId;
        $currentTeamEventLabel = '';
        $availableTeamEventLabels = [];
        $currentTeamEventId = 0;
        if ($isTeamAccount) {
            list($currentTeamEventLabel, $availableTeamEventLabels) = $this->resolveSessionTeamEventSelection($teamAdminUserId, $session);
            $currentTeamEventId = isset($session->current_teamevent_id) ? (int)$session->current_teamevent_id : 0;
            if ($currentTeamEventId <= 0 && $currentTeamEventLabel !== '') {
                $event = $this->getTeamEventByLabel($teamAdminUserId, $currentTeamEventLabel);
                if ($event && isset($event['id'])) {
                    $currentTeamEventId = (int)$event['id'];
                    $session->current_teamevent_id = $currentTeamEventId;
                }
            }
        }
        $drinkHistory = [];
        foreach ($drinkDeposits as $deposit) {
            $depositComment = isset($deposit['comment']) ? trim((string)$deposit['comment']) : '';
            $drinkHistory[] = [
                'type' => 'deposit',
                'amount' => $deposit['amount'],
                'created_at' => $deposit['deposit_time'],
                'datetime' => $deposit['deposit_time'],
                'id' => $deposit['id'],
                'comment' => $depositComment,
                'teamevent_id' => isset($deposit['teamevent_id']) ? (int)$deposit['teamevent_id'] : 0,
            ];
        }
        foreach ($drinkOrders as $order) {
            $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : null;
            $comment = isset($order['comment']) ? trim((string)$order['comment']) : '';
            $drinkName = $order['name'];
            // For special comment-based entries (1, -1), use drink name as fallback when comment is empty
            if (($drinkId === 1 || $drinkId === -1) && $comment === '') {
                $comment = $drinkName;
            }
            $drinkHistory[] = [
                'type' => 'order',
                'drink_id' => $drinkId,
                'name' => $drinkName,
                'quantity' => $order['quantity'],
                'price' => $order['price'],
                'total' => $order['quantity'] * $order['price'],
                'created_at' => $order['order_time'],
                'datetime' => $order['order_time'],
                'id' => $order['id'],
                'deleted' => $order['deleted'],
                'comment' => $comment,
                'teamevent_id' => isset($order['teamevent_id']) ? (int)$order['teamevent_id'] : 0,
            ];
        }
        if ($isTeamAccount && $currentTeamEventLabel !== '') {
            $drinkHistory = array_values(array_filter($drinkHistory, function ($entry) use ($currentTeamEventId, $currentTeamEventLabel) {
                $entryTeamEventId = isset($entry['teamevent_id']) ? (int)$entry['teamevent_id'] : 0;
                if ($currentTeamEventId > 0 && $entryTeamEventId > 0) {
                    return $entryTeamEventId === $currentTeamEventId;
                }

                // Backward compatibility for legacy data without teamevent_id.
                $entryComment = isset($entry['comment']) ? trim((string)$entry['comment']) : '';
                return $entryTeamEventId === 0 && $entryComment !== '' && $entryComment === $currentTeamEventLabel;
            }));
        }
        usort($drinkHistory, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        $drinkOrderCancelWindow = \Drinks\Manager\DrinkOrderManager::CANCEL_WINDOW_SECONDS;
        // Party Mode variables (with time window)
        $partyModeEnabled = false; $partyModeMessage = ''; $partyModeStart=''; $partyModeEnd='';
        try {
            $optionManager = $this->getServiceLocator()->get('Base\\Manager\\OptionManager');
            $rawEnabled = $optionManager->get('party_mode.enabled', false);
            $partyModeEnabledBase = ($rawEnabled === '1' || $rawEnabled === 1 || $rawEnabled === true);
            try { $partyModeMessage = (string)$optionManager->get('party_mode.message', ''); } catch (\RuntimeException $e) {}
            try { $partyModeStart = (string)$optionManager->get('party_mode.start', ''); } catch (\RuntimeException $e) {}
            try { $partyModeEnd = (string)$optionManager->get('party_mode.end', ''); } catch (\RuntimeException $e) {}
            $now = time(); $activeWithin = true;
            $sTs = $partyModeStart && ($ts=strtotime($partyModeStart))!==false ? $ts : null;
            $eTs = $partyModeEnd && ($ts=strtotime($partyModeEnd))!==false ? $ts : null;
            if ($sTs && $now < $sTs) $activeWithin = false;
            if ($eTs && $now > $eTs) $activeWithin = false;
            $partyModeEnabled = $partyModeEnabledBase && $activeWithin;
        } catch (\Exception $e) {}

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

        return $viewModel->setVariables([
            'drinks' => $drinks,
            'drinkHistory' => $drinkHistory,
            'userName' => $userName,
            'currentBalance' => $currentBalance,
            'error' => null,
            'success' => false,
            'drinkOrderCancelWindow' => $drinkOrderCancelWindow,
            'drinkCategories' => $drinkCategories,
            'drinkStats' => [],
            'simpleOrderMode' => true,
            'thekenadmin' => $thekenadmin,
            'isTeamAccount' => $isTeamAccount,
            'currentSpieltag' => $currentTeamEventLabel,
            'availableSpieltage' => $availableTeamEventLabels,
            'partyModeEnabled' => $partyModeEnabled,
            'partyModeMessage' => $partyModeMessage,
            'moneyRecipients' => $moneyRecipients,
        ]);
    }

    public function dropOrderAction()
    {
        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $session = new \Zend\Session\Container('SimpleLogin');
        if (empty($session->user_id)) {
            return $this->getResponse()->setStatusCode(403);
        }
        $orderId = (int)$this->params()->fromPost('order_id');
        if (!$orderId) {
            return $this->getResponse()->setStatusCode(400);
        }
        $userManager = $this->getServiceLocator()->get('User\Manager\UserManager');
        $user = $userManager->get($session->user_id);
        $drinkManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkManager');
        $success = $drinkManager->dropOrderAndNotify($orderId, $user, [$this, 't'], $this->getServiceLocator());
        if ($success) {
            return $this->getResponse()->setContent(json_encode(['success' => true]))->setStatusCode(200);
        }
        return $this->getResponse()->setContent(json_encode(['success' => false, 'error_message' => 'Update failed.']))->setStatusCode(500);
    }

    public function teamStatsAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $session = new \Zend\Session\Container('SimpleLogin');
        if (empty($session->user_id)) {
            return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
        }

        $userId = (int)$session->user_id;
        $teamAdminUserId = $userId;
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $serviceManager = $this->getServiceLocator();
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $accountBalance = (float)$drinkManager->calculateUserDrinkBalance($userId, $serviceManager);
        $aliasRow = $db->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        if (!$aliasRow || empty($aliasRow['is_team'])) {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['success' => false, 'error' => 'Kein Team-Account.']));
        }
        $requestedTeamEventLabel = $this->normalizeTeamEventLabel($this->params()->fromQuery('spieltag', isset($session->current_spieltag) ? $session->current_spieltag : ''));
        if ($requestedTeamEventLabel === '') {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Kein Spieltag ausgewählt.']));
        }

        return $this->getResponse()->setContent(json_encode(array_merge([
            'success' => true,
            'account_balance' => $accountBalance,
            'spieltage' => $this->getAvailableTeamEventLabels($teamAdminUserId, true),
            'open_spieltage' => $this->getAvailableTeamEventLabels($teamAdminUserId, false),
        ], $this->buildTeamStatsPayload($teamAdminUserId, $requestedTeamEventLabel))));
    }

    public function teamMembersAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->getResponse()->setStatusCode(405)->setContent(json_encode(['success' => false, 'error' => 'POST required.']));
        }

        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $session = new \Zend\Session\Container('SimpleLogin');
        if (empty($session->user_id)) {
            return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
        }

        $teamAdminUserId = (int)$session->user_id;
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $aliasRow = $db->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$teamAdminUserId])->current();
        if (!$aliasRow || empty($aliasRow['is_team'])) {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['success' => false, 'error' => 'Kein Team-Account.']));
        }

        $teamEventId = (int)$this->params()->fromPost('team_event_id', 0);
        $memberUserId = (int)$this->params()->fromPost('member_user_id', 0);
        $operation = trim((string)$this->params()->fromPost('operation', ''));
        $responseData = $this->buildMemberOperationJsonResponse($teamAdminUserId, $teamEventId, $memberUserId, $operation, $teamAdminUserId);
        if (!isset($responseData['success']) || !$responseData['success']) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode($responseData));
        }
        return $this->getResponse()->setContent(json_encode($responseData));
    }

    public function closeTeamEventAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->getResponse()->setStatusCode(405)->setContent(json_encode(['success' => false, 'error' => 'POST required.']));
        }

        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $session = new \Zend\Session\Container('SimpleLogin');
        if (empty($session->user_id)) {
            return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
        }

        $teamAdminUserId = (int)$session->user_id;
        $teamEventId = (int)$this->params()->fromPost('team_event_id', 0);
        if ($teamEventId <= 0) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Ungültiger Spieltag.']));
        }

        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $aliasRow = $db->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$teamAdminUserId])->current();
        if (!$aliasRow || empty($aliasRow['is_team'])) {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['success' => false, 'error' => 'Kein Team-Account.']));
        }

        $closeResult = $this->closeTeamEventWithStatus($teamAdminUserId, $teamEventId);
        if (empty($closeResult['success'])) {
            $error = isset($closeResult['error']) ? $closeResult['error'] : '';
            if ($error === 'feature_unavailable') {
                return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['success' => false, 'error' => 'Team-Event Schließen ist noch nicht verfügbar.']));
            }
            if ($error === 'not_found') {
                return $this->getResponse()->setStatusCode(404)->setContent(json_encode(['success' => false, 'error' => 'Spieltag nicht gefunden.']));
            }
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Ungültiger Spieltag.']));
        }

        $eventRow = isset($closeResult['event']) ? $closeResult['event'] : null;

        // If the closed event is currently selected, move session selection to latest open event.
        if (isset($session->current_spieltag)) {
            $closedLabel = isset($eventRow['comment']) ? trim((string)$eventRow['comment']) : '';
            if ($closedLabel !== '' && trim((string)$session->current_spieltag) === $closedLabel) {
                $openLabels = $this->getAvailableTeamEventLabels($teamAdminUserId, false);
                $session->current_spieltag = !empty($openLabels) ? $openLabels[0] : '';
                $session->current_teamevent_id = null;
                if (!empty($openLabels)) {
                    $openEvent = $this->getTeamEventByLabel($teamAdminUserId, $openLabels[0]);
                    if ($openEvent && isset($openEvent['id'])) {
                        $session->current_teamevent_id = (int)$openEvent['id'];
                    }
                }
            }
        }

        return $this->getResponse()->setContent(json_encode([
            'success' => true,
            'already_closed' => !empty($closeResult['already_closed'])
        ]));
    }

    public function spieltagAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $session = new \Zend\Session\Container('SimpleLogin');
        if (empty($session->user_id)) {
            return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
        }

        $userId = (int)$session->user_id;
        $teamAdminUserId = $userId;
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $aliasRow = $db->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        if (!$aliasRow || empty($aliasRow['is_team'])) {
            return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['success' => false, 'error' => 'Kein Team-Account.']));
        }
        if ($this->getRequest()->isPost()) {
            $selectedRaw = $this->normalizeTeamEventLabel($this->params()->fromPost('spieltag', ''));
            $isNewTeamEventRequest = ($selectedRaw === self::TEAM_SPIELTAG_NEW_OPTION || $selectedRaw === '');
            $selected = $selectedRaw;
            if ($isNewTeamEventRequest) {
                $selected = $this->normalizeTeamEventLabel($this->params()->fromPost('new_spieltag', ''));
            }
            if ($selected === '') {
                return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Ungueltiger Spieltag.']));
            }
            $event = $this->getOrCreateTeamEventByLabel($teamAdminUserId, $selected);
            if (!$event) {
                return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['success' => false, 'error' => 'Spieltag konnte nicht gespeichert werden.']));
            }

            if ($isNewTeamEventRequest) {
                $memberIdsRaw = $this->params()->fromPost('member_user_ids', '');
                $memberUserIds = $this->parseTeamEventMemberIds($memberIdsRaw);
                $this->saveTeamEventMembers($teamAdminUserId, (int)$event['id'], $memberUserIds);
            }

            $session->current_spieltag = $selected;
            $session->current_teamevent_id = (int)$event['id'];
        }

        list($currentTeamEventLabel, $availableTeamEventLabels) = $this->resolveSessionTeamEventSelection($teamAdminUserId, $session);
        return $this->getResponse()->setContent(json_encode([
            'success' => true,
            'current_spieltag' => $currentTeamEventLabel,
            'spieltage' => $availableTeamEventLabels,
        ]));
    }

    public function sendMoneyAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->getResponse()->setStatusCode(405)->setContent(json_encode(['success' => false, 'error' => 'POST required.']));
        }

        $serviceManager = $this->getServiceLocator();

        $senderUserId = 0;
        $sessionManager = $serviceManager->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $simpleSession = new \Zend\Session\Container('SimpleLogin');
        $isSimpleModeRequest = false;
        if (!empty($simpleSession->user_id)) {
            $isSimpleModeRequest = true;
            $senderUserId = (int)$simpleSession->user_id;
        }

        if ($senderUserId <= 0) {
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            $sessionUser = $userSessionManager->getSessionUser();
            if ($sessionUser) {
                $senderUserId = (int)$sessionUser->need('uid');
            }
        }

        if ($senderUserId <= 0) {
            return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
        }

        if ($isSimpleModeRequest) {
            $password = (string)$this->params()->fromPost('password', '');
            if ($password === '') {
                return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Bitte Passwort eingeben.']));
            }

            $userManager = $serviceManager->get('User\Manager\UserManager');
            $senderUser = $userManager->get($senderUserId);
            if (!$senderUser) {
                return $this->getResponse()->setStatusCode(404)->setContent(json_encode(['success' => false, 'error' => 'Nutzer nicht gefunden.']));
            }

            $bcrypt = new Bcrypt();
            $bcrypt->setCost(6);
            if (!$bcrypt->verify($password, $senderUser->need('pw'))) {
                return $this->getResponse()->setStatusCode(403)->setContent(json_encode(['success' => false, 'error' => 'Passwort ist falsch.']));
            }
        }

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

    public function submitOrderAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $session = new \Zend\Session\Container('SimpleLogin');
        if (empty($session->user_id)) {
            return $this->getResponse()->setStatusCode(401)->setContent(json_encode(['success' => false, 'error' => 'Not authenticated.']));
        }
        $userManager = $this->getServiceLocator()->get('User\Manager\UserManager');
        $user = $userManager->get($session->user_id);
        $drinkManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkManager');
        $drinkCounts = $this->params()->fromPost('drink_counts', []);
        $isAutoOrder = (int)$this->params()->fromPost('is_auto_order', 0);
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $row = $db->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$session->user_id])->current();
        $isTeamAccount = ($row && !empty($row['is_team'])) ? true : false;
        $comment = null;
        $teamEventId = null;
        if ($isTeamAccount) {
            $teamAdminUserId = (int)$session->user_id;
            $selectedTeamEventLabel = $this->normalizeTeamEventLabel(isset($session->current_spieltag) ? $session->current_spieltag : '');
            if ($selectedTeamEventLabel === '') {
                list($selectedTeamEventLabel) = $this->resolveSessionTeamEventSelection($teamAdminUserId, $session);
            }
            if ($selectedTeamEventLabel !== '') {
                $event = $this->getTeamEventByLabel($teamAdminUserId, $selectedTeamEventLabel);
                if ($event) {
                    $teamEventId = (int)$event['id'];
                    $session->current_teamevent_id = $teamEventId;
                }
            }
            // Spieltag is stored via teamevent_id only; keep comment for actual free-text comments.
            $comment = null;
        }
        $result = $drinkManager->addOrdersAndNotify($user, $drinkCounts, [$this, 't'], $this->getServiceLocator(), $isAutoOrder, $comment, $teamEventId);
        if ($result['success']) {
            return $this->getResponse()->setContent(json_encode(['success' => true, 'balance' => $result['balance']]))->setStatusCode(200);
        }
        return $this->getResponse()->setContent(json_encode(['success' => false, 'error' => $result['error']]))->setStatusCode(400);
    }
}
