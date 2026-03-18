# WordPress OIDC Plugin - Configuration Guide

## Environment Variables (Recommended for Production)

The plugin supports configuration via environment variables. Environment variables have priority over WordPress database options.

### Supported Environment Variables

```bash
WP_OIDC_ENABLED              # Enable/disable OIDC (1/0 or true/false)
WP_OIDC_ISSUER_URL          # Keycloak realm URL
WP_OIDC_CLIENT_ID           # OAuth client ID
WP_OIDC_CLIENT_SECRET       # OAuth client secret (KEEP SECURE!)
WP_OIDC_REDIRECT_URI        # Custom redirect URI (optional)
```

### Setup Examples

#### 1. Using `.env` File (Development)

1. Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

2. Edit `.env` with your actual values:

```bash
WP_OIDC_ENABLED=1
WP_OIDC_ISSUER_URL=https://keycloak.example.com/realms/myrealm
WP_OIDC_CLIENT_ID=wordpress
WP_OIDC_CLIENT_SECRET=your-secret-key-here
```

3. Load in `wp-config.php`:

```php
// Load environment variables from .env file
$env_file = dirname( __FILE__ ) . '/.env';
if ( file_exists( $env_file ) ) {
	$lines = file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		if ( strpos( $line, '#' ) === 0 ) continue; // Skip comments
		if ( strpos( $line, '=' ) === false ) continue;

		[ $key, $value ] = explode( '=', $line, 2 );
		$value = trim( $value, '\'"' );
		putenv( "$key=$value" );
	}
}
```

**⚠️ Important:** Add `.env` to `.gitignore` to prevent secrets from being committed.

#### 2. Using PHP Constants (Alternative)

In `wp-config.php`:

```php
putenv( 'WP_OIDC_ENABLED=1' );
putenv( 'WP_OIDC_ISSUER_URL=https://keycloak.example.com/realms/myrealm' );
putenv( 'WP_OIDC_CLIENT_ID=wordpress' );
putenv( 'WP_OIDC_CLIENT_SECRET=your-secret-key-here' );
```

#### 3. Using Docker Environment Variables

In `docker-compose.yml`:

```yaml
services:
  wordpress:
    environment:
      WP_OIDC_ENABLED: "1"
      WP_OIDC_ISSUER_URL: "https://keycloak.example.com/realms/myrealm"
      WP_OIDC_CLIENT_ID: "wordpress"
      WP_OIDC_CLIENT_SECRET: "${OIDC_CLIENT_SECRET}"  # Load from .env
```

Then in `.env`:

```bash
OIDC_CLIENT_SECRET=your-secret-key-here
```

#### 4. Using Systemd Environment File

On Linux servers using systemd:

Create `/etc/environment.d/wordpress-oidc.conf`:

```bash
WP_OIDC_ENABLED=1
WP_OIDC_ISSUER_URL=https://keycloak.example.com/realms/myrealm
WP_OIDC_CLIENT_ID=wordpress
WP_OIDC_CLIENT_SECRET=your-secret-key-here
```

#### 5. Using Apache SetEnv

In Apache VirtualHost or `.htaccess`:

```apache
SetEnv WP_OIDC_ENABLED "1"
SetEnv WP_OIDC_ISSUER_URL "https://keycloak.example.com/realms/myrealm"
SetEnv WP_OIDC_CLIENT_ID "wordpress"
SetEnv WP_OIDC_CLIENT_SECRET "your-secret-key-here"
```

#### 6. Using Nginx (via FastCGI Params)

In Nginx configuration:

```nginx
location ~ \.php$ {
    fastcgi_param WP_OIDC_ENABLED "1";
    fastcgi_param WP_OIDC_ISSUER_URL "https://keycloak.example.com/realms/myrealm";
    fastcgi_param WP_OIDC_CLIENT_ID "wordpress";
    fastcgi_param WP_OIDC_CLIENT_SECRET "your-secret-key-here";
    # ... other fastcgi params
}
```

## Fallback to WordPress Options

If an environment variable is not set, the plugin falls back to the WordPress option stored in the database. This allows:

- **Production:** Use environment variables for security
- **Development:** Use WordPress admin panel for convenience
- **Mixed:** Set only sensitive values (client secret) in env, others in database

## Configuration Priority

The plugin checks settings in this order:

```
1. Environment Variable (if set)
   ↓
2. WordPress Database Option (if env not set)
   ↓
3. Empty string (if neither set)
```

### Examples

If `WP_OIDC_CLIENT_SECRET` is set in environment:
- Plugin uses environment value
- Database option field is **disabled** in WordPress admin
- Cannot be changed via WordPress admin

If `WP_OIDC_CLIENT_SECRET` is **not** set in environment:
- Plugin reads from WordPress database
- Database option field is **enabled** in WordPress admin
- Can be changed via WordPress admin

## Security Best Practices

### 1. Never Commit Secrets to Git

```bash
# .gitignore
.env
.env.local
wp-config.php (if modified for env vars)
```

### 2. Use Different Secrets for Each Environment

```bash
# Development
WP_OIDC_CLIENT_SECRET=dev-secret-key

# Staging
WP_OIDC_CLIENT_SECRET=staging-secret-key

# Production
WP_OIDC_CLIENT_SECRET=production-secret-key
```

### 3. Restrict File Permissions

