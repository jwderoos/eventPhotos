# Admin User CRUD + First-Run Bootstrap — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `/admin/users` CRUD (ROLE_ADMIN-gated) and a one-shot `/setup` bootstrap that creates the first admin when the user table is empty. Reuses the existing password-reset machinery for inviting new users.

**Architecture:** Two coupled features in one feature branch. A `kernel.request` subscriber funnels traffic to `/setup` while user count is zero. Once any user exists, the subscriber short-circuits and `/setup` 404s. Admin CRUD follows the existing hand-rolled controller + voter + Twig pattern, never collects plaintext passwords for new users — it persists them with a random unusable hash and fires the same reset email used for forgotten-password.

**Tech Stack:** PHP 8.5 / Symfony 8 / Doctrine ORM 3 / Twig + AssetMapper / DaisyUI (`dracula` theme) / symfonycasts/reset-password-bundle / PHPUnit 13 + DAMA Doctrine Test Bundle.

---

## File Structure

**Create:**
- `src/Security/Voter/UserVoter.php`
- `src/EventSubscriber/FirstRunBootstrapSubscriber.php`
- `src/Controller/Public/SetupController.php`
- `src/Controller/Admin/UserController.php`
- `src/Form/SetupFormType.php`
- `src/Form/UserCreateType.php`
- `src/Form/UserEditType.php`
- `templates/setup/start.html.twig`
- `templates/admin/user/index.html.twig`
- `templates/admin/user/form.html.twig`
- `tests/Unit/Security/UserVoterTest.php`
- `tests/Integration/Repository/CountByOwnerTest.php`
- `tests/Functional/Setup/FirstRunBootstrapTest.php`
- `tests/Functional/Setup/SetupControllerTest.php`
- `tests/Functional/Admin/UserCrudTest.php`

**Modify:**
- `src/Repository/EventRepository.php` — add `countByOwner(User $owner): int`
- `src/Repository/EventCollectionRepository.php` — add `countByOwner(User $owner): int`
- `config/packages/security.yaml` — insert `^/admin/users` row above `^/admin`
- `templates/admin/_base.html.twig` — add "Users" sidebar entry (ROLE_ADMIN only)

No Doctrine migration: `User` already has every field we need.

---

## Conventions used by every task

- Branch is already `feature/16-admin-user-crud-bootstrap` (created out of `main`).
- Commit messages must contain `16 -` to satisfy `git_commit_message` (issue-number gate).
- Run tests on host: `vendor/bin/phpunit <path>`. Run `vendor/bin/grumphp run` after each task before committing.
- Form types are `final`. All new PHP files start with `<?php\n\ndeclare(strict_types=1);`.
- Voter signature includes the Symfony 8 trailing `?Vote $vote = null` — see `EventCollectionVoter` for the exact shape.

---

### Task 1: `UserVoter`

**Files:**
- Create: `src/Security/Voter/UserVoter.php`
- Test:   `tests/Unit/Security/UserVoterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Security/UserVoterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\Voter\UserVoter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class UserVoterTest extends TestCase
{
    public function testAdminCanViewAndEditOthers(): void
    {
        $admin  = $this->makeUser(1, 'admin@example.com');
        $target = $this->makeUser(2, 'target@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($admin);

        $this->assertSame(1, $voter->vote($token, $target, [UserVoter::VIEW]));
        $this->assertSame(1, $voter->vote($token, $target, [UserVoter::EDIT]));
    }

    public function testAdminCannotEditOwnRole(): void
    {
        $admin = $this->makeUser(1, 'admin@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($admin);

        $this->assertSame(-1, $voter->vote($token, $admin, [UserVoter::EDIT_ROLE]));
    }

    public function testAdminCannotDeleteSelf(): void
    {
        $admin = $this->makeUser(1, 'admin@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($admin);

        $this->assertSame(-1, $voter->vote($token, $admin, [UserVoter::DELETE]));
    }

    public function testAdminCanEditRoleAndDeleteOtherAdmin(): void
    {
        $admin = $this->makeUser(1, 'admin@example.com');
        $other = $this->makeUser(2, 'other@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($admin);

        $this->assertSame(1, $voter->vote($token, $other, [UserVoter::EDIT_ROLE]));
        $this->assertSame(1, $voter->vote($token, $other, [UserVoter::DELETE]));
    }

    public function testOrganizerDeniedForEveryAttribute(): void
    {
        $organizer = $this->makeUser(1, 'org@example.com');
        $target    = $this->makeUser(2, 'target@example.com');

        $voter = new UserVoter($this->securityWithAdmin(false));
        $token = $this->tokenFor($organizer);

        foreach ([UserVoter::VIEW, UserVoter::EDIT, UserVoter::EDIT_ROLE, UserVoter::DELETE] as $attr) {
            $this->assertSame(-1, $voter->vote($token, $target, [$attr]), $attr);
        }
    }

    public function testUnsupportedAttributeAbstains(): void
    {
        $user = $this->makeUser(1, 'a@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($user);

        $this->assertSame(0, $voter->vote($token, $user, ['SOMETHING_ELSE']));
    }

    private function makeUser(int $id, string $email): User
    {
        $user = new User($email, 'Display');
        $reflection = new ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);
        return $user;
    }

    private function securityWithAdmin(bool $isAdmin): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, $isAdmin]]);
        return $security;
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        return $token;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Security/UserVoterTest.php`
Expected: FAIL — `App\Security\Voter\UserVoter` does not exist.

- [ ] **Step 3: Implement `UserVoter`**

