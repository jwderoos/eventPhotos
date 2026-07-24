<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventNotificationSubscriptionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity(repositoryClass: EventNotificationSubscriptionRepository::class)]
#[ORM\Table(name: 'event_notification_subscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_event_notif_event_email', columns: ['event_id', 'email'])]
#[ORM\Index(name: 'idx_event_notif_event_status', columns: ['event_id', 'status'])]
class EventNotificationSubscription
{
    public const int DEFAULT_TTL_DAYS = 7;

    private const int TOKEN_BYTES = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $confirmationToken;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $unsubscribeToken;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: EventNotificationStatus::class)]
    private EventNotificationStatus $status = EventNotificationStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmationExpiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $unsubscribedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $notifiedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Event $event,
        string $email,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        int $ttlDays = self::DEFAULT_TTL_DAYS,
    ) {
        $this->email = strtolower($email);
        $this->confirmationToken = $this->generateToken();
        $this->unsubscribeToken = $this->generateToken();
        $this->confirmationExpiresAt = $this->createdAt->modify(sprintf('+%d days', $ttlDays));
    }

    /**
     * Rebuild a subscription from an event-export archive: fresh tokens (source
     * tokens never travel), but the original status and timestamps restored
     * directly — the normal confirm()/unsubscribe() API would overwrite them
     * with "now" and can reject expired confirmations.
     */
    public static function reconstituteForImport(
        Event $event,
        string $email,
        EventNotificationStatus $status,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $confirmedAt,
        ?DateTimeImmutable $unsubscribedAt,
        ?DateTimeImmutable $notifiedAt,
    ): self {
        $sub = new self($event, $email, $createdAt);

        $sub->status         = $status;
        $sub->confirmedAt    = $confirmedAt;
        $sub->unsubscribedAt = $unsubscribedAt;
        $sub->notifiedAt     = $notifiedAt;

        if ($status !== EventNotificationStatus::Pending) {
            // Mirror the state-machine invariant: only pending rows carry a
            // live confirmation token / expiry.
            $sub->confirmationToken     = null;
            $sub->confirmationExpiresAt = null;
        }

        return $sub;
    }

    public function confirm(DateTimeImmutable $now): void
    {
        if ($this->status !== EventNotificationStatus::Pending) {
            throw new DomainException('Only pending subscriptions can be confirmed.');
        }

        if ($this->isConfirmationExpired($now)) {
            throw new DomainException('Confirmation window has expired.');
        }

        $this->status = EventNotificationStatus::Confirmed;
        $this->confirmedAt = $now;
        // Token is retained (not nulled) so a repeat tap of the confirm link
        // still resolves this row and can render the idempotent "confirmed"
        // page instead of the generic "invalid" page. It is inert after
        // confirmation — the confirm controller returns before any state
        // change. See #122. (reconstituteForImport still nulls it for
        // non-pending imports, whose tokens are freshly minted and never sent.)
        $this->confirmationExpiresAt = null;
    }

    public function unsubscribe(DateTimeImmutable $now): void
    {
        if ($this->status === EventNotificationStatus::Unsubscribed) {
            throw new DomainException('Subscription is already unsubscribed.');
        }

        $this->status = EventNotificationStatus::Unsubscribed;
        $this->unsubscribedAt = $now;
        $this->confirmationToken = null;
        $this->confirmationExpiresAt = null;
    }

    public function restartPending(DateTimeImmutable $now, int $ttlDays = self::DEFAULT_TTL_DAYS): void
    {
        $this->status = EventNotificationStatus::Pending;
        $this->confirmationToken = $this->generateToken();
        $this->confirmationExpiresAt = $now->modify(sprintf('+%d days', $ttlDays));
        $this->confirmedAt = null;
        $this->unsubscribedAt = null;
        $this->notifiedAt = null;
    }

    public function markNotified(DateTimeImmutable $now): void
    {
        if ($this->status !== EventNotificationStatus::Confirmed) {
            throw new DomainException('Only confirmed subscriptions can be marked notified.');
        }

        $this->notifiedAt = $now;
    }

    public function isConfirmationExpired(DateTimeImmutable $now): bool
    {
        if (!$this->confirmationExpiresAt instanceof DateTimeImmutable) {
            return false;
        }

        return $now > $this->confirmationExpiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getStatus(): EventNotificationStatus
    {
        return $this->status;
    }

    public function getConfirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function getUnsubscribeToken(): string
    {
        return $this->unsubscribeToken;
    }

    public function getNotifiedAt(): ?DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function getConfirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getUnsubscribedAt(): ?DateTimeImmutable
    {
        return $this->unsubscribedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }
}
