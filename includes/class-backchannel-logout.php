<?php

namespace WpOidc;

use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Backchannel logout handler for OIDC RP-Initiated Logout
 *
 * Keycloak can notify the RP (WordPress) about logout events via backchannel
 * without requiring a redirect. This implements that endpoint.
 *
 * @see https://openid.net/specs/openid-connect-backchannel-1_0.html
 */
class BackchannelLogout {

	/** @see https://openid.net/specs/openid-connect-backchannel-1_0.html#Validation */
	private const BACKCHANNEL_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

	private string $issuer_url;
	private string $client_id;

	public function __construct() {
		$this->issuer_url = (string) $this->get_setting( 'issuer_url' );
		$this->client_id  = (string) $this->get_setting( 'client_id' );
	}

	/**
	 * Initialize backchannel logout endpoint
	 */
	public function init(): void {
		if ( empty( $this->issuer_url ) || empty( $this->client_id ) ) {
			return;
		}

		add_action( 'wp_ajax_nopriv_wp_oidc_backchannel_logout', [ $this, 'handle_logout' ] );
	}

	/**
	 * Handle backchannel logout request from Keycloak
	 *
	 * Keycloak sends a POST request with logout_token parameter.
	 * We validate the token and log out the corresponding user.
	 */
	public function handle_logout(): void {
		$logout_token = isset( $_POST['logout_token'] ) ? trim( wp_unslash( $_POST['logout_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( empty( $logout_token ) ) {
			wp_send_json_error( 'Missing logout_token', 400 );
			return;
		}

		try {
			$claims = $this->validate_logout_token( $logout_token );

			if ( ! $claims ) {
				wp_send_json_error( 'Invalid logout token', 400 );
				return;
			}

			// Spec allows logout by sub or sid; sub is sufficient here
			$user_sub = $claims['sub'] ?? null;

			if ( ! $user_sub ) {
				wp_send_json_error( 'No sub claim in token', 400 );
				return;
			}

			$user = $this->find_user_by_keycloak_id( $user_sub );

			if ( ! $user ) {
				// Not an error — user may have never logged in or was already logged out
				wp_send_json_success( 'User not found' );
				return;
			}

			AuthHandler::destroy_user_oidc_sessions( $user->ID );

			$sessions = \WP_Session_Tokens::get_instance( $user->ID );
			$sessions->destroy_all();

			do_action( 'wp_oidc_backchannel_logout', $user->ID, $user_sub );

			wp_send_json_success( 'User logged out' );
		} catch ( \Exception $e ) {
			wp_send_json_error( 'Logout processing failed: ' . $e->getMessage(), 500 );
		}
	}

	/**
	 * Validate logout token: verify JWT signature and required claims.
	 *
	 * @param string $logout_token JWT logout token from Keycloak
	 *
	 * @return array|false Token claims if valid, false otherwise
	 */
	private function validate_logout_token( string $logout_token ): array|false {
		$parts = explode( '.', $logout_token );
		if ( count( $parts ) !== 3 ) {
			return false;
		}

		$claims = json_decode(
			base64_decode( strtr( $parts[1], '-_', '+/' ) ),
			true
		);

		if ( ! is_array( $claims ) ) {
			return false;
		}

		// Verify JWT signature using issuer's JWKS
		if ( ! $this->verify_signature( $logout_token ) ) {
			return false;
		}

		// iss must match configured issuer
		if ( ( $claims['iss'] ?? '' ) !== $this->issuer_url ) {
			return false;
		}

		// aud must contain our client_id
		$aud = $claims['aud'] ?? [];
		if ( is_string( $aud ) ) {
			$aud = [ $aud ];
		}
		if ( ! in_array( $this->client_id, $aud, true ) ) {
			return false;
		}

		// iat must be present and not more than 5 minutes in the past
		if ( ! isset( $claims['iat'] ) || ( time() - (int) $claims['iat'] ) > 300 ) {
			return false;
		}

		// nonce must NOT be present in logout tokens (per spec)
		if ( isset( $claims['nonce'] ) ) {
			return false;
		}

		// events claim must be a JSON object with the backchannel logout event key
		if ( ! isset( $claims['events'][ self::BACKCHANNEL_LOGOUT_EVENT ] ) ) {
			return false;
		}

		// sub or sid must be present
		if ( empty( $claims['sub'] ) && empty( $claims['sid'] ) ) {
			return false;
		}

		return $claims;
	}

	/**
	 * Verify JWT signature against the issuer's JWKS.
	 *
	 * @param string $token Compact-serialized JWT
	 *
	 * @return bool
	 */
	private function verify_signature( string $token ): bool {
		$jwks_data = $this->get_jwks();
		if ( ! $jwks_data ) {
			return false;
		}

		try {
			$algorithm_manager = new AlgorithmManager( [
				new RS256(), new RS384(), new RS512(),
				new ES256(), new ES384(), new ES512(),
			] );

			$jws_verifier = new JWSVerifier( $algorithm_manager );
			$serializer   = new CompactSerializer();
			$jws          = $serializer->unserialize( $token );
			$jwkset       = JWKSet::createFromKeyData( $jwks_data );

			return $jws_verifier->verifyWithKeySet( $jws, $jwkset, 0 );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Fetch JWKS from issuer discovery document, with 1-hour transient cache.
	 *
	 * @return array|null JWKS key data or null on failure
	 */
	private function get_jwks(): ?array {
		$cache_key = 'wp_oidc_jwks_' . md5( $this->issuer_url );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$issuer   = ( new IssuerBuilder() )->build( $this->issuer_url );
			$jwks_uri = $issuer->getMetadata()->get( 'jwks_uri' );

			$response = wp_remote_get( $jwks_uri, [ 'timeout' => 10 ] );
			if ( is_wp_error( $response ) ) {
				return null;
			}

			$jwks = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $jwks ) ) {
				return null;
			}

			set_transient( $cache_key, $jwks, HOUR_IN_SECONDS );

			return $jwks;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Find WordPress user by Keycloak ID (sub claim stored in user meta).
	 *
	 * @param string $keycloak_id Keycloak sub claim
	 *
	 * @return \WP_User|false
	 */
	private function find_user_by_keycloak_id( string $keycloak_id ): \WP_User|false {
		$users = get_users( [
			'meta_key'   => 'wp_oidc_keycloak_id',
			'meta_value' => $keycloak_id,
			'number'     => 1,
		] );

		return $users[0] ?? false;
	}

	/**
	 * Get setting from environment variable or WordPress option.
	 *
	 * @param string $setting issuer_url | client_id | client_secret | redirect_uri | enabled
	 *
	 * @return mixed
	 */
	private function get_setting( string $setting ): mixed {
		$env_map = [
			'issuer_url'    => 'WP_OIDC_ISSUER_URL',
			'client_id'     => 'WP_OIDC_CLIENT_ID',
			'client_secret' => 'WP_OIDC_CLIENT_SECRET',
			'redirect_uri'  => 'WP_OIDC_REDIRECT_URI',
			'enabled'       => 'WP_OIDC_ENABLED',
		];

		$env_var = $env_map[ $setting ] ?? null;
		if ( $env_var ) {
			$env_value = getenv( $env_var );
			if ( false !== $env_value && '' !== $env_value ) {
				return $env_value;
			}
		}

		return get_option( 'wp_oidc_' . $setting ) ?? '';
	}

	/**
	 * Get backchannel logout endpoint URL to register in Keycloak.
	 *
	 * @return string
	 */
	public static function get_endpoint_url(): string {
		return add_query_arg( 'action', 'wp_oidc_backchannel_logout', admin_url( 'admin-ajax.php' ) );
	}
}