Create `src/Security/Voter/UserVoter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, User>
 */
final class UserVoter extends Voter
{
    public const string VIEW      = 'USER_VIEW';

    public const string EDIT      = 'USER_EDIT';

    public const string EDIT_ROLE = 'USER_EDIT_ROLE';

    public const string DELETE    = 'USER_DELETE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::EDIT_ROLE, self::DELETE], true)
            && $subject instanceof User;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $current = $token->getUser();

        if (!$current instanceof User) {
            return false;
        }

        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return false;
        }

        if (!$subject instanceof User) {
            return false;
        }

        if ($attribute === self::EDIT_ROLE || $attribute === self::DELETE) {
            return $subject->getId() !== $current->getId();
        }

        return true;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Security/UserVoterTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Lint + commit**

Run: `vendor/bin/grumphp run`
Expected: green.

```bash
git add src/Security/Voter/UserVoter.php tests/Unit/Security/UserVoterTest.php
git commit -m "16 - UserVoter with self-edit-role and self-delete guards"
```

---

### Task 2: `countByOwner` on EventRepository + EventCollectionRepository

**Files:**
- Modify: `src/Repository/EventRepository.php`
- Modify: `src/Repository/EventCollectionRepository.php`
- Test:   `tests/Integration/Repository/CountByOwnerTest.php`

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/Repository/CountByOwnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\User;
use App\Repository\EventCollectionRepository;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CountByOwnerTest extends KernelTestCase
{
    public function testCountsEventsAndCollectionsByOwner(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var EventRepository $events */
        $events = $container->get(EventRepository::class);
        /** @var EventCollectionRepository $collections */
        $collections = $container->get(EventCollectionRepository::class);

        $owner   = new User('owner@example.com', 'Owner');
        $someone = new User('someone@example.com', 'Someone');
        $em->persist($owner);
        $em->persist($someone);

        $em->persist(new Event('e1', 'E1', new DateTimeImmutable('2026-07-01'), $owner));
        $em->persist(new Event('e2', 'E2', new DateTimeImmutable('2026-07-02'), $owner));
        $em->persist(new Event('e3', 'E3', new DateTimeImmutable('2026-07-03'), $someone));
        $em->persist(new EventCollection('c1', 'C1', $owner));
        $em->flush();

        $this->assertSame(2, $events->countByOwner($owner));
        $this->assertSame(1, $events->countByOwner($someone));
        $this->assertSame(1, $collections->countByOwner($owner));
        $this->assertSame(0, $collections->countByOwner($someone));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Repository/CountByOwnerTest.php`
Expected: FAIL — `countByOwner` method does not exist on either repository.

- [ ] **Step 3: Implement on `EventRepository`**

Modify `src/Repository/EventRepository.php` — append inside the class:

```php
    public function countByOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }
```

Add `use App\Entity\User;` to the top of the file if not already present.

- [ ] **Step 4: Implement on `EventCollectionRepository`**

Modify `src/Repository/EventCollectionRepository.php` — append inside the class:

```php
    public function countByOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }
```

Add `use App\Entity\User;` to the top of the file if not already present.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Repository/CountByOwnerTest.php`
Expected: PASS.

- [ ] **Step 6: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/Repository/EventRepository.php src/Repository/EventCollectionRepository.php tests/Integration/Repository/CountByOwnerTest.php
git commit -m "16 - countByOwner helpers on Event and EventCollection repositories"
```

---

### Task 3: `FirstRunBootstrapSubscriber`

