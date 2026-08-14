<?php

declare(strict_types=1);

namespace MacroRisk\Core\Security;

use MacroRisk\Core\Exceptions\ScientificIntegrityViolationException;

final class ScientificIntegrityGuard
{
    private const BANNED_PHRASES = [
        'will enter recession',
        'proves that',
        'model proves',
        'guarantees',
        'predicts with confidence',
        'recession is certain',
        'statistically confirms when n < 10',
        'caused by when causal identification is not established',
        'demographic collapse',
        'inevitable bankruptcy',
        'ai will fully replace',
        'immigration solves aging',
        'kondratiev',
        'juglar',
        'elliott wave',
        'market cycle predicts',
    ];

    public function screen(string $text): void
    {
        $lower = mb_strtolower($text);

        foreach (self::BANNED_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                throw new ScientificIntegrityViolationException(
                    "Scientific integrity violation: banned phrase detected: \"{$phrase}\""
                );
            }
        }
    }
}
