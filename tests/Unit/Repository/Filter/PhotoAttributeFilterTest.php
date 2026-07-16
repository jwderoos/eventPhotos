<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository\Filter;

use App\Repository\Filter\PhotoAttributeFilter;
use PHPUnit\Framework\TestCase;

final class PhotoAttributeFilterTest extends TestCase
{
    public function testEmptyWhenNoDimensionsSet(): void
    {
        $this->assertTrue(new PhotoAttributeFilter()->isEmpty());
    }

    public function testNotEmptyWithColours(): void
    {
        $this->assertFalse(new PhotoAttributeFilter(colours: ['orange'])->isEmpty());
    }

    public function testNotEmptyWithBib(): void
    {
        $this->assertFalse(new PhotoAttributeFilter(bib: '1423')->isEmpty());
    }
}
