<?php
/**
 * PanelDNS Blesta Provisioning Module
 *
 * Connects to the PanelDNS Reseller API (/api/v1) using a reseller Bearer
 * token to sell DNS sub-client accounts as Blesta products. One Blesta
 * "server row" = one PanelDNS reseller account (base URL + API token).
 *
 * When a Blesta client orders a product, a sub-client is created under the
 * reseller's PanelDNS organisation. The reseller's Blesta admin installs this
 * module and connects it with an API token from Settings → API Tokens in their
 * PanelDNS dashboard (scopes: sub_clients:read + sub_clients:write).
 *
 * Lifecycle mapping:
 *   addService()           → POST /api/v1/sub-clients
 *   suspendService()       → PATCH /api/v1/sub-clients/{id}  {status: "suspended"}
 *   unsuspendService()     → PATCH /api/v1/sub-clients/{id}  {status: "active"}
 *   cancelService()        → DELETE /api/v1/sub-clients/{id}
 *   changeServicePackage() → PATCH /api/v1/sub-clients/{id}  {zone_limit, max_records}
 *
 * Client tab: zone + record usage stats + SSO "Manage DNS" button.
 * Admin tab:  sub-client detail + manual re-sync button.
 * Cron task:  daily drift sync — reconciles Blesta service status with upstream.
 *
 * @version 2.0.0
 * @link    https://paneldns.com
 */

use Blesta\Core\Util\Input\Fields\InputFields;

