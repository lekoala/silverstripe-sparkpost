<?php
namespace LeKoala\SparkPost\Test;

use SilverStripe\Dev\TestMailer;
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

    protected function setUp()
    {
        parent::setUp();

        $this->testMailer = Injector::inst()->get(Mailer::class);

        // Ensure we have the right mailer
        $mailer =  new SwiftMailer();
        $swiftMailer = new \Swift_Mailer(new \Swift_MailTransport());
        $mailer->setSwiftMailer($swiftMailer);
        Injector::inst()->registerService($mailer, Mailer::class);

    }
    protected function tearDown()
    {
        parent::tearDown();

        Injector::inst()->registerService($this->testMailer, Mailer::class);
    }

    public function testSetup()
    {
        $inst = SparkPostHelper::registerTransport();
        $mailer = SparkPostHelper::getMailer();
        $this->assertTrue($inst === $mailer);
    }

    public function testClient()
    {
        $client = SparkPostHelper::getClient();
        $result = $client->listAllSendingDomains();

        $this->assertTrue(is_array($result));
    }

    public function testSending()
    {
        $test_to = Environment::getEnv('SPARKPOST_TEST_TO');
        $test_from = Environment::getEnv('SPARKPOST_TEST_FROM');
        if (!$test_from || !$test_to) {
            $this->markTestIncomplete("You must define tests environement variable: SPARKPOST_TEST_TO, SPARKPOST_TEST_FROM");
        }

        $inst = SparkPostHelper::registerTransport();

        $email = new Email();
        $email->setTo($test_to);
        $email->setSubject('Test email');
        $email->setBody("Body of my email");
        $email->setFrom($test_from);
        $sent = $email->send();

        $this->assertTrue(!!$sent);
    }
}
