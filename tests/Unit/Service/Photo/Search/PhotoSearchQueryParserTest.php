<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo\Search;

use App\Service\Photo\Search\ParsedPhotoQuery;
use App\Entity\PhotoAttributeType;
use App\Service\Photo\Search\PhotoSearchQueryParser;
use App\Service\Photo\Search\SearchToken;
use PHPUnit\Framework\TestCase;

final class PhotoSearchQueryParserTest extends TestCase
{
    private PhotoSearchQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhotoSearchQueryParser();
    }

    public function testEmptyStringParsesToEmpty(): void
    {
        $this->assertTrue($this->parser->parse('', true, true)->isEmpty());
        $this->assertTrue($this->parser->parse('   ', true, true)->isEmpty());
    }

    public function testBibDigitsBecomeBibTokenWhenEnabled(): void
    {
        $query = $this->parser->parse('1423', true, true);

        $this->assertCount(1, $query->tokens);
        $this->assertSame(PhotoAttributeType::Bib, $query->tokens[0]->type);
        $this->assertSame(['1423'], $query->tokens[0]->canonicals);
        $this->assertSame('bib 1423', $query->tokens[0]->label);
    }

    public function testBibIgnoredWhenDisabled(): void
    {
        $query = $this->parser->parse('1423', false, true);

        $this->assertTrue($query->isEmpty());
        $this->assertSame(['1423'], $query->ignored);
    }

    public function testSecondNumberIsIgnored(): void
    {
        $query = $this->parser->parse('1423 2000', true, true);

        $this->assertCount(1, $query->tokens);
        $this->assertSame('1423', $query->tokens[0]->canonicals[0]);
        $this->assertSame(['2000'], $query->ignored);
    }

    public function testColourExactMatch(): void
    {
        $token = $this->firstOfType($this->parser->parse('red', true, true), PhotoAttributeType::ClothingColor);

        $this->assertSame(['red'], $token->canonicals);
        $this->assertSame('red', $token->label);
    }

    public function testGrayMapsToGrey(): void
    {
        $token = $this->firstOfType($this->parser->parse('gray', true, true), PhotoAttributeType::ClothingColor);

        $this->assertSame(['grey'], $token->canonicals);
    }

    public function testHyphenatedTShirtMatches(): void
    {
        $token = $this->firstOfType($this->parser->parse('t-shirt', true, true), PhotoAttributeType::ClothingType);

        $this->assertSame(['t-shirt'], $token->canonicals);
    }

    public function testGarmentSynonymSweaterMapsToHoodieSweater(): void
    {
        $token = $this->firstOfType($this->parser->parse('sweater', true, true), PhotoAttributeType::ClothingType);

        $this->assertSame(['hoodie/sweater'], $token->canonicals);
    }

    public function testAmbiguousShirtMapsToBothShirts(): void
    {
        $token = $this->firstOfType($this->parser->parse('shirt', true, true), PhotoAttributeType::ClothingType);

        $this->assertSame(['t-shirt', 'long-sleeve shirt'], $token->canonicals);
        $this->assertSame('shirt', $token->label);
    }

    public function testLongestPhraseWinsOverShirt(): void
    {
        $query = $this->parser->parse('long sleeve shirt', true, true);
        $token = $this->firstOfType($query, PhotoAttributeType::ClothingType);

        $this->assertSame(['long-sleeve shirt'], $token->canonicals);
        $this->assertCount(1, $query->tokens);
    }

    public function testMultiWordSceneFinishLine(): void
    {
        $token = $this->firstOfType($this->parser->parse('finish line', true, true), PhotoAttributeType::Scene);

        $this->assertSame(['finish-line'], $token->canonicals);
    }

    public function testFuzzyColourPrefix(): void
    {
        $token = $this->firstOfType($this->parser->parse('blu', true, true), PhotoAttributeType::ClothingColor);

        $this->assertSame(['blue'], $token->canonicals);
    }

    public function testFuzzyColourTypo(): void
    {
        $token = $this->firstOfType($this->parser->parse('gren', true, true), PhotoAttributeType::ClothingColor);

        $this->assertSame(['green'], $token->canonicals);
    }

    public function testUnknownWordIgnored(): void
    {
        $query = $this->parser->parse('banana', true, true);

        $this->assertTrue($query->isEmpty());
        $this->assertSame(['banana'], $query->ignored);
    }

    public function testAttributesDisabledIgnoresClothing(): void
    {
        $query = $this->parser->parse('red shirt', true, false);

        $this->assertTrue($query->isEmpty());
        $this->assertSame(['red', 'shirt'], $query->ignored);
    }

    public function testMixedQuery(): void
    {
        $query = $this->parser->parse('1423 red finish line banana', true, true);

        $types = array_map(static fn (SearchToken $t): string => $t->type->value, $query->tokens);
        $this->assertSame(['bib', 'clothing_color', 'scene'], $types);
        $this->assertSame(['banana'], $query->ignored);
    }

    public function testTokenOrderFollowsInput(): void
    {
        $query = $this->parser->parse('red 1423', true, true);

        $this->assertSame(PhotoAttributeType::ClothingColor, $query->tokens[0]->type);
        $this->assertSame(PhotoAttributeType::Bib, $query->tokens[1]->type);
    }

    public function testMultiWordSceneWaterStation(): void
    {
        $token = $this->firstOfType($this->parser->parse('water station', true, true), PhotoAttributeType::Scene);

        $this->assertSame(['water-station'], $token->canonicals);
    }

    public function testMultiWordSceneOnCourseRunning(): void
    {
        $token = $this->firstOfType($this->parser->parse('on course running', true, true), PhotoAttributeType::Scene);

        $this->assertSame(['on-course/running'], $token->canonicals);
    }

    public function testMultiWordSceneCrowdSpectators(): void
    {
        $token = $this->firstOfType($this->parser->parse('crowd spectators', true, true), PhotoAttributeType::Scene);

        $this->assertSame(['crowd/spectators'], $token->canonicals);
    }

    public function testMultiWordSceneMedalPodium(): void
    {
        $token = $this->firstOfType($this->parser->parse('medal podium', true, true), PhotoAttributeType::Scene);

        $this->assertSame(['medal/podium'], $token->canonicals);
    }

    private function firstOfType(ParsedPhotoQuery $q, PhotoAttributeType $type): SearchToken
    {
        foreach ($q->tokens as $token) {
            if ($token->type === $type) {
                return $token;
            }
        }

        self::fail('No token of type ' . $type->value);
    }
}