**Files:**
- Create: `src/EventSubscriber/FirstRunBootstrapSubscriber.php`
- Test:   `tests/Functional/Setup/FirstRunBootstrapTest.php`

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Setup/FirstRunBootstrapTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FirstRunBootstrapTest extends WebTestCase
{
    public function testZeroUsersRedirectsLoginToSetup(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/login');
        self::assertResponseRedirects('/setup');
    }

    public function testZeroUsersRedirectsAdminToSetup(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseRedirects('/setup');
    }

    public function testZeroUsersRedirectsRootToSetup(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');
        self::assertResponseRedirects('/setup');
    }

    public function testSetupItselfIsNotRedirected(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/setup');
        // 404 is fine here (SetupController not built yet); the assertion is "no redirect".
        $this->assertFalse(
            $client->getResponse()->isRedirect('/setup'),
            'GET /setup must never redirect to itself',
        );
    }

    public function testAfterFirstUserLoginIsNoLongerRedirected(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $admin = new User('admin@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword($hasher->hashPassword($admin, 'irrelevant for test'));
        $em->persist($admin);
        $em->flush();

        $client->request(Request::METHOD_GET, '/login');
        self::assertResponseIsSuccessful();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Setup/FirstRunBootstrapTest.php`
Expected: FAIL — current `/login` returns 200 (not a redirect) when DB is empty.

- [ ] **Step 3: Implement the subscriber**

Create `src/EventSubscriber/FirstRunBootstrapSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class FirstRunBootstrapSubscriber implements EventSubscriberInterface
{
    private const int LISTENER_PRIORITY = 32;

    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', self::LISTENER_PRIORITY]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ($path === '/setup' || str_starts_with($path, '/_')) {
            return;
        }

        if ($this->users->count([]) > 0) {
            return;
        }

        $event->setResponse(new RedirectResponse('/setup'));
    }
}
```

Symfony's `autoconfigure: true` (default in `config/services.yaml`) auto-tags any `EventSubscriberInterface` implementation with `kernel.event_subscriber` — no manual service config needed.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Setup/FirstRunBootstrapTest.php`
Expected: PASS, 5 tests. The "after first user" test confirms the subscriber stops redirecting once a row exists.

- [ ] **Step 5: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/EventSubscriber/FirstRunBootstrapSubscriber.php tests/Functional/Setup/FirstRunBootstrapTest.php
git commit -m "16 - kernel.request subscriber redirects to /setup when no users exist"
```

---

### Task 4: `SetupController` + `SetupFormType` + template

**Files:**
- Create: `src/Controller/Public/SetupController.php`
- Create: `src/Form/SetupFormType.php`
- Create: `templates/setup/start.html.twig`
- Test:   `tests/Functional/Setup/SetupControllerTest.php`

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Setup/SetupControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SetupControllerTest extends WebTestCase
{
    public function testGetSetupRendersFormWhenEmpty(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/setup');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="setup_form[email]"]');
    }

    public function testPostSetupCreatesFirstAdminAndLogsThemIn(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);

        $this->assertSame(0, $users->count([]));

        $client->request(Request::METHOD_GET, '/setup');
        $client->submitForm('Create admin account', [
            'setup_form[email]'                    => 'first.admin@example.com',
            'setup_form[displayName]'              => 'First Admin',
            'setup_form[plainPassword][first]'     => 'a strong password 1',
            'setup_form[plainPassword][second]'    => 'a strong password 1',
        ]);

        self::assertResponseRedirects('/admin');

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();

        $created = $users->findOneByEmail('first.admin@example.com');
        $this->assertNotNull($created);
        $this->assertContains('ROLE_ADMIN', $created->getRoles());

        // Verify the redirect lands somewhere authenticated (auto-login worked).
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testSetupIs404OnceAUserExists(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $existing = new User('existing@example.com', 'Existing');
        $existing->addRole('ROLE_ADMIN');
        $existing->setPassword($hasher->hashPassword($existing, 'whatever'));
        $em->persist($existing);
        $em->flush();

        $client->request(Request::METHOD_GET, '/setup');
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Setup/SetupControllerTest.php`
Expected: FAIL — `/setup` returns 404 (no controller).

- [ ] **Step 3: Create `SetupFormType`**

Create `src/Form/SetupFormType.php`:

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
final class SetupFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'constraints' => [
                    new NotBlank(message: 'Please enter an email address.'),
                    new Email(),
                ],
            ])
            ->add('displayName', TextType::class, [
                'label'       => 'Display name',
                'constraints' => [new NotBlank(message: 'Please enter a display name.')],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'invalid_message' => 'The password fields must match.',
                'first_options'   => [
                    'label'       => 'Password',
                    'attr'        => ['autocomplete' => 'new-password'],
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password.'),
                        new Length(min: 12, minMessage: 'Your password must be at least 12 characters long.'),
                    ],
                ],
                'second_options' => [
                    'label' => 'Repeat password',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
```

- [ ] **Step 4: Create `SetupController`**

Create `src/Controller/Public/SetupController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\User;
use App\Form\SetupFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SetupController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Security $security,
    ) {
    }

    #[Route('/setup', name: 'app_setup', methods: ['GET', 'POST'])]
    public function start(Request $request): Response
    {
        if ($this->users->count([]) > 0) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(SetupFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, displayName: string} $data */
            $data = $form->getData();
            /** @var string $plain */
            $plain = $form->get('plainPassword')->getData();

            $admin = new User($data['email'], $data['displayName']);
            $admin->addRole('ROLE_ADMIN');
            $admin->setPassword($this->passwordHasher->hashPassword($admin, $plain));
            $this->em->persist($admin);
            $this->em->flush();

            $this->security->login($admin, 'form_login', 'main');

            return new RedirectResponse('/admin');
        }

        return $this->render('setup/start.html.twig', ['form' => $form]);
    }
}
```

- [ ] **Step 5: Create the template**

Create `templates/setup/start.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block html_attributes %}data-theme="dracula"{% endblock %}

{% block title %}Set up eventFotos{% endblock %}

{% block body %}
    <main class="flex min-h-screen items-center justify-center bg-base-200 p-4">
        <div class="card w-full max-w-md bg-base-100 shadow-xl">
            <div class="card-body">
                <h1 class="card-title">Create the first admin</h1>
                <p class="text-sm text-base-content/70">
                    No users exist yet. Create your admin account to finish setting up eventFotos.
                </p>

                {{ form_start(form) }}
                    <div class="grid gap-4">
                        {{ form_row(form.email) }}
                        {{ form_row(form.displayName) }}
                        {{ form_row(form.plainPassword.first) }}
                        {{ form_row(form.plainPassword.second) }}
                    </div>
                    <div class="card-actions mt-6 justify-end">
                        <button type="submit" class="btn btn-primary">Create admin account</button>
                    </div>
                {{ form_end(form) }}
            </div>
        </div>
    </main>
{% endblock %}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Setup/SetupControllerTest.php tests/Functional/Setup/FirstRunBootstrapTest.php`
Expected: PASS — all 8 tests across both files.

- [ ] **Step 7: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/Controller/Public/SetupController.php src/Form/SetupFormType.php templates/setup/start.html.twig tests/Functional/Setup/SetupControllerTest.php
git commit -m "16 - /setup one-shot form creates first admin and logs them in"
```

---

### Task 5: Security access_control rule for `/admin/users`

**Files:**
- Modify: `config/packages/security.yaml`

This task lays the groundwork for `/admin/users` access control before the controller exists. No new test — gating is exercised in Task 7's controller test. The sidebar nav entry is intentionally **deferred to Task 7** (it requires the `admin_user_index` route to exist at template render time).

- [ ] **Step 1: Add `/admin/users` to `access_control`**

Modify `config/packages/security.yaml`. Insert the new rows **above** the existing `^/admin` row:

