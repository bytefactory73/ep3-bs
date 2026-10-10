<?php

/**
 * Literal route below /user to a Drinks controller action.
 */
$route = function ($path, $action, $controller = 'Drinks\Controller\Drinks', array $childRoutes = []) {
    $route = [
        'type' => 'Literal',
        'options' => [
            'route' => $path,
            'defaults' => ['controller' => $controller, 'action' => $action],
        ],
    ];
    if ($childRoutes) {
        $route['may_terminate'] = true;
        $route['child_routes'] = $childRoutes;
    }
    return $route;
};
$theke = function ($path, $action, array $childRoutes = []) use ($route) {
    return $route($path, $action, 'Drinks\Controller\SimpleLogin', $childRoutes);
};

return [
    'router' => [
        'routes' => [
            // Merged into the 'user' route of the User module
            'user' => [
                'child_routes' => [
                    // Main site: own account
                    'drinks' => $route('/drinks', 'drinks'),
                    'bookings' => [
                        'child_routes' => [
                            'drop-order' => $route('/drop-order', 'dropOrder'),
                            'submit-order' => $route('/submit-order', 'submitOrder'),
                        ],
                    ],
                    'send-money' => $route('/send-money', 'sendMoney'),
                    'money-recipient-team-events' => $route('/money-recipient-team-events', 'moneyRecipientTeamEvents'),
                    'drinks-summary' => $route('/drinks-summary', 'drinksSummary'),
                    'store-check-date' => $route('/store-check-date', 'storeCheckDate'),

                    // Main site: Kostenübersicht of teamleads
                    'teamlead-team-stats' => $route('/teamlead-team-stats', 'teamleadTeamStats'),
                    'teamlead-team-members' => $route('/teamlead-team-members', 'teamleadTeamMembers'),
                    'teamlead-order-relevance' => $route('/teamlead-order-relevance', 'teamleadOrderRelevance'),
                    'teamlead-extra-cost' => $route('/teamlead-extra-cost', 'teamleadExtraCost'),
                    'teamlead-update-extra-cost' => $route('/teamlead-update-extra-cost', 'teamleadUpdateExtraCost'),
                    'teamlead-delete-extra-cost' => $route('/teamlead-delete-extra-cost', 'teamleadDeleteExtraCost'),
                    'teamlead-guest-donation' => $route('/teamlead-guest-donation', 'teamleadGuestDonation'),
                    'teamlead-update-guest-donation' => $route('/teamlead-update-guest-donation', 'teamleadUpdateGuestDonation'),
                    'teamlead-delete-guest-donation' => $route('/teamlead-delete-guest-donation', 'teamleadDeleteGuestDonation'),
                    'teamlead-close-team-event' => $route('/teamlead-close-team-event', 'teamleadCloseTeamEvent'),

                    // Admin pages
                    'drinks-admin' => $route('/drinks-admin', 'drinksAdmin', 'Drinks\Controller\Drinks', [
                        'party-mode-save' => $route('/party-mode-save', 'savePartyMode'),
                        'paypal-settings' => $route('/paypal-settings', 'paypalSettings'),
                        'save-paypal-settings' => $route('/save-paypal-settings', 'savePaypalSettings'),
                        'spieltage-overview' => $route('/spieltage-overview', 'spieltageOverview'),
                    ]),
                    'manage-drinks' => $route('/manage-drinks', 'manageDrinks'),
                    'deposits' => $route('/deposits', 'deposits'),
                    'balance-list' => $route('/balance-list', 'balanceList'),
                    'deposit-overview' => $route('/deposit-overview', 'depositOverview'),

                    // Admin AJAX
                    'add-drink-booking' => $route('/add-drink-booking', 'addDrinkBooking'),
                    'toggle-deposit-order-deleted' => $route('/toggle-deposit-order-deleted', 'toggleDepositOrderDeleted'),
                    'set-user-drinks-settings' => $route('/set-user-drinks-settings', 'setUserDrinksSettings'),
                    'get-user-deposits-data' => $route('/get-user-deposits-data', 'getUserDepositsData'),
                    'get-user-team-event-stats-data' => $route('/get-user-team-event-stats-data', 'getUserTeamEventStatsData'),
                    'update-user-history-team-event' => $route('/update-user-history-team-event', 'updateUserHistoryTeamEvent'),
                    'create-team-event' => $route('/create-team-event', 'createTeamEvent'),
                    'paypal-fetch' => $route('/paypal-fetch', 'triggerPaypalFetch'),
                    'paypal-history-import' => $route('/paypal-history-import', 'triggerPaypalHistoryImport'),
                    'emails-import' => $route('/emails-import', 'triggerEmailsImport'),
                    'create-deposit-from-paypal' => $route('/create-deposit-from-paypal', 'createDepositFromPaypal'),
                    'reassign-paypal-transaction' => $route('/reassign-paypal-transaction', 'reassignPaypalTransaction'),
                    'ignore-paypal-transaction' => $route('/ignore-paypal-transaction', 'ignorePaypalTransaction'),

                    // Theke
                    'simple-login' => $theke('/simple-login', 'login'),
                    'simple-order' => $theke('/simple-order', 'order', [
                        'drop-order' => $theke('/drop-order', 'dropOrder'),
                        'submit-order' => $theke('/submit-order', 'submitOrder'),
                        'send-money' => $theke('/send-money', 'sendMoney'),
                        'spieltag' => $theke('/spieltag', 'spieltag'),
                        'team-stats' => $theke('/team-stats', 'teamStats'),
                        'team-members' => $theke('/team-members', 'teamMembers'),
                        'team-order-relevance' => $theke('/team-order-relevance', 'teamOrderRelevance'),
                        'team-extra-cost' => $theke('/team-extra-cost', 'teamExtraCost'),
                        'team-update-extra-cost' => $theke('/team-update-extra-cost', 'teamUpdateExtraCost'),
                        'team-delete-extra-cost' => $theke('/team-delete-extra-cost', 'teamDeleteExtraCost'),
                        'team-guest-donation' => $theke('/team-guest-donation', 'teamGuestDonation'),
                        'team-update-guest-donation' => $theke('/team-update-guest-donation', 'teamUpdateGuestDonation'),
                        'team-delete-guest-donation' => $theke('/team-delete-guest-donation', 'teamDeleteGuestDonation'),
                        'close-team-event' => $theke('/close-team-event', 'closeTeamEvent'),
                    ]),
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            'Drinks\Manager\DrinkManager' => 'Drinks\Manager\DrinkManagerFactory',
            'Drinks\Manager\DrinkOrderManager' => 'Drinks\Manager\DrinkOrderManagerFactory',
            'Drinks\Manager\DrinkDepositManager' => 'Drinks\Manager\DrinkDepositManagerFactory',
            'Drinks\Manager\DrinkCategoryManager' => 'Drinks\Manager\DrinkCategoryManagerFactory',
            'Drinks\Manager\PaypalTransactionManager' => 'Drinks\Manager\PaypalTransactionManagerFactory',
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Drinks\Controller\Drinks' => 'Drinks\Controller\DrinksController',
            'Drinks\Controller\SimpleLogin' => 'Drinks\Controller\SimpleLoginController',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
    'form_elements' => [
        // No closures here: the config cache (EP3_BS_DEV_TAG = false) is written with var_export().
        // init() is called by the FormElementManager initializer.
        'invokables' => [
            'Drinks\Form\EditDrinksAliasForm' => 'Drinks\Form\EditDrinksAliasForm',
        ],
    ],
];
