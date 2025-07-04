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
        return new ViewModel(['error' => $error]);
    }

    public function orderAction()
    {
        $sessionManager = $this->getServiceLocator()->get('Zend\Session\SessionManager');
        $sessionManager->start();
        $session = new \Zend\Session\Container('SimpleLogin');
        if (empty($session->user_id)) {
            return $this->redirect()->toRoute('user/simple-login');
        }
        $userId = $session->user_id;
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        // Example: fetch available drinks (customize as needed)
        // Fetch available drinks with user_total_count for this user
        $drinks = $db->query('
            SELECT d.id, d.name, d.price, d.image, d.category,
                (SELECT SUM(o.quantity) FROM drink_orders o WHERE o.user_id = ? AND o.drink_id = d.id AND o.deleted = 0) AS user_total_count
            FROM drinks d', [$userId])->toArray();
        // Provide dummy or minimal data for booking.phtml compatibility
        $drinkHistory = [];
        $userName = 'Gast';
        $currentBalance = 0;
        $error = null;
        $success = false;
        if ($this->getRequest()->isPost()) {
            $drinkId = (int)$this->getRequest()->getPost('drink_id');
            $qty = (int)$this->getRequest()->getPost('qty');
            if ($drinkId && $qty > 0) {
                // Insert order (customize table/fields as needed)
                $db->query('INSERT INTO drink_orders (user_id, drink_id, qty, created_at) VALUES (?, ?, ?, NOW())', [$userId, $drinkId, $qty]);
                $success = true;
                // After order, clear session and redirect to login
                $session->getManager()->getStorage()->clear('SimpleLogin');
                return $this->redirect()->toRoute('user/simple-login');
            } else {
                $error = 'Please select a drink and quantity.';
            }
        }
        $userManager = $this->getServiceLocator()->get('User\Manager\UserManager');
        $user = $userManager->get($userId);
        $userName = $user ? $user->get('alias') : 'Gast';
        $drinkOrderManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkDepositManager');
        // Fetch drink categories for category buttons in simple order UI
        $drinkCategoryManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkCategoryManager');
        $drinkCategories = iterator_to_array($drinkCategoryManager->getAll());
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
        return new ViewModel([
            'drinks' => $drinks,
            'drinkHistory' => $drinkHistory,
            'userName' => $userName,
            'currentBalance' => $currentBalance,
            'error' => $error,
            'success' => $success,
            'drinkOrderCancelWindow' => $drinkOrderCancelWindow,
            'drinkCategories' => $drinkCategories,
            'drinkStats' => $drinkStats
        ]);
    }
}
