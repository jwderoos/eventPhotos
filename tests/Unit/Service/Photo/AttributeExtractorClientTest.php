<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\AttributeScore;
use App\Service\Photo\AttributeExtractorClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AttributeExtractorClientTest extends TestCase
{
    public function testMapsResponseAndDropsUnknownVocabulary(): void
    {
        $json = json_encode([
            'clothing_colors' => [
                ['value' => 'orange', 'confidence' => 0.9],
                ['value' => 'chartreuse', 'confidence' => 0.9],
            ],
            'clothing_types' => [['value' => 't-shirt', 'confidence' => 0.8]],
            'scenes' => [['value' => 'finish-line', 'confidence' => 0.7]],
            'bibs' => [['value' => '1423', 'confidence' => 0.95]],
        ], JSON_THROW_ON_ERROR);

        $http = new MockHttpClient(new MockResponse($json, [
            'response_headers' => ['content-type' => 'application/json'],
        ]));
        $client = new AttributeExtractorClient($http);

        $result = $client->extract('fake-jpeg-bytes');

        $this->assertSame(['orange'], array_map(fn (AttributeScore $s): string => $s->value, $result->clothingColors));
        $this->assertSame('t-shirt', $result->clothingTypes[0]->value);
        $this->assertSame('finish-line', $result->scenes[0]->value);
        $this->assertSame('1423', $result->bibs[0]->value);
        $this->assertEqualsWithDelta(0.95, $result->bibs[0]->confidence, 0.0001);
    }

    public function testReturnsEmptyOnServerError(): void
    {
        $http = new MockHttpClient(new MockResponse('boom', ['http_code' => 500]));
        $client = new AttributeExtractorClient($http);

        $result = $client->extract('bytes');

        $this->assertSame([], $result->clothingColors);
        $this->assertSame([], $result->bibs);
    }

    public function testMalformedItemsAreSkippedNotFatal(): void
    {
        // A valid-JSON 200 whose items are the wrong shape (missing value,
        // non-numeric confidence, non-array entry) must degrade to skipped
        // items — never raise — so the non-fatal contract holds for callers.
        $json = json_encode([
            'clothing_colors' => [
                ['confidence' => 0.9],                 // missing "value"
                ['value' => 'orange', 'confidence' => 'high'], // non-numeric confidence
                ['value' => 'blue', 'confidence' => 0.8],      // well-formed, kept
                'not-an-array',                        // scalar entry
            ],
            'bibs' => 'not-a-list',                    // whole field wrong type
        ], JSON_THROW_ON_ERROR);

        $http = new MockHttpClient(new MockResponse($json, [
            'response_headers' => ['content-type' => 'application/json'],
        ]));
        $client = new AttributeExtractorClient($http);

        $result = $client->extract('bytes');

        $this->assertSame(['blue'], array_map(fn (AttributeScore $s): string => $s->value, $result->clothingColors));
        $this->assertSame([], $result->bibs);
    }

    public function testOverLongValuesAreSkipped(): void
    {
        // A bib value longer than the PhotoAttribute.value column (64 chars)
        // must be dropped rather than persisted, to avoid a DB truncation
        // error / batch rollback downstream.
        $json = json_encode([
            'bibs' => [
                ['value' => str_repeat('9', 65), 'confidence' => 0.95],
                ['value' => '1423', 'confidence' => 0.9],
            ],
        ], JSON_THROW_ON_ERROR);

        $http = new MockHttpClient(new MockResponse($json, [
            'response_headers' => ['content-type' => 'application/json'],
        ]));
        $client = new AttributeExtractorClient($http);

        $result = $client->extract('bytes');

        $this->assertSame(['1423'], array_map(fn (AttributeScore $s): string => $s->value, $result->bibs));
    }
}
