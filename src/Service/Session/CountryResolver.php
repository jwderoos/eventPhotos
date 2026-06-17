<?php

declare(strict_types=1);

namespace App\Service\Session;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CountryResolver
{
    private ?Reader $reader = null;

    private bool $readerInitialised = false;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $mmdbPath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $reader = $this->reader();
        if (!$reader instanceof Reader) {
            return null;
        }

        try {
            return strtoupper($reader->country($ip)->country->isoCode ?? '') ?: null;
        } catch (AddressNotFoundException) {
            return null;
        } catch (InvalidDatabaseException $e) {
            $this->logger->warning('GeoLite2 MMDB unreadable: ' . $e->getMessage());
            return null;
        }
    }

    private function reader(): ?Reader
    {
        if ($this->readerInitialised) {
            return $this->reader;
        }

        $this->readerInitialised = true;

        $path = $this->resolveMmdbPath();
        if (!is_file($path) || !is_readable($path)) {
            $this->logger->info('GeoLite2 MMDB not present at ' . $path . '; country lookups disabled.');
            return null;
        }

        try {
            $this->reader = new Reader($path);
        } catch (InvalidDatabaseException $invalidDatabaseException) {
            $this->logger->warning('GeoLite2 MMDB unreadable on first use: ' . $invalidDatabaseException->getMessage());
            $this->reader = null;
        }

        return $this->reader;
    }

    private function resolveMmdbPath(): string
    {
        // Constructor receives either the full mmdb path OR kernel.project_dir;
        // detect which by suffix and normalize.
        if (str_ends_with($this->mmdbPath, '.mmdb')) {
            return $this->mmdbPath;
        }

        return rtrim($this->mmdbPath, '/') . '/var/geoip/GeoLite2-Country.mmdb';
    }
}
