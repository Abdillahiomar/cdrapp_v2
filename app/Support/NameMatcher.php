<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Compare un nom fourni (fichier importé) au nom enregistré en base KYC.
 *
 * Les deux noms sont d'abord normalisés : majuscules, sans accents, sans
 * ponctuation (tirets, apostrophes, points), espaces multiples réduits.
 * L'ordre des mots compte : « ALI AHMED » ≠ « AHMED ALI ».
 *
 * Résultat :
 *   - oui    : noms identiques après normalisation (100 %) ;
 *   - proche : ressemblance ≥ PROCHE_THRESHOLD %, ou l'un est le début de
 *              l'autre mot pour mot (ex. « MOHAMED ALI » / « MOHAMED ALI HASSAN ») ;
 *   - non    : sinon ;
 *   - null   : comparaison impossible (nom absent d'un côté).
 */
class NameMatcher
{
    public const PROCHE_THRESHOLD = 80;

    /**
     * @return array{match: ?string, score: ?int}
     */
    public static function compare(?string $fromFile, ?string $fromDb): array
    {
        $a = self::normalize($fromFile);
        $b = self::normalize($fromDb);

        if ($a === '' || $b === '') {
            return ['match' => null, 'score' => null];
        }

        if ($a === $b) {
            return ['match' => 'oui', 'score' => 100];
        }

        $score = self::similarity($a, $b);

        // Un prénom ou nom en moins à la fin, mots dans le même ordre (au moins 2 mots communs)
        $wordsA = explode(' ', $a);
        $wordsB = explode(' ', $b);
        [$short, $long] = count($wordsA) <= count($wordsB) ? [$wordsA, $wordsB] : [$wordsB, $wordsA];
        $isPrefix = count($short) >= 2 && array_slice($long, 0, count($short)) === $short;

        return [
            'match' => ($score >= self::PROCHE_THRESHOLD || $isPrefix) ? 'proche' : 'non',
            'score' => $score,
        ];
    }

    public static function normalize(?string $name): string
    {
        $name = Str::upper(Str::ascii((string) $name));
        $name = preg_replace('/[^A-Z0-9 ]+/', ' ', $name);   // ponctuation → espace

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Ressemblance en % (distance de Levenshtein rapportée à la longueur du
     * plus long nom) : sensible à l'ordre des lettres et des mots.
     */
    private static function similarity(string $a, string $b): int
    {
        $max = max(strlen($a), strlen($b));

        return (int) round((1 - levenshtein($a, $b) / $max) * 100);
    }
}
