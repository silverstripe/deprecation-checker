<?php

function someGlobalFunctionTwo($someArg, ...$someArg2): void
{
    // no-op
}

function someGlobalFunctionThree(?string $stringRenamed, bool $newArg): null
{
    return null;
}

function someGlobalFunctionFour(?int &$string = null): ?bool
{
    return null;
}

function someGlobalFunctionFive()
{

}

function &someGlobalFunctionSix()
{

}
