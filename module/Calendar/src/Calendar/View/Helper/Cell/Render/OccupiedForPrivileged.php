<?php

namespace Calendar\View\Helper\Cell\Render;

use Booking\Service\BookingStatusService;
use Booking\Service\BookingTypeService;
use Zend\View\Helper\AbstractHelper;

class OccupiedForPrivileged extends AbstractHelper
{

    protected $bookingStatusService;
    protected $bookingTypeService;

    public function __construct(BookingStatusService $bookingStatusService, BookingTypeService $bookingTypeService)
    {
        $this->bookingStatusService = $bookingStatusService;
        $this->bookingTypeService = $bookingTypeService;
    }

    public function __invoke(array $reservations, array $cellLinkParams)
    {
        $view = $this->getView();

        $reservationsCount = count($reservations);

        if ($reservationsCount > 1) {
            return $view->calendarCellLink($this->view->t('Occupied'), $view->url('backend/booking/edit', [], $cellLinkParams), 'cc-single');
        } else {
            $reservation = current($reservations);
            $booking = $reservation->needExtra('booking');
            $bookingStatusColor = $this->bookingStatusService->getStatusColor($booking->getBillingStatus());
            $bookingTypeColor = $this->bookingTypeService->getTypeColor($booking->getMeta('type'));

            $cellStyle = '';

            if ($bookingStatusColor) {
                $cellStyle .= 'outline: solid 3px ' . $bookingStatusColor . ';';
            }

            if ($bookingTypeColor) {
                $cellStyle .= 'background-color: ' . $bookingTypeColor . ';';
            }

            $cellStyle = $cellStyle ?: null;

            $cellLabel = $booking->getMeta('custom-name') ?: $booking->needExtra('user')->need('alias');
            $cellGroup = ' cc-group-' . $booking->need('bid');

            switch ($booking->need('status')) {
                case 'single':
                    return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('backend/booking/edit', [], $cellLinkParams), 'cc-single' . $cellGroup, null, $cellStyle);
                case 'subscription':
                    return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('backend/booking/edit', [], $cellLinkParams), 'cc-multiple' . $cellGroup, null, $cellStyle);
            }
        }
    }

}
