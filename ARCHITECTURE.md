# WordPress OIDC Plugin - Architecture & Development Guide

## Overview

This document describes the technical architecture of the WordPress OIDC plugin, designed for developers who need to understand the codebase or extend it.

## Project Goals

- Replace WordPress login form with OIDC authentication
- Match users by email address only
- Handle authentication flow (not user/role management)
- Lightweight, single-responsibility design
- No database modifications or migrations needed

## Key Constraints

- Plugin is **read-only** regarding WordPress users
- Plugin does **not** create or update WordPress users
- Plugin does **not** manage roles or permissions
- Users must be created manually by administrators
- Email is the only matching criteria

## Core Classes

### 1. OidcClient (`includes/class-oidc-client.php`)

Wrapper around the OIDC protocol, using `facile-it/php-openid-client` library.

**Public Methods:**

```php
public function init(): void
// Initializes handler with WordPress hooks
// Reads configuration from environment or database
// Only registers hooks if OIDC is enabled and configured

public function redirect_to_oidc(): void
// Hook: login_form_login
// Redirects unauthenticated users to Keycloak

public function handle_oidc_callback(): void
// Hook: init
// Processes OIDC callback, exchanges code for tokens
// Finds user by email, sets auth cookie

public function handle_logout(): void
// Hook: wp_logout
// Redirects to Keycloak logout URL

private function get_setting(string $setting): mixed
// Reads setting from environment variable or WordPress option
// Priority: environment variable > WordPress option
// Returns setting value or empty string
```

**Private Methods:**

```php
private function get_provider_config(): array
// Fetches .well-known/openid-configuration
// Cached via wp_transient() for 24 hours

private function exchange_code_for_token(string $code): array
// HTTP POST to token endpoint
// Returns access_token, id_token, refresh_token, etc.

private function get_userinfo(string $access_token): array
// HTTP GET to userinfo endpoint
// Returns user info including email, name, etc.

private function get_client(): ClientInterface
// Instantiates facile-it OIDC client
// Cached for entire request lifecycle
```

**Session Handling:**

- Calls `session_start()` if not already started
- Stores `$_SESSION['wp_oidc_state']` (CSRF protection)
- Stores `$_SESSION['wp_oidc_nonce']` (token validation)
- Clears session data after successful callback

**Error Handling:**

- Throws `Exception` for all error cases
- Error messages include details for debugging
- Discovery errors propagate (will prevent plugin from working)
- Token exchange errors are caught by `AuthHandler`

### 2. AuthHandler (`includes/class-auth-handler.php`)

Integrates OIDC with WordPress through hooks.

**Public Methods:**

```php
public function init(): void
// Called during plugins_loaded
// Checks if OIDC is enabled and configured
// Registers WordPress hooks

public function redirect_to_oidc(): void
// Hook: login_form_login (action)
// Triggered before login form is rendered
// Redirects unauthenticated users to Keycloak

public function handle_oidc_callback(): void
// Hook: init (action)
// Processes OIDC callback from Keycloak
// Exchanges code for tokens
// Finds user by email
// Sets authentication cookie

public function handle_logout(): void
// Hook: wp_logout (action)
// Redirects to Keycloak logout endpoint
// Clears user session
```

**User Lookup:**

```php
$user = get_user_by('email', $user_info['email']);
```

Only email-based lookup is performed. No other user attributes are synced.

**Authentication:**

```php
wp_set_auth_cookie($user->ID);
```

Standard WordPress cookie-based authentication is used.

**Error Handling:**

- Missing email from token → error page
- User not found → error page with email shown
- Token exchange exception → error page with details
- User already logged in → redirects to admin directly

### 3. AdminSettings (`includes/class-admin-settings.php`)

Provides WordPress admin interface for configuration.

**Public Methods:**

