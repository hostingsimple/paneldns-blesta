# PanelDNS — Blesta Provisioning Module

Sell DNS sub-client accounts as Blesta products. The module connects to your
PanelDNS reseller account via the `/api/v1` REST API using a reseller Bearer token
and drives the full service lifecycle: provision, suspend, unsuspend, terminate,
upgrade/downgrade.

**Blesta version:** 5.x  
**PHP version:** 8.0–8.2  
**PanelDNS:** any version exposing `/api/v1` sub-client endpoints

---

## Features

| Feature | Description |
|---|---|
| Provision | Creates a sub-client — `POST /api/v1/sub-clients` |
| Suspend | Suspends the sub-client — `PATCH /api/v1/sub-clients/{id}` `{status: "suspended"}` |
| Unsuspend | Reactivates — `PATCH /api/v1/sub-clients/{id}` `{status: "active"}` |
| Terminate | Deletes the sub-client — `DELETE /api/v1/sub-clients/{id}` |
| Change package | Updates zone/record limits — `PATCH /api/v1/sub-clients/{id}` |
| Drift sync | Daily cron task reconciles Blesta service status with upstream |
| Client area | Usage cards (zones / records vs limits) + "Manage DNS" SSO button |
| Admin area | Sub-client detail panel + manual re-sync button |

---

## Installation

1. **Download** the latest `paneldns-blesta-vX.X.X.zip` from the [Releases](https://github.com/hostingsimple/paneldns-blesta/releases) page.

2. **Upload** in Blesta Admin:  
   `Settings → Modules → Available Modules → Upload Module`  
   Select the ZIP and click **Install**.

3. **Add a server** in Blesta Admin:  
   `Settings → Modules → Installed Modules → PanelDNS → Add Server`

   | Field | Value |
   |---|---|
   | Server Name | Friendly label, e.g. "PanelDNS Production" |
   | Base URL | Root URL of your PanelDNS install, e.g. `https://app.paneldns.com` |
   | API Token | From PanelDNS → Settings → API Tokens (see below) |

   The token is validated against `/api/v1/licence-status` on save.

4. **Create a product** in Blesta Admin:  
   `Packages → Create Package` → choose **PanelDNS** as the module.  
   In the **Module Options** section, set zone and record limits for the plan.
   Enter `0` to inherit the reseller's org-level limit.

5. **Order a service** — Blesta calls `addService()` which creates the sub-client
   using the Blesta client's name and email.

---

## Credential Setup

### Getting an API Token

1. Log in to your PanelDNS reseller dashboard.
2. Go to **Settings → API Tokens → Create Token**.
3. Enable the **`sub_clients:read`** and **`sub_clients:write`** scopes.
4. Copy the token (shown only once — starts with `dnsm_`).
5. Paste it into the Blesta Server form's **API Token** field.

The token is encrypted at rest by Blesta's module field storage.

---

## Package Configuration

Each Blesta product maps to a set of limits applied to the provisioned sub-client:

| Field | Description |
|---|---|
| Zone Limit | Maximum DNS zones the sub-client can create. `0` = inherit org limit (no per-client cap). |
| Record Limit | Maximum DNS records across all zones. `0` = inherit org limit. |

When a client upgrades or downgrades their plan, Blesta calls `changeServicePackage()`,
which patches the sub-client's limits upstream.

---

## Cron Task (Drift Sync)

The module registers a cron task `paneldns_drift_sync` that runs daily at 08:00.
Blesta's cron must be running:

```bash
# Add to your server crontab
*/5 * * * * php /path/to/blesta/index.php cron > /dev/null 2>&1
```

The drift sync:
- Fetches upstream sub-client status for every active/suspended PanelDNS service.
- Stamps the Blesta service `suspended` if the sub-client is suspended upstream.
- Stamps the Blesta service `canceled` if the sub-client has been deleted upstream (404).
- Processes up to 100 services per run (large installs catch up over several days).

---

## API Endpoints Used

All under `/api/v1` (Bearer token auth):

| Method | Endpoint | Used by |
|---|---|---|
| GET | `/api/v1/licence-status` | Credential validation on server save |
| POST | `/api/v1/sub-clients` | `addService` |
| GET | `/api/v1/sub-clients/{id}` | Drift sync |
| PATCH | `/api/v1/sub-clients/{id}` | `suspendService`, `unsuspendService`, `changeServicePackage` |
| DELETE | `/api/v1/sub-clients/{id}` | `cancelService` |
| GET | `/api/v1/sub-clients/{id}/summary` | Client tab + admin tab |
| POST | `/api/v1/sub-clients/{id}/sso-token` | SSO "Manage DNS" button |

---

## File Structure

```
components/modules/paneldns/
├── paneldns.php               # Main module class (extends Module)
├── config.json                # Module metadata
├── apis/
│   └── PanelDnsApi.php        # cURL wrapper for /api/v1
├── language/
│   └── en_us/
│       └── paneldns.php       # English language strings
└── views/
    └── default/
        ├── manage_module.pdt      # Server list
        ├── manage_add_row.pdt     # Add server form
        ├── manage_edit_row.pdt    # Edit server form
        ├── tab_client_usage.pdt   # Client area — usage stats + SSO button
        ├── tab_admin_actions.pdt  # Admin area — sub-client detail + re-sync
        └── service_info.pdt       # Compact service summary panel
```

---

## Security Notes

- The API token is stored encrypted by Blesta (`'encrypted' => 1` field flag).
- All HTTP requests use `CURLOPT_IPRESOLVE_V4` — IPv4 only, preventing IPv6 SSRF rebinding attacks.
- A private-IP check is applied to the resolved primary IP after every cURL response.
- The API token is redacted to `[REDACTED]` before being passed to any log call.
- SSO login URLs are validated to start with `https://` before use (prevents `javascript:` / `data:` injection).
- TLS verification is respected from Blesta's global `Blesta.curl_verify_ssl` configuration option.
- HTTP (non-TLS) base URLs log a warning to the module log.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Support

Open an issue at [github.com/hostingsimple/paneldns-blesta](https://github.com/hostingsimple/paneldns-blesta)
or contact support via [paneldns.com](https://paneldns.com).
