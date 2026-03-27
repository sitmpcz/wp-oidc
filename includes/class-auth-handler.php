<?php

namespace WpOidc;

/**
 * WordPress authentication handler for OIDC
 */
class AuthHandler {

	private const COOKIE_NAME = 'wp_oidc_session';

	private OidcClient $oidc_client;

	/**
	 * Initialize the authentication handler
	 */
	public function init(): void {
		// Verify plugin is enabled
		if ( ! $this->get_setting( 'enabled' ) ) {
			return;
		}

		// Verify required settings are configured
		if ( ! $this->has_required_config() ) {
			return;
		}

		// Initialize OIDC client
		$this->init_oidc_client();

		// Register hooks
		add_filter( 'determine_current_user', [ $this, 'determine_oidc_user' ], 5 );
		add_action( 'init', [ $this, 'handle_oidc_callback' ], 10 );
		add_action( 'login_form_login', [ $this, 'redirect_to_oidc' ], 10 );
		add_action( 'wp_logout', [ $this, 'handle_logout' ], 10 );
	}

	/**
	 * Check if required OIDC settings are configured
	 *
	 * @return bool
	 */
	private function has_required_config(): bool {
		return ! empty( $this->get_setting( 'issuer_url' ) ) &&
		       ! empty( $this->get_setting( 'client_id' ) ) &&
		       ! empty( $this->get_setting( 'client_secret' ) );
	}

	/**
	 * Initialize the OIDC client with configuration from env or WordPress options
	 */
	private function init_oidc_client(): void {
		$issuer = $this->get_setting( 'issuer_url' );
		$client_id = $this->get_setting( 'client_id' );
		$client_secret = $this->get_setting( 'client_secret' );
		$redirect_uri = $this->get_redirect_uri();

		$this->oidc_client = new OidcClient(
			$issuer,
			$client_id,
			$client_secret,
			$redirect_uri
		);
	}

	/**
	 * Get setting from environment variable or WordPress option
	 *
	 * Environment variables have priority over WordPress options.
	 *
	 * @param string $setting Setting name (issuer_url, client_id, client_secret, redirect_uri, enabled)
	 *
	 * @return mixed Setting value or empty string if not configured
	 */
	private function get_setting( string $setting ): mixed {
		// Map setting name to environment variable
		$env_map = [
			'issuer_url'    => 'WP_OIDC_ISSUER_URL',
			'client_id'     => 'WP_OIDC_CLIENT_ID',
			'client_secret' => 'WP_OIDC_CLIENT_SECRET',
			'redirect_uri'  => 'WP_OIDC_REDIRECT_URI',
			'enabled'       => 'WP_OIDC_ENABLED',
		];

		$env_var = $env_map[ $setting ] ?? null;

		// Check environment variable first
		if ( $env_var ) {
			$env_value = getenv( $env_var );
			if ( false !== $env_value && '' !== $env_value ) {
				return $env_value;
			}
		}

		// Fall back to WordPress option
		$option_name = 'wp_oidc_' . $setting;
		return get_option( $option_name ) ?? '';
	}

	/**
	 * Get the redirect URI (callback URL)
	 *
	 * @return string
	 */
	private function get_redirect_uri(): string {
		$configured = $this->get_setting( 'redirect_uri' );
		if ( ! empty( $configured ) ) {
			return $configured;
		}

		// Default: wp-login.php with oidc_callback parameter
		return add_query_arg( 'oidc_callback', '1', wp_login_url() );
	}

