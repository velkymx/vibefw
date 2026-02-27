<?php
$title = 'About Fw Framework';
?>

<style>
    .hero-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 4rem 0;
        margin: -1.5rem -0.75rem 2rem;
        text-align: center;
    }
    .hero-section h1 {
        font-size: 3.5rem;
        font-weight: 800;
        margin-bottom: 1rem;
    }
    .hero-section .lead {
        font-size: 1.5rem;
        opacity: 0.95;
    }
    .speed-badge {
        display: inline-block;
        background: #ffc107;
        color: #000;
        padding: 0.5rem 1.5rem;
        border-radius: 50px;
        font-weight: 700;
        font-size: 1.1rem;
        margin-top: 1.5rem;
    }
    .feature-card {
        border: none;
        border-radius: 16px;
        padding: 2rem;
        height: 100%;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .feature-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
    }
    .feature-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
    }
    .benchmark-table th {
        background: #f8f9fa;
    }
    .benchmark-bar {
        height: 24px;
        border-radius: 4px;
        background: linear-gradient(90deg, #667eea, #764ba2);
    }
    .vibe-section {
        background: #f8f9fa;
        padding: 4rem 0;
        margin: 3rem -0.75rem;
    }
    .code-block {
        background: #1e1e1e;
        color: #d4d4d4;
        border-radius: 12px;
        padding: 1.5rem;
        font-family: 'Monaco', 'Menlo', monospace;
        font-size: 0.9rem;
        overflow-x: auto;
    }
    .code-block .keyword { color: #569cd6; }
    .code-block .string { color: #ce9178; }
    .code-block .comment { color: #6a9955; }
    .code-block .function { color: #dcdcaa; }
    .code-block .variable { color: #9cdcfe; }
</style>

<div class="hero-section">
    <div class="container">
        <h1>Fw Framework</h1>
        <p class="lead">The PHP framework built for speed, simplicity, and vibes.</p>
        <div class="speed-badge">13,593 requests/sec</div>
    </div>
</div>

<div class="container">
    <!-- Speed Section -->
    <section class="mb-5">
        <h2 class="text-center mb-4">Blazing Fast Performance</h2>
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <p class="lead text-center text-muted mb-4">
                    Fw is engineered for raw speed. No bloat, no unnecessary abstractions, just pure performance.
                </p>

                <!-- Framework Comparison -->
                <h5 class="text-center mb-3">Framework Benchmark Comparison</h5>
                <p class="text-center text-muted small mb-4">
                    Requests per second (higher is better)<br>
                    <em>Tested on Mac M2 with FrankenPHP worker mode - 8 threads, 200 connections, 15s</em>
                </p>

                <div class="mb-4">
                    <div class="d-flex align-items-center mb-2">
                        <div style="width: 120px;"><strong>Fw Framework</strong></div>
                        <div class="flex-grow-1 mx-3">
                            <div class="benchmark-bar" style="width: 100%; position: relative;">
                                <span style="position: absolute; right: 10px; color: white; font-weight: 600; line-height: 24px;">13,593 req/s</span>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <div style="width: 120px;">Slim 4</div>
                        <div class="flex-grow-1 mx-3">
                            <div class="benchmark-bar" style="width: 29%; background: #6c757d; position: relative;">
                                <span style="position: absolute; right: 10px; color: white; font-weight: 600; line-height: 24px;">~4,000 req/s</span>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <div style="width: 120px;">Symfony</div>
                        <div class="flex-grow-1 mx-3">
                            <div class="benchmark-bar" style="width: 11%; background: #6c757d; position: relative;">
                                <span style="position: absolute; left: 100%; margin-left: 10px; color: #333; font-weight: 600; line-height: 24px;">~1,500 req/s</span>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <div style="width: 120px;">Laravel</div>
                        <div class="flex-grow-1 mx-3">
                            <div class="benchmark-bar" style="width: 6%; background: #6c757d; position: relative;">
                                <span style="position: absolute; left: 100%; margin-left: 10px; color: #333; font-weight: 600; line-height: 24px;">~800 req/s</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row text-center mt-5">
                    <div class="col-md-4">
                        <div class="display-4 text-primary fw-bold">17x</div>
                        <p class="text-muted">faster than Laravel</p>
                    </div>
                    <div class="col-md-4">
                        <div class="display-4 text-primary fw-bold">9x</div>
                        <p class="text-muted">faster than Symfony</p>
                    </div>
                    <div class="col-md-4">
                        <div class="display-4 text-primary fw-bold">14.8ms</div>
                        <p class="text-muted">average latency</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Grid -->
    <section class="mb-5">
        <h2 class="text-center mb-4">Key Features</h2>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card feature-card bg-light">
                    <div class="feature-icon">🚀</div>
                    <h4>Fiber-Based Async</h4>
                    <p class="text-muted mb-0">Non-blocking I/O with PHP 8.4 Fibers. Handle thousands of concurrent connections without callback hell.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card bg-light">
                    <div class="feature-icon">🛡️</div>
                    <h4>Security First</h4>
                    <p class="text-muted mb-0">CSRF protection, SQL injection prevention, XSS filtering, and security headers built-in. Safe by default.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card bg-light">
                    <div class="feature-icon">🎯</div>
                    <h4>Result Monads</h4>
                    <p class="text-muted mb-0">No more null pointer exceptions. Explicit error handling with Result&lt;T,E&gt; and Option&lt;T&gt; types.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card bg-light">
                    <div class="feature-icon">⚡</div>
                    <h4>Zero Config</h4>
                    <p class="text-muted mb-0">Works out of the box with SQLite. Scale up to MySQL or PostgreSQL when you're ready. No complex setup.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card bg-light">
                    <div class="feature-icon">🔥</div>
                    <h4>Hot Reloading</h4>
                    <p class="text-muted mb-0">FrankenPHP worker mode keeps your app bootstrapped. Changes reflect instantly in development.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card bg-light">
                    <div class="feature-icon">📦</div>
                    <h4>Elegant ORM</h4>
                    <p class="text-muted mb-0">Fluent query builder, relationships, eager loading, and migrations. Database work that feels good.</p>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Vibe Coding Section -->
<div class="vibe-section">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-5">
                <h2 class="mb-3">Built for Vibe Coding</h2>
                <p class="lead">Write code that flows. Fw gets out of your way and lets you build.</p>
                <ul class="list-unstyled">
                    <li class="mb-2">✓ Clean, expressive syntax</li>
                    <li class="mb-2">✓ Sensible defaults everywhere</li>
                    <li class="mb-2">✓ Minimal boilerplate</li>
                    <li class="mb-2">✓ Powerful CLI generators</li>
                    <li class="mb-2">✓ AI-friendly codebase structure</li>
                </ul>
            </div>
            <div class="col-lg-7">
                <div class="code-block">
<span class="comment">// Create a complete CRUD resource in seconds</span>
<span class="variable">$router</span>-><span class="function">get</span>(<span class="string">'/posts'</span>, [<span class="variable">PostController</span>::<span class="keyword">class</span>, <span class="string">'index'</span>]);
<span class="variable">$router</span>-><span class="function">post</span>(<span class="string">'/posts'</span>, [<span class="variable">PostController</span>::<span class="keyword">class</span>, <span class="string">'store'</span>]);

<span class="comment">// Elegant query building</span>
<span class="variable">$posts</span> = <span class="variable">Post</span>::<span class="function">published</span>()
    -><span class="function">with</span>(<span class="string">'author'</span>)
    -><span class="function">orderBy</span>(<span class="string">'created_at'</span>, <span class="string">'desc'</span>)
    -><span class="function">paginate</span>(<span class="number">15</span>);

<span class="comment">// Safe, explicit error handling</span>
<span class="variable">$user</span> = <span class="variable">User</span>::<span class="function">find</span>(<span class="variable">$id</span>)-><span class="function">unwrapOr</span>(<span class="keyword">new</span> <span class="variable">GuestUser</span>());
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <!-- PHP 8.4 Section -->
    <section class="my-5">
        <h2 class="text-center mb-4">Powered by PHP 8.4</h2>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card border-0 bg-light p-4">
                    <div class="row text-center">
                        <div class="col-md-4 mb-3 mb-md-0">
                            <h3 class="h1 text-primary mb-0">8.4+</h3>
                            <small class="text-muted">PHP Version</small>
                        </div>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <h3 class="h1 text-primary mb-0">0</h3>
                            <small class="text-muted">Dependencies*</small>
                        </div>
                        <div class="col-md-4">
                            <h3 class="h1 text-primary mb-0">100%</h3>
                            <small class="text-muted">Type Coverage</small>
                        </div>
                    </div>
                    <p class="text-center text-muted mt-3 mb-0">
                        <small>*Zero runtime dependencies. Just PHP and your code.</small>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="text-center my-5 py-5">
        <h2 class="mb-3">Ready to Build Something Fast?</h2>
        <p class="lead text-muted mb-4">Get started in under a minute.</p>
        <div class="code-block d-inline-block text-start mb-4">
git clone https://github.com/velkymx/vibefw myapp
cd myapp && composer install && php fw serve
        </div>
        <div>
            <a href="/posts" class="btn btn-primary btn-lg me-2">See It In Action</a>
            <a href="https://github.com/velkymx/vibefw" class="btn btn-outline-secondary btn-lg">View on GitHub</a>
        </div>
    </section>

    <!-- Credits -->
    <section class="text-center mb-5 pt-4 border-top">
        <p class="text-muted mb-1">
            Created by <a href="https://blog.ajb.bz" class="text-decoration-none">Alan Bollinger</a>
        </p>
        <p class="text-muted small">
            &copy; <?= date('Y') ?> Alan Bollinger. MIT License.
        </p>
    </section>
</div>
