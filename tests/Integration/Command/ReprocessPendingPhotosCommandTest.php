<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Message\ProcessPhoto;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ReprocessPendingPhotosCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $app = new Application($kernel);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;

        $this->tester = new CommandTester($app->find('app:photo:reprocess-pending'));
    }

    private function makeEvent(): Event
    {
        $owner = new User('o' . bin2hex(random_bytes(4)) . '@example.test', 'O');
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');
        $event->setRetainOriginals(true);

        $this->em->persist($owner);
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function addPending(Event $event, string $hashSeed): Photo
    {
        // A fresh Photo defaults to PhotoStatus::Pending.
        $photo = new Photo($event, str_pad($hashSeed, 64, '0'), $hashSeed . '.jpg', 100);
        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    /** @return list<int> dispatched photoIds (reingest must be false — fresh ingest) */
    private function dispatchedIds(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        $ids = [];
        foreach ($transport->getSent() as $envelope) {
            $msg = $envelope->getMessage();
            $this->assertInstanceOf(ProcessPhoto::class, $msg);
            $this->assertFalse($msg->reingest, 'Reprocess is a fresh ingest attempt (reingest: false).');
            $ids[] = $msg->photoId;
        }

        return $ids;
    }

    public function testDispatchesProcessPhotoForEachPendingPhoto(): void
    {
        $event = $this->makeEvent();
        $p1 = $this->addPending($event, 'aa');
        $p2 = $this->addPending($event, 'bb');

        $exit = $this->tester->execute(['--min-age' => '0']);

        $this->assertSame(0, $exit);
        $ids = $this->dispatchedIds();
        sort($ids);
        $expected = [(int) $p1->getId(), (int) $p2->getId()];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertStringContainsString('2', $this->tester->getDisplay());
    }

    public function testDryRunDispatchesNothing(): void
    {
        $event = $this->makeEvent();
        $this->addPending($event, 'aa');

        $exit = $this->tester->execute(['--min-age' => '0', '--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertCount(0, $this->dispatchedIds());
    }

    public function testEventFilterRestrictsToThatEvent(): void
    {
        $eventA = $this->makeEvent();
        $eventB = $this->makeEvent();
        $inA = $this->addPending($eventA, 'aa');
        $this->addPending($eventB, 'bb');

        $exit = $this->tester->execute(['--min-age' => '0', '--event' => (string) $eventA->getId()]);

        $this->assertSame(0, $exit);
        $this->assertSame([(int) $inA->getId()], $this->dispatchedIds());
    }

    public function testMinAgeExcludesRecentlyTouchedPhotos(): void
    {
        $event = $this->makeEvent();
        $this->addPending($event, 'aa'); // updatedAt ~= now

        // Only photos older than 60 minutes are eligible; the fresh one is skipped.
        $exit = $this->tester->execute(['--min-age' => '60']);

        $this->assertSame(0, $exit);
        $this->assertCount(0, $this->dispatchedIds());
    }
}
