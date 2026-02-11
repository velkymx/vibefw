# View Helpers

Every view template automatically has access to built-in helper functions and support classes. These are pre-compiled closures created once in the View constructor for optimal performance.

## Template Functions

### Output & Escaping

#### `$e()` - Escape HTML

```php
<h1><?= $e($post->title) ?></h1>
<p><?= $e($user->bio) ?></p>
```

Wraps `htmlspecialchars()` with `ENT_QUOTES` and UTF-8 encoding. **Always use this for user-generated content.**

### URLs & Routing

#### `$url()` - Generate URL from Named Route

```php
<a href="<?= $url('posts.show', ['id' => $post->id]) ?>">View Post</a>
<a href="<?= $url('home') ?>">Home</a>

<form action="<?= $url('posts.store') ?>" method="POST">
```

Parameters are substituted into route placeholders.

### Forms & Security

#### `$csrf()` - CSRF Token Field

```php
<form method="POST" action="/posts">
    <?= $csrf() ?>
    <!-- form fields -->
</form>
```

Outputs: `<input type="hidden" name="_csrf_token" value="...">`

#### `$old()` - Old Form Input

```php
<input type="text" name="title" value="<?= $e($old('title', '')) ?>">
<textarea name="content"><?= $e($old('content', '')) ?></textarea>
```

Retrieves the previous form input from the session after a validation failure. The second argument is the default value.

### Layout & Sections

#### `$this->layout()` - Set Layout

```php
<?php $this->layout('app'); ?>
<!-- Uses app/Views/layouts/app.php -->
```

Must be called at the top of the view file.

#### `$section()` / `$endSection()` - Define Sections

```php
<?php $section('content'); ?>
    <h1>Page Content</h1>
    <p>This goes into the "content" section.</p>
<?php $endSection(); ?>

<?php $section('sidebar'); ?>
    <nav>Sidebar navigation</nav>
<?php $endSection(); ?>
```

#### `$yield()` - Output Section in Layout

```php
<!-- In layout file -->
<main>
    <?= $yield('content') ?>
</main>

<aside>
    <?= $yield('sidebar', '<p>Default sidebar</p>') ?>
</aside>
```

The second argument is rendered when the section is not defined.

### String Helpers

#### `$strLimit()` - Truncate String

```php
<?= $strLimit($post->content, 150) ?>
<!-- "This is a long article that..." -->

<?= $strLimit($post->content, 50, ' [more]') ?>
<!-- "This is a long article [more]" -->
```

#### `$strSlug()` - URL Slug

```php
<?= $strSlug('Hello World!') ?>
<!-- "hello-world" -->
```

#### `$strUpper()` / `$strLower()` / `$strTitle()`

```php
<?= $strUpper('hello') ?>    <!-- "HELLO" -->
<?= $strLower('HELLO') ?>    <!-- "hello" -->
<?= $strTitle('hello world') ?> <!-- "Hello World" -->
```

#### `$strExcerpt()` - Extract Excerpt Around Phrase

```php
<?= $strExcerpt($article->body, 'framework', 50) ?>
<!-- "...a lightweight PHP framework designed for..." -->
```

Returns text surrounding the given phrase with a configurable radius.

### Date & Time Helpers

#### `$formatDate()` - Format Date

```php
<?= $formatDate($post->created_at) ?>
<!-- "January 15, 2024" (default: 'F j, Y') -->

<?= $formatDate($post->created_at, 'Y-m-d') ?>
<!-- "2024-01-15" -->

<?= $formatDate($post->created_at, 'M j, Y g:ia') ?>
<!-- "Jan 15, 2024 3:30pm" -->
```

Accepts any `\DateTimeInterface` instance. Returns empty string for null.

#### `$timeAgo()` - Human-Readable Time Diff

```php
<?= $timeAgo($post->created_at) ?>
<!-- "2 hours ago" / "3 days ago" / "just now" -->

<span title="<?= $formatDate($comment->created_at) ?>">
    <?= $timeAgo($comment->created_at) ?>
</span>
```

### Fragment Caching

#### `$cache()` / `$endCache()` - Cache View Fragments

