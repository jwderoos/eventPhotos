<?php

declare(strict_types=1);

namespace App\Service\Photo\Search;

use App\Entity\PhotoAttributeType;
use App\Service\Photo\AttributeVocabulary;

final class PhotoSearchQueryParser
{
    private const int FUZZY_MIN_PREFIX = 3;

    private const int FUZZY_MAX_DISTANCE = 1;

    private const int LEV_COST_INSERT = 1;

    private const int LEV_COST_REPLACE = 2;

    private const int LEV_COST_DELETE = 1;

    /**
     * Synonym dictionary: normalized phrase => [type, canonical vocabulary values].
     * Space-normalized (no '-' or '/'), lowercase. Scanned longest-phrase-first so
     * "long sleeve shirt" is consumed before the bare "shirt" fallback.
     *
     * @var array<string, array{PhotoAttributeType, list<string>}>
     */
    private const array DICTIONARY = [
        // Colours
        'black'  => [PhotoAttributeType::ClothingColor, ['black']],
        'white'  => [PhotoAttributeType::ClothingColor, ['white']],
        'grey'   => [PhotoAttributeType::ClothingColor, ['grey']],
        'gray'   => [PhotoAttributeType::ClothingColor, ['grey']],
        'red'    => [PhotoAttributeType::ClothingColor, ['red']],
        'orange' => [PhotoAttributeType::ClothingColor, ['orange']],
        'yellow' => [PhotoAttributeType::ClothingColor, ['yellow']],
        'green'  => [PhotoAttributeType::ClothingColor, ['green']],
        'blue'   => [PhotoAttributeType::ClothingColor, ['blue']],
        'purple' => [PhotoAttributeType::ClothingColor, ['purple']],
        'pink'   => [PhotoAttributeType::ClothingColor, ['pink']],
        'brown'  => [PhotoAttributeType::ClothingColor, ['brown']],
        'beige'  => [PhotoAttributeType::ClothingColor, ['beige']],

        // Garments
        't shirt'           => [PhotoAttributeType::ClothingType, ['t-shirt']],
        'tshirt'            => [PhotoAttributeType::ClothingType, ['t-shirt']],
        'tee'               => [PhotoAttributeType::ClothingType, ['t-shirt']],
        'tees'              => [PhotoAttributeType::ClothingType, ['t-shirt']],
        'long sleeve shirt' => [PhotoAttributeType::ClothingType, ['long-sleeve shirt']],
        'long sleeve'       => [PhotoAttributeType::ClothingType, ['long-sleeve shirt']],
        'longsleeve'        => [PhotoAttributeType::ClothingType, ['long-sleeve shirt']],
        'jacket'            => [PhotoAttributeType::ClothingType, ['jacket']],
        'coat'              => [PhotoAttributeType::ClothingType, ['jacket']],
        'hoodie sweater'    => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'hoodie'            => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'hoody'             => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'sweater'           => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'sweatshirt'        => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'jumper'            => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'dress'             => [PhotoAttributeType::ClothingType, ['dress']],
        'shorts'            => [PhotoAttributeType::ClothingType, ['shorts']],
        'short'             => [PhotoAttributeType::ClothingType, ['shorts']],
        'trousers'          => [PhotoAttributeType::ClothingType, ['trousers']],
        'trouser'           => [PhotoAttributeType::ClothingType, ['trousers']],
        'pants'             => [PhotoAttributeType::ClothingType, ['trousers']],
        'skirt'             => [PhotoAttributeType::ClothingType, ['skirt']],
        'hat cap'           => [PhotoAttributeType::ClothingType, ['hat/cap']],
        'hat'               => [PhotoAttributeType::ClothingType, ['hat/cap']],
        'cap'               => [PhotoAttributeType::ClothingType, ['hat/cap']],
        'shirt'             => [PhotoAttributeType::ClothingType, ['t-shirt', 'long-sleeve shirt']],

        // Scenes
        'start'              => [PhotoAttributeType::Scene, ['start']],
        'finish line'        => [PhotoAttributeType::Scene, ['finish-line']],
        'finishline'         => [PhotoAttributeType::Scene, ['finish-line']],
        'finish'             => [PhotoAttributeType::Scene, ['finish-line']],
        'on course running'  => [PhotoAttributeType::Scene, ['on-course/running']],
        'on course'          => [PhotoAttributeType::Scene, ['on-course/running']],
        'course'             => [PhotoAttributeType::Scene, ['on-course/running']],
        'running'            => [PhotoAttributeType::Scene, ['on-course/running']],
        'water station'      => [PhotoAttributeType::Scene, ['water-station']],
        'waterstation'       => [PhotoAttributeType::Scene, ['water-station']],
        'water'              => [PhotoAttributeType::Scene, ['water-station']],
        'crowd spectators'   => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'crowd'              => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'crowds'             => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'spectators'         => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'spectator'          => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'medal podium'       => [PhotoAttributeType::Scene, ['medal/podium']],
        'medal'              => [PhotoAttributeType::Scene, ['medal/podium']],
        'medals'             => [PhotoAttributeType::Scene, ['medal/podium']],
        'podium'             => [PhotoAttributeType::Scene, ['medal/podium']],
    ];

