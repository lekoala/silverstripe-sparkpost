<?php

/**
 * A really simple SparkPost api client
 *
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostApiClient
{
    const CLIENT_VERSION  = '0.1';
    const API_ENDPOINT    = 'https://api.sparkpost.com/api/v1';
    const METHOD_GET      = "GET";
    const METHOD_POST     = "POST";
    const METHOD_PUT      = "PUT";
    const METHOD_DELETE   = "DELETE";
    const DATETIME_FORMAT = 'Y-m-d\TH:i';

    /**
     * Your api key
     *
     * @var string
     */
    protected $key;

    /**
     * Debug mode enabled
     *
     * @var boolean
     */
    protected $debug = false;

    /**
     * A callback to log results
     *
     * @var callable
     */
    protected $logger;

    /**
     * Results from the api
     *
     * @var array
     */
    protected $results = [];

    /**
     * Create a new instance of the SparkPostApiClient
     *
     * @param string $key Specify the string, or it will read env SPARKPOST_API_KEY or constant SPARKPOST_API_KEY
     * @param boolean $debug
     */
    public function __construct($key = null, $debug = false)
    {
        if ($key) {
            $this->key = $key;
        } else {
            $this->key = getenv('SPARKPOST_API_KEY');
            if (!$this->key && defined('SPARKPOST_API_KEY')) {
                $this->key = SPARKPOST_API_KEY;
            }
        }
        $this->debug = $debug;
    }

    /**
     * Get the current api key
     *
     * @return string
     */
    function getKey()
    {
        return $this->key;
    }

    /**
     * Set the current api key
     *
     * @param string $key
     */
    function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * Get debug mode flag
     *
     * @return boolean
     */
    function getDebug()
    {
        return $this->debug;
    }

    /**
     * Set debug mode
     *
     * @param boolean $debug
     */
    function setDebug($debug = true)
    {
        $this->debug = $debug;
    }

    /**
     * Get the logger
     *
     * @return type
     */
    function getLogger()
    {
        return $this->logger;
    }

    /**
     * Set a logging method
     *
     * @param callable $logger
     */
    function setLogger(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Helper that handles dot notation
     *
     * @param array $arr
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
     * @param array $data
     * @param array $map
     * @return array
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
     * replyTo'
     * 'subject'
     * 'from'
     * 'html'
     * 'text'
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
     *
     * @param array $data
     * @return array
     */
    public function createTransmissions($data)
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
            'useDraftTemplate' => 'use_draft_template'
        ];

        $data = $this->mapData($data, $mapping);

        return $this->makeRequest('transmissions', self::METHOD_POST, $data);
    }

    /**
     * Get the detail of a transmission
     *
     * @param string $id
     * @return array
     */
    public function getTransmission($id)
    {
        return $this->makeRequest('transmissions/'.$id);
    }

    /**
     * Delete a transmission
     *
     * @param string $id
     * @return array
     */
    public function deleteTransmission($id)
    {
        return $this->makeRequest('transmissions/'.$id, self::METHOD_DELETE);
    }

    /**
     * List tranmssions
     *
     * @param string $campaignId
     * @param string $templateId
     * @return array
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
     * 'bounce_classes' : Comma-delimited list of bounce classification codes to search.
     * 'campaign_ids' : Comma-delimited list of campaign ID's to search (i.e. campaign_id used during creation of a transmission).
     * 'events' : Comma-delimited list of event types to search.  Example: delivery, injection, bounce, delay, policy_rejection, out_of_band, open, click, ...
     * 'friendly_froms' : Comma-delimited list of friendly_froms to search.
     * 'from' : Datetime in format of YYYY-MM-DDTHH:MM.
     * 'message_ids' : Comma-delimited list of message ID's to search.
     * 'page' : The results page number to return. Used with per_page for paging through results
     * 'per_page' : Number of results to return per page. Must be between 1 and 10,000 (inclusive).
     * 'reason' : Bounce/failure/rejection reason that will be matched using a wildcard (e.g., %reason%)
     * 'recipients' : Comma-delimited list of recipients to search.
     * 'template_ids' : Comma-delimited list of template ID's to search.
     * 'timezone' : Standard timezone identification string
     * 'to' : Datetime in format of YYYY-MM-DDTHH:MM
     * 'transmission_ids' : Comma-delimited list of transmission ID's to search (i.e. id generated during creation of a transmission).
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
     * @param array $params
     * @return array
     */
    public function searchMessageEvents($params = [])
    {
        $defaultParams = [
            'timezone' => date_default_timezone_get(),
            'per_page' => 100,
            'from' => $this->createValidDatetime('-7 days'),
        ];
        $params        = array_merge($defaultParams, $params);

        return $this->makeRequest('message-events', self::METHOD_GET, $params);
    }

    /**
     * Create a webhook
     *
     * @param string $params
     * @return array
     */
    public function createWebhook($params = [])
    {
        return $this->makeRequest('relay-webhooks', self::METHOD_POST, $params);
    }

    /**
     * A simpler call to the api
     *
     * @param string $target
     * @param string|array $match A match object or the inbound domain
     * @param array $name
     * @return array
     */
    public function createSimpleWebhook($target, $match, $name = null)
    {
        if (is_string($match)) {
            $match = ['domain' => $match];
        }
        $params = [
            'target' => $target,
            'match' => $match,
        ];
        if ($name) {
            $params['name'] = $name;
        }
        return $this->createWebhook($params);
    }

    /**
     * List all webhooks
     *
     * @return array
     */
    public function listAllWebhooks()
    {
        return $this->makeRequest('relay-webhooks', self::METHOD_GET);
    }

    /**
     * Get a webhook
     *
     * @param string $id
     * @return array
     */
    public function getWebhook($id)
    {
        return $this->makeRequest('relay-webhooks/'.$id, self::METHOD_GET);
    }

    /**
     * Update a webhook
     *
     * @param string $id
     * @param array $params
     * @return array
     */
    public function updateWebhook($id, $params = [])
    {
        return $this->makeRequest('relay-webhooks/'.$id, self::METHOD_PUT,
                $params);
    }

    /**
     * Delete a webhook
     *
     * @param string $id
     * @return array
     */
    public function deleteWebhook($id)
    {
        return $this->makeRequest('relay-webhooks/'.$id, self::METHOD_DELETE);
    }

    /**
     * Create a sending domain
     *
     * @param string $params
     * @return array
     */
    public function createSendingDomain($params = [])
    {
        return $this->makeRequest('sending-domains', self::METHOD_POST, $params);
    }

    /**
     * A simpler call to the api
     *
     * @param string $name
     * @return array
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
     * @return array
     */
    public function listAllSendingDomains()
    {
        return $this->makeRequest('sending-domains', self::METHOD_GET);
    }

    /**
     * Get a sending domain
     *
     * @param string $id
     * @return array
     */
    public function getSendingDomain($id)
    {
        return $this->makeRequest('sending-domains/'.$id, self::METHOD_GET);
    }

    /**
     * Verify a sending domain
     *
     * @param string $id
     * @return array
     */
    public function verifySendingDomain($id)
    {
        return $this->makeRequest('sending-domains/'.$id.'/verify',
                self::METHOD_POST,
                [
                'dkim_verify' => true,
                'spf_verify' => true
        ]);
    }

    /**
     * Update a sending domain
     *
     * @param string $id
     * @param array $params
     * @return array
     */
    public function updateSendingDomain($id, $params = [])
    {
        return $this->makeRequest('sending-domainss/'.$id, self::METHOD_PUT,
                $params);
    }

    /**
     * Delete a sending domain
     *
     * @param string $id
     * @return array
     */
    public function deleteSendingDomain($id)
    {
        return $this->makeRequest('sending-domains/'.$id, self::METHOD_DELETE);
    }

    /**
     * Create a valid date for the API
     *
     * @param string $time
     * @param string $format
     * @return string Datetime in format of YYYY-MM-DDTHH:MM
     */
    public function createValidDatetime($time, $format = null)
    {
        if (!is_int($time)) {
            $time = strtotime($time);
        }
        if (!$format) {
            $dt = new DateTime('@'.$time);
        } else {
            $dt = DateTime::createFromFormat($format, $time);
        }
        return $dt->format(self::DATETIME_FORMAT);
    }

    /**
     * Build an address object
     *
     * @param string $email
     * @param string $name
     * @param string $header_to
     * @return array
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
     * @return array
     */
    public function buildAddressFromString($string, $header_to = null)
    {
        $email = self::get_email_from_rfc_email($string);
        $name  = self::get_displayname_from_rfc_email($string);
        return $this->buildAddress($email, $name, $header_to);
    }

    /**
     * Build a recipient
     *
     * @param string|array $address
     * @param array $tags
     * @param array $metadata
     * @param array $substitution_data
     * @return array
     * @throws Exception
     */
    public function buildRecipient($address, array $tags = null,
                                   array $metadata = null,
                                   array $substitution_data = null)
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
     * @param array $data
     * @return array
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

        if ($action === self::METHOD_GET && !empty($data)) {
            $endpoint .= '?'.http_build_query($data);
        }
        if ($action === self::METHOD_POST && is_array($data)) {
            $data = json_encode($data);
        }

        $header   = [];
        $header[] = 'Content-Type: application/json';
        $header[] = 'Authorization: '.$this->key;

        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_USERAGENT,
            'SparkPostApiClient v'.self::CLIENT_VERSION);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, self::API_ENDPOINT.'/'.$endpoint);

        if ($this->debug) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }

        // This fixes ca cert issues if server is not configured properly
        if (strlen(ini_get('curl.cainfo')) === 0) {
            curl_setopt($ch, CURLOPT_CAINFO, __DIR__."/cacert.pem");
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
            throw new Exception('Error: "'.curl_error($ch).'" - Code: '.curl_errno($ch));
        }

        curl_close($ch);

        $decodedResult = json_decode($result, true);
        if (!$decodedResult) {
            throw new Exception("Failed to decode json : ".self::json_last_error_msg());
        }

        $this->results[] = $decodedResult;

        if (isset($decodedResult['errors'])) {
            $errors = array_map(function($item) {
                $message = $item['message'];
                if (isset($item['description'])) {
                    $message .= ': '.$item['description'];
                }
                return $message;
            }, $decodedResult['errors']);
            throw new Exception("The API returned the following error(s) : ".implode(", ",
                $errors));
        }

        return $decodedResult['results'];
    }

    /**
     * Get all results from the api
     *
     * @return array
     */
    public function getResults()
    {
        return $this->results;
    }

    /**
     * Get last result
     *
     * @return array
     */
    public function getLastResult()
    {
        return end($this->results);
    }

    /**
     * Helper for json errors
     *
     * @staticvar array $ERRORS
     * @return string
     */
    protected static function json_last_error_msg()
    {
        static $ERRORS = [
            JSON_ERROR_NONE => 'No error',
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'State mismatch (invalid or malformed JSON)',
            JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded'
        ];

        $error = json_last_error();
        return isset($ERRORS[$error]) ? $ERRORS[$error] : 'Unknown error';
    }

    /**
     * Match all words and whitespace, will be terminated by '<'
     *
     * Note: use /u to support utf8 strings
     *
     * @param string $rfc_email_string
     * @return string
     */
    protected static function get_displayname_from_rfc_email($rfc_email_string)
    {
        $name       = preg_match('/[\w\s]+/u', $rfc_email_string, $matches);
        $matches[0] = trim($matches[0]);
        return $matches[0];
    }

    /**
     * Extract parts between brackets
     *
     * @param string $rfc_email_string
     * @return string
     */
    protected static function get_email_from_rfc_email($rfc_email_string)
    {
        if (strpos($rfc_email_string, '<') === false) {
            return $rfc_email_string;
        }
        $mailAddress = preg_match('/(?:<)(.+)(?:>)$/', $rfc_email_string,
            $matches);
        if (empty($matches)) {
            return $rfc_email_string;
        }
        return $matches[1];
    }
}