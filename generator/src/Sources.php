<?php
declare(strict_types=1);
namespace Echappees;

use DOMDocument;
use DOMXPath;

final class Sources {
    public function __construct(private readonly Transport $http, private readonly array $config) {}
    private function api(string $host, array $params): array {
        return remoteJson($this->http, 'https://' . $host . '/w/api.php?' . http_build_query(['format'=>'json', 'formatversion'=>2, ...$params], '', '&', PHP_QUERY_RFC3986));
    }
    public function collect(array $topic): array {
        if (isset($topic['source_url'])) return $this->collectExternal($topic);
        $title = text($topic, 'wiki_title', 200);
        $query = $this->api('fr.wikipedia.org', ['action'=>'query', 'redirects'=>1, 'prop'=>'extracts|pageimages|pageprops|info',
            'explaintext'=>1, 'exchars'=>1200, 'piprop'=>'name', 'pilicense'=>'free', 'inprop'=>'url', 'titles'=>$title]);
        $page = $query['query']['pages'][0] ?? [];
        ensure(!isset($page['missing']) && !isset($page['pageprops']['disambiguation']) && isset($page['pageid']), 'Page absente ou homonymie.');
        $extract = text($page, 'extract', 12000);
        ensure(mb_strlen($extract) >= 400, 'Source documentaire insuffisante.');
        $wikiUrl = https(text($page, 'fullurl', 2000), ['fr.wikipedia.org']);
        try {
            $photoName = text($page, 'pageimage', 300);
            $imageQuery = $this->api('commons.wikimedia.org', ['action'=>'query', 'titles'=>'File:' . $photoName,
                'prop'=>'imageinfo', 'iiprop'=>'url|extmetadata|mime|mediatype|sha1', 'iiurlwidth'=>1280]);
            $photo = self::photo($imageQuery['query']['pages'][0]['imageinfo'][0] ?? []);
        } catch (\Throwable) {
            $photo = $this->findPhoto($topic['photo_query'] ?? $page['title']);
        }
        $subject = $page['pageprops']['wikibase_item'] ?? 'frwiki-' . $page['pageid'];
        ensure(preg_match('/^(Q[0-9]+|frwiki-[0-9]+)$/D', $subject) === 1, 'Identité documentaire invalide.');
        $source = ['subject_id'=>$subject, 'wiki_title'=>$page['title'], 'excerpt'=>$extract,
            'sources'=>[['label'=>'Wikipédia · ' . $page['title'], 'url'=>$wikiUrl]], 'photo'=>$photo,
            'category'=>$topic['category'] ?? null, 'country'=>$topic['country'] ?? 'Monde', 'kind'=>'evergreen'];
        return $source;
    }
    private function document(string $url): array {
        $url = https($url, Http::EDITORIAL_HOSTS);
        $html = $this->http->request($url, maxBytes: 2097152)['body'];
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return [$document, new DOMXPath($document)];
    }
    private function collectExternal(array $topic): array {
        $url = https(text($topic, 'source_url', 2000), Http::EDITORIAL_HOSTS);
        [$document, $xpath] = $this->document($url);
        $date = null;
        if (($topic['kind'] ?? '') === 'recent') {
            $date = text($topic, 'source_published_at', 10);
            ensure(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 && strtotime($date) !== false
                && gmdate('Y-m-d', strtotime($date)) === $date && $date <= gmdate('Y-m-d')
                && $date >= gmdate('Y-m-d', time() - 86400 * $this->config['source_max_age_days']), 'Actualité périmée ou future.');
            $confirmed = false;
            foreach ($xpath->query('//meta[@property="article:published_time" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="date" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dc.date" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dc.date.issued" or @name="citation_publication_date"]/@content|//time[not(@itemprop="dateModified")]/@datetime') as $value) {
                if (strtotime($value->nodeValue) !== false && gmdate('Y-m-d', strtotime($value->nodeValue)) === $date) $confirmed = true;
            }
            $scan = static function ($value, int $depth = 0) use (&$scan, &$confirmed, $date): void {
                if (!is_array($value) || $depth > 10) return;
                foreach ($value as $key=>$item) {
                    if ($key === 'datePublished' && is_string($item) && strtotime($item) !== false && gmdate('Y-m-d', strtotime($item)) === $date) $confirmed = true;
                    elseif (is_array($item)) $scan($item, $depth + 1);
                }
            };
            foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) $scan(json_decode($script->textContent, true));
            ensure($confirmed, 'Date de publication non confirmée par la source primaire.');
        }
        foreach ($xpath->query('//script|//style|//nav|//footer|//header|//aside|//form') as $node) $node->parentNode?->removeChild($node);
        $main = $xpath->query('//article')->item(0) ?? $xpath->query('//main')->item(0) ?? $xpath->query('//*[@id="content"]')->item(0) ?? $xpath->query('//body')->item(0);
        ensure($main !== null, 'Corps de la source introuvable.');
        $excerpt = plain($main->textContent);
        ensure(mb_strlen($excerpt) >= 400, 'Source documentaire insuffisante.');
        $photo = $this->findPhoto($topic['photo_query'] ?? text($topic, 'wiki_title', 200));
        $source = ['subject_id'=>($date === null ? 'source-' : 'news-') . substr(hash('sha256', $url), 0, 32),
            'wiki_title'=>text($topic, 'wiki_title', 200), 'excerpt'=>mb_substr($excerpt, 0, 7000),
            'sources'=>[['label'=>(isset($topic['source_label']) ? text($topic, 'source_label', 200) : parse_url($url, PHP_URL_HOST)), 'url'=>$url]], 'photo'=>$photo,
            'category'=>$topic['category'] ?? null, 'country'=>$topic['country'] ?? 'Monde', 'kind'=>$topic['kind'] ?? 'evergreen'];
        if ($date !== null) $source['source_published_at'] = $date;
        return $source;
    }
    private function findPhoto(string $query): array {
        $result = $this->api('commons.wikimedia.org', ['action'=>'query', 'generator'=>'search', 'gsrnamespace'=>6,
            'gsrsearch'=>$query . ' filetype:bitmap', 'gsrlimit'=>8, 'prop'=>'imageinfo',
            'iiprop'=>'url|extmetadata|mime|mediatype|sha1', 'iiurlwidth'=>1280]);
        foreach ($result['query']['pages'] ?? [] as $page) {
            try { return self::photo($page['imageinfo'][0] ?? []); } catch (\RuntimeException) { }
        }
        throw new \RuntimeException('Aucune photographie réutilisable vérifiée pour ce sujet.');
    }
    public function probe(array $topics): array {
        $accepted = 0; $rejected = 0;
        foreach (array_slice($topics, 0, 3) as $topic) {
            try {
                $this->collect($topic); $accepted++;
                echo "Collecte documentaire acceptée.\n";
            } catch (\Throwable $error) {
                $rejected++;
                $reason = $error instanceof \RuntimeException ? $error->getMessage() : 'Données de source invalides.';
                echo '::warning title=Diagnostic de collecte::' . $reason . "\n";
            }
        }
        return ['accepted'=>$accepted, 'rejected'=>$rejected, 'openai_calls'=>0];
    }
    public static function photo(array $info): array {
        ensure(($info['mediatype'] ?? '') === 'BITMAP' && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png'], true), 'Photo matricielle JPEG/PNG requise.');
        $metadata = $info['extmetadata'] ?? [];
        $get = static fn(string $key): string => plain((string)($metadata[$key]['value'] ?? ''));
        $license = $get('LicenseShortName');
        $licenseUrl = $get('LicenseUrl');
        ensure(preg_match('/^(CC BY(?:-SA)? (?:2\.0|2\.5|3\.0|4\.0)|CC0|Public domain)$/D', $license) === 1, 'Licence non prise en charge.');
        if ($license === 'Public domain') {
            $licenseUrl = 'https://commons.wikimedia.org/wiki/Commons:Public_domain';
        } else {
            // Les métadonnées historiques peuvent contenir http:// ou // ; aucune
            // requête HTTP n'est faite : seul un lien CC reconnu est canonisé en HTTPS.
            $rawUrl = str_starts_with($licenseUrl, '//') ? 'https:' . $licenseUrl : $licenseUrl;
            $parts = parse_url($rawUrl);
            ensure(is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                && in_array(strtolower($parts['host'] ?? ''), ['creativecommons.org', 'www.creativecommons.org'], true)
                && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
                && !isset($parts['query']) && !isset($parts['fragment']), 'URL de licence Creative Commons non reconnue.');
            if ($license === 'CC0') {
                $expectedPath = '/publicdomain/zero/1.0/';
            } else {
                preg_match('/^CC (BY(?:-SA)?) ([0-9.]+)$/D', $license, $matches);
                $expectedPath = '/licenses/' . strtolower($matches[1]) . '/' . $matches[2] . '/';
            }
            $path = preg_replace('~/(?:deed|legalcode)(?:\.[a-zA-Z-]+)?/?$~D', '/', $parts['path'] ?? '');
            ensure(rtrim($path, '/') === rtrim($expectedPath, '/'), 'URL et libellé de licence incohérents.');
            $licenseUrl = 'https://creativecommons.org' . $expectedPath;
        }
        ensure($get('Restrictions') === '', 'Restrictions supplémentaires à examiner.');
        $artist = $get('Artist'); $description = $get('ImageDescription'); $credit = $get('Credit');
        ensure($artist !== '' && $description !== '' && mb_strlen($artist) <= 300, 'Auteur ou description de photo insuffisant.');
        $all = mb_strtolower($description . ' ' . $get('Categories') . ' ' . ($info['descriptionurl'] ?? ''));
        ensure(preg_match('/(?:ai.generated|generated.by.ai|stable.diffusion|midjourney|dall.e|artificial.intelligence.generated|\bdiagram\b|\bmap\b|\bpainting\b|\billustration\b|\bdrawing\b)/u', $all) !== 1,
            'Origine photographique incertaine.');
        $attribution = $artist . ($credit !== '' && $credit !== $artist ? ' · ' . $credit : '') . ' · Wikimedia Commons · Version mobile redimensionnée/compressée';
        ensure(mb_strlen($attribution) <= 500, 'Attribution trop longue ; revue nécessaire.');
        $download = https($info['thumburl'] ?? $info['url'] ?? '', ['upload.wikimedia.org', 'thumb.wikimedia.org']);
        $sourceUrl = https($info['descriptionurl'] ?? '', ['commons.wikimedia.org']);
        return ['download_url'=>$download, 'source_url'=>$sourceUrl, 'credit'=>$attribution,
            'license'=>$license . ' · ' . $licenseUrl, 'description'=>mb_substr($description, 0, 1800),
            'license_url'=>$licenseUrl, 'original_sha1'=>$info['sha1'] ?? ''];
    }
    public function discover(int $round = 0): array {
        if ($round === 0) {
            [, $xpath] = $this->document('https://oceanservice.noaa.gov/facts/oceanfacts-oceanlife.html');
            $topics = [];
            foreach ($xpath->query('//a[@href]') as $link) {
                $href = $link->getAttribute('href');
                $parts = parse_url($href);
                if (!is_array($parts) || (isset($parts['host']) && $parts['host'] !== 'oceanservice.noaa.gov')) continue;
                $path = $parts['path'] ?? '';
                if (!str_starts_with($path, '/')) $path = '/facts/' . $path;
                if (preg_match('~^/facts/[a-z0-9-]+\.html$~D', $path) !== 1 || str_contains($path, 'oceanfacts-')) continue;
                $title = plain($link->textContent);
                if (mb_strlen($title) < 10 || mb_strlen($title) > 180) continue;
                $url = 'https://oceanservice.noaa.gov' . $path;
                $topics[$url] = ['wiki_title'=>$title, 'source_url'=>$url, 'source_label'=>'NOAA · ' . $title,
                    'photo_query'=>$title, 'country'=>'Monde', 'kind'=>'evergreen', 'category'=>'Nature et animaux'];
            }
            uksort($topics, static fn(string $a, string $b): int => strcmp(hash('sha256', gmdate('Y-m-d') . $a), hash('sha256', gmdate('Y-m-d') . $b)));
            return array_slice(array_values($topics), 0, 20);
        }
        if ($round === 7) {
            [$document, $xpath] = $this->document('https://whc.unesco.org/en/list/');
            $topics = [];
            foreach ($xpath->query('//a[@href]') as $link) {
                $path = parse_url($link->getAttribute('href'), PHP_URL_PATH);
                if (!is_string($path) || preg_match('~^/en/list/([0-9]+)/?$~D', $path, $match) !== 1) continue;
                $title = plain($link->textContent);
                if (mb_strlen($title) < 4 || mb_strlen($title) > 180) continue;
                $url = 'https://whc.unesco.org/en/list/' . $match[1] . '/';
                $topics[$url] = ['wiki_title'=>$title, 'source_url'=>$url, 'source_label'=>'UNESCO · ' . $title,
                    'photo_query'=>$title, 'country'=>'Monde', 'kind'=>'evergreen', 'category'=>'Histoire et patrimoine'];
            }
            // Chaque jour visite une autre partie de la liste, sans page aléatoire.
            uksort($topics, static fn(string $a, string $b): int => strcmp(hash('sha256', gmdate('Y-m-d') . $a), hash('sha256', gmdate('Y-m-d') . $b)));
            return array_slice(array_values($topics), $round === 0 ? 0 : 20, 20);
        }
        $themes = [
            ['jardin botanique', 'Initiatives positives'], ['village', 'Villages et villes'],
            ['parc national', 'Voyages et paysages'], ['oiseau', 'Nature et animaux'],
            ['observatoire', 'Sciences'], ['patrimoine mondial', 'Histoire et patrimoine'], ['cuisine', 'Gastronomie et traditions'],
        ];
        [$query, $category] = $themes[$round % count($themes)];
        $result = $this->api('fr.wikipedia.org', ['action'=>'query', 'list'=>'search', 'srnamespace'=>0,
            'srsearch'=>$query, 'srlimit'=>20, 'sroffset'=>intdiv($round, count($themes)) * 20]);
        return array_map(static fn(array $page): array => ['wiki_title'=>$page['title'], 'category'=>$category,
            'country'=>'Monde', 'kind'=>'evergreen'], $result['query']['search'] ?? []);
    }
    public function recent(): array {
        return (new Feeds($this->http, $this->config))->collect(jsonFile(dirname(__DIR__) . '/feeds.json'));
    }
}
