<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__.'/src');

return new Config()
    ->setRiskyAllowed(true)
    ->setRules([
        // Base coding standards
        '@PSR12' => true,
        // '@Symfony' => true,

        // Spryker-like strictness (approximation)
        // 'declare_strict_types' => false, // exclude strict type hints
        // 'phpdoc_no_type_check' => true,  // allow missing type hints in docblocks

        // Exclusions similar to SprykerStrict
        // 'phpdoc_types_order' => false,
        // 'phpdoc_no_alias_tag' => false,

        // Prevent usage of functions not available in PHP 7.4
        // 'native_function_invocation' => [
        //     'include' => ['@internal'],
        //     'scope' => 'namespaced',
        //     'strict' => true,
        // ],
    ])
    ->setFinder($finder)
    ->setUnsupportedPhpVersionAllowed(true); // allow running under newer PHP
