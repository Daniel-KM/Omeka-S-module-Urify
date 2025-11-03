<?php declare(strict_types=1);

namespace Urify\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class UrifyForm extends Form
{
    public function init(): void
    {
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
            ->add([
                'name' => 'endpoints',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Repositories', // @translate
                    'value_options' => [
                        'omeka' => 'Omeka',
                        'archive.org' => 'Archive.org',
                        'dloc' => 'dLoc',
                        'gallica' => 'Gallica',
                        'persée' => 'Persée',
                    ],
                ],
                'attributes' => [
                    'id' => 'endpoints',
                    'value' => [],
                ],
            ])
            ->add([
                'name' => 'file',
                'type' => Element\File::class,
                'options' => [
                    'label' => 'Spreedsheet (csv, tsv, ods)', // @translate
                ],
                'attributes' => [
                    'id' => 'file',
                    'accept' => '.csv,.tab,.tsv,.ods,.txt,text/csv,text/tsv,text/plain,text/tab-separated-values,application/vnd.oasis.opendocument.spreadsheet',
                ],
            ])
            ->add([
                'name' => 'references',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'List of resources to urify, eventually with elements separated by "="', // @translate
                ],
                'attributes' => [
                    'id' => 'references',
                    'rows' => 20,
                    'placeholder' => <<<'TXT'
                        Creator = Title = Date
                        Victor Hugo = Les misérables = 1862
                        TXT,
                ],
            ])
        ;
    }
}
