<?php

declare(strict_types=1);

namespace App\Twig;

use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class BytesExtension extends AbstractExtension
{
    private const int BYTES_PER_KB = 1024;

    /**
     * @return list<TwigFilter>
     */
    #[Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_bytes', $this->formatBytes(...)),
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < self::BYTES_PER_KB) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes / self::BYTES_PER_KB;
        $unit  = $units[0];
        foreach ($units as $candidate) {
            $unit = $candidate;
            if ($value < self::BYTES_PER_KB) {
                break;
            }

            $value /= self::BYTES_PER_KB;
        }

        return sprintf('%.1f %s', $value, $unit);
    }
}
