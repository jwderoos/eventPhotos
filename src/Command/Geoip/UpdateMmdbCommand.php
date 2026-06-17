<?php

declare(strict_types=1);

namespace App\Command\Geoip;

use App\Service\Session\GeoIpFeatureFlag;
use PharData;
use PharFileInfo;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:geoip:update',
    description: 'Downloads the latest MaxMind GeoLite2-Country MMDB to var/geoip/.',
)]
final class UpdateMmdbCommand extends Command
{
    private const string DOWNLOAD_URL =
        'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&suffix=tar.gz&license_key=%s';

    private const int HTTP_OK = 200;

    private const int DIR_PERMISSIONS = 0755;

    public function __construct(
        private readonly GeoIpFeatureFlag $flag,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct();
        $this->setHidden(!$this->flag->isEnabled());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->flag->isEnabled()) {
            $io->error('MAXMIND_LICENSE_KEY is empty; GeoIP feature is disabled.');

            return Command::FAILURE;
        }

        $destDir = $this->projectDir . '/var/geoip';
        if (!is_dir($destDir) && !mkdir($destDir, self::DIR_PERMISSIONS, true) && !is_dir($destDir)) {
            $io->error('Could not create ' . $destDir);

            return Command::FAILURE;
        }

        $tmpTar  = $destDir . '/GeoLite2-Country.tar.gz.partial';
        $finalMmdb = $destDir . '/GeoLite2-Country.mmdb';
        $tmpMmdb = $finalMmdb . '.partial';

        $url = sprintf(self::DOWNLOAD_URL, urlencode($this->flag->getLicenseKey()));
        $io->writeln('Downloading GeoLite2-Country.tar.gz…');

        $response = $this->http->request('GET', $url);
        if ($response->getStatusCode() !== self::HTTP_OK) {
            $io->error('Download failed with HTTP ' . $response->getStatusCode());

            return Command::FAILURE;
        }

        file_put_contents($tmpTar, $response->getContent());

        $tar = new PharData($tmpTar);
        $extracted = null;
        foreach (new RecursiveIteratorIterator($tar) as $file) {
            if (!$file instanceof PharFileInfo) {
                continue;
            }

            if (str_ends_with($file->getFilename(), '.mmdb')) {
                copy($file->getPathname(), $tmpMmdb);
                $extracted = $tmpMmdb;
                break;
            }
        }

        unlink($tmpTar);

        if ($extracted === null) {
            $io->error('No .mmdb file found inside the downloaded tarball.');

            return Command::FAILURE;
        }

        if (!rename($extracted, $finalMmdb)) {
            $io->error('Atomic rename of ' . $extracted . ' → ' . $finalMmdb . ' failed.');

            return Command::FAILURE;
        }

        $io->success('GeoLite2-Country.mmdb updated at ' . $finalMmdb);

        return Command::SUCCESS;
    }
}
