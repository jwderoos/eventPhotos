<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EventCollection;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class EventCollectionTest extends TestCase
{
    public function testNewCollectionExposesGivenFields(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $collection = new EventCollection('summer-2026', 'Summer 2026', $owner);

        $this->assertSame('summer-2026', $collection->getSlug());
        $this->assertSame('Summer 2026', $collection->getName());
        $this->assertSame($owner, $collection->getOwner());
        $this->assertNull($collection->getDescription());
    }

    public function testDescriptionIsMutable(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $collection = new EventCollection('summer-2026', 'Summer 2026', $owner);
        $collection->setDescription('All summer events');

        $this->assertSame('All summer events', $collection->getDescription());
    }
}
