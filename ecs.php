<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\ClassDefinitionFixer;
use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use PhpCsFixer\Fixer\ControlStructure\TrailingCommaInMultilineFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use PhpCsFixer\Fixer\PhpTag\BlankLineAfterOpeningTagFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\StringNotation\SingleQuoteFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/public/index.php', __DIR__ . '/config.php'])
    ->withRules([
        DeclareStrictTypesFixer::class,
        NoUnusedImportsFixer::class,
        OrderedImportsFixer::class,
        SingleQuoteFixer::class,
        BlankLineAfterOpeningTagFixer::class,
    ])
    ->withConfiguredRule(
        ClassDefinitionFixer::class,
        ['single_line' => false],
    )
    ->withConfiguredRule(
        TrailingCommaInMultilineFixer::class,
        ['elements' => ['arrays']],
    )
    ->withSkip([
        NotOperatorWithSuccessorSpaceFixer::class,
        OrderedClassElementsFixer::class,
    ]);
