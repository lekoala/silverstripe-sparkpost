<?php

/**
 * SparkPost for SilverStripe
 *
 * @link https://www.sparkpost.com/api#/reference/introduction
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostMailer extends Mailer
{

    /**
     * Mailer instance
     *
     * @var SparkPostMailer
     */
    protected static $instance;

    /**
     * Client instance
     *
     * @var SparkPostApiClient
     */
    protected $client;

    /**
     * Last exception from the client
     *
     * @var Exception
     */
    protected $lastException;

    public function __construct()
    {
        self::$instance = $this;
    }

    /**
     * Helper method to initialize the mailer
     *
     * @return SparkPostMailer
     */
    public static function setAsMailer()
    {
        $mailer = self::getInstance();
        Email::set_mailer($mailer);
        return $mailer;
    }

    /**
     * @return SparkPostApiClient
     * @throws Exception
     */
    public function getClient()
    {
        if (!$this->client) {
            $key = self::config()->api_key;
            if (empty($key)) {
                throw new Exception("Api key is not defined or empty");
            }
            $this->client = new SparkPostApiClient($key);
            if (Director::isDev()) {
                $this->client->setCurlOption(CURLOPT_VERBOSE, true);
            }
            $subaccountId = self::config()->subaccount_id;
            if ($subaccountId) {
                $this->client->setSubaccount($subaccountId);
            }
        }
        return $this->client;
    }

    /**
     * @return SparkPostApiClient
     * @throws Exception
     */
    public function getMasterClient()
    {
        if (!$this->client) {
            $key = self::config()->master_api_key;
            if (empty($key)) {
                throw new Exception("Master api key is not defined or empty");
            }
            $this->client = new SparkPostApiClient($key);
            if (Director::isDev()) {
                $this->client->setCurlOption(CURLOPT_VERBOSE, true);
            }
        }
        return $this->client;
    }

    /**
     * @return bool
     */
    public static function getSendingDisabled()
    {
        return self::config()->disable_sending;
    }

    /**
     * @return \SparkPostMailer
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Add file upload support
     *
     * A typical SilverStripe attachement looks like this :
     *
     * array(
     * 'contents' => $data,
     * 'filename' => $filename,
     * 'mimetype' => $mimetype,
     * );
     *
     * @link https://support.sparkpost.com/customer/en/portal/articles/2214831-sending-attachments-in-sparkpost-and-sparkpost-elite
     * @param string|array $file The name of the file or a silverstripe array
     * @param string $destFileName
     * @param string $disposition
     * @param string $extraHeaders
     * @return array
     */
    public function encodeFileForEmail($file, $destFileName = false, $disposition = null, $extraHeaders = "")
    {
        if (!$file) {
            throw new Exception("encodeFileForEmail: not passed a filename and/or data");
        }

        if (is_string($file)) {
            $file = ['filename' => $file];
            $file['contents'] = file_get_contents($file['filename']);
        }

        if (empty($file['contents'])) {
            throw new Exception('A file should have some contents');
        }

        $name = $destFileName;
        if (!$destFileName) {
            $name = basename($file['filename']);
        }

        $mimeType = !empty($file['mimetype']) ? $file['mimetype'] : HTTP::get_mime_type($file['filename']);
        if (!$mimeType) {
            $mimeType = "application/unknown";
        }

        $content = $file['contents'];
        $content = base64_encode($content);

        // Return completed packet
        return [
            'type' => $mimeType,
            'name' => $name,
            'data' => $content
        ];
    }

    /**
     * We use only send method
     *
     * @param string|array $to
     * @param string $from
     * @param string $subject
     * @param string $plainContent
     * @param array $attachedFiles
     * @param array $customheaders
     * @return array|bool
     */
    public function sendPlain($to, $from, $subject, $plainContent, $attachedFiles = false, $customheaders = false)
    {
        return $this->send($to, $from, $subject, false, $attachedFiles, $customheaders, $plainContent, false);
    }

    /**
     * We use only send method
     *
     * @param string|array $to
     * @param string $from
     * @param string $subject
     * @param string $plainContent
     * @param array $attachedFiles
     * @param array $customheaders
     * @return array|bool
     */
    public function sendHTML($to, $from, $subject, $htmlContent, $attachedFiles = false, $customheaders = false, $plainContent = false, $inlineImages = false)
    {
        return $this->send($to, $from, $subject, $htmlContent, $attachedFiles, $customheaders, $plainContent, $inlineImages);
    }

    /**
     * Normalize an address to an array of email and name
     *
     * @param string|array $address
     * @return array
     */
    protected function processAddress($address)
    {
        if (is_array($address)) {
            $email = $address['email'];
            $name = $address['name'];
        } elseif (strpos($address, '<') !== false) {
            $email = self::get_email_from_rfc_email($address);
            $name = self::get_displayname_from_rfc_email($address);
        } else {
            $email = $address;
            $name = null;
        }

        // As a fallback, extract the first part of the email as the name
        if (!$name && self::config()->name_fallback) {
            $name = trim(ucwords(str_replace(['.', '-', '_'], ' ', substr($email, 0, strpos($email, '@')))));
        }

        return [
            'email' => $email,
            'name' => $name,
        ];
    }

    /**
     * A helper method to process a list of recipients
     *
     * @param array $arr
     * @param string|array $recipients
     * @return array
     */
    protected function appendTo($arr, $recipients)
    {
        if (!is_array($recipients)) {
            $recipients = explode(',', $recipients);
        }
        foreach ($recipients as $recipient) {
            $r = $this->processAddress($recipient);

            $to = ['email' => $r['email']];
            if ($r['name']) {
                $to['name'] = $r['name'];
            }

            $arr[] = ['address' => $to];
        }
        return $arr;
    }

    /**
     * Send the email through SparkPost
     *
     * @param string|array $to
     * @param string $from
     * @param string $subject
     * @param string $plainContent
     * @param array $attachedFiles
     * @param array $customheaders
     * @param bool $inlineImages
     * @return array|bool
     */
    protected function send($to, $from, $subject, $htmlContent, $attachedFiles = false, $customheaders = false, $plainContent = false, $inlineImages = false)
    {
        $original_to = $to;

        // Process recipients
        $to_array = [];
        $to_array = $this->appendTo($to_array, $to);

        // Handle CC/BCC/BCC ALL
        if (isset($customheaders['Cc'])) {
            $to_array = $this->appendTo($to_array, $customheaders['Cc']);
            unset($customheaders['Cc']);
        }
        if (isset($customheaders['Bcc'])) {
            $to_array = $this->appendTo($to_array, $customheaders['Bcc']);
            unset($customheaders['Bcc']);
        }
        $bcc_email = Email::config()->bcc_all_emails_to;
        if ($bcc_email) {
            $to_array = $this->appendTo($to_array, $bcc_email);
        }

        // Process sender
        $from = $this->resolveDefaultFromEmail($from);

        // Create params
        $default_params = [];
        if (self::config()->default_params) {
            $default_params = self::config()->default_params;
        }
        $params = array_merge($default_params, [
            "subject" => $subject,
            "from" => $from,
            "recipients" => $to_array
        ]);

        // Inject additional params into message
        if (isset($customheaders['X-SparkPostMailer'])) {
            $params = array_merge($params, $customheaders['X-SparkPostMailer']);
            unset($customheaders['X-SparkPostMailer']);
        }

        // Always set some default content
        if (!$plainContent && $htmlContent && self::config()->provide_plain) {
            $plainContent = $this->convertHtmlToText($htmlContent);
        }

        if ($plainContent) {
            $params['text'] = $plainContent;
        }
        if ($htmlContent) {
            if (self::config()->inline_styles) {
                try {
                    $html = $this->inlineStyles($htmlContent);

                    // Prevent SparkPost from inlining twice
                    $params['default_params']['inlineCss'] = false;
                } catch (Exception $ex) {
                    // If it fails, let SparkPost do the job
                    $params['default_params']['inlineCss'] = true;
                }
            }

            $params['html'] = $htmlContent;
        }

        // Handle files attachments
        if ($attachedFiles) {
            $attachments = [];

            // Include any specified attachments as additional parts
            foreach ($attachedFiles as $file) {
                if (isset($file['tmp_name']) && isset($file['name'])) {
                    $attachments[] = $this->encodeFileForEmail($file['tmp_name'], $file['name']);
                } else {
                    $attachments[] = $this->encodeFileForEmail($file);
                }
            }

            $params['attachments'] = $attachments;
        }

        // Handle Reply-To custom header properly
        if (isset($customheaders['Reply-To'])) {
            $params['replyTo'] = $customheaders['Reply-To'];
            unset($customheaders['Reply-To']);
        }

        // Handle other custom headers
        if (isset($customheaders['Metadata'])) {
            if (!is_array($customheaders['Metadata'])) {
                throw new Exception("Metadata parameter must be an associative array");
            }
            $params['metadata'] = $customheaders['Metadata'];
            unset($customheaders['Metadata']);
        }
        if (isset($customheaders['Campaign'])) {
            $params['campaign'] = $customheaders['Campaign'];
            unset($customheaders['Campaign']);
        }
        if (isset($customheaders['Description'])) {
            $params['description'] = $customheaders['Description'];
            unset($customheaders['Description']);
        }


        if ($customheaders) {
            $params['customHeaders'] = $customheaders;
        }

        $sendingDisabled = false;
        if (isset($customheaders['X-SendingDisabled']) && $customheaders['X-SendingDisabled']) {
            $sendingDisabled = $sendingDisabled;
            unset($customheaders['X-SendingDisabled']);
        }

        if (self::config()->enable_logging) {
            // Append some extra information at the end
            $logContent = $htmlContent;
            $logContent .= '<hr><pre>Debug infos:' . "\n\n";
            $logContent .= 'To : ' . print_r($original_to, true) . "\n";
            $logContent .= 'Subject : ' . $subject . "\n";
            $logContent .= 'Headers : ' . print_r($customheaders, true) . "\n";
            if (!empty($params['from'])) {
                $logContent .= 'From : ' . $params['from'] . "\n";
            }
            if (!empty($params['recipients'])) {
                $logContent .= 'Recipients : ' . print_r($params['recipients'], true) . "\n";
            }
            $logContent .= '</pre>';

            $logFolder = $this->getLogFolder();

            // Generate filename
            $filter = new FileNameFilter();
            $title = substr($filter->filter($subject), 0, 35);
            $logName = date('Ymd_His') . '_' . $title;

            // Store attachments if any
            if (!empty($params['attachments'])) {
                $logContent .= '<hr />';
                foreach ($params['attachments'] as $attachment) {
                    file_put_contents($logFolder . '/' . $logName . '_' . $attachment['name'], base64_decode($attachment['data']));

                    $logContent .= 'File : ' . $attachment['name'] . '<br/>';
                }
            }

            // Store it
            $ext = empty($htmlContent) ? 'txt' : 'html';

            $r = file_put_contents($logFolder . '/' . $logName . '.' . $ext, $logContent);

            if (!$r && Director::isDev()) {
                throw new Exception('Failed to store email in ' . $logFolder);
            }
        }

        if (self::getSendingDisabled() || $sendingDisabled) {
            $customheaders['X-SendingDisabled'] = true;
            return array($original_to, $subject, $htmlContent, $customheaders);
        }

        $logLevel = self::config()->log_level ? self::config()->log_level : 7;

        try {
            $result = $this->getClient()->createTransmission($params);

            if (!empty($result['total_accepted_recipients'])) {
                return [$original_to, $subject, $htmlContent, $customheaders, $result];
            }

            SS_Log::log("No recipient was accepted for transmission " . $result['id'], $logLevel);
        } catch (Exception $ex) {
            $this->lastException = $ex;
            SS_Log::log($ex->getMessage(), $logLevel);
        }

        return false;
    }

    /**
     * Inline styles using Pelago Emogrifier
     *
     * @param string $html
     * @return string
     */
    protected function inlineStyles($html)
    {
        if (!class_exists("\\Pelago\\Emogrifier")) {
            throw new Exception("You must run composer require pelago/emogrifier");
        }
        $emogrifier = new \Pelago\Emogrifier();
        $emogrifier->setHtml($html);
        $emogrifier->disableInvisibleNodeRemoval();
        $emogrifier->enableCssToHtmlMapping();
        $html = $emogrifier->emogrify();

        return $html;
    }

    /**
     * Get the log folder and create it if necessary
     *
     * @return string
     */
    public function getLogFolder()
    {
        $logFolder = BASE_PATH . '/' . self::config()->log_folder;
        if (!is_dir($logFolder)) {
            mkdir($logFolder, 0755, true);
        }
        return $logFolder;
    }

    /**
     * Convert an html email to a text email while keeping formatting and links
     *
     * @param string $content
     * @return string
     */
    protected function convertHtmlToText($content)
    {
        // Prevent styles to be included
        $content = preg_replace('/<style.*>([\s\S]*)<\/style>/i', '', $content);
        // Convert html entities to strip them later on
        $content = html_entity_decode($content);
        // Convert new lines for relevant tags
        $content = str_ireplace(['<br />', '<br/>', '<br>', '<table>', '</table>'], "\r\n", $content);
        // Avoid lots of spaces
        $content = preg_replace('/[\r\n]+/', ' ', $content);
        // Replace links to keep them accessible
        $content = preg_replace('/<a[\s\S]*href="(.*?)"[\s\S]*>(.*)<\/a>/i', '$2 ($1)', $content);
        // Remove html tags
        $content = strip_tags($content);
        // Trim content so that it's nice
        $content = trim($content);
        return $content;
    }

    /**
     * Get last exception
     *
     * @return Exception
     */
    public function getLastException()
    {
        return $this->lastException;
    }

    /**
     * Resolve default send from address
     *
     * Keep in mind that an email using send() without a from
     * will inject the admin_email. Therefore, SiteConfig
     * will not be used
     *
     * @param string $from
     * @return string
     */
    public static function resolveDefaultFromEmail($from = null)
    {
        $original_from = $from;
        if (!empty($from)) {
            // If we have a sender, validate its email
            $from = self::get_email_from_rfc_email($from);
            if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
                return $original_from;
            }
        }
        // Look in siteconfig for default sender
        $config = SiteConfig::current_site_config();
        $config_field = self::config()->siteconfig_from;
        if ($config_field && !empty($config->$config_field)) {
            return $config->$config_field;
        }
        // Use admin email
        if ($admin = Email::config()->admin_email) {
            return $admin;
        }
        // If we still don't have anything, create something based on the domain
        return self::createDefaultEmail();
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
            $to = self::get_email_from_rfc_email($to);
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

    /**
     * Match all words and whitespace, will be terminated by '<'
     *
     * Note: use /u to support utf8 strings
     *
     * @param string $rfc_email_string
     * @return string
     */
    public static function get_displayname_from_rfc_email($rfc_email_string)
    {
        $name = preg_match('/[\w\s]+/u', $rfc_email_string, $matches);
        $matches[0] = trim($matches[0]);
        return $matches[0];
    }

    /**
     * Extract parts between brackets
     *
     * @param string $rfc_email_string
     * @return string
     */
    public static function get_email_from_rfc_email($rfc_email_string)
    {
        if (strpos($rfc_email_string, '<') === false) {
            return $rfc_email_string;
        }
        $mailAddress = preg_match('/(?:<)(.+)(?:>)$/', $rfc_email_string, $matches);
        if (empty($matches)) {
            return $rfc_email_string;
        }
        return $matches[1];
    }
}