```yaml
    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/reset-password, roles: PUBLIC_ACCESS }
        - { path: ^/setup, roles: PUBLIC_ACCESS }
        - { path: ^/admin/users, roles: ROLE_ADMIN }
        - { path: ^/admin, roles: ROLE_ORGANIZER }
        - { path: ^/, roles: PUBLIC_ACCESS }
```

(The `^/setup` row is also new — keeps the firewall consistent for a hypothetical logged-out visit; the subscriber redirect runs at higher priority and is the actual gate.)

- [ ] **Step 2: Sanity-check the cache rebuilds**

Run: `bin/console cache:clear --env=test`
Expected: success.

- [ ] **Step 3: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add config/packages/security.yaml
git commit -m "16 - access_control rule gating /admin/users to ROLE_ADMIN"
```

---

### Task 6: `UserCreateType` + `UserEditType`

**Files:**
- Create: `src/Form/UserCreateType.php`
- Create: `src/Form/UserEditType.php`

The two forms are very similar but kept distinct so the controller doesn't need conditional `email` field rendering. Both expose role as a `ChoiceType` radio mapping to a single role string. The choice values are role strings (`ROLE_USER`, `ROLE_ORGANIZER`, `ROLE_ADMIN`) so the controller can call `addRole($form->get('role')->getData())` directly.

No test in this task — forms are exercised through the controller tests in Tasks 7–10.

- [ ] **Step 1: Create `UserCreateType`**

Create `src/Form/UserCreateType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class UserCreateType extends AbstractType
{
    public const array ROLE_CHOICES = [
        'User'      => 'ROLE_USER',
        'Organizer' => 'ROLE_ORGANIZER',
        'Admin'     => 'ROLE_ADMIN',
    ];

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
            ->add('role', ChoiceType::class, [
                'label'       => 'Role',
                'choices'     => self::ROLE_CHOICES,
                'expanded'    => true,
                'multiple'    => false,
                'data'        => 'ROLE_ORGANIZER',
                'constraints' => [new NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
```

We deliberately use `data_class => null` (not `User::class`) because we want the controller to construct the `User` object — `User::__construct` is non-trivial (email-non-empty invariant), and the form data is just a plain array.

- [ ] **Step 2: Create `UserEditType`**

Create `src/Form/UserEditType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class UserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label'       => 'Display name',
                'constraints' => [new NotBlank()],
            ]);

        if ($options['can_edit_role'] === true) {
            $builder->add('role', ChoiceType::class, [
                'label'    => 'Role',
                'choices'  => UserCreateType::ROLE_CHOICES,
                'expanded' => true,
                'multiple' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => null, 'can_edit_role' => true])
            ->setAllowedTypes('can_edit_role', 'bool');
    }
}
```

The `can_edit_role` option lets the controller hide the role field entirely when the admin is editing themselves (Task 9's self-edit case).

- [ ] **Step 3: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/Form/UserCreateType.php src/Form/UserEditType.php
git commit -m "16 - UserCreateType and UserEditType with role radios"
```

---

### Task 7: `UserController::index` + list template + sidebar nav

**Files:**
- Create: `src/Controller/Admin/UserController.php` (index action only — other actions added in later tasks)
- Create: `templates/admin/user/index.html.twig`
- Modify: `templates/admin/_base.html.twig`
- Test:   `tests/Functional/Admin/UserCrudTest.php` (index scenarios only — extended later)

- [ ] **Step 1: Write the failing index tests**

Create `tests/Functional/Admin/UserCrudTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserCrudTest extends WebTestCase
{
    public function testAdminCanReachUserIndex(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Users');
    }

    public function testOrganizerIsForbiddenFromUserIndex(): void
    {
        $client = self::createClient();
        $org    = $this->seedUser($client, 'org@example.com', 'Org', ['ROLE_ORGANIZER']);
        $client->loginUser($org);

        $client->request(Request::METHOD_GET, '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexListsKnownUsers(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $this->seedUser($client, 'other@example.com', 'Other', ['ROLE_ORGANIZER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'admin@example.com');
        self::assertSelectorTextContains('table', 'other@example.com');
    }

    /** @param list<string> $roles */
    private function seedUser(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $email,
        string $displayName,
        array $roles,
    ): User {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User($email, $displayName);
        foreach ($roles as $role) {
            $user->addRole($role);
        }
        $user->setPassword($hasher->hashPassword($user, 'placeholder placeholder'));
        $em->persist($user);
        $em->flush();
        return $user;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php`
Expected: FAIL — `/admin/users` returns 404.

- [ ] **Step 3: Create `UserController` with index action**

Create `src/Controller/Admin/UserController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    #[Route('/admin/users', name: 'admin_user_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/user/index.html.twig', [
            'users' => $this->users->findBy([], ['email' => 'ASC']),
        ]);
    }
}
```

- [ ] **Step 4: Create the list template (minimal — actions added in later tasks)**

Create `templates/admin/user/index.html.twig`:

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — Users{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li>Users</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Users</h1>
    </header>

    {% set roleBadgeMap = {
        'ROLE_ADMIN':     'badge-error',
        'ROLE_ORGANIZER': 'badge-info',
        'ROLE_USER':      'badge-ghost',
    } %}

    <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
        <table class="table table-zebra">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Display name</th>
                    <th>Role</th>
                </tr>
            </thead>
            <tbody>
                {% for user in users %}
                    {% set topRole = 'ROLE_ADMIN' in user.roles ? 'ROLE_ADMIN'
                                   : ('ROLE_ORGANIZER' in user.roles ? 'ROLE_ORGANIZER' : 'ROLE_USER') %}
                    <tr>
                        <td><code class="text-xs">{{ user.email }}</code></td>
                        <td>{{ user.displayName }}</td>
                        <td>
                            <span class="badge {{ roleBadgeMap[topRole] }}">
                                {{ topRole|replace({'ROLE_': ''})|capitalize }}
                            </span>
                        </td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
{% endblock %}
```

Action buttons (New user / Edit / Delete) are added in Tasks 8, 9, and 10 respectively — keeping the template forward-reference-free at each commit boundary so `path()` never resolves a route that doesn't exist.

- [ ] **Step 5: Add the sidebar entry to the admin shell**

Modify `templates/admin/_base.html.twig`. Inside the `<ul class="menu menu-md px-2">` block, after the Collections `<li>` (currently lines 86–91), add:

```twig
                        {% if is_granted('ROLE_ADMIN') %}
                            <li>
                                <a href="{{ path('admin_user_index') }}"
                                   class="{{ route starts with 'admin_user_' ? 'active' : '' }}">
                                    Users
                                </a>
                            </li>
                        {% endif %}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/Controller/Admin/UserController.php templates/admin/user/index.html.twig templates/admin/_base.html.twig tests/Functional/Admin/UserCrudTest.php
git commit -m "16 - /admin/users index lists all users for ROLE_ADMIN"
```

---

### Task 8: `UserController::new` (create user + auto-send reset email)

**Files:**
- Modify: `src/Controller/Admin/UserController.php`
- Create: `templates/admin/user/form.html.twig`
- Modify: `tests/Functional/Admin/UserCrudTest.php`

- [ ] **Step 1: Append failing tests to `UserCrudTest`**

Add to `tests/Functional/Admin/UserCrudTest.php`:

```php
    public function testAdminCanCreateUserAndResetEmailIsSent(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/new');
        $client->submitForm('Create', [
            'user_create[email]'       => 'new.user@example.com',
            'user_create[displayName]' => 'New User',
            'user_create[role]'        => 'ROLE_ORGANIZER',
        ]);

        self::assertResponseRedirects('/admin/users');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        $this->assertNotNull($email);
        $this->assertSame('new.user@example.com', $email->getTo()[0]->getAddress());

        $container = self::getContainer();
        /** @var \App\Repository\UserRepository $users */
        $users = $container->get(\App\Repository\UserRepository::class);
        $created = $users->findOneByEmail('new.user@example.com');
        $this->assertNotNull($created);
        $this->assertContains('ROLE_ORGANIZER', $created->getRoles());
        $this->assertNotEmpty($created->getPassword(), 'random unusable hash should still occupy the column');
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $this->seedUser($client, 'taken@example.com', 'Taken', ['ROLE_USER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/new');
        $client->submitForm('Create', [
            'user_create[email]'       => 'taken@example.com',
            'user_create[displayName]' => 'Dup',
            'user_create[role]'        => 'ROLE_ORGANIZER',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }
```

(Imports `\App\Repository\UserRepository` inline to avoid editing the existing `use` block — refactor later if you add many more tests.)

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php --filter='testAdminCanCreateUserAndResetEmailIsSent|testDuplicateEmailIsRejected'`
Expected: FAIL — `/admin/users/new` returns 404.

- [ ] **Step 3: Add `new` action to `UserController`**

Modify `src/Controller/Admin/UserController.php`. Update imports and replace the file's body with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserCreateType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly MailerInterface $mailer,
    ) {
    }

    #[Route('/admin/users', name: 'admin_user_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/user/index.html.twig', [
            'users' => $this->users->findBy([], ['email' => 'ASC']),
        ]);
    }

    #[Route('/admin/users/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(UserCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, displayName: string, role: string} $data */
            $data = $form->getData();

            if ($this->users->findOneByEmail($data['email']) !== null) {
                $form->get('email')->addError(new FormError('A user with that email already exists.'));
            } else {
                $user = new User($data['email'], $data['displayName']);
                $user->addRole($data['role']);
                $user->setPassword(
                    $this->passwordHasher->hashPassword($user, bin2hex(random_bytes(16))),
                );
                $this->em->persist($user);
                $this->em->flush();

                $this->sendInviteEmail($user);

                $this->addFlash(
                    'success',
                    sprintf('User created. Reset email sent to %s.', $user->getEmail()),
                );
                return new RedirectResponse('/admin/users');
            }
        }

        $status = $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'mode' => 'new',
        ], new Response(null, $status));
    }

    private function sendInviteEmail(User $user): void
    {
        try {
            $token = $this->resetPasswordHelper->generateResetToken($user);
        } catch (TooManyPasswordRequestsException | ResetPasswordExceptionInterface) {
            $this->addFlash('warning', sprintf(
                'User created but a reset email could not be issued. Use "Resend reset" on the edit page.',
            ));
            return;
        }

        $email = new TemplatedEmail()
            ->from(new Address('no-reply@eventfotos.local', 'eventFotos'))
            ->to($user->getEmail())
            ->subject('Set your eventFotos password')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context(['user' => $user, 'resetToken' => $token]);

        $this->mailer->send($email);
    }
}
```

Note: the duplicate-email check is done in the controller (not via `UniqueEntity` constraint) because `UserCreateType` has `data_class => null`, so attribute-based entity validation does not run. The DB-level `uniq_users_email` constraint remains the backstop.

- [ ] **Step 4: Create the shared form template (no send-reset button yet — added in Task 11)**

Create `templates/admin/user/form.html.twig`:

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}
    Admin — {{ mode == 'new' ? 'New user' : 'Edit ' ~ (target_email|default('user')) }}
{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li><a href="{{ path('admin_user_index') }}" class="link link-hover">Users</a></li>
            <li>{{ mode == 'new' ? 'New' : 'Edit' }}</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6">
        <h1 class="text-2xl font-semibold">
            {{ mode == 'new' ? 'New user' : 'Edit ' ~ (target_email|default('user')) }}
        </h1>
        {% if mode == 'edit' and target_email is defined %}
            <p class="mt-1 text-sm text-base-content/70">
                Email: <code>{{ target_email }}</code> (read-only)
            </p>
        {% endif %}
    </header>

    {{ form_start(form, {attr: {id: 'user-form', class: 'card bg-base-100 shadow-sm'}}) }}
        <div class="card-body grid gap-4 lg:grid-cols-2">
            {{ form_widget(form) }}
        </div>
    {{ form_end(form, {render_rest: false}) }}
{% endblock %}

{% block admin_actions %}
    <a href="{{ path('admin_user_index') }}" class="btn btn-ghost">Cancel</a>
    <button type="submit" form="user-form" class="btn btn-primary">
        {{ mode == 'new' ? 'Create' : 'Save' }}
    </button>
{% endblock %}
```

