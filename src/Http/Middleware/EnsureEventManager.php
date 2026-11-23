<?php

namespace Iquesters\Event\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Iquesters\Event\Support\EventAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Event management pages: super-admins and organisation admins. Which
 * organisations' events each may touch is checked again in the controller.
 * Auto-registered as `ensure.event.manager`.
 */
class EnsureEventManager
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(EventAccess::canManage($request->user()), 403);

        return $next($request);
    }
}
