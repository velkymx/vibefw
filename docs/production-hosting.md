# Production Hosting Guide

This guide covers optimal production deployment for the FW framework on PHP 8.5.

## System Requirements

### PHP 8.5+

The framework requires PHP 8.5 or later for:
- Property hooks
- Asymmetric visibility (`public private(set)`)
- `array_first()` / `array_last()` functions
- Pipe operator support
- `readonly` properties for Request object

### Required PHP Extensions

```bash
# Core extensions
php-fpm
php-pdo
php-mbstring
php-json
php-openssl
php-tokenizer

# Database (choose one)
php-mysql    # MySQL/MariaDB
php-pgsql    # PostgreSQL
php-sqlite3  # SQLite

# Performance (highly recommended)
php-apcu     # In-memory cache
php-opcache  # Opcode cache

# Optional
php-redis    # Redis cache/sessions
php-curl     # HTTP client
php-gd       # Image processing
php-intl     # Internationalization
```

### Install on Ubuntu/Debian

```bash
# Add PHP 8.5 repository
sudo add-apt-repository ppa:ondrej/php
sudo apt update

# Install PHP 8.5 with extensions
sudo apt install php8.5-fpm php8.5-cli php8.5-pdo php8.5-mysql \
    php8.5-mbstring php8.5-xml php8.5-curl php8.5-apcu php8.5-opcache
```

### Install on Alpine (Docker)

```dockerfile
FROM php:8.5-fpm-alpine

RUN apk add --no-cache \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql opcache \
    && pecl install apcu \
    && docker-php-ext-enable apcu
```

---

## PHP Configuration

### php.ini (Production)

```ini
[PHP]
; Error handling - log, don't display
display_errors = Off
display_startup_errors = Off
error_reporting = E_ALL
log_errors = On
error_log = /var/log/php/error.log

; Performance
memory_limit = 256M
max_execution_time = 30
max_input_time = 60
post_max_size = 50M
upload_max_filesize = 50M

; Security
expose_php = Off
allow_url_fopen = Off
allow_url_include = Off

; Session security
session.cookie_httponly = On
session.cookie_secure = On
session.cookie_samesite = Strict
session.use_strict_mode = On

[opcache]
opcache.enable = On
opcache.enable_cli = Off
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = Off  ; Disable for production
opcache.save_comments = On
opcache.jit = On
opcache.jit_buffer_size = 128M

[apcu]
apc.enabled = On
apc.shm_size = 128M
apc.ttl = 7200
apc.enable_cli = Off
```

### php-fpm.conf (Production)

```ini
[www]
user = www-data
group = www-data

listen = /run/php/php8.5-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Process management
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 1000

; Timeouts
request_terminate_timeout = 30s
request_slowlog_timeout = 5s
slowlog = /var/log/php/slow.log

; Security
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_admin_flag[expose_php] = off
```

---

## Nginx Configuration

### Basic Setup

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name example.com www.example.com;

    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name example.com www.example.com;

    root /var/www/app/public;
    index index.php;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_stapling on;
    ssl_stapling_verify on;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml application/json application/javascript
               application/rss+xml application/atom+xml image/svg+xml;

    # Static file caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2|ttf|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Block hidden files
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # Block sensitive files
    location ~* (composer\.json|composer\.lock|\.env|phpunit\.xml)$ {
        deny all;
    }

    # Main location - route all requests through index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # Timeouts
        fastcgi_connect_timeout 60s;
        fastcgi_send_timeout 60s;
        fastcgi_read_timeout 60s;

        # Buffers
        fastcgi_buffer_size 128k;
        fastcgi_buffers 256 16k;
        fastcgi_busy_buffers_size 256k;
    }
}
```

### High-Traffic Configuration

For high-traffic sites, add microcaching:

```nginx
# Add to http block
fastcgi_cache_path /var/cache/nginx levels=1:2 keys_zone=FWCACHE:100m inactive=60m;
fastcgi_cache_key "$scheme$request_method$host$request_uri";

# Add to server block
set $skip_cache 0;

