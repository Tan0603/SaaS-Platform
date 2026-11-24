<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One site, many companies: the tenant comes from the logged-in user, not the URL.
 *
 * Must run after `auth`. Guest routes (login) deliberately run WITHOUT tenancy so the
 * login lookup can search all users: stancl's TenantScope returns early when
 * tenancy()->initialized is false, so no tenant_id filter is applied.
 */
class InitializeTenancyByUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenant;

        // A user without a company must never reach tenant data.
        abort_if($tenant === null, 403, 'Your account is not linked to a company.');

        tenancy()->initialize($tenant);

        return $next($request);
    }
}
