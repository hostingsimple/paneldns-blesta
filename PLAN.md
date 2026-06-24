# PanelDNS — Blesta Provisioning Module

## Overview

A Blesta provisioning module that connects to the PanelDNS `/platform/v1` REST API,
allowing hosting companies running Blesta to sell DNS packages as orderable products.
Mirrors the functionality of the existing WHMCS module (`paneldns-whmcs`).

**Target Blesta version:** 5.x (PHP 8.0–8.2)
**Module directory:** `components/modules/paneldns/`
**API surface used:** `/platform/v1` (platform key auth, Bearer token)

---

## What the WHMCS Module Does (reference)

The existing WHMCS module covers:

| Feature | Description |
|---|---|
| Provision | Creates a reseller org via `POST /platform/v1/orgs` |
| Suspend | Calls `PATCH /platform/v1/orgs/{id}` → status=suspended |
| Unsuspend | Calls `PATCH /platform/v1/orgs/{id}` → status=active |
| Terminate | Calls `DELETE /platform/v1/orgs/{id}` |
| Change package | Calls `PATCH /platform/v1/orgs/{id}` → plan update |
| Drift sync | Daily job re-syncs org plan/status from Blesta to PanelDNS |
| Welcome email | Sends SSO link to new reseller on provision |
| Licence check | Verifies PanelDNS licence is valid before provisioning |
| Client area | Usage cards (zones/clients/seats used vs limit) + SSO button |
| Admin area | Service detail panel showing org slug, plan, status |

The Blesta module should match all of the above.

---

## File Structure

```
components/modules/paneldns/
├── paneldns.php               # Main module class (extends Module)
├── config.json                # Module metadata
├── apis/
│   └── PanelDnsApi.php        # cURL wrapper for /platform/v1
├── language/
│   └── en_us/
│       └── paneldns.php       # Language strings
└── views/
    └── default/
        ├── manage_module.pdt      # Server list (credentials)
        ├── manage_add_row.pdt     # Add server form
        ├── manage_edit_row.pdt    # Edit server form
        ├── tab_client_usage.pdt   # Client area — usage stats + SSO button
        ├── tab_admin_actions.pdt  # Admin area — org detail + actions
        └── service_info.pdt      # Service summary panel
```

---

## Build Phases

### Phase 1 — Scaffold + Credentials

- `config.json` with module metadata, version 1.0.0
- `paneldns.php` extending `Module`, constructor loading config + language
- `PanelDnsApi.php` — cURL wrapper for `/platform/v1`:
  - Constructor accepts `$base_url` and `$platform_key`
  - Methods: `createOrg()`, `getOrg()`, `updateOrg()`, `deleteOrg()`, `getLicenceStatus()`
  - TLS verify respects `Configure::get('Blesta.curl_verify_ssl')`
  - All calls log via `$this->log()` on the Module base class
  - Credentials redacted before logging
- Module row management (server = one PanelDNS installation):
  - `manageModule()` — lists configured servers
  - `manageAddRow()` / `manageEditRow()` — form with: Base URL, Platform API Key (encrypted), friendly name
  - `addModuleRow()` / `editModuleRow()` — validates by calling `getLicenceStatus()` on save

### Phase 2 — Package Fields

- `getPackageFields()` — dropdown of available plans fetched from `/platform/v1/plans`
  - Falls back to manual text input if API unreachable
  - Cached for 5 minutes to avoid N+1 on package list pages
- `addPackage()` / `editPackage()` — no-op (fields stored by Blesta automatically)

### Phase 3 — Service Lifecycle

