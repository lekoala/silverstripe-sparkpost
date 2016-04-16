<?php

/**
 * Test for SparkPost
 *
 * @group SparkPost
 */
class SparkPostTest extends SapphireTest
{

    public function setUp()
    {
        //nest config and injector for each test so they are effectively sandboxed per test
        Config::nest();
        Injector::nest();

        // We cannot run the tests on this abstract class.
        if (get_class($this) == "SapphireTest") $this->skipTest = true;

        if ($this->skipTest) {
            $this->markTestSkipped(sprintf(
                    'Skipping %s ', get_class($this)
            ));

            return;
        }

        // Mark test as being run
        $this->originalIsRunningTest = self::$is_running_test;
        self::$is_running_test       = true;

        // i18n needs to be set to the defaults or tests fail
        i18n::set_locale(i18n::default_locale());
        i18n::config()->date_format = null;
        i18n::config()->time_format = null;

        // Set default timezone consistently to avoid NZ-specific dependencies
        date_default_timezone_set('UTC');

        // Remove password validation
        $this->originalMemberPasswordValidator = Member::password_validator();
        $this->originalRequirements            = Requirements::backend();
        Member::set_password_validator(null);
        Config::inst()->update('Cookie', 'report_errors', false);

        if (class_exists('RootURLController')) RootURLController::reset();
        if (class_exists('Translatable')) Translatable::reset();
        Versioned::reset();
        DataObject::reset();
        if (class_exists('SiteTree')) SiteTree::reset();
        Hierarchy::reset();
        if (Controller::has_curr())
                Controller::curr()->setSession(Injector::inst()->create('Session',
                    array()));
        Security::$database_is_ready = null;

        // Add controller-name auto-routing
        Config::inst()->update('Director', 'rules',
            array(
            '$Controller//$Action/$ID/$OtherID' => '*'
        ));

        $fixtureFile = static::get_fixture_file();

        $prefix = defined('SS_DATABASE_PREFIX') ? SS_DATABASE_PREFIX : 'ss_';

        // Set up email
        $this->originalMailer = Email::mailer();
        $this->mailer         = new TestMailer();
        Injector::inst()->registerService($this->mailer, 'Mailer');
        Config::inst()->remove('Email', 'send_all_emails_to');

        // Todo: this could be a special test model
        $this->model = DataModel::inst();

        // Set up fixture
        if ($fixtureFile || $this->usesDatabase || !self::using_temp_db()) {
            if (substr(DB::get_conn()->getSelectedDatabase(), 0,
                    strlen($prefix) + 5) != strtolower(sprintf('%stmpdb',
                        $prefix))) {

                //echo "Re-creating temp database... ";
                self::create_temp_db();
                //echo "done.\n";
            }

            singleton('DataObject')->flushCache();

            self::empty_temp_db();

            foreach ($this->requireDefaultRecordsFrom as $className) {
                $instance = singleton($className);
                if (method_exists($instance, 'requireDefaultRecords'))
                        $instance->requireDefaultRecords();
                if (method_exists($instance, 'augmentDefaultRecords'))
                        $instance->augmentDefaultRecords();
            }

            if ($fixtureFile) {
                $pathForClass = $this->getCurrentAbsolutePath();
                $fixtureFiles = (is_array($fixtureFile)) ? $fixtureFile : array(
                    $fixtureFile);

                $i = 0;
                foreach ($fixtureFiles as $fixtureFilePath) {
                    // Support fixture paths relative to the test class, rather than relative to webroot
                    // String checking is faster than file_exists() calls.
                    $isRelativeToFile = (strpos('/', $fixtureFilePath) === false
                        || preg_match('/^\.\./', $fixtureFilePath));

                    if ($isRelativeToFile) {
                        $resolvedPath    = realpath($pathForClass.'/'.$fixtureFilePath);
                        if ($resolvedPath) $fixtureFilePath = $resolvedPath;
                    }

                    $fixture          = Injector::inst()->create('YamlFixture',
                        $fixtureFilePath);
                    $fixture->writeInto($this->getFixtureFactory());
                    $this->fixtures[] = $fixture;

                    // backwards compatibility: Load first fixture into $this->fixture
                    if ($i == 0) $this->fixture = $fixture;
                    $i++;
                }
            }

            $this->logInWithPermission("ADMIN");
        }

        // Preserve memory settings
        $this->originalMemoryLimit = ini_get('memory_limit');

        // turn off template debugging
        Config::inst()->update('SSViewer', 'source_file_comments', false);

        // Clear requirements
        Requirements::clear();
    }

    public static function create_temp_db()
    {
        //don't setup a database, it's just super slow
        return true;
    }

    public function testSetup()
    {
        $inst = SparkPostMailer::setAsMailer();
        $this->assertTrue($inst === Email::mailer());
    }

    public function testSending()
    {
        $inst = SparkPostMailer::setAsMailer();

        $email = new Email();
        $email->setTo(SPARKPOST_TEST_TO);
        $email->setSubject('Test email');
        $email->setBody("Body of my email");
        $email->setFrom(SPARKPOST_TEST_FROM);
        $sent  = $email->send();

        $this->assertTrue(!!$sent);
    }
}