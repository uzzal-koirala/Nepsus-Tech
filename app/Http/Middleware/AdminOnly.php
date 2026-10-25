<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin pages. Employee logins are sent to their own version of a page, or refused. */
class AdminOnly
{
    /** Admin page => the employee's version of it. */
    private const STAFF_PAGES = [
        'dashboard' => 'my.dashboard',
        'clients.index' => 'my.clients',
        'clients.show' => 'my.clients.show',
        'tasks.index' => 'my.tasks',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAdmin()) {
            return $next($request);
        }

        $route = $request->route();
        if ($request->isMethod('GET') && ($staffPage = self::STAFF_PAGES[$route?->getName()] ?? null)) {
            return redirect()->route($staffPage, $route->parameters());
        }

        abort(403, __('Only admins can open this page.'));
    }
}
