<?php

namespace Square\Controller;

use Zend\Mvc\Controller\AbstractActionController;

class SquareController extends AbstractActionController
{

    public function indexAction()
    {
        $dateStartParam = $this->params()->fromQuery('ds');
        $dateEndParam = $this->params()->fromQuery('de');
        $timeStartParam = $this->params()->fromQuery('ts');
        $timeEndParam = $this->params()->fromQuery('te');
        $squareParam = $this->params()->fromQuery('s');
        $flagParam = $this->params()->fromQuery('f');

        $serviceManager = @$this->getServiceLocator();
        $squareProductManager = $serviceManager->get('Square\Manager\SquareProductManager');
        $squareValidator = $serviceManager->get('Square\Service\SquareValidator');
        $bookingTypeService = $serviceManager->get('Booking\Service\BookingTypeService');

        $byproducts = $squareValidator->isBookable($dateStartParam, $dateEndParam, $timeStartParam, $timeEndParam, $squareParam);
        $byproducts['validator'] = $squareValidator;
        $byproducts['bookingTypeService'] = $bookingTypeService;

        $products = $squareProductManager->getBySquare($byproducts['square']);
        $byproducts['products'] = $products;

        $byproducts['flag'] = $flagParam;

        return $this->ajaxViewModel($byproducts);
    }

}
