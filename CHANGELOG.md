# Changelog

## [0.5.2] - 2015-12-11
### Fixed
- Fixed an issue where a stream that ended itself (made itself unwritable) would then be closed when using `Icicle\Stream\pipe()` with the `$end` parameter set to `true`.

## [0.5.1] - 2015-12-09
### Fixed
- The `Icicle\Stream\pipe()` function also closed the source stream if `$end` was `true`, though this was not the intended behavior. This has now been fixed, only the destination stream is ended (closed).

## [0.5.0] - 2015-12-03
### Changed
- All interface names have been changed to remove the `Interface` suffix. Most interfaces simply had the suffix removed, except for `Icicle\Stream\StreamResourceInterface`, which was renamed to `Icicle\Stream\Resource` and no longer extends `Icicle\Stream\Stream`.
- Calling `close()` on a readable stream when there is a pending read operation will now fulfill the read with an empty string instead of rejecting.

## [0.4.3] - 2015-11-02
### Added
- Added a `rebind()` method to the pipe classes that rebinds the object to the current event loop instance. This method should be used if the event loop is switched out during runtime (for example, when forking using the concurrent package).

### Fixed
- Fixed issue in `Icicle\Stream\Pipe\ReadablePipe` where certain stream resources would cause a warning to be issued if the stream was unexpectedly closed.

## [0.4.2] - 2015-11-01
### Changed
- Setting the third parameter of Stream\pipe() to true will now also close the source stream when piping completes.

## [0.4.1] - 2015-10-19
### Fixed
- Fixed issue in `Icicle\Stream\Pipe\ReadablePipe` where reading might fail if the other end of the pipe is closed.

## [0.4.0] - 2015-10-16
### Added
- Added `\Icicle\Stream\StreamResourceInterface` and `\Icicle\Stream\StreamResource` as a basis for classes working with PHP stream resources.
- Added `\Icicle\Stream\Pipe\ReadablePipe`, `\Icicle\Stream\Pipe\WritablePipe`, and `\Icicle\Stream\Pipe\DuplexPipe` for using a PHP stream resource as a non-blocking stream (only compatible with streams from pipes and sockets, *not* files).
- Functions for accessing streams for STDIN, STDOUT, and STDERR were added, `\Icicle\Stream\stdin()`,`\Icicle\Stream\stdout()`, `\Icicle\Stream\stderr()`.
- Functions for reading from streams more easily were added: `\Icicle\Stream\readTo()`, `\Icicle\Stream\readUntil()`, and `\Icicle\Stream\readAll()`.
- Added `\Icicle\Stream\pair()` function that returns a pair of connected stream resources (useful with `DuplexPipe`).
- `\Icicle\Stream\Text\TextReader` and `\Icicle\Stream\Text\TextWriter` added for reading and writing streams of text in a given character encoding. These classes contain convenience methods for reading or writing based on characters rather than bytes.
### Changed
- `pipe()` is no longer a method of `\Icicle\Stream\ReadableStreamInterface`. Use the function `\Icicle\Stream\pipe()` for the same functionality.
- Renamed `\Icicle\Stream\Stream` to `\Icicle\Stream\MemoryStream` and `\Icicle\Stream\Sink` to `\Icicle\Stream\MemorySink`.

## [0.3.0] - 2015-09-04
### Changed
- The byte parameter must be a single-byte string. Use `chr()` to convert an integer to a single ascii character.

## [0.2.1] - 2015-08-25
### Fixed
- Fixed bug when seeking to the end of the stream in `\Icicle\Stream\Sink`.

## [0.2.0] - 2015-08-17
### Changed
- Stream methods now are coroutines instead of returning promises. This change was made to provide a more consistent API across packages, improve performance, and support `yield from` in PHP 7.

## [0.1.1] - 2015-07-16
- Minor performance improvement in `\Icicle\Stream\Structures\Buffer::shift()`. Should be preferred over `remove()` if no offset is needed.
- Bug fixes in tests.

## [0.1.0] - 2015-07-02
- Initial release after split from the main [Icicle repository](https://github.com/icicleio/icicle).
