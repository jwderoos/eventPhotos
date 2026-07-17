<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\AttributeVocabulary;
use PHPUnit\Framework\TestCase;

final class AttributeVocabularyTest extends TestCase
{
    public function testKnownColorAccepted(): void
    {
        $this->assertTrue(AttributeVocabulary::isColor('orange'));
    }

    public function testUnknownColorRejected(): void
    {
        $this->assertFalse(AttributeVocabulary::isColor('chartreuse'));
    }

    public function testGarmentAndSceneMembership(): void
    {
        $this->assertTrue(AttributeVocabulary::isGarment('t-shirt'));
        $this->assertTrue(AttributeVocabulary::isScene('finish-line'));
        $this->assertFalse(AttributeVocabulary::isScene('bedroom'));
    }
}
