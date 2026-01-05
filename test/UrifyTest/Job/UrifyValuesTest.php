<?php declare(strict_types=1);

namespace UrifyTest\Job;

use Omeka\Entity\Job;
use Omeka\Test\AbstractHttpControllerTestCase;
use Urify\Job\UrifyValues;
use UrifyTest\UrifyTestTrait;

/**
 * Unit tests for the UrifyValues job (search for values to urify).
 */
class UrifyValuesTest extends AbstractHttpControllerTestCase
{
    use UrifyTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Test job fails with missing properties argument.
     */
    public function testJobFailsWithMissingProperties(): void
    {
        $args = [
            'modes' => ['literal_to_uri'],
            'value_types' => ['literal'],
            'datatype' => 'valuesuggest:idref:person',
            // Missing 'properties'
        ];

        $job = $this->runJob(UrifyValues::class, $args, true);

        $this->assertEquals(Job::STATUS_ERROR, $job->getStatus());
    }

    /**
     * Test job fails with missing modes argument.
     */
    public function testJobFailsWithMissingModes(): void
    {
        $args = [
            'properties' => [1],
            'value_types' => ['literal'],
            'datatype' => 'valuesuggest:idref:person',
            // Missing 'modes'
        ];

        $job = $this->runJob(UrifyValues::class, $args, true);

        $this->assertEquals(Job::STATUS_ERROR, $job->getStatus());
    }

    /**
     * Test job fails with missing value_types argument.
     */
    public function testJobFailsWithMissingValueTypes(): void
    {
        $args = [
            'properties' => [1],
            'modes' => ['literal_to_uri'],
            'datatype' => 'valuesuggest:idref:person',
            // Missing 'value_types'
        ];

        $job = $this->runJob(UrifyValues::class, $args, true);

        $this->assertEquals(Job::STATUS_ERROR, $job->getStatus());
    }

    /**
     * Test job fails with missing datatype argument.
     */
    public function testJobFailsWithMissingDatatype(): void
    {
        $args = [
            'properties' => [1],
            'modes' => ['literal_to_uri'],
            'value_types' => ['literal'],
            // Missing 'datatype'
        ];

        $job = $this->runJob(UrifyValues::class, $args, true);

        $this->assertEquals(Job::STATUS_ERROR, $job->getStatus());
    }

    /**
     * Test job completes with valid arguments but no matching items.
     *
     * @group integration
     */
    public function testJobCompletesWithNoMatchingItems(): void
    {
        $this->markTestSkipped('Requires full module loading context - run in integration test suite');
        // Get dcterms:creator property ID.
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $propertyId = $easyMeta->propertyId('dcterms:creator');

        $args = [
            'properties' => [$propertyId],
            'modes' => ['literal_to_uri'],
            'value_types' => ['literal'],
            'datatype' => 'valuesuggest:idref:person',
            'query' => ['id' => [999999]], // Non-existent item.
        ];

        $job = $this->runJob(UrifyValues::class, $args);

        // Job should complete (not error) even with no matches.
        $this->assertEquals(Job::STATUS_COMPLETED, $job->getStatus());
    }

    /**
     * Test job finds items with literal values.
     *
     * @group integration
     */
    public function testJobFindsItemsWithLiteralValues(): void
    {
        $this->markTestSkipped('Requires full module loading context - run in integration test suite');
        // Create test item.
        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test Document']],
            'dcterms:creator' => [['type' => 'literal', '@value' => 'John Doe']],
        ]);

        // Get property ID.
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $propertyId = $easyMeta->propertyId('dcterms:creator');

        $args = [
            'properties' => [$propertyId],
            'modes' => ['literal_to_uri'],
            'value_types' => ['literal'],
            'datatype' => 'valuesuggest:idref:person',
        ];

        $job = $this->runJob(UrifyValues::class, $args);

        $this->assertEquals(Job::STATUS_COMPLETED, $job->getStatus());

        // Check results in job args.
        $jobArgs = $job->getArgs();
        $this->assertArrayHasKey('results', $jobArgs);
        $results = $jobArgs['results'];

        // Should find the dcterms:creator value.
        $this->assertArrayHasKey('dcterms:creator', $results);
    }

    /**
     * Test job respects query filter.
     *
     * @group integration
     */
    public function testJobRespectsQueryFilter(): void
    {
        $this->markTestSkipped('Requires full module loading context - run in integration test suite');
        // Create two test items.
        $item1 = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Document One']],
            'dcterms:creator' => [['type' => 'literal', '@value' => 'Author A']],
        ]);

        $item2 = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Document Two']],
            'dcterms:creator' => [['type' => 'literal', '@value' => 'Author B']],
        ]);

        // Get property ID.
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $propertyId = $easyMeta->propertyId('dcterms:creator');

        // Query only item1.
        $args = [
            'properties' => [$propertyId],
            'modes' => ['literal_to_uri'],
            'value_types' => ['literal'],
            'datatype' => 'valuesuggest:idref:person',
            'query' => ['id' => [$item1->id()]],
        ];

        $job = $this->runJob(UrifyValues::class, $args);

        $this->assertEquals(Job::STATUS_COMPLETED, $job->getStatus());

        $jobArgs = $job->getArgs();
        $results = $jobArgs['results']['dcterms:creator'] ?? [];

        // Should only find Author A, not Author B.
        $labels = array_column($results, 'label');
        $this->assertContains('Author A', $labels);
        $this->assertNotContains('Author B', $labels);
    }
}
