<?php

namespace LeKoala\SparkPost\Test;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use LeKoala\SparkPost\EmailUtils;

/**
 * Test for EmailUtils
 *
 * @group SparkPost
 */
class EmailUtilsTest extends SapphireTest
{
    public function testDisplayName()
    {
        $arr = [
            // Standard emails
            "me@test.com" => "me",
            "mobius@test.com" => "mobius",
            "test_with-chars.in.it@test-ds.com.xyz" => "test_with-chars.in.it",
            // Rfc emails
            "Me <me@test.com>" => "Me",
            "Möbius <mobius@test.com>" => "Möbius",
            "John Smith <test_with-chars.in.it@test-ds.com.xyz>" => "John Smith",

        ];

        foreach ($arr as $k => $v) {
            $displayName = EmailUtils::get_displayname_from_rfc_email($k);
            $this->assertEquals($v, $displayName);
        }
    }

    public function testGetEmail()
    {
        $arr = [
            // Standard emails
            "me@test.com" => "me@test.com",
            "mobius@test.com" => "mobius@test.com",
            "test_with-chars.in.it@test-ds.com.xyz" => "test_with-chars.in.it@test-ds.com.xyz",
            // Rfc emails
            "Me <me@test.com>" => "me@test.com",
            "Möbius <mobius@test.com>" => "mobius@test.com",
            "John Smith <test_with-chars.in.it@test-ds.com.xyz>" => "test_with-chars.in.it@test-ds.com.xyz",

        ];

        foreach ($arr as $k => $v) {
            $email = EmailUtils::get_email_from_rfc_email($k);
            $this->assertEquals($v, $email);
        }
    }

    public function testInlineStyles()
    {
        if (!class_exists(\Pelago\Emogrifier::class)) {
            return $this->markTestIncomplete("Install Pelago\Emogrifier to run this test");
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><style type="text/css">.red {color:red;}</style></head>
<body><span class="red">red</span></body>
</html>
HTML;
        $result = <<<HTML
<!DOCTYPE html>
<html>
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>
<body><span class="red" style="color: red;">red</span></body>
</html>

HTML;

        $process = EmailUtils::inline_styles($html);
        $this->assertEquals($result, $process);
    }

    public function testConvertHtmlToText()
    {
        $someHtml = '   Some<br/>Text <a href="http://test.com">Link</a> <strong>End</strong>    ';

        $textResult = "Some\r\nText Link (http://test.com) End";

        $process = EmailUtils::convert_html_to_text($someHtml);

        $this->assertEquals($textResult, $process);
    }
}
