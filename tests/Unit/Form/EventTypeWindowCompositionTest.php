<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Event;
use App\Entity\User;
use App\Form\EventType;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Verifies the form's composition of (eventDate, startTime, endTime) into UTC
 * startsAt/endsAt on the entity, including midnight rollover and tz handling.
 *
 * EventType pulls in EntityType (Doctrine), VichFileType and the security
 * service — none of which can be reasonably stubbed in a flat TypeTestCase.
 * We boot the test kernel to obtain the real form factory; the test still
 * exercises only the form's PRE_SET_DATA + SUBMIT listener logic, not HTTP
 * controller plumbing (the latter is covered in EventWindowFormTest).
 */
final class EventTypeWindowCompositionTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('form.factory');
        $this->assertInstanceOf(FormFactoryInterface::class, $factory);
        $this->factory = $factory;
    }

    public function testComposesUtcStartAndEndForSameDayEventAmsterdam(): void
    {
        $event = $this->newEvent('Europe/Amsterdam');

        $this->submitDate($event, '2026-07-15', '10:00', '14:00');

        $this->assertSame(
            '2026-07-15 08:00:00',
            $event->getStartsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'Amsterdam DST (UTC+2) means 10:00 local = 08:00 UTC',
        );
        $this->assertSame(
            '2026-07-15 12:00:00',
            $event->getEndsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    public function testRollsEndsAtToNextDayWhenEndTimeIsLessThanOrEqualToStartTime(): void
    {
        $event = $this->newEvent('Europe/Amsterdam');

        // 22:00 → 02:00 should yield a window of exactly 4 hours that crosses midnight.
        $this->submitDate($event, '2026-07-15', '22:00', '02:00');

        $this->assertSame(
            '2026-07-15 22:00',
            $event->getStartsAt()->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'),
        );
        $this->assertSame(
            '2026-07-16 02:00',
            $event->getEndsAt()->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'),
        );
    }

    public function testRollsEndsAtToNextDayWhenEndTimeEqualsStartTime(): void
    {
        $event = $this->newEvent('Europe/Amsterdam');

        $this->submitDate($event, '2026-07-15', '10:00', '10:00');

        // Equal → rolls to next day → 24h window (still allowed by ≤1440 rule).
        $this->assertSame(
            '2026-07-16 10:00',
            $event->getEndsAt()->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'),
        );
    }

    public function testNonDstTimezoneComposesCorrectly(): void
    {
        $event = $this->newEvent('Pacific/Honolulu');

        $this->submitDate($event, '2026-07-15', '10:00', '14:00');

        // Hawaii is UTC-10 year-round.
        $this->assertSame(
            '2026-07-15 20:00:00',
            $event->getStartsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    private function submitDate(Event $event, string $date, string $start, string $end): void
    {
        $form = $this->factory->create(EventType::class, $event);
        $form->submit([
            'name'      => $event->getName(),
            'eventDate' => $date,
            'startTime' => $start,
            'endTime'   => $end,
            'timezone'  => $event->getTimezone(),
            'preview'   => [
                'longEdge' => '1600',
                'quality'  => '85',
            ],
        ]);
    }

    private function newEvent(string $tz): Event
    {
        $owner = new User('o@x', 'O');
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $event->setTimezone($tz);

        return $event;
    }
}
