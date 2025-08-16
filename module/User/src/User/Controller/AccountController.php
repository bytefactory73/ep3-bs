<?php

namespace User\Controller;

use DateTime;
use RuntimeException;
use Zend\Crypt\Password\Bcrypt;
use Zend\Mvc\Controller\AbstractActionController;

class AccountController extends AbstractActionController
{
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
            $drinkOrderManager->addOrder($uid, $drinkId, $count);
        } catch (\Exception $e) {
            return $this->getResponse()->setStatusCode(500)->setContent(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
        return $this->getResponse()->setContent(json_encode(['success' => true]));
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
            $drinkHistory[] = [
                'type' => 'order',
                'id' => $order['id'],
                'name' => $order['name'],
                'quantity' => $order['quantity'],
                'price' => $order['price'],
                'total' => $order['quantity'] * $order['price'],
                'datetime' => $order['order_time'],
                'deleted' => $deleted,
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
        // Load current alias and enabled flag
        $aliasRow = $dbAdapter->query('SELECT alias, enabled FROM drink_aliases WHERE user_id = ?', [$userId])->current();
        $currentAlias = $aliasRow ? $aliasRow['alias'] : '';
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
                    // Check uniqueness again in controller (defense-in-depth)
                    $existing = $dbAdapter->query('SELECT user_id FROM drink_aliases WHERE alias = ? AND user_id != ?', [$alias, $userId])->current();
                    if ($existing) {
                        $editDrinksAliasForm->get('edaf-alias')->setMessages([$this->t('Diese Theken-ID ist bereits vergeben.')]);
                    } else {
                        // Upsert alias
                        $dbAdapter->query('INSERT INTO drink_aliases (user_id, alias) VALUES (?, ?) ON DUPLICATE KEY UPDATE alias = VALUES(alias)', [$userId, $alias]);
                        $this->flashMessenger()->addSuccessMessage($this->t('Theken-ID wurde gespeichert.'));
                        return $this->redirect()->toRoute('user/settings');
                    }
                }
            } else {
                $editDrinksAliasForm->get('edaf-alias')->setValue($currentAlias);
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

        $result = $drinkManager->addOrdersAndNotify($user, $drinkCounts, [$this, 't'], $serviceManager);

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

        $orders = iterator_to_array($drinkOrderManager->getByUser($uid));
        $deposits = iterator_to_array($drinkDepositManager->getByUser($uid));
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
                'amount' => $d['amount'],
                'desc' => $d['comment'],
                'datetime' => $d['deposit_time'],
                'deleted' => 0,
                'createdby' => $creatorName,
            ];
        }
        foreach ($orders as $o) {
            $history[] = [
                'type' => empty($o['deleted']) ? 'Buchung' : 'Storno',
                'amount' => -1 * $o['quantity'] * $o['price'],
                'desc' => $o['quantity'] . ' x ' . $o['name'],
                'datetime' => $o['order_time'],
                'deleted' => empty($o['deleted']) ? 0 : 1,
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
        $sql = 'SELECT ' . $groupSql . ' as grp, user_id, drink_id, SUM(quantity) as quantity, MIN(order_time) as min_time
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
            $amount = $count * ($drinkPriceMap[$did] ?? 0);
            $orderMap[$grp][$uid][$did] = [
                'count' => $count > 0 ? $count : '',
                'amount' => $count > 0 ? number_format($amount, 2, ',', '.') : '',
            ];
        }

        $showUsers = $this->params()->fromQuery('show_users', '1');

        return [
            'drinks' => $drinks,
            'users' => $users,
            'orders' => $orderMap,
            'mode' => $this->params()->fromQuery('mode', 'count'),
            'from' => $from,
            'to' => $to,
            'show_users' => $showUsers,
            'group' => $group,
        ];
    }
}
