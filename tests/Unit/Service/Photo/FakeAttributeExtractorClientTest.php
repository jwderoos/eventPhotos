<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\AttributeScore;
use App\Service\Photo\ExtractedAttributes;
use App\Tests\Fake\FakeAttributeExtractorClient;
use PHPUnit\Framework\TestCase;

final class FakeAttributeExtractorClientTest extends TestCase
{
    public function testReturnsConfiguredResponseAndRecordsInput(): void
    {
        $fake = new FakeAttributeExtractorClient();
        $fake->setNext(new ExtractedAttributes([new AttributeScore('blue', 0.9)], [], [], []));

        $result = $fake->extract('the-bytes');

        $this->assertSame('blue', $result->clothingColors[0]->value);
        $this->assertSame('the-bytes', $fake->lastImageBytes);
    }

    public function testDefaultsToEmpty(): void
    {
        $this->assertSame([], new FakeAttributeExtractorClient()->extract('x')->bibs);
    }
}