# Don't cache POST requests
if ($request_method = POST) {
    set $skip_cache 1;
}

# Don't cache authenticated users
if ($http_cookie ~* "session") {
    set $skip_cache 1;
}

# Add to PHP location
location ~ \.php$ {
    # ... existing config ...

    fastcgi_cache FWCACHE;
    fastcgi_cache_valid 200 60m;
    fastcgi_cache_bypass $skip_cache;
    fastcgi_no_cache $skip_cache;
    add_header X-Cache-Status $upstream_cache_status;
}
```

---

## FrankenPHP (Worker Mode)

For maximum performance, use FrankenPHP in worker mode. This keeps the application bootstrapped between requests, achieving 5,000-10,000+ req/sec.

### Installation

```bash
# Download FrankenPHP
curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 -o /usr/local/bin/frankenphp
chmod +x /usr/local/bin/frankenphp
```

### Caddyfile Configuration

```caddyfile
{
    frankenphp
    order php_server before file_server
}

example.com {
    root * /var/www/app/public

    # Enable compression
    encode zstd gzip

    # Security headers
    header {
        X-Frame-Options "SAMEORIGIN"
        X-Content-Type-Options "nosniff"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
        -Server
    }

    # Static files
    @static {
        path *.css *.js *.ico *.gif *.jpg *.jpeg *.png *.svg *.woff *.woff2
    }
    header @static Cache-Control "public, max-age=31536000, immutable"

    # PHP worker mode
    php_server {
        worker /var/www/app/public/index.php
        num_threads 4
    }
}
```

### Worker Mode Bootstrap

Create `public/index.php` for worker mode. In VibeFW v2.0.0, the `HttpKernel` automatically handles state resetting and Fiber isolation, making the worker loop much simpler:

```php
<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\SapiEmitter;

// 1. Initialize Application (Boots only once)
$app = Application::getInstance();
$kernel = $app->getKernel();
$emitter = new SapiEmitter();

// 2. Define the Request Handler
$handler = static function () use ($kernel, $emitter) {
    // In FrankenPHP, superglobals are populated per-request inside the worker loop
    $request = Request::createFromGlobals();
    
    // handle() returns a Response object and automatically resets state 
    // (resets Connection, clears Cache L1, flushes Container context)
    $response = $kernel->handle($request);
    
    $emitter->emit($response);
};

// 3. Execution Loop
if (function_exists('frankenphp_handle_request')) {
    // FrankenPHP Worker Mode
    while (frankenphp_handle_request($handler)) {
        // Application remains in memory
    }
} else {
    // Standard PHP-FPM / CLI-Server Mode
    $handler();
}
```

---

## Environment Configuration

### .env (Production)

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

# Security
APP_KEY=base64:your-32-character-random-key-here
SECURE_COOKIES=true

# Database
DB_ENABLED=true
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=myapp_user
DB_PASSWORD=secure_password
DB_LOGGING=false

# Cache
CACHE_DRIVER=apcu

# Logging
LOG_ENABLED=true
LOG_LEVEL=warning
LOG_PATH=/var/log/app

# Queue (if using)
QUEUE_DRIVER=database
QUEUE_TABLE=jobs

# Trusted Proxies (if behind load balancer)
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
```

### Trusted Proxies

If behind a load balancer or CDN, configure trusted proxies in your bootstrap:

```php
// config/app.php or bootstrap
use Fw\Core\Request;

Request::setTrustedProxies([
    '10.0.0.0/8',      // AWS internal
    '172.16.0.0/12',   // Docker networks
    '192.168.0.0/16',  // Private networks
    '173.245.48.0/20', // Cloudflare
    '103.21.244.0/22', // Cloudflare
    // Add your load balancer IPs
]);
```

---

## Security Hardening

### File Permissions

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/app

# Directories: readable and traversable
sudo find /var/www/app -type d -exec chmod 755 {} \;

# Files: readable only
sudo find /var/www/app -type f -exec chmod 644 {} \;

# Storage directories: writable
sudo chmod -R 775 /var/www/app/storage
sudo chmod -R 775 /var/www/app/storage/cache
sudo chmod -R 775 /var/www/app/storage/logs

