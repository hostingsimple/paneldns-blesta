# PanelDNS — Blesta Provisioning Module

Sell DNS sub-client accounts as Blesta products. The module connects to your
PanelDNS reseller account via the `/api/v1` REST API using a reseller Bearer token
and drives the full service lifecycle: provision, suspend, unsuspend, terminate,
upgrade/downgrade, and includes a **fully embedded DNS zone and record manager** in
the client area — giving clients the same experience as the WHMCS and HostBill integrations.

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
| Grace period | Optional termination grace period; final DELETE fires on expiry via cron |
| Welcome email | SSO login link + nameservers sent to client on first provision; resendable from admin |
| GDPR consent | Legal version stamped at sub-client creation |
| Idempotent create | Re-provision an existing sub-client by patching status:active |
| Drift sync | Daily cron task reconciles Blesta service status with upstream |
| Grace expiry | Daily cron task finalises deletions after grace period ends |
| **Zone list** | Client sees all their DNS zones; create, delete, export |
| **Zone create** | Form with full name validation (253-char, strict regex, no `..`) |
| **Zone import** | Import BIND zone text additively into an existing zone (512 KB cap) |
| **Zone export** | Download BIND zone file directly |
| **Record manager** | Full record CRUD — inline edit, delete, add; 13 record types |
| **DNSSEC** | Enable/disable per zone; DS records shown |
| **Nameservers card** | Overview and per-zone NS instructions for registrar setup |
| **Zone health** | Overview tab flags non-active zones |
| **CSRF protection** | Per-service token, rotated on every successful mutation |
| **Rate limiting** | 60 req/min per sub-client via session sliding window |
| **Ownership checks** | Every zone/record action verifies `sub_client_id` matches — no ID guessing |
| Admin zone list | Admin tab shows first 20 zones with status badges |
| Admin re-sync | Admin button to refresh sub-client details |
| Admin resend welcome | Resend the SSO welcome email from admin tab |

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
   | NS1–NS4 Hostname | Nameservers shown to clients in the client area (e.g. `ns1.example.com`) |
   | SOA Email | Email shown in welcome emails as the SOA contact |

   The token is validated against `/api/v1/licence-status` on save.

4. **Create a product** in Blesta Admin:  
   `Packages → Create Package` → choose **PanelDNS** as the module.  
   In the **Module Options** section configure:

   | Field | Description |
   |---|---|
   | Zone Limit | Maximum DNS zones. `0` = inherit reseller org limit |
   | Record Limit | Maximum DNS records. `0` = inherit reseller org limit |
   | Send Welcome Email | Send SSO login link to client on first provision |
   | Auto Create Zone | Automatically create a DNS zone when a domain is ordered (requires hook wiring) |
   | Auto Delete Zone | Automatically delete the zone when a domain expires |
   | Grace Period (days) | Days between termination and final deletion (0 = immediate) |

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

## Cron Tasks

The module registers two cron tasks. Blesta's cron must be running:

```bash
# Add to your server crontab
*/5 * * * * php /path/to/blesta/index.php cron > /dev/null 2>&1
```

| Task | Schedule | Description |
|---|---|---|
| `paneldns_drift_sync` | Daily 08:00 | Reconciles Blesta service status with upstream sub-client status |
| `paneldns_grace_expiry` | Daily 09:00 | Finalises deletions for services past their grace period deadline |

The drift sync:
- Fetches upstream sub-client status for every active/suspended PanelDNS service.
- Stamps the Blesta service `suspended` if the sub-client is suspended upstream.
- Stamps the Blesta service `canceled` if the sub-client has been deleted upstream (404).
- Processes up to 100 services per run.

The grace expiry:
- Scans all terminated services with a non-empty `grace_period_deadline` field.
- For each where the deadline date is today or in the past: calls `DELETE /api/v1/sub-clients/{id}`.
- Clears the deadline field after successful deletion.

---

## API Endpoints Used

All under `/api/v1` (Bearer token auth):

| Method | Endpoint | Used by |
|---|---|---|
| GET | `/api/v1/licence-status` | Credential validation on server save |
| GET | `/api/v1/legal-version` | GDPR consent stamp at creation |
| POST | `/api/v1/sub-clients` | `addService` |
| GET | `/api/v1/sub-clients/{id}` | Drift sync |
| PATCH | `/api/v1/sub-clients/{id}` | `suspendService`, `unsuspendService`, `changeServicePackage` |
| DELETE | `/api/v1/sub-clients/{id}` | `cancelService` (and grace expiry cron) |
| GET | `/api/v1/sub-clients/{id}/summary` | Client tab + admin tab |
| POST | `/api/v1/sub-clients/{id}/sso-token` | SSO "Open Full Portal" button + welcome email |
| GET | `/api/v1/org/nameservers` | Nameservers shown in client area |
| GET | `/api/v1/zones` | Zone list in client area and admin tab |
| POST | `/api/v1/zones` | Zone create |
| DELETE | `/api/v1/zones/{id}` | Zone delete |
| GET | `/api/v1/zones/{id}/records` | Record list |
| POST | `/api/v1/zones/{id}/records` | Record create |
| PATCH | `/api/v1/zones/{id}/records/{rid}` | Record update |
| DELETE | `/api/v1/zones/{id}/records/{rid}` | Record delete |
| GET | `/api/v1/zones/{id}/export` | Zone export (BIND) |
| POST | `/api/v1/zones/{id}/import` | Zone import (BIND) |
| GET | `/api/v1/zones/{id}/dnssec` | DNSSEC status |
| POST | `/api/v1/zones/{id}/dnssec` | DNSSEC toggle |

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
        ├── manage_add_row.pdt     # Add server form (includes NS1–4 + SOA fields)
        ├── manage_edit_row.pdt    # Edit server form
        ├── tab_client_usage.pdt   # Client area — overview + usage tiles + NS card
        ├── zones.pdt              # Client area — zone list
        ├── records.pdt            # Client area — zone records + DNSSEC card
        ├── zone_create.pdt        # Client area — create zone form
        ├── zone_import.pdt        # Client area — import BIND zone form
        ├── tab_admin_actions.pdt  # Admin area — sub-client detail + zone list + buttons
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
- **CSRF**: every client-area mutation is protected by a per-service session token, rotated after each successful change.
- **Rate limiting**: 60 requests/minute per sub-client (session-based sliding window). Clients exceeding this see a generic error; no API calls are made.
- **Ownership enforcement**: zone and record actions resolve the zone's `sub_client_id` from the API and compare it to the authenticated sub-client before any mutation — a client cannot act on another client's zones by guessing an ID.
- **Record type allowlist**: only A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, PTR, TLSA, SSHFP, HTTPS, NAPTR are accepted server-side, regardless of what the client POSTs.
- **Input caps**: zone names ≤ 253 chars, record names ≤ 253 chars, record content ≤ 4096 chars, BIND import ≤ 512 KB.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Support

Open an issue at [github.com/hostingsimple/paneldns-blesta](https://github.com/hostingsimple/paneldns-blesta)
or contact support via [paneldns.com](https://paneldns.com).
