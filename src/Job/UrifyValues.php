<?php declare(strict_types=1);

namespace Urify\Job;

use Omeka\Job\AbstractJob;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Doctrine\DBAL\Query\QueryBuilder;

class UrifyValues extends AbstractJob
{
    use ValueTypesTrait;

    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 100;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Omeka\DataType\Manager
     */
    protected $dataTypeManager;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Http\Client
     */
    protected $httpClient;

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \ValueSuggest\Suggester\SuggesterInterface
     */
    protected $suggester;

    /**
     * @var \Laminas\I18n\Translator\TranslatorInterface
     */
    protected $translator;

    /**
     * @var string
     */
    protected $dataType;

    /**
     * @var array
     */
    protected $dataTypesSource;

    /**
     * @var string
     */
    protected $language;

    /**
     * @var int
     */
    protected $maxResults = 100;

    /**
     * @var array
     */
    protected $modes;

    /**
     * Property terms by id.
     *
     * @var array
     */
    protected $properties;

    /**
     * @var array
     */
    protected $query;

    /**
     * @var array
     */
    protected $resourceIds;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('urify/search_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);
        $this->api = $services->get('Omeka\ApiManager');
        $this->translator = $services->get('MvcTranslator');
        $this->httpClient = $services->get('Omeka\HttpClient');
        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->connection = $services->get('Omeka\Connection');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->dataTypeManager = $services->get('Omeka\DataTypeManager');

        // Check and prepare each option.

        // The status of error should be set just before return, else it can be
        // reset to "in_progress".
        $hasError = false;

        $this->modes = $this->getArg('modes') ?: [];
        $this->modes = array_intersect(['miss', 'check'], $this->modes);
        if (!$this->modes) {
            $hasError = true;
            $this->logger->err('No processes defined.'); // @translate
        }
        $modeMiss = in_array('miss', $this->modes);
        $modeCheck = in_array('check', $this->modes);

        $this->properties = $this->getArg('properties');
        $this->properties = $this->properties ? $this->easyMeta->propertyIds($this->properties) : null;
        if (!$this->properties) {
            $hasError = true;
            $this->logger->err('No properties provided.'); // @translate
        }

        $this->dataType = $this->getArg('datatype');
        if (!$this->dataType || !$this->easyMeta->dataTypeName($this->dataType)) {
            $hasError = true;
            $this->logger->err('No data type provided.'); // @translate
        }

        /* // Process to get label is not managed for now.
        $managedDatatypes = [
            'valuesuggest:geonames:geonames' => 'Geonames',
            'valuesuggest:idref:person' => 'IdRef Personnes',
            'valuesuggest:idref:corporation' => 'IdRef Organisations',
            'valuesuggest:idref:conference' => 'IdRef Congrès',
            'valuesuggest:idref:subject' => 'IdRef Sujets',
            'valuesuggest:idref:rameau' => 'IdRef Sujets Rameau',
        ];
        if ($this->dataType && !isset($managedDatatypes[$this->dataType])) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The data type {datatype} is not managed for now.', // @translate
                ['datatype' => $this->dataType]
            );
        }
        */

        $this->dataTypesSource = $this->dataTypesFromValueTypes($this->getArg('value_types'));
        if (!$this->dataTypesSource) {
            $hasError = true;
            $this->logger->err('No source data types provided.'); // @translate
        }

        // Update data types source according to modes.
        if ($modeMiss && $modeCheck) {
            // Both: proccess all.
        } elseif ($modeMiss) {
            // Only missing uri, so source should be literal.
            // Options for fake uri and custom vocab uri are not manage.
            $this->dataTypesSource = array_filter(
                $this->dataTypesSource,
                fn ($v) => $v === 'literal' || $this->easyMeta->dataTypeMainCustomVocab($v) === 'literal'
            );
        } elseif ($modeCheck) {
            // Only check uri, so source should be the specified data type.
            $this->dataTypesSource = [$this->dataType];
            $hasError = true;
            $this->logger->err('The process to check uris is not yet implemented.'); // @translate
        }
        if (!$this->dataTypesSource) {
            $hasError = true;
            $this->logger->err('No source data types matching options.'); // @translate
        }

        $this->language = $this->getArg('language') ?: null;

        if (!class_exists('ValueSuggest\Module', false)) {
            $hasError = true;
            $this->logger->err(
                'The module Value Suggest is required to get uris.' // @translate
            );
        }

        if ($this->dataType) {
            $errorDataType = false;
            if (!$this->dataTypeManager->has($this->dataType)) {
                $errorDataType = true;
            } else {
                $dataType = $this->dataTypeManager->get($this->dataType);
                if (!$dataType instanceof \ValueSuggest\DataType\DataTypeInterface) {
                    $errorDataType = true;
                } else {
                    $this->suggester = $dataType->getSuggester();
                    if (!$this->suggester instanceof \ValueSuggest\Suggester\SuggesterInterface) {
                        $errorDataType = true;
                    }
                }
            }
            if ($errorDataType) {
                $hasError = true;
                $this->logger->err(
                    'The data type {data_type} is not available.', // @translate
                    ['data_type' => $this->dataType]
                );
            }
        }

        if ($hasError) {
            // Anyway, the status will change without exception.
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }

        $this->query = $this->getArg('query') ?: [];

        // The loop can be done by mode, by resource or by property. It is more
        // consistent to check by property, so it is the main loop, after mode.

        if ($modeMiss) {
            $this->processMissingUri();
        }

        if ($modeCheck) {
            $this->processCheckUri();
        }

        $this->prepareSpreadsheet();
    }

