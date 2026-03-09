<?php declare(strict_types=1);

namespace Urify\Service\Form;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Urify\Form\UrifyForm;

/**
 * Service factory to get the UrifyForm.
 */
class UrifyFormFactory implements FactoryInterface
{
    /**
     * Create and return the UrifyForm.
     *
     * @return \Urify\Form\UrifyForm
     */
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        /**  @var \Common\Stdlib\EasyMeta $easyMeta */
        $easyMeta = $services->get('Common\EasyMeta');

        $form = new UrifyForm(null, $options ?? []);
        return $form
            ->setDataTypesLabels($easyMeta->dataTypeLabels())
        ;
    }
}
