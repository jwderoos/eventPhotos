<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Event\Archive;

use App\Service\Event\Archive\EventArchiveManifest;
use App\Service\Event\Archive\InvalidArchiveException;
use App\Service\Event\Archive\ManifestEvent;
use App\Service\Event\Archive\ManifestPhoto;
use App\Service\Event\Archive\ManifestSubscription;
use PHPUnit\Framework\TestCase;

final class EventArchiveManifestTest extends TestCase
{
    private function manifest(): EventArchiveManifest
    {
        return new EventArchiveManifest(
            '2026-07-08T10:00:00+00:00',
            'https://events.peakcapture.io',
            new ManifestEvent(
                'My Event',
                'my-event-abc123',
                'Desc',
                'Europe/Amsterdam',
                '2026-01-01T10:00:00+00:00',
                '2026-01-01T12:00:00+00:00',
                '2026-01-01T13:00:00+00:00',
                true,
                '#111111',
                '#eeeeee',
                '#3366ff',
                true,
                'logo.png',
                true,
            ),
            [new ManifestPhoto(
                'a' . str_repeat('0', 63),
                'IMG.jpg',
                1000,
                4000,
                3000,
                '2026-01-01T11:00:00+00:00',
                200000,
                '2026-01-01T11:05:00+00:00',
            )],
            [new ManifestSubscription(
                'v@example.com',
                'confirmed',
                '2026-01-01T11:10:00+00:00',
                null,
                null,
                '2026-01-01T11:00:00+00:00',
            )],
            2,
        );
    }

    public function testJsonRoundTrip(): void
    {
        $restored = EventArchiveManifest::fromJson($this->manifest()->toJson());

        $this->assertSame('my-event-abc123', $restored->event->slug);
        $this->assertTrue($restored->event->notificationsEnabled);
        $this->assertCount(1, $restored->photos);
        $this->assertSame('confirmed', $restored->subscriptions[0]->status);
        $this->assertSame(2, $restored->skippedPhotos);
    }

    public function testRetainOriginalsSurvivesJsonRoundTrip(): void
    {
        $restored = EventArchiveManifest::fromJson($this->manifest()->toJson());

        $this->assertTrue($restored->event->retainOriginals);
    }

    public function testRetainOriginalsDefaultsFalseWhenAbsent(): void
    {
        // A pre-#110 manifest with no retainOriginals key must import as retain-off.
        $json = $this->manifest()->toJson();
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertIsArray($data['event']);
        unset($data['event']['retainOriginals']);

        $reEncoded = json_encode($data);
        $this->assertIsString($reEncoded);

        $restored = EventArchiveManifest::fromJson($reEncoded);

        $this->assertFalse($restored->event->retainOriginals);
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(InvalidArchiveException::class);
        EventArchiveManifest::fromJson('{"format":"nope","version":1}');
    }

    public function testRejectsFutureVersion(): void
    {
        $this->expectException(InvalidArchiveException::class);
        EventArchiveManifest::fromJson('{"format":"eventphotos.event-export","version":999}');
    }

    public function testRejectsNonJson(): void
    {
        $this->expectException(InvalidArchiveException::class);
        EventArchiveManifest::fromJson('not json');
    }
}
