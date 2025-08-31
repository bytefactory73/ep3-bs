<?php

namespace User\Controller;

use DateTime;
use RuntimeException;
use Zend\Crypt\Password\Bcrypt;
use Zend\Mvc\Controller\AbstractActionController;

class AccountController extends AbstractActionController
{
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
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $mailService = $serviceManager->get('User\Service\MailService');
        if ($entryType === 'deposit') {
            $row = $dbAdapter->query('SELECT * FROM drink_deposits WHERE id = ?', [$entryId])->current();
            if ($row) {
                $newDeleted = empty($row['deleted']) ? 1 : 0;
                if ($newDeleted) {
                    $dbAdapter->query('UPDATE drink_deposits SET deleted = 1, user_id_deleted = ? WHERE id = ?', [$admin->get('uid'), $entryId]);
                } else {
                    $dbAdapter->query('UPDATE drink_deposits SET deleted = 0, user_id_deleted = NULL WHERE id = ?', [$entryId]);
                }
                // Send notification email to user
                $user = $userManager->get($row['user_id']);
                if ($user) {
                    // Calculate new balance
                    $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
                    $balance = $drinkManager->calculateUserDrinkBalance($row['user_id'], $serviceManager);
                    $action = $newDeleted ? 'storniert' : 'wiederhergestellt';
                    $subject = 'Einzahlung ' . ucfirst($action);
                    $adminName = '';
                    if (isset($admin) && $admin) {
                        $adminName = $admin->get('alias') ?: $admin->get('name');
                    }
                    if (!$adminName) {
                        $adminName = 'Administrator';
                    }
                    $body = 'Ihre Einzahlung am ' . $row['deposit_time'] . ' wurde von ' . htmlspecialchars($adminName) . ' ' . $action . '.<br><br>Kontostand nach Änderung: <b>' . number_format($balance, 2, ',', '.') . ' EUR</b>';
                    $mailService->sendFromTheke($user, $subject, $body, ['isHtml' => true]);
                }
                return $this->getResponse()->setContent(json_encode(['success' => true]));
            }
        } elseif ($entryType === 'order') {
            $row = $dbAdapter->query('SELECT * FROM drink_orders WHERE id = ?', [$entryId])->current();
            if ($row) {
                $newDeleted = empty($row['deleted']) ? 1 : 0;
                if ($newDeleted) {
                    $dbAdapter->query('UPDATE drink_orders SET deleted = 1, user_id_deleted = ? WHERE id = ?', [$admin->get('uid'), $entryId]);
                } else {
                    $dbAdapter->query('UPDATE drink_orders SET deleted = 0, user_id_deleted = NULL WHERE id = ?', [$entryId]);
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
                        $subject = 'Buchung ' . ucfirst($action) . ' (Admin)';
                        $label = '';
                        if ((int)$row['drink_id'] === 1) {
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
                        $body = 'Ihre Getränkebuchung (' . $label . ') am ' . $row['order_time'] . ' wurde von ' . htmlspecialchars($adminName) . ' ' . $action . '.<br><br>Kontostand nach Änderung: <b>' . number_format($balance, 2, ',', '.') . ' EUR</b>';
                        $mailService->sendFromTheke($user, $subject, $body, ['isHtml' => true]);
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
            try {
                foreach ($orders as $order) {
                    $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : 0;
                    $count = isset($order['count']) ? (int)$order['count'] : 1;
                    $comment = isset($order['comment']) ? $order['comment'] : null;
                    if ($drinkId && $count > 0) {
                        if ($drinkId === 1 && isset($order['price'])) {
                            $customPrice = (float)$order['price'];
                            $drinkOrderManager->addOrder($uid, $drinkId, $count, $admin ? $admin->get('uid') : null, 0, $comment, $customPrice);
                        } else {
                            $drinkOrderManager->addOrder($uid, $drinkId, $count, $admin ? $admin->get('uid') : null, 0, $comment);
                        }
                    }
                }
                // Send notification email to user (HTML) only if order_email_option allows
                $drinks = [];
                $total = 0;
                foreach ($orders as $order) {
                    $drink = $drinkManager->get($order['drink_id']);
                    if ($drink) {
                        if ((int)$order['drink_id'] === 1) {
                            // Fallback to drink name if comment is empty
                            $drinkName = $drink ? $drink['name'] : $order['drink_id'];
                            $label = '';
                            if ((int)$order['count'] > 1) {
                                $label = $order['count'] . 'x ';
                            }
                            $comment = isset($order['comment']) ? trim((string)$order['comment']) : '';
                            $label .= ($comment !== '') ? $comment : $drinkName;
                            // Use custom price from order for id==1
                            $customPrice = isset($order['price']) ? (float)$order['price'] : (float)$drink['price'];
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
                $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
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
            if (!$uid || !$drinkId || $count < 1) {
                return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['success' => false, 'error' => 'Invalid input']));
            }
            $userManager = $serviceManager->get('User\Manager\UserManager');
            $user = $userManager->get($uid);
            if (!$user) {
                return $this->getResponse()->setStatusCode(404)->setContent(json_encode(['success' => false, 'error' => 'User not found']));
            }
            $drinkOrderManager = $serviceManager->get('Drinks\Manager\DrinkOrderManager');
            try {
                $drinkOrderManager->addOrder($uid, $drinkId, $count, $admin ? $admin->get('uid') : null);
            } catch (\Exception $e) {
                return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['success' => false, 'error' => $e->getMessage()]));
            }
            return $this->getResponse()->setContent(json_encode(['success' => true]));
        }
    }
    /**
     * AJAX endpoint to update drinks_enabled and drinks_alias for a user
     * POST: uid, drinks_enabled (bool), drinks_alias (string)
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
                    $columns = array_merge(['user_id'], $columns);
                    $values = array_merge([$uid], $values);
                    $sql = 'INSERT INTO drink_aliases (' . implode(', ', $columns) . ') VALUES (' . rtrim(str_repeat('?, ', count($columns)), ', ') . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
                    $dbAdapter->query($sql, $values);
                }
            } else {
                // Insert: require both fields
                if ($drinksAlias === null && $drinksEnabled === null) {
                    return $this->getResponse()->setStatusCode(400)->setContent(json_encode(['error' => 'Alias and enabled required for new entry']));
                }
                $enabledVal = ($drinksEnabled === '1' || $drinksEnabled === 1 || $drinksEnabled === true || $drinksEnabled === 'true') ? 1 : 0;
                $dbAdapter->query('INSERT INTO drink_aliases (user_id, alias, enabled) VALUES (?, ?, ?)', [$uid, $drinksAlias, $enabledVal]);
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
        $aliasRow = $dbAdapter->query('SELECT enabled FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        $drinksEnabled = ($aliasRow && isset($aliasRow['enabled']) && (int)$aliasRow['enabled'] === 1);
        // Merge and sort by date descending
        $drinkHistory = [];
        foreach ($drinkOrders as $order) {
            $deleted = isset($order['deleted']) ? (int)$order['deleted'] : 0;
            $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : null;
            $quantity = isset($order['quantity']) ? (int)$order['quantity'] : 1;
            $comment = isset($order['comment']) ? trim((string)$order['comment']) : '';
            $drinkName = $order['name'];
            // Always ensure for id==1 (custom drink): if comment is empty, use drink name as fallback
            if ($drinkId === 1 && $comment === '') {
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
        );
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

    public function drinksAdminAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        if (!$user || $user->get('status') !== 'admin') {
            return $this->redirect()->toRoute('user/settings');
        }
        // Just render the new landing page
        return [];
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
                $createdByUserId = $user ? $user->need('uid') : null;
                if ($depositUserId > 0 && $depositAmount > 0) {
                    $serviceManager->get('Drinks\Manager\DrinkDepositManager')->addDeposit($depositUserId, $depositAmount, $depositComment, $createdByUserId);
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
        $drinksAliasRow = $dbAdapter->query('SELECT enabled, alias FROM drink_aliases WHERE user_id = ?', [$uid])->current();
        $drinksEnabled = $drinksAliasRow ? (bool)$drinksAliasRow['enabled'] : false;
        $drinksAlias = $drinksAliasRow ? $drinksAliasRow['alias'] : null;

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
            ];
        }
        foreach ($orders as $o) {
            $drinkId = isset($o['drink_id']) ? (int)$o['drink_id'] : null;
            $comment = isset($o['comment']) ? trim((string)$o['comment']) : '';
            $drinkName = isset($o['name']) ? $o['name'] : '';
            $desc = $o['quantity'] . ' x ' . $drinkName;
            // For id==1, if comment is empty, use drink name as fallback for desc and comment
            if ($drinkId === 1 && $comment === '') {
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
        ]));
    }

    public function drinksSummaryAction()
    {
        $serviceManager = @$this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();
        if (!$user || $user->get('status') !== 'admin') {
            return $this->redirect()->toRoute('user/settings');
        }
        $drinkManager = $serviceManager->get('Drinks\Manager\DrinkManager');
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $drinks = iterator_to_array($drinkManager->getAll());
        $users = $userManager->getAll('alias ASC');

        $group = $this->params()->fromQuery('group', 'date');
        $from = $this->params()->fromQuery('from');
        $to = $this->params()->fromQuery('to');
        $showUsers = $this->params()->fromQuery('show_users', '1');
        $showEmptyCols = $this->params()->fromQuery('show_emptycols', '0');

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
            $sql .= ' AND DATE(order_time) >= ?';
            $params[] = $from;
        }
        if ($to) {
            $sql .= ' AND DATE(order_time) <= ?';
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

        return [
            'drinks' => $drinks,
            'users' => $users,
            'orders' => $orderMap,
            'mode' => $this->params()->fromQuery('mode', 'count'),
            'from' => $from,
            'to' => $to,
            'show_users' => $showUsers,
            'show_emptycols' => $showEmptyCols,
            'group' => $group,
        ];
    }
}
