<?php

require_once __DIR__ . '/db/bootstrap.php';

global $domainhosts;

if ($domainhosts !== '') {
    telegram('setwebhook', mirzaTelegramWebhookParameters("https://$domainhosts/index.php"));
}