    /**
     * Filter queries with modes, data types and properties, joined with or.
     * If there is already a property filter, this early filtering cannot be
     * done.
     *
     * @see \AdvancedSearch\Stdlib\SearchResources::buildFilterQuery()
     * @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::buildPropertyQuery()
     */
    protected function processMissingUri()
    {
        $dataTypes = array_filter(
            $this->dataTypesSource,
            fn ($v) => $v === 'literal' || $this->easyMeta->dataTypeMainCustomVocab($v) === 'literal'
        );

        // TODO When AdvancedSearch is available, use a sub-query.
        $hasPropertyFilter = !empty($this->query['property']);
        $hasAdvancedFilter = !empty($this->query['filter']);
        $hasAdvancedSearch = class_exists('AdvancedSearch\Module', false);
        $useFilter = !$hasAdvancedFilter && $hasAdvancedSearch;
        $useProperty = !$useFilter && !$hasPropertyFilter;

        foreach ($this->properties as $propertyTerm => $propertyId) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return;
            }

            $this->resourceIds = [];

            // Add filters to the query when there is one to limit resource ids
            // the most.
            if ($this->query) {
                $q = $this->query;
                if ($useFilter) {
                    $q['filter'][] = [
                        'field' => $propertyTerm,
                        'type' => 'dtp',
                        'val' => $dataTypes,
                    ];
                } elseif ($useProperty) {
                    // Data types cannot be checked here.
                    $q['property'][] = [
                        'property' => $propertyId,
                        'type' => 'ex',
                    ];
                }

                // TODO Search resources, not items.
                $response = $this->api->search('items', $q, ['returnScalar' => 'id']);
                $this->resourceIds = $response->getContent();
                if (!count($this->resourceIds)) {
                    $this->logger->warn(
                        'No resources found for property {property} with the given query and data types.', // @translate
                        ['property' => $propertyTerm]
                    );
                    continue;
                }
            }

