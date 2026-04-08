# Views

Views are plain PHP templates. They live in `app/Views/` and use built-in helper functions.

## Directory Structure

```
app/Views/
├── layouts/
│   └── main.php
├── posts/
│   ├── index.php
│   ├── create.php
│   ├── edit.php
│   └── show.php
└── partials/
    ├── header.php
    └── footer.php
```

## Rendering from Controllers

```php
return $this->view('posts.index', ['posts' => $posts]);
// Renders app/Views/posts/index.php

return $this->cachedView('pages.about', [], 3600);
// Full-page cache for 1 hour

return $this->streamedView('reports.large', ['data' => $data]);
// Streamed render (lower memory, faster TTFB)
```

## Layouts

### Creating a Layout

```php
<!-- app/Views/layouts/main.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($title ?? 'Fw App') ?></title>
</head>
<body>
    <nav><!-- Navigation --></nav>

    <main>
        <?= $yield('content') ?>
    </main>

    <aside>
        <?= $yield('sidebar', '<p>Default sidebar</p>') ?>
    </aside>
</body>
</html>
```

### Using Layouts in Views

```php
<?php $this->layout('main'); ?>
<?php $title = 'All Posts'; ?>

<?php $section('content'); ?>
    <h1>Posts</h1>
    <?php foreach ($posts['items'] as $post): ?>
        <article>
            <h2><a href="<?= $url('posts.show', ['id' => $post->id]) ?>"><?= $e($post->title) ?></a></h2>
            <p><?= $strLimit($post->content, 150) ?></p>
            <time><?= $timeAgo($post->created_at) ?></time>
        </article>
    <?php endforeach; ?>
<?php $endSection(); ?>
```

### Setting Layout in Controller

```php
return $this->view('posts.index', ['posts' => $posts])
    ->layout('app');
```

## Helper Functions

### Escaping — `$e()`

```php
<h1><?= $e($post->title) ?></h1>
```

Always escape user-generated content. Wraps `htmlspecialchars()` with `ENT_QUOTES`.

### URL Generation — `$url()`

```php
<a href="<?= $url('posts.show', ['id' => $post->id]) ?>">View Post</a>
```

### CSRF Token — `$csrf()`

```php
<form method="POST" action="/posts">
    <?= $csrf() ?>
    <!-- form fields -->
</form>
```

### Old Input — `$old()`

```php
<input type="text" name="title" value="<?= $e($old('title', '')) ?>">
```

### String Helpers

```php
<?= $strLimit($post->content, 100) ?>     <!-- Truncate -->
<?= $strSlug($post->title) ?>             <!-- URL slug -->
<?= $strUpper($text) ?>                   <!-- HELLO -->
<?= $strLower($text) ?>                   <!-- hello -->
<?= $strTitle($text) ?>                   <!-- Hello World -->
<?= $strExcerpt($body, 'framework', 50) ?> <!-- ...PHP framework designed... -->
```

### Date Helpers

```php
<?= $formatDate($post->created_at) ?>              <!-- January 15, 2024 -->
<?= $formatDate($post->created_at, 'Y-m-d') ?>     <!-- 2024-01-15 -->
<?= $timeAgo($post->created_at) ?>                  <!-- 2 hours ago -->
```

### Fragment Caching

```php
<?php if ($cache('sidebar', 3600)): ?>
    <!-- Rendered only on cache miss, cached for 1 hour -->
    <nav>
        <?php foreach ($categories as $cat): ?>
            <a href="<?= $url('categories.show', ['id' => $cat->id]) ?>"><?= $e($cat->name) ?></a>
        <?php endforeach; ?>
    </nav>
<?php $endCache(); endif; ?>
```

## Including Partials

```php
<?= $this->include('partials.header', ['title' => $title]) ?>

<main><!-- Content --></main>

<?= $this->include('partials.footer') ?>
```

## Forms

### Create Form

```php
<form method="POST" action="/posts">
    <?= $csrf() ?>

    <div class="form-group">
        <label for="title">Title</label>
        <input type="text" id="title" name="title"
               value="<?= $e($old('title', '')) ?>" required>
    </div>

    <div class="form-group">
        <label for="content">Content</label>
        <textarea id="content" name="content" required><?= $e($old('content', '')) ?></textarea>
    </div>

    <button type="submit">Create Post</button>
</form>
```

### Edit Form (PUT)

```php
<form method="POST" action="/posts/<?= $post->id ?>">
    <?= $csrf() ?>
    <input type="hidden" name="_method" value="PUT">

    <input type="text" name="title" value="<?= $e($post->title) ?>" required>
    <button type="submit">Update Post</button>
</form>
```

### Delete Form

```php
<form method="POST" action="/posts/<?= $post->id ?>"
      onsubmit="return confirm('Are you sure?')">
    <?= $csrf() ?>
    <input type="hidden" name="_method" value="DELETE">
    <button type="submit">Delete</button>
</form>
```

## Displaying Errors

```php
<?php if (isset($errors) && !empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
```

## Pagination

```php
<?php if ($posts['last_page'] > 1): ?>
    <nav>
        <?php for ($i = 1; $i <= $posts['last_page']; $i++): ?>
            <a href="?page=<?= $i ?>"
               <?= $i === $posts['current_page'] ? 'class="active"' : '' ?>>
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
```

## Support Classes

Three utility classes are available in all views. See [View Helpers](helpers.md) for the full API reference.

- **`$Str`** — String utilities (`Fw\Support\Str`): `$Str::slug()`, `$Str::limit()`, `$Str::uuid()`, `$Str::of()` (fluent)
- **`$DateTime`** — DateTime utilities (`Fw\Support\DateTime`): `$DateTime::now()`, `$DateTime::parse()`, `->diffForHumans()`
- **`$Arr`** — Array utilities (`Fw\Support\Arr`): `$Arr::get()` (dot notation), `$Arr::pluck()`, `$Arr::where()`

## Reserved Variable Names

These cannot be used as view data keys:

`e`, `url`, `csrf`, `old`, `section`, `endSection`, `yield`, `strLimit`, `strSlug`, `strUpper`, `strLower`, `strTitle`, `strExcerpt`, `formatDate`, `timeAgo`, `Str`, `DateTime`, `Arr`, `path`, `data`, `this`, `cache`, `endCache`

## Cache Invalidation

```php
$view->invalidate('pages.about');           // Single page
$view->invalidateFragment('sidebar');        // Single fragment
$view->clearCache();                         // All cached views
```
