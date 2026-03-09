<?php declare(strict_types=1);

namespace Urify;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

$config = $services->get('Config');
$basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

if (PHP_VERSION_ID < 80100) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s requires PHP %2$s or later.'), // @translate
        'Urify', '8.1'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.81')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.81'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}
