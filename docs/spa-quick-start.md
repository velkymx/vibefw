# SPA Quick Start Guide

Build a full-stack Vue 3 + PHP application with VibeFW in under 5 minutes.

## Prerequisites

- PHP 8.5+
- Composer
- Node.js 18+ and npm
- SQLite (default) or MySQL/PostgreSQL

## 1. Create a New Project

```bash
composer create-project velkymx/vibefw my-app
cd my-app
```

That's it — Composer automatically creates `.env` with secure keys, sets up storage, creates the SQLite database, and runs migrations. Everything is ready to go.

> **Cloned the repo instead?** Run `php fw setup` to do the same thing.

## 2. Scaffold the SPA

```bash
php fw make:spa
```

This single command:
- Creates `app/Controllers/Api/` — PHP API endpoints for auth, dashboard stats, profile, and token management
- Creates `frontend/` — Vue 3 + TypeScript + Vite SPA with routing, auth forms, and a dashboard
- Updates `config/routes.php` — API routes wired to the new controllers
- Runs `npm install` and `npm run build` automatically

## 3. Start the Dev Server

```bash
# Terminal 1: PHP backend (serves API + built SPA)
php fw serve

# Terminal 2 (optional): Vite dev server with hot-reload
cd frontend && npm run dev
```

Visit `http://localhost:8000` to see your app. The Vite dev server on port 5173 proxies API calls to the PHP backend.

## 4. Register and Log In

1. Click **Get Started** on the landing page
2. Fill in the registration form
3. You're automatically logged in and redirected to the Dashboard

## What's Included

### Frontend (Vue 3 + [VibeUI](VIBE-UI-AI.md))

| Page | Route | Description |
|------|-------|-------------|
| Home | `/` | Public landing page with feature cards |
| Login | `/login` | Authentication form |
| Register | `/register` | Registration form |
| Dashboard | `/dashboard` | Protected stats dashboard |
| 404 | `/*` | Catch-all error page |

### Backend (PHP API)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/auth/login` | No | Authenticate and get token |
| POST | `/api/auth/register` | No | Create account and get token |
| POST | `/api/auth/logout` | Yes | Invalidate token |
| GET | `/api/user` | Yes | Get authenticated user |
| GET | `/api/dashboard/stats` | Yes | Dashboard statistics |
| PUT | `/api/profile` | Yes | Update user profile |
| PUT | `/api/profile/password` | Yes | Change password |
| DELETE | `/api/profile` | Yes | Delete account |
| GET | `/api/tokens` | Yes | List API tokens |
| POST | `/api/tokens` | Yes | Create API token |
| DELETE | `/api/tokens/{id}` | Yes | Revoke API token |

## Adding Your First Page

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

### 4. Add a PHP API endpoint (if needed)

```php
// app/Controllers/Api/SettingsController.php
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

```php
// In config/routes.php, inside the /api group:
$router->get('/settings', [App\Controllers\Api\SettingsController::class, 'index'])
    ->middleware('auth:api');
```

## VibeUI Component Cheat Sheet

All components are globally registered — no imports needed. For full prop reference see [VIBE-UI-AI.md](VIBE-UI-AI.md).

```vue
<!-- Buttons -->
<VibeButton variant="primary" :loading="saving">Save</VibeButton>
<VibeButton variant="danger" outline size="sm" @click="remove">Delete</VibeButton>

<!-- Forms -->
<VibeFormGroup label="Email">
  <VibeFormInput v-model="email" type="email" placeholder="you@example.com" />
</VibeFormGroup>

<!-- Cards -->
<VibeCard title="Title">
  <template #body>Content here</template>
</VibeCard>

<!-- Icons (Bootstrap Icons — see https://icons.getbootstrap.com) -->
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

Full component reference: [VIBE-UI-AI.md](VIBE-UI-AI.md) — props, slots, form validation, dark mode. Also see [spa.md](spa.md) for the complete SPA scaffold documentation.

## Production Deployment

```bash
# Build the frontend
cd frontend && npm run build

# Optimize the PHP backend
php fw optimize

# Start with FrankenPHP for maximum performance
frankenphp php-server --root public/ --listen :8080 --worker public/index.php
```

## Troubleshooting

| Problem | Solution |
|---------|----------|
| `npm install` fails | Ensure Node.js 18+ is installed: `node --version` |
| 401 on API calls | Check that migrations have run: `php fw migrate:status` |
| Blank page after build | Ensure `public/spa/` exists and Vite built successfully |
| CORS errors in dev | The Vite proxy handles this — make sure `npm run dev` is running |
| Dark mode not working | Use `var(--bs-*)` CSS variables, not hardcoded hex colors |
