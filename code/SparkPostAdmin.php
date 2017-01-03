<?php

/**
 * Allow you to see messages sent through the api key used to send messages
 *
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostAdmin extends LeftAndMain implements PermissionProvider
{

    const MESSAGE_TAG = 'message';
    const MESSAGE_CACHE_MINUTES = 5;
    const WEBHOOK_TAG = 'webhook';
    const WEBHOOK_CACHE_MINUTES = 1440; // 1 day
    const SENDINGDOMAIN_TAG = 'sending_domain';
    const SENDINGDOMAIN_CACHE_MINUTES = 1440; // 1 day

    private static $menu_title = "SparkPost";
    private static $url_segment = "sparkpost";
    private static $menu_icon = "sparkpost/images/sparkpost-icon.png";
    private static $url_rule = '/$Action/$ID/$OtherID';
    private static $allowed_actions = [
        'settings',
        'SearchForm',
        'doSearch',
        "doInstallHook",
        "doUninstallHook",
        "doInstallDomain",
        "doUninstallDomain",
    ];
    private static $cache_enabled = true;

    /**
     * @var ViewableData
     */
    protected $currentMessage;

    public function init()
    {
        parent::init();

        if (isset($_GET['refresh'])) {
            $this->clearAllCache();
        }
    }

    public function index($request)
    {
        return parent::index($request);
    }

    public function settings($request)
    {
        return parent::index($request);
    }

    /**
     * @return SparkPostMailer
     * @throws Exception
     */
    public function getMailer()
    {
        $mailer = Email::mailer();
        if (!$mailer instanceof SparkPostMailer) {
            throw new Exception('This class require to use SparkPostMailer');
        }
        return $mailer;
    }

    /**
     * @return SparkPostApiClient
     */
    public function getClient()
    {
        return $this->getMailer()->getClient();
    }

    /**
     * Returns a GridField of messages
     * @return CMSForm
     */
    public function getEditForm($id = null, $fields = null)
    {
        if (!$id)
            $id = $this->currentPageID();
        $form = parent::getEditForm($id);

        $record = $this->getRecord($id);

        if ($record && !$record->canView()) {
            return Security::permissionFailure($this);
        }

        // Build gridfield

        $messageListConfig = GridFieldConfig::create()->addComponents(
            new GridFieldSortableHeader(), new GridFieldDataColumns(), new GridFieldFooter()
        );

        $messages = $this->Messages();
        if (is_string($messages)) {
            // The api returned an error
            $messagesList = new LiteralField("MessageAlert", '<div class="message bad">' . $messages . '</div>');
        } else {
            $messagesList = GridField::create(
                    'Messages', false, $messages, $messageListConfig
                )->addExtraClass("messages_grid");

            $columns = $messageListConfig->getComponentByType('GridFieldDataColumns');
            $columns->setDisplayFields([
                'transmission_id' => _t('SparkPostAdmin.EventTransmissionId', 'Id'),
                'timestamp' => _t('SparkPostAdmin.EventDate', 'Date'),
                'type' => _t('SparkPostAdmin.EventType', 'Type'),
                'rcpt_to' => _t('SparkPostAdmin.EventRecipient', 'Recipient'),
                'subject' => _t('SparkPostAdmin.EventSubject', 'Subject'),
                'friendly_from' => _t('SparkPostAdmin.EventSender', 'Sender'),
            ]);

            $columns->setFieldFormatting([
                'timestamp' => function ($value, &$item) {
                    return date('Y-m-d H:i:s', strtotime($value));
                },
            ]);

            // Validator setup
            $validator = null;
            if ($record && method_exists($record, 'getValidator')) {
                $validator = $record->getValidator();
            }

            if ($validator) {
                $messageListConfig
                    ->getComponentByType('GridFieldDetailForm')
                    ->setValidator($validator);
            }
        }

        // Create tabs
        $messagesTab = new Tab('Messages', _t('SparkPostAdmin.Messages', 'Messages'), $this->SearchFields(), $messagesList,
            // necessary for tree node selection in LeftAndMain.EditForm.js
            new HiddenField('ID', false, 0)
        );

        $fields = new FieldList(
            $root = new TabSet('Root', $messagesTab)
        );

        if ($this->CanConfigureApi()) {

            $settingsTab = new Tab('Settings', _t('SparkPostAdmin.Settings', 'Settings'));

            $webhookTabData = $this->WebhookTab();
            $settingsTab->push($webhookTabData);

            $domainTabData = $this->DomainTab();
            $settingsTab->push($domainTabData);

            $fields->addFieldToTab('Root', $settingsTab);
        }

        // Tab nav in CMS is rendered through separate template
        $root->setTemplate('CMSTabSet');

        // Manage tabs state
        $actionParam = $this->getRequest()->param('Action');
        if ($actionParam == 'setting') {
            $settingsTab->addExtraClass('ui-state-active');
        } elseif ($actionParam == 'messages') {
            $messagesTab->addExtraClass('ui-state-active');
        }

        $actions = new FieldList();

        // Create cms form
        $form = CMSForm::create(
                $this, 'EditForm', $fields, $actions
            )->setHTMLID('Form_EditForm');
        $form->setResponseNegotiator($this->getResponseNegotiator());
        $form->addExtraClass('cms-edit-form');
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
        // Tab nav in CMS is rendered through separate template
        if ($form->Fields()->hasTabset()) {
            $form->Fields()->findOrMakeTab('Root')->setTemplate('CMSTabSet');
        }
        $form->addExtraClass('center ss-tabset cms-tabset ' . $this->BaseCSSClasses());
        $form->setAttribute('data-pjax-fragment', 'CurrentForm');

        $this->extend('updateEditForm', $form);

        return $form;
    }

    /**
     * @return Zend_Cache_Frontend
     */
    public function getCache()
    {
        return SS_Cache::factory(__CLASS__);
    }

    /**
     * @return boolean
     */
    public function getCacheEnabled()
    {
        $v = $this->config()->cache_enabled;
        if ($v === null) {
            $v = self::$cache_enabled;
        }
        return $v;
    }

    public function getParams()
    {
        $params = $this->config()->default_search_params;
        if (!$params) {
            $params = [];
        }
        $data = Session::get(__CLASS__ . '.Search');
        if (!$data) {
            $data = [];
        }

        $params = array_merge($params, $data);

        // Respect api formats
        if (!empty($params['to'])) {
            $params['to'] = date('Y-m-d', strtotime(str_replace('/', '-', $params['to']))) . 'T00:00';
        }
        if (!empty($params['from'])) {
            $params['from'] = date('Y-m-d', strtotime(str_replace('/', '-', $params['from']))) . 'T23:59';
        }

        $params = array_filter($params);

        return $params;
    }

    public function getParam($name, $default = null)
    {
        $data = Session::get(__CLASS__ . '.Search');
        if (!$data) {
            return $default;
        }
        return (isset($data[$name]) && strlen($data[$name])) ? $data[$name] : $default;
    }

    public function SearchFields()
    {
        $disabled_filters = $this->config()->disabled_search_filters;
        if (!$disabled_filters) {
            $disabled_filters = [];
        }

        $fields = new CompositeField();
        $fields->push($from = new DateField('params[from]', _t('SparkPostAdmin.DATEFROM', 'From'), $this->getParam('from')));
        $from->setConfig('min', date('Y-m-d', strtotime('-10 days')));

        $fields->push(new DateField('params[to]', _t('SparkPostAdmin.DATETO', 'To'), $to = $this->getParam('to')));

        if (!in_array('friendly_froms', $disabled_filters)) {
            $fields->push($friendly_froms = new TextField('params[friendly_froms]', _t('SparkPostAdmin.FRIENDLYFROM', 'Sender'), $this->getParam('friendly_froms')));
            $friendly_froms->setAttribute('placeholder', 'sender@mail.example.com,other@exemple.com');
        }

        if (!SparkPostMailer::config()->subaccount_id && !in_array('subaccounts', $disabled_filters)) {
            $fields->push($subaccounts = new TextField('params[subaccounts]', _t('SparkPostAdmin.SUBACCOUNTS', 'Subaccounts'), $this->getParam('subaccounts')));
            $subaccounts->setAttribute('placeholder', '101,102');
        }

        $fields->push(new DropdownField('params[per_page]', _t('SparkPostAdmin.PERPAGE', 'Number of results'), array(
            100 => 100,
            500 => 500,
            1000 => 1000,
            10000 => 10000,
            ), $this->getParam('per_page', 100)));

        foreach ($fields->FieldList() as $field) {
            $field->addExtraClass('no-change-track');
        }

        // This is a ugly hack to allow embedding a form into another form
        $fields->push($doSearch = new FormAction('doSearch', _t('SparkPostAdmin.DOSEARCH', 'Search')));
        $doSearch->setAttribute('onclick', "jQuery('#Form_SearchForm').append(jQuery('#Form_EditForm input,#Form_EditForm select').clone()).submit();");

        return $fields;
    }

    public function SearchForm()
    {
        $SearchForm = new Form($this, 'SearchForm', new FieldList(), new FieldList(new FormAction('doSearch')));
        $SearchForm->setAttribute('style', 'display:none');
        return $SearchForm;
    }

    public function doSearch($data, Form $form)
    {
        $post = $this->getRequest()->postVar('params');
        if (!$post) {
            return $this->redirectBack();
        }
        $params = [];

        $validFields = [];
        foreach ($this->SearchFields()->FieldList()->dataFields() as $field) {
            $validFields[] = str_replace(['params[', ']'], '', $field->getName());
        }

        foreach ($post as $k => $v) {
            if (in_array($k, $validFields)) {
                $params[$k] = $v;
            }
        }

        Session::set(__CLASS__ . '.Search', $params);
        Session::save();
        return $this->redirectBack();
    }

    /**
     * List of messages events
     *
     * Messages are cached to avoid hammering the api
     *
     * @return ArrayList|string
     */
    public function Messages()
    {
        $params = $this->getParams();

        $cache_enabled = $this->getCacheEnabled();
        if ($cache_enabled) {
            $cache = $this->getCache();
            $cache_key = md5(serialize($params));
            $cache_result = $cache->load($cache_key);
        }
        if ($cache_enabled && $cache_result) {
            $messages = unserialize($cache_result);
        } else {
            try {
                $messages = $this->getClient()->searchMessageEvents($params);
            } catch (Exception $ex) {
                return $ex->getMessage();
            }

            //5 minutes cache
            if ($cache_enabled) {
                $cache->save(serialize($messages), $cache_key, [self::MESSAGE_TAG], 60 * self::MESSAGE_CACHE_MINUTES);
            }
        }

        // Consolidate Subject/Sender for open and click events
        $transmissions = [];
        foreach ($messages as $message) {
            if (empty($message['transmission_id']) || empty($message['subject'])) {
                continue;
            }
            if (isset($transmissions[$message['transmission_id']])) {
                continue;
            }
            $transmissions[$message['transmission_id']] = $message;
        }

        $list = new ArrayList();
        if ($messages) {
            foreach ($messages as $message) {
                // If we have a transmission id but no subject, try to find the transmission details
                if (isset($message['transmission_id']) && empty($message['subject']) && isset($transmissions[$message['transmission_id']])) {
                    $message = array_merge($transmissions[$message['transmission_id']], $message);
                }
                // In some case (errors, etc) we don't have a friendly from
                if (empty($message['friendly_from']) && isset($message['msg_from'])) {
                    $message['friendly_from'] = $message['msg_from'];
                }
                $m = new ArrayData($message);
                $list->push($m);
            }
        }

        return $list;
    }

    /**
     * Provides custom permissions to the Security section
     *
     * @return array
     */
    public function providePermissions()
    {
        $title = _t("SparkPostAdmin.MENUTITLE", LeftAndMain::menu_title_for_class('SparkPost'));
        return [
            "CMS_ACCESS_SparkPost" => [
                'name' => _t('SparkPostAdmin.ACCESS', "Access to '{title}' section", ['title' => $title]),
                'category' => _t('Permission.CMS_ACCESS_CATEGORY', 'CMS Access'),
                'help' => _t(
                    'SparkPostAdmin.ACCESS_HELP', 'Allow use of SparkPost admin section'
                )
            ],
        ];
    }

    /**
     * A template accessor to check the ADMIN permission
     *
     * @return bool
     */
    public function IsAdmin()
    {
        return Permission::check("ADMIN");
    }

    /**
     * Check the permission for current user
     *
     * @return bool
     */
    public function canView($member = null)
    {
        $mailer = Email::mailer();
        if (!$mailer instanceof SparkPostMailer) {
            return false;
        }
        return Permission::check("CMS_ACCESS_SparkPost", 'any', $member);
    }

    /**
     *
     * @return bool
     */
    public function CanConfigureApi()
    {
        return Permission::check('ADMIN') || Director::isDev();
    }

    /**
     * Check if webhook is installed
     *
     * @return array
     */
    public function WebhookInstalled()
    {
        $client = $this->getClient();

        $cache_enabled = $this->getCacheEnabled();
        if ($cache_enabled) {
            $cache = $this->getCache();
            $cache_key = self::WEBHOOK_TAG;
            $cache_result = $cache->load($cache_key);
        }
        if ($cache_enabled && $cache_result) {
            $list = unserialize($cache_result);
        } else {
            try {
                $list = $client->listAllWebhooks();
                if ($cache_enabled) {
                    $cache->save(serialize($list), $cache_key, [self::WEBHOOK_TAG], 60 * self::WEBHOOK_CACHE_MINUTES);
                }
            } catch (Exception $ex) {
                $list = [];
                SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
            }
        }
        if (empty($list)) {
            return false;
        }
        $url = $this->WebhookUrl();
        foreach ($list as $el) {
            if ($el['target'] === $url) {
                return $el;
            }
        }
        return false;
    }

    /**
     * Hook details for template
     * @return \ArrayData
     */
    public function WebhookDetails()
    {
        $el = $this->WebhookInstalled();
        if ($el) {
            return new ArrayData($el);
        }
    }

    /**
     * Get content of the tab
     *
     * @return FormField
     */
    public function WebhookTab()
    {
        if ($this->WebhookInstalled()) {
            return $this->UninstallHookForm();
        }
        return $this->InstallHookForm();
    }

    /**
     * @return string
     */
    public function WebhookUrl()
    {
        if (self::config()->webhook_url) {
            return rtrim(self::config()->webhook_url, '/') . '/sparkpost/incoming';
        }
        if (Director::isLive()) {
            return Director::absoluteURL('/sparkpost/incoming');
        }
        return 'http://' . $this->getDomain() . '/sparkpost/incoming';
    }

    /**
     * Install hook form
     *
     * @return FormField
     */
    public function InstallHookForm()
    {
        $fields = new CompositeField();
        $fields->push(new LiteralField('Info', '<div class="message bad">' . _t('SparkPostAdmin.HookNotInstalled', 'Hook is not installed. Url of the webhook is: {url}. This url must be publicly visible to be used as a hook.', ['url' => $this->WebhookUrl()]) . '</div>'));
        $fields->push(new LiteralField('doInstallHook', '<a class="ss-ui-button" href="' . $this->Link('doInstallHook') . '">' . _t('SparkPostAdmin.DOINSTALLHOOK', 'Install hook') . '</a>'));
        return $fields;
    }

    public function doInstallHook()
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = $this->getClient();

        $url = $this->WebhookUrl();
        $description = SiteConfig::current_site_config()->Title;

        try {
            if (defined('SS_DEFAULT_ADMIN_USERNAME') && SS_DEFAULT_ADMIN_USERNAME) {
                $client->createSimpleWebhook($description, $url, null, true, ['username' => SS_DEFAULT_ADMIN_USERNAME, 'password' => SS_DEFAULT_ADMIN_PASSWORD]);
            } else {
                $client->createSimpleWebhook($description, $url);
            }
            $this->getCache()->clean('matchingTag', [self::WEBHOOK_TAG]);
        } catch (Exception $ex) {
            SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
        }

        return $this->redirectBack();
    }

    /**
     * Uninstall hook form
     *
     * @return FormField
     */
    public function UninstallHookForm()
    {
        $fields = new CompositeField();
        $fields->push(new LiteralField('Info', '<div class="message good">' . _t('SparkPostAdmin.HookInstalled', 'Hook is installed. Url of the webhook is: {url}.', ['url' => $this->WebhookUrl()]) . '</div>'));
        $fields->push(new LiteralField('doUninstallHook', '<a class="ss-ui-button" href="' . $this->Link('doUninstallHook') . '">' . _t('SparkPostAdmin.DOUNINSTALLHOOK', 'Uninstall hook') . '</a>'));
        return $fields;
    }

    public function doUninstallHook($data, Form $form)
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = $this->getClient();

        try {
            $el = $this->WebhookInstalled();
            if ($el && !empty($el['id'])) {
                $client->deleteWebhook($el['id']);
            }
            $this->getCache()->clean('matchingTag', [self::WEBHOOK_TAG]);
        } catch (Exception $ex) {
            SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
        }

        return $this->redirectBack();
    }

    /**
     * Check if sending domain is installed
     *
     * @return array
     */
    public function SendingDomainInstalled()
    {
        $client = $this->getClient();

        $host = $this->getDomain();

        $cache_enabled = $this->getCacheEnabled();
        if ($cache_enabled) {
            $cache = $this->getCache();
            $cache_key = self::SENDINGDOMAIN_TAG;
            $cache_result = $cache->load($cache_key);
        }
        if ($cache_enabled && $cache_result) {
            $domain = unserialize($cache_result);
        } else {
            try {
                $domain = $client->getSendingDomain($host);
                if ($cache_enabled) {
                    $cache->save(serialize($domain), $cache_key, [self::SENDINGDOMAIN_TAG], 60 * self::SENDINGDOMAIN_CACHE_MINUTES);
                }
            } catch (Exception $ex) {
                $domain = null;
                SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
            }
        }
        if (empty($domain)) {
            return false;
        }
        return $domain;
    }

    /**
     * Check if sending domain is verified
     *
     * @return array
     */
    public function SendingDomainVerified()
    {
        $client = $this->getClient();

        $host = $this->getDomain();

        $cache_enabled = $this->getCacheEnabled();
        if ($cache_enabled) {
            $cache = $this->getCache();
            $cache_key = self::SENDINGDOMAIN_TAG . '_verify';
            $cache_result = $cache->load($cache_key);
        }
        if ($cache_enabled && $cache_result) {
            $verification = unserialize($cache_result);
        } else {
            try {
                $verification = $client->verifySendingDomain($host);
                if ($cache_enabled) {
                    $cache->save(serialize($verification), $cache_key, [self::SENDINGDOMAIN_TAG . '_verify'], 60 * self::SENDINGDOMAIN_CACHE_MINUTES);
                }
            } catch (Exception $ex) {
                $verification = null;
                SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
            }
        }
        if (empty($verification)) {
            return false;
        }
        return $verification;
    }

    /**
     * Get content of the tab
     *
     * @return FormField
     */
    public function DomainTab()
    {
        if ($this->SendingDomainInstalled()) {
            return $this->UninstallDomainForm();
        }
        return $this->InstallDomainForm();
    }

    /**
     * @return string
     */
    public function InboundUrl()
    {
        $subdomain = self::config()->inbound_subdomain;
        $domain = $this->getDomain();
        if ($domain) {
            return $subdomain . '.' . $domain;
        }
        return false;
    }

    /**
     * Get domain name from current host
     *
     * @return boolean|string
     */
    public function getDomainFromHost()
    {
        $host = parse_url(Director::protocolAndHost(), PHP_URL_HOST);
        $hostParts = explode('.', $host);
        $parts = count($hostParts);
        if ($parts < 2) {
            return false;
        }
        $domain = $hostParts[$parts - 2] . "." . $hostParts[$parts - 1];
        return $domain;
    }

    /**
     * Get domain from admin email
     *
     * @return boolean|string
     */
    public function getDomainFromEmail()
    {
        $email = SparkPostMailer::resolveDefaultFromEmail();
        if ($email) {
            $domain = substr(strrchr($email, "@"), 1);
            return $domain;
        }
        return false;
    }

    /**
     * Get domain
     *
     * @return boolean|string
     */
    public function getDomain()
    {
        if (self::config()->sending_domain) {
            return self::config()->sending_domain;
        }
        if (Director::isLive()) {
            return $this->getDomainFromHost();
        }
        $domain = $this->getDomainFromEmail();
        if (!$domain) {
            return $this->getDomainFromHost();
        }
        return $domain;
    }

    /**
     * Install domain form
     *
     * @return FormField
     */
    public function InstallDomainForm()
    {
        $host = $this->getDomain();

        $fields = new CompositeField();
        $fields->push(new LiteralField('Info', '<div class="message bad">' . _t('SparkPostAdmin.DomainNotInstalled', 'Sending domain {domain} is not installed.' . '</div>', ['domain' => $host])));
        $fields->push(new LiteralField('doInstallDomain', '<a class="ss-ui-button" href="' . $this->Link('doInstallDomain') . '">' . _t('SparkPostAdmin.DOINSTALLDOMAIN', 'Install domain') . '</a>'));
        return $fields;
    }

    public function doInstallDomain()
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = $this->getClient();

        $domain = $this->getDomain();

        if (!$domain) {
            return $this->redirectBack();
        }

        try {
            $client->createSimpleSendingDomain($domain);
            $this->getCache()->clean('matchingTag', [self::SENDINGDOMAIN_TAG]);
            $this->getCache()->clean('matchingTag', [self::SENDINGDOMAIN_TAG . '_verify']);
        } catch (Exception $ex) {
            SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
        }

        return $this->redirectBack();
    }

    /**
     * Uninstall domain form
     *
     * @return FormField
     */
    public function UninstallDomainForm()
    {
        $verified = $this->SendingDomainVerified();

        $spfVerified = true;
        $dkimVerified = true;
        if ($verified) {
            if ($verified['spf_status'] == 'invalid') {
                $spfVerified = false;
            }
            if ($verified['dkim_status'] == 'invalid') {
                $dkimVerified = false;
            }
        }

        $domain = $this->getDomain();

        $fields = new CompositeField();

        if ($spfVerified && $dkimVerified) {
            $fields->push(new LiteralField('Info', '<div class="message good">' . _t('SparkPostAdmin.DomainInstalled', 'Domain {domain} is installed.', ['domain' => $domain]) . '</div>'));
        } else {
            $fields->push(new LiteralField('Info', '<div class="message warning">' . _t('SparkPostAdmin.DomainInstalledBut', 'Domain {domain} is installed, but is not properly configured. SPF records : {spf}. Domain key (DKIM) : {dkim}. Please check your dns zone.', ['domain' => $domain, 'spf' => $spfVerified ? 'OK' : 'NOT OK',
                    'dkim' => $dkimVerified ? 'OK' : 'NOT OK']) . '</div>'));
        }
        $fields->push(new LiteralField('doUninstallHook', '<a class="ss-ui-button" href="' . $this->Link('doUninstallHook') . '">' . _t('SparkPostAdmin.DOUNINSTALLDOMAIN', 'Uninstall domain') . '</a>'));
        return $fields;
    }

    public function doUninstallDomain($data, Form $form)
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = $this->getClient();

        $domain = $this->getDomain();

        if (!$domain) {
            return $this->redirectBack();
        }

        try {
            $el = $this->SendingDomainInstalled();
            if ($el) {
                $client->deleteSendingDomain($domain);
            }
            $this->getCache()->clean('matchingTag', [self::SENDINGDOMAIN_TAG]);
            $this->getCache()->clean('matchingTag', [self::SENDINGDOMAIN_TAG . '_verify']);
        } catch (Exception $ex) {
            SS_Log::log($ex->getMessage(), SS_Log::DEBUG);
        }

        return $this->redirectBack();
    }

    protected function clearAllCache()
    {
        $this->getCache()->clean('matchingTag', [self::MESSAGE_TAG]);
        $this->getCache()->clean('matchingTag', [self::WEBHOOK_TAG]);
        $this->getCache()->clean('matchingTag', [self::SENDINGDOMAIN_TAG]);
        $this->getCache()->clean('matchingTag', [self::SENDINGDOMAIN_TAG . '_verify']);
    }
}
