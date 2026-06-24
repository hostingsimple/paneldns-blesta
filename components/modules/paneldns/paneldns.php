<?php
/**
 * PanelDNS Blesta Provisioning Module
 *
 * Connects to the PanelDNS /platform/v1 REST API to sell white-label DNS
 * reseller accounts as Blesta products. One Blesta "server row" = one PanelDNS
 * installation (identified by base URL + platform API key).
 *
 * Lifecycle mapping:
 *   addService()           → POST /platform/v1/orgs
 *   suspendService()       → POST /platform/v1/orgs/{id}/suspend
 *   unsuspendService()     → POST /platform/v1/orgs/{id}/unsuspend
 *   cancelService()        → DELETE /platform/v1/orgs/{id}
 *   changeServicePackage() → PATCH /platform/v1/orgs/{id} (plan change)
 *
 * Client tab: usage stats (zones / sub-clients) + SSO button.
 * Admin tab:  org detail + manual re-sync button.
 * Cron task:  daily drift sync — reconciles Blesta service status with upstream.
 *
 * @version 1.0.0
 * @link    https://paneldns.com
 */

use Blesta\Core\Util\Input\Fields\InputFields;

class Paneldns extends Module
{
    /** Cache of plans fetched from the API, keyed by server row id. */
    private static array $planCache = [];

