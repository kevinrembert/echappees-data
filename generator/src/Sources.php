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
        $title = text($topic, 'wiki_title', 200);
        $query = $this->api('fr.wikipedia.org', ['action'=>'query', 'redirects'=>1, 'prop'=>'extracts|pageimages|pageprops|info',
            'explaintext'=>1, 'exintro'=>1, 'piprop'=>'name', 'pilicense'=>'free', 'inprop'=>'url', 'titles'=>$title]);
        $page = $query['query']['pages'][0] ?? [];
        ensure(!isset($page['missing']) && !isset($page['pageprops']['disambiguation']) && isset($page['pageid']), 'Page absente ou homonymie.');
        $extract = text($page, 'extract', 12000);
        ensure(mb_strlen($extract) >= 400, 'Source documentaire insuffisante.');
        $wikiUrl = https(text($page, 'fullurl', 2000), ['fr.wikipedia.org']);
        $photoName = text($page, 'pageimage', 300);
        $imageQuery = $this->api('commons.wikimedia.org', ['action'=>'query', 'titles'=>'File:' . $photoName,
            'prop'=>'imageinfo', 'iiprop'=>'url|extmetadata|mime|mediatype|sha1', 'iiurlwidth'=>1280]);
        $info = $imageQuery['query']['pages'][0]['imageinfo'][0] ?? [];
        $photo = self::photo($info);
        $subject = $page['pageprops']['wikibase_item'] ?? 'frwiki-' . $page['pageid'];
        ensure(preg_match('/^(Q[0-9]+|frwiki-[0-9]+)$/D', $subject) === 1, 'Identité documentaire invalide.');
        $source = ['subject_id'=>$subject, 'wiki_title'=>$page['title'], 'excerpt'=>$extract,
            'sources'=>[['label'=>'Wikipédia · ' . $page['title'], 'url'=>$wikiUrl]], 'photo'=>$photo,
            'category'=>$topic['category'] ?? null, 'country'=>$topic['country'] ?? 'Monde', 'kind'=>'evergreen'];
        if (($topic['kind'] ?? '') === 'recent') {
            $date = text($topic, 'source_published_at', 10);
            ensure(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 && gmdate('Y-m-d', strtotime($date)) === $date
                && $date <= gmdate('Y-m-d') && $date >= gmdate('Y-m-d', time() - 86400 * $this->config['source_max_age_days']), 'Actualité périmée ou future.');
            $url = https(text($topic, 'source_url', 2000), ['www.nasa.gov', 'www.esa.int', 'www.cnrs.fr']);
            $html = $this->http->request($url, maxBytes: 2097152)['body'];
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors(); libxml_use_internal_errors($previous);
            $xpath = new DOMXPath($document);
            $publishedDates = [];
            foreach ($xpath->query('//meta[@property="article:published_time" or @name="date" or @name="DC.date.issued"]/@content|//time/@datetime') as $value) {
                $timestamp = strtotime($value->nodeValue);
                if ($timestamp !== false) $publishedDates[] = gmdate('Y-m-d', $timestamp);
            }
            $scanDates = static function ($value, int $depth = 0) use (&$scanDates, &$publishedDates): void {
                if (!is_array($value) || $depth > 10) return;
                foreach ($value as $key=>$item) {
                    if ($key === 'datePublished' && is_string($item) && strtotime($item) !== false) $publishedDates[] = gmdate('Y-m-d', strtotime($item));
                    elseif (is_array($item)) $scanDates($item, $depth + 1);
                }
            };
            foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) $scanDates(json_decode($script->textContent, true));
            ensure(in_array($date, $publishedDates, true), 'Date de publication non confirmée par la source primaire.');
            foreach ($xpath->query('//script|//style|//nav|//footer|//header|//aside') as $node) $node->parentNode?->removeChild($node);
            $main = $xpath->query('//article|//main')->item(0);
            ensure($main !== null, 'Corps de la source institutionnelle introuvable.');
            $primary = plain($main->textContent);
            ensure(mb_strlen($primary) >= 400, 'Communiqué insuffisant.');
            $source['primary_excerpt'] = mb_substr($primary, 0, 10000);
            $source['source_published_at'] = $date;
            $source['subject_id'] = 'news-' . substr(hash('sha256', $url), 0, 32);
            $source['sources'][] = ['label'=>'Communiqué institutionnel du ' . $date, 'url'=>$url];
            $source['kind'] = 'recent';
        }
        return $source;
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
    public function discover(): array {
        $result = $this->api('fr.wikipedia.org', ['action'=>'query', 'list'=>'random', 'rnnamespace'=>0, 'rnlimit'=>20]);
        return array_map(static fn(array $page): array => ['wiki_title'=>$page['title'], 'country'=>'Monde', 'kind'=>'evergreen'], $result['query']['random'] ?? []);
    }
    public function recent(): array {
        $xml = $this->http->request('https://www.nasa.gov/news-release/feed/', maxBytes: 2097152)['body'];
        ensure(!str_contains(strtoupper($xml), '<!DOCTYPE') && !str_contains(strtoupper($xml), '<!ENTITY'), 'Flux XML non autorisé.');
        $previous = libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        ensure($rss !== false, 'Flux récent invalide.');
        $topics = [];
        foreach ($rss->channel->item ?? [] as $item) {
            $date = strtotime((string)$item->pubDate);
            if (!$date || $date > time() || $date < time() - $this->config['source_max_age_days'] * 86400) continue;
            $url = https((string)$item->link, ['www.nasa.gov']);
            $search = $this->api('fr.wikipedia.org', ['action'=>'query', 'list'=>'search', 'srsearch'=>mb_substr(plain((string)$item->title), 0, 200), 'srlimit'=>1]);
            $title = $search['query']['search'][0]['title'] ?? null;
            if ($title) $topics[] = ['wiki_title'=>$title, 'kind'=>'recent', 'country'=>'Monde', 'source_url'=>$url, 'source_published_at'=>gmdate('Y-m-d', $date)];
            if (count($topics) >= 5) break;
        }
        return $topics;
    }
}