            // Count the number of literal values to process.
            /** @var \Doctrine\DBAL\Query\QueryBuilder $qb */
            $qb = $this->connection->createQueryBuilder()
                ->select('COUNT(DISTINCT value.value)')
                ->from('value', 'value')
                ->innerJoin('value', 'resource', 'resource', 'value.resource_id = resource.id AND resource.resource_type = :resource_type')
                ->setParameter('resource_type', \Omeka\Entity\Item::class)
                ->where('value.property_id = :property_id')
                ->setParameter('property_id', $propertyId, \Doctrine\DBAL\Types\Types::INTEGER)
                ->andWhere('value.type IN (:data_types)')
                ->setParameter('data_types', $dataTypes, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
            ;
            // The list of resource ids contains items for all properties, so it
            // is useless to use it when there is no query.
            // TODO Do a specific query with current property with the api to limit the number of resource ids?
            if ($this->resourceIds) {
                $qb
                    ->andWhere('value.resource_id IN (:ids)')
                    ->setParameter('ids', $this->resourceIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);
            }

            $total = $qb->execute()->fetchOne();
            if (!$total) {
                $this->logger->warn(
                    'No literal values found for property {property} and specified data types.', // @translate
                    ['property' => $propertyTerm]
                );
                continue;
            }

            $this->logger->notice(
                'Processing {total} values for property {property}.', // @translate
                ['total' => $total, 'property' => $propertyTerm]
            );

            $this->getValueSuggestUriForLabel($qb, $propertyTerm);
        }
    }

    protected function processCheckUri()
    {
        $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
        $this->logger->err('The process to check uris is not yet implemented.'); // @translate
    }

    /**
     * Adapted:
     * @see \BulkEdit\Stdlib\BulkEdit::fillValuesForResource()
     * @see \BulkEdit\Stdlib\BulkEdit::getValueSuggestUriForLabel()
     * @see \Urify\Job\UrifyValues::getValueSuggestUriForLabel()
     */
    protected function getValueSuggestUriForLabel(QueryBuilder $qb, string $propertyTerm): ?array
    {
        // TODO When the same data type is used for multiple properties (creator/contributor), cache them?

        $qb
            ->resetQueryPart('select')
            ->select(
                'value.value AS v',
                'GROUP_CONCAT(value.resource_id ORDER BY value.resource_id ASC) AS r',
            )
            ->groupBy('value.value')
            ->orderBy('value.value', 'ASC');
        $values = $qb->execute()->fetchAllKeyValue();

        $results = [];
        foreach ($values as $label => $resourceIds) {
            $originalLabel = $label;
            $label = trim($label);
            if ($label !== $originalLabel) {
                $this->logger->warn(
                    'The provided label "{label}" is not trimmed. It is recommended to run the Easy Admin tasks to clean values.', // @translate
                    ['label' => $label]
                );
            }

            $resourceIds = array_unique(array_map('intval', explode(',', $resourceIds)));

            if (!strlen($label)) {
                // Very rare case on bad database anyway.
                $this->logger->warn(
                    'Property {property}: The label is an empty string. Resources: {resource_ids}.', // @translate
                    ['property' => $propertyTerm, 'resource_ids' => implode(', ', $resourceIds)]
                );
                continue;
            }

            $result = [
                'label' => $label,
                'count' => count($resourceIds),
                // Only the first ten resource ids for quick display.
                'resources' => array_slice($resourceIds, 0, 10),
                'uris' => [],
            ];

            $suggestions = $this->suggester->getSuggestions($label, $this->language);
            if (!is_array($suggestions) || !count($suggestions)) {
                $results[] = $result;
                continue;
            }

            // The proposed label and the uri are not in the same array.
            $uris = array_map(fn ($v) => ['u' => $v['data']['uri'], 'l' => $v['value']], $suggestions);
            $result['uris'] = array_column($uris, 'l', 'u');
            $results[] = $result;
        }

        $args = $this->job->getArgs();
        $args['results'][$propertyTerm] = array_values($results);
        $this->job->setArgs($args);

        return $results;
   }

   /**
    * Store the results of the job into a spreadsheet.
    *
    * The file is stored in files/result/urify-{date}-{time}-{jobid}.ods
    */
   protected function prepareSpreadsheet(): bool
   {
       $jobArgs = $this->job->getArgs();
       if (empty($jobArgs['results'])) {
           $this->logger->warn('No results to export to spreadsheet.'); // @translate
           return false;
       }

       // Create the spreadsheet directory if it doesn't exist
       $services = $this->getServiceLocator();
       $config = $services->get('Config');
       $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
       if (!$this->checkDestinationDir($basePath . '/result/urify')) {
           return false;
       }

       $urlHelper = $services->get('ViewHelperManager')->get('url');
       $tempDir = $config['temp_dir'] ?: sys_get_temp_dir();
       $baseUrl = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
       $baseUrlItem = rtrim($urlHelper->__invoke('admin/id', ['controller' => 'item', 'id' => '00'], ['force_canonical' => true]), '0');

       $filename = sprintf('urify-%s-%s-%d.ods', date('Ymd'), date('His'), $this->job->getId());
       $filepath = $basePath . '/result/urify/' . $filename;

       try {
           $writer = WriterEntityFactory::createODSWriter();
           $writer
               ->setTempFolder($tempDir)
               ->openToFile($filepath);

           // Prepare headers.
           $headers = [
               $this->translator->translate('Property'), // @translate
               $this->translator->translate('First item'), // @translate
               $this->translator->translate('Original value'), // @translate
               $this->translator->translate('Proposed label'), // @translate
               $this->translator->translate('Proposed uri'), // @translate
           ];
           $writer->addRow(WriterEntityFactory::createRowFromArray($headers));

           // Write data rows.
           foreach ($jobArgs['results'] as $property => $resultForProperty) foreach ($resultForProperty as $result) {
               $rowData = [];
               $rowData[] = $property;
               $rowData[] = empty($result['resources']) ? '' : $baseUrlItem . reset($result['resources']);
               $rowData[] = $result['label'] ?? '';
               $baseRowData = $rowData;
               if (empty($result['uris'])) {
                   $writer->addRow(WriterEntityFactory::createRowFromArray($rowData));
                   continue;
               }
               foreach ($result['uris'] as $uri => $label) {
                   $rowData = $baseRowData;
                   $rowData[] = $label;
                   $rowData[] = $uri;
                   $writer->addRow(WriterEntityFactory::createRowFromArray($rowData));
               }
           }

           $writer->close();

           $this->logger->notice(
               'Spreadsheet created: {url}', // @translate
               ['url' => $baseUrl . '/result/urify/' . $filename]
           );

           // Store filename in job args
           $jobArgs['spreadsheet'] = $filename;
           $this->job->setArgs($jobArgs);
           $this->entityManager->persist($this->job);

       } catch (\Exception $e) {
           $this->logger->err(
               'Failed to create spreadsheet: {message}', // @translate
               ['message' => $e->getMessage()]
           );
           return false;
       }

       return true;
   }

   /**
    * Check or create the destination folder.
    *
    * @param string $dirPath Absolute path.
    * @return string|null
    */
   protected function checkDestinationDir($dirPath): ?string
   {
       if (file_exists($dirPath)) {
           if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
               $this->logger->err(
                   'The directory "{path}" is not writeable.', // @translate
                   ['path' => $dirPath]
               );
               return null;
           }
           return $dirPath;
       }

       $result = @mkdir($dirPath, 0775, true);
       if (!$result) {
           $this->logger->err(
               'The directory "{path}" is not writeable: {error}.', // @translate
               ['path' => $dirPath, 'error' => error_get_last()['message']]
           );
           return null;
       }

       return $dirPath;
   }
}