```php
<?php if ($cache('sidebar', 3600)): ?>
    <!-- This block only renders on cache miss -->
    <!-- Cached for 1 hour (3600 seconds) -->
    <nav>
        <?php foreach ($categories as $cat): ?>
            <a href="<?= $url('categories.show', ['id' => $cat->id]) ?>">
                <?= $e($cat->name) ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php $endCache(); endif; ?>
```

Returns `true` on cache miss (content needs rendering), `false` on cache hit (cached content is output automatically).

---

## Support Classes

Three utility classes are injected into every view as variables.

### `$Str` - String Utilities

Full static API from `Fw\Support\Str`.

```php
<!-- Basic operations -->
<?= $Str::slug('Hello World') ?>              <!-- hello-world -->
<?= $Str::limit('Long text here...', 50) ?>   <!-- Long text here... -->
<?= $Str::title('hello world') ?>             <!-- Hello World -->
<?= $Str::headline('orderShipped') ?>         <!-- Order Shipped -->

<!-- Case conversion -->
<?= $Str::camel('foo_bar') ?>       <!-- fooBar -->
<?= $Str::kebab('fooBar') ?>        <!-- foo-bar -->
<?= $Str::snake('fooBar') ?>        <!-- foo_bar -->
<?= $Str::studly('foo_bar') ?>      <!-- FooBar -->

<!-- Inspection -->
<?= $Str::length('hello') ?>        <!-- 5 -->
<?= $Str::wordCount('hello world') ?> <!-- 2 -->
<?= $Str::contains('hello', 'ell') ? 'yes' : 'no' ?>
<?= $Str::startsWith('hello', 'he') ? 'yes' : 'no' ?>
<?= $Str::endsWith('hello', 'lo') ? 'yes' : 'no' ?>
<?= $Str::isJson('{"a":1}') ? 'valid' : 'invalid' ?>
<?= $Str::isUuid($value) ? 'uuid' : 'not uuid' ?>
<?= $Str::isEmpty('') ? 'empty' : 'not empty' ?>

<!-- Manipulation -->
<?= $Str::after('hello world', 'hello ') ?>     <!-- world -->
<?= $Str::before('hello world', ' world') ?>     <!-- hello -->
<?= $Str::between('[value]', '[', ']') ?>         <!-- value -->
<?= $Str::finish('/path', '/') ?>                 <!-- /path/ -->
<?= $Str::start('path', '/') ?>                   <!-- /path -->
<?= $Str::mask('secret@email.com', '*', 3) ?>     <!-- sec***@email.com -->
<?= $Str::reverse('hello') ?>                     <!-- olleh -->
<?= $Str::squish('  too   many  spaces  ') ?>     <!-- too many spaces -->
<?= $Str::wrap('value', '"') ?>                    <!-- "value" -->
<?= $Str::substr('hello', 0, 3) ?>                <!-- hel -->

<!-- Replacement -->
<?= $Str::replace('world', 'PHP', 'hello world') ?>    <!-- hello PHP -->
<?= $Str::replaceFirst('o', '0', 'foobar') ?>          <!-- f0obar -->
<?= $Str::replaceLast('o', '0', 'foobar') ?>           <!-- fo0bar -->

<!-- Generation -->
<?= $Str::random(16) ?>       <!-- random alphanumeric string -->
<?= $Str::uuid() ?>           <!-- UUID v4 -->
<?= $Str::ulid() ?>           <!-- ULID -->

<!-- Pluralization -->
<?= $Str::plural('post') ?>     <!-- posts -->
<?= $Str::singular('posts') ?>  <!-- post -->
```

#### Fluent Strings with `$Str::of()`

Chain methods for readable transformations:

```php
<?= $Str::of('hello world')
    ->title()
    ->slug()
    ->value() ?>
<!-- hello-world -->

<?= $Str::of($post->title)
    ->limit(50)
    ->value() ?>

<?= $Str::of('user_first_name')
    ->headline()
    ->value() ?>
<!-- User First Name -->

<!-- Type conversions -->
<?php $count = $Str::of('42')->toInteger(); ?>
<?php $price = $Str::of('19.99')->toFloat(); ?>
<?php $flag = $Str::of('true')->toBoolean(); ?>

<!-- Conditional operations -->
<?= $Str::of($input)
    ->whenEmpty(fn($s) => $s->append('N/A'))
    ->value() ?>

<!-- Regex -->
<?php $match = $Str::of('foo bar')->match('/bar/'); ?>
<?php $matches = $Str::of('foo 123 bar 456')->matchAll('/\d+/'); ?>
```

