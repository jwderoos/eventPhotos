<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withImportNames()
    ->withComposerBased(
        twig: true,
        doctrine: true,
        phpunit: true,
        symfony: true,
        netteUtils: true,
        laravel: true,
    )
    ->withAttributesSets(
        symfony: true,
        doctrine: true,
        mongoDb: true,
        gedmo: true,
        phpunit: true,
        fosRest: true,
        jms: true,
        sensiolabs: true,
        behat: true,
        all: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        naming: true,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )
    ->withPHPStanConfigs([
        __DIR__ . '/phpstan.neon',
    ])
    ->withPaths([
        __DIR__ . '/rector.php',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // uncomment to reach your current PHP version
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withAttributesSets()
    ->withPhpSets()
;
