<?php
declare(strict_types=1);
namespace Echappees;

final class Editorial {
    public const VERSION = 'positive-v1';
    public static function limits(array $config): array { return $config['editorial_mix'] ?? []; }
    public static function eligible(array $topics, array $counts, array $limits): array {
        if ($limits === []) return $topics;
        $eligible = array_filter($topics, static fn(array $t): bool => ($counts[$t['kind'] ?? 'evergreen'] ?? 0) < ($limits[$t['kind'] ?? 'evergreen'] ?? 0));
        $recent = array_filter($eligible, static fn(array $t): bool => ($t['kind'] ?? '') === 'recent');
        return $recent !== [] ? $recent : $eligible;
    }
}
