<?php

namespace Zumba\JsonSerializer\Test\SupportClasses;

class MagicClass
{

    public bool $show = true;
    public bool $hide = true;
    public bool $woke = false;

    public function __sleep()
    {
        return array('show');
    }

    public function __wakeup()
    {
        $this->woke = true;
    }
}
