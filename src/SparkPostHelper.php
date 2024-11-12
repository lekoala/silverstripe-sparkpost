<?php

namespace LeKoala\SparkPost;

use Exception;
use ReflectionObject;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Config\Config;
use Symfony\Component\Mailer\Mailer;
use SilverStripe\Control\Email\Email;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Config\Configurable;
use LeKoala\SparkPost\Api\SparkPostApiClient;
use Symfony\Component\Mailer\MailerInterface;
use SilverStripe\Core\Injector\InjectorNotFoundException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * This configurable class helps decoupling the api client from SilverStripe
 */
class SparkPostHelper
{
    use Configurable;

    const FROM_SITECONFIG = "SiteConfig";
    const FROM_ADMIN = "Admin";
    const FROM_DEFAULT = "Default";

    /**
     * Client instance
     *
     * @var ?\LeKoala\SparkPost\Api\SparkPostApiClient
     */
    protected static $client;

    /**
     * Get the mailer instance
     *
     * @return MailerInterface
     */
    public static function getMailer()
    {
        return Injector::inst()->get(MailerInterface::class);
    }

    /**
     * @param MailerInterface $mailer
     * @return \Symfony\Component\Mailer\Transport\AbstractTransport|SparkPostApiTransport
     */
    public static function getTransportFromMailer($mailer)
    {
        $r = new ReflectionObject($mailer);
        $p = $r->getProperty('transport');
        $p->setAccessible(true);
        return $p->getValue($mailer);
    }

    /**
     * @return string
     */
    public static function getApiKey()
    {
        return self::config()->api_key;
    }

    /**
     * Get the api client instance
     * @return SparkPostApiClient
     * @throws Exception
     */
    public static function getClient()
    {
        if (!self::$client) {
            $key = self::getApiKey();
            if (empty($key)) {
                throw new \Exception("api_key is not configured for " . __class__);
            }
            self::$client = new SparkPostApiClient($key);
            if (Director::isDev()) {
                //@phpstan-ignore-next-line
                self::$client->setCurlOption(CURLOPT_VERBOSE, true);
            }
            if (Environment::getEnv("SPARKPOST_EU")) {
                self::$client->setEuEndpoint(true);
            }
            $subaccountId = self::config()->subaccount_id;
            if ($subaccountId) {
                self::$client->setSubaccount($subaccountId);
            }
        }
        return self::$client;
    }

    /**
     * Get the api client instance
     * @return \LeKoala\SparkPost\Api\SparkPostApiClient
     * @throws Exception
     */
    public static function getMasterClient()
    {
        $masterKey = self::config()->master_api_key;
        if (!$masterKey) {
            return self::getClient();
        }
        $client = new SparkPostApiClient($masterKey);
        return $client;
    }

    /**
     * Get the log folder and create it if necessary
     *
     * @return string
     */
    public static function getLogFolder()
    {
        $logFolder = BASE_PATH . '/' . self::config()->log_folder;
        if (!is_dir($logFolder)) {
            mkdir($logFolder, 0755, true);
        }
        return $logFolder;
    }


    /**
     * Process environment variable to configure this module
     *
     * @return void
     */
    public static function init()
    {
        // Regular api key used for sending emails (including subaccount support)
        $api_key = self::getEnvApiKey();
        if ($api_key) {
            self::config()->api_key = $api_key;
        }

        // Master api key that is used to configure the account. If no api key is defined, the master api key is used
        $master_api_key = self::getEnvMasterApiKey();
        if ($master_api_key) {
            self::config()->master_api_key = $master_api_key;
            if (!self::config()->api_key) {
                self::config()->api_key = $master_api_key;
            }
        }

        $sending_disabled = self::getEnvSendingDisabled();
        if ($sending_disabled === false) {
            // In dev, if we didn't set a value, disable by default
            // This can avoid sending emails by mistake :-) oops!
            if (Director::isDev() && !self::hasEnvSendingDisabled()) {
                $sending_disabled = true;
            }
        }
        if ($sending_disabled) {
            self::config()->disable_sending = $sending_disabled;
        }
        $enable_logging = self::getEnvEnableLogging();
        if ($enable_logging) {
            self::config()->enable_logging = $enable_logging;
        }
        $subaccount_id = self::getEnvSubaccountId();
        if ($subaccount_id) {
            self::config()->subaccount_id = $subaccount_id;
        }

        // We have a key, we can register the transport
        if (self::config()->api_key) {
            self::registerTransport();
        }
    }

