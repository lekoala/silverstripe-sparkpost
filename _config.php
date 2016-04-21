<?php
// Autosetup constants defined in _ss_environment
if (defined('SPARKPOST_API_KEY') && SPARKPOST_API_KEY !== '') {
    SparkPostMailer::config()->api_key = SPARKPOST_API_KEY;
}
if (defined('SPARKPOST_SENDING_DISABLED')) {
    SparkPostMailer::config()->disable_sending = SPARKPOST_SENDING_DISABLED;
}
if (defined('SPARKPOST_ENABLE_LOGGING')) {
    SparkPostMailer::config()->enable_logging = SPARKPOST_ENABLE_LOGGING;
}
if (defined('SPARKPOST_SUBACCOUNT_ID')) {
    SparkPostMailer::config()->subaccount_id = SPARKPOST_SUBACCOUNT_ID;
}
// Register as mailer if api key is set
if (SparkPostMailer::config()->api_key) {
    SparkPostMailer::setAsMailer();
}