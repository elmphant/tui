<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/examples/inline')
    ->append(glob(__DIR__ . '/examples/*.php') ?: []);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'yoda_style' => false,
        'concat_space' => ['spacing' => 'one'],
        'cast_spaces' => ['space' => 'none'],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => false,
            'import_constants' => false,
        ],
        'phpdoc_line_span' => [
            'const' => 'multi',
            'method' => 'multi',
            'property' => 'multi',
        ],
        // Coarse categories only, and the default 'none' algorithm keeps the
        // order inside each one: the visibility grouping is enforced, the
        // top-down call order within a group is left alone.
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant',
                'property',
                'construct',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
        // PHPStan reads @var on a return statement; downgrading it to a plain
        // comment silently loses the type.
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
