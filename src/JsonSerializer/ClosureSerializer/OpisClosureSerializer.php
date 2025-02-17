<?php

namespace Zumba\JsonSerializer\ClosureSerializer;

use Closure;
use Opis\Closure\SerializableClosure as OpisSerializableClosure;

class OpisClosureSerializer implements ClosureSerializer {

    /**
     * Serialize a closure
     *
     * @param Closure $closure
     * @return string
     */
    public function serialize(Closure $closure): string
    {
        return serialize(new OpisSerializableClosure($closure));
    }

    /**
     * Unserialize a closure
     *
     * @param string $serialized
     *
     * @return Closure
     */
    public function unserialize(string $serialized): Closure
    {
        return unserialize($serialized)->getClosure();
    }

}
