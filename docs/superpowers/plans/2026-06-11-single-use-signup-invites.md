# Single-use Signup Invitation Links — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-06-11-single-use-signup-invites-design.md` (Issue #31)

**Goal:** Ship admin-issued, single-use, time-limited signup invite URLs that create new `User` accounts with a pre-baked role.

**Architecture:** New `Invitation` entity (Pending/Used/Expired/Revoked, status derived from timestamps), isolated `InvitationTokenService` for crypto (selector + sha256-hashed verifier, mirroring the `symfonycasts/reset-password-bundle` pattern but with no `User` FK at creation time), thin `Admin\InvitationController` (`ROLE_ADMIN`-only create/index/revoke) and thin `Public\InvitationRedemptionController` (`GET/POST /invite/{token}`). Redemption serializes via pessimistic row-lock + re-check inside a single transaction.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 / DBAL 4, PostgreSQL 16, PHPUnit 13 (with `dama/doctrine-test-bundle` for transactional integration tests), Twig + Tailwind/DaisyUI.

**Branch:** `feature/31-single-use-signup-invites` (already created).

**Conventions reminder (CLAUDE.md):**
- `final class` + `declare(strict_types=1)` everywhere.
- PHP attributes for routing / Doctrine — no annotations.
- Migrations via `bin/console doctrine:migrations:diff` ONLY — never hand-written.
- GrumPHP runs phpstan-level-10, phpcs PSR-12, rector, phpmnd (no magic numbers in `src/`), phpcpd, `doctrine:schema:validate` on every commit. Branch name and issue-number-in-commit-message are enforced gates.
- PHPUnit 13 fails on deprecation/notice/warning — keep the source clean.

---

## File Structure (locked-in before tasks)

**New files:**
- `src/Entity/Invitation.php` — entity + state-machine methods
- `src/Entity/InvitationStatus.php` — backed enum, NOT persisted
- `src/Repository/InvitationRepository.php`
- `src/Service/Invitation/InvitationTokenService.php` — pure crypto, no DB / HTTP deps
- `src/Service/Invitation/GeneratedToken.php` — readonly DTO returned by `generate()`
- `src/Form/InvitationCreateType.php`
- `src/Form/InvitationRedeemType.php`
- `src/Controller/Admin/InvitationController.php`
- `src/Controller/Public/InvitationRedemptionController.php`
- `migrations/VersionYYYYMMDDHHMMSS.php` — generated; one new table `invitations`
- `templates/admin/invitation/index.html.twig`
- `templates/admin/invitation/new.html.twig`
- `templates/public/invitation/redeem.html.twig`
- `templates/public/invitation/invalid.html.twig`
- `templates/public/invitation/already_signed_in.html.twig`
- Tests:
  - `tests/Unit/Service/Invitation/InvitationTokenServiceTest.php`
  - `tests/Unit/Entity/InvitationTest.php`
  - `tests/Integration/Repository/InvitationRepositoryTest.php`
  - `tests/Functional/Admin/AdminInvitationFlowTest.php`
  - `tests/Functional/Public/InvitationRedemptionFlowTest.php`
  - `tests/Functional/Public/InvitationConcurrencyTest.php`

**Modified files:**
- `config/packages/security.yaml` — add `{ path: ^/admin/invites, roles: ROLE_ADMIN }` BEFORE the existing `^/admin` → ROLE_ORGANIZER rule.
- `templates/admin/_base.html.twig` — add an "Invites" link in the sidebar nav (visible to `ROLE_ADMIN`).

---

## Task 1: Token service + status enum

**Files:**
- Create: `src/Service/Invitation/GeneratedToken.php`
- Create: `src/Service/Invitation/InvitationTokenService.php`
- Create: `src/Entity/InvitationStatus.php`
- Test: `tests/Unit/Service/Invitation/InvitationTokenServiceTest.php`

The token service has no DB or HTTP dependencies and is the most security-sensitive piece. Build and test it first, in isolation.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Service/Invitation/InvitationTokenServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Invitation;

use App\Service\Invitation\GeneratedToken;
use App\Service\Invitation\InvitationTokenService;
use PHPUnit\Framework\TestCase;

final class InvitationTokenServiceTest extends TestCase
{
    public function testGenerateProducesParseableToken(): void
    {
        $service = new InvitationTokenService();
        $generated = $service->generate();

        self::assertInstanceOf(GeneratedToken::class, $generated);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', $generated->plaintext);
        self::assertSame(32, strlen($generated->selector));
        self::assertSame(64, strlen($generated->hashedVerifier));

        $parsed = $service->parse($generated->plaintext);
        self::assertNotNull($parsed);
        self::assertSame($generated->selector, $parsed['selector']);
    }

    public function testParseRejectsMalformedTokens(): void
    {
        $service = new InvitationTokenService();

        self::assertNull($service->parse(''));
        self::assertNull($service->parse('no-dot-here'));
        self::assertNull($service->parse('.'));
        self::assertNull($service->parse('aa.bb.cc'));
        self::assertNull($service->parse('aa.'));
        self::assertNull($service->parse('.bb'));
        self::assertNull($service->parse('NOT-HEX.NOT-HEX'));
    }

    public function testVerifySucceedsForMatchingPair(): void
    {
        $service = new InvitationTokenService();
        $generated = $service->generate();
        $parsed = $service->parse($generated->plaintext);

        self::assertNotNull($parsed);
        self::assertTrue($service->verify($generated->hashedVerifier, $parsed['verifier']));
    }

    public function testVerifyFailsForTamperedVerifier(): void
    {
        $service = new InvitationTokenService();
        $generated = $service->generate();
        $parsed = $service->parse($generated->plaintext);

        self::assertNotNull($parsed);
        $tampered = str_repeat('0', strlen($parsed['verifier']));
        self::assertFalse($service->verify($generated->hashedVerifier, $tampered));
    }

    public function testVerifyFailsForEmptyVerifier(): void
    {
        $service = new InvitationTokenService();
        $generated = $service->generate();

        self::assertFalse($service->verify($generated->hashedVerifier, ''));
    }

