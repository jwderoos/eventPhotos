<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HomepageTest extends WebTestCase
{
    public function testHomepageShowsAppName(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Event Photos');
    }
}