    /**
     * @return mixed
     */
    public static function getEnvApiKey()
    {
        return Environment::getEnv('SPARKPOST_API_KEY');
    }

    /**
     * @return mixed
     */
    public static function getEnvMasterApiKey()
    {
        return Environment::getEnv('SPARKPOST_MASTER_API_KEY');
    }

    /**
     * @return mixed
     */
    public static function getEnvSendingDisabled()
    {
        return Environment::getEnv('SPARKPOST_SENDING_DISABLED');
    }

    /**
     * @return bool
     */
    public static function hasEnvSendingDisabled()
    {
        return Environment::hasEnv('SPARKPOST_SENDING_DISABLED');
    }

    /**
     * @return mixed
     */
    public static function getEnvEnableLogging()
    {
        return  Environment::getEnv('SPARKPOST_ENABLE_LOGGING');
    }

    /**
     * @return mixed
     */
    public static function getEnvSubaccountId()
    {
        return  Environment::getEnv('SPARKPOST_SUBACCOUNT_ID');
    }

    /**
     * @return mixed
     */
    public static function getSubaccountId()
    {
        return self::config()->subaccount_id;
    }

    /**
     * @return mixed
     */
    public static function getEnvForceSender()
    {
        return Environment::getEnv('SPARKPOST_FORCE_SENDER');
    }

    /**
     * @return mixed
     */
    public static function getWebhookUsername()
    {
        return self::config()->webhook_username;
    }

    /**
     * @return mixed
     */
    public static function getWebhookPassword()
    {
        return self::config()->webhook_password;
    }

    /**
     * Register the transport with the client
     *
     * @return Mailer The updated mailer
     * @throws Exception
     */
    public static function registerTransport()
    {
        $client = self::getClient();
        // Make sure MailerSubscriber is registered
        try {
            $dispatcher = Injector::inst()->get(EventDispatcherInterface::class . '.mailer');
        } catch (Exception $e) {
            // It may not be set
            $dispatcher = null;
        }
        $transport = new SparkPostApiTransport($client, null, $dispatcher);
        $mailer = new Mailer($transport);
        Injector::inst()->registerService($mailer, MailerInterface::class);
        return $mailer;
    }

    /**
     * Update admin email so that we use our config email
     *
     * @return void
     */
    public static function forceAdminEmailOverride()
    {
        Config::modify()->set(Email::class, 'admin_email', self::resolveDefaultFromEmailType());
    }

    /**
     * @param string $email
     * @return bool
     */
    public static function isEmailSuppressed($email)
    {
        $client = self::getClient();

        $state = $client->getSuppression($email);
        if (empty($state)) {
            return false;
        }
        return true;
    }

    /**
     * @param string $email
     * @return void
     */
    public static function removeSuppression($email)
    {
        self::getClient()->deleteSuppression($email);
    }

    /**
     * Check if email is ready to send emails
     *
     * @param string $email
     * @return boolean
     */
    public static function isEmailDomainReady($email)
    {
        if (!$email) {
            return false;
        }
        $email = EmailUtils::get_email_from_rfc_email($email);
        $parts = explode("@", $email);
        if (count($parts) != 2) {
            return false;
        }
        $client = SparkPostHelper::getClient();
        try {
            $domain = $client->getSendingDomain(strtolower($parts[1]));
        } catch (Exception $ex) {
            return false;
        }
        if (!$domain) {
            return false;
        }
        if ($domain['status']['dkim_status'] != 'valid') {
            return false;
        }
        if ($domain['status']['compliance_status'] != 'valid') {
            return false;
        }
        if ($domain['status']['ownership_verified'] != true) {
            return false;
        }
        return true;
    }

