# Keycloak Logout Redirect Configuration

## Overview
This document explains how the Keycloak logout redirect functionality has been implemented to ensure users are redirected back to the application after logging out from Keycloak.

## Changes Made

### 1. Keycloak Client Configuration
Updated `keycloak-init.sh` to include post-logout redirect URIs in the client configuration:

```json
"attributes": {
    "post.logout.redirect.uris": "http://localhost:${APP_PORT}/*##http://${APP_NAME}-app:8080/*"
}
```

This allows Keycloak to accept logout redirect requests back to the application.

### 2. AuthController Logout Method
Enhanced the logout method in `AuthController.php` to:
- Accept a `redirect` query parameter to specify where to redirect after logout
- Use the OpenID Connect `post_logout_redirect_uri` parameter
- Include the `client_id` for proper client identification
- Optionally include `id_token_hint` for cleaner logout

### 3. Session Management
Updated the callback method to store the ID token:
```php
$_SESSION['id_token'] = $tokenData['id_token'] ?? null;
```

This ID token can be used as a hint during logout for better user experience.

## Usage

### Basic Logout
To logout and redirect to the homepage:
```html
<a href="/auth/logout">Logout</a>
```

### Logout with Custom Redirect
To logout and redirect to a specific page:
```html
<a href="/auth/logout?redirect=/dashboard">Logout</a>
```

### In Controllers
```php
// Redirect to login page after logout
header('Location: /auth/logout?redirect=/login');

// Redirect to custom page
header('Location: /auth/logout?redirect=/thank-you');
```

## How It Works

1. User clicks logout link with optional redirect parameter
2. AuthController builds Keycloak logout URL with:
   - `post_logout_redirect_uri`: Full URL where user should return
   - `client_id`: Identifies which client is requesting logout
   - `id_token_hint`: (optional) Helps Keycloak identify the session
3. User is redirected to Keycloak logout endpoint
4. Keycloak terminates the SSO session
5. Keycloak redirects user back to the specified URL

## Security Considerations

- The redirect URIs must be whitelisted in Keycloak client configuration
- The `post.logout.redirect.uris` attribute supports wildcards (*)
- Multiple URIs can be specified using ## as separator
- Always validate redirect URLs to prevent open redirect vulnerabilities

## Testing

To test the logout redirect:

1. Login to the application
2. Click logout - you should be redirected to Keycloak
3. After Keycloak logout, you should be redirected back to the app
4. Verify the session is cleared and you're on the correct page