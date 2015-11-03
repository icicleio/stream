# Changelog

### v0.4.3

- New Features
    - Added a `rebind()` method to the pipe classes that rebinds the object to the current event loop instance. This method should be used if the event loop is switched out during runtime (for example, when forking using the concurrent package).

- Bug Fixes
    - Fixed issue in `Icicle\Stream\Pipe\ReadablePipe` where certain stream resources would cause a warning to be issued if the stream was unexpectedly closed.

---

### v0.4.2

- Changes
    - Setting the third parameter of Stream\pipe() to true will now also close the source stream when piping completes.

---

### v0.4.1

- Bug Fixes
    - Fixed issue in `Icicle\Stream\Pipe\ReadablePipe` where reading might fail if the other end of the pipe is closed.

---

### v0.4.0

- New Features
    - Added `\Icicle\Stream\StreamResourceInterface` and `\Icicle\Stream\StreamResource` as a basis for classes working with PHP stream resources.
    - Added `\Icicle\Stream\Pipe\ReadablePipe`, `\Icicle\Stream\Pipe\WritablePipe`, and `\Icicle\Stream\Pipe\DuplexPipe` for using a PHP stream resource as a non-blocking stream (only compatible with streams from pipes and sockets, *not* files).
    - Functions for accessing streams for STDIN, STDOUT, and STDERR were added, `\Icicle\Stream\stdin()`,`\Icicle\Stream\stdout()`, `\Icicle\Stream\stderr()`.
    - Functions for reading from streams more easily were added: `\Icicle\Stream\readTo()`, `\Icicle\Stream\readUntil()`, and `\Icicle\Stream\readAll()`.
    - Added `\Icicle\Stream\pair()` function that returns a pair of connected stream resources (useful with `DuplexPipe`).
    - `\Icicle\Stream\Text\TextReader` and `\Icicle\Stream\Text\TextWriter` added for reading and writing streams of text in a given character encoding. These classes contain convenience methods for reading or writing based on characters rather than bytes.

- Changes
    - `pipe()` is no longer a method of `\Icicle\Stream\ReadableStreamInterface`. Use the function `\Icicle\Stream\pipe()` for the same functionality.
    - Renamed `\Icicle\Stream\Stream` to `\Icicle\Stream\MemoryStream` and `\Icicle\Stream\Sink` to `\Icicle\Stream\MemorySink`.

---

### v0.3.0

- Changes
    - The byte parameter must be a single-byte string. Use `chr()` to convert an integer to a single ascii character.

---

### v0.2.1

- Bug Fixes
    - Fixed bug when seeking to the end of the stream in `\Icicle\Stream\Sink`.

---

### v0.2.0

- Changes
    - Stream methods now are coroutines instead of returning promises. This change was made to provide a more consistent API across packages, improve performance, and support `yield from` in PHP 7.

---

### v0.1.1

- Minor performance improvement in `\Icicle\Stream\Structures\Buffer::shift()`. Should be preferred over `remove()` if no offset is needed.
- Bug fixes in tests.

---

### v0.1.0

- Initial release after split from the main [Icicle repository](https://github.com/icicleio/icicle).
