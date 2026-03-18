# WordPress OIDC Plugin - Installation & Configuration Guide

## Overview

This is a lightweight WordPress plugin that adds OpenID Connect (OIDC) authentication via Keycloak. Users are matched by email address and must be created manually in WordPress admin.

## Requirements

- PHP 8.0 or higher
- WordPress 5.0 or higher
- Keycloak server with OIDC provider configured
- Composer (for dependency installation)

## Installation Steps

### Step 1: Install PHP Dependencies

```bash
composer install
```

This installs:
- `facile-it/php-openid-client` - OIDC protocol library
- `php-http/guzzle7-adapter` - HTTP client implementation (required by facile-it)

**If you get "No PSR-18 clients found" error:**
Ensure `php-http/guzzle7-adapter` is installed. You can add it manually to plugin composer.json:
```json
{
  "require": {
    "php-http/guzzle7-adapter": "^2.0"
  }
}
```
Then run `composer install` again.

### Step 2: Copy Plugin to WordPress

**Option A: Direct Copy**
```bash
cp -r /path/to/wp_oidc /path/to/wordpress/wp-content/plugins/wp-oidc
```

**Option B: Symlink (Development)**
```bash
ln -s /path/to/wp_oidc /path/to/wordpress/wp-content/plugins/wp-oidc
```

### Step 3: Activate in WordPress Admin

1. Go to **WordPress Admin Dashboard**
2. Navigate to **Plugins**
3. Find **"WordPress OIDC Login"**
4. Click **"Activate"**

## Configuration

**Environment Variables (Recommended):** See [CONFIG.md](CONFIG.md) for setting up via `.env` file, Docker, Nginx, Apache, etc.

### Configure Keycloak

In your Keycloak admin console, create or configure a client with these settings:

1. **Client ID:** Set a descriptive ID (e.g., `wordpress`)
2. **Client Protocol:** OpenID Connect
3. **Access Type:** Confidential
4. **Valid Redirect URIs:**
   ```
   https://example.com/wp-login.php?oidc_callback=1
   ```
5. **Valid Post Logout Redirect URIs:**
   ```
   https://example.com/wp-login.php
   ```
6. Go to **Credentials** tab and copy the **Client Secret**

### Configure WordPress Plugin

1. In WordPress Admin, go to **Settings → OIDC Login**
2. Fill in the following fields:
   - **Enable OIDC Login:** Check this box to enable
   - **Issuer URL:** `https://keycloak.example.com/realms/your-realm`
   - **Client ID:** From Keycloak client configuration
   - **Client Secret:** From Keycloak Credentials tab
   - **Redirect URI:** Leave empty to use auto-generated URL, or enter custom redirect URL

3. Click **Save Changes**

## User Management

### Creating Users

Users must be created manually in WordPress:

1. **WordPress Admin → Users → Add New**
2. Enter:
   - **Username:** Any unique username
   - **Email:** MUST match the email configured in Keycloak for this user
3. Click **Add New User**

### Important: Email Matching

The plugin matches users by email address. Therefore:
- The user's **WordPress email** MUST match the **Keycloak email**
- Case sensitivity depends on your email system
- If emails don't match, authentication will fail

## Authentication Flow

```
User visits /wp-login.php
    ↓
Plugin detects unauthenticated user
    ↓
Plugin redirects to Keycloak authorization URL
    ↓
User logs into Keycloak
    ↓
Keycloak redirects to /wp-login.php?oidc_callback=1&code=...
    ↓
Plugin exchanges authorization code for tokens
    ↓
Plugin fetches user email from userinfo endpoint
    ↓
Plugin searches for WordPress user by email
    ↓
User found? → Set auth cookie → Redirect to admin
       ↓
    Not found? → Display error → Redirect to login
```

## Logout Flow

### Standard Logout (User-Initiated)

```
User clicks "Log Out"
    ↓
Plugin captures wp_logout hook
    ↓
Plugin redirects to Keycloak logout endpoint
    ↓
Keycloak logs out user
    ↓
Keycloak redirects back to WordPress login page
```

### Backchannel Logout (Keycloak-Initiated)

