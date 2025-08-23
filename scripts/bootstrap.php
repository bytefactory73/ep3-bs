<?php
// scripts/bootstrap.php
// Minimal Zend Framework ServiceManager bootstrap for scripts (no session, no MVC)

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

use Zend\ServiceManager\ServiceManager;
use Zend\Mvc\Service\ServiceManagerConfig;

// Load application config
$appConfig = require __DIR__ . '/../config/application.config.php';

// Build ServiceManager
$serviceManager = new ServiceManager();
$serviceManagerConfig = new ServiceManagerConfig($appConfig['service_manager'] ?? []);
$serviceManagerConfig->configureServiceManager($serviceManager);
$serviceManager->setService('ApplicationConfig', $appConfig);

// Load modules
$moduleManager = $serviceManager->get('ModuleManager');
$moduleManager->loadModules();

return $serviceManager;
