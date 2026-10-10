<?php

namespace Drinks\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class ThekeMailerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        return new ThekeMailer(
            $serviceLocator->get('User\Service\MailService'),
            $serviceLocator->get('Zend\Db\Adapter\Adapter')
        );
    }
}