```php
public function init(): void
// Registers hooks for admin initialization

public function add_settings_menu(): void
// Hook: admin_menu (action)
// Adds "OIDC Login" submenu under Settings

public function register_settings(): void
// Hook: admin_init (action)
// Registers settings, sections, and fields

public function render_settings_page(): void
// Renders admin settings page HTML

public function render_settings_section_description(): void
// Renders section description text
```

**Settings (Environment Variables or WordPress Options):**

Plugin reads settings from environment variables first, falls back to WordPress options.

| Setting | Database Option | Environment Variable | Purpose |
|---------|-----------------|----------------------|---------|
| Enabled | `wp_oidc_enabled` | `WP_OIDC_ENABLED` | Enable/disable OIDC |
| Issuer URL | `wp_oidc_issuer_url` | `WP_OIDC_ISSUER_URL` | Keycloak realm URL |
| Client ID | `wp_oidc_client_id` | `WP_OIDC_CLIENT_ID` | OAuth client ID |
| Client Secret | `wp_oidc_client_secret` | `WP_OIDC_CLIENT_SECRET` | OAuth client secret |
| Redirect URI | `wp_oidc_redirect_uri` | `WP_OIDC_REDIRECT_URI` | Custom redirect URI (optional) |

**Priority:** Environment variables have priority over database options.

**Implementation:** `AuthHandler::get_setting()` method handles the priority logic.

## WordPress Hooks Used

### Initialization
- `plugins_loaded` - Initialize plugin classes
- `activation_hook` - Register activation routine

### Admin
- `admin_menu` - Add settings page menu
- `admin_init` - Register settings and fields

### Authentication
- `login_form_login` (action) - Redirect to Keycloak before login form
- `init` (action) - Handle callback from Keycloak
- `wp_logout` (action) - Redirect to Keycloak logout

## Data Flow

### Login Flow

```
1. User visits /wp-login.php
2. login_form_login hook fires
3. AuthHandler::redirect_to_oidc() checks:
   - Is this a callback? (skip if yes)
   - Is user already logged in? (skip if yes)
   - Generate state/nonce, store in $_SESSION
   - Redirect to OidcClient::get_authorization_url()
4. User authenticates with Keycloak
5. Keycloak redirects to wp-login.php?oidc_callback=1&code=...&state=...
6. init hook fires early in page load
7. AuthHandler::handle_oidc_callback() processes:
   - Verify state matches $_SESSION['wp_oidc_state']
   - OidcClient::handle_callback() exchanges code for tokens
   - Extract email from userinfo
   - get_user_by('email', $email) finds WordPress user
   - wp_set_auth_cookie($user->ID) authenticates
   - wp_redirect(admin_url()) sends to admin
```

### Logout Flow

```
1. User clicks "Log Out"
2. wp_logout hook fires
3. AuthHandler::handle_logout():
   - Get id_token from $_SESSION (if available)
   - OidcClient::get_logout_url($id_token) builds logout URL
   - wp_redirect($logout_url) to Keycloak logout
4. Keycloak logs out user
5. Keycloak redirects to post_logout_redirect_uri (wp-login.php)
6. User sees WordPress login form
```

### Configuration Flow

```
Admin saves settings → update_option('wp_oidc_*', value)
↓
AuthHandler::init() reads options
↓
OidcClient initialized with settings
↓
On next request:
  - get_provider_config() fetches .well-known/openid-configuration
  - Configuration cached in WordPress transient for 24 hours
  - All subsequent requests use cached configuration
```

## Error Handling Strategy

### Plugin-level Errors

- Missing/invalid settings → plugin disabled
- Discovery endpoint unreachable → thrown exception caught at hook level
- Token endpoint failure → error page with description

### User-level Errors

- User not found → error page with user email shown
- Missing email in token → error page
- Invalid state → error page (CSRF attempt or session loss)

### Error Display

Error messages redirect to `wp_login_url()` with query parameters:
- `oidc_error=error_code`
- `oidc_error_description=message`

Admin can display these in login form with:
```php
$error = isset($_GET['oidc_error']) ? sanitize_text_field($_GET['oidc_error']) : '';
if ($error === 'user_not_found') {
    // Display user not found message
}
```

