<?php

namespace LeKoala\SparkPost;

use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use SilverStripe\Control\Director;
use Symfony\Component\Mailer\Envelope;
use SilverStripe\Assets\FileNameFilter;
use Symfony\Component\Mailer\SentMessage;
use LeKoala\SparkPost\Api\SparkPostApiClient;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * We create our own class
 * We cannot extend easily due to private methods
 *
 * @link https://developers.sparkpost.com/api/transmissions/
 * @link https://github.com/gam6itko/sparkpost-mailer/blob/master/src/Transport/SparkPostApiTransport.php
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.sparkpost.com';
    private const EU_HOST = 'api.eu.sparkpost.com';

    /**
     * @var SparkPostApiClient
     */
    private $apiClient;

    /**
     * @var array<mixed>
     */
    private $apiResult;

    /**
     * @param SparkPostApiClient $apiClient
     * @param HttpClientInterface|null $client
     * @param EventDispatcherInterface|null $dispatcher
     * @param LoggerInterface|null $logger
     */
    public function __construct(SparkPostApiClient $apiClient, HttpClientInterface $client = null, EventDispatcherInterface $dispatcher = null, LoggerInterface $logger = null)
    {
        $this->apiClient = $apiClient;

        if ($apiClient->getEuEndpoint()) {
            $this->setHost(self::EU_HOST);
        } else {
            $this->setHost(self::HOST);
        }

        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('sparkpost+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $disableSending = $email->getHeaders()->has('X-SendingDisabled') || !SparkPostHelper::getSendingEnabled();

        // We don't really care about the actual response
        $response = new MockResponse();

        $to = $email->getTo();

        if ($disableSending) {
            $result = [
                'total_rejected_recipients' => 0,
                'total_accepted_recipients' => count($to),
                'id' => uniqid(),
                'disabled' => true,
            ];
        } else {
            $payload = $this->getPayload($email, $envelope);
            $result = $this->apiClient->createTransmission($payload);
        }

        // Add email
        $result['email'] = implode('; ', array_map(function ($recipient) {
            return $recipient->toString();
        }, $to));

        $this->apiResult = $result;

        $messageId = $result['id'] ?? null;
        if ($messageId) {
            $sentMessage->setMessageId($messageId);
        }

        if (SparkPostHelper::getLoggingEnabled()) {
            $this->logMessageContent($email, $result);
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    public function getApiResult(): array
    {
        return $this->apiResult;
    }

    /**
     * @return string
     */
    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST) . ($this->port ? ':' . $this->port : '');
    }

    /**
     * @param Email $email
     * @return array<array<mixed>>
     */
    private function buildAttachments(Email $email): array
    {
        $result = [];
        foreach ($email->getAttachments() as $attachment) {
            $preparedHeaders = $attachment->getPreparedHeaders();
            /** @var ParameterizedHeader $file */
            $file = $preparedHeaders->get('Content-Disposition');
            /** @var ParameterizedHeader $type */
            $type = $preparedHeaders->get('Content-Type');

            $result[] = [
                'name' => $file->getParameter('filename'),
                'type' => $type->getValue(),
                'data' => base64_encode($attachment->getBody()),
            ];
        }

        return $result;
    }

    /**
     * @param Email $email
     * @param Envelope $envelope
     * @return array<string,mixed>
     */
    public function getPayload(Email $email, Envelope $envelope): array
    {
        $from = $envelope->getSender();

        $fromFirstEmail = $from->getAddress();
        $fromFirstName = $from->getName();
        if (SparkPostHelper::config()->override_admin_email && SparkPostHelper::isAdminEmail($fromFirstEmail)) {
            $fromFirstEmail = SparkPostHelper::resolveDefaultFromEmail();
        }
        if (SparkPostHelper::getEnvForceSender()) {
            $fromFirstEmail = SparkPostHelper::getEnvForceSender();
        }
        if (!$fromFirstName) {
            $fromFirstName = EmailUtils::get_displayname_from_rfc_email($fromFirstEmail);
        }

        $toAddresses = [];
        $ccAddresses = [];
        $bccAddresses = [];

        foreach ($envelope->getRecipients() as $recipient) {
            $type = 'to';
            if (\in_array($recipient, $email->getBcc(), true)) {
                $type = 'bcc';
            } elseif (\in_array($recipient, $email->getCc(), true)) {
                $type = 'cc';
            }

            $recipientEmail = $recipient->getAddress();

            // This is always going to be empty because of Envelope:77
            // $this->recipients[] = new Address($recipient->getAddress());
            $recipientName = $recipient->getName();
            if (!$recipientName) {
                $recipientName = EmailUtils::get_displayname_from_rfc_email($recipientEmail);
            }

            switch ($type) {
                case 'to':
                    $toAddresses[$recipientEmail] = $recipientName;
                    break;
                case 'cc':
                    $ccAddresses[$recipientEmail] = $recipientName;
                    break;
                case 'bcc':
                    $bccAddresses[$recipientEmail] = $recipientName;
                    break;
            }
        }

        $recipients = [];
        $cc = [];
        $bcc = [];
        $headers = [];
        $tags = [];
        $metadata = [];
        $inlineCss = null;

        // Mandrill compatibility
        // Data is merged with transmission and removed from headers
        // @link https://mailchimp.com/developer/transactional/docs/tags-metadata/#tags
        $emailHeaders = $email->getHeaders();
        if ($emailHeaders->has('X-MC-Tags')) {
            $tagsHeader = $emailHeaders->get('X-MC-Tags');
            $tags = explode(',', self::getHeaderValue($tagsHeader));
            $emailHeaders->remove('X-MC-Tags');
        }
        if ($emailHeaders->has('X-MC-Metadata')) {
            $metadataHeader = $emailHeaders->get('X-MC-Metadata');
            $metadata = json_decode(self::getHeaderValue($metadataHeader), true);
            $emailHeaders->remove('X-MC-Metadata');
        }
        if ($emailHeaders->has('X-MC-InlineCSS')) {
            $inlineHeader = $emailHeaders->get('X-MC-InlineCSS');
            $inlineCss = self::getHeaderValue($inlineHeader);
            $emailHeaders->remove('X-MC-InlineCSS');
        }

        // Handle MSYS headers
        // Data is merge with transmission and removed from headers
        // @link https://developers.sparkpost.com/api/smtp-api.html
        $msysHeader = [];
        if ($emailHeaders->has('X-MSYS-API')) {
            $msysHeaderObj = $emailHeaders->get('X-MSYS-API');
            $msysHeader = json_decode(self::getHeaderValue($msysHeaderObj), true);
            if (!empty($msysHeader['tags'])) {
                $tags = array_merge($tags, $msysHeader['tags']);
            }
            if (!empty($msysHeader['metadata'])) {
                $metadata = array_merge($metadata, $msysHeader['metadata']);
            }
            $emailHeaders->remove('X-MSYS-API');
        }

        // Build recipients list
        // @link https://developers.sparkpost.com/api/recipient-lists.html
        $primaryEmail = null;
        foreach ($toAddresses as $toEmail => $toName) {
            if ($primaryEmail === null) {
                $primaryEmail = $toEmail;
            }
            if (!$toName) {
                $toName = $toEmail;
            }
            $recipient = array(
                'address' => array(
                    'email' => $toEmail,
                    'name' => $toName,
                )
            );
            if (!empty($tags)) {
                $recipient['tags'] = $tags;
            }
            // TODO: metadata are not valid?
            if (!empty($metadata)) {
                $recipient['metadata'] = $metadata;
            }
            $recipients[] = $recipient;
        }

        // @link https://www.sparkpost.com/docs/faq/cc-bcc-with-rest-api/
        foreach ($ccAddresses as $ccEmail => $ccName) {
            $cc[] = array(
                'email' => $ccEmail,
                'name' => $ccName,
                'header_to' => $primaryEmail ? $primaryEmail : $ccEmail,
            );
        }

        foreach ($bccAddresses as $bccEmail => $bccName) {
            $bcc[] = array(
                'email' => $bccEmail,
                'name' => $bccName,
                'header_to' => $primaryEmail ? $primaryEmail : $bccEmail,
            );
        }

        $bodyHtml = (string)$email->getHtmlBody();
        $bodyText = (string)$email->getTextBody();

        if ($bodyHtml) {
            // If we ask to provide plain, use our custom method instead of the provided one
            if (SparkPostHelper::config()->provide_plain) {
                $bodyText = EmailUtils::convert_html_to_text($bodyHtml);
            }

            // Should we inline css
            if (!$inlineCss && SparkPostHelper::config()->inline_styles) {
                $bodyHtml = EmailUtils::inline_styles($bodyHtml);
            }
        }

        // Custom unsubscribe list
        if ($emailHeaders->has('List-Unsubscribe')) {
            $unsubHeader  = $emailHeaders->get('List-Unsubscribe');
            $headers['List-Unsubscribe'] = self::getHeaderValue($unsubHeader);
        }

        $defaultParams = SparkPostHelper::config()->default_params;
        if ($inlineCss !== null) {
            $defaultParams['inline_css'] = $inlineCss;
        }

        // Build base transmission. Keep in mind that parameters are mapped by the sdk
        // @link @link https://developers.sparkpost.com/api/transmissions/#transmissions-post-send-inline-content
        $sparkPostMessage = [
            'recipients' => $recipients,
            'content' => [
                'from' => [
                    'name' => $fromFirstName,
                    'email' => $fromFirstEmail,
                ],
                'subject' => $email->getSubject(),
                'html' => $bodyHtml,
                'text' => $bodyText,
            ],
        ];
        if ($email->getReplyTo()) {
            $sparkPostMessage['reply_to'] = $email->getReplyTo();
        }

        // Add default params
        $sparkPostMessage = array_merge($defaultParams, $sparkPostMessage);
        if ($msysHeader) {
            $sparkPostMessage = array_merge($sparkPostMessage, $msysHeader);
        }

        // Add remaining elements
        if (!empty($cc)) {
            $sparkPostMessage['headers.CC'] = $cc;
        }
        if (!empty($headers)) {
            $sparkPostMessage['customHeaders'] = $headers;
        }

        $attachments = $this->buildAttachments($email);
        if (count($attachments) > 0) {
            $sparkPostMessage['attachments'] = $attachments;
        }

        return $sparkPostMessage;
    }

    /**
     * @param HeaderInterface|UnstructuredHeader|null $header
     * @return string
     */
    protected static function getHeaderValue(HeaderInterface $header = null)
    {
        if (!$header) {
            return '';
        }
        if ($header instanceof UnstructuredHeader) {
            return $header->getValue();
        }
        return $header->getBody();
    }


    /**
     * Log message content
     *
     * @param Email $message
     * @param array<mixed> $results Results from the api
     * @throws Exception
     * @return void
     */
    protected function logMessageContent(Email $message, $results = [])
    {
        // Folder not set
        $logFolder = SparkPostHelper::getLogFolder();
        if (!$logFolder) {
            return;
        }
        // Logging disabled
        if (!SparkPostHelper::getLoggingEnabled()) {
            return;
        }

        $logContent = "";

        $subject = $message->getSubject();
        if ($message->getHtmlBody()) {
            $logContent .= (string)$message->getHtmlBody();
        } else {
            $logContent .= $message->getBody()->toString();
        }
        $logContent .= "<hr/>";
        $emailHeaders = $message->getHeaders();

        // Append some extra information at the end
        $logContent .= '<pre>Debug infos:' . "\n\n";
        $logContent .= 'To : ' . EmailUtils::stringifyArray($message->getTo()) . "\n";
        $logContent .= 'Subject : ' . $subject . "\n";
        $logContent .= 'From : ' . EmailUtils::stringifyArray($message->getFrom()) . "\n";
        $logContent .= 'Headers:' . "\n" . $emailHeaders->toString() . "\n";
        $logContent .= 'Results:' . "\n";
        $logContent .= print_r($results, true) . "\n";
        $logContent .= '</pre>';

        // Generate filename
        $filter = new FileNameFilter();
        $title = substr($filter->filter($subject), 0, 35);
        $logName = date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '_' . $title;

        // Store attachments if any
        $attachments = $message->getAttachments();
        if (!empty($attachments)) {
            $logContent .= '<hr />';
            foreach ($attachments as $attachment) {
                $attachmentDestination = $logFolder . '/' . $logName . '_' . $attachment->getFilename();
                file_put_contents($attachmentDestination, $attachment->getBody());
                $logContent .= 'File : <a href="' . $attachmentDestination . '">' . $attachment->getFilename() . '</a><br/>';
            }
        }

        // Store it
        $r = file_put_contents($logFolder . '/' . $logName . '.html', $logContent);

        if (!$r && Director::isDev()) {
            throw new Exception('Failed to store email in ' . $logFolder);
        }
    }
}
