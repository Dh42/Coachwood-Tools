<?php

namespace App\Support;

use App\Models\GoogleWorkspaceDomain;
use Illuminate\Support\Str;

/**
 * Backed by the google_workspace_domains table, managed at Admin > Google
 * Sign-In (step 3f) and copied from the old app by `legacy:import-platform`.
 * A Google Workspace org can span several domains (aliases, regional TLDs,
 * post-acquisition brands, etc.), and login/invite/reset all need to treat
 * every configured one as "company domain," not just the first.
 *
 * Ported unchanged from the old app, apart from this comment.
 */
class GoogleWorkspace
{
    /**
     * @return list<string> as stored — already lowercased/trimmed at write time
     */
    public static function allowedDomains(): array
    {
        return GoogleWorkspaceDomain::query()->pluck('domain')->all();
    }

    public static function isAllowedDomain(string $domain): bool
    {
        return in_array(strtolower(trim($domain)), self::allowedDomains(), true);
    }

    public static function isCompanyDomain(string $email): bool
    {
        return self::isAllowedDomain(Str::after($email, '@'));
    }
}
