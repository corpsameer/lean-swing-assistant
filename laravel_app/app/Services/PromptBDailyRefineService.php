<?php

namespace App\Services;

use App\Models\MarketSnapshot;
use App\Models\PromptLog;
use App\Models\Run;
use App\Models\WatchlistCandidate;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PromptBDailyRefineService
{
    public function __construct(private readonly OpenAiService $openAiService) {}

    /**
     * @return array{run_id:int,candidates_sent:int,candidates_refined:int,candidates_updated:int,errors:int}
     */
    public function run(): array
    {
        $run = Run::create([
            'run_type' => 'daily_refine',
            'status' => 'running',
            'started_at' => now('UTC'),
        ]);

        $errors = 0;
        $candidatesRefined = 0;
        $candidatesUpdated = 0;

        try {
            $candidates = $this->latestWeekendCandidates();
            $candidatesSent = $candidates->count();

            if ($candidatesSent === 0) {
                $run->status = 'completed';
                $run->completed_at = now('UTC');
                $run->meta_json = [
                    'candidates_sent' => 0,
                    'candidates_refined' => 0,
                    'candidates_updated' => 0,
                    'error_count' => 0,
                ];
                $run->save();

                return [
                    'run_id' => $run->id,
                    'candidates_sent' => 0,
                    'candidates_refined' => 0,
                    'candidates_updated' => 0,
                    'errors' => 0,
                ];
            }

            $promptPayload = $this->buildPromptPayload($candidates);
            $openAiResult = $this->openAiService->requestStructuredJson($promptPayload);
            $parsedOutput = $this->parseModelOutput($openAiResult['content']);

            $refinedCandidates = Arr::get($parsedOutput, 'refined_candidates');
            if (! is_array($refinedCandidates)) {
                throw new RuntimeException('Model response missing refined_candidates array.');
            }

            DB::transaction(function () use ($run, $openAiResult, $refinedCandidates, $candidates, &$candidatesRefined, &$candidatesUpdated): void {
                PromptLog::create([
                    'run_id' => $run->id,
                    'symbol_id' => null,
                    'prompt_type' => 'B',
                    'input_json' => $openAiResult['request'],
                    'output_json' => $openAiResult['response'],
                    'model_name' => $openAiResult['model'],
                    'created_at' => now('UTC'),
                ]);

                $candidateBySymbol = $candidates->keyBy(fn (WatchlistCandidate $candidate): string => (string) $candidate->symbol->symbol);
                $seenSymbols = [];

                foreach ($refinedCandidates as $refinedCandidate) {
                    if (! is_array($refinedCandidate)) {
                        throw new RuntimeException('Invalid refined candidate object in model response.');
                    }

                    $symbol = strtoupper((string) Arr::get($refinedCandidate, 'symbol', ''));
                    if ($symbol === '' || ! $candidateBySymbol->has($symbol)) {
                        throw new RuntimeException("Model returned unknown symbol: {$symbol}");
                    }

                    if (in_array($symbol, $seenSymbols, true)) {
                        throw new RuntimeException("Duplicate symbol in model response: {$symbol}");
                    }
                    $seenSymbols[] = $symbol;

                    $decision = Arr::get($refinedCandidate, 'decision');
                    if (! is_string($decision) || ! in_array($decision, ['keep', 'wait', 'remove'], true)) {
                        throw new RuntimeException("Invalid decision for symbol: {$symbol}");
                    }

                    $triggerBandLow = Arr::get($refinedCandidate, 'trigger_band_low');
                    $triggerBandHigh = Arr::get($refinedCandidate, 'trigger_band_high');
                    if (! is_numeric($triggerBandLow) || ! is_numeric($triggerBandHigh)) {
                        throw new RuntimeException("Invalid trigger band for symbol: {$symbol}");
                    }

                    $reasoningText = Arr::get($refinedCandidate, 'reasoning_text');
                    if (! is_string($reasoningText) || trim($reasoningText) === '') {
                        throw new RuntimeException("Invalid reasoning_text for symbol: {$symbol}");
                    }

                    /** @var WatchlistCandidate $candidate */
                    $candidate = $candidateBySymbol->get($symbol);

                    $setupType = Arr::get($refinedCandidate, 'setup_type');
                    if (is_string($setupType) && $setupType !== '') {
                        $candidate->setup_type = $setupType;
                    }

                    $candidate->status = $decision;
                    $candidate->trigger_band_low = (float) $triggerBandLow;
                    $candidate->trigger_band_high = (float) $triggerBandHigh;
                    $candidate->reasoning_text = $reasoningText;
                    $candidate->prompt_output_json = $refinedCandidate;
                    $candidate->save();

                    $candidatesUpdated++;
                }

                $candidatesRefined = count($refinedCandidates);
            });

            $run->status = 'completed';
            $run->completed_at = now('UTC');
            $run->meta_json = [
                'candidates_sent' => $candidatesSent,
                'candidates_refined' => $candidatesRefined,
                'candidates_updated' => $candidatesUpdated,
                'error_count' => 0,
            ];
            $run->save();

            return [
                'run_id' => $run->id,
                'candidates_sent' => $candidatesSent,
                'candidates_refined' => $candidatesRefined,
                'candidates_updated' => $candidatesUpdated,
                'errors' => 0,
            ];
        } catch (Throwable $throwable) {
            $errors = 1;
            $run->status = 'completed_with_errors';
            $run->completed_at = now('UTC');
            $run->meta_json = [
                'error_count' => $errors,
                'error_message' => $throwable->getMessage(),
                'candidates_refined' => $candidatesRefined,
                'candidates_updated' => $candidatesUpdated,
            ];
            $run->save();

            throw $throwable;
        }
    }

    /**
     * @return Collection<int, WatchlistCandidate>
     */
    private function latestWeekendCandidates(): Collection
    {
        $latestWeekendRunId = WatchlistCandidate::query()
            ->where('stage', 'weekend')
            ->max('run_id');

        if ($latestWeekendRunId === null) {
            return collect();
        }

        return WatchlistCandidate::query()
            ->with('symbol:id,symbol')
            ->where('run_id', $latestWeekendRunId)
            ->where('stage', 'weekend')
            ->orderBy('symbol_id')
            ->get();
    }

    /**
     * @param  Collection<int, WatchlistCandidate>  $candidates
     * @return array{system_prompt:string,user_payload:array<string,mixed>,json_schema:array<string,mixed>,schema_name:string}
     */
    private function buildPromptPayload(Collection $candidates): array
    {
        $metricsBySymbolId = $this->latestMetricsBySymbolId($candidates->pluck('symbol_id')->all());

        $candidateRows = [];

        foreach ($candidates as $candidate) {
            $metrics = Arr::get($metricsBySymbolId, $candidate->symbol_id, []);

            $candidateRows[] = [
                'symbol' => $candidate->symbol->symbol,
                'current_status' => $candidate->status,
                'weekend_setup_type' => $candidate->setup_type,
                'weekend_score_total' => $candidate->score_total,
                'last_price' => Arr::get($metrics, 'last_price'),
                'daily_change_percent' => Arr::get($metrics, 'daily_change_percent'),
                'avg_volume_20d' => Arr::get($metrics, 'avg_volume_20d'),
                'atr_14' => Arr::get($metrics, 'atr_14'),
                'atr_percent' => Arr::get($metrics, 'atr_percent'),
                'high_20d' => Arr::get($metrics, 'high_20d'),
                'low_20d' => Arr::get($metrics, 'low_20d'),
                'high_50d' => Arr::get($metrics, 'high_50d'),
                'low_50d' => Arr::get($metrics, 'low_50d'),
                'breakout_level' => Arr::get($metrics, 'breakout_level'),
                'support_level' => Arr::get($metrics, 'support_level'),
                'distance_to_breakout_percent' => Arr::get($metrics, 'distance_to_breakout_percent'),
                'pullback_depth_percent' => Arr::get($metrics, 'pullback_depth_percent'),
                'trend_state' => Arr::get($metrics, 'trend_state'),
                'extension_percent' => Arr::get($metrics, 'extension_percent'),
                'relative_volume_simple' => Arr::get($metrics, 'relative_volume_simple'),
            ];
        }

        $allowedSymbols = array_values(array_map(static fn (array $row): string => (string) $row['symbol'], $candidateRows));

        return [
            'system_prompt' => implode("\n", [
                'You are Prompt B, a daily watchlist refiner for swing-trading candidates.',
                'Refine only provided symbols and do not invent symbols.',
                'For each symbol choose decision: keep, wait, or remove.',
                'Refine setup_type and define trigger_band_low and trigger_band_high as numeric prices.',
                'Provide invalidation_note, confidence (0 to 1), and concise reasoning_text.',
                'Return strict JSON only that matches the schema.',
            ]),
            'user_payload' => [
                'task' => 'Revalidate weekend candidates with latest daily metrics and produce actionable daily watchlist decisions.',
                'rules' => [
                    'Only return symbols from allowed_symbols.',
                    'Do not include symbols not in the input.',
                    'Use structured output only.',
                ],
                'allowed_symbols' => $allowedSymbols,
                'candidates' => $candidateRows,
            ],
            'schema_name' => 'daily_watchlist_refiner',
            'json_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'refined_candidates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'symbol' => ['type' => 'string'],
                                'decision' => ['type' => 'string', 'enum' => ['keep', 'wait', 'remove']],
                                'setup_type' => ['type' => 'string'],
                                'trigger_band_low' => ['type' => 'number'],
                                'trigger_band_high' => ['type' => 'number'],
                                'invalidation_note' => ['type' => 'string'],
                                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                                'reasoning_text' => ['type' => 'string'],
                            ],
                            'required' => [
                                'symbol',
                                'decision',
                                'setup_type',
                                'trigger_band_low',
                                'trigger_band_high',
                                'invalidation_note',
                                'confidence',
                                'reasoning_text',
                            ],
                        ],
                    ],
                ],
                'required' => ['refined_candidates'],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $symbolIds
     * @return array<int, array<string, mixed>>
     */
    private function latestMetricsBySymbolId(array $symbolIds): array
    {
        $latestBySymbol = MarketSnapshot::query()
            ->selectRaw('MAX(id) AS id')
            ->where('snapshot_type', 'derived_daily_metrics')
            ->whereIn('symbol_id', $symbolIds)
            ->groupBy('symbol_id');

        return MarketSnapshot::query()
            ->joinSub($latestBySymbol, 'latest', function ($join): void {
                $join->on('market_snapshots.id', '=', 'latest.id');
            })
            ->select('market_snapshots.symbol_id', 'market_snapshots.payload_json')
            ->get()
            ->mapWithKeys(function (MarketSnapshot $snapshot): array {
                $metrics = Arr::get($snapshot->payload_json, 'metrics', []);

                return [$snapshot->symbol_id => is_array($metrics) ? $metrics : []];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function parseModelOutput(string $jsonString): array
    {
        $decoded = json_decode($jsonString, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Model output is not valid JSON object.');
        }

        return $decoded;
    }
}
