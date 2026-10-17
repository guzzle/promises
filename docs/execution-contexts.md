# Execution Contexts

Guzzle promises run their callbacks through a task queue. By default there is
one task queue for the whole process, which is correct when one execution
context drives all promise work. Runtimes that interleave several execution
contexts in one process, such as native PHP fibers, coroutine schedulers built
on fibers, or coroutine extensions, need each context to keep its own work.
Otherwise, a context that drains the queue runs callbacks that another context
scheduled, and those callbacks observe the wrong request, tenant, or
transaction.

An execution context provider tells Guzzle which context is running. With a
provider configured, Guzzle keeps task queues per context and rejects
cross-context use of pending promises. Without a provider, nothing changes.

## Providers

Implement `GuzzleHttp\Promise\ExecutionContextProviderInterface`:

```php
public function current(): ?object;
```

Returns an object identifying the current execution context, or null when the
caller is not inside a managed context.

The returned object must be the same instance for every call within one
context, must differ between contexts that are alive at the same time, and must
become unreachable once the context has ended, so that state keyed by it can be
released.

Guzzle keeps per-context state in weak maps keyed by this object and only holds
weak references to it elsewhere, so a context's state is released together with
the context. Integer identifiers, such as coroutine IDs, cannot be used because
they cannot be weak keys.

Configure the provider with `ExecutionContext::setProvider()`:

Sets the provider that identifies the current execution context, or removes it
when null is given.

With a provider configured, Guzzle keeps a task queue for each context and
rejects use of a pending promise from a context that does not own it with a
`CrossContextException`.

Configure the provider once while bootstrapping the process, before promises
are created. Removing or replacing the provider discards the state kept for
existing contexts. Providers require PHP 8.0 or newer.

```php
use GuzzleHttp\Promise\ExecutionContext;

ExecutionContext::setProvider($provider);
```

### Fibers

`GuzzleHttp\Promise\FiberExecutionContextProvider`: Treats each native PHP
fiber as an execution context.

Code running outside any fiber is not inside a managed context. Fibers do not
notify Guzzle when they end, so schedulers should call
`ExecutionContext::finish(\Fiber::getCurrent())` as the last step inside each
fiber, because finishing a context runs its callbacks in that context.

Requires PHP 8.1 or newer.

### Coroutine Extensions

Coroutine extensions usually expose a per-coroutine object that satisfies the
provider requirements. For example, a provider for Swoole can return the
coroutine context object and register finalizers with `defer()`:

```php
use GuzzleHttp\Promise\FinalizingExecutionContextProviderInterface;
use Swoole\Coroutine;

final class SwooleExecutionContextProvider implements FinalizingExecutionContextProviderInterface
{
    public function current(): ?object
    {
        return Coroutine::getCid() > 0 ? Coroutine::getContext() : null;
    }

    public function onFinish(callable $finalizer): void
    {
        Coroutine::defer($finalizer);
    }
}
```

Guzzle does not ship providers for extensions. Verify that the object you return
meets the requirements above for the runtime version you use.


## Task Queues

`Utils::queue()`: When an execution context provider is configured, the
returned queue routes tasks to the queue of the current execution context, and
the global queue is used only outside managed contexts.

A queue assigned with `Utils::queue($queue)` becomes the global queue.

## Ownership

A promise created inside a managed context is owned by that context. A promise
that has adopted a pending promise counts as pending until the adopted promise
settles.

| Operation from another context | Pending promise | Settled promise |
| --- | --- | --- |
| `then()` and `otherwise()` | Rejected | Allowed, callbacks run in the calling context |
| `wait()` | Rejected | Allowed |
| `resolve()` and `reject()` | Rejected | Unchanged behavior |
| `cancel()` | Rejected | Unchanged behavior |
| Resolving with the pending promise or coroutine | Rejected | Allowed |

Rejected operations throw `GuzzleHttp\Promise\CrossContextException` without
changing the promise. Guzzle cannot see inside promises from other libraries. If
one refuses the callbacks of a promise resolved with it, for example because its
`then()` forwards to another context's pending promise, the exception is thrown
and the promise is left unchanged. If it calls the callbacks before throwing,
the exception is thrown and the promise stays settled.

The promise that `then()` returns is owned by the context that called `then()`,
and its callbacks run in that context. When another context settles the promise,
the callbacks are queued for the registering context, which runs them the next
time it drains its task queue, such as while it waits or when it is finished.
Callbacks registered by a context that has been finished or has ended are
dropped.

A context that registers callbacks on a promise that another context settles
must drain its own task queue to run them, for example by waiting on the promise
that `then()` returned, or on a promise that depends on it. Blocking on anything
else does not run them, so a context that waits on a channel or lock that one of
those callbacks would release never resumes.

`CrossContextException`: Thrown when pending work is used from an execution
context that does not own it.

If the owning context ends while a promise is still pending, every other context
is rejected, because nothing can settle the promise any more. Promises created
outside managed contexts are not restricted, but calling `then()` on them from a
managed context returns a promise that context owns.

`Coroutine`, `EachPromise`, `Each`, and the `Utils` helpers create Guzzle
promises internally, so they follow the same rules. A `Coroutine` checks
ownership before it cancels the promise it is waiting on, because that promise
can come from another library.

## Finishing Contexts

`ExecutionContext::onFinish()`: Registers a callback to run when the current
execution context is finished.

Finalizers run before the context's task queue is drained, so work they cancel
can still run its rejection callbacks in the same context.

`ExecutionContext::finish()`: Runs the given context's finalizers and drains
its task queue.

Runtimes call this when a context ends, unless their provider registers
finalizers through `FinalizingExecutionContextProviderInterface`. Call it from
inside the context being finished, so that callbacks keep running in the
context that owns them. Calling it again for the same context is harmless.

Once a context is finished, callbacks that other contexts queue for it are
dropped. If the context uses promises again, for example in a later shutdown
callback, Guzzle tracks it as a new context.

`FinalizingExecutionContextProviderInterface::onFinish()`: Registers a callback
that the runtime invokes, inside the current context, when that context ends.

Guzzle calls this the first time it keeps state for a context from inside that
context, and again if the context uses promises after it was finished.

If a context ends without being finished, its queued callbacks are discarded
together with the context.

## Related

- [Promise API](promise-api.md)
- [Promise Interoperability](promise-interoperability.md)
- [Implementation Notes](implementation-notes.md)
