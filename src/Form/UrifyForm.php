<?php declare(strict_types=1);

namespace Urify\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

/**
 * Adapted
 * @see \BulkEdit\Form\BulkEditFieldset::appendFieldsetFillValues()
 * @see \Urify\Form\UrifyForm::init()
 *
 * @todo Add fill mode: missing or all.
 */
 class UrifyForm extends Form
{
    /**
     * @var array
     */
    protected $dataTypesLabels = [];

    public function init(): void
    {
        $hasMapper = class_exists('Mapper\Module', false);

        // All datatypes are included for uri, not to get label.
        // Uri is not selected on load, but all datatypes are included anyway
        // until mode selection.
        // For now, only uri is implemented anyway.
        /*
        $managedDatatypes = [
            'valuesuggest:geonames:geonames' => 'Geonames',
            'valuesuggest:idref:person' => 'IdRef Personnes',
            'valuesuggest:idref:corporation' => 'IdRef Organisations',
            'valuesuggest:idref:conference' => 'IdRef Congrès',
            'valuesuggest:idref:subject' => 'IdRef Sujets',
            'valuesuggest:idref:rameau' => 'IdRef Sujets Rameau',
        ];
        */

        /*
        $datatypesVSAttrs = [];
        foreach ($this->dataTypesLabels as $datatype => $label) {
            if (substr($datatype, 0, 13) === 'valuesuggest:'
                || substr($datatype, 0, 16) === 'valuesuggestall:'
            ) {
                $datatypesVSAttrs[] = [
                    'value' => $datatype,
                    'label' => $label,
                ];
            }
        }
        */

        // TODO Get the right name of domain (first part before ":" in the label.
        $datatypesVSGrouped = [];
        foreach ($this->dataTypesLabels as $datatype => $label) {
            if (substr($datatype, 0, 13) === 'valuesuggest:'
                || substr($datatype, 0, 16) === 'valuesuggestall:'
            ) {
                // Extract domain from datatype (e.g., "valuesuggest:idref:person" -> "idref")
                $parts = explode(':', $datatype);
                $domain = strpos($label, ':') === false ? $parts[1] ?? 'other' : strtok($label, ':');
                if (!isset($datatypesVSGrouped[$domain])) {
                    $datatypesVSGrouped[$domain] = [
                        'label' => ucfirst($domain),
                        'options' => [],
                    ];
                }
                $datatypesVSGrouped[$domain]['options'][] = [
                    'value' => $datatype,
                    'label' => $label,
                ];
            }
        }

        $this
            ->setAttribute('id', 'urify-form')
            ->setAttribute('class', 'form-urify')

            ->add([
                'name' => 'label',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Label', // @translate
                ],
                'attributes' => [
                    'id' => 'label',
                ],
            ])

            /*
            ->add([
                'name' => 'modes',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Processes', // @translate
                    'value_options' => [
                        'miss' => 'Get uris for missing literal values', // @translate
                        'replace' => 'Replace all values according to mapper', // @translate
                        // TODO Check existing uris.
                        // 'check' => 'Check existing uris when a label exists', // @translate
                        // Via BulkEdit for now.
                        // 'clean' => 'Clean labels', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'fill_modes',
                    'required' => true,
                    'value' => [
                        'miss',
                    ],
                ],
            ])
            */
            ->add([
                'name' => 'modes',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'id' => 'fill_modes',
                    'value' => 'miss',
                ],
            ])

            ->add([
                'name' => 'datatype',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Source of data for urification', // @translate
                    'empty_option' => '',
                    'value_options' => $datatypesVSGrouped,
                ],
                'attributes' => [
                    'id' => 'fill_datatype',
                    'class' => 'chosen-select',
                    'required' => true,
                    'multiple' => false,
                    'data-placeholder' => 'Select a source…', // @translate
                ],
            ])

            ->add([
                'name' => 'properties',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => $hasMapper
                        ? 'Properties to search (mapper) and to urify (single mode)' // @translate
                        : 'Properties to search and ti urify', // @translate
                    'term_as_value' => true,
                    'used_terms' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'fill_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'required' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])

            ->add([
                'name' => 'value_types',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Value types to process', // @translate
                    // Unlike bulkedit, there is no conversion between data types.
                    // So uri should be converted first.
                    'value_options' => [
                        'literal' => 'Literal', // @translate
                        // 'uri' => 'Uri', // @translate
                        'custom_vocab_literal' => 'Custom vocabs (literal)', // @translate
                        // 'custom_vocab_uri' => 'Custom vocabs (uri)', // @translate
                        // 'specified' => 'Specified data type', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'fill_value_types',
                    'value' => [
                        'literal',
                        'custom_vocab_literal'
                    ],
                ],
            ])

            ->add([
                'name' => 'language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Language code to improve request and response for some endpoints', // @translate
                ],
                'attributes' => [
                    'id' => 'fill_language',
                ],
            ])

            ->add([
                'name' => 'query',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Query to filter resources to process', // @translate
                ],
                'attributes' => [
                    'id' => 'query',
                ],
            ])

            // TODO Include properties for querying.
            // TODO Language to update (second manual form).
            // TODO Include the property to use for label; or convert the main value (for example remove birth date).
        ;
    }

    public function setDataTypesLabels(array $dataTypesLabels): self
    {
        $this->dataTypesLabels = $dataTypesLabels;
        return $this;
    }
}
