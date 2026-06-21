<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserMailConfigRepository;
use App\Service\Mail\EncryptedDsn;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

#[ORM\Entity(repositoryClass: UserMailConfigRepository::class)]
#[ORM\Table(name: 'user_mail_configs')]
class UserMailConfig
{
    private const int VERIFICATION_TOKEN_BYTES = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** base64-encoded libsodium ciphertext (entity boundary converts to/from raw bytes) */
    #[ORM\Column(type: Types::TEXT)]
    private string $dsnCiphertext;

    /** base64-encoded 24-byte nonce */
    #[ORM\Column(type: Types::TEXT)]
    private string $dsnNonce;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $fromAddr;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $verificationToken = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $verificationSentAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\OneToOne(inversedBy: 'mailConfig')]
        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        private User $user,
        EncryptedDsn $envelope,
        string $fromAddr,
        #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
        private ?string $fromName,
    ) {
        if ($fromAddr === '') {
            throw new InvalidArgumentException('UserMailConfig from_addr cannot be empty.');
        }

        $this->dsnCiphertext = base64_encode($envelope->ciphertext);
        $this->dsnNonce = base64_encode($envelope->nonce);
        $this->fromAddr = $fromAddr;
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->regenerateVerificationToken();
        $user->setMailConfig($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEncryptedDsn(): EncryptedDsn
    {
        $ciphertext = base64_decode($this->dsnCiphertext, true);
        $nonce = base64_decode($this->dsnNonce, true);
        if ($ciphertext === false || $nonce === false) {
            throw new RuntimeException('UserMailConfig stored DSN payload is not valid base64.');
        }

        return new EncryptedDsn(ciphertext: $ciphertext, nonce: $nonce);
    }

    public function getFromAddr(): string
    {
        return $this->fromAddr;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function getVerifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function getVerificationSentAt(): ?DateTimeImmutable
    {
        return $this->verificationSentAt;
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt instanceof DateTimeImmutable;
    }

    public function markVerified(): void
    {
        if ($this->verifiedAt instanceof DateTimeImmutable) {
            throw new DomainException('UserMailConfig already verified.');
        }

        if ($this->verificationToken === null) {
            throw new DomainException('UserMailConfig has no pending verification token.');
        }

        $this->verifiedAt = new DateTimeImmutable();
        $this->verificationToken = null;
        $this->updatedAt = $this->verifiedAt;
    }

    public function revokeVerification(): void
    {
        if (!$this->verifiedAt instanceof DateTimeImmutable) {
            return;
        }

        $this->verifiedAt = null;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function regenerateVerificationToken(): void
    {
        $this->verificationToken = $this->generateToken();
        $this->verificationSentAt = new DateTimeImmutable();
        $this->verifiedAt = null;
        $this->updatedAt = $this->verificationSentAt;
    }

    /**
     * Replace stored DSN/from fields. Caller is responsible for deciding whether to encrypt a new
     * envelope; the entity treats any new envelope as a DSN change (nonces never repeat).
     *
     * Returns true when re-verification is required (DSN or from_addr changed), false when only
     * the cosmetic from_name differs.
     */
    public function applyConfig(EncryptedDsn $envelope, string $fromAddr, ?string $fromName): bool
    {
        if ($fromAddr === '') {
            throw new InvalidArgumentException('UserMailConfig from_addr cannot be empty.');
        }

        $incomingCiphertext = base64_encode($envelope->ciphertext);
        $incomingNonce = base64_encode($envelope->nonce);
        $dsnChanged = !hash_equals($this->dsnCiphertext, $incomingCiphertext)
            || !hash_equals($this->dsnNonce, $incomingNonce);
        $fromAddrChanged = $this->fromAddr !== $fromAddr;

        $this->dsnCiphertext = $incomingCiphertext;
        $this->dsnNonce = $incomingNonce;
        $this->fromAddr = $fromAddr;
        $this->fromName = $fromName;
        $this->updatedAt = new DateTimeImmutable();

        if ($dsnChanged || $fromAddrChanged) {
            $this->regenerateVerificationToken();
            return true;
        }

        return false;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::VERIFICATION_TOKEN_BYTES)), '+/', '-_'), '=');
    }
}
