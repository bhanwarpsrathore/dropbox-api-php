<?php

declare(strict_types=1);

namespace DropboxAPI;

class Session {

    protected string $clientId = '';
    protected string $clientSecret = '';
    protected string $redirectUri = '';
    protected string $accessToken = '';
    protected string $refreshToken = '';
    protected int $expirationTime = 0;
    protected string $scope = '';
    protected string $account_id = '';
    protected string $team_id = '';
    protected string $id_token = '';

    protected ?Request $request = null;

    /**
     * Constructor
     * Set up client credentials.
     *
     * @param string $clientId The client ID.
     * @param string $clientSecret Optional. The client secret.
     * @param string $redirectUri Optional. The redirect URI.
     * @param Request $request Optional. The Request object to use.
     */
    public function __construct(
        string $clientId,
        string $clientSecret = '',
        string $redirectUri = '',
        ?Request $request = null
    ) {
        $this->setClientId($clientId);
        $this->setClientSecret($clientSecret);
        $this->setRedirectUri($redirectUri);

        $this->request = $request ?? new Request();
    }

    /**
     * Generate a code challenge from a code verifier for use with the PKCE flow.
     *
     * @param string $codeVerifier The code verifier to create a challenge from.
     * @param string $hashAlgo Optional. The hash algorithm to use. Defaults to "sha256".
     *
     * @return string The code challenge.
     */
    public function generateCodeChallenge(string $codeVerifier, string $hashAlgo = 'sha256'): string {
        $challenge = hash($hashAlgo, $codeVerifier, true);
        $challenge = base64_encode($challenge);
        $challenge = strtr($challenge, '+/', '-_');
        $challenge = rtrim($challenge, '=');

        return $challenge;
    }

    /**
     * Generate a code verifier for use with the PKCE flow.
     *
     * @param int $length Optional. Code verifier length. Must be between 43 and 128 characters long, default is 128.
     *
     * @return string A code verifier string.
     */
    public function generateCodeVerifier(int $length = 128): string {
        return $this->generateState($length);
    }

    /**
     * Generate a random state value.
     *
     * @param int $length Optional. Length of the state. Default is 16 characters.
     *
     * @return string A random state value.
     */
    public function generateState(int $length = 16): string {
        // Length will be doubled when converting to hex
        return bin2hex(
            random_bytes($length / 2)
        );
    }

    /**
     * Get the authorization URL.
     *
     * @param array|object $options Optional. Options for the authorization URL.
     * - string code_challenge Optional. A PKCE code challenge.
     * - array scope Optional. Scope(s) to request from the user. This parameter allows your user to authorize a subset of the scopes selected in the App Console. If this parameter is omitted, the authorization page will request all scopes selected on the Permissions tab.
     * - string state Optional. A CSRF token.
     * - for more details visit - https://www.dropbox.com/developers/documentation/http/documentation
     *
     * @return string The authorization URL.
     */
    public function getAuthorizeUrl(array|object $options = []): string {
        $options = (array) $options;

        $parameters = [
            'client_id' => $this->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code'
        ];

        // State
        if (isset($options['state'])) {
            $parameters['state'] = $options['state'];
        }

        // Scope
        if (isset($options['scope'])) {
            $parameters['scope'] = implode(' ', $options['scope']);
        }

        // Include granted scopes
        if (isset($options['include_granted_scopes'])) {
            $parameters['include_granted_scopes'] = $options['include_granted_scopes'];
        }

        // Token access type
        if (isset($options['token_access_type'])) {
            $parameters['token_access_type'] = $options['token_access_type'];
        }

        // Set some extra parameters for PKCE flows
        if (isset($options['code_challenge'])) {
            $parameters['code_challenge'] = $options['code_challenge'];
            $parameters['code_challenge_method'] = $options['code_challenge_method'] ?? 'S256';
        }

        return Request::AUTHORIZE_URL . '?' . http_build_query($parameters, '', '&');
    }

    /**
     * Get the client ID.
     *
     * @return string The client ID.
     */
    public function getClientId(): string {
        return $this->clientId;
    }

    /**
     * Get the client secret.
     *
     * @return string The client secret.
     */
    public function getClientSecret(): string {
        return $this->clientSecret;
    }

    /**
     * Get the client's redirect URI.
     *
     * @return string The redirect URI.
     */
    public function getRedirectUri(): string {
        return $this->redirectUri;
    }

    /**
     * Get the access token.
     *
     * @return string The access token.
     */
    public function getAccessToken(): string {
        return $this->accessToken;
    }

