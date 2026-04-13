# PHP 8.5 Features

VibeFW leverages PHP 8.5's new features for cleaner, more expressive code.

## array_first() and array_last()

PHP 8.5 introduces native functions for getting the first and last elements of arrays.

### In Collections

```php
// Before PHP 8.5
$first = $this->items[0] ?? null;
$last = end($this->items) ?: null;

// PHP 8.5
$first = array_first($this->items);
$last = array_last($this->items);
```

The Collection class uses these internally:

```php
$posts = Post::where('published', true)->get();

// Get first post (returns Option)
$posts->first()->match(
    some: fn($post) => "Found: {$post->title}",
    none: fn() => "No posts found"
);

// Get last post
$posts->last()->map(fn($post) => $post->title);
```

## Filter Exceptions (FILTER_FLAG_THROW_ON_FAILURE)

PHP 8.5 adds the ability to throw exceptions on filter validation failures instead of returning false.

### Validator Exception Mode

FormRequests use `FILTER_FLAG_THROW_ON_FAILURE` internally for immediate error detection:

```php
use App\Requests\StorePostRequest;
use Fw\Validation\ValidationException;

try {
    $data = StorePostRequest::fromRequest($request);
} catch (ValidationException $e) {
    return $this->view('form', ['errors' => $e->errors]);
}
```

## Pipe Operator (|>)

PHP 8.5's pipe operator enables functional composition. While FW's Result and Option types already support method chaining, the pipe operator opens new possibilities:

### Future Enhancement Example

```php
// Current chaining
$result = Post::find($id)
    ->map(fn($post) => $post->title)
    ->filter(fn($title) => strlen($title) > 10)
    ->getOrElse('Untitled');

// With pipe operator (future)
$result = $id
    |> Post::find(...)
    |> fn($opt) => $opt->map(fn($post) => $post->title)
    |> fn($opt) => $opt->filter(fn($t) => strlen($t) > 10)
    |> fn($opt) => $opt->getOrElse('Untitled');
```

### Pipe Helper Class

FW provides a `Pipe` helper class with common pipe-friendly operations. These examples use the `|>` operator — they require PHP 8.5 with the pipe operator enabled. Do not use this syntax in code targeting PHP < 8.5.

```php
use Fw\Support\Pipe;

// Transform data through the pipe
$result = $users
    |> Pipe::filterArray(fn($u) => $u->active)
    |> Pipe::pluck('email')
    |> Pipe::take(10)
    |> Pipe::collect();

// With side effects
$user = $request->all()
    |> Pipe::tap(fn($d) => Log::info('Processing', $d))
    |> Pipe::map(fn($d) => User::create($d))
    |> Pipe::tap(fn($u) => event(new UserCreated($u)));

// Conditional transformation
$value = $input
    |> Pipe::when(
        fn($v) => $v > 100,
        fn($v) => $v * 0.9,  // Apply 10% discount
        fn($v) => $v         // Keep original
    );

// Get first/last with predicates (PHP 8.5)
$firstAdmin = $users
    |> Pipe::first(fn($u) => $u->role === 'admin');

// Convert to Option/Result
$maybeUser = $userData
    |> Pipe::map(fn($d) => User::find($d['id']))
    |> Pipe::toOption();

// Compose multiple operations
$processUser = Pipe::compose(
    Pipe::get('name'),
    fn($name) => strtoupper($name),
    fn($name) => trim($name)
);

$name = $user |> $processUser;
```

### Available Pipe Helpers

| Method | Description |
|--------|-------------|
| `Pipe::map($fn)` | Transform the value |
| `Pipe::filter($predicate)` | Return value if true, null otherwise |
| `Pipe::tap($fn)` | Execute side effect, return original |
| `Pipe::orElse($default)` | Provide default for null |
| `Pipe::mapNullable($fn)` | Map only if not null |
| `Pipe::toOption()` | Wrap in Option |
| `Pipe::toOk()` | Wrap in Result::ok |
| `Pipe::collect()` | Convert array to Collection |
| `Pipe::get($key)` | Get property/key from value |
| `Pipe::pluck($key)` | Pluck key from array items |
| `Pipe::filterArray($fn)` | Filter array by predicate |
| `Pipe::sort($fn)` | Sort array |
| `Pipe::take($n)` | Take first N items |
| `Pipe::first($fn)` | Get first (optionally matching) |
| `Pipe::last($fn)` | Get last (optionally matching) |
| `Pipe::compose(...$fns)` | Combine multiple functions |
| `Pipe::when($if, $then, $else)` | Conditional transformation |
| `Pipe::throwIf($predicate, $e)` | Throw on condition |
| `Pipe::debug($label)` | Debug current value |

## New INI: max_memory_limit

PHP 8.5 adds `max_memory_limit` to cap how high `memory_limit` can be set:

```ini
; php.ini
max_memory_limit = 512M
memory_limit = 256M
```

This prevents scripts from requesting unlimited memory.

## Stack Traces for Fatal Errors

PHP 8.5 now includes stack traces in fatal error output, making debugging easier:

```
Fatal error: Allowed memory size exhausted in /path/file.php on line 42

Stack trace:
#0 /path/file.php(42): processLargeData()
#1 /path/controller.php(15): handleRequest()
#2 /path/index.php(8): Application->run()
```

## locale_is_right_to_left()

Useful for internationalization:

```php
// Check if locale requires RTL text direction
$locale = 'ar_SA'; // Arabic
if (locale_is_right_to_left($locale)) {
    echo '<html dir="rtl">';
}

// In views
<html dir="<?= locale_is_right_to_left($locale) ? 'rtl' : 'ltr' ?>">
```

## IntlListFormatter

Format lists according to locale rules:

```php
$formatter = new IntlListFormatter('en_US', IntlListFormatter::TYPE_AND);
echo $formatter->format(['apples', 'oranges', 'bananas']);
// "apples, oranges, and bananas"

$formatter = new IntlListFormatter('en_US', IntlListFormatter::TYPE_OR);
echo $formatter->format(['red', 'blue', 'green']);
// "red, blue, or green"
```

## get_exception_handler() and get_error_handler()

Retrieve current handlers for testing or temporary replacement:

```php
// Save current handler
$originalHandler = get_exception_handler();

// Set custom handler for testing
set_exception_handler(function ($e) {
    // Custom handling
});

// Restore original
set_exception_handler($originalHandler);
```

## CLI: php --ini=diff

Show only modified INI settings:

```bash
php --ini=diff
```

Useful for debugging configuration issues.

## curl_multi_get_handles()

Get all handles from a multi handle:

```php
$mh = curl_multi_init();
curl_multi_add_handle($mh, $ch1);
curl_multi_add_handle($mh, $ch2);

// PHP 8.5: Get all handles
$handles = curl_multi_get_handles($mh);
foreach ($handles as $handle) {
    // Process each handle
}
```

## Best Practices

1. **Use array_first/array_last** - Cleaner than array access with null coalescing
2. **Prefer Result types** - Use exception mode sparingly for specific use cases
3. **Leverage IntlListFormatter** - For user-facing list formatting
4. **Check RTL locales** - For proper internationalization
5. **Use --ini=diff** - For debugging PHP configuration

## Compatibility

These features require PHP 8.5+. The framework gracefully degrades on older versions where possible.

```php
// Check PHP version for feature availability
if (PHP_VERSION_ID >= 80500) {
    // Use PHP 8.5 features
}
```
