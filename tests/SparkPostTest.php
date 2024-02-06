<?php

namespace LeKoala\SparkPost\Test;

use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Email\Email;
use LeKoala\SparkPost\SparkPostHelper;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Mailer\MailerInterface;

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
}
