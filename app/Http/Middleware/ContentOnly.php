<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Script pages: admins and Content Creators. Other employee logins go to their Dashboard. */
class ContentOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->canPlanContent()) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire')) {
            return redirect()->route('my.dashboard');
        }

        abort(403, __('Only Content Creators can do this.'));
    }
}
