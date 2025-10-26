<?php

namespace TFG\Core;

final class Bootstrap
{
    public static function tryInit(string $fqcn): void
    {
        if (class_exists($fqcn) && method_exists($fqcn, 'init')) {
            $fqcn::init();
        }
    }
}
