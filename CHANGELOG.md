# Changelog — PanelDNS Blesta Provisioning Module

All notable changes are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.0.0] — 2026-06-25

### Added

- **Module scaffold** — `paneldns.php` extending Blesta's `Module` base class; `config.json` metadata; English language file.
- **Server row management** — Add / Edit / list PanelDNS servers (base URL + encrypted platform API key). Credentials validated against `/platform/v1/licence-status` on save.
- **Package fields** — Dropdown of available PanelDNS plans fetched from `/platform/v1/plans`; falls back to free-text input if no server row is configured.
- **Service lifecycle**:
  - `addService()` — creates reseller org via `POST /platform/v1/orgs`; stores `org_id`, `org_slug`, `reseller_email`, `sso_url` as service fields; sends welcome email with SSO link.
  - `suspendService()` — `POST /platform/v1/orgs/{id}/suspend`.
  - `unsuspendService()` — `POST /platform/v1/orgs/{id}/unsuspend`.
  - `cancelService()` — `DELETE /platform/v1/orgs/{id}`; 404 treated as success (idempotent).
  - `changeServicePackage()` — `PATCH /platform/v1/orgs/{id}` with new `plan_slug`.
- **Client area tab** — "DNS Usage" tab showing zones / sub-clients usage vs plan limits with progress bars, org status badge, and "Manage DNS →" SSO button.
- **Admin area tab** — "PanelDNS" tab showing org ID, slug, plan, status, usage counts, and a "Re-sync Status" button that re-fetches live org data.
- **Cron task** — `paneldnsDriftSync` registered via `getCronTasks()`; iterates all active/suspended PanelDNS services, compares upstream status, and stamps Blesta services accordingly. Limited to 100 services per run.
- **`PanelDnsApi` HTTP client** — cURL-only, no Guzzle; IPv4-only (CURLOPT_IPRESOLVE_V4 SSRF guard); private-IP response check; TLS verify from `Configure::get('Blesta.curl_verify_ssl')`; platform key redacted before all log calls.
- **GitHub Actions release workflow** — push a `v*.*.*` tag → build ZIP → attach to GitHub Release.
- **README** — install guide, credential setup, package configuration, API endpoint reference.

[1.0.0]: https://github.com/hostingsimple/paneldns-blesta/releases/tag/v1.0.0
