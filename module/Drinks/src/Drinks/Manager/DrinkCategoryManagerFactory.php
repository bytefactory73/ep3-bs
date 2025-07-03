<?php
namespace Drinks\Manager;

class DrinkCategoryManagerFactory
{
    public function __invoke($container, $requestedName = null, $options = null)
    {
        $dbAdapter = $container->get('Zend\Db\Adapter\Adapter');
        return new DrinkCategoryManager($dbAdapter);
    }
    // For ZF2 compatibility
    public function createService($serviceLocator)
    {
        return $this($serviceLocator);
    }
}
