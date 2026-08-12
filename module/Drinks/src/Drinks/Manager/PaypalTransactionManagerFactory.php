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
        $mailService = $serviceLocator->get('User\Service\MailService');
        return new PaypalTransactionManager($dbAdapter, $userManager, $mailService);
    }
}
