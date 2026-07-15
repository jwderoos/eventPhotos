<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Event;
use App\Entity\User;
use App\Form\EventType;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Issue #49 — lenient time input: short digit forms get rewritten to HH:mm
 * server-side (PRE_SUBMIT) before the regex constraint runs, so the user can
 * type `0930` or `9` and still pass validation.
 *
 * Pattern follows EventTypeWindowCompositionTest: boot the kernel to obtain a
 * real form factory (EventType depends on EntityType / VichFileType / Security
 * which can't be reasonably stubbed in a flat TypeTestCase).
 */
final class EventTypeTimeNormalizationTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('form.factory');
        $this->assertInstanceOf(FormFactoryInterface::class, $factory);
        $this->factory = $factory;
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function lenientStartTimes(): iterable
    {
        yield 'single digit hour'           => ['9',     '09:00'];
        yield 'two digit hour'              => ['09',    '09:00'];
        yield 'three digits HMM'            => ['930',   '09:30'];
        yield 'four digits HHMM'            => ['0930',  '09:30'];
        yield 'late evening HHMM'           => ['2200',  '22:00'];
        yield 'max valid HHMM'              => ['2359',  '23:59'];
        yield 'already formatted unchanged' => ['09:30', '09:30'];
    }

    #[DataProvider('lenientStartTimes')]
    public function testStartTimeIsNormalisedBeforeValidation(string $input, string $expected): void
    {
        $event = $this->newEvent('Europe/Amsterdam');
        $form  = $this->factory->create(EventType::class, $event);

        $form->submit([
            'name'      => $event->getName(),
            'eventDate' => '2026-07-15',
            'startTime' => $input,
            'endTime'   => '23:59',
            'timezone'  => $event->getTimezone(),
            'preview'   => [
                'longEdge' => '1600',
                'quality'  => '85',
            ],
        ]);

        $this->assertTrue($form->isSynchronized(), 'form should be synchronized');
        $startTimeField = $form->get('startTime');
        $this->assertSame($expected, $startTimeField->getData());
        $this->assertTrue(
            $startTimeField->isValid(),
            sprintf('startTime %s should be valid after normalization', var_export($input, true)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStartTimes(): iterable
    {
        yield 'out of range HH:mm' => ['25:99'];
        yield 'four digit too big' => ['2400'];
        yield 'minutes overflow'   => ['2460'];
        yield 'all nines'          => ['9999'];
        yield 'non numeric'        => ['abc'];
        yield 'too many digits'    => ['12345'];
    }

    #[DataProvider('invalidStartTimes')]
    public function testInvalidStartTimeStillRejected(string $input): void
    {
        $event = $this->newEvent('Europe/Amsterdam');
        $form  = $this->factory->create(EventType::class, $event);

        $form->submit([
            'name'      => $event->getName(),
            'eventDate' => '2026-07-15',
            'startTime' => $input,
            'endTime'   => '23:59',
            'timezone'  => $event->getTimezone(),
            'preview'   => [
                'longEdge' => '1600',
                'quality'  => '85',
            ],
        ]);

        $this->assertFalse(
            $form->get('startTime')->isValid(),
            sprintf('startTime %s must still fail the regex constraint', var_export($input, true)),
        );
    }

    public function testNormalizedInputComposesCorrectStartsAt(): void
    {
        $event = $this->newEvent('Europe/Amsterdam');
        $form  = $this->factory->create(EventType::class, $event);

        $form->submit([
            'name'      => $event->getName(),
            'eventDate' => '2026-07-15',
            'startTime' => '0930',
            'endTime'   => '1400',
            'timezone'  => $event->getTimezone(),
            'preview'   => [
                'longEdge' => '1600',
                'quality'  => '85',
            ],
        ]);

        // Pair with EventTypeWindowCompositionTest: that suite already asserts the
        // form composes startsAt/endsAt correctly given HH:mm input — here we only need
        // to prove the lenient digit input flows through to that composition unchanged.
        $this->assertTrue(
            $form->get('startTime')->isValid() && $form->get('endTime')->isValid(),
            'time fields should validate after lenient normalisation',
        );
        $this->assertSame(
            '2026-07-15 07:30:00',
            $event->getStartsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'Amsterdam DST (UTC+2): 09:30 local = 07:30 UTC',
        );
        $this->assertSame(
            '2026-07-15 12:00:00',
            $event->getEndsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
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
