# Changelog — PanelDNS Blesta Provisioning Module

All notable changes are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [2.0.0] — 2026-06-25

Complete rewrite targeting the **reseller-tier `/api/v1` API** instead of the platform `/platform/v1` API.
The module now creates **sub-client accounts** under a reseller organisation, matching the behaviour
of the PanelDNS WHMCS module.

### Changed

- **API tier** — migrated from `/platform/v1` (platform key, reseller orgs) to `/api/v1` (reseller Bearer token, sub-clients).
- **Server credential** — `platform_key` field replaced with `api_token` (Bearer token in `dnsm_*` format, generated at Settings → API Tokens in the reseller dashboard). Scopes required: `sub_clients:read` + `sub_clients:write`.
- **Package fields** — `plan_slug` dropdown replaced with `zone_limit` (integer) + `max_records` (integer) fields. `0` means inherit the reseller org-level limit.
- **Service stored fields** — `org_id`, `org_slug`, `reseller_email`, `sso_url` replaced with `sub_client_id` + `sub_client_email`.

### Updated lifecycle methods

- `addService()` — `POST /api/v1/sub-clients` (name + email from Blesta client record; zone_limit + max_records from package meta).
- `suspendService()` — `PATCH /api/v1/sub-clients/{id}` `{status: "suspended"}`.
- `unsuspendService()` — `PATCH /api/v1/sub-clients/{id}` `{status: "active"}`.
- `cancelService()` — `DELETE /api/v1/sub-clients/{id}`; 404 treated as success (idempotent).
- `changeServicePackage()` — `PATCH /api/v1/sub-clients/{id}` with new `zone_limit` + `max_records`.

### Updated tabs and views

- **Client tab** — "DNS Usage" now shows sub-client zone and record usage from `GET /api/v1/sub-clients/{id}/summary`. SSO "Manage DNS" button via `POST /api/v1/sub-clients/{id}/sso-token`.
- **Admin tab** — shows sub-client ID, name, email, status, zones used/limit, records used/limit. "View in PanelDNS Admin" links to `/admin/sub-clients/{id}`.
- `manage_add_row.pdt` / `manage_edit_row.pdt` — replaced `platform_key` input with `api_token` (password, placeholder `dnsm_...`).

### Updated `PanelDnsApi`

- Constructor accepts `string $apiToken` (was `string $platformKey`).
- Auth header changed from `X-Platform-Key` to `Authorization: Bearer {token}`.
- All `/platform/v1/orgs/*` methods removed.
- New methods: `getLicenceStatus()`, `createSubClient()`, `getSubClient()`, `patchSubClient()`, `deleteSubClient()`, `getSubClientSummary()`, `mintSsoToken()`.
- Log redaction updated — redacts `api_token` field (was `platform_key`).
- User-Agent bumped to `paneldns-blesta/2.0.0`.

### Cron drift sync

- Now calls `getSubClient()` instead of `getOrg()` to check upstream status.
- Detects 404 (sub-client deleted upstream) and cancels the Blesta service.

---

## [1.0.0] — 2026-06-25

### Added

- **Module scaffold** — `paneldns.php` extending Blesta's `Module` base class; `config.json` metadata; English language file.
- **Server row management** — Add / Edit / list PanelDNS servers (base URL + encrypted platform API key). Credentials validated against `/platform/v1/licence-status` on save.
- **Package fields** — Dropdown of available PanelDNS plans fetched from `/platform/v1/plans`; falls back to free-text input if no server row is configured.
- **Service lifecycle** — full org lifecycle via `/platform/v1/orgs/*`.
- **Client area tab** — "DNS Usage" showing org zones / sub-clients usage vs plan limits.
- **Admin area tab** — "PanelDNS" with org details and re-sync button.
- **Cron task** — `paneldnsDriftSync` for daily status reconciliation.
- **`PanelDnsApi` HTTP client** — cURL-only, IPv4-only SSRF guard, private-IP response check, TLS verify.
- **GitHub Actions release workflow** — push a `v*.*.*` tag → build ZIP → attach to GitHub Release.
- **README** — install guide, credential setup, package configuration.

[2.0.0]: https://github.com/hostingsimple/paneldns-blesta/releases/tag/v2.0.0
[1.0.0]: https://github.com/hostingsimple/paneldns-blesta/releases/tag/v1.0.0
