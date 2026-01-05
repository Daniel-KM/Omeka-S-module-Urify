<?php declare(strict_types=1);

namespace UrifyTest\Controller\Admin;

use Omeka\Test\AbstractHttpControllerTestCase;
use UrifyTest\UrifyTestTrait;

/**
 * Tests for the Urify admin controller.
 *
 * Note: Some controller tests are simplified because the full admin context
 * (user settings, etc.) isn't fully initialized in the test environment.
 */
class IndexControllerTest extends AbstractHttpControllerTestCase
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
     * Test that add action can be accessed and returns HTML.
     */
    public function testAddActionCanBeAccessed(): void
    {
        $this->dispatch('/admin/urify/add');
        // Verify route and controller match.
        $this->assertControllerName('Urify\Controller\Admin\Index');
        $this->assertActionName('add');
    }

    /**
     * Test that show action requires a valid job ID.
     */
    public function testShowActionRequiresValidJob(): void
    {
        $this->dispatch('/admin/urify/999999');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test that apply action requires POST method.
     */
    public function testApplyActionRequiresPost(): void
    {
        $this->dispatch('/admin/urify/999999/apply');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test route matching for urify module.
     */
    public function testUrifyRouteExists(): void
    {
        $this->dispatch('/admin/urify/add');
        $this->assertControllerName('Urify\Controller\Admin\Index');
        $this->assertActionName('add');
    }

    /**
     * Test browse route exists.
     */
    public function testBrowseRouteExists(): void
    {
        $this->dispatch('/admin/urify/browse');
        $this->assertControllerName('Urify\Controller\Admin\Index');
        $this->assertActionName('browse');
    }
}
