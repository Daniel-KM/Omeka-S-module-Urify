<?php declare(strict_types=1);

namespace UrifyTest;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Entity\Job;

/**
 * Shared test helpers for Urify module tests.
 */
trait UrifyTestTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array List of created resource IDs for cleanup.
     */
    protected $createdResources = [];

    /**
     * @var array List of created mapper IDs for cleanup.
     */
    protected $createdMappers = [];

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if ($this->services === null) {
            $this->services = $this->getApplication()->getServiceManager();
        }
        return $this->services;
    }

    /**
     * Get the entity manager.
     */
    protected function getEntityManager()
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Create a test item.
     *
     * @param array $data Item data with property terms as keys.
     * @return ItemRepresentation
     */
    protected function createItem(array $data): ItemRepresentation
    {
        // Convert property terms to proper format if needed.
        $itemData = [];
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');

        foreach ($data as $term => $values) {
            // Skip non-property fields.
            if (strpos($term, ':') === false) {
                $itemData[$term] = $values;
                continue;
            }

            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            $itemData[$term] = [];
            foreach ($values as $value) {
                $valueData = [
                    'type' => $value['type'] ?? 'literal',
                    'property_id' => $propertyId,
                ];
                if (isset($value['@value'])) {
                    $valueData['@value'] = $value['@value'];
                }
                if (isset($value['@id'])) {
                    $valueData['@id'] = $value['@id'];
                }
                if (isset($value['o:label'])) {
                    $valueData['o:label'] = $value['o:label'];
                }
                $itemData[$term][] = $valueData;
            }
        }

        $response = $this->api()->create('items', $itemData);
        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        return $item;
    }

    /**
     * Create a mapper in the database.
     *
     * @param string $label Mapper label.
     * @param string $mapping Mapping configuration (INI format).
     * @return \Mapper\Api\Representation\MapperRepresentation
     */
    protected function createMapper(string $label, string $mapping)
    {
        $response = $this->api()->create('mappers', [
            'o:label' => $label,
            'o:mapping' => $mapping,
        ]);
        $mapper = $response->getContent();
        $this->createdMappers[] = $mapper->id();

        return $mapper;
    }

    /**
     * Get the IdRef Person mapping configuration for testing.
     *
     * This mapping extracts data from IdRef RDF for a person record.
     */
    protected function getIdRefPersonMapping(): string
    {
        return <<<'INI'
[info]
label = "IdRef Person"
querier = xpath

[default]
; The URI itself as the primary identifier
~ = dcterms:creator ^^uri ~ {{ __uri__ }}

[maps]
; Preferred label (full name with dates)
//foaf:Person/skos:prefLabel = dcterms:description ^^literal

; Family name
//foaf:Person/foaf:familyName = foaf:familyName ^^literal

; Given name
//foaf:Person/foaf:givenName = foaf:givenName ^^literal

; Full name
//foaf:Person/foaf:name = foaf:name ^^literal

; Birth date
//bio:Birth/bio:date = bio:birth ^^literal

; Death date
//bio:Death/bio:date = bio:death ^^literal

; Alternative names
//foaf:Person/skos:altLabel = skos:altLabel ^^literal

; Gender
//foaf:Person/foaf:gender = foaf:gender ^^literal

; Note/description
//foaf:Person/skos:note = skos:note ^^literal

; ISNI identifier
//foaf:Person/isni:identifierValid = dcterms:identifier ^^literal ~ ISNI: {{ value }}
INI;
    }

    /**
     * Get the IdRef UNIMARC XML mapping configuration for testing.
     *
     * This mapping extracts data from IdRef UNIMARC XML format.
     * UNIMARC uses numbered tags and subfield codes:
     * - 200$a = Family name, 200$b = Given name, 200$f = Dates
     * - 103$a = Birth date, 103$b = Death date
     * - 340$a = Biographical note
     * - 400$a/$b = Alternative name forms
     */
    protected function getIdRefUnimarcXmlMapping(): string
    {
        return <<<'INI'
[info]
label = "IdRef UNIMARC XML"
querier = xpath

[maps]
; Family name (tag 200, subfield a)
//datafield[@tag='200']/subfield[@code='a'] = foaf:familyName ^^literal

; Given name (tag 200, subfield b)
//datafield[@tag='200']/subfield[@code='b'] = foaf:givenName ^^literal

; Dates (tag 200, subfield f) - contains "1748-1836"
//datafield[@tag='200']/subfield[@code='f'] = dcterms:temporal ^^literal

; Birth date (tag 103, subfield a) - contains "17480503"
//datafield[@tag='103']/subfield[@code='a'] = bio:birth ^^literal

; Death date (tag 103, subfield b) - contains "18360620"
//datafield[@tag='103']/subfield[@code='b'] = bio:death ^^literal

; Biographical note (tag 340, subfield a)
//datafield[@tag='340']/subfield[@code='a'] = skos:note ^^literal

; Alternative name - family (tag 400, subfield a)
//datafield[@tag='400']/subfield[@code='a'] = skos:altLabel ^^literal ~ {{ value }}

; Alternative name - qualifier (tag 400, subfield b)
//datafield[@tag='400']/subfield[@code='b'] = dcterms:alternative ^^literal

; Preferred label (tag 900, subfield a)
//datafield[@tag='900']/subfield[@code='a'] = skos:prefLabel ^^literal

; ISNI identifier (tag 010, subfield a)
//datafield[@tag='010']/subfield[@code='a'] = dcterms:identifier ^^literal ~ ISNI: {{ value }}

; Language (tag 101, subfield a)
//datafield[@tag='101']/subfield[@code='a'] = dcterms:language ^^literal

; Country (tag 102, subfield a)
//datafield[@tag='102']/subfield[@code='a'] = dcterms:spatial ^^literal
INI;
    }

    /**
     * Get the IdRef UNIMARC JSON mapping configuration for testing.
     *
     * This mapping extracts data from IdRef UNIMARC JSON format using jsdot querier.
     * The JSON structure has: record.datafield[] with tag, ind1, ind2, subfield[]
     * Each subfield has: code, content
     */
    protected function getIdRefUnimarcJsonMapping(): string
    {
        return <<<'INI'
[info]
label = "IdRef UNIMARC JSON"
querier = jmespath

[maps]
; Family name - datafield tag 200, subfield code 'a'
record.datafield[?tag=='200'].subfield[?code=='a'].content | [0] = foaf:familyName ^^literal

; Given name - datafield tag 200, subfield code 'b'
record.datafield[?tag=='200'].subfield[?code=='b'].content | [0] = foaf:givenName ^^literal

; Dates - datafield tag 200, subfield code 'f'
record.datafield[?tag=='200'].subfield[?code=='f'].content | [0] = dcterms:temporal ^^literal

; Birth date - datafield tag 103, subfield code 'a'
record.datafield[?tag=='103'].subfield[?code=='a'].content | [0] = bio:birth ^^literal

; Death date - datafield tag 103, subfield code 'b'
record.datafield[?tag=='103'].subfield[?code=='b'].content | [0] = bio:death ^^literal

; Biographical note - datafield tag 340, subfield code 'a'
record.datafield[?tag=='340'].subfield[?code=='a'].content | [0] = skos:note ^^literal

; Preferred label - datafield tag 900, subfield code 'a'
record.datafield[?tag=='900'].subfield[?code=='a'].content | [0] = skos:prefLabel ^^literal

; Alternative label - datafield tag 901, subfield code 'a'
record.datafield[?tag=='901'].subfield[?code=='a'].content | [0] = skos:altLabel ^^literal

; ISNI - datafield tag 010, subfield code 'a'
record.datafield[?tag=='010'].subfield[?code=='a'].content | [0] = dcterms:identifier ^^literal ~ ISNI: {{ value }}

; Language - datafield tag 101, subfield code 'a'
record.datafield[?tag=='101'].subfield[?code=='a'].content | [0] = dcterms:language ^^literal

; Country - datafield tag 102, subfield code 'a'
record.datafield[?tag=='102'].subfield[?code=='a'].content | [0] = dcterms:spatial ^^literal
INI;
    }

    /**
     * Get a fixture file content.
     *
     * @param string $name Fixture filename.
     * @return string
     */
    protected function getFixture(string $name): string
    {
        $path = dirname(__DIR__) . '/fixtures/' . $name;
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture not found: $path");
        }
        return file_get_contents($path);
    }

    /**
     * Get the path to the fixtures directory.
     */
    protected function getFixturesPath(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    /**
     * @var \Exception|null Last exception from job execution.
     */
    protected $lastJobException;

    /**
     * Run a job synchronously for testing.
     *
     * @param string $jobClass Job class name.
     * @param array $args Job arguments.
     * @param bool $expectError If true, don't rethrow exceptions (for testing error cases).
     * @return Job
     */
    protected function runJob(string $jobClass, array $args, bool $expectError = false): Job
    {
        $this->lastJobException = null;
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');

        // Create job entity.
        $job = new Job();
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass($jobClass);
        $job->setArgs($args);
        $job->setOwner($auth->getIdentity());

        $entityManager->persist($job);
        $entityManager->flush();

        // Run job synchronously.
        $jobClass = $job->getClass();
        $jobInstance = new $jobClass($job, $services);
        $job->setStatus(Job::STATUS_IN_PROGRESS);
        $job->setStarted(new \DateTime('now'));
        $entityManager->flush();

        try {
            $jobInstance->perform();
            if ($job->getStatus() === Job::STATUS_IN_PROGRESS) {
                $job->setStatus(Job::STATUS_COMPLETED);
            }
        } catch (\Throwable $e) {
            $this->lastJobException = $e;
            $job->setStatus(Job::STATUS_ERROR);
            if (!$expectError) {
                throw $e;
            }
        }

        $job->setEnded(new \DateTime('now'));
        $entityManager->flush();

        return $job;
    }

    /**
     * Get the last exception from job execution (for debugging).
     */
    protected function getLastJobException(): ?\Exception
    {
        return $this->lastJobException;
    }

    /**
     * Setup mock HTTP client to return fixtures instead of real requests.
     */
    protected function setupMockHttpClient(): void
    {
        $services = $this->getServiceLocator();
        $fixturesPath = $this->getFixturesPath();

        // Create mock HTTP client.
        $mockClient = new Service\MockHttpClient($fixturesPath);

        // Override the HTTP client in service manager.
        $services->setAllowOverride(true);
        $services->setService('Omeka\HttpClient', $mockClient);
        $services->setAllowOverride(false);
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        // Delete created items.
        foreach ($this->createdResources as $resource) {
            try {
                $this->api()->delete($resource['type'], $resource['id']);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdResources = [];

        // Delete created mappers.
        foreach ($this->createdMappers as $mapperId) {
            try {
                $this->api()->delete('mappers', $mapperId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdMappers = [];
    }
}
