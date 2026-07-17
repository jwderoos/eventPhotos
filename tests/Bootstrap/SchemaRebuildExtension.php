<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Kernel;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Rebuilds the test database from migrations once, before the first test runs.
 *
 * This makes the phpunit gate (GrumPHP + CI) deterministic: the schema always matches the
 * code under test, regardless of which branch last left the local _test database in what
 * state. dama/doctrine-test-bundle then wraps each test in a transaction on top of it.
 *
 * It lives in an Extension rather than tests/bootstrap.php on purpose: bootstrap() is
 * invoked exactly once, in the main runner process. Process-isolated tests
 * (#[RunTestsInSeparateProcesses]) re-include tests/bootstrap.php in a child process but do
 * NOT re-run extensions, so the rebuild can't fire mid-run and collide (DROP DATABASE while
 * the parent still holds a connection) or wipe data underneath a running suite.
 *
 * Set SKIP_SCHEMA_REBUILD=1 to skip the rebuild for tight local `--filter` TDD loops.
 * GrumPHP and CI never set it, so the gate always rebuilds. See issue #116.
 */
final class SchemaRebuildExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (! empty($_SERVER['SKIP_SCHEMA_REBUILD'])) {
            return;
        }

        $kernel = new Kernel('test', (bool) ($_SERVER['APP_DEBUG'] ?? false));
        $application = new Application($kernel);
        $application->setAutoExit(false);

        // Drop and recreate the whole database rather than the schema: doctrine:schema:drop
        // honours the `schema_filter` in doctrine.yaml (which excludes `sessions`), so it
        // would leave that table behind and the migration's CREATE TABLE would collide. A
        // fresh database also mirrors exactly what CI does before running the suite.
        $commands = [
            ['command' => 'doctrine:database:drop', '--if-exists' => true, '--force' => true],
            ['command' => 'doctrine:database:create'],
            ['command' => 'doctrine:migrations:migrate', '--allow-no-migration' => true],
        ];

        foreach ($commands as $commandParameters) {
            $output = new BufferedOutput();
            $exitCode = $application->run(new ArrayInput($commandParameters + ['--no-interaction' => true]), $output);

            if ($exitCode !== 0) {
                fwrite(STDERR, sprintf(
                    "Test schema rebuild failed on `%s` (exit %d):\n%s\n",
                    $commandParameters['command'],
                    $exitCode,
                    $output->fetch(),
                ));

                exit(1);
            }
        }

        $kernel->shutdown();
    }
}
