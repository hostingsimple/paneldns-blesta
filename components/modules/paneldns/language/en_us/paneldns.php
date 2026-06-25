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
$lang['paneldns.manage_add_row.field_ns1_hostname']      = 'NS1 Hostname';
$lang['paneldns.manage_add_row.field_ns1_hostname_note'] = 'Optional. First nameserver hostname shown in welcome emails for this server, e.g. ns1.example.com.';
$lang['paneldns.manage_add_row.field_ns2_hostname']      = 'NS2 Hostname';
$lang['paneldns.manage_add_row.field_ns2_hostname_note'] = 'Optional. Second nameserver hostname shown in welcome emails.';
$lang['paneldns.manage_add_row.field_ns3_hostname']      = 'NS3 Hostname';
$lang['paneldns.manage_add_row.field_ns3_hostname_note'] = 'Optional. Third nameserver hostname shown in welcome emails.';
$lang['paneldns.manage_add_row.field_ns4_hostname']      = 'NS4 Hostname';
$lang['paneldns.manage_add_row.field_ns4_hostname_note'] = 'Optional. Fourth nameserver hostname shown in welcome emails.';
$lang['paneldns.manage_add_row.field_soa_email']         = 'SOA Email';
$lang['paneldns.manage_add_row.field_soa_email_note']    = 'Optional. SOA contact email shown in welcome emails. Defaults to the PanelDNS org\'s configured value.';

$lang['paneldns.manage_edit_row.title']               = 'Edit PanelDNS Server';
$lang['paneldns.manage_edit_row.api_token_placeholder'] = 'Leave blank to keep current token';
$lang['paneldns.manage_edit_row.api_token_note']      = 'Stored encrypted. Leave blank to retain the current token. Enter a new token to replace it.';
$lang['paneldns.manage_edit_row.field_ns1_hostname']      = 'NS1 Hostname';
$lang['paneldns.manage_edit_row.field_ns1_hostname_note'] = 'Optional. First nameserver hostname shown in welcome emails for this server.';
$lang['paneldns.manage_edit_row.field_ns2_hostname']      = 'NS2 Hostname';
$lang['paneldns.manage_edit_row.field_ns2_hostname_note'] = 'Optional. Second nameserver hostname shown in welcome emails.';
$lang['paneldns.manage_edit_row.field_ns3_hostname']      = 'NS3 Hostname';
$lang['paneldns.manage_edit_row.field_ns3_hostname_note'] = 'Optional. Third nameserver hostname shown in welcome emails.';
$lang['paneldns.manage_edit_row.field_ns4_hostname']      = 'NS4 Hostname';
$lang['paneldns.manage_edit_row.field_ns4_hostname_note'] = 'Optional. Fourth nameserver hostname shown in welcome emails.';
$lang['paneldns.manage_edit_row.field_soa_email']         = 'SOA Email';
$lang['paneldns.manage_edit_row.field_soa_email_note']    = 'Optional. SOA contact email shown in welcome emails. Defaults to the PanelDNS org\'s configured value.';

