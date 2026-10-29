<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Echappees\{Budget, Catalog, Generator, Http, Sources, Transport, Writer};
use function Echappees\{atomicJson, config, ensure, https, jsonFile};

$sourceRoot = dirname(__DIR__, 2);
$passed = 0; $failed = 0; $directories = [];
function test(string $name, callable $callback): void {
    global $passed, $failed;
    try { $callback(); $passed++; echo "OK $name\n"; }
    catch (Throwable $e) { $failed++; echo "ÉCHEC $name : " . $e->getMessage() . "\n"; }
}
function rejects(callable $callback): void {
    try { $callback(); } catch (Throwable) { return; }
    throw new RuntimeException('Le scénario aurait dû être refusé.');
}
function fixture(): string {
    global $sourceRoot, $directories;
    $root = sys_get_temp_dir() . '/echappees-php-test-' . bin2hex(random_bytes(8)); $directories[] = $root;
    mkdir($root . '/data/batches', 0755, true); mkdir($root . '/generator/state', 0755, true);
    atomicJson($root . '/data/index.json', ['schema_version'=>1, 'version'=>'fixture', 'batches'=>[
        ['id'=>'demo-a', 'version'=>'2', 'url'=>'batches/demo-a.v2.json', 'count'=>2],
        ['id'=>'demo-b', 'version'=>'2', 'url'=>'batches/demo-b.v2.json', 'count'=>2],
        ['id'=>'demo-c', 'version'=>'2', 'url'=>'batches/demo-c.v2.json', 'count'=>1],
    ]]);
    foreach (glob($sourceRoot . '/data/batches/*.json') as $file) copy($file, $root . '/data/batches/' . basename($file));
    copy($sourceRoot . '/generator/config.json', $root . '/generator/config.json');
    $fixtureConfig = jsonFile($root . '/generator/config.json');
    unset($fixtureConfig['editorial_mix'], $fixtureConfig['test_batch']);
    atomicJson($root . '/generator/config.json', $fixtureConfig);
    atomicJson($root . '/generator/topics.json', [['wiki_title'=>'Sujet de test', 'kind'=>'evergreen', 'category'=>'Voyages et paysages', 'country'=>'Monde']]);
    atomicJson($root . '/generator/recent.json', []);
    return $root;
}
function removeTree(string $dir): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}
function photoInfo(): array {
    return ['mediatype'=>'BITMAP', 'mime'=>'image/jpeg', 'thumburl'=>'https://upload.wikimedia.org/fixture.jpg',
        'descriptionurl'=>'https://commons.wikimedia.org/wiki/File:Fixture.jpg', 'sha1'=>str_repeat('a', 40),
        'extmetadata'=>['LicenseShortName'=>['value'=>'CC BY-SA 4.0'], 'LicenseUrl'=>['value'=>'https://creativecommons.org/licenses/by-sa/4.0/'],
            'Artist'=>['value'=>'<a href="https://example.invalid">Auteur de fixture</a>'], 'Credit'=>['value'=>'Travail personnel'],
            'ImageDescription'=>['value'=>'Photographie documentaire des façades de Porto.'], 'Categories'=>['value'=>'Photographs of Porto']]];
}
final class FakeHttp implements Transport {
    public int $calls = 0;
    public bool $approve = true;
    public bool $badDraft = false;
    public int $badDrafts = 0;
    public bool $timeout = false;
    public bool $recent = false;
    public array $payloads = [];
    public array $gets = [];
    public array $searchPages = [];
    public bool $distinct = false;
    public bool $discoveryUnavailable = false;
    public bool $wikiUnavailable = false;
    public bool $badEvidence = false;
    public string $evidenceFault = '';
    public bool $noaaIndex = false;
    public bool $dublinDate = false;
    public bool $noPositiveChange = false;
    public function request(string $url, ?array $payload = null, ?string $token = null, int $maxBytes = 4194304): array {
        global $sourceRoot;
        if ($payload !== null) {
            $this->calls++; $this->payloads[] = $payload;
            ensure($url === 'https://api.openai.com/v1/responses' && $token === 'fixture-only-not-a-secret', 'Mauvaise destination des appels.');
            if ($this->timeout) throw new Echappees\RemoteFailure(true, 0);
            if ($payload['text']['format']['name'] === 'echappees_article') {
                $article = jsonFile($sourceRoot . '/data/batches/demo-a.v2.json')['articles'][1];
                $value = array_intersect_key($article, array_flip(['title', 'body', 'category', 'location', 'image_description', 'image_caption']));
                $value['body'] = json_decode($payload['input'], true)['document']['wiki_title'] . '. ' . $value['body'];
                $value['title'] = 'Une découverte documentaire de test';
                if ($this->distinct) $value['title'] = 'Article sur ' . json_decode($payload['input'], true)['document']['wiki_title'];
                $value['positive_change'] = $this->noPositiveChange ? '' : 'Résultat de fixture'; $value['beneficiaries'] = 'Bénéficiaires de fixture';
                $value['category'] = 'Voyages et paysages'; $value['suitable'] = true;
                if ($this->recent) {
                    $value['body'] .= ' Ce texte de test cite un communiqué publié le ' . gmdate('Y-m-d') . '.';
                    $value['image_caption'] = 'Illustration documentaire générale de Porto.';
                }
                if ($this->badDraft || $this->badDrafts-- > 0) $value['body'] = 'Texte trop court.';
            } else {
                $value = array_fill_keys(['approved', 'supported_facts', 'french', 'positive', 'photo_honest', 'not_duplicate'], $this->approve);
                $value['reason'] = 'Fixture uniquement.';
                $input = json_decode($payload['input'], true);
                $value['evidence'] = array_map(fn($index)=>['sentence_index'=>$index, 'passage_ids'=>[$this->badEvidence ? 999999 : 0]],
                    array_keys($input['draft_sentences']));
                if ($this->evidenceFault === 'missing') array_pop($value['evidence']);
                if ($this->evidenceFault === 'duplicate') $value['evidence'][] = $value['evidence'][0];
                if ($this->evidenceFault === 'empty') $value['evidence'][0]['passage_ids'] = [];
            }
            return ['body'=>json_encode(['status'=>'completed', 'usage'=>['input_tokens'=>1000, 'output_tokens'=>300],
                'output'=>[['content'=>[['type'=>'output_text', 'text'=>json_encode($value, JSON_THROW_ON_ERROR)]]]]], JSON_THROW_ON_ERROR), 'type'=>'application/json'];
        }
        $this->gets[] = $url;
        if (str_starts_with($url, 'https://oceanservice.noaa.gov/')) return ['body'=>$this->noaaIndex ? '<html><body><a href="/facts/biolum.html">What is bioluminescence?</a><a href="https://evil.invalid/facts/evil.html">Untrusted external topic</a><a href="/facts/oceanfacts-science.html">Index science</a></body></html>' : '<html><body></body></html>', 'type'=>'text/html'];
        if ($url === 'https://www.nasa.gov/fixture' || $url === 'https://www.inrae.fr/fixture') return ['body'=>'<html><head><meta ' . ($this->dublinDate ? 'name="DC.Date"' : 'property="article:published_time"') . ' content="' . gmdate('Y-m-d') . 'T00:00:00Z"></head><body><article>' . jsonFile($sourceRoot . '/data/batches/demo-a.v2.json')['articles'][1]['body'] . '</article></body></html>', 'type'=>'text/html'];
        if ($url === 'https://www.nasa.gov/news-release/feed/') return ['body'=>'<rss><channel/></rss>', 'type'=>'application/rss+xml'];
        if (str_starts_with($url, 'https://upload.wikimedia.org/')) return ['body'=>file_get_contents($sourceRoot . '/data/images/porto.jpg'), 'type'=>'image/jpeg'];
        $host = parse_url($url, PHP_URL_HOST);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if ($this->wikiUnavailable && $host === 'fr.wikipedia.org') throw new Echappees\RemoteFailure(false, 503);
        if (($query['list'] ?? '') === 'search' || $host === 'whc.unesco.org') {
            if ($this->discoveryUnavailable) throw new Echappees\RemoteFailure(false, 503);
            if ($host === 'whc.unesco.org') return ['body'=>'<html><body>Aucun candidat dans cet index de fixture.</body></html>', 'type'=>'text/html'];
            return ['body'=>json_encode(['query'=>['search'=>array_shift($this->searchPages) ?? []]]), 'type'=>'application/json'];
        }
        $value = $host === 'commons.wikimedia.org'
            ? ['query'=>['pages'=>[['imageinfo'=>[photoInfo()]]]]]
            : ['query'=>['pages'=>[['pageid'=>123456, 'pageprops'=>['wikibase_item'=>$this->distinct ? 'Q' . sprintf('%u', crc32($query['titles'] ?? 'Fixture')) : 'Q123456'], 'title'=>$this->distinct ? ($query['titles'] ?? 'Sujet de test') : 'Sujet de test',
                'extract'=>jsonFile($sourceRoot . '/data/batches/demo-a.v2.json')['articles'][1]['body'],
                'fullurl'=>'https://fr.wikipedia.org/wiki/Fixture', 'pageimage'=>'Fixture.jpg']]]];
        return ['body'=>json_encode($value, JSON_THROW_ON_ERROR), 'type'=>'application/json'];
    }
}
function prepared(): array {
    $root = fixture(); $c = config($root); $c['max_per_run'] = 1;
    $budget = new Budget($root . '/generator/state/budget.json', $c);
    $budget->reserve('100', 1, 'daily');
    return [$root, $c, $budget, new FakeHttp()];
}

