<?php

namespace Drinks\Controller\Traits;

use Zend\Session\Container;

/**
 * Access to the two login kinds: the main-site session user and the Theke ("SimpleLogin")
 * session, which only stores a user id.
 */
trait SessionUserTrait
{
    /**
     * getServiceLocator() is deprecated in zend-mvc 2.7; the notice is suppressed here once.
     */
    protected function service($name)
    {
        $serviceManager = @$this->getServiceLocator();
        return $serviceManager->get($name);
    }

    protected function getSessionUser()
    {
        return $this->service('User\Manager\UserSessionManager')->getSessionUser();
    }

    /**
     * The session user if it is a backend admin, otherwise null.
     */
    protected function getAdminUser()
    {
        $user = $this->getSessionUser();
        return ($user && $user->get('status') === 'admin') ? $user : null;
    }

    protected function getSimpleLoginSession()
    {
        $this->service('Zend\Session\SessionManager')->start();
        return new Container('SimpleLogin');
    }

    protected function getSimpleLoginUserId()
    {
        $session = $this->getSimpleLoginSession();
        return empty($session->user_id) ? 0 : (int)$session->user_id;
    }

    protected function getDrinkManager()
    {
        return $this->service('Drinks\Manager\DrinkManager');
    }
}
