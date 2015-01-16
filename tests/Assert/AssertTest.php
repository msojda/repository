<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository\Tests\Assert;

use PHPUnit_Framework_TestCase;
use Puli\Repository\Assert\Assert;

/**
 * @since  1.0
 * @author Daniel Leech <daniel@dantleech.com>
 */
class AssertTest extends PHPUnit_Framework_TestCase
{
    public function providePath()
    {
        return array(
            array('/path/to', null),
            array(new \stdClass, 'The path must be a string. Got: stdClass'),
            array('', 'The path must not be empty'),
            array('not/absolute', 'The path "not/absolute" is not absolute'),
        );
    }

    /**
     * @dataProvider providePath
     */
    public function testPath($path, $expectedExceptionMessage = null)
    {
        if ($expectedExceptionMessage) {
            $this->setExpectedException('InvalidArgumentException', $expectedExceptionMessage);
        }

        Assert::path($path);
    }

    public function provideGlob()
    {
        return array(
            array('/glob/to', null),
            array(new \stdClass, 'The glob must be a string. Got: stdClass'),
            array('', 'The glob must not be empty'),
            array('not/absolute', 'The glob "not/absolute" is not absolute'),
        );
    }

    /**
     * @dataProvider provideGlob
     */
    public function testGlob($glob, $expectedExceptionMessage = null)
    {
        if ($expectedExceptionMessage) {
            $this->setExpectedException('InvalidArgumentException', $expectedExceptionMessage);
        }

        Assert::glob($glob);
    }
}
