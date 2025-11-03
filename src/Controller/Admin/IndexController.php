<?php declare(strict_types=1);

namespace Urify\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Mvc\Exception\NotFoundException;
use Urify\Form\UrifyForm;
use Urify\Job\UrifyResources;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        return $this->redirect()->toRoute('admin/urify/default', ['controller' => 'index', 'action' => 'add']);
    }

    public function addAction()
    {
        /** @var \Urify\Form\UrifyForm $form */
        $form = $this->getForm(UrifyForm::class);
        $form->init();

        $view = new ViewModel([
            'form' => $form,
        ]);

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $view;
        }

        $params = $request->getPost();

        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $params = $form->getData();
        unset($params['csrf']);

        $endpoints = $params['endpoints'] ?? [];
        if (!$endpoints) {
            $this->messenger()->addError('No endpoints defined.'); // @translate
            return $view;
        }
        $file = $request->getFiles('file');
        $references = $this->fileToReferences($file);
        $isInput = $references === null;
        if ($isInput) {
            $references = $params['references'] ?? [];
        }
        if (!$references) {
            $this->messenger()->addError('No references defined.'); // @translate
            return $view;
        }

        // Check if the first row contains formats.
        $firstRow = reset($references);
        if ($isInput) {
            $firstRow = array_map('trim', explode('=', $firstRow));
        }
        $format = $this->extractFormat($firstRow);
        if ($format) {
            array_shift($references);
            $count = count($format);
            // Make an array for input.
            if ($isInput) {
                $references = array_map(fn ($v) => array_map('trim', explode('=', $v, $count)), $references);
            }
            if (!count($references)) {
                $this->messenger()->addError('The references contain only the headers.'); // @translate
                return $view;
            }
            // Remove useless columns.
            $references = array_map(fn ($v) => array_slice($v, 0, $count), $references);
            $this->messenger()->addWarning('The format is a list of properties for precise search.'); // @translate
        } else {
            // A simple list of string.
            if ($isInput) {
                $references = $params['references'];
            } else {
                $references = array_map(fn ($v) => implode(' ', $v), $references);
            }
            $this->messenger()->addWarning('No format defined for references: using global search.'); // @translate
        }

        if (!count(array_filter($references))) {
            $this->messenger()->addError('All the references are empty.'); // @translate
            return $view;
        }

        $args = [
            'label' => $params['label'] ?? '',
            'endpoints' => $endpoints,
            'format' => $format,
            'references' => $references,
        ];

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        // Use synchronous dispatcher for quick testing purpose.
        $strategy = null;
        // $strategy = $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()->get(\Omeka\Job\DispatchStrategy\Synchronous::class);
        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(\Urify\Job\UrifyResources::class, $args, $strategy);
        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing {link_result}urification{link_end} of resources in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_result' => sprintf('<a href="%s">', $urlPlugin->fromRoute('admin/urify/id', ['id' => $job->getId()])),
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        //  Reinit the list for a new job.
        $form = $this->getForm(UrifyForm::class);
        return new ViewModel([
            'form' => $form,
        ]);
    }

    public function browseAction()
    {
        $this->browse()->setDefaults('jobs');

        $query = $this->params()->fromQuery();
        $query = ['class' => UrifyResources::class] + $query;

        $response = $this->api()->search('jobs', $query);
        $this->paginator($response->getTotalResults());

        $services = $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator();
        $config = $services->get('Config');
        $baseUrlFiles = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';

        return new ViewModel([
            'jobs' => $response->getContent(),
            'baseUrlFiles' => $baseUrlFiles,
        ]);
    }

    public function showAction()
    {
        $id = $this->params('id');

        try {
            /** @var \Omeka\Api\Representation\JobRepresentation $job */
            $job = $this->api()->read('jobs', ['id' => $id, 'class' => UrifyResources::class])->getContent();
        } catch (\Exception $e) {
            throw new NotFoundException();
        }

        $services = $job->getServiceLocator();
        $config = $services->get('Config');
        $baseUrlFiles = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';

        $results = $job->args()['results'] ?? [];
        return new ViewModel([
            'job' => $job,
            'results' => $results,
            'baseUrlFiles' => $baseUrlFiles,
        ]);
    }

    /**
     * Extract the uploaded file (csv, tsv, ods) to get references.
     *
     * @param array $fileData File data from a post ($_FILES).
     * @return array
     */
    protected function fileToReferences(?array $fileData): ?array
    {
        if ($fileData === null || $fileData['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (empty($fileData)
            || empty($fileData['tmp_name'])
            || empty($fileData['name'])
            || empty($fileData['type'])
            || !is_readable($fileData['tmp_name'])
        ) {
            return [];
        }

        $filePath = $fileData['tmp_name'];
        $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        $mediaType = $fileData['type'];

        // Manage an exception for a very common format, undetected by fileinfo.
        if ($mediaType === 'text/plain' || $mediaType === 'application/octet-stream') {
            $extensions = [
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'tab' => 'text/tab-separated-values',
                'tsv' => 'text/tab-separated-values',
            ];
            if (isset($extensions[$extension])) {
                $mediaType = $extensions[$extension];
                $fileData['type'] = $mediaType;
            }
        }

        $references = [];
        switch ($mediaType) {
            case 'application/vnd.oasis.opendocument.spreadsheet':
                // The composer OpenSpout is used only here, so autoload here.
                // TODO In fact, it can be used for all formats.
                require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
                // Extract the active sheet with openspout.
                /** @var \OpenSpout\Reader\ODS\Reader $spreadsheetReader */
                $reader = \OpenSpout\Reader\Common\Creator\ReaderEntityFactory::createODSReader();
                // Important, else next rows will be skipped.
                $reader->setShouldPreserveEmptyRows(true);
                try {
                    $reader->open($filePath);
                    $reader
                        // ->setTempFolder($this->getServiceLocator()->get('Config')['temp_dir'])
                        // Read the dates as text. See fix #179 in CSVImport.
                        // TODO Read the good format in spreadsheet entry.
                        ->setShouldFormatDates(true);
                    $activeSheet = null;
                    // First pass: find the active sheet
                    foreach ($reader->getSheetIterator() as $sheet) {
                        if ($sheet->isActive()) {
                            $activeSheet = $sheet;
                            break;
                        }
                    }
                    // If no active sheet found, use the first sheet
                    if (!$activeSheet) {
                        foreach ($reader->getSheetIterator() as $sheet) {
                            $activeSheet = $sheet;
                            break;
                        }
                    }
                    // Extract data from the active sheet
                    if ($activeSheet) {
                        foreach ($activeSheet->getRowIterator() as $row) {
                            $r = [];
                            foreach ($row->getCells() as $cell) {
                                $r[] = trim((string) $cell->getValue());
                            }
                            $references[] = $r;
                        }
                    }
                } catch (\OpenSpout\Common\Exception\IOException $e) {
                    $this->messenger()->addError('Unable to read the spreadsheet file.'); // @translate
                } finally {
                    $reader->close();
                }
                return $references;
            case 'text/csv':
                $separator = ',';
                $enclosure = '"';
                break;
            case 'text/plain':
            case 'text/tab-separated-values':
                $separator = "\t";
                $enclosure = chr(0);
                break;
            default:
                return [];
        }

        // For csv/tsv.
        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle, 0, $separator, $enclosure)) !== false) {
                $r = [];
                foreach ($row as $cell) {
                    $r[] = trim((string) $cell);
                }
                $references[] = $r;
            }
            fclose($handle);
        }
        return $references;
    }

    /**
     * Check if the row contains dublin core properties or label to get them.
     *
     * All the cell should be a valid properties, else nothing is returned.
     * The label may be translated.
     */
    protected function extractFormat(array $row): array
    {
        /**
         * @var \Common\Stdlib\EasyMeta $easyMeta
         */
        $easyMeta = $this->easyMeta()();

        // Get the dublin core terms from terms, labels or translated labels.
        // array_change_key_case() can't be used because it is not unicode-safe.
        $propertyTerms = $easyMeta->propertyTerms();
        $propertyTerms = array_filter($propertyTerms, fn ($v) => substr($v, 0, 8) === 'dcterms:');
        $propertyLabels = $easyMeta->propertyLabels($propertyTerms);
        $propertyTerms = array_merge(
            array_flip(array_combine($propertyTerms, array_map('mb_strtolower', $propertyTerms))),
            array_flip(array_map('mb_strtolower', $propertyLabels)),
            array_flip(array_map(fn ($v) => mb_strtolower($this->translate($v)), $propertyLabels)),
        );

        $format = [];
        foreach ($row as $cell) {
            $cell = mb_strtolower(trim((string) $cell));
            if (!isset($propertyTerms[$cell])) {
                return [];
            }
            $format[] = $propertyTerms[$cell];
        }

        return $format;
    }
}
