<?php
declare(strict_types=1);
namespace Echappees;

final class Editorial {
    public static function rescueTitle(string $title): bool {
        return preg_match('/\b(?:sauvetages?|sauvés?|sauvées?|rescue[ds]?|rescuing|near[ -]death)\b|sauv(?:er|é|ée).{0,40}(?:noyade|flammes|accident)/iu', $title) === 1;
    }
    public const VERSION = 'positive-v2';
    public const EXCLUSIONS = "Exclus tout sujet centré sur la politique, les partis, les élus, les élections, les conflits idéologiques, le racisme, les discriminations, la sexualité, l’orientation sexuelle ou l’identité de genre, même présenté comme un progrès, une inclusion ou une bonne nouvelle. Cette règle porte sur le thème du récit, pas sur la valeur des personnes concernées. N’essaie pas de rendre le sujet admissible en effaçant artificiellement son contexte. Privilégie les découvertes, la nature, les sciences, le patrimoine, la cuisine et les améliorations concrètes du quotidien hors de ces thèmes.";
    public static function excludedTopic(string $text): bool {
        return preg_match('/\b(?:politi(?:que[s]?|cs|cal)|élections?|elections?|parlement(?:aire[s]?)?|député[e]?[s]?|sénateur[s]?|racis\p{L}*|racial\p{L}*|discriminat\p{L}*|homophob\p{L}*|transphob\p{L}*|lgbt\p{L}*|transgenre[s]?|transgender|sexualité|sexuel\p{L}*|sexuality|intersex\p{L}*|sexisme|avortement|abortion|réélection[s]?|electoral|partisan\p{L}*|gouverneur\p{L}*)\b|orientation[s]? sexuelle[s]?|sexual orientation|gender identity|identité[s]? de genre|préférence[s]? sexuelle[s]?/iu', $text) === 1;
    }
    public static function limits(array $config): array { return $config['editorial_mix'] ?? []; }
    public static function eligible(array $topics, array $counts, array $limits): array {
        $topics = array_filter($topics, static fn(array $topic): bool => !self::excludedTopic($topic['wiki_title'] ?? $topic['topic_key'] ?? ''));
        if ($limits === []) return $topics;
        $eligible = array_filter($topics, static fn(array $t): bool => ($counts[$t['kind'] ?? 'evergreen'] ?? 0) < ($limits[$t['kind'] ?? 'evergreen'] ?? 0));
        $recent = array_filter($eligible, static fn(array $t): bool => ($t['kind'] ?? '') === 'recent');
        return $recent !== [] ? $recent : $eligible;
    }
}
