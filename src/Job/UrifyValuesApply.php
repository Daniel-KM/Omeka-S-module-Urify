<?php declare(strict_types=1);

namespace Urify\Job;

use Omeka\Job\AbstractJob;

class UrifyValuesApply extends AbstractJob
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
    protected $userAgent;

    /**
     * @var \Mapper\Stdlib\Mapper|null
     */
    protected $mapper;

    /**
     * @var int
     */
    protected $maxResults = 100;

    /**
     * @var array
     */
    protected $arguments;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('urify/apply_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);
        $this->api = $services->get('Omeka\ApiManager');
        $this->translator = $services->get('MvcTranslator');
        $this->httpClient = $services->get('Omeka\HttpClient');
        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->connection = $services->get('Omeka\Connection');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->dataTypeManager = $services->get('Omeka\DataTypeManager');

        $moduleVersion = $services->get('Omeka\ModuleManager')->getModule('Urify')->getIni('version');
        $this->userAgent = 'Omeka-S-Urify/' . $moduleVersion;

        // Check and prepare each option.

        $this->arguments = [
            'value' => null,
            'uri' => null,
            'label' => null,
            'property' => null,
            'datatype' => null,
            'data_types' => [],
            'query' => [],
            'updateMode' => 'single',
            'updateMapper' => null,
        ];

        // The status of error should be set just before return, else it can be
        // reset to "in_progress".
        $hasError = false;
        $this->arguments['value'] = trim((string) ($this->getArg('value') ?? ''));
        if (!strlen($this->arguments['value'])) {
            $hasError = true;
            $this->logger->err('No value to replace provided.'); // @translate
        }

        $this->arguments['uri'] = trim((string) ($this->getArg('uri') ?? ''));
        if (!strlen($this->arguments['uri'])) {
            $hasError = true;
            $this->logger->err('No uri to use for replacement provided.'); // @translate
        }

        $this->arguments['label'] = trim((string) ($this->getArg('label') ?? ''));
        if (!strlen($this->arguments['label'])) {
            $this->logger->notice('No label to use for replacement: the existing value will be kept.'); // @translate
        }

        $this->arguments['property'] = (string) $this->easyMeta->propertyTerm($this->getArg('property'));
        if (!strlen($this->arguments['property'])) {
            $hasError = true;
            $this->logger->err('No property provided.'); // @translate
        }

        $this->arguments['datatype'] = (string) $this->easyMeta->dataTypeName($this->getArg('datatype'));
        if (!strlen($this->arguments['datatype'])) {
            $hasError = true;
            $this->logger->err('No data type provided.'); // @translate
        }

        $this->arguments['data_types'] = $this->dataTypesFromValueTypes($this->getArg('value_types'));
        if (!$this->arguments['data_types']) {
            $hasError = true;
            $this->logger->err('No value types provided.'); // @translate
        }

        $this->arguments['query'] = $this->getArg('query') ?? [];
        if ($this->arguments['query']) {
            $this->logger->notice('The process will be done on resource matching the specified query.'); // @translate
        } else {
            $this->logger->notice('No query provided: the process will be done on all resources with the specified property.'); // @translate
        }

        // TODO Use a language to fill.

        $this->arguments['updateMode'] = $this->getArg('updateMode') ?? 'single';
        $this->arguments['updateMapper'] = $this->getArg('updateMapper');

        // Validate mapper for multiple mode.
        if ($this->arguments['updateMode'] === 'multiple') {
            if (!$this->arguments['updateMapper']) {
                $hasError = true;
                $this->logger->err('A mapper is required when using mode "multiple".'); // @translate
            }
            if (!class_exists('Mapper\Module', false)) {
                $hasError = true;
                $this->logger->err('The module Mapper is required for mode "multiple".'); // @translate
            } else {
                $this->mapper = $services->get(\Mapper\Stdlib\Mapper::class);
            }
        }

        if (!class_exists('ValueSuggest\Module', false)) {
            $hasError = true;
            $this->logger->err(
                'The module Value Suggest is required to get uris.' // @translate
            );
        }

        if ($this->arguments['datatype']) {
            $errorDataType = false;
            if (!$this->dataTypeManager->has($this->arguments['datatype'])) {
                $errorDataType = true;
            } else {
                $dataType = $this->dataTypeManager->get($this->arguments['datatype']);
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
                    ['data_type' => $this->arguments['datatype']]
                );
            }
        }

        if ($hasError) {
            // Anyway, the status will change without exception.
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }

        $this->urify();
    }

    protected function urify()
    {
        if ($this->arguments['updateMode'] === 'multiple') {
            $this->urifyMultiple();
            return;
        }

        // A global sql is simple to use, but it won't run sub-jobs for indexes,
        // title update, etc. So use a simple batch loop on resource ids.

        // The other way is to run a sql, get the list of modified resource ids
        // and to run a loop on them. But it is not possible for some tools.

        // If there is a query, use it.
        // TODO When AdvancedSearch is available, use a sub-query.

        $resourceIds = null;
        if ($this->arguments['query']) {
            $resourceIds = $this->api->search('items', $this->arguments['query'], ['returnScalar' => 'id'])->getContent();
            if (!count($resourceIds)) {
                $this->logger->warn(
                    'The query returned no resources.' // @translate
                );
                return;
            }
        }

        // The filter is more specific and get exactly the wanted resources,
        // without false positive.
        if (class_exists('AdvancedSearch\Module', false)) {
            $valueQuery = [
                'filter' => [
                    [
                        'join' => 'and',
                        'field' => $this->arguments['property'],
                        'type' => 'eq',
                        'val' => $this->arguments['value'],
                        'datatype' => $this->arguments['data_types'],
                    ],
                ],
            ];
        } else {
            $valueQuery = [
                'property' => [
                    [
                        'joiner' => 'and',
                        'property' => $this->arguments['property'],
                        'type' => 'eq',
                        'text' => $this->arguments['value'],
                    ],
                ],
            ];
        }
        if ($resourceIds) {
            $valueQuery['id'] = $resourceIds;
        }

        $propertyIdsByTerms = $this->easyMeta->propertyIds();
        $updateLabel = $this->arguments['label'] !== '';
        $lowerValue = mb_convert_case($this->arguments['value'], MB_CASE_FOLD, 'UTF-8');

        $resourceIds = $this->api->search('items', $valueQuery, ['returnScalar' => 'id'])->getContent();
        $resourceIds = array_map('intval', $resourceIds);
        $processedIds = [];
        foreach ($resourceIds as $resourceId) {
            if ($this->shouldStop()) {
                $this->logger->notice(
                    'Urification ended for {count} resources: {resource_ids}', // @translate
                    ['count' => count($processedIds), 'resource_ids' => implode(', ', $processedIds)]
                );
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return false;
            }
            try {
                /** @var \Omeka\Api\Representation\ItemRepresentation $item */
                $item = $this->api->read('items', ['id' => $resourceId])->getContent();
            } catch (\Exception $e) {
                continue;
            }

            // Check if the item really contains the wanted value.
            $itemValues = array_intersect_key($item->jsonSerialize(), $propertyIdsByTerms);
            if (!isset($itemValues[$this->arguments['property']])
                || !count($itemValues[$this->arguments['property']])
            ) {
                continue;
            }

            // If the "early full real json-serialize" is used, values is an
            // array or arrays, else it may be an array of objects.
            /** @var \Omeka\Api\Representation\ValueRepresentation[]|[] $values */
            $itemValues = json_decode(json_encode($itemValues), true);

            $hasChange = false;
            foreach ($itemValues[$this->arguments['property']] as $key => $value) {
                if (mb_convert_case(strval($value['@value'] ?? ''), MB_CASE_FOLD, 'UTF-8') === $lowerValue
                    && in_array($value['type'], $this->arguments['data_types'])
                ) {
                    $value['type'] = $this->arguments['datatype'];
                    $value['@id'] = $this->arguments['uri'];
                    $value['o:label'] = $updateLabel
                        ? $this->arguments['label']
                        :  $value['@value'];
                    unset($value['@value']);
                    $itemValues[$this->arguments['property']][$key] = $value;
                    $hasChange = true;
                }
            }
            if (!$hasChange) {
                continue;
            }

            $this->api->update('items', $resourceId, $itemValues, [], ['isPartial' => true]);

            $processedIds[] = $resourceId;
            if (count($processedIds) % 100 === 0) {
                // The entity manager is already flushed via api update.
                $this->entityManager->clear();
            }
        }

        $this->logger->notice(
            'Urification ended for {count} resources: {resource_ids}', // @translate
            ['count' => count($processedIds), 'resource_ids' => implode(', ', $processedIds)]
        );
    }

    /**
     * Urify values using a mapper to convert remote data into multiple values.
     *
     * So:
     * - Fetch xml or rdf from the uri;
     * - convert output to Omeka resource values via the mapper;
     * - Replace all values of the matching resources with the mapped values;
     * - Keep existing values for properties not in the mapping.
     *
     * @todo Create a stdlib in Mapper to manage applying a mapper to a resource, with different modes (add, update, replace, etc.).
     */
    protected function urifyMultiple(): void
    {
        $uri = $this->arguments['uri'];

        // 1. Load mapper first to get media type for content negotiation.
        // Mapper reference can be a database ID (numeric) or a file reference (module:path or user:path).
        $updateMapper = $this->arguments['updateMapper'];
        $mapperRef = is_numeric($updateMapper)
            ? 'mapping:' . $updateMapper
            : $updateMapper;
        try {
            $this->mapper->setMapping('urify', $mapperRef);
        } catch (\Exception $e) {
            $this->logger->err(
                'Unable to load mapper "{mapper}": {error}', // @translate
                ['mapper' => $this->arguments['updateMapper'], 'error' => $e->getMessage()]
            );
            return;
        }

        // Get the media type from mapper info section, or use default for rdf/xml.
        $mapperConfig = $this->mapper->getMapperConfig();
        $mediaType = $mapperConfig->getSectionSetting('info', 'media_type')
            ?? 'application/rdf+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5';

        // 2. Fetch data from the URI with appropriate Accept header.
        $this->httpClient->reset();
        $this->httpClient->setUri($uri);
        $this->httpClient->setOptions([
            'timeout' => 30,
            'useragent' => $this->userAgent,
        ]);
        $this->httpClient->setHeaders([
            'Accept' => $mediaType,
        ]);

        try {
            $response = $this->httpClient->send();
        } catch (\Exception $e) {
            $this->logger->err(
                'Unable to fetch data from URI "{uri}": {error}', // @translate
                ['uri' => $uri, 'error' => $e->getMessage()]
            );
            return;
        }

        if (!$response->isSuccess()) {
            $this->logger->err(
                'Unable to fetch data from URI "{uri}": HTTP {status}', // @translate
                ['uri' => $uri, 'status' => $response->getStatusCode()]
            );
            return;
        }

        $xmlContent = $response->getBody();

        // 3. Parse xml.
        try {
            $simpleXml = new \SimpleXMLElement($xmlContent);
        } catch (\Exception $e) {
            $this->logger->err(
                'Invalid XML from URI "{uri}": {error}', // @translate
                ['uri' => $uri, 'error' => $e->getMessage()]
            );
            return;
        }

        // 4. Convert xml to Omeka resource values using the mapper.
        $mappedValues = $this->mapper->convert($simpleXml);

        if (empty($mappedValues)) {
            $this->logger->warn(
                'No values mapped from URI "{uri}" using mapper "{mapper}".', // @translate
                ['uri' => $uri, 'mapper' => $this->arguments['updateMapper']]
            );
            return;
        }

        $this->logger->info(
            'Mapped {count} properties from URI "{uri}".', // @translate
            ['count' => count($mappedValues), 'uri' => $uri]
        );

        // Convert mapper output to Omeka API format.
        $mappedValues = $this->convertMappedValuesToResource($mappedValues);

        // 5. Find resources with matching value (see single mode).
        $resourceIds = null;
        if ($this->arguments['query']) {
            $resourceIds = $this->api->search('items', $this->arguments['query'], ['returnScalar' => 'id'])->getContent();
            if (!count($resourceIds)) {
                $this->logger->warn(
                    'The query returned no resources.' // @translate
                );
                return;
            }
        }

        // Build query to find resources with the value to replace.
        if (class_exists('AdvancedSearch\Module', false)) {
            $valueQuery = [
                'filter' => [
                    [
                        'join' => 'and',
                        'field' => $this->arguments['property'],
                        'type' => 'eq',
                        'val' => $this->arguments['value'],
                        'datatype' => $this->arguments['data_types'],
                    ],
                ],
            ];
        } else {
            $valueQuery = [
                'property' => [
                    [
                        'joiner' => 'and',
                        'property' => $this->arguments['property'],
                        'type' => 'eq',
                        'text' => $this->arguments['value'],
                    ],
                ],
            ];
        }
        if ($resourceIds) {
            $valueQuery['id'] = $resourceIds;
        }

        $propertyIdsByTerms = $this->easyMeta->propertyIds();
        $lowerValue = mb_convert_case($this->arguments['value'], MB_CASE_FOLD, 'UTF-8');

        $resourceIds = $this->api->search('items', $valueQuery, ['returnScalar' => 'id'])->getContent();
        $resourceIds = array_map('intval', $resourceIds);
        $processedIds = [];

        // 6. Update each resource.
        foreach ($resourceIds as $resourceId) {
            if ($this->shouldStop()) {
                $this->logger->notice(
                    'Urification (multiple) ended for {count} resources: {resource_ids}', // @translate
                    ['count' => count($processedIds), 'resource_ids' => implode(', ', $processedIds)]
                );
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return;
            }

            try {
                /** @var \Omeka\Api\Representation\ItemRepresentation $item */
                $item = $this->api->read('items', ['id' => $resourceId])->getContent();
            } catch (\Exception $e) {
                continue;
            }

            // Get existing values.
            $itemValues = array_intersect_key($item->jsonSerialize(), $propertyIdsByTerms);
            if (!isset($itemValues[$this->arguments['property']])
                || !count($itemValues[$this->arguments['property']])
            ) {
                continue;
            }

            // Ensure proper array format.
            $itemValues = json_decode(json_encode($itemValues), true);

            // Check if the item really contains the value we're replacing.
            $hasMatchingValue = false;
            foreach ($itemValues[$this->arguments['property']] as $key => $value) {
                if (mb_convert_case(strval($value['@value'] ?? ''), MB_CASE_FOLD, 'UTF-8') === $lowerValue
                    && in_array($value['type'], $this->arguments['data_types'])
                ) {
                    // Remove the original value that is being replaced.
                    unset($itemValues[$this->arguments['property']][$key]);
                    $hasMatchingValue = true;
                }
            }

            if (!$hasMatchingValue) {
                continue;
            }

            // Re-index the array after removing elements.
            $itemValues[$this->arguments['property']] = array_values($itemValues[$this->arguments['property']]);
            if (empty($itemValues[$this->arguments['property']])) {
                unset($itemValues[$this->arguments['property']]);
            }

            // Merge mapped values with existing values.
            // For each mapped property, replace or add the values.
            foreach ($mappedValues as $property => $values) {
                // Skip non-property fields (like o:resource_class, etc.).
                if (!isset($propertyIdsByTerms[$property])) {
                    continue;
                }
                // Replace/add mapped property values.
                $itemValues[$property] = $values;
            }

            $this->api->update('items', $resourceId, $itemValues, [], ['isPartial' => true]);

            $processedIds[] = $resourceId;
            if (count($processedIds) % 100 === 0) {
                // The entity manager is already flushed via api update.
                $this->entityManager->clear();
            }
        }

        $this->logger->notice(
            'Urification (multiple) ended for {count} resources: {resource_ids}', // @translate
            ['count' => count($processedIds), 'resource_ids' => implode(', ', $processedIds)]
        );
    }

    /**
     * Convert mapper output for resources.
     *
     * The mapper returns values like:
     *   ['dcterms:title' => ['My title'], 'dcterms:language' => ['http://...']]
     *
     * The resource should be:
     *   ['dcterms:title' => [['type' => 'literal', 'property_id' => 1, '@value' => 'My title']]]
     */
    protected function convertMappedValuesToResource(array $mappedValues): array
    {
        $result = [];
        $propertyIdsByTerms = $this->easyMeta->propertyIds();

        foreach ($mappedValues as $term => $values) {
            // Skip non-property fields (like o:resource_class, o:resource_template).
            // TODO Manage non-property fields.
            if (!isset($propertyIdsByTerms[$term])) {
                continue;
            }

            $propertyId = $propertyIdsByTerms[$term];

            foreach ($values as $value) {
                // Handle values that are already in array format (with metadata).
                if (is_array($value)) {
                    $val = [
                        'type' => $value['type'] ?? $value['datatype'] ?? 'literal',
                        'property_id' => $propertyId,
                    ];
                    if (isset($value['@value']) || isset($value['__value'])) {
                        $val['@value'] = $value['@value'] ?? $value['__value'];
                    }
                    if (isset($value['@id'])) {
                        $val['@id'] = $value['@id'];
                    }
                    if (isset($value['@language'])) {
                        $val['@language'] = $value['@language'];
                    }
                    if (isset($value['o:label'])) {
                        $val['o:label'] = $value['o:label'];
                    }
                    if (isset($value['is_public'])) {
                        $val['is_public'] = $value['is_public'];
                    }
                } else {
                    $stringValue = (string) $value;
                    if (filter_var($stringValue, FILTER_VALIDATE_URL)) {
                        $val = [
                            'type' => 'uri',
                            'property_id' => $propertyId,
                            '@id' => $stringValue,
                        ];
                    } else {
                        $val = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $stringValue,
                        ];
                    }
                }

                $result[$term][] = $val;
            }
        }

        return $result;
    }
}
