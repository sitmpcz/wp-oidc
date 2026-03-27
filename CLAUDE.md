# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install PHP dependencies
composer install

# Update dependencies
composer update
```

There are no automated tests in this project.

## Architecture

This is a WordPress plugin (`type: wordpress-plugin`) that replaces the standard WP login with Keycloak OIDC authentication. PHP namespace: `WpOidc\`. Requires PHP >= 8.0. Dependency: `facile-it/php-openid-client`.

### Entry Point

`wp-oidc.php` — plugin header, loads Composer autoloader (falls back to Bedrock root `vendor/`), requires all class files, then on `plugins_loaded` instantiates and initializes `AdminSettings`, `AuthHandler`, and `BackchannelLogout`.

### Classes (`includes/`)

**`OidcClient`** — thin wrapper around `facile-it/php-openid-client`:
- `get_authorization_url()` — generates state/nonce, stores in `$_SESSION`, returns Keycloak auth URL
- `handle_callback(array $query_params)` — verifies state, exchanges code for tokens via `AuthorizationServiceBuilder`, fetches userinfo via `UserInfoServiceBuilder`, returns array with `email`, `sub`, `name`, etc.
- `get_logout_url()` — currently returns `home_url()` (RP-initiated logout not fully implemented)

**`AuthHandler`** — hooks into WordPress authentication lifecycle:
- `determine_oidc_user` (filter `determine_current_user`, priority 5) — validates the custom `wp_oidc_session` cookie by looking up a SHA-256 token hash in WP transients; returns user ID if valid
- `redirect_to_oidc` (`login_form_login`) — redirects non-authenticated users to Keycloak
- `handle_oidc_callback` (`init`) — processes `?oidc_callback=1`, finds WP user by email, calls `wp_set_auth_cookie()` + `set_oidc_session_cookie()`, stores `sub` claim as `wp_oidc_keycloak_id` user meta
- `handle_logout` (`wp_logout`) — clears OIDC session cookie/transient, redirects to `get_logout_url()`
- `destroy_user_oidc_sessions(int $user_id)` (static) — deletes all transients for a user's tokens; called by `BackchannelLogout`

**Session cookie mechanism** (avoids username-in-cookie for WAF compatibility):
- Login sets a 256-bit random token as cookie `wp_oidc_session`; token hash → user ID stored in WP transient (`wp_oidc_<sha256hash>`) for 8 hours
- Token hashes also stored in user meta `wp_oidc_session_tokens` to support bulk invalidation

**`BackchannelLogout`** — AJAX endpoint (`wp_ajax_nopriv_wp_oidc_backchannel_logout`) at `admin-ajax.php?action=wp_oidc_backchannel_logout`:
- Receives POST `logout_token` (JWT) from Keycloak
- Validates token structure and `events` claim (note: signature verification is not implemented — payload is decoded without verification)
- Looks up WP user by `wp_oidc_keycloak_id` user meta, calls `AuthHandler::destroy_user_oidc_sessions()` and `WP_Session_Tokens::destroy_all()`
- Fires action `wp_oidc_backchannel_logout` for custom handling

**`AdminSettings`** — settings page at Settings > OIDC Login; fields are disabled when the corresponding env var is set.

### Configuration priority: env vars > WordPress options

| Setting | Env Var | WP Option |
|---|---|---|
| Enable | `WP_OIDC_ENABLED` | `wp_oidc_enabled` |
| Issuer URL | `WP_OIDC_ISSUER_URL` | `wp_oidc_issuer_url` |
| Client ID | `WP_OIDC_CLIENT_ID` | `wp_oidc_client_id` |
| Client Secret | `WP_OIDC_CLIENT_SECRET` | `wp_oidc_client_secret` |
| Redirect URI | `WP_OIDC_REDIRECT_URI` | `wp_oidc_redirect_uri` |

Default redirect URI: `wp-login.php?oidc_callback=1`

### User matching

Users are matched by email address. Users must be pre-created in WordPress — the plugin does **not** auto-provision accounts. The `sub` claim from Keycloak is stored as `wp_oidc_keycloak_id` user meta to support backchannel logout.

### Known limitations

- Backchannel logout token JWT signature is **not verified** (only structure/claims checked)
- `get_logout_url()` returns `home_url()` — RP-initiated logout with Keycloak is not implemented