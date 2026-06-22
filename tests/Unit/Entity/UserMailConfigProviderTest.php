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
