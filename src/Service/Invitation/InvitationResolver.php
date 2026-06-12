<?php

declare(strict_types=1);

namespace App\Service\Invitation;

use App\Entity\Invitation;
use App\Repository\InvitationRepository;
use Psr\Log\LoggerInterface;

final readonly class InvitationResolver
{
    public function __construct(
        private InvitationRepository $invitations,
        private InvitationTokenService $tokens,
        private LoggerInterface $logger,
    ) {
    }

    public function resolveValid(string $token): ?Invitation
    {
        $parsed = $this->tokens->parse($token);
        if ($parsed === null) {
            $this->logger->warning('invite.redeem_failed', ['reason' => 'malformed']);
            return null;
        }

        $invite = $this->invitations->findBySelector($parsed['selector']);
        if (!$invite instanceof Invitation) {
            $this->logger->warning('invite.redeem_failed', [
                'reason'          => 'unknown',
                'selector_prefix' => substr($parsed['selector'], 0, 8),
            ]);
            return null;
        }

        if (!$this->tokens->verify($invite->getHashedVerifier(), $parsed['verifier'])) {
            $this->logger->warning('invite.redeem_failed', [
                'reason'          => 'verifier_mismatch',
                'invite_id'       => $invite->getId(),
                'selector_prefix' => substr($parsed['selector'], 0, 8),
            ]);
            return null;
        }

        if (!$invite->isPending()) {
            $this->logger->warning('invite.redeem_failed', [
                'reason'    => $invite->status()->value,
                'invite_id' => $invite->getId(),
            ]);
            return null;
        }

        return $invite;
    }
}
