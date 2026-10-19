<?php
declare(strict_types=1);
namespace Echappees;

final class Budget {
    private array $ledger;
    public function __construct(private readonly string $file, private readonly array $config) {
        $this->ledger = is_file($file) ? jsonFile($file) : ['schema_version'=>1, 'runs'=>[]];
        ensure(($this->ledger['schema_version'] ?? null) === 1 && is_array($this->ledger['runs'] ?? null), 'Registre de budget invalide.');
    }
    public function worstCall(): float {
        // Un token UTF-8 ne représente pas moins d'un octet ; marge pour le formatage côté API.
        return (($this->config['max_input_bytes'] + 4096) * $this->config['input_usd_per_million']
            + $this->config['max_output_tokens'] * $this->config['output_usd_per_million']) / 1000000;
    }
    public function reserve(string $run, int $count, string $mode): bool {
        if (isset($this->ledger['runs'][$run])) return false;
        $month = gmdate('Y-m'); $spent = 0.0;
        foreach ($this->ledger['runs'] as $item) {
            ensure(is_numeric($item['reserved_usd'] ?? null) && $item['reserved_usd'] >= 0, 'Réservation invalide.');
            if ($item['month'] === $month) $spent += $item['reserved_usd'];
        }
        $calls = min($count * 2, $this->config['max_api_calls']);
        $reserved = ceil($calls * $this->worstCall() * 1000000) / 1000000;
        ensure($count > 0 && $calls >= 2 && $reserved <= $this->config['run_budget_usd'] && $spent + $reserved <= $this->config['monthly_budget_usd'], 'Plafond de budget atteint.');
        $this->ledger['runs'][$run] = ['month'=>$month, 'date'=>gmdate('Y-m-d'), 'mode'=>$mode, 'status'=>'reserved',
            'count'=>$count, 'max_calls'=>$calls, 'calls'=>0, 'charged_usd'=>0.0, 'reserved_usd'=>$reserved,
            'input_tokens'=>0, 'output_tokens'=>0, 'config_sha256'=>hash('sha256', json_encode($this->config, JSON_THROW_ON_ERROR))];
        $this->save(); return true;
    }
    public function entry(string $run): array { ensure(isset($this->ledger['runs'][$run]), 'Réservation préalable obligatoire.'); return $this->ledger['runs'][$run]; }
    public function start(string $run): void {
        $e = $this->entry($run);
        ensure($e['status'] === 'reserved', 'Exécution déjà commencée ou terminée ; ne pas rejouer les appels.');
        ensure($e['config_sha256'] === hash('sha256', json_encode($this->config, JSON_THROW_ON_ERROR)), 'Configuration changée après réservation.');
        ensure($e['date'] === gmdate('Y-m-d'), 'Réservation périmée.');
        $this->ledger['runs'][$run]['status'] = 'running'; $this->save();
    }
    public function beforeCall(string $run): void {
        $e = $this->entry($run);
        ensure($e['status'] === 'running' && $e['calls'] < $e['max_calls'], 'Plafond des appels atteint.');
        $this->ledger['runs'][$run]['calls']++;
        // Une réponse perdue conserve le coût maximal de cet appel.
        $this->ledger['runs'][$run]['charged_usd'] += $this->worstCall();
        $this->save();
    }
    public function account(string $run, array $usage): void {
        $input = $usage['input_tokens'] ?? null; $output = $usage['output_tokens'] ?? null;
        ensure(is_int($input) && $input >= 0 && $input <= $this->config['max_input_bytes'] + 4096
            && is_int($output) && $output >= 0 && $output <= $this->config['max_output_tokens'], 'Usage absent ou hors limites : coût maximal conservé.');
        $e = &$this->ledger['runs'][$run];
        $actual = ($input * $this->config['input_usd_per_million'] + $output * $this->config['output_usd_per_million']) / 1000000;
        $e['charged_usd'] += $actual - $this->worstCall();
        $e['input_tokens'] += $input; $e['output_tokens'] += $output;
        $this->save();
    }
    public function finish(string $run, string $status, int $published = 0): void {
        $entry = $this->entry($run);
        ensure(in_array($status, ['completed', 'failed'], true) && $published >= 0 && $published <= $entry['count']
            && ($status !== 'failed' || $published === 0), 'Bilan de publication invalide.');
        $e = &$this->ledger['runs'][$run];
        $e['status'] = $status;
        $e['published_count'] = $published;
        // Les appels non effectués sont libérés ; les appels incertains restent comptés au maximum.
        $e['reserved_usd'] = ceil(max(0, $e['charged_usd']) * 1000000) / 1000000;
        $this->save();
    }
    public function dailyUsed(): int {
        $sum = 0;
        foreach ($this->ledger['runs'] as $e) {
            if ($e['date'] !== gmdate('Y-m-d')) continue;
            // Le workflow ne publie aucun lot quand la génération échoue. Les anciennes
            // entrées failed n'avaient pas encore de published_count ; leurs coûts restent conservés.
            $sum += match ($e['status']) {
                'failed'=>0,
                'completed'=>$e['published_count'] ?? $e['count'],
                default=>$e['count'], // Réservation/en cours/interruption incertaine : conserver les places.
            };
        }
        return $sum;
    }
    private function save(): void { atomicJson($this->file, $this->ledger); }
}
