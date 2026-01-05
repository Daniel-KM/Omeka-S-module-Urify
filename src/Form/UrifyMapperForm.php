<?php declare(strict_types=1);

namespace Urify\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Form;
use Mapper\Form\Element as MapperElement;

 class UrifyMapperForm extends Form
{
    public function init(): void
    {
        $this
            ->setAttribute('id', 'urify-mapper-form')
            ->setAttribute('class', 'form-urify-mapper')

            ->add([
                'name' => 'update_mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Update mode of values', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'single' => 'Single value', // @translate
                        'multiple' => 'Mapper selected below', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'update_mode',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'update_mapper',
                'type' => MapperElement\MapperSelect::class,
                'options' => [
                    'label' => 'Mapper to use', // @translate
                    'empty_option' => '',
                    'disable_group_by_owner' => true,
                ],
                'attributes' => [
                    'id' => 'update_mapper',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a mapper…', // @translate
                ],
            ])
        ;
    }
}
