<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ApplicationInfo
{
    public const REPOSITORY = 'https://github.com/kinouzero/canabicultarr';

    public function get(): array
    {
        $version = config('app.version');
        $release = Cache::remember('application-release', 43200, fn () => $this->release());
        $latest = $release['latestVersion'];
        $status = $release['status'];
        if ($latest !== null) {
            $installed = $this->version($version);
            $status = $installed === null ? 'unknownVersion' : (version_compare($latest, $installed, '>') ? 'available' : 'current');
        }

        return ['version' => $version, 'repositoryUrl' => self::REPOSITORY,
            'runtime' => ['php' => PHP_VERSION, 'laravel' => app()->version()],
            'update' => [...$release, 'status' => $status]];
    }

    private function release(): array
    {
        $result = ['status' => 'unavailable', 'latestVersion' => null, 'releaseUrl' => null, 'prerelease' => false, 'checkedAt' => now()->toISOString()];
        try {
            $request = Http::acceptJson()->withUserAgent('Canabicultarr update checker')
                ->connectTimeout(2)->timeout(5)->withoutRedirecting();
            $response = $request->get('https://api.github.com/repos/kinouzero/canabicultarr/releases', ['per_page' => 100]);
            if (! $response->successful()) {
                return [...$result, 'status' => match ($response->status()) {
                    401, 404 => 'inaccessible',
                    403, 429 => 'limited',
                    default => 'unavailable',
                }];
            }
            $rows = $response->json();
            if (! is_array($rows) || ! array_is_list($rows)) {
                return $result;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ($row['draft'] ?? true) !== false || ! is_string($row['published_at'] ?? null)) {
                    continue;
                }
                $tag = $row['tag_name'] ?? null;
                $version = is_string($tag) ? $this->version($tag) : null;
                if ($version === null || ($result['latestVersion'] !== null && ! version_compare($version, $result['latestVersion'], '>'))) {
                    continue;
                }
                $result = [...$result, 'latestVersion' => $version,
                    'releaseUrl' => self::REPOSITORY.'/releases/tag/'.rawurlencode($tag),
                    'prerelease' => ($row['prerelease'] ?? false) === true || str_contains($version, '-')];
            }

            return [...$result, 'status' => $result['latestVersion'] === null ? 'noRelease' : 'found'];
        } catch (ConnectionException) {
            return $result;
        }
    }

    private function version(string $tag): ?string
    {
        return preg_match('/^v?(\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)[.-]?\d*)?)(?:\+[0-9A-Za-z.-]+)?$/iD', $tag, $matches) ? $matches[1] : null;
    }
}
