<?php

namespace LeKoala\SparkPost\Test;

use LeKoala\Base\Email\EmailHelper;
use LeKoala\SparkPost\SparkPostApiTransport;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Email\Email;
use LeKoala\SparkPost\SparkPostHelper;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Test for SparkPost
 *
 * @group SparkPost
 */
class SparkPostTest extends SapphireTest
{
    /**
     * @var MailerInterface
     */
    protected $testMailer;
    protected bool $isDummy = false;

    protected function setUp(): void
    {
        parent::setUp();

        // add dummy api key
        if (!SparkPostHelper::getAPIKey()) {
            $this->isDummy = true;
            SparkPostHelper::config()->api_key = "dummy";
        }

        $this->testMailer = Injector::inst()->get(MailerInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Injector::inst()->registerService($this->testMailer, MailerInterface::class);
    }

    public function testSetup(): void
    {
        $inst = SparkPostHelper::registerTransport();
        $mailer = SparkPostHelper::getMailer();
        $instClass = get_class($inst);
        $instMailer = get_class($mailer);
        $this->assertEquals($instClass, $instMailer);
    }

    public function testClient(): void
    {
        $client = SparkPostHelper::getClient();

        if ($this->isDummy) {
            $this->assertTrue(true);
        } else {
            $result = $client->listAllSendingDomains();
            $this->assertTrue(is_array($result));
        }
    }

    public function testTLSVersion(): void
    {
        $ch = curl_init();
        // This fixes ca cert issues if server is not configured properly
        $cainfo = ini_get('curl.cainfo');
        if (is_string($cainfo) && strlen($cainfo) === 0) {
            curl_setopt($ch, CURLOPT_CAINFO, \Composer\CaBundle\CaBundle::getBundledCaBundlePath());
        }
        curl_setopt($ch, CURLOPT_URL, 'https://www.howsmyssl.com/a/check');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        if (!$data) {
            $this->markTestIncomplete('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
        }
        curl_close($ch);
        if (is_string($data)) {
            $json = json_decode($data);
            $this->assertNotEquals("TLS 1.0", $json->tls_version);
        }
    }

    public function testSendAllTo(): void
    {
        $sendAllTo = Environment::getEnv('SS_SEND_ALL_EMAILS_TO');

        $mailer = SparkPostHelper::registerTransport();

        $email = new Email();
        $email->setSubject('Test email');
        $email->setBody("Body of my email");
        $email->getHeaders()->addTextHeader('X-SendingDisabled', "true");
        $email->setTo("sendfrom@test.local");

        // This is async, therefore it does not return anything anymore
        $email->send();

        /** @var \LeKoala\SparkPost\SparkPostApiTransport $transport */
        $transport = SparkPostHelper::getTransportFromMailer($mailer);
        $result = $transport->getApiResult();

        // if we have a send all to, it should match
        $realRecipient = $sendAllTo ? $sendAllTo : "sendfrom@test.local";
        $this->assertEquals($realRecipient, $result["email"]);

        Environment::setEnv("SS_SEND_ALL_EMAILS_TO", "sendall@test.local");

        $email->send();
        $result = $transport->getApiResult();

        $this->assertEquals("sendall@test.local", $result["email"]);

        // reset env
        Environment::setEnv("SS_SEND_ALL_EMAILS_TO", $sendAllTo);
    }

    public function testSending(): void
    {
        $test_to = Environment::getEnv('SPARKPOST_TEST_TO');
        $test_from = Environment::getEnv('SPARKPOST_TEST_FROM');

        $mailer = SparkPostHelper::registerTransport();

        $email = new Email();
        $email->setSubject('Test email');
        $email->setBody("Body of my email");

        if (!$test_from || !$test_to || $this->isDummy) {
            $test_to = "example@localhost";
            $test_from =  "sender@localhost";
            // don't try to send it for real
            $email->getHeaders()->addTextHeader('X-SendingDisabled', "true");
        }
        $email->setTo($test_to);
        $email->setFrom($test_from);

        // This is async, therefore it does not return anything anymore
        $email->send();

        /** @var \LeKoala\SparkPost\SparkPostApiTransport $transport */
        $transport = SparkPostHelper::getTransportFromMailer($mailer);
        $result = $transport->getApiResult();

        $this->assertEquals(1, $result['total_accepted_recipients']);
    }

    public function testPayload(): void
    {
        $mailer = SparkPostHelper::registerTransport();
        /** @var \LeKoala\SparkPost\SparkPostApiTransport $transport */
        $transport = SparkPostHelper::getTransportFromMailer($mailer);


        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><style type="text/css">.red {color:red;}</style></head>
<body><span class="red">red</span></body>
</html>
HTML;
        $result = <<<HTML
<!DOCTYPE html>
<html>
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>
<body><span class="red" style="color: red;">red</span></body>
</html>

HTML;

        $sender = new Address('test@test.com', "testman");
        $recipients = [
            new Address('rec@test.com', "testrec"),
        ];
        $email = new Email();
        $email->setBody($html);
        $envelope = new Envelope($sender, $recipients);
        $payload = $transport->getPayload($email, $envelope);
        $content = $payload['content'];

        $payloadRecipients = $payload['recipients'][0];
        $this->assertEquals("testrec", $recipients[0]->getName());
        $this->assertEquals(
            [
                'address' => [
                    'email' => 'rec@test.com',
                    'name' => 'rec' // extracted from email due to how recipients work
                ]
            ],
            $payloadRecipients
        );
        $payloadSender = $content['from'];
        $this->assertEquals([
            'email' => 'test@test.com',
            'name' => 'testman'
        ], $payloadSender);

        // Make sure our styles are properly inlined
        $this->assertEquals('red', $content['text']);
        $this->assertEquals($result, $content['html']);
    }

    public function testMeta()
    {
        $meta = [
            'TestID' => 1,
            'SomeText' => 'test',
        ];
        $wrongMeta = [
            'WrongMeta' => true
        ];
        $tags = ['test-tag'];

        // Meta can be set using the mandrill compat layer
        $email = new Email();
        // meta is json encoded
        // https://mailchimp.com/developer/transactional/docs/smtp-integration/#x-mc-metadata
        $email->getHeaders()->addTextHeader('X-MC-Metadata', json_encode($meta));
        // tags are , delimited
        // https://mailchimp.com/developer/transactional/docs/smtp-integration/#x-mc-tags
        $email->getHeaders()->addTextHeader('X-MC-Tags', implode(',', $tags));
        $email->setTo('dummy@localhost');
        $client = SparkPostHelper::getClient();
        $api = new SparkPostApiTransport($client);

        $sender = $email->getSender()[0] ?? $email->getFrom()[0] ?? null;
        $recipients = $email->getTo();
        $envelope ??= new Envelope($sender, $recipients);
        $payload = $api->getPayload($email, $envelope);

        $metaFromRecipients = $payload['recipients'][0]['metadata'];
        $this->assertEquals($meta, $metaFromRecipients);
        $tagsFromRecipients = $payload['recipients'][0]['tags'];
        $this->assertEquals($tags, $tagsFromRecipients);

        // Or using the smtp api
        // https://developers.sparkpost.com/api/smtp/#header-using-the-x-msys-api-custom-header
        $email = new Email();
        $msys = [
            'metadata' => $meta,
            // Tags are available in click/open events. Maximum number of tags is 10 per recipient, 100 system wide.
            'tags' => $tags
        ];
        // It's one big json blob
        $email->getHeaders()->addTextHeader('X-MSYS-API', json_encode($msys));
        $email->setTo('dummy@localhost');
        $client = SparkPostHelper::getClient();
        $api = new SparkPostApiTransport($client);

        $sender = $email->getSender()[0] ?? $email->getFrom()[0] ?? null;
        $recipients = $email->getTo();
        $envelope ??= new Envelope($sender, $recipients);
        $payload = $api->getPayload($email, $envelope);

        $metaFromRecipients = $payload['recipients'][0]['metadata'];
        $this->assertEquals($meta, $metaFromRecipients);
        $tagsFromRecipients = $payload['recipients'][0]['tags'];
        $this->assertEquals($tags, $tagsFromRecipients);

        // Beware of settings headers multiple times

        $email = new Email();
        $email->getHeaders()->addTextHeader('X-MC-Metadata', json_encode($wrongMeta));
        $email->getHeaders()->addTextHeader('X-MC-Metadata', json_encode($meta));
        $email->setTo('dummy@localhost');
        $client = SparkPostHelper::getClient();
        $api = new SparkPostApiTransport($client);

        $stringHeaders = $email->getHeaders()->toString();
        // It's not a unique header, it appears twice
        $this->assertEquals(2, substr_count($stringHeaders, 'X-MC-Metadata'));

        $sender = $email->getSender()[0] ?? $email->getFrom()[0] ?? null;
        $recipients = $email->getTo();
        $envelope ??= new Envelope($sender, $recipients);
        $payload = $api->getPayload($email, $envelope);

        $metaFromRecipients = $payload['recipients'][0]['metadata'];
        $this->assertEquals($wrongMeta, $metaFromRecipients);
        $this->assertNotEquals($meta, $metaFromRecipients);

        // Instead, use SparkPostHelper::addMetaData that can merge or replace headers

        $email = new Email();

        $mergedMeta = array_merge($wrongMeta, $meta);
        SparkPostHelper::addMetaData($email, $wrongMeta);
        SparkPostHelper::addMetaData($email, $meta);
        $email->setTo('dummy@localhost');
        $client = SparkPostHelper::getClient();
        $api = new SparkPostApiTransport($client);

        $stringHeaders = $email->getHeaders()->toString();
        // Only once!
        $this->assertEquals(1, substr_count($stringHeaders, 'X-MC-Metadata'));

        $sender = $email->getSender()[0] ?? $email->getFrom()[0] ?? null;
        $recipients = $email->getTo();
        $envelope ??= new Envelope($sender, $recipients);
        $payload = $api->getPayload($email, $envelope);

        $metaFromRecipients = $payload['recipients'][0]['metadata'];
        $this->assertEquals($mergedMeta, $metaFromRecipients);

        SparkPostHelper::addMetaData($email, $wrongMeta, true);
        SparkPostHelper::addMetaData($email, $meta, true);
        $email->setTo('dummy@localhost');
        $client = SparkPostHelper::getClient();
        $api = new SparkPostApiTransport($client);

        $stringHeaders = $email->getHeaders()->toString();
        // Only once!
        $this->assertEquals(1, substr_count($stringHeaders, 'X-MC-Metadata'));

        $sender = $email->getSender()[0] ?? $email->getFrom()[0] ?? null;
        $recipients = $email->getTo();
        $envelope ??= new Envelope($sender, $recipients);
        $payload = $api->getPayload($email, $envelope);

        $metaFromRecipients = $payload['recipients'][0]['metadata'];
        $this->assertEquals($meta, $metaFromRecipients);
    }
}
