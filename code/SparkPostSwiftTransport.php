<?php
namespace LeKoala\SparkPost;

use \Swift_Events_EventDispatcher;
use \Swift_Events_EventListener;
use \Swift_Events_SendEvent;
use \Swift_Mime_Message;
use \Swift_Transport;
use \Swift_Attachment;
use \Swift_MimePart;
use LeKoala\SparkPost\Api\SparkPostApiClient;

/**
 * A SparkPost transport for Swift Mailer using our custom client
 *
 * Heavily inspired by slowprog/SparkPostSwiftMailer
 *
 * @link https://github.com/slowprog/SparkPostSwiftMailer
 * @link https://www.sparkpost.com/api#/reference/introduction
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostSwiftTransport implements Swift_Transport
{

    /**
     * @var Swift_Transport_SimpleMailInvoker
     */
    protected $invoker;

    /**
     * @var Swift_Events_SimpleEventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var LeKoala\SparkPost\Api\SparkPostApiClient
     */
    protected $client;

    /**
     * @var [type]
     */
    protected $resultApi;

    /**
     * @var [type]
     */
    protected $fromEmail;

    /**
     * @var boolean
     */
    protected $isStarted = false;

    public function __construct(SparkPostApiClient $client)
    {
        $this->client = $client;

        $this->invoker = new \Swift_Transport_SimpleMailInvoker();
        $this->eventDispatcher = new \Swift_Events_SimpleEventDispatcher();
    }

    /**
     * Not used
     */
    public function isStarted()
    {
        return $this->isStarted;
    }

    /**
     * Not used
     */
    public function start()
    {
        $this->isStarted = true;
    }

    /**
     * Not used
     */
    public function stop()
    {
        $this->isStarted = false;
    }

    /**
     * @param Swift_Mime_Message $message
     * @param null $failedRecipients
     * @return int Number of messages sent
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $this->resultApi = null;
        if ($event = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($event, 'beforeSendPerformed');
            if ($event->bubbleCancelled()) {
                return 0;
            }
        }

        $sendCount = 0;

        $transmissionData = $this->getTransmissionFromMessage($message);

        /* @var $client LeKoala\SparkPost\Api\SparkPostApiClient */
        $client = $this->client;

        try {
            $result = $client->createTransmission($transmissionData);
            $this->resultApi = $result;
        } catch (\Exception $e) {
            throw $e;
        }

        $sendCount = $this->resultApi['total_accepted_recipients'];

        if ($this->resultApi['total_rejected_recipients'] > 0) {
            $failedRecipients[] = $this->fromEmail;
        }

        if ($event) {
            if ($sendCount > 0) {
                $event->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            } else {
                $event->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            }

            $this->eventDispatcher->dispatchEvent($event, 'sendPerformed');
        }

        return $sendCount;
    }

     /**
     * Send the email through SparkPost
     *
     * TODO: verify if we need to add some functionnalities back into new "send" method
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
    public function old_send($email)
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
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }

    /**
     * @return array
     */
    protected function getSupportedContentTypes()
    {
        return array(
            'text/plain',
            'text/html'
        );
    }

    /**
     * @param string $contentType
     * @return bool
     */
    protected function supportsContentType($contentType)
    {
        return in_array($contentType, $this->getSupportedContentTypes());
    }

    /**
     * @param Swift_Mime_Message $message
     * @return string
     */
    protected function getMessagePrimaryContentType(Swift_Mime_Message $message)
    {
        $contentType = $message->getContentType();

        if ($this->supportsContentType($contentType)) {
            return $contentType;
        }

        // SwiftMailer hides the content type set in the constructor of Swift_Mime_Message as soon
        // as you add another part to the message. We need to access the protected property
        // _userContentType to get the original type.
        $messageRef = new \ReflectionClass($message);
        if ($messageRef->hasProperty('_userContentType')) {
            $propRef = $messageRef->getProperty('_userContentType');
            $propRef->setAccessible(true);
            $contentType = $propRef->getValue($message);
        }

        return $contentType;
    }

    /**
     * https://jsapi.apiary.io/apis/sparkpostapi/introduction/subaccounts-coming-to-an-api-near-you-in-april!.html
     *
     * @param Swift_Mime_Message $message
     * @return array SparkPost Send Message
     * @throws \Swift_SwiftException
     */
    public function getTransmissionFromMessage(Swift_Mime_Message $message)
    {
        $contentType = $this->getMessagePrimaryContentType($message);

        $fromAddresses = $message->getFrom();
        $fromEmails = array_keys($fromAddresses);
        list($fromFirstEmail, $fromFirstName) = each($fromAddresses);
        $this->fromEmail = $fromFirstEmail;

        $toAddresses = $message->getTo();
        $ccAddresses = $message->getCc() ? $message->getCc() : [];
        $bccAddresses = $message->getBcc() ? $message->getBcc() : [];
        $replyToAddresses = $message->getReplyTo() ? $message->getReplyTo() : [];

        $recipients = array();
        $cc = array();
        $bcc = array();
        $attachments = array();
        $headers = array();
        $tags = array();
        $inlineCss = null;

        if ($message->getHeaders()->has('X-MC-Tags')) {
            /** @var \Swift_Mime_Headers_UnstructuredHeader $tagsHeader */
            $tagsHeader = $message->getHeaders()->get('X-MC-Tags');
            $tags = explode(',', $tagsHeader->getValue());
        }

        foreach ($toAddresses as $toEmail => $toName) {
            $recipients[] = array(
                'address' => array(
                    'email' => $toEmail,
                    'name' => $toName,
                ),
                'tags' => $tags,
            );
        }
        $reply_to = null;
        foreach ($replyToAddresses as $replyToEmail => $replyToName) {
            if ($replyToName) {
                $reply_to = sprintf('%s <%s>', $replyToName, $replyToEmail);
            } else {
                $reply_to = $replyToEmail;
            }
        }

        foreach ($ccAddresses as $ccEmail => $ccName) {
            $cc[] = array(
                'email' => $ccEmail,
                'name' => $ccName,
            );
        }

        foreach ($bccAddresses as $bccEmail => $bccName) {
            $bcc[] = array(
                'email' => $bccEmail,
                'name' => $bccName,
            );
        }

        $bodyHtml = $bodyText = null;

        if ($contentType === 'text/plain') {
            $bodyText = $message->getBody();
        } elseif ($contentType === 'text/html') {
            $bodyHtml = $message->getBody();
        } else {
            $bodyHtml = $message->getBody();
        }

        foreach ($message->getChildren() as $child) {
            if ($child instanceof Swift_Attachment) {
                $attachments[] = array(
                    'type' => $child->getContentType(),
                    'name' => $child->getFilename(),
                    'data' => base64_encode($child->getBody())
                );
            } elseif ($child instanceof Swift_MimePart && $this->supportsContentType($child->getContentType())) {
                if ($child->getContentType() == "text/html") {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == "text/plain") {
                    $bodyText = $child->getBody();
                }
            }
        }

        if ($message->getHeaders()->has('List-Unsubscribe')) {
            $headers['List-Unsubscribe'] = $message->getHeaders()->get('List-Unsubscribe')->getValue();
        }

        if ($message->getHeaders()->has('X-MC-InlineCSS')) {
            $inlineCss = $message->getHeaders()->get('X-MC-InlineCSS')->getValue();
        }

        $sparkPostMessage = array(
            'recipients' => $recipients,
            'reply_to' => $reply_to,
            'inline_css' => $inlineCss,
            'tags' => $tags,
            'content' => array(
                'from' => array(
                    'name' => $fromFirstName,
                    'email' => $fromFirstEmail,
                ),
                'subject' => $message->getSubject(),
                'html' => $bodyHtml,
                'text' => $bodyText,
            ),
        );

        if (!empty($cc)) {
            $sparkPostMessage['cc'] = $cc;
        }
        if (!empty($bcc)) {
            $sparkPostMessage['bcc'] = $bcc;
        }
        if (!empty($headers)) {
            $sparkPostMessage['headers'] = $headers;
        }

        if (count($attachments) > 0) {
            $sparkPostMessage['attachments'] = $attachments;
        }

        return $sparkPostMessage;
    }

    /**
     * @return null|array
     */
    public function getResultApi()
    {
        return $this->resultApi;
    }
}
