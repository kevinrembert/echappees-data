<?php
declare(strict_types=1);
namespace Echappees;

final class Generator {
    public function __construct(private readonly string $root, private readonly array $config,
        private readonly Transport $http, private readonly Budget $budget) {}
    public function count(string $mode): int {
        ensure(in_array($mode, ['bootstrap', 'daily'], true), 'Mode inconnu.');
        $catalog = new Catalog($this->root);
        $remaining = $this->config['max_catalog_articles'] - count($catalog->articles);
        if ($mode === 'bootstrap' || count($catalog->articles) < $this->config['initial_target']) {
            $remaining = min($remaining, $this->config['initial_target'] - count($catalog->articles));
        }
        $dayLimit = $mode === 'bootstrap' ? $this->config['initial_target'] : $this->config['daily_count'];
        return max(0, min($remaining, $this->config['max_per_run'], $dayLimit - $this->budget->dailyUsed(), intdiv($this->config['max_api_calls'], 2)));
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
        $writer = new Writer($this->http, $this->budget, $this->config, $run, $key);
        $sources = new Sources($this->http, $this->config);
        $articles = []; $audit = []; $skipped = 0;
        try {
            $recent = jsonFile($this->root . '/generator/recent.json');
            try { $recent = [...$recent, ...$sources->recent()]; } catch (\Throwable) { echo "Flux récent indisponible : poursuite avec les sources disponibles.\n"; }
            $topics = [...$recent, ...jsonFile($this->root . '/generator/topics.json')];
            $knownTitles = array_map(static fn(array $a): string => $a['title'], array_values($catalog->articles));
            $usedTitles = array_fill_keys(['ainhoa', 'porto', 'machu picchu', 'récif corallien', 'glacier'], true);
            $deadline = time() + 1200; $attempts = 0; $discovered = false;
            for ($cursor = 0; count($articles) < $entry['count'] && $attempts < 80 && time() < $deadline; $cursor++) {
                if (!isset($topics[$cursor])) {
                    if ($discovered) break;
                    $topics = [...$topics, ...$sources->discover()]; $discovered = true;
                    if (!isset($topics[$cursor])) break;
                }
                if ($this->budget->entry($run)['calls'] >= $entry['max_calls']) break;
                $topic = $topics[$cursor]; $titleKey = fingerprint($topic['wiki_title'] ?? '');
                if ($titleKey === '' || isset($usedTitles[$titleKey]) || isset($state['subjects']['title:' . $titleKey])) continue;
                if (($state['failed'][$titleKey] ?? '') >= gmdate('Y-m-d', time() - 7 * 86400)) continue;
                $attempts++;
                try {
                    $source = $sources->collect($topic);
                    $id = 'decouverte-' . substr(hash('sha256', $source['subject_id']), 0, 24);
                    if (isset($state['subjects'][$source['subject_id']]) || isset($catalog->subjects[$source['subject_id']]) || isset($catalog->articles[$id])) continue;
                    $draft = $writer->write($source, $knownTitles);
                    ensure(!isset($catalog->titles[fingerprint($draft['title'])]) && !in_array(fingerprint($draft['title']), array_map(__NAMESPACE__ . '\\fingerprint', $knownTitles), true), 'Titre déjà publié.');
                    $photoUrl = $this->saveImage($source['photo']);
                    $article = ['id'=>$id, ...$draft, 'image_url'=>$photoUrl, 'image_credit'=>$source['photo']['credit'],
                        'image_license'=>$source['photo']['license'], 'image_source_url'=>$source['photo']['source_url'],
                        'sources'=>$source['sources'], 'subject_id'=>$source['subject_id'], 'published_at'=>gmdate('Y-m-d'), 'country'=>$source['country']];
                    if ($source['kind'] === 'recent') $article['source_published_at'] = $source['source_published_at'];
                    Catalog::validateArticle($article);
                    $articles[] = $article; $knownTitles[] = $article['title'];
                    $state['subjects'][$source['subject_id']] = $id; $state['subjects']['title:' . $titleKey] = $id;
                    $usedTitles[$titleKey] = true;
                    $audit[] = ['id'=>$id, 'subject_id'=>$source['subject_id'], 'collected_at'=>gmdate(DATE_ATOM),
                        'sources'=>$source['sources'], 'source_sha256'=>hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)),
                        'image_source_url'=>$source['photo']['source_url'], 'image_license'=>$source['photo']['license'],
                        'original_sha1'=>$source['photo']['original_sha1'], 'model'=>$this->config['model'],
                        'review'=>'automated-approved', 'modifications'=>'Redimensionnement proportionnel à 1280 px maximum et JPEG qualité 82.'];
                } catch (\Throwable $error) {
                    if ($error instanceof RemoteFailure && $error->paidPost) throw $error;
                    $skipped++; $state['failed'][$titleKey] = gmdate('Y-m-d');
                    // Ne jamais afficher les corps de réponse API ou les exceptions de transport contenant des données distantes.
                    echo 'Candidat écarté (' . $attempts . ') : ' . ($error instanceof \RuntimeException ? $error->getMessage() : 'données invalides.') . "\n";
                }
            }
            if ($articles !== []) {
                $catalog->append($articles, $run);
                atomicJson($this->root . '/generator/state/audit-' . substr(hash('sha256', $run), 0, 20) . '.json', ['schema_version'=>1, 'articles'=>$audit]);
            }
            atomicJson($stateFile, $state);
            $this->budget->finish($run, 'completed');
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
