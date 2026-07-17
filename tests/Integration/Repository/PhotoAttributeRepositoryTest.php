<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoAttribute;
use App\Entity\PhotoAttributeType;
use App\Entity\User;
use App\Repository\PhotoAttributeRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhotoAttributeRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private PhotoAttributeRepository $attributes;

    private Event $event;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var PhotoAttributeRepository $attributes */
        $attributes = $c->get(PhotoAttributeRepository::class);

        $this->em = $em;
        $this->attributes = $attributes;

        $owner = new User('o@example.test', 'O');
        $owner->setPassword('x');

        $this->em->persist($owner);

        $this->event = new Event(
            'demo',
            'Demo',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $this->em->persist($this->event);
        $this->em->flush();
    }

    private function seedPhoto(string $hash): Photo
    {
        $photo = new Photo($this->event, str_pad($hash, 64, '0'), 'p.jpg', 1000);
        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    public function testDeleteBibForEventRemovesThatBibAcrossAllPhotosOnly(): void
    {
        $photoA = $this->seedPhoto('a1');
        $photoB = $this->seedPhoto('b2');

        // Same bib on two different photos, plus a different bib and a clothing tag that must survive.
        $this->em->persist(new PhotoAttribute($photoA, PhotoAttributeType::Bib, '1423', 0.99));
        $this->em->persist(new PhotoAttribute($photoB, PhotoAttributeType::Bib, '1423', 0.91));
        $this->em->persist(new PhotoAttribute($photoB, PhotoAttributeType::Bib, '5000', 0.95));
        $this->em->persist(new PhotoAttribute($photoA, PhotoAttributeType::ClothingColor, 'orange', 0.9));
        $this->em->flush();

        $this->attributes->deleteBibForEvent($this->event, '1423');
        $this->em->clear();

        $remaining = array_map(
            static fn (PhotoAttribute $a): string => $a->getType()->value . ':' . $a->getValue(),
            $this->attributes->findBy(['value' => ['1423', '5000', 'orange']]),
        );
        sort($remaining);

        $this->assertSame(['bib:5000', 'clothing_color:orange'], $remaining);
    }

    public function testDeleteBibForEventDoesNotTouchOtherEvents(): void
    {
        $otherOwner = new User('o2@example.test', 'O2');
        $otherOwner->setPassword('x');

        $this->em->persist($otherOwner);

        $otherEvent = new Event(
            'other',
            'Other',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $otherOwner,
        );
        $this->em->persist($otherEvent);
        $this->em->flush();

        $photo = $this->seedPhoto('c3');
        $otherPhoto = new Photo($otherEvent, str_pad('d4', 64, '0'), 'p.jpg', 1000);
        $this->em->persist($otherPhoto);
        $this->em->flush();

        $this->em->persist(new PhotoAttribute($photo, PhotoAttributeType::Bib, '1423', 0.99));
        $this->em->persist(new PhotoAttribute($otherPhoto, PhotoAttributeType::Bib, '1423', 0.99));
        $this->em->flush();

        $this->attributes->deleteBibForEvent($this->event, '1423');
        $this->em->clear();

        $surviving = $this->attributes->findBy(['type' => PhotoAttributeType::Bib, 'value' => '1423']);

        $this->assertCount(1, $surviving);
        $this->assertSame($otherEvent->getId(), $surviving[0]->getPhoto()->getEvent()->getId());
    }
}