For `.env` file or config files with secrets:

```bash
chmod 600 .env
chmod 600 wp-config.php
```

Ensure only the web server user can read:

```bash
chown www-data:www-data .env
```

### 4. Use Secrets Management Systems

For production deployment, use:

- **AWS Secrets Manager** - for AWS deployments
- **HashiCorp Vault** - for on-premises deployments
- **Kubernetes Secrets** - for Kubernetes deployments
- **Docker Secrets** - for Docker Swarm
- **1Password / LastPass** - for team credential sharing

### 5. Rotate Secrets Regularly

Change your Keycloak client secret:

1. Generate new secret in Keycloak
2. Update environment variable or WordPress option
3. Test authentication works
4. Remove old secret from Keycloak

### 6. Audit Access

- Monitor who can access secrets
- Implement password rotation policies
- Log configuration changes in WordPress audit logs

## Checking What's Configured

### Via WordPress Admin

Go to **Settings → OIDC Login** to see:
- Which settings are from environment variables (shown in blue)
- Which settings are from database options
- Current values (except passwords)

### Via Command Line

Check environment variables:

```bash
# Check if set
env | grep WP_OIDC

# Check specific variable
echo $WP_OIDC_CLIENT_SECRET
```

Check WordPress options via WP-CLI:

```bash
# View all OIDC options
wp option list | grep oidc

# View specific option
wp option get wp_oidc_issuer_url
wp option get wp_oidc_client_id
```

## Troubleshooting Configuration

### Settings Not Being Applied

1. Check environment variables are set:
   ```bash
   echo $WP_OIDC_ISSUER_URL
   ```

2. Ensure PHP can read environment variables:
   - Apache: Use `SetEnv` directive or load from file
   - Nginx: Use fastcgi_param
   - CLI: Variables loaded from shell

3. If using `.env` file:
   - Ensure it's loaded in `wp-config.php`
   - Check file permissions (readable by web server)
   - Verify syntax (no spaces around `=`)

### Can't Change Settings in WordPress Admin

If a field is disabled in WordPress admin settings, it means an environment variable is set for that option. To change the setting:

1. Remove the environment variable
2. Reload the settings page
3. Change the value in WordPress admin

Or update the environment variable directly if it's stored in:
- `.env` file
- `wp-config.php`
- Server environment

### "Configuration not found" Error

The plugin requires these settings to be configured (either via env or database):
- `WP_OIDC_ISSUER_URL`
- `WP_OIDC_CLIENT_ID`
- `WP_OIDC_CLIENT_SECRET`

If any of these is missing, the plugin will be disabled.

## Environment Variable Reference

### WP_OIDC_ENABLED

**Type:** Boolean (1/0, true/false, yes/no)
**Purpose:** Enable or disable the plugin
**Default:** Database option value or disabled if neither set
**Example:**
```bash
WP_OIDC_ENABLED=1
```

### WP_OIDC_ISSUER_URL

**Type:** String (URL)
**Purpose:** Keycloak realm URL
**Format:** `https://keycloak.example.com/realms/my-realm`
**Example:**
```bash
WP_OIDC_ISSUER_URL=https://keycloak.example.com/realms/wordpress
```

### WP_OIDC_CLIENT_ID

**Type:** String
**Purpose:** OAuth 2.0 client ID from Keycloak
**Example:**
```bash
WP_OIDC_CLIENT_ID=wordpress
```

### WP_OIDC_CLIENT_SECRET

**Type:** String (Sensitive!)
**Purpose:** OAuth 2.0 client secret from Keycloak
**Format:** Confidential client secret (treat as sensitive)
**Example:**
```bash
WP_OIDC_CLIENT_SECRET=abc123def456ghi789jkl
```

### WP_OIDC_REDIRECT_URI

**Type:** String (URL, Optional)
**Purpose:** Custom OAuth redirect URI
**Default:** Auto-generated from WordPress URL
**Format:** `https://example.com/wp-login.php?oidc_callback=1`
**Example:**
```bash
WP_OIDC_REDIRECT_URI=https://example.com/wp-login.php?oidc_callback=1
```

## Testing Configuration

### Via WordPress Admin

1. Go to **Settings → OIDC Login**
2. Verify all settings are displayed correctly
3. Check if settings show "Configured via environment variable"
4. Test login at `/wp-login.php`

### Via Command Line

```bash
# Test Issuer URL is accessible
curl -s https://keycloak.example.com/realms/wordpress/.well-known/openid-configuration | jq .

# Verify environment variables
wp config get WP_OIDC_ISSUER_URL
wp option get wp_oidc_issuer_url
```

## Summary

| Setting | Database | Environment | Notes |
|---------|----------|-------------|-------|
| Issuer URL | `wp_oidc_issuer_url` | `WP_OIDC_ISSUER_URL` | Required |
| Client ID | `wp_oidc_client_id` | `WP_OIDC_CLIENT_ID` | Required |
| Client Secret | `wp_oidc_client_secret` | `WP_OIDC_CLIENT_SECRET` | Required, use env in production |
| Redirect URI | `wp_oidc_redirect_uri` | `WP_OIDC_REDIRECT_URI` | Optional |
| Enabled | `wp_oidc_enabled` | `WP_OIDC_ENABLED` | Optional |

Environment variables always have priority if both are set.
