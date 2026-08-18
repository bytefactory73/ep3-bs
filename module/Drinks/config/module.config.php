<?php
return [
    'service_manager' => [
        'factories' => [
            'Drinks\Manager\DrinkManager' => 'Drinks\Manager\DrinkManagerFactory',
            'Drinks\Manager\DrinkOrderManager' => 'Drinks\Manager\DrinkOrderManagerFactory',
            'Drinks\Manager\DrinkDepositManager' => 'Drinks\Manager\DrinkDepositManagerFactory',
            'Drinks\Manager\DrinkCategoryManager' => 'Drinks\Manager\DrinkCategoryManagerFactory',
            'Drinks\Manager\PaypalTransactionManager' => 'Drinks\Manager\PaypalTransactionManagerFactory',
            'Drinks\Controller\SimpleLogin' => 'Drinks\Controller\SimpleLoginControllerFactory',
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Drinks\Controller\SimpleLogin' => 'Drinks\Controller\SimpleLoginController',
        ],
    ],
    'form_elements' => [
        'factories' => [
            'Drinks\Form\EditDrinksAliasForm' => function($formElementManager) {
                $form = new \Drinks\Form\EditDrinksAliasForm();
                $form->init();
                return $form;
            },
        ],
    ],
];
