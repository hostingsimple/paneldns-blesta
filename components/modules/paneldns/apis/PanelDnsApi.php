<?php
/**
 * PanelDnsApi — cURL wrapper for the PanelDNS Platform API (/platform/v1).
 *
 * Used by the Blesta provisioning module to drive org lifecycle operations.
 * Mirrors shared/PanelDnsApi.php from paneldns-whmcs but adapted for Blesta:
 *   - Logging via $module->log() instead of logModuleCall()
 *   - TLS verify from Configure::get('Blesta.curl_verify_ssl')
 *   - No WHMCS-specific class references
 *
 * Security:
 *   - Bearer token never logged (redacted to [REDACTED] in all log payloads)
 *   - CURLOPT_IPRESOLVE_V4 forces IPv4 — SSRF guard (no IPv6 rebinding)
 *   - Private IP check on resolved primary_ip after each call
 *   - HTTP warning logged when base_url is plaintext http://
 */
class PanelDnsApi
{
    private string $baseUrl;
    private string $platformKey;
    private bool   $tlsVerify;
    private int    $timeout    = 15;
    private int    $connectTimeout = 5;

    /** Reference to the parent Module instance for $this->log() calls. */
    private ?object $module;

    public function __construct(string $baseUrl, string $platformKey, bool $tlsVerify = true, ?object $module = null)
    {
        $this->baseUrl     = rtrim($baseUrl, '/');
        $this->platformKey = $platformKey;
        $this->tlsVerify   = $tlsVerify;
        $this->module      = $module;

        if (str_starts_with($this->baseUrl, 'http://')) {
            $this->log('WARNING', $this->baseUrl, [], 'API calls over plaintext HTTP — platform key transmitted unencrypted', '');
        }
    }

    // ── Domain methods ────────────────────────────────────────────────────────

    /** GET /platform/v1/licence-status — validate credentials. */
    public function getLicenceStatus(): array
    {
        return $this->get('/platform/v1/licence-status');
    }

    /** GET /platform/v1/plans — list available plans. */
    public function getPlans(): array
    {
        return $this->get('/platform/v1/plans');
    }

    /**
     * POST /platform/v1/orgs — create a new reseller org.
     *
     * @param array $data {name, plan_slug, owner: {name, email, password}, ...}
     */
    public function createOrg(array $data): array
    {
        return $this->post('/platform/v1/orgs', $data);
    }

    /** GET /platform/v1/orgs/{id} */
    public function getOrg(int $id): array
    {
        return $this->get("/platform/v1/orgs/{$id}");
    }

    /** PATCH /platform/v1/orgs/{id} */
    public function patchOrg(int $id, array $data): array
    {
        return $this->patch("/platform/v1/orgs/{$id}", $data);
    }

    /** DELETE /platform/v1/orgs/{id} */
    public function deleteOrg(int $id): array
    {
        return $this->delete("/platform/v1/orgs/{$id}");
    }

    /**
     * GET /platform/v1/orgs/{id}/summary
     * Returns usage + plan + org status in one call.
     */
    public function getOrgSummary(int $id): array
    {
        return $this->get("/platform/v1/orgs/{$id}/summary");
    }

    /**
     * POST /platform/v1/orgs/{id}/sso-token
     * Mints a short-lived SSO login URL.
     */
    public function mintSsoToken(int $id, ?string $email = null): array
    {
        $body = $email ? ['user_email' => $email] : [];
        return $this->post("/platform/v1/orgs/{$id}/sso-token", $body);
    }

    /**
     * POST /platform/v1/orgs/{id}/suspend
     * Suspend a reseller org.
     */
    public function suspendOrg(int $id): array
    {
        return $this->post("/platform/v1/orgs/{$id}/suspend");
    }

    /**
     * POST /platform/v1/orgs/{id}/unsuspend
     * Unsuspend a reseller org.
     */
    public function unsuspendOrg(int $id): array
    {
        return $this->post("/platform/v1/orgs/{$id}/unsuspend");
    }

    // ── Generic HTTP verbs ────────────────────────────────────────────────────

    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path . (!empty($query) ? '?' . http_build_query($query) : '');
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body);
    }

    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $this->baseUrl . $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $this->baseUrl . $path);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch      = curl_init();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->platformKey,
            'User-Agent: paneldns-blesta/1.0.0',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);
        $raw       = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        $this->log($method, $url, $body, $status, $raw, $curlErr);

        if ($curlErr) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'cURL error: ' . $curlErr];
        }

        // SSRF belt-and-braces: block responses from private ranges even with IPRESOLVE_V4 set.
        if ($primaryIp !== '' && self::isPrivateIp($primaryIp)) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'SSRF guard: target resolved to a private IP'];
        }

        $decoded = json_decode((string) ($raw ?: 'null'), true);
        $ok      = $status >= 200 && $status < 300;

        return [
            'ok'       => $ok,
            'status'   => $status,
            'data'     => is_array($decoded) ? ($decoded['data'] ?? $decoded) : null,
            'error'    => $ok ? null : ($decoded['error'] ?? "HTTP {$status}"),
            'raw_body' => $raw,
        ];
    }

    /**
     * Log the API call via the parent module's log() method.
     * The platform key is never included in any logged payload.
     */
    private function log(
        string $method,
        string $url,
        ?array $body,
        int    $status    = 0,
        ?string $rawResp  = null,
        ?string $curlErr  = null
    ): void {
        if ($this->module === null || !method_exists($this->module, 'log')) {
            return;
        }

        // Redact the key from the URL if it ever leaks in (it shouldn't, it's a header).
        $safeUrl = $this->redactUrl($url);

        // Redact known secret fields from the body before logging.
        $safeBody = $this->redactBody($body ?? []);

        $response = [
            'status'     => $status,
            'body'       => substr((string) ($rawResp ?? ''), 0, 2048),
            'curl_error' => $curlErr,
        ];

        // Blesta log() signature: log($url, $vars, $direction, $success)
        $this->module->log($safeUrl, serialize($safeBody), 'input', true);
        $this->module->log($safeUrl, serialize($response),  'output', $status >= 200 && $status < 300);
    }

    private function redactUrl(string $url): string
    {
        return preg_replace(
            '/([?&])(token|key|password|secret|api_key)=([^&]*)/i',
            '$1$2=[REDACTED]',
            $url
        );
    }

    private function redactBody(array $payload): array
    {
        $copy = $payload;
        foreach (['password', 'api_key', 'token', 'secret', 'authorization', 'platform_key'] as $field) {
            if (isset($copy[$field])) {
                $copy[$field] = '[REDACTED]';
            }
        }
        // Recurse one level for nested arrays (e.g. owner.password).
        foreach ($copy as $k => $v) {
            if (is_array($v)) {
                $copy[$k] = $this->redactBody($v);
            }
        }
        return $copy;
    }

    /**
     * Returns true if the IPv4 address falls within a private/loopback/reserved range.
     * Matches the WHMCS module's isPrivateIp() implementation.
     */
    private static function isPrivateIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true; // unparseable — block to be safe
        }
        foreach ([
            ['10.0.0.0',    8],
            ['172.16.0.0',  12],
            ['192.168.0.0', 16],
            ['127.0.0.0',   8],
            ['169.254.0.0', 16],
            ['0.0.0.0',     8],
            ['100.64.0.0',  10],
        ] as [$subnet, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($subnet) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
