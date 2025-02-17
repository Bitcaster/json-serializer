<?php

namespace Zumba\JsonSerializer\Test\SupportClasses;

class AllVisibilities
{

    public EmptyClass|string $pub = 'this is public';
    protected EmptyClass|string $prot = 'protected';
    private EmptyClass|string $priv = 'dont tell anyone';
}
