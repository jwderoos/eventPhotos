<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

use JsonException;

final readonly class EventArchiveManifest
{
    public const string FORMAT = 'eventphotos.event-export';

    public const int VERSION = 1;

    /**
     * @param list<ManifestPhoto>        $photos
     * @param list<ManifestSubscription> $subscriptions
     */
    public function __construct(
        public string $exportedAt,
        public string $sourceInstance,
        public ManifestEvent $event,
        public array $photos,
        public array $subscriptions,
        public int $skippedPhotos,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format'         => self::FORMAT,
            'version'        => self::VERSION,
            'exportedAt'     => $this->exportedAt,
            'sourceInstance' => $this->sourceInstance,
            'event'          => [
                'name'                 => $this->event->name,
                'slug'                 => $this->event->slug,
                'description'          => $this->event->description,
                'timezone'             => $this->event->timezone,
                'startsAt'             => $this->event->startsAt,
                'endsAt'               => $this->event->endsAt,
                'publishedAt'          => $this->event->publishedAt,
                'notificationsEnabled' => $this->event->notificationsEnabled,
                'style'                => [
                    'fontColor'       => $this->event->fontColor,
                    'backgroundColor' => $this->event->backgroundColor,
                    'buttonColor'     => $this->event->buttonColor,
                    'glowEnabled'     => $this->event->glowEnabled,
                ],
                'logo' => $this->event->logoFilename === null
                    ? null
                    : ['filename' => $this->event->logoFilename],
            ],
            'photos'        => array_map(static fn (ManifestPhoto $p): array => [
                'contentHash'      => $p->contentHash,
                'originalFilename' => $p->originalFilename,
                'byteSize'         => $p->byteSize,
                'width'            => $p->width,
                'height'           => $p->height,
                'takenAt'          => $p->takenAt,
                'derivativeBytes'  => $p->derivativeBytes,
                'createdAt'        => $p->createdAt,
            ], $this->photos),
            'subscriptions' => array_map(static fn (ManifestSubscription $s): array => [
                'email'          => $s->email,
                'status'         => $s->status,
                'confirmedAt'    => $s->confirmedAt,
                'unsubscribedAt' => $s->unsubscribedAt,
                'notifiedAt'     => $s->notifiedAt,
                'createdAt'      => $s->createdAt,
            ], $this->subscriptions),
            'skippedPhotos' => $this->skippedPhotos,
        ];
    }

    public function toJson(): string
    {
        try {
            return json_encode(
                $this->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $jsonException) {
            throw new InvalidArchiveException('Could not encode manifest.', 0, $jsonException);
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidArchiveException('Manifest is not valid JSON.', 0, $jsonException);
        }

        if (!is_array($data)) {
            throw new InvalidArchiveException('Manifest root must be an object.');
        }

        /** @var array<string, mixed> $data */
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArchiveException('Unrecognised archive format.');
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidArchiveException('Unsupported archive version.');
        }

        $event     = self::readArray($data, 'event');
        $style     = self::readArray($event, 'style');
        $logoArray = self::readOptionalArray($event, 'logo');

        $manifestEvent = new ManifestEvent(
            self::str($event, 'name'),
            self::str($event, 'slug'),
            self::nullableStr($event, 'description'),
            self::str($event, 'timezone'),
            self::str($event, 'startsAt'),
            self::str($event, 'endsAt'),
            self::nullableStr($event, 'publishedAt'),
            (bool) ($event['notificationsEnabled'] ?? false),
            self::nullableStr($style, 'fontColor'),
            self::nullableStr($style, 'backgroundColor'),
            self::nullableStr($style, 'buttonColor'),
            isset($style['glowEnabled']) ? (bool) $style['glowEnabled'] : null,
            $logoArray === null ? null : self::nullableStr($logoArray, 'filename'),
        );

        $photos = [];
        foreach (self::readList($data, 'photos') as $row) {
            $photos[] = new ManifestPhoto(
                self::str($row, 'contentHash'),
                self::str($row, 'originalFilename'),
                self::readInt($row, 'byteSize'),
                self::readInt($row, 'width'),
                self::readInt($row, 'height'),
                self::nullableStr($row, 'takenAt'),
                self::readInt($row, 'derivativeBytes'),
                self::str($row, 'createdAt'),
            );
        }

        $subscriptions = [];
        foreach (self::readList($data, 'subscriptions') as $row) {
            $subscriptions[] = new ManifestSubscription(
                self::str($row, 'email'),
                self::str($row, 'status'),
                self::nullableStr($row, 'confirmedAt'),
                self::nullableStr($row, 'unsubscribedAt'),
                self::nullableStr($row, 'notifiedAt'),
                self::str($row, 'createdAt'),
            );
        }

        return new self(
            self::str($data, 'exportedAt'),
            self::str($data, 'sourceInstance'),
            $manifestEvent,
            $photos,
            $subscriptions,
            self::readInt($data, 'skippedPhotos'),
        );
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function readArray(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            throw new InvalidArchiveException(sprintf('Manifest "%s" must be an object.', $key));
        }

        /** @var array<string, mixed> $out */
        $out = $data[$key];

        return $out;
    }

    /**
     * @param  array<string, mixed>      $data
     * @return array<string, mixed>|null
     */
    private static function readOptionalArray(array $data, string $key): ?array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return null;
        }

        /** @var array<string, mixed> $out */
        $out = $data[$key];

        return $out;
    }

    /**
     * @param  array<string, mixed>       $data
     * @return list<array<string, mixed>>
     */
    private static function readList(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return [];
        }

        $out = [];
        foreach ($data[$key] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArchiveException(sprintf('Manifest field "%s" must be a string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $data */
    private static function readInt(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
