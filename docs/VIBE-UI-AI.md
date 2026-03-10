# VibeUI AI Blueprint

This document defines the architectural and stylistic standards for AI agents building frontend interfaces with VibeUI and VibeFW.

> **Source of truth:** https://github.com/velkymx/vibeui/tree/main/docs

---

## Component Principles

1. **VibeUI Primacy**: Always check for a `@velkymx/vibeui` component before building a custom one.
2. **Global Registration**: VibeUI is registered globally via `app.use(VibeUI)`. **Do not import individual components** — they are available in every template automatically.
3. **`validators` and `useFormValidation` must be imported** from `@velkymx/vibeui` — they are not globally registered.
4. **Always use `<VibeIcon>`** for icons. Never use raw `<i class="bi bi-...">` elements.
5. **Composition Over Inheritance**: Use slots and props to customize behavior.
6. **Scoped Styling**: Use `<style scoped>` for component-specific styles.
7. **Dark Mode**: Use Bootstrap CSS variables (`var(--bs-body-bg)`, `var(--bs-body-color)`, etc.) instead of hardcoded colors.

---

## Icons

### `VibeIcon`
Renders a Bootstrap Icon using the `icon` prop. Globally registered — no import needed.

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `icon` | String | **required** | Icon name without `bi-` prefix (e.g. `"house"`, `"heart-fill"`) |
| `size` | `'sm' \| 'lg' \| '1x' \| '2x' \| '3x' \| '4x' \| '5x'` | — | Predefined sizes |
| `fontSize` | String | — | Custom CSS font-size (e.g. `"1.5rem"`, `"24px"`) |
| `color` | String | — | CSS color value |
| `customClass` | String | — | Additional CSS classes |
| `flipH` | Boolean | false | Horizontal flip |
| `flipV` | Boolean | false | Vertical flip |
| `rotate` | `90 \| 180 \| 270` | — | Rotation angle |

```vue
<!-- Basic -->
<VibeIcon icon="house" />

<!-- Sized and colored -->
<VibeIcon icon="heart-fill" size="2x" color="var(--bs-danger)" />

<!-- With spacing class -->
<VibeIcon icon="arrow-right" custom-class="me-2" />

<!-- Transformed -->
<VibeIcon icon="chevron-right" :rotate="90" />
```

**Never do this:**
```vue
<!-- WRONG: raw Bootstrap Icon markup -->
<i class="bi bi-house"></i>
```

---

## Form Components

### `VibeFormGroup`
Intelligent container that provides automatic ID generation and label linking for the child input.

| Prop | Type | Default |
|------|------|---------|
| `label` | String | — |
| `labelFor` | String | auto |
| `required` | Boolean | false |
| `validationState` | `'valid' \| 'invalid' \| null` | null |
| `validationMessage` | String | — |
| `helpText` | String | — |
| `floating` | Boolean | false |
| `row` | Boolean | false |
| `labelCols` | Number\|String | — |
| `labelAlign` | `'start' \| 'center' \| 'end'` | — |

### `VibeFormInput`
Text input with integrated validation.

| Prop | Type | Default |
|------|------|---------|
| `modelValue` | String\|Number | `''` |
| `label` | String | — |
| `type` | InputType | `'text'` |
| `placeholder` | String | — |
| `disabled` | Boolean | false |
| `readonly` | Boolean | false |
| `required` | Boolean | false |
| `size` | `'sm' \| 'lg'` | — |
| `validationState` | `'valid' \| 'invalid' \| null` | null |
| `validationMessage` | String | — |
| `validateOn` | `'input' \| 'blur' \| 'change'` | `'blur'` |
| `helpText` | String | — |

### `VibeFormSelect`
Dropdown supporting single and multiple selection.

| Prop | Type | Default |
|------|------|---------|
| `modelValue` | any | `''` |
| `label` | String | — |
| `options` | `FormSelectOption[]` | `[]` |
| `multiple` | Boolean | false |
| `disabled` | Boolean | false |
| `required` | Boolean | false |
| `validationState` | `'valid' \| 'invalid' \| null` | null |
| `validationMessage` | String | — |

### `VibeFormCheckbox`
| Prop | Type | Default |
|------|------|---------|
| `modelValue` | any | false |
| `value` | any | true |
| `label` | String | — |
| `disabled` | Boolean | false |
| `inline` | Boolean | false |
| `indeterminate` | Boolean | false |

