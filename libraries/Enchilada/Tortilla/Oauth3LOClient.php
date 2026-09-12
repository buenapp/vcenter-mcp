<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * OAuth 3LO client over the loop-aware HttpClient
 *
 * Drives token exchange/refresh through HttpClient, so the waits inherit
 * the host's regime: fiber park under a reactor, progress-emitting poll
 * when blocking. The OAuth protocol (PKCE, payloads, token persistence)
 * lives in \EnchiladaOAuth\Oauth3LO from Enchilada/Extras — this class
 * performs the two token POSTs and delegates everything else.
 *
 * The PKCE helpers are statics on \EnchiladaOAuth\Oauth3LO; call them
 * there (e.g. `\EnchiladaOAuth\Oauth3LO::generateCodeVerifier()`).
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class Oauth3LOClient
{
	/** @var HttpClient The loop-aware token HTTP transport */
	private HttpClient $http;

	/** @var \EnchiladaOAuth\Oauth3LO Protocol core (Extras OAuth/) */
	private \EnchiladaOAuth\Oauth3LO $oauth;

	/**
	 * @param HttpClient  $http                  HttpClient mounted on the token endpoint's origin
	 * @param string      $authorizationEndpoint Full authorization endpoint URL
	 * @param string      $tokenEndpoint         Full token endpoint URL (or relative to the HTTP base)
	 * @param string      $clientId              OAuth2 client_id
	 * @param string      $clientSecret          OAuth2 client_secret
	 * @param string      $redirectUri           Registered redirect URI
	 * @param string|null $scope                 Space-separated scope string
	 * @param string      $tokenFile             Path to file for persisting tokens
	 */
	public function __construct(
		HttpClient $http,
		string $authorizationEndpoint,
		string $tokenEndpoint,
		string $clientId,
		string $clientSecret,
		string $redirectUri,
		?string $scope = null,
		string $tokenFile = ''
	) {
		$this->http = $http;
		$this->oauth = new \EnchiladaOAuth\Oauth3LO(
			$authorizationEndpoint,
			$tokenEndpoint,
			$clientId,
			$clientSecret,
			$redirectUri,
			$scope,
			$tokenFile
		);
	}

	/**
	 * Update the redirect URI (e.g., when using a dynamic port from OAuthCallbackServer).
	 */
	public function setRedirectUri(string $redirectUri): void
	{
		$this->oauth->setRedirectUri($redirectUri);
	}

	/**
	 * The raw authorization-endpoint URL, for consumers that assemble the
	 * full URL themselves from getAuthorizationParams().
	 */
	public function getAuthorizationEndpoint(): string
	{
		return $this->oauth->getAuthorizationEndpoint();
	}

	/**
	 * The authorization-flow query parameters.
	 */
	public function getAuthorizationParams(string $codeChallenge, ?string $state = null): array
	{
		return $this->oauth->getAuthorizationParams($codeChallenge, $state);
	}

	/**
	 * Build the authorization URL for the consent flow.
	 */
	public function buildAuthorizationUrl(string $codeChallenge, ?string $state = null): string
	{
		return $this->oauth->buildAuthorizationUrl($codeChallenge, $state);
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @return array Token response (access_token, refresh_token, expires_in, scope)
	 * @throws \Exception If the exchange fails
	 */
	public function exchangeCode(string $code, string $codeVerifier): array
	{
		$response = $this->http->call($this->oauth->getTokenEndpointPath(),
			http_build_query($this->oauth->buildExchangePayload($code, $codeVerifier)), 'POST',
			['Content-Type: application/x-www-form-urlencoded'], null, 'json');

		return $this->oauth->handleExchangeResponse($response);
	}

	/**
	 * Returns a valid access token, refreshing if necessary.
	 *
	 * Re-reads the token file from disk if no valid in-memory token exists.
	 * This handles the case where tokens were obtained by an external process
	 * (e.g., CLI auth tool) after this instance was constructed.
	 *
	 * @return string
	 * @throws \Exception When no valid token is available and refresh fails.
	 */
	public function getAccessToken(): string
	{
		$token = $this->oauth->getFreshAccessToken();
		if ($token !== null) {
			return $token;
		}

		if ($this->oauth->hasRefreshToken()) {
			return $this->refreshAccessToken();
		}

		// Try re-reading from disk — tokens may have been written by another process
		$this->oauth->reloadTokens();

		$token = $this->oauth->getFreshAccessToken();
		if ($token !== null) {
			return $token;
		}

		if ($this->oauth->hasRefreshToken()) {
			return $this->refreshAccessToken();
		}

		throw new \Exception('No valid access token available. Run the authorization flow first.');
	}

	/**
	 * Apply the Authorization header to the provided headers array.
	 *
	 * @param array $headers Existing headers (array of "Header: value" strings)
	 * @return array Updated headers including Authorization
	 */
	public function applyAuthorizationHeader(array $headers = []): array
	{
		$headers[] = 'Authorization: Bearer ' . $this->getAccessToken();
		return $headers;
	}

	/**
	 * Check if we have a valid (non-expired) token or a refresh token.
	 */
	public function isAuthenticated(): bool
	{
		return $this->oauth->isAuthenticated();
	}

	/**
	 * Get the current refresh token (e.g., for inspection or manual persistence).
	 */
	public function getRefreshToken(): ?string
	{
		return $this->oauth->getRefreshToken();
	}

	/**
	 * Get the scope string that was granted during the last authorization.
	 */
	public function getGrantedScope(): ?string
	{
		return $this->oauth->getGrantedScope();
	}

	/**
	 * Refresh the access token using the stored refresh token.
	 */
	private function refreshAccessToken(): string
	{
		$response = $this->http->call($this->oauth->getTokenEndpointPath(),
			http_build_query($this->oauth->buildRefreshPayload()), 'POST',
			['Content-Type: application/x-www-form-urlencoded'], null, 'json');

		return $this->oauth->handleRefreshResponse($response);
	}
}
