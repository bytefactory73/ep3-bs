<?php

namespace Calendar\View\Helper\Cell\Render;

use Booking\Service\BookingTypeService;
use Square\Entity\Square;
use Zend\View\Helper\AbstractHelper;

class Occupied extends AbstractHelper
{

    protected $bookingTypeService;

    public function __construct(BookingTypeService $bookingTypeService)
    {
        $this->bookingTypeService = $bookingTypeService;
    }

    public function __invoke($user, $userBooking, array $reservations, array $cellLinkParams, Square $square)
    {
        $view = $this->getView();

        if ($user && $user->can('calendar.see-data')) {
            return $view->calendarCellRenderOccupiedForPrivileged($reservations, $cellLinkParams);
        } else if ($user) {
            if ($userBooking) {
                $cellLabel = $userBooking->getMeta('custom-name') ?: $view->t('Your Booking');
                $cellGroup = ' cc-group-' . $userBooking->need('bid');

                $bookingTypeColor = $this->bookingTypeService->getTypeColor($userBooking->getMeta('type'));
                $cellStyle = $bookingTypeColor ? 'background-color: ' . $bookingTypeColor . ';' : null;

                return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('square', [], $cellLinkParams), 'cc-own' . $cellGroup, null, $cellStyle);
            } else {
                return $view->calendarCellRenderOccupiedForVisitors($reservations, $cellLinkParams, $square, $user);
            }
        } else {
            return $view->calendarCellRenderOccupiedForVisitors($reservations, $cellLinkParams, $square);
        }
    }

}