### `$DateTime` - DateTime Utilities

Full API from `Fw\Support\DateTime`.

#### Factory Methods

```php
<?php $now = $DateTime::now(); ?>
<?php $today = $DateTime::today(); ?>
<?php $yesterday = $DateTime::yesterday(); ?>
<?php $tomorrow = $DateTime::tomorrow(); ?>
<?php $date = $DateTime::parse('2024-01-15 14:30:00'); ?>
<?php $ts = $DateTime::fromTimestamp(1700000000); ?>
<?php $custom = $DateTime::create(2024, 6, 15, 12, 0, 0); ?>
```

#### Formatting

```php
<?= $date->format('F j, Y') ?>           <!-- January 15, 2024 -->
<?= $date->toDateString() ?>             <!-- 2024-01-15 -->
<?= $date->toTimeString() ?>             <!-- 14:30:00 -->
<?= $date->toDateTimeString() ?>         <!-- 2024-01-15 14:30:00 -->
<?= $date->toIso8601() ?>               <!-- 2024-01-15T14:30:00+00:00 -->
<?= $date->toAtom() ?>                  <!-- Atom format -->
<?= $date->toRfc2822() ?>               <!-- RFC 2822 format -->
```

#### Human-Readable Differences

```php
<?= $date->diffForHumans() ?>            <!-- "3 months ago" / "in 2 days" -->
<?= $date->age() ?>                      <!-- 25 (years from now) -->
<?= $date->diffInDays($otherDate) ?>     <!-- 45 -->
<?= $date->diffInHours($otherDate) ?>    <!-- 1080 -->
<?= $date->diffInMinutes($otherDate) ?>
<?= $date->diffInSeconds($otherDate) ?>
<?= $date->diffInWeeks($otherDate) ?>
<?= $date->diffInMonths($otherDate) ?>
<?= $date->diffInYears($otherDate) ?>
```

#### Date Arithmetic

```php
<?= $date->addDays(30)->format('Y-m-d') ?>
<?= $date->addHours(2)->format('H:i') ?>
<?= $date->addMonths(3)->toDateString() ?>
<?= $date->addYears(1)->year() ?>
<?= $date->addWeeks(2)->toDateString() ?>
<?= $date->addMinutes(45)->toTimeString() ?>
<?= $date->addSeconds(90)->toTimeString() ?>

<?= $date->subDays(7)->toDateString() ?>
<?= $date->subHours(6)->format('H:i') ?>
<?= $date->subMonths(1)->toDateString() ?>
<?= $date->subYears(2)->year() ?>
<?= $date->subWeeks(1)->toDateString() ?>
<?= $date->subMinutes(30)->toTimeString() ?>
<?= $date->subSeconds(10)->toTimeString() ?>
```

#### Boundaries

```php
<?= $date->startOfDay()->toDateTimeString() ?>    <!-- 2024-01-15 00:00:00 -->
<?= $date->endOfDay()->toDateTimeString() ?>      <!-- 2024-01-15 23:59:59 -->
<?= $date->startOfWeek()->toDateString() ?>       <!-- Monday of that week -->
<?= $date->endOfWeek()->toDateString() ?>         <!-- Sunday of that week -->
<?= $date->startOfMonth()->toDateString() ?>      <!-- 2024-01-01 -->
<?= $date->endOfMonth()->toDateString() ?>        <!-- 2024-01-31 -->
<?= $date->startOfYear()->toDateString() ?>       <!-- 2024-01-01 -->
<?= $date->endOfYear()->toDateString() ?>         <!-- 2024-12-31 -->
```

#### Component Access

```php
<?= $date->year() ?>        <!-- 2024 -->
<?= $date->month() ?>       <!-- 1 -->
<?= $date->day() ?>         <!-- 15 -->
<?= $date->hour() ?>        <!-- 14 -->
<?= $date->minute() ?>      <!-- 30 -->
<?= $date->second() ?>      <!-- 0 -->
<?= $date->dayOfWeek() ?>   <!-- 1 (Monday) -->
<?= $date->dayOfYear() ?>   <!-- 15 -->
<?= $date->weekOfYear() ?>  <!-- 3 -->
<?= $date->daysInMonth() ?> <!-- 31 -->
<?= $date->timestamp() ?>   <!-- Unix timestamp -->
```

