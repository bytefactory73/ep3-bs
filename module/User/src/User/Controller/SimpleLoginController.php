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
        $drinks = $db->query('SELECT id, name, price FROM drinks', [])->toArray();
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
        return new ViewModel([
            'drinks' => $drinks,
            'error' => $error,
            'success' => $success
        ]);
    }
}
