# Changelog — PanelDNS Blesta Provisioning Module

All notable changes are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [3.2.0] — 2026-11-18

### Security — a service could be bound to another customer's PanelDNS sub-client

PanelDNS found a critical account-takeover class in its WHMCS module. Billing systems do not
verify client emails, and that module linked new services to existing PanelDNS accounts by
email. This module never did that. It uses only the reseller sub-client API, and that API
rejects a duplicate email instead of reusing the account. An audit found the same class
through a different input: an **ID taken from `$vars`**.

Blesta documents `$vars` as user-supplied, and on a client order it carries the order form's
input. Blesta also forces `use_module=false` for pending and in-review orders, so customers
reach the no-provision branch.

- **`addService()` no-provision path stored `$vars['sub_client_id']` as the service's link**
  (SEC-LINK-01). An order that named another customer's sub-client ID bound the new service
  to it, and the client tab and SSO then opened that customer's zones. Sub-client IDs are
  sequential, so they are easy to guess. The branch now always stores an empty ID.
- **`addService()` "idempotent create" (IDEMPOTENT-01) reused `$vars['sub_client_id']`.**
  When the order input carried a numeric ID, provisioning PATCHed *that* sub-client to
  `active` and stored its ID. It neither created a sub-client nor checked ownership.
  **Removed:** `addService()` now always creates a new sub-client. Reactivating a
  sub-client is `unsuspendService()`'s job.
- **New: explicit admin "Link sub-client" action** (SEC-LINK-03). This replaces "add the
  service without the module and type an ID" for attaching an existing sub-client. It
  appears on the PanelDNS admin tab of an unlinked service only. It takes the numeric
  PanelDNS ID, never an email, and requires a confirmation tick. It verifies that the
  sub-client exists in the reseller account and refuses an ID already stored on another
  service on the same server. It fails closed when that check cannot run. It stores
  PanelDNS's own email for the sub-client.
- **Create failures are reported, never worked around** (SEC-LINK-02). A duplicate email
  in the reseller org (422) now gives a clear admin error that names the email. So do the
  PanelDNS 3.104.1 Platform API reasons `owner_email_exists` / `user_email_exists` (409),
  which are mapped defensively. Provisioning stops. The module never retries with a
  modified email and never looks the existing account up to link it.
- **SSO** (SEC-SSO-02/03). Tokens are minted only for the stored sub-client ID, with no
  request body. A 403 `sso_refused_platform_admin` from PanelDNS 3.104.1 now shows "Single
  sign-on is not available for this account" in the client area and is logged.
- `PanelDnsApi` responses now carry `reason` and `errors`, and surface the validation
  `message` for 422s.

**Operators: upgrade PanelDNS to 3.104.1 or later as well.** This module does not depend on
it, but PanelDNS before 3.104.1 still attaches an existing account to a new org by email on
the Platform API (`/platform/v1/orgs`), which other billing integrations use.

### Fixed — on the linking path

- **`addService()` signature did not match `Module::addService()`** (BLESTA-SIG-01). The
  parameters were declared `($package, $service, $vars, ...)` instead of
  `($package, $vars, $parent_package, $parent_service, $status)`, so every argument
  arrived one position late. The IDEMPOTENT-01 bug was dormant behind this mismatch and
  would have gone live once the signature was corrected.
- **Stored service fields were never found** (BLESTA-FIELDS-01). Blesta passes
  `$service->fields` as a list of `{key, value}` objects, but the reader indexed it by key.
  `getServiceField()` now handles Blesta's shape. The drift-sync and grace-expiry cron
  tasks use it too.
- The sub-client email and name now come from the Blesta client record (`client_id`).
  Previously they came from a `$vars['client']` array that Blesta does not pass.

### Known issues (not addressed in this release)

The module still has defects unrelated to this fix that likely stop it from running on a
live Blesta install:

- `makeView()` is called for every view but is not defined in this module. Blesta's own
  modules build views with `new View(...)`.
- `getModuleRows()` is redeclared `private`. PHP rejects that at class load if the base
  `Module` declares it `protected` or `public`.
- Four client views are rendered under names that do not match their files:
  `tab_client_zones`, `tab_client_records`, `tab_client_zone_create` and
  `tab_client_zone_import`, against `zones.pdt`, `records.pdt`, `zone_create.pdt` and
  `zone_import.pdt`.

These need a separate repair and a live Blesta test.

---

## [3.1.1] — 2026-08-27

### Fixed

- **`components/modules/paneldns/config.json` still declared `3.0.0`.** Blesta reads the
  module version from `config.json`, not from the class docblock, so v3.1.0 installed and
  reported itself as 3.0.0 — and Blesta would not have offered the upgrade. The v3.1.0 tag
  and release remain published but are superseded by this one; install v3.1.1.

  Both version identifiers are now bumped together. The docblock alone is not the version.

---

## [3.1.0] — 2026-08-27

### Fixed — the module did not load at all on PHP 8

