<?php

/**
 * Trait Vercel\AiGatewayProvider\Models\WithProviderOptionsTrait
 *
 * @since n.e.x.t
 *
 * @package Vercel\AiGatewayProvider
 */

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Trait for unified provider options handling in gateway models.
 *
 * @since n.e.x.t
 */
trait WithProviderOptionsTrait
{
    abstract protected function getGatewayModelId(): string;

    /**
     * Amends the request body with provider options based on custom options.
     *
     * Any existing $requestBody['providerOptions'] value is used as the base.
     * The resulting providerOptions are written back into $requestBody['providerOptions']
     * if non-empty, or set to an empty stdClass otherwise.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $requestBody         The request body to amend.
     * @param array<string, mixed> $customOptions       The custom options to process.
     * @param list<string>         $knownTopLevelOptions Keys that should go directly into the request body.
     * @return array<string, mixed> The amended request body.
     *
     * @throws InvalidArgumentException If custom options are invalid or conflict.
     */
    protected function amendProviderOptions(
        array $requestBody,
        array $customOptions,
        array $knownTopLevelOptions = []
    ): array {
        /** @var array<string, mixed> $providerOptions */
        $providerOptions = isset($requestBody['providerOptions']) && is_array($requestBody['providerOptions'])
            ? $requestBody['providerOptions']
            : [];
        unset($requestBody['providerOptions']);

        $gatewayModelId = $this->getGatewayModelId();
        $slashPos = strpos($gatewayModelId, '/');
        $providerName = $slashPos !== false ? substr($gatewayModelId, 0, $slashPos) : $gatewayModelId;

        foreach ($customOptions as $key => $value) {
            if (in_array($key, $knownTopLevelOptions, true)) {
                $requestBody[$key] = $value;
            } elseif ($key === 'providerOptions') {
                if (!is_array($value)) {
                    throw new InvalidArgumentException(
                        'The "providerOptions" custom option must be an array.'
                    );
                }
                /** @var array<string, mixed> $value */
                foreach ($value as $subKey => $subValue) {
                    if (($subKey === $providerName || $subKey === 'gateway') && is_array($subValue)) {
                        /** @var array<string, mixed> $subValue */
                        $this->mergeNestedProviderOptions($providerOptions, (string) $subKey, $subValue);
                    } else {
                        if (array_key_exists((string) $subKey, $providerOptions)) {
                            throw new InvalidArgumentException(
                                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                                sprintf('Provider option "%s" conflicts with an existing value.', $subKey)
                            );
                        }
                        $providerOptions[(string) $subKey] = $subValue;
                    }
                }
            } elseif ($key === 'gateway' || $key === $providerName) {
                if (!is_array($value)) {
                    throw new InvalidArgumentException(
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                        sprintf('The "%s" custom option must be an array.', $key)
                    );
                }
                /** @var array<string, mixed> $value */
                $this->mergeNestedProviderOptions($providerOptions, $key, $value);
            } else {
                if (
                    isset($providerOptions[$providerName])
                    && is_array($providerOptions[$providerName])
                    && array_key_exists($key, $providerOptions[$providerName])
                ) {
                    throw new InvalidArgumentException(
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                        sprintf('Provider option "%s.%s" conflicts with an existing value.', $providerName, $key)
                    );
                }
                if (!isset($providerOptions[$providerName]) || !is_array($providerOptions[$providerName])) {
                    $providerOptions[$providerName] = [];
                }
                $providerOptions[$providerName][$key] = $value;
            }
        }

        $requestBody['providerOptions'] = !empty($providerOptions) ? $providerOptions : new \stdClass();

        return $requestBody;
    }

    /**
     * Merges nested values into a provider options key, throwing on conflicts.
     *
     * @param array<string, mixed> &$providerOptions The provider options array (by reference).
     * @param string               $key              The top-level key (provider name or 'gateway').
     * @param array<string, mixed> $values           The values to merge.
     *
     * @throws InvalidArgumentException If any inner key conflicts.
     */
    private function mergeNestedProviderOptions(array &$providerOptions, string $key, array $values): void
    {
        if (!isset($providerOptions[$key])) {
            $providerOptions[$key] = $values;
            return;
        }

        if (!is_array($providerOptions[$key])) {
            throw new InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                sprintf('Provider option "%s" conflicts with an existing non-array value.', $key)
            );
        }

        /** @var array<string, mixed> $existing */
        $existing = $providerOptions[$key];
        foreach ($values as $innerKey => $innerValue) {
            if (array_key_exists($innerKey, $existing)) {
                throw new InvalidArgumentException(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    sprintf('Provider option "%s.%s" conflicts with an existing value.', $key, $innerKey)
                );
            }
            $existing[$innerKey] = $innerValue;
        }
        $providerOptions[$key] = $existing;
    }
}
