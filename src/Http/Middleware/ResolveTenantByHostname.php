<?php

declare(strict_types=1);

namespace YourVendor\CloudflareSaasDomains\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use YourVendor\CloudflareSaasDomains\Resolution\CurrentTenant;
use YourVendor\CloudflareSaasDomains\Resolution\TenantResolver;
use YourVendor\CloudflareSaasDomains\Tenancy\TenantInitializer;

final class ResolveTenantByHostname
{
    public const TENANT_ATTRIBUTE = 'tenant';

    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly CurrentTenant $current,
        private readonly TenantInitializer $initializer,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $mode  "required" answers 404 for an unknown host, "optional" continues without a tenant.
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $resolved = $this->resolver->resolve($request->getHost());

        if ($resolved === null) {
            if ($mode === 'optional') {
                return $next($request);
            }

            throw new NotFoundHttpException('Unknown tenant domain.');
        }

        $this->current->set($resolved);
        $request->attributes->set(self::TENANT_ATTRIBUTE, $resolved->tenant);
        $this->initializer->initialize($resolved->tenant);

        return $next($request);
    }
}