	/**
	 * Redirect to Keycloak login on WP login page (if not callback)
	 */
	public function redirect_to_oidc(): void {
		// Skip if this is a callback request
		if ( ! empty( $_GET['oidc_callback'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Skip if user is already logged in
		if ( is_user_logged_in() ) {
			return;
		}

		// Redirect to Keycloak
		$auth_url = $this->oidc_client->get_authorization_url();
		wp_redirect( $auth_url );
		exit;
	}

	/**
	 * Handle OIDC callback - exchange code for user info and log in
	 */
	public function handle_oidc_callback(): void {
		// Check if this is an OIDC callback
		if ( empty( $_GET['oidc_callback'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Skip if user is already logged in
		if ( is_user_logged_in() ) {
			wp_redirect( admin_url() );
			exit;
		}

		try {
			// Get query parameters
			$query_params = array_map( 'sanitize_text_field', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// Handle callback
			$user_info = $this->oidc_client->handle_callback( $query_params );

			// Validate email
			if ( empty( $user_info['email'] ) ) {
				$this->handle_error( 'No email in token' );
			}

			// Find user by email
			$user = get_user_by( 'email', $user_info['email'] );

			if ( ! $user ) {
				$this->handle_error(
					'User not found',
					'User with email ' . esc_html( $user_info['email'] ) . ' does not exist'
				);
			}
            wp_set_auth_cookie( $user->ID, false );
            // Set custom session cookie (without username, to avoid WAF/firewall issues)
			$this->set_oidc_session_cookie( $user->ID );

			// Store Keycloak ID for backchannel logout
			if ( ! empty( $user_info['sub'] ) ) {
				update_user_meta( $user->ID, 'wp_oidc_keycloak_id', $user_info['sub'] );
			}

			if ( session_status() === PHP_SESSION_NONE ) {
				session_start();
			}

			// Store id_token for RP-initiated logout
			if ( ! empty( $user_info['id_token'] ) ) {
				$_SESSION['wp_oidc_id_token'] = $user_info['id_token'];
			}

			$_SESSION['wp_oidc_user_info'] = $user_info;

			// Redirect to admin
			wp_redirect( admin_url() );
			exit;
		} catch ( \Exception $e ) {
			$this->handle_error( 'Authentication failed', $e->getMessage() );
		}
	}

	/**
	 * Handle logout - redirect to Keycloak logout
	 */
	public function handle_logout(): void {
		// Clear OIDC session cookie
		$this->clear_oidc_session_cookie();

		// Get ID token if available (for RP-initiated logout)
		$id_token = null;
		if ( session_status() === PHP_SESSION_NONE ) {
			session_start();
		}
		if ( ! empty( $_SESSION['wp_oidc_id_token'] ) ) {
			$id_token = $_SESSION['wp_oidc_id_token'];
		}

		// Redirect to Keycloak logout
		$logout_url = $this->oidc_client->get_logout_url( $id_token );
		wp_redirect( $logout_url );
		exit;
	}

	/**
	 * Validate OIDC session cookie and return user ID
	 *
	 * Hooked on determine_current_user (priority 5) to authenticate users
	 * via opaque session token instead of WordPress's default username-in-cookie mechanism.
	 *
	 * @param int|false $user_id User ID from earlier filter or false
	 *
	 * @return int|false User ID if OIDC session is valid, original value otherwise
	 */
	public function determine_oidc_user( mixed $user_id ): mixed {
		if ( $user_id ) {
			return $user_id;
		}

		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return false;
		}

		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		if ( empty( $token ) ) {
			return false;
		}

		$stored_user_id = get_transient( 'wp_oidc_' . hash( 'sha256', $token ) );
		if ( ! $stored_user_id ) {
			return false;
		}

		return (int) $stored_user_id;
	}

	/**
	 * Set opaque OIDC session cookie (no username in cookie value)
	 *
	 * @param int $user_id WordPress user ID
	 */
	private function set_oidc_session_cookie( int $user_id ): void {
		$token      = bin2hex( random_bytes( 32 ) ); // 256-bit random token
		$expiration = time() + 8 * HOUR_IN_SECONDS;
		$token_hash = hash( 'sha256', $token );

		// Store mapping token_hash -> user_id in transient for fast O(1) lookup
		set_transient( 'wp_oidc_' . $token_hash, $user_id, 8 * HOUR_IN_SECONDS );

		// Store token hashes in user meta to allow bulk invalidation (backchannel logout)
		$user_tokens = get_user_meta( $user_id, 'wp_oidc_session_tokens', true );
		if ( ! is_array( $user_tokens ) ) {
			$user_tokens = [];
		}
		// Prune tokens that no longer exist in transient store
		$user_tokens   = array_filter( $user_tokens, fn( $h ) => false !== get_transient( 'wp_oidc_' . $h ) );
		$user_tokens[] = $token_hash;
		update_user_meta( $user_id, 'wp_oidc_session_tokens', array_values( $user_tokens ) );

		setcookie(
			self::COOKIE_NAME,
			$token,
			[
				'expires'  => $expiration,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/**
	 * Clear OIDC session cookie and invalidate the token in transient store
	 */
	private function clear_oidc_session_cookie(): void {
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$token          = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
			$token_hash     = hash( 'sha256', $token );
			$transient_key  = 'wp_oidc_' . $token_hash;

			$user_id = (int) get_transient( $transient_key );
			delete_transient( $transient_key );

			if ( $user_id > 0 ) {
				$user_tokens = get_user_meta( $user_id, 'wp_oidc_session_tokens', true );
				if ( is_array( $user_tokens ) ) {
					update_user_meta(
						$user_id,
						'wp_oidc_session_tokens',
						array_values( array_diff( $user_tokens, [ $token_hash ] ) )
					);
				}
			}
		}

		setcookie(
			self::COOKIE_NAME,
			'',
			[
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/**
	 * Destroy all active OIDC sessions for a user (used by backchannel logout)
	 *
	 * @param int $user_id WordPress user ID
	 */
	public static function destroy_user_oidc_sessions( int $user_id ): void {
		$user_tokens = get_user_meta( $user_id, 'wp_oidc_session_tokens', true );
		if ( is_array( $user_tokens ) ) {
			foreach ( $user_tokens as $token_hash ) {
				delete_transient( 'wp_oidc_' . $token_hash );
			}
		}
		delete_user_meta( $user_id, 'wp_oidc_session_tokens' );
	}

	/**
	 * Handle authentication error
	 *
	 * @param string $error_code Error code
	 * @param string $error_message Error message (optional)
	 */
	private function handle_error( string $error_code, string $error_message = '' ): void {
		$url = wp_login_url();
		$url = add_query_arg( 'oidc_error', urlencode( $error_code ), $url );

		if ( ! empty( $error_message ) ) {
			$url = add_query_arg( 'oidc_error_description', urlencode( $error_message ), $url );
		}

		wp_redirect( $url );
		exit;
	}
}
