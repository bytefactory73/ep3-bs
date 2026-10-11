<?php

namespace Drinks\Accounting;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class AccountLedgerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        return new AccountLedger($serviceLocator->get('Zend\Db\Adapter\Adapter'));
    }
}
