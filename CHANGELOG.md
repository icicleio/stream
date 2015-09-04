# Changelog

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
