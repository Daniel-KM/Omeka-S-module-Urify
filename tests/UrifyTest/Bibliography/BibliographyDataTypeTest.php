<?php declare(strict_types=1);

namespace UrifyTest\Bibliography;

use CommonTest\AbstractHttpControllerTestCase;
use Omeka\Entity\Job;
use Urify\Job\UrifyValues;
use Urify\Job\UrifyValuesApply;
use UrifyTest\UrifyTestTrait;

/**
 * Urify must work when a data type of Bibliography is loaded first.
 *
 * Bibliography has a fallback for the parent class of ValueSuggest data types
 * that must not replace the real one. The check is valid only when the parent
 * class is not loaded yet, so this test should run first: keep its directory
 * sorted before the other ones.
 */
class BibliographyDataTypeTest extends AbstractHttpControllerTestCase
{
    use UrifyTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists('Bibliography\Module', false)) {
            $this->markTestSkipped('Requires module Bibliography.');
        }
        $this->loginAdmin();
        $this->setupMockHttpClient();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testJobsWithDataTypesAfterBibliography(): void
    {
        $isLoaded = class_exists('ValueSuggest\DataType\AbstractDataType', false);

        // Load a data type of Bibliography first, as in the list of data types.
        $this->getServiceLocator()->get('Omeka\DataTypeManager')->get('valuesuggest:doi:works');
        $this->assertFalse($isLoaded, 'The parent class was loaded before the test, so the regression cannot be checked.');

        foreach (['valuesuggest:idref:person', 'valuesuggest:doi:works'] as $dataType) {
            $job = $this->runJob(UrifyValues::class, [
                'properties' => ['dcterms:creator'],
                'modes' => ['miss'],
                'value_types' => ['literal'],
                'datatype' => $dataType,
                'query' => ['id' => [999999]],
            ]);
            $this->assertSame(Job::STATUS_COMPLETED, $job->getStatus(), $dataType);
        }

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test Document']],
            'dcterms:creator' => [['type' => 'literal', '@value' => 'Abbé Sieyès']],
        ]);

        $job = $this->runJob(UrifyValuesApply::class, [
            'value' => 'Abbé Sieyès',
            'uri' => 'https://www.idref.fr/028618661.rdf',
            'label' => 'Sieyès, Emmanuel-Joseph (1748-1836)',
            'property' => 'dcterms:creator',
            'datatype' => 'valuesuggest:idref:person',
            'value_types' => ['literal'],
            'updateMode' => 'single',
        ]);
        $this->assertSame(Job::STATUS_COMPLETED, $job->getStatus());

        $item = $this->api()->read('items', $item->id())->getContent();
        $creator = $item->value('dcterms:creator');
        $this->assertNotNull($creator);
        $this->assertSame('valuesuggest:idref:person', $creator->type());
        $this->assertSame('https://www.idref.fr/028618661.rdf', $creator->uri());
        $this->assertSame('Sieyès, Emmanuel-Joseph (1748-1836)', $creator->value());
    }
}
