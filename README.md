# SilverStripe SparkPost module

[![Build Status](https://travis-ci.org/lekoala/silverstripe-sparkpost.svg?branch=master)](https://travis-ci.org/lekoala/silverstripe-sparkpost)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/lekoala/silverstripe-sparkpost/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-sparkpost/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/lekoala/silverstripe-sparkpost/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-sparkpost/?branch=master)
[![Build Status](https://scrutinizer-ci.com/g/lekoala/silverstripe-sparkpost/badges/build.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-sparkpost/build-status/master)
[![codecov.io](https://codecov.io/github/lekoala/silverstripe-sparkpost/coverage.svg?branch=master)](https://codecov.io/github/lekoala/silverstripe-sparkpost?branch=master)

[![Latest Stable Version](https://poser.pugx.org/lekoala/silverstripe-sparkpost/version)](https://packagist.org/packages/lekoala/silverstripe-sparkpost)
[![Latest Unstable Version](https://poser.pugx.org/lekoala/silverstripe-sparkpost/v/unstable)](//packagist.org/packages/lekoala/silverstripe-sparkpost)
[![Total Downloads](https://poser.pugx.org/lekoala/silverstripe-sparkpost/downloads)](https://packagist.org/packages/lekoala/silverstripe-sparkpost)
[![License](https://poser.pugx.org/lekoala/silverstripe-sparkpost/license)](https://packagist.org/packages/lekoala/silverstripe-sparkpost)
[![Monthly Downloads](https://poser.pugx.org/lekoala/silverstripe-sparkpost/d/monthly)](https://packagist.org/packages/lekoala/silverstripe-sparkpost)
[![Daily Downloads](https://poser.pugx.org/lekoala/silverstripe-sparkpost/d/daily)](https://packagist.org/packages/lekoala/silverstripe-sparkpost)

## Setup

Define in your .env file the following variable

	SPARKPOST_API_KEY='YOUR_API_KEY_HERE'

or by defining the api key in your config.yml

```yaml
LeKoala\SparkPost\SparkPostHelper:
    api_key: 'YOUR_API_KEY_HERE'
```

This module uses a custom client (not the official PHP SDK).

You can also autoconfigure the module with the following environment variables

    # Will log emails in the temp folders
    SPARKPOST_ENABLE_LOGGING=true
    # Will disable sending (useful in development)
	SPARKPOST_SENDING_DISABLED=true

By defining the Api Key, the module will register a new transport that will be used to send all emails.

If you're using the SparkPost EU service you can change the API endpoint

    # Will use https://api.eu.sparkpost.com/api/v1
    SPARKPOST_EU=true

## Register the new mailer

If you define the SPARKPOST_API_KEY variable, the mailer transport will be automatically registered.

Otherwise, you need to call the following line:

```php
SparkPostHelper::registerTransport();
```

## Subaccounts support

If you use a master api key, but need to [limit data access](https://developers.sparkpost.com/api/#/introduction/subaccounts),
you can configure a subaccount id

    SPARKPOST_SUBACCOUNT_ID=1234;

or through the YML config.

## SparkPost integration

This module create a new admin section that allows you to:

- List all messages events and allow searching them
- Have a settings tab to list and configure sending domains and webhook

NOTE : Make sure that you have a valid api key (not a subaccount key) to access
features related to installation of the webhook through the CMS.

## Setting tags or metadata

By using custom headers you can pass parameters to the api by following the
same principle than the SMTP api.

The main way to pass parameters is to add a json encoded string through the
X-MSYS-API header, but you can also use that Mandrill compatiblity layer.

For full details, look at the [documentation](https://developers.sparkpost.com/api/smtp/)

```php
$email = new Email();
$email->setSubject($sellerTitle . ' - Invoice - ' . $date);
$email->setBody($body);
// Through Mandrill compat layer
$email->getSwiftMessage()->getHeaders()->addTextHeader('X-MC-Metadata', json_encode(['RecordID' => $this->ID]));
// Or use M-SYS header
$email->getSwiftMessage()->getHeaders()->addTextHeader('X-MSYS-API', json_encode(['metadata' => ['RecordID' => $this->ID]]));
```

## Webhooks

From the SparkPost Admin, you can setup a webhook for your website. This webhook
will be called and SparkPostController will take care of handling all events
for you. It is registered under the __sparkpost/ route.

By default, SparkPostController will do nothing. Feel free to add your own
extensions to SparkPostController to define your own rules, like "Send an
email to the admin when a receive a spam complaint".

SparkPostController provides the following extension point for all events:
- onAnyEvent

And the following extensions points depending on the type of the event:
- onEngagementEvent
- onGenerationEvent
- onMessageEvent
- onUnsubscribeEvent

You can also inspect the whole payload and the batch id with
- beforeProcessPayload : to check if a payload has been processed
- afterProcessPayload : to mark the payload has been processed or log information

You can test if your extension is working properly by visiting /__sparkpost/test
if your site is in dev mode. It will load sample data from the API.

Please ensure that the url for the webhook is properly configured if required
by using the following configuration

```yaml
LeKoala\SparkPost\SparkPostAdmin:
    webhook_base_url: 'https://my.domain.com/'
```

You can also define the following environment variable to log all incoming payload into a given
directory. Make sure the directory exists. It is relative to your base folder.

    SPARKPOST_WEBHOOK_LOG_DIR='_incoming'

Please also pay attention to the fact that the webhook is called for ALL events
of your SparkPost account, regardless of the fact of which API key generated the transmission.

To help you overcome this, if a subaccount id is defined, events will be filtered according
to this subaccount.

## Preventing spam

- Make sure you have properly configured your [SPF](https://tools.sparkpost.com/spf/builder) and DKIM records for your domain.

    mydomain.com   TXT   "v=spf1 include:myauthorizeddomain.com include:sparkpostmail.com ~all”

- Create a [DMARC record](https://www.unlocktheinbox.com/dmarcwizard/)

    _dmarc.mydomain.com. 3600 IN TXT "v=DMARC1; p=none; sp=none; rf=afrf; pct=100; ri=86400"

- Leave provide_plain option to true or provide plain content for your emails
- Use [Mail Tester](http://www.mail-tester.com/) to troubleshoot your issues

## Inlining styles

Although SparkPost can inline styles for you, it may not work properly for complex
style sheet, such as Foundation Emails. This is why the package pelago\emogrifier
is not required by default and styles are inlined in php to get the best results.

If you want to restore built-in functionnality, use this:

```yaml
LeKoala\SparkPost\SparkPostHelper:
    inline_styles: false
    default_params:
    inlineCss: true
```

## Compatibility
Tested with 4.x

For 3.x compatibility, use branch 1

## Maintainer
LeKoala - thomas@lekoala.be
