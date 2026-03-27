<?php

namespace WpOidc;

use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Service\Builder\UserInfoServiceBuilder;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OIDC Client wrapper for Keycloak integration
 */
class OidcClient {

	private string $issuer;
	private string $client_id;
	private string $client_secret;
	private string $redirect_uri;
	private ?ClientInterface $client = null;

	/**
	 * Initialize OIDC Client
	 *
	 * @param string $issuer        Keycloak realm URL
	 * @param string $client_id     OAuth client ID
	 * @param string $client_secret OAuth client secret
	 * @param string $redirect_uri  Callback URL
	 */
	public function __construct( string $issuer, string $client_id, string $client_secret, string $redirect_uri ) {
		$this->issuer        = $issuer;
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->redirect_uri  = $redirect_uri;
	}

	/**
	 * Get authorization URL for redirecting user to Keycloak
	 *
	 * @return string Authorization URL
	 */
	public function get_authorization_url(): string {
		$state = bin2hex( random_bytes( 16 ) );
		$nonce = bin2hex( random_bytes( 16 ) );

		// Store state and nonce in session
		if ( session_status() === PHP_SESSION_NONE ) {
			session_start();
		}
		$_SESSION['wp_oidc_state'] = $state;
		$_SESSION['wp_oidc_nonce'] = $nonce;

		$authorization_service = ( new AuthorizationServiceBuilder() )->build();

		return $authorization_service->getAuthorizationUri(
			$this->get_client(),
			[
				'state' => $state,
				'nonce' => $nonce,
				'scope' => 'openid email profile',
			]
		);
	}

	/**
	 * Handle OIDC callback - exchange code for tokens and get user info
	 *
	 * @param array $query_params Query parameters from callback URL
	 * @param ServerRequestInterface|null $server_request PSR-7 server request (optional)
	 *
	 * @return array User info with email
	 * @throws \Exception If callback validation fails
	 */
	public function handle_callback( array $query_params, ?ServerRequestInterface $server_request = null ): array {
		if ( session_status() === PHP_SESSION_NONE ) {
			session_start();
		}

		// Verify state parameter
		$stored_state = $_SESSION['wp_oidc_state'] ?? null;
		$callback_state = $query_params['state'] ?? null;

		if ( ! $stored_state || $stored_state !== $callback_state ) {
			throw new \Exception( 'Invalid state parameter' );
		}

		// Check for error response
		if ( ! empty( $query_params['error'] ) ) {
			throw new \Exception( 'OIDC Error: ' . $query_params['error'] . ' - ' . ( $query_params['error_description'] ?? '' ) );
		}

		// Get authorization code
		$code = $query_params['code'] ?? null;
		if ( ! $code ) {
			throw new \Exception( 'Missing authorization code' );
		}

		try {
			// Use facile-it library to handle callback and exchange code
			$authorization_service = ( new AuthorizationServiceBuilder() )->build();
			$token_set = $authorization_service->callback(
				$this->get_client(),
				$query_params
			);

			// Get user info using userinfo service
			$userinfo_service = ( new UserInfoServiceBuilder() )->build();
			$user_info = $userinfo_service->getUserInfo(
				$this->get_client(),
				$token_set
			);

			// Convert to array if it's an object
			if ( is_object( $user_info ) ) {
				$user_info = (array) $user_info;
			}

			return [
				'email'       => $user_info['email'] ?? null,
				'name'        => $user_info['name'] ?? null,
				'given_name'  => $user_info['given_name'] ?? null,
				'family_name' => $user_info['family_name'] ?? null,
				'sub'         => $user_info['sub'] ?? null,
			];
		} finally {
			// Clean up session
			unset( $_SESSION['wp_oidc_state'], $_SESSION['wp_oidc_nonce'] );
		}
	}

	/**
	 * Get logout URL for Keycloak
	 *
	 * @param string|null $id_token ID token (optional for RP-initiated logout)
	 *
	 * @return string Logout URL
	 */
	public function get_logout_url(): string {
		$end_session_endpoint = get_transient( 'wp_oidc_end_session_' . md5( $this->issuer ) );

		if ( empty( $end_session_endpoint ) ) {
			return home_url();
		}

		return add_query_arg( [
			'client_id'                => $this->client_id,
			'post_logout_redirect_uri' => home_url(),
		], $end_session_endpoint );
	}


	/**
	 * Get the OIDC client from facile-it library
	 *
	 * @return ClientInterface
	 */
	private function get_client(): ClientInterface {
		if ( $this->client === null ) {
			// Build issuer from discovery document
			$issuer = ( new IssuerBuilder() )->build( $this->issuer );

			// Cache end_session_endpoint so logout needs no extra HTTP request
			$end_session_endpoint = $issuer->getMetadata()->get( 'end_session_endpoint' );
			if ( $end_session_endpoint ) {
				set_transient( 'wp_oidc_end_session_' . md5( $this->issuer ), $end_session_endpoint, DAY_IN_SECONDS );
			}

			// Create client metadata
			$client_metadata = ClientMetadata::fromArray( [
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'redirect_uris' => [ $this->redirect_uri ],
			] );

			// Build and configure client
			$this->client = ( new ClientBuilder() )
				->setIssuer( $issuer )
				->setClientMetadata( $client_metadata )
				->build();
		}

		return $this->client;
	}
}
