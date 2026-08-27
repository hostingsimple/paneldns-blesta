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
 *   cancelService()        → DELETE /api/v1/sub-clients/{id}  (or suspend+grace if configured)
 *   changeServicePackage() → PATCH /api/v1/sub-clients/{id}  {zone_limit, max_records}
 *
 * Client tab: full embedded DNS manager (zone list, record editor, DNSSEC, import/export).
 * Admin tab:  sub-client detail + zone list + re-sync + resend welcome email.
 * Cron tasks: daily drift sync; daily grace-period expiry termination.
 *
 * @version 2.1.0
 * @link    https://paneldns.com
 */

use Blesta\Core\Util\Input\Fields\InputFields;

class Paneldns extends Module
{
    /** @var string[] Allowed DNS record types (enforced server-side) */
    private const ALLOWED_RECORD_TYPES = [
        'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA',
        'PTR', 'TLSA', 'SSHFP', 'HTTPS', 'NAPTR',
    ];

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
                'name'         => $module_row->meta->name         ?? '',
                'base_url'     => $module_row->meta->base_url     ?? '',
                'api_token'    => $module_row->meta->api_token    ?? '',
                'ns1_hostname' => $module_row->meta->ns1_hostname ?? '',
                'ns2_hostname' => $module_row->meta->ns2_hostname ?? '',
                'ns3_hostname' => $module_row->meta->ns3_hostname ?? '',
                'ns4_hostname' => $module_row->meta->ns4_hostname ?? '',
                'soa_email'    => $module_row->meta->soa_email    ?? '',
            ];
        }
        $this->view = $this->makeView('manage_edit_row', 'default', '');
        $this->view->set('module_row', $module_row);
        $this->view->set('vars', $vars);
        return $this->view->fetch();
    }

    public function addModuleRow(array &$vars): array
    {
        // SEC: ns1–ns4 and soa_email are informational, not secrets — not encrypted.
        $meta_fields = [
            'name', 'base_url', 'api_token',
            'ns1_hostname', 'ns2_hostname', 'ns3_hostname', 'ns4_hostname',
            'soa_email',
        ];
        $encrypted = ['api_token'];

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
    // Package fields — zone_limit, max_records, welcome email, NS overrides, etc.
    // =========================================================================

    /**
     * Package fields shown when an admin configures a Blesta product.
     *
     * zone_limit and max_records are sent to PanelDNS on provisioning.
     * send_welcome_email, ns1–ns4 overrides, soa_email, auto_create_zone,
     * auto_delete_zone, and grace_period control provisioning behaviour.
     * 0 on limits = inherit the reseller org-level limit.
     */
    public function getPackageFields($vars = null, $package = null): InputFields
    {
        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = new InputFields();

        // Zone limit
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

        // Max records per zone
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

        // Send welcome email on provision
        $welcomeLabel = $fields->label(
            Language::_('paneldns.package_fields.send_welcome_email', true),
            'send_welcome_email'
        );
        $welcomeLabel->attach(
            $fields->fieldCheckbox(
                'meta[send_welcome_email]',
                '1',
                $this->Html->ifSet($vars->meta['send_welcome_email'], '1') === '1',
                ['id' => 'send_welcome_email']
            )
        );
        $fields->setField($welcomeLabel);

        // NS hostname overrides for welcome email
        foreach ([1, 2, 3, 4] as $n) {
            $nsKey   = "ns{$n}_hostname";
            $nsLabel = $fields->label(
                Language::_("paneldns.package_fields.{$nsKey}", true),
                $nsKey
            );
            $nsLabel->attach(
                $fields->fieldText(
                    "meta[{$nsKey}]",
                    $this->Html->ifSet($vars->meta[$nsKey], ''),
                    ['id' => $nsKey, 'maxlength' => '253']
                )
            );
            $fields->setField($nsLabel);
        }

        // SOA email for welcome email
        $soaLabel = $fields->label(
            Language::_('paneldns.package_fields.soa_email', true),
            'pkg_soa_email'
        );
        $soaLabel->attach(
            $fields->fieldText(
                'meta[soa_email]',
                $this->Html->ifSet($vars->meta['soa_email'], ''),
                ['id' => 'pkg_soa_email', 'maxlength' => '254']
            )
        );
        $fields->setField($soaLabel);

        // Auto-create DNS zone when domain is ordered
        $autoCreateLabel = $fields->label(
            Language::_('paneldns.package_fields.auto_create_zone', true),
            'auto_create_zone'
        );
        $autoCreateLabel->attach(
            $fields->fieldCheckbox(
                'meta[auto_create_zone]',
                '1',
                $this->Html->ifSet($vars->meta['auto_create_zone'], '1') === '1',
                ['id' => 'auto_create_zone']
            )
        );
        $fields->setField($autoCreateLabel);

        // Auto-delete DNS zone when domain expires/is removed
        $autoDeleteLabel = $fields->label(
            Language::_('paneldns.package_fields.auto_delete_zone', true),
            'auto_delete_zone'
        );
        $autoDeleteLabel->attach(
            $fields->fieldCheckbox(
                'meta[auto_delete_zone]',
                '1',
                $this->Html->ifSet($vars->meta['auto_delete_zone'], '0') === '1',
                ['id' => 'auto_delete_zone']
            )
        );
        $fields->setField($autoDeleteLabel);

        // Grace period before permanent deletion after cancellation
        $graceLabel = $fields->label(
            Language::_('paneldns.package_fields.grace_period', true),
            'grace_period'
        );
        $graceLabel->attach(
            $fields->fieldText(
                'meta[grace_period]',
                $this->Html->ifSet($vars->meta['grace_period'], '0'),
                ['id' => 'grace_period', 'type' => 'number', 'min' => '0', 'max' => '365']
            )
        );
        $fields->setField($graceLabel);

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
            'meta[grace_period]' => [
                'format' => [
                    // GRACE-01: 0–365 days; isNaturalNumber covers 0+; additional max check via regex.
                    'rule'    => [[$this, 'validateGracePeriod']],
                    'message' => Language::_('paneldns.!error.grace_period.format', true),
                    'if_set'  => true,
                ],
            ],
        ];
    }

    public function validateGracePeriod($value): bool
    {
        if (!is_numeric($value)) return false;
        $v = (int) $value;
        return $v >= 0 && $v <= 365;
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
        $idLabel->attach($fields->fieldText(
            'sub_client_id',
            $this->Html->ifSet($vars->sub_client_id),
            ['id' => 'sub_client_id', 'readonly' => 'readonly']
        ));
        $fields->setField($idLabel);

        $emailLabel = $fields->label(Language::_('paneldns.service_fields.sub_client_email', true), 'sub_client_email');
        $emailLabel->attach($fields->fieldText(
            'sub_client_email',
            $this->Html->ifSet($vars->sub_client_email),
            ['id' => 'sub_client_email']
        ));
        $fields->setField($emailLabel);

        // GRACE-01: deadline date stored here after cancellation if grace_period > 0.
        $graceDeadlineLabel = $fields->label(
            Language::_('paneldns.service_fields.grace_period_deadline', true),
            'grace_period_deadline'
        );
        $graceDeadlineLabel->attach($fields->fieldText(
            'grace_period_deadline',
            $this->Html->ifSet($vars->grace_period_deadline, ''),
            ['id' => 'grace_period_deadline', 'readonly' => 'readonly', 'placeholder' => 'YYYY-MM-DD or empty']
        ));
        $fields->setField($graceDeadlineLabel);

        return $fields;
    }

    // =========================================================================
    // Service lifecycle
    // =========================================================================

    /**
     * Provision a new PanelDNS sub-client.
     *
     * Idempotent: if sub_client_id is already in $vars (manual re-provision),
     * PATCHes the existing sub-client to active instead of creating a duplicate.
     * Fetches org nameservers after creation and stores them in service notes.
     * Sends welcome email with SSO link if send_welcome_email package option is set.
     * Fetches legal/consent version and includes it in the create payload.
     */
    public function addService($package, $service = null, array $vars = null, $parent_package = null, $parent_service = null)
    {
        // No-provision path: store supplied IDs as-is.
        if (($vars['use_module'] ?? '') !== 'true') {
            return [
                ['key' => 'sub_client_id',          'value' => $vars['sub_client_id']          ?? '', 'encrypted' => 0],
                ['key' => 'sub_client_email',        'value' => $vars['sub_client_email']        ?? '', 'encrypted' => 0],
                ['key' => 'grace_period_deadline',   'value' => '',                                    'encrypted' => 0],
            ];
        }

        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors(['module' => ['no_server' => 'No PanelDNS server is configured.']]);
            return;
        }

        $api = $this->makeApiFromRow($row);

        // LICENCE-BLESTA-01: gate provisioning on an active PanelDNS subscription.
        //
        // Only addService() is gated. suspendService, unsuspendService, cancelService
        // and every read path stay open deliberately: an expired subscription must not
        // strand existing customers or block an orderly wind-down. It is also checked
        // AFTER the no-provision early return above, so importing an existing
        // sub-client by ID keeps working while provisioning is locked.
        require_once dirname(__FILE__) . DS . 'apis' . DS . 'PanelDnsLicenceCheck.php';
        $licenceError = PanelDnsLicenceCheck::gateOrError($api);
        if ($licenceError !== null) {
            $this->Input->setErrors(['module' => ['licence' => $licenceError]]);
            return;
        }

        // Derive sub-client name + email from the Blesta client record.
        $client    = $vars['client'] ?? [];
        $email     = $vars['sub_client_email'] ?? ($client['email'] ?? '');
        $firstName = $client['firstname'] ?? '';
        $lastName  = $client['lastname']  ?? '';
        $company   = $client['company']   ?? '';
        // PHP8-PARSE-01: the inner fallback MUST stay parenthesised. Written as
        // `$a ? $b : $c ?: $d` this is an unparenthesised nested ternary, which PHP 8
        // rejects at COMPILE time - so the whole module file failed to parse and every
        // Blesta hook into it fatalled, on every PHP 8 release. Blesta 5 requires PHP 8.
        $name      = $vars['sub_client_name'] ?? (
            !empty($company)
                ? $company
                : (trim("{$firstName} {$lastName}") ?: $email)
        );

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->Input->setErrors(['sub_client_email' => ['format' => Language::_('paneldns.!error.sub_client_email.format', true)]]);
            return;
        }

        // IDEMPOTENT-01: if sub_client_id already exists on $vars, re-activate instead of creating.
        $existingId = isset($vars['sub_client_id']) && is_numeric($vars['sub_client_id'])
            ? (int) $vars['sub_client_id']
            : 0;

        if ($existingId > 0) {
            $resp = $api->patchSubClient($existingId, ['status' => 'active']);
            if (!$resp['ok']) {
                $this->Input->setErrors(['api' => ['reactivate' => 'PanelDNS re-activation failed. See module log for details.']]);
                return;
            }
            return [
                ['key' => 'sub_client_id',        'value' => (string) $existingId, 'encrypted' => 0],
                ['key' => 'sub_client_email',      'value' => $email,               'encrypted' => 0],
                ['key' => 'grace_period_deadline', 'value' => '',                   'encrypted' => 0],
            ];
        }

        $payload = [
            'name'        => $name,
            'email'       => $email,
            'zone_limit'  => (int) ($package->meta->zone_limit  ?? 0),
            'max_records' => (int) ($package->meta->max_records ?? 0),
        ];

        // Optional password — omit for SSO-only accounts.
        if (!empty($vars['sub_client_password'])) {
            $payload['password'] = $vars['sub_client_password'];
        }

        // GDPR-01: fetch current legal/consent version and include in create payload.
        try {
            $legalResp = $api->get('/api/v1/legal-version');
            if ($legalResp['ok'] && !empty($legalResp['data']['version'])) {
                $payload['consent_version'] = $legalResp['data']['version'];
            }
        } catch (Throwable $e) {
            // Non-fatal — proceed without consent_version if endpoint is unavailable.
        }

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

        // NS-STORE-01: fetch org nameservers after creation and store in service notes.
        $storedNs = '';
        try {
            $nsResp = $api->get('/api/v1/org/nameservers');
            if ($nsResp['ok'] && !empty($nsResp['data']['nameservers'])) {
                $storedNs = implode(',', (array) $nsResp['data']['nameservers']);
            }
        } catch (Throwable $e) {
            // Non-fatal.
        }

        // Send welcome email if package option is set.
        $sendWelcome = ($package->meta->send_welcome_email ?? '1') === '1';
        if ($sendWelcome) {
            $this->sendWelcomeEmail($api, $subClientId, $email, $name, $package, $row, $storedNs);
        }

        return [
            ['key' => 'sub_client_id',        'value' => (string) $subClientId, 'encrypted' => 0],
            ['key' => 'sub_client_email',      'value' => $email,                'encrypted' => 0],
            ['key' => 'grace_period_deadline', 'value' => '',                    'encrypted' => 0],
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

    /**
     * Cancel (terminate) a PanelDNS sub-client.
     *
     * GRACE-01: if the package specifies grace_period > 0, we suspend the
     * sub-client upstream and stamp the grace_period_deadline service field.
     * The paneldnsGraceExpiry cron task will perform the actual DELETE once
     * the deadline has passed. Returning null signals Blesta success.
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($service->fields['use_module'] ?? 'true') === 'false') {
            return null;
        }

        $id = $this->getSubClientId($service);
        if (!$id) {
            return null; // Nothing to delete — treat as success.
        }

        $graceDays = (int) ($package->meta->grace_period ?? 0);

        if ($graceDays > 0) {
            // GRACE-01: suspend upstream and store deadline; cron deletes later.
            $api = $this->makeApiFromRow($this->getModuleRow());
            $api->patchSubClient($id, ['status' => 'suspended']);

            $deadline = date('Y-m-d', strtotime("+{$graceDays} days"));

            // Store the deadline back into the service field.
            try {
                if (!isset($this->Services)) {
                    Loader::loadModels($this, ['Services']);
                }
                $this->Services->editField($service->id, [
                    'key'       => 'grace_period_deadline',
                    'value'     => $deadline,
                    'encrypted' => 0,
                ]);
            } catch (Throwable $e) {
                $this->log('cancelService.grace_deadline', $e->getMessage(), 'output', false);
            }

            return null;
        }

        // No grace period — delete immediately.
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
    // Client area tab — full embedded DNS manager
    // =========================================================================

    public function getClientTabs($package): array
    {
        return [
            'tabClientUsage' => Language::_('paneldns.tab_client_usage', true),
        ];
    }

    /**
     * Renders the "DNS Manager" tab in the client area.
     *
     * Dispatches GET views and POST mutations for the full embedded DNS manager:
     * zone list, record editor, zone create/import/export, DNSSEC toggle.
     *
     * Dispatch order:
     *   1. Rate-limit check (60 req/min per sub_client_id, session-based).
     *   2. POST: verify CSRF; route to mutation handler; re-render appropriate view.
     *   3. GET: route to view renderer.
     *   4. Default: render overview.
     *
     * zone-export streams a text/plain file and calls exit — it never returns
     * to the Blesta template engine. All other actions return an HTML string.
     */
    public function tabClientUsage($package, $service, array $get = null, array $post = null, array $files = null): string
    {
        $id = $this->getSubClientId($service);

        if (!$id) {
            $this->view = $this->makeView('tab_client_usage', 'default', '');
            $this->view->set('not_provisioned', true);
            return $this->view->fetch();
        }

        $row = $this->getModuleRow();
        $api = $this->makeApiFromRow($row);

        $serviceId = (int) ($service->id ?? 0);

        // RATE-01: check rate limit before any work.
        if ($this->rateLimit($id)) {
            return $this->h('<p class="alert alert-danger">Rate limit exceeded. Please wait before making more requests.</p>');
        }

        $pdnsView   = $_GET['pdns']            ?? null;
        $pdnsAction = $_POST['paneldns_action'] ?? null;

        // POST dispatch — all mutations.
        if ($pdnsAction !== null) {
            // CSRF-01: verify token on every POST.
            if (!$this->csrfVerify($serviceId)) {
                return '<p class="alert alert-danger">Security token mismatch. Please reload the page and try again.</p>';
            }

            return match ((string) $pdnsAction) {
                'do-zone-create'    => $this->doZoneCreate($api, $id, $serviceId),
                'do-zone-delete'    => $this->doZoneDelete($api, $id, $serviceId),
                'do-zone-import'    => $this->doZoneImport($api, $id, $serviceId),
                'do-record-create'  => $this->doRecordCreate($api, $id, $serviceId),
                'do-record-update'  => $this->doRecordUpdate($api, $id, $serviceId),
                'do-record-delete'  => $this->doRecordDelete($api, $id, $serviceId),
                'do-dnssec-toggle'  => $this->doDnssecToggle($api, $id, $serviceId),
                default             => $this->renderOverview($api, $id, $serviceId, $service, $package),
            };
        }

        // GET dispatch — views.
        return match ($pdnsView) {
            'zones'        => $this->renderZones($api, $id, $serviceId),
            'records'      => $this->renderRecords($api, $id, $serviceId),
            'zone-create'  => $this->renderZoneCreate($serviceId),
            'zone-import'  => $this->renderZoneImport($api, $id, $serviceId),
            'zone-export'  => $this->doZoneExport($api, $id),   // streams file + exits
            default        => $this->renderOverview($api, $id, $serviceId, $service, $package),
        };
    }

    // -------------------------------------------------------------------------
    // GET view renderers
    // -------------------------------------------------------------------------

    private function renderOverview($api, int $subClientId, int $serviceId, $service, $package): string
    {
        $data  = null;
        $error = null;

        try {
            $resp = $this->getSubClientSummary($api, $subClientId);
            if ($resp['ok']) {
                $data = $resp['data'];
            } else {
                $error = 'Unable to load usage data. Please try again later.';
            }
        } catch (Throwable $e) {
            $error = 'Unable to load usage data. Please try again later.';
        }

        // SSO button — non-fatal if it fails.
        $ssoUrl = null;
        try {
            $ssoResp = $api->mintSsoToken($subClientId);
            if ($ssoResp['ok'] && !empty($ssoResp['data']['login_url'])
                && str_starts_with((string) $ssoResp['data']['login_url'], 'https://')) {
                $ssoUrl = $ssoResp['data']['login_url'];
            }
        } catch (Throwable $e) {
            // Non-fatal.
        }

        // NS card
        $nameservers = $this->fetchNameservers($api, $subClientId);

        $this->view = $this->makeView('tab_client_usage', 'default', '');
        $this->view->set('not_provisioned', false);
        $this->view->set('data', $data);
        $this->view->set('sso_url', $ssoUrl);
        $this->view->set('error', $error);
        $this->view->set('nameservers', $nameservers);
        $this->view->set('service_id', $serviceId);
        $this->view->set('flash', $this->getFlash($serviceId));
        $this->view->set('csrf', $this->csrfToken($serviceId));
        return $this->view->fetch();
    }

    private function renderZones($api, int $subClientId, int $serviceId): string
    {
        $zones = [];
        $error = null;

        try {
            $resp = $api->get("/api/v1/zones?sub_client_id={$subClientId}&per_page=100");
            if ($resp['ok']) {
                $zones = $resp['data'] ?? [];
            } else {
                $error = 'Unable to load zones. Please try again later.';
            }
        } catch (Throwable $e) {
            $error = 'Unable to load zones. Please try again later.';
        }

        $this->view = $this->makeView('tab_client_zones', 'default', '');
        $this->view->set('zones', $zones);
        $this->view->set('error', $error);
        $this->view->set('service_id', $serviceId);
        $this->view->set('flash', $this->getFlash($serviceId));
        $this->view->set('csrf', $this->csrfToken($serviceId));
        return $this->view->fetch();
    }

    private function renderRecords($api, int $subClientId, int $serviceId): string
    {
        $zoneId = (int) ($_GET['zone_id'] ?? 0);
        if ($zoneId <= 0) {
            return $this->renderZones($api, $subClientId, $serviceId);
        }

        $zone = $this->fetchOwnZone($api, $subClientId, $zoneId);
        if (!$zone) {
            $this->flash($serviceId, 'error', 'Zone not found.');
            return $this->renderZones($api, $subClientId, $serviceId);
        }

        $records = [];
        $error   = null;
        try {
            $resp = $api->get("/api/v1/zones/{$zoneId}/records?per_page=200");
            if ($resp['ok']) {
                $records = $resp['data'] ?? [];
            } else {
                $error = 'Unable to load records. Please try again later.';
            }
        } catch (Throwable $e) {
            $error = 'Unable to load records. Please try again later.';
        }

        // DNSSEC-01: fetch DNSSEC status — non-fatal.
        $dnssec = null;
        try {
            $dnssec = $this->fetchDnssecStatus($api, $zoneId);
        } catch (Throwable $e) {
            // Non-fatal — template hides DNSSEC card if null.
        }

        $nameservers = $this->fetchNameservers($api, $subClientId);
        $editRecordId = (int) ($_GET['edit'] ?? 0) ?: null;

        $this->view = $this->makeView('tab_client_records', 'default', '');
        $this->view->set('zone', $zone);
        $this->view->set('records', $records);
        $this->view->set('error', $error);
        $this->view->set('service_id', $serviceId);
        $this->view->set('flash', $this->getFlash($serviceId));
        $this->view->set('csrf', $this->csrfToken($serviceId));
        $this->view->set('edit_record_id', $editRecordId);
        $this->view->set('nameservers', $nameservers);
        $this->view->set('dnssec', $dnssec);
        $this->view->set('allowed_types', self::ALLOWED_RECORD_TYPES);
        return $this->view->fetch();
    }

    private function renderZoneCreate(int $serviceId): string
    {
        $this->view = $this->makeView('tab_client_zone_create', 'default', '');
        $this->view->set('service_id', $serviceId);
        $this->view->set('csrf', $this->csrfToken($serviceId));
        $this->view->set('flash', $this->getFlash($serviceId));
        return $this->view->fetch();
    }

    private function renderZoneImport($api, int $subClientId, int $serviceId): string
    {
        $zones = [];
        try {
            $resp  = $api->get("/api/v1/zones?sub_client_id={$subClientId}&per_page=100");
            $zones = $resp['ok'] ? ($resp['data'] ?? []) : [];
        } catch (Throwable $e) {
            // Non-fatal — zone picker will be empty.
        }

        $this->view = $this->makeView('tab_client_zone_import', 'default', '');
        $this->view->set('zones', $zones);
        $this->view->set('service_id', $serviceId);
        $this->view->set('csrf', $this->csrfToken($serviceId));
        $this->view->set('flash', $this->getFlash($serviceId));
        return $this->view->fetch();
    }

    // -------------------------------------------------------------------------
    // POST mutation handlers
    // -------------------------------------------------------------------------

    /**
     * EXPORT-01: stream a BIND-format zone file to the browser and exit.
     *
     * Uses GET params (not POST) — called from the GET dispatch path.
     * Ownership-checked before streaming. On error, redirects with a flash.
     */
    private function doZoneExport($api, int $subClientId): string
    {
        $zoneId = (int) ($_GET['zone_id'] ?? 0);
        if ($zoneId <= 0) {
            $this->flash(0, 'error', 'Zone ID required.');
            // Cannot redirect cleanly — fall through to an inline error.
            return '<p class="alert alert-danger">Zone ID required.</p>';
        }

        // SEC-OWN: ownership check before exporting.
        $zone = $this->fetchOwnZone($api, $subClientId, $zoneId);
        if (!$zone) {
            return '<p class="alert alert-danger">Zone not found.</p>';
        }

        try {
            $resp = $api->get("/api/v1/zones/{$zoneId}/export");
        } catch (Throwable $e) {
            return '<p class="alert alert-danger">Export failed. Please try again later.</p>';
        }

        // Export endpoint returns text/plain; 'ok' may be false because it's not JSON.
        // Accept any 2xx status and use raw_body directly.
        $rawBody = (string) ($resp['raw_body'] ?? '');
        if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300 || trim($rawBody) === '') {
            return '<p class="alert alert-danger">Export failed. The zone may not have a DNS provider configured yet.</p>';
        }

        $zoneName = preg_replace('/[^a-z0-9._-]/i', '_', (string) ($zone['name'] ?? 'zone'));
        $filename = $zoneName . '.zone';

        // Stream file — after this we exit, never returning to Blesta.
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($rawBody));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $rawBody;
        exit;
    }

    private function doZoneCreate($api, int $subClientId, int $serviceId): string
    {
        $name = trim((string) ($_POST['name'] ?? ''));

        $nameError = $this->validateZoneName($name);
        if ($nameError !== null) {
            $this->flash($serviceId, 'error', $nameError);
            return $this->renderZoneCreate($serviceId);
        }

        // QUOTA-01: pre-flight check against sub-client summary.
        try {
            $summary = $this->getSubClientSummary($api, $subClientId);
            if ($summary['ok']) {
                $used  = (int) ($summary['data']['zones_used']  ?? $summary['data']['usage']['active_zones'] ?? 0);
                $limit = (int) ($summary['data']['zone_limit']  ?? $summary['data']['plan']['zones'] ?? 0);
                if ($limit > 0 && $used >= $limit) {
                    $this->flash($serviceId, 'error',
                        "You've reached your zone limit ({$used}/{$limit}). Please contact support to upgrade.");
                    return $this->renderZoneCreate($serviceId);
                }
            }
        } catch (Throwable $e) {
            // Non-fatal — proceed without quota check if API is unreachable.
        }

        try {
            $resp = $api->post('/api/v1/zones', [
                'name'          => $name,
                'sub_client_id' => $subClientId,
            ]);
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'Zone creation failed. Please try again later.');
            return $this->renderZoneCreate($serviceId);
        }

        if (!$resp['ok']) {
            $this->flash($serviceId, 'error', 'Failed to create zone: ' . $this->apiError($resp));
            return $this->renderZoneCreate($serviceId);
        }

        $this->csrfRotate($serviceId);
        // SEC-M01: escape user-supplied name before embedding in flash.
        $this->flash($serviceId, 'success', 'Zone ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' created.');
        return $this->renderZones($api, $subClientId, $serviceId);
    }

    private function doZoneDelete($api, int $subClientId, int $serviceId): string
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if ($zoneId <= 0 || !$this->fetchOwnZone($api, $subClientId, $zoneId)) {
            $this->flash($serviceId, 'error', 'Zone not found.');
            return $this->renderZones($api, $subClientId, $serviceId);
        }

        try {
            $resp = $api->delete("/api/v1/zones/{$zoneId}");
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'Delete failed. Please try again later.');
            return $this->renderZones($api, $subClientId, $serviceId);
        }

        if (!$resp['ok']) {
            $this->flash($serviceId, 'error', 'Delete failed: ' . $this->apiError($resp));
        } else {
            $this->csrfRotate($serviceId);
            $this->flash($serviceId, 'success', 'Zone deleted.');
        }
        return $this->renderZones($api, $subClientId, $serviceId);
    }

    private function doZoneImport($api, int $subClientId, int $serviceId): string
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $bindText = (string) ($_POST['bind_text'] ?? '');

        if ($zoneId <= 0) {
            $this->flash($serviceId, 'error', 'Please select a zone.');
            return $this->renderZoneImport($api, $subClientId, $serviceId);
        }

        // SEC-H9: ownership check BEFORE size cap.
        if (!$this->fetchOwnZone($api, $subClientId, $zoneId)) {
            $this->flash($serviceId, 'error', 'Zone not found.');
            return $this->renderZoneImport($api, $subClientId, $serviceId);
        }

        if (trim($bindText) === '') {
            $this->flash($serviceId, 'error', 'Please paste BIND-format zone text.');
            return $this->renderZoneImport($api, $subClientId, $serviceId);
        }

        // SEC-M03: cap import payload to prevent memory-exhaustion DoS.
        if (strlen($bindText) > 512 * 1024) {
            $this->flash($serviceId, 'error', 'Import data too large (max 512 KB).');
            return $this->renderZoneImport($api, $subClientId, $serviceId);
        }

        try {
            $resp = $api->post("/api/v1/zones/{$zoneId}/import", ['bind' => $bindText]);
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'Import failed. Please try again later.');
            return $this->renderZoneImport($api, $subClientId, $serviceId);
        }

        if (!$resp['ok']) {
            $this->flash($serviceId, 'error', 'Import failed: ' . $this->apiError($resp));
            return $this->renderZoneImport($api, $subClientId, $serviceId);
        }

        $count = $resp['data']['imported'] ?? '?';
        $this->csrfRotate($serviceId);
        $this->flash($serviceId, 'success', "Imported {$count} records into the zone.");

        // Redirect to the records view for that zone.
        $_GET['zone_id'] = $zoneId;
        return $this->renderRecords($api, $subClientId, $serviceId);
    }

    private function doRecordCreate($api, int $subClientId, int $serviceId): string
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if ($zoneId <= 0 || !$this->fetchOwnZone($api, $subClientId, $zoneId)) {
            $this->flash($serviceId, 'error', 'Zone not found.');
            return $this->renderZones($api, $subClientId, $serviceId);
        }

        $validationError = $this->validateRecord($_POST);
        if ($validationError !== null) {
            $this->flash($serviceId, 'error', $validationError);
            $_GET['zone_id'] = $zoneId;
            return $this->renderRecords($api, $subClientId, $serviceId);
        }

        $payload = $this->recordPayloadFromPost();

        try {
            $resp = $api->post("/api/v1/zones/{$zoneId}/records", $payload);
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'Add record failed. Please try again later.');
            $_GET['zone_id'] = $zoneId;
            return $this->renderRecords($api, $subClientId, $serviceId);
        }

        if (!$resp['ok']) {
            $this->flash($serviceId, 'error', 'Add record failed: ' . $this->apiError($resp));
        } else {
            $this->csrfRotate($serviceId);
            $this->flash($serviceId, 'success', 'Record added.');
        }

        $_GET['zone_id'] = $zoneId;
        return $this->renderRecords($api, $subClientId, $serviceId);
    }

    private function doRecordUpdate($api, int $subClientId, int $serviceId): string
    {
        $zoneId   = (int) ($_POST['zone_id']   ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);

        if ($zoneId <= 0 || !$this->fetchOwnZone($api, $subClientId, $zoneId) || $recordId <= 0) {
            $this->flash($serviceId, 'error', 'Record not found.');
            return $this->renderZones($api, $subClientId, $serviceId);
        }

        // FIX-H3: verify the record actually belongs to this zone.
        try {
            $rec = $api->get("/api/v1/zones/{$zoneId}/records/{$recordId}");
            if (empty($rec['ok'])) {
                $this->flash($serviceId, 'error', 'Record not found.');
                $_GET['zone_id'] = $zoneId;
                return $this->renderRecords($api, $subClientId, $serviceId);
            }
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'Record not found.');
            $_GET['zone_id'] = $zoneId;
            return $this->renderRecords($api, $subClientId, $serviceId);
        }

        $validationError = $this->validateRecord($_POST);
        if ($validationError !== null) {
            $this->flash($serviceId, 'error', $validationError);
            $_GET['zone_id'] = $zoneId;
            return $this->renderRecords($api, $subClientId, $serviceId);
        }

        $payload = $this->recordPayloadFromPost();

        try {
            $resp = $api->patch("/api/v1/zones/{$zoneId}/records/{$recordId}", $payload);
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'Update failed. Please try again later.');
            $_GET['zone_id'] = $zoneId;
            return $this->renderRecords($api, $subClientId, $serviceId);
        }

        if (!$resp['ok']) {
            $this->flash($serviceId, 'error', 'Update failed: ' . $this->apiError($resp));
        } else {
            $this->csrfRotate($serviceId);
            $this->flash($serviceId, 'success', 'Record updated.');
        }

        $_GET['zone_id'] = $zoneId;
        return $this->renderRecords($api, $subClientId, $serviceId);
    }

    private function doRecordDelete($api, int $subClientId, int $serviceId): string
    {
        $zoneId   = (int) ($_POST['zone_id']   ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);

        if ($zoneId <= 0 || !$this->fetchOwnZone($api, $subClientId, $zoneId) || $recordId <= 0) {
            $this->flash($serviceId, 'error', 'Record not found.');
            return $this->renderZones($api, $subClientId, $serviceId);
        }

        // FIX-H3: verify the record belongs to this zone before deleting.
        try {
            $rec = $api->get("/api/v1/zones/{$zoneId}/records/{$recordId}");
            if (empty($rec['ok'])) {
                $this->flash($serviceId, 'error', 'Record not found.');
                $_GET['zone_id'] = $zoneId;
                return $this->renderRecords($api, $subClientId, $serviceId);
            }
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'Record not found.');
            $_GET['zone_id'] = $zoneId;
            return $this->renderRecords($api, $subClientId, $serviceId);
        }

        try {
            $resp = $api->delete("/api/v1/zones/{$zoneId}/records/{$recordId}");
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'Delete failed. Please try again later.');
            $_GET['zone_id'] = $zoneId;
            return $this->renderRecords($api, $subClientId, $serviceId);
        }

        if (!$resp['ok']) {
            $this->flash($serviceId, 'error', 'Delete failed: ' . $this->apiError($resp));
        } else {
            $this->csrfRotate($serviceId);
            $this->flash($serviceId, 'success', 'Record deleted.');
        }

        $_GET['zone_id'] = $zoneId;
        return $this->renderRecords($api, $subClientId, $serviceId);
    }

    /**
     * DNSSEC-01: enable or disable DNSSEC signing on a zone.
     *
     * POST paneldns_action=do-dnssec-toggle with zone_id + enable (1|0).
     */
    private function doDnssecToggle($api, int $subClientId, int $serviceId): string
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if ($zoneId <= 0 || !$this->fetchOwnZone($api, $subClientId, $zoneId)) {
            $this->flash($serviceId, 'error', 'Zone not found.');
            return $this->renderZones($api, $subClientId, $serviceId);
        }

        $enable = isset($_POST['enable']) && (string) $_POST['enable'] === '1';

        try {
            $resp = $api->post("/api/v1/zones/{$zoneId}/dnssec", ['enable' => $enable]);
        } catch (Throwable $e) {
            $this->flash($serviceId, 'error', 'DNSSEC toggle failed. Please try again later.');
            $_GET['zone_id'] = $zoneId;
            return $this->renderRecords($api, $subClientId, $serviceId);
        }

        if (!$resp['ok']) {
            $this->flash($serviceId, 'error',
                'DNSSEC ' . ($enable ? 'enable' : 'disable') . ' failed: ' . $this->apiError($resp));
        } else {
            $this->csrfRotate($serviceId);
            $this->flash($serviceId, 'success', $enable
                ? 'DNSSEC enabled. Add the DS records below to your domain registrar to complete setup.'
                : 'DNSSEC disabled.');
        }

        $_GET['zone_id'] = $zoneId;
        return $this->renderRecords($api, $subClientId, $serviceId);
    }

    // =========================================================================
    // Admin area tab — sub-client detail + zone list + resync + resend welcome
    // =========================================================================

    public function getAdminTabs($package): array
    {
        return [
            'tabAdminActions' => Language::_('paneldns.tab_admin_actions', true),
        ];
    }

    /**
     * Renders the admin service detail tab.
     *
     * Shows sub-client summary, a list of up to 20 zones, and action buttons:
     * - Resync: reload the summary data.
     * - Resend Welcome Email: mint a fresh SSO token and resend.
     */
    public function tabAdminActions($package, $service, array $get = null, array $post = null, array $files = null): string
    {
        $this->view = $this->makeView('tab_admin_actions', 'default', '');

        $id = $this->getSubClientId($service);

        if (!$id) {
            $this->view->set('not_provisioned', true);
            $this->view->set('data', null);
            $this->view->set('error', null);
            $this->view->set('sync_success', false);
            $this->view->set('resend_success', false);
            $this->view->set('resend_error', null);
            $this->view->set('sub_client_id', null);
            $this->view->set('admin_url', null);
            $this->view->set('zones', []);
            return $this->view->fetch();
        }

        $row         = $this->getModuleRow();
        $api         = $this->makeApiFromRow($row);
        $data        = null;
        $error       = null;
        $syncSuccess = false;
        $resendSuccess = false;
        $resendError   = null;

        // Handle POST actions.
        $adminAction = $post['pdns_admin_action'] ?? '';
        if ($adminAction === 'resync' || $adminAction === 'sync') {
            $syncSuccess = true; // Flag — data is always freshly fetched below.
        }

        if ($adminAction === 'resend_welcome') {
            $email = $this->getServiceField($service, 'sub_client_email') ?? '';
            $name  = ''; // Name not stored in service fields; use email as fallback.
            try {
                $result = $this->sendWelcomeEmail($api, $id, $email, $name, $package, $row, '');
                if ($result) {
                    $resendSuccess = true;
                } else {
                    $resendError = 'Welcome email could not be sent. Check the module log.';
                }
            } catch (Throwable $e) {
                $resendError = 'Welcome email failed: ' . substr($e->getMessage(), 0, 256);
            }
        }

        // Always fetch fresh data.
        try {
            $resp = $this->getSubClientSummary($api, $id);
            if ($resp['ok']) {
                $data = $resp['data'];
            } else {
                $error = Language::_('paneldns.admin_actions.error', true);
            }
        } catch (Throwable $e) {
            $error = Language::_('paneldns.admin_actions.error', true);
        }

        // Zone list — up to 20 zones.
        $zones = [];
        try {
            $zonesResp = $api->get("/api/v1/zones?sub_client_id={$id}&per_page=20");
            if ($zonesResp['ok']) {
                $zones = $zonesResp['data'] ?? [];
            }
        } catch (Throwable $e) {
            // Non-fatal — zones list is informational.
        }

        $baseUrl  = rtrim($row->meta->base_url ?? '', '/');
        // SEC-SSO-01: validate base_url starts with https:// before constructing admin URL.
        $adminUrl = ($baseUrl && str_starts_with($baseUrl, 'https://'))
            ? $baseUrl . '/admin/sub-clients/' . $id
            : null;

        $this->view->set('not_provisioned', false);
        $this->view->set('sub_client_id', $id);
        $this->view->set('data', $data);
        $this->view->set('error', $error);
        $this->view->set('sync_success', $syncSuccess);
        $this->view->set('resend_success', $resendSuccess);
        $this->view->set('resend_error', $resendError);
        $this->view->set('admin_url', $adminUrl);
        $this->view->set('zones', $zones);
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

    public function getAdminServiceInfo($service, $package): array
    {
        $email    = $this->getServiceField($service, 'sub_client_email') ?: '—';
        $id       = $this->getSubClientId($service);
        $zl       = $package->meta->zone_limit  ?? '0';
        $mr       = $package->meta->max_records ?? '0';
        $deadline = $this->getServiceField($service, 'grace_period_deadline') ?: '—';

        return [
            Language::_('paneldns.service_fields.sub_client_email', true) => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
            'Sub-client ID'         => $id ? (string) $id : '—',
            'Zone limit'            => $zl === '0' ? '∞' : htmlspecialchars($zl, ENT_QUOTES, 'UTF-8'),
            'Record limit'          => $mr === '0' ? '∞' : htmlspecialchars($mr, ENT_QUOTES, 'UTF-8'),
            'Grace period deadline' => htmlspecialchars($deadline, ENT_QUOTES, 'UTF-8'),
        ];
    }

    // =========================================================================
    // Welcome email
    // =========================================================================

    /**
     * Mint a 60-second SSO token and send a welcome email to the sub-client.
     *
     * Uses Blesta's Emails model if available, falls back to @mail().
     * Non-fatal: errors are caught and logged.
     *
     * @param PanelDnsApi $api
     * @param int $subClientId
     * @param string $email Recipient email address.
     * @param string $name Recipient display name.
     * @param object $package Blesta package (for NS overrides, soa_email).
     * @param object $row Module row (for NS fallbacks, base_url).
     * @param string $storedNs Comma-separated NS from service notes (may be empty).
     * @return bool True on success.
     */
    private function sendWelcomeEmail($api, int $subClientId, string $email, string $name, $package, $row, string $storedNs): bool
    {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Mint a 60-second SSO login URL.
        $loginUrl    = null;
        $loginExpiry = '60 seconds';
        try {
            $ssoResp = $api->mintSsoToken($subClientId);
            if ($ssoResp['ok'] && !empty($ssoResp['data']['login_url'])
                && str_starts_with((string) $ssoResp['data']['login_url'], 'https://')) {
                $loginUrl = $ssoResp['data']['login_url'];
                if (!empty($ssoResp['data']['expires_in'])) {
                    $secs        = (int) $ssoResp['data']['expires_in'];
                    $loginExpiry = $secs >= 60 ? floor($secs / 60) . ' minute(s)' : $secs . ' second(s)';
                }
            }
        } catch (Throwable $e) {
            // Non-fatal — proceed with portal URL if SSO is unavailable.
        }

        // Nameservers — prefer package meta overrides, then stored NS, then org API.
        $nameservers = [];
        foreach ([1, 2, 3, 4] as $n) {
            $key = "ns{$n}_hostname";
            $ns  = trim((string) ($package->meta->$key ?? $row->meta->$key ?? ''));
            if ($ns !== '') $nameservers[] = $ns;
        }
        if (empty($nameservers) && $storedNs !== '') {
            $nameservers = array_filter(array_map('trim', explode(',', $storedNs)));
        }
        if (empty($nameservers)) {
            try {
                $nsResp = $api->get('/api/v1/org/nameservers');
                if ($nsResp['ok'] && !empty($nsResp['data']['nameservers'])) {
                    $nameservers = (array) $nsResp['data']['nameservers'];
                }
            } catch (Throwable $e) { /* Non-fatal */ }
        }

        $soaEmail  = trim((string) ($package->meta->soa_email ?? $row->meta->soa_email ?? ''));
        $baseUrl   = rtrim((string) ($row->meta->base_url ?? ''), '/');
        $portalUrl = str_starts_with($baseUrl, 'https://') ? $baseUrl : '';

        $nsHtml = '';
        if (!empty($nameservers)) {
            $nsHtml = '<ul>';
            foreach ($nameservers as $ns) {
                $nsHtml .= '<li>' . htmlspecialchars((string) $ns, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $nsHtml .= '</ul>';
        }

        $displayName  = $name ?: $email;
        $safeEmail    = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeName     = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $safeSoa      = htmlspecialchars($soaEmail, ENT_QUOTES, 'UTF-8');
        $safePortal   = $portalUrl ? htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') : '';
        $safeLoginUrl = $loginUrl ? htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') : '';
        $safeExpiry   = htmlspecialchars($loginExpiry, ENT_QUOTES, 'UTF-8');

        $subject = 'Your PanelDNS Account is Ready';
        $body    = <<<HTML
        <p>Hello {$safeName},</p>
        <p>Your PanelDNS DNS hosting account has been set up and is ready to use.</p>
        HTML;

        if ($safeLoginUrl !== '') {
            $body .= <<<HTML
            <p><strong><a href="{$safeLoginUrl}">Click here to log in to your DNS portal</a></strong>
            (this link expires in {$safeExpiry}).</p>
            HTML;
        } elseif ($safePortal !== '') {
            $body .= <<<HTML
            <p><strong><a href="{$safePortal}">Log in to your DNS portal</a></strong></p>
            HTML;
        }

        if (!empty($nameservers)) {
            $body .= '<p><strong>Your nameservers are:</strong>' . $nsHtml . '</p>';
            $body .= '<p>Point your domain\'s nameservers to the above to use PanelDNS.</p>';
        }

        if ($safeSoa !== '') {
            $body .= "<p>SOA contact email: {$safeSoa}</p>";
        }

        $body .= '<p>Thank you for choosing PanelDNS.</p>';

        // Attempt to use Blesta's Emails model.
        try {
            if (class_exists('Loader')) {
                Loader::loadModels($this, ['Emails']);
            }
            if (isset($this->Emails)) {
                $this->Emails->send(
                    'paneldns_welcome',
                    null,
                    null,
                    $email,
                    $displayName,
                    null,
                    null,
                    [
                        'login_url'    => $loginUrl ?? '',
                        'login_expires' => $loginExpiry,
                        'portal_url'   => $portalUrl,
                        'nameservers'  => implode(', ', $nameservers),
                        'soa_email'    => $soaEmail,
                    ]
                );
                return true;
            }
        } catch (Throwable $e) {
            // Fall through to @mail().
        }

        // Fallback: basic @mail().
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= 'From: noreply@' . (parse_url($baseUrl, PHP_URL_HOST) ?: 'paneldns.com') . "\r\n";

        // SEC: guard against header injection in the recipient address.
        if (preg_match('/[\r\n\0]/', $email)) {
            return false;
        }

        $sent = @mail($email, $subject, $body, $headers);

        try {
            $this->log(
                'sendWelcomeEmail',
                json_encode(['sub_client_id' => $subClientId, 'to' => $email, 'method' => 'mail', 'result' => $sent]),
                'output',
                $sent
            );
        } catch (Throwable $e) { /* Swallow */ }

        return (bool) $sent;
    }

    // =========================================================================
    // Cron tasks — drift sync + grace-period expiry
    // =========================================================================

    public function getCronTasks(): array
    {
        return [
            [
                'key'         => 'paneldns_drift_sync',
                'task_type'   => 'module',
                'dir'         => 'paneldns',
                'name'        => 'PanelDNS Drift Sync',
                'description' => 'Reconciles Blesta service status with upstream PanelDNS sub-client status. Runs daily at 08:00.',
                'type'        => 'time',
                'type_value'  => '08:00',
                'run_id'      => 'paneldns',
                'task_id'     => 'paneldns_drift_sync',
            ],
            [
                'key'         => 'paneldns_grace_expiry',
                'task_type'   => 'module',
                'dir'         => 'paneldns',
                'name'        => 'PanelDNS Grace Period Expiry',
                'description' => 'Permanently deletes sub-clients whose grace period has expired. Runs daily at 09:00.',
                'type'        => 'time',
                'type_value'  => '09:00',
                'run_id'      => 'paneldns',
                'task_id'     => 'paneldns_grace_expiry',
            ],
        ];
    }

    /**
     * Drift sync cron task.
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

    /**
     * Grace-period expiry cron task. Runs daily at 09:00.
     *
     * Scans all Cancelled PanelDNS services that have a non-empty
     * grace_period_deadline. For each where deadline <= today:
     *   1. Calls DELETE /api/v1/sub-clients/{id}.
     *   2. Clears the grace_period_deadline service field.
     *   3. Logs the result.
     *
     * Capped at 100 services per run.
     */
    public function paneldnsGraceExpiry(array $run_settings): void
    {
        Loader::loadModels($this, ['Services']);

        $processed = 0;
        $deleted   = 0;
        $errors    = 0;
        $limit     = 100;
        $today     = date('Y-m-d');

        $moduleRows = $this->getModuleRows();
        if (empty($moduleRows)) {
            return;
        }

        foreach ($moduleRows as $row) {
            if ($processed >= $limit) break;

            try {
                $api = $this->makeApiFromRow($row);

                // Fetch cancelled services for this module row.
                $services = [];
                try {
                    $results = $this->Services->getList(null, 'canceled', 1, null, ['module_row_id' => $row->id ?? 0]);
                    $services = is_array($results) ? $results : [];
                } catch (Throwable $e) {
                    $errors++;
                    continue;
                }

                foreach ($services as $svc) {
                    if ($processed >= $limit) break;

                    $subClientId = isset($svc->fields) ? ((int) ($svc->fields['sub_client_id'] ?? 0)) : 0;
                    $deadline    = isset($svc->fields) ? ($svc->fields['grace_period_deadline'] ?? '') : '';

                    if (!$subClientId || empty($deadline)) {
                        $processed++;
                        continue;
                    }

                    // GRACE-01: only process if deadline <= today.
                    if ($deadline > $today) {
                        $processed++;
                        continue;
                    }

                    try {
                        $resp = $api->deleteSubClient($subClientId);

                        if ($resp['ok'] || $resp['status'] === 404) {
                            // Clear the deadline field so we don't retry.
                            try {
                                $this->Services->editField($svc->id, [
                                    'key'       => 'grace_period_deadline',
                                    'value'     => '',
                                    'encrypted' => 0,
                                ]);
                            } catch (Throwable $e) { /* Best effort */ }

                            $deleted++;
                        } else {
                            $errors++;
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
            'paneldns_grace_expiry',
            serialize(['processed' => $processed, 'deleted' => $deleted, 'errors' => $errors, 'date' => $today]),
            'output',
            true
        );
    }

    // =========================================================================
    // Security helpers
    // =========================================================================

    /**
     * CSRF-01: generate or return an existing per-service CSRF token.
     *
     * Token is stored in $_SESSION['paneldns_csrf_{serviceId}'].
     * Service-scoped: a token from one product cannot mutate another.
     */
    private function csrfToken(int $serviceId): string
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $key = 'paneldns_csrf_' . $serviceId;
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION[$key];
    }

    /**
     * CSRF-01: verify POST token matches session token via constant-time comparison.
     *
     * POST field name: pdns_token.
     * Returns true if valid, false if mismatched or missing.
     */
    private function csrfVerify(int $serviceId): bool
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $key      = 'paneldns_csrf_' . $serviceId;
        $expected = $_SESSION[$key] ?? '';
        $supplied = (string) ($_POST['csrf'] ?? '');
        if ($expected === '' || !hash_equals($expected, $supplied)) {
            return false;
        }
        return true;
    }

    /**
     * CSRF-01: rotate the token after each successful mutation (prevents replay).
     */
    private function csrfRotate(int $serviceId): void
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION['paneldns_csrf_' . $serviceId] = bin2hex(random_bytes(24));
    }

    /**
     * RATE-01: session-based sliding window rate limit — 60 requests/60s per sub-client.
     *
     * Returns true if the limit is exceeded (caller should abort the request).
     * Per-process only (not shared across workers — sufficient for client-area usage).
     */
    private function rateLimit(int $subClientId): bool
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }

        $rlKey  = 'paneldns_rl_'    . $subClientId;
        $tsKey  = 'paneldns_rl_ts_' . $subClientId;
        $now    = time();
        $window = 60;
        $max    = 60;

        $hits      = (int)  ($_SESSION[$rlKey]  ?? 0);
        $windowStart = (int)($_SESSION[$tsKey] ?? 0);

        if ($now - $windowStart >= $window) {
            // Reset window.
            $_SESSION[$rlKey]  = 1;
            $_SESSION[$tsKey] = $now;
            return false;
        }

        if ($hits >= $max) {
            return true; // Exceeded.
        }

        $_SESSION[$rlKey] = $hits + 1;
        return false;
    }

    // =========================================================================
    // Flash message helpers
    // =========================================================================

    /**
     * Store a flash message for the next page render.
     *
     * @param int    $serviceId Scoped to service so multiple tabs don't bleed.
     * @param string $type      'success' or 'error'
     * @param string $message   Capped at 512 chars.
     */
    private function flash(int $serviceId, string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION['paneldns_flash_' . $serviceId] = [
            'type' => $type,
            'msg'  => substr((string) $message, 0, 512),
        ];
    }

    /**
     * Retrieve and clear flash messages for the given service.
     *
     * @return array{type: string, msg: string}|null
     */
    private function getFlash(int $serviceId): ?array
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $key = 'paneldns_flash_' . $serviceId;
        $f   = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $f;
    }

    /**
     * Extract and cap an API error string (256 chars) before passing to flash.
     * Prevents raw stack traces or large JSON error bodies leaking to the client.
     */
    private function apiError(array $resp, string $fallback = 'Unknown error.'): string
    {
        return substr((string) ($resp['error'] ?? $resp['message'] ?? $fallback), 0, 256);
    }

    // =========================================================================
    // Validation helpers
    // =========================================================================

    /**
     * Zone name validation.
     *
     * @return string|null Error message, or null if valid.
     */
    private function validateZoneName(string $name): ?string
    {
        if ($name === '') {
            return 'Zone name is required.';
        }
        if (strlen($name) > 253) {
            return 'Zone name must be 253 characters or fewer.';
        }
        if (str_contains($name, '..')) {
            return 'Zone name must not contain consecutive dots.';
        }
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_\-]|\.[a-zA-Z0-9])*$/', $name)) {
            return 'Zone name contains invalid characters.';
        }
        return null;
    }

    /**
     * Record field validation.
     *
     * @param array $data POST data (type, name, content, ttl).
     * @return string|null Error message, or null if valid.
     */
    private function validateRecord(array $data): ?string
    {
        $type    = strtoupper(trim((string) ($data['type']    ?? 'A')));
        $name    = trim((string) ($data['name']    ?? '@'));
        $content = trim((string) ($data['content'] ?? ''));
        $ttl     = (int) ($data['ttl'] ?? 3600);

        // SEC-M02: allowlist record type.
        if (!in_array($type, self::ALLOWED_RECORD_TYPES, true)) {
            return 'Invalid record type: ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '.';
        }
        // Name: ≤253 chars, no control characters.
        if (strlen($name) > 253) {
            return 'Record name is too long (max 253 characters).';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return 'Record name contains invalid characters.';
        }
        // Content: ≤4096 chars, no null bytes or bare CR/LF (injection risk in zone files).
        if (strlen($content) > 4096) {
            return 'Record content is too long (max 4096 characters).';
        }
        if (preg_match('/[\x00\r\n]/', $content)) {
            return 'Record content contains invalid characters.';
        }
        // TTL: minimum 60 seconds server-side (L15).
        if ($ttl < 60) {
            return 'TTL must be at least 60 seconds.';
        }
        return null;
    }

    /**
     * Build a clean record payload from POST data after validation has passed.
     */
    private function recordPayloadFromPost(): array
    {
        $type     = strtoupper(trim((string) ($_POST['type']    ?? 'A')));
        $name     = trim((string) ($_POST['name']    ?? '@'));
        $content  = trim((string) ($_POST['content'] ?? ''));
        $ttl      = max(60, (int) ($_POST['ttl'] ?? 3600));
        $priority = isset($_POST['priority']) && $_POST['priority'] !== ''
            ? (int) $_POST['priority']
            : null;

        $payload = [
            'name'    => $name,
            'type'    => $type,
            'content' => $content,
            'ttl'     => $ttl,
        ];

        if ($priority !== null) {
            $payload['priority'] = $priority;
        }

        return $payload;
    }

    // =========================================================================
    // API + data helpers
    // =========================================================================

    /**
     * SEC-OWN: verify a zone belongs to the given sub-client.
     *
     * Returns the zone array on success, null on failure/mismatch.
     */
    private function fetchOwnZone($api, int $subClientId, int $zoneId): ?array
    {
        if ($zoneId <= 0) return null;
        try {
            $resp = $api->get("/api/v1/zones/{$zoneId}");
            if (!$resp['ok']) return null;
            $z = $resp['data'] ?? null;
            if (!is_array($z)) return null;
            if ((int) ($z['sub_client_id'] ?? 0) !== $subClientId) return null;
            return $z;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * DNSSEC-01: fetch the current DNSSEC state for a zone.
     *
     * Returns null if provider doesn't support DNSSEC (non-2xx).
     *
     * @return array{enabled: bool, algorithm: string|null, ds_records: string[], last_synced_at: string|null}|null
     */
    private function fetchDnssecStatus($api, int $zoneId): ?array
    {
        $resp = $api->get("/api/v1/zones/{$zoneId}/dnssec");
        if (!$resp['ok']) return null;
        $d = $resp['data'] ?? null;
        if (!is_array($d)) return null;
        return [
            'enabled'        => (bool) ($d['enabled'] ?? false),
            'algorithm'      => (isset($d['algorithm']) && $d['algorithm'] !== '') ? (string) $d['algorithm'] : null,
            'ds_records'     => is_array($d['ds_records']) ? array_values($d['ds_records']) : [],
            'last_synced_at' => isset($d['last_synced_at']) ? (string) $d['last_synced_at'] : null,
        ];
    }

    /**
     * NS-CARD-01: fetch the org's nameservers.
     *
     * Cached for 5 minutes in $_SESSION to avoid an API call on every page render
     * (Blesta modules cannot use WHMCS Cache\Store, so session cache is the safe fallback).
     *
     * @return string[] Array of NS hostnames.
     */
    private function fetchNameservers($api, int $subClientId): array
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }

        $cacheKey = 'paneldns_ns_' . $subClientId;
        $cached   = $_SESSION[$cacheKey] ?? null;

        if (is_array($cached) && !empty($cached['ns']) && (time() - (int) ($cached['ts'] ?? 0)) < 300) {
            return $cached['ns'];
        }

        $ns = [];
        try {
            $resp = $api->get('/api/v1/org/nameservers');
            if ($resp['ok'] && !empty($resp['data']['nameservers'])) {
                $ns = (array) $resp['data']['nameservers'];
            }
        } catch (Throwable $e) { /* Non-fatal */ }

        $_SESSION[$cacheKey] = ['ns' => $ns, 'ts' => time()];
        return $ns;
    }

    /**
     * Thin wrapper around GET /api/v1/sub-clients/{id}/summary.
     * Tries two response shapes for compatibility with different API versions.
     */
    private function getSubClientSummary($api, int $subClientId): array
    {
        return $api->getSubClientSummary($subClientId);
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

    /**
     * HTML-escape a string. Shorthand for use inside this file.
     */
    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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
