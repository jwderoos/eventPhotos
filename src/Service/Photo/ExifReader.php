<?php

declare(strict_types=1);

namespace App\Service\Photo;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ExifReader
{
    public function readTakenAt(string $path, DateTimeZone $eventTimezone): DateTimeImmutable
    {
        try {
            $data = @exif_read_data($path, 'EXIF', true);
        } catch (Throwable $throwable) {
            throw new PhotoRejected('Could not read EXIF: ' . $throwable->getMessage(), 0, $throwable);
        }

        if (!is_array($data)) {
            throw new PhotoRejected('EXIF DateTimeOriginal is missing (no EXIF data found).');
        }

        $exifSection = is_array($data['EXIF'] ?? null) ? $data['EXIF'] : [];
        $raw = $exifSection['DateTimeOriginal'] ?? $data['DateTimeOriginal'] ?? null;

        if (!is_string($raw) || $raw === '') {
            throw new PhotoRejected('EXIF DateTimeOriginal is missing.');
        }

        $offset = $exifSection['OffsetTimeOriginal'] ?? $data['OffsetTimeOriginal'] ?? null;
        $tz = (is_string($offset) && $offset !== '')
            ? $this->buildOffsetTimezone($offset, $eventTimezone)
            : $eventTimezone;

        $taken = DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $raw, $tz);

        if (!$taken instanceof DateTimeImmutable) {
            throw new PhotoRejected(sprintf('EXIF DateTimeOriginal "%s" is unparseable.', $raw));
        }

        return $taken->setTimezone(new DateTimeZone('UTC'));
    }

    private function buildOffsetTimezone(string $offset, DateTimeZone $fallback): DateTimeZone
    {
        // Accepts "+02:00" or "-05:00".
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset) !== 1) {
            return $fallback;
        }

        try {
            return new DateTimeZone($offset);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
