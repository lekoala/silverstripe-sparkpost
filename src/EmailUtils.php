<?php

namespace LeKoala\SparkPost;

use Pelago\Emogrifier\CssInliner;
use Symfony\Component\Mime\Address;
use Pelago\Emogrifier\HtmlProcessor\HtmlPruner;
use Pelago\Emogrifier\HtmlProcessor\CssToAttributeConverter;

class EmailUtils
{
    /**
     * Inline styles using Pelago Emogrifier V7
     *
     * This is much better than the functionnality provided by SparkPost anyway
     *
     * @link https://github.com/MyIntervals/emogrifier#more-complex-example
     * @param string $html
     * @param string $css (optional) css to inline
     * @return string
     */
    public static function inline_styles($html, $css = '')
    {
        $domDocument = CssInliner::fromHtml($html)->inlineCss($css)->getDomDocument();

        HtmlPruner::fromDomDocument($domDocument)->removeElementsWithDisplayNone();
        $html = CssToAttributeConverter::fromDomDocument($domDocument)
            ->convertCssToVisualAttributes()->render();

        return $html;
    }

    /**
     * @param array<string|int,string|null>|string|null|bool|Address $email
     * @return string|null
     */
    public static function stringify($email)
    {
        if (!$email || is_bool($email)) {
            return null;
        }
        if ($email instanceof Address) {
            if ($email->getName()) {
                return $email->getName() . ' <' . $email->getAddress() . '>';
            }
            return $email->getAddress();
        }
        if (is_array($email)) {
            return $email[1] . ' <' . $email[0] . '>';
        }
        return $email;
    }

    /**
     * @param array<mixed> $emails
     * @return string
     */
    public static function stringifyArray(array $emails)
    {
        $result = [];
        foreach ($emails as $email) {
            $result[] = self::stringify($email);
        }
        return implode(", ", $result);
    }

    /**
     * @param array<string|int,string|null>|string|null|bool $email
     * @return bool
     */
    public static function validate($email)
    {
        return boolval(filter_var(self::stringify($email), FILTER_VALIDATE_EMAIL));
    }

    /**
     * Convert an html email to a text email while keeping formatting and links
     *
     * @param string $content
     * @return string
     */
    public static function convert_html_to_text($content)
    {
        // Prevent styles to be included
        $content = preg_replace('/<style.*>([\s\S]*)<\/style>/i', '', $content);
        // Convert html entities to strip them later on
        $content = html_entity_decode($content);
        // Bold
        $content = str_ireplace(['<strong>', '</strong>', '<b>', '</b>'], "*", $content);
        // Replace links to keep them accessible
        $content = preg_replace('/<a[\s\S]href="(.*?)"[\s\S]*?>(.*?)<\/a>/i', '$2 ($1)', $content);
        // Replace new lines
        $content = str_replace(['<br>', '<br/>', '<br />'], "\r\n", $content);
        // Remove html tags
        $content = strip_tags($content);
        // Avoid lots of spaces
        $content = preg_replace('/^[\s][\s]+(\S)/m', "\n$1", $content);
        // Trim content so that it's nice
        $content = trim($content);
        return $content;
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
        $name = preg_match('/[\w\s\-\.]+/u', $rfc_email_string, $matches);
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

    /**
     * @deprecated
     * @param \SilverStripe\Control\Email\Email $Email
     * @return \Symfony\Component\Mime\Header\Headers
     */
    public static function getHeaders($Email)
    {
        return method_exists($Email, 'getSwiftMessage') ? $Email->getSwiftMessage()->getHeaders() : $Email->getHeaders();
    }
}
