<?php

namespace LeKoala\SparkPost;

use Exception;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Control\Email\SwiftMailer;
use LeKoala\SparkPost\Api\SparkPostApiClient;
use LeKoala\SparkPost\SparkPostSwiftTransport;
use SilverStripe\Core\Config\Config;
use Swift_Mailer;

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
     * @var SparkPostApiClient
     */
    protected static $client;

    /**
     * Get the mailer instance
     *
     * @return SilverStripe\Control\Email\SwiftMailer
     */
    public static function getMailer()
    {
        return Injector::inst()->get(Mailer::class);
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
     * @return LeKoala\SparkPost\Api\SparkPostApiClient
     *
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

    public static function getEnvApiKey()
    {
        return Environment::getEnv('SPARKPOST_API_KEY');
    }

    public static function getEnvMasterApiKey()
    {
        return Environment::getEnv('SPARKPOST_MASTER_API_KEY');
    }

    public static function getEnvSendingDisabled()
    {
        return Environment::getEnv('SPARKPOST_SENDING_DISABLED');
    }

    public static function getEnvEnableLogging()
    {
        return  Environment::getEnv('SPARKPOST_ENABLE_LOGGING');
    }

    public static function getEnvSubaccountId()
    {
        return  Environment::getEnv('SPARKPOST_SUBACCOUNT_ID');
    }

    public static function getEnvForceSender()
    {
        return  Environment::getEnv('SPARKPOST_FORCE_SENDER');
    }

    /**
     * Register the transport with the client
     *
     * @return SilverStripe\Control\Email\SwiftMailer The updated swift mailer
     * @throws Exception
     */
    public static function registerTransport()
    {
        $client = self::getClient();
        $mailer = self::getMailer();
        if (!$mailer instanceof SwiftMailer) {
            throw new Exception("Mailer must be an instance of " . SwiftMailer::class . " instead of " . get_class($mailer));
        }
        $transport = new SparkPostSwiftTransport($client);
        $newSwiftMailer = new Swift_Mailer($transport);
        $mailer->setSwiftMailer($newSwiftMailer);
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
     * @return string
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
        if ($admin = Email::config()->admin_email) {
            return $admin;
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
     * @param string $to
     * @return string
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
        return false;
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
}
