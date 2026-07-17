<?php

declare(strict_types=1);

namespace App\Service\Photo;

/**
 * PHP mirror of inference/app/vocabulary.py — the allowlist source of truth.
 * Adding a term here requires the same change in the Python service.
 */
final class AttributeVocabulary
{
    /** @var list<string> */
    public const array COLORS = [
        'black', 'white', 'grey', 'red', 'orange', 'yellow',
        'green', 'blue', 'purple', 'pink', 'brown', 'beige',
    ];

    /** @var list<string> */
    public const array GARMENTS = [
        't-shirt', 'long-sleeve shirt', 'jacket', 'hoodie/sweater',
        'dress', 'shorts', 'trousers', 'skirt', 'hat/cap',
    ];

    /** @var list<string> */
    public const array SCENES = [
        'start', 'finish-line', 'on-course/running',
        'water-station', 'crowd/spectators', 'medal/podium',
    ];

    public static function isColor(string $value): bool
    {
        return in_array($value, self::COLORS, true);
    }

    public static function isGarment(string $value): bool
    {
        return in_array($value, self::GARMENTS, true);
    }

    public static function isScene(string $value): bool
    {
        return in_array($value, self::SCENES, true);
    }
}