    /**
     * Resolve default send from address
     *
     * Keep in mind that an email using send() without a from
     * will inject the admin_email. Therefore, SiteConfig
     * will not be used
     * See forceAdminEmailOverride() or use override_admin_email config
     *
     * @param string $from
     * @param bool $createDefault
     * @return string|array<string,string>|false
     */
    public static function resolveDefaultFromEmail($from = null, $createDefault = true)
    {
        $configEmail = self::getSenderFromSiteConfig();
        $original_from = $from;
        if (!empty($from)) {
            // We have a set email but sending from admin => override if flag is set
            if (self::isAdminEmail($from) && $configEmail && self::config()->override_admin_email) {
                return $configEmail;
            }
            // If we have a sender, validate its email
            $from = EmailUtils::get_email_from_rfc_email($from);
            if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
                return $original_from;
            }
        }
        // Look in siteconfig for default sender
        if ($configEmail) {
            return $configEmail;
        }
        // Use admin email if set
        if ($adminEmail = Email::config()->admin_email) {
            if (is_array($adminEmail) && count($adminEmail) > 0) {
                $email = array_keys($adminEmail)[0];
                return [$email => $adminEmail[$email]];
            } elseif (is_string($adminEmail)) {
                return $adminEmail;
            }
        }
        // If we still don't have anything, create something based on the domain
        if ($createDefault) {
            return self::createDefaultEmail();
        }
        return false;
    }

    /**
     * Returns what type of default email is used
     *
     * @return string
     */
    public static function resolveDefaultFromEmailType()
    {
        // Look in siteconfig for default sender
        if (self::getSenderFromSiteConfig()) {
            return self::FROM_SITECONFIG;
        }
        // Is admin email set ?
        if (Email::config()->admin_email) {
            return self::FROM_ADMIN;
        }
        return self::FROM_DEFAULT;
    }

    /**
     * @return string|false
     */
    public static function getSenderFromSiteConfig()
    {
        $config = SiteConfig::current_site_config();
        $config_field = self::config()->siteconfig_from;
        if ($config_field && !empty($config->$config_field)) {
            return $config->$config_field;
        }
        return false;
    }

    /**
     * @param string $email
     * @return boolean
     */
    public static function isAdminEmail($email)
    {
        $admin_email = Email::config()->admin_email;
        if (!$admin_email && $email) {
            return false;
        }
        $rfc_email = EmailUtils::get_email_from_rfc_email($email);
        $rfc_admin_email = EmailUtils::get_email_from_rfc_email($admin_email);
        return $rfc_email == $rfc_admin_email;
    }

    /**
     * @param string $email
     * @return boolean
     */
    public static function isDefaultEmail($email)
    {
        $rfc_email = EmailUtils::get_email_from_rfc_email($email);
        return $rfc_email == self::createDefaultEmail();
    }

    /**
     * Resolve default send to address
     *
     * @param string|array<mixed>|null $to
     * @return string|array<mixed>|null
     */
    public static function resolveDefaultToEmail($to = null)
    {
        // In case of multiple recipients, do not validate anything
        if (is_array($to) || strpos($to, ',') !== false) {
            return $to;
        }
        $original_to = $to;
        if (!empty($to)) {
            $to = EmailUtils::get_email_from_rfc_email($to);
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return $original_to;
            }
        }
        $config = SiteConfig::current_site_config();
        $config_field = self::config()->siteconfig_to;
        if ($config_field && !empty($config->$config_field)) {
            return $config->$config_field;
        }
        if ($admin = Email::config()->admin_email) {
            return $admin;
        }
        return null;
    }

    /**
     * Create a sensible default address based on domain name
     *
     * @return string
     */
    public static function createDefaultEmail()
    {
        $fulldom = Director::absoluteBaseURL();
        $host = parse_url($fulldom, PHP_URL_HOST);
        if (!$host) {
            $host = 'localhost';
        }
        $dom = str_replace('www.', '', $host);

        return 'postmaster@' . $dom;
    }

    /**
     * Is logging enabled?
     *
     * @return bool
     */
    public static function getLoggingEnabled()
    {
        if (self::config()->get('enable_logging')) {
            return true;
        }
        return false;
    }

    /**
     * Is sending enabled?
     *
     * @return bool
     */
    public static function getSendingEnabled()
    {
        if (self::config()->get('disable_sending')) {
            return false;
        }
        return true;
    }
}
