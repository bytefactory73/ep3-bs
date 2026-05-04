<?php
namespace User\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class SimpleLoginController extends AbstractActionController
{
    /**
     * Number of hours to look back for recent orders
     */
    const RECENT_ORDERS_CUTOFF_HOURS = 2;

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
        $drinkHistory = [];
        foreach ($drinkDeposits as $deposit) {
            $drinkHistory[] = [
                'type' => 'deposit',
                'amount' => $deposit['amount'],
                'created_at' => $deposit['deposit_time'],
                'datetime' => $deposit['deposit_time'],
                'id' => $deposit['id'],
            ];
        }
        foreach ($drinkOrders as $order) {
            $drinkId = isset($order['drink_id']) ? (int)$order['drink_id'] : null;
            $comment = isset($order['comment']) ? trim((string)$order['comment']) : '';
            $drinkName = $order['name'];
            // Always ensure for id==1 (custom drink): if comment is empty, use drink name as fallback
            if ($drinkId === 1 && $comment === '') {
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
            ];
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
            'partyModeEnabled' => $partyModeEnabled,
            'partyModeMessage' => $partyModeMessage,
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
        $result = $drinkManager->addOrdersAndNotify($user, $drinkCounts, [$this, 't'], $this->getServiceLocator(), $isAutoOrder);
        if ($result['success']) {
            return $this->getResponse()->setContent(json_encode(['success' => true, 'balance' => $result['balance']]))->setStatusCode(200);
        }
        return $this->getResponse()->setContent(json_encode(['success' => false, 'error' => $result['error']]))->setStatusCode(400);
    }
}
