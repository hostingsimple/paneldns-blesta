<?php

/**
 * PanelDnsLicenceCheck — verifies this Blesta install is paired with an active
 * PanelDNS subscription, with a 7-day grace period for past_due.
 *
 * Behaviour (matches the WHMCS and HostBill modules):
 *   - Calls GET /api/v1/licence-status on the configured PanelDNS server.
 *   - Result is cached as flat JSON under sys_get_temp_dir(), keyed by the
 *     server+token identity hash — no Blesta Cache dependency.
 *   - sub_status 'active' or 'trialing'                 -> unlocked
 *   - sub_status 'past_due' within a 7-day grace window -> still unlocked
 *     (measured from when it FIRST went past due, not from the last fetch)
 *   - past the grace window, or a cache older than 2 days -> locked
 *   - 'free'                                            -> locked from day one
 *
 * ONLY provisioning is gated. addService() is blocked; suspend, unsuspend, cancel
 * and every read path keep working, so an expired subscription never strands
 * existing customers or prevents an orderly wind-down.
 *
 * LICENCE-SLUG-01: this gates on the 'blesta' slug, which PanelDNS emits in
 * modules_unlocked from v3.91.8 onward. Against an older server that slug is
 * absent, every check reports "module not unlocked", and provisioning locks even
 * on a healthy subscription — so the server change must land first. The failure is
 * silent by nature: it looks exactly like an expired plan.
 */
class PanelDnsLicenceCheck
{
    /** Slug this module looks for in modules_unlocked. */
    const REQUIRED_MODULE = 'blesta';

    /** Past-due grace period — past this, lock provisioning. */
    const GRACE_SECONDS = 604800;

    /** How long a fresh licence response stays authoritative. */
    const CACHE_TTL = 86400;

    /** If PanelDNS has been unreachable longer than this, stop trusting the cache. */
    const STALE_HARD_LOCK = 172800;

    /**
     * @return array{unlocked:bool, reason:string, sub_status:string, expires_at:?string}
     */
    public static function check(PanelDnsApi $api): array
    {
        $cacheKey = 'paneldns-blesta-licence-' . $api->identityHash();
        $cached   = self::readCache($cacheKey);
        $now      = time();

        if ($cached && ($now - ($cached['fetched_at'] ?? 0)) < self::CACHE_TTL) {
            return self::interpret($cached, $now);
        }

        $resp = $api->getLicenceStatus();

        if (!empty($resp['ok'])) {
            $payload = $resp['data'] ?? [];
            $newSub  = $payload['sub_status'] ?? 'unknown';

            // GRACE-CLOCK-01: the grace window is measured from when the subscription
            // FIRST went past due, which must survive every subsequent refresh. Measuring
            // it from fetched_at instead makes the window unreachable: fetched_at is reset
            // to now on every successful fetch, and the cache refreshes daily, so the
            // elapsed time can never exceed CACHE_TTL (1 day) and therefore never reaches
            // GRACE_SECONDS (7 days). A past-due subscription would stay unlocked forever
            // while still reporting "7 day(s) left".
            //
            // Null when not past due, so returning to active resets the clock and a later
            // lapse gets a fresh window rather than inheriting the old one.
            $firstPastDueAt = $newSub === 'past_due'
                ? ($cached['first_past_due_at'] ?? $now)
                : null;

            $entry   = [
                'fetched_at'     => $now,
                'first_past_due_at' => $firstPastDueAt,
                'sub_status'     => $newSub,
                'modules'        => $payload['modules_unlocked'] ?? [],
                'expires_at'     => $payload['expires_at']       ?? null,
                'current_plan'   => $payload['current_plan']     ?? null,
            ];
            self::writeCache($cacheKey, $entry);

            return self::interpret($entry, $now);
        }

        // No fresh answer. Fall back to a recent cache so a transient blip does not
        // immediately stop provisioning.
        if ($cached && ($now - ($cached['fetched_at'] ?? 0)) < self::STALE_HARD_LOCK) {
            $stale           = self::interpret($cached, $now);
            $stale['reason'] = 'Stale (using cached licence). ' . $stale['reason'];

            return $stale;
        }

        return [
            'unlocked'   => false,
            'reason'     => self::failureReason($resp),
            'sub_status' => 'unknown',
            'expires_at' => null,
        ];
    }

    /** Null to proceed, or an admin-facing error string to block on. */
    public static function gateOrError(PanelDnsApi $api)
    {
        $result = self::check($api);

        return $result['unlocked'] ? null : self::formatErrorBanner($result);
    }

