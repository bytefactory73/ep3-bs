<?php

namespace Calendar\View\Helper\Cell\Render;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class OccupiedForVisitorsFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new OccupiedForVisitors($sm->getServiceLocator()->get('Booking\Service\BookingTypeService'));
    }

}
