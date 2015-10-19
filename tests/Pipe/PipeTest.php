<?php

/*
 * This file is part of the pipe package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream\Pipe;

use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Tests\Stream\TestCase;

abstract class PipeTest extends TestCase
{
    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';
    
    /**
     * @return \Icicle\Stream\StreamResourceInterface[]
     */
    abstract public function createStreams();

    public function setUp()
    {
        Loop\loop(new SelectLoop());
    }
}