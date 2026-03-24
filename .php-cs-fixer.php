<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        // PSR-12 als Basis
        '@PSR12' => true,
        // Symfony-Standard zusätzlich
        '@Symfony' => true,
        // Strict Types in jeder Datei
        'declare_strict_types' => true,
        // Kurze Array-Syntax
        'array_syntax' => ['syntax' => 'short'],
        // Ungenutzte Imports entfernen
        'no_unused_imports' => true,
        // Imports alphabetisch sortieren
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        // Kein PHP-Tag am Ende der Datei
        'no_closing_tag' => true,
        // Einheitliche Klammern bei Kontrollstrukturen
        'braces' => ['allow_single_line_closure' => true],
        // Kein überflüssiges Semikolon
        'no_extra_blank_lines' => ['tokens' => ['curly_brace_block']],
        // Moderne PHP-Features nutzen
        'modernize_types_casting' => true,
        'no_alias_functions' => true,
        // PHPDoc-Ausrichtung deaktivieren (zu viel Aufwand für Nutzen)
        'phpdoc_align' => false,
        // Kein PHPDoc wenn TypeHint ausreicht
        'no_superfluous_phpdoc_tags' => true,
        // Trailing Kommas in mehrzeiligen Arrays
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'parameters', 'match']],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache');