- [ ] **Step 4b: Add the "+ New user" link to the index template**

Modify `templates/admin/user/index.html.twig`. Replace the `<header>` block:

```twig
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Users</h1>
        <a href="{{ path('admin_user_new') }}" class="btn btn-primary btn-sm">+ New user</a>
    </header>
```

- [ ] **Step 5: Run the new tests**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php`
Expected: PASS, 5 tests total.

- [ ] **Step 6: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/Controller/Admin/UserController.php templates/admin/user/form.html.twig tests/Functional/Admin/UserCrudTest.php
git commit -m "16 - admin can create new users; reset email sent automatically"
```

---

### Task 9: `UserController::edit` (with self-role-edit guard)

**Files:**
- Modify: `src/Controller/Admin/UserController.php`
- Modify: `templates/admin/user/index.html.twig`
- Modify: `tests/Functional/Admin/UserCrudTest.php`

- [ ] **Step 1: Append failing tests to `UserCrudTest`**

Add to `tests/Functional/Admin/UserCrudTest.php`:

```php
    public function testAdminCanEditOtherUserDisplayNameAndRole(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $other  = $this->seedUser($client, 'other@example.com', 'Other', ['ROLE_USER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/' . $other->getId() . '/edit');
        $client->submitForm('Save', [
            'user_edit[displayName]' => 'Renamed',
            'user_edit[role]'        => 'ROLE_ORGANIZER',
        ]);

        self::assertResponseRedirects('/admin/users');

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        /** @var \App\Repository\UserRepository $users */
        $users = $container->get(\App\Repository\UserRepository::class);
        $reloaded = $users->findOneByEmail('other@example.com');
        $this->assertNotNull($reloaded);
        $this->assertSame('Renamed', $reloaded->getDisplayName());
        $this->assertContains('ROLE_ORGANIZER', $reloaded->getRoles());
        // Note: $user->getRoles() auto-appends ROLE_USER; can't assert its absence here.
    }

    public function testAdminEditingSelfHasNoRoleField(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/users/' . $admin->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('input[name="user_edit[role]"]'));
    }

    public function testAdminEditingSelfCanRenameSelfButNotChangeOwnRole(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/' . $admin->getId() . '/edit');
        $client->submitForm('Save', [
            'user_edit[displayName]' => 'Renamed Admin',
        ]);
        self::assertResponseRedirects('/admin/users');

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        /** @var \App\Repository\UserRepository $users */
        $users = $container->get(\App\Repository\UserRepository::class);
        $reloaded = $users->findOneByEmail('admin@example.com');
        $this->assertNotNull($reloaded);
        $this->assertSame('Renamed Admin', $reloaded->getDisplayName());
        $this->assertContains('ROLE_ADMIN', $reloaded->getRoles(), 'self-edit must not be able to demote self');
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php --filter='testAdminCanEditOtherUserDisplayNameAndRole|testAdminEditingSelfHasNoRoleField|testAdminEditingSelfCanRenameSelfButNotChangeOwnRole'`
Expected: FAIL — `/admin/users/{id}/edit` returns 404.

