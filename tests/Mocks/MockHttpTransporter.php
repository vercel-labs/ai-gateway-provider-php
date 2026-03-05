<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Mocks;

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

class MockHttpTransporter implements HttpTransporterInterface
{
    /** @var Response[] */
    private $responses;

    /** @var int */
    private $callIndex = 0;

    /** @var Request[] */
    private $requests = [];

    /** @var RequestOptions[] */
    private $options = [];

    public function __construct(Response ...$responses)
    {
        $this->responses = $responses;
    }

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        $this->requests[] = $request;
        $this->options[] = $options;

        $index = min($this->callIndex, count($this->responses) - 1);
        $this->callIndex++;

        return $this->responses[$index];
    }

    public function getLastRequest(): ?Request
    {
        return $this->requests ? $this->requests[count($this->requests) - 1] : null;
    }

    public function getLastOptions(): ?RequestOptions
    {
        return $this->options ? $this->options[count($this->options) - 1] : null;
    }

    public function getRequest(int $index): ?Request
    {
        return $this->requests[$index] ?? null;
    }
}
