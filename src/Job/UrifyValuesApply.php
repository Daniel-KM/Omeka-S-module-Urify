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
        // A global sql is simple to use, but it won't run sub-jobs for indexes,
        // title update, etc. So use a simple batch loop on resource ids.

        // The other way is to run a sql, get the list of modified resource ids and to run a loop on them. But it is not possible for some tools.

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
}