- [ ] **Step 3: Add `edit` action**

Modify `src/Controller/Admin/UserController.php`. Add the `UserVoter` import and the `UserEditType` import at the top:

```php
use App\Form\UserEditType;
use App\Security\Voter\UserVoter;
```

Then append the action below `new`:

```php
    #[Route(
        '/admin/users/{id}/edit',
        name: 'admin_user_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(User $target, Request $request): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $target);

        $canEditRole = $this->isGranted(UserVoter::EDIT_ROLE, $target);

        $currentTopRole = $this->topRole($target);
        $form = $this->createForm(UserEditType::class, [
            'displayName' => $target->getDisplayName(),
            'role'        => $currentTopRole,
        ], ['can_edit_role' => $canEditRole]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, role?: string} $data */
            $data = $form->getData();

            $target->setDisplayName($data['displayName']);

            if ($canEditRole && isset($data['role']) && $data['role'] !== $currentTopRole) {
                foreach (['ROLE_ADMIN', 'ROLE_ORGANIZER', 'ROLE_USER'] as $roleToClear) {
                    $target->removeRole($roleToClear);
                }
                $target->addRole($data['role']);
            }

            $this->em->flush();
            $this->addFlash('success', 'User updated.');
            return new RedirectResponse('/admin/users');
        }

        $status = $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        return $this->render('admin/user/form.html.twig', [
            'form'         => $form,
            'mode'         => 'edit',
            'target_id'    => $target->getId(),
            'target_email' => $target->getEmail(),
        ], new Response(null, $status));
    }

    private function topRole(User $user): string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return 'ROLE_ADMIN';
        }
        if (in_array('ROLE_ORGANIZER', $roles, true)) {
            return 'ROLE_ORGANIZER';
        }
        return 'ROLE_USER';
    }
```

