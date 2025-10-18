<?php
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__])
    ->exclude(['vendor', 'wp-admin', 'wp-includes'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'phpdoc_align' => ['align' => 'left']
    ])
    ->setFinder($finder);
