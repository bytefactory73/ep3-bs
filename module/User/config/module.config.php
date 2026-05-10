<?php

return array(
    'router' => array(
        'routes' => array(
            'user' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/user',
                ),
                'may_terminate' => false,
                'child_routes' => array(
                    'toggle-deposit-order-deleted' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/toggle-deposit-order-deleted',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'toggleDepositOrderDeleted',
                            ),
                        ),
                    ),
                    'set-user-drinks-settings' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/set-user-drinks-settings',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'setUserDrinksSettings',
                            ),
                        ),
                    ),
                    'add-drink-booking' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/user/add-drink-booking',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action'     => 'addDrinkBooking',
                            ),
                        ),
                    ),
                    'login' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/login',
                            'defaults' => array(
                                'controller' => 'User\Controller\Session',
                                'action' => 'login',
                            ),
                        ),
                    ),
                    'logout' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/logout',
                            'defaults' => array(
                                'controller' => 'User\Controller\Session',
                                'action' => 'logout',
                            ),
                        ),
                    ),
                    'password' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/password',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'password',
                            ),
                        ),
                    ),
                    'password-reset' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/password-reset',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'passwordReset',
                            ),
                        ),
                    ),
                    'registration' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/registration',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'registration',
                            ),
                        ),
                    ),
                    'registration-confirmation' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/registration-confirmation',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'registrationConfirmation',
                            ),
                        ),
                    ),
                    'activation' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/activation',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'activation',
                            ),
                        ),
                    ),
                    'activation-resend' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/activation-resend',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'activationResend',
                            ),
                        ),
                    ),
                    'bookings' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/bookings',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'bookings',
                            ),
                        ),
                        'may_terminate' => true,
                        'child_routes' => array(
                            'bills' => array(
                                'type' => 'Segment',
                                'options' => array(
                                    'route' => '/bills/:bid',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\Account',
                                        'action' => 'bills',
                                    ),
                                    'constraints' => array(
                                        'bid' => '[0-9]+',
                                    ),
                                ),
                            ),
                            'drop-order' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/drop-order',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\Account',
                                        'action' => 'dropOrder',
                                    ),
                                ),
                            ),
                            'submit-order' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/submit-order',
                                    'defaults' => array(
                                        'controller' => 'User\\Controller\\Account',
                                        'action' => 'submitOrder',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'settings' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/settings',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'settings',
                            ),
                        ),
                    ),
                    'send-money' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/send-money',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'sendMoney',
                            ),
                        ),
                    ),
                    'money-recipient-team-events' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/money-recipient-team-events',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'moneyRecipientTeamEvents',
                            ),
                        ),
                    ),
                    'teamlead-team-stats' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/teamlead-team-stats',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'teamleadTeamStats',
                            ),
                        ),
                    ),
                    'teamlead-team-members' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/teamlead-team-members',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'teamleadTeamMembers',
                            ),
                        ),
                    ),
                    'teamlead-order-relevance' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/teamlead-order-relevance',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'teamleadOrderRelevance',
                            ),
                        ),
                    ),
                        'teamlead-extra-cost' => array(
                            'type' => 'Literal',
                            'options' => array(
                                'route' => '/teamlead-extra-cost',
                                'defaults' => array(
                                    'controller' => 'User\Controller\Account',
                                    'action' => 'teamleadExtraCost',
                                ),
                            ),
                        ),
                        'teamlead-update-extra-cost' => array(
                            'type' => 'Literal',
                            'options' => array(
                                'route' => '/teamlead-update-extra-cost',
                                'defaults' => array(
                                    'controller' => 'User\Controller\Account',
                                    'action' => 'teamleadUpdateExtraCost',
                                ),
                            ),
                        ),
                        'teamlead-delete-extra-cost' => array(
                            'type' => 'Literal',
                            'options' => array(
                                'route' => '/teamlead-delete-extra-cost',
                                'defaults' => array(
                                    'controller' => 'User\Controller\Account',
                                    'action' => 'teamleadDeleteExtraCost',
                                ),
                            ),
                        ),
                        'teamlead-guest-donation' => array(
                            'type' => 'Literal',
                            'options' => array(
                                'route' => '/teamlead-guest-donation',
                                'defaults' => array(
                                    'controller' => 'User\Controller\Account',
                                    'action' => 'teamleadGuestDonation',
                                ),
                            ),
                        ),
                        'teamlead-update-guest-donation' => array(
                            'type' => 'Literal',
                            'options' => array(
                                'route' => '/teamlead-update-guest-donation',
                                'defaults' => array(
                                    'controller' => 'User\Controller\Account',
                                    'action' => 'teamleadUpdateGuestDonation',
                                ),
                            ),
                        ),
                        'teamlead-delete-guest-donation' => array(
                            'type' => 'Literal',
                            'options' => array(
                                'route' => '/teamlead-delete-guest-donation',
                                'defaults' => array(
                                    'controller' => 'User\Controller\Account',
                                    'action' => 'teamleadDeleteGuestDonation',
                                ),
                            ),
                        ),
                    'teamlead-close-team-event' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/teamlead-close-team-event',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'teamleadCloseTeamEvent',
                            ),
                        ),
                    ),
                    'manage-drinks' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/manage-drinks',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'manageDrinks',
                            ),
                        ),
                    ),
                    'account/barcode-lookup' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/account/barcode-lookup',
                            'defaults' => array(
                                'controller' => 'User\\Controller\\Barcode',
                                'action' => 'lookup',
                            ),
                        ),
                    ),
                    'account/barcode-assign' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/account/barcode-assign',
                            'defaults' => array(
                                'controller' => 'User\\Controller\\Barcode',
                                'action' => 'assign',
                            ),
                        ),
                    ),
                    'account/barcode-remove' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/account/barcode-remove',
                            'defaults' => array(
                                'controller' => 'User\\Controller\\Barcode',
                                'action' => 'remove',
                            ),
                        ),
                    ),
                    'simple-login' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/simple-login',
                            'defaults' => array(
                                'controller' => 'User\\Controller\\SimpleLogin',
                                'action' => 'login',
                            ),
                        ),
                    ),
                    'simple-order' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/simple-order',
                            'defaults' => array(
                                'controller' => 'User\\Controller\\SimpleLogin',
                                'action' => 'order',
                            ),
                        ),
                        'may_terminate' => true,
                        'child_routes' => array(
                            'drop-order' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/drop-order',
                                    'defaults' => array(
                                        'controller' => 'User\\Controller\\SimpleLogin',
                                        'action' => 'dropOrder',
                                    ),
                                ),
                            ),
                            'submit-order' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/submit-order',
                                    'defaults' => array(
                                        'controller' => 'User\\Controller\\SimpleLogin',
                                        'action' => 'submitOrder',
                                    ),
                                ),
                            ),
                            'team-stats' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-stats',
                                    'defaults' => array(
                                        'controller' => 'User\\Controller\\SimpleLogin',
                                        'action' => 'teamStats',
                                    ),
                                ),
                            ),
                            'spieltag' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/spieltag',
                                    'defaults' => array(
                                        'controller' => 'User\\Controller\\SimpleLogin',
                                        'action' => 'spieltag',
                                    ),
                                ),
                            ),
                            'send-money' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/send-money',
                                    'defaults' => array(
                                        'controller' => 'User\\Controller\\SimpleLogin',
                                        'action' => 'sendMoney',
                                    ),
                                ),
                            ),
                            'team-members' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-members',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'teamMembers',
                                    ),
                                ),
                            ),
                            'team-order-relevance' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-order-relevance',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'teamOrderRelevance',
                                    ),
                                ),
                            ),
                            'team-extra-cost' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-extra-cost',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'teamExtraCost',
                                    ),
                                ),
                            ),
                            'team-update-extra-cost' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-update-extra-cost',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'teamUpdateExtraCost',
                                    ),
                                ),
                            ),
                            'team-delete-extra-cost' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-delete-extra-cost',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'teamDeleteExtraCost',
                                    ),
                                ),
                            ),
                            'team-guest-donation' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-guest-donation',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'teamGuestDonation',
                                    ),
                                ),
                            ),
                            'team-update-guest-donation' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-update-guest-donation',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'teamUpdateGuestDonation',
                                    ),
                                ),
                            ),
                            'team-delete-guest-donation' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/team-delete-guest-donation',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'teamDeleteGuestDonation',
                                    ),
                                ),
                            ),
                            'close-team-event' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/close-team-event',
                                    'defaults' => array(
                                        'controller' => 'User\Controller\SimpleLogin',
                                        'action' => 'closeTeamEvent',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'drinks-admin' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/drinks-admin',
                            'defaults' => array(
                                'controller' => 'User\\Controller\\Account',
                                'action' => 'drinksAdmin',
                            ),
                        ),
                        'may_terminate' => true,
                        'child_routes' => array(
                            'party-mode-save' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/party-mode-save',
                                    'defaults' => array(
                                        'controller' => 'User\\Controller\\Account',
                                        'action' => 'savePartyMode',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'deposits' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/deposits',
                            'defaults' => array(
                                'controller' => 'User\\Controller\\Account',
                                'action' => 'deposits',
                            ),
                        ),
                    ),
										'balance-list' => array(
										   'type' => 'Literal',
										   'options' => array(
										       'route' => '/balance-list',
										       'defaults' => array(
										           'controller' => 'User\Controller\Account',
										           'action' => 'balanceList',
										       ),
										   ),
										),
                    'get-user-deposits-data' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/get-user-deposits-data',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'getUserDepositsData',
                            ),
                        ),
                    ),
                    'create-team-event' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/create-team-event',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'createTeamEvent',
                            ),
                        ),
                    ),
                    'drinks-summary' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/drinks-summary',
                            'defaults' => array(
                                'controller' => 'User\\Controller\\Account',
                                'action' => 'drinksSummary',
                            ),
                        ),
                    ),
                    'store-check-date' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/store-check-date',
                            'defaults' => array(
                                'controller' => 'User\Controller\Account',
                                'action' => 'storeCheckDate',
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),

    'controllers' => array(
        'invokables' => array(
            'User\Controller\Session' => 'User\Controller\SessionController',
            'User\Controller\Account' => 'User\Controller\AccountController',
            'User\Controller\SimpleLogin' => 'User\Controller\SimpleLoginController',
        ),
        'factories' => array(
            'User\\Controller\\Barcode' => function($controllerManager) {
                $controller = new \User\Controller\BarcodeController();
                $serviceLocator = $controllerManager->getServiceLocator();
                $adapter = $serviceLocator->get('Zend\\Db\\Adapter\\Adapter');
                $controller->setDbAdapter($adapter);
                return $controller;
            },
        ),
    ),

    'controller_plugins' => array(
        'factories' => array(
            'Authorize' => 'User\Controller\Plugin\AuthorizeFactory',
        ),
    ),

    'service_manager' => array(
        'factories' => array(
            'User\Manager\UserManager' => 'User\Manager\UserManagerFactory',
            'User\Manager\UserSessionManager' => 'User\Manager\UserSessionManagerFactory',

            'User\Table\UserMetaTable' => 'User\Table\UserMetaTableFactory',
            'User\Table\UserTable' => 'User\Table\UserTableFactory',

            'User\Service\MailService' => 'User\Service\MailServiceFactory',

            'Zend\Session\Config\ConfigInterface' => 'Zend\Session\Service\SessionConfigFactory',
            'Zend\Session\SessionManager' => 'Zend\Session\Service\SessionManagerFactory',
        ),
    ),

    'form_elements' => array(
        'factories' => array(
            'User\Form\EditEmailForm' => 'User\Form\EditEmailFormFactory',
            'User\Form\RegistrationForm' => 'User\Form\RegistrationFormFactory',
            'User\Form\EditDrinksAliasForm' => function($formElementManager) {
                $form = new \User\Form\EditDrinksAliasForm();
                $form->init();
                return $form;
            },
        ),
    ),

    'view_helpers' => array(
        'factories' => array(
            'UserLastBookings' => 'User\View\Helper\LastBookingsFactory',
        ),
    ),

    'view_manager' => array(
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
    ),

    'validators' => array(
        'factories' => array(
            'User\Validator\UniqueDrinksAlias' => function($sm) {
                $validator = new \User\Validator\UniqueDrinksAlias();
                // Try to get the DB adapter from the service manager
                if (method_exists($sm, 'getServiceLocator')) {
                    $serviceLocator = $sm->getServiceLocator();
                } else {
                    $serviceLocator = $sm;
                }
                if ($serviceLocator->has('Zend\Db\Adapter\Adapter')) {
                    $validator->setDbAdapter($serviceLocator->get('Zend\Db\Adapter\Adapter'));
                }
                return $validator;
            },
        ),
    ),
);