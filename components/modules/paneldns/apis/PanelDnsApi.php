<?php
/**
 * PanelDnsApi — cURL wrapper for the PanelDNS Reseller API (/api/v1).
 *
 * Used by the Blesta provisioning module to drive sub-client lifecycle.
 * Authenticates with a reseller Bearer token (dnsm_* format) from the
 * reseller's Settings → API Tokens page.
 *
 * Security:
 *   - Bearer token never logged (redacted to [REDACTED] in all payloads)
 *   - CURLOPT_IPRESOLVE_V4 forces IPv4 — SSRF guard (no IPv6 rebinding)
 *   - Private IP check on resolved primary_ip after each call
 *   - HTTP warning logged when base_url is plaintext http://
 */
class PanelDnsApi
{
    private string $baseUrl;
    private string $apiToken;
    private bool   $tlsVerify;
    private int    $timeout        = 15;
    private int    $connectTimeout = 5;

    /** Reference to the parent Module instance for $this->log() calls. */
    private ?object $module;

    public function __construct(string $baseUrl, string $apiToken, bool $tlsVerify = true, ?object $module = null)
    {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->apiToken = $apiToken;
        $this->tlsVerify = $tlsVerify;
        $this->module   = $module;

        if (str_starts_with($this->baseUrl, 'http://')) {
            $this->log('WARNING', $this->baseUrl, [], 'API calls over plaintext HTTP — Bearer token transmitted unencrypted', '');
        }
    }

    // ── Credential check ──────────────────────────────────────────────────────

    /** GET /api/v1/licence-status — validate reseller credentials. */
    public function getLicenceStatus(): array
    {
        return $this->get('/api/v1/licence-status');
    }

    // ── Sub-client methods ────────────────────────────────────────────────────

    /**
     * POST /api/v1/sub-clients — create a sub-client under this reseller's org.
     *
     * @param array $data {name, email, password?, zone_limit?, max_records?}
     */
    public function createSubClient(array $data): array
    {
        return $this->post('/api/v1/sub-clients', $data);
    }

    /** GET /api/v1/sub-clients/{id} */
    public function getSubClient(int $id): array
    {
        return $this->get("/api/v1/sub-clients/{$id}");
    }

    /**
     * PATCH /api/v1/sub-clients/{id}
     *
     * Partial update — pass only the fields to change.
     * @param array $data Subset of {name, status, zone_limit, max_records}
     */
    public function patchSubClient(int $id, array $data): array
    {
        return $this->patch("/api/v1/sub-clients/{$id}", $data);
    }

    /** DELETE /api/v1/sub-clients/{id} */
    public function deleteSubClient(int $id): array
    {
        return $this->delete("/api/v1/sub-clients/{$id}");
    }

    /**
     * GET /api/v1/sub-clients/{id}/summary
     * Returns zone count, record count, and limits in one call.
     */
    public function getSubClientSummary(int $id): array
    {
        return $this->get("/api/v1/sub-clients/{$id}/summary");
    }

    /**
     * POST /api/v1/sub-clients/{id}/sso-token
     * Mints a short-lived one-time SSO login URL pointing at the sub-client portal.
     */
    public function mintSsoToken(int $id): array
    {
        return $this->post("/api/v1/sub-clients/{$id}/sso-token");
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
            'Authorization: Bearer ' . $this->apiToken,
            'User-Agent: paneldns-blesta/2.0.0',
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
     * The Bearer token is never included in any logged payload.
     */
    private function log(
        string $method,
        string $url,
        ?array $body,
        int    $status   = 0,
        ?string $rawResp = null,
        ?string $curlErr = null
    ): void {
        if ($this->module === null || !method_exists($this->module, 'log')) {
            return;
        }

        $safeUrl  = $this->redactUrl($url);
        $safeBody = $this->redactBody($body ?? []);

        $response = [
            'status'     => $status,
            'body'       => substr((string) ($rawResp ?? ''), 0, 2048),
            'curl_error' => $curlErr,
        ];

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
        foreach (['password', 'api_key', 'token', 'secret', 'authorization', 'api_token'] as $field) {
            if (isset($copy[$field])) {
                $copy[$field] = '[REDACTED]';
            }
        }
        foreach ($copy as $k => $v) {
            if (is_array($v)) {
                $copy[$k] = $this->redactBody($v);
            }
        }
        return $copy;
    }

    private static function isPrivateIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
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

    /**
     * Stable, non-reversible identifier for this server + credential pair.
     *
     * Used to key the licence cache so two configured PanelDNS servers, or the same
     * server after a token rotation, never share a cached licence verdict. Truncated
     * to 16 hex chars: it is a cache key, not a secret, and the full token is never
     * derivable from it.
     */
    public function identityHash(): string
    {
        return substr(hash('sha256', $this->baseUrl . '|' . $this->apiToken), 0, 16);
    }
}
