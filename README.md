# SilverStripe SparkPost module

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

## Register the new mailer

If you define the SPARKPOST_API_KEY variable, the mailer transport will be automatically registered.

Otherwise, you need to call the following line:

    ```php
    SparkPostHelper::registerTransport();
    ```

## Subaccounts support

If you use a master api key, but need to [limit data access] (https://developers.sparkpost.com/api/#/introduction/subaccounts),
you can configure a subaccount id

    'SPARKPOST_SUBACCOUNT_ID=1234;

or through the YML config.

## SparkPost integration

This module create a new admin section that allows you to:

- List all messages events and allow searching them
- Have a settings tab to list and configure sending domains and webhook

NOTE : Make sure that you have a valid api key (not a subaccount key) to access
features related to installation of the webhook through the CMS.

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

    LeKoala\SparkPost\SparkPostAdmin:
      webhook_base_url: 'https://my.domain.com/'

You can also define the following environment variable to log all incoming payload into a given
directory. Make sure the directory exists. It is relative to your base folder.

    SPARKPOST_WEBHOOK_LOG_DIR='_incoming'

Please also pay attention to the fact that the webhook is called for ALL events
of your SparkPost account, regardless of the fact of which API key generated the transmission.

To help you overcome this, if a subaccount id is defined, events will be filtered according
to this subaccount.

## Preventing spam

- Make sure you have properly configured your SPF and DKIM records for your domain.
- Create a [DMARC record] (https://www.unlocktheinbox.com/dmarcwizard/)
- Leave provide_plain option to true or provide plain content for your emails
- Use [Mail Tester] (http://www.mail-tester.com/) to troubleshoot your issues

## Inlining styles

Although SparkPost can inline styles for you, it may not work properly for complex
style sheet, such as Foundation Emails. The following config can help you with this
issue.

   ```yaml
   LeKoala\SparkPost\SparkPostHelper:
     inline_styles: true
   ```

It require the use of pelago\emogrifier so please install it if you plan to use this option.

## Compatibility
Tested with 4.0

For 3.x compatibility, use branch 1

## Maintainer
LeKoala - thomas@lekoala.be
