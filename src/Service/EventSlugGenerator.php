<?php

declare(strict_types=1);

namespace App\Service;

use Random\RandomException;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class EventSlugGenerator
{
    private const int BASE_MAX_LENGTH = 60;

    private const int TOKEN_LENGTH = 6;

    private const string TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const int TOKEN_ALPHABET_SIZE = 36;

    private const string EMPTY_BASE_FALLBACK = 'event';

    /**
     * @throws RandomException if the CSPRNG cannot produce randomness.
     */
    public function generate(string $name): string
    {
        $base = $this->slugifyBase($name);

        return $base . '-' . $this->randomToken();
    }

    private function slugifyBase(string $name): string
    {
        $slugger = new AsciiSlugger();
        $slugged = strtolower((string) $slugger->slug($name, '-'));

        $sanitized = (string) preg_replace('/[^a-z0-9-]+/', '-', $slugged);
        $collapsed = (string) preg_replace('/-+/', '-', $sanitized);
        $trimmed = trim($collapsed, '-');

        if ($trimmed === '') {
            return self::EMPTY_BASE_FALLBACK;
        }

        if (strlen($trimmed) > self::BASE_MAX_LENGTH) {
            $trimmed = rtrim(substr($trimmed, 0, self::BASE_MAX_LENGTH), '-');
        }

        return $trimmed === '' ? self::EMPTY_BASE_FALLBACK : $trimmed;
    }

    private function randomToken(): string
    {
        $token = '';
        for ($i = 0; $i < self::TOKEN_LENGTH; ++$i) {
            $token .= self::TOKEN_ALPHABET[random_int(0, self::TOKEN_ALPHABET_SIZE - 1)];
        }

        return $token;
    }
}
