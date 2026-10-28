<?php
declare(strict_types=1);
namespace Echappees;

use RuntimeException;

function ensure(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function jsonFile(string $file): array {
    ensure(is_file($file) && filesize($file) <= 25 * 1024 * 1024, 'Document absent ou trop volumineux.');
    $value = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    ensure(is_array($value), 'Objet JSON attendu.');
    return $value;
}
function atomicJson(string $file, array $value): void {
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    ensure(strlen($encoded) <= 25 * 1024 * 1024, 'Document trop volumineux.');
    if (!is_dir(dirname($file))) ensure(mkdir(dirname($file), 0755, true), 'Création du dossier impossible.');
    $temporary = tempnam(dirname($file), '.tmp-');
    try {
        ensure($temporary !== false && file_put_contents($temporary, $encoded, LOCK_EX) === strlen($encoded), 'Écriture interrompue.');
        ensure(rename($temporary, $file), 'Remplacement atomique impossible.');
    } finally {
        if ($temporary && is_file($temporary)) unlink($temporary);
    }
}
function plain(string $html): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}
function fingerprint(string $value): string {
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value, 'UTF-8')) ?? '');
}
function text(array $value, string $key, int $max): string {
    $s = $value[$key] ?? null;
    ensure(is_string($s) && trim($s) !== '' && mb_strlen($s) <= $max, 'Champ invalide : ' . $key);
    return trim($s);
}
function https(string $value, ?array $hosts = null): string {
    $parts = parse_url($value);
    ensure(strlen($value) <= 2000 && is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['fragment']) && (!isset($parts['port']) || $parts['port'] === 443), 'URL HTTPS non autorisée.');
    if ($hosts !== null) {
        $host = strtolower($parts['host']);
        $displayHost = preg_match('/^[a-z0-9.-]{1,100}$/D', $host) === 1 ? $host : 'invalide';
        ensure(in_array($host, $hosts, true), 'Hôte non autorisé : ' . $displayHost . '.');
    }
    return $value;
}
function quotaDate(array $config, ?\DateTimeImmutable $instant = null): string {
    $instant ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    return $instant->setTimezone(new \DateTimeZone($config['timezone'] ?? 'UTC'))->format('Y-m-d');
}
function config(string $root): array {
    $c = jsonFile($root . '/generator/config.json');
    ensure(($c['schema_version'] ?? null) === 1 && is_bool($c['enabled'] ?? null), 'Configuration incompatible.');
    foreach (['daily_count'=>50, 'max_per_run'=>50, 'max_catalog_articles'=>5000,
        'max_api_calls'=>100, 'max_input_bytes'=>30000, 'max_output_tokens'=>3000, 'source_max_age_days'=>90] as $key=>$max) {
        ensure(is_int($c[$key] ?? null) && $c[$key] > 0 && $c[$key] <= $max, 'Limite invalide : ' . $key);
    }
    foreach (['monthly_budget_usd', 'run_budget_usd', 'input_usd_per_million', 'output_usd_per_million'] as $key) {
        ensure(is_numeric($c[$key] ?? null) && $c[$key] > 0 && $c[$key] <= 100, 'Budget invalide : ' . $key);
    }
    ensure($c['run_budget_usd'] <= $c['monthly_budget_usd'], 'Plafonds incohérents.');
    ensure(preg_match('/^[a-z0-9][a-z0-9._-]{1,80}$/D', $c['model'] ?? '') === 1, 'Modèle invalide.');
    ensure(is_string($c['timezone'] ?? 'UTC') && in_array($c['timezone'] ?? 'UTC', timezone_identifiers_list(), true), 'Fuseau horaire IANA invalide.');
    https(text($c, 'contact', 500));
    https($c['base_url'], ['kevinrembert.github.io']);
    ensure(str_ends_with($c['base_url'], '/echappees-data/data/'), 'Base du catalogue invalide.');
    return $c;
}

