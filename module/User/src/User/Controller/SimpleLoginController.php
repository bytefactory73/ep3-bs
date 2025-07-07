<?php
namespace User\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Stdlib\Parameters;

class SimpleLoginController extends AbstractActionController
{
    public function loginAction()
    {
        $request = $this->getRequest();
        $error = null;
        // Ensure session manager is started
        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        // Fetch last 24h drink orders for the history box
        $recentOrders = [];
        try {
            $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
            $cutoff = (new \DateTime('-48 hours'))->format('Y-m-d H:i:s');
            $sql = 'SELECT o.order_time, o.user_id, u.alias, d.name AS drink_name, o.quantity, o.deleted FROM drink_orders o JOIN bs_users u ON o.user_id = u.uid JOIN drinks d ON o.drink_id = d.id WHERE o.deleted = false AND o.order_time >= ? ORDER BY o.order_time DESC';
            $recentOrders = $db->query($sql, [$cutoff])->toArray();
        } catch (\Exception $e) {
            $recentOrders = [];
        }
        if ($request->isPost()) {
            $alias = trim($request->getPost('alias'));
            if ($alias) {
                $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
                $row = $db->query('SELECT user_id FROM drink_aliases WHERE alias = ?', [$alias])->current();
                if ($row && $row['user_id']) {
                    // Store user_id in session with limited rights
                    $session = new \Zend\Session\Container('SimpleLogin');
                    $session->user_id = $row['user_id'];
                    return $this->redirect()->toRoute('user/simple-order');
                } else {
                    $error = 'Alias not found.';
                }
            } else {
                $error = 'Please enter an alias.';
            }
        }
        $viewModel = new ViewModel(['error' => $error, 'recentOrders' => $recentOrders]);
        $viewModel->setTerminal(true);
        return $viewModel;
    }

    public function orderAction()
    {
        // Disable layout for simple order mode (render only the view, no layout)
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
        $userName = $user ? $user->get('alias') : 'Gast';
        $drinks = $drinkManager->getAll($userId);
        $drinkCategoryManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkCategoryManager');
        $drinkCategories = iterator_to_array($drinkCategoryManager->getAll());
        $drinkOrderManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkDepositManager');
        $drinkOrders = iterator_to_array($drinkOrderManager->getByUser($userId));
        $drinkDeposits = iterator_to_array($drinkDepositManager->getByUser($userId));
        // Calculate current balance
        $currentBalance = 0;
        foreach ($drinkDeposits as $deposit) {
            $currentBalance += $deposit['amount'];
        }
        foreach ($drinkOrders as $order) {
            if (!empty($order['deleted'])) continue;
            $currentBalance -= $order['quantity'] * $order['price'];
        }
        // Merge drink orders and deposits into a single history array with 'type' key
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
            $drinkHistory[] = [
                'type' => 'order',
                'drink_id' => $order['drink_id'],
                'name' => $order['name'],
                'quantity' => $order['quantity'],
                'price' => $order['price'],
                'total' => $order['quantity'] * $order['price'],
                'created_at' => $order['order_time'],
                'datetime' => $order['order_time'],
                'id' => $order['id'],
                'deleted' => $order['deleted'],
            ];
        }
        // Sort by created_at descending
        usort($drinkHistory, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        // Fetch drink statistics for the user
        $drinkStats = [];
        try {
            $statsResult = $drinkOrderManager->getDrinkStatsByUser($userId);
            foreach ($statsResult as $row) {
                $drinkStats[] = [
                    'id' => isset($row['id']) ? (int)$row['id'] : null,
                    'name' => $row['name'],
                    'total_count' => $row['total_count'],
                ];
            }
        } catch (\Exception $e) {
            // Leave $drinkStats empty on error
        }
        $drinkOrderCancelWindow = \Drinks\Manager\DrinkOrderManager::CANCEL_WINDOW_SECONDS;
        return $viewModel->setVariables([
            'drinks' => $drinks,
            'drinkHistory' => $drinkHistory,
            'userName' => $userName,
            'currentBalance' => $currentBalance,
            'error' => null,
            'success' => false,
            'drinkOrderCancelWindow' => $drinkOrderCancelWindow,
            'drinkCategories' => $drinkCategories,
            'drinkStats' => $drinkStats,
            'simpleOrderMode' => true // Add this line to enable simple-order mode in the view
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
        } else {
            return $this->getResponse()->setContent(json_encode(['success' => false, 'error_message' => 'Update failed.']))->setStatusCode(500);
        }
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

        $result = $drinkManager->addOrdersAndNotify($user, $drinkCounts, [$this, 't'], $this->getServiceLocator());

        if ($result['success']) {
            return $this->getResponse()->setContent(json_encode(['success' => true, 'balance' => $result['balance']]))->setStatusCode(200);
        } else {
            return $this->getResponse()->setContent(json_encode(['success' => false, 'error' => $result['error']]))->setStatusCode(400);
        }
    }
}
