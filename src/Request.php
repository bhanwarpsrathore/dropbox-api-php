<?php

declare(strict_types=1);

namespace DropboxAPI;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GrahamCampbell\GuzzleFactory\GuzzleFactory;

class Request {

    public const AUTHORIZE_URL = 'https://www.dropbox.com/oauth2/authorize';
    public const TOKEN_URL = 'https://api.dropbox.com/oauth2';
    public const RPC_ENDPOINT = 'https://api.dropboxapi.com/2';
    public const CONTENT_ENDPOINT = 'https://content.dropboxapi.com/2';

    protected ClientInterface $client;

    protected array $lastResponse = [];

    /**
     * Constructor
     * Set client.
     *
     * @param ClientInterface $client Optional. Client to set.
     */
    public function __construct(ClientInterface $client = null) {
        $this->client = $client ?? new Client(['handler' => GuzzleFactory::handler()]);
    }

    /**
     * Handle response errors.
     *
     * @param string $body The raw, unparsed response body.
     * @param int $status The HTTP status code, passed along to any exceptions thrown.
     *
     * @throws DropboxAPIException
     * @throws DropboxAPIAuthException
     *
     * @return void
     */
    protected function handleResponseError(string $body, int $status): void {
        $parsedBody = json_decode($body);
        $error = $parsedBody->error ?? null;
        $error_tag = !empty($error->{'.tag'}) ? $error->{'.tag'} : null;
        $error_summary = $parsedBody->error_summary ?? null;

        $user_message = $parsedBody->user_message ?? null;
        if (!empty($user_message)) {
            $error_summary = $user_message;
        }

        if ($error_summary) {
            // It's an API call error
            throw  new DropboxAPIException($this->parseErrorTag($error_tag, $error_summary), $status);
        } elseif (isset($parsedBody->error_description)) {
            // It's an auth call error
            throw  new DropboxAPIAuthException($parsedBody->error_description, $status);
        } elseif (isset($parsedBody->error) && is_string($parsedBody->error)) {
            // It's an auth call error
            throw  new DropboxAPIAuthException($parsedBody->error, $status);
        } else {
            // Something went really wrong, we don't know what
            throw new DropboxAPIException('An unknown error occurred.', $status);
        }
    }

    protected function parseErrorTag($tag, $summary = null) {
        $error_tags = [
            // AuthError
            'invalid_access_token' => 'The access token is invalid.',
            'invalid_select_user' => 'The user specified in \'Dropbox-API-Select-User\' is no longer on the team.',
            'invalid_select_admin' => 'The user specified in \'Dropbox-API-Select-Admin\' is not a Dropbox Business team admin.',
            'user_suspended' => 'The user has been suspended.',
            'expired_access_token' => 'The access token has expired.',
            'missing_scope' => 'The access token does not have the required scope to access the route.',
            'route_access_denied' => 'The route is not available to public.'
        ];

        return isset($error_tags[$tag]) ? $error_tags[$tag] : $summary ?? $tag;
    }

    /**
     * Make a request to the "token" endpoint.
     *
     * @param string $method The HTTP method to use.
     * @param string $uri The URI to request.
     * @param array $options
     *
     * @throws DropboxAPIException
     * @throws DropboxAPIAuthException
     *
     * @return array Response data.
     * - array body The response body.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function token(string $method, string $uri, array $options): array {
        return $this->send($method, self::TOKEN_URL . $uri, $options);
    }

    /**
     * Make a request to the RPC endpoints.
     *
     * @param string $method The HTTP method to use.
     * @param string $uri The URI to request.
     * @param array $options
     *
     * @throws DropboxAPIException
     * @throws DropboxAPIAuthException
     *
     * @return array Response data.
     * - array body The response body.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function rpcApi(string $method, string $uri, array $options): array {
        return $this->send($method, self::RPC_ENDPOINT . $uri, $options);
    }

    /**
     * Make a request to the Content endpoints.
     *
     * @param string $method The HTTP method to use.
     * @param string $uri The URI to request.
     * @param array $options
     *
     * @throws DropboxAPIException
     * @throws DropboxAPIAuthException
     *
     * @return array Response data.
     * - array body The response body.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function contentApi(string $method, string $uri, array $options = []): array {
        return $this->send($method, self::CONTENT_ENDPOINT . $uri, $options);
    }

    /**
     * Get the latest full response from the Dropbox API.
     *
     * @return array Response data.
     * - array body The response body.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function getLastResponse(): array {
        return $this->lastResponse;
    }

    /**
     * Make a request to Dropbox.
     * You'll probably want to use one of the convenience methods instead.
     *
     * @param string $method The HTTP method to use.
     * @param string $url The URL to request.
     * @param array $options
     *
     * @throws DropboxAPIException
     * @throws DropboxAPIAuthException
     *
     * @return array Response data.
     * - array body The response body.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function send(string $method, string $url, array $options): array {
        // Reset any old responses
        $this->lastResponse = [];

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (ClientException $exception) {
            $this->handleResponseError($exception->getResponse()->getBody()->getContents(), $exception->getResponse()->getStatusCode());
        }

        $body = $parsedBody = $response->getBody();
        $status = $response->getStatusCode();
        $parsedHeaders = $response->getHeaders();

        if (in_array('application/json', $response->getHeader('Content-Type'))) {
            $parsedBody = json_decode($body->getContents(), true);
        }

        $this->lastResponse = [
            'body' => $parsedBody,
            'headers' => $parsedHeaders,
            'status' => $status,
            'url' => $url,
        ];

        return $this->lastResponse;
    }
}