- [ ] **Step 4: Add the "Edit" link to each index row**

Modify `templates/admin/user/index.html.twig`. Add a `<th class="text-right">Actions</th>` column to the table head, and append a cell to each row:

```twig
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Display name</th>
                    <th>Role</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
```

Inside the `{% for user in users %}` row, append after the role cell:

```twig
                        <td class="text-right">
                            <div class="inline-flex items-center gap-1">
                                <a href="{{ path('admin_user_edit', {id: user.id}) }}"
                                   class="btn btn-ghost btn-xs">Edit</a>
                            </div>
                        </td>
```

(The Delete form is added to this same `<div>` in Task 10.)

- [ ] **Step 5: Run the new tests**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php`
Expected: PASS, 8 tests total.

- [ ] **Step 6: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/Controller/Admin/UserController.php templates/admin/user/index.html.twig tests/Functional/Admin/UserCrudTest.php
git commit -m "16 - admin can edit user display name and role; self-role-edit blocked"
```

---

### Task 10: `UserController::delete` (with owned-content guard + self-delete guard)

**Files:**
- Modify: `src/Controller/Admin/UserController.php`
- Modify: `templates/admin/user/index.html.twig`
- Modify: `tests/Functional/Admin/UserCrudTest.php`

- [ ] **Step 1: Append failing tests**

Add to `tests/Functional/Admin/UserCrudTest.php`:

```php
    public function testAdminCanDeleteUserWithNoOwnedContent(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $target = $this->seedUser($client, 'target@example.com', 'Target', ['ROLE_USER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, '/admin/users/' . $target->getId() . '/delete', [
            '_token' => $this->csrf($client, 'delete_user_' . $target->getId()),
        ]);

        self::assertResponseRedirects('/admin/users');
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        /** @var \App\Repository\UserRepository $users */
        $users = $container->get(\App\Repository\UserRepository::class);
        $this->assertNull($users->findOneByEmail('target@example.com'));
    }

    public function testDeleteBlockedWhenUserOwnsEvents(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $owner  = $this->seedUser($client, 'owner@example.com', 'Owner', ['ROLE_ORGANIZER']);

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new \App\Entity\Event('o-1', 'Owned', new \DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_POST, '/admin/users/' . $owner->getId() . '/delete', [
            '_token' => $this->csrf($client, 'delete_user_' . $owner->getId()),
        ]);

        self::assertResponseRedirects('/admin/users/' . $owner->getId() . '/edit');
        $em->clear();
        /** @var \App\Repository\UserRepository $users */
        $users = $container->get(\App\Repository\UserRepository::class);
        $this->assertNotNull($users->findOneByEmail('owner@example.com'), 'user must still exist');
    }

    public function testAdminCannotDeleteSelf(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, '/admin/users/' . $admin->getId() . '/delete', [
            '_token' => $this->csrf($client, 'delete_user_' . $admin->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function csrf(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $id): string
    {
        $container = self::getContainer();
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $manager */
        $manager = $container->get('security.csrf.token_manager');
        return $manager->getToken($id)->getValue();
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php --filter='testAdminCanDeleteUserWithNoOwnedContent|testDeleteBlockedWhenUserOwnsEvents|testAdminCannotDeleteSelf'`
Expected: FAIL — `/admin/users/{id}/delete` returns 404.

- [ ] **Step 3: Add `delete` action**

Modify `src/Controller/Admin/UserController.php`. Add to constructor signature:

```php
        private readonly \App\Repository\EventRepository $events,
        private readonly \App\Repository\EventCollectionRepository $collections,
```

Append the action:

```php
    #[Route(
        '/admin/users/{id}/delete',
        name: 'admin_user_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(User $target, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(UserVoter::DELETE, $target);

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('delete_user_' . $target->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ownedEvents      = $this->events->countByOwner($target);
        $ownedCollections = $this->collections->countByOwner($target);
        if ($ownedEvents + $ownedCollections > 0) {
            $this->addFlash('error', sprintf(
                'Cannot delete — %s owns %d event(s) and %d collection(s). Reassign or delete them first.',
                $target->getEmail(),
                $ownedEvents,
                $ownedCollections,
            ));
            return new RedirectResponse('/admin/users/' . $target->getId() . '/edit');
        }

        $this->em->remove($target);
        $this->em->flush();
        $this->addFlash('success', 'User deleted.');

        return new RedirectResponse('/admin/users');
    }
```

- [ ] **Step 4: Add the Delete form to each index row**

Modify `templates/admin/user/index.html.twig`. Inside the `<div class="inline-flex items-center gap-1">` (added in Task 9), append after the Edit `<a>`:

```twig
                                {% if user.id != app.user.id %}
                                    <form method="post"
                                          action="{{ path('admin_user_delete', {id: user.id}) }}"
                                          onsubmit="return confirm('Delete this user?')"
                                          class="inline">
                                        <input type="hidden" name="_token"
                                               value="{{ csrf_token('delete_user_' ~ user.id) }}">
                                        <button type="submit" class="btn btn-ghost btn-xs text-error">Delete</button>
                                    </form>
                                {% endif %}
```

- [ ] **Step 5: Run the new tests**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php`
Expected: PASS, 11 tests total.

- [ ] **Step 6: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/Controller/Admin/UserController.php templates/admin/user/index.html.twig tests/Functional/Admin/UserCrudTest.php
git commit -m "16 - admin can delete users; owned-content and self-delete blocked"
```

---

