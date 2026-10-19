#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Echappees\{Budget, Catalog, Generator, Http, Sources};
use function Echappees\{config, ensure};

$root = dirname(__DIR__);
$lock = null;
try {
    ensure(PHP_VERSION_ID >= 80400, 'PHP 8.4 ou supérieur requis.');
    foreach (['curl', 'mbstring', 'dom', 'SimpleXML', 'gd'] as $extension) ensure(extension_loaded($extension), 'Extension PHP manquante : ' . $extension);
    $command = $argv[1] ?? 'plan';
    ensure(in_array($command, ['validate', 'plan', 'probe', 'reserve', 'generate'], true), 'Commande attendue : validate, plan, probe, reserve ou generate.');
    $config = config($root); $catalog = new Catalog($root);
    if ($command === 'validate') {
        echo count($catalog->index['batches']) . ' lots, ' . count($catalog->articles) . " publications valides.\n"; exit(0);
    }
    if ($command === 'probe') {
        $report = (new Sources(new Http($config['contact']), $config))->probe(Echappees\jsonFile($root . '/generator/topics.json'));
        echo json_encode($report, JSON_THROW_ON_ERROR) . "\n";
        echo '::notice title=Diagnostic de collecte::' . $report['accepted'] . ' sources acceptées, ' . $report['rejected'] . ' refusées ; aucun appel OpenAI.' . "\n";
        exit(0);
    }
    if (!is_dir($root . '/generator/state')) mkdir($root . '/generator/state', 0755, true);
    $lock = fopen($root . '/generator/state/generation.lock', 'c');
    ensure($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Une exécution est déjà active.');
    $budget = new Budget($root . '/generator/state/budget.json', $config);
    $generator = new Generator($root, $config, new Http($config['contact']), $budget);
    $count = $generator->count();
    if ($command === 'plan') {
        echo json_encode(['mode'=>'daily', 'current'=>count($catalog->articles), 'daily_count'=>$config['daily_count'],
            'next_maximum'=>$count, 'worst_run_usd'=>round($count * 2 * $budget->worstCall(), 6),
            'monthly_budget_usd'=>$config['monthly_budget_usd'], 'enabled'=>$config['enabled'] && getenv('GENERATION_ENABLED') === 'true'], JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    ensure(getenv('GITHUB_ACTIONS') === 'true', 'La génération réelle est réservée à GitHub Actions.');
    ensure($config['enabled'] && getenv('GENERATION_ENABLED') === 'true', 'Génération désactivée.');
    $run = getenv('GITHUB_RUN_ID') ?: '';
    ensure(preg_match('/^[0-9]{1,30}$/D', $run) === 1, 'Identifiant GitHub Actions obligatoire.');
    if ($command === 'reserve') {
        if ($count === 0) { echo "Plafond atteint : aucun appel nécessaire.\n"; exit(0); }
        echo $budget->reserve($run, $count, 'daily') ? "Budget réservé ; committer ce registre avant les appels.\n" : "Exécution déjà réservée : reprise sans nouvelle réservation.\n";
    } else {
        if ($count === 0 && !is_file($root . '/generator/state/budget.json')) { echo "Aucune génération à effectuer.\n"; exit(0); }
        try { $entry = $budget->entry($run); } catch (Throwable) { echo "Aucune réservation pour cette exécution : aucun appel.\n"; exit(0); }
        if ($entry['status'] !== 'reserved') { echo "Exécution déjà traitée : aucun appel rejoué.\n"; exit(0); }
        $report = $generator->run($run, getenv('OPENAI_API_KEY') ?: '');
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
} catch (Throwable $error) {
    // Messages locaux uniquement ; les réponses HTTP et secrets ne sont pas journalisés.
    fwrite(STDERR, "Opération interrompue : " . ($error instanceof RuntimeException ? $error->getMessage() : 'données invalides ou erreur interne.') . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