```
User logs out in Keycloak (or session expires)
    ↓
Keycloak sends backchannel logout notification to WordPress
    ↓
Plugin AJAX endpoint validates logout token
    ↓
Plugin finds user by Keycloak ID (stored in user meta)
    ↓
Plugin destroys all user sessions
    ↓
User is logged out from WordPress
```

**Backchannel Logout Endpoint:**
```
https://example.com/wp-admin/admin-ajax.php?action=wp_oidc_backchannel_logout
```

### Configuring Backchannel Logout in Keycloak

1. In your Keycloak Admin Console, navigate to your client
2. In the **Settings** tab, add the backchannel logout URL:
   - **Backchannel Logout Session Required:** Toggle ON
   - **Backchannel Logout URL:**
     ```
     https://example.com/wp-admin/admin-ajax.php?action=wp_oidc_backchannel_logout
     ```
3. Click **Save**

**Important:** Replace `https://example.com` with your actual WordPress domain.

The plugin will automatically:
- Store the Keycloak ID (sub claim) in user meta when users log in
- Validate logout tokens from Keycloak
- Destroy all sessions for the user when notified of logout
- Return appropriate JSON responses to Keycloak

## Troubleshooting

### "User not found" Error

**Problem:** User authenticated with Keycloak but can't find matching WordPress user

**Solution:**
1. Check that WordPress user exists in **Users** menu
2. Verify the WordPress user email matches Keycloak email exactly
3. Create the user if it doesn't exist

### Plugin Not Redirecting to Keycloak

**Problem:** Clicking login just reloads the login page

**Solution:**
1. Check plugin is activated in **Plugins** menu
2. Go to **Settings → OIDC Login** and verify:
   - **Enable OIDC Login** is checked
   - All required fields are filled
   - Configuration is saved
3. Check PHP error logs: `/var/log/php-errors.log` or `/var/log/apache2/error.log`

### "Invalid state parameter" Error

**Problem:** Session was lost during authentication

**Solution:**
1. Try logging in again
2. Ensure PHP sessions are enabled
3. Check that session storage directory is writable
4. Look for PHP error logs

### "OIDC Error: invalid_client"

**Problem:** Authentication fails at token exchange

**Solution:**
1. Verify **Client Secret** matches between WordPress and Keycloak
2. Verify **Client ID** is correct
3. Check that redirect URI is registered in Keycloak

### Redirect URI Mismatch

**Problem:** Keycloak shows "redirect_uri_mismatch"

**Solution:**
1. In Keycloak, go to your client
2. Check **Valid Redirect URIs** section
3. Add the exact redirect URI from WordPress settings
4. If using custom redirect URI in WordPress, it must match exactly (including protocol, domain, and all parameters)

## Security Considerations

### Client Secret Storage

**Never commit the client secret to version control.**

For production, use one of these approaches:

**Option 1: Environment Variables**
```php
// In wp-config.php or environment setup
define('WP_OIDC_CLIENT_SECRET', getenv('OIDC_CLIENT_SECRET'));
```

Then modify the plugin to read from this constant instead of the WordPress option.

**Option 2: Separate Config File**
Store secrets in a file outside the web root that's excluded from version control.

**Option 3: WordPress Secrets Manager**
Use a WordPress plugin that manages secrets securely.

### HTTPS Requirement

OIDC requires secure connections. Ensure your WordPress site uses HTTPS in production.

### State Parameter Validation

The plugin automatically validates the OIDC state parameter to prevent CSRF attacks.

## File Structure

```
src/
├── wp-oidc.php                        # Main plugin file (WordPress header + init)
├── composer.json                      # PHP dependencies
├── composer.lock                      # Locked dependency versions (generated)
├── .gitignore                         # Excludes vendor/ and sensitive files
├── includes/
│   ├── class-oidc-client.php         # OIDC protocol client
│   ├── class-auth-handler.php        # WordPress hook integration
│   └── class-admin-settings.php      # Admin settings page
└── vendor/                            # Composer dependencies (generated, not in repo)
```

## Next Steps

1. Install dependencies: `composer install`
2. Copy plugin to WordPress
3. Activate in WordPress admin
4. Configure Keycloak client
5. Configure plugin settings
6. Create test user with matching email
7. Test login flow

## Support

For issues or questions:
- Check PHP error logs
- Review WordPress admin settings (Settings → OIDC Login)
- Verify Keycloak configuration
- Check that user email matches between systems
