# WordPress OIDC Login Plugin

A lightweight WordPress plugin that replaces the standard login form with OpenID Connect (OIDC) authentication via Keycloak.

## Features

- 🔐 **OIDC Authentication** - Single Sign-On via Keycloak
- 👤 **Email-based User Matching** - Pairs users by email address
- 🚀 **Lightweight** - Minimal code, single responsibility (authentication only)
- ⚙️ **Environment Variables** - Secure configuration via env variables
- 🔄 **Logout Integration** - Automatic logout redirect to Keycloak
- 📡 **Backchannel Logout** - Support for OIDC RP-Initiated Logout
- 🛡️ **CSRF Protection** - State parameter validation
- 💾 **WordPress Native** - Uses standard WordPress authentication

## Quick Start

### 1. Install Dependencies
```bash
composer install
```

### 2. Install Plugin
```bash
# Development (symlink)
ln -s $(pwd) /path/to/wordpress/wp-content/plugins/wp-oidc

# Production (copy)
cp -r . /path/to/wordpress/wp-content/plugins/wp-oidc
```

### 3. Configure
**Option A: Environment Variables (Recommended)**
```bash
cp .env.example .env
# Edit .env with your Keycloak credentials
```

**Option B: WordPress Admin**
- Go to Settings → OIDC Login
- Enter Keycloak configuration

### 4. Setup Keycloak Client
In Keycloak admin console:
1. Create OAuth 2.0 Confidential Client
2. Set Valid Redirect URIs: `https://example.com/wp-login.php?oidc_callback=1`
3. Copy Client ID and Client Secret

## Configuration

### Environment Variables (Recommended for Production)

```bash
WP_OIDC_ENABLED=1
WP_OIDC_ISSUER_URL=https://keycloak.example.com/realms/my-realm
WP_OIDC_CLIENT_ID=wordpress
WP_OIDC_CLIENT_SECRET=your-client-secret
WP_OIDC_REDIRECT_URI=https://example.com/wp-login.php?oidc_callback=1
```

See [CONFIG.md](CONFIG.md) for detailed setup options:
- `.env` file (development)
- Docker/Docker Compose
- Apache/Nginx
- wp-config.php
- Systemd
- Secrets Management Systems

### User Management

Users must be created **manually** in WordPress admin:
1. WordPress Admin → Users → Add New
2. Enter username and email
3. **Email must match Keycloak user email**

The plugin handles authentication only. User creation, roles, and permissions are managed separately.

## Documentation

- **[QUICKSTART.md](QUICKSTART.md)** - 5-minute setup guide
- **[INSTALLATION.md](INSTALLATION.md)** - Detailed installation & troubleshooting
- **[CONFIG.md](CONFIG.md)** - Environment configuration guide
- **[ARCHITECTURE.md](ARCHITECTURE.md)** - Technical design & development

## Requirements

- PHP 8.0+
- WordPress 5.0+
- Keycloak server with OIDC provider
- Composer (for dependencies)

## Dependencies

- [facile-it/php-openid-client](https://github.com/facile-it/php-openid-client) - OIDC protocol library

## How It Works

```
User visits /wp-login.php
  ↓ (Redirected to Keycloak)
User authenticates with Keycloak
  ↓ (Redirected back with authorization code)
Plugin exchanges code for tokens
  ↓ (Fetches email from userinfo)
Plugin finds WordPress user by email
  ↓ (Sets authentication cookie)
User logged into WordPress
```

## What This Plugin Does

✅ Replaces WordPress login form with OIDC
✅ Matches users by email address
✅ Handles authentication flow
✅ Redirects to Keycloak logout
✅ Supports backchannel logout (OIDC RP-Initiated Logout)

## What This Plugin Does NOT Do

❌ Auto-create WordPress users (manual creation required)
❌ Manage user roles or permissions
❌ Sync user data from Keycloak
❌ Support multiple email addresses per user

These are intentional limitations to keep the plugin lightweight and focused on authentication.

## Security

- ✅ CSRF protection via state parameter
- ✅ JWT signature verification
- ✅ Environment variables for secrets
- ✅ Standard WordPress authentication
- ⚠️ Use HTTPS in production (required by OIDC)

### Protecting Secrets

**Never commit `.env` file or client secrets to version control:**

```bash
# Add to .gitignore
.env
.env.local
```

Use environment variables or secure secrets management:
- AWS Secrets Manager
- HashiCorp Vault
- Kubernetes Secrets
- Docker Secrets

## Troubleshooting

### "User not found" Error
→ Create WordPress user with same email as Keycloak

### Plugin not redirecting to Keycloak
→ Check if OIDC is enabled in Settings → OIDC Login
→ Verify all required settings are filled

### "Invalid state parameter"
→ Session was lost - try logging in again

See [INSTALLATION.md](INSTALLATION.md) for more troubleshooting steps.

## Development

This plugin uses:
- PHP 8.0+ with PSR-4 autoloading
- WordPress hooks for integration
- Composer for dependency management
- Environment variables for configuration

### Project Structure

```
├── wp-oidc.php              # Main plugin file
├── composer.json            # PHP dependencies
├── .env.example             # Config template
├── includes/
│   ├── class-oidc-client.php           # OIDC protocol
│   ├── class-auth-handler.php          # WordPress hooks
│   ├── class-admin-settings.php        # Admin page
│   └── class-backchannel-logout.php    # Backchannel logout handler
└── Documentation
    ├── QUICKSTART.md
    ├── INSTALLATION.md
    ├── CONFIG.md
    └── ARCHITECTURE.md
```

## License

GPL-2.0-or-later

## Author

Dubovsky

## Contributing

Contributions are welcome. Please ensure:
- Code follows WordPress coding standards
- Changes are well-documented
- Environment variable handling is secure
- User-facing changes update documentation
