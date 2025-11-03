<?php declare(strict_types=1);

namespace UrifyTest\Controller;

class IndexControllerTest extends UrifyControllerTestCase
{
    public function testIndexActionCanBeAccessed(): void
    {
        $this->dispatch('/admin/urify');
        $this->assertResponseStatusCode(200);
    }
}