#### Comparisons & Checks

```php
<?php if ($date->isToday()): ?>Today<?php endif; ?>
<?php if ($date->isYesterday()): ?>Yesterday<?php endif; ?>
<?php if ($date->isTomorrow()): ?>Tomorrow<?php endif; ?>
<?php if ($date->isPast()): ?>Already happened<?php endif; ?>
<?php if ($date->isFuture()): ?>Coming up<?php endif; ?>
<?php if ($date->isWeekend()): ?>Weekend<?php endif; ?>
<?php if ($date->isWeekday()): ?>Weekday<?php endif; ?>
<?php if ($date->isMonday()): ?>Monday<?php endif; ?>
<?php if ($date->isLeapYear()): ?>Leap year<?php endif; ?>

<?php if ($date->isBefore($otherDate)): ?>Earlier<?php endif; ?>
<?php if ($date->isAfter($otherDate)): ?>Later<?php endif; ?>
<?php if ($date->isBeforeOrEqual($otherDate)): ?>...<?php endif; ?>
<?php if ($date->isAfterOrEqual($otherDate)): ?>...<?php endif; ?>
<?php if ($date->isBetween($start, $end)): ?>In range<?php endif; ?>
<?php if ($date->isSameDay($otherDate)): ?>Same day<?php endif; ?>
<?php if ($date->isSameMonth($otherDate)): ?>Same month<?php endif; ?>
<?php if ($date->isSameYear($otherDate)): ?>Same year<?php endif; ?>
<?php if ($date->equals($otherDate)): ?>Exact match<?php endif; ?>

<!-- Min/Max -->
<?= $date->min($otherDate)->toDateString() ?>  <!-- Earlier of the two -->
<?= $date->max($otherDate)->toDateString() ?>  <!-- Later of the two -->
```

#### Setters

```php
<?= $date->setYear(2025)->toDateString() ?>
<?= $date->setMonth(6)->toDateString() ?>
<?= $date->setDay(1)->toDateString() ?>
<?= $date->setHour(9)->toTimeString() ?>
<?= $date->setMinute(0)->toTimeString() ?>
<?= $date->setDate(2025, 6, 15)->toDateString() ?>
<?= $date->setTime(9, 30, 0)->toTimeString() ?>
<?= $date->setTimezone('America/New_York')->format('Y-m-d H:i T') ?>
```

### `$Arr` - Array Utilities

Full static API from `Fw\Support\Arr`.

#### Dot-Notation Access

```php
<?php
$config = ['database' => ['host' => 'localhost', 'port' => 3306]];

$host = $Arr::get($config, 'database.host');              // 'localhost'
$missing = $Arr::get($config, 'database.name', 'myapp');  // 'myapp' (default)

$Arr::has($config, 'database.host');   // true
$Arr::has($config, 'database.name');   // false

$Arr::set($config, 'database.name', 'myapp');
$Arr::forget($config, 'database.port');
?>
```

#### Extraction & Filtering

```php
<?php
$users = [
    ['name' => 'John', 'role' => 'admin', 'age' => 30],
    ['name' => 'Jane', 'role' => 'user', 'age' => 25],
    ['name' => 'Bob', 'role' => 'admin', 'age' => 35],
];

// Extract single field
$names = $Arr::pluck($users, 'name');
// ['John', 'Jane', 'Bob']

// Subset of keys
$subset = $Arr::only($users[0], ['name', 'role']);
// ['name' => 'John', 'role' => 'admin']

// Exclude keys
$without = $Arr::except($users[0], ['age']);
// ['name' => 'John', 'role' => 'admin']

// Filter with callback
$admins = $Arr::where($users, fn($u) => $u['role'] === 'admin');

// Remove nulls
$clean = $Arr::whereNotNull(['a' => 1, 'b' => null, 'c' => 3]);
// ['a' => 1, 'c' => 3]
?>
```

#### Search

```php
<?php
$first = $Arr::first($items, fn($item) => $item['active']);
$last = $Arr::last($items, fn($item) => $item['active']);
$any = $Arr::any($items, fn($item) => $item['price'] > 100);
$all = $Arr::all($items, fn($item) => $item['price'] > 0);
?>
```

