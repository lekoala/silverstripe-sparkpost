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
    ];

    /**
     * Handle incoming webhook
     *
     * @link https://www.sparkpost.com/blog/webhooks-beyond-the-basics/
     * @link https://support.sparkpost.com/customer/portal/articles/1976204-webhook-event-reference
     * @param SS_HTTPRequest $req
     */
    public function incoming(SS_HTTPRequest $req)
    {
        $json = file_get_contents('php://input');

        // By default, return a valid response
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->setBody('');

        if (!$json) {
            return $response;
        }

        $events = json_decode($json);

        $data = $events['msys'];

        foreach ($data as $ev) {
            $this->handleAnyEvent($ev);

            $event = $event->event;
            switch ($event) {
                // Relay type
                case self::EVENT_RELAY_DELIVERY:
                case self::EVENT_RELAY_INJECTION:
                case self::EVENT_RELAY_PERMFAIL:
                case self::EVENT_RELAY_REJECTION:
                case self::EVENT_RELAY_TEMPFAIL:
                    $this->handleRelayEvent($ev);
                    break;
                // Tracking
                case self::EVENT_CLICK:
                case self::EVENT_OPEN:
                case self::EVENT_DELIVERY:
                    $this->handleTrackingEvent($ev);
                    break;
            }
        }
        return $response;
    }

    protected function handleAnyEvent($e)
    {
        $this->extend('updateHandleAnyEvent', $e);
    }

    protected function handleRelayEvent($e)
    {
        $this->extend('updateHandleRelayEvent', $e);
    }

    protected function handleTrackingEvent($e)
    {
        $this->extend('updateHandleTrackingEvent', $e);
    }
}