// Package fields (per-product configuration)
$lang['paneldns.package_fields.zone_limit']      = 'Zone Limit';
$lang['paneldns.package_fields.zone_limit_note'] = 'Maximum DNS zones for this sub-client. 0 = inherit the reseller\'s org-level limit.';
$lang['paneldns.package_fields.max_records']     = 'Record Limit';
$lang['paneldns.package_fields.max_records_note'] = 'Maximum DNS records across all zones. 0 = inherit the reseller\'s org-level limit.';
$lang['paneldns.package_fields.send_welcome_email']      = 'Send Welcome Email';
$lang['paneldns.package_fields.send_welcome_email_note'] = 'When the account is provisioned, email the customer a 60-second portal SSO login link.';
$lang['paneldns.package_fields.send_welcome_email_yes']  = 'Yes';
$lang['paneldns.package_fields.send_welcome_email_no']   = 'No';
$lang['paneldns.package_fields.ns1_hostname']      = 'NS1 Hostname (Welcome Email)';
$lang['paneldns.package_fields.ns1_hostname_note'] = 'Optional. Overrides the server-level NS1 hostname shown to this package\'s customers in the welcome email.';
$lang['paneldns.package_fields.ns2_hostname']      = 'NS2 Hostname (Welcome Email)';
$lang['paneldns.package_fields.ns2_hostname_note'] = 'Optional. Second nameserver hostname for the welcome email.';
$lang['paneldns.package_fields.ns3_hostname']      = 'NS3 Hostname (Welcome Email)';
$lang['paneldns.package_fields.ns3_hostname_note'] = 'Optional. Third nameserver hostname for the welcome email.';
$lang['paneldns.package_fields.ns4_hostname']      = 'NS4 Hostname (Welcome Email)';
$lang['paneldns.package_fields.ns4_hostname_note'] = 'Optional. Fourth nameserver hostname for the welcome email.';
$lang['paneldns.package_fields.soa_email']      = 'SOA Email (Welcome Email)';
$lang['paneldns.package_fields.soa_email_note'] = 'Optional. SOA contact email shown in the welcome email. Defaults to the org\'s configured value.';
$lang['paneldns.package_fields.auto_create_zone']      = 'Auto-Create Zone on Domain Order';
$lang['paneldns.package_fields.auto_create_zone_note'] = 'Automatically create a matching DNS zone when a domain is registered or transferred for this client.';
$lang['paneldns.package_fields.auto_create_zone_yes']  = 'Yes';
$lang['paneldns.package_fields.auto_create_zone_no']   = 'No';
$lang['paneldns.package_fields.auto_delete_zone']      = 'Auto-Delete Zone on Domain Expiry';
$lang['paneldns.package_fields.auto_delete_zone_note'] = 'Remove the DNS zone when a domain is deleted or expires. Disabled by default — enable only if clients do not need zone data after expiry.';
$lang['paneldns.package_fields.auto_delete_zone_yes']  = 'Yes';
$lang['paneldns.package_fields.auto_delete_zone_no']   = 'No';
$lang['paneldns.package_fields.grace_period']      = 'Termination Grace Period (Days)';
$lang['paneldns.package_fields.grace_period_note'] = 'Days to wait before permanently deleting the sub-client after termination. 0 = delete immediately. During the grace period the sub-client is suspended in PanelDNS.';

// Service fields (stored per provisioned account)
$lang['paneldns.service_fields.sub_client_id']        = 'PanelDNS Sub-client ID';
$lang['paneldns.service_fields.sub_client_email']     = 'Sub-client Email';
$lang['paneldns.service_fields.grace_period_deadline'] = 'Grace Period Deadline';

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

// ── Embedded DNS Manager — client area ───────────────────────────────────────

// Zone list page
$lang['paneldns.dns.zones_heading']        = 'Your DNS Zones';
$lang['paneldns.dns.zones_col_name']       = 'Zone Name';
$lang['paneldns.dns.zones_col_records']    = 'Records';
$lang['paneldns.dns.zones_col_status']     = 'Status';
$lang['paneldns.dns.zones_col_created']    = 'Created';
$lang['paneldns.dns.zones_col_actions']    = 'Actions';
$lang['paneldns.dns.zones_empty']          = 'You have no DNS zones yet.';
$lang['paneldns.dns.zones_add_button']     = 'Add Zone';
$lang['paneldns.dns.zones_import_button']  = 'Import Zone';
$lang['paneldns.dns.zone_manage_button']   = 'Manage Records';
$lang['paneldns.dns.zone_export_button']   = 'Export (BIND)';
$lang['paneldns.dns.zone_delete_button']   = 'Delete';
$lang['paneldns.dns.zone_delete_confirm']  = 'Are you sure you want to delete this zone and all its records? This cannot be undone.';

// Zone create form
$lang['paneldns.dns.zone_create_heading']       = 'Add DNS Zone';
$lang['paneldns.dns.zone_create_name_label']    = 'Zone Name';
$lang['paneldns.dns.zone_create_name_placeholder'] = 'example.com';
$lang['paneldns.dns.zone_create_name_note']     = 'Enter the domain name for the zone, e.g. example.com. Subdomains are allowed.';
$lang['paneldns.dns.zone_create_submit']        = 'Create Zone';
$lang['paneldns.dns.zone_create_cancel']        = 'Cancel';

