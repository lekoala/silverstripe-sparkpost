<?php

namespace LeKoala\SparkPost;

use \Exception;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\ArrayLib;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\Control\Session;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\TextField;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\FormAction;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Security\Security;
use SilverStripe\View\ViewableData;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Security\Permission;
use LeKoala\SparkPost\SparkPostHelper;
use SilverStripe\Forms\CompositeField;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\SwiftMailer;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Security\PermissionProvider;
use LeKoala\SparkPost\SparkPostSwiftTransport;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldFooter;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;

/**
 * Allow you to see messages sent through the api key used to send messages
 *
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostAdmin extends LeftAndMain implements PermissionProvider
{

    const MESSAGE_CACHE_MINUTES = 5;
    const WEBHOOK_CACHE_MINUTES = 1440; // 1 day
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
     * @var bool
     */
    protected $subaccountKey = false;

    /**
     * @var Exception
     */
    protected $lastException;

    /**
     * @var ViewableData
     */
    protected $currentMessage;

    /**
     * Inject public dependencies into the controller
     *
     * @var array
     */
    private static $dependencies = [
        'logger' => '%$Psr\Log\LoggerInterface',
        'cache' => '%$Psr\SimpleCache\CacheInterface.sparkpost', // see _config/cache.yml
    ];

    /**
     * @var Psr\Log\LoggerInterface
     */
    public $logger;

    /**
     * @var Psr\SimpleCache\CacheInterface
     */
    public $cache;

    public function init()
    {
        parent::init();

        if (isset($_GET['refresh'])) {
            $this->getCache()->clear();
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
     * @return Session
     */
    public function getSession()
    {
        return $this->getRequest()->getSession();
    }

    /**
     * Returns a GridField of messages
     * @return CMSForm
     */
    public function getEditForm($id = null, $fields = null)
    {
        if (!$id) {
            $id = $this->currentPageID();
        }

        $form = parent::getEditForm($id);

        $record = $this->getRecord($id);

        // Check if this record is viewable
        if ($record && !$record->canView()) {
            $response = Security::permissionFailure($this);
            $this->setResponse($response);
            return null;
        }

        // Build gridfield
        $messageListConfig = GridFieldConfig::create()->addComponents(
            new GridFieldSortableHeader(),
            new GridFieldDataColumns(),
            new GridFieldFooter()
        );

        $messages = $this->Messages();
        if (is_string($messages)) {
            // The api returned an error
            $messagesList = new LiteralField("MessageAlert", $this->MessageHelper($messages, 'bad'));
        } else {
            $messagesList = GridField::create(
                'Messages',
                false,
                $messages,
                $messageListConfig
            )->addExtraClass("messages_grid");

            $columns = $messageListConfig->getComponentByType(GridFieldDataColumns::class);
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
                    ->getComponentByType(GridFieldDetailForm::class)
                    ->setValidator($validator);
            }
        }

        // Create tabs
        $messagesTab = new Tab(
            'Messages',
            _t('SparkPostAdmin.Messages', 'Messages'),
            $this->SearchFields(),
            $messagesList,
            // necessary for tree node selection in LeftAndMain.EditForm.js
            new HiddenField('ID', false, 0)
        );

        $fields = new FieldList([
            $root = new TabSet('Root', $messagesTab)
        ]);

        if ($this->CanConfigureApi()) {
            $settingsTab = new Tab('Settings', _t('SparkPostAdmin.Settings', 'Settings'));

            $domainTabData = $this->DomainTab();
            $settingsTab->push($domainTabData);

            // Show webhook options if not using a subaccount key
            if (!$this->subaccountKey) {
                $webhookTabData = $this->WebhookTab();
                $settingsTab->push($webhookTabData);
            }

            $toolsHtml = '<h2>Tools</h2>';

            // Show default from email
            $defaultEmail =  SparkPostHelper::resolveDefaultFromEmail();
            $toolsHtml .= "<p>Default sending email: " . $defaultEmail . " (" . SparkPostHelper::resolveDefaultFromEmailType() . ")</p>";
            if (!SparkPostHelper::isEmailDomainReady($defaultEmail)) {
                $toolsHtml .= '<p style="color:red">The default email is not ready to send emails</p>';
            }

            // Show constants
            if (SparkPostHelper::getEnvSendingDisabled()) {
                $toolsHtml .= '<p style="color:red">Sending is disabled by .env configuration</p>';
            }
            if (SparkPostHelper::getEnvEnableLogging()) {
                $toolsHtml .= '<p style="color:orange">Logging is enabled by .env configuration</p>';
            }
            if (SparkPostHelper::getEnvSubaccountId()) {
                $toolsHtml .= '<p style="color:orange">Using subaccount id</p>';
            }

            // Add a refresh button
            $toolsHtml .= $this->ButtonHelper(
                $this->Link() . '?refresh=true',
                _t('SparkPostAdmin.REFRESH', 'Force data refresh from the API')
            );

            $toolsHtml = $this->FormGroupHelper($toolsHtml);
            $Tools = new LiteralField('Tools', $toolsHtml);
            $settingsTab->push($Tools);

            $fields->addFieldToTab('Root', $settingsTab);
        }

        // Tab nav in CMS is rendered through separate template
        $root->setTemplate('SilverStripe\\Forms\\CMSTabSet');

        // Manage tabs state
        $actionParam = $this->getRequest()->param('Action');
        if ($actionParam == 'setting') {
            $settingsTab->addExtraClass('ui-state-active');
        } elseif ($actionParam == 'messages') {
            $messagesTab->addExtraClass('ui-state-active');
        }

        $actions = new FieldList();


        // Build replacement form
        $form = Form::create(
            $this,
            'EditForm',
            $fields,
            new FieldList()
        )->setHTMLID('Form_EditForm');
        $form->addExtraClass('cms-edit-form fill-height');
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
        $form->addExtraClass('ss-tabset cms-tabset ' . $this->BaseCSSClasses());
        $form->setAttribute('data-pjax-fragment', 'CurrentForm');

        $this->extend('updateEditForm', $form);

        return $form;
    }

    /**
     * Get logger
     *
     * @return  Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Get the cache
     *
     * @return Psr\SimpleCache\CacheInterface
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * @return boolean
     */
    public function getCacheEnabled()
    {
        if (Environment::getEnv('SPARKPOST_DISABLE_CACHE')) {
            return false;
        }
        $v = $this->config()->cache_enabled;
        if ($v === null) {
            $v = self::$cache_enabled;
        }
        return $v;
    }

    /**
     * A simple cache helper
     *
     * @param string $method
     * @param array $params
     * @param int $expireInSeconds
     * @return array
     */
    protected function getCachedData($method, $params, $expireInSeconds = 60)
    {
        $enabled = $this->getCacheEnabled();
        if ($enabled) {
            $cache = $this->getCache();
            $key = md5(serialize($params));
            $cacheResult = $cache->get($key);
        }
        if ($enabled && $cacheResult) {
            $data = unserialize($cacheResult);
        } else {
            try {
                $client = SparkPostHelper::getClient();
                $data = $client->$method($params);
            } catch (Exception $ex) {
                $this->lastException = $ex;
                $this->getLogger()->debug($ex);
                $data = false;
            }

            //5 minutes cache
            if ($enabled) {
                $cache->set($key, serialize($data), $expireInSeconds);
            }
        }

        return $data;
    }

    public function getParams()
    {
        $params = $this->config()->default_search_params;
        if (!$params) {
            $params = [];
        }
        $data = $this->getSession()->get(__class__ . '.Search');
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
        $data = $this->getSession()->get(__class__ . '.Search');
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
        // $from->setConfig('min', date('Y-m-d', strtotime('-10 days')));

        $fields->push(new DateField('params[to]', _t('SparkPostAdmin.DATETO', 'To'), $to = $this->getParam('to')));

        if (!in_array('friendly_froms', $disabled_filters)) {
            $fields->push($friendly_froms = new TextField('params[friendly_froms]', _t('SparkPostAdmin.FRIENDLYFROM', 'Sender'), $this->getParam('friendly_froms')));
            $friendly_froms->setAttribute('placeholder', 'sender@mail.example.com,other@example.com');
        }

        if (!in_array('recipients', $disabled_filters)) {
            $fields->push($recipients = new TextField('params[recipients]', _t('SparkPostAdmin.RECIPIENTS', 'Recipients'), $this->getParam('recipients')));
            $recipients->setAttribute('placeholder', 'recipient@example.com,other@example.com');
        }

        // Only allow filtering by subaccount if a master key is defined
        if (SparkPostHelper::config()->master_api_key && !in_array('subaccounts', $disabled_filters)) {
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
        $doSearch->addExtraClass("btn-primary");
        $doSearch->setAttribute('onclick', "jQuery('#Form_SearchForm').append(jQuery('#Form_EditForm input,#Form_EditForm select').clone()).submit();");

        return $fields;
    }

    public function SearchForm()
    {
        $SearchForm = new Form($this, 'SearchForm', new FieldList(), new FieldList([
            new FormAction('doSearch')
        ]));
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

        $this->getSession()->set(__class__ . '.Search', $params);
        $this->getSession()->save($this->getRequest());

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

        $messages = $this->getCachedData('searchEvents', $params, 60 * self::MESSAGE_CACHE_MINUTES);
        if ($messages === false) {
            if ($this->lastException) {
                return $this->lastException->getMessage();
            }
            return _t('SparkpostAdmin.NO_MESSAGES', 'No messages');
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
                    'SparkPostAdmin.ACCESS_HELP',
                    'Allow use of SparkPost admin section'
                )
            ],
        ];
    }

    /**
     * Message helper
     *
     * @param string $message
     * @param string $status
     * @return string
     */
    protected function MessageHelper($message, $status = 'info')
    {
        return '<div class="message ' . $status . '">' . $message . '</div>';
    }

    /**
     * Button helper
     *
     * @param string $link
     * @param string $text
     * @param boolean $confirm
     * @return string
     */
    protected function ButtonHelper($link, $text, $confirm = false)
    {
        $link = '<a class="btn btn-primary" href="' . $link . '"';
        if ($confirm) {
            $link .= ' onclick="return confirm(\'' . _t('SparkPostAdmin.CONFIRM_MSG', 'Are you sure?') . '\')"';
        }
        $link .= '>' . $text . '</a>';
        return $link;
    }

    /**
     * Wrap html in a form group
     *
     * @param string $html
     * @return string
     */
    protected function FormGroupHelper($html)
    {
        return '<div class="form-group"><div class="form__fieldgroup form__field-holder form__field-holder--no-label">' . $html . '</div></div>';
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
        $mailer = SparkPostHelper::getMailer();
        // Another custom mailer has been set
        if (!$mailer instanceof SwiftMailer) {
            return false;
        }
        // Doesn't use the proper transport
        if (!$mailer->getSwiftMailer()->getTransport() instanceof SparkPostSwiftTransport) {
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
        $list = $this->getCachedData('listAllWebhooks', null, 60 * self::WEBHOOK_CACHE_MINUTES);

        if (empty($list)) {
            return false;
        }
        $url = $this->WebhookUrl();
        foreach ($list as $el) {
            if (!empty($el['target']) && $el['target'] === $url) {
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
        if (self::config()->webhook_base_url) {
            return rtrim(self::config()->webhook_base_url, '/') . '/sparkpost/incoming';
        }
        if (Director::isLive()) {
            return Director::absoluteURL('/sparkpost/incoming');
        }
        $protocol = Director::protocol();
        return $protocol . $this->getDomain() . '/sparkpost/incoming';
    }

    /**
     * Install hook form
     *
     * @return FormField
     */
    public function InstallHookForm()
    {
        $fields = new CompositeField();
        $fields->push(new LiteralField('Info', $this->MessageHelper(
            _t('SparkPostAdmin.WebhookNotInstalled', 'Webhook is not installed. It should be configured using the following url {url}. This url must be publicly visible to be used as a hook.', ['url' => $this->WebhookUrl()]),
            'bad'
        )));
        $fields->push(new LiteralField('doInstallHook', $this->ButtonHelper(
            $this->Link('doInstallHook'),
            _t('SparkPostAdmin.DOINSTALL_WEBHOOK', 'Install webhook')
        )));
        return $fields;
    }

    public function doInstallHook()
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = SparkPostHelper::getClient();

        $url = $this->WebhookUrl();
        $description = SiteConfig::current_site_config()->Title;

        try {
            if (defined('SS_DEFAULT_ADMIN_USERNAME') && SS_DEFAULT_ADMIN_USERNAME) {
                $client->createSimpleWebhook($description, $url, null, true, ['username' => SS_DEFAULT_ADMIN_USERNAME, 'password' => SS_DEFAULT_ADMIN_PASSWORD]);
            } else {
                $client->createSimpleWebhook($description, $url);
            }
            $this->getCache()->clear();
        } catch (Exception $ex) {
            $this->getLogger()->debug($ex);
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
        $fields->push(new LiteralField('Info', $this->MessageHelper(
            _t('SparkPostAdmin.WebhookInstalled', 'Webhook is installed and accessible at the following url {url}.', ['url' => $this->WebhookUrl()]),
            'good'
        )));
        $fields->push(new LiteralField('doUninstallHook', $this->ButtonHelper(
            $this->Link('doUninstallHook'),
            _t('SparkPostAdmin.DOUNINSTALL_WEBHOOK', 'Uninstall webhook'),
            true
        )));
        return $fields;
    }

    public function doUninstallHook($data, Form $form)
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = SparkPostHelper::getClient();

        try {
            $el = $this->WebhookInstalled();
            if ($el && !empty($el['id'])) {
                $client->deleteWebhook($el['id']);
            }
            $this->getCache()->clear();
        } catch (Exception $ex) {
            $this->getLogger()->debug($ex);
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
        $domain = $this->getCachedData('getSendingDomain', $this->getDomain(), 60 * self::SENDINGDOMAIN_CACHE_MINUTES);

        if (empty($domain)) {
            return false;
        }
        return $domain;
    }

    /**
     * Trigger request to check if sending domain is verified
     *
     * @return array
     */
    public function VerifySendingDomain()
    {
        $client = SparkPostHelper::getClient();

        $host = $this->getDomain();

        $verification = $client->verifySendingDomain($host);

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
        $defaultDomain = $this->getDomain();
        $defaultDomainInfos = null;

        $domains = $this->getCachedData('listAllSendingDomains', null, 60 * self::SENDINGDOMAIN_CACHE_MINUTES);

        $fields = new CompositeField();

        $list = new ArrayList();
        if ($domains) {
            foreach ($domains as $domain) {
                // We are using a subaccount api key
                if (!isset($domain['shared_with_subaccounts'])) {
                    $this->subaccountKey = true;
                }

                $list->push(new ArrayData([
                    'Domain' => $domain['domain'],
                    'SPF' => $domain['status']['spf_status'],
                    'DKIM' => $domain['status']['dkim_status'],
                    'Compliance' => $domain['status']['compliance_status'],
                    'Verified' => $domain['status']['ownership_verified'],
                ]));

                if ($domain['domain'] == $defaultDomain) {
                    $defaultDomainInfos = $domain;
                }
            }
        }

        $config = GridFieldConfig::create();
        $config->addComponent(new GridFieldToolbarHeader());
        $config->addComponent(new GridFieldTitleHeader());
        $config->addComponent($columns = new GridFieldDataColumns());
        $columns->setDisplayFields(ArrayLib::valuekey(['Domain', 'SPF', 'DKIM', 'Compliance', 'Verified']));
        $domainsList = new GridField('SendingDomains', _t('SparkPostAdmin.ALL_SENDING_DOMAINS', 'Configured sending domains'), $list, $config);
        $domainsList->addExtraClass('mb-2');
        $fields->push($domainsList);

        if (!$defaultDomainInfos) {
            $this->InstallDomainForm($fields);
        } else {
            $this->UninstallDomainForm($fields);
        }

        return $fields;
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
        $base = Environment::getEnv('SS_BASE_URL');
        if (!$base) {
            $base = Director::protocolAndHost();
        }
        $host = parse_url($base, PHP_URL_HOST);
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
        $email = SparkPostHelper::resolveDefaultFromEmail(null, false);
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
        $domain = $this->getDomainFromEmail();
        if (!$domain) {
            return $this->getDomainFromHost();
        }
        return $domain;
    }

    /**
     * Install domain form
     *
     * @param CompositeField $fieldsd
     * @return FormField
     */
    public function InstallDomainForm(CompositeField $fields)
    {
        $host = $this->getDomain();

        $fields->push(new LiteralField('Info', $this->MessageHelper(
            _t('SparkPostAdmin.DomainNotInstalled', 'Default sending domain {domain} is not installed.', ['domain' => $host]),
            "bad"
        )));
        $fields->push(new LiteralField('doInstallDomain', $this->ButtonHelper(
            $this->Link('doInstallDomain'),
            _t('SparkPostAdmin.DOINSTALLDOMAIN', 'Install domain')
        )));
    }

    public function doInstallDomain()
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = SparkPostHelper::getClient();

        $domain = $this->getDomain();

        if (!$domain) {
            return $this->redirectBack();
        }

        try {
            $client->createSimpleSendingDomain($domain);
            $this->getCache()->clear();
        } catch (Exception $ex) {
            $this->getLogger()->debug($ex);
        }

        return $this->redirectBack();
    }

    /**
     * Uninstall domain form
     *
     * @param CompositeField $fieldsd
     * @return FormField
     */
    public function UninstallDomainForm(CompositeField $fields)
    {
        $domainInfos = $this->SendingDomainInstalled();

        $domain = $this->getDomain();

        if ($domainInfos && $domainInfos['status']['ownership_verified']) {
            $fields->push(new LiteralField('Info', $this->MessageHelper(
                _t('SparkPostAdmin.DomainInstalled', 'Default domain {domain} is installed.', ['domain' => $domain]),
                'good'
            )));
        } else {
            $fields->push(new LiteralField('Info', $this->MessageHelper(
                _t('SparkPostAdmin.DomainInstalledBut', 'Default domain {domain} is installed, but is not properly configured.'),
                'warning'
            )));
        }
        $fields->push(new LiteralField('doUninstallHook', $this->ButtonHelper(
            $this->Link('doUninstallHook'),
            _t('SparkPostAdmin.DOUNINSTALLDOMAIN', 'Uninstall domain'),
            true
        )));
    }

    public function doUninstallDomain($data, Form $form)
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = SparkPostHelper::getClient();

        $domain = $this->getDomain();

        if (!$domain) {
            return $this->redirectBack();
        }

        try {
            $el = $this->SendingDomainInstalled();
            if ($el) {
                $client->deleteSendingDomain($domain);
            }
            $this->getCache()->clear();
        } catch (Exception $ex) {
            $this->getLogger()->debug($ex);
        }

        return $this->redirectBack();
    }
}
