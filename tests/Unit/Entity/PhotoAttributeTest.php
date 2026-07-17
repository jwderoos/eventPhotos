<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoAttribute;
use App\Entity\PhotoAttributeType;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PhotoAttributeTest extends TestCase
{
    private function makePhoto(): Photo
    {
        $owner = new User('o@example.test', 'O');
        $event = new Event(
            'demo',
            'Demo',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );

        return new Photo($event, str_repeat('a', 64), 'p.jpg', 1234);
    }

    public function testStoresTypeValueAndConfidence(): void
    {
        $attribute = new PhotoAttribute($this->makePhoto(), PhotoAttributeType::ClothingColor, 'orange', 0.92);

        $this->assertSame(PhotoAttributeType::ClothingColor, $attribute->getType());
        $this->assertSame('orange', $attribute->getValue());
        $this->assertEqualsWithDelta(0.92, $attribute->getConfidence(), PHP_FLOAT_EPSILON);
    }

    public function testConfidenceDefaultsToNull(): void
    {
        $attribute = new PhotoAttribute($this->makePhoto(), PhotoAttributeType::Scene, 'finish-line');

        $this->assertNull($attribute->getConfidence());
    }
}
