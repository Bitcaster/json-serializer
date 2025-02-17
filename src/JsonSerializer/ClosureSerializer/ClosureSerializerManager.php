<?php

namespace Zumba\JsonSerializer\ClosureSerializer;

class ClosureSerializerManager {

    /**
     * Closure serializer instances
     *
     * @var array
     */
    protected array $closureSerializer = array();

    /**
     * Prefered closure serializer
     */
    protected array $preferred = array(
        SuperClosureSerializer::class,
        OpisClosureSerializer::class
    );

    /**
     * Set closure engine
     *
     * @param ClosureSerializer $closureSerializer
     * @return self
     */
    public function addSerializer(ClosureSerializer $closureSerializer): self
    {
        // Keep BC compat to PHP 7: Don't use "::class" on dynamic class names
        $classname = get_class($closureSerializer);
        $this->closureSerializer[$classname] = $closureSerializer;
        return $this;
    }

    /**
     * Get preferred closure serializer
     *
     * @return ClosureSerializer|null
     */
    public function getPreferredSerializer(): ?ClosureSerializer
    {
        if (empty($this->closureSerializer)) {
            return null;
        }

        foreach ($this->preferred as $preferred) {
            if (isset($this->closureSerializer[$preferred])) {
                return $this->closureSerializer[$preferred];
            }
        }
        return current($this->closureSerializer);
    }

    /**
     * Get closure serializer
     *
     * @param string $classname
     * @return ClosureSerializer|null
     */
    public function getSerializer(string $classname): ?ClosureSerializer
    {
        return $this->closureSerializer[$classname] ?? null;
    }
}
