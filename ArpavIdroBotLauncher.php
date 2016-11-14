<?php
$botName = 'ArpavIdroBot';
cli_set_process_title($botName);
set_time_limit(0);
require_once $botName.'.php';
$botInstance = new $botName();
$botInstance->start();
