<?php

namespace Zumba\JsonSerializer\Test\ClosureSerializer;

use Closure;
use PHPUnit\Framework\TestCase;
use SuperClosure\Serializer;
use SuperClosure\SerializerInterface;
use Zumba\JsonSerializer\ClosureSerializer\SuperClosureSerializer;

class SuperClosureSerializerTest extends TestCase
{
    public function setUp(): void
    {
        if (! class_exists(SerializerInterface::class)) {
            $this->markTestSkipped('Missing jeremeamia/superclosure to run this test');
        }
    }

    public function testSerialize(): void
    {
        $closure = function() {
            return 'foo';
        };
        $serializer = new SuperClosureSerializer(new Serializer());
        $serialized = $serializer->serialize($closure);
        $this->assertNotEmpty($serialized);
        $this->assertIsString($serialized);
        $this->assertNotEquals($closure, $serialized);
    }

    public function testUnserialize(): void
    {
        $closure = function() {
            return 'foo';
        };
        $serializer = new SuperClosureSerializer(new Serializer());
        $serialized = $serializer->serialize($closure);
        $unserialized = $serializer->unserialize($serialized);
        $this->assertNotEmpty($unserialized);
        $this->assertInstanceOf(Closure::class, $unserialized);
        $this->assertEquals($closure(), $unserialized());
    }
}
