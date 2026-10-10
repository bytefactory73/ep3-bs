<?php

return array(
    'router' => array(
        'routes' => array(
            'calendar' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/calendar',
                    'defaults' => array(
                        'controller' => 'Calendar\Controller\Calendar',
                        'action' => 'index',
                    ),
                ),
            ),
        ),
    ),

    'controllers' => array(
        'invokables' => array(
            'Calendar\Controller\Calendar' => 'Calendar\Controller\CalendarController',
        ),
    ),

    'controller_plugins' => array(
        'invokables' => array(
            'CalendarDetermineDate' => 'Calendar\Controller\Plugin\DetermineDate',
        ),

        'factories' => array(
            'CalendarDetermineSquares' => 'Calendar\Controller\Plugin\DetermineSquaresFactory',
        ),
    ),

    'view_helpers' => array(
        'invokables' => array(
            'CalendarCell' => 'Calendar\View\Helper\Cell\Cell',
            'CalendarCellLink' => 'Calendar\View\Helper\Cell\CellLink',
            'CalendarCellLogic' => 'Calendar\View\Helper\Cell\CellLogic',

            'CalendarCellRenderCell' => 'Calendar\View\Helper\Cell\Render\Cell',
            'CalendarCellRenderEvent' => 'Calendar\View\Helper\Cell\Render\Event',
            'CalendarCellRenderEventConflict' => 'Calendar\View\Helper\Cell\Render\EventConflict',
            'CalendarCellRenderEventForPrivileged' => 'Calendar\View\Helper\Cell\Render\EventForPrivileged',
            'CalendarCellRenderFree' => 'Calendar\View\Helper\Cell\Render\Free',
            'CalendarCellRenderFreeForPrivileged' => 'Calendar\View\Helper\Cell\Render\FreeForPrivileged',

            'CalendarDateRow' => 'Calendar\View\Helper\DateRow',
            'CalendarSquareRow' => 'Calendar\View\Helper\SquareRow',
            'CalendarSquareTable' => 'Calendar\View\Helper\SquareTable',
            'CalendarTimeRow' => 'Calendar\View\Helper\TimeRow',
            'CalendarTimeTable' => 'Calendar\View\Helper\TimeTable',

            'CalendarReservationsCleanup' => 'Calendar\View\Helper\ReservationsCleanup',
            'CalendarReservationsForCell' => 'Calendar\View\Helper\ReservationsForCell',
            'CalendarReservationsForCol' => 'Calendar\View\Helper\ReservationsForCol',

            'CalendarEventsCleanup' => 'Calendar\View\Helper\EventsCleanup',
            'CalendarEventsForCell' => 'Calendar\View\Helper\EventsForCell',
            'CalendarEventsForCol' => 'Calendar\View\Helper\EventsForCol',
        ),

        'factories' => array(
            'CalendarCellRenderOccupied' => 'Calendar\View\Helper\Cell\Render\OccupiedFactory',
            'CalendarCellRenderOccupiedForPrivileged' => 'Calendar\View\Helper\Cell\Render\OccupiedForPrivilegedFactory',
            'CalendarCellRenderOccupiedForVisitors' => 'Calendar\View\Helper\Cell\Render\OccupiedForVisitorsFactory',
        ),
    ),

    'view_manager' => array(
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
    ),
);