// Zone import form
$lang['paneldns.dns.zone_import_heading']        = 'Import Zone (BIND Format)';
$lang['paneldns.dns.zone_import_zone_label']     = 'Zone';
$lang['paneldns.dns.zone_import_zone_note']      = 'Select the zone to import records into.';
$lang['paneldns.dns.zone_import_bind_label']     = 'BIND Zone Data';
$lang['paneldns.dns.zone_import_bind_placeholder'] = 'Paste BIND-format zone text here ($ORIGIN, $TTL, resource records...)';
$lang['paneldns.dns.zone_import_bind_note']      = 'Paste the contents of a standard BIND zone file. Maximum 512 KB.';
$lang['paneldns.dns.zone_import_submit']         = 'Import Records';
$lang['paneldns.dns.zone_import_cancel']         = 'Cancel';

// Records page
$lang['paneldns.dns.records_heading']         = 'DNS Records';
$lang['paneldns.dns.records_back_to_zones']   = 'Back to Zones';
$lang['paneldns.dns.records_add_button']      = 'Add Record';
$lang['paneldns.dns.records_col_name']        = 'Name';
$lang['paneldns.dns.records_col_type']        = 'Type';
$lang['paneldns.dns.records_col_content']     = 'Content';
$lang['paneldns.dns.records_col_ttl']         = 'TTL';
$lang['paneldns.dns.records_col_priority']    = 'Priority';
$lang['paneldns.dns.records_col_actions']     = 'Actions';
$lang['paneldns.dns.records_empty']           = 'No records found for this zone.';
$lang['paneldns.dns.record_edit_button']      = 'Edit';
$lang['paneldns.dns.record_delete_button']    = 'Delete';
$lang['paneldns.dns.record_delete_confirm']   = 'Delete this DNS record?';
$lang['paneldns.dns.record_save_button']      = 'Save';
$lang['paneldns.dns.record_cancel_button']    = 'Cancel';

// Record form labels
$lang['paneldns.dns.record_name_label']       = 'Name';
$lang['paneldns.dns.record_name_placeholder'] = '@ or subdomain';
$lang['paneldns.dns.record_name_note']        = 'Use @ for the zone apex or enter a subdomain label.';
$lang['paneldns.dns.record_type_label']       = 'Type';
$lang['paneldns.dns.record_content_label']    = 'Content / Value';
$lang['paneldns.dns.record_content_placeholder'] = 'Record value';
$lang['paneldns.dns.record_ttl_label']        = 'TTL (seconds)';
$lang['paneldns.dns.record_ttl_placeholder']  = '3600';
$lang['paneldns.dns.record_ttl_note']         = 'Minimum 60 seconds. Common values: 300 (5 min), 3600 (1 hr), 86400 (1 day).';
$lang['paneldns.dns.record_priority_label']   = 'Priority';
$lang['paneldns.dns.record_priority_note']    = 'Required for MX and SRV records. Lower number = higher priority.';

// Nameservers card (NS-CARD-01)
$lang['paneldns.dns.ns_card_heading']   = 'Point Your Domain Here';
$lang['paneldns.dns.ns_card_intro']     = 'To use PanelDNS for this zone, update your domain\'s nameservers at your registrar to:';
$lang['paneldns.dns.ns_card_none']      = 'Contact support for nameserver details.';

