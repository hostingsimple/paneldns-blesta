# PanelDNS — Blesta Provisioning Module

Sell white-label DNS reseller accounts as Blesta products. The module connects to
your PanelDNS installation via the `/platform/v1` REST API and drives the full
service lifecycle: provision, suspend, unsuspend, terminate, upgrade/downgrade.

**Blesta version:** 5.x  
**PHP version:** 8.0–8.2  
**PanelDNS:** any version exposing `/platform/v1`

---

## Features

| Feature | Description |
|---|---|
| Provision | Creates a reseller org — `POST /platform/v1/orgs` |
| Suspend | Suspends the org — `POST /platform/v1/orgs/{id}/suspend` |
| Unsuspend | Reactivates the org — `POST /platform/v1/orgs/{id}/unsuspend` |
| Terminate | Deletes the org — `DELETE /platform/v1/orgs/{id}` |
| Change package | Updates the plan — `PATCH /platform/v1/orgs/{id}` |
| Drift sync | Daily cron task reconciles Blesta service status with upstream |
| Welcome email | Sends SSO link on provision so resellers can log in immediately |
| Client area | Usage cards (zones / sub-clients vs plan limits) + "Manage DNS" SSO button |
| Admin area | Org detail panel + manual re-sync button |

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
   | Platform API Key | From PanelDNS Admin → Settings → API Keys → Platform Key |

   The key is validated against `/platform/v1/licence-status` on save.

4. **Create a product** in Blesta Admin:  
   `Packages → Create Package` → choose **PanelDNS** as the module.  
   In the **Module Options** section, select a plan from the dropdown
   (plans are fetched live from `/platform/v1/plans`).

5. **Order a service** — Blesta calls `addService()` which creates the org and
   sends the welcome email with a one-time SSO login link.

---

## Credential Setup

### Getting the Platform API Key

1. Log in to your PanelDNS installation as an operator-level admin.
2. Go to **Admin → Settings → API Keys**.
3. Copy the **Platform Key** (starts with `pdns_platform_`).
4. Paste it into the Blesta Server form.

The platform key has full access to all org management endpoints. Store it
securely — it is encrypted at rest by Blesta's module field storage.

---

## Cron Task (Drift Sync)

The module registers a cron task `paneldns_drift_sync` that runs daily at 08:00.
It is activated automatically via Blesta's cron task system when the module is installed.

Blesta's cron must be running:
```bash
# Add to your server crontab
*/5 * * * * php /path/to/blesta/index.php cron > /dev/null 2>&1
```

The drift sync:
- Fetches the upstream org status for every active/suspended PanelDNS service.
- Stamps the Blesta service `suspended` if the org is suspended upstream.
- Stamps the Blesta service `canceled` if the org has been deleted upstream.
- Processes up to 100 services per run (large installs catch up over several days).

---

## API Endpoints Used

All under `/platform/v1` (Bearer token auth):

| Method | Endpoint | Used by |
|---|---|---|
| GET | `/platform/v1/licence-status` | Credential validation on server save |
| GET | `/platform/v1/plans` | Package fields dropdown |
| POST | `/platform/v1/orgs` | `addService` |
| GET | `/platform/v1/orgs/{id}` | Drift sync |
| PATCH | `/platform/v1/orgs/{id}` | `changeServicePackage` |
| POST | `/platform/v1/orgs/{id}/suspend` | `suspendService` |
| POST | `/platform/v1/orgs/{id}/unsuspend` | `unsuspendService` |
| DELETE | `/platform/v1/orgs/{id}` | `cancelService` |
| GET | `/platform/v1/orgs/{id}/summary` | Client tab + admin tab |
| POST | `/platform/v1/orgs/{id}/sso-token` | SSO login button |

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
│       └── paneldns.php       # English language strings
└── views/
    └── default/
        ├── manage_module.pdt      # Server list
        ├── manage_add_row.pdt     # Add server form
        ├── manage_edit_row.pdt    # Edit server form
        ├── tab_client_usage.pdt   # Client area — usage stats + SSO button
        ├── tab_admin_actions.pdt  # Admin area — org detail + re-sync
        └── service_info.pdt       # Compact service summary panel
```

---

## Security Notes

- The platform API key is stored encrypted by Blesta (`'encrypted' => 1` field flag).
- All HTTP requests use `CURLOPT_IPRESOLVE_V4` — IPv4 only, preventing IPv6 SSRF rebinding attacks.
- A private-IP check is applied to the resolved primary IP after every cURL response.
- The platform key is redacted to `[REDACTED]` before being passed to any log call.
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
