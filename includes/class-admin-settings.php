<?php

namespace WpOidc;

/**
 * WordPress admin settings page for OIDC configuration
 */
class AdminSettings {

	/**
	 * Initialize admin settings
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Add settings menu under Settings
	 */
	public function add_settings_menu(): void {
		add_options_page(
			'OIDC Login Settings',
			'OIDC Login',
			'manage_options',
			'wp-oidc-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register plugin settings
	 */
	public function register_settings(): void {
		// Register settings
		register_setting( 'wp-oidc-settings', 'wp_oidc_enabled' );
		register_setting( 'wp-oidc-settings', 'wp_oidc_issuer_url' );
		register_setting( 'wp-oidc-settings', 'wp_oidc_client_id' );
		register_setting( 'wp-oidc-settings', 'wp_oidc_client_secret' );
		register_setting( 'wp-oidc-settings', 'wp_oidc_redirect_uri' );

		// Add settings section
		add_settings_section(
			'wp-oidc-main',
			'OIDC Provider Configuration',
			[ $this, 'render_settings_section_description' ],
			'wp-oidc-settings'
		);

		// Add settings fields
		add_settings_field(
			'wp_oidc_enabled',
			'Enable OIDC Login',
			[ $this, 'render_field_enabled' ],
			'wp-oidc-settings',
			'wp-oidc-main'
		);

		add_settings_field(
			'wp_oidc_issuer_url',
			'Issuer URL',
			[ $this, 'render_field_issuer_url' ],
			'wp-oidc-settings',
			'wp-oidc-main'
		);

		add_settings_field(
			'wp_oidc_client_id',
			'Client ID',
			[ $this, 'render_field_client_id' ],
			'wp-oidc-settings',
			'wp-oidc-main'
		);

		add_settings_field(
			'wp_oidc_client_secret',
			'Client Secret',
			[ $this, 'render_field_client_secret' ],
			'wp-oidc-settings',
			'wp-oidc-main'
		);

		add_settings_field(
			'wp_oidc_redirect_uri',
			'Redirect URI',
			[ $this, 'render_field_redirect_uri' ],
			'wp-oidc-settings',
			'wp-oidc-main'
		);
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		?>
		<div class="wrap">
			<h1>OIDC Login Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wp-oidc-settings' );
				do_settings_sections( 'wp-oidc-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render settings section description
	 */
	public function render_settings_section_description(): void {
		echo '<p>Configure your OIDC provider (e.g., Keycloak) to enable single sign-on.</p>';
		echo '<p><strong>Environment Variables:</strong> You can set configuration via environment variables for better security:';
		echo '<br><code>WP_OIDC_ENABLED</code>, <code>WP_OIDC_ISSUER_URL</code>, <code>WP_OIDC_CLIENT_ID</code>, ';
		echo '<code>WP_OIDC_CLIENT_SECRET</code>, <code>WP_OIDC_REDIRECT_URI</code></p>';
		echo '<p>Environment variables have priority over the settings below.</p>';
	}

	/**
	 * Render enabled checkbox field
	 */
	public function render_field_enabled(): void {
		$env_value = getenv( 'WP_OIDC_ENABLED' );
		$db_value = get_option( 'wp_oidc_enabled' );
		$disabled = false !== $env_value;
		?>
		<input type="checkbox" name="wp_oidc_enabled" value="1" <?php checked( $db_value, 1 ); ?> <?php disabled( $disabled ); ?> />
		<label>Enable OIDC authentication</label>
		<?php if ( $disabled ) : ?>
			<p class="description" style="color: #0073aa;"><strong>Configured via WP_OIDC_ENABLED environment variable</strong></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render issuer URL field
	 */
	public function render_field_issuer_url(): void {
		$env_value = getenv( 'WP_OIDC_ISSUER_URL' );
		$db_value = get_option( 'wp_oidc_issuer_url' );
		$disabled = false !== $env_value;
		?>
		<input type="url" name="wp_oidc_issuer_url" value="<?php echo esc_attr( $db_value ); ?>" size="50" <?php disabled( $disabled ); ?> />
		<p class="description">
			Example: https://keycloak.example.com/realms/myrealm<br />
			<?php if ( $disabled ) : ?>
				<span style="color: #0073aa;"><strong>Configured via WP_OIDC_ISSUER_URL environment variable</strong></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render client ID field
	 */
	public function render_field_client_id(): void {
		$env_value = getenv( 'WP_OIDC_CLIENT_ID' );
		$db_value = get_option( 'wp_oidc_client_id' );
		$disabled = false !== $env_value;
		?>
		<input type="text" name="wp_oidc_client_id" value="<?php echo esc_attr( $db_value ); ?>" size="50" <?php disabled( $disabled ); ?> />
		<?php if ( $disabled ) : ?>
			<p class="description" style="color: #0073aa;"><strong>Configured via WP_OIDC_CLIENT_ID environment variable</strong></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render client secret field
	 */
	public function render_field_client_secret(): void {
		$env_value = getenv( 'WP_OIDC_CLIENT_SECRET' );
		$db_value = get_option( 'wp_oidc_client_secret' );
		$disabled = false !== $env_value;
		?>
		<input type="password" name="wp_oidc_client_secret" value="<?php echo esc_attr( $db_value ); ?>" size="50" <?php disabled( $disabled ); ?> />
		<p class="description">
			<?php if ( $disabled ) : ?>
				<strong style="color: #0073aa;">Configured via WP_OIDC_CLIENT_SECRET environment variable</strong><br />
			<?php else : ?>
				⚠️ Keep this secret secure. Use <code>WP_OIDC_CLIENT_SECRET</code> environment variable in production.
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render redirect URI field
	 */
	public function render_field_redirect_uri(): void {
		$env_value = getenv( 'WP_OIDC_REDIRECT_URI' );
		$db_value = get_option( 'wp_oidc_redirect_uri' );
		$disabled = false !== $env_value;
		$default = add_query_arg( 'oidc_callback', '1', wp_login_url() );
		?>
		<input type="url" name="wp_oidc_redirect_uri" value="<?php echo esc_attr( $db_value ); ?>" size="50" <?php disabled( $disabled ); ?> />
		<p class="description">
			Leave empty to use default: <code><?php echo esc_html( $default ); ?></code><br />
			Register this URL in your OIDC provider as a valid redirect URI.
			<?php if ( $disabled ) : ?>
				<br /><strong style="color: #0073aa;">Configured via WP_OIDC_REDIRECT_URI environment variable</strong>
			<?php endif; ?>
		</p>
		<?php
	}
}