- **`components/modules/paneldns/paneldns.php` failed to parse on every PHP 8 release.**

  ```
  PHP Fatal error: Unparenthesized `a ? b : c ?: d` is not supported
  ```

  Nested ternaries without explicit parentheses were deprecated in PHP 7.4 and became a
  **compile-time** error in PHP 8.0. Because it is a parse error, execution never had to
  reach the line: the file never loaded and every Blesta hook into this module fatalled.
  Blesta 5 requires PHP 8, so the module was non-functional on every supported platform.
  Behaviour is unchanged — the intended precedence was already company → "First Last" →
  email, which the parentheses now state explicitly.

### Added — licence gating

- **`PanelDnsLicenceCheck`** — verifies the install is paired with an active PanelDNS
  subscription. This module previously enforced nothing: its API client defined
  `getLicenceStatus()` and nothing in the repo called it.
  - `active` / `trialing` → unlocked; `past_due` → 7-day grace; `free` / `cancelled` → locked
  - 24h cache; a cached verdict is trusted for at most 2 days when the server is unreachable
  - Gates **provisioning only** (`addService()`). Suspend, unsuspend, cancel and all read
    paths stay open, so a lapsed subscription never strands existing customers.
  - Requires PanelDNS **v3.91.8+**, which is the first release to emit the `blesta` slug in
    `modules_unlocked`. Against an older server the slug is absent and provisioning locks
    even on a healthy subscription (LICENCE-SLUG-01).
- Grace is measured from `first_past_due_at` — when the lapse was first observed — matching
  the WHMCS, reseller-WHMCS and HostBill modules. Simulated day 0→365: locks on day 7.

### Fixed

- **`curl_close()` removed** (PHP8-CURL-01). Deprecated in PHP 8.5, emitting a notice on
  every API call.
- `@version` docblock corrected from a stale `2.1.0` to match the release line.

---

## [3.0.0] — 2026-06-25

Full feature parity with the PanelDNS WHMCS reseller module. Every client-facing and
admin-facing feature now exists identically across WHMCS, Blesta, and HostBill.

### Added — embedded DNS manager

A full DNS zone and record manager is now embedded in the Blesta client area tab:

- **Zone list** — paginated list of all zones; create / delete / export (BIND) / navigate to records
- **Zone create** — validated zone name form (253-char limit, no `..`, strict regex)
- **Zone import** — import BIND zone text into an existing zone (additive; 512 KB cap)
- **Zone export** — direct `text/plain` BIND file download (streamed, no page reload)
- **Record list** — tabular view of all records per zone; inline edit form; delete; add record
- **Record validation** — 13-type allowlist (A AAAA CNAME MX TXT NS SRV CAA PTR TLSA SSHFP HTTPS NAPTR); name ≤ 253 chars; content ≤ 4096 chars; TTL ≥ 60
- **DNSSEC card** — enable / disable per zone; DS records shown when active
- **Nameservers card** — per-zone and overview cards showing nameservers to configure at registrar
- **Zone health widget** — overview tab surfaces up to 5 non-active zones for quick remediation
- **Ownership enforcement** — every zone / record action verifies the zone's `sub_client_id` matches the authenticated sub-client; no ID-guessing attacks possible
- **CSRF protection** — per-session per-service token, rotated after every successful mutation
- **Rate limiting** — 60 req/min per sub-client (session-based sliding window)

### Added — provisioning enhancements

- **Nameserver fields on server row** — `ns1_hostname`–`ns4_hostname` + `soa_email` stored on the server record; surfaced in the overview card and welcome email
- **Extended package options** — `send_welcome_email` (checkbox), `ns1`–`ns4` hostname overrides, `soa_email`, `auto_create_zone`, `auto_delete_zone`, `grace_period` (0–365 days)
- **Welcome email on provision** — sends SSO login link + nameservers to the sub-client on first creation; resendable from admin tab
- **Grace period in terminate** — if `grace_period > 0`, `cancelService()` suspends upstream and stores the deadline date; actual DELETE fires when the grace cron processes it
- **Idempotent `addService()`** — if `sub_client_id` is already set on the service, PATCHes `status:active` instead of creating a duplicate
- **GDPR consent stamp** — fetches current legal version from `GET /api/v1/legal-version` and includes `consent_version` in the create payload
- **Grace period deadline service field** — visible in admin; stamped on termination with grace, cleared on final delete

### Added — cron tasks

- **`paneldns_grace_expiry`** (new, daily 09:00) — scans terminated services with a stored grace deadline; for each where `deadline <= today`, calls `DELETE /api/v1/sub-clients/{id}` and clears the field
- **`paneldns_drift_sync`** (existing, daily 08:00) — unchanged

### Added — admin tab improvements

- Zone list (first 20 zones) with status badges and "View in PanelDNS" links
- "Resend Welcome Email" action button
- "Re-sync Status" action button
- Grace period deadline shown in service detail

[3.0.0]: https://github.com/hostingsimple/paneldns-blesta/releases/tag/v3.0.0

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
