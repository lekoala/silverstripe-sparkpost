<?php

namespace LeKoala\SparkPost;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;
use SilverStripe\Control\HTTPResponse;
use LeKoala\SparkPost\Api\SparkPostApiClient;
use SilverStripe\Control\HTTP;
use SilverStripe\ORM\ArrayList;

/**
 * Provide extensions points for handling the webhook
 *
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostController extends Controller
{
    private static $url_segment = '__sparkpost';

    /**
     * @var int
     */
    protected $eventsCount = 0;

    /**
     * @var int
     */
    protected $skipCount = 0;

    /**
     * @var array<string>
     */
    private static $allowed_actions = [
        'incoming',
        'test',
        'configure_inbound_emails',
        'sent_emails',
    ];

    /**
     * Inject public dependencies into the controller
     *
     * @var array<string,string>
     */
    private static $dependencies = [
        'logger' => '%$Psr\Log\LoggerInterface',
    ];

    /**
     * @var \Psr\Log\LoggerInterface
     */
    public $logger;

    /**
     * @param HTTPRequest $req
     * @return HTTPResponse|string
     */
    public function index(HTTPRequest $req)
    {
        return $this->render([
            'Title' => 'SparkPost',
            'Content' => 'Please use a dedicated action'
        ]);
    }

    public function sent_emails(HTTPRequest $req)
    {
        if (!Director::isDev() && !Permission::check('ADMIN')) {
            return $this->httpError(404);
        }

        $logFolder = SparkPostHelper::getLogFolder();
        $view = $req->getVar('view');
        $download = $req->getVar('download');
        $iframe = $req->getVar('iframe');
        $base = Director::baseFolder();

        if ($download) {
            $file = $logFolder . '/' . $download;
            if (!is_file($file) || dirname($file) != $logFolder) {
                return $this->httpError(404);
            }

            $fileData = file_get_contents($file);
            $fileName = $download;
            return HTTPRequest::send_file($fileData, $fileName);
        }

        if ($iframe) {
            $file = $logFolder . '/' . $view;
            if (!is_file($file) || dirname($file) != $logFolder) {
                return $this->httpError(404);
            }
            $content = file_get_contents($file);
            return $content;
        }

        if ($view) {
            $file = $logFolder . '/' . $view;
            if (!is_file($file) || dirname($file) != $logFolder) {
                return $this->httpError(404);
            }

            $content = file_get_contents($file);
            $content = str_replace($logFolder . '/', '/__sparkpost/sent_emails?download=', $content);
            $content = str_replace($base, '', $content);

            $customFields = [
                'Email' => $content,
                'Name' => $view,
            ];
        } else {
            $emails = new ArrayList();
            $items = glob("$logFolder/*.html", GLOB_NOSORT);
            usort($items, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            foreach ($items as $email) {
                $emails->push([
                    'File' => $email,
                    'Name' => basename($email),
                    'Date' => date('Y-m-d H:i:s', filemtime($email)),
                ]);
            }

            $customFields = [
                'Emails' => $emails
            ];
        }

        return $this->renderWith('LeKoala/SparkPost/SparkPostController_sent_emails', $customFields);
    }

    /**
     * You can also see /resources/sample.json
     *
     * @param HTTPRequest $req
     * @return string
     */
    public function test(HTTPRequest $req)
    {
        if (!Director::isDev()) {
            return $this->httpError(404);
        }

        $file = $this->getRequest()->getVar('file');
        if ($file) {
            $data = file_get_contents(Director::baseFolder() . '/' . rtrim($file, '/'));
        } else {
            $data = file_get_contents(dirname(__DIR__) . '/resources/sample.json');
        }
        if (!$data) {
            throw new Exception("Failed to get data");
        }
        $payload = json_decode($data, true);
        $payload['@headers'] = $req->getHeaders();

        $this->processPayload($payload, 'TEST');

        return 'TEST OK - ' . $this->eventsCount . ' events processed / ' . $this->skipCount . ' events skipped';
    }

    /**
     * @link https://support.sparkpost.com/customer/portal/articles/2039614-enabling-inbound-email-relaying-relay-webhooks
     * @param HTTPRequest $req
     * @return string
     */
    public function configure_inbound_emails(HTTPRequest $req)
    {
        if (!Director::isDev() && !Permission::check('ADMIN')) {
            return 'You must be in dev mode or be logged as an admin';
        }

        $clearExisting = $req->getVar('clear_existing');
        $clearWebhooks = $req->getVar('clear_webhooks');
        $clearInbound = $req->getVar('clear_inbound');
        if ($clearExisting) {
            echo '<strong>Existing inbounddomains and relay webhooks will be cleared</strong><br/>';
        } else {
            echo 'You can clear existing inbound domains and relay webhooks by passing ?clear_existing=1&clear_webhooks=1&clear_inbound=1<br/>';
        }

        $client = SparkPostHelper::getMasterClient();

        $inbound_domain = Environment::getEnv('SPARKPOST_INBOUND_DOMAIN');
        if (!$inbound_domain) {
            die('You must define a key SPARKPOST_INBOUND_DOMAIN');
        }

        /*
         *  "name": "Replies Webhook",
         *  "target": "https://webhooks.customer.example/replies",
         * "auth_token": "5ebe2294ecd0e0f08eab7690d2a6ee69",
         *  "match": {
         *  "protocol": "SMTP",
         * "domain": "email.example.com"
         */

        $listWebhooks = $client->listRelayWebhooks();
        $listInboundDomains = $client->listInboundDomains();

        if ($clearExisting) {
            // we need to delete relay webhooks first!
            if ($clearWebhooks) {
                foreach ($listWebhooks as $wh) {
                    $client->deleteRelayWebhook($wh['id']);
                    echo 'Delete relay webhook ' . $wh['id'] . '<br/>';
                }
            }
            if ($clearInbound) {
                foreach ($listInboundDomains as $id) {
                    $client->deleteInboundDomain($id['domain']);
                    echo 'Delete domain ' . $id['domain'] . '<br/>';
                }
            }

            $listWebhooks = $client->listRelayWebhooks();
            $listInboundDomains = $client->listInboundDomains();
        }

        echo '<pre>' . __FILE__ . ':' . __LINE__ . '<br/>';
        echo 'List Inbounds Domains:<br/>';
        print_r($listInboundDomains);
        echo '</pre>';

        $found = false;

        foreach ($listInboundDomains as $id) {
            if ($id['domain'] == $inbound_domain) {
                $found = true;
            }
        }

        if (!$found) {
            echo "Domain is not found, we create it<br/>";

            // This is the domain that users will send email to.
            $result = $client->createInboundDomain($inbound_domain);

            echo '<pre>' . __FILE__ . ':' . __LINE__ . '<br/>';
            echo 'Create Inbound Domain:<br/>';
            print_r($result);
            echo '</pre>';
        } else {
            echo "Domain is already configured<br/>";
        }

        // Now that you have your InboundDomain set up, you can create your Relay Webhook by sending a POST request to
        // https://api.sparkpost.com/api/v1/relay-webhooks. This step links your consumer with the Inbound Domain.

        echo '<pre>' . __FILE__ . ':' . __LINE__ . '<br/>';
        echo 'List Webhooks:<br/>';
        print_r($listWebhooks);
        echo '</pre>';

        $found = false;

        foreach ($listWebhooks as $wh) {
            if ($wh['match']['domain'] == $inbound_domain) {
                $found = true;
            }
        }

        if (!$found) {
            //  The match.domain property should be the same as the Inbound Domain you set up in the previous step
            $webhookResult = $client->createRelayWebhook([
                'name' => 'Inbound Webhook',
                'target' => Director::absoluteURL('sparkpost/incoming'),
                'match' => [
                    'domain' => $inbound_domain
                ]
            ]);

            echo '<pre>' . __FILE__ . ':' . __LINE__ . '<br/>';
            echo 'Webhook result:<br/>';
            print_r($webhookResult);
            echo '</pre>';

            if ($webhookResult['id']) {
                echo "New webhook created with id " . $webhookResult['id'];
            }
        } else {
            echo "Webhook already configured";
        }
        return '';
    }

    /**
     * Handle incoming webhook
     *
     * @link https://developers.sparkpost.com/api/#/reference/webhooks/create-a-webhook
     * @link https://www.sparkpost.com/blog/webhooks-beyond-the-basics/
     * @link https://support.sparkpost.com/customer/portal/articles/1976204-webhook-event-reference
     * @param HTTPRequest $req
     * @return HTTPResponse
     */
    public function incoming(HTTPRequest $req)
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

        $payload = json_decode($json, true);

        // Check credentials if defined
        $isAuthenticated = true;
        $authError = null;
        if (SparkPostHelper::getWebhookUsername()) {
            try {
                $this->authRequest($req);
            } catch (Exception $e) {
                $isAuthenticated = false;
                $authError = $e->getMessage();
            }
        }

        $webhookLogDir = Environment::getEnv('SPARKPOST_WEBHOOK_LOG_DIR');
        if ($webhookLogDir) {
            $dir = rtrim(Director::baseFolder(), '/') . '/' . rtrim($webhookLogDir, '/');

            if (!is_dir($dir) && Director::isDev()) {
                mkdir($dir, 0755, true);
            }

            if (is_dir($dir)) {
                $storedPayload = array_merge([], $payload);
                $storedPayload['@headers'] = $req->getHeaders();
                $storedPayload['@isAuthenticated'] = $isAuthenticated;
                $storedPayload['@authError'] = $authError;
                $prettyPayload = json_encode($storedPayload, JSON_PRETTY_PRINT);
                $time = date('Ymd-His');
                file_put_contents($dir . '/' . $time . '_' . $batchId . '.json', $prettyPayload);
            } else {
                $this->getLogger()->debug("Directory $dir does not exist");
            }
        }

        if (!$isAuthenticated) {
            return $response;
        }

        try {
            $this->processPayload($payload, $batchId);
        } catch (Exception $ex) {
            // Maybe processing payload will create exceptions, but we
            // catch them to send a proper response to the API
            $logLevel = self::config()->log_level ? self::config()->log_level : 7;
            $this->getLogger()->log($ex->getMessage(), $logLevel);
        }

        $response->setBody('OK');
        return $response;
    }

    /**
     * @param HTTPRequest $req
     * @return void
     */
    protected function authRequest(HTTPRequest $req)
    {
        $requestUser = $req->getHeader('php_auth_user');
        $requestPassword = $req->getHeader('php_auth_pw');
        if (!$requestUser) {
            $requestUser = $_SERVER['PHP_AUTH_USER'] ?? null;
        }
        if (!$requestPassword) {
            $requestPassword = $_SERVER['PHP_AUTH_PW'] ?? null;
        }

        $authError = null;
        $hasSuppliedCredentials = $requestUser && $requestPassword;
        if ($hasSuppliedCredentials) {
            $user = SparkPostHelper::getWebhookUsername();
            $password = SparkPostHelper::getWebhookPassword();
            if ($user != $requestUser) {
                $authError = "User $requestUser doesn't match";
            } elseif ($password != $requestPassword) {
                $authError = "Password $requestPassword don't match";
            }
        } else {
            $authError = "No credentials";
        }
        if ($authError) {
            throw new Exception($authError);
        }
    }

    /**
     * Process data
     *
     * @param array<mixed> $payload
     * @param string $batchId
     * @return void
     */
    protected function processPayload(array $payload, $batchId = null)
    {
        $this->extend('beforeProcessPayload', $payload, $batchId);

        $subaccount = SparkPostHelper::getClient()->getSubaccount();

        foreach ($payload as $idx => $r) {
            // This is a test payload
            if (empty($r)) {
                continue;
            }
            // This is a custom entry
            if (!is_numeric($idx)) {
                continue;
            }

            $ev = $r['msys'] ?? null;

            // Invalid payload: it should always be an object with a msys key containing the event
            if ($ev === null) {
                $this->getLogger()->warning("Invalid payload: " . substr((string)json_encode($r), 0, 100) . '...');
                continue;
            }

            // Check type: it should be an object with the type as key
            $type = key($ev);
            if (!isset($ev[$type])) {
                $this->getLogger()->warning("Invalid type $type in SparkPost payload");
                continue;
            }
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


    /**
     * Get logger
     *
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }
}