// DNSSEC card (DNSSEC-01)
$lang['paneldns.dns.dnssec_heading']          = 'DNSSEC';
$lang['paneldns.dns.dnssec_status_enabled']   = 'Enabled';
$lang['paneldns.dns.dnssec_status_disabled']  = 'Disabled';
$lang['paneldns.dns.dnssec_algorithm_label']  = 'Algorithm';
$lang['paneldns.dns.dnssec_ds_heading']       = 'DS Records';
$lang['paneldns.dns.dnssec_ds_intro']         = 'Add these DS records at your domain registrar to complete DNSSEC setup:';
$lang['paneldns.dns.dnssec_ds_none']          = 'No DS records available yet. DS records appear after DNSSEC is enabled and the provider has signed the zone.';
$lang['paneldns.dns.dnssec_enable_button']    = 'Enable DNSSEC';
$lang['paneldns.dns.dnssec_disable_button']   = 'Disable DNSSEC';
$lang['paneldns.dns.dnssec_disable_confirm']  = 'Disable DNSSEC signing for this zone? Remove any DS records at your registrar first to avoid resolution failures.';
$lang['paneldns.dns.dnssec_last_synced']      = 'Last synced';
$lang['paneldns.dns.dnssec_unsupported']      = 'DNSSEC is not supported for this zone\'s DNS provider.';

// Flash messages — zone actions
$lang['paneldns.dns.flash_zone_created']      = 'Zone created successfully.';
$lang['paneldns.dns.flash_zone_deleted']      = 'Zone deleted.';
$lang['paneldns.dns.flash_zone_imported']     = 'Imported %s records into the zone.';
$lang['paneldns.dns.flash_zone_exported']     = 'Zone exported.';

// Flash messages — record actions
$lang['paneldns.dns.flash_record_added']      = 'Record added.';
$lang['paneldns.dns.flash_record_updated']    = 'Record updated.';
$lang['paneldns.dns.flash_record_deleted']    = 'Record deleted.';

// Flash messages — DNSSEC
$lang['paneldns.dns.flash_dnssec_enabled']    = 'DNSSEC enabled. Add the DS records below to your domain registrar to complete setup.';
$lang['paneldns.dns.flash_dnssec_disabled']   = 'DNSSEC disabled.';

// Flash messages — errors (generic)
$lang['paneldns.dns.flash_error_zone_not_found']    = 'Zone not found.';
$lang['paneldns.dns.flash_error_record_not_found']  = 'Record not found.';
$lang['paneldns.dns.flash_error_zone_create_failed'] = 'Failed to create zone.';
$lang['paneldns.dns.flash_error_zone_delete_failed'] = 'Zone deletion failed.';
$lang['paneldns.dns.flash_error_record_add_failed']  = 'Failed to add record.';
$lang['paneldns.dns.flash_error_record_update_failed'] = 'Failed to update record.';
$lang['paneldns.dns.flash_error_record_delete_failed'] = 'Failed to delete record.';
$lang['paneldns.dns.flash_error_import_failed']      = 'Import failed.';
$lang['paneldns.dns.flash_error_export_failed']      = 'Export failed. The zone may not have a DNS provider configured yet.';
$lang['paneldns.dns.flash_error_dnssec_enable_failed']  = 'DNSSEC enable failed.';
$lang['paneldns.dns.flash_error_dnssec_disable_failed'] = 'DNSSEC disable failed.';
$lang['paneldns.dns.flash_error_load_zones']   = 'Failed to load zones.';
$lang['paneldns.dns.flash_error_load_records'] = 'Failed to load records.';

// Validation error messages
$lang['paneldns.dns.error_zone_name_required']   = 'Zone name is required.';
$lang['paneldns.dns.error_zone_name_invalid']    = 'Invalid zone name. Use a valid domain name up to 253 characters with no consecutive dots.';
$lang['paneldns.dns.error_zone_name_too_long']   = 'Zone name must not exceed 253 characters.';
$lang['paneldns.dns.error_zone_id_required']     = 'Zone ID is required.';
$lang['paneldns.dns.error_bind_text_required']   = 'Paste BIND-format zone text.';
$lang['paneldns.dns.error_bind_text_too_large']  = 'Import data too large (max 512 KB).';
$lang['paneldns.dns.error_record_type_invalid']  = 'Invalid record type.';
$lang['paneldns.dns.error_record_name_invalid']  = 'Record name is invalid or too long.';
$lang['paneldns.dns.error_record_content_invalid'] = 'Record content is invalid or too long.';
$lang['paneldns.dns.error_record_ttl_minimum']   = 'TTL must be at least 60 seconds.';
$lang['paneldns.dns.error_quota_exceeded']       = 'You\'ve reached your zone limit (%s/%s). Please contact support to upgrade your plan.';