final class RemoteFailure extends RuntimeException {
    public function __construct(public readonly bool $paidPost, public readonly int $status, string $body = '', string $host = '') {
        $service = match ($host) {
            'api.openai.com'=>'OpenAI', 'fr.wikipedia.org'=>'Wikipédia',
            'commons.wikimedia.org', 'upload.wikimedia.org', 'thumb.wikimedia.org'=>'Wikimedia Commons',
            'www.nasa.gov'=>'NASA', default=>'Service distant',
        };
        $detail = '';
        if ($paidPost && $host === 'api.openai.com') {
            $json = json_decode($body, true);
            $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
            // Seuls des codes connus donnent un diagnostic ; jamais le message ou le corps brut de l'API.
            $details = [
                'insufficient_quota'=>'Crédits API insuffisants ou plafond du projet atteint : vérifier Billing et Usage dans le projet OpenAI. Un abonnement ChatGPT ne finance pas les appels API.',
                'billing_hard_limit_reached'=>'Plafond de facturation du projet OpenAI atteint : vérifier Billing et Usage.',
                'rate_limit_exceeded'=>'Limite de débit OpenAI atteinte : attendre avant un nouveau lancement ; aucun POST automatique n’est rejoué.',
                'invalid_api_key'=>'Clé API refusée par OpenAI : vérifier le secret Actions et le projet associé.',
                'model_not_found'=>'Modèle OpenAI indisponible pour ce projet : vérifier son identifiant et les autorisations.',
            ];
            foreach (['code', 'type'] as $field) {
                if (is_string($error[$field] ?? null) && isset($details[$error[$field]])) { $detail = ' ' . $details[$error[$field]]; break; }
            }
            if ($detail === '' && $status === 429) $detail = ' Quota ou débit OpenAI à vérifier dans Billing/Usage ; aucun code précis exploitable reçu.';
        }
        parent::__construct($service . ' : requête refusée ou interrompue (HTTP ' . $status . ').' . $detail);
    }
}

interface Transport {
    public function request(string $url, ?array $payload = null, ?string $token = null, int $maxBytes = 4194304): array;
}

final class Http implements Transport {
    public const EDITORIAL_HOSTS = ['www.nasa.gov', 'www.esa.int', 'www.cnrs.fr', 'www.inrae.fr', 'icmpg.hub.inrae.fr', 'www.anses.fr', 'www.mangerbouger.fr', 'whc.unesco.org', 'www.kew.org', 'www.nhm.ac.uk', 'www.nps.gov', 'oceanservice.noaa.gov', 'www.exploratorium.edu', 'annex.exploratorium.edu', 'www.seriouseats.com'];
    private const HOSTS = ['fr.wikipedia.org', 'commons.wikimedia.org', 'upload.wikimedia.org', 'thumb.wikimedia.org', 'api.openai.com', ...self::EDITORIAL_HOSTS];
    public function __construct(private readonly string $contact) {}
    public function request(string $url, ?array $payload = null, ?string $token = null, int $maxBytes = 4194304): array {
        https($url, self::HOSTS);
        ensure($token === null || $url === 'https://api.openai.com/v1/responses', 'Destination du secret invalide.');
        $headers = ['Accept: application/json, text/html, application/rss+xml, application/xml', 'User-Agent: EchappeesContent/1.0 (' . $this->contact . ')'];
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        $attempts = $payload === null ? 3 : 1; // Un POST incertain n'est jamais rejoué.
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $body = ''; $tooLarge = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>60,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) { $tooLarge = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                }]);
            if ($payload !== null) curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            // libcurl utilise le proxy et le magasin CA de l'environnement.
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if ($ok !== false && $status === 200 && !$tooLarge) return ['body'=>$body, 'type'=>$type];
            if ($tooLarge || $attempt + 1 === $attempts || !($status === 429 || $status >= 500 || $status === 0)) {
                throw new RemoteFailure($payload !== null, $status, $body, (string)parse_url($url, PHP_URL_HOST));
            }
            usleep(1000000 * (2 ** $attempt));
        }
        throw new RuntimeException('Requête impossible.');
    }
}
function remoteJson(Transport $http, string $url): array {
    $result = $http->request($url);
    $json = json_decode($result['body'], true, 64, JSON_THROW_ON_ERROR);
    ensure(is_array($json), 'Réponse JSON invalide.');
    return $json;
}