### Task 11: `UserController::sendReset` (manual resend from edit page)

**Files:**
- Modify: `src/Controller/Admin/UserController.php`
- Modify: `templates/admin/user/form.html.twig`
- Modify: `tests/Functional/Admin/UserCrudTest.php`

- [ ] **Step 1: Append failing test**

Add to `tests/Functional/Admin/UserCrudTest.php`:

```php
    public function testAdminCanResendResetEmail(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser($client, 'admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $target = $this->seedUser($client, 'target@example.com', 'Target', ['ROLE_ORGANIZER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, '/admin/users/' . $target->getId() . '/send-reset', [
            '_token' => $this->csrf($client, 'send_reset_' . $target->getId()),
        ]);

        self::assertResponseRedirects('/admin/users/' . $target->getId() . '/edit');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        $this->assertNotNull($email);
        $this->assertSame('target@example.com', $email->getTo()[0]->getAddress());
    }
```

- [ ] **Step 2: Run the new test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php --filter=testAdminCanResendResetEmail`
Expected: FAIL — `/admin/users/{id}/send-reset` returns 404.

- [ ] **Step 3: Add the action**

Append to `src/Controller/Admin/UserController.php`:

```php
    #[Route(
        '/admin/users/{id}/send-reset',
        name: 'admin_user_send_reset',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function sendReset(User $target, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $target);

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('send_reset_' . $target->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->sendInviteEmail($target);
        $this->addFlash('success', sprintf('Reset email sent to %s.', $target->getEmail()));

        return new RedirectResponse('/admin/users/' . $target->getId() . '/edit');
    }
```

(`sendInviteEmail` already exists from Task 8.)

- [ ] **Step 4: Add the "Send password reset email" button to the form template**

Modify `templates/admin/user/form.html.twig`. Append after the `{{ form_end(...) }}` line, still inside `{% block admin_main %}`:

```twig
    {% if mode == 'edit' and target_id is defined and target_id != app.user.id %}
        <form method="post"
              action="{{ path('admin_user_send_reset', {id: target_id}) }}"
              class="mt-6">
            <input type="hidden" name="_token" value="{{ csrf_token('send_reset_' ~ target_id) }}">
            <button type="submit" class="btn btn-ghost btn-sm">Send password reset email</button>
        </form>
    {% endif %}
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit tests/Functional/Admin/UserCrudTest.php`
Expected: PASS, 12 tests total.

- [ ] **Step 6: Lint + commit**

Run: `vendor/bin/grumphp run`

```bash
git add src/Controller/Admin/UserController.php templates/admin/user/form.html.twig tests/Functional/Admin/UserCrudTest.php
git commit -m "16 - resend reset email from user edit page"
```

---

### Task 12: Full-suite green + manual smoke

**Files:** none (verification only).

- [ ] **Step 1: Run the full PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: all tests green. Previous suite had 26 tests / 56 assertions; this branch adds ~25 new tests across unit/integration/functional layers.

- [ ] **Step 2: Run GrumPHP end-to-end**

Run: `vendor/bin/grumphp run`
Expected: green. This invokes `doctrine:schema:validate --skip-sync`, phpstan level 10, phpcs PSR-12, phpmnd, phpcpd, rector, securitychecker_roave, and the commit/branch-name gates.

- [ ] **Step 3: Manual smoke on a fresh DB**

```bash
docker compose up -d
bin/console doctrine:database:drop --force --if-exists
bin/console doctrine:database:create
bin/console doctrine:migrations:migrate --no-interaction
```

- [ ] **Step 4: Smoke /setup**

Open `http://localhost:8080/`. Expected: redirected to `/setup`. Submit the form with a real password. Expected: redirected to `/admin`, signed in as the new admin.

- [ ] **Step 5: Smoke /admin/users**

In the sidebar, click "Users". Click "+ New user", fill in another email + display name + role = Organizer. Submit. Expected: redirect to `/admin/users`, flash "User created. Reset email sent to …". Open Mailpit at `http://localhost:8025`, confirm the reset email arrived.

- [ ] **Step 6: Smoke self-protection**

On `/admin/users`, click "Edit" on yourself. Expected: no role field rendered. Click "Edit" on the other user. Expected: role radio present. Try to delete yourself by hand-crafting the request — voter returns 403 (already covered by test, but eyeball it).

- [ ] **Step 7: Final commit (only if any fixes were made during smoke)**

If smoke surfaced any issue, fix it, add tests if applicable, commit with `16 - <fix description>`. Otherwise this task has no commit.

---

## Spec → Plan coverage check

| Spec section | Plan task(s) |
| --- | --- |
| Bootstrap subscriber | Task 3 |
| `/setup` form + auto-login | Task 4 |
| `/setup` 404s once a user exists | Task 4 |
| `/admin/users` list view | Task 7 |
| Create user with auto-send reset | Task 8 |
| Edit user (displayName + role) | Task 9 |
| Email read-only on edit | Task 9 (UserEditType has no email field; template renders email as text) |
| Self-edit-role blocked | Task 9 + Task 1 (voter) |
| Send-reset action on edit page | Task 11 |
| Delete with owned-content guard | Task 10 |
| Self-delete blocked | Task 10 + Task 1 (voter) |
| `UserVoter` (VIEW/EDIT/EDIT_ROLE/DELETE) | Task 1 |
| `countByOwner` repository helpers | Task 2 |
| security.yaml `^/admin/users` rule | Task 5 |
| Admin sidebar "Users" entry | Task 7 |
| Same reset email template reused | Task 8 (`sendInviteEmail`) |
| YAGNI — no `UserService` extracted | Task 8 (inlined in controller) |

No spec section is unrepresented.