## Security Considerations

### CSRF Protection

- State parameter generated: `bin2hex(random_bytes(16))`
- State stored in `$_SESSION`
- State verified on callback against query parameter
- Invalid state → exception thrown

### Token Validation

- Uses `facile-it/php-openid-client` for JWT validation
- Nonce checked by library (stored in $_SESSION)
- Signature verification performed on ID token

### Secrets

- Client Secret stored in WordPress option (insecure by default)
- Should use environment variable in production
- Never logged or displayed in error messages

### HTTPS

- OIDC specification requires HTTPS
- Enforced by Keycloak (won't issue tokens to HTTP)
- Redirect URI must be HTTPS in production

## Extensibility

### Adding New User Claims

Modify `OidcClient::handle_callback()` to return additional claims:

```php
return [
    'email'       => $user_info['email'] ?? null,
    'name'        => $user_info['name'] ?? null,
    'given_name'  => $user_info['given_name'] ?? null,
    'family_name' => $user_info['family_name'] ?? null,
    'sub'         => $user_info['sub'] ?? null,
    'phone'       => $user_info['phone_number'] ?? null,  // Add
];
```

### Adding User Profile Update (Future Enhancement)

Would require:
1. Store additional claims in session
2. Update user metadata: `update_user_meta()`
3. Add admin settings for which claims to sync

### Adding Role Mapping (Future Enhancement)

Would require:
1. Extract roles from Keycloak token
2. Call `wp_set_user_role()` on successful login
3. Add admin UI for role mapping configuration

## Testing

### Unit Tests

Currently no unit tests. Could add with PHPUnit:
- Test state validation logic
- Test email extraction from userinfo
- Test error handling

### Integration Tests

Would require:
- Mock Keycloak server or use test instance
- WordPress test environment
- Database transactions for test isolation

### Manual Testing

1. Create Keycloak test user with email: `test@example.com`
2. Create WordPress user with same email
3. Test login flow
4. Test logout flow
5. Test error scenarios:
   - Wrong email in Keycloak
   - Missing email claim
   - Invalid state
   - Token exchange failure

## Dependencies

### Production

- `facile-it/php-openid-client` (^0.3)
  - Handles OIDC protocol details
  - JWT validation
  - Discovery document parsing

### Development

None (no dev dependencies defined).

### WordPress

- `wp_redirect()` - Safe redirect
- `wp_set_auth_cookie()` - Set authentication
- `get_user_by()` - User lookup
- `get_option()` / `update_option()` - Settings storage
- `wp_safe_remote_post()` - HTTP requests
- `wp_remote_get()` - HTTP requests
- `wp_remote_retrieve_body()` - Response parsing
- `add_query_arg()` - URL building
- `esc_attr()` / `sanitize_text_field()` - Security

## Performance Considerations

### Discovery Caching

- `.well-known/openid-configuration` cached for 24 hours
- Uses WordPress transient API
- Reduces HTTPS requests to Keycloak

### Session Usage

- Session started only when needed
- State/nonce stored in $_SESSION (not database)
- Session cleaned up after successful callback

### Database Access

- Only one query per login: `get_user_by('email', $email)`
- No writes to database
- No custom tables or migrations needed

## Future Enhancements

### High Priority
- [ ] Admin UI for secrets (environment variable support)
- [ ] Error messages in login form
- [ ] Backchannel logout support
- [ ] Nonce validation logging

### Medium Priority
- [ ] Role mapping from Keycloak
- [ ] User metadata sync (name, email)
- [ ] Multiple email claim support
- [ ] Language translations

### Low Priority
- [ ] Social login provider support
- [ ] Custom claim support
- [ ] Multi-tenant support
- [ ] Admin UI improvements

## Code Style

- PHP 8.0+
- Namespaced: `WpOidc\`
- WordPress coding standards
- PSR-4 autoloading via Composer
- Methods use snake_case (WordPress convention)
- Classes use PascalCase
