<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Audit\Attribute\AuditIgnore;
use App\Audit\Attribute\Audited;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

final class AuditCoverageTest extends KernelTestCase
{
    private const array MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function testEveryMutatingAdminRouteIsAnnotated(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = self::getContainer()->get(RouterInterface::class);

        $unannotated = [];
        $checked = 0;

        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller)) {
                continue;
            }

            if (!str_starts_with($controller, 'App\\Controller\\Admin\\')) {
                continue;
            }

            $routeMethods = $route->getMethods();
            $isMutating = array_intersect(self::MUTATING_METHODS, $routeMethods) !== [];
            if (!$isMutating) {
                continue;
            }

            // Guard: only handle Class::method form (invokable-class form is rejected by the
            // second test below — an invokable admin controller would silently bypass the
            // AuditedControllerListener which only handles array callables).
            if (!str_contains($controller, '::')) {
                $unannotated[] = $name . ' (' . $controller . ') [invokable — not supported by listener]';
                continue;
            }

            [$class, $method] = explode('::', $controller, 2);
            $reflection = new ReflectionMethod($class, $method);

            $hasAudited = $reflection->getAttributes(Audited::class) !== [];
            $hasIgnore = $reflection->getAttributes(AuditIgnore::class) !== [];

            if (!$hasAudited && !$hasIgnore) {
                $unannotated[] = $name . ' (' . $controller . ')';
            }

            ++$checked;
        }

        $this->assertSame([], $unannotated, "Mutating admin routes missing #[Audited] or #[AuditIgnore]:\n"
        . implode("\n", $unannotated));

        // Sanity: ensure we actually iterated at least one route (guard against a routing
        // misconfiguration that would cause a false-positive empty pass).
        $this->assertGreaterThan(0, $checked, 'No mutating admin routes were found — routing may be misconfigured.');
    }

    /**
     * AuditedControllerListener only handles method controllers (array callables [$object, 'method']).
     * An invokable controller (bare class with __invoke) is resolved as a plain callable, not an
     * array callable, so it would silently bypass the listener and never write an audit row even if
     * it carries #[Audited].  This test enforces that no App\Controller\Admin\ controller is
     * invokable, which makes the listener's array-only assumption safe.
     */
    public function testNoAdminControllerIsInvokable(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = self::getContainer()->get(RouterInterface::class);

        $invokable = [];
        $found = 0;

        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller)) {
                continue;
            }

            if (!str_starts_with($controller, 'App\\Controller\\Admin\\')) {
                continue;
            }

            ++$found;

            // A bare class name (no '::') means Symfony will call it as an invokable.
            if (!str_contains($controller, '::')) {
                $invokable[] = $name . ' (' . $controller . ')';
                continue;
            }

            // Defensive: also check whether the resolved class itself defines __invoke,
            // in case a controller is registered as both an invokable and a method callable.
            [$class] = explode('::', $controller, 2);
            /** @var class-string $class */
            $reflection = new ReflectionClass($class);
            if ($reflection->hasMethod('__invoke')) {
                $invokable[] = $name . ' (' . $controller . ') [class defines __invoke]';
            }
        }

        $this->assertSame([], $invokable, "Admin controllers must NOT be invokable — "
        . "AuditedControllerListener only handles array callables and would silently skip an invokable controller:\n"
        . implode("\n", $invokable));

        $this->assertGreaterThan(
            0,
            $found,
            'No admin routes were found — coverage guard would be vacuous.'
        );
    }
}
