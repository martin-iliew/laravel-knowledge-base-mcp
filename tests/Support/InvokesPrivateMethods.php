<?php

namespace Tests\Support;

use ReflectionMethod;

final class InvokesPrivateMethods
{
    public static function call(object $instance, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new ReflectionMethod($instance, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($instance, $arguments);
    }
}
