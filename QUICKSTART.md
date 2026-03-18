# WordPress OIDC Plugin - Quick Start

## 5-Minute Setup

### 1. Install Dependencies (30 seconds)
```bash
composer install
```

### 2. Install Plugin (1 minute)
```bash
# Copy to WordPress plugins directory
cp -r /path/to/wp_oidc /path/to/wordpress/wp-content/plugins/wp-oidc

# Or create symlink (development)
ln -s /path/to/wp_oidc /path/to/wordpress/wp-content/plugins/wp-oidc
```

### 3. Activate (1 minute)
- WordPress Admin → Plugins
- Find "WordPress OIDC Login"
- Click "Activate"

### 4. Get Keycloak Credentials (1 minute)
From your Keycloak admin console:
1. Create a new Client (or use existing)
2. Set **Access Type** to "Confidential"
3. Under **Credentials** tab, copy the **Client Secret**
4. Under **Credentials** tab, copy the **Client ID**

### 5. Configure Plugin (1 minute)

**Option A: Environment Variables (Recommended for Production)**

1. Copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```

2. Edit `.env` with your actual values:
   ```bash
   WP_OIDC_ENABLED=1
   WP_OIDC_ISSUER_URL=https://keycloak.example.com/realms/your-realm
   WP_OIDC_CLIENT_ID=wordpress
   WP_OIDC_CLIENT_SECRET=your-secret
   ```

See [CONFIG.md](CONFIG.md) for detailed setup instructions.

**Option B: WordPress Admin (Development)**

- WordPress Admin → Settings → OIDC Login
- **Issuer URL:** `https://keycloak.example.com/realms/your-realm`
- **Client ID:** [From Keycloak]
- **Client Secret:** [From Keycloak Credentials]
- Check "Enable OIDC Login"
- Click "Save Changes"

### 6. Configure Keycloak (1 minute)
In Keycloak client settings:
- **Valid Redirect URIs:** `https://example.com/wp-login.php?oidc_callback=1`
- **Valid Post Logout Redirect URIs:** `https://example.com/wp-login.php`

## Create Test User

1. **WordPress Admin → Users → Add New**
   - Username: `testuser`
   - Email: `test@example.com` (must match Keycloak email)
   - Click "Add New User"

2. **Keycloak Admin Console → Users → Create User**
   - Username: `testuser`
   - Email: `test@example.com` (must match WordPress email)
   - Set password

## Test Login

1. Log out of WordPress (if logged in)
2. Visit `/wp-login.php`
3. You should be redirected to Keycloak
4. Log in with Keycloak credentials
5. You should be logged into WordPress

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "User not found" | Create WordPress user with matching email |
| Not redirecting to Keycloak | Enable OIDC in plugin settings |
| "invalid_client" error | Verify Client Secret matches |
| "redirect_uri_mismatch" | Check Valid Redirect URIs in Keycloak |

## File Structure

```
src/
├── wp-oidc.php                    # Main plugin file
├── composer.json                  # Dependencies
├── .gitignore                     # Git ignores
└── includes/
    ├── class-oidc-client.php      # OIDC protocol
    ├── class-auth-handler.php     # WordPress integration
    └── class-admin-settings.php   # Admin settings page
```

## Key Points

✅ Users matched by email only
✅ No automatic user creation
✅ No role/permission management
✅ Authentication only
✅ Simple WordPress integration

## Next Steps

1. Read **INSTALLATION.md** for detailed setup
2. Read **ARCHITECTURE.md** for technical details
3. Check **TROUBLESHOOTING.md** (see INSTALLATION.md) for common issues

## Support

- Check WordPress error logs
- Verify Keycloak client configuration
- Ensure emails match between systems
