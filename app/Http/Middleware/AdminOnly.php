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
        'tasks.index' => 'my.tasks',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAdmin()) {
            return $next($request);
        }

        // Opening a page (a link, a bookmark, or the page they were on before signing in): go to their own version, else their Dashboard.
        if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire')) {
            $route = $request->route();
            $staffPage = self::STAFF_PAGES[$route?->getName()] ?? null;

            return $staffPage ? redirect()->route($staffPage, $route->parameters()) : redirect()->route('my.dashboard');
        }

        abort(403, __('Only admins can do this.'));
    }
}
