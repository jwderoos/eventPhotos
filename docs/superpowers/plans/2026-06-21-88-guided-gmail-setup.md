# Guided Gmail Setup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Gmail a guided, no-DSN-typing option on the per-organizer mail-config form while keeping the custom-DSN path byte-for-byte unchanged.

**Architecture:** A provider selector (`custom` | `gmail`) on `UserMailConfigType`. In Gmail mode the server assembles a `smtps://…@smtp.gmail.com:465` DSN from a Gmail address + app password via a new `GmailDsnFactory`, then feeds it through the existing `DsnValidator` → `DsnVault` → verification pipeline unchanged. A new `provider` discriminator column on `UserMailConfig` re-opens the form in the right mode. A small Stimulus controller toggles the visible input block (no-JS fallback: both blocks visible, server-side conditional validation).

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, PostgreSQL 16, Stimulus, Tailwind, PHPUnit 13.

## Global Constraints

- PHP attributes everywhere — no annotations.
- Branch is `feature/88-guided-gmail-setup`. Every commit message must contain the issue number `88`. Co-author trailer not added by Claude (user commits).
- `phpstan` level 10, `phpcs` PSR-12, `phpmnd` (no magic numbers in `src/` — port `465` and `'smtp.gmail.com'` must be class constants), `phpcpd` (50-line / 100-token duplication — the Gmail branch must live in a shared service, NOT be inlined in both controllers), `rector`, `doctrine:schema:validate` all gate commits. Run `vendor/bin/grumphp run` before each commit.
- Never hand-write a migration — generate via `bin/console doctrine:migrations:diff`, edit only `getDescription()`.
- PHPUnit config has `failOnDeprecation`/`failOnNotice`/`failOnWarning` = true; a single deprecation in the code path fails the test.
- Assembled Gmail DSN: `smtps://{rawurlencode(email)}:{rawurlencode(despaced_app_password)}@smtp.gmail.com:465`. Strip ALL whitespace from the app password before encoding.
- Run PHP/Composer/`bin/console`/`vendor/bin/*` on the host. Test DB: `bin/console doctrine:database:create --env=test --if-not-exists` then migrate against it before functional tests.
- Stale copy: replace every "platform default / falls back" phrase on the mail-config screen with wording reflecting #77 (no platform fallback): "Without a verified configuration, event mail cannot be sent."

---

### Task 1: `MailProvider` enum + `UserMailConfig.provider` column + migration

**Files:**
- Create: `src/Enum/MailProvider.php`
- Modify: `src/Entity/UserMailConfig.php` (constructor + `applyConfig` + getter)
- Create: `migrations/Version<generated>.php` (via diff)
- Test: `tests/Unit/Entity/UserMailConfigProviderTest.php`

**Interfaces:**
- Produces: `App\Enum\MailProvider` (backed string enum: `Custom = 'custom'`, `Gmail = 'gmail'`). `UserMailConfig::__construct(User, EncryptedDsn, string $fromAddr, ?string $fromName, MailProvider $provider = MailProvider::Custom)`. `UserMailConfig::applyConfig(EncryptedDsn, string $fromAddr, ?string $fromName, MailProvider $provider = MailProvider::Custom): bool`. `UserMailConfig::getProvider(): MailProvider`.
- Note: trailing optional `$provider` keeps all existing call sites (controllers + tests) compiling unchanged; provider does NOT affect the `applyConfig` re-verify return value.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Enum\MailProvider;
use App\Service\Mail\EncryptedDsn;
use PHPUnit\Framework\TestCase;

final class UserMailConfigProviderTest extends TestCase
{
    private function envelope(): EncryptedDsn
    {
        return new EncryptedDsn(ciphertext: 'cipher', nonce: 'nonce');
    }

    public function testDefaultsToCustom(): void
    {
        $config = new UserMailConfig(new User('o@x', 'O'), $this->envelope(), 'from@x', null);
        $this->assertSame(MailProvider::Custom, $config->getProvider());
    }

    public function testStoresGmailProvider(): void
    {
        $config = new UserMailConfig(new User('o@x', 'O'), $this->envelope(), 'from@x', null, MailProvider::Gmail);
        $this->assertSame(MailProvider::Gmail, $config->getProvider());
    }

