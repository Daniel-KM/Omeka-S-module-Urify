<?php declare(strict_types=1);

namespace UrifyTest\Controller;

use Omeka\Test\AbstractHttpControllerTestCase;

class IndexControllerTest extends AbstractHttpControllerTestCase
{
    public function testIndexActionCanBeAccessed(): void
    {
        $this->dispatch('/admin/urify');
        $this->assertResponseStatusCode(200);
    }
}
