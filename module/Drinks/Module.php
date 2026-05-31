<?php
namespace Drinks;

class Module
{
    public function getAutoloaderConfig()
    {
        return array(
            'Zend\\Loader\\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function getConfig()
    {
        $config = include __DIR__ . '/config/module.config.php';
        $config['view_manager'] = array(
            'template_path_stack' => array(
                __DIR__ . '/../view',
                __DIR__ . '/../User/view',
            ),
        );
        return $config;
    }
}