### `VibeFormRadio`
| Prop | Type | Default |
|------|------|---------|
| `modelValue` | any | `''` |
| `value` | any | **required** |
| `name` | String | **required** |
| `label` | String | — |
| `inline` | Boolean | false |

### `VibeFormTextarea`
| Prop | Type | Default |
|------|------|---------|
| `modelValue` | String | `''` |
| `label` | String | — |
| `rows` | Number\|String | 3 |
| `placeholder` | String | — |
| `noResize` | Boolean | false |

### Typical Form Pattern

```vue
<VibeFormGroup label="Email Address" help-text="We'll never share your email.">
  <VibeFormInput v-model="form.email" type="email" placeholder="you@example.com" />
</VibeFormGroup>

<VibeFormGroup label="Role">
  <VibeFormSelect v-model="form.role" :options="roleOptions" />
</VibeFormGroup>

<VibeButton variant="primary" :loading="submitting" @click="submit">
  Save
</VibeButton>
```

---

## Validation

Import `validators` and `useFormValidation` from the package — they are NOT globally available.

```ts
import { validators, useFormValidation } from '@velkymx/vibeui'

const { required, email, minLength, maxLength, pattern, url, min, max, async: asyncValidator } = validators
```

### `useFormValidation` Composable

```ts
const field = useFormValidation()
// field.value, field.validationState, field.validationMessage
// field.isDirty, field.isTouched, field.isValidating
// field.validate(rules), field.reset(), field.markAsTouched()
```

### Usage Pattern

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { validators, useFormValidation } from '@velkymx/vibeui'

const { required, email, minLength } = validators
const emailField = useFormValidation()

const form = ref({ email: '' })

const submit = async () => {
  const valid = await emailField.validate([required(), email()])
  if (!valid) return
  // submit...
}
</script>

<template>
  <VibeFormGroup
    label="Email"
    :validation-state="emailField.validationState"
    :validation-message="emailField.validationMessage"
  >
    <VibeFormInput
      v-model="form.email"
      type="email"
      validate-on="blur"
      :validation-state="emailField.validationState"
      :validation-message="emailField.validationMessage"
      @blur="emailField.markAsTouched()"
    />
  </VibeFormGroup>
</template>
```

### Backend 422 Validation Mapping

```vue
<script setup lang="ts">
const errors = ref<Record<string, string>>({})

const submit = async () => {
  try {
    await axios.post('/api/register', form.value, { headers: authHeaders() })
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
    }
  }
}
</script>

<template>
  <VibeFormInput
    v-model="form.email"
    label="Email"
    :validation-state="errors.email ? 'invalid' : null"
    :validation-message="errors.email"
  />
</template>
```

---

## Core Components

### `VibeButton`
| Prop | Type | Notes |
|------|------|-------|
| `variant` | String | primary, secondary, success, danger, warning, info, light, dark |
| `size` | `'sm' \| 'lg'` | — |
| `outline` | Boolean | — |
| `disabled` | Boolean | — |
| `loading` | Boolean | Shows spinner, disables button |
| `type` | String | button, submit, reset |
| `href` | String | Renders as `<a>` |
| `to` | String\|Object | Router-link |

### `VibeAlert`
| Prop | Type | Notes |
|------|------|-------|
| `variant` | String | success, danger, warning, info, etc. |
| `modelValue` | Boolean | v-model for show/hide |
| `dismissable` | Boolean | Adds close button |
| `message` | String | Content (or use default slot) |
| `fade` | Boolean | Fade transition |

### `VibeCard`
| Prop | Type | Notes |
|------|------|-------|
| `title` | String | Card heading |
| `body` | String | Card body text |
| `header` | String | Header text |
| `footer` | String | Footer text |
| `variant` | String | Background color |
| `border` | String | Border color |
| `textVariant` | String | Text color |
| `imgSrc` | String | Image URL |
| `imgTop` | Boolean | Image above content (default) |
| `imgBottom` | Boolean | Image below content |

Slots: `default`, `header`, `title`, `body`, `footer`

```vue
<VibeCard title="Welcome" variant="light">
  <template #body>
    <p>Card content goes here.</p>
    <VibeButton variant="primary">Action</VibeButton>
  </template>
