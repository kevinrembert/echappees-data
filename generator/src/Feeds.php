<?php
declare(strict_types=1);
namespace Echappees;

use DOMDocument;
use DOMXPath;

final class Feeds {
    public function __construct(private readonly Transport $http, private readonly array $config) {}
    public static function parse(string $xml, array $feed, int $maxAge, ?int $now = null): array {
        $now ??= time();
        ensure(strlen($xml) <= 2097152 && !str_contains($xml, "\0") && preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) !== 1, 'Flux XML non autorisé.');
        $doc = new DOMDocument(); $previous = libxml_use_internal_errors(true);
        try { $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        ensure($ok && in_array($doc->documentElement?->localName, ['rss', 'RDF', 'feed'], true), 'Flux RSS/Atom invalide.');
        $xpath = new DOMXPath($doc); $topics = [];
        foreach ($xpath->query('//*[local-name()="item" or local-name()="entry"]') as $item) {
            try {
                $value = static fn(string $names): string => trim($xpath->evaluate('string(./*[' . $names . '][1])', $item));
                $title = plain($value('local-name()="title"'));
                $dateText = $value('local-name()="pubDate" or local-name()="published" or local-name()="date"');
                $date = strtotime($dateText);
                if (!$date || $date > $now || $date < $now - $maxAge * 86400 || mb_strlen($title) < 4 || mb_strlen($title) > 180) continue;
                $url = $value('local-name()="link"');
                $atom = $xpath->query('./*[local-name()="link" and (not(@rel) or @rel="alternate")]/@href', $item)->item(0);
                if ($atom) $url = trim($atom->nodeValue);
                $url = https($url, $feed['article_hosts']);
                $topics[$url] = ['wiki_title'=>$title, 'source_url'=>$url, 'source_label'=>$feed['label'],
                    'photo_query'=>$title, 'category'=>$feed['category'], 'country'=>'Monde',
                    'kind'=>'recent', 'source_published_at'=>gmdate('Y-m-d', $date)];
            } catch (\Throwable) { /* Une entrée invalide ne condamne pas le flux. */ }
            if (count($topics) >= 12) break;
        }
        return array_values($topics);
    }
    public function collect(array $feeds): array {
        $topics = [];
        foreach ($feeds as $feed) {
            try {
                $url = https($feed['url'], Http::EDITORIAL_HOSTS);
                foreach ($feed['article_hosts'] as $host) ensure(in_array($host, Http::EDITORIAL_HOSTS, true), 'Hôte de flux non autorisé.');
                $items = self::parse($this->http->request($url, maxBytes: 2097152)['body'], $feed, $this->config['source_max_age_days']);
                echo 'Flux ' . $feed['label'] . ' : ' . count($items) . " candidats.\n";
                foreach ($items as $item) $topics[$item['source_url']] = $item;
            } catch (\Throwable $e) {
                echo 'Flux ' . $feed['label'] . ' indisponible : ' . ($e instanceof \RuntimeException ? $e->getMessage() : 'données invalides.') . "\n";
            }
        }
        return array_values($topics);
    }
    public static function provider(string $url): string {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === 'cnrs.fr' || str_ends_with($host, '.cnrs.fr')) return 'cnrs.fr';
        return preg_replace('/^www\./', '', $host);
    }
    public static function next(array $topics, array $providers, array $categories): int {
        $best = 0; $score = PHP_INT_MAX;
        foreach ($topics as $index=>$topic) {
            $provider = self::provider($topic['source_url'] ?? 'https://fr.wikipedia.org');
            $candidate = 100 * ($providers[$provider] ?? 0) + ($categories[$topic['category'] ?? 'Divers'] ?? 0);
            if ($candidate < $score) { $score = $candidate; $best = $index; }
        }
        return $best;
    }
}
