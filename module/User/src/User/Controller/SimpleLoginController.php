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
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        // Example: fetch available drinks (customize as needed)
        // Fetch available drinks with user_total_count for this user
        $drinkManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkManager');
        $drinks = iterator_to_array($drinkManager->getAll($userId));
        // Provide dummy or minimal data for booking.phtml compatibility
        $drinkHistory = [];
        $userName = 'Gast';
        $currentBalance = 0;
        $error = null;
        $success = false;
        $drinkOrderManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkOrderManager');
        $drinkDepositManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkDepositManager');
        $userManager = $this->getServiceLocator()->get('User\Manager\UserManager');
        $user = $userManager->get($userId);
        $userName = $user ? $user->get('alias') : 'Gast';
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
        return $viewModel->setVariables([
            'drinks' => $drinks,
            'drinkHistory' => $drinkHistory,
            'userName' => $userName,
            'currentBalance' => $currentBalance,
            'error' => $error,
            'success' => $success,
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
        $userId = $session->user_id;
        $orderId = (int)$this->params()->fromPost('order_id');
        if (!$orderId) {
            return $this->getResponse()->setStatusCode(400);
        }
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        // Only allow deleting orders belonging to this user
        $order = $db->query('SELECT * FROM drink_orders WHERE id = ? AND user_id = ?', [$orderId, $userId])->current();
        if (!$order) {
            return $this->getResponse()->setStatusCode(404);
        }
        // Mark as deleted
        $result = $db->query('UPDATE drink_orders SET deleted = 1 WHERE id = ? AND user_id = ?', [$orderId, $userId]);
        if ($result->getAffectedRows() > 0) {
            // Send cancellation email (copied from AccountController)
            $userManager = $this->getServiceLocator()->get('User\Manager\UserManager');
            $user = $userManager->get($userId);
            $userMailService = $this->getServiceLocator()->get('User\Service\MailService');
            $drinkOrderManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkOrderManager');
            $drinkDepositManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkDepositManager');
            $dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
            // Fetch order details before deletion
            $sql = 'SELECT do.*, d.name as drink_name FROM drink_orders do JOIN drinks d ON do.drink_id = d.id WHERE do.id = ? AND do.user_id = ?';
            $statement = $dbAdapter->createStatement($sql, [$orderId, $userId]);
            $order = $statement->execute()->current();
            $drinkOrders = iterator_to_array($drinkOrderManager->getByUser($userId));
            $drinkDeposits = iterator_to_array($drinkDepositManager->getByUser($userId));
            $balance = 0;
            foreach ($drinkDeposits as $deposit) $balance += $deposit['amount'];
            foreach ($drinkOrders as $o) if (empty($o['deleted'])) $balance -= $o['quantity'] * $o['price'];
            $subject = $this->t('Stornierung Deiner Getränkebestellung');
            $lines = [];
            if ($order) {
                $lines[] = sprintf('%s x %d = %.2f EUR', $order['drink_name'], $order['quantity'], $order['quantity'] * $order['price']);
            }
            $lines[] = '---------------------';
            $lines[] = sprintf($this->t('Storniert am:') . ' %s', date('d.m.Y H:i'));
            $lines[] = sprintf($this->t('Saldo nach Stornierung:') . '<b> %.2f EUR </b>', $balance);
            $text = $this->t('Deine Getränkebestellung wurde erfolgreich storniert.') . "<br><br>" . implode("<br>", $lines);
            $userMailService->send($user, $subject, $text, array('isHtml' => true));
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
        $userId = $session->user_id;
        $db = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        $drinkOrderManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkOrderManager');
        $drinkManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkManager');
        $drinkDepositManager = $this->getServiceLocator()->get('Drinks\Manager\DrinkDepositManager');
        $drinkCounts = $this->params()->fromPost('drink_counts', []);
        $anyOrdered = false;
        $orderedDrinks = [];
        if (is_array($drinkCounts)) {
            foreach ($drinkCounts as $drinkId => $qty) {
                $drinkId = (int)$drinkId;
                $qty = (int)$qty;
                if ($drinkId && $qty > 0) {
                    $drink = $drinkManager->get($drinkId);
                    if ($drink) {
                        $db->query('INSERT INTO drink_orders (user_id, drink_id, quantity, price, order_time) VALUES (?, ?, ?, ?, NOW())', [$userId, $drinkId, $qty, $drink['price']]);
                        $anyOrdered = true;
                        $orderedDrinks[] = [
                            'name' => $drink['name'],
                            'quantity' => $qty,
                            'price' => $drink['price'],
                            'total' => $qty * $drink['price'],
                        ];
                    }
                }
            }
        }
        if ($anyOrdered) {
            // Calculate new balance after order
            $drinkOrders = iterator_to_array($drinkOrderManager->getByUser($userId));
            $drinkDeposits = iterator_to_array($drinkDepositManager->getByUser($userId));
            $balance = 0;
            foreach ($drinkDeposits as $deposit) $balance += $deposit['amount'];
            foreach ($drinkOrders as $order) if (empty($order['deleted'])) $balance -= $order['quantity'] * $order['price'];
            // Send confirmation email
            $userManager = $this->getServiceLocator()->get('User\Manager\UserManager');
            $user = $userManager->get($userId);
            $subject = $this->t('Bestätigung Deiner Getränkebestellung');
            $lines = [];
            $totalSum = 0;
            foreach ($orderedDrinks as $item) {
                $lines[] = sprintf('%s x %d = %.2f EUR', $item['name'], $item['quantity'], $item['total']);
                $totalSum += $item['total'];
            }
            $lines[] = '---------------------';
            $lines[] = sprintf($this->t('Gesamt:') . ' %.2f EUR', $totalSum);
            $lines[] = '';
            $lines[] = sprintf($this->t('Saldo nach Bestellung:') . '<b> %.2f EUR </b>', $balance);
            $text = $this->t('Vielen Dank für Deine Getränkebestellung!') . "<br><br>" . implode("<br>", $lines);
            if ($balance < 0) {
                $text .= "<br><br>";
                $text .= '<span style="color:#d32f2f;font-weight:bold;">' . $this->t('Warnung: Dein Saldo ist negativ! Bitte überweise Geld auf das STC Paypal-Konto.') . '</span>';
            }
            $userMailService = $this->getServiceLocator()->get('User\Service\MailService');
            $userMailService->send($user, $subject, $text, array('isHtml' => true));
            return $this->getResponse()->setContent(json_encode(['success' => true, 'balance' => $balance]))->setStatusCode(200);
        } else {
            return $this->getResponse()->setContent(json_encode(['success' => false, 'error' => $this->t('Bitte mindestens ein Getränk auswählen.')]))->setStatusCode(400);
        }
    }
}
