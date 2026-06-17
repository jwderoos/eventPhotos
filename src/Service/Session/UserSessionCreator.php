<?php

declare(strict_types=1);

namespace App\Service\Session;

use App\Entity\User;
use App\Entity\UserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class UserSessionCreator
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserAgentParser $userAgentParser,
        private CountryResolver $countryResolver,
        private ClockInterface $clock,
    ) {
    }

    public function create(string $sessId, User $user, string $ip, string $userAgent): UserSession
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        $session = new UserSession(
            sessId: $sessId,
            user: $user,
            ip: $ip,
            userAgent: $userAgent,
            userAgentDisplay: $this->userAgentParser->displayString($userAgent),
            countryCode: $this->countryResolver->resolve($ip),
            createdAt: $now,
        );

        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }
}