    public function parse(string $q, bool $bibEnabled, bool $attributesEnabled): ParsedPhotoQuery
    {
        $words = $this->normalize($q);

        /** @var list<SearchToken> $tokens */
        $tokens = [];
        /** @var list<string> $ignored */
        $ignored = [];
        $bibTaken = false;

        $maxPhraseWords = $this->maxPhraseWords();
        $count          = count($words);
        $i              = 0;

        while ($i < $count) {
            $matched = false;

            if ($attributesEnabled) {
                for ($len = min($maxPhraseWords, $count - $i); $len >= 1; --$len) {
                    $phrase = implode(' ', array_slice($words, $i, $len));
                    if (isset(self::DICTIONARY[$phrase])) {
                        [$type, $values] = self::DICTIONARY[$phrase];
                        $label            = count($values) === 1 ? $values[0] : $phrase;
                        $tokens[]        = new SearchToken($type, $phrase, $values, $label);
                        $i              += $len;
                        $matched         = true;
                        break;
                    }
                }
            }

            if ($matched) {
                continue;
            }

            $word = $words[$i];
            ++$i;

            if (preg_match('/^\d+$/', $word) === 1) {
                if ($bibEnabled && !$bibTaken) {
                    $tokens[] = new SearchToken(PhotoAttributeType::Bib, $word, [$word], 'bib ' . $word);
                    $bibTaken = true;
                } else {
                    $ignored[] = $word;
                }

                continue;
            }

            if ($attributesEnabled) {
                $fuzzy = $this->fuzzyColour($word);
                if ($fuzzy !== null) {
                    $tokens[] = new SearchToken(PhotoAttributeType::ClothingColor, $word, [$fuzzy], $fuzzy);
                    continue;
                }
            }

            $ignored[] = $word;
        }

        return new ParsedPhotoQuery($tokens, $ignored);
    }

    /**
     * @return list<string>
     */
    private function normalize(string $q): array
    {
        $lower     = mb_strtolower($q);
        $spaced    = str_replace(['-', '/'], ' ', $lower);
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $spaced));

        if ($collapsed === '') {
            return [];
        }

        return explode(' ', $collapsed);
    }

    private function maxPhraseWords(): int
    {
        $max = 1;
        foreach (array_keys(self::DICTIONARY) as $phrase) {
            $words = substr_count((string) $phrase, ' ') + 1;
            if ($words > $max) {
                $max = $words;
            }
        }

        return $max;
    }

    private function fuzzyColour(string $word): ?string
    {
        if (strlen($word) < self::FUZZY_MIN_PREFIX) {
            return null;
        }

        $bestDistance = null;
        $bestColours  = [];

        foreach (AttributeVocabulary::COLORS as $colour) {
            if (str_starts_with($colour, $word)) {
                $distance = 0;
            } else {
                // Substitutions cost more than insertions/deletions so a typo like
                // "gren" (one letter dropped from "green") stays within the
                // conservative threshold while "grey" (a same-length substitution)
                // does not — this is what keeps the match unique.
                $distance = levenshtein(
                    $word,
                    $colour,
                    self::LEV_COST_INSERT,
                    self::LEV_COST_REPLACE,
                    self::LEV_COST_DELETE,
                );
                if ($distance > self::FUZZY_MAX_DISTANCE) {
                    continue;
                }
            }

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestColours  = [$colour];
            } elseif ($distance === $bestDistance && !in_array($colour, $bestColours, true)) {
                $bestColours[] = $colour;
            }
        }

        return count($bestColours) === 1 ? $bestColours[0] : null;
    }
}
