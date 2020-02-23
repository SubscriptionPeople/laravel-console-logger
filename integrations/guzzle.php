<?php

use Illuminated\Console\Exceptions\RuntimeException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use function GuzzleHttp\Promise\rejection_for;

if (!function_exists('iclogger_guzzle_middleware')) {
    /**
     * Create a Guzzle middleware to provide logging of Guzzle requests/responses.
     *
     * @see https://github.com/dmitry-ivanov/laravel-console-logger#guzzle-6-integration
     * @see http://docs.guzzlephp.org/en/stable/handlers-and-middleware.html
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param string $type
     * @param callable|null $shouldLogRequest
     * @param callable|null $shouldLogResponse
     * @return \Closure
     */
    function iclogger_guzzle_middleware(LoggerInterface $logger, string $type = 'raw', callable $shouldLogRequest = null, callable $shouldLogResponse = null)
    {
        return function (callable $handler) use ($logger, $type, $shouldLogRequest, $shouldLogResponse) {
            return function (RequestInterface $request, array $options) use ($handler, $logger, $type, $shouldLogRequest, $shouldLogResponse) {
                // Gather information about the request
                $method = (string) $request->getMethod();
                $uri = (string) $request->getUri();
                $body = (string) $request->getBody();

                // Log the request with a proper message and context
                if (isset($shouldLogRequest) && !$shouldLogRequest($request)) {
                    $message = "[{$method}] Calling `{$uri}`, body is not shown, according to the custom logic.";
                    $context = [];
                } else if (empty($body)) {
                    $message = "[{$method}] Calling `{$uri}`.";
                    $context = [];
                } else {
                    $message = "[{$method}] Calling `{$uri}` with body:";
                    switch ($type) {
                        case 'json':
                            $context = json_decode($body, true);
                            break;

                        case 'raw':
                        default:
                            $message .= "\n{$body}";
                            $context = [];
                            break;
                    }
                }
                $logger->info($message, $context);

                // Using another callback to log the response
                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $logger, $type, $shouldLogResponse) {
                        // Gather information about the response
                        $body = (string) $response->getBody();
                        $code = $response->getStatusCode();

                        // Log the response with a proper message and context
                        if (isset($shouldLogResponse) && !$shouldLogResponse($request, $response)) {
                            $message = "[{$code}] Response is not shown, according to the custom logic.";
                            $context = [];
                        } else {
                            $message = "[{$code}] Response:";
                            switch ($type) {
                                case 'json':
                                    $context = is_json($body, true);
                                    if (empty($context)) {
                                        throw new RuntimeException('Bad response, JSON expected.', ['response' => $body]);
                                    }
                                    break;

                                case 'raw':
                                default:
                                    $message .= "\n{$body}";
                                    $context = [];
                                    break;
                            }
                            // Save the parsed body of response, so that it could be re-used instead of double decoding
                            if (!empty($context)) {
                                $response->iclParsedBody = $context;
                            }
                        }
                        $logger->info($message, $context);

                        return $response;
                    },
                    function ($reason) {
                        return rejection_for($reason);
                    }
                );
            };
        };
    }
}
