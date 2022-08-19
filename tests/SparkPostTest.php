<?php

namespace LeKoala\SparkPost\Test;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Email\Email;
use LeKoala\SparkPost\SparkPostHelper;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\SwiftMailer;

/**
 * Test for SparkPost
 *
 * @group SparkPost
 */
class SparkPostTest extends SapphireTest
{
    protected $testMailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testMailer = Injector::inst()->get(Mailer::class);

        // Ensure we have the right mailer
        $mailer = new SwiftMailer();
        $swiftMailer = new \Swift_Mailer(new \Swift_MailTransport());
        $mailer->setSwiftMailer($swiftMailer);
        Injector::inst()->registerService($mailer, Mailer::class);
    }
    protected function tearDown(): void
    {
        parent::tearDown();

        Injector::inst()->registerService($this->testMailer, Mailer::class);
    }

    public function testSetup()
    {
        if (!SparkPostHelper::getApiKey()) {
            return $this->markTestIncomplete("No api key set for test");
        }

        $inst = SparkPostHelper::registerTransport();
        $mailer = SparkPostHelper::getMailer();
        $this->assertTrue($inst === $mailer);
    }

    public function testClient()
    {
        if (!SparkPostHelper::getApiKey()) {
            return $this->markTestIncomplete("No api key set for test");
        }

        $client = SparkPostHelper::getClient();
        $result = $client->listAllSendingDomains();

        $this->assertTrue(is_array($result));
    }

    public function testTLSVersion()
    {
        $ch = curl_init();
        // This fixes ca cert issues if server is not configured properly
        if (strlen(ini_get('curl.cainfo')) === 0) {
            curl_setopt($ch, CURLOPT_CAINFO, \Composer\CaBundle\CaBundle::getBundledCaBundlePath());
        }
        curl_setopt($ch, CURLOPT_URL, 'https://www.howsmyssl.com/a/check');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        if (!$data) {
            $this->markTestIncomplete('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
            return;
        }
        curl_close($ch);
        $json = json_decode($data);
        $this->assertNotEquals("TLS 1.0", $json->tls_version);
    }

    public function testSending()
    {
        if (!SparkPostHelper::getApiKey()) {
            return $this->markTestIncomplete("No api key set for test");
        }

        $inst = SparkPostHelper::registerTransport();

        $email = new Email();
        $email->setTo("example@localhost");
        $email->setSubject('Test email');
        $email->setBody("Body of my email");
        $email->setFrom("sender@localhost");
        $email->getSwiftMessage()->getHeaders()->addTextHeader('X-Sending-Disabled', true);
        $sent = $email->send();

        $this->assertTrue(!!$sent);
    }
}