All methods check `$vars['use_module'] == 'true'` before calling the API (Blesta's no-provision path).

- **`addService()`**
  1. Validate required fields (company name / org slug)
  2. Call `POST /platform/v1/orgs` with plan slug from package fields
  3. Call `POST /platform/v1/orgs/{id}/users` to create the reseller admin user
  4. Store as encrypted service fields: `org_id`, `org_slug`, `reseller_email`, `sso_url`
  5. Send welcome email with SSO login link (via Blesta's email system)
  6. Return service fields array

- **`suspendService()`** — `PATCH /platform/v1/orgs/{id}` → `status: suspended`

- **`unsuspendService()`** — `PATCH /platform/v1/orgs/{id}` → `status: active`

- **`cancelService()`** — `DELETE /platform/v1/orgs/{id}`

- **`changeServicePackage()`** — `PATCH /platform/v1/orgs/{id}` with new plan slug from `$package_to`

- **`validateService()`** — checks org slug format (alphanumeric + hyphens, 3–63 chars), email validity

### Phase 4 — Client Area

- `getClientTabs()` returns `['tabClientUsage' => 'DNS Usage']`
- `tabClientUsage($package, $service, $get, $post, $files)`
  1. Fetch `GET /api/v1/org/summary` using the org's API token (stored as service field)
  2. Render `tab_client_usage.pdt` with: zones used/limit, clients used/limit, seats used/limit, progress bars
  3. SSO button: `GET /api/v1/sso` → redirect URL → rendered as "Manage DNS →" link
  4. Handle API errors gracefully — show cached data or friendly error message

- `getClientServiceInfo()` — compact panel showing org slug + plan name + link to Usage tab

### Phase 5 — Admin Area

- `getAdminTabs()` returns `['tabAdminActions' => 'PanelDNS']`
- `tabAdminActions($package, $service, $get, $post, $files)`
  - Show: org ID, org slug, plan, status, zones/clients/seats counts
  - Action buttons: Suspend / Unsuspend / direct link to org in PanelDNS admin
  - POST handler: manual sync (re-fetches org from API and updates service fields)

- `getAdminServiceInfo()` — one-liner: org slug + plan name

### Phase 6 — Drift Sync

- Blesta doesn't have a native daily cron hook equivalent to WHMCS `DailyCronJob`
- Options:
  - **Option A**: Register a Blesta automation task (if API supports it in v5.x)
  - **Option B**: Document a cron entry: `php /path/to/blesta/index.php cron` already runs Blesta's task scheduler — add a module task via `$this->addCronTask()`
- Task: iterate all active PanelDNS services, call `GET /platform/v1/orgs/{id}`, compare plan/status, update service fields if drifted, log discrepancies

### Phase 7 — Packaging + Docs

- `README.md` — install guide, credential setup, package configuration
- `CHANGELOG.md` — v1.0.0 entry
- GitHub repo: `paneldns-blesta`
- Release: ZIP archive installable via Blesta's module upload UI
- GitHub Actions workflow: tag → build ZIP → attach to release (mirrors WHMCS module workflow)

---

## Key Differences vs WHMCS Module

| Concern | WHMCS | Blesta |
|---|---|---|
| Base class | free functions (`dnsmanager_` prefix) | `extends Module` |
| Views | Smarty `.tpl` | PHP `.pdt` via `View` object |
| Service fields | `['key'=>'', 'value'=>'']` array | same pattern |
| Encryption | `encrypt()` / `decrypt()` | `'encrypted' => 1` flag |
| Logging | `logModuleCall()` | `$this->log()` |
| Config | `_config()` function | `config.json` + `$this->loadConfig()` |
| Daily sync | `DailyCronJob` hook | `$this->addCronTask()` |
| No-provision | `$params['useModule']` | `$vars['use_module'] == 'true'` |
| Client area | `ClientArea()` returning template vars | Tab methods returning HTML string |

---

## API Endpoints Used

All from `/platform/v1` (platform key in `Authorization: Bearer` header):

| Method | Endpoint | Used by |
|---|---|---|
| GET | `/platform/v1/licence-status` | Credential validation on module row save |
| GET | `/platform/v1/plans` | Package fields dropdown |
| POST | `/platform/v1/orgs` | addService |
| GET | `/platform/v1/orgs/{id}` | Admin tab, drift sync |
| PATCH | `/platform/v1/orgs/{id}` | suspend, unsuspend, changePackage |
| DELETE | `/platform/v1/orgs/{id}` | cancelService |
| GET | `/api/v1/org/summary` | Client usage tab (uses org API token) |
| GET | `/api/v1/sso` | SSO button in client tab |

---

## Open Questions

1. Does PanelDNS `/platform/v1` need any new endpoints for Blesta that WHMCS doesn't need?
   - Likely no — the API surface is already sufficient
2. Should the welcome email use Blesta's built-in email template system or send via PanelDNS?
   - Prefer Blesta's system so operators can customise it in the Blesta UI
3. Blesta cron task API — confirm `addCronTask()` is available in Blesta 5.x before building Phase 6
4. Do we publish this as a paid add-on (like the WHMCS module) or free/open-source?

---

## Version

**v1.0.0** — initial release targeting feature parity with paneldns-whmcs v0.5.0