    /** @internal exposed for testing. */
    public static function formatErrorBanner(array $result): string
    {
        $sub = $result['sub_status'] ?? 'unknown';

        switch ($sub) {
            case 'cancelled':
                $headline  = 'PanelDNS subscription cancelled.';
                $explainer = 'New provisioning is disabled. Existing customers keep working.';
                break;
            case 'past_due':
                $headline  = 'PanelDNS subscription past due (grace expired).';
                $explainer = 'The 7-day grace period has expired. Provisioning is paused until payment is settled.';
                break;
            case 'free':
                $headline  = 'No active PanelDNS subscription.';
                $explainer = 'The Blesta module requires an active PanelDNS subscription.';
                break;
            case 'unknown':
                $headline  = 'Could not verify PanelDNS subscription.';
                $explainer = $result['reason'] ?? 'Check the PanelDNS hostname and API token on this module row.';
                break;
            default:
                $headline  = 'PanelDNS licence check failed.';
                $explainer = $result['reason'] ?? '';
        }

        $expiry = !empty($result['expires_at'])
            ? 'Subscription expired: ' . $result['expires_at']
            : '';

        return implode(' ', array_filter([$headline, $explainer, $expiry]));
    }

    /** Pure decision function. @internal Public for testing. */
    public static function interpret(array $cached, int $now): array
    {
        $sub     = $cached['sub_status'] ?? 'unknown';
        $mods    = $cached['modules']    ?? [];
        $expAt   = $cached['expires_at'] ?? null;
        $fetched = $cached['fetched_at'] ?? 0;

        $hasModule = in_array(self::REQUIRED_MODULE, $mods, true);

        if (in_array($sub, ['active', 'trialing'], true) && $hasModule) {
            return [
                'unlocked'   => true,
                'reason'     => 'Subscription ' . $sub,
                'sub_status' => $sub,
                'expires_at' => $expAt,
            ];
        }

        if ($sub === 'past_due' && $hasModule) {
            // Fall back to fetched_at only for a cache written before first_past_due_at
            // existed. That starts the clock now rather than locking immediately, which is
            // the right way to be wrong: we genuinely do not know when the lapse began.
            $firstPastDueAt   = $cached['first_past_due_at'] ?? $fetched;
            $secondsPastDue = $now - $firstPastDueAt;

            if ($secondsPastDue < self::GRACE_SECONDS) {
                $daysLeft = (int) ceil((self::GRACE_SECONDS - $secondsPastDue) / 86400);

                return [
                    'unlocked'   => true,
                    'reason'     => 'Subscription past due (grace: ' . $daysLeft . ' day(s) left)',
                    'sub_status' => $sub,
                    'expires_at' => $expAt,
                ];
            }

            return [
                'unlocked'   => false,
                'reason'     => 'Subscription past due - grace period expired',
                'sub_status' => $sub,
                'expires_at' => $expAt,
            ];
        }

        return [
            'unlocked'   => false,
            'reason'     => 'Subscription status: ' . $sub . ($hasModule ? '' : ' (module not unlocked)'),
            'sub_status' => $sub,
            'expires_at' => $expAt,
        ];
    }

    /**
     * Distinguish a server we could not reach from one that reached us and refused.
     *
     * LICENCE-DIAG-01: reporting a 401/403 as "cannot reach" sends the reseller to
     * debug their network instead of their token or their invoice.
     */
    private static function failureReason(array $resp): string
    {
        $status = (int) ($resp['status'] ?? 0);
        $error  = trim((string) ($resp['error'] ?? ''));

        if ($status >= 400 && $status < 500) {
            return $error !== ''
                ? 'PanelDNS refused the licence check (HTTP ' . $status . '): ' . $error
                : 'PanelDNS refused the licence check (HTTP ' . $status . '). Check the API token.';
        }

        return $error !== ''
            ? 'Cannot reach PanelDNS to verify licence: ' . $error
            : 'Cannot reach PanelDNS to verify licence. Please try again later.';
    }

    private static function cachePath(string $key): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paneldns_blesta_cache';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private static function readCache(string $key)
    {
        $path = self::cachePath($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private static function writeCache(string $key, array $value): void
    {
        // Only cache a well-formed response; a malformed one must not pin a verdict.
        if (
            !isset($value['sub_status'])
            || !is_string($value['sub_status'])
            || $value['sub_status'] === ''
            || !isset($value['modules'])
            || !is_array($value['modules'])
        ) {
            return;
        }

        @file_put_contents(self::cachePath($key), json_encode($value, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
