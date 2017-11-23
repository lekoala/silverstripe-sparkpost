<?php
namespace LeKoala\SparkPost;

use \Exception;

class EmailUtils
{

    /**
     * Inline styles using Pelago Emogrifier
     *
     * @param string $html
     * @return string
     */
    public static function inline_styles($html)
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
