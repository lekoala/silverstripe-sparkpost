<?php

namespace LeKoala\SparkPost\Api;

use Exception;
use InvalidArgumentException;
use DateTime;
use LeKoala\SparkPost\EmailUtils;

/**
 * A really simple SparkPost api client
 *
 * @link https://developers.sparkpost.com/api/
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostApiClient
{

    // CLIENT SETTINGS
    const CLIENT_VERSION = '0.2';
    const API_ENDPOINT = 'https://api.sparkpost.com/api/v1';
    const API_ENDPOINT_EU = 'https://api.eu.sparkpost.com/api/v1';
    const METHOD_GET = "GET";
    const METHOD_POST = "POST";
    const METHOD_PUT = "PUT";
    const METHOD_DELETE = "DELETE";
    const DATETIME_FORMAT = 'Y-m-d\TH:i';
    // SPARKPOST TYPES
    const TYPE_MESSAGE = 'message_event'; // Bounce, Delivery, Injection, SMS Status, Spam Complaint, Out of Band, Policy Rejection, Delay
    const TYPE_ENGAGEMENT = 'track_event'; // Click, Open
    const TYPE_GENERATION = 'gen_event'; // Generation Failure, Generation Rejection
    const TYPE_UNSUBSCRIBE = 'unsubscribe_event'; // List Unsubscribe, Link Unsubscribe
    const TYPE_RELAY = 'relay_event'; // Relay Injection, Relay Rejection, Relay Delivery, Relay Temporary Failure, Relay Permanent Failure
    // SPARKPOST EVENTS
    const EVENT_DELIVERY = 'delivery';
    const EVENT_BOUNCE = 'bounce';
    const EVENT_INJECTION = 'injection';
    const EVENT_SMS_STATUS = 'sms_status';
    const EVENT_SPAM_COMPLAINT = 'spam_complaint';
    const EVENT_OUT_OF_BAND = 'out_of_band';
    const EVENT_POLICY_REJECTION = 'policy_rejection';
    const EVENT_DELAY = 'delay';
    const EVENT_OPEN = 'open';
    const EVENT_CLICK = 'click';
    const EVENT_GEN_FAILURE = 'generation_failure';
    const EVENT_GEN_REJECTION = 'generation_rejection';
    const EVENT_LIST_UNSUB = 'list_unsubscribe';
    const EVENT_LINK_UNSUB = 'link_unsubscribe';
    const EVENT_RELAY_INJECTION = 'relay_injection';
    const EVENT_RELAY_REJECTION = 'relay_rejection';
    const EVENT_RELAY_DELIVERY = 'relay_delivery';
    const EVENT_RELAY_TEMPFAIL = 'relay_tempfail';
    const EVENT_RELAY_PERMFAIL = 'relay_permfail';

    /**
     * Your api key
     *
     * @var ?string
     */
    protected $key;

    /**
     * Is eu endpoint ?
     *
     * @var boolean
     */
    protected $euEndpoint = false;

    /**
     * Curl verbose log
     *
     * @var string
     */
    protected $verboseLog = '';

    /**
     * A callback to log results
     *
     * @var callable
     */
    protected $logger;

    /**
     * Results from the api
     *
     * @var array<mixed>
     */
    protected $results = [];

    /**
     * The ID of the subaccount to use
     *
     * @var int
     */
    protected $subaccount;

    /**
     * Client options
     *
     * @var array<mixed>
     */
    protected $curlOpts = [];

    /**
     * Create a new instance of the SparkPostApiClient
     *
     * @param string $key Specify the string, or it will read env SPARKPOST_API_KEY or constant SPARKPOST_API_KEY
     * @param int $subaccount Specify a subaccount to limit data sent by the API
     * @param array<mixed> $curlOpts Additionnal options to configure the curl client
     */
    public function __construct($key = null, $subaccount = null, $curlOpts = [])
    {
        if ($key) {
            $this->key = $key;
        } else {
            $envkey = getenv('SPARKPOST_API_KEY');
            if ($envkey) {
                $this->key = $envkey;
            }
        }
        if (getenv('SPARKPOST_EU')) {
            $this->euEndpoint = boolval(getenv('SPARKPOST_EU'));
        } elseif (defined('SPARKPOST_EU')) {
            $this->euEndpoint = true;
        }
        $this->subaccount = $subaccount;
        $this->curlOpts = array_merge($this->getDefaultCurlOptions(), $curlOpts);
    }

    /**
     * Get default options
     *
     * @return array<mixed>
     */
    public function getDefaultCurlOptions()
    {
        return [
            'connect_timeout' => 10,
            'timeout' => 10,
            'verbose' => false,
        ];
    }

    /**
     * Get an option
     *
     * @param string $name
     * @return mixed
     */
    public function getCurlOption($name)
    {
        if (!isset($this->curlOpts[$name])) {
            throw new InvalidArgumentException("$name is not a valid option. Valid options are : " .
                implode(', ', array_keys($this->curlOpts)));
        }
        return $this->curlOpts[$name];
    }

    /**
     * Set an option
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function setCurlOption($name, $value)
    {
        $this->curlOpts[$name] = $value;
    }

    /**
     * Get the current api key
     *
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Set the current api key
     *
     * @param string $key
     * @return void
     */
    public function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * Get the use of eu endpoint
     *
     * @return bool
     */
    public function getEuEndpoint()
    {
        return $this->euEndpoint;
    }

    /**
     * Set the use of eu endpoint
     *
     * @param bool $euEndpoint
     * @return void
     */
    public function setEuEndpoint($euEndpoint)
    {
        $this->euEndpoint = $euEndpoint;
    }

    /**
     * Get verbose log
     *
     * @return string
     */
    public function getVerboseLog()
    {
        return $this->verboseLog;
    }

    /**
     * Get the logger
     *
     * @return callable
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Set a logging method
     *
     * @param callable $logger
     * @return void
     */
    public function setLogger(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get subaccount id
     *
     * @return int
     */
    public function getSubaccount()
    {
        return $this->subaccount;
    }

    /**
     * Set subaccount id
     *
     * @param int $subaccount
     * @return void
     */
    public function setSubaccount($subaccount)
    {
        $this->subaccount = $subaccount;
    }

    /**
     * Helper that handles dot notation
     *
     * @param array<mixed> $arr
     * @param string $path
     * @param string $val
     * @return mixed
     */
    protected function setMappedValue(array &$arr, $path, $val)
    {
        $loc = &$arr;
        foreach (explode('.', $path) as $step) {
            $loc = &$loc[$step];
        }
        return $loc = $val;
    }

    /**
     * Map data using a given mapping array
     *
     * @param array<mixed> $data
     * @param array<mixed> $map
     * @return array<mixed>
     */
    protected function mapData($data, $map)
    {
        $mappedData = [];
        foreach ($data as $k => $v) {
            $key = $k;
            if (isset($map[$k])) {
                $key = $map[$k];
            }
            $this->setMappedValue($mappedData, $key, $v);
        }
        return $mappedData;
    }

    /**
     * Create a transmission
     *
     * 'campaign'
     * 'metadata'
     * 'substitutionData'
     * 'description'
     * 'returnPath'
     * 'replyTo'
     * 'subject'
     * 'from'
     * 'html'
     * 'text'
     * 'attachments'
     * 'rfc822'
     * 'customHeaders'
     * 'recipients'
     * 'recipientList'
     * 'template'
     * 'trackOpens'
     * 'trackClicks'
     * 'startTime'
     * 'transactional'
     * 'sandbox'
     * 'useDraftTemplate'
     * 'inlineCss'
     *
     * @link https://developers.sparkpost.com/api/transmissions.html
     * @param array<mixed> $data
     * @return array<mixed> An array containing 3 keys: total_rejected_recipients, total_accepted_recipients, id
     */
    public function createTransmission($data)
    {
        // Use the same mapping as official sdk
        $mapping = [
            'campaign' => 'campaign_id',
            'metadata' => 'metadata',
            'substitutionData' => 'substitution_data',
            'description' => 'description',
            'returnPath' => 'return_path',
            'replyTo' => 'content.reply_to',
            'subject' => 'content.subject',
            'from' => 'content.from',
            'html' => 'content.html',
            'text' => 'content.text',
            'attachments' => 'content.attachments',
            'rfc822' => 'content.email_rfc822',
            'customHeaders' => 'content.headers',
            'recipients' => 'recipients',
            'recipientList' => 'recipients.list_id',
            'template' => 'content.template_id',
            'trackOpens' => 'options.open_tracking',
            'trackClicks' => 'options.click_tracking',
            'startTime' => 'options.start_time',
            'transactional' => 'options.transactional',
            'sandbox' => 'options.sandbox',
            'useDraftTemplate' => 'use_draft_template',
            'inlineCss' => 'options.inline_css',
        ];

        $data = $this->mapData($data, $mapping);

        return $this->makeRequest('transmissions', self::METHOD_POST, $data);
    }

    /**
     * Get the detail of a transmission
     *
     * @param string $id
     * @return array<mixed>
     */
    public function getTransmission($id)
    {
        return $this->makeRequest('transmissions/' . $id);
    }

    /**
     * Delete a transmission
     *
     * @param string $id
     * @return array<mixed>
     */
    public function deleteTransmission($id)
    {
        return $this->makeRequest('transmissions/' . $id, self::METHOD_DELETE);
    }

    /**
     * List tranmssions
     *
     * @param string $campaignId
     * @param string $templateId
     * @return array<mixed>
     */
    public function listTransmissions($campaignId = null, $templateId = null)
    {
        $params = [];
        if ($campaignId !== null) {
            $params['campaign_id'] = $campaignId;
        }
        if ($templateId !== null) {
            $params['template_id'] = $templateId;
        }
        return $this->makeRequest('transmissions', self::METHOD_GET, $params);
    }

    /**
     * Search message events
     *
     * Use the following parameters (default is current timezone, 100 messages for the last 7 days)
     *
     * 'bounce_classes' : delimited list of bounce classification codes to search.
     * 'campaign_ids' : delimited list of campaign ID's to search (i.e. campaign_id used during creation of a transmission).
     * 'delimiter' : Specifies the delimiter for query parameter lists
     * 'events' : delimited list of event types to search.  Example: delivery, injection, bounce, delay, policy_rejection, out_of_band, open, click, ...
     * 'friendly_froms' : delimited list of friendly_froms to search.
     * 'from' : Datetime in format of YYYY-MM-DDTHH:MM.
     * 'message_ids' : delimited list of message ID's to search.
     * 'page' : The results page number to return. Used with per_page for paging through results
     * 'per_page' : Number of results to return per page. Must be between 1 and 10,000 (inclusive).
     * 'reason' : Bounce/failure/rejection reason that will be matched using a wildcard (e.g., %reason%)
     * 'recipients' : delimited list of recipients to search.
     * 'subaccounts' :  delimited list of subaccount ID’s to search..
     * 'template_ids' : delimited list of template ID's to search.
     * 'timezone' : Standard timezone identification string
     * 'to' : Datetime in format of YYYY-MM-DDTHH:MM
     * 'transmission_ids' : delimited list of transmission ID's to search (i.e. id generated during creation of a transmission).
     *
     * Result is an array that looks like this
     *
     * [customer_id] => 0000
     * [delv_method] => esmtp
     * [event_id] => 99997643157770993
     * [friendly_from] => some@email.ext
     * [ip_address] => 12.34.56.78
     * [message_id] => abcd2fd71057477a0fa5
     * [msg_from] => msprvs1=000000Q7Zx0yG=bounces-12345-1234@sparkpostmail1.com
     * [msg_size] => 1234
     * [num_retries] => 0
     * [queue_time] => 1234
     * [raw_rcpt_to] => some@email.ext
     * [rcpt_meta] => Array
     * [rcpt_tags] => Array
     * [rcpt_to] => some@email.ext
     * [routing_domain] => email.ext
     * [subaccount_id] => 0000
     * [subject] => my test subject
     * [tdate] => 2050-01-01T11:57:36.000Z
     * [template_id] => template_123456789
     * [template_version] => 0
     * [transactional] => 1
     * [transmission_id] => 12234554568854
     * [type] => delivery
     * [timestamp] =>  2050-01-01T11:57:36.000Z
     *
     * @deprecated
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function searchMessageEvents($params = [])
    {
        $defaultParams = [
            'timezone' => date_default_timezone_get(),
            'per_page' => 100,
            'from' => $this->createValidDatetime('-7 days'),
        ];
        $params = array_merge($defaultParams, $params);

        return $this->makeRequest('message-events', self::METHOD_GET, $params);
    }

    /**
     * Search for Message Events
     *
     * Parameters
     * - from string, default is 24 hours ago
     * - per_page number, default is 1000
     * - event_ids string
     * - events string, default is all event types
     * - recipients string
     * - recipient_domains string
     * - from_addresses string
     * - sending_domains string
     * - subjects string
     * - bounce_classes number
     * - reasons string
     * - campaigns string
     * - templates string
     * - sending_ips string
     * - ip_pools string
     * - subaccounts string
     * - messages string
     * - transmissions string
     * - mailbox_providers string
     * - mailbox_provider_regions string
     * - ab_tests string
     * - ab_test_versions number
     *
     * Result is an array of objects that looks like this
     *
     * "mailbox_provider" => "SomeProvider"
     * "template_version" => "0"
     * "friendly_from" => "noreply@testing.example.com"
     * "subject" => "My email"
     * "ip_pool" => "default"
     * "sending_domain" => "testing.example.com"
     * "rcpt_tags" => []
     * "type" => "initial_open"
     * "mailbox_provider_region" => "Global"
     * "raw_rcpt_to" => "recipient@dest.com"
     * "msg_from" => "msprvs1=46848646040zazea=bounces-99999-1@bounce.example.com"
     * "geo_ip" => array:8 [▶]
     * "rcpt_to" => "recipient@dest.com"
     * "subaccount_id" => 1
     * "transmission_id" => "1230984717797820762"
     * "user_agent" => "Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)"
     * "timestamp" => "2055-02-19T14:44:29.000Z"
     * "click_tracking" => true
     * "rcpt_meta" => []
     * "message_id" => "122da1ce2f606b5c07dc"
     * "ip_address" => "12.123.11.11"
     * "initial_pixel" => true
     * "recipient_domain" => "dest.com"
     * "event_id" => "5454130313444582480096"
     * "routing_domain" => "dest.com"
     * "sending_ip" => "99.99.99.99"
     * "template_id" => "template_693098471779782565"
     * "delv_method" => "esmtp"
     * "customer_id" => 99999
     * "open_tracking" => true
     * "injection_time" => "2055-02-19T14:43:45.000Z"
     * "transactional" => "1"
     * "msg_size" => "48613"
     *
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function searchEvents($params = [])
    {
        return $this->makeRequest('events/message', self::METHOD_GET, $params);
    }

    /**
     * Create a webhook by providing a webhooks object as the POST request body.
     * On creation, events will begin to be pushed to the target URL specified in the POST request body.
     *
     * {
     * "name": "Example webhook",
     * "target": "http://client.example.com/example-webhook",
     * "auth_type": "oauth2",
     * "auth_request_details": {
     * "url": "http://client.example.com/tokens",
     * "body": {
     *   "client_id": "CLIENT123",
     *   "client_secret": "9sdfj791d2bsbf",
     *   "grant_type": "client_credentials"
     * }
     * },
     * "auth_token": "",
     *   "events": [
     *   "delivery",
     *   "injection",
     *   "open",
     *   "click"
     * ]
     * }
     *
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function createWebhook($params = [])
    {
        return $this->makeRequest('webhooks', self::METHOD_POST, $params);
    }

    /**
     * A simpler call to the api
     *
     * @param string $name
     * @param string $target
     * @param array<mixed> $events
     * @param bool $auth Should we use basic auth ?
     * @param array<mixed> $credentials An array containing "username" and "password"
     * @return array<mixed>
     */
    public function createSimpleWebhook($name, $target, array $events = null, $auth = false, $credentials = null)
    {
        if ($events === null) {
            // Default to the most used events
            $events = [
                'delivery', 'injection', 'open', 'click', 'bounce', 'spam_complaint',
                'list_unsubscribe', 'link_unsubscribe'
            ];
        }
        $params = [
            'name' => $name,
            'target' => $target,
            'events' => $events,
        ];
        if ($auth) {
            if ($credentials === null) {
                $credentials = ['username' => "sparkpost", "password" => "sparkpost"];
            }
            $params['auth_type'] = 'basic';
            $params['auth_credentials'] = $credentials;
        }
        return $this->createWebhook($params);
    }

    /**
     * List all webhooks
     *
     * @param string $timezone
     * @return array<mixed>
     */
    public function listAllWebhooks($timezone = null)
    {
        $params = [];
        if ($timezone) {
            $params['timezone'] = $timezone;
        }
        return $this->makeRequest('webhooks', self::METHOD_GET, $params);
    }

    /**
     * Get a webhook
     *
     * @param string $id
     * @return array<mixed>
     */
    public function getWebhook($id)
    {
        return $this->makeRequest('webhooks/' . $id, self::METHOD_GET);
    }

    /**
     * Update a webhook
     *
     * @param string $id
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function updateWebhook($id, $params = [])
    {
        return $this->makeRequest('webhooks/' . $id, self::METHOD_PUT, $params);
    }

    /**
     * Delete a webhook
     *
     * @param string $id
     * @return array<mixed>
     */
    public function deleteWebhook($id)
    {
        return $this->makeRequest('webhooks/' . $id, self::METHOD_DELETE);
    }

    /**
     * Validate a webhook
     *
     * @param string $id
     * @return array<mixed>
     */
    public function validateWebhook($id)
    {
        return $this->makeRequest('webhooks/' . $id . '/validate', self::METHOD_POST, '{"msys": {}}');
    }

    /**
     * Retrieve status information regarding batches that have been generated
     * for the given webhook by specifying its id in the URI path. Status
     * information includes the successes of batches that previously failed to
     * reach the webhook's target URL and batches that are currently in a failed state.
     *
     * @param string $id
     * @param int $limit
     * @return array<mixed>
     */
    public function webhookBatchStatus($id, $limit = 1000)
    {
        return $this->makeRequest('webhooks/' . $id . '/batch-status', self::METHOD_GET, ['limit' => 1000]);
    }

    /**
     * List an example of the event data that will be posted by a Webhook for the specified events.
     *
     * @param string $events bounce, delivery...
     * @return array<mixed>
     */
    public function getSampleEvents($events = null)
    {
        $params = [];
        if ($events) {
            $params['events'] = $events;
        }
        return $this->makeRequest('webhooks/events/samples/', self::METHOD_GET, $params);
    }

    /**
     * Create a sending domain
     *
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function createSendingDomain($params = [])
    {
        return $this->makeRequest('sending-domains', self::METHOD_POST, $params);
    }

    /**
     * A simpler call to the api
     *
     * @param string $name
     * @return array<mixed>
     */
    public function createSimpleSendingDomain($name)
    {
        $params = [
            'domain' => $name,
        ];
        return $this->createSendingDomain($params);
    }

    /**
     * List all sending domains
     *
     * @return array<mixed>
     */
    public function listAllSendingDomains()
    {
        return $this->makeRequest('sending-domains', self::METHOD_GET);
    }

    /**
     * Get a sending domain
     *
     * @param string $id
     * @return array<mixed>
     */
    public function getSendingDomain($id)
    {
        return $this->makeRequest('sending-domains/' . $id, self::METHOD_GET);
    }

    /**
     * Verify a sending domain - This will ask SparkPost to check if SPF and DKIM are valid
     *
     * @param string $id
     * @return array<mixed>
     */
    public function verifySendingDomain($id)
    {
        return $this->makeRequest('sending-domains/' . $id . '/verify', self::METHOD_POST, [
            'dkim_verify' => true,
            'spf_verify' => true
        ]);
    }

    /**
     * Update a sending domain
     *
     * @param string $id
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function updateSendingDomain($id, $params = [])
    {
        return $this->makeRequest('sending-domains/' . $id, self::METHOD_PUT, $params);
    }

    /**
     * Delete a sending domain
     *
     * @param string $id
     * @return array<mixed>
     */
    public function deleteSendingDomain($id)
    {
        return $this->makeRequest('sending-domains/' . $id, self::METHOD_DELETE);
    }

    /**
     * Create an inbound domain
     *
     * @param string $domain
     * @return array<mixed>
     */
    public function createInboundDomain($domain)
    {
        return $this->makeRequest('inbound-domains', self::METHOD_POST, ['domain' => $domain]);
    }

    /**
     * List all inbound domains
     *
     * @return array<mixed>
     */
    public function listInboundDomains()
    {
        return $this->makeRequest('inbound-domains', self::METHOD_GET);
    }

    /**
     * Get details of an inbound domain
     *
     * @param string $domain
     * @return array<mixed>
     */
    public function getInboundDomain($domain)
    {
        return $this->makeRequest('inbound-domains/' . $domain, self::METHOD_GET);
    }

    /**
     * Delete an inbound domain
     *
     * @param string $domain
     * @return array<mixed>
     */
    public function deleteInboundDomain($domain)
    {
        return $this->makeRequest('inbound-domains/' . $domain, self::METHOD_DELETE);
    }

    /**
     * Create a relay webhook
     *
     *  "name": "Replies Webhook",
     *  "target": "https://webhooks.customer.example/replies",
     * "auth_token": "5ebe2294ecd0e0f08eab7690d2a6ee69",
     *  "match": {
     *  "protocol": "SMTP",
     * "domain": "email.example.com"
     * }
     *
     * @param array<mixed>|string $params
     * @return array<mixed>
     */
    public function createRelayWebhook($params)
    {
        return $this->makeRequest('relay-webhooks', self::METHOD_POST, $params);
    }

    /**
     * List all relay webhooks
     *
     * @return array<mixed>
     */
    public function listRelayWebhooks()
    {
        return $this->makeRequest('relay-webhooks', self::METHOD_GET);
    }

    /**
     * Get the details of a relay webhook
     *
     * @param int $id
     * @return array<mixed>
     */
    public function getRelayWebhook($id)
    {
        return $this->makeRequest('relay-webhooks/' . $id, self::METHOD_GET);
    }

    /**
     * Update a relay webhook
     *
     * @param int $id
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function updateRelayWebhook($id, $params)
    {
        return $this->makeRequest('relay-webhooks/' . $id, self::METHOD_PUT, $params);
    }

    /**
     * Delete a relay webhook
     *
     * @param int $id
     * @return array<mixed>
     */
    public function deleteRelayWebhook($id)
    {
        return $this->makeRequest('relay-webhooks/' . $id, self::METHOD_DELETE);
    }

    /**
     * @link https://developers.sparkpost.com/api/suppression-list/#suppression-list-get-retrieve-a-suppression
     * @param string $recipient
     * @return array<array{"recipient":string,"type":string,"source":string,"description":string,"created":string,"updated":string,"transactional":bool,"subaccount_id"?:int}>
     */
    public function getSuppression($recipient)
    {
        return $this->makeRequest('suppression-list/' . $recipient, self::METHOD_GET);
    }

    /**
     * @link https://developers.sparkpost.com/api/suppression-list/#suppression-list-put-create-or-update-a-suppression
     * @param string $recipient
     * @param bool $isTransactional
     * @param string $description
     * @return array<mixed>
     */
    public function createSuppression($recipient, $isTransactional = true, $description = '')
    {
        return $this->makeRequest('suppression-list/' . $recipient, self::METHOD_PUT, [
            'type' => $isTransactional ? 'transactional' : 'non_transactional',
            'description' => $description
        ]);
    }

    /**
     * @param string $recipient
     * @return array<mixed>
     */
    public function deleteSuppression($recipient)
    {
        return $this->makeRequest('suppression-list/' . $recipient, self::METHOD_DELETE);
    }

    /**
     * @link https://developers.sparkpost.com/api/suppression-list/#suppression-list-get-search-suppressions
     * @param array{'from'?:string,'to'?:string,'domain'?:string,'sources'?:string,'types'?:string,'description'?:string} $params
     * @return array<array{"recipient":string,"type":string,"source":string,"description":string,"created":string,"updated":string,"transactional":bool}>
     */
    public function searchSuppressions($params = [])
    {
        $defaultParams = [
            'per_page' => 10,
            'from' => $this->createValidDatetime('-30 days'),
        ];
        $params = array_merge($defaultParams, $params);

        return $this->makeRequest('suppression-list', self::METHOD_GET, $params);
    }

    /**
     * @link https://developers.sparkpost.com/api/suppression-list/#suppression-list-get-retrieve-summary
     * @return array{"spam_complaint":int,"list_unsubscribe":int,"bounce_rule":int,"unsubscribe_link":int,"manually_added":int,"compliance":int,"total":int}
     */
    public function suppressionSummary()
    {
        //@phpstan-ignore-next-line
        return $this->makeRequest('suppression-list/summary', self::METHOD_GET);
    }

    /**
     * Create a valid date for the API
     *
     * @param string|int $time
     * @param string $format
     * @return string Datetime in format of YYYY-MM-DDTHH:MM
     */
    public function createValidDatetime($time, $format = null)
    {
        if (!is_int($time)) {
            $time = strtotime($time);
        }
        if (!$time) {
            throw new Exception("Invalid time");
        }
        if (!$format) {
            $dt = new DateTime('@' . $time);
        } else {
            $dt = DateTime::createFromFormat((string)$format, (string)$time);
        }
        if (!$dt) {
            throw new Exception("Invalid datetime");
        }
        return $dt->format(self::DATETIME_FORMAT);
    }

    /**
     * Build an address object
     *
     * @param string $email
     * @param string $name
     * @param string $header_to
     * @return array<mixed>
     */
    public function buildAddress($email, $name = null, $header_to = null)
    {
        $address = [
            'email' => $email
        ];
        if ($name) {
            $address['name'] = $name;
        }
        if ($header_to) {
            $address['header_to'] = $header_to;
        }
        return $address;
    }

    /**
     * Build an address object from a RFC 822 email string
     *
     * @param string $string
     * @param string $header_to
     * @return array<mixed>
     */
    public function buildAddressFromString($string, $header_to = null)
    {
        $email = EmailUtils::get_email_from_rfc_email($string);
        $name = EmailUtils::get_displayname_from_rfc_email($string);
        return $this->buildAddress($email, $name, $header_to);
    }

    /**
     * Build a recipient
     *
     * @param string|array<mixed> $address
     * @param array<mixed> $tags
     * @param array<mixed> $metadata
     * @param array<mixed> $substitution_data
     * @return array<mixed>
     * @throws Exception
     */
    public function buildRecipient($address, array $tags = null, array $metadata = null, array $substitution_data = null)
    {
        if (is_array($address)) {
            if (empty($address['email'])) {
                throw new Exception('Address must contain an email');
            }
        }
        $recipient = [
            'address' => $address
        ];

        if (!empty($tags)) {
            $recipient['tags'] = $tags;
        }
        if (!empty($metadata)) {
            $recipient['metadata'] = $metadata;
        }
        if (!empty($tags)) {
            $recipient['substitution_data'] = $substitution_data;
        }

        return $recipient;
    }

    /**
     * Make a request to the api using curl
     *
     * @param string $endpoint
     * @param string $action
     * @param array<mixed>|string $data
     * @return array<mixed>
     * @throws Exception
     */
    protected function makeRequest($endpoint, $action = null, $data = null)
    {
        if (!$this->key) {
            throw new Exception('You must set an API key before making requests');
        }

        $ch = curl_init();

        if ($action === null) {
            $action = self::METHOD_GET;
        } else {
            $action = strtoupper($action);
        }

        if (is_array($data) && !empty($data)) {
            if ($action === self::METHOD_GET) {
                $endpoint .= '?' . http_build_query($data);
            }
            if ($action === self::METHOD_POST) {
                $data = json_encode($data);
            }
        }

        $header = [];
        $header[] = 'Content-Type: application/json';
        $header[] = 'Authorization: ' . $this->key;
        if ($this->subaccount) {
            $header[] = 'X-MSYS-SUBACCOUNT: ' . $this->subaccount;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SparkPostApiClient v' . self::CLIENT_VERSION);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($this->euEndpoint) {
            curl_setopt($ch, CURLOPT_URL, self::API_ENDPOINT_EU . '/' . $endpoint);
        } else {
            curl_setopt($ch, CURLOPT_URL, self::API_ENDPOINT . '/' . $endpoint);
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$this->getCurlOption('connect_timeout'));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$this->getCurlOption('timeout'));

        // Collect verbose data in a stream
        if ($this->getCurlOption('verbose')) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            $verbose = fopen('php://temp', 'w+');
            if ($verbose === false) {
                throw new Exception("Failed to open stream");
            }
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
        }

        // This fixes ca cert issues if server is not configured properly
        $cainfo = ini_get('curl.cainfo');
        if ($cainfo !== false) {
            if (strlen($cainfo) === 0) {
                curl_setopt($ch, CURLOPT_CAINFO, \Composer\CaBundle\CaBundle::getBundledCaBundlePath());
            }
        }

        switch ($action) {
            case self::METHOD_POST:
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case self::METHOD_DELETE:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
        }

        $result = curl_exec($ch);

        if (!$result) {
            throw new Exception('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
        }
        if (is_bool($result)) {
            throw new Exception("CURLOPT_RETURNTRANSFER was not set");
        }

        if ($this->getCurlOption('verbose')) {
            rewind($verbose);
            $this->verboseLog .= stream_get_contents($verbose);
        }

        curl_close($ch);

        // In some cases, SparkPost api returns this strange empty result
        if ($result == '{ }') {
            $decodedResult = ['results' => null];
        } else {
            $decodedResult = json_decode($result, true);
            if (!$decodedResult) {
                throw new Exception("Failed to decode $result : " . json_last_error_msg());
            }
        }

        $this->results[] = $decodedResult;

        if (isset($decodedResult['errors'])) {
            $errors = array_map(function ($item) use ($data) {
                $message = $item['message'];
                // Prepend code to message
                if (isset($item['code'])) {
                    $message = $item['code'] . ' - ' . $message;

                    // For invalid domains, append domain name to make error more useful
                    if ($item['code'] == 7001) {
                        $from = '';
                        if (!is_array($data) && is_string($data)) {
                            $data = json_decode($data, true);
                        }
                        if (isset($data['content']['from'])) {
                            $from = $data['content']['from'];
                        }
                        if ($from && is_string($from)) {
                            $fromat = strrchr($from, "@");
                            if ($fromat) {
                                $domain = substr($fromat, 1);
                                $message .= ' (' . $domain . ')';
                            }
                        }
                    }

                    // For invalid recipients, append recipients
                    if ($item['code'] == 5002) {
                        if (isset($data['recipients'])) {
                            if (empty($data['recipients'])) {
                                $message .= ' (empty recipients list)';
                            } else {
                                $addresses = [];
                                if (is_array($data['recipients'])) {
                                    foreach ($data['recipients'] as $recipient) {
                                        $addresses[] = json_encode($recipient['address']);
                                    }
                                }
                                $message .= ' (' . implode(',', $addresses) . ')';
                            }
                        } else {
                            $message .= ' (no recipients defined)';
                        }
                    }
                }
                if (isset($item['description'])) {
                    $message .= ': ' . $item['description'];
                }
                return $message;
            }, $decodedResult['errors']);
            throw new Exception("The API returned the following error(s) : " . implode("; ", $errors));
        }

        return $decodedResult['results'];
    }

    /**
     * Get all results from the api
     *
     * @return array<mixed>
     */
    public function getResults()
    {
        return $this->results;
    }

    /**
     * Get last result
     *
     * @return array<mixed>
     */
    public function getLastResult()
    {
        return end($this->results);
    }
}
