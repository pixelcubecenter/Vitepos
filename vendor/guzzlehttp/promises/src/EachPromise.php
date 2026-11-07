<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Represents a promise that iterates over many promises and invokes
 * side-effect functions in the process.
 *
 * @final
 */
class EachPromise implements PromisorInterface
{
    private $pending = [];

    private $nextPendingIndex = 0;

    /** @var \Iterator|null */
    private $iterable;

    /** @var callable|int|null */
    private $concurrency;

    /** @var callable|null */
    private $onFulfilled;

    /** @var callable|null */
    private $onRejected;

    /** @var Promise|null */
    private $aggregate;

    /** @var bool|null */
    private $mutex;

    /**
     * Configuration hash can include the following key value pairs:
     *
     * - fulfilled: (callable) Invoked when a promise fulfills. The function
     *   is invoked with three arguments: the fulfillment value, the index
     *   position from the iterable list of the promise, and the aggregate
     *   promise that manages all of the promises. The aggregate promise may
     *   be resolved from within the callback to short-circuit the promise.
     * - rejected: (callable) Invoked when a promise is rejected. The
     *   function is invoked with three arguments: the rejection reason, the
     *   index position from the iterable list of the promise, and the
     *   aggregate promise that manages all of the promises. The aggregate
     *   promise may be resolved from within the callback to short-circuit
     *   the promise.
     * - concurrency: (integer) Pass this configuration option to limit the
     *   allowed number of outstanding concurrently executing promises,
     *   creating a capped pool of promises. There is no limit by default.
     *
     * @param mixed $iterable Promises or values to iterate.
     * @param array $config   Configuration options
     */
    public function __construct($iterable, array $config = [])
    {
        $this->iterable = Create::iterFor($iterable);

        if (isset($config['concurrency'])) {
            $this->concurrency = $config['concurrency'];
        }

        if (isset($config['fulfilled'])) {
            $this->onFulfilled = $config['fulfilled'];
        }

        if (isset($config['rejected'])) {
            $this->onRejected = $config['rejected'];
        }
    }

    /** @psalm-suppress InvalidNullableReturnType */
    public function promise(): PromiseInterface
    {
        if ($this->aggregate) {
            return $this->aggregate;
        }

        try {
            $this->createPromise();
            /** @psalm-assert Promise $this->aggregate */
            $this->iterable->rewind();
            $this->refillPending();
        } catch (\Throwable $e) {
            $this->aggregate->reject($e);
        }

        /**
         * @psalm-suppress NullableReturnStatement
         */
        return $this->aggregate;
    }

    private function createPromise(): void
    {
        $this->mutex = false;
        $this->aggregate = new Promise(function (): void {
            if ($this->checkIfFinished()) {
                return;
            }
            reset($this->pending);
            
            
            while ($promise = current($this->pending)) {
                next($this->pending);
                $promise->wait();
                if (Is::settled($this->aggregate)) {
                    return;
                }
            }
        });

        
        $clearFn = function (): void {
            $this->iterable = $this->concurrency = $this->pending = null;
            $this->onFulfilled = $this->onRejected = null;
            $this->nextPendingIndex = 0;
        };

        $this->aggregate->then($clearFn, $clearFn);
    }

    private function refillPending(): void
    {
        if (!$this->concurrency) {
            
            while ($this->addPending() && $this->advanceIterator()) {
            }

            return;
        }

        
        $concurrency = is_callable($this->concurrency)
            ? ($this->concurrency)(count($this->pending))
            : $this->concurrency;
        $concurrency = max($concurrency - count($this->pending), 0);
        
        if (!$concurrency) {
            return;
        }
        
        $this->addPending();
        
        
        
        
        while (--$concurrency
            && $this->advanceIterator()
            && $this->addPending()) {
        }
    }

    private function addPending(): bool
    {
        if (!$this->iterable || !$this->iterable->valid()) {
            return false;
        }

        $promise = Create::promiseFor($this->iterable->current());
        $key = $this->iterable->key();

        
        
        $idx = $this->nextPendingIndex++;

        $this->pending[$idx] = $promise->then(
            function ($value) use ($idx, $key): void {
                if ($this->onFulfilled) {
                    ($this->onFulfilled)(
                        $value,
                        $key,
                        $this->aggregate
                    );
                }
                $this->step($idx);
            },
            function ($reason) use ($idx, $key): void {
                if ($this->onRejected) {
                    ($this->onRejected)(
                        $reason,
                        $key,
                        $this->aggregate
                    );
                }
                $this->step($idx);
            }
        );

        return true;
    }

    private function advanceIterator(): bool
    {
        
        
        if ($this->mutex) {
            return false;
        }

        $this->mutex = true;

        try {
            $this->iterable->next();
            $this->mutex = false;

            return true;
        } catch (\Throwable $e) {
            $this->aggregate->reject($e);
            $this->mutex = false;

            return false;
        }
    }

    private function step(int $idx): void
    {
        
        if (Is::settled($this->aggregate)) {
            return;
        }

        unset($this->pending[$idx]);

        
        
        
        if ($this->advanceIterator() && !$this->checkIfFinished()) {
            
            $this->refillPending();
        }
    }

    private function checkIfFinished(): bool
    {
        if (!$this->pending && !$this->iterable->valid()) {
            
            $this->aggregate->resolve(null);

            return true;
        }

        return false;
    }
}
