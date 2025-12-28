<?php
@define('API_TOKEN', getenv('API_TOKEN') ?: 'changeme_dev_token');
@define('WEBHOOK_URL', getenv('WEBHOOK_URL') ?: '');
// Dynamische base URL, zodat alles ook in een submap kan draaien.
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') $basePath = '';
@define('BASE_PATH', $basePath);
@define('BASEDIR', $basePath); // I am useless
