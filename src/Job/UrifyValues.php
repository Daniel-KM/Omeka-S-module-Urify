<?php declare(strict_types=1);

namespace Urify\Job;

use Omeka\Job\AbstractJob;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Doctrine\DBAL\Query\QueryBuilder;

class UrifyValues extends AbstractJob
{
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
        $referenceIdProcessor->setReferenceId('urify/index_' . $this->job->getId());

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

        $this->modes = $this->getArg('modes');
        $this->modes = array_intersect(['miss', 'check'], $this->modes);
        if (!$this->modes) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err('No processes defined.'); // @translate
        }
        $modeMiss = in_array('miss', $this->modes);
        $modeCheck = in_array('check', $this->modes);

        $this->properties = $this->getArg('properties');
        $this->properties = $this->properties ? $this->easyMeta->propertyIds($this->properties) : null;
        if (!$this->properties) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err('No properties provided.'); // @translate
        }

        $this->dataType = $this->getArg('datatype');
        if (!$this->dataType || !$this->easyMeta->dataTypeName($this->dataType)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
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

        $dataTypes = $this->getArg('datatypes') ?: [];
        if (!$dataTypes) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err('No source data types provided.'); // @translate
        }

        // Prepare source data types.
        $this->dataTypesSource = [];
        if (in_array('literal', $dataTypes)) {
            $this->dataTypesSource[] = 'literal';
        }
        if (in_array('uri', $dataTypes)) {
            $this->dataTypesSource[] = 'uri';
        }
        if (in_array('specified', $dataTypes)) {
            $this->dataTypesSource[] = $this->dataType;
        }
        if (in_array('custom_vocab_literal', $dataTypes)) {
            $mainCustomVocabs = $this->easyMeta->dataTypeMainCustomVocabs();
            $this->dataTypesSource = array_merge(
                $this->dataTypesSource,
                array_values(array_filter($mainCustomVocabs, fn ($v) => $v === 'literal'))
            );
        }
        if (in_array('custom_vocab_uri', $dataTypes)) {
            $mainCustomVocabs = $this->easyMeta->dataTypeMainCustomVocabs();
            $this->dataTypesSource = array_merge(
                $this->dataTypesSource,
                array_values(array_filter($mainCustomVocabs, fn ($v) => $v === 'uri'))
            );
        }
        $this->dataTypesSource = array_unique($this->dataTypesSource);
        if ($dataTypes && !$this->dataTypesSource) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
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
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err('The process to check uris is not yet implemented.'); // @translate
        }
        if (!$this->dataTypesSource) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err('No source data types matching options.'); // @translate
        }

        $this->language = $this->getArg('language') ?: null;

        if (!class_exists('ValueSuggest\Module', false)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
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
                $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                $this->logger->err(
                    'The data type {data_type} is not available.', // @translate
                    ['data_type' => $this->dataType]
                );
            }
        }

        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
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

            $resourceIds = array_unique(explode(',', $resourceIds));

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
}
