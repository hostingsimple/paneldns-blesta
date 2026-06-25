<?php
/**
 * PanelDNS Blesta module — English language strings.
 * v2.0.0: targets /api/v1 sub-client API (reseller Bearer token).
 */

// Module labels
$lang['paneldns.name']        = 'PanelDNS';
$lang['paneldns.description'] = 'Sell DNS sub-client accounts powered by PanelDNS.';

// Module row (server) field labels used on the add/edit forms
$lang['paneldns.module_row']  = 'PanelDNS Server';
$lang['paneldns.module_rows'] = 'PanelDNS Servers';

$lang['paneldns.manage_add_row.title']       = 'Add PanelDNS Server';
$lang['paneldns.manage_add_row.description'] = 'Connect a PanelDNS reseller account. One Blesta server row = one PanelDNS reseller account.';
$lang['paneldns.manage_add_row.field_name']              = 'Server Name';
$lang['paneldns.manage_add_row.field_name_note']         = 'A friendly label for this PanelDNS reseller account.';
$lang['paneldns.manage_add_row.field_base_url']          = 'Base URL';
$lang['paneldns.manage_add_row.field_base_url_note']     = 'Root URL of your PanelDNS installation, e.g. https://app.paneldns.com';
$lang['paneldns.manage_add_row.field_api_token']         = 'API Token';
$lang['paneldns.manage_add_row.field_api_token_note']    = 'From PanelDNS → Settings → API Tokens. Needs sub_clients:read + sub_clients:write scopes. Stored encrypted.';

$lang['paneldns.manage_edit_row.title']               = 'Edit PanelDNS Server';
$lang['paneldns.manage_edit_row.api_token_placeholder'] = 'Leave blank to keep current token';
$lang['paneldns.manage_edit_row.api_token_note']      = 'Stored encrypted. Leave blank to retain the current token. Enter a new token to replace it.';

// Package fields (per-product configuration)
$lang['paneldns.package_fields.zone_limit']      = 'Zone Limit';
$lang['paneldns.package_fields.zone_limit_note'] = 'Maximum DNS zones for this sub-client. 0 = inherit the reseller\'s org-level limit.';
$lang['paneldns.package_fields.max_records']     = 'Record Limit';
$lang['paneldns.package_fields.max_records_note'] = 'Maximum DNS records across all zones. 0 = inherit the reseller\'s org-level limit.';

// Service fields (stored per provisioned account)
$lang['paneldns.service_fields.sub_client_id']    = 'PanelDNS Sub-client ID';
$lang['paneldns.service_fields.sub_client_email'] = 'Sub-client Email';

// Tab labels
$lang['paneldns.tab_client_usage']   = 'DNS Usage';
$lang['paneldns.tab_admin_actions']  = 'PanelDNS';

// Client usage tab
$lang['paneldns.client_usage.title']              = 'DNS Account Overview';
$lang['paneldns.client_usage.zones']              = 'DNS Zones';
$lang['paneldns.client_usage.records']            = 'DNS Records';
$lang['paneldns.client_usage.unlimited']          = 'Unlimited';
$lang['paneldns.client_usage.status']             = 'Status';
$lang['paneldns.client_usage.manage_dns_button']  = 'Manage DNS';
$lang['paneldns.client_usage.sso_note']           = 'Opens your DNS control panel. Link expires shortly.';
$lang['paneldns.client_usage.sso_unavailable']    = 'SSO login is temporarily unavailable. Please try again later.';
$lang['paneldns.client_usage.not_provisioned']    = 'Your DNS account has not been provisioned yet. Please contact support.';
$lang['paneldns.client_usage.error']              = 'Could not load account data. Please try again or contact support.';
$lang['paneldns.client_usage.suspended']          = 'Your DNS account is currently suspended. Please contact support to reactivate.';

// Admin actions tab
$lang['paneldns.admin_actions.title']             = 'PanelDNS Sub-client Details';
$lang['paneldns.admin_actions.field_id']          = 'Sub-client ID';
$lang['paneldns.admin_actions.field_name']        = 'Name';
$lang['paneldns.admin_actions.field_email']       = 'Email';
$lang['paneldns.admin_actions.field_status']      = 'Status';
$lang['paneldns.admin_actions.field_zones']       = 'Zones Used / Limit';
$lang['paneldns.admin_actions.field_records']     = 'Records Used / Limit';
$lang['paneldns.admin_actions.sync_button']       = 'Re-sync Status';
$lang['paneldns.admin_actions.sync_success']      = 'Sub-client status re-synced successfully.';
$lang['paneldns.admin_actions.view_in_paneldns']  = 'View in PanelDNS Admin';
$lang['paneldns.admin_actions.not_provisioned']   = 'This service has not been provisioned yet.';
$lang['paneldns.admin_actions.error']             = 'Could not load sub-client data. Check the module log for details.';

// Validation / error messages
$lang['paneldns.!error.name.empty']                   = 'Please enter a name for this server.';
$lang['paneldns.!error.base_url.empty']               = 'Please enter the Base URL for your PanelDNS installation.';
$lang['paneldns.!error.base_url.format']              = 'The Base URL must start with https:// or http://.';
$lang['paneldns.!error.api_token.empty']              = 'Please enter the API Token.';
$lang['paneldns.!error.api_token.invalid']            = 'The API Token is invalid or the server is unreachable. Check the token and Base URL.';
$lang['paneldns.!error.zone_limit.format']            = 'Zone Limit must be a whole number (0 or greater).';
$lang['paneldns.!error.max_records.format']           = 'Record Limit must be a whole number (0 or greater).';
$lang['paneldns.!error.sub_client_email.format']      = 'The email address is not valid.';
