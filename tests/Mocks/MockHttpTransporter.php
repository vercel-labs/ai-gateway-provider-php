<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Mocks;

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

class MockHttpTransporter implements HttpTransporterInterface
{
    /** @var Response */
    private $response;

    /** @var Request|null */
    private $lastRequest;

    /** @var RequestOptions|null */
    private $lastOptions;

    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        $this->lastRequest = $request;
        $this->lastOptions = $options;

        return $this->response;
    }

    public function getLastRequest(): ?Request
    {
        return $this->lastRequest;
    }

    public function getLastOptions(): ?RequestOptions
    {
        return $this->lastOptions;
    }
}
