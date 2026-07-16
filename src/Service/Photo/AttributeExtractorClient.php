<?php

declare(strict_types=1);

namespace App\Service\Photo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AttributeExtractorClient implements AttributeExtractorClientInterface
{
    private const int HTTP_OK = 200;

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
                return ExtractedAttributes::empty();
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray();
        } catch (ExceptionInterface) {
            return ExtractedAttributes::empty();
        }

        // The mapping is defensive against a malformed-but-valid-JSON 200
        // (e.g. an item missing "value" or a non-numeric "confidence"): such
        // items are skipped rather than raising, so the "any service problem
        // yields an empty, non-fatal result" contract holds for callers.
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

            if ($accept($value)) {
                $out[] = new AttributeScore($value, (float) $confidence);
            }
        }

        return $out;
    }
}
