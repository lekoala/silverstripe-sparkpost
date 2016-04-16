<?php
// Autosetup if key is defined in _ss_environment or yml
if (defined('SPARKPOST_API_KEY') && SPARKPOST_API_KEY !== '') {
    SparkPostMailer::config()->api_key = SPARKPOST_API_KEY;
}
if (SparkPostMailer::config()->api_key) {
    SparkPostMailer::setAsMailer();
}