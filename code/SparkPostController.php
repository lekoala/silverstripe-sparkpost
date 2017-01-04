<?php

/**
 * Provide extensions points for handling the webhook
 *
 * @author LeKoala <thomas@lekoala.be>
 * @mixin InvoiceWebhookExtension
 */
class SparkPostController extends Controller
{

    private static $allowed_actions = [
        'incoming',
        'test',
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
        $payload = $client->getSampleEvents();

        $this->processPayload($payload, 'TEST');

        return 'TEST OK';
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
            $batchId = time();
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
                file_put_contents($dir . '/' . $batchId . '.json', $prettyPayload);
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

        $subaccount = defined('SPARKPOST_SUBACCOUNT_ID') ? SPARKPOST_SUBACCOUNT_ID : null;

        foreach ($payload as $r) {
            $ev = $r['msys'];

            $type = key($ev);
            $data = $ev[$type];

            // Ignore events not related to the subaccount we are managing
            if (!empty($data['subaccount_id']) && $subaccount && $subaccount != $data['subaccount_id']) {
                continue;
            }

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