    public function testTwoGenerationsProduceDifferentTokens(): void
    {
        $service = new InvitationTokenService();
        $a = $service->generate();
        $b = $service->generate();

        self::assertNotSame($a->selector, $b->selector);
        self::assertNotSame($a->hashedVerifier, $b->hashedVerifier);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Invitation/InvitationTokenServiceTest.php`

Expected: FAIL — classes `GeneratedToken` and `InvitationTokenService` do not exist.

- [ ] **Step 3: Implement `GeneratedToken`**

Create `src/Service/Invitation/GeneratedToken.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Invitation;

final readonly class GeneratedToken
{
    public function __construct(
        public string $plaintext,
        public string $selector,
        public string $hashedVerifier,
    ) {
    }
}
```

- [ ] **Step 4: Implement `InvitationTokenService`**

Create `src/Service/Invitation/InvitationTokenService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Invitation;

final class InvitationTokenService
{
    private const int SELECTOR_BYTES = 16;
    private const int VERIFIER_BYTES = 32;

    public function generate(): GeneratedToken
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $verifier = bin2hex(random_bytes(self::VERIFIER_BYTES));
        $hashedVerifier = hash('sha256', $verifier);

        return new GeneratedToken(
            plaintext: $selector . '.' . $verifier,
            selector: $selector,
            hashedVerifier: $hashedVerifier,
        );
    }

    /**
     * @return array{selector: string, verifier: string}|null
     */
    public function parse(string $plaintext): ?array
    {
        if (!preg_match('/^([a-f0-9]+)\.([a-f0-9]+)$/', $plaintext, $m)) {
            return null;
        }

        return ['selector' => $m[1], 'verifier' => $m[2]];
    }

    public function verify(string $storedHashedVerifier, string $presentedVerifier): bool
    {
        if ($presentedVerifier === '') {
            return false;
        }

        return hash_equals($storedHashedVerifier, hash('sha256', $presentedVerifier));
    }
}
```

Note on `private const int`: PHP 8.3+ supports typed class constants; the project is on PHP 8.5 so this is fine and dodges phpmnd false positives on the byte counts.

- [ ] **Step 5: Create the `InvitationStatus` enum**

Create `src/Entity/InvitationStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
```

- [ ] **Step 6: Run unit tests**

Run: `vendor/bin/phpunit tests/Unit/Service/Invitation/InvitationTokenServiceTest.php`

Expected: PASS, all six tests.

- [ ] **Step 7: Run static analysis on what we just added**

Run: `vendor/bin/phpstan analyse src/Service/Invitation src/Entity/InvitationStatus.php tests/Unit/Service/Invitation`

Expected: no errors. (Project runs phpstan at level 10 globally.)

- [ ] **Step 8: Commit**

```bash
git add src/Service/Invitation src/Entity/InvitationStatus.php tests/Unit/Service/Invitation
git commit -m "31 - add invitation token service, generated-token DTO, status enum"
```

---

## Task 2: `Invitation` entity + state machine

**Files:**
- Create: `src/Entity/Invitation.php`
- Test: `tests/Unit/Entity/InvitationTest.php`

The entity has zero ORM behaviour yet (no Doctrine attributes); we'll add those in Task 3. This task is pure domain logic: constructor invariants, state-transition methods, and `status()` derivation. Pure unit tests, no DB.

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Entity/InvitationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Invitation;
use App\Entity\InvitationStatus;
use App\Entity\User;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvitationTest extends TestCase
{
    public function testFreshInvitationIsPending(): void
    {
        $invite = $this->makeInvite();

        self::assertSame(InvitationStatus::Pending, $invite->status());
        self::assertTrue($invite->isPending());
    }

    public function testExpiredInvitationReportsExpired(): void
    {
        $invite = $this->makeInvite(expiresAt: new DateTimeImmutable('-1 hour'));

        self::assertSame(InvitationStatus::Expired, $invite->status());
        self::assertFalse($invite->isPending());
    }

    public function testRevokeMarksInvitationRevoked(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $invite = $this->makeInvite();

        $invite->revoke($admin);

        self::assertSame(InvitationStatus::Revoked, $invite->status());
        self::assertSame($admin, $invite->getRevokedBy());
        self::assertNotNull($invite->getRevokedAt());
    }

    public function testMarkUsedTransitionsToUsed(): void
    {
        $newUser = $this->makeUser('new@example.com');
        $invite = $this->makeInvite();

        $invite->markUsed($newUser, 'new@example.com');

        self::assertSame(InvitationStatus::Used, $invite->status());
        self::assertSame($newUser, $invite->getUsedBy());
        self::assertSame('new@example.com', $invite->getEmail());
        self::assertNotNull($invite->getUsedAt());
    }

    public function testRevokeThrowsWhenAlreadyUsed(): void
    {
        $newUser = $this->makeUser('new@example.com');
        $admin = $this->makeUser('admin@example.com');
        $invite = $this->makeInvite();
        $invite->markUsed($newUser, 'new@example.com');

        $this->expectException(DomainException::class);
        $invite->revoke($admin);
    }

    public function testMarkUsedThrowsWhenAlreadyRevoked(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $newUser = $this->makeUser('new@example.com');
        $invite = $this->makeInvite();
        $invite->revoke($admin);

        $this->expectException(DomainException::class);
        $invite->markUsed($newUser, 'new@example.com');
    }

    public function testMarkUsedThrowsWhenExpired(): void
    {
        $newUser = $this->makeUser('new@example.com');
        $invite = $this->makeInvite(expiresAt: new DateTimeImmutable('-1 minute'));

        $this->expectException(DomainException::class);
        $invite->markUsed($newUser, 'new@example.com');
    }

    public function testConstructorRejectsEmptySelector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invitation(
            selector: '',
            hashedVerifier: str_repeat('a', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $this->makeUser('admin@example.com'),
            expiresAt: new DateTimeImmutable('+7 days'),
        );
    }

    public function testConstructorRejectsEmptyHashedVerifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invitation(
            selector: str_repeat('a', 32),
            hashedVerifier: '',
            role: 'ROLE_ORGANIZER',
            createdBy: $this->makeUser('admin@example.com'),
            expiresAt: new DateTimeImmutable('+7 days'),
        );
    }

    public function testConstructorRejectsUnknownRole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invitation(
            selector: str_repeat('a', 32),
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ROOT',
            createdBy: $this->makeUser('admin@example.com'),
            expiresAt: new DateTimeImmutable('+7 days'),
        );
    }

    private function makeInvite(?DateTimeImmutable $expiresAt = null): Invitation
    {
        return new Invitation(
            selector: str_repeat('a', 32),
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $this->makeUser('admin@example.com'),
            expiresAt: $expiresAt ?? new DateTimeImmutable('+7 days'),
        );
    }

    private function makeUser(string $email): User
    {
        return new User($email, 'Display');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/InvitationTest.php`

Expected: FAIL — `App\Entity\Invitation` does not exist.

- [ ] **Step 3: Implement the `Invitation` entity (no ORM attributes yet)**

Create `src/Entity/Invitation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

class Invitation
{
    public const array ALLOWED_ROLES = ['ROLE_ORGANIZER', 'ROLE_ADMIN'];

    private ?int $id = null;

    private string $selector;

    private string $hashedVerifier;

    private string $role;

    private ?string $email = null;

    private User $createdBy;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $expiresAt;

    private ?DateTimeImmutable $usedAt = null;

    private ?User $usedBy = null;

    private ?DateTimeImmutable $revokedAt = null;

    private ?User $revokedBy = null;

    public function __construct(
        string $selector,
        string $hashedVerifier,
        string $role,
        User $createdBy,
        DateTimeImmutable $expiresAt,
    ) {
        if ($selector === '') {
            throw new InvalidArgumentException('Invitation selector cannot be empty.');
        }
        if ($hashedVerifier === '') {
            throw new InvalidArgumentException('Invitation hashed verifier cannot be empty.');
        }
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException(sprintf('Invitation role "%s" is not allowed.', $role));
        }

        $this->selector = $selector;
        $this->hashedVerifier = $hashedVerifier;
        $this->role = $role;
        $this->createdBy = $createdBy;
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getHashedVerifier(): string
    {
        return $this->hashedVerifier;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getUsedBy(): ?User
    {
        return $this->usedBy;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function status(): InvitationStatus
    {
        if ($this->usedAt !== null) {
            return InvitationStatus::Used;
        }
        if ($this->revokedAt !== null) {
            return InvitationStatus::Revoked;
        }
        if ($this->expiresAt < new DateTimeImmutable()) {
            return InvitationStatus::Expired;
        }

        return InvitationStatus::Pending;
    }

    public function isPending(): bool
    {
        return $this->status() === InvitationStatus::Pending;
    }

    public function markUsed(User $newUser, string $email): void
    {
        if (!$this->isPending()) {
            throw new DomainException(sprintf(
                'Cannot mark invitation as used from status %s.',
                $this->status()->value,
            ));
        }

        $this->usedAt = new DateTimeImmutable();
        $this->usedBy = $newUser;
        $this->email = $email;
    }

    public function revoke(User $admin): void
    {
        if (!$this->isPending()) {
            throw new DomainException(sprintf(
                'Cannot revoke invitation from status %s.',
                $this->status()->value,
            ));
        }

        $this->revokedAt = new DateTimeImmutable();
        $this->revokedBy = $admin;
    }
}
```

The class is intentionally non-`final` because Doctrine proxies still benefit from extending it. Other entities in this codebase (e.g. `User`, `Photo`) follow the same pattern.

- [ ] **Step 4: Run unit tests**

Run: `vendor/bin/phpunit tests/Unit/Entity/InvitationTest.php`

Expected: PASS, all ten tests.

- [ ] **Step 5: Static analysis**

Run: `vendor/bin/phpstan analyse src/Entity/Invitation.php tests/Unit/Entity/InvitationTest.php`

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Entity/Invitation.php tests/Unit/Entity/InvitationTest.php
git commit -m "31 - add Invitation entity and state-machine (domain layer)"
```

---

## Task 3: ORM mapping, migration, repository

**Files:**
- Modify: `src/Entity/Invitation.php` (add Doctrine attributes)
- Create: `src/Repository/InvitationRepository.php`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Test: `tests/Integration/Repository/InvitationRepositoryTest.php`

- [ ] **Step 1: Add Doctrine attributes to `Invitation`**

Edit `src/Entity/Invitation.php`. Add imports at the top:

```php
use App\Repository\InvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
```

Add class-level attributes immediately above `class Invitation`:

```php
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitations')]
#[ORM\UniqueConstraint(name: 'uniq_invitations_selector', columns: ['selector'])]
class Invitation
```

Annotate each persisted property. Use these attributes (place each above its corresponding property declaration; leave the constructor body unchanged):

```php
#[ORM\Id]
#[ORM\GeneratedValue]
#[ORM\Column(type: Types::INTEGER)]
private ?int $id = null;

#[ORM\Column(type: Types::STRING, length: 32)]
private string $selector;

#[ORM\Column(name: 'hashed_verifier', type: Types::STRING, length: 64)]
private string $hashedVerifier;

#[ORM\Column(type: Types::STRING, length: 32)]
private string $role;

#[ORM\Column(type: Types::STRING, length: 180, nullable: true)]
private ?string $email = null;

#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'RESTRICT')]
private User $createdBy;

#[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
private DateTimeImmutable $createdAt;

#[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
private DateTimeImmutable $expiresAt;

#[ORM\Column(name: 'used_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
private ?DateTimeImmutable $usedAt = null;

#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(name: 'used_by_id', nullable: true, onDelete: 'SET NULL')]
private ?User $usedBy = null;

#[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
private ?DateTimeImmutable $revokedAt = null;

#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(name: 'revoked_by_id', nullable: true, onDelete: 'SET NULL')]
private ?User $revokedBy = null;
```

- [ ] **Step 2: Create the repository**

Create `src/Repository/InvitationRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
final class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findBySelector(string $selector): ?Invitation
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    /**
     * @return list<Invitation>
     */
    public function findAllOrderedByCreated(): array
    {
        return array_values($this->findBy([], ['createdAt' => 'DESC']));
    }
}
```

- [ ] **Step 3: Verify the schema validates**

Run: `bin/console doctrine:schema:validate --skip-sync`

Expected: `[Mapping] OK - The mapping files are correct.` (DB-sync may still report differences — that's fine; we generate the migration next.)

If mapping is NOT OK, fix the attributes before generating a migration. A bad mapping produces a bad migration.

- [ ] **Step 4: Generate the migration**

Run: `bin/console doctrine:migrations:diff --formatted`

Expected: a new file `migrations/VersionYYYYMMDDHHMMSS.php` containing a `CREATE TABLE invitations` with a UNIQUE INDEX on `selector` and three foreign keys to `users` (one `RESTRICT`, two `SET NULL`).

Open the generated file, replace the empty `getDescription()` body with a useful string:

```php
public function getDescription(): string
{
    return 'Add invitations table for single-use signup links (#31).';
}
```

Do NOT hand-edit the SQL.

- [ ] **Step 5: Apply the migration to the dev DB**

Run: `bin/console doctrine:migrations:migrate --no-interaction`

Expected: migration runs, `invitations` table now exists.

- [ ] **Step 6: Verify the schema is in sync**

Run: `bin/console doctrine:schema:validate`

Expected: both `[Mapping]` and `[Database]` report OK.

- [ ] **Step 7: Write the repository integration test**

Create `tests/Integration/Repository/InvitationRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Invitation;
use App\Entity\User;
use App\Repository\InvitationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvitationRepositoryTest extends KernelTestCase
{
    public function testFindBySelectorReturnsMatch(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationRepository $repo */
        $repo = $container->get(InvitationRepository::class);

        $admin = new User('admin-repo@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('hashed');
        $em->persist($admin);

        $selector = str_repeat('a', 32);
        $invite = new Invitation(
            selector: $selector,
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();

        $found = $repo->findBySelector($selector);
        self::assertNotNull($found);
        self::assertSame($invite->getId(), $found->getId());

        self::assertNull($repo->findBySelector(str_repeat('z', 32)));
    }

    public function testFindAllOrderedByCreatedReturnsNewestFirst(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationRepository $repo */
        $repo = $container->get(InvitationRepository::class);

        $admin = new User('admin-ordering@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('hashed');
        $em->persist($admin);

        $older = new Invitation(
            selector: str_repeat('1', 32),
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($older);
        $em->flush();

        // Force a different createdAt by waiting one second wall-clock OR re-loading;
        // simplest: persist + flush twice with a small usleep so the timestamps differ.
        usleep(1_100_000);

        $newer = new Invitation(
            selector: str_repeat('2', 32),
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($newer);
        $em->flush();

        $all = $repo->findAllOrderedByCreated();

        // We only assert the relative ordering of OUR two rows in case the test
        // runs with sibling rows from other test interaction. dama transactions
        // isolate this, but be defensive.
        $ids = array_map(static fn (Invitation $i): ?int => $i->getId(), $all);
        $newerIdx = array_search($newer->getId(), $ids, true);
        $olderIdx = array_search($older->getId(), $ids, true);
        self::assertIsInt($newerIdx);
        self::assertIsInt($olderIdx);
        self::assertLessThan($olderIdx, $newerIdx, 'Newer invitation should come before older.');
    }
}
```

Note: the project already uses `dama/doctrine-test-bundle` for transactional rollback (see `tests/Integration/` siblings). Inheriting `KernelTestCase` and the bundle's auto-rollback keeps this clean.

- [ ] **Step 8: Prepare the test DB and run the repository test**

Run (sequential):
```bash
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --env=test --no-interaction
vendor/bin/phpunit tests/Integration/Repository/InvitationRepositoryTest.php
```

Expected: the database create no-ops or creates `eventfotos_test`, migrations run, and both tests pass.

- [ ] **Step 9: Run the full GrumPHP gate now that schema is real**

Run: `vendor/bin/grumphp run`

This catches whatever else GrumPHP would block at commit time (rector, phpcs, phpmnd, schema-validate). Fix whatever it complains about — do NOT skip with `--no-verify`.

- [ ] **Step 10: Commit**

```bash
git add src/Entity/Invitation.php src/Repository/InvitationRepository.php migrations tests/Integration/Repository/InvitationRepositoryTest.php
git commit -m "31 - add invitations table mapping, migration, and repository"
```

---

## Task 4: Admin index page + nav link + create flow

**Files:**
- Create: `src/Form/InvitationCreateType.php`
- Create: `src/Controller/Admin/InvitationController.php` (index + new only — revoke added in Task 5)
- Create: `templates/admin/invitation/index.html.twig`
- Create: `templates/admin/invitation/new.html.twig`
- Modify: `templates/admin/_base.html.twig` (nav link)
- Modify: `config/packages/security.yaml` (access_control rule)
- Test: `tests/Functional/Admin/AdminInvitationFlowTest.php` (initial subset)

- [ ] **Step 1: Update `security.yaml`**

Edit `config/packages/security.yaml`. Find the `access_control:` list. Insert the invites rule immediately AFTER the existing `^/admin/users` rule and BEFORE the catch-all `^/admin`:

```yaml
        - { path: ^/admin/users, roles: ROLE_ADMIN }
        - { path: ^/admin/invites, roles: ROLE_ADMIN }
        - { path: ^/admin, roles: ROLE_ORGANIZER }
```

Order matters — `access_control` rules are first-match.

- [ ] **Step 2: Add the nav link**

Edit `templates/admin/_base.html.twig`. Inside the `{% if is_granted('ROLE_ADMIN') %} ... {% endif %}` block that contains the existing "Users" `<li>`, add a second `<li>` directly below it:

```twig
                            <li>
                                <a href="{{ path('admin_invite_index') }}"
                                   class="{{ route starts with 'admin_invite_' ? 'active' : '' }}">
                                    Invites
                                </a>
                            </li>
```

- [ ] **Step 3: Build the create form**

Create `src/Form/InvitationCreateType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class InvitationCreateType extends AbstractType
{
    public const array ROLE_CHOICES = [
        'Organizer' => 'ROLE_ORGANIZER',
        'Admin'     => 'ROLE_ADMIN',
    ];

    public const int MIN_DAYS = 1;
    public const int MAX_DAYS = 30;
    public const int DEFAULT_DAYS = 7;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('role', ChoiceType::class, [
                'label'       => 'Role',
                'choices'     => self::ROLE_CHOICES,
                'expanded'    => true,
                'multiple'    => false,
                'data'        => 'ROLE_ORGANIZER',
                'constraints' => [new NotBlank()],
            ])
            ->add('expiresInDays', IntegerType::class, [
                'label'       => 'Valid for (days)',
                'data'        => self::DEFAULT_DAYS,
                'constraints' => [
                    new NotBlank(),
                    new Range(min: self::MIN_DAYS, max: self::MAX_DAYS),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
```

- [ ] **Step 4: Build the controller (index + new)**

Create `src/Controller/Admin/InvitationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Invitation;
use App\Form\InvitationCreateType;
use App\Repository\InvitationRepository;
use App\Service\Invitation\InvitationTokenService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly EntityManagerInterface $em,
        private readonly InvitationTokenService $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/invites', name: 'admin_invite_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $session = $request->getSession();
        $newUrl = null;
        if ($session->has('invitation.new_url')) {
            $value = $session->get('invitation.new_url');
            $newUrl = is_string($value) ? $value : null;
            $session->remove('invitation.new_url');
        }

        return $this->render('admin/invitation/index.html.twig', [
            'invitations' => $this->invitations->findAllOrderedByCreated(),
            'newUrl'      => $newUrl,
        ]);
    }

    #[Route('/admin/invites/new', name: 'admin_invite_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(InvitationCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{role: string, expiresInDays: int} $data */
            $data = $form->getData();

            $generated = $this->tokens->generate();
            $invite = new Invitation(
                selector: $generated->selector,
                hashedVerifier: $generated->hashedVerifier,
                role: $data['role'],
                createdBy: $this->getCurrentAdmin(),
                expiresAt: new DateTimeImmutable('+' . $data['expiresInDays'] . ' days'),
            );

            $this->em->persist($invite);
            $this->em->flush();

            $url = $this->generateUrl(
                'public_invite_redeem',
                ['token' => $generated->plaintext],
                \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $request->getSession()->set('invitation.new_url', $url);

            $this->logger->info('invite.created', [
                'invite_id'     => $invite->getId(),
                'role'          => $invite->getRole(),
                'created_by_id' => $invite->getCreatedBy()->getId(),
                'expires_at'    => $invite->getExpiresAt()->format(DateTimeImmutable::ATOM),
            ]);

            return new RedirectResponse($this->generateUrl('admin_invite_index'));
        }

        $status = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/invitation/new.html.twig', [
            'form' => $form,
        ], new Response(null, $status));
    }

    private function getCurrentAdmin(): \App\Entity\User
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new \LogicException('Authenticated user expected.');
        }
        return $user;
    }
}
```

- [ ] **Step 5: Build the index template**

Create `templates/admin/invitation/index.html.twig`:

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — Invites{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li>Invites</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Invites</h1>
        <a href="{{ path('admin_invite_new') }}" class="btn btn-primary btn-sm">+ New invite</a>
    </header>

    {% if newUrl is not null %}
        <div class="alert alert-success mb-6" data-testid="invite-new-url">
            <div class="flex w-full flex-col gap-2">
                <span class="font-semibold">Invite link generated — copy it now.</span>
                <span class="text-sm">This URL is shown only once. After you leave this page it cannot be recovered.</span>
                <div class="flex items-center gap-2">
                    <input type="text" readonly value="{{ newUrl }}"
                           class="input input-bordered input-sm flex-1 font-mono text-xs"
                           onclick="this.select()">
                    <button type="button" class="btn btn-sm" onclick="navigator.clipboard.writeText('{{ newUrl|e('js') }}')">Copy</button>
                </div>
            </div>
        </div>
    {% endif %}

    {% set statusBadgeMap = {
        'pending':  'badge-info',
        'used':     'badge-success',
        'expired':  'badge-ghost',
        'revoked':  'badge-error',
    } %}

    <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
        <table class="table table-zebra">
            <thead>
                <tr>
                    <th>Selector</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created by</th>
                    <th>Created</th>
                    <th>Expires</th>
                    <th>Redeemed by</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                {% for invite in invitations %}
                    {% set status = invite.status.value %}
                    <tr>
                        <td><code class="text-xs">{{ invite.selector|slice(0, 8) }}…</code></td>
                        <td><span class="badge badge-outline">{{ invite.role|replace({'ROLE_': ''})|capitalize }}</span></td>
                        <td>
                            <span class="badge {{ statusBadgeMap[status] }}" data-testid="invite-status-{{ invite.id }}">
                                {{ status|capitalize }}
                            </span>
                        </td>
                        <td>{{ invite.createdBy.email }}</td>
                        <td><span title="{{ invite.createdAt|date('c') }}">{{ invite.createdAt|date('Y-m-d H:i') }}</span></td>
                        <td><span title="{{ invite.expiresAt|date('c') }}">{{ invite.expiresAt|date('Y-m-d H:i') }}</span></td>
                        <td>{{ invite.usedBy ? invite.usedBy.email : invite.email|default('—') }}</td>
                        <td class="text-right">
                            {% if invite.pending %}
                                <form method="post"
                                      action="{{ path('admin_invite_revoke', {id: invite.id}) }}"
                                      onsubmit="return confirm('Revoke this invite?')"
                                      class="inline">
                                    <input type="hidden" name="_token"
                                           value="{{ csrf_token('invite_revoke_' ~ invite.id) }}">
                                    <button type="submit" class="btn btn-ghost btn-xs text-error">Revoke</button>
                                </form>
                            {% else %}
                                <span class="text-xs text-base-content/50">—</span>
                            {% endif %}
                        </td>
                    </tr>
                {% else %}
                    <tr>
                        <td colspan="8" class="py-8 text-center text-base-content/60">No invites yet.</td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
{% endblock %}
```

`invite.pending` calls `Invitation::isPending()` via Twig's getter shorthand.

- [ ] **Step 6: Build the new-invite template**

Create `templates/admin/invitation/new.html.twig`:

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — New invite{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li><a href="{{ path('admin_invite_index') }}">Invites</a></li>
            <li>New</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6">
        <h1 class="text-2xl font-semibold">Create invite link</h1>
        <p class="text-sm text-base-content/70">The URL is shown ONCE after creation. Share it via your own channel.</p>
    </header>

    <div class="card max-w-xl bg-base-100 shadow">
        <div class="card-body">
            {{ form_start(form) }}
                {{ form_row(form.role) }}
                {{ form_row(form.expiresInDays) }}
                <div class="card-actions justify-end">
                    <a href="{{ path('admin_invite_index') }}" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create invite</button>
                </div>
            {{ form_end(form) }}
        </div>
    </div>
{% endblock %}
```

- [ ] **Step 7: Write the initial admin functional tests**

Create `tests/Functional/Admin/AdminInvitationFlowTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminInvitationFlowTest extends WebTestCase
{
    public function testAdminCanCreateInviteAndSeeOneTimeUrlBanner(): void
    {
        $browser = self::createClient();
        $this->loginAsAdmin($browser);

        $browser->request(Request::METHOD_GET, '/admin/invites/new');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $browser->submitForm('Create invite');

        self::assertResponseRedirects('/admin/invites');
        $browser->followRedirect();

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorExists('[data-testid="invite-new-url"]');

        // Re-visit: the banner should be gone (flash consumed).
        $browser->request(Request::METHOD_GET, '/admin/invites');
        self::assertSelectorNotExists('[data-testid="invite-new-url"]');

        /** @var InvitationRepository $repo */
        $repo = self::getContainer()->get(InvitationRepository::class);
        self::assertCount(1, $repo->findAllOrderedByCreated());
    }

    public function testOrganizerCannotAccessInviteRoutes(): void
    {
        $browser = self::createClient();
        $this->loginAsOrganizer($browser);

        $browser->request(Request::METHOD_GET, '/admin/invites');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $browser->request(Request::METHOD_GET, '/admin/invites/new');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function loginAsAdmin(KernelBrowser $browser): User
    {
        return $this->createAndLogin($browser, 'admin@example.com', 'ROLE_ADMIN');
    }

    private function loginAsOrganizer(KernelBrowser $browser): User
    {
        return $this->createAndLogin($browser, 'organizer@example.com', 'ROLE_ORGANIZER');
    }

    private function createAndLogin(KernelBrowser $browser, string $email, string $role): User
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User($email, ucfirst(explode('@', $email)[0]));
        $user->addRole($role);
        $user->setPassword($hasher->hashPassword($user, 'correct horse battery'));

        $em->persist($user);
        $em->flush();

        $browser->loginUser($user);

        return $user;
    }
}
```

- [ ] **Step 8: Run the new functional tests**

Run: `vendor/bin/phpunit tests/Functional/Admin/AdminInvitationFlowTest.php`

Expected: both tests pass. (`testAdminCanCreateInviteAndSeeOneTimeUrlBanner` will fail if the `public_invite_redeem` route doesn't exist yet — Task 6 adds it. So this test depends on Task 6 wiring. **Skip this step if Task 6 isn't done yet; come back to it.**)

If you ARE running Tasks in order: this step will fail at `generateUrl('public_invite_redeem', ...)`. Comment out that test temporarily, run only `testOrganizerCannotAccessInviteRoutes`, and remove the comment after Task 6 lands.

- [ ] **Step 9: Static analysis + GrumPHP**

Run:
```bash
vendor/bin/phpstan analyse
vendor/bin/grumphp run
```

Expected: clean.

- [ ] **Step 10: Commit**

```bash
git add config/packages/security.yaml templates/admin/_base.html.twig templates/admin/invitation src/Form/InvitationCreateType.php src/Controller/Admin/InvitationController.php tests/Functional/Admin/AdminInvitationFlowTest.php
git commit -m "31 - admin invites index, create flow, and nav wiring"
```

---

## Task 5: Admin revoke action

**Files:**
- Modify: `src/Controller/Admin/InvitationController.php` (add `revoke` method)
- Modify: `tests/Functional/Admin/AdminInvitationFlowTest.php` (add revoke tests)

- [ ] **Step 1: Add failing tests for revoke**

Edit `tests/Functional/Admin/AdminInvitationFlowTest.php` and append the following tests inside the class:

```php
    public function testAdminCanRevokePendingInvite(): void
    {
        $browser = self::createClient();
        $admin = $this->loginAsAdmin($browser);

        $invite = $this->createPendingInvite($admin);
        $inviteId = $invite->getId();
        self::assertNotNull($inviteId);

        $browser->request(Request::METHOD_POST, '/admin/invites/' . $inviteId . '/revoke', [
            '_token' => $this->csrfToken('invite_revoke_' . $inviteId),
        ]);
        self::assertResponseRedirects('/admin/invites');

        /** @var InvitationRepository $repo */
        $repo = self::getContainer()->get(InvitationRepository::class);
        $reloaded = $repo->findBySelector($invite->getSelector());
        self::assertNotNull($reloaded);
        self::assertSame('revoked', $reloaded->status()->value);
    }

    public function testRevokeOnAlreadyTerminalInviteIsNoOp(): void
    {
        $browser = self::createClient();
        $admin = $this->loginAsAdmin($browser);

        $invite = $this->createPendingInvite($admin);
        $invite->revoke($admin);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->flush();

        $inviteId = $invite->getId();
        self::assertNotNull($inviteId);

        $browser->request(Request::METHOD_POST, '/admin/invites/' . $inviteId . '/revoke', [
            '_token' => $this->csrfToken('invite_revoke_' . $inviteId),
        ]);
        self::assertResponseRedirects('/admin/invites');

        /** @var InvitationRepository $repo */
        $repo = self::getContainer()->get(InvitationRepository::class);
        $reloaded = $repo->findBySelector($invite->getSelector());
        self::assertNotNull($reloaded);
        self::assertSame('revoked', $reloaded->status()->value);
    }

    public function testRevokeRejectsMissingCsrf(): void
    {
        $browser = self::createClient();
        $admin = $this->loginAsAdmin($browser);

        $invite = $this->createPendingInvite($admin);
        $inviteId = $invite->getId();
        self::assertNotNull($inviteId);

        $browser->request(Request::METHOD_POST, '/admin/invites/' . $inviteId . '/revoke');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function createPendingInvite(User $admin): \App\Entity\Invitation
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var \App\Service\Invitation\InvitationTokenService $tokens */
        $tokens = $container->get(\App\Service\Invitation\InvitationTokenService::class);

        $gen = $tokens->generate();
        $invite = new \App\Entity\Invitation(
            selector: $gen->selector,
            hashedVerifier: $gen->hashedVerifier,
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();

        return $invite;
    }

    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $manager */
        $manager = self::getContainer()->get('security.csrf.token_manager');
        return $manager->getToken($id)->getValue();
    }
```

Add `use App\Repository\InvitationRepository;` (already present) and ensure `use Doctrine\ORM\EntityManagerInterface;` is at the top.

- [ ] **Step 2: Run the new tests (should fail)**

Run: `vendor/bin/phpunit tests/Functional/Admin/AdminInvitationFlowTest.php`

Expected: the three new tests FAIL — route `admin_invite_revoke` does not exist.

- [ ] **Step 3: Add the `revoke` action**

Edit `src/Controller/Admin/InvitationController.php`. Add (anywhere among the other route methods):

```php
    #[Route(
        '/admin/invites/{id}/revoke',
        name: 'admin_invite_revoke',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function revoke(Invitation $invite, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('invite_revoke_' . $invite->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$invite->isPending()) {
            $this->addFlash('warning', sprintf(
                'Invite is already %s — nothing to revoke.',
                $invite->status()->value,
            ));
            return new RedirectResponse($this->generateUrl('admin_invite_index'));
        }

        $invite->revoke($this->getCurrentAdmin());
        $this->em->flush();

        $this->logger->info('invite.revoked', [
            'invite_id'      => $invite->getId(),
            'revoked_by_id'  => $this->getCurrentAdmin()->getId(),
        ]);

        $this->addFlash('success', 'Invite revoked.');
        return new RedirectResponse($this->generateUrl('admin_invite_index'));
    }
```

- [ ] **Step 4: Run the admin tests**

Run: `vendor/bin/phpunit tests/Functional/Admin/AdminInvitationFlowTest.php`

Expected: all admin tests pass (including the create-flow test from Task 4 — see note in Task 4 step 8 if you deferred it).

- [ ] **Step 5: GrumPHP**

Run: `vendor/bin/grumphp run`

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Admin/InvitationController.php tests/Functional/Admin/AdminInvitationFlowTest.php
git commit -m "31 - admin invite revoke action"
```

---

## Task 6: Public redemption — GET, anonymous form, invalid page, logged-in branch

**Files:**
- Create: `src/Form/InvitationRedeemType.php`
- Create: `src/Controller/Public/InvitationRedemptionController.php` (GET only; POST in Task 7)
- Create: `templates/public/invitation/redeem.html.twig`
- Create: `templates/public/invitation/invalid.html.twig`
- Create: `templates/public/invitation/already_signed_in.html.twig`
- Test: `tests/Functional/Public/InvitationRedemptionFlowTest.php`

- [ ] **Step 1: Build the redeem form**

Create `src/Form/InvitationRedeemType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class InvitationRedeemType extends AbstractType
{
    public const int MIN_PASSWORD_LENGTH = 12;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('displayName', TextType::class, [
                'label'       => 'Display name',
                'constraints' => [new NotBlank()],
            ])
            ->add('password', RepeatedType::class, [
                'type'            => PasswordType::class,
                'invalid_message' => 'The password fields must match.',
                'first_options'   => [
                    'attr'        => ['autocomplete' => 'new-password'],
                    'label'       => 'Password',
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password.'),
                        new Length(min: self::MIN_PASSWORD_LENGTH, minMessage: 'Your password must be at least {{ limit }} characters long.'),
                    ],
                ],
                'second_options'  => [
                    'attr'  => ['autocomplete' => 'new-password'],
                    'label' => 'Repeat password',
                ],
                'mapped'          => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
```

- [ ] **Step 2: Build the public templates**

Create `templates/public/invitation/redeem.html.twig`:

```twig
{% extends 'public/_base.html.twig' %}

{% block title %}Accept invitation{% endblock %}

{% block body %}
<main class="mx-auto max-w-md p-8">
    <h1 class="mb-4 text-2xl font-semibold">You've been invited</h1>
    <p class="mb-6 text-sm text-base-content/70">Set up your account to continue.</p>

    {{ form_start(form) }}
        {{ form_row(form.email) }}
        {{ form_row(form.displayName) }}
        {{ form_row(form.password.first) }}
        {{ form_row(form.password.second) }}
        <button type="submit" class="btn btn-primary w-full">Create account</button>
    {{ form_end(form) }}
</main>
{% endblock %}
```

Create `templates/public/invitation/invalid.html.twig`:

```twig
{% extends 'public/_base.html.twig' %}

{% block title %}Invite invalid or expired{% endblock %}

{% block body %}
<main class="mx-auto max-w-md p-8 text-center" data-testid="invite-invalid">
    <h1 class="mb-4 text-2xl font-semibold">This invite link is invalid or has expired</h1>
    <p class="text-sm text-base-content/70">If you believe this is a mistake, ask the person who sent it to issue a new link.</p>
</main>
{% endblock %}
```

Create `templates/public/invitation/already_signed_in.html.twig`:

```twig
{% extends 'public/_base.html.twig' %}

{% block title %}Already signed in{% endblock %}

{% block body %}
<main class="mx-auto max-w-md p-8 text-center" data-testid="invite-already-signed-in">
    <h1 class="mb-4 text-2xl font-semibold">You're already signed in</h1>
    <p class="mb-4 text-sm text-base-content/70">
        You're signed in as <strong>{{ app.user.email }}</strong>.
        Sign out first if you want to redeem this invite for a new account.
    </p>
    <a href="{{ path('app_logout') }}" class="btn btn-outline">Sign out</a>
</main>
{% endblock %}
```

- [ ] **Step 3: Build the GET controller**

Create `src/Controller/Public/InvitationRedemptionController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Invitation;
use App\Form\InvitationRedeemType;
use App\Repository\InvitationRepository;
use App\Service\Invitation\InvitationTokenService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvitationRedemptionController extends AbstractController
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly InvitationTokenService $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/invite/{token}',
        name: 'public_invite_redeem',
        requirements: ['token' => '[a-f0-9]+\.[a-f0-9]+'],
        methods: ['GET'],
    )]
    public function show(string $token, Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->render('public/invitation/already_signed_in.html.twig');
        }

        $invite = $this->resolveValidInvite($token);
        if ($invite === null) {
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $form = $this->createForm(InvitationRedeemType::class);
        return $this->render('public/invitation/redeem.html.twig', [
            'form'  => $form,
            'token' => $token,
        ]);
    }

    private function resolveValidInvite(string $token): ?Invitation
    {
        $parsed = $this->tokens->parse($token);
        if ($parsed === null) {
            $this->logger->warning('invite.redeem_failed', ['reason' => 'malformed']);
            return null;
        }

        $invite = $this->invitations->findBySelector($parsed['selector']);
        if ($invite === null) {
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
```

- [ ] **Step 4: Write the GET-side functional tests**

Create `tests/Functional/Public/InvitationRedemptionFlowTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Invitation;
use App\Entity\User;
use App\Service\Invitation\GeneratedToken;
use App\Service\Invitation\InvitationTokenService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InvitationRedemptionFlowTest extends WebTestCase
{
    public function testAnonymousGetWithValidTokenRendersForm(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorTextContains('h1', "You've been invited");
    }

    public function testLoggedInUserSeesAlreadySignedInPage(): void
    {
        $browser = self::createClient();
        $existing = $this->ensureUserExists();
        $browser->loginUser($existing);

        [, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorExists('[data-testid="invite-already-signed-in"]');
    }

    public function testMalformedTokenRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        // Strict route requirement makes "not-hex" produce 404. Use a hex-but-untraceable token instead.
        $browser->request(Request::METHOD_GET, '/invite/' . str_repeat('a', 32) . '.' . str_repeat('b', 64));

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('[data-testid="invite-invalid"]');
    }

    public function testExpiredTokenRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [, $generated] = $this->createPendingInvite(expiresAt: new DateTimeImmutable('-1 minute'));

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('[data-testid="invite-invalid"]');
    }

    public function testRevokedTokenRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $admin = $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();
        $invite->revoke($admin);
        $this->em()->flush();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('[data-testid="invite-invalid"]');
    }

    public function testTamperedVerifierRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [, $generated] = $this->createPendingInvite();
        $tampered = $generated->selector . '.' . str_repeat('0', strlen($generated->plaintext) - strlen($generated->selector) - 1);

        $browser->request(Request::METHOD_GET, '/invite/' . $tampered);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('[data-testid="invite-invalid"]');
    }

    /**
     * @return array{Invitation, GeneratedToken}
     */
    private function createPendingInvite(?DateTimeImmutable $expiresAt = null): array
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationTokenService $tokens */
        $tokens = $container->get(InvitationTokenService::class);

        $admin = $this->ensureUserExists();
        $generated = $tokens->generate();

        $invite = new Invitation(
            selector: $generated->selector,
            hashedVerifier: $generated->hashedVerifier,
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: $expiresAt ?? new DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();

        return [$invite, $generated];
    }

    private function ensureUserExists(): User
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $existing = $em->getRepository(User::class)->findOneBy(['email' => 'admin-redeem@example.com']);
        if ($existing instanceof User) {
            return $existing;
        }

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('admin-redeem@example.com', 'AdminRedeem');
        $user->addRole('ROLE_ADMIN');
        $user->setPassword($hasher->hashPassword($user, 'correct horse battery'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        return $em;
    }
}
```

- [ ] **Step 5: Run the public functional tests**

Run: `vendor/bin/phpunit tests/Functional/Public/InvitationRedemptionFlowTest.php`

Expected: PASS. (We haven't implemented POST yet — these tests are GET-only.)

- [ ] **Step 6: GrumPHP**

Run: `vendor/bin/grumphp run`

Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Form/InvitationRedeemType.php src/Controller/Public/InvitationRedemptionController.php templates/public/invitation tests/Functional/Public/InvitationRedemptionFlowTest.php
git commit -m "31 - public invite GET, signup form, invalid + already-signed-in pages"
```

---

## Task 7: Public redemption — POST happy path, collision, pessimistic lock

**Files:**
- Modify: `src/Controller/Public/InvitationRedemptionController.php` (add POST action + helper)
- Modify: `tests/Functional/Public/InvitationRedemptionFlowTest.php` (POST tests)
- Create: `tests/Functional/Public/InvitationConcurrencyTest.php`

- [ ] **Step 1: Add the POST action**

Edit `src/Controller/Public/InvitationRedemptionController.php`. Add these imports:

```php
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
```

Add to the constructor:

```php
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly InvitationTokenService $tokens,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Security $security,
    ) {
    }
```

Add the POST action (place it after `show`):

```php
    #[Route(
        '/invite/{token}',
        name: 'public_invite_redeem_submit',
        requirements: ['token' => '[a-f0-9]+\.[a-f0-9]+'],
        methods: ['POST'],
    )]
    public function submit(string $token, Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->render('public/invitation/already_signed_in.html.twig');
        }

        $invite = $this->resolveValidInvite($token);
        if ($invite === null) {
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $form = $this->createForm(InvitationRedeemType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('public/invitation/redeem.html.twig', [
                'form'  => $form,
                'token' => $token,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        /** @var array{email: string, displayName: string} $data */
        $data = $form->getData();
        $email = $data['email'];
        $displayName = $data['displayName'];
        $plainPassword = $form->get('password')->getData();
        if (!is_string($plainPassword) || $plainPassword === '') {
            $form->get('password')->addError(new FormError('Password is required.'));
            return $this->render('public/invitation/redeem.html.twig', [
                'form'  => $form,
                'token' => $token,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if ($this->users->findOneByEmail($email) !== null) {
            $form->get('email')->addError(new FormError(
                'An account already exists for this email — sign in or reset your password.',
            ));
            return $this->render('public/invitation/redeem.html.twig', [
                'form'  => $form,
                'token' => $token,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $newUser = $this->em->wrapInTransaction(function () use ($invite, $email, $displayName, $plainPassword) {
            $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);

            // Re-check inside the lock: a concurrent redemption may have just landed.
            $this->em->refresh($invite);
            if (!$invite->isPending()) {
                return null;
            }

            $user = new \App\Entity\User($email, $displayName);
            $user->addRole($invite->getRole());
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $this->em->persist($user);
            $this->em->flush();

            $invite->markUsed($user, $email);
            $this->em->flush();

            return $user;
        });

        if (!$newUser instanceof \App\Entity\User) {
            $this->logger->warning('invite.redeem_failed', [
                'reason'    => 'race_lost',
                'invite_id' => $invite->getId(),
            ]);
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $this->logger->info('invite.redeemed', [
            'invite_id'     => $invite->getId(),
            'new_user_id'   => $newUser->getId(),
            'used_by_email' => $newUser->getEmail(),
        ]);

        $this->security->login($newUser, 'form_login', 'main');
        return new RedirectResponse($this->generateUrl('admin_dashboard'));
    }
```

`UserRepository::findOneByEmail` already exists in the project (see `Admin\UserController`). Confirm before relying on it: `grep -n 'findOneByEmail' src/Repository/UserRepository.php`. If it doesn't exist, add it to the repo with a one-liner: `return $this->findOneBy(['email' => $email]);`.

- [ ] **Step 2: Add POST tests to the redemption suite**

Edit `tests/Functional/Public/InvitationRedemptionFlowTest.php`. Add inside the class:

```php
    public function testPostHappyPathCreatesUserAndLogsIn(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        $browser->submitForm('Create account', [
            'invitation_redeem[email]'           => 'new-user@example.com',
            'invitation_redeem[displayName]'     => 'New User',
            'invitation_redeem[password][first]' => 'a-very-strong-passphrase',
            'invitation_redeem[password][second]' => 'a-very-strong-passphrase',
        ]);

        self::assertResponseRedirects('/admin');

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $created = $em->getRepository(User::class)->findOneBy(['email' => 'new-user@example.com']);
        self::assertNotNull($created);
        self::assertContains('ROLE_ORGANIZER', $created->getRoles());

        $em->refresh($invite);
        self::assertSame('used', $invite->status()->value);
        self::assertSame('new-user@example.com', $invite->getEmail());
        self::assertSame($created->getId(), $invite->getUsedBy()?->getId());
    }

    public function testPostEmailCollisionLeavesInvitePending(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists(); // creates admin-redeem@example.com

        [$invite, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        $browser->submitForm('Create account', [
            'invitation_redeem[email]'           => 'admin-redeem@example.com',
            'invitation_redeem[displayName]'     => 'Should Fail',
            'invitation_redeem[password][first]' => 'a-very-strong-passphrase',
            'invitation_redeem[password][second]' => 'a-very-strong-passphrase',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('body', 'An account already exists');

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->refresh($invite);
        self::assertSame('pending', $invite->status()->value);
    }

    public function testPostPasswordMismatchLeavesInvitePending(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        $browser->submitForm('Create account', [
            'invitation_redeem[email]'           => 'mismatch@example.com',
            'invitation_redeem[displayName]'     => 'Mismatch',
            'invitation_redeem[password][first]' => 'a-very-strong-passphrase',
            'invitation_redeem[password][second]' => 'something-else-entirely',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->refresh($invite);
        self::assertSame('pending', $invite->status()->value);
    }

    public function testPostSecondTimeRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();

        // First redemption (success).
        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        $browser->submitForm('Create account', [
            'invitation_redeem[email]'           => 'first@example.com',
            'invitation_redeem[displayName]'     => 'First',
            'invitation_redeem[password][first]' => 'a-very-strong-passphrase',
            'invitation_redeem[password][second]' => 'a-very-strong-passphrase',
        ]);
        self::assertResponseRedirects('/admin');

        // Second attempt — fresh browser, no session.
        $browser2 = self::createClient();
        $browser2->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }
```

- [ ] **Step 3: Build the concurrency test**

Create `tests/Functional/Public/InvitationConcurrencyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Invitation;
use App\Entity\User;
use App\Service\Invitation\InvitationTokenService;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Verifies pessimistic-lock behaviour without two real HTTP clients —
 * we drive two ORM transactions through the same logic the controller uses.
 *
 * NOTE: this test must NOT use dama auto-rollback (it commits real rows).
 */
final class InvitationConcurrencyTest extends KernelTestCase
{
    public function testTwoConcurrentRedemptionsProduceOneUser(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationTokenService $tokens */
        $tokens = $container->get(InvitationTokenService::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $admin = new User('concurrency-admin@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('hashed');
        $em->persist($admin);

        $gen = $tokens->generate();
        $invite = new Invitation(
            selector: $gen->selector,
            hashedVerifier: $gen->hashedVerifier,
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();
        $em->clear();

        $inviteId = $invite->getId();
        self::assertNotNull($inviteId);

        $redeem = function (string $email) use ($container, $hasher, $inviteId): ?int {
            /** @var EntityManagerInterface $em */
            $em = $container->get('doctrine')->getManager();
            $em->clear();

            return $em->wrapInTransaction(function () use ($em, $hasher, $inviteId, $email): ?int {
                $invite = $em->find(Invitation::class, $inviteId, LockMode::PESSIMISTIC_WRITE);
                if (!$invite instanceof Invitation || !$invite->isPending()) {
                    return null;
                }

                $user = new User($email, 'Concurrent');
                $user->addRole($invite->getRole());
                $user->setPassword($hasher->hashPassword($user, 'a-very-strong-passphrase'));
                $em->persist($user);
                $em->flush();

                $invite->markUsed($user, $email);
                $em->flush();

                return $user->getId();
            });
        };

        // Both calls run inline (single thread), but the in-DB row lock + re-check is
        // exactly the production-path mechanism. The first call wins; the second sees
        // isPending() === false and returns null.
        $userA = $redeem('concurrent-a@example.com');
        $userB = $redeem('concurrent-b@example.com');

        $winners = array_filter([$userA, $userB], static fn (?int $id): bool => $id !== null);
        self::assertCount(1, $winners, 'Exactly one redemption should succeed.');

        // Cleanup so we don't pollute the dev DB.
        /** @var EntityManagerInterface $cleanupEm */
        $cleanupEm = $container->get('doctrine')->getManager();
        $cleanupEm->clear();
        $cleanupInvite = $cleanupEm->find(Invitation::class, $inviteId);
        if ($cleanupInvite instanceof Invitation) {
            $cleanupEm->remove($cleanupInvite);
        }
        foreach (['concurrent-a@example.com', 'concurrent-b@example.com', 'concurrency-admin@example.com'] as $email) {
            $u = $cleanupEm->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($u instanceof User) {
                $cleanupEm->remove($u);
            }
        }
        $cleanupEm->flush();
    }
}
```

Caveat to surface: this concurrency test exercises the SAME-process two-transaction case (the second `wrapInTransaction` sees `isPending() === false` after the first commits). A true two-process race needs OS-level forking and isn't worth the cost. The mechanism this test guards (PESSIMISTIC_WRITE + re-check inside the lock) is the same one that protects the multi-process case in production.

- [ ] **Step 4: Run the public flow + concurrency tests**

Run: `vendor/bin/phpunit tests/Functional/Public/`

Expected: PASS, all of them.

- [ ] **Step 5: GrumPHP**

Run: `vendor/bin/grumphp run`

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Public/InvitationRedemptionController.php tests/Functional/Public/
git commit -m "31 - public invite POST: redemption, collision, pessimistic-lock concurrency"
```

---

## Task 8: Full suite + manual smoke

**Files:** none new; verification only.

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`

Expected: green across `tests/Unit`, `tests/Integration`, `tests/Functional`.

If any sibling test fails because the new nav link / new route changed behaviour (e.g. a snapshot of `/admin` HTML), fix the sibling — do NOT loosen the assertion if it was meaningful.

- [ ] **Step 2: Run the full GrumPHP gate**

Run: `vendor/bin/grumphp run`

Expected: every task passes — branch-name check, commit-message check (against staged HEAD), phpstan level 10, phpcs PSR-12, phpmnd, phpcpd, rector, doctrine:schema:validate, securitychecker_roave.

- [ ] **Step 3: Spin up the stack and smoke-test by hand**

Run:
```bash
docker compose up -d
bin/console doctrine:migrations:migrate --no-interaction
```

In a browser, sign in as a `ROLE_ADMIN` (use `bin/console app:create-user admin@example.com Admin <password> ROLE_ADMIN` if no admin exists). Then exercise:

1. Visit `/admin/invites` — empty table.
2. Click "+ New invite", submit with role=Organizer, days=7. You should land back on `/admin/invites` with a banner showing the full URL.
3. Refresh `/admin/invites`. Banner should be GONE.
4. Open the captured URL in an incognito window. Signup form renders.
5. Submit valid email/displayName/password. You're redirected to `/admin` and signed in as the new user.
6. Sign out. Open the same URL again. Generic "invalid or expired" page (HTTP 410).
7. As admin again, create another invite. Don't redeem it. Click "Revoke" on the row. Status flips to `Revoked`. Open that URL → invalid page.
8. As admin again, create an invite. While signed in, paste the URL → "already signed in" page.

If any of those don't match, fix the bug before declaring done.

- [ ] **Step 4: Open the pull request**

```bash
git push -u origin feature/31-single-use-signup-invites
gh pr create --title "31 - Single-use signup invitation links" --body "$(cat <<'EOF'
## Summary
- Admin can mint, view, and revoke single-use invite URLs with a baked role and configurable expiry.
- Recipients redeem at `/invite/{selector}.{verifier}` to create an account; tokens are stored as selector + sha256-hashed verifier (plaintext shown once on creation).
- Pessimistic row-lock + re-check guarantees exactly-once redemption under concurrent POSTs.
- Spec: `docs/superpowers/specs/2026-06-11-single-use-signup-invites-design.md`; closes #31.

## Test plan
- [ ] `vendor/bin/phpunit` green
- [ ] `vendor/bin/grumphp run` green
- [ ] Manual smoke per the plan's Task 8 step 3
EOF
)"
```

---

## Self-review (run mentally before handing off)

**Spec coverage check** — every acceptance criterion mapped to a task:

| Spec acceptance criterion | Where covered |
|---|---|
| Admin can create, view, revoke | Task 4 (create + index) + Task 5 (revoke) |
| GET shows form or friendly error, no PII leak | Task 6 (`show` + invalid template, identical body across invalid reasons) |
| POST creates user with baked role + logs in | Task 7 (`submit` + happy-path test) |
| Redeemable exactly once | Task 7 (`testPostSecondTimeRendersInvalidPage` + concurrency test) |
| Expired / revoked unredeemable | Task 6 (`testExpiredTokenRendersInvalidPage`, `testRevokedTokenRendersInvalidPage`) |
| Email collision: clear msg, invite stays redeemable | Task 7 (`testPostEmailCollisionLeavesInvitePending`) |
| Functional tests cover happy path, four invalidation modes, collision | Tasks 6 + 7 |

**Type / name consistency check:**
- `InvitationTokenService::generate()` → `GeneratedToken` with `plaintext`, `selector`, `hashedVerifier` properties. Used consistently in Tasks 4 and 7.
- `Invitation::status()` returns `InvitationStatus` enum; status string values referenced in templates (`pending|used|expired|revoked`) match the enum cases.
- Route names: `admin_invite_index`, `admin_invite_new`, `admin_invite_revoke`, `public_invite_redeem`, `public_invite_redeem_submit`. Cross-referenced in templates, controllers, and tests — all match.
- Form types: `InvitationCreateType` (admin), `InvitationRedeemType` (public). Form name `invitation_redeem` is the default Symfony name derived from the class name; verified in the submitForm field paths.
