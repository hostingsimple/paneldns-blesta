<?php
/**
 * PanelDNS Blesta module — English language strings.
 */

// Module labels
$lang['paneldns.name'] = 'PanelDNS';
$lang['paneldns.description'] = 'Sell white-label DNS reseller accounts powered by PanelDNS.';

// Module row (server) fields
$lang['paneldns.module_row'] = 'PanelDNS Server';
$lang['paneldns.module_rows'] = 'PanelDNS Servers';
$lang['paneldns.module_row.name'] = 'Server Name';
$lang['paneldns.module_row.name_note'] = 'A friendly label for this PanelDNS installation.';
$lang['paneldns.module_row.base_url'] = 'Base URL';
$lang['paneldns.module_row.base_url_note'] = 'The root URL of your PanelDNS installation, e.g. https://app.paneldns.com';
$lang['paneldns.module_row.platform_key'] = 'Platform API Key';
$lang['paneldns.module_row.platform_key_note'] = 'Found at PanelDNS Admin → Settings → API Keys → Platform Key.';

// Package / service fields
$lang['paneldns.package_fields.plan_slug'] = 'PanelDNS Plan';
$lang['paneldns.package_fields.plan_slug_note'] = 'The plan to assign to new reseller orgs created from this package.';

$lang['paneldns.service_fields.org_id'] = 'PanelDNS Org ID';
$lang['paneldns.service_fields.org_slug'] = 'Org Slug';
$lang['paneldns.service_fields.reseller_email'] = 'Reseller Email';
$lang['paneldns.service_fields.sso_url'] = 'Portal URL';

// Tab labels
$lang['paneldns.tab_client_usage'] = 'DNS Usage';
$lang['paneldns.tab_admin_actions'] = 'PanelDNS';

// Client usage tab strings
$lang['paneldns.client_usage.title'] = 'DNS Account Overview';
$lang['paneldns.client_usage.login_btn'] = 'Manage DNS';
$lang['paneldns.client_usage.zones'] = 'DNS Zones';
$lang['paneldns.client_usage.clients'] = 'Sub-Clients';
$lang['paneldns.client_usage.seats'] = 'Admin Seats';
$lang['paneldns.client_usage.api_calls'] = 'API Calls (this period)';
$lang['paneldns.client_usage.unlimited'] = 'Unlimited';
$lang['paneldns.client_usage.plan'] = 'Plan';
$lang['paneldns.client_usage.status'] = 'Status';
$lang['paneldns.client_usage.not_provisioned'] = 'Your DNS account has not been provisioned yet. Please contact support.';
$lang['paneldns.client_usage.error'] = 'Could not load account data. Please try again or contact support.';
$lang['paneldns.client_usage.suspended'] = 'Your DNS account is currently suspended. Please contact support to reactivate.';

// Admin actions tab strings
$lang['paneldns.admin_actions.title'] = 'PanelDNS Org Details';
$lang['paneldns.admin_actions.org_id'] = 'Org ID';
$lang['paneldns.admin_actions.org_slug'] = 'Org Slug';
$lang['paneldns.admin_actions.plan'] = 'Plan';
$lang['paneldns.admin_actions.status'] = 'Status';
$lang['paneldns.admin_actions.zones_used'] = 'Zones Used / Limit';
$lang['paneldns.admin_actions.clients_used'] = 'Sub-Clients Used / Limit';
$lang['paneldns.admin_actions.api_calls'] = 'API Calls (period)';
$lang['paneldns.admin_actions.open_admin'] = 'Open in PanelDNS Admin';
$lang['paneldns.admin_actions.sync_btn'] = 'Re-sync Status';
$lang['paneldns.admin_actions.sync_success'] = 'Status re-synced successfully.';
$lang['paneldns.admin_actions.not_provisioned'] = 'This service has not been provisioned yet.';
$lang['paneldns.admin_actions.error'] = 'Could not load org data. Check the module log for details.';

// Validation / error messages
$lang['paneldns.!error.base_url.empty'] = 'Please enter the Base URL for your PanelDNS installation.';
$lang['paneldns.!error.base_url.format'] = 'The Base URL must start with https:// or http://.';
$lang['paneldns.!error.platform_key.empty'] = 'Please enter the Platform API Key.';
$lang['paneldns.!error.platform_key.invalid'] = 'The Platform API Key is invalid or the server is unreachable. Check the key and Base URL.';
$lang['paneldns.!error.name.empty'] = 'Please enter a name for this server.';
$lang['paneldns.!error.plan_slug.empty'] = 'Please select a PanelDNS plan for this package.';
$lang['paneldns.!error.reseller_email.empty'] = 'A reseller email address is required.';
$lang['paneldns.!error.reseller_email.format'] = 'The reseller email address is not valid.';
