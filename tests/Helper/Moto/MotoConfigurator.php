<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Moto;

use RuntimeException;

/**
 * Posts runtime configuration to a running motoserver instance.
 *
 * See https://docs.getmoto.org/en/stable/docs/configuration/ — the moto-api
 * endpoints accept JSON to flip features on/off at runtime.
 */
final class MotoConfigurator
{
    /** @var string */
    private $endpoint;

    public function __construct(string $endpoint)
    {
        $this->endpoint = rtrim($endpoint, '/');
    }

    /**
     * Enable actual state machine execution for Step Functions.
     *
     * Without this, moto's StartExecution returns a stub response and never
     * walks the state machine.
     */
    public function enableStepFunctionsExecution(): void
    {
        $this->postJson('/moto-api/config', [
            'stepfunctions' => ['execute_state_machine' => true],
            'lambda' => ['use_docker' => false],
        ]);
    }

    /**
     * Wipe all moto state. Required between tests because moto's Step
     * Functions execution mode hits a deepcopy crash when StartExecution
     * is issued against a state machine that has already executed: the
     * cached state machine carries a non-picklable RLock from the prior
     * run (moto/stepfunctions/parser/models.py:175).
     */
    public function reset(): void
    {
        $this->postJson('/moto-api/reset', []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $path, array $body): void
    {
        $url = $this->endpoint . $path;
        $json = json_encode($body);
        if ($json === false) {
            throw new RuntimeException('Failed to encode moto config payload as JSON');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new RuntimeException(sprintf('Failed to POST to motoserver at %s', $url));
        }

        // ignore_errors=true makes file_get_contents return the body for 4xx/5xx
        // too, so inspect $http_response_header to surface server-side errors.
        $headers = isset($http_response_header) ? $http_response_header : [];
        $statusCode = self::parseStatusCode($headers);
        if ($statusCode === null || $statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf(
                'motoserver POST %s returned status %s: %s',
                $url,
                $statusCode === null ? 'unknown' : (string) $statusCode,
                $result
            ));
        }
    }

    /**
     * Return the final response's status code from $http_response_header.
     * The variable accumulates headers across the entire redirect chain, so
     * iterate from the end to skip past any 3xx hops.
     *
     * @param array<int, string> $headers Raw HTTP response headers from $http_response_header
     */
    private static function parseStatusCode(array $headers): ?int
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }
        return null;
    }
}
