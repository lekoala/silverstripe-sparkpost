<?php

/**
 * Test for SparkPost
 *
 * @group SparkPost
 */
class SparkPostTest extends SapphireTest
{

    protected $usesDatabase = true;

    public function testSetup()
    {
        $inst = SparkPostMailer::setAsMailer();
        $this->assertTrue($inst === Email::mailer());
    }

    public function testClient()
    {
        $client = SparkPostMailer::getInstance()->getClient();
        $result = $client->listAllSendingDomains();

        $this->assertTrue(is_array($result));
    }

    public function testSending()
    {
        if (!defined('SPARKPOST_TEST_TO') || !defined('SPARKPOST_TEST_FROM')) {
            $this->markTestIncomplete("You must define tests constants: SPARKPOST_TEST_TO, SPARKPOST_TEST_FROM");
        }

        $inst = SparkPostMailer::setAsMailer();

        $email = new Email();
        $email->setTo(SPARKPOST_TEST_TO);
        $email->setSubject('Test email');
        $email->setBody("Body of my email");
        $email->setFrom(SPARKPOST_TEST_FROM);
        $sent = $email->send();

        $this->assertTrue(!!$sent);
    }
}
