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

SparkPost integration
==================

This module create a new admin section that allows you to:

- List all messages events
- Have a settings tab to configure domain and webhook

Webhooks
==================

From the SparkPost Admin, you can setup a webhook for your website. This webhook
will be called and SparkPostController will take care of handling all events
for you.

By default, SparkPostController will do nothing. Feel free to add your own
extensions to SparkPostController to define your own rules, like "Send an
email to the admin when a receive a spam complaint".

SparkPostController provides 4 extensions points:
- updateHandleAnyEvent
- updateHandleSyncEvent
- updateHandleInboundEvent
- updateHandleMessageEvent

Compatibility
==================
Tested with 3.x

Maintainer
==================
LeKoala - thomas@lekoala.be