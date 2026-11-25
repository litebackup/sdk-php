<?php

declare(strict_types=1);

// PHP-CS-Fixer's settings: the PER coding style, over the library, its
// tests, its scripts, and the Laravel configuration file.
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config'])
    ->exclude(['frameworks/laravel-11/vendor', 'frameworks/laravel-12/vendor'])
    ->notPath('#/vendor/#')
    ->append([__DIR__ . '/scripts/generate', __FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setCacheFile(sys_get_temp_dir() . '/litebackup-php-cs-fixer.cache')
    ->setFinder($finder);
