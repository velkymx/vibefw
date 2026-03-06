# Views

Views are plain PHP templates that render HTML. They live in `app/Views/` and use simple, familiar PHP syntax.

## Directory Structure

```
app/Views/
├── layouts/
│   └── app.php           # Main layout template
├── home/
│   └── index.php
├── posts/
│   ├── index.php
│   ├── create.php
│   ├── edit.php
│   └── show.php
└── partials/
    ├── header.php
    └── footer.php
```

## Basic Usage

### Rendering from Controller

```php
// Renders app/Views/posts/index.php
return $this->view('posts.index', ['posts' => $posts]);

// With layout
return $this->view('posts.index', ['posts' => $posts]);
// Layout is set in the view or controller
```

### View Structure

```php
<?php $title = 'All Posts'; ?>

<h1>Posts</h1>

<?php foreach ($posts as $post): ?>
    <article>
        <h2><?= $e($post->title) ?></h2>
        <p><?= $strLimit($post->content, 200) ?></p>
        <a href="/posts/<?= $post->id ?>">Read more</a>
    </article>
<?php endforeach; ?>
```

## Layouts

### Creating a Layout

```php
<!-- app/Views/layouts/app.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?? 'My App' ?></title>
    <link href="/css/app.css" rel="stylesheet">
</head>
<body>
    <nav>
        <!-- Navigation -->
    </nav>

    <main class="container">
        <?= $content ?>
    </main>

    <footer>
        <!-- Footer -->
    </footer>

    <script src="/js/app.js"></script>
</body>
</html>
```

### Using Layouts in Controller

While you can define the layout within your view template, you can also specify it in your controller. The `Response` object is an immutable value object, and methods like `layout()` return a new instance with the updated configuration.

```php
public function index(Request $request): Response
{
    $posts = Post::all();
    
    // Set the layout and pass data to the view
    return $this->view('posts.index', compact('posts'))
        ->layout('app');
}
```

### Setting Title

```php
<?php $title = 'Edit Post'; ?>

<!-- Rest of view content -->
```

## Helper Functions

### Escaping Output

```php
// Using $e() helper
<h1><?= $e($post->title) ?></h1>

// Or htmlspecialchars directly
<p><?= htmlspecialchars($post->content, ENT_QUOTES, 'UTF-8') ?></p>
```

**Always escape user-generated content to prevent XSS attacks.**

### CSRF Token

```php
<form method="POST" action="/posts">
    <?= $csrf() ?>
    <!-- form fields -->
</form>
```

Outputs: `<input type="hidden" name="_csrf_token" value="...">`

### String Helpers

```php
// Limit string length
<?= $strLimit($post->content, 100) ?>
// Output: "This is a long text that gets..."

// Slug
<?= $strSlug($post->title) ?>
// Output: "my-post-title"

// Case conversion
<?= $strUpper($text) ?>
<?= $strLower($text) ?>
<?= $strTitle($text) ?>
```

### Date Formatting

```php
// Format date
<?= $formatDate($post->created_at) ?>
// Output: "January 15, 2024"

<?= $formatDate($post->created_at, 'Y-m-d') ?>
// Output: "2024-01-15"

// Time ago
<?= $timeAgo($post->created_at) ?>
// Output: "2 hours ago"
```

### URL Generation

```php
// Named route URL
<a href="<?= $url('posts.show', ['id' => $post->id]) ?>">View</a>
```

## Conditionals

```php
<?php if ($posts->isEmpty()): ?>
    <p>No posts found.</p>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <article>
            <h2><?= $e($post->title) ?></h2>
        </article>
    <?php endforeach; ?>
<?php endif; ?>
```

## Loops

```php
<?php foreach ($posts as $post): ?>
    <article>
        <h2><?= $e($post->title) ?></h2>
        <p>By <?= $e($post->author->name ?? 'Unknown') ?></p>
    </article>
<?php endforeach; ?>
```

## Including Partials

```php
// Include another view file
<?= $this->include('partials.header', ['title' => $title]) ?>

<main>
    <!-- Content -->
</main>

<?= $this->include('partials.footer') ?>
```

## Forms

### Basic Form

```php
<form method="POST" action="/posts">
    <?= $csrf() ?>

    <div class="form-group">
        <label for="title">Title</label>
        <input type="text" id="title" name="title"
               value="<?= $e($old['title'] ?? '') ?>" required>
    </div>

    <div class="form-group">
        <label for="content">Content</label>
        <textarea id="content" name="content" required><?= $e($old['content'] ?? '') ?></textarea>
    </div>

    <button type="submit">Create Post</button>
</form>
```

### Edit Form (PUT/PATCH)

```php
<form method="POST" action="/posts/<?= $post->id ?>">
    <?= $csrf() ?>
    <input type="hidden" name="_method" value="PUT">

    <div class="form-group">
        <label for="title">Title</label>
        <input type="text" id="title" name="title"
               value="<?= $e($post->title) ?>" required>
    </div>

    <button type="submit">Update Post</button>
</form>
```

### Delete Form

```php
<form method="POST" action="/posts/<?= $post->id ?>"
      onsubmit="return confirm('Are you sure?')">
    <?= $csrf() ?>
    <input type="hidden" name="_method" value="DELETE">
    <button type="submit" class="btn-danger">Delete</button>
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

## Flash Messages

```php
<?php if (isset($_SESSION['flash']['success'])): ?>
    <div class="alert alert-success">
        <?= $e($_SESSION['flash']['success']) ?>
    </div>
    <?php unset($_SESSION['flash']['success']); ?>
<?php endif; ?>
```

## Authentication Checks

```php
<?php if (isset($_SESSION['user'])): ?>
    <p>Welcome, <?= $e($_SESSION['user']['name']) ?>!</p>
    <form method="POST" action="/logout">
        <?= $csrf() ?>
        <button type="submit">Logout</button>
    </form>
<?php else: ?>
    <a href="/login">Login</a>
    <a href="/register">Register</a>
<?php endif; ?>
```

## Complete Example

```php
<!-- app/Views/posts/index.php -->
<?php $title = 'All Posts'; ?>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Posts</h1>
        <a href="/posts/create" class="btn btn-primary">New Post</a>
    </div>

    <?php if (isset($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?>
                <p><?= $e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($posts->isEmpty()): ?>
        <div class="alert alert-info">
            No posts yet. <a href="/posts/create">Create your first post!</a>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($posts as $post): ?>
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?= $e($post->title) ?></h5>
                            <p class="card-text"><?= $strLimit($e($post->content), 150) ?></p>
                            <p class="text-muted small">
                                By <?= $e($post->author->name ?? 'Unknown') ?>
                                &bull; <?= $timeAgo($post->created_at) ?>
                            </p>
                            <a href="/posts/<?= $post->id ?>" class="btn btn-sm btn-outline-primary">
                                Read More
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
```

## Tips

1. **Always escape output** - Use `$e()` for any user-generated content
2. **Keep logic minimal** - Complex logic belongs in controllers or models
3. **Use partials** - Break large views into reusable components
4. **Set meaningful titles** - Always set `$title` for each page
5. **Use semantic HTML** - Proper HTML5 elements improve accessibility