    /**
     * Get the refresh token.
     *
     * @return string The refresh token.
     */
    public function getRefreshToken(): string {
        return $this->refreshToken;
    }

    /**
     * Get the access token expiration time.
     *
     * @return int A Unix timestamp indicating the token expiration time.
     */
    public function getTokenExpiration(): int {
        return $this->expirationTime;
    }

    /**
     * Get the scope for the current access token
     *
     * @return array The scope for the current access token
     */
    public function getScope(): array {
        return explode(' ', $this->scope);
    }

    /**
     * Get the account ID for the current access token
     * 
     * @return string The account ID for the current access token
     */
    public function getAccountId(): string {
        return $this->account_id;
    }

    /**
     * Get the team ID for the current access token
     * 
     * @return string The team ID for the current access token
     */
    public function getTeamId(): string {
        return $this->team_id;
    }

    /**
     * Get the ID token for the current access token
     * 
     * @return string The ID token for the current access token
     */
    public function getIdToken(): string {
        return $this->id_token;
    }

    /**
     * Refresh an access token.
     *
     * @param string $refreshToken Optional. The refresh token to use.
     *
     * @return bool Whether the access token was successfully refreshed.
     */
    public function refreshAccessToken(?string $refreshToken = null): bool {
        $parameters = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken ?? $this->refreshToken,
            'client_id' => $this->getClientId(),
        ];

        if ($this->getClientSecret()) {
            $parameters['client_secret'] = $this->getClientSecret();
        }

        ['body' => $response] = $this->request->token('POST', '/token', [
            'form_params' => $parameters
        ]);

        if (isset($response["access_token"])) {
            $this->accessToken = $response["access_token"];
            $this->expirationTime = time() + $response["expires_in"];
            $this->scope = isset($response["scope"]) ? $response["scope"] : $this->scope;

            if (empty($this->refreshToken)) {
                $this->refreshToken = $refreshToken;
            }

            return true;
        }

        return false;
    }

    /**
     * Request an access token given an authorization code.
     *
     * @param string $authorizationCode The authorization code from Dropbox.
     * @param string $codeVerifier Optional. A previously generated code verifier. Will assume a PKCE flow if passed.
     *
     * @return bool True when the access token was successfully granted, false otherwise.
     */
    public function requestAccessToken(string $authorizationCode, string $codeVerifier = ''): bool {
        $parameters = [
            'client_id' => $this->getClientId(),
            'code' => $authorizationCode,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->getRedirectUri(),
        ];

        // Send a code verifier when PKCE, client secret otherwise
        if ($codeVerifier) {
            $parameters['code_verifier'] = $codeVerifier;
        } else {
            $parameters['client_secret'] = $this->getClientSecret();
        }

        ['body' => $response] = $this->request->token('POST', '/token', [
            'form_params' => $parameters
        ]);

        if (isset($response['access_token'])) {
            $this->accessToken = $response["access_token"];
            $this->refreshToken = isset($response["refresh_token"]) ? $response["refresh_token"] : '';
            $this->expirationTime = time() + $response["expires_in"];
            $this->scope = isset($response["scope"]) ? $response["scope"] : '';
            $this->account_id = isset($response["account_id"]) ? $response["account_id"] : '';
            $this->team_id = isset($response["team_id"]) ? $response["team_id"] : '';
            $this->id_token = isset($response["id_token"]) ? $response["id_token"] : '';

            return true;
        }

        return false;
    }

    /**
     * Set the access token.
     *
     * @param string $accessToken The access token
     *
     * @return self
     */
    public function setAccessToken(string $accessToken): self {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Set the client ID.
     *
     * @param string $clientId The client ID.
     *
     * @return self
     */
    public function setClientId(string $clientId): self {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Set the client secret.
     *
     * @param string $clientSecret The client secret.
     *
     * @return self
     */
    public function setClientSecret(string $clientSecret): self {
        $this->clientSecret = $clientSecret;

        return $this;
    }

    /**
     * Set the client's redirect URI.
     *
     * @param string $redirectUri The redirect URI.
     *
     * @return self
     */
    public function setRedirectUri(string $redirectUri): self {
        $this->redirectUri = $redirectUri;

        return $this;
    }

    /**
     * Set the session's refresh token.
     *
     * @param string $refreshToken The refresh token.
     *
     * @return self
     */
    public function setRefreshToken(string $refreshToken): self {
        $this->refreshToken = $refreshToken;

        return $this;
    }
}