class Paneldns extends Module
{
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Language::loadLang('paneldns', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    // =========================================================================
    // Module row management (Blesta calls these for the "Servers" admin screen)
    // =========================================================================

    public function manageModule($module, array &$vars): string
    {
        $this->view = $this->makeView('manage_module', 'default', '');
        $this->view->set('module', $module);
        $this->view->set('vars', $vars);
        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars): string
    {
        $this->view = $this->makeView('manage_add_row', 'default', '');
        $this->view->set('vars', $vars);
        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars): string
    {
        if (empty($vars)) {
            $vars = [
                'name'      => $module_row->meta->name      ?? '',
                'base_url'  => $module_row->meta->base_url  ?? '',
                'api_token' => $module_row->meta->api_token ?? '',
            ];
        }
        $this->view = $this->makeView('manage_edit_row', 'default', '');
        $this->view->set('module_row', $module_row);
        $this->view->set('vars', $vars);
        return $this->view->fetch();
    }

    public function addModuleRow(array &$vars): array
    {
        $meta_fields = ['name', 'base_url', 'api_token'];
        $encrypted   = ['api_token'];

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

    public function editModuleRow($module_row, array &$vars): array
    {
        return $this->addModuleRow($vars);
    }

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
            'api_token' => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('paneldns.!error.api_token.empty', true),
                ],
                'valid' => [
                    'rule'    => [[$this, 'validateCredentials'], $vars['base_url'] ?? ''],
                    'message' => Language::_('paneldns.!error.api_token.invalid', true),
                ],
            ],
        ];
    }

    public function validateBaseUrl(string $url): bool
    {
        return str_starts_with(trim($url), 'https://') || str_starts_with(trim($url), 'http://');
    }

    public function validateCredentials(string $token, string $baseUrl): bool
    {
        if (empty($token) || empty($baseUrl)) {
            return false;
        }
        try {
            $api  = $this->makeApi($baseUrl, $token);
            $resp = $api->getLicenceStatus();
            return $resp['ok'] && $resp['status'] === 200;
        } catch (Throwable $e) {
            return false;
        }
    }

    // =========================================================================
    // Package fields — zone_limit + max_records per product
    // =========================================================================

    /**
     * Package fields shown when an admin configures a Blesta product.
     * zone_limit and max_records are stored in meta and sent to PanelDNS
     * on provisioning. 0 = inherit the reseller's org-level limit.
     */
    public function getPackageFields($vars = null, $package = null): InputFields
    {
        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = new InputFields();

        $zoneLimitLabel = $fields->label(
            Language::_('paneldns.package_fields.zone_limit', true),
            'zone_limit'
        );
        $zoneLimitLabel->attach(
            $fields->fieldText(
                'meta[zone_limit]',
                $this->Html->ifSet($vars->meta['zone_limit'], '0'),
                ['id' => 'zone_limit', 'type' => 'number', 'min' => '0', 'max' => '10000']
            )
        );
        $fields->setField($zoneLimitLabel);

        $maxRecordsLabel = $fields->label(
            Language::_('paneldns.package_fields.max_records', true),
            'max_records'
        );
        $maxRecordsLabel->attach(
            $fields->fieldText(
                'meta[max_records]',
                $this->Html->ifSet($vars->meta['max_records'], '0'),
                ['id' => 'max_records', 'type' => 'number', 'min' => '0', 'max' => '100000']
            )
        );
        $fields->setField($maxRecordsLabel);

        return $fields;
    }

    public function getPackageRules(array $vars): array
    {
        return [
            'meta[zone_limit]' => [
                'format' => [
                    'rule'    => 'isNaturalNumber',
                    'message' => Language::_('paneldns.!error.zone_limit.format', true),
                ],
            ],
            'meta[max_records]' => [
                'format' => [
                    'rule'    => 'isNaturalNumber',
                    'message' => Language::_('paneldns.!error.max_records.format', true),
                ],
            ],
        ];
    }

    public function addPackage(array $vars = null): void {}
    public function editPackage($package = null, array $vars = null): void {}

    // =========================================================================
    // Service fields — stored per provisioned service
    // =========================================================================

    public function getServiceFields($vars = null, $package = null): InputFields
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new InputFields();

        $idLabel = $fields->label(Language::_('paneldns.service_fields.sub_client_id', true), 'sub_client_id');
        $idLabel->attach($fields->fieldText('sub_client_id', $this->Html->ifSet($vars->sub_client_id), ['id' => 'sub_client_id', 'readonly' => 'readonly']));
        $fields->setField($idLabel);

        $emailLabel = $fields->label(Language::_('paneldns.service_fields.sub_client_email', true), 'sub_client_email');
        $emailLabel->attach($fields->fieldText('sub_client_email', $this->Html->ifSet($vars->sub_client_email), ['id' => 'sub_client_email']));
        $fields->setField($emailLabel);

        return $fields;
    }

    // =========================================================================
    // Service lifecycle
    // =========================================================================

    /**
     * Provision a new PanelDNS sub-client.
     *
     * Uses the Blesta client's name and email to create the sub-client.
     * Zone and record limits are taken from the package meta.
     * If no password is supplied, the sub-client account is SSO-only — they
     * can log in via the "Manage DNS" SSO button but cannot set a password
     * until they use the portal's forgot-password flow.
     */
    public function addService($package, $service = null, array $vars = null, $parent_package = null, $parent_service = null)
    {
        // No-provision path: manual provisioning — store supplied IDs as-is.
        if (($vars['use_module'] ?? '') !== 'true') {
            return [
                ['key' => 'sub_client_id',    'value' => $vars['sub_client_id']    ?? '', 'encrypted' => 0],
                ['key' => 'sub_client_email', 'value' => $vars['sub_client_email'] ?? '', 'encrypted' => 0],
            ];
        }

        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors(['module' => ['no_server' => 'No PanelDNS server is configured.']]);
            return;
        }

        $api = $this->makeApiFromRow($row);

        // Derive sub-client name + email from the Blesta client record.
        $client    = $vars['client'] ?? [];
        $email     = $vars['sub_client_email'] ?? ($client['email'] ?? '');
        $firstName = $client['firstname'] ?? '';
        $lastName  = $client['lastname']  ?? '';
        $company   = $client['company']   ?? '';
        $name      = $vars['sub_client_name'] ?? (
            !empty($company)
                ? $company
                : trim("{$firstName} {$lastName}") ?: $email
        );

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->Input->setErrors(['sub_client_email' => ['format' => Language::_('paneldns.!error.sub_client_email.format', true)]]);
            return;
        }

        $payload = [
            'name'  => $name,
            'email' => $email,
        ];

        // Optional password — omit for SSO-only accounts.
        if (!empty($vars['sub_client_password'])) {
            $payload['password'] = $vars['sub_client_password'];
        }

        // Zone / record limits from package meta (0 = inherit org-level limit).
        $zoneLimit  = (int) ($package->meta->zone_limit  ?? 0);
        $maxRecords = (int) ($package->meta->max_records ?? 0);
        $payload['zone_limit']  = $zoneLimit;
        $payload['max_records'] = $maxRecords;

        $resp = $api->createSubClient($payload);

        if (!$resp['ok']) {
            $this->Input->setErrors(['api' => ['create' => 'PanelDNS provisioning failed. See module log for details.']]);
            return;
        }

        $subClientId = (int) ($resp['data']['id'] ?? 0);
        if ($subClientId <= 0) {
            $this->Input->setErrors(['api' => ['no_id' => 'PanelDNS returned success but no sub-client ID. See module log.']]);
            return;
        }

        return [
            ['key' => 'sub_client_id',    'value' => (string) $subClientId, 'encrypted' => 0],
            ['key' => 'sub_client_email', 'value' => $email,                'encrypted' => 0],
        ];
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($service->fields['use_module'] ?? 'true') === 'false') {
            return null;
        }

        $id = $this->getSubClientId($service);
        if (!$id) {
            $this->Input->setErrors(['service' => ['no_id' => 'No PanelDNS sub-client ID on this service.']]);
            return;
        }

        $resp = $this->makeApiFromRow($this->getModuleRow())->patchSubClient($id, ['status' => 'suspended']);

        if (!$resp['ok']) {
            $this->Input->setErrors(['api' => ['suspend' => 'PanelDNS suspend failed. See module log for details.']]);
        }
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($service->fields['use_module'] ?? 'true') === 'false') {
            return null;
        }

        $id = $this->getSubClientId($service);
        if (!$id) {
            $this->Input->setErrors(['service' => ['no_id' => 'No PanelDNS sub-client ID on this service.']]);
            return;
        }

        $resp = $this->makeApiFromRow($this->getModuleRow())->patchSubClient($id, ['status' => 'active']);

        if (!$resp['ok']) {
            $this->Input->setErrors(['api' => ['unsuspend' => 'PanelDNS unsuspend failed. See module log for details.']]);
        }
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($service->fields['use_module'] ?? 'true') === 'false') {
            return null;
        }

        $id = $this->getSubClientId($service);
        if (!$id) {
            return null; // Nothing to delete — treat as success.
        }

        $resp = $this->makeApiFromRow($this->getModuleRow())->deleteSubClient($id);

        if (!$resp['ok'] && $resp['status'] !== 404) {
            $this->Input->setErrors(['api' => ['delete' => 'PanelDNS termination failed. See module log for details.']]);
        }
    }

    /**
     * Change the zone/record limits on an existing sub-client.
     * Called by Blesta when the client upgrades or downgrades their plan.
     */
    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        $id = $this->getSubClientId($service);
        if (!$id) {
            $this->Input->setErrors(['service' => ['no_id' => 'No PanelDNS sub-client ID on this service.']]);
            return;
        }

        $patch = [
            'zone_limit'  => (int) ($package_to->meta->zone_limit  ?? 0),
            'max_records' => (int) ($package_to->meta->max_records ?? 0),
        ];

        $resp = $this->makeApiFromRow($this->getModuleRow())->patchSubClient($id, $patch);

        if (!$resp['ok']) {
            $this->Input->setErrors(['api' => ['change_package' => 'PanelDNS package change failed. See module log for details.']]);
        }
    }

    public function validateService($package, array $vars = null): array
    {
        $rules = [
            'sub_client_email' => [
                'format' => [
                    'rule'    => 'isEmail',
                    'message' => Language::_('paneldns.!error.sub_client_email.format', true),
                    'if_set'  => true,
                ],
            ],
        ];

        $this->Input->setRules($rules);
        $this->Input->validates($vars);

        return $this->Input->errors() ?? [];
    }

    // =========================================================================
    // Client area tab — usage stats + SSO button
    // =========================================================================

    public function getClientTabs($package): array
    {
        return [
            'tabClientUsage' => Language::_('paneldns.tab_client_usage', true),
        ];
    }

    /**
     * Renders the "DNS Usage" tab in the client area.
     * Fetches sub-client zone/record usage and provides an SSO login button.
     */
    public function tabClientUsage($package, $service, array $get = null, array $post = null, array $files = null): string
    {
        $this->view = $this->makeView('tab_client_usage', 'default', '');

        $id = $this->getSubClientId($service);

        if (!$id) {
            $this->view->set('not_provisioned', true);
            $this->view->set('data', null);
            $this->view->set('sso_url', null);
            $this->view->set('error', null);
            return $this->view->fetch();
        }

        $api   = $this->makeApiFromRow($this->getModuleRow());
        $data  = null;
        $error = null;

        try {
            $resp = $api->getSubClientSummary($id);
            if ($resp['ok']) {
                $data = $resp['data'];
            } else {
                $error = Language::_('paneldns.client_usage.error', true);
            }
        } catch (Throwable $e) {
            $error = Language::_('paneldns.client_usage.error', true);
        }

        // SSO button — non-fatal if it fails.
        $ssoUrl = null;
        try {
            $ssoResp = $api->mintSsoToken($id);
            if ($ssoResp['ok'] && !empty($ssoResp['data']['login_url'])
                && str_starts_with((string) $ssoResp['data']['login_url'], 'https://')) {
                $ssoUrl = $ssoResp['data']['login_url'];
            }
        } catch (Throwable $e) {
            // Non-fatal — tab renders without SSO button.
        }

        $this->view->set('not_provisioned', false);
        $this->view->set('data', $data);
        $this->view->set('sso_url', $ssoUrl);
        $this->view->set('error', $error);
        return $this->view->fetch();
    }

    public function getClientServiceInfo($service, $package): array
    {
        $email = $this->getServiceField($service, 'sub_client_email') ?: '—';
        $id    = $this->getSubClientId($service);

        return [
            Language::_('paneldns.service_fields.sub_client_email', true) => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
            'Sub-client ID' => $id ? (string) $id : '—',
        ];
    }

    // =========================================================================
    // Admin area tab — sub-client detail + re-sync
    // =========================================================================

    public function getAdminTabs($package): array
    {
        return [
            'tabAdminActions' => Language::_('paneldns.tab_admin_actions', true),
        ];
    }

    public function tabAdminActions($package, $service, array $get = null, array $post = null, array $files = null): string
    {
        $this->view = $this->makeView('tab_admin_actions', 'default', '');

        $id = $this->getSubClientId($service);

        if (!$id) {
            $this->view->set('not_provisioned', true);
            $this->view->set('data', null);
            $this->view->set('error', null);
            $this->view->set('sync_success', false);
            $this->view->set('sub_client_id', null);
            $this->view->set('admin_url', null);
            return $this->view->fetch();
        }

        $row         = $this->getModuleRow();
        $api         = $this->makeApiFromRow($row);
        $data        = null;
        $error       = null;
        $syncSuccess = false;

        try {
            $resp = $api->getSubClientSummary($id);
            if ($resp['ok']) {
                $data        = $resp['data'];
                $syncSuccess = !empty($post['sync']);
            } else {
                $error = Language::_('paneldns.admin_actions.error', true);
            }
        } catch (Throwable $e) {
            $error = Language::_('paneldns.admin_actions.error', true);
        }

        $baseUrl  = rtrim($row->meta->base_url ?? '', '/');
        $adminUrl = $baseUrl ? $baseUrl . '/admin/sub-clients/' . $id : null;

        $this->view->set('not_provisioned', false);
        $this->view->set('sub_client_id', $id);
        $this->view->set('data', $data);
        $this->view->set('error', $error);
        $this->view->set('sync_success', $syncSuccess);
        $this->view->set('admin_url', $adminUrl);
        return $this->view->fetch();
    }

    public function getAdminServiceInfo($service, $package): array
    {
        $email = $this->getServiceField($service, 'sub_client_email') ?: '—';
        $id    = $this->getSubClientId($service);
        $zl    = $package->meta->zone_limit  ?? '0';
        $mr    = $package->meta->max_records ?? '0';

        return [
            Language::_('paneldns.service_fields.sub_client_email', true) => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
            'Sub-client ID' => $id ? (string) $id : '—',
            'Zone limit'    => $zl === '0' ? '∞' : htmlspecialchars($zl, ENT_QUOTES, 'UTF-8'),
            'Record limit'  => $mr === '0' ? '∞' : htmlspecialchars($mr, ENT_QUOTES, 'UTF-8'),
        ];
    }

    // =========================================================================
    // Cron tasks — drift sync
    // =========================================================================

    public function getCronTasks(): array
    {
        return [
            [
                'key'         => 'paneldns_drift_sync',
                'task_type'   => 'module',
                'dir'         => 'paneldns',
                'name'        => 'PanelDNS Drift Sync',
                'description' => 'Reconciles Blesta service status with upstream PanelDNS sub-client status. Runs daily.',
                'type'        => 'time',
                'type_value'  => '08:00',
                'run_id'      => 'paneldns',
                'task_id'     => 'paneldns_drift_sync',
            ],
        ];
    }

    /**
     * Cron task handler — drift sync.
     *
     * Iterates all active/suspended PanelDNS services, fetches their upstream
     * sub-client status, and stamps Blesta services accordingly.
     * Capped at 100 services per run to avoid PHP timeouts.
     */
    public function paneldnsDriftSync(array $run_settings): void
    {
        Loader::loadModels($this, ['Services']);

        $processed  = 0;
        $driftFixed = 0;
        $errors     = 0;
        $limit      = 100;

        $moduleRows = $this->getModuleRows();
        if (empty($moduleRows)) {
            return;
        }

        foreach ($moduleRows as $row) {
            if ($processed >= $limit) break;

            try {
                $api      = $this->makeApiFromRow($row);
                $services = $this->getServicesForRow($row);

                foreach ($services as $svc) {
                    if ($processed >= $limit) break;

                    $subClientId = isset($svc->fields) ? ($svc->fields['sub_client_id'] ?? null) : null;
                    if (!$subClientId || !is_numeric($subClientId)) {
                        $processed++;
                        continue;
                    }

                    try {
                        $resp = $api->getSubClient((int) $subClientId);

                        if (!$resp['ok'] && $resp['status'] === 404) {
                            // Sub-client deleted upstream — cancel the Blesta service.
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

    private function makeApiFromRow($row): PanelDnsApi
    {
        return $this->makeApi(
            rtrim($row->meta->base_url  ?? '', '/'),
            $row->meta->api_token ?? '',
            $this->getTlsVerify()
        );
    }

    private function makeApi(string $baseUrl, string $token, bool $tlsVerify = true): PanelDnsApi
    {
        require_once dirname(__FILE__) . DS . 'apis' . DS . 'PanelDnsApi.php';
        return new PanelDnsApi($baseUrl, $token, $tlsVerify, $this);
    }

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

    private function getSubClientId($service): ?int
    {
        $id = $this->getServiceField($service, 'sub_client_id');
        return ($id && is_numeric($id)) ? (int) $id : null;
    }

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

    private function getModuleRows(): array
    {
        $rows = parent::getModuleRows();
        return is_array($rows) ? $rows : [];
    }
}
