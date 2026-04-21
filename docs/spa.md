# START HERE

Best practices for this part of the codebase are to use the following CLI commands.

- `php fw make:spa` — scaffold the Vue 3 + TypeScript + VibeUI frontend and matching API starter kit
- `php fw make:spa --test` — same, with Vitest setup wired up
- `php fw make:spa --force` — overwrite existing `frontend/` (use with care)
- `php fw dev` — start backend and frontend dev servers concurrently (the normal dev loop)
- `php fw serve` — backend only (port 8000 by default)
- `php fw env:sync` — mirror `.env` keys into `frontend/.env.local` with the `VITE_` prefix
- `php fw routes:list` — verify your API routes after generating controllers

# BEWARE

Only read past here if you are unable to use the CLI.

# SPA (Vue 3 + TypeScript)

VibeFW includes a full-stack SPA scaffold: Vue 3 + TypeScript + VibeUI frontend with a PHP API backend.

## Quick Start

```bash
# 1. Create project
composer create-project velkymx/vibefw my-app
cd my-app

# 2. Scaffold the SPA
php fw make:spa

# 3. Start dev servers
php fw serve                        # PHP backend on :8000
cd frontend && npm run dev          # Vite HMR on :5173
```

Visit `http://localhost:8000`. The Vite dev server on port 5173 proxies API calls to the PHP backend.

## What `make:spa` Generates

### Frontend (`frontend/`)

| File | Description |
|------|-------------|
| `src/views/Home.vue` | Public landing page |
| `src/views/auth/Login.vue` | Login form |
| `src/views/auth/Register.vue` | Registration form |
| `src/views/Dashboard.vue` | Protected dashboard |
| `src/views/errors/NotFound.vue` | 404 page |
| `src/layouts/MainLayout.vue` | Sidebar + topbar shell |
| `src/router/index.ts` | Vue Router with auth guards |
| `src/types/api.ts` | TypeScript interfaces for API responses |
| `src/main.ts` | App bootstrap — Vue, Router, Pinia, VibeUI |

### Backend (`app/`)

| File | Description |
|------|-------------|
| `app/Controllers/Api/Auth/LoginController.php` | Login + logout |
| `app/Controllers/Api/Auth/RegisterController.php` | Registration |
| `app/Controllers/Api/ProfileController.php` | Profile update + delete |
| `app/Controllers/Api/StatsController.php` | Dashboard stats |
| `app/Controllers/Api/ApiTokenController.php` | Token CRUD |
| `app/Requests/LoginRequest.php` | Login validation (typed rules) |
| `app/Requests/RegisterRequest.php` | Registration validation |
| `app/Requests/UpdateProfileRequest.php` | Profile validation |
| `app/Requests/UpdatePasswordRequest.php` | Password validation |
| `app/Requests/CreateTokenRequest.php` | Token validation |

### API Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/auth/login` | No | Authenticate, returns token |
| POST | `/api/auth/register` | No | Create account, returns token |
| POST | `/api/auth/logout` | Yes | Invalidate token |
| GET | `/api/user` | Yes | Current user |
| GET | `/api/dashboard/stats` | Yes | Dashboard statistics |
| PUT | `/api/profile` | Yes | Update profile |
| PUT | `/api/profile/password` | Yes | Change password |
| DELETE | `/api/profile` | Yes | Delete account |
| GET | `/api/tokens` | Yes | List API tokens |
| POST | `/api/tokens` | Yes | Create token |
| DELETE | `/api/tokens/{id}` | Yes | Revoke token |

### Database Migrations

The scaffold includes migrations for:
- `users` — name, email, password, remember token
- `personal_access_tokens` — API token storage with abilities
- `password_resets` — Password reset tokens
- `email_verifications` — Email verification tokens
- `jobs` — Queue jobs table

## Tech Stack

| Package | Version | Purpose |
|---------|---------|---------|
| Vue | 3.5+ | Frontend framework |
| Vue Router | 5.0+ | Client-side routing |
| Pinia | 3.0+ | State management |
| Axios | 1.15+ | HTTP client |
| VibeUI | 0.8+ | Vue 3 component library (Bootstrap 5) |
| Bootstrap | 5.3+ | CSS framework |
| Bootstrap Icons | 1.13+ | Icon set |
| Vite | 8.0+ | Build tool |
| TypeScript | 6.0+ | Type safety |

## VibeUI Components

All VibeUI components are globally registered — no imports needed. Full prop reference and component API: [VIBE-UI-AI.md](VIBE-UI-AI.md).

