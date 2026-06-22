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
        $form = $this->factory->create(UserMailConfigType::class, null, ['csrf_protection' => false]);
        $form->submit(['provider' => 'custom', 'dsn' => '', 'fromAddr' => 'a@b.test', 'fromName' => '']);

        $this->assertFalse($form->isValid());
        $this->assertGreaterThan(0, $form->get('dsn')->getErrors()->count());
    }

    public function testCustomModeRequiresFromAddr(): void
    {
        $form = $this->factory->create(UserMailConfigType::class, null, ['csrf_protection' => false]);
        $form->submit([
            'provider' => 'custom',
            'dsn' => 'smtp://user:pass@smtp.example.test:25',
            'fromAddr' => '',
            'fromName' => '',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertGreaterThan(0, $form->get('fromAddr')->getErrors()->count());
    }

    public function testGmailModeRequiresEmailAndAppPassword(): void
    {
        $form = $this->factory->create(UserMailConfigType::class, null, ['csrf_protection' => false]);
        $form->submit([
            'provider' => 'gmail',
            'gmailEmail' => '',
            'gmailAppPassword' => '',
            'fromAddr' => '',
            'fromName' => '',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertGreaterThan(0, $form->get('gmailEmail')->getErrors()->count());
        $this->assertGreaterThan(0, $form->get('gmailAppPassword')->getErrors()->count());
    }

    public function testGmailModeDoesNotRequireDsnOrFromAddr(): void
    {
        $form = $this->factory->create(UserMailConfigType::class, null, ['csrf_protection' => false]);
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
