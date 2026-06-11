<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EventSlugGenerator;
use PHPUnit\Framework\TestCase;

final class EventSlugGeneratorTest extends TestCase
{
    private const string SHAPE_REGEX = '/^[a-z0-9]+(-[a-z0-9]+)*-[a-z0-9]{6}$/';

    public function testShapeIsSlugifiedNameDashSixCharToken(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('Summer Fest 2026');

        $this->assertMatchesRegularExpression(self::SHAPE_REGEX, $slug);
        $this->assertStringStartsWith('summer-fest-2026-', $slug);
    }

    public function testAsciiFoldsDiacritics(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('Café Olé');

        $this->assertStringStartsWith('cafe-ole-', $slug);
        $this->assertMatchesRegularExpression(self::SHAPE_REGEX, $slug);
    }

    public function testStripsPunctuationAndCollapsesSeparators(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('Summer  Fest!!!  ---  2026');

        $this->assertStringStartsWith('summer-fest-2026-', $slug);
    }

    public function testFallsBackToLiteralEventWhenNameHasNoAlphanumerics(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('!!!---???');

        $this->assertStringStartsWith('event-', $slug);
        $this->assertMatchesRegularExpression(self::SHAPE_REGEX, $slug);
    }

    public function testFallsBackToLiteralEventWhenNameIsEmpty(): void
    {
        $generator = new EventSlugGenerator();

        $slug = $generator->generate('');

        $this->assertStringStartsWith('event-', $slug);
        $this->assertMatchesRegularExpression(self::SHAPE_REGEX, $slug);
    }

    public function testBaseIsCappedAt60CharsForMultiWordName(): void
    {
        $generator = new EventSlugGenerator();
        $name = str_repeat('alpha beta ', 30); // 330 chars, many separators

        $slug = $generator->generate($name);

        $lastDash = strrpos($slug, '-');
        $this->assertNotFalse($lastDash);
        $base = substr($slug, 0, $lastDash);
        $this->assertLessThanOrEqual(60, strlen($base), 'base must be ≤ 60 chars');
        $this->assertStringEndsNotWith('-', $base, 'base must not end with separator');
    }

    public function testBaseIsHardTruncatedFor200CharSingleWord(): void
    {
        $generator = new EventSlugGenerator();
        $name = str_repeat('a', 200);

        $slug = $generator->generate($name);

        $lastDash = strrpos($slug, '-');
        $this->assertNotFalse($lastDash);
        $base = substr($slug, 0, $lastDash);
        $this->assertSame(60, strlen($base));
        $this->assertSame(str_repeat('a', 60), $base);
    }

    public function testTokenCharsetIsLowercaseAlphanumeric(): void
    {
        $generator = new EventSlugGenerator();

        for ($i = 0; $i < 100; ++$i) {
            $slug = $generator->generate('Test');
            $token = substr($slug, -6);
            $this->assertMatchesRegularExpression('/^[a-z0-9]{6}$/', $token);
        }
    }

    public function testTokensVaryAcrossGenerationsForSameName(): void
    {
        $generator = new EventSlugGenerator();
        $tokens = [];

        for ($i = 0; $i < 1000; ++$i) {
            $slug = $generator->generate('Summer Fest');
            $lastDash = strrpos($slug, '-');
            $this->assertNotFalse($lastDash);
            $tokens[] = substr($slug, $lastDash + 1);
        }

        // 6 chars from a 36-char alphabet over 1000 draws: expected
        // distinct ≈ 999.77, std dev tiny. 990 is a vanishingly small
        // false-failure bound (~10 collisions when ~0.23 are expected).
        $this->assertGreaterThan(990, count(array_unique($tokens)));
    }
}
