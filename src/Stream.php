<?php
namespace Icicle\Stream;

use Exception;
use Icicle\Promise\Deferred;
use Icicle\Stream\Exception\BusyError;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\Structures\Buffer;

/**
 * Serves as buffer that implements the stream interface, allowing consumers to be notified when data is available in
 * the buffer. This class by itself is not particularly useful, but it can be extended to add functionality upon reading 
 * or writing, as well as acting as an example of how stream classes can be implemented.
 */
class Stream implements DuplexStreamInterface
{
    use PipeTrait;

    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;
    
    /**
     * @var bool
     */
    private $open = true;
    
    /**
     * @var bool
     */
    private $writable = true;
    
    /**
     * @var \Icicle\Promise\Deferred|null
     */
    private $deferred;
    
    /**
     * @var int
     */
    private $length;
    
    /**
     * @var string|null
     */
    private $byte;

    /**
     * @var int
     */
    private $hwm;

    /**
     * @var \SplQueue|null
     */
    private $queue;
    
    /**
     * @param int $hwm High water mark. If the internal buffer has more than $hwm bytes, writes to the stream will
     *     return pending promises until the data is consumed.
     */
    public function __construct($hwm = 0)
    {
        $this->buffer = new Buffer();
        $this->hwm = (int) $hwm;
        if (0 > $this->hwm) {
            $this->hwm = 0;
        }

        if (0 !== $this->hwm) {
            $this->queue = new \SplQueue();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isOpen()
    {
        return $this->open;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->free();
    }

    /**
     * Closes the stream and rejects any pending promises.
     *
     * @param \Exception|null $exception
     */
    protected function free(Exception $exception = null)
    {
        $this->open = false;
        $this->writable = false;

        if (null !== $this->deferred) {
            $this->deferred->getPromise()->cancel(
                $exception = $exception ?: new ClosedException('The stream was unexpectedly closed.')
            );
        }

        if (0 !== $this->hwm) {
            while (!$this->queue->isEmpty()) {
                /** @var \Icicle\Promise\Deferred $deferred */
                $deferred = $this->queue->shift();
                $deferred->getPromise()->cancel(
                    $exception = $exception ?: new ClosedException('The stream was unexpectedly closed.')
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($length = 0, $byte = null, $timeout = 0)
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting to read from stream.');
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        $this->length = (int) $length;
        if (0 > $this->length) {
            $this->length = 0;
        }

        $this->byte = (string) $byte;
        $this->byte = strlen($this->byte) ? $this->byte[0] : null;

        if (!$this->buffer->isEmpty()) {
            $data = $this->remove();

            if (0 !== $this->hwm && $this->buffer->getLength() <= $this->hwm) {
                while (!$this->queue->isEmpty()) {
                    /** @var \Icicle\Promise\Deferred $deferred */
                    $deferred = $this->queue->shift();
                    $deferred->resolve();
                }
            }

            if (!$this->writable && $this->buffer->isEmpty()) {
                $this->close();
            }

            yield $data;
            return;
        }

        $this->deferred = new Deferred();
        $promise = $this->deferred->getPromise();

        if (0 !== $timeout) {
            $promise = $promise->timeout($timeout, 'Reading from the stream timed out.');
        }

        try {
            yield $promise;
        } finally {
            $this->deferred = null;
        }
    }

    /**
     * Returns bytes from the buffer based on the current length or current search byte.
     *
     * @return string
     */
    private function remove()
    {
        if (null !== $this->byte && false !== ($position = $this->buffer->search($this->byte))) {
            if (0 === $this->length || $position < $this->length) {
                return $this->buffer->shift($position + 1);
            }

            return $this->buffer->shift($this->length);
        }

        if (0 === $this->length) {
            return $this->buffer->drain();
        }

        return $this->buffer->shift($this->length);
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable()
    {
        return $this->isOpen();
    }

    /**
     * {@inheritdoc}
     */
    public function write($data, $timeout = 0)
    {
        return $this->send($data, $timeout, false);
    }

    /**
     * {@inheritdoc}
     */
    public function end($data = '', $timeout = 0)
    {
        return $this->send($data, $timeout, true);
    }

    /**
     * @coroutine
     *
     * @param string $data
     * @param float|int $timeout
     * @param bool $end
     *
     * @return \Icicle\Promise\PromiseInterface
     *
     * @resolve int Number of bytes written to the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If the stream was already waiting to write.
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is not longer writable.
     */
    protected function send($data, $timeout = 0, $end = false)
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        $this->buffer->push($data);

        if (null !== $this->deferred && !$this->buffer->isEmpty()) {
            $this->deferred->resolve($this->remove());
        }

        if ($end) {
            if ($this->buffer->isEmpty()) {
                $this->close();
            } else {
                $this->writable = false;
            }
        }

        if (0 !== $this->hwm && $this->buffer->getLength() > $this->hwm) {
            $deferred = new Deferred();
            $this->queue->push($deferred);

            $promise = $deferred->getPromise();
            if (0 !== $timeout) {
                $promise = $promise->timeout($timeout, 'Writing to the stream timed out.');
            }

            try {
                yield $promise;
            } catch (Exception $exception) {
                if ($this->isOpen()) {
                    $this->free($exception);
                }
                throw $exception;
            }
        }

        yield strlen($data);
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable()
    {
        return $this->writable;
    }
}
