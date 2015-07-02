# Asynchronous Streams for Icicle

This library is a component for [Icicle](https://github.com/icicleio/Icicle), providing an asynchronous readable, writable, and seekable stream interfaces and a couple basic stream implementations. Like other Icicle components, this library uses [Promises](https://github.com/icicleio/Icicle/wiki/Promises) and [Generators](http://www.php.net/manual/en/language.generators.overview.php) for asynchronous operations that may be used to build [Coroutines](//github.com/icicleio/Icicle/wiki/Coroutines) to make writing asynchronous code more like writing synchronous code.

[![Build Status](https://img.shields.io/travis/icicleio/stream/master.svg?style=flat-square)](https://travis-ci.org/icicleio/stream)
[![Coverage Status](https://img.shields.io/coveralls/icicleio/stream.svg?style=flat-square)](https://coveralls.io/r/icicleio/stream)
[![Semantic Version](https://img.shields.io/github/release/icicleio/stream.svg?style=flat-square)](http://semver.org)
[![Apache 2 License](https://img.shields.io/packagist/l/icicleio/stream.svg?style=flat-square)](LICENSE)
[![@icicleio on Twitter](https://img.shields.io/badge/twitter-%40icicleio-5189c7.svg?style=flat-square)](https://twitter.com/icicleio)

##### Requirements

- PHP 5.5+

##### Installation

The recommended way to install is with the [Composer](http://getcomposer.org/) package manager. (See the [Composer installation guide](https://getcomposer.org/doc/00-intro.md) for information on installing and using Composer.)

Run the following command to use this library in your project: 

```bash
composer require icicleio/stream
```

You can also manually edit `composer.json` to add this library as a project requirement.

```js
// composer.json
{
    "require": {
        "icicleio/stream": "^0.1"
    }
}
```

Streams represent a common promise-based API that may be implemented by classes that read or write sequences of binary data to facilitate interoperability. The stream component defines three interfaces, one of which should be used by all streams.

- `Icicle\Stream\ReadableStreamInterface`: Interface to be used by streams that are only readable.
- `Icicle\Stream\WritableStreamInterface`: Interface to be used by streams that are only writable.
- `Icicle\Stream\DuplexStreamInterface`: Interface to be used by streams that are readable and writable. Extends both `Icicle\Stream\ReadableStreamInterface` and `Icicle\Stream\WritableStreamInterface`.
- `Icicle\Stream\SeekableStreamInterface`: Interface to be used by seekable streams (readable and/or writable).

## Documentation

- [StreamInterface](#streaminterface) - Basic stream interface.
    - [isOpen()](#isopen) - Determines if the stream is still open.
    - [close()](#close) - Closes the stream.
- [ReadableStreamInterface](#readablestreaminterface) - Interface for readable streams.
    - [read()](#read) - Read data from the stream.
    - [pipe()](#pipe) - Pipes data from this stream to a writable stream.
    - [isReadable()](#isreadable) - Determines if the stream is readable.
- [WritableStreamInterface](#writablestreaminterface) - Interface for writable streams.
    - [write()](#write) - Writes data to the stream.
    - [end()](#end) - Writes data to the stream then closes the stream.
    - [isWritable()](#isWritable)
- [DuplexStreamInterface](#duplexstreaminterface) - Interface for streams that are readable and writable.
- [SeekableStreamInterface](#seekablestreaminterface) - Interface for seekable streams.
    - [seek()](#seek) - Moves the stream pointer.
    - [tell()](#tell) - Returns the current position of the stream pointer.
    - [getLength()](#getlength) - Returns the length of the stream if known.
- [Stream](#stream) - Buffer that implements `Icicle\Stream\DuplexStreamInterface`.
- [Sink](#sink) - Memory buffer that implements `Icicle\Stream\DuplexStreamInterface` and `Icicle\Stream\SeekableStreamInterface`.

#### Function prototypes

Prototypes for object instance methods are described below using the following syntax:

```php
ReturnType ClassOrInterfaceName::methodName(ArgumentType $arg1, ArgumentType $arg2)
```

Note that references in the prototypes below to `PromiseInterface` refer to `Icicle\Promise\PromiseInterface` (see the [Promise API documentation](../Promises) for more information).

## StreamInterface

All other stream interfaces extend this basic interface.

#### isOpen()

```php
bool StreamInterface::isOpen()
```

Determines if the stream is still open. A closed stream will be neither readable or writable.

---

#### close()

```php
void StreamInterface::close()
```

Closes the stream. Once closed, a stream will no longer be readable or writable.

## ReadableStreamInterface

#### read()

```php
PromiseInterface ReadableStreamInterface::read(
    int $length = 0,
    string|int|null $byte = null
)
```

Returns a promise that is fulfilled with data read from the stream when data becomes available. If `$length` is `0`, the promise is fulfilled with any amount of data available on the stream. If `$length` is not `0` the promise will be fulfilled with a maximum of `$length` bytes, but it may be fulfilled with fewer bytes. If the `$byte` parameter is not `null`, reading will stop once the given byte is encountered in the string. The byte matched by `$byte` will be included in the fulfillment string. `$byte` should be a single byte or the integer value of the byte (e.g., `0xa` for the newline character). If a multibyte string is provided, only the first byte will be used.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `string` | Any number of bytes or up to `$length` bytes if `$length` was not `0`.
Rejected | `Icicle\Stream\Exception\BusyError` | If a read was already pending on the stream.
Rejected | `Icicle\Stream\Exception\UnreadableException` | If the stream is no longer readable.
Rejected | `Icicle\Stream\Exception\ClosedException` | If the stream is unexpectedly closed.
Rejected | `Icicle\Promise\Exception\TimeoutException` | If reading from the stream times out.

---

#### pipe()

```php
Generator ReadableStreamInterface::pipe(
    WritableStreamInterface $stream,
    bool $end = true,
    int $length = 0,
    string|int|null $byte = null
    int $timeout = 0
)
```

Returns a generator that should be used within a coroutine or used to create a new coroutine. Pipes all data read from this stream to the writable stream. If `$length` is not `0`, only `$length` bytes will be piped to the writable stream.  If `$byte` is not `0`, piping will end once `$byte` is encountered in the stream. The returned promise is fulfilled with the number of bytes piped once the writable stream is no longer writable, `$length` bytes have been piped, or `$byte` is encountered in the stream.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `int` | Fulfilled when the writable stream is no longer writable or when `$length` bytes have been piped or `$byte` is read from the stream.
Rejected | `Icicle\Stream\Exception\BusyError` | If a read was already pending on the stream.
Rejected | `Icicle\Stream\Exception\UnreadableException` | If the stream is no longer readable.
Rejected | `Icicle\Stream\Exception\ClosedException` | If the stream is unexpectedly closed.
Rejected | `Icicle\Promise\Exception\TimeoutException` | If reading from the stream times out.

---

#### isReadable()

```php
bool ReadableStreamInterface::isReadable()
```

Determines if the stream is readable.

## WritableStreamInterface

#### write()

```php
PromiseInterface WritableStreamInterface::write(string $data)
```

Writes the given data to the stream. Returns a promise that is fulfilled with the number of bytes written once that data has successfully been written to the stream.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `int` | Fulfilled with the number of bytes written when the data has actually been written to the stream.
Rejected | `Icicle\Stream\Exception\UnwritableException` | If the stream is no longer writable.
Rejected | `Icicle\Stream\Exception\ClosedException` | If the stream is unexpectedly closed.
Rejected | `Icicle\Promise\Exception\TimeoutException` | If writing to the stream times out.

---

#### end()

```php
PromiseInterface WritableStreamInterface::end(string $data = '')
```

Writes the given data to the stream then immediately closes the stream by calling `close()`.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `int` | Fulfilled with the number of bytes written when the data has actually been written to the stream.
Rejected | `Icicle\Stream\Exception\UnwritableException` | If the stream is no longer writable.
Rejected | `Icicle\Stream\Exception\ClosedException` | If the stream is unexpectedly closed.
Rejected | `Icicle\Promise\Exception\TimeoutException` | If writing to the stream times out.

---

#### isWritable()

```php
bool WritableStreamInterface::isWritable()
```

Determines if the stream is writable.

## DuplexStreamInterface

A duplex stream is both readable and writable. `Icicle\Stream\DuplexStreamInterface` extends both `Icicle\Stream\ReadableStreamInterface` and `Icicle\Stream\WritableStreamInterface`, and therefore inherits all the methods above.

## SeekableStreamInterface

#### seek()

```php
PromiseInterface SeekableStreamInterface::seek(int $position, $whence = SEEK_SET, $timeout = 0)
```

Moves the pointer to a new position in the stream. The `$whence` parameter is identical the parameter of the same name on the built-in `fseek()` function.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `int` | Fulfilled with the new pointer position.
Rejected | `Icicle\Stream\Exception\UnseekableException` | If the stream is no longer seekable.
Rejected | `Icicle\Stream\Exception\ClosedException` | If the stream is unexpectedly closed.
Rejected | `Icicle\Promise\Exception\TimeoutException` | If seeking times out.

---

#### tell()

```php
int SeekableStreamInterface::tell()
```

Returns the current pointer position. Value returned may not reflect the future pointer position if a read, write, or seek operation is pending.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `int` | Fulfilled with the number of bytes written when the data has actually been written to the stream.
Rejected | `Icicle\Stream\Exception\UnwritableException` | If the stream is no longer writable.
Rejected | `Icicle\Stream\Exception\ClosedException` | If the stream is unexpectedly closed.
Rejected | `Icicle\Promise\Exception\TimeoutException` | If writing to the stream times out.

---

#### getLength()

```php
int|null SeekableStreamInterface::getLength()
```

Returns the total length of the stream if known, otherwise null. Value returned may not reflect a pending writeoperation.

## Stream

`Icicle\Stream\Stream` objects act as a buffer that implements `Icicle\Stream\DuplexStreamInterface`, allowing consumers to be notified when data is available in the buffer. This class by itself is not particularly useful, but it can be extended to add functionality upon reading or writing, as well as acting as an example of how stream classes can be implemented.

Anything written to an instance of `Icicle\Stream\Stream` is immediately readable.

```php
use Icicle\Loop;
use Icicle\Stream\Stream;

$stream = new Stream();

$stream
    ->write("This is just a test.\nThis will not be read.")
    ->then(function () use ($stream) {
        return $stream->read(0, "\n");
    })
    ->then(function ($data) {
        echo $data; // Echoes "This is just a test."
    });

Loop\run();
```

## Sink

`Icicle\Stream\Sink` acts as a buffered sink with a seekable read/write pointer. All data written to the sink remains in the sink. The read/write pointer may be moved anywhere within the buffered sink using `seek()`. The current position of the pointer may be determined with `tell()`. Since all data remains in the sink, the entire length of the sink is available with `getLength()`.

```php
use Icicle\Coroutine;
use Icicle\Loop;
use Icicle\Stream\Sink;

$coroutine = Coroutine\create(function () {
    $sink = new Sink();

    yield $sink->write("This is just a test.\n");

    yield $sink->seek(15);

    yield $sink->write("sink ");

    yield $sink->seek(0);

    $data = (yield $sink->read(0, "\n"));

    echo $data; // Echoes "This is just a sink test."
});

Loop\run();
```