    public function testApplyConfigUpdatesProvider(): void
    {
        $config = new UserMailConfig(new User('o@x', 'O'), $this->envelope(), 'from@x', null, MailProvider::Gmail);
        $config->applyConfig(new EncryptedDsn(ciphertext: 'c2', nonce: 'n2'), 'from@x', null, MailProvider::Custom);
        $this->assertSame(MailProvider::Custom, $config->getProvider());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/UserMailConfigProviderTest.php`
Expected: FAIL — `MailProvider` class not found / too few arguments.

- [ ] **Step 3: Create the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum MailProvider: string
{
    case Custom = 'custom';
    case Gmail = 'gmail';
}
```

- [ ] **Step 4: Add the column + accessors to `UserMailConfig`**

Add the import `use App\Enum\MailProvider;`. Add a promoted constructor param after `$fromName` (mirroring how `$fromName` is promoted with its `#[ORM\Column]`):

```php
public function __construct(
    #[ORM\OneToOne(inversedBy: 'mailConfig')]
    #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
    private User $user,
    EncryptedDsn $envelope,
    string $fromAddr,
    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $fromName,
    #[ORM\Column(type: Types::STRING, length: 16, enumType: MailProvider::class, options: ['default' => 'custom'])]
    private MailProvider $provider = MailProvider::Custom,
) {
```

Add the getter (near `getFromName`):

```php
public function getProvider(): MailProvider
{
    return $this->provider;
}
```

In `applyConfig`, add the trailing param and assign it (do NOT fold it into the `$dsnChanged || $fromAddrChanged` return decision):

```php
public function applyConfig(
    EncryptedDsn $envelope,
    string $fromAddr,
    ?string $fromName,
    MailProvider $provider = MailProvider::Custom,
): bool {
    // ... existing body unchanged up to the assignments ...
    $this->fromName = $fromName;
    $this->provider = $provider;
    $this->updatedAt = new DateTimeImmutable();
    // ... existing return logic unchanged ...
}
```

- [ ] **Step 5: Run the unit test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/UserMailConfigProviderTest.php`
Expected: PASS.

- [ ] **Step 6: Generate the migration**

Run:
```bash
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:diff
```
Expected: a new migration adding `provider VARCHAR(16) DEFAULT 'custom' NOT NULL` to `user_mail_configs`. Edit only `getDescription()` to: `88 - add provider discriminator to user_mail_configs`. Do NOT hand-edit the DDL.

- [ ] **Step 7: Migrate dev + test DBs and validate schema**

Run:
```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console doctrine:schema:validate
```
Expected: schema validate reports mapping + database in sync.

- [ ] **Step 8: Commit**

```bash
git add src/Enum/MailProvider.php src/Entity/UserMailConfig.php migrations/ tests/Unit/Entity/UserMailConfigProviderTest.php
git commit -m "88 - add MailProvider enum and provider discriminator on UserMailConfig"
```

---

### Task 2: `GmailDsnFactory` service

**Files:**
- Create: `src/Service/Mail/GmailDsnFactory.php`
- Test: `tests/Unit/Service/Mail/GmailDsnFactoryTest.php`

**Interfaces:**
- Produces: `App\Service\Mail\GmailDsnFactory::build(string $email, string $appPassword): string` — strips all whitespace from `$appPassword`, returns `smtps://{rawurlencode(email)}:{rawurlencode(pw)}@smtp.gmail.com:465`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\GmailDsnFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;

final class GmailDsnFactoryTest extends TestCase
{
    public function testAssemblesAndParsesBack(): void
    {
        $dsn = new GmailDsnFactory()->build('name@gmail.com', 'abcd efgh ijkl mnop');
        $parsed = Dsn::fromString($dsn);

        $this->assertSame('smtps', $parsed->getScheme());
        $this->assertSame('smtp.gmail.com', $parsed->getHost());
        $this->assertSame(465, $parsed->getPort());
        $this->assertSame('name@gmail.com', $parsed->getUser());
        $this->assertSame('abcdefghijklmnop', $parsed->getPassword());
    }

    public function testUrlSignificantCharsAreEncoded(): void
    {
        // App passwords are alphanumeric in practice, but the encoder must be robust:
        // an address/password containing ':' '@' '/' must round-trip exactly.
        $dsn = new GmailDsnFactory()->build('o+tag@gmail.com', 'a:b@c/d');
        $parsed = Dsn::fromString($dsn);

        $this->assertSame('o+tag@gmail.com', $parsed->getUser());
        $this->assertSame('a:b@c/d', $parsed->getPassword());
        $this->assertSame('smtp.gmail.com', $parsed->getHost());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/GmailDsnFactoryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;

final class GmailDsnFactory
{
    private const string HOST = 'smtp.gmail.com';
    private const int PORT = 465;

    public function build(string $email, #[SensitiveParameter] string $appPassword): string
    {
        $password = (string) preg_replace('/\s+/', '', $appPassword);

        return sprintf(
            'smtps://%s:%s@%s:%d',
            rawurlencode($email),
            rawurlencode($password),
            self::HOST,
            self::PORT,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Mail/GmailDsnFactoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Mail/GmailDsnFactory.php tests/Unit/Service/Mail/GmailDsnFactoryTest.php
git commit -m "88 - add GmailDsnFactory to assemble Gmail SMTP DSN from email + app password"
```

---

### Task 3: `UserMailConfigType` — provider + Gmail fields + conditional validation

**Files:**
- Modify: `src/Form/UserMailConfigType.php`
- Test: `tests/Unit/Form/UserMailConfigTypeTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: form with unmapped children `provider` (ChoiceType, `custom`/`gmail`), `gmailEmail` (EmailType), `gmailAppPassword` (PasswordType), plus existing `dsn`, `fromAddr`, `fromName`. New form option `provider` (string, default `'custom'`) sets the initially-selected radio. Conditional validation via `FormEvents::POST_SUBMIT`: custom → `dsn` + `fromAddr` required; gmail → `gmailEmail` + `gmailAppPassword` required, `fromAddr` optional.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Form\UserMailConfigType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class UserMailConfigTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('form.factory');
        $this->assertInstanceOf(FormFactoryInterface::class, $factory);
        $this->factory = $factory;
    }

    public function testCustomModeRequiresDsn(): void
    {
        $form = $this->factory->create(UserMailConfigType::class);
        $form->submit(['provider' => 'custom', 'dsn' => '', 'fromAddr' => 'a@b.test', 'fromName' => '']);

        $this->assertFalse($form->isValid());
        $this->assertGreaterThan(0, $form->get('dsn')->getErrors()->count());
    }

    public function testGmailModeRequiresEmailAndAppPassword(): void
    {
        $form = $this->factory->create(UserMailConfigType::class);
        $form->submit(['provider' => 'gmail', 'gmailEmail' => '', 'gmailAppPassword' => '', 'fromAddr' => '', 'fromName' => '']);

        $this->assertFalse($form->isValid());
        $this->assertGreaterThan(0, $form->get('gmailEmail')->getErrors()->count());
        $this->assertGreaterThan(0, $form->get('gmailAppPassword')->getErrors()->count());
    }

    public function testGmailModeDoesNotRequireDsnOrFromAddr(): void
    {
        $form = $this->factory->create(UserMailConfigType::class);
        $form->submit([
            'provider' => 'gmail',
            'gmailEmail' => 'me@gmail.com',
            'gmailAppPassword' => 'abcd efgh ijkl mnop',
            'dsn' => '',
            'fromAddr' => '',
            'fromName' => '',
        ]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Form/UserMailConfigTypeTest.php`
Expected: FAIL — unknown field `provider` / form invalid in gmail mode (dsn still required).

- [ ] **Step 3: Rewrite `UserMailConfigType`**

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\MailProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;

/**
 * @extends AbstractType<null>
 */
final class UserMailConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('provider', ChoiceType::class, [
                'label' => 'Mail provider',
                'mapped' => false,
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Custom (SMTP DSN)' => MailProvider::Custom->value,
                    'Gmail' => MailProvider::Gmail->value,
                ],
                'data' => $options['provider'],
            ])
            ->add('dsn', TextareaType::class, [
                'label' => 'SMTP DSN',
                'help' => 'Format: smtp://user:password@smtp.example.com:587 or smtps://...',
                'attr' => ['rows' => 2, 'spellcheck' => 'false', 'autocomplete' => 'off'],
                'required' => false,
                'mapped' => false,
                'constraints' => [new Length(max: 1024)],
            ])
            ->add('gmailEmail', EmailType::class, [
                'label' => 'Gmail address',
                'help' => 'The address you sign in to Gmail with.',
                'attr' => ['autocomplete' => 'off'],
                'required' => false,
                'mapped' => false,
                'constraints' => [new Email(), new Length(max: 254)],
            ])
            ->add('gmailAppPassword', PasswordType::class, [
                'label' => 'App password',
                'help' => 'A 16-character Google app password (not your account password). '
                    . 'Create one at https://myaccount.google.com/apppasswords — requires 2-Step Verification.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                'always_empty' => false,
                'required' => false,
                'mapped' => false,
                'constraints' => [new Length(max: 256)],
            ])
            ->add('fromAddr', TextType::class, [
                'label' => 'From address',
                'required' => false,
                'mapped' => false,
                'constraints' => [new Email(), new Length(max: 254)],
            ])
            ->add('fromName', TextType::class, [
                'label' => 'From display name',
                'required' => false,
                'mapped' => false,
                'constraints' => [new Length(max: 120)],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validateProvider(...));
    }

    private function validateProvider(FormEvent $event): void
    {
        $form = $event->getForm();
        $provider = $form->get('provider')->getData();

        $blank = static function (string $field) use ($form): bool {
            $value = $form->get($field)->getData();

            return !is_string($value) || trim($value) === '';
        };

        if ($provider === MailProvider::Gmail->value) {
            if ($blank('gmailEmail')) {
                $form->get('gmailEmail')->addError(new FormError('Enter your Gmail address.'));
            }
            if ($blank('gmailAppPassword')) {
                $form->get('gmailAppPassword')->addError(new FormError('Enter your Google app password.'));
            }

            return;
        }

        if ($blank('dsn')) {
            $form->get('dsn')->addError(new FormError('Enter an SMTP DSN.'));
        }
        if ($blank('fromAddr')) {
            $form->get('fromAddr')->addError(new FormError('Enter a from address.'));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'user_mail_config',
            'provider' => MailProvider::Custom->value,
        ]);
        $resolver->setAllowedTypes('provider', 'string');
    }
}
```

- [ ] **Step 4: Run the form test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Form/UserMailConfigTypeTest.php`
Expected: PASS.

- [ ] **Step 5: Run phpstan on the form to confirm level-10 clean**

Run: `vendor/bin/phpstan analyse src/Form/UserMailConfigType.php`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Form/UserMailConfigType.php tests/Unit/Form/UserMailConfigTypeTest.php
git commit -m "88 - add provider selector and conditional Gmail/custom validation to UserMailConfigType"
```

---

### Task 4: Controllers — Gmail branch (both paths) + DNS stub + stale copy

**Files:**
- Modify: `src/Controller/Admin/AccountMailController.php` (`edit` passes provider option; `update` Gmail branch; `clear` flash copy)
- Modify: `src/Controller/Admin/UserMailController.php` (`edit` passes provider option; `update` Gmail branch)
- Modify: `tests/Fake/PrebakedDnsResolver.php` (resolve `smtp.gmail.com`)
- Test: `tests/Functional/Admin/AccountMailFlowTest.php` (add Gmail-mode + regression cases), `tests/Functional/Admin/UserMailFlowTest.php` (add Gmail-mode smoke)

**Interfaces:**
- Consumes: `GmailDsnFactory::build`, `MailProvider`, `UserMailConfig` ctor/`applyConfig` provider param (Tasks 1–2).
- Produces: both `update` actions resolve `(string $dsn, string $fromAddr, MailProvider $provider)` from the submitted form before the existing validate/encrypt/persist/verify path.

- [ ] **Step 1: Add the Gmail resolution to the functional suite as a failing test**

Add to `tests/Functional/Admin/AccountMailFlowTest.php` (uses `DsnVault` to assert the assembled DSN; the Gmail IP `93.184.216.40` comes from the stub change in Step 3):

```php
public function testGmailModeAssemblesDsnAndDefaultsFromAddr(): void
{
    $user = $this->createOrganizer('gmail-mode@example.com', 'secret');
    $this->client->loginUser($user);

    $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
    $form = $crawler->selectButton('Save and send verification')->form([
        'user_mail_config[provider]' => 'gmail',
        'user_mail_config[gmailEmail]' => 'organizer@gmail.com',
        'user_mail_config[gmailAppPassword]' => 'abcd efgh ijkl mnop',
        'user_mail_config[fromAddr]' => '',
        'user_mail_config[fromName]' => '',
    ]);
    $this->client->submit($form);
    self::assertResponseRedirects('/admin/account/mail');

    // Verification email went through the Gmail-shaped transport (stub IP for smtp.gmail.com).
    $this->assertCount(1, CapturedMail::messagesForHost('93.184.216.40'));

    $this->em->clear();
    $reloaded = $this->em->getRepository(User::class)->find($user->getId());
    $config = $reloaded?->getMailConfig();
    $this->assertInstanceOf(UserMailConfig::class, $config);
    $this->assertSame(MailProvider::Gmail, $config->getProvider());
    $this->assertSame('organizer@gmail.com', $config->getFromAddr());

    /** @var DsnVault $vault */
    $vault = self::getContainer()->get(DsnVault::class);
    $dsn = $vault->decrypt($config->getEncryptedDsn());
    $this->assertSame(
        'smtps://organizer%40gmail.com:abcdefghijklmnop@smtp.gmail.com:465',
        $dsn,
    );
}

public function testCustomModeStillWorks(): void
{
    $user = $this->createOrganizer('still-custom@example.com', 'secret');
    $this->client->loginUser($user);

    $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
    $form = $crawler->selectButton('Save and send verification')->form([
        'user_mail_config[provider]' => 'custom',
        'user_mail_config[dsn]' => 'smtp://user:pass@smtp.example-organizer.test:25',
        'user_mail_config[fromAddr]' => 'press@example-organizer.test',
        'user_mail_config[fromName]' => '',
    ]);
    $this->client->submit($form);
    self::assertResponseRedirects('/admin/account/mail');

    $this->assertCount(1, CapturedMail::messagesForHost('93.184.216.34'));

    $this->em->clear();
    $reloaded = $this->em->getRepository(User::class)->find($user->getId());
    $config = $reloaded?->getMailConfig();
    $this->assertInstanceOf(UserMailConfig::class, $config);
    $this->assertSame(MailProvider::Custom, $config->getProvider());
}
```

Add the imports `use App\Enum\MailProvider;` and (already present) `use App\Service\Mail\DsnVault;` to the test file.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/AccountMailFlowTest.php --filter testGmailMode`
Expected: FAIL — `smtp.gmail.com` unresolvable (DsnRejected) → no captured mail; provider not gmail.

- [ ] **Step 3: Make `smtp.gmail.com` resolve in the test DNS stub**

Edit `tests/Fake/PrebakedDnsResolver.php` — add to the `MAP` const (a distinct public IP so the Gmail test is unambiguous):

```php
private const array MAP = [
    '.loopback.rebind.example-organizer.test' => ['127.0.0.1'],
    '.mapped.rebind.example-organizer.test' => ['::ffff:169.254.169.254'],
    '.cgnat.rebind.example-organizer.test' => ['100.64.0.1'],
    'smtp.fail.example-organizer.test' => ['93.184.216.35'],
    'smtp.gmail.com' => ['93.184.216.40'],
];
```

- [ ] **Step 4: Add the Gmail branch to `AccountMailController`**

Add imports: `use App\Enum\MailProvider;` and `use App\Service\Mail\GmailDsnFactory;`. Inject the factory:

```php
public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly UserMailConfigRepository $configs,
    private readonly DsnValidator $validator,
    private readonly DsnVault $vault,
    private readonly TransportBuilder $transports,
    private readonly GmailDsnFactory $gmailDsn,
) {
}
```

In `edit`, pass the stored provider so the form re-opens correctly:

```php
$config = $user->getMailConfig();
$form = $this->createForm(UserMailConfigType::class, null, [
    'action' => $this->generateUrl('admin_account_mail_update'),
    'provider' => $config?->getProvider()->value ?? MailProvider::Custom->value,
]);

return $this->render('admin/account/mail/edit.html.twig', [
    'form' => $form->createView(),
    'config' => $config,
]);
```

In `update`, replace the `$dsn`/`$fromAddr` derivation block (the lines reading `dsn`/`fromAddr`/`fromName` from the form) with provider resolution, and track the provider for persistence:

```php
$providerRaw = $form->get('provider')->getData();
$provider = $providerRaw === MailProvider::Gmail->value ? MailProvider::Gmail : MailProvider::Custom;
$fromNameRaw = $form->get('fromName')->getData();
$fromName = is_string($fromNameRaw) && $fromNameRaw !== '' ? $fromNameRaw : null;

if ($provider === MailProvider::Gmail) {
    $emailRaw = $form->get('gmailEmail')->getData();
    $appPwRaw = $form->get('gmailAppPassword')->getData();
    $email = is_string($emailRaw) ? $emailRaw : '';
    $appPw = is_string($appPwRaw) ? $appPwRaw : '';
    $dsn = $this->gmailDsn->build($email, $appPw);
    $fromAddrRaw = $form->get('fromAddr')->getData();
    $fromAddr = is_string($fromAddrRaw) && $fromAddrRaw !== '' ? $fromAddrRaw : $email;
} else {
    $dsnRaw = $form->get('dsn')->getData();
    $fromAddrRaw = $form->get('fromAddr')->getData();
    $dsn = is_string($dsnRaw) ? $dsnRaw : '';
    $fromAddr = is_string($fromAddrRaw) ? $fromAddrRaw : '';
}
```

Pass `$provider` into the entity in both branches:

```php
if ($config === null) {
    $config = new UserMailConfig($user, $envelope, $fromAddr, $fromName, $provider);
    $this->em->persist($config);
} else {
    $config->applyConfig($envelope, $fromAddr, $fromName, $provider);
}
```

- [ ] **Step 5: Apply the identical branch to `UserMailController`**

Mirror Step 4 in `src/Controller/Admin/UserMailController.php`: add the same imports, inject `GmailDsnFactory $gmailDsn`, pass `'provider' => $target->getMailConfig()?->getProvider()->value ?? MailProvider::Custom->value` in `edit`, replace the derivation block in `update` with the same provider-resolution code, and pass `$provider` into `new UserMailConfig(...)` / `applyConfig(...)`. (Same logic — the per-controller `$user`/`$target` and audit/redirect lines stay as they are.)

- [ ] **Step 6: Run the functional tests to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/AccountMailFlowTest.php`
Expected: PASS (new Gmail + custom cases and all pre-existing cases).

- [ ] **Step 7: Add a Gmail-mode smoke test to `UserMailFlowTest`**

Open `tests/Functional/Admin/UserMailFlowTest.php`, find its existing custom-DSN submit test for the admin-on-behalf path, and add an analogous Gmail-mode case: submit `user_mail_config[provider]=gmail` + `gmailEmail` + `gmailAppPassword` against `/admin/users/{id}/mail`, assert redirect, assert the target's `UserMailConfig.provider === MailProvider::Gmail`, and assert one message captured for host `93.184.216.40`. (Match the file's existing helper/login conventions; add `use App\Enum\MailProvider;`.)

- [ ] **Step 8: Reword the stale "platform default" flash in `AccountMailController::clear`**

Change the success flash from `'Mail configuration cleared. Event emails will be sent from the platform default.'` to:

```php
$this->addFlash(
    'success',
    'Mail configuration cleared. Without a verified configuration, event mail cannot be sent.',
);
```

- [ ] **Step 9: Run both functional mail suites + grumphp**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/AccountMailFlowTest.php tests/Functional/Admin/UserMailFlowTest.php
vendor/bin/grumphp run
```
Expected: all green (watch phpcpd — the Gmail branch lives in `GmailDsnFactory`, only the small provider-resolution glue is repeated across the two controllers; if phpcpd flags it, extract the resolution into a tiny private helper or a shared service and re-run).

- [ ] **Step 10: Commit**

```bash
git add src/Controller/Admin/AccountMailController.php src/Controller/Admin/UserMailController.php tests/Fake/PrebakedDnsResolver.php tests/Functional/Admin/AccountMailFlowTest.php tests/Functional/Admin/UserMailFlowTest.php
git commit -m "88 - assemble Gmail DSN from guided inputs in both mail-config controllers"
```

---

### Task 5: Template + Stimulus toggle + stale copy

**Files:**
- Modify: `templates/admin/account/mail/edit.html.twig`
- Create: `assets/controllers/mail_provider_controller.js`

**Interfaces:**
- Consumes: form view fields `provider`, `dsn`, `gmailEmail`, `gmailAppPassword`, `fromAddr`, `fromName`.
- Produces: server-rendered custom + Gmail blocks; Stimulus toggles visibility on provider change and pre-fills `fromAddr` from `gmailEmail` when `fromAddr` is empty. No-JS: both blocks visible.

- [ ] **Step 1: Write the Stimulus controller**

```javascript
import { Controller } from '@hotwired/stimulus';

// Toggles the visible mail-provider input block and pre-fills the from-address
// from the Gmail address (only when from-address is still empty).
export default class extends Controller {
    static targets = ['custom', 'gmail', 'gmailEmail', 'fromAddr'];

    connect() {
        this.toggle();
    }

    providerChanged(event) {
        this.provider = event.target.value;
        this.toggle();
    }

    toggle() {
        const provider = this.provider ?? this.selectedProvider();
        const isGmail = provider === 'gmail';
        this.customTarget.hidden = isGmail;
        this.gmailTarget.hidden = !isGmail;
    }

    selectedProvider() {
        const checked = this.element.querySelector('input[name$="[provider]"]:checked');
        return checked ? checked.value : 'custom';
    }

    prefillFromAddr() {
        if (this.hasFromAddrTarget && this.hasGmailEmailTarget && this.fromAddrTarget.value === '') {
            this.fromAddrTarget.value = this.gmailEmailTarget.value;
        }
    }
}
```

- [ ] **Step 2: Rewrite the form section of `edit.html.twig`**

Replace the intro paragraph (the "Without a verified configuration, event mail goes out from the platform default." sentence) with: `Without a verified configuration, event mail cannot be sent.` Replace the `data-turbo-confirm` on the Clear form (`"Clear mail configuration? Event mail will fall back to the platform default."`) with: `"Clear mail configuration? Event mail cannot be sent until a new configuration is verified."`

Replace the `form_start … form_end` block (lines ~41–46) with:

```twig
{{ form_start(form, {attr: {class: 'space-y-4', 'data-controller': 'mail-provider'}}) }}
    <div data-action="change->mail-provider#providerChanged">
        {{ form_row(form.provider) }}
    </div>

    <div data-mail-provider-target="custom">
        {{ form_row(form.dsn) }}
    </div>

    <div data-mail-provider-target="gmail" hidden>
        {{ form_row(form.gmailEmail, {attr: {'data-mail-provider-target': 'gmailEmail', 'data-action': 'input->mail-provider#prefillFromAddr'}}) }}
        {{ form_row(form.gmailAppPassword) }}
    </div>

    {{ form_row(form.fromAddr, {attr: {'data-mail-provider-target': 'fromAddr'}}) }}
    {{ form_row(form.fromName) }}
    <button type="submit" class="btn btn-primary">Save and send verification</button>
{{ form_end(form) }}
```

(The `hidden` attribute on the Gmail block is the no-JS-safe default for custom mode; Stimulus `connect()` immediately re-evaluates and shows the right block when the stored provider is Gmail.)

- [ ] **Step 3: Verify the page renders in both modes (functional render assertion)**

Add to `tests/Functional/Admin/AccountMailFlowTest.php`:

```php
public function testFormRendersGmailFields(): void
{
    $user = $this->createOrganizer('render@example.com', 'secret');
    $this->client->loginUser($user);

    $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
    self::assertResponseIsSuccessful();
    $this->assertGreaterThan(0, $crawler->filter('[data-controller="mail-provider"]')->count());
    $this->assertGreaterThan(0, $crawler->filter('input[name="user_mail_config[gmailAppPassword]"]')->count());
}
```

- [ ] **Step 4: Run the render test + full mail suite**

Run: `vendor/bin/phpunit tests/Functional/Admin/AccountMailFlowTest.php`
Expected: PASS.

- [ ] **Step 5: Build assets and smoke-check in the running stack (optional manual)**

Run: `docker compose up -d` then load `http://localhost:8080/admin/account/mail`, toggle the provider radios, confirm the block swaps and that entering a Gmail address pre-fills From address. (Asset Mapper serves the new controller automatically; no build step.)

- [ ] **Step 6: Commit**

```bash
git add templates/admin/account/mail/edit.html.twig assets/controllers/mail_provider_controller.js tests/Functional/Admin/AccountMailFlowTest.php
git commit -m "88 - guided Gmail UI: provider toggle, app-password inputs, fix stale fallback copy"
```

---

### Task 6: Full verification gate

**Files:** none (verification only).

- [ ] **Step 1: Run the complete suite**

Run: `vendor/bin/phpunit`
Expected: all green, zero deprecations/notices/warnings.

- [ ] **Step 2: Run the full quality gate**

Run: `vendor/bin/grumphp run`
Expected: phpstan L10, phpcs, phpmnd, phpcpd, rector, securitychecker, `doctrine:schema:validate` all pass.

- [ ] **Step 3: Grep for any remaining stale fallback copy on this screen**

Run:
```bash
rtk grep -rin "platform default\|falls back\|fall back" src/Controller/Admin/AccountMailController.php src/Controller/Admin/UserMailController.php templates/admin/account/mail/
```
Expected: no matches (or only intentional, unrelated ones). Fix any stragglers, re-run grumphp, amend the Task 4/5 commit or add a follow-up commit referencing `88`.

- [ ] **Step 4: Propose the final summary**

Summarize what shipped and propose a squashed/feature commit message line for the user (Claude does not commit beyond the per-task commits already made; the user controls final history).

---

## Self-Review

**Spec coverage:**
- Provider selector → Task 3 (form) + Task 5 (template).
- Gmail two-input mode + help/link → Task 3 + Task 5.
- Server assembles DSN through existing pipeline → Task 2 + Task 4.
- `fromAddr` defaults to Gmail address, editable → Task 4 (server default) + Task 5 (Stimulus prefill).
- Custom mode no regression → Task 4 `testCustomModeStillWorks` + existing suite in Task 6.
- Stale copy cleanup (template + flashes) → Task 4 Step 8 + Task 5 Step 2 + Task 6 Step 3 grep.
- Provider discriminator (your decision) → Task 1 (enum/column/migration) + Task 4 (`edit` re-opens in stored mode).
- smtps/465 → Task 2 constants + asserted in Task 2/Task 4 tests.
- Strip whitespace → Task 2 implementation + `testAssemblesAndParsesBack`.
- Admin-on-behalf path → Task 4 Step 5 + Step 7.
- Tests (unit DSN, unit form, functional both modes/both paths) → Tasks 2, 3, 4.

**Placeholder scan:** No TBD/TODO; every code step shows full code. Task 5 Step 5 is an explicitly-optional manual smoke check, not a placeholder. Task 4 Step 7 references the existing `UserMailFlowTest` conventions rather than reproducing an unseen file verbatim — implementer follows the file's own helpers.

**Type consistency:** `MailProvider` enum + `getProvider(): MailProvider`, ctor/`applyConfig` trailing `MailProvider $provider = MailProvider::Custom`, `GmailDsnFactory::build(string,string): string`, form option `provider` (string), DNS stub IP `93.184.216.40` for `smtp.gmail.com` — consistent across Tasks 1, 2, 3, 4, 5.
