<?php
namespace User\Controller;

use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Db\Adapter\Adapter;

class BarcodeControllerFactory
{
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $controller = new BarcodeController();
        // In ZF2, controllers get the service locator via getServiceLocator()
        $parentLocator = $serviceLocator->getServiceLocator();
        $adapter = $parentLocator->get(Adapter::class);
        $controller->setDbAdapter($adapter);
        return $controller;
    }
}
