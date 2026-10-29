<?php
declare(strict_types=1);
namespace Echappees;

final class Generator {
    public function __construct(private readonly string $root, private readonly array $config,
        private readonly Transport $http, private readonly Budget $budget) {}
    public function mode(): string {
        $test = $this->config['test_batch'] ?? null;
        if ($test && $test['date'] === quotaDate($this->config) && $this->budget->usedMode('test:' . $test['id']) < $test['count']) return 'test:' . $test['id'];
        return 'daily';
    }
    public function count(): int {
        $catalog = new Catalog($this->root);
        $remaining = $this->config['max_catalog_articles'] - count($catalog->articles);
        $quota = $this->mode() === 'daily' ? $this->config['daily_count'] - $this->budget->dailyUsed()
            : $this->config['test_batch']['count'] - $this->budget->usedMode($this->mode());
        return max(0, min($remaining, $this->config['max_per_run'],
            $quota, intdiv($this->config['max_api_calls'], 2)));
    }
    public function run(string $run, string $key): array {
        ensure(getenv('GITHUB_ACTIONS') === 'true', 'Les appels OpenAI sont réservés à GitHub Actions.');
        ensure($this->config['enabled'] && getenv('GENERATION_ENABLED') === 'true', 'Génération désactivée.');
        ensure($key !== '', 'Secret OPENAI_API_KEY absent dans GitHub Actions.');
        $entry = $this->budget->entry($run);
        $catalog = new Catalog($this->root);
        $this->budget->start($run);
        $stateFile = $this->root . '/generator/state/subjects.json';
        $state = is_file($stateFile) ? jsonFile($stateFile) : ['schema_version'=>1, 'subjects'=>[], 'failed'=>[]];
        ensure(($state['schema_version'] ?? null) === 1 && is_array($state['subjects'] ?? null) && is_array($state['failed'] ?? null), 'Registre des sujets invalide.');
        if (($state['failure_version'] ?? '') !== 'positive-selection-v3') {
            $state['failed'] = []; $state['failure_version'] = 'positive-selection-v3';
        }
        $writer = new Writer($this->http, $this->budget, $this->config, $run, $key);
        $sources = new Sources($this->http, $this->config, $writer->selectRecent(...));
        $articles = []; $audit = []; $skipped = 0;
        try {
            $recent = jsonFile($this->root . '/generator/recent.json');
            try { $recent = [...$recent, ...$sources->recent()]; } catch (\Throwable) { echo "Flux récent indisponible : poursuite avec les sources disponibles.\n"; }
            $discovered = [];
            try { $discovered = $sources->discover(0); } catch (\Throwable) { echo "Index NOAA indisponible : poursuite avec les autres sources.\n"; }
            $externalFile = $this->root . '/generator/editorial-topics.json';
            $topics = [...$recent, ...(is_file($externalFile) ? jsonFile($externalFile) : []), ...$discovered, ...jsonFile($this->root . '/generator/topics.json')];
            // Les lots sont listés du plus récent au plus ancien : remettre les
            // précédents textes dans l'autre sens pour garder les plus récents en fin.
            $history = array_reverse(array_values($catalog->articles));
            $knownTitles = array_map(static fn(array $a): string => $a['title'], $history);
            $openings = array_map(static fn(array $a): string => mb_substr($a['body'], 0, 160), $history);
            $usedTitles = array_fill_keys(['ainhoa', 'porto', 'machu picchu', 'récif corallien', 'glacier'], true);
            $providers = []; $categories = []; $mix = [];
            foreach ($catalog->articles as $article) {
                if (($article['published_at'] ?? '') !== quotaDate($this->config)) continue;
                if (($article['editorial_policy'] ?? '') === Editorial::VERSION && ($article['editorial_batch'] ?? 'daily') === $entry['mode']) {
                    $kind = $article['editorial_kind']; $mix[$kind] = ($mix[$kind] ?? 0) + 1;
                }
                $provider = Feeds::provider($article['sources'][0]['url']);
                $providers[$provider] = ($providers[$provider] ?? 0) + 1;
                $categories[$article['category']] = ($categories[$article['category']] ?? 0) + 1;
            }
            $deadline = time() + 1200; $attempts = 0; $round = 1; $tried = [];
            for ($cursor = 0; count($articles) < $entry['count'] && $attempts < 180 && time() < $deadline; $cursor++) {
                if (!isset($topics[$cursor])) {
                    if ($round >= 14) break;
                    $batchRound = $round++;
                    try { $fresh = $sources->discover($batchRound); }
                    catch (\Throwable) { echo "Recherche de candidats indisponible : autre thème.\n"; $cursor--; continue; }
                    $topics = [...$topics, ...$fresh];
                    if (!isset($topics[$cursor])) { $cursor--; continue; }
                }
                if ($this->budget->entry($run)['calls'] + 2 > $entry['max_calls']) break;
                $remaining = array_slice($topics, $cursor, null, true);
                $eligible = Editorial::eligible($remaining, $mix, Editorial::limits($this->config));
                if ($eligible === []) break;
                $next = Feeds::next($eligible, $providers, $categories);
                [$topics[$cursor], $topics[$next]] = [$topics[$next], $topics[$cursor]];
                $topic = $topics[$cursor]; $titleKey = fingerprint($topic['topic_key'] ?? $topic['wiki_title'] ?? '');
                if ($titleKey === '' || isset($usedTitles[$titleKey]) || isset($state['subjects']['title:' . $titleKey])) continue;
                if (isset($tried[$titleKey])) continue;
                if (($state['failed'][$titleKey] ?? '') >= gmdate('Y-m-d')) continue;
                $tried[$titleKey] = true;
                $attempts++;
                try {
                    $source = $sources->collect($topic);
                    $id = 'decouverte-' . substr(hash('sha256', $source['subject_id']), 0, 24);
                    if (isset($state['subjects'][$source['subject_id']]) || isset($catalog->subjects[$source['subject_id']]) || isset($catalog->articles[$id])) continue;
                    $draft = $writer->write($source, $knownTitles, $openings);
                    ensure(!isset($catalog->titles[fingerprint($draft['title'])]) && !in_array(fingerprint($draft['title']), array_map(__NAMESPACE__ . '\\fingerprint', $knownTitles), true), 'Titre déjà publié.');
                    $photoUrl = $this->saveImage($source['photo']);
                    $kind = $source['kind'];
                    $article = ['editorial_policy'=>Editorial::VERSION, 'editorial_kind'=>$kind, 'editorial_batch'=>$entry['mode'], 'id'=>$id, ...$draft, 'image_url'=>$photoUrl, 'image_credit'=>$source['photo']['credit'],
                        'image_license'=>$source['photo']['license'], 'image_source_url'=>$source['photo']['source_url'],
                        'sources'=>$source['sources'], 'subject_id'=>$source['subject_id'], 'published_at'=>gmdate('Y-m-d'), 'country'=>$source['country']];
                    if ($source['kind'] === 'recent') $article['source_published_at'] = $source['source_published_at'];
                    Catalog::validateArticle($article);
                    $provider = Feeds::provider($article['sources'][0]['url']);
                    $providers[$provider] = ($providers[$provider] ?? 0) + 1;
                    $categories[$article['category']] = ($categories[$article['category']] ?? 0) + 1;
                    $mix[$kind] = ($mix[$kind] ?? 0) + 1;
                    $articles[] = $article; $knownTitles[] = $article['title'];
                    $openings[] = mb_substr($article['body'], 0, 160);
                    $state['subjects'][$source['subject_id']] = $id; $state['subjects']['title:' . $titleKey] = $id;
                    $usedTitles[$titleKey] = true;
                    $audit[] = ['id'=>$id, 'subject_id'=>$source['subject_id'], 'collected_at'=>gmdate(DATE_ATOM),
                        'sources'=>$source['sources'], 'source_sha256'=>hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)),
                        'image_source_url'=>$source['photo']['source_url'], 'image_license'=>$source['photo']['license'],
                        'original_sha1'=>$source['photo']['original_sha1'], 'model'=>$this->config['model'],
                        'review'=>'automated-approved', 'positive_change'=>$writer->positiveChange(), 'fact_checks'=>$writer->factChecks(), 'modifications'=>'Redimensionnement proportionnel à 1280 px maximum et JPEG qualité 82.'];
                } catch (\Throwable $error) {
                    if ($error instanceof RemoteFailure && $error->paidPost) throw $error;
                    $skipped++;
                    // Une panne du fournisseur ne condamne pas un sujet pendant 7 jours.
                    if (!$error instanceof RemoteFailure) $state['failed'][$titleKey] = gmdate('Y-m-d');
                    // Ne jamais afficher les corps de réponse API ou les exceptions de transport contenant des données distantes.
                    echo 'Candidat écarté (' . $attempts . ') : ' . ($error instanceof \RuntimeException ? $error->getMessage() : 'données invalides.') . "\n";
                }
            }
            if ($articles !== []) {
                $catalog->append($articles, $run);
                atomicJson($this->root . '/generator/state/audit-' . substr(hash('sha256', $run), 0, 20) . '.json', ['schema_version'=>1, 'articles'=>$audit]);
            }
            atomicJson($stateFile, $state);
            $this->budget->finish($run, 'completed', count($articles));
            return ['published'=>count($articles), 'skipped'=>$skipped, 'target'=>$entry['count']];
        } catch (\Throwable $error) {
            $this->budget->finish($run, 'failed');
            throw $error;
        }
    }
    private function saveImage(array $photo): string {
        $response = $this->http->request($photo['download_url'], maxBytes: 12 * 1024 * 1024);
        ensure(preg_match('~^image/(jpeg|png)(?:;|$)~i', $response['type']) === 1, 'Type de photo invalide.');
        $dimensions = getimagesizefromstring($response['body']);
        ensure($dimensions !== false && $dimensions[0] > 0 && $dimensions[1] > 0 && $dimensions[0] * $dimensions[1] <= 16000000, 'Dimensions de photo non prises en charge.');
        $image = imagecreatefromstring($response['body']);
        ensure($image !== false, 'Photo indécodable.');
        $ratio = min(1, 1280 / max(imagesx($image), imagesy($image)));
        $resized = imagecreatetruecolor(max(1, (int)round(imagesx($image) * $ratio)), max(1, (int)round(imagesy($image) * $ratio)));
        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        ensure(imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), imagesx($image), imagesy($image)), 'Redimensionnement impossible.');
        imageinterlace($resized, true);
        ob_start(); $encoded = imagejpeg($resized, null, 82); $bytes = ob_get_clean();
        ensure($encoded && is_string($bytes) && strlen($bytes) <= 12 * 1024 * 1024, 'Encodage photo impossible.');
        $relative = 'images/generated/' . hash('sha256', $bytes) . '.jpg'; $path = $this->root . '/data/' . $relative;
        if (!is_dir(dirname($path))) ensure(mkdir(dirname($path), 0755, true), 'Dossier photo impossible.');
        if (!is_file($path)) {
            $temp = tempnam(dirname($path), '.tmp-');
            ensure($temp !== false && file_put_contents($temp, $bytes, LOCK_EX) === strlen($bytes) && rename($temp, $path), 'Écriture photo impossible.');
        }
        return $this->config['base_url'] . $relative;
    }
}
