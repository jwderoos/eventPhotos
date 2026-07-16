<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php84\Rector\Class_\PropertyHookRector;
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
        naming: false,
        instanceOf: true,
        earlyReturn: true,
        carbon: false,
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
    ->withSkip([
        __DIR__ . '/migrations',
        __DIR__ . '/config',
        ArrayToFirstClassCallableRector::class => [
            __DIR__ . '/tests/Unit/EventListener/Audit/AuditedControllerListenerTest.php',
        ],
        // Converting setNext() to a property hook would drop the public
        // setNext(ExtractedAttributes): void method the fake's contract
        // requires (mirrors FakeGoogleOAuthClient's plain-setter shape),
        // and the rewrite is unsound here anyway: it emits a non-nullable
        // hook parameter for a nullable property, which fatals at runtime.
        PropertyHookRector::class => [
            __DIR__ . '/tests/Fake/FakeAttributeExtractorClient.php',
        ],
    ])
    // uncomment to reach your current PHP version
    ->withPhpVersion(PhpVersion::PHP_85)
    ->withRules([
        PropertyHookRector::class,
    ])
    ->withAttributesSets()
    ->withPhpSets()
;
