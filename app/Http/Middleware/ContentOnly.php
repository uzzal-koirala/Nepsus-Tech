<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Content pages: scripts for admins and Content Creators ("content"), videos for admins and Video Editors ("content:videos"). Anyone else goes to their Dashboard. */
class ContentOnly
{
    public function handle(Request $request, Closure $next, string $page = 'scripts'): Response
    {
        $user = $request->user();
        if ($page === 'videos' ? $user?->canEditVideos() : $user?->canPlanContent()) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire')) {
            return redirect()->route('my.dashboard');
        }

        abort(403, $page === 'videos' ? __('Only Video Editors can do this.') : __('Only Content Creators can do this.'));
    }
}
