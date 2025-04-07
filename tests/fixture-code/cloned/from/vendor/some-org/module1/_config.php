<?php

function someGlobalFunction()
{
    // no-op
}

function someGlobalFunctionTwo($someArg, bool $someArg2 = false): void
{
    // no-op
}

function someGlobalFunctionThree(string $string = null): ?bool
{
    return null;
}

function someGlobalFunctionFour(string $string = null): ?bool
{
    return null;
}

function &someGlobalFunctionFive()
{

}

function someGlobalFunctionSix()
{

}
