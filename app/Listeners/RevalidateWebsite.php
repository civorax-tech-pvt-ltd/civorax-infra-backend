<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the Next.js website to refresh its cached pages for a content tag, so edits made here appear on
 * the site (and in search engines' next crawl) within seconds instead of waiting for the cache to expire.
 * Never fails the save: if the website can't be reached, its normal hourly refresh still picks the change up.
 */
class RevalidateWebsite
{
    /**
     * Tags already sent during this request (saving a project and its categories pings once).
     *
     * @var array<string, true>
     */
    protected static array $sent = [];

    public static function portfolio(): void
    {
        static::tag('portfolio');
    }

    public static function tag(string $tag): void
    {
        $secret = config('services.website.revalidate_secret');

        if (blank($secret) || isset(static::$sent[$tag])) {
            return;
        }

        static::$sent[$tag] = true;

        app()->terminating(function () use ($tag, $secret): void {
            try {
                Http::timeout(5)
                    ->withHeaders(['x-revalidate-secret' => $secret])
                    ->post(rtrim(config('services.website.url'), '/').'/api/revalidate', ['tag' => $tag])
                    ->throw();
            } catch (Throwable $exception) {
                Log::warning('Website revalidation failed', ['tag' => $tag, 'error' => $exception->getMessage()]);
            } finally {
                unset(static::$sent[$tag]);
            }
        });
    }
}
