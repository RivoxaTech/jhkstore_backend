<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Builds MySQL FULLTEXT (boolean mode) queries from what a user typed: "comp kit" -> "+comp* +kit*" (every word, as a word start).
 * InnoDB ignores words shorter than 3 letters and its stop words, so a search that contains such a word returns null and the caller
 * falls back to a plain LIKE.
 */
class FullTextSearch
{
    /** InnoDB's built-in stop words (a required stop word would match nothing). */
    private const STOP_WORDS = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or',
        'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'the', 'www'];

    public const MIN_WORD = 3;

    /** @return list<string> lower-case words (letters and digits only) */
    public static function words(string $term): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($term)) ?: []));
    }

    /** The boolean-mode expression, or null when FULLTEXT cannot answer this search (use LIKE). */
    public static function booleanQuery(string $term): ?string
    {
        $words = self::words($term);
        if (! $words) {
            return null;
        }
        foreach ($words as $word) {
            if (mb_strlen($word) < self::MIN_WORD || in_array($word, self::STOP_WORDS, true)) {
                return null;
            }
        }

        return implode(' ', array_map(fn ($w) => '+'.$w.'*', $words));
    }

    /** Escapes % _ \ for a LIKE pattern. */
    public static function like(string $term): string
    {
        return addcslashes($term, '%_'.chr(92));
    }
}
