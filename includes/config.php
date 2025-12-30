<?php
@define('API_TOKEN', getenv('API_TOKEN') ?: 'changeme_dev_token');
@define('WEBHOOK_URL', getenv('WEBHOOK_URL') ?: '');
// Dynamische base URL, zodat alles ook in een submap kan draaien.
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') $basePath = '';
@define('BASE_PATH', $basePath);
@define('BASEDIR', $basePath); // I am useless

// Applicatie naam (zichtbaar in UI en PDF). Kan overschreven worden via env.
@define('APP_NAME', getenv('APP_NAME') ?: 'Fiscana');

// Leningverstrekker opties en adressen (kunnen via docker-compose env variabelen gezet worden)
@define('LENDER_COMPANY_NAME', getenv('LENDER_COMPANY_NAME') ?: 'Holding BV');
@define('LENDER_COMPANY_ADDRESS', getenv('LENDER_COMPANY_ADDRESS') ?: '');

@define('LENDER_PRIVATE_NAME', getenv('LENDER_PRIVATE_NAME') ?: 'Test Persoon');
@define('LENDER_PRIVATE_ADDRESS', getenv('LENDER_PRIVATE_ADDRESS') ?: '');

// Default lender type used when een lening geen expliciete instelling heeft: 'company' of 'private'
@define('DEFAULT_LENDER_TYPE', getenv('DEFAULT_LENDER_TYPE') ?: 'private');
