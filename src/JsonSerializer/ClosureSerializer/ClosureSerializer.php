<?php

namespace Zumba\JsonSerializer\ClosureSerializer;

use Closure;

interface ClosureSerializer {

    /**
     * Serialize a closure
     *
     * @param Closure $closure
     * @return string
     */
    public function serialize(Closure $closure): string;

    /**
     * Unserialize a closure
     *
     * @param string $serialized
     *
     * @return Closure
     */
    public function unserialize(string $serialized): Closure;

}
