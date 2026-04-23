<?php

declare(strict_types=1);

namespace Fw\Events;

/**
 * Event Dispatcher for publishing and subscribing to domain events.
 *
 * Enables decoupled communication between components.
 * Components can react to events without knowing about each other.
 *
 * Usage:
 *     $dispatcher = new EventDispatcher();
 *
 *     // Subscribe to events
 *     $dispatcher->listen(UserCreated::class, function (UserCreated $event) {
 *         $this->mailer->sendWelcome($event->email);
 *     });
 *
 *     // Or use a listener class
 *     $dispatcher->listen(UserCreated::class, SendWelcomeEmail::class);
 *
 *     // Dispatch events
 *     $dispatcher->dispatch(new UserCreated($userId, $email));
 */
final class EventDispatcher
{
    /** @var array<class-string<Event>, list<callable|class-string>> */
    private array $listeners = [];

    /** @var array<class-string<Event>, list<callable|class-string>> */
    private array $wildcardListeners = [];

    /**
     * @param callable(class-string): object $resolver Container resolver function
     */
    public function __construct(
        private readonly mixed $resolver = null
    ) {}

    /**
     * Register a listener for an event.
     *
     * @param class-string<Event>|string $event Event class or wildcard pattern
     * @param callable|class-string|object $listener
     */
    public function listen(string $event, callable|string|object $listener): self
    {
        if (str_contains($event, '*')) {
            $this->wildcardListeners[$event][] = $listener;
        } else {
            $this->listeners[$event][] = $listener;
        }

        return $this;
    }

    /**
     * Register a subscriber class.
     *
     * Subscribers can listen to multiple events through a subscribe() method.
     *
     * @param class-string|EventSubscriber $subscriber
     */
    public function subscribe(string|EventSubscriber $subscriber): self
    {
        if (is_string($subscriber)) {
            $subscriber = $this->resolveListener($subscriber);
        }

        if ($subscriber instanceof EventSubscriber) {
            $subscriber->subscribe($this);
        }

        return $this;
    }

    /**
     * Dispatch an event to all listeners.
     */
    public function dispatch(Event $event): void
    {
        $eventClass = $event::class;

        // Direct listeners
        foreach ($this->listeners[$eventClass] ?? [] as $listener) {
            $this->invokeListener($listener, $event);
        }

        // Wildcard listeners
        foreach ($this->wildcardListeners as $pattern => $listeners) {
            if ($this->matchesWildcard($eventClass, $pattern)) {
                foreach ($listeners as $listener) {
                    $this->invokeListener($listener, $event);
                }
            }
        }
    }

    /**
     * Dispatch multiple events.
     *
     * @param iterable<Event> $events
     */
    public function dispatchAll(iterable $events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }

    /**
     * Check if there are listeners for an event.
     *
     * @param class-string<Event> $event
     */
    public function hasListeners(string $event): bool
    {
        if (!empty($this->listeners[$event])) {
            return true;
        }

        foreach (array_keys($this->wildcardListeners) as $pattern) {
            if ($this->matchesWildcard($event, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove all listeners for an event.
     *
     * @param class-string<Event>|null $event Null removes all listeners
     */
    public function forget(?string $event = null): self
    {
        if ($event === null) {
            $this->listeners = [];
            $this->wildcardListeners = [];
        } else {
            unset($this->listeners[$event]);
        }

        return $this;
    }

    /**
     * Get all registered listeners.
     *
     * @return array<class-string<Event>, list<callable|class-string>>
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }

    /**
     * Invoke a listener with an event.
     */
    private function invokeListener(callable|string|object $listener, Event $event): void
    {
        if (is_string($listener)) {
            $listener = $this->resolveListener($listener);
        }

        if (is_callable($listener)) {
            $listener($event);
        } elseif (is_object($listener) && method_exists($listener, 'handle')) {
            $listener->handle($event);
        }
    }

    /**
     * Resolve a listener class.
     *
     * When a container resolver is configured it always wins, so listeners
     * with constructor deps can be wired up. Without a resolver only
     * listeners whose constructor takes no *required* args are safe —
     * calling `new $listenerClass()` on a DI-hungry class used to fail
     * with a cryptic `ArgumentCountError`. Detect that up front and
     * throw a `RuntimeException` naming the class so the caller knows
     * to register a resolver.
     */
    private function resolveListener(string $listenerClass): object
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($listenerClass);
        }

        if (!class_exists($listenerClass)) {
            throw new \RuntimeException(
                "Event listener class does not exist: {$listenerClass}"
            );
        }

        $ctor = (new \ReflectionClass($listenerClass))->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            throw new \RuntimeException(
                "Event listener {$listenerClass} has required constructor "
                . 'parameters but no container resolver is configured. '
                . 'Register a resolver via `new EventDispatcher($resolver)` '
                . 'so listener dependencies can be injected.'
            );
        }

        return new $listenerClass();
    }

    /**
     * Check if event class matches a wildcard pattern.
     */
    private function matchesWildcard(string $eventClass, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

        return preg_match($regex, $eventClass) === 1;
    }
}
