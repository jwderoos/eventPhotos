<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Geoip;

use App\Command\Geoip\UpdateMmdbCommand;
use App\Service\Session\GeoIpFeatureFlag;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UpdateMmdbCommandTest extends KernelTestCase
{
    public function testCommandFailsWhenFeatureFlagDisabled(): void
    {
        $kernel = self::bootKernel();
        $app = new Application($kernel);

        $httpClient = self::getContainer()->get('http_client');
        $this->assertInstanceOf(HttpClientInterface::class, $httpClient);

        $command = new UpdateMmdbCommand(
            new GeoIpFeatureFlag(''),
            $kernel->getProjectDir(),
            $httpClient,
        );
        $app->addCommand($command);

        $tester = new CommandTester($app->find('app:geoip:update'));
        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('MAXMIND_LICENSE_KEY', $tester->getDisplay());
    }

    public function testCommandIsHiddenWhenFeatureFlagDisabled(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->assertIsString($projectDir);
        $httpClient = self::getContainer()->get('http_client');
        $this->assertInstanceOf(HttpClientInterface::class, $httpClient);

        $command = new UpdateMmdbCommand(new GeoIpFeatureFlag(''), $projectDir, $httpClient);
        $this->assertTrue($command->isHidden());
    }

    public function testCommandIsVisibleWhenFeatureFlagEnabled(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->assertIsString($projectDir);
        $httpClient = self::getContainer()->get('http_client');
        $this->assertInstanceOf(HttpClientInterface::class, $httpClient);

        $command = new UpdateMmdbCommand(new GeoIpFeatureFlag('FAKE-KEY'), $projectDir, $httpClient);
        $this->assertFalse($command->isHidden());
    }
}
