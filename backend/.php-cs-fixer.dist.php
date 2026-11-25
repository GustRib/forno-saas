<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations', __DIR__.'/config'])
    ->exclude(['var'])
    ->notPath('reference.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'phpdoc_to_comment' => false,
        'yoda_style' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
