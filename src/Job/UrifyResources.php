<?php declare(strict_types=1);

namespace Urify\Job;

use Omeka\Job\AbstractJob;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

class UrifyResources extends AbstractJob
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

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
     * @var \Laminas\I18n\Translator\TranslatorInterface
     */
    protected $translator;

    /**
     * @var array
     */
    protected $endpoints;

    /**
     * @var array|null
     */
    protected $format;

    /**
     * @var int
     */
    protected $maxResults = 100;

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
        $this->entityManager = $services->get('Omeka\EntityManager');

        $jobArgs = $this->job->getArgs();

        $references = $this->getArg('references');
        if (!$references || !count(array_filter($references))) {
            $this->logger->err('No list of references provided.'); // @translate
            return;
        }

        $this->endpoints = $this->getArg('endpoints');
        if (!$this->endpoints) {
            $this->logger->err('No endpoints defined.'); // @translate
            return;
        }

        $this->format = $this->getArg('format') ?: null;
        if (is_string($this->format)) {
            $this->format = array_map('trim', explode('=', $this->format));
        }
        if (!$this->format) {
            $this->logger->warn('No format provided for references: using global search.'); // @translate
        }

        $results = [];

        // Results are stored in job for now.
        $jobArgs['results'] = $results;
        $this->job->setArgs($jobArgs);
        $this->entityManager->persist($this->job);

        $baseRow = [
            'reference' => '',
        ] + array_fill_keys($this->endpoints, []);

        foreach ($references as $index => $reference) {
            $reference = is_array($reference)
                ? array_map('trim', array_map('strval', $reference))
                : trim((string) $reference);
            $results[$index + 1] = $baseRow;
            $results[$index + 1]['reference'] = $reference;
            if (is_array($reference) ? !count(array_filter($reference, 'strlen')) : !strlen($reference)) {
                continue;
            }

            // Format the reference.
            $reference = $this->formatReference($reference);
            if (!$reference) {
                $this->logger->warn(
                    'Index #{index}: invalid reference.', // @translate
                    ['index' => $index + 1]
                );
                continue;
            }

            // TODO Manage the total by reference for information?

            foreach ($this->endpoints as $endpoint) {
                switch ($endpoint) {
                    case 'omeka':
                        [$total, $list] = array_values($this->searchOmeka($reference));
                        break;
                    case 'archive.org':
                        [$total, $list] = array_values($this->searchArchiveOrg($reference));
                        break;
                    case 'dloc':
                        [$total, $list] = array_values($this->searchDLoc($reference));
                        break;
                    case 'gallica':
                        [$total, $list] = array_values($this->searchGallica($reference));
                        break;
                    case 'persée':
                        [$total, $list] = array_values($this->searchPersée($reference));
                        break;
                    default:
                        // Reset values in other cases.
                        $total = 0;
                        $list = [];
                        continue 2;
                }
                $results[$index + 1][$endpoint] = $list;
            }

            $jobArgs['results'] = $results;

            $this->job->setArgs($jobArgs);
            $this->entityManager->persist($this->job);

            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return;
            }
        }

        $this->prepareSpreadsheet();
    }

    /**
     * Convert reference into array or string according to format.
     *
     * Normally, the conversion is done earlier.
     */
    protected function formatReference($reference)
    {
        // Set reference as a string.
        if (!$this->format || !is_array($reference)) {
            return is_array($reference)
                ? implode(' ', $reference)
                : $reference;
        }

        // Set reference as an array.
        if (!is_array($reference)) {
            $result = array_map('trim', explode('=', $reference, count($this->format)));
        } else {
            $result = $reference;
        }

        // Slice or expand reference to fit the format.
        $result = array_slice($result, 0, count($this->format));
        while (count($result) < count($this->format)) {
            $result[] = '';
        }

        // Keep only existing data.
        return array_filter(array_combine($this->format, $result), 'strlen');
    }

    /**
     * Get the list of results in omeka from a reference.
     *
     * @param array|string $reference String or values according to format.
     * @return array List of url / label.
     */
    protected function searchOmeka($reference): array
    {
        $output = [
            'total' => 0,
            'results' => [],
        ];

        if (is_array($reference) && $this->format) {
            $query = [];
            foreach ($reference as $term => $element) {
                $element = trim((string) $element);
                if (strlen($element)) {
                    $query['property'][] = [
                        'joiner' => 'and',
                        'property' => $term,
                        'type' => 'eq',
                        'text' => $element,
                    ];
                }
            }
            if (!$query) {
                return $output;
            }
        } else {
            $query = ['fulltext_search' => is_array($reference) ? implode(' ', $reference) : $reference];
        }

        $query['limit'] = $this->maxResults;

        $response = $this->api->search('items', $query, ['returnScalar' => 'title']);
        return [
            'total' => 0, // $response->getTotalResults(),
            'results' => $response->getContent(),
        ];
    }

    /**
     * Archive.org.
     *
     * Maximum records: 100.
     * Looks partially like Solr.
     *
     * @see https://archive.org/developers/index-apis.html
     * @see https://archive.org/developers/md-read.html
     * @see https://archive.org/advancedsearch.php
     */
    protected function searchArchiveOrg($reference): array
    {
        $endpoint = 'https://archive.org/advancedsearch.php';

        $output = [
            'total' => 0,
            'results' => [],
        ];

        // Don't use addslashes, that is not unicode safe.
        $escape = ['\\' => '\\\\', '"' => '\"'];

        // Build the query.
        if (is_array($reference) && $this->format) {
            $queryParts = [];
            foreach ($reference as $term => $value) {
                $value = trim((string) $value);
                if (strlen($value)) {
                    $field = $this->simpleDublinCore($term);
                    if ($field) {
                        // TODO Escape AND/OR inside quoted string for archive.org?
                        $queryParts[] = $field . ':("' . strtr($value, $escape) . '")';
                    }
                }
            }
            if (empty($queryParts)) {
                return $output;
            }
            $query = implode(' AND ', $queryParts);
        } else {
            $query = is_array($reference)
                ? implode(' ', $reference)
                : $reference;
        }

        if (!$query) {
            return $output;
        }

        $params = [
            'q' => $query,
            'fl' => [
                'identifier',
                'title',
            ],
            'rows' => min($this->maxResults, 100),
            'page' => 1,
            'output' => 'json',
        ];

        $url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->httpClient->setUri($url)->send();
            if (!$response->isSuccess()) {
                $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                $this->logger->err(
                    'Archive.org search failed with status {status}: {msg}', // @translate
                    ['status' => $response->getStatusCode(), 'msg' => $response->getBody()]
                );
                return $output;
            }

            $data = json_decode($response->getBody(), true);
            if (!$data) {
                $this->logger->err(
                    'Failed to parse archive.org json response.' // @translate
                );
                return $output;
            }

            if (isset($data['error'])) {
                $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                $this->logger->err(
                    'An error occurred on archive.org: {error}', // @translate
                    ['error' => $data['error']]
                );
                return $output;
            }

            $total = (int) ($data['response']['numFound'] ?? 0);
            $docs = $data['response']['docs'] ?? [];

            $results = [];
            foreach ($docs as $doc) {
                $identifier = $doc['identifier'] ?? '';
                $title = $doc['title'] ?? $this->translator->translate('No title');
                if ($identifier) {
                    $url = 'https://archive.org/details/' . $identifier;
                    $results[$url] = is_array($title) ? $title[0] : $title;
                }
            }

            return [
                'total' => $total,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Archive.org search error: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            return $output;
        }
    }

    /**
     * DLoc.com via the Django rest framework.
     *
     * Maximum records: 100.
     *
     * Example:
     * @see https://api.patron.uflib.ufl.edu/exactsearch?title=martinique&creator=Saffache
     *
     * @see https://dloc.com/
     * @see https://www.django-rest-framework.org/
     * @see https://api.patron.uflib.ufl.edu/exactsearch
     */
    protected function searchDLoc($reference): array
    {
        $endpoint = 'https://api.patron.uflib.ufl.edu/exactsearch';

        $output = [
            'total' => 0,
            'results' => [],
        ];

        // Build the query parameters
        if (is_array($reference) && $this->format) {
            $params = [];
            foreach ($reference as $term => $value) {
                $value = trim((string) $value);
                if (strlen($value)) {
                    $field = $this->simpleDublinCore($term);
                    $field = $this->dcToDlocField($field);
                    if ($field) {
                        $params[$field] = $value;
                    }
                }
            }
            if (empty($params)) {
                return $output;
            }
        } else {
            $ref = is_array($reference) ? implode(' ', $reference) : $reference;
            $params = ['title' => $ref];
        }

        //  TODO Limitation does not seem to be allowed on exact search.
        /** @see https://www.django-rest-framework.org/api-guide/pagination/#limitoffsetpagination */
        // $params['limit'] = min($this->maxResults, 100);

        $url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->httpClient->setUri($url)->send();
            if (!$response->isSuccess()) {
                $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                $this->logger->err(
                    'dLoc search failed with status {status}: {msg}', // @translate
                    ['status' => $response->getStatusCode(), 'msg' => $response->getBody()]
                );
                return $output;
            }

            $data = json_decode($response->getBody(), true);
            if (!$data) {
                $this->logger->err(
                    'Failed to parse dLoc json response.' // @translate
                );
                return $output;
            }

            $results = [];
            $items = $data['hits'] ?? [];

            foreach ($items as $item) {
                // There is always a did.
                $identifier = $item['did'] ?? '';
                $title = $item['title'] ?? $this->translator->translate('No title');
                if ($identifier) {
                    $url = 'https://dloc.com/' . strtr($identifier, [':' => '/']);
                    $results[$url] = $title;
                }
            }

            return [
                'total' => count($results),
                'results' => $results,
            ];

        } catch (\Exception $e) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'dLoc search error: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            return $output;
        }
    }

    /**
     * Gallica uses the protocol SRU/SRW.
     *
     * Maximum records: 50.
     *
     * @see https://api.bnf.fr/fr/api-gallica-de-recherche
     * @see https://www.loc.gov/standards/sru/cql/spec.html
     */
    protected function searchGallica($reference): array
    {
        $endpoint = 'https://gallica.bnf.fr/SRU';
        $mainQ = 'gallica';

        $output = [
            'total' => 0,
            'results' => [],
        ];

        // Don't use addslashes, that is not unicode safe.
        $escape = ['\\' => '\\\\', '"' => '\"'];

        // Build the SRU query.
        if (is_array($reference) && $this->format) {
            $queryParts = [];
            foreach ($reference as $term => $value) {
                $value = trim((string) $value);
                if (strlen($value)) {
                    $field = ($s = $this->simpleDublinCore($term)) ? 'dc.' . $s : $mainQ;
                    $queryParts[] = $field . ' all "' . strtr($value, $escape) . '"';
                }
            }
            $query = implode(' and ', $queryParts);
        } else {
            $ref = is_array($reference) ? implode(' ', $reference) : $reference;
            $query = $mainQ . ' all "' . strtr($ref, $escape) . '"';
        }

        $params = [
            'version' => '1.2',
            'operation' => 'searchRetrieve',
            'query' => $query,
            'maximumRecords' => $this->maxResults,
            'startRecord' => 1,
        ];

        $url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->httpClient->setUri($url)->send();

            if (!$response->isSuccess()) {
                $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                $this->logger->err(
                    'Gallica search failed with status {status}: {msg}', // @translate
                    ['status' => $response->getStatusCode(), 'msg' => $response->getBody()]
                );
                return $output;
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response->getBody());
            if ($xml === false) {
                $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                $error = (string) libxml_get_last_error();
                if (!$error) {
                    foreach (libxml_get_errors() as $err) {
                        $error .= $err->message . "\n";
                    }
                    $error = trim($error);
                }
                $this->logger->err(
                    'Failed to parse Gallica xml response: {msg}', // @translate
                    ['msg' => $error ?: 'unknown error']
                );
                libxml_clear_errors();
                return $output;
            }

            $xml->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');
            $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');

            $total = (int) $xml->xpath('//srw:numberOfRecords')[0] ?? 0;
            $records = $xml->xpath('//srw:record');

            $results = [];
            foreach ($records as $k => $record) {
                $dcRecord = $record->xpath('.//oai_dc:dc')[0] ?? null;
                if ($dcRecord === null) {
                    continue;
                }

                // The namespace should be registered on each xpath query.
                $dcRecord->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                $title = (string) ($dcRecord->xpath('.//dc:title')[0] ?? $this->translator->translate('No title'));
                $identifier = (string) ($dcRecord->xpath('.//dc:identifier')[0] ?? '');
                // Extract Gallica ark url if available.
                $url = '';
                if ($identifier === '') {
                    $url = $mainQ . ':' . $k;
                } elseif (mb_strpos($identifier, 'http') === 0) {
                    $url = $identifier;
                } elseif ($identifier !== '') {
                    $url = $mainQ . ':' . $k . '=' . $identifier;
                }

                $results[$url] = $title;
            }

            return [
                'total' => $total,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Gallica search error: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            return $output;
        }
    }

    /**
     * Persée uses the protocol Sparql. Use "contains" instead of "=".
     *
     * Maximum records: 50.
     *
     * Example of valid query on Persée:
     * ```sparql
        PREFIX dcterms: <http://purl.org/dc/terms/>
        PREFIX bibo: <http://purl.org/ontology/bibo/>
        PREFIX foaf: <http://xmlns.com/foaf/0.1/>
        PREFIX marcrel: <http://id.loc.gov/vocabulary/relators/>
        PREFIX rdam: <http://rdaregistry.info/Elements/m/>
        SELECT DISTINCT ?Doc ?title ?name ?date
        WHERE {
            ?Doc a bibo:Document .
            ?Doc rdam:dateOfPublication ?date .
            FILTER(CONTAINS(LCASE(STR(?date)), LCASE("2008"))) .
            ?Doc marcrel:aut ?Person .
            ?Person a foaf:Person .
            ?Person foaf:name ?name .
            FILTER(CONTAINS(LCASE(STR(?name)), LCASE("Hugo"))) .
            OPTIONAL { ?Doc dcterms:title ?title }
        }
        LIMIT 100
     * ```
     *
     * @see https://data.persee.fr/explorer/sparql-endpoint/
     */
    protected function searchPersée($reference): array
    {
        $endpoint = 'https://data.persee.fr/sparql';

        $output = [
            'total' => 0,
            'results' => [],
        ];

        // Don't use addslashes, that is not unicode safe.
        $escape = ['\\' => '\\\\', '"' => '\"'];

        // Build the SPARQL query.
        if (is_array($reference) && $this->format) {
            $filters = [];
            foreach ($reference as $term => $value) {
                $value = trim((string) $value);
                if (strlen($value)) {
                    $field = $this->simpleDublinCore($term);
                    if ($field) {
                        $predicate = 'dcterms:' . $field;
                        $index = count($filters) + 1;
                        $name = $field . '_' . $index;
                        $val = strtr($value, $escape);
                        if ($predicate === 'dcterms:creator') {
                            // Manage relation for creator.
                            // TODO Manage more relation than marcrel:aut: marcrel:pht, marcrel:ill, marcrel:trl, marcrel:ctg…
                            $filters[] = <<<SPARQL
                                ?Doc marcrel:aut ?Person_$index .
                                ?Person_$index a foaf:Person .
                                ?Person_$index foaf:name ?$name .
                                FILTER(CONTAINS(LCASE(STR(?$name)), LCASE("$val"))) .
                                SPARQL;
                        } else {
                            if ($predicate === 'dcterms:date') {
                                $predicate = 'rdam:dateOfPublication';
                            }
                            $filters[] = <<<SPARQL
                                ?Doc $predicate ?$name .
                                FILTER(CONTAINS(LCASE(STR(?$name)), LCASE("$val"))) .
                                SPARQL;
                        }
                    }
                }
            }
            if (!count($filters)) {
                return $output;
            }
            $filterString = implode("\n", $filters);
        } else {
            // Works only for one or two words.
            $val = strtr(is_array($reference) ? implode(' ', $reference) : $reference, $escape);
            $filterString = <<<SPARQL
                ?Doc ?p ?val .
                FILTER(CONTAINS(LCASE(STR(?val)), LCASE("$val"))) .
                SPARQL;
        }

        $min = min($this->maxResults, 100);

        $sparql = <<<SPARQL
            PREFIX bibo: <http://purl.org/ontology/bibo/>
            PREFIX dcterms: <http://purl.org/dc/terms/>
            PREFIX foaf: <http://xmlns.com/foaf/0.1/>
            PREFIX marcrel: <http://id.loc.gov/vocabulary/relators/>
            PREFIX rdam: <http://rdaregistry.info/Elements/m/>
            SELECT DISTINCT ?Doc ?title
            WHERE {
                ?Doc a bibo:Document .
                $filterString
                OPTIONAL { ?Doc dcterms:title ?title }
            }
            LIMIT $min
            SPARQL;

        $params = [
            'query' => $sparql,
            'format' => 'json',
        ];

        $url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->httpClient->setUri($url)->send();

            if (!$response->isSuccess()) {
                $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                $this->logger->err(
                    'Persée search failed with status {status}: {error}', // @translate
                    ['status' => $response->getStatusCode(), 'error' => $response->getBody()]
                );
                return $output;
            }

            $data = json_decode($response->getBody(), true);
            if (!$data) {
                $this->logger->err(
                    'Failed to parse Persée json response.' // @translate
                );
                return $output;
            }

            $bindings = $data['results']['bindings'] ?? [];
            $total = count($bindings);
            $results = [];
            foreach ($bindings as $binding) {
                $docUri = $binding['Doc']['value'] ?? '';
                $title = $binding['title']['value'] ?? $this->translator->translate('No title');
                if ($docUri) {
                    $results[$docUri] = $title;
                }
            }

            return [
                'total' => $total,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Persée search error: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            return $output;
        }
    }

    /**
     * Convert dublin core term into dublin core element, without prefix.
     */
    protected function simpleDublinCore(string $term): ?string
    {
        /**
         * Refinements of Dublin Core.
         *
         * Dublin Core properties and property refinements. See DCMI Metadata Terms:
         * http://dublincore.org/documents/dcmi-terms/
         * The order is the Omeka one.
         *
         * @todo Use the rdf relations directly.
         */
        $map = [
            'dcterms:title' => [
                'dcterms:alternative',
            ],
            'dcterms:creator' => [
            ],
            'dcterms:subject' => [
            ],
            'dcterms:description' => [
                'dcterms:tableOfContents',
                'dcterms:abstract',
            ],
            'dcterms:publisher' => [
            ],
            'dcterms:contributor' => [
            ],
            'dcterms:date' => [
                'dcterms:created',
                'dcterms:valid',
                'dcterms:available',
                'dcterms:issued',
                'dcterms:modified',
                'dcterms:dateAccepted',
                'dcterms:dateCopyrighted',
                'dcterms:dateSubmitted',
            ],
            'dcterms:type' => [
            ],
            'dcterms:format' => [
                'dcterms:extent',
                'dcterms:medium',
            ],
            'dcterms:identifier' => [
                'dcterms:bibliographicCitation',
            ],
            // Source is a refinement of Relation too.
            'dcterms:source' => [
            ],
            'dcterms:language' => [
            ],
            'dcterms:relation' => [
                'dcterms:isVersionOf',
                'dcterms:hasVersion',
                'dcterms:isReplacedBy',
                'dcterms:replaces',
                'dcterms:isRequiredBy',
                'dcterms:requires',
                'dcterms:isPartOf',
                'dcterms:hasPart',
                'dcterms:isReferencedBy',
                'dcterms:references',
                'dcterms:isFormatOf',
                'dcterms:hasFormat',
                'dcterms:conformsTo',
            ],
            'dcterms:coverage' => [
                'dcterms:spatial',
                'dcterms:temporal',
            ],
            'dcterms:rights' => [
                'dcterms:accessRights',
                'dcterms:license',
            ],
            // Ungenerized terms.
            // 'dcterms:audience',
            // 'dcterms:mediator',
            // 'dcterms:educationLevel',
            // 'dcterms:rightsHolder',
            // 'dcterms:provenance',
            // 'dcterms:instructionalMethod',
            // 'dcterms:accrualMethod',
            // 'dcterms:accrualPeriodicity',
            // 'dcterms:accrualPolicy',
        ];

        if (strpos($term, 'dc:') === 0 || strpos($term, 'dc.') === 0) {
            return mb_substr($term, 3);
        }
        if (strpos($term, 'dcterms:') === false) {
            return null;
        }
        if (isset($map[$term])) {
            return mb_substr($term, 8);
        }
        foreach ($map as $dcterm => $dcterms) {
            if (in_array($term, $dcterms)) {
                return mb_substr($dcterm, 8);
            }
        }

        return null;
    }

    /**
     * Map Dublin Core elements to dLoc fields.
     *
     * @see https://api.patron.uflib.ufl.edu/exactsearch?format=api
     */
    protected function dcToDlocField(?string $field): ?string
    {
        if (!$field) {
            return null;
        }

        /**
         * Quick mapping. May be improved and more vocabularies may be managed.
         */
        $map = [
            'default' => [],
            'did' => [
                'dc:identifier',
            ],
            'bibid' => [
                'dc:identifier',
            ],
            'vid' => [
                'dc:identifier',
            ],
            'title' => [
                'dc:title',
            ],
            'mediatype' => [
                'dc:type',
            ],
            'aggregationcodes' => [],
            'mainthumbnail' => [],
            'mainjpeg' => [],
            'pagecount' => [],
            'language' => [
                'dc:language',
            ],
            'creator' => [
                'dc:creator',
            ],
            'publisher' => [
                'dc:publisher',
            ],
            'publication_place' => [],
            'subject_keyword' => [
                'dc:subject',
            ],
            'genre' => [
                'dc:subject',
                'dc:type',
            ],
            'target_audience' => [
                'dc:audience',
            ],
            'spatial_coverage' => [
                'dc:coverage',
                'dcterms:spatial',
            ],
            'country' => [],
            'state' => [],
            'county' => [],
            'city' => [],
            'source_institution' => [
                'dc:source',
            ],
            'holding_location' => [
                'dc:source',
                'dcterms:provenance',
            ],
            'publication_date' => [
                'dc:date',
                'dcterms:issued',
            ],
            'conv_date' => [],
            'etd_degree_discipline' => [
                'dc:subject',
            ],
            'etd_degree_level' => [
                'dcterms:educationLevel',
            ],
            'fulltext' => [],
            'map_point' => [],
            'finding_guide' => [
                'dc:relation',
            ],
            'donor' => [
                'dc:provenance',
            ],
            'collections' => [],
            'accesscode' => [],
            'embargoend' => [],
            'series_title' => [
                'dc:title',
            ],
            'uniform_title' => [
                'dc:title',
            ],
            'alternative_title' => [
                'dc:title',
                'dcterms:alternative',
            ],
            'physical_description' => [
                'dc:format',
                'dcterms:extent',
                'dcterms:medium',
            ],
            'general_note' => [
                'dc:description',
            ],
            'abstract' => [
                'dc:description',
                'dcterms:abstract',
            ],
            'isbn' => [
                'dc:identifier',
            ],
            'oclc' => [
                'dc:identifier',
            ],
            'group_title' => [
                'dc:title',
            ],
            'visibility_restrictions' => [
                'dcterms:accessRights',
            ],
            'ip_restriction_mask' => [
                'dcterms:accessRights',
            ],
            'made_public_date' => [
                'dc:date',
                'dcterms:issued',
            ],
        ];

        $baseField = strpos($field, ':') === false ? $field : substr($field, strpos($field, ':') + 1);
        $dcField = 'dc:' . $field;
        $dctermsField = 'dcterms:' . $field;

        if (isset($map[$baseField])) {
            return $field;
        }

        foreach ($map as $dlocField => $dcFields) {
            if (in_array($dcField, $dcFields) || in_array($dctermsField, $dcFields)) {
                return $dlocField;
            }
        }

        return null;
    }

    /**
     * Store the results of the job into a spreadsheet.
     *
     * The file is stored in files/urify/urify-{date}-{time}-jobid.ods
     */
    protected function prepareSpreadsheet(): bool
    {
        $jobArgs = $this->job->getArgs();
        $results = $jobArgs['results'] ?? [];

        if (empty($results)) {
            $this->logger->warn('No results to export to spreadsheet.'); // @translate
            return false;
        }

        // Create the spreadsheet directory if it doesn't exist
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        if (!$this->checkDestinationDir($basePath . '/urify')) {
            return false;
        }

        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $tempDir = $config['temp_dir'] ?: sys_get_temp_dir();
        $baseUrl = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $baseUrlItem = $services->get('Router')->getBaseUrl(true) . '/admin/item/';
        $baseUrlItem = rtrim($urlHelper->__invoke('admin/id', ['controller' => 'item', 'id' => '00'], ['force_canonical' => true]), '0');

        $filename = sprintf('urify-%s-%s-%d.ods', date('Ymd'), date('His'), $this->job->getId());
        $filepath = $basePath . '/urify/' . $filename;

        try {
            $writer = WriterEntityFactory::createODSWriter();
            $writer
                ->setTempFolder($tempDir)
                ->openToFile($filepath);

            // Prepare headers.
            if ($this->format) {
                $headers = $this->format;
            } else {
                $headers = [
                    $this->translator->translate('Search'),
                ];
            }

            foreach ($this->endpoints as $endpoint) {
                $headers[] = sprintf($this->translator->translate('%s (title)'), ucfirst($endpoint)); // @translate
                $headers[] = sprintf($this->translator->translate('%s (uri)'), ucfirst($endpoint)); // @translate
            }
            $row = WriterEntityFactory::createRowFromArray($headers);
            $writer->addRow($row);

            // Write data rows.
            foreach ($results as $result) {
                $rowData = [];
                $reference = $result['reference'] ?? '';
                if (!$reference) {
                    $writer->addRow(WriterEntityFactory::createRowFromArray($rowData));
                    continue;
                }

                // Input search.
                if ($this->format && is_array($reference)) {
                    foreach (array_keys($this->format) as $k) {
                        $rowData[] = $reference[$k] ?? '';
                    }
                } else {
                    $rowData[] = is_array($reference) ? implode(' ; ', $reference) : $reference;
                }

                // Add results for each endpoint.
                // There may be multiple results by endpoint, filled by row.
                $totalResultsByEndpoint = [];
                foreach ($this->endpoints as $endpoint) {
                    $totalResultsByEndpoint[] = count($result[$endpoint] ?? []);
                }
                $maxResults = max($totalResultsByEndpoint);
                if (!$maxResults) {
                    $writer->addRow(WriterEntityFactory::createRowFromArray($rowData));
                    continue;
                }

                $baseRowData = $rowData;
                for ($i = 0; $i < $maxResults; $i++) {
                    $rowData = $baseRowData;
                    foreach ($this->endpoints as $endpoint) {
                        $current = array_slice($result[$endpoint] ?? [], $i, 1, true);
                        if (empty($current)) {
                            $rowData[] = '';
                            $rowData[] = '';
                        } else {
                            $rowData[] = reset($current);
                            $url = key($current);
                            $rowData[] = is_numeric($url)
                                ? $baseUrlItem . $url
                                : $url;
                        }
                    }
                    $writer->addRow(WriterEntityFactory::createRowFromArray($rowData));
                }
            }

            $writer->close();

            $this->logger->notice(
                'Spreadsheet created: {url}', // @translate
                ['url' => $baseUrl . '/urify/' . $filename]
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
