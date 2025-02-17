<?php

namespace Zumba\JsonSerializer\Test\ClosureSerializer;

use Closure;
use PHPUnit\Framework\TestCase;
use Zumba\JsonSerializer\ClosureSerializer\OpisClosureSerializer;

class OpisClosureSerializerTest extends TestCase
{
    public function setUp(): void
    {
        if (! class_exists(\Opis\Closure\SerializableClosure::class)) {
            $this->markTestSkipped('Missing opis/closure to run this test');
        }
    }

    public function testSerialize(): void
    {
        $closure = function() {
            return 'foo';
        };
        $serializer = new OpisClosureSerializer();
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
        $serializer = new OpisClosureSerializer();
        $serialized = $serializer->serialize($closure);
        $unserialized = $serializer->unserialize($serialized);
        $this->assertNotEmpty($unserialized);
        $this->assertInstanceOf(Closure::class, $unserialized);
        $this->assertEquals($closure(), $unserialized());
    }
}
