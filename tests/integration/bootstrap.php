<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\AiClient;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->usePutenv(true);

$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $dotenv->load($envFile);
}

AiClient::defaultRegistry()->registerProvider(AiGatewayProvider::class);
