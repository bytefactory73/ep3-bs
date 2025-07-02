<?php
return [
    'service_manager' => [
        'factories' => [
            'Drinks\Manager\DrinkManager' => 'Drinks\Manager\DrinkManagerFactory',
            'Drinks\Manager\DrinkOrderManager' => 'Drinks\Manager\DrinkOrderManagerFactory',
            'Drinks\Manager\DrinkDepositManager' => 'Drinks\Manager\DrinkDepositManagerFactory',
        ],
    ],
];
