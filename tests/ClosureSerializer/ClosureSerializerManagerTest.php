<?php

namespace Zumba\JsonSerializer\Test\ClosureSerializer;

use PHPUnit\Framework\TestCase;
use SuperClosure\Serializer;
use SuperClosure\SerializerInterface;
use Zumba\JsonSerializer\ClosureSerializer\ClosureSerializerManager;
use Zumba\JsonSerializer\ClosureSerializer\SuperClosureSerializer;

class ClosureSerializerManagerTest extends TestCase
{
    public function setUp(): void
    {
        if (! class_exists(SerializerInterface::class)) {
            $this->markTestSkipped('Missing jeremeamia/superclosure to run this test');
        }
    }

    public function testAddSerializer(): void
    {
        $manager = new ClosureSerializerManager();
        $this->assertEmpty($manager->getSerializer('foo'));
        $manager->addSerializer(new SuperClosureSerializer(new Serializer()));
        $this->assertNotEmpty($manager->getSerializer(SuperClosureSerializer::class));
    }

    public function testGetPreferredSerializer(): void
    {
        $manager = new ClosureSerializerManager();
        $this->assertNull($manager->getPreferredSerializer());

        $serializer = new SuperClosureSerializer(new Serializer());
        $manager->addSerializer($serializer);
        $this->assertSame($serializer, $manager->getPreferredSerializer());
    }
}
