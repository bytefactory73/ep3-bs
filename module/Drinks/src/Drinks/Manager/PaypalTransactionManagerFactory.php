<?php

namespace Drinks\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class PaypalTransactionManagerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $dbAdapter = $serviceLocator->get('Zend\Db\Adapter\Adapter');
        $userManager = $serviceLocator->get('User\Manager\UserManager');
        $thekeMailer = $serviceLocator->get('Drinks\Service\ThekeMailer');
        return new PaypalTransactionManager($dbAdapter, $userManager, $thekeMailer);
    }
}
