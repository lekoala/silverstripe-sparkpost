<?php

/**
 * Provide extensions points for handling the webhook
 *
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostController extends Controller
{
    //
    const TYPE_MESSAGE           = 'message_event';
    const TYPE_ENGAGEMENT        = 'track_event';
    const TYPE_GENERATION        = 'gen_event';
    const TYPE_UNSUBSCRIBE       = 'unsubscribe_event';
    const TYPE_RELAY             = 'relay_event';
    //
    const EVENT_DELIVERY         = 'delivery';
    const EVENT_BOUNCE           = 'bounce';
    const EVENT_INJECTION        = 'injection';
    const EVENT_SMS_STATUS       = 'sms_status';
    const EVENT_SPAM_COMPLAINT   = 'spam_complaint';
    const EVENT_OUT_OF_BAND      = 'out_of_band';
    const EVENT_POLICY_REJECTION = 'policy_rejection';
    const EVENT_DELAY            = 'delay';
    const EVENT_OPEN             = 'open';
    const EVENT_CLICK            = 'click';
    const EVENT_GEN_FAILURE      = 'generation_failure';
    const EVENT_GEN_REJECTION    = 'generation_rejection';
    const EVENT_LIST_UNSUB       = 'list_unsubscribe';
    const EVENT_LINK_UNSUB       = 'link_unsubscribe';
    const EVENT_RELAY_INJECTION  = 'relay_injection';
    const EVENT_RELAY_REJECTION  = 'relay_rejection';
    const EVENT_RELAY_DELIVERY   = 'relay_delivery';
    const EVENT_RELAY_TEMPFAIL   = 'relay_tempfail';
    const EVENT_RELAY_PERMFAIL   = 'relay_permfail';

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
     * @param SS_HTTPRequest $req
     */
    public function test(SS_HTTPRequest $req)
    {
        if (!Director::isDev()) {
            return 'You can only test in dev mode';
        }

        $client  = $this->getClient();
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
        // Each webhook batch contains the header X-MessageSystems-Batch-ID,
        // which is useful for auditing and prevention of processing duplicate batches.
        $batchId = $req->getHeader('X-MessageSystems-Batch-ID');

        $json = file_get_contents('php://input');

        // By default, return a valid response
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->setBody('NO DATA');

        if (!$json) {
            return $response;
        }

        $payload = json_decode($json, JSON_OBJECT_AS_ARRAY);

        try {
            $this->processPayload($payload, $batchId);
        } catch (Exception $ex) {
            // Maybe processing payload will create exceptions, but we
            // catch them to send a proper response to the API
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

        foreach ($payload as $r) {
            $ev = $r['msys'];

            $type = key($ev);
            $data = $ev[$type];

            $this->extend('onAnyEvent', $ev);

            switch ($type) {
                case self::TYPE_ENGAGEMENT:
                    $this->extend('onEngagementEvent', $ev);
                    break;
                case self::TYPE_GENERATION:
                    $this->extend('onGenerationEvent', $ev);
                    break;
                case self::TYPE_MESSAGE:
                    $this->extend('onMessageEvent', $ev);
                    break;
                case self::TYPE_RELAY:
                    $this->extend('onRelayEvent', $ev);
                    break;
                case self::TYPE_UNSUBSCRIBE:
                    $this->extend('onUnsubscribeEvent', $ev);
                    break;
            }
        }

        $this->extend('afterProcessPayload', $payload, $batchId);
    }
}