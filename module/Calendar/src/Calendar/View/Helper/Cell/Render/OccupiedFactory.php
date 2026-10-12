<?php

namespace Calendar\View\Helper\Cell\Render;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class OccupiedFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new Occupied($sm->getServiceLocator()->get('Booking\Service\BookingTypeService'));
    }

}
