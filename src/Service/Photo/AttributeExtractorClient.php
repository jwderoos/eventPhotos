<?php

declare(strict_types=1);

namespace App\Service\Photo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AttributeExtractorClient implements AttributeExtractorClientInterface
{
    private const int HTTP_OK = 200;

    // Mirrors the PhotoAttribute.value column length (64) so an over-long
    // value can never cause a DB truncation error / batch rollback downstream.
    private const int MAX_VALUE_LENGTH = 64;

    public function __construct(
        #[Autowire(service: 'inference.client')]
        private HttpClientInterface $inferenceClient,
    ) {
    }

    public function extract(string $imageBytes): ExtractedAttributes
    {
        try {
            $response = $this->inferenceClient->request('POST', 'extract', [
                'headers' => ['Content-Type' => 'image/jpeg'],
                'body' => $imageBytes,
            ]);

            if ($response->getStatusCode() !== self::HTTP_OK) {
                throw new AttributeExtractionUnavailable(
                    sprintf('Inference returned HTTP %d', $response->getStatusCode()),
                );
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray();
        } catch (ExceptionInterface $exception) {
            throw new AttributeExtractionUnavailable('Inference request failed', 0, $exception);
        }

        // Within a valid 200, the mapping is defensive about individual items
        // (e.g. one missing "value" or a non-numeric "confidence"): such items
        // are skipped rather than raising. Service-level problems (non-200,
        // transport/timeout, undecodable body) are NOT masked — they throw
        // AttributeExtractionUnavailable so the caller can retry rather than
        // mistaking a failure for an empty (destructive-replace) result.
        return new ExtractedAttributes(
            $this->scores($data['clothing_colors'] ?? null, AttributeVocabulary::isColor(...)),
            $this->scores($data['clothing_types'] ?? null, AttributeVocabulary::isGarment(...)),
            $this->scores($data['scenes'] ?? null, AttributeVocabulary::isScene(...)),
            $this->scores($data['bibs'] ?? null, static fn (string $v): bool => $v !== ''),
        );
    }

    /**
     * @param callable(string):bool $accept
     * @return list<AttributeScore>
     */
    private function scores(mixed $items, callable $accept): array
    {
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $value      = $item['value'] ?? null;
            $confidence = $item['confidence'] ?? null;
            if (!is_string($value)) {
                continue;
            }

            if (!is_int($confidence) && !is_float($confidence)) {
                continue;
            }

            if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                continue;
            }

            if ($accept($value)) {
                $out[] = new AttributeScore($value, (float) $confidence);
            }
        }

        return $out;
    }
}
