<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo\Search;

use App\Entity\PhotoAttributeType;
use App\Service\Photo\Search\ParsedPhotoQuery;
use App\Service\Photo\Search\SearchToken;
use PHPUnit\Framework\TestCase;

final class ParsedPhotoQueryTest extends TestCase
{
    public function testEmptyWhenNoTokens(): void
    {
        $this->assertTrue(new ParsedPhotoQuery([], [])->isEmpty());
        $this->assertFalse(new ParsedPhotoQuery([$this->bib('1423')], [])->isEmpty());
    }

    public function testToFilterCollectsCanonicalsByType(): void
    {
        $query = new ParsedPhotoQuery([
            $this->bib('1423'),
            new SearchToken(PhotoAttributeType::ClothingColor, 'blue', ['blue'], 'blue'),
            new SearchToken(PhotoAttributeType::ClothingColor, 'red', ['red'], 'red'),
            new SearchToken(PhotoAttributeType::ClothingType, 'shirt', ['t-shirt', 'long-sleeve shirt'], 'shirt'),
            new SearchToken(PhotoAttributeType::Scene, 'finish', ['finish-line'], 'finish-line'),
        ], ['xyzzy']);

        $filter = $query->toFilter();

        $this->assertSame('1423', $filter->bib);
        $this->assertSame(['blue', 'red'], $filter->colours);
        $this->assertSame(['t-shirt', 'long-sleeve shirt'], $filter->garments);
        $this->assertSame(['finish-line'], $filter->scenes);
    }

    public function testToFilterFirstBibTokenWins(): void
    {
        $query = new ParsedPhotoQuery([
            $this->bib('1423'),
            $this->bib('6002'),
        ], []);

        $this->assertSame('1423', $query->toFilter()->bib);
    }

    public function testToFilterDeduplicatesCanonicals(): void
    {
        $query = new ParsedPhotoQuery([
            new SearchToken(PhotoAttributeType::ClothingType, 'shirt', ['t-shirt', 'long-sleeve shirt'], 'shirt'),
            new SearchToken(PhotoAttributeType::ClothingType, 'tee', ['t-shirt'], 't-shirt'),
        ], []);

        $this->assertSame(['t-shirt', 'long-sleeve shirt'], $query->toFilter()->garments);
    }

    public function testWithoutRemovesTokenByIndex(): void
    {
        $query = new ParsedPhotoQuery([$this->bib('1423'), $this->colour('red')], []);

        $reduced = $query->without(0);

        $this->assertCount(1, $reduced->tokens);
        $this->assertSame('red', $reduced->tokens[0]->sourceText);
        $this->assertCount(2, $query->tokens); // original untouched (readonly)
    }

    public function testSerializeJoinsSourceTextThenIgnored(): void
    {
        $query = new ParsedPhotoQuery([$this->bib('1423'), $this->colour('red')], ['xyzzy']);

        $this->assertSame('1423 red xyzzy', $query->serialize());
    }

    public function testWithoutThenSerializeDropsTheToken(): void
    {
        $query = new ParsedPhotoQuery([$this->bib('1423'), $this->colour('red')], []);

        $this->assertSame('red', $query->without(0)->serialize());
    }

    private function bib(string $value): SearchToken
    {
        return new SearchToken(PhotoAttributeType::Bib, $value, [$value], 'bib ' . $value);
    }

    private function colour(string $value): SearchToken
    {
        return new SearchToken(PhotoAttributeType::ClothingColor, $value, [$value], $value);
    }
}