</VibeCard>
```

### `VibeModal`
| Prop | Type | Notes |
|------|------|-------|
| `modelValue` | Boolean | v-model open/close |
| `title` | String | — |
| `size` | `'sm' \| 'lg' \| 'xl'` | — |
| `centered` | Boolean | — |
| `scrollable` | Boolean | — |
| `staticBackdrop` | Boolean | Prevent close on backdrop click |
| `hideHeader` | Boolean | — |
| `hideFooter` | Boolean | — |

### `VibeToast`
Use for transient notifications. **There is no `useToast()` composable or `VToaster` component.**

| Prop | Type | Notes |
|------|------|-------|
| `modelValue` | Boolean | v-model show/hide |
| `title` | String | — |
| `variant` | String | — |
| `autohide` | Boolean | — |
| `delay` | Number | ms before auto-hide |
| `placement` | String | Position on screen |

### `VibeSpinner`
| Prop | Type | Notes |
|------|------|-------|
| `type` | `'border' \| 'grow'` | default: border |
| `variant` | String | — |
| `size` | `'sm'` | — |

### `VibeDataTable`
| Prop | Type | Notes |
|------|------|-------|
| `items` | Array | Row data |
| `columns` | Array | **required** — column definitions |
| `searchable` | Boolean | — |
| `sortable` | Boolean | — |
| `paginated` | Boolean | — |
| `perPage` | Number | — |
| `striped` | Boolean | — |
| `hover` | Boolean | — |

### Other Components
- `VibeButtonGroup` — group of related buttons
- `VibeBadge` — inline status badge (`variant`, `pill`)
- `VibeAccordion` — collapsible sections (`id`, `items`, `flush`, `alwaysOpen`)
- `VibeDropdown` — dropdown menu (`text`, `variant`, `items`, `direction`)
- `VibeCollapse` — toggleable content region (`id`, `modelValue`)
- `VibeOffcanvas` — slide-in panel (`id`, `modelValue`, `title`, `placement`)
- `VibeListGroup` — styled list (`items`, `flush`, `horizontal`)
- `VibePopover` — contextual popover (`title`, `content`, `placement`, `trigger`)
- `VibeTooltip` — hover tooltip (`content`, `placement`, `trigger`)
- `VibeProgress` — progress bar (`bars` array with `value`, `max`, `variant`)
- `VibeNavbar` — navigation bar (`variant`, `expand`, `container`)

---

## API Interaction Standards

1. **Axios directly** — Import `axios` from `'axios'`. Attach Bearer token per-request or via an interceptor.
2. **Auth token** — Store in `localStorage`, key `'token'`. Remove on logout.

```ts
const authHeaders = () => ({
  Authorization: `Bearer ${localStorage.getItem('token')}`
})

const response = await axios.get('/api/user', { headers: authHeaders() })
```

---

## State Management

- Use **Pinia** for global user state and data shared across routes.
- Keep component-local state simple with `ref()` and `reactive()`.
- Do not use Vuex.

---

## Tech Stack Versions

| Package | Version |
|---------|---------|
| `vue` | `^3.x` |
| `vue-router` | `^5.x` |
| `pinia` | `^3.x` |
| `vite` | `^7.x` |
| `@vitejs/plugin-vue` | `^6.x` |
| `axios` | `^1.x` |
| `@velkymx/vibeui` | latest |

---

## AI Instructions

When generating or refactoring SPA components:

- **Never import VibeUI components** — globally registered, zero imports needed.
- **Do import** `validators` and `useFormValidation` from `@velkymx/vibeui` when doing validation.
- **Never use** `VInput`, `VButton`, `VCard`, `VToaster`, `useToast()` — these do not exist.
- Use `VibeToast` (v-model) for notifications, not a toast composable.
- **Always use `<VibeIcon icon="name" />`** for icons. Never use raw `<i class="bi bi-...">` elements.
- Use **Bootstrap CSS variables** for colors (`var(--bs-body-bg)`, `var(--bs-primary)`, etc.) — never hardcode hex values. This ensures dark mode compatibility.
- Maintain **TypeScript** throughout (`<script setup lang="ts">`).
- Follow **Vue 3 Composition API** — Script Setup only.
- Use **Vue Router 5 return-value style** navigation guards — the `next()` callback is deprecated:
  ```ts
  router.beforeEach((to) => {
    if (to.meta.auth && !localStorage.getItem('token')) return '/login'
  })
  ```
- Ensure all interactive elements provide **visual feedback** — `loading` prop on `VibeButton`, `VibeSpinner` during data fetches.
- Use Bootstrap utility classes for layout and spacing.
