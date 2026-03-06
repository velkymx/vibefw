<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($title ?? 'VibeFW Framework') ?></title>
    <?= $csrf() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        .navbar-brand { font-weight: 600; }
        .card { transition: box-shadow 0.2s; }
        .card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .badge-draft { background-color: #6c757d; }
        .badge-published { background-color: #198754; }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
    <?php
    // Get current authenticated user for the layout
    $authUser = \Fw\Auth\Auth::user();
    $isAuthenticated = $authUser !== null;
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="/">VibeFW Framework</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/posts">Posts</a>
                    </li>
                    <?php if ($isAuthenticated): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/posts/create">New Post</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/users">Users</a>
                        </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <?php if ($isAuthenticated): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <?= $e($authUser->getAttribute('name') ?? 'Account') ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="/users/<?= $e((string) $authUser->getKey()) ?>">
                                        My Profile
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="/users/<?= $e((string) $authUser->getKey()) ?>/edit">
                                        Settings
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="/logout">
                                        <?= $csrf() ?>
                                        <button type="submit" class="dropdown-item">Logout</button>
                                    </form>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/login">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/register">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <main class="flex-grow-1 py-4">
        <div class="container">
            <?php if (isset($_SESSION['_flash']['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $e($_SESSION['_flash']['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['_flash']['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['_flash']['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $e($_SESSION['_flash']['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['_flash']['error']); ?>
            <?php endif; ?>

            <?php
            $displayErrors = $errors ?? $_SESSION['_flash']['errors'] ?? null;
            if (isset($_SESSION['_flash']['errors'])) {
                unset($_SESSION['_flash']['errors']);
            }
            ?>
            <?php if (!empty($displayErrors)): ?>
                <div class="alert alert-danger">
                    <strong>Please fix the following errors:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($displayErrors as $field => $message): ?>
                            <li><?= $e($message) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?= $content ?? '' ?>
        </div>
    </main>

    <footer class="bg-light py-3 mt-auto border-top">
        <div class="container text-center text-muted">
            <p class="mb-0">
                Built with <strong>VibeFW Framework</strong> &bull;
                PHP <?= PHP_VERSION ?> &bull;
                &copy; <?= date('Y') ?>
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
