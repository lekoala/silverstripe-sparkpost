<?php

use LeKoala\SparkPost\SparkPostHelper;

// Prevent error if somehow class is not loaded
if (class_exists(SparkPostHelper::class)) {
    SparkPostHelper::init();
}
