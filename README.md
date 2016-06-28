SilverStripe SparkPost module
==================
Use SparkPost in SilverStripe

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

Subaccounts support
==================

If you use a master api key, but need to [limit data access] (https://developers.sparkpost.com/api/#/introduction/subaccounts),
you can configure a subaccount id

        define('SPARKPOST_SUBACCOUNT_ID',1234);

or through the YML config.

SparkPost integration
==================

This module create a new admin section that allows you to:

- List all messages events
- Have a settings tab to configure domain and webhook

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
      $this->sessionMessage("Sending failed:" . SparkPostMailer::getLastException()->getMessage(), 'bad');
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

Compatibility
==================
Tested with 3.x

Maintainer
==================
LeKoala - thomas@lekoala.be