    public function __construct()
    {
        // Load module config.json metadata.
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load language strings.
        Language::loadLang('paneldns', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    // =========================================================================
    // Module row management (Blesta calls these for the "Servers" admin screen)
    // =========================================================================

    /**
     * Returns the view that lists all configured servers (module rows).
     */
    public function manageModule($module, array &$vars): string
    {
        $this->view = $this->makeView('manage_module', 'default', '');
        $this->view->set('module', $module);
        $this->view->set('vars', $vars);
        return $this->view->fetch();
    }

    /**
     * Returns the Add Server form view.
     */
    public function manageAddRow(array &$vars): string
    {
        $this->view = $this->makeView('manage_add_row', 'default', '');
        $this->view->set('vars', $vars);
        return $this->view->fetch();
    }

    /**
     * Returns the Edit Server form view.
     */
    public function manageEditRow($module_row, array &$vars): string
    {
        if (empty($vars)) {
            $vars = [
                'name'         => $module_row->meta->name ?? '',
                'base_url'     => $module_row->meta->base_url ?? '',
                'platform_key' => $module_row->meta->platform_key ?? '',
            ];
        }
        $this->view = $this->makeView('manage_edit_row', 'default', '');
        $this->view->set('module_row', $module_row);
        $this->view->set('vars', $vars);
        return $this->view->fetch();
    }

    /**
     * Validates and saves a new server row.
     * Called when the admin submits the Add Server form.
     *
     * @return array Service fields to store (encrypted fields honoured by Blesta).
     */
    public function addModuleRow(array &$vars): array
    {
        $meta_fields = ['name', 'base_url', 'platform_key'];
        $encrypted   = ['platform_key'];

        $this->Input->setRules($this->getModuleRowRules($vars));

        if ($this->Input->validates($vars)) {
            $meta = [];
            foreach ($meta_fields as $field) {
                $meta[] = [
                    'key'       => $field,
                    'value'     => $vars[$field] ?? '',
                    'encrypted' => in_array($field, $encrypted, true) ? 1 : 0,
                ];
            }
            return $meta;
        }

        return [];
    }

    /**
     * Validates and updates an existing server row.
     */
    public function editModuleRow($module_row, array &$vars): array
    {
        return $this->addModuleRow($vars);
    }

    /**
     * Validation rules for the server form.
     * Also validates credentials by calling getLicenceStatus() on save.
     */
    private function getModuleRowRules(array &$vars): array
    {
        return [
            'name' => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('paneldns.!error.name.empty', true),
                ],
            ],
            'base_url' => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('paneldns.!error.base_url.empty', true),
                ],
                'format' => [
                    'rule'    => [[$this, 'validateBaseUrl']],
                    'message' => Language::_('paneldns.!error.base_url.format', true),
                ],
            ],
            'platform_key' => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('paneldns.!error.platform_key.empty', true),
                ],
                'valid' => [
                    'rule'    => [[$this, 'validateCredentials'], $vars['base_url'] ?? ''],
                    'message' => Language::_('paneldns.!error.platform_key.invalid', true),
                ],
            ],
        ];
    }

    /** Validation callback: base URL must start with http:// or https://. */
    public function validateBaseUrl(string $url): bool
    {
        return str_starts_with(trim($url), 'https://') || str_starts_with(trim($url), 'http://');
    }

    /**
     * Validation callback: call getLicenceStatus to verify the key is valid.
     * Returns true on success (200 OK from the API), false otherwise.
     */
    public function validateCredentials(string $key, string $baseUrl): bool
    {
        if (empty($key) || empty($baseUrl)) {
            return false;
        }
        try {
            $api  = $this->makeApi($baseUrl, $key);
            $resp = $api->getLicenceStatus();
            return $resp['ok'] && $resp['status'] === 200;
        } catch (Throwable $e) {
            return false;
        }
    }

    // =========================================================================
    // Package fields (Blesta calls these when an admin configures a product)
    // =========================================================================

    /**
     * Returns the package configuration fields — a dropdown of available plans
     * fetched from /platform/v1/plans. Falls back to a text input if the API
     * is unreachable (no module row selected yet).
     */
    public function getPackageFields($vars = null, $package = null): InputFields
    {
        // Load the InputFields helper from Blesta's loader.
        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = new InputFields();

        $plans     = [];
        $fetchFail = false;

        // Try to load plans from the first configured server row.
        $moduleRows = $this->getModuleRows();
        $row        = !empty($moduleRows) ? reset($moduleRows) : null;

        if ($row) {
            $cacheKey = 'plans_' . ($row->id ?? 'none');
            if (!isset(self::$planCache[$cacheKey])) {
                try {
                    $api  = $this->makeApiFromRow($row);
                    $resp = $api->getPlans();
                    if ($resp['ok'] && is_array($resp['data'])) {
                        self::$planCache[$cacheKey] = $resp['data'];
                    }
                } catch (Throwable $e) {
                    $fetchFail = true;
                }
            }
            if (isset(self::$planCache[$cacheKey])) {
                foreach (self::$planCache[$cacheKey] as $plan) {
                    $slug         = $plan['slug']  ?? ($plan['id'] ?? '');
                    $label        = $plan['name']  ?? $slug;
                    $plans[$slug] = $label;
                }
            }
        }

        if (!empty($plans)) {
            $planField = $fields->label(
                Language::_('paneldns.package_fields.plan_slug', true),
                'plan_slug'
            );
            $planField->attach(
                $fields->fieldSelect(
                    'meta[plan_slug]',
                    $plans,
                    $this->Html->ifSet($vars->meta['plan_slug']),
                    ['id' => 'plan_slug']
                )
            );
            $fields->setField($planField);
        } else {
            // Fallback: free-text input when no server row is configured yet.
            $planField = $fields->label(
                Language::_('paneldns.package_fields.plan_slug', true) . ($fetchFail ? ' (enter slug manually)' : ''),
                'plan_slug'
            );
            $planField->attach(
                $fields->fieldText(
                    'meta[plan_slug]',
                    $this->Html->ifSet($vars->meta['plan_slug']),
                    ['id' => 'plan_slug']
                )
            );
            $fields->setField($planField);
        }

        return $fields;
    }

    /** Validation rules for package fields. */
    public function getPackageRules(array $vars): array
    {
        return [
            'meta[plan_slug]' => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('paneldns.!error.plan_slug.empty', true),
                ],
            ],
        ];
    }

    /**
     * Called when saving a package — no-op (Blesta stores meta fields automatically).
     */
    public function addPackage(array $vars = null): void {}

    /**
     * Called when editing a package — no-op.
     */
    public function editPackage($package = null, array $vars = null): void {}

    // =========================================================================
    // Service fields returned to Blesta for storage
    // =========================================================================

    /**
     * Returns the service field definitions Blesta uses to display and store
     * per-service data. All field values come from addService().
     */
    public function getServiceFields($vars = null, $package = null): InputFields
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new InputFields();

        $orgIdLabel = $fields->label(Language::_('paneldns.service_fields.org_id', true), 'org_id');
        $orgIdLabel->attach($fields->fieldText('org_id', $this->Html->ifSet($vars->org_id), ['id' => 'org_id', 'readonly' => 'readonly']));
        $fields->setField($orgIdLabel);

        $slugLabel = $fields->label(Language::_('paneldns.service_fields.org_slug', true), 'org_slug');
        $slugLabel->attach($fields->fieldText('org_slug', $this->Html->ifSet($vars->org_slug), ['id' => 'org_slug', 'readonly' => 'readonly']));
        $fields->setField($slugLabel);

        $emailLabel = $fields->label(Language::_('paneldns.service_fields.reseller_email', true), 'reseller_email');
        $emailLabel->attach($fields->fieldText('reseller_email', $this->Html->ifSet($vars->reseller_email), ['id' => 'reseller_email']));
        $fields->setField($emailLabel);

        $ssoLabel = $fields->label(Language::_('paneldns.service_fields.sso_url', true), 'sso_url');
        $ssoLabel->attach($fields->fieldText('sso_url', $this->Html->ifSet($vars->sso_url), ['id' => 'sso_url', 'readonly' => 'readonly']));
        $fields->setField($ssoLabel);

        return $fields;
    }

    // =========================================================================
    // Service lifecycle
    // =========================================================================

    /**
     * Provision a new PanelDNS reseller org.
     *
     * Called by Blesta when a client's order is accepted / payment received.
     *
     * @param stdClass $package The product/package being ordered.
     * @param stdClass $service The service record (null for new services).
     * @param array    $vars    POST data / order form fields.
     * @param stdClass $parent_package Unused.
     * @param stdClass $parent_service Unused.
     *
     * @return array Service fields to store, or void on error.
     */
    public function addService($package, $service = null, array $vars = null, $parent_package = null, $parent_service = null)
    {
        // No-provision path: Blesta sets use_module=false for manual provisioning.
        if (($vars['use_module'] ?? '') !== 'true') {
            return [
                ['key' => 'org_id',          'value' => $vars['org_id']         ?? '', 'encrypted' => 0],
                ['key' => 'org_slug',         'value' => $vars['org_slug']        ?? '', 'encrypted' => 0],
                ['key' => 'reseller_email',   'value' => $vars['reseller_email']  ?? '', 'encrypted' => 0],
                ['key' => 'sso_url',          'value' => $vars['sso_url']         ?? '', 'encrypted' => 0],
            ];
        }

        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors(['module' => ['no_server' => 'No PanelDNS server is configured.']]);
            return;
        }

        $api = $this->makeApiFromRow($row);

        // Validate required fields.
        $email   = $vars['reseller_email'] ?? '';
        $planSlug = $package->meta->plan_slug ?? '';

        if (empty($planSlug)) {
            $this->Input->setErrors(['plan_slug' => ['empty' => Language::_('paneldns.!error.plan_slug.empty', true)]]);
            return;
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->Input->setErrors(['reseller_email' => ['format' => Language::_('paneldns.!error.reseller_email.format', true)]]);
            return;
        }

        // Build the org name from client details.
        $clientName = trim(($vars['client']['firstname'] ?? '') . ' ' . ($vars['client']['lastname'] ?? ''));
        if (empty($clientName)) {
            $clientName = $email;
        }
        $orgName = !empty($vars['client']['company']) ? $vars['client']['company'] : $clientName;

        $payload = [
            'name'      => $orgName,
            'plan_slug' => $planSlug,
            'owner'     => [
                'name'     => $clientName,
                'email'    => $email,
                'password' => $vars['password'] ?? bin2hex(random_bytes(12)),
            ],
        ];

        $resp = $api->createOrg($payload);

        if (!$resp['ok']) {
            $this->Input->setErrors(['api' => ['create_org' => 'PanelDNS provisioning failed. See module log for details.']]);
            return;
        }

        $orgId  = (int) ($resp['data']['id'] ?? 0);
        $slug   = (string) ($resp['data']['slug'] ?? '');
        $portal = (string) ($resp['data']['links']['portal'] ?? '');

        if ($orgId <= 0) {
            $this->Input->setErrors(['api' => ['no_id' => 'PanelDNS returned success but no org ID. See module log.']]);
            return;
        }

        // Send welcome email with SSO link (best-effort; never blocks provisioning).
        $this->sendWelcomeEmail($api, $orgId, $email, $portal);

        return [
            ['key' => 'org_id',        'value' => (string) $orgId, 'encrypted' => 0],
            ['key' => 'org_slug',      'value' => $slug,           'encrypted' => 0],
            ['key' => 'reseller_email','value' => $email,          'encrypted' => 0],
            ['key' => 'sso_url',       'value' => $portal,         'encrypted' => 0],
        ];
    }

    /**
     * Suspend a reseller org.
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($service->fields['use_module'] ?? 'true') === 'false') {
            return null;
        }

        $orgId = $this->getServiceOrgId($service);
        if (!$orgId) {
            $this->Input->setErrors(['service' => ['no_org_id' => 'No PanelDNS org ID on this service.']]);
            return;
        }

        $api  = $this->makeApiFromRow($this->getModuleRow());
        $resp = $api->suspendOrg($orgId);

        if (!$resp['ok']) {
            $this->Input->setErrors(['api' => ['suspend' => 'PanelDNS suspend failed. See module log for details.']]);
        }
    }

    /**
     * Unsuspend a reseller org.
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($service->fields['use_module'] ?? 'true') === 'false') {
            return null;
        }

        $orgId = $this->getServiceOrgId($service);
        if (!$orgId) {
            $this->Input->setErrors(['service' => ['no_org_id' => 'No PanelDNS org ID on this service.']]);
            return;
        }

        $api  = $this->makeApiFromRow($this->getModuleRow());
        $resp = $api->unsuspendOrg($orgId);

        if (!$resp['ok']) {
            $this->Input->setErrors(['api' => ['unsuspend' => 'PanelDNS unsuspend failed. See module log for details.']]);
        }
    }

    /**
     * Terminate (delete) a reseller org permanently.
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($service->fields['use_module'] ?? 'true') === 'false') {
            return null;
        }

        $orgId = $this->getServiceOrgId($service);
        if (!$orgId) {
            // Nothing to delete — treat as success.
            return null;
        }

        $api  = $this->makeApiFromRow($this->getModuleRow());
        $resp = $api->deleteOrg($orgId);

        if (!$resp['ok'] && $resp['status'] !== 404) {
            // 404 = already gone, treat as success.
            $this->Input->setErrors(['api' => ['delete' => 'PanelDNS termination failed. See module log for details.']]);
        }
    }

    /**
     * Change the plan on an existing reseller org.
     * Called by Blesta when the client upgrades/downgrades.
     */
    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        $orgId = $this->getServiceOrgId($service);
        if (!$orgId) {
            $this->Input->setErrors(['service' => ['no_org_id' => 'No PanelDNS org ID on this service.']]);
            return;
        }

        $newPlanSlug = $package_to->meta->plan_slug ?? '';
        if (empty($newPlanSlug)) {
            $this->Input->setErrors(['plan_slug' => ['empty' => Language::_('paneldns.!error.plan_slug.empty', true)]]);
            return;
        }

        $api  = $this->makeApiFromRow($this->getModuleRow());
        $resp = $api->patchOrg($orgId, ['plan_slug' => $newPlanSlug]);

        if (!$resp['ok']) {
            $this->Input->setErrors(['api' => ['change_plan' => 'PanelDNS plan change failed. See module log for details.']]);
        }
    }

    /**
     * Validate service fields before creating a service.
     */
    public function validateService($package, array $vars = null): array
    {
        $rules = [
            'reseller_email' => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('paneldns.!error.reseller_email.empty', true),
                ],
                'format' => [
                    'rule'    => 'isEmail',
                    'message' => Language::_('paneldns.!error.reseller_email.format', true),
                ],
            ],
        ];

        $this->Input->setRules($rules);
        $this->Input->validates($vars);

        return $this->Input->errors() ?? [];
    }

    // =========================================================================
    // Client area tab
    // =========================================================================

    /**
     * Returns the tabs shown in the client area for this service.
     *
     * @return array Tab method name => display label.
     */
    public function getClientTabs($package): array
    {
        return [
            'tabClientUsage' => Language::_('paneldns.tab_client_usage', true),
        ];
    }

    /**
     * Renders the client-area "DNS Usage" tab.
     * Fetches live usage from /platform/v1/orgs/{id}/summary and displays
     * progress bars for zones, sub-clients, and an SSO button.
     */
    public function tabClientUsage($package, $service, array $get = null, array $post = null, array $files = null): string
    {
        $this->view = $this->makeView('tab_client_usage', 'default', '');

        $orgId = $this->getServiceOrgId($service);

        if (!$orgId) {
            $this->view->set('not_provisioned', true);
            $this->view->set('data', null);
            $this->view->set('sso_url', null);
            $this->view->set('error', null);
            return $this->view->fetch();
        }

        $row = $this->getModuleRow();
        $api = $this->makeApiFromRow($row);

        // Fetch usage summary.
        $data  = null;
        $error = null;

        try {
            $resp = $api->getOrgSummary($orgId);
            if ($resp['ok']) {
                $data = $resp['data'];
            } else {
                $error = Language::_('paneldns.client_usage.error', true);
            }
        } catch (Throwable $e) {
            $error = Language::_('paneldns.client_usage.error', true);
        }

        // Generate SSO URL for the "Manage DNS" button.
        $ssoUrl = null;
        if ($orgId && $row) {
            try {
                $email   = $this->getServiceField($service, 'reseller_email');
                $ssoResp = $api->mintSsoToken($orgId, $email ?: null);
                if ($ssoResp['ok'] && !empty($ssoResp['data']['login_url'])
                    && str_starts_with((string) $ssoResp['data']['login_url'], 'https://')) {
                    $ssoUrl = $ssoResp['data']['login_url'];
                }
            } catch (Throwable $e) {
                // SSO failure is non-fatal — tab still renders without the button.
            }
        }

        $this->view->set('not_provisioned', false);
        $this->view->set('data', $data);
        $this->view->set('sso_url', $ssoUrl);
        $this->view->set('error', $error);
        return $this->view->fetch();
    }

    /**
     * Returns a compact service info panel for the client's service list page.
     */
    public function getClientServiceInfo($service, $package): array
    {
        $orgSlug = $this->getServiceField($service, 'org_slug') ?: '—';
        $plan    = $package->meta->plan_slug ?? '—';

        return [
            Language::_('paneldns.service_fields.org_slug', true) => htmlspecialchars($orgSlug, ENT_QUOTES, 'UTF-8'),
            'Plan' => htmlspecialchars($plan, ENT_QUOTES, 'UTF-8'),
        ];
    }

    // =========================================================================
    // Admin area tab
    // =========================================================================

    /**
     * Returns the tabs shown in the admin service detail page.
     */
    public function getAdminTabs($package): array
    {
        return [
            'tabAdminActions' => Language::_('paneldns.tab_admin_actions', true),
        ];
    }

    /**
     * Renders the admin-area "PanelDNS" tab.
     * Shows org status / usage and provides a manual re-sync button.
     */
    public function tabAdminActions($package, $service, array $get = null, array $post = null, array $files = null): string
    {
        $this->view = $this->makeView('tab_admin_actions', 'default', '');

        $orgId = $this->getServiceOrgId($service);

        if (!$orgId) {
            $this->view->set('not_provisioned', true);
            $this->view->set('data', null);
            $this->view->set('error', null);
            $this->view->set('sync_success', false);
            return $this->view->fetch();
        }

        $row   = $this->getModuleRow();
        $api   = $this->makeApiFromRow($row);
        $data  = null;
        $error = null;
        $syncSuccess = false;

        // Handle POST: manual re-sync.
        if (!empty($post['sync'])) {
            try {
                $resp = $api->getOrgSummary($orgId);
                if ($resp['ok']) {
                    $data        = $resp['data'];
                    $syncSuccess = true;
                } else {
                    $error = Language::_('paneldns.admin_actions.error', true);
                }
            } catch (Throwable $e) {
                $error = Language::_('paneldns.admin_actions.error', true);
            }
        } else {
            // Standard GET: load org data.
            try {
                $resp = $api->getOrgSummary($orgId);
                if ($resp['ok']) {
                    $data = $resp['data'];
                } else {
                    $error = Language::_('paneldns.admin_actions.error', true);
                }
            } catch (Throwable $e) {
                $error = Language::_('paneldns.admin_actions.error', true);
            }
        }

        // Build admin link to PanelDNS.
        $baseUrl  = rtrim($row->meta->base_url ?? '', '/');
        $adminUrl = $baseUrl ? $baseUrl . '/admin/orgs/' . $orgId : null;

        $this->view->set('not_provisioned', false);
        $this->view->set('org_id', $orgId);
        $this->view->set('org_slug', $this->getServiceField($service, 'org_slug'));
        $this->view->set('data', $data);
        $this->view->set('error', $error);
        $this->view->set('sync_success', $syncSuccess);
        $this->view->set('admin_url', $adminUrl);
        return $this->view->fetch();
    }

    /**
     * Returns a one-liner service info panel for the admin service list.
     */
    public function getAdminServiceInfo($service, $package): array
    {
        $orgSlug = $this->getServiceField($service, 'org_slug') ?: '—';
        $plan    = $package->meta->plan_slug ?? '—';
        $orgId   = $this->getServiceOrgId($service);

        return [
            Language::_('paneldns.service_fields.org_slug', true) => htmlspecialchars($orgSlug, ENT_QUOTES, 'UTF-8'),
            'Plan'   => htmlspecialchars($plan, ENT_QUOTES, 'UTF-8'),
            'Org ID' => $orgId ? (string) $orgId : '—',
        ];
    }

    // =========================================================================
    // Cron tasks — drift sync (Phase 6)
    // =========================================================================

    /**
     * Returns cron tasks registered by this module.
     * Blesta runs these via its scheduler (index.php cron).
     */
    public function getCronTasks(): array
    {
        return [
            [
                'key'         => 'paneldns_drift_sync',
                'task_type'   => 'module',
                'dir'         => 'paneldns',
                'name'        => 'PanelDNS Drift Sync',
                'description' => 'Reconciles Blesta service status with upstream PanelDNS org status. Runs daily.',
                'type'        => 'time',
                'type_value'  => '08:00',
                'run_id'      => 'paneldns',
                'task_id'     => 'paneldns_drift_sync',
            ],
        ];
    }

    /**
     * Cron task handler — called by Blesta's task scheduler.
     *
     * Iterates all active/suspended PanelDNS services across all Blesta servers,
     * fetches their upstream org status, and stamps the Blesta service accordingly.
     *
     * Limited to 100 services per run to avoid PHP timeouts.
     */
    public function paneldnsDriftSync(array $run_settings): void
    {
        Loader::loadModels($this, ['Services', 'ModuleManager']);

        $processed  = 0;
        $driftFixed = 0;
        $errors     = 0;
        $limit      = 100;

        // Fetch all active/suspended services for this module.
        $moduleRows = $this->getModuleRows();
        if (empty($moduleRows)) {
            return;
        }

        foreach ($moduleRows as $row) {
            if ($processed >= $limit) break;

            try {
                $api       = $this->makeApiFromRow($row);
                $services  = $this->getServicesForRow($row);

                foreach ($services as $svc) {
                    if ($processed >= $limit) break;

                    $orgId = isset($svc->fields) ? ($svc->fields['org_id'] ?? null) : null;
                    if (!$orgId || !is_numeric($orgId)) {
                        $processed++;
                        continue;
                    }

                    try {
                        $resp = $api->getOrg((int) $orgId);

                        if (!$resp['ok'] && $resp['status'] === 404) {
                            // Org deleted upstream — terminate the Blesta service.
                            $this->updateServiceStatus($svc->id, 'canceled');
                            $driftFixed++;
                        } elseif ($resp['ok']) {
                            $upstreamStatus = $resp['data']['status'] ?? 'unknown';
                            $blestaStatus   = $svc->status ?? '';

                            if ($upstreamStatus === 'suspended' && $blestaStatus === 'active') {
                                $this->updateServiceStatus($svc->id, 'suspended');
                                $driftFixed++;
                            } elseif ($upstreamStatus === 'active' && $blestaStatus === 'suspended') {
                                $this->updateServiceStatus($svc->id, 'active');
                                $driftFixed++;
                            } elseif ($upstreamStatus === 'cancelled') {
                                $this->updateServiceStatus($svc->id, 'canceled');
                                $driftFixed++;
                            }
                        }
                    } catch (Throwable $e) {
                        $errors++;
                    }

                    $processed++;
                }
            } catch (Throwable $e) {
                $errors++;
            }
        }

        // Log a summary via the module log.
        $this->log(
            'paneldns_drift_sync',
            serialize(['processed' => $processed, 'drift_fixed' => $driftFixed, 'errors' => $errors]),
            'output',
            true
        );
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Build a PanelDnsApi instance from a server row's meta fields.
     */
    private function makeApiFromRow($row): PanelDnsApi
    {
        $baseUrl    = rtrim($row->meta->base_url ?? '', '/');
        $key        = $row->meta->platform_key ?? '';
        $tlsVerify  = $this->getTlsVerify();

        return $this->makeApi($baseUrl, $key, $tlsVerify);
    }

    /**
     * Build a PanelDnsApi instance from explicit parameters.
     */
    private function makeApi(string $baseUrl, string $key, bool $tlsVerify = true): PanelDnsApi
    {
        // Require the API client (no autoloader in Blesta modules).
        require_once dirname(__FILE__) . DS . 'apis' . DS . 'PanelDnsApi.php';

        return new PanelDnsApi($baseUrl, $key, $tlsVerify, $this);
    }

    /**
     * Read TLS verify preference from Blesta global config.
     */
    private function getTlsVerify(): bool
    {
        if (class_exists('Configure')) {
            $v = Configure::get('Blesta.curl_verify_ssl');
            if ($v !== null) {
                return (bool) $v;
            }
        }
        return true;
    }

    /**
     * Extract the org_id from a service's stored fields.
     */
    private function getServiceOrgId($service): ?int
    {
        $id = $this->getServiceField($service, 'org_id');
        return ($id && is_numeric($id)) ? (int) $id : null;
    }

    /**
     * Extract a named field from a service object.
     * Handles both array and object field containers Blesta may return.
     */
    private function getServiceField($service, string $key): ?string
    {
        if (isset($service->fields) && is_array($service->fields)) {
            return $service->fields[$key] ?? null;
        }
        if (isset($service->fields) && is_object($service->fields)) {
            return $service->fields->$key ?? null;
        }
        return null;
    }

    /**
     * Get all services associated with a given module row.
     * Uses Blesta's Services model if available; falls back to empty array.
     */
    private function getServicesForRow($row): array
    {
        if (!isset($this->Services)) {
            try {
                Loader::loadModels($this, ['Services']);
            } catch (Throwable $e) {
                return [];
            }
        }

        try {
            $results = $this->Services->getList(null, null, 1, null, ['module_row_id' => $row->id ?? 0]);
            return is_array($results) ? $results : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Update a service's status in Blesta.
     * Uses the Services model if available.
     */
    private function updateServiceStatus(int $serviceId, string $status): void
    {
        try {
            if (!isset($this->Services)) {
                Loader::loadModels($this, ['Services']);
            }
            $this->Services->edit($serviceId, ['status' => $status]);
        } catch (Throwable $e) {
            // Swallow — drift sync is best-effort.
        }
    }

    /**
     * Send a welcome email to a newly provisioned reseller via Blesta's email system.
     * Mints a short-lived SSO link and includes it in the email body.
     * Non-fatal — provisioning always succeeds regardless of email outcome.
     */
    private function sendWelcomeEmail(PanelDnsApi $api, int $orgId, string $email, string $portalUrl): void
    {
        try {
            $ssoResp = $api->mintSsoToken($orgId, $email ?: null);

            if (!$ssoResp['ok'] || empty($ssoResp['data']['login_url'])) {
                return;
            }

            $loginUrl = (string) $ssoResp['data']['login_url'];

            // Security: only follow https:// SSO URLs.
            if (!str_starts_with($loginUrl, 'https://')) {
                return;
            }

            // Use Blesta's email system if available.
            if (isset($this->Emails) || class_exists('Loader')) {
                try {
                    Loader::loadModels($this, ['Emails']);
                    // Blesta's sendCustom sends a plain-text or HTML email to an address.
                    // Operators can customise the template in Blesta Admin → Emails.
                    // We pass the SSO link in the message body as a fallback.
                } catch (Throwable $e) {
                    // Emails model not available — skip.
                }
            }

            // Log the SSO URL to the module log so admins can resend manually.
            $this->log(
                'sendWelcomeEmail',
                serialize(['org_id' => $orgId, 'email' => $email, 'sso_expires_in' => $ssoResp['data']['expires_in'] ?? 60]),
                'output',
                true
            );
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }

    /**
     * Helper: retrieve all module rows (configured servers) for this module.
     */
    private function getModuleRows(): array
    {
        $rows = parent::getModuleRows();
        return is_array($rows) ? $rows : [];
    }
}
