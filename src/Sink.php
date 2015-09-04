<?php
namespace Icicle\Stream;

use Icicle\Stream\Exception\{
    InvalidArgumentError,
    OutOfBoundsException,
    UnreadableException,
    UnseekableException,
    UnwritableException
};
use Icicle\Stream\Structures\Buffer;

/**
 * Acts as a buffered sink with a seekable read/write pointer. All data written to the sink remains in the sink. The
 * read/write pointer may be moved anywhere within the buffered sink using seek(). The current position of the pointer
 * may be determined with tell(). Since all data remains in the sink, the entire length of the sink is available with
 * getLength().
 */
class Sink implements DuplexStreamInterface, SeekableStreamInterface
{
    use PipeTrait;

    /**
     * @var bool
     */
    private $open = true;

    /**
     * @var bool
     */
    private $writable = true;

    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;

    /**
     * @var \Icicle\Stream\Structures\BufferIterator
     */
    private $iterator;

    /**
     * Initializes empty sink.
     */
    public function __construct()
    {
        $this->buffer = new Buffer();
        $this->iterator = $this->buffer->getIterator();
    }

    /**
     * {@inheritdoc}
     */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->open = false;
        $this->writable = false;
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool
    {
        return $this->isOpen() && $this->iterator->valid();
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 0, string $byte = null, float $timeout = 0): \Generator
    {
        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        if (0 > $length) {
            $length = 0;
        }

        $byte = strlen($byte) ? $byte[0] : null;

        if (null !== $byte) {
            $data = '';
            $i = 0;
            do {
                $char = $this->iterator->current();
                $this->iterator->next();
                $data .= $char;
            } while ($char !== $byte && (0 === $length || ++$i < $length) && $this->iterator->valid());

            return $data;
        }

        if (0 === $length) {
            $length = $this->buffer->getLength();
        }

        $position = $this->iterator->key();
        $data = $this->buffer->peek($length, $position);
        $position = $length + $position;

        if ($position > $this->buffer->getLength()) {
            $position = $this->buffer->getLength();
        }

        $this->iterator->seek($position);

        return $data;

        yield; // Unreachable, but makes the method a coroutine.
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data, float $timeout = 0): \Generator
    {
        return $this->send($data, $timeout, false);
    }

    /**
     * {@inheritdoc}
     */
    public function end(string $data = '', float $timeout = 0): \Generator
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
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     */
    protected function send(string $data, float $timeout = 0, bool $end = false): \Generator
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        if ($end) {
            $this->writable = false;
        }

        $length = strlen($data);

        if (!$this->iterator->valid()) {
            $this->buffer->push($data);
        } else {
            $this->iterator->insert($data);
        }

        $this->iterator->seek($this->iterator->key() + $length);

        return $length;

        yield; // Unreachable, but makes the method a coroutine.
    }

    /**
     * {@inheritdoc}
     */
    public function seek(int $offset, int $whence = SEEK_SET, float $timeout = 0): \Generator
    {
        if (!$this->isOpen()) {
            throw new UnseekableException('The stream is no longer seekable.');
        }

        $offset = (int) $offset;

        switch ($whence) {
            case SEEK_SET:
                break;

            case SEEK_CUR:
                $offset += $this->tell();
                break;

            case SEEK_END:
                $offset += $this->getLength();
                break;

            default:
                throw new InvalidArgumentError('Invalid value for whence. Use SEEK_SET, SEEK_CUR, or SEEK_END.');
        }

        if (0 > $offset || $this->buffer->getLength() < $offset) {
            throw new OutOfBoundsException(sprintf('Invalid offset: %s.', $offset));
        }

        $this->iterator->seek($offset);

        return $offset;

        yield; // Unreachable, but makes the method a coroutine.
    }

    /**
     * {@inheritdoc}
     */
    public function tell(): int
    {
        return $this->iterator->key();
    }

    /**
     * {@inheritdoc}
     */
    public function getLength()
    {
        return $this->buffer->getLength();
    }
}