```vue
<!-- Buttons -->
<VibeButton variant="primary" :loading="saving">Save</VibeButton>
<VibeButton variant="danger" outline size="sm" @click="remove">Delete</VibeButton>

<!-- Forms -->
<VibeFormGroup label="Email">
  <VibeFormInput v-model="email" type="email" placeholder="you@example.com" />
</VibeFormGroup>

<VibeFormGroup label="Role">
  <VibeFormSelect v-model="role" :options="[
    { value: 'user', text: 'User' },
    { value: 'admin', text: 'Admin' }
  ]" />
</VibeFormGroup>

<!-- Cards -->
<VibeCard title="Stats">
  <template #body>Content here</template>
</VibeCard>

<!-- Icons (Bootstrap Icons) -->
<VibeIcon icon="house" />
<VibeIcon icon="heart-fill" size="2x" color="var(--bs-danger)" />

<!-- Alerts -->
<VibeAlert v-model="showSuccess" variant="success" message="Saved!" dismissable />

<!-- Data tables -->
<VibeDataTable :items="users" :columns="columns" searchable sortable paginated />

<!-- Modals -->
<VibeModal v-model="showModal" title="Confirm" centered>
  <template #default>Are you sure?</template>
</VibeModal>
```

Full component reference: [VIBE-UI-AI.md](VIBE-UI-AI.md) — props, slots, validation integration, dark mode patterns.

## Adding a New Page

### 1. Create the Vue component

```vue
<!-- frontend/src/views/Settings.vue -->
<script setup lang="ts">
import { ref } from 'vue'

const theme = ref('light')
</script>

<template>
  <div class="p-4">
    <h1 class="fw-bold">Settings</h1>
    <VibeCard class="mt-4">
      <template #body>
        <VibeFormGroup label="Theme">
          <VibeFormSelect v-model="theme" :options="[
            { value: 'light', text: 'Light' },
            { value: 'dark', text: 'Dark' }
          ]" />
        </VibeFormGroup>
      </template>
    </VibeCard>
  </div>
</template>
```

### 2. Add the route

```ts
// frontend/src/router/index.ts
import Settings from '../views/Settings.vue'

// Add inside the MainLayout children array:
{ path: 'settings', name: 'settings', component: Settings },
```

### 3. Add a sidebar link

```vue
<!-- In MainLayout.vue, inside the <nav> -->
<router-link to="/settings" class="nav-link py-2 px-3 rounded mb-1" active-class="active">
  <VibeIcon icon="gear" custom-class="me-2" /> Settings
</router-link>
```

### 4. Add API endpoint (if needed)

```php
// app/Controllers/Api/SettingsController.php
<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json(['theme' => 'light']);
    }
}
```

Route in `config/routes.php` inside the API group with `ApiAuthMiddleware`:

```php
$router->get('/settings', [Api\SettingsController::class, 'index']);
```

## Authentication Flow

1. User submits login/register form
2. Vue sends POST to `/api/auth/login` or `/api/auth/register`
3. PHP validates with FormRequest (typed rules), creates session + API token
4. Token stored in `localStorage`, sent as `Authorization: Bearer <token>` header
5. Vue Router guards check auth state, redirect to `/login` if unauthenticated
6. Protected API routes use `ApiAuthMiddleware` to verify token

## Dark Mode

VibeUI supports Bootstrap's dark mode via CSS variables. Toggle with:

```ts
document.documentElement.setAttribute('data-bs-theme', 'dark')
```

Use `var(--bs-*)` CSS variables (not hardcoded hex colors) so all components adapt automatically.

## Building for Production

```bash
cd frontend && npm run build    # Outputs to public/spa/
php fw optimize                 # Cache routes + config
```

The built SPA is served as static files from `public/spa/`. The PHP backend serves the API.

### With FrankenPHP (recommended)

```bash
frankenphp php-server --root public/ --listen :8080 --worker public/index.php
```

### With PHP built-in server

```bash
php fw serve
```

## Testing

```bash
# Unit tests (Vitest)
cd frontend && npm run test:unit

# E2E tests (Playwright)
cd frontend && npm run test:e2e
```

## Troubleshooting

| Problem | Solution |
|---------|----------|
| `npm install` fails | Ensure Node.js 18+ is installed: `node --version` |
| 401 on API calls | Check migrations: `php fw migrate:status` |
| Blank page after build | Ensure `public/spa/` exists: `cd frontend && npm run build` |
| CORS errors in dev | Use the Vite proxy — run `npm run dev` |
| Dark mode broken | Use `var(--bs-*)` variables, not hardcoded colors |
| FrankenPHP 404 | Add `--root public/` flag |
