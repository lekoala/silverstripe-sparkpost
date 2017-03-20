<?php

/**
 * Provide extensions points for handling the webhook
 *
 * @author LeKoala <thomas@lekoala.be>
 * @mixin InvoiceWebhookExtension
 */
class SparkPostController extends Controller
{

    protected $eventsCount = 0;
    protected $skipCount = 0;
    private static $allowed_actions = [
        'incoming',
        'test',
        'configure_inbound_emails'
    ];

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
     * You can also see /resources/sample.json
     * 
     * @param SS_HTTPRequest $req
     */
    public function test(SS_HTTPRequest $req)
    {
        if (!Director::isDev()) {
            return 'You can only test in dev mode';
        }

        $client = $this->getClient();

        $file = $this->getRequest()->getVar('file');
        if ($file) {
            $data = file_get_contents(Director::baseFolder() . '/' . rtrim($file, '/'));
            $payload = json_decode($data, JSON_OBJECT_AS_ARRAY);
        } else {
            $payload = $client->getSampleEvents();
        }

        $this->processPayload($payload, 'TEST');

        return 'TEST OK - ' . $this->eventsCount . ' events processed / ' . $this->skipCount . ' events skipped';
    }

    /**
     * @link https://support.sparkpost.com/customer/portal/articles/2039614-enabling-inbound-email-relaying-relay-webhooks
     * @param SS_HTTPRequest $req
     * @return string
     */
    public function configure_inbound_emails(SS_HTTPRequest $req)
    {
        if (!Director::isDev() || !Permission::check('ADMIN')) {
            return 'You must be in dev mode or be logged as an admin';
        }

        $client = $this->getClient();

        if (!defined('SPARKPOST_INBOUND_DOMAIN')) {
            die('You must define a key SPARKPOST_INBOUND_DOMAIN');
        }

        // This is the domain that users will send email to.
        $result = $client->createInboundDomain(SPARKPOST_INBOUND_DOMAIN);

        $list = $client->listInboundDomains();

        echo '<pre>' . __FILE__ . ':' . __LINE__ . '<br/>';
        print_r($result);
        print_r($list);
        echo '</pre>';

        // Now that you have your InboundDomain set up, you can create your Relay Webhook by sending a POST request to
        // https://api.sparkpost.com/api/v1/relay-webhooks. This step links your consumer with the Inbound Domain.

        /*
         *  "name": "Replies Webhook",
         *  "target": "https://webhooks.customer.example/replies",
         * "auth_token": "5ebe2294ecd0e0f08eab7690d2a6ee69",
         *  "match": {
         *  "protocol": "SMTP",
         * "domain": "email.example.com"
         */

        //  The match.domain property should be the same as the Inbound Domain you set up in the previous step
        $webhookResult = $client->createRelayWebhook([
            'name' => 'Inbound Webhook',
            'target' => Director::absoluteURL('sparkpost/incoming'),
            'match' => [
                'domain' => SPARKPOST_INBOUND_DOMAIN
            ]
        ]);

        echo '<pre>' . __FILE__ . ':' . __LINE__ . '<br/>';
        print_r($webhookResult);
        echo '</pre>';
    }

    /**
     * Handle incoming webhook
     *
     * @link https://developers.sparkpost.com/api/#/reference/webhooks/create-a-webhook
     * @link https://www.sparkpost.com/blog/webhooks-beyond-the-basics/
     * @link https://support.sparkpost.com/customer/portal/articles/1976204-webhook-event-reference
     * @param SS_HTTPRequest $req
     */
    public function incoming(SS_HTTPRequest $req)
    {
        // Each webhook batch contains the header X-Messagesystems-Batch-Id,
        // which is useful for auditing and prevention of processing duplicate batches.
        $batchId = $req->getHeader('X-Messagesystems-Batch-Id');
        if (!$batchId) {
            $batchId = uniqid();
        }

        $json = file_get_contents('php://input');

        // By default, return a valid response
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->setBody('NO DATA');

        if (!$json) {
            return $response;
        }

        if (defined('SPARKPOST_WEBHOOK_LOG_DIR')) {
            $dir = rtrim(Director::baseFolder(), '/') . '/' . rtrim(SPARKPOST_WEBHOOK_LOG_DIR, '/');

            if (!is_dir($dir) && Director::isDev()) {
                mkdir($dir, 0755, true);
            }

            if (is_dir($dir)) {
                $payload['@headers'] = $req->getHeaders();
                $prettyPayload = json_encode(json_decode($json), JSON_PRETTY_PRINT);
                $time = date('Ymd-His');
                file_put_contents($dir . '/' . $time . '_' . $batchId . '.json', $prettyPayload);
            } else {
                SS_Log::log("Directory $dir does not exist", SS_Log::DEBUG);
            }
        }

        $payload = json_decode($json, JSON_OBJECT_AS_ARRAY);

        try {
            $this->processPayload($payload, $batchId);
        } catch (Exception $ex) {
            // Maybe processing payload will create exceptions, but we
            // catch them to send a proper response to the API
            $logLevel = self::config()->log_level ? self::config()->log_level : 7;
            SS_Log::log($ex->getMessage(), $logLevel);
        }

        $response->setBody('OK');

        return $response;
    }

    /**
     * Process data
     *
     * @param array $payload
     * @param string $batchId
     */
    protected function processPayload(array $payload, $batchId = null)
    {
        $this->extend('beforeProcessPayload', $payload, $batchId);

        $subaccount = SparkPostMailer::getInstance()->getClient()->getSubaccount();

        foreach ($payload as $r) {
            $ev = $r['msys'];

            $type = key($ev);
            $data = $ev[$type];

            // Ignore events not related to the subaccount we are managing
            if (!empty($data['subaccount_id']) && $subaccount && $subaccount != $data['subaccount_id']) {
                $this->skipCount++;
                continue;
            }

            $this->eventsCount++;
            $this->extend('onAnyEvent', $data, $type);

            switch ($type) {
                //Click, Open
                case SparkPostApiClient::TYPE_ENGAGEMENT:
                    $this->extend('onEngagementEvent', $data, $type);
                    break;
                //Generation Failure, Generation Rejection
                case SparkPostApiClient::TYPE_GENERATION:
                    $this->extend('onGenerationEvent', $data, $type);
                    break;
                //Bounce, Delivery, Injection, SMS Status, Spam Complaint, Out of Band, Policy Rejection, Delay
                case SparkPostApiClient::TYPE_MESSAGE:
                    $this->extend('onMessageEvent', $data, $type);
                    break;
                //Relay Injection, Relay Rejection, Relay Delivery, Relay Temporary Failure, Relay Permanent Failure
                case SparkPostApiClient::TYPE_RELAY:
                    $this->extend('onRelayEvent', $data, $type);
                    break;
                //List Unsubscribe, Link Unsubscribe
                case SparkPostApiClient::TYPE_UNSUBSCRIBE:
                    $this->extend('onUnsubscribeEvent', $data, $type);
                    break;
            }
        }

        $this->extend('afterProcessPayload', $payload, $batchId);
    }
}
