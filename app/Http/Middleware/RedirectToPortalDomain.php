<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portals live on their own address (services.portal.url, e.g. app.civoraxinfra.com) while the API and
 * uploaded files stay on api.civoraxinfra.com. Portal pages opened on the old address (bookmarks, links in
 * old notifications, QR codes on certificates) are sent to the same page on the portal address.
 */
class RedirectToPortalDomain
{
    /**
     * Paths that belong to the portals.
     *
     * @var list<string>
     */
    public const PORTAL_PATHS = ['team', 'team/*', 'client', 'client/*', 'student', 'student/*', 'site/*', 'certificates/*', 'verify/*'];

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        $admin = config('services.portal.admin_path');

        return [$admin, $admin.'/*', ...self::PORTAL_PATHS];
    }

    public static function host(): ?string
    {
        $url = config('services.portal.url');

        return filled($url) ? parse_url($url, PHP_URL_HOST) : null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $host = static::host();

        if ($host !== null && $request->getHost() !== $host && $request->isMethod('GET') && $request->is(...static::paths())) {
            return redirect()->to(rtrim(config('services.portal.url'), '/').$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
