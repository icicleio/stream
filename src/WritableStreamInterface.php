<?php
namespace Icicle\Stream;

interface WritableStreamInterface extends StreamInterface
{
    /**
     * @coroutine
     *
     * Queues data to be sent on the stream. The promise returned is fulfilled once the data has successfully been
     * written to the stream.
     *
     * @param string $data
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     and the stream is closed if the data cannot be written to the stream. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve int Number of bytes written to the stream.
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function write($data, $timeout = 0);

    /**
     * @coroutine
     *
     * Queues the data to be sent on the stream and closes the stream once the data has been written.
     *
     * @param string $data
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     and the stream is closed if the data cannot be written to the stream. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve int Number of bytes written to the stream.
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function end($data = '', $timeout = 0);
    
    /**
     * Determines if the stream is still writable.
     *
     * @return bool
     */
    public function isWritable();
}
