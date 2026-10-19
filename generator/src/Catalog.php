<?php
declare(strict_types=1);
namespace Echappees;

final class Catalog {
    public array $index;
    public array $articles = [];
    public array $titles = [];
    public array $subjects = [];
    public function __construct(public readonly string $root) {
        $this->index = jsonFile($root . '/data/index.json');
        ensure(($this->index['schema_version'] ?? null) === 1, 'Schéma index inconnu.');
        text($this->index, 'version', 100);
        ensure(is_array($this->index['batches'] ?? null) && count($this->index['batches']) <= 2000, 'Liste de lots invalide.');
        $ids = []; $urls = [];
        foreach ($this->index['batches'] as $desc) {
            $id = text($desc, 'id', 100); $version = text($desc, 'version', 100);
            ensure(preg_match('~^batches/[a-z0-9][a-z0-9._-]*\.json$~D', $desc['url'] ?? '') === 1, 'Chemin de lot invalide.');
            ensure(!isset($ids[$id]) && !isset($urls[$desc['url']]), 'Lot dupliqué.');
            $ids[$id] = true; $urls[$desc['url']] = true;
            $batch = jsonFile($root . '/data/' . $desc['url']);
            ensure(($batch['schema_version'] ?? null) === 1 && ($batch['id'] ?? null) === $id && ($batch['version'] ?? null) === $version,
                'Identité de lot incohérente.');
            ensure(is_int($desc['count'] ?? null) && $desc['count'] >= 1 && $desc['count'] <= 200
                && is_array($batch['articles'] ?? null) && count($batch['articles']) === $desc['count'], 'Lot incomplet.');
            foreach ($batch['articles'] as $article) {
                self::validateArticle($article);
                $title = fingerprint($article['title']);
                ensure(!isset($this->articles[$article['id']]) && !isset($this->titles[$title]), 'Publication dupliquée.');
                if (isset($article['subject_id'])) {
                    ensure(!isset($this->subjects[$article['subject_id']]), 'Sujet dupliqué.');
                    $this->subjects[$article['subject_id']] = true;
                }
                $this->articles[$article['id']] = $article; $this->titles[$title] = true;
            }
        }
    }
    public static function validateArticle(array $a): void {
        ensure(preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $a['id'] ?? '') === 1, 'Identifiant invalide.');
        foreach (['title'=>180, 'category'=>100, 'location'=>160, 'body'=>6000,
            'image_description'=>500, 'image_caption'=>500, 'image_credit'=>500, 'image_license'=>500] as $key=>$max) text($a, $key, $max);
        $words = preg_split('/\s+/u', trim($a['body']), -1, PREG_SPLIT_NO_EMPTY);
        ensure(count($words) >= 100 && count($words) <= 150, 'Texte hors de la plage 100–150 mots.');
        https(text($a, 'image_url', 2000)); https(text($a, 'image_source_url', 2000));
        ensure(is_array($a['sources'] ?? null) && count($a['sources']) >= 1 && count($a['sources']) <= 20, 'Sources absentes.');
        foreach ($a['sources'] as $source) { text($source, 'label', 200); https(text($source, 'url', 2000)); }
        if (isset($a['subject_id'])) text($a, 'subject_id', 100);
        if (isset($a['country'])) text($a, 'country', 160);
        foreach (['published_at', 'source_published_at', 'event_date'] as $key) {
            if (isset($a[$key])) ensure(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $a[$key]) === 1
                && date('Y-m-d', strtotime($a[$key])) === $a[$key] && $a[$key] <= gmdate('Y-m-d'), 'Date invalide.');
        }
    }
    public function append(array $articles, string $run): void {
        ensure(count($articles) >= 1 && count($articles) <= 50, 'Taille du nouveau lot invalide.');
        $allIds = array_fill_keys(array_keys($this->articles), true); $titles = $this->titles; $subjects = $this->subjects;
        foreach ($articles as $a) {
            self::validateArticle($a);
            $key = fingerprint($a['title']);
            ensure(!isset($allIds[$a['id']]) && !isset($titles[$key]) && !isset($subjects[$a['subject_id']]), 'Doublon avant publication.');
            $allIds[$a['id']] = true; $titles[$key] = true; $subjects[$a['subject_id']] = true;
        }
        $id = 'generated-' . substr(hash('sha256', $run), 0, 20);
        $relative = 'batches/' . $id . '.v1.json';
        ensure(!is_file($this->root . '/data/' . $relative), 'Lot de cette exécution déjà publié.');
        $batch = ['schema_version'=>1, 'id'=>$id, 'version'=>'1', 'articles'=>array_values($articles)];
        // Le fichier immuable est complet avant de remplacer l'index.
        atomicJson($this->root . '/data/' . $relative, $batch);
        $index = $this->index;
        array_unshift($index['batches'], ['id'=>$id, 'version'=>'1', 'url'=>$relative, 'count'=>count($articles)]);
        $index['version'] = gmdate('Y-m-d') . '.' . substr(hash('sha256', $run), 0, 16);
        atomicJson($this->root . '/data/index.json', $index);
        new self($this->root);
    }
}
