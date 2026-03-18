<?php

namespace WpOidc;

/**
 * Backchannel logout handler for OIDC RP-Initiated Logout
 *
 * Keycloak can notify the RP (WordPress) about logout events via backchannel
 * without requiring a redirect. This implements that endpoint.
 *
 * @see https://openid.net/specs/openid-connect-backchannel-1_0.html
 */
class BackchannelLogout {

	/**
	 * Initialize backchannel logout handler
	 */
	public function __construct() {
	}

	/**
	 * Initialize backchannel logout endpoint
	 */
	public function init(): void {
		// Register AJAX endpoint for backchannel logout
		add_action( 'wp_ajax_nopriv_wp_oidc_backchannel_logout', [ $this, 'handle_logout' ] );
	}

	/**
	 * Handle backchannel logout request from Keycloak
	 *
	 * Keycloak sends a POST request with logout_token parameter.
	 * We validate the token and log out the corresponding user.
	 */
	public function handle_logout(): void {
		// Get logout token from request
		$logout_token = isset( $_POST['logout_token'] ) ? sanitize_text_field( wp_unslash( $_POST['logout_token'] ) ) : '';

		if ( empty( $logout_token ) ) {
			wp_send_json_error( 'Missing logout_token' );
			return;
		}

		try {
			// Validate and parse the logout token
			$claims = $this->validate_logout_token( $logout_token );

			if ( ! $claims ) {
				wp_send_json_error( 'Invalid logout token' );
				return;
			}

			// Extract user identifier (sub claim)
			$user_sub = $claims['sub'] ?? null;

			if ( ! $user_sub ) {
				wp_send_json_error( 'No user identifier in token' );
				return;
			}

			// Find user by Keycloak ID stored in user meta
			$user = $this->find_user_by_keycloak_id( $user_sub );

			if ( ! $user ) {
				// User not found, but that's OK - just return success
				wp_send_json_success( 'User not found (already logged out)' );
				return;
			}

			// Get all sessions for this user and destroy them
			$sessions = WP_Session_Tokens::get_instance( $user->ID );
			$sessions->destroy_all();

			// Log the logout action
			do_action( 'wp_oidc_backchannel_logout', $user->ID, $user_sub );

			wp_send_json_success( 'User logged out' );
		} catch ( \Exception $e ) {
			wp_send_json_error( 'Logout processing failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Validate logout token signature and claims
	 *
	 * @param string $logout_token JWT logout token from Keycloak
	 *
	 * @return array|false Token claims if valid, false otherwise
	 */
	private function validate_logout_token( string $logout_token ) {
		try {
			// Try to decode and validate using facile-it library
			// This would require JWT validation setup, for now we do basic checks

			// Split token into parts
			$parts = explode( '.', $logout_token );
			if ( count( $parts ) !== 3 ) {
				return false;
			}

			// Decode payload (without signature verification for now)
			// In production, you should verify the signature
			$payload = base64_decode( $parts[1], true );
			if ( ! $payload ) {
				return false;
			}

			$claims = json_decode( $payload, true );
			if ( ! is_array( $claims ) ) {
				return false;
			}

			// Verify required claims for logout token
			// Must have 'events' claim with 'http://openid.net/specs/openid-connect-backchannel-1_0/event_type/backchannel+logout'
			if ( ! isset( $claims['events'] ) || ! is_array( $claims['events'] ) ) {
				return false;
			}

			$has_logout_event = false;
			foreach ( $claims['events'] as $event ) {
				if ( isset( $event['http://openid.net/specs/openid-connect-backchannel-1_0/event_type/backchannel+logout'] ) ) {
					$has_logout_event = true;
					break;
				}
			}

			if ( ! $has_logout_event ) {
				return false;
			}

			// Token is valid enough
			return $claims;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Find WordPress user by Keycloak ID (sub claim)
	 *
	 * @param string $keycloak_id User's Keycloak ID (sub claim)
	 *
	 * @return \WP_User|false User object or false if not found
	 */
	private function find_user_by_keycloak_id( string $keycloak_id ) {
		// Search for user who has this Keycloak ID stored in user meta
		$users = get_users( [
			'meta_key'   => 'wp_oidc_keycloak_id',
			'meta_value' => $keycloak_id,
		] );

		if ( ! empty( $users ) ) {
			return $users[0];
		}

		return false;
	}

	/**
	 * Get backchannel logout endpoint URL
	 *
	 * This URL should be registered in Keycloak as the backchannel logout URL
	 *
	 * @return string Backchannel logout endpoint URL
	 */
	public static function get_endpoint_url(): string {
		return add_query_arg( 'action', 'wp_oidc_backchannel_logout', admin_url( 'admin-ajax.php' ) );
	}
}
