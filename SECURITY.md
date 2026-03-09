# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 2.1.x   | :white_check_mark: |
| 2.0.x   | :white_check_mark: |
| 1.x     | :x: (end of life)  |

## Reporting a Vulnerability

We take security seriously. If you discover a security vulnerability, please report it responsibly.

### How to Report

1. **Do NOT** create a public GitHub issue for security vulnerabilities
2. Email security concerns to: security@fw-framework.dev
3. Include as much detail as possible:
   - Type of vulnerability
   - Full path to the affected file(s)
   - Step-by-step reproduction instructions
   - Proof of concept (if applicable)
   - Impact assessment

### What to Expect

- **Response Time**: We aim to acknowledge reports within 48 hours
- **Updates**: We'll keep you informed of our progress
- **Resolution**: We target fixes within 14 days for critical issues
- **Credit**: We'll credit reporters in our security advisories (unless you prefer anonymity)

## Security Features

Fw Framework includes built-in protection against common vulnerabilities:

### CSRF Protection
- Automatic token generation and validation
- Timing-safe comparison prevents timing attacks
- Session-bound tokens with regeneration on login

### SQL Injection Prevention
- All queries use parameterized statements
- Operator whitelisting in QueryBuilder
- Identifier quoting for dynamic column/table names

### XSS Prevention
- Automatic HTML escaping in views via `$e()` helper
- Content-Type headers enforced
- Input sanitization helpers

### Mass Assignment Protection
- `$fillable` whitelist for allowed attributes
- Strict mode throws exceptions for undeclared attributes
- `forceFill()` explicitly bypasses protection (internal use only)

### Authentication Security
- Timing-safe password and token comparison
- Dummy hash comparison when user doesn't exist (prevents enumeration)
- HMAC-signed remember-me cookies
- Secure session configuration by default

### Serialization Security
- Queue job payloads are HMAC-signed
- Signature verification before unserialization
- Class allowlisting as defense-in-depth

### Rate Limiting
- Cache-backed request throttling
- Cryptographic hash for rate limit keys
- Configurable limits per route/IP

## Security Best Practices

When using Fw Framework:

1. **Environment Variables**: Never commit `.env` files. Use `.env.example` as a template.

2. **APP_KEY**: Generate a strong random key:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

3. **HTTPS**: Always use HTTPS in production. Enable HSTS:
   ```php
   $response->securityHeaders(hsts: true);
   ```

4. **Database**: Use separate database users with minimal privileges.

5. **File Uploads**: Configure allowed directories:
   ```php
   Response::setDownloadBasePaths(['/var/www/uploads']);
   ```

6. **Trusted Proxies**: Configure if behind a load balancer:
   ```php
   Request::setTrustedProxies(['10.0.0.0/8']);
   ```

7. **Debug Mode**: Never enable `app.debug = true` in production.

## Security Headers

Enable security headers in your responses:

```php
$response->securityHeaders(
    hsts: true,
    hstsMaxAge: 31536000,
    hstsIncludeSubdomains: true
);
```

This sets:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security` (when HSTS enabled)

## Changelog

Security fixes are documented in [CHANGELOG.md](CHANGELOG.md) with CVE identifiers when applicable.
