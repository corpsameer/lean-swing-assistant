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

class PromptAWeekendRankService
{
    public function __construct(private readonly OpenAiService $openAiService) {}

    /**
     * @return array{run_id:int,candidates_sent:int,candidates_ranked:int,candidates_updated:int,errors:int}
     */
    public function run(): array
    {
        $run = Run::create([
            'run_type' => 'weekend_prompt_rank',
            'status' => 'running',
            'started_at' => now('UTC'),
        ]);

        $errors = 0;
        $candidatesRanked = 0;
        $candidatesUpdated = 0;

        try {
            $candidates = $this->latestWeekendCandidates();
            $candidatesSent = $candidates->count();

            if ($candidatesSent === 0) {
                $run->status = 'completed';
                $run->completed_at = now('UTC');
                $run->meta_json = [
                    'candidates_sent' => 0,
                    'candidates_ranked' => 0,
                    'candidates_updated' => 0,
                    'error_count' => 0,
                ];
                $run->save();

                return [
                    'run_id' => $run->id,
                    'candidates_sent' => 0,
                    'candidates_ranked' => 0,
                    'candidates_updated' => 0,
                    'errors' => 0,
                ];
            }

            $promptPayload = $this->buildPromptPayload($candidates);
            $openAiResult = $this->openAiService->requestStructuredJson($promptPayload);
            $parsedOutput = $this->parseModelOutput($openAiResult['content']);

            $rankedCandidates = Arr::get($parsedOutput, 'ranked_candidates');
            if (! is_array($rankedCandidates)) {
                throw new RuntimeException('Model response missing ranked_candidates array.');
            }

            DB::transaction(function () use ($run, $openAiResult, $rankedCandidates, $candidates, &$candidatesRanked, &$candidatesUpdated): void {
                PromptLog::create([
                    'run_id' => $run->id,
                    'symbol_id' => null,
                    'prompt_type' => 'A',
                    'input_json' => $openAiResult['request'],
                    'output_json' => $openAiResult['response'],
                    'model_name' => $openAiResult['model'],
                    'created_at' => now('UTC'),
                ]);

                $candidateBySymbol = $candidates->keyBy(fn (WatchlistCandidate $candidate): string => (string) $candidate->symbol->symbol);
                $seenSymbols = [];

                foreach ($rankedCandidates as $rankedCandidate) {
                    if (! is_array($rankedCandidate)) {
                        throw new RuntimeException('Invalid ranked candidate object in model response.');
                    }

                    $symbol = strtoupper((string) Arr::get($rankedCandidate, 'symbol', ''));
                    if ($symbol === '' || ! $candidateBySymbol->has($symbol)) {
                        throw new RuntimeException("Model returned unknown symbol: {$symbol}");
                    }

                    if (in_array($symbol, $seenSymbols, true)) {
                        throw new RuntimeException("Duplicate symbol in model response: {$symbol}");
                    }
                    $seenSymbols[] = $symbol;

                    $score = Arr::get($rankedCandidate, 'score_total');
                    if (! is_int($score) && ! is_float($score) && ! (is_string($score) && is_numeric($score))) {
                        throw new RuntimeException("Invalid score_total for symbol: {$symbol}");
                    }

                    $reasoningText = Arr::get($rankedCandidate, 'reasoning_text');
                    if (! is_string($reasoningText) || trim($reasoningText) === '') {
                        throw new RuntimeException("Invalid reasoning_text for symbol: {$symbol}");
                    }

                    /** @var WatchlistCandidate $candidate */
                    $candidate = $candidateBySymbol->get($symbol);
                    $candidate->score_total = (float) $score;

                    $setupType = Arr::get($rankedCandidate, 'setup_type');
                    if (is_string($setupType) && $setupType !== '') {
                        $candidate->setup_type = $setupType;
                    }

                    $candidate->reasoning_text = $reasoningText;
                    $candidate->prompt_output_json = $rankedCandidate;
                    $candidate->save();

                    $candidatesUpdated++;
                }

                $candidatesRanked = count($rankedCandidates);
            });

            $run->status = 'completed';
            $run->completed_at = now('UTC');
            $run->meta_json = [
                'candidates_sent' => $candidatesSent,
                'candidates_ranked' => $candidatesRanked,
                'candidates_updated' => $candidatesUpdated,
                'error_count' => 0,
            ];
            $run->save();

            return [
                'run_id' => $run->id,
                'candidates_sent' => $candidatesSent,
                'candidates_ranked' => $candidatesRanked,
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
                'candidates_ranked' => $candidatesRanked,
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
     * @return array{system_prompt:string,user_payload:array<string,mixed>,json_schema:array<string,mixed>}
     */
    private function buildPromptPayload(Collection $candidates): array
    {
        $metricsBySymbolId = $this->latestMetricsBySymbolId($candidates->pluck('symbol_id')->all());

        $candidateRows = [];

        foreach ($candidates as $candidate) {
            $metrics = Arr::get($metricsBySymbolId, $candidate->symbol_id, []);

            $candidateRows[] = [
                'symbol' => $candidate->symbol->symbol,
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
                'setup_type' => $candidate->setup_type,
            ];
        }

        $allowedSymbols = array_values(array_map(static fn (array $row): string => (string) $row['symbol'], $candidateRows));

        return [
            'system_prompt' => implode("\n", [
                'You are Prompt A, a weekend swing-trading candidate ranker.',
                'Rank only the provided symbols and do not invent symbols.',
                'Score each candidate from 0 to 30.',
                'Assign setup_type as breakout or pullback.',
                'Assign preferred_action as watch, ready_breakout, or ready_pullback.',
                'Keep reasoning concise and practical.',
                'Return strict JSON only that matches the schema.',
            ]),
            'user_payload' => [
                'task' => 'Rank already-filtered weekend candidates.',
                'rules' => [
                    'Only return symbols from allowed_symbols.',
                    'Do not include symbols not in the input.',
                    'Provide concise upside and risk comments.',
                ],
                'allowed_symbols' => $allowedSymbols,
                'candidates' => $candidateRows,
            ],
            'json_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'ranked_candidates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'symbol' => ['type' => 'string'],
                                'keep' => ['type' => 'boolean'],
                                'score_total' => ['type' => 'number'],
                                'setup_type' => ['type' => 'string', 'enum' => ['breakout', 'pullback']],
                                'preferred_action' => ['type' => 'string', 'enum' => ['watch', 'ready_breakout', 'ready_pullback']],
                                'upside_potential_summary' => ['type' => 'string'],
                                'risk_flags' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'reasoning_text' => ['type' => 'string'],
                            ],
                            'required' => [
                                'symbol',
                                'keep',
                                'score_total',
                                'setup_type',
                                'preferred_action',
                                'upside_potential_summary',
                                'risk_flags',
                                'reasoning_text',
                            ],
                        ],
                    ],
                ],
                'required' => ['ranked_candidates'],
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
