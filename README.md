SilverStripe SparkPost module
==================
Use SparkPost in SilverStripe.

Define in your _ss_environment.php file the following constant

    ```php
	define('SPARKPOST_API_KEY','YOUR_API_KEY_HERE');
    ```

or by defining the api key in your config.yml

   ```yaml
   SparkPostMailer:
     api_key: 'YOUR_API_KEY_HERE'
   ```

This module uses a custom client (not the official PHP SDK).

You can also autoconfigure the module with the following constants in your _ss_environment.php

	define('SPARKPOST_ENABLE_LOGGING',true); // Will log emails in the temp folders
	define('SPARKPOST_SENDING_DISABLED',true); // Will disable sending (useful in development)

By defining the Api Key, the module will register a new mailer that will be used to send all emails.

Register the new mailer
==================

If you define the SPARKPOST_API_KEY constant, the mailer will be automatically registered.

Otherwise, you need to call the following line:

    ```php
    SparkPostMailer::setAsMailer();
    ```

One small difference from the original mailer is that it will return a fifth argument
for successful emails with the result of the call to the SparkPost api.

Using custom headers
==================

You can pass custom headers to the api by specifying any number of additionnal arguments
in X-SparkPostMailer custom header.

As a convenience, you can also directly use the following custom headers : Campaign, Metadata, Description.

    ```php
    $email->addCustomHeader('Metadata', ['ID' => $this->ID]);
    ```

Subaccounts support
==================

If you use a master api key, but need to [limit data access] (https://developers.sparkpost.com/api/#/introduction/subaccounts),
you can configure a subaccount id

    define('SPARKPOST_SUBACCOUNT_ID',1234);

or through the YML config.

SparkPost integration
==================

This module create a new admin section that allows you to:

- List all messages events and allow searching them
- Have a settings tab to list and configure sending domains and webhook

NOTE : Make sure that you have a valid api key (not a subaccount key) to access
features related to installation of the webhook through the CMS.

Handling errors
==================

The mailer behaves like the default mailer. This means that if sending fails,
"false" will be returned.

Any error will be logged. If you want to access the error, you can follow this
kind of approach:

    $result = $email->send();
    if ($result) {
      $this->sessionMessage("Message has been sent", 'good');
    } else {
      $this->sessionMessage("Sending failed:" . SparkPostMailer::getInstance()->getLastException()->getMessage(), 'bad');
    }

Webhooks
==================

From the SparkPost Admin, you can setup a webhook for your website. This webhook
will be called and SparkPostController will take care of handling all events
for you.

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

You can test if your extension is working properly by visiting /sparkpost/test
if your site is in dev mode. It will load sample data from the API.

Please ensure that the url for the webhook is properly configured if required
by using the following configuration

    SparkPostAdmin:
      webhook_base_url: 'https://my.domain.com/'

You can also define the following constant to log all incoming payload into a given
directory. Make sure the directory exists. It is relative to your base folder.

    define('SPARKPOST_WEBHOOK_LOG_DIR','_incoming');

Please also pay attention to the fact that the webhook is called for ALL events
of your SparkPost account, regardless of the fact of which API key generated the transmission.

To help you overcome this, if a subaccount id is defined, events will be filtered according
to this subaccount.

Preventing spam
==================

- Make sure you have properly configured your SPF and DKIM records for your domain.
- Create a [DMARC record] (https://www.unlocktheinbox.com/dmarcwizard/)
- Leave provide_plain option to true or provide plain content for your emails
- Use [Mail Tester] (http://www.mail-tester.com/) to troubleshoot your issues

Inlining styles
==================

Although SparkPost can inline styles for you, it may not work properly for complex
style sheet, such as Foundation Emails. The following config can help you with this
issue.

   ```yaml
   SparkPostMailer:
     inline_styles: true
   ```

It require the use of pelago\emogrifier so please install it if you plan to use this option.

Compatibility
==================
Tested with 3.x

Maintainer
==================
LeKoala - thomas@lekoala.be