# Protect sensitive files
sudo chmod 600 /var/www/app/.env
```

### Download Path Restrictions

Configure allowed download directories:

```php
// config/app.php or bootstrap
use Fw\Core\Response;

Response::setDownloadBasePaths([
    BASE_PATH . '/storage/downloads',
    BASE_PATH . '/public/files',
]);
```

### Request Limits

Configure request body limits:

```php
// config/app.php or bootstrap
use Fw\Core\Request;

Request::setMaxBodySize(10 * 1024 * 1024);  // 10MB
Request::setReadTimeout(30);                 // 30 seconds
```

---

## Performance Optimization

### Route Caching

Cache compiled routes for production:

```php
// config/routes.php
$router->setCacheFile(BASE_PATH . '/storage/cache/routes.php');

// During deployment, clear cache
unlink(BASE_PATH . '/storage/cache/routes.php');
```

### View Caching

Views are automatically cached. Clear during deployment:

```bash
rm -rf storage/cache/views/*
```

### OPcache Preloading

Create `preload.php` for OPcache:

```php
<?php
// preload.php - load frequently used classes

require_once __DIR__ . '/vendor/autoload.php';

// Preload core framework classes
$preload = [
    'Fw\\Core\\Application',
    'Fw\\Core\\Container',
    'Fw\\Core\\Router',
    'Fw\\Core\\Request',
    'Fw\\Core\\Response',
    'Fw\\Core\\View',
    'Fw\\Database\\Connection',
    'Fw\\Model\\Model',
];

foreach ($preload as $class) {
    if (class_exists($class)) {
        // Class is now preloaded
    }
}
```

Add to `php.ini`:

```ini
opcache.preload = /var/www/app/preload.php
opcache.preload_user = www-data
```

---

## Deployment Checklist

### Pre-Deployment

- [ ] Set `APP_DEBUG=false`
- [ ] Set `APP_ENV=production`
- [ ] Generate production `APP_KEY`
- [ ] Configure database credentials
- [ ] Set `DB_LOGGING=false`
- [ ] Configure trusted proxies
- [ ] Run `composer install --no-dev --optimize-autoloader`

### Deployment Steps

```bash
# 1. Pull latest code
git pull origin main

# 2. Install dependencies (no dev)
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Clear caches
rm -rf storage/cache/*
php fw cache:clear

# 4. Run migrations
php fw migrate --force

# 5. Restart PHP-FPM
sudo systemctl restart php8.5-fpm

# 6. Restart FrankenPHP (if using)
sudo systemctl restart frankenphp
```

### Post-Deployment Verification

```bash
# Check PHP-FPM status
sudo systemctl status php8.5-fpm

# Check error logs
tail -f /var/log/php/error.log
tail -f /var/log/app/fw-$(date +%Y-%m-%d).log

# Test response
curl -I https://example.com
```

---

## Monitoring

### Health Check Endpoint

Add a health check route:

```php
// config/routes.php
$router->get('/health', function () {
    return [
        'status' => 'ok',
        'timestamp' => time(),
        'php_version' => PHP_VERSION,
    ];
}, 'health');
```

### Log Rotation

Create `/etc/logrotate.d/fw-app`:

```
/var/log/app/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
    postrotate
        systemctl reload php8.5-fpm > /dev/null 2>&1 || true
    endscript
}
```

---

## Troubleshooting

### Common Issues

**502 Bad Gateway**
- Check PHP-FPM is running: `systemctl status php8.5-fpm`
- Check socket permissions
- Check PHP-FPM error log

**Slow Response Times**
- Enable OPcache JIT
- Check database query log
- Enable APCu caching
- Consider FrankenPHP worker mode

**Memory Issues in Worker Mode**
- Reduce `$maxRequests` in worker loop
- Call `Model::clearMetadataCache()` more frequently
- Check for memory leaks in custom code

**Session Issues Behind Load Balancer**
- Use database or Redis sessions
- Configure sticky sessions on load balancer
- Ensure `TRUSTED_PROXIES` is set correctly