#### Grouping & Sorting

```php
<?php
// Group by key
$grouped = $Arr::groupBy($users, 'role');
// ['admin' => [...], 'user' => [...]]

// Group by callback
$grouped = $Arr::groupBy($users, fn($u) => $u['age'] >= 30 ? 'senior' : 'junior');

// Re-key by field
$byName = $Arr::keyBy($users, 'name');
// ['John' => [...], 'Jane' => [...], 'Bob' => [...]]

// Sort
$sorted = $Arr::sortBy($users, 'name');
$sorted = $Arr::sortByDesc($users, 'age');
$sorted = $Arr::sortBy($users, fn($u) => $u['age']);

// Unique
$unique = $Arr::unique($Arr::pluck($users, 'role'));
// ['admin', 'user']
?>
```

#### Transformation

```php
<?php
// Flatten nested arrays
$flat = $Arr::flatten([[1, 2], [3, [4, 5]]]);
// [1, 2, 3, 4, 5]

// Collapse one level
$collapsed = $Arr::collapse([[1, 2], [3, 4]]);
// [1, 2, 3, 4]

// Map values
$upper = $Arr::map($names, fn($n) => strtoupper($n));

// Map with custom keys
$lookup = $Arr::mapWithKeys($users, fn($u) => [$u['name'] => $u['role']]);
// ['John' => 'admin', 'Jane' => 'user', 'Bob' => 'admin']

// Dot notation flatten
$dotted = $Arr::dot(['a' => ['b' => 1, 'c' => 2]]);
// ['a.b' => 1, 'a.c' => 2]

// Expand from dot notation
$nested = $Arr::undot(['a.b' => 1, 'a.c' => 2]);
// ['a' => ['b' => 1, 'c' => 2]]

// Deep merge
$merged = $Arr::mergeRecursiveDistinct($defaults, $overrides);
?>
```

#### Utility

```php
<?php
// Ensure value is array
$arr = $Arr::wrap('hello');      // ['hello']
$arr = $Arr::wrap(['hello']);    // ['hello']
$arr = $Arr::wrap(null);        // []

// Split into keys and values
[$keys, $values] = $Arr::divide(['a' => 1, 'b' => 2]);

// Get and remove
$value = $Arr::pull($data, 'key');

// Random element(s)
$item = $Arr::random($items);
$three = $Arr::random($items, 3);

// Shuffle
$shuffled = $Arr::shuffle($items);

// Slice
$subset = $Arr::slice($items, 0, 5);

// Prepend
$items = $Arr::prepend($items, 'first');

// Type checks
$Arr::isAssoc(['a' => 1]);   // true
$Arr::isList([1, 2, 3]);     // true
$Arr::accessible($value);     // true if array-like
$Arr::exists($arr, 'key');    // true if key exists

// Convert iterable
$arr = $Arr::toArray($generator);

// Cartesian product
$combos = $Arr::crossJoin([1, 2], ['a', 'b']);
// [[1,'a'], [1,'b'], [2,'a'], [2,'b']]
?>
```

---

## Reserved Variable Names

These variable names are reserved for the template engine and **cannot be used as view data keys**:

`e`, `url`, `csrf`, `old`, `section`, `endSection`, `yield`, `strLimit`, `strSlug`, `strUpper`, `strLower`, `strTitle`, `strExcerpt`, `formatDate`, `timeAgo`, `Str`, `DateTime`, `Arr`, `path`, `data`, `this`, `cache`, `endCache`

PHP superglobals are also reserved: `_GET`, `_POST`, `_SERVER`, `_REQUEST`, `_SESSION`, `_COOKIE`, `_FILES`, `_ENV`, `GLOBALS`, `argc`, `argv`

---

## Controller Rendering Methods

These methods are available in controllers for rendering views:

```php
// Standard render
return $this->view('posts.index', ['posts' => $posts]);

// Cached render (full-page cache for TTL seconds)
return $this->cachedView('pages.about', [], 3600);

// Streamed render (lower memory, faster TTFB for large pages)
return $this->streamedView('reports.large', ['data' => $data]);
```

### Cache Invalidation

```php
$view->invalidate('pages.about');           // Single page
$view->invalidateFragment('sidebar');        // Single fragment
$view->clearCache();                         // All cached views
```
