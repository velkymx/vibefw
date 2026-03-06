<?php
/**
 * Homepage View
 *
 * Shows welcome message and recent posts.
 *
 * @var array $posts Recent published posts
 * @var \App\Models\User|null $user Current authenticated user (passed from controller)
 * @var int $draftCount Number of user's drafts
 */

$title = 'Welcome';
$isAuthenticated = isset($user) && $user !== null;
?>

<div class="row">
    <div class="col-lg-8">
        <div class="mb-5">
            <h1 class="display-5 fw-bold">Welcome to VibeFW Framework</h1>
            <p class="lead text-muted">
                A modern PHP framework built with PHP 8.4 features, designed for simplicity and performance.
            </p>
            <?php if (!$isAuthenticated): ?>
                <div class="d-flex gap-2">
                    <a href="/register" class="btn btn-primary btn-lg">Get Started</a>
                    <a href="/login" class="btn btn-outline-primary btn-lg">Sign In</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isAuthenticated && $draftCount > 0): ?>
            <div class="alert alert-info d-flex align-items-center mb-4">
                <div class="flex-grow-1">
                    You have <strong><?= $draftCount ?></strong> draft post<?= $draftCount > 1 ? 's' : '' ?> waiting to be published.
                </div>
                <a href="/posts/create" class="btn btn-sm btn-info">View Drafts</a>
            </div>
        <?php endif; ?>

        <section class="mb-5">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h4 mb-0">Latest Posts</h2>
                <a href="/posts" class="btn btn-outline-primary btn-sm">View All</a>
            </div>

            <?php if (!empty($posts)): ?>
                <div class="list-group">
                    <?php foreach ($posts as $post): ?>
                        <?php
                        $author = $post->author()->get()->unwrapOr(null);
                        $authorName = $author?->getAttribute('name') ?? 'Unknown';
                        ?>
                        <a href="/posts/<?= $e((string) $post->getKey()) ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h5 class="mb-1"><?= $e($post->getAttribute('title') ?? '') ?></h5>
                                <small class="text-muted"><?= $formatDate($post->getAttribute('published_at')) ?></small>
                            </div>
                            <p class="mb-1 text-muted"><?= $e($post->excerpt(120)) ?></p>
                            <small class="text-muted">By <?= $e($authorName) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-light">
                    <p class="mb-0">
                        No published posts yet.
                        <?php if ($isAuthenticated): ?>
                            <a href="/posts/create">Create your first post</a>.
                        <?php else: ?>
                            <a href="/register">Sign up</a> to create the first post.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-4">
        <?php if ($isAuthenticated): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h3 class="h5 card-title">Quick Actions</h3>
                    <div class="d-grid gap-2">
                        <a href="/posts/create" class="btn btn-primary">Create New Post</a>
                        <a href="/posts" class="btn btn-outline-secondary">Manage Posts</a>
                        <a href="/users/<?= $e((string) $user->getKey()) ?>/edit" class="btn btn-outline-secondary">Account Settings</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card bg-light border-0">
            <div class="card-body">
                <h3 class="h5 card-title">Framework Features</h3>
                <ul class="list-unstyled mb-0">
                    <li class="mb-3">
                        <strong class="d-block">PHP 8.4 Ready</strong>
                        <small class="text-muted">Property hooks, asymmetric visibility, and modern features.</small>
                    </li>
                    <li class="mb-3">
                        <strong class="d-block">Pure MVC Architecture</strong>
                        <small class="text-muted">Clean separation of Models, Views, and Controllers.</small>
                    </li>
                    <li class="mb-3">
                        <strong class="d-block">ActiveRecord ORM</strong>
                        <small class="text-muted">Elegant database interactions with relationships and scopes.</small>
                    </li>
                    <li class="mb-3">
                        <strong class="d-block">Fiber-Based Async</strong>
                        <small class="text-muted">Non-blocking I/O with PHP Fibers.</small>
                    </li>
                    <li class="mb-3">
                        <strong class="d-block">Result &amp; Option Types</strong>
                        <small class="text-muted">Rust-inspired error handling for safer code.</small>
                    </li>
                    <li>
                        <strong class="d-block">Built-in Security</strong>
                        <small class="text-muted">CSRF protection, input validation, secure sessions.</small>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