// Rate limit and CSRF errors
$lang['paneldns.dns.error_rate_limit']  = 'Too many requests. Please wait a moment and try again.';
$lang['paneldns.dns.error_csrf']        = 'Your session has expired or the form token is invalid. Please return to the previous page and try again.';

// ── Admin actions tab ─────────────────────────────────────────────────────────

$lang['paneldns.admin_actions.title']             = 'PanelDNS Sub-client Details';
$lang['paneldns.admin_actions.field_id']          = 'Sub-client ID';
$lang['paneldns.admin_actions.field_name']        = 'Name';
$lang['paneldns.admin_actions.field_email']       = 'Email';
$lang['paneldns.admin_actions.field_status']      = 'Status';
$lang['paneldns.admin_actions.field_zones']       = 'Zones Used / Limit';
$lang['paneldns.admin_actions.field_records']     = 'Records Used / Limit';
$lang['paneldns.admin_actions.zones_heading']     = 'DNS Zones';
$lang['paneldns.admin_actions.zones_col_name']    = 'Zone Name';
$lang['paneldns.admin_actions.zones_col_records'] = 'Records';
$lang['paneldns.admin_actions.zones_col_status']  = 'Status';
$lang['paneldns.admin_actions.zones_col_created'] = 'Created';
$lang['paneldns.admin_actions.zones_count']       = 'Total zones: %s';
$lang['paneldns.admin_actions.zones_empty']       = 'No zones found for this sub-client.';
$lang['paneldns.admin_actions.sync_button']       = 'Re-sync Status';
$lang['paneldns.admin_actions.sync_success']      = 'Sub-client status re-synced successfully.';
$lang['paneldns.admin_actions.resend_welcome_button'] = 'Resend Welcome Email';
$lang['paneldns.admin_actions.resend_welcome_success'] = 'Welcome email resent successfully.';
$lang['paneldns.admin_actions.resend_welcome_failed']  = 'Failed to resend welcome email.';
$lang['paneldns.admin_actions.view_in_paneldns']  = 'View in PanelDNS Admin';
$lang['paneldns.admin_actions.not_provisioned']   = 'This service has not been provisioned yet.';
$lang['paneldns.admin_actions.error']             = 'Could not load sub-client data. Check the module log for details.';
$lang['paneldns.admin_actions.grace_period_deadline'] = 'Grace Period Ends';

// Validation / error messages
$lang['paneldns.!error.name.empty']                   = 'Please enter a name for this server.';
$lang['paneldns.!error.base_url.empty']               = 'Please enter the Base URL for your PanelDNS installation.';
$lang['paneldns.!error.base_url.format']              = 'The Base URL must start with https:// or http://.';
$lang['paneldns.!error.api_token.empty']              = 'Please enter the API Token.';
$lang['paneldns.!error.api_token.invalid']            = 'The API Token is invalid or the server is unreachable. Check the token and Base URL.';
$lang['paneldns.!error.zone_limit.format']            = 'Zone Limit must be a whole number (0 or greater).';
$lang['paneldns.!error.max_records.format']           = 'Record Limit must be a whole number (0 or greater).';
$lang['paneldns.!error.sub_client_email.format']      = 'The email address is not valid.';
$lang['paneldns.!error.grace_period.format']          = 'Termination Grace Period must be a whole number (0 or greater).';
$lang['paneldns.!error.ns1_hostname.format']          = 'NS1 Hostname must be a valid hostname.';
$lang['paneldns.!error.ns2_hostname.format']          = 'NS2 Hostname must be a valid hostname.';
$lang['paneldns.!error.ns3_hostname.format']          = 'NS3 Hostname must be a valid hostname.';
$lang['paneldns.!error.ns4_hostname.format']          = 'NS4 Hostname must be a valid hostname.';
$lang['paneldns.!error.soa_email.format']             = 'SOA Email must be a valid email address.';