try {
    test('Le catalogue publié respecte le contrat', function () use ($sourceRoot): void { ensure(count((new Catalog($sourceRoot))->articles) >= 5, 'Catalogue incomplet.'); });
    test('Les URL non HTTPS, à identifiants ou hôtes inattendus sont refusées', function (): void {
        foreach (['http://fr.wikipedia.org/x', 'https://user:pass@fr.wikipedia.org/x', 'https://127.0.0.1/x', 'https://fr.wikipedia.org:444/x', 'https://evil.invalid/x'] as $url) rejects(fn()=>https($url, ['fr.wikipedia.org']));
    });
    test('Le transport refuse la redirection du secret avant tout réseau', function (): void { rejects(fn()=>(new Http('test'))->request('https://commons.wikimedia.org/x', [], 'fixture')); });
    test('Les chemins de lot hors catalogue sont refusés', function (): void {
        $root = fixture(); $index = jsonFile($root . '/data/index.json'); $index['batches'][0]['url'] = '../secret.json'; atomicJson($root . '/data/index.json', $index);
        rejects(fn()=>new Catalog($root));
    });
    test('Un texte trop court et des crédits absents sont refusés', function () use ($sourceRoot): void {
        $article = jsonFile($sourceRoot . '/data/batches/demo-a.v2.json')['articles'][0];
        $bad = $article; $bad['body'] = 'Trop court.'; rejects(fn()=>Catalog::validateArticle($bad));
        $bad = $article; $bad['image_credit'] = ''; rejects(fn()=>Catalog::validateArticle($bad));
    });
    test('Une licence libre garde auteur, lien et mention de modification', function (): void {
        $photo = Sources::photo(photoInfo()); ensure(str_contains($photo['credit'], 'Auteur de fixture') && str_contains($photo['credit'], 'redimensionnée') && str_contains($photo['license'], 'by-sa/4.0'), 'Attribution incomplète.');
    });
    test('Les licences incertaines, illustrations et descriptions sans auteur sont refusées', function (): void {
        foreach (['license', 'artist', 'drawing', 'restrictions'] as $case) {
            $info = photoInfo();
            if ($case === 'license') $info['extmetadata']['LicenseShortName']['value'] = 'All rights reserved';
            if ($case === 'artist') $info['extmetadata']['Artist']['value'] = '';
            if ($case === 'drawing') $info['extmetadata']['ImageDescription']['value'] = 'AI generated painting';
            if ($case === 'restrictions') $info['extmetadata']['Restrictions']['value'] = 'Restrictions';
            rejects(fn()=>Sources::photo($info));
        }
    });
    test('Une date d’actualité périmée est refusée avant de lire le communiqué', function (): void {
        $c = config(fixture()); $sources = new Sources(new FakeHttp(), $c);
        rejects(fn()=>$sources->collect(['wiki_title'=>'Fixture', 'kind'=>'recent', 'source_published_at'=>'2000-01-01', 'source_url'=>'https://www.nasa.gov/fixture']));
    });
    test('Le budget est persisté et une même exécution ne réserve pas deux fois', function (): void {
        [$root, $c, $budget] = prepared(); ensure($budget->reserve('100', 1, 'daily') === false, 'Réservation doublée.');
        ensure((new Budget($root . '/generator/state/budget.json', $c))->entry('100')['status'] === 'reserved', 'Réservation perdue.');
    });
    test('Les plafonds par exécution et mensuel empêchent les appels', function (): void {
        $root = fixture(); $c = config($root); $c['run_budget_usd'] = 0.001;
        rejects(fn()=>(new Budget($root . '/generator/state/a.json', $c))->reserve('1', 1, 'daily'));
        $c['run_budget_usd'] = 1; $c['monthly_budget_usd'] = 0.1;
        $b = new Budget($root . '/generator/state/b.json', $c); $b->reserve('1', 1, 'daily');
        rejects(fn()=>$b->reserve('2', 1, 'daily'));
    });
    test('Un appel incertain n’est pas relancé et reste facturé au maximum', function (): void {
        [$root, $c, $b, $http] = prepared(); $b->start('100'); $http->timeout = true;
        $writer = new Writer($http, $b, $c, '100', 'fixture-only-not-a-secret');
        $source = (new Sources($http, $c))->collect(['wiki_title'=>'Fixture']);
        rejects(fn()=>$writer->write($source, [])); ensure($http->calls === 1, 'POST rejoué.');
        $b->finish('100', 'failed'); ensure($b->entry('100')['reserved_usd'] >= $b->worstCall(), 'Coût incertain libéré.');
    });
    test('Une panne API arrête le passage après un seul POST sans publier de lot', function (): void {
        [$root, $c, $b, $http] = prepared(); $http->timeout = true;
        putenv('GITHUB_ACTIONS=true'); putenv('GENERATION_ENABLED=true');
        $before = file_get_contents($root . '/data/index.json');
        rejects(fn()=>(new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret'));
        ensure($http->calls === 1 && file_get_contents($root . '/data/index.json') === $before, 'Panne API rejouée ou lot publié.');
        ensure($b->entry('100')['status'] === 'failed', 'Échec absent du registre.');
    });
    test('Les compteurs réels libèrent uniquement les appels non effectués', function (): void {
        [$root, $c, $b] = prepared(); $b->start('100'); $b->beforeCall('100'); $b->account('100', ['input_tokens'=>1000, 'output_tokens'=>300]); $b->finish('100', 'completed');
        ensure(abs($b->entry('100')['reserved_usd'] - 0.00088) < 0.000002, 'Coût réel incorrect.');
        rejects(fn()=>$b->start('100'));
    });
    test('Un quota d’appels épuisé bloque les appels suivants', function (): void {
        [$root, $c, $b] = prepared(); $b->start('100');
        for ($i = 0; $i < $b->entry('100')['max_calls']; $i++) $b->beforeCall('100');
        rejects(fn()=>$b->beforeCall('100'));
    });
    test('Un changement de configuration après réservation bloque la dépense', function (): void {
        [$root, $c, $b] = prepared(); $c['model'] = 'other-model';
        rejects(fn()=>(new Budget($root . '/generator/state/budget.json', $c))->start('100'));
    });
    test('Une publication dupliquée ne remplace pas l’index valide', function (): void {
        $root = fixture(); $catalog = new Catalog($root); $before = file_get_contents($root . '/data/index.json');
        $a = array_values($catalog->articles)[0]; $a['subject_id'] = 'Q123';
        rejects(fn()=>$catalog->append([$a], 'fixture'));
        ensure(file_get_contents($root . '/data/index.json') === $before, 'Index écrasé.');
    });
    test('Le rythme quotidien démarre directement, même avec moins de 50 articles', function (): void {
        $root = fixture(); $c = config($root);
        $b = new Budget($root . '/generator/state/budget.json', $c);
        ensure((new Generator($root, $c, new FakeHttp(), $b))->count() === 10, 'Rythme quotidien non appliqué.');
        $c['max_catalog_articles'] = 7;
        ensure((new Generator($root, $c, new FakeHttp(), $b))->count() === 2, 'Capacité restante ignorée.');
        $c['max_catalog_articles'] = 5;
        ensure((new Generator($root, $c, new FakeHttp(), $b))->count() === 0, 'Plafond total ignoré.');
    });
    test('Deux passages le même jour partagent le quota de dix publications', function (): void {
        $root = fixture(); $c = config($root);
        $b = new Budget($root . '/generator/state/budget.json', $c);
        $b->reserve('first-run', 3, 'daily');
        ensure((new Generator($root, $c, new FakeHttp(), $b))->count() === 7, 'Quota déjà réservé ignoré.');
        $b->reserve('second-run', 7, 'daily');
        ensure((new Generator($root, $c, new FakeHttp(), $b))->count() === 0, 'Limite quotidienne dépassée.');
    });
    test('L’arrêt et le contexte hors Actions empêchent toute génération réelle', function (): void {
        [$root, $c, $b, $http] = prepared(); $g = new Generator($root, $c, $http, $b);
        putenv('GITHUB_ACTIONS=false'); putenv('GENERATION_ENABLED=true'); rejects(fn()=>$g->run('100', 'fixture-only-not-a-secret'));
        putenv('GITHUB_ACTIONS=true'); putenv('GENERATION_ENABLED=false'); rejects(fn()=>$g->run('100', 'fixture-only-not-a-secret'));
        ensure($http->calls === 0, 'Appel après arrêt.');
    });
    test('Un article non approuvé conserve le catalogue et ne publie rien', function (): void {
        [$root, $c, $b, $http] = prepared(); $http->approve = false;
        putenv('GITHUB_ACTIONS=true'); putenv('GENERATION_ENABLED=true');
        $before = file_get_contents($root . '/data/index.json');
        $report = (new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret');
        ensure($report['published'] === 0 && file_get_contents($root . '/data/index.json') === $before, 'Article refusé publié.');
    });
    test('Le cycle complet publie un lot, une vraie photo décodable et un audit sans secret', function (): void {
        [$root, $c, $b, $http] = prepared(); putenv('GITHUB_ACTIONS=true'); putenv('GENERATION_ENABLED=true');
        $old = jsonFile($root . '/data/index.json');
        $report = (new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret');
        ensure($report['published'] === 1 && $http->calls === 2, 'Cycle incomplet.');
        $catalog = new Catalog($root); ensure(count($catalog->articles) === 6, 'Nouvel article absent.');
        ensure(array_slice($catalog->index['batches'], 1) === $old['batches'], 'Archives modifiées.');
        $article = $catalog->articles['decouverte-' . substr(hash('sha256', 'Q123456'), 0, 24)];
        $path = $root . '/data/' . substr($article['image_url'], strlen($c['base_url']));
        $im = getimagesize($path); ensure($im !== false && max($im[0], $im[1]) <= 1280, 'Photo invalide.');
        ensure($http->payloads[0]['store'] === false && $http->payloads[0]['text']['format']['strict'] === true, 'Sortie non structurée.');
        foreach (glob($root . '/generator/state/*.json') as $file) ensure(!str_contains(file_get_contents($file), 'fixture-only-not-a-secret'), 'Secret enregistré.');
        rejects(fn()=>(new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret'));
        ensure($http->calls === 2, 'Appels rejoués.');
    });
    test('Une actualité conserve la date vérifiée de la source et une photo illustrative', function (): void {
        [$root, $c, $b, $http] = prepared(); $http->recent = true;
        atomicJson($root . '/generator/recent.json', [['wiki_title'=>'Fixture', 'kind'=>'recent',
            'source_url'=>'https://www.nasa.gov/fixture', 'source_published_at'=>gmdate('Y-m-d')]]);
        $report = (new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret');
        ensure($report['published'] === 1, 'Actualité non publiée.');
        $a = (new Catalog($root))->articles['decouverte-' . substr(hash('sha256', 'news-' . substr(hash('sha256', 'https://www.nasa.gov/fixture'), 0, 32)), 0, 24)];
        ensure($a['source_published_at'] === gmdate('Y-m-d') && str_contains($a['body'], gmdate('Y-m-d')) && str_contains($a['image_caption'], 'Illustration'), 'Date/illustration perdue.');
    });
    test('Le diagnostic OpenAI distingue quota et débit sans exposer le corps API', function (): void {
        $quota = new Echappees\RemoteFailure(true, 429, json_encode(['error'=>['code'=>'insufficient_quota', 'message'=>'CONFIDENTIAL_FIXTURE']]), 'api.openai.com');
        ensure(str_contains($quota->getMessage(), 'Crédits API insuffisants') && !str_contains($quota->getMessage(), 'CONFIDENTIAL_FIXTURE'), 'Diagnostic de quota dangereux.');
        $rate = new Echappees\RemoteFailure(true, 429, json_encode(['error'=>['code'=>'rate_limit_exceeded']]), 'api.openai.com');
        ensure(str_contains($rate->getMessage(), 'Limite de débit'), 'Débit non identifié.');
        $unknown = new Echappees\RemoteFailure(true, 429, json_encode(['error'=>['code'=>'CONFIDENTIAL_FIXTURE', 'message'=>'CONFIDENTIAL_FIXTURE']]), 'api.openai.com');
        ensure(!str_contains($unknown->getMessage(), 'CONFIDENTIAL_FIXTURE'), 'Code inconnu affiché.');
        $wiki = new Echappees\RemoteFailure(false, 429, '', 'fr.wikipedia.org');
        ensure(str_contains($wiki->getMessage(), 'Wikipédia') && !str_contains($wiki->getMessage(), 'OpenAI'), 'Service erroné.');
    });
    test('Un échec libère les places quotidiennes sans effacer le coût incertain', function (): void {
        [$root, $c, $b] = prepared(); $b->start('100'); $b->beforeCall('100'); $b->finish('100', 'failed');
        ensure($b->dailyUsed() === 0 && $b->entry('100')['reserved_usd'] >= $b->worstCall(), 'Quota ou coût incorrect après échec.');
        $ledger = jsonFile($root . '/generator/state/budget.json'); unset($ledger['runs']['100']['published_count']);
        atomicJson($root . '/generator/state/budget.json', $ledger);
        ensure((new Budget($root . '/generator/state/budget.json', $c))->dailyUsed() === 0, 'Ancien échec toujours bloquant.');
    });
    test('Seuls les articles publiés comptent après un passage terminé', function (): void {
        $root = fixture(); $c = config($root); $b = new Budget($root . '/generator/state/budget.json', $c);
        $b->reserve('123', 10, 'daily'); $b->start('123'); $b->finish('123', 'completed', 3);
        ensure($b->dailyUsed() === 3, 'Places non publiées toujours occupées.');
        ensure((new Generator($root, $c, new FakeHttp(), $b))->count() === 7, 'Places restantes incorrectes.');
    });
    test('Les vignettes thumb.wikimedia.org sont acceptées sans ouvrir les autres hôtes', function (): void {
        $info = photoInfo(); $info['thumburl'] = 'https://thumb.wikimedia.org/wikipedia/commons/fixture.jpg';
        ensure(Sources::photo($info)['download_url'] === $info['thumburl'], 'Nouveau serveur de vignettes refusé.');
        foreach (['https://thumb.wikimedia.org.evil.invalid/photo.jpg', 'https://evil.invalid/photo.jpg'] as $url) {
            $info['thumburl'] = $url; rejects(fn()=>Sources::photo($info));
        }
    });
    test('Les liens CC historiques sont canonisés sans assouplir les téléchargements', function (): void {
        foreach (['http://creativecommons.org/licenses/by-sa/4.0/', '//creativecommons.org/licenses/by-sa/4.0', 'https://www.creativecommons.org/licenses/by-sa/4.0/deed.fr'] as $url) {
            $info = photoInfo(); $info['extmetadata']['LicenseUrl']['value'] = $url;
            ensure(Sources::photo($info)['license_url'] === 'https://creativecommons.org/licenses/by-sa/4.0/', 'Licence historique refusée.');
        }
        foreach (['http://evil.invalid/licenses/by-sa/4.0/', 'https://creativecommons.org/licenses/by/4.0/', 'https://user@creativecommons.org/licenses/by-sa/4.0/'] as $url) {
            $info = photoInfo(); $info['extmetadata']['LicenseUrl']['value'] = $url; rejects(fn()=>Sources::photo($info));
        }
        rejects(fn()=>https('http://thumb.wikimedia.org/photo.jpg', ['thumb.wikimedia.org']));
    });
    test('Le diagnostic de sources ne rédige aucun article et ne contacte pas OpenAI', function (): void {
        $root = fixture(); $http = new FakeHttp();
        ob_start();
        try { $report = (new Sources($http, config($root)))->probe([['wiki_title'=>'Fixture']]); } finally { ob_end_clean(); }
        ensure($report['accepted'] === 1 && $report['openai_calls'] === 0 && $http->calls === 0, 'Diagnostic payant ou incomplet.');
    });
    test('Le calendrier parisien suit les heures d’été, d’hiver et les transitions', function (): void {
        $c = ['timezone'=>'Europe/Paris'];
        foreach ([
            '2026-07-14T22:59:00Z'=>'2026-07-15',
            '2026-07-14T23:00:00Z'=>'2026-07-15',
            '2026-01-14T23:00:00Z'=>'2026-01-15',
            '2026-03-28T23:30:00Z'=>'2026-03-29',
            '2026-03-29T22:30:00Z'=>'2026-03-30',
            '2026-10-24T23:00:00Z'=>'2026-10-25',
            '2026-10-25T23:00:00Z'=>'2026-10-26',
        ] as $instant=>$expected) {
            ensure(Echappees\quotaDate($c, new DateTimeImmutable($instant)) === $expected, 'Date parisienne incorrecte.');
        }
        foreach (['2026-01-15T00:00:00Z', '2026-07-14T23:00:00Z', '2026-10-24T23:00:00Z', '2026-10-26T00:00:00Z'] as $instant) {
            ensure((new DateTimeImmutable($instant))->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i') === '01:00', 'Horaire été/hiver incorrect.');
        }
    });
    test('Le quota utilise la date locale sans réécrire les anciens budgets UTC', function (): void {
        $root = fixture(); $c = config($root); $file = $root . '/generator/state/budget.json';
        $utcYesterday = gmdate('Y-m-d', time() - 86400);
        atomicJson($file, ['schema_version'=>1, 'runs'=>[
            'new'=>['date'=>$utcYesterday, 'quota_date'=>Echappees\quotaDate($c), 'status'=>'completed', 'count'=>10, 'published_count'=>3],
            'old'=>['date'=>Echappees\quotaDate($c), 'status'=>'completed', 'count'=>2],
        ]]);
        $before = file_get_contents($file);
        ensure((new Budget($file, $c))->dailyUsed() === 5, 'Quota local ou reprise historique incorrect.');
        ensure(file_get_contents($file) === $before, 'Historique réécrit.');
    });
    test('Une seule correction de longueur est tentée sans relâcher le contrôle', function (): void {
        [$root, $c, $b, $http] = prepared(); $http->badDraft = true;
        $report = (new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret');
        ensure($report['published'] === 0 && $http->calls === 2, 'Réparations non bornées ou longueur assouplie.');
    });
    test('Un brouillon corrigé en longueur est encore revu avant publication', function (): void {
        [$root, $c, $b, $http] = prepared(); $http->badDrafts = 1;
        $report = (new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret');
        ensure($report['published'] === 1 && $http->calls === 3, 'Correction ou revue manquante.');
        ensure($http->payloads[2]['text']['format']['name'] === 'echappees_review', 'Revue omise après correction.');
    });
    test('Dix articles sont atteints en renouvelant les candidats et en corrigeant un brouillon', function (): void {
        $root = fixture(); $c = config($root); atomicJson($root . '/generator/topics.json', []);
        $b = new Budget($root . '/generator/state/budget.json', $c); $b->reserve('200', 10, 'daily');
        $http = new FakeHttp(); $http->distinct = true; $http->badDrafts = 1;
        $http->searchPages = [array_map(static fn($i)=>['title'=>'Candidat ' . $i], range(1, 5)),
            array_map(static fn($i)=>['title'=>'Candidat ' . $i], range(6, 10))];
        $report = (new Generator($root, $c, $http, $b))->run('200', 'fixture-only-not-a-secret');
        ensure($report['published'] === 10 && $http->calls === 21 && $b->dailyUsed() === 10, 'Quota incomplet ou correction sans marge.');
        ensure(count((new Catalog($root))->articles) === 15 && $b->entry('200')['charged_usd'] <= 1, 'Archives ou budget non respectés.');
    });
    test('Une panne de recherche ne perd pas les articles déjà validés', function (): void {
        $root = fixture(); $c = config($root); $b = new Budget($root . '/generator/state/budget.json', $c); $b->reserve('300', 2, 'daily');
        $http = new FakeHttp(); $http->discoveryUnavailable = true;
        $report = (new Generator($root, $c, $http, $b))->run('300', 'fixture-only-not-a-secret');
        ensure($report['published'] === 1 && $b->dailyUsed() === 1, 'Article validé perdu sur panne de recherche.');
    });
    test('Un HTTP 503 ne place pas un sujet dans la liste des échecs', function (): void {
        [$root, $c, $b, $http] = prepared(); $http->wikiUnavailable = true; $http->discoveryUnavailable = true;
        $report = (new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret');
        ensure($report['published'] === 0 && $http->calls === 0 && jsonFile($root . '/generator/state/subjects.json')['failed'] === [], 'Panne temporaire mémorisée comme refus éditorial.');
    });
    test('Une source culinaire institutionnelle se collecte sans Wikipédia et conserve ses références', function (): void {
        $root = fixture(); $http = new FakeHttp();
        $source = (new Sources($http, config($root)))->collect(['wiki_title'=>'Astuce vérifiée', 'kind'=>'culinary',
            'source_url'=>'https://www.inrae.fr/fixture', 'source_label'=>'INRAE', 'photo_query'=>'lentils food', 'category'=>'Gastronomie et traditions']);
        ensure($source['kind'] === 'culinary' && $source['sources'][0]['url'] === 'https://www.inrae.fr/fixture', 'Source primaire perdue.');
        ensure(!array_filter($http->gets, static fn($url)=>str_contains($url, 'fr.wikipedia.org')) && $http->calls === 0, 'Dépendance Wikipédia ou appel rédactionnel en collecte.');
        rejects(fn()=>(new Sources($http, config($root)))->collect(['wiki_title'=>'Invalide', 'source_url'=>'https://evil.invalid/recipe']));
    });
    test('Une revue approuvée avec un passage source inexistant ne publie pas l’article', function (): void {
        [$root, $c, $b, $http] = prepared(); $http->badEvidence = true;
        $report = (new Generator($root, $c, $http, $b))->run('100', 'fixture-only-not-a-secret');
        ensure($report['published'] === 0, 'Un passage source inexistant a été accepté.');
    });
    test('Une phrase oubliée, dupliquée ou sans passage bloque la revue', function (): void {
        foreach (['missing', 'duplicate', 'empty'] as $fault) {
            [$root, $c, $b, $http] = prepared(); $b->start('100'); $http->evidenceFault = $fault;
            $source = (new Sources($http, $c))->collect(['wiki_title'=>'Fixture']);
            rejects(fn()=>(new Writer($http, $b, $c, '100', 'fixture-only-not-a-secret'))->write($source, []));
        }
    });
    test('Chaque justificatif conservé provient exactement du document fourni', function (): void {
        [$root, $c, $b, $http] = prepared(); $b->start('100');
        $source = (new Sources($http, $c))->collect(['wiki_title'=>'Fixture']);
        $writer = new Writer($http, $b, $c, '100', 'fixture-only-not-a-secret');
        $draft = $writer->write($source, []);
        ensure(count($writer->factChecks()) > 0, 'Audit vide.');
        foreach ($writer->factChecks() as $check) {
            ensure(str_contains($draft['body'], $check['sentence']), 'Phrase altérée.');
            foreach ($check['source_passages'] as $passage) ensure(str_contains(Echappees\plain($source['excerpt']), $passage), 'Passage inventé.');
        }
    });
    test('Le réservoir NOAA fournit des sources primaires sans lien externe ni page index', function (): void {
        $root = fixture(); $http = new FakeHttp(); $http->noaaIndex = true;
        $topics = (new Sources($http, config($root)))->discover(0);
        ensure(count($topics) === 1 && $topics[0]['source_url'] === 'https://oceanservice.noaa.gov/facts/biolum.html', 'Découverte NOAA incorrecte.');
    });

    test('RSS, RDF et Atom respectent dates, hôtes autorisés et déduplication', function (): void {
        $feed = ['label'=>'Fixture', 'article_hosts'=>['www.nasa.gov'], 'category'=>'Sciences'];
        $now = strtotime('2026-10-29T12:00:00Z');
        $rss = '<rss><channel><item><title>Nouvelle découverte</title><link>https://www.nasa.gov/test</link><pubDate>2026-10-28</pubDate></item><item><title>Doublon de découverte</title><link>https://www.nasa.gov/test</link><pubDate>2026-10-28</pubDate></item><item><title>Ancienne découverte</title><link>https://www.nasa.gov/old</link><pubDate>2020-01-01</pubDate></item><item><title>Source non autorisée</title><link>https://evil.invalid/test</link><pubDate>2026-10-28</pubDate></item><item><title>Date future</title><link>https://www.nasa.gov/future</link><pubDate>2027-01-01</pubDate></item></channel></rss>';
        ensure(count(Echappees\Feeds::parse($rss, $feed, 30, $now)) === 1, 'Filtrage RSS incorrect.');
        $atom = '<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Découverte Atom</title><link rel="self" href="https://evil.invalid/self"/><link rel="alternate" href="https://www.nasa.gov/atom"/><published>2026-10-28T10:00:00Z</published><updated>2026-10-29T11:00:00Z</updated></entry></feed>';
        $items = Echappees\Feeds::parse($atom, $feed, 30, $now);
        ensure(count($items) === 1 && $items[0]['source_published_at'] === '2026-10-28' && $items[0]['source_url'] === 'https://www.nasa.gov/atom', 'Atom incorrect.');
        $rdf = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:dc="http://purl.org/dc/elements/1.1/"><item><title>Découverte RDF</title><link>https://www.nasa.gov/rdf</link><dc:date>2026-10-28</dc:date></item></rdf:RDF>';
        ensure(count(Echappees\Feeds::parse($rdf, $feed, 30, $now)) === 1, 'Date RDF perdue.');
        rejects(fn()=>Echappees\Feeds::parse('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss/>', $feed, 30, $now));
        rejects(fn()=>Echappees\Feeds::parse('<html>Erreur du serveur</html>', $feed, 30, $now));
        ensure(Echappees\Feeds::parse(str_replace(['<published>', '</published>'], ['<updated>', '</updated>'], $atom), $feed, 30, $now) === [], 'Une mise à jour a été prise pour une publication.');
    });
    test('Les sous-domaines CNRS partagent un fournisseur et la sélection répartit les sujets', function (): void {
        ensure(Echappees\Feeds::provider('https://lejournal.cnrs.fr/test') === Echappees\Feeds::provider('https://www.cnrs.fr/test'), 'CNRS compté deux fois.');
        $topics = [['source_url'=>'https://oceanservice.noaa.gov/a', 'category'=>'Sciences'], ['source_url'=>'https://www.esa.int/a', 'category'=>'Sciences']];
        ensure(Echappees\Feeds::next($topics, ['oceanservice.noaa.gov'=>3], []) === 1, 'Le fournisseur déjà dominant est prioritaire.');
    });

    test('Un flux refusé ne bloque pas le suivant et les résumés ne sont pas des preuves', function (): void {
        $http = new class implements Transport {
            public function request(string $url, ?array $payload = null, ?string $token = null, int $maxBytes = 4194304): array {
                if (str_contains($url, 'bad')) throw new Echappees\RemoteFailure(false, 403, '', 'www.nasa.gov');
                return ['body'=>'<rss><channel><item><title>Découverte vérifiable</title><link>https://www.nasa.gov/article</link><pubDate>' . gmdate(DATE_RSS) . '</pubDate><description>Résumé insuffisant.</description></item></channel></rss>', 'type'=>'application/rss+xml'];
            }
        };
        $base = ['label'=>'Fixture', 'article_hosts'=>['www.nasa.gov'], 'category'=>'Sciences'];
        $topics = (new Echappees\Feeds($http, config(fixture())))->collect([
            [...$base, 'url'=>'https://www.nasa.gov/bad'], [...$base, 'url'=>'https://www.nasa.gov/good'],
        ]);
        ensure(count($topics) === 1 && !isset($topics[0]['excerpt']) && !isset($topics[0]['body']), 'Le flux indisponible bloque la collecte ou le résumé remplace le document.');
    });

    test('La date Dublin Core d’un article RSS est confirmée sur sa page complète', function (): void {
        $http = new FakeHttp(); $http->dublinDate = true;
        $source = (new Sources($http, config(fixture())))->collect(['wiki_title'=>'Actualité scientifique',
            'source_url'=>'https://www.nasa.gov/fixture', 'kind'=>'recent', 'source_published_at'=>gmdate('Y-m-d')]);
        ensure($source['source_published_at'] === gmdate('Y-m-d'), 'Date non confirmée.');
    });

    test('Sept nouvelles, deux découvertes et une astuce : aucun remplissage encyclopédique', function (): void {
        $limits = ['recent'=>7, 'evergreen'=>2, 'culinary'=>1];
        $topics = [['kind'=>'recent'], ['kind'=>'evergreen'], ['kind'=>'culinary']];
        ensure(array_keys(Echappees\Editorial::eligible($topics, [], $limits)) === [0], 'Les nouvelles ne sont pas prioritaires.');
        ensure(array_keys(Echappees\Editorial::eligible($topics, ['recent'=>7], $limits)) === [1,2], 'Les compléments ne sont pas disponibles.');
        ensure(Echappees\Editorial::eligible([['kind'=>'evergreen'], ['kind'=>'culinary']], ['evergreen'=>2, 'culinary'=>1], $limits) === [], 'Le manque de nouvelles est compensé par des sujets intemporels.');
    });
    test('Le lot exceptionnel est plafonné, reprend le reliquat et ne modifie pas le quota quotidien', function (): void {
        $root = fixture(); $c = config($root);
        $c['test_batch'] = ['id'=>'essai', 'date'=>Echappees\quotaDate($c), 'count'=>10];
        $b = new Budget($root . '/generator/state/budget.json', $c);
        $b->reserve('1', 10, 'daily'); $b->start('1'); $b->finish('1', 'completed', 10);
        $generator = new Generator($root, $c, new FakeHttp(), $b);
        ensure($generator->count() === 10 && $generator->mode() === 'test:essai', 'Essai bloqué par le quota quotidien.');
        $b->reserve('2', 10, 'test:essai'); $b->start('2'); $b->finish('2', 'completed', 6);
        ensure($generator->count() === 4 && $b->dailyUsed() === 10, 'Reliquat ou quota quotidien incorrect.');
        $b->reserve('3', 4, 'test:essai'); $b->start('3'); $b->finish('3', 'completed', 4);
        ensure($generator->count() === 0 && $generator->mode() === 'daily', 'Lot exceptionnel rejoué.');
    });
    test('Une actualité sans amélioration concrète est refusée avant la revue', function (): void {
        [$root, $c, $b, $http] = prepared(); $b->start('100'); $http->recent = true; $http->noPositiveChange = true;
        $source = (new Sources($http, $c))->collect(['wiki_title'=>'Actualité', 'source_url'=>'https://www.nasa.gov/fixture',
            'kind'=>'recent', 'source_published_at'=>gmdate('Y-m-d')]);
        rejects(fn()=>(new Writer($http, $b, $c, '100', 'fixture-only-not-a-secret'))->write($source, []));
        ensure($http->calls === 1, 'Une actualité sans résultat a atteint la revue.');
    });

} finally {
    putenv('GITHUB_ACTIONS'); putenv('GENERATION_ENABLED');
    foreach ($directories as $dir) if (is_dir($dir)) removeTree($dir);
}
echo "$passed tests réussis, $failed échecs. Aucun appel réseau ni OpenAI réel.\n";
exit($failed === 0 ? 0 : 1);
