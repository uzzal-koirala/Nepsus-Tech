<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The "Update now" button: compares the live version with GitHub and starts the
 * deploy workflow there. GitHub does the heavy work (tests, build, upload), so the
 * live server only receives finished files.
 */
class Updater
{
    public static function configured(): bool
    {
        return (bool) config('services.github.token') && (bool) config('services.github.repo');
    }

    /** Written by the deploy workflow after each successful deploy. */
    public static function deployed(): ?array
    {
        $file = storage_path('app/deploy.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) && ! empty($data['sha']) ? $data : null;
    }

    private function api(): PendingRequest
    {
        return Http::withToken(config('services.github.token'))
            ->acceptJson()->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->baseUrl('https://api.github.com/repos/'.config('services.github.repo'))
            ->timeout(15);
    }

    /** GET with a short cache, so opening the page often doesn't hit GitHub's rate limit. */
    private function get(string $path, array $query = []): ?array
    {
        return Cache::remember($this->key($path, $query), 20, function () use ($path, $query) {
            try {
                $response = $this->api()->get($path, $query);
            } catch (ConnectionException) {
                return null;
            }

            return $response->successful() ? $response->json() : ['error' => $response->status()];
        });
    }

    /** Bumping the generation makes every cached GitHub answer stale at once. */
    private function key(string $path, array $query = []): string
    {
        return 'updater:'.Cache::get('updater:gen', 0).':'.md5($path.json_encode($query));
    }

    /**
     * The newest commit on the branch and what is not live yet.
     *
     * @return array{error: ?string, latest: ?array, ahead: int, commits: list<array{sha: string, message: string, author: ?string, at: ?string}>}
     */
    public function status(): array
    {
        $branch = config('services.github.branch');
        $head = $this->get("/commits/{$branch}");

        if (! $head || isset($head['error'])) {
            return ['error' => $this->explain($head['error'] ?? null), 'latest' => null, 'ahead' => 0, 'commits' => []];
        }

        $commit = fn ($c) => ['sha' => $c['sha'], 'message' => strtok((string) $c['commit']['message'], "\n"), 'author' => $c['commit']['author']['name'] ?? null, 'at' => $c['commit']['author']['date'] ?? null];
        $latest = $commit($head);
        $live = static::deployed()['sha'] ?? null;

        if ($live === $latest['sha']) {
            return ['error' => null, 'latest' => $latest, 'ahead' => 0, 'commits' => []];
        }

        $compare = $live ? $this->get("/compare/{$live}...{$latest['sha']}") : null;
        if ($compare && ! isset($compare['error'])) {
            $commits = array_reverse(array_map($commit, $compare['commits'] ?? []));

            return ['error' => null, 'latest' => $latest, 'ahead' => (int) ($compare['ahead_by'] ?? count($commits)), 'commits' => array_slice($commits, 0, 15)];
        }

        // Never deployed by the workflow yet (or the old version is gone): everything counts as new.
        return ['error' => null, 'latest' => $latest, 'ahead' => 1, 'commits' => [$latest]];
    }

    /** The most recent run of the deploy workflow. */
    public function lastRun(bool $fresh = false): ?array
    {
        $path = '/actions/workflows/'.config('services.github.workflow').'/runs';
        if ($fresh) {
            Cache::forget($this->key($path, ['per_page' => 1]));
        }
        $runs = $this->get($path, ['per_page' => 1]);
        $run = $runs['workflow_runs'][0] ?? null;

        return $run ? [
            'status' => $run['status'],            // queued, in_progress, completed
            'conclusion' => $run['conclusion'],    // success, failure, cancelled, null while running
            'started' => $run['run_started_at'] ?? $run['created_at'],
            'url' => $run['html_url'],
            'sha' => $run['head_sha'],
        ] : null;
    }

    /** Starts the deploy on GitHub. Returns an error message, or null when it started. */
    public function start(): ?string
    {
        try {
            $response = $this->api()->post('/actions/workflows/'.config('services.github.workflow').'/dispatches', ['ref' => config('services.github.branch')]);
        } catch (ConnectionException) {
            return __('Could not reach GitHub. Check the internet connection.');
        }

        Cache::forever('updater:gen', Cache::get('updater:gen', 0) + 1);

        return $response->status() === 204 ? null : $this->explain($response->status());
    }

    private function explain(?int $status): string
    {
        return match ($status) {
            401 => __('The GitHub token is not valid or has expired.'),
            403 => __('The GitHub token is not allowed to do this. It needs Actions: read and write, and Contents: read.'),
            404 => __('GitHub could not find the repository or the deploy workflow. Check GITHUB_REPO and that deploy.yml is pushed.'),
            422 => __('GitHub refused to start the deploy. Check that the branch exists.'),
            null => __('Could not reach GitHub. Check the internet connection.'),
            default => __('GitHub answered with an error (:code). Try again in a minute.', ['code' => $status]),
        };
    }
}
