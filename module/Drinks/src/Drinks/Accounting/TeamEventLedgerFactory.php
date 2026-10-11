<?php

namespace Drinks\Accounting;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class TeamEventLedgerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        return new TeamEventLedger($serviceLocator->get('Zend\Db\Adapter\Adapter'));
    }
}
