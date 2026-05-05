<?php

namespace User\Controller;

use User\Controller\Traits\MoneyTransferTrait;
use User\Controller\Traits\TeamEventTrait;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Crypt\Password\Bcrypt;

class AccountController extends AbstractActionController
{
    use TeamEventTrait;
    use MoneyTransferTrait;

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

    public function createTeamEventAction()
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

        $uid = (int)$this->params()->fromPost('uid', 0);
        $teamEventLabel = $this->normalizeTeamEventLabel($this->params()->fromPost('label', ''));
        if ($uid <= 0 || $teamEventLabel === '') {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Invalid input']));
        }

        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $aliasRow = $dbAdapter->query('SELECT is_team FROM drink_aliases WHERE user_id = ?', [$uid])->current();
        if (!$aliasRow || empty($aliasRow['is_team'])) {
            return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'User is not a team account']));
        }

        $eventRow = $this->getOrCreateTeamEventByLabel($uid, $teamEventLabel);
        if (!$eventRow || empty($eventRow['id'])) {
            return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['success' => false, 'error' => 'Spieltag konnte nicht angelegt werden.']));
        }

        return $this->getResponse()->setContent(json_encode([
            'success' => true,
            'team_event_id' => (int)$eventRow['id'],
            'label' => isset($eventRow['comment']) ? trim((string)$eventRow['comment']) : $teamEventLabel,
        ]));
    }

    /**
     * Admin: Übersicht aller Nutzer mit Buchungen oder Einzahlungen, sortiert nach Kontostand
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
            // Use DrinkManager for balance
            $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
            $balance = $drinkManager->calculateUserDrinkBalance($uid, $serviceManager);
            // Still need lastDeposit and lastOrder for activity
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
                    if (!$lastOrder || (isset($o['order_time']) && $o['order_time'] > $lastOrder)) {
                        $lastOrder = $o['order_time'];
                    }
                    if (isset($o['price'])) {
                        $qty = isset($o['quantity']) ? (float)$o['quantity'] : 1;
                        $ordersTotal += ((float)$o['price']) * $qty;
                    }
                }
            }
            // Find most recent activity
            $lastActivity = null;
            if ($lastDeposit && $lastOrder) {
                $lastActivity = max($lastDeposit, $lastOrder);
            } elseif ($lastDeposit) {
                $lastActivity = $lastDeposit;
            } elseif ($lastOrder) {
                $lastActivity = $lastOrder;
            }
            // Only show users with at least one deposit or order
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
        // Sort by balance ascending
        usort($userList, function($a, $b) {
            return $a['balance'] <=> $b['balance'];
        });
        // Calculate total sum of all balances
        $totalBalance = 0;
        foreach ($userList as $user) {
            $totalBalance += $user['balance'];
        }
        return [
            'users' => $userList,
            'total_balance' => $totalBalance,
        ];
    }
    /**
     * POST: entry_id
     * Returns JSON: { success: true } or { error: ... }
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
                // Send notification email to deposit owner
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
                    $mailService->sendFromTheke($user, $subject, $body, ['isHtml' => true]);
                }
                // Send notification email to transfer counterpart (the order side)
                if ($transferReference !== '') {
                    $counterRow = $dbAdapter->query('SELECT * FROM drink_orders WHERE transfer_reference = ? LIMIT 1', [$transferReference])->current();
                    if ($counterRow && (int)$counterRow['user_id'] !== (int)$row['user_id']) {
                        $counterUser = $userManager->get($counterRow['user_id']);
                        if ($counterUser) {
                            $counterBalance = $drinkManager->calculateUserDrinkBalance($counterRow['user_id'], $serviceManager);
                            $counterSubject = 'Geldüberweisung ' . ucfirst($action);
                            $counterBody = 'Eine Geldüberweisung, die Ihr Konto betrifft, wurde von ' . htmlspecialchars($adminName) . ' ' . $action . '.<br><br>Kontostand nach Änderung: <b>' . number_format($counterBalance, 2, ',', '.') . ' EUR</b>';
                            $mailService->sendFromTheke($counterUser, $counterSubject, $counterBody, ['isHtml' => true]);
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
                // Send notification email to user only if order_email_option is 'order',
                // or if it is 'negative' and the balance is zero or negative
                $user = $userManager->get($row['user_id']);
                if ($user) {
                    // Calculate new balance
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
                            // Fetch drink name for fallback
                            $drinkName = '';
                            if (isset($row['drink_id'])) {
                                $drinkRow = $dbAdapter->query('SELECT name FROM drinks WHERE id = ?', [$row['drink_id']])->current();
                                if ($drinkRow && isset($drinkRow['name'])) {
                                    $drinkName = $drinkRow['name'];
                                } else {
                                    $drinkName = $row['drink_id']; // fallback
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
                            // Fetch drink name for the label
                            $drinkName = '';
                            if (isset($row['drink_id'])) {
                                $drinkRow = $dbAdapter->query('SELECT name FROM drinks WHERE id = ?', [$row['drink_id']])->current();
                                if ($drinkRow && isset($drinkRow['name'])) {
                                    $drinkName = $drinkRow['name'];
                                } else {
                                    $drinkName = $row['drink_id']; // fallback
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
                        $mailService->sendFromTheke($user, $subject, $body, ['isHtml' => true]);
                    }
                }
                // Send notification email to transfer counterpart (the deposit side)
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
                            $mailService->sendFromTheke($counterUser, $counterSubject, $counterBody, ['isHtml' => true]);
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
                    $mailService->sendFromTheke($user, $subject, $body, ['isHtml' => true]);
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
     * AJAX endpoint to update drinks_enabled and drinks_alias for a user
        * POST: uid, drinks_enabled (bool), drinks_alias (string), order_email_option (string)
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
                if ($drinksAlias === null && $drinksEnabled === null && $orderEmailOption === null && !isset($thekenadmin) && $isTeamVal === null) {
                    return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['error' => 'Alias, enabled, and thekenadmin required for new entry']));
                }
                $enabledVal = ($drinksEnabled === '1' || $drinksEnabled === 1 || $drinksEnabled === true || $drinksEnabled === 'true') ? 1 : 0;
                $thekenadminVal = isset($thekenadmin) ? ($thekenadmin ? 1 : 0) : 0;
                $isTeamInsert = $isTeamVal !== null ? $isTeamVal : 0;
                $orderEmailOptionInsert = $orderEmailOption !== null ? $orderEmailOption : 'order';
                $dbAdapter->query('INSERT INTO drink_aliases (user_id, alias, enabled, thekenadmin, is_team, order_email_option) VALUES (?, ?, ?, ?, ?, ?)', [$uid, $drinksAlias, $enabledVal, $thekenadminVal, $isTeamInsert, $orderEmailOptionInsert]);
            }
        } catch (\Exception $e) {
            return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['error' => 'DB error', 'details' => $e->getMessage()]));
        }
        return $this->getResponse()->setContent(json_encode(['success' => true]));
    }

    public function passwordAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $formElementManager = $serviceManager->get('FormElementManager');

        $passwordForm = $formElementManager->get('User\Form\PasswordForm');
        $passwordMessage = null;

        if ($this->getRequest()->isPost()) {
            $passwordForm->setData($this->params()->fromPost());

            if ($passwordForm->isValid()) {
                $passwordData = $passwordForm->getData();

                $userManager = $serviceManager->get('User\Manager\UserManager');
                $user = current( $userManager->getBy(array('email' => $passwordData['pf-email'])) );

                if ($user) {
                    $mailMessage = $this->t('We have just received your request to reset your password.') . "\r\n\r\n";

                    switch ($user->need('status')) {
                        case 'placeholder':
                            $mailMessage .= $this->t('Unfortunately, your account is considered a placeholder and thus cannot login.');
                            break;
                        case 'blocked':
                            $mailMessage .= $this->t('Unfortunately, your account is currently blocked. Please contact us for support.');
                            break;
                        case 'disabled':
                            $mailMessage .= $this->t('Unfortunately, your account has not yet been activated. If you did not receive an activation email yet, you can request a new one here:') . "\r\n\r\n";
                            $mailMessage .= $this->url()->fromRoute('user/activation-resend', [], ['force_canonical' => true]);

                            break;
                        case 'enabled':
                            $resetCode = base64_encode( substr($user->need('pw'), 16, 8) );

                            $mailMessage .= $this->t('Simply visit the following website to type your new password:') . "\r\n\r\n";
                            $mailMessage .= $this->url()->fromRoute('user/password-reset', [], ['query' => ['id' => $user->need('uid'), 'code' => $resetCode], 'force_canonical' => true]);

                            break;
                        case 'assist':
                        case 'admin':
                            $mailMessage .= $this->t('However, you are using a privileged account. For safety, you cannot reset your password this way. Please contact the system support.');
                            break;
                        default:
                            $mailMessage .= $this->t('Unfortunately, your account seems somewhat unique, thus we are unsure how to treat it. Mind contacting us?');
                            break;
                    }

                    $userMailService = $serviceManager->get('User\Service\MailService');
                    $userMailService->send($user, $this->t('Forgot your password?'), $mailMessage);
                }
            }

            $passwordForm->get('pf-email')->setValue('');

            $passwordMessage = sprintf('%s <div class="small-text">(%s)</div>',
                $this->t('All right, you should receive an email from us soon'),
                $this->t('if we find a valid user account with this email address'));
        }

        return array(
            'passwordForm' => $passwordForm,
            'passwordMessage' => $passwordMessage,
        );
    }

    public function passwordResetAction()
    {
        $resetUid = $this->params()->fromQuery('id');
        $resetCode = $this->params()->fromQuery('code');

        if (! (is_numeric($resetUid) && $resetUid > 0 && preg_match('/^[a-zA-Z0-9\+\/\=]+$/', $resetCode))) {
            throw new RuntimeException('Your token to reset your password is invalid or expired. Please request a new email.');
        }

        $serviceManager = @$this->getServiceLocator();

        $userManager = $serviceManager->get('User\Manager\UserManager');
        $user = $userManager->get($resetUid, false);

        if (! $user) {
            throw new RuntimeException('Your token to reset your password is invalid or expired. Please request a new email.');
        }

        $actualResetCode = base64_encode( substr($user->need('pw'), 16, 8) );

        if ($resetCode != $actualResetCode) {
            throw new RuntimeException('Your token to reset your password is invalid or expired. Please request a new email.');
        }

        $formElementManager = $serviceManager->get('FormElementManager');

        $resetForm = $formElementManager->get('User\Form\PasswordResetForm');
        $resetMessage = null;

        if ($this->getRequest()->isPost()) {
            $resetForm->setData($this->params()->fromPost());

            if ($resetForm->isValid()) {
                $resetData = $resetForm->getData();

                $bcrypt = new Bcrypt();
                $bcrypt->setCost(6);

                $user->set('pw', $bcrypt->create($resetData['prf-pw1']));

                $user->set('last_activity', date('Y-m-d H:i:s'));
                $user->set('last_ip', $_SERVER['REMOTE_ADDR']);

                $userManager->save($user);

                $resetMessage = 'All right, your password has been changed. You can now log into your account.';
            }
        }

        return array(
            'resetUid' => $resetUid,
            'resetCode' => $resetCode,
            'resetForm' => $resetForm,
            'resetMessage' => $resetMessage,
        );
    }

    public function registrationAction()
    {
        $serviceManager = @$this->getServiceLocator();

        $formElementManager = $serviceManager->get('FormElementManager');

        $registrationForm = $formElementManager->get('User\Form\RegistrationForm');

        if ($this->getRequest()->isPost() && $this->option('service.user.registration') == 'true') {
            $registrationForm->setData($this->params()->fromPost());

            if ($registrationForm->isValid()) {
                $registrationData = $registrationForm->getData();

                $meta = array();
                $meta['gender'] = $registrationData['rf-gender'];

                if (isset($registrationData['rf-lastname']) && $registrationData['rf-lastname']) {
                    $meta['firstname'] = ucfirst($registrationData['rf-firstname']);
                    $meta['lastname'] = ucfirst($registrationData['rf-lastname']);

                    $alias = $meta['firstname'] . ' ' . $meta['lastname'];
                } else {
                    $meta['name'] = $registrationData['rf-firstname'];

                    if ($meta['gender'] == 'male' || $meta['gender'] == 'female' || $meta['gender'] == 'family') {
                        $meta['name'] = ucfirst($meta['name']);
                    }

                    $alias = $meta['name'];
                }

                $meta['street'] = $registrationData['rf-street'] . ' ' . $registrationData['rf-number'];
                $meta['zip'] = $registrationData['rf-zip'];
                $meta['city'] = $registrationData['rf-city'];
                $meta['phone'] = $registrationData['rf-phone'];

                if (! (isset($registrationData['rf-birthdate']) && preg_match('/^([ \,\-\.0-9\x{00c0}-\x{01ff}a-zA-Z]){4,}$/u', $registrationData['rf-birthdate']))) {
                    $registrationData['rf-birthdate'] = null;
                }

                if (isset($registrationData['rf-birthdate']) && $registrationData['rf-birthdate']) {
                    $meta['birthdate'] = $registrationData['rf-birthdate'];
                }

                $meta['locale'] = $this->config('i18n.locale');

                if ($this->option('service.user.activation') == 'immediate') {
                    $status = 'enabled';
                } else {
                    $status = 'disabled';
                }

                $userManager = $serviceManager->get('User\Manager\UserManager');

                $user = $userManager->create($alias, $status, $registrationData['rf-email1'], $registrationData['rf-pw1'], $meta);
                $user->set('last_ip', $_SERVER['REMOTE_ADDR']);

                $userManager->save($user);

                /* Send confirmation email to administration for manual activation */

                if ($this->option('service.user.activation') == 'manual-email') {
                    $backendMailService = $serviceManager->get('Backend\Service\MailService');
                    $backendMailService->send(
                        $this->t('New registration waiting for activation'),
                        sprintf($this->t('A new user has registered to your %s. According to your configuration, this user will not be able to book %s until you manually activate him.'),
                            $this->option('service.name.full', false), $this->option('subject.square.type.plural', false)));
                }

                /* Send confirmation email to user for activation */

                if ($this->option('service.user.activation') == 'email') {

                    /* Activation code is "created" hash */

                    $activationCode = urlencode( sha1($user->need('created')) );
                    $activationLink = $this->url()->fromRoute('user/activation', [], ['query' => ['id' => $user->need('uid'), 'code' => $activationCode], 'force_canonical' => true]);

                    $subject = sprintf($this->t('Your registration to the %s %s'),
                        $this->option('client.name.short', false), $this->option('service.name.full', false));

                    $text = sprintf($this->t("welcome to the %s %s!\r\n\r\nThank you for your registration to our service.\r\n\r\nBefore you can completely use your new user account to book spare %s online, you have to activate it by simply clicking the following link. That's all!\r\n\r\n%s"),
                        $this->option('client.name.full', false), $this->option('service.name.full', false), $this->option('subject.square.type.plural', false), $activationLink);

                    $userMailService = $serviceManager->get('User\Service\MailService');
                    $userMailService->send($user, $subject, $text);
                }

                return $this->redirect()->toRoute('user/registration-confirmation');
            }
        }

        return array(
            'registrationForm' => $registrationForm,
        );
    }

    public function registrationConfirmationAction()
    {
        return array(
            'activation' => $this->option('service.user.activation', false),
        );
    }

    public function activationAction()
    {
        $activationUid = $this->params()->fromQuery('id');
        $activationCode = urldecode($this->params()->fromQuery('code'));

        if (! (is_numeric($activationUid) && $activationUid > 0)) {
            throw new RuntimeException('Your activation code seems invalid. Please try again.');
        }

        $userManager = @$this->getServiceLocator()->get('User\Manager\UserManager');
        $user = $userManager->get($activationUid, false);

        if (! $user) {
            throw new RuntimeException('Your activation code seems invalid. Please try again.');
        }

        $actualActivationCode = sha1($user->need('created'));

        if ($activationCode != $actualActivationCode) {
            throw new RuntimeException('Your activation code seems invalid. Please try again.');
        }

        $user->set('status', $user->getMeta('status_before_reactivation', 'enabled'));
        $user->set('last_activity', date('Y-m-d H:i:s'));
        $user->set('last_ip', $_SERVER['REMOTE_ADDR']);

        $userManager->save($user);
    }

    public function activationResendAction()
    {
        if ($this->option('service.user.activation') != 'email') {
            throw new RuntimeException('You cannot manually activate your account currently');
        }

        $serviceManager = @$this->getServiceLocator();

        $formElementManager = $serviceManager->get('FormElementManager');

        $activationResendForm = $formElementManager->get('User\Form\ActivationResendForm');
        $activationResendMessage = null;

        if ($this->getRequest()->isPost()) {
            $activationResendForm->setData($this->params()->fromPost());

            if ($activationResendForm->isValid()) {
                $activationResendData = $activationResendForm->getData();

                $userManager = $serviceManager->get('User\Manager\UserManager');
                $user = current( $userManager->getBy(array('email' => $activationResendData['arf-email'])) );

                if ($user) {
                    $mailMessage = $this->t('We have just received your request for a new user account activation email.') . "\r\n\r\n";

                    switch ($user->need('status')) {
                        case 'placeholder':
                            $mailMessage .= $this->t('Unfortunately, your account is considered a placeholder and thus cannot be activated.');
                            break;
                        case 'blocked':
                            $mailMessage .= $this->t('Unfortunately, your account is currently blocked. Please contact us for support.');
                            break;
                        case 'disabled':

                            /* Activation code is "created" hash */

                            $activationCode = urlencode( sha1($user->need('created')) );
                            $activationLink = $this->url()->fromRoute('user/activation', [], ['query' => ['id' => $user->need('uid'), 'code' => $activationCode], 'force_canonical' => true]);

                            $mailMessage .= sprintf($this->t("Before you can completely use your new user account to book spare %s online, you have to activate it by simply clicking the following link. That's all!\r\n\r\n%s"),
                                $this->option('subject.square.type.plural', false), $activationLink);

                            break;
                        case 'enabled':
                        case 'assist':
                        case 'admin':
                            $mailMessage .= $this->t('However, your account has already been activated. You can login whenever you like!');
                            break;
                        default:
                            $mailMessage .= $this->t('Unfortunately, your account seems somewhat unique, thus we are unsure how to treat it. Mind contacting us?');
                            break;
                    }

                    $userMailService = $serviceManager->get('User\Service\MailService');
                    $userMailService->send($user, $this->t('User account activation'), $mailMessage);
                }
            }

            $activationResendForm->get('arf-email')->setValue('');

            $activationResendMessage = sprintf('%s <div class="small-text">(%s)</div>',
                $this->t('All right, you should receive an email from us soon'),
                $this->t('if we find a valid user account with this email address'));
        }

        return array(
            'activationResendForm' => $activationResendForm,
            'activationResendMessage' => $activationResendMessage,
        );
    }

    public function bookingsAction()
    {
        $serviceManager = @$this->getServiceLocator();

        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');
        $squareValidator = $serviceManager->get('Square\Service\SquareValidator');
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');

        // Drinks managers
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $drinkCategoryManager = $serviceManager->get('Drinks\Manager\DrinkCategoryManager');
        $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
        $userManager = $serviceManager->get('User\Manager\UserManager'); // Ensure userManager is defined

        $user = $userSessionManager->getSessionUser();

        if (! $user) {
            $this->redirectBack()->setOrigin('user/bookings');
            return $this->redirect()->toRoute('user/login');
        }

        $bookings = $bookingManager->getByValidity(array('uid' => $user->need('uid')));
        $reservations = $reservationManager->getByBookings($bookings, 'date DESC, time_start DESC');
        $bookingBillManager->getByBookings($bookings);

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
        return array(
            'now' => new \DateTime(),
            'bookings' => $bookings,
            'reservations' => $reservations,
            'squareManager' => $squareManager,
            'squareValidator' => $squareValidator,
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
        );
    }

    public function moneyRecipientTeamEventsAction()
    {
        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $serviceManager = @$this->getServiceLocator();
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

        return $this->getResponse()->setContent(json_encode([
            'success' => true,
            'is_team' => true,
            'team_events' => $this->getTeamEventsWithBalances($receiverUserId),
        ]));
    }

    public function billsAction()
    {
        $bid = $this->params()->fromRoute('bid');

        $serviceManager = @$this->getServiceLocator();

        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();

        if (! $user) {
            $this->redirectBack()->setOrigin('user/bookings/bills', ['bid' => $bid]);

            return $this->redirect()->toRoute('user/login');
        }

        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
        $bookingStatusService = $serviceManager->get('Booking\Service\BookingStatusService');

        $booking = $bookingManager->get($bid);
        $bookingBillingStatus = $bookingStatusService->getStatusTitle($booking->getBillingStatus());

        if ($booking->get('uid') != $user->get('uid')) {
            if (! $user->can('admin.booking')) {
                throw new RuntimeException('You have no permission for this');
            }
        }

        $bills = $bookingBillManager->getBy(array('bid' => $bid), 'bbid ASC');

        return array(
            'booking' => $booking,
            'bookingBillingStatus' => $bookingBillingStatus,
            'bills' => $bills,
            'user' => $user,
        );
    }

    public function settingsAction()
    {
        $serviceManager = @$this->getServiceLocator();

        $userManager = $serviceManager->get('User\Manager\UserManager');
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $formElementManager = $serviceManager->get('FormElementManager');

        $user = $userSessionManager->getSessionUser();

        if (! $user) {
            $this->redirectBack()->setOrigin('user/settings');

            return $this->redirect()->toRoute('user/login');
        }

        $editParam = $this->params()->fromQuery('edit');

        /* Phone form */

        $editPhoneForm = $formElementManager->get('User\Form\EditPhoneForm');

        if ($this->getRequest()->isPost() && $editParam == 'phone') {
            $editPhoneForm->setData($this->params()->fromPost());

            if ($editPhoneForm->isValid()) {
                $data = $editPhoneForm->getData();

                $phone = $data['epf-phone'];

                $user->setMeta('phone', $phone);
                $userManager->save($user);

                $this->flashMessenger()->addSuccessMessage(sprintf($this->t('Your %sphone number%s has been updated'), '<b>', '</b>'));

                return $this->redirect()->toRoute('user/settings');
            }
        } else {
            $editPhoneForm->get('epf-phone')->setValue($user->getMeta('phone'));
        }

        /* Email form */

        $editEmailForm = $formElementManager->get('User\Form\EditEmailForm');

        if ($this->getRequest()->isPost() && $editParam == 'email') {
            $editEmailForm->setData($this->params()->fromPost());

            if ($editEmailForm->isValid()) {
                $data = $editEmailForm->getData();

                $email = $data['eef-email1'];

                $user->set('email', $email);

                if ($this->option('service.user.activation') == 'email') {

                    $user->setMeta('status_before_reactivation',
                        $user->get('status'));

                    $user->set('status', 'disabled');

                    /* Activation code is "created" hash */

                    $activationCode = urlencode( sha1($user->need('created')) );
                    $activationLink = $this->url()->fromRoute('user/activation', [], ['query' => ['id' => $user->need('uid'), 'code' => $activationCode], 'force_canonical' => true]);

                    $subject = sprintf($this->t('New email address at %s %s'),
                        $this->option('client.name.short', false), $this->option('service.name.full', false));

                    $text = sprintf($this->t("You have just changed your account's email address to this one.\r\n\r\nBefore you can completely use your new email address to book spare %s online again, you have to activate it by simply clicking the following link. That's all!\r\n\r\n%s"),
                        $this->option('subject.square.type.plural', false), $activationLink);

                    $userMailService = $serviceManager->get('User\Service\MailService');
                    $userMailService->send($user, $subject, $text);
                }

                $userManager->save($user);

                $this->flashMessenger()->addSuccessMessage(sprintf($this->t('Your %semail address%s has been updated'), '<b>', '</b>'));

                return $this->redirect()->toRoute('user/settings');
            }
        } else {
            $editEmailForm->get('eef-email1')->setValue($user->get('email'));
            $editEmailForm->get('eef-email2')->setValue($user->get('email'));
        }

        /* Notifications form */

        $editNotificationsForm = $formElementManager->get('User\Form\EditNotificationsForm');

        if ($this->getRequest()->isPost() && $editParam == 'notifications') {
            $editNotificationsForm->setData($this->params()->fromPost());

            if ($editNotificationsForm->isValid()) {
                $data = $editNotificationsForm->getData();

                $bookingNotifications = $data['enf-booking-notifications'];

                $user->setMeta('notification.bookings', $bookingNotifications);

                $userManager->save($user);

                $this->flashMessenger()->addSuccessMessage(sprintf($this->t('Your %snotification settings%s have been updated'), '<b>', '</b>'));

                return $this->redirect()->toRoute('user/settings');
            }
        } else {
            $editNotificationsForm->get('enf-booking-notifications')->setValue($user->getMeta('notification.bookings', 'true'));
        }

        /* Password form */

        $editPasswordForm = $formElementManager->get('User\Form\EditPasswordForm');

        if ($this->getRequest()->isPost() && $editParam == 'password') {
            $editPasswordForm->setData($this->params()->fromPost());

            if ($editPasswordForm->isValid()) {
                $data = $editPasswordForm->getData();

                $passwordCurrent = $data['epf-pw-current'];
                $passwordNew = $data['epf-pw1'];

                $bcrypt = new Bcrypt();
                $bcrypt->setCost(6);

                if ($bcrypt->verify($passwordCurrent, $user->need('pw'))) {

                    $user->set('pw', $bcrypt->create($passwordNew));
                    $userManager->save($user);

                    $this->flashMessenger()->addSuccessMessage(sprintf($this->t('Your %spassword%s has been updated'), '<b>', '</b>'));

                    return $this->redirect()->toRoute('user/settings');
                } else {
                    $editPasswordForm->get('epf-pw-current')->setMessages(array('This is not your correct password'));
                }
            }
        }

        /* Delete account form */

        $deleteAccountForm = $formElementManager->get('User\Form\DeleteAccountForm');
        $deleteAccountMessage = null;

        if ($this->getRequest()->isPost() && $editParam == 'delete') {
            $deleteAccountForm->setData($this->params()->fromPost());

            if ($deleteAccountForm->isValid()) {
                $data = $deleteAccountForm->getData();

                $why = $data['daf-why'];
                $passwordCurrent = $data['daf-pw-current'];

                $bcrypt = new Bcrypt();
                $bcrypt->setCost(6);

                if ($bcrypt->verify($passwordCurrent, $user->need('pw'))) {

                    $user->set('status', 'deleted');
                    $user->set('last_activity', date('Y-m-d H:i:s'));
                    $user->set('last_ip', $_SERVER['REMOTE_ADDR']);

                    if ($why) {
                        $user->setMeta('deletion.reason', $why);
                    }

                    $userManager->save($user);
                    $userSessionManager->logout();

                    $deleteAccountMessage = sprintf($this->t('Your %suser account has been deleted%s. Good bye!'), '<b>', '</b>');
                } else {
                    $editPasswordForm->get('epf-pw-current')->setMessages(array('This is not your correct password'));
                }
            }
        }

        /* Drinks Alias form */
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $userId = $user->need('uid');
        // Load current alias, enabled flag, and order email option
        $aliasRow = $dbAdapter->query('SELECT alias, enabled, order_email_option FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        $currentAlias = $aliasRow ? $aliasRow['alias'] : '';
        $currentOrderEmail = $aliasRow && isset($aliasRow['order_email_option']) ? $aliasRow['order_email_option'] : '';
        $drinksEnabled = ($aliasRow && isset($aliasRow['enabled']) && (int)$aliasRow['enabled'] === 1);
        $editDrinksAliasForm = null;
        if ($drinksEnabled) {
            $editDrinksAliasForm = $formElementManager->get('User\Form\EditDrinksAliasForm');
            $editDrinksAliasForm->init();
            if ($this->getRequest()->isPost() && $editParam == 'drinks-alias') {
                $editDrinksAliasForm->setData($this->params()->fromPost());
                if ($editDrinksAliasForm->isValid()) {
                    $data = $editDrinksAliasForm->getData();
                    $alias = $data['edaf-alias'];
                    $orderEmail = isset($data['edaf-order-email']) ? $data['edaf-order-email'] : null;
                    // Check uniqueness again in controller (defense-in-depth)
                    $existing = $dbAdapter->query('SELECT user_id FROM drink_aliases WHERE alias = ? AND user_id != ?', [$alias, $userId])->current();
                    if ($existing) {
                        $editDrinksAliasForm->get('edaf-alias')->setMessages([$this->t('Diese Theken-ID ist bereits vergeben.')]);
                    } else {
                        // Upsert alias and order email option
                        $dbAdapter->query('INSERT INTO drink_aliases (user_id, alias, order_email_option) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE alias = VALUES(alias), order_email_option = VALUES(order_email_option)', [$userId, $alias, $orderEmail]);
                        $this->flashMessenger()->addSuccessMessage($this->t('Theken-ID und Bestell-Email-Option wurden gespeichert.'));
                        return $this->redirect()->toRoute('user/settings');
                    }
                }
            } else {
                $editDrinksAliasForm->get('edaf-alias')->setValue($currentAlias);
                // If user is in DB and order_email_option is empty, set default to 'order'
                if ($aliasRow && ($currentOrderEmail === null || $currentOrderEmail === '')) {
                    $editDrinksAliasForm->get('edaf-order-email')->setValue('order');
                } else {
                    $editDrinksAliasForm->get('edaf-order-email')->setValue($currentOrderEmail);
                }
            }
        }

        return array(
            'user' => $user,
            'editDrinksAliasForm' => $editDrinksAliasForm,
            'editPhoneForm' => $editPhoneForm,
            'editEmailForm' => $editEmailForm,
            'editNotificationsForm' => $editNotificationsForm,
            'editPasswordForm' => $editPasswordForm,
            'deleteAccountForm' => $deleteAccountForm,
            'deleteAccountMessage' => $deleteAccountMessage,
        );
    }

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
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $drinkDepositManager = $serviceManager->get('Drinks\Manager\DrinkDepositManager');
        $users = $userManager->getAll('alias ASC');
        $message = null;
        $uploadDir = realpath(__DIR__ . '/../../../../../public/imgs/branding');
        $drinkCategories = $drinkCategoryManager->getAll();
        // Handle add/edit/delete/deposit
        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $files = $this->getRequest()->getFiles()->toArray();
            if (isset($post['add_drink'])) {
                $name = trim($post['name']);
                $price = floatval($post['price']);
                $category = isset($post['category']) ? (int)$post['category'] : null;
                $imageName = null;
                if (!empty($files['image']['tmp_name']) && is_uploaded_file($files['image']['tmp_name'])) {
                    $ext = pathinfo($files['image']['name'], PATHINFO_EXTENSION);
                    $imageName = uniqid('drink_', true) . '.' . $ext;
                    move_uploaded_file($files['image']['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $imageName);
                }
                if ($name && $price > 0) {
                    $dbAdapter->query('INSERT INTO drinks (name, price, image, category) VALUES (?, ?, ?, ?)', [$name, $price, $imageName, $category]);
                    $message = 'Drink added.';
                }
            } elseif (isset($post['edit_drink'])) {
                $id = intval($post['id']);
                $name = trim($post['name']);
                $price = floatval($post['price']);
                $category = isset($post['category']) ? (int)$post['category'] : null;
                $imageName = $post['existing_image'] ?? null;
                if (!empty($files['image']['tmp_name']) && is_uploaded_file($files['image']['tmp_name'])) {
                    $ext = pathinfo($files['image']['name'], PATHINFO_EXTENSION);
                    $imageName = uniqid('drink_', true) . '.' . $ext;
                    move_uploaded_file($files['image']['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $imageName);
                }
                if ($id && $name && $price > 0) {
                    $dbAdapter->query('UPDATE drinks SET name = ?, price = ?, image = ?, category = ? WHERE id = ?', [$name, $price, $imageName, $category, $id]);
                    $message = 'Drink updated.';
                }
            } elseif (isset($post['delete_drink'])) {
                $id = intval($post['id']);
                if ($id) {
                    $dbAdapter->query('DELETE FROM drinks WHERE id = ?', [$id]);
                    $message = 'Drink deleted.';
                }
            }
        }
        $drinks = $drinkManager->getAll();
        return [
            'drinks' => $drinks,
            'users' => $users,
            'message' => $message,
            'dbAdapter' => $dbAdapter,
            'drinkCategories' => $drinkCategories,
        ];
    }

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
        return [
            'partyModeEnabled' => $partyModeEnabled,          // raw stored value for checkbox
            'partyModeActive' => $partyModeActiveComputed,    // computed active (may be false outside window)
            'partyModeMessage' => $partyModeMessage,
            'partyModeStart' => $partyModeStart,
            'partyModeEnd' => $partyModeEnd,
        ];
    }

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
                        $mailService->sendFromTheke($empfaenger, $subject, $body, ['isHtml' => true]);
                    } catch (\Exception $e) {
                        error_log('Fehler beim Senden der Einzahlungsbenachrichtigung: ' . $e->getMessage());
                    }
                    return $this->redirect()->toRoute(null, [], ['query' => ['message' => 'Deposit added.']], true);
                } else {
                    $message = 'Invalid deposit data.';
                }
            }
        }
        return [
            'users' => $users,
            'drinks' => $drinks,
            'message' => $message,
        ];
    }

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
        $drinksAliasRow = $dbAdapter->query('SELECT enabled, alias, thekenadmin, is_team, order_email_option FROM drink_aliases WHERE user_id = ?', [$uid])->current();
		    $drinksEnabled = $drinksAliasRow ? (bool)$drinksAliasRow['enabled'] : false;
		    $drinksAlias = $drinksAliasRow ? $drinksAliasRow['alias'] : null;
		    $thekenadmin = ($drinksAliasRow && isset($drinksAliasRow['thekenadmin']) && (int)$drinksAliasRow['thekenadmin'] === 1);
		    $isTeam = ($drinksAliasRow && isset($drinksAliasRow['is_team']) && (int)$drinksAliasRow['is_team'] === 1);
            $orderEmailOption = ($drinksAliasRow && isset($drinksAliasRow['order_email_option']) && $drinksAliasRow['order_email_option'] !== '') ? $drinksAliasRow['order_email_option'] : 'order';
        $teamEvents = [];
        $teamEventLabelById = [];
        $latestTeamEventId = null;
        $currentTeamEventId = null;
        $preferredTeamEventId = (int)$this->params()->fromQuery('selected_teamevent_id', 0);
        if ($isTeam) {
            $eventRows = $dbAdapter->query('SELECT id, comment FROM drinks_teamevents WHERE team_admin_user_id = ? ORDER BY created_at DESC, id DESC', [$uid])->toArray();
            foreach ($eventRows as $eventRow) {
                $eventId = isset($eventRow['id']) ? (int)$eventRow['id'] : 0;
                $label = isset($eventRow['comment']) ? trim((string)$eventRow['comment']) : '';
                if ($label === '') {
                    continue;
                }
                $teamEvents[] = [
                    'id' => $eventId,
                    'label' => $label,
                ];
                if ($eventId > 0) {
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
                'id' => isset($d['id']) ? (int)$d['id'] : null, // Always include deposit id
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
                'id' => isset($o['id']) ? (int)$o['id'] : null, // Always include order id
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
            'team_events' => $teamEvents,
            'current_teamevent_id' => $currentTeamEventId,
        ]));
    }

    public function drinksSummaryAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        $isSimple = false;
        $thekenadmin = false;
        // Check for simple user session
        $simpleSession = null;
        if (!$user || $user->get('status') !== 'admin') {
            // Try to get simple user session
            if (class_exists('Zend\Session\Container')) {
                $simpleSession = new \Zend\Session\Container('SimpleLogin');
                if (!empty($simpleSession->user_id)) {
                    $isSimple = true;
                    // Check thekenadmin flag for simple user
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

        // ------------------------------------------------------------------
        // Last global check + personal last check (clean version)
        // ------------------------------------------------------------------
        $lastCheckDate = null;
        $lastCheckUserName = null;
        $lastUserCheckDate = null; // specific to current (admin/simple) user

        // Helper (kept minimal) to safely extract a field from array / ArrayAccess
        $gf = function($row, $key) {
            if (!$row) return null;
            if (is_array($row)) return array_key_exists($key, $row) ? $row[$key] : null;
            if ($row instanceof \ArrayAccess && isset($row[$key])) return $row[$key];
            if (is_object($row) && isset($row->$key)) return $row->$key;
            $tmp = (array)$row;
            return array_key_exists($key, $tmp) ? $tmp[$key] : null;
        };

        try {
            // Single joined query: prefer user.alias, fallback to drink_aliases.alias
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
                    // Final cheap fallback: look up drink_aliases (covers rare mismatch)
                    $fallbackAliasRow = $dbAdapter->query('SELECT alias FROM drink_aliases WHERE user_id = ? LIMIT 1', [$uid])->current();
                    $fa = $gf($fallbackAliasRow, 'alias');
                    $lastCheckUserName = $fa ?: ('UID ' . $uid);
                }
            }
        } catch (\Exception $e) { /* ignore */ }

        // Personal (current actor) last check (admin or simple session user)
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

        // Simple normalization: accept values with or without 'T' and with or without seconds.
        // Store internally (for filtering) as space separated with seconds; present to view in original style (minutes precision, 'T').
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
            // cut to minutes and re-add T
            return str_replace(' ', 'T', substr($n, 0, 16));
        };
        $lastCheckNormalized = $normalize($lastCheckDate);
        $lastUserCheckNormalized = $normalize($lastUserCheckDate);
        $lastCheckDisplay = $formatMinutesT($lastCheckNormalized);
        $lastUserCheckDisplay = $formatMinutesT($lastUserCheckNormalized);

        // Relative age helper (returns compact string like 2h 13m, 3d 4h, etc.)
        $relativeAge = function($dt) {
            if (!$dt) return null;
            try {
                $now = new \DateTime();
                $base = new \DateTime($dt); // $dt already normalized to Y-m-d H:i:s or similar
            } catch (\Exception $e) { return null; }
            $diff = $now->getTimestamp() - $base->getTimestamp();
            if ($diff < 0) $diff = 0; // future safeguard
            $seconds = $diff;
            $minutes = (int) floor($seconds / 60);
            $hours = (int) floor($minutes / 60);
            $days = (int) floor($hours / 24);
            $minutesR = $minutes % 60;
            $hoursR = $hours % 24;
            // Days handling
            if ($days > 0) {
                // Up to 14 days show day+hours, afterwards just days
                if ($days <= 14) {
                    $s = $days . 'd';
                    if ($hoursR > 0) $s .= ' ' . $hoursR . 'h';
                    return $s;
                }
                return $days . 'd';
            }
            // Hours handling
            if ($hours > 0) {
                $s = $hours . 'h';
                if ($minutesR > 0) $s .= ' ' . $minutesR . 'm';
                return $s;
            }
            // Minutes handling
            if ($minutes > 0) {
                $s = $minutes . 'm';
                $secondsR = $seconds % 60;
                if ($minutes < 5 && $secondsR > 0) $s .= ' ' . $secondsR . 's';
                return $s;
            }
            // Seconds (< 60s)
            return '0m';
        };

        $lastCheckRelative = $relativeAge($lastCheckNormalized);
        $lastUserCheckRelative = $relativeAge($lastUserCheckNormalized);

        // Apply quick range logic if provided (server-side fallback when front-end redirect not executed)
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
            } elseif ($quick === 'cw' || $quick === 'lw') { // current week / last week (Mon-Sun)
                $monday = clone $now;
                $dow = (int)$monday->format('N'); // 1=Mon
                $monday->modify('-' . ($dow - 1) . ' days');
                if ($quick === 'lw') $monday->modify('-7 days');
                $sunday = clone $monday; $sunday->modify('+6 days');
                if (empty($from)) $from = $monday->format('Y-m-d') . ' 00:00:00';
                if (empty($to)) $to = $sunday->format('Y-m-d') . ' 23:59:59';
            } elseif ($quick === 'cm' || $quick === 'lm') { // current month / last month
                $year = (int)$now->format('Y');
                $month = (int)$now->format('n');
                if ($quick === 'lm') {
                    $month -= 1; if ($month === 0) { $month = 12; $year -= 1; }
                }
                $first = new \DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
                $last = clone $first; $last->modify('+1 month -1 second');
                if (empty($from)) $from = $first->format('Y-m-d H:i:s');
                if (empty($to)) $to = $last->format('Y-m-d H:i:s');
            } elseif ($quick === 'cy' || $quick === 'ly') { // current year / last year
                $year = (int)$now->format('Y');
                if ($quick === 'ly') $year -= 1;
                if (empty($from)) $from = sprintf('%04d-01-01 00:00:00', $year);
                if (empty($to)) $to = sprintf('%04d-12-31 23:59:59', $year);
            }
        }

        // Normalize incoming from/to after quick logic
    $from = $normalize($from);
    $to = $normalize($to);

        // Query all drink orders, grouped by date, user, drink
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
                WHERE deleted = 0';
        $params = [];
    if ($from) {
            // Treat $from as local time, no conversion
            $sql .= ' AND order_time >= ?';
            $params[] = $from;
        }
    if ($to) {
            // Treat $to as local time, no conversion
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
        // Build lookup: [group][user_id][drink_id] = ...
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

        $showUsers = $this->params()->fromQuery('show_users', '1');
        $showEmptyCols = $this->params()->fromQuery('show_emptycols', '0');

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
            // Simplified display variants (old style format, minutes, with T)
            'lastCheckDateDisplay' => $lastCheckDisplay,
            'lastUserCheckDateDisplay' => $lastUserCheckDisplay,
            'lastCheckRelative' => $lastCheckRelative,
            'lastUserCheckRelative' => $lastUserCheckRelative,
            // debug variables removed from final return
        ];
        if ($isSimple && $thekenadmin) {
            $viewVars['simpleOrderMode'] = true;
        }
        return $viewVars;
    }

    /**
     * AJAX: Store a new drink check event in drink_checks (replaces theke.last.check.date option)
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
                // Figure out user performing the check (admin or simple session)
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
                    // Normalize datetime (replace T from possible HTML5 input) & ensure seconds
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
}
