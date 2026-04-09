<?php

namespace App\Services;

use App\Models\MarketSnapshot;
use App\Models\PromptLog;
use App\Models\Run;
use App\Models\TradeSetup;
use App\Models\WatchlistCandidate;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PromptCIntradayValidationService
{
    public function __construct(
        private readonly OpenAiService $openAiService,
        private readonly PaperTradeExecutionService $paperTradeExecutionService,
    ) {}

    /**
     * @return array{run_id:int,active_candidates_scanned:int,candidates_sent_to_model:int,enter_now_count:int,wait_count:int,reject_count:int,trade_setups_created:int,errors:int}
     */
    public function run(): array
    {
        $run = Run::create([
            'run_type' => 'intraday_validate',
            'status' => 'running',
            'started_at' => now('UTC'),
        ]);

        $summary = [
            'run_id' => $run->id,
            'active_candidates_scanned' => 0,
            'candidates_sent_to_model' => 0,
            'enter_now_count' => 0,
            'wait_count' => 0,
            'reject_count' => 0,
            'trade_setups_created' => 0,
            'errors' => 0,
        ];

        try {
            $candidates = $this->latestActiveCandidates();
            $summary['active_candidates_scanned'] = $candidates->count();
            $summary['skipped_candidates'] = [];

            if ($candidates->isEmpty()) {
                $this->completeRun($run, $summary);

                return $summary;
            }

            $symbolIds = $candidates->pluck('symbol_id')->all();
            $metricsBySymbolId = $this->latestMetricsBySymbolId($symbolIds, 'derived_daily_metrics');
            $intradayBySymbolId = $this->latestMetricsBySymbolId($symbolIds, 'intraday');

            [$eligibleRows, $candidateBySymbol, $skippedCandidates] = $this->buildEligiblePayloadRows($candidates, $metricsBySymbolId, $intradayBySymbolId);
            $summary['skipped_candidates'] = $skippedCandidates;

            $summary['candidates_sent_to_model'] = count($eligibleRows);

            if ($summary['candidates_sent_to_model'] === 0) {
                $this->completeRun($run, $summary);

                return $summary;
            }

            $promptPayload = $this->buildPromptPayload($eligibleRows);
            $openAiResult = $this->openAiService->requestStructuredJson($promptPayload);
            $parsedOutput = $this->parseModelOutput($openAiResult['content']);

            $validatedCandidates = Arr::get($parsedOutput, 'validated_candidates');
            if (! is_array($validatedCandidates)) {
                throw new RuntimeException('Model response missing validated_candidates array.');
            }

            DB::transaction(function () use ($run, $openAiResult, $validatedCandidates, $candidateBySymbol, &$summary): void {
                PromptLog::create([
                    'run_id' => $run->id,
                    'symbol_id' => null,
                    'prompt_type' => 'C',
                    'input_json' => $openAiResult['request'],
                    'output_json' => $openAiResult['response'],
                    'model_name' => $openAiResult['model'],
                    'created_at' => now('UTC'),
                ]);

                $seenSymbols = [];

                foreach ($validatedCandidates as $validatedCandidate) {
                    if (! is_array($validatedCandidate)) {
                        throw new RuntimeException('Invalid validated candidate object in model response.');
                    }

                    $symbol = strtoupper((string) Arr::get($validatedCandidate, 'symbol', ''));
                    if ($symbol === '' || ! $candidateBySymbol->has($symbol)) {
                        throw new RuntimeException("Model returned unknown symbol: {$symbol}");
                    }

                    if (in_array($symbol, $seenSymbols, true)) {
                        throw new RuntimeException("Duplicate symbol in model response: {$symbol}");
                    }
                    $seenSymbols[] = $symbol;

                    $decision = Arr::get($validatedCandidate, 'decision');
                    if (! is_string($decision) || ! in_array($decision, ['enter_now', 'wait', 'reject'], true)) {
                        throw new RuntimeException("Invalid decision for symbol: {$symbol}");
                    }

                    $entryPrice = Arr::get($validatedCandidate, 'entry_price');
                    $stopPrice = Arr::get($validatedCandidate, 'stop_price');
                    $target1Price = Arr::get($validatedCandidate, 'target1_price');
                    $target2Price = Arr::get($validatedCandidate, 'target2_price');
                    $alreadyExtended = Arr::get($validatedCandidate, 'already_extended');
                    $riskNote = Arr::get($validatedCandidate, 'risk_note');
                    $reasoningText = Arr::get($validatedCandidate, 'reasoning_text');

                    if (! is_numeric($entryPrice) || ! is_numeric($stopPrice) || ! is_numeric($target1Price) || ! is_numeric($target2Price)) {
                        throw new RuntimeException("Invalid pricing fields for symbol: {$symbol}");
                    }
                    if (! is_bool($alreadyExtended)) {
                        throw new RuntimeException("Invalid already_extended flag for symbol: {$symbol}");
                    }
                    if (! is_string($riskNote) || trim($riskNote) === '' || ! is_string($reasoningText) || trim($reasoningText) === '') {
                        throw new RuntimeException("Invalid risk_note/reasoning_text for symbol: {$symbol}");
                    }

                    /** @var WatchlistCandidate $candidate */
                    $candidate = $candidateBySymbol->get($symbol);

                    if ($decision === 'enter_now') {
                        $summary['enter_now_count']++;

                        $duplicateExists = TradeSetup::query()
                            ->where('symbol_id', $candidate->symbol_id)
                            ->whereIn('status', ['planned', 'open'])
                            ->exists();

                        if (! $duplicateExists) {
                            $tradeSetup = TradeSetup::create([
                                'symbol_id' => $candidate->symbol_id,
                                'source_candidate_id' => $candidate->id,
                                'status' => 'planned',
                                'entry_price' => (float) $entryPrice,
                                'stop_price' => (float) $stopPrice,
                                'target1_price' => (float) $target1Price,
                                'target2_price' => (float) $target2Price,
                                'notes' => trim($riskNote).' | '.trim($reasoningText),
                            ]);

                            $summary['trade_setups_created']++;

                            DB::afterCommit(function () use ($tradeSetup, $symbol): void {
                                $this->paperTradeExecutionService->executeBuyLimitForSetup($tradeSetup, $symbol);
                            });
                        }
                    }

                    if ($decision === 'wait') {
                        $summary['wait_count']++;
                    }

                    if ($decision === 'reject') {
                        $summary['reject_count']++;
                    }

                    $candidate->reasoning_text = $reasoningText;
                    $candidate->prompt_output_json = $validatedCandidate;
                    $candidate->save();
                }

                if (count($seenSymbols) !== $candidateBySymbol->count()) {
                    throw new RuntimeException('Model response did not include all eligible symbols.');
                }
            });

            $this->completeRun($run, $summary);

            return $summary;
        } catch (Throwable $throwable) {
            $summary['errors'] = 1;

            $run->status = 'completed_with_errors';
            $run->completed_at = now('UTC');
            $run->meta_json = [
                ...$summary,
                'error_message' => $throwable->getMessage(),
            ];
            $run->save();

            throw $throwable;
        }
    }

    /**
     * Latest is determined by the highest watchlist_candidates.id per symbol_id.
     *
     * @return Collection<int, WatchlistCandidate>
     */
    private function latestActiveCandidates(): Collection
    {
        $latestIdsBySymbol = WatchlistCandidate::query()
            ->selectRaw('MAX(id) AS id')
            ->where('stage', 'weekend')
            ->whereIn('status', ['keep', 'wait'])
            ->groupBy('symbol_id');

        return WatchlistCandidate::query()
            ->joinSub($latestIdsBySymbol, 'latest', function ($join): void {
                $join->on('watchlist_candidates.id', '=', 'latest.id');
            })
            ->with('symbol:id,symbol')
            ->select('watchlist_candidates.*')
            ->orderBy('symbol_id')
            ->get();
    }

    /**
     * @param  Collection<int, WatchlistCandidate>  $candidates
     * @param  array<int, array<string, mixed>>  $metricsBySymbolId
     * @param  array<int, array<string, mixed>>  $intradayBySymbolId
     * @return array{0:array<int,array<string,mixed>>,1:Collection<string,WatchlistCandidate>,2:array<int,string>}
     */
    private function buildEligiblePayloadRows(Collection $candidates, array $metricsBySymbolId, array $intradayBySymbolId): array
    {
        $nearBandTolerancePercent = (float) config('services.intraday_validation.near_band_tolerance_percent', 0.75);
        $maxExtensionPercent = (float) config('services.intraday_validation.max_extension_percent', 1.5);

        $eligibleRows = [];
        $candidateBySymbol = collect();
        $skippedCandidates = [];

        foreach ($candidates as $candidate) {
            $symbol = (string) $candidate->symbol->symbol;

            $triggerBandLow = $this->toFloat($candidate->trigger_band_low);
            $triggerBandHigh = $this->toFloat($candidate->trigger_band_high);

            if ($triggerBandLow === null || $triggerBandHigh === null || $triggerBandLow > $triggerBandHigh) {
                $skippedCandidates[] = sprintf('%s skipped: missing trigger band', $symbol);
                continue;
            }

            $intraday = Arr::get($intradayBySymbolId, $candidate->symbol_id, []);
            $dailyMetrics = Arr::get($metricsBySymbolId, $candidate->symbol_id, []);

            $currentPrice = $this->toFloat($this->extractInputValue($intraday, ['current_price', 'last_price', 'close']));
            if ($currentPrice === null || $currentPrice <= 0) {
                $skippedCandidates[] = sprintf('%s skipped: missing intraday price', $symbol);
                continue;
            }

            $isInBand = $currentPrice >= $triggerBandLow && $currentPrice <= $triggerBandHigh;
            $isNearBand = $this->isNearBand($currentPrice, $triggerBandLow, $triggerBandHigh, $nearBandTolerancePercent / 100);
            if (! $isInBand && ! $isNearBand) {
                $skippedCandidates[] = sprintf('%s skipped: price not near trigger band', $symbol);
                continue;
            }

            $extensionPercent = $this->toFloat($this->extractInputValue($intraday, ['extension_percent']))
                ?? $this->toFloat(Arr::get($dailyMetrics, 'extension_percent'));
            if ($currentPrice > ($triggerBandHigh * (1 + ($maxExtensionPercent / 100))) || (($extensionPercent ?? 0.0) > ($maxExtensionPercent * 2))) {
                $skippedCandidates[] = sprintf('%s skipped: already extended', $symbol);
                continue;
            }

            $eligibleRows[] = [
                'symbol' => $symbol,
                'current_price' => $currentPrice,
                'session_high' => $this->toFloat($this->extractInputValue($intraday, ['session_high', 'high'])),
                'session_low' => $this->toFloat($this->extractInputValue($intraday, ['session_low', 'low'])),
                'intraday_vwap' => $this->toFloat($this->extractInputValue($intraday, ['intraday_vwap', 'vwap'])),
                'breakout_level' => $this->toFloat(Arr::get($dailyMetrics, 'breakout_level')),
                'support_level' => $this->toFloat(Arr::get($dailyMetrics, 'support_level')),
                'trigger_band_low' => $triggerBandLow,
                'trigger_band_high' => $triggerBandHigh,
                'trend_state' => Arr::get($dailyMetrics, 'trend_state'),
                'atr_percent' => $this->toFloat(Arr::get($dailyMetrics, 'atr_percent')),
                'distance_to_breakout_percent' => $this->toFloat(Arr::get($dailyMetrics, 'distance_to_breakout_percent')),
                'extension_percent' => $extensionPercent,
                'setup_type' => $candidate->setup_type,
                'relative_volume_simple' => $this->toFloat($this->extractInputValue($intraday, ['relative_volume_simple']))
                    ?? $this->toFloat(Arr::get($dailyMetrics, 'relative_volume_simple')),
                'market_state' => $this->extractInputValue($intraday, ['market_state']),
            ];

            $candidateBySymbol->put(strtoupper($symbol), $candidate);
        }

        return [$eligibleRows, $candidateBySymbol, $skippedCandidates];
    }

    /**
     * @param  array<int,array<string,mixed>>  $eligibleRows
     * @return array{system_prompt:string,user_payload:array<string,mixed>,json_schema:array<string,mixed>,schema_name:string}
     */
    private function buildPromptPayload(array $eligibleRows): array
    {
        $allowedSymbols = array_values(array_map(static fn (array $row): string => (string) $row['symbol'], $eligibleRows));

        return [
            'system_prompt' => implode("\n", [
                'You are Prompt C, an intraday entry validator for swing-trading setups.',
                'Validate only provided symbols and do not invent symbols.',
                'For each symbol choose exactly one decision: enter_now, wait, or reject.',
                'Use concise actionable reasoning and short risk note.',
                'Return strict JSON only that matches the schema.',
            ]),
            'user_payload' => [
                'task' => 'Validate intraday entry readiness for active swing candidates and return planned trade parameters.',
                'rules' => [
                    'Only return symbols from allowed_symbols.',
                    'Do not include symbols not in the input.',
                    'Use structured output only.',
                ],
                'allowed_symbols' => $allowedSymbols,
                'candidates' => $eligibleRows,
            ],
            'schema_name' => 'intraday_entry_validator',
            'json_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'validated_candidates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'symbol' => ['type' => 'string'],
                                'decision' => ['type' => 'string', 'enum' => ['enter_now', 'wait', 'reject']],
                                'entry_price' => ['type' => 'number'],
                                'stop_price' => ['type' => 'number'],
                                'target1_price' => ['type' => 'number'],
                                'target2_price' => ['type' => 'number'],
                                'already_extended' => ['type' => 'boolean'],
                                'risk_note' => ['type' => 'string'],
                                'reasoning_text' => ['type' => 'string'],
                            ],
                            'required' => [
                                'symbol',
                                'decision',
                                'entry_price',
                                'stop_price',
                                'target1_price',
                                'target2_price',
                                'already_extended',
                                'risk_note',
                                'reasoning_text',
                            ],
                        ],
                    ],
                ],
                'required' => ['validated_candidates'],
            ],
        ];
    }

    /**
     * @param  array<int, int>  $symbolIds
     * @return array<int, array<string, mixed>>
     */
    private function latestMetricsBySymbolId(array $symbolIds, string $snapshotType): array
    {
        if ($symbolIds === []) {
            return [];
        }

        $latestBySymbol = MarketSnapshot::query()
            ->selectRaw('MAX(id) AS id')
            ->where('snapshot_type', $snapshotType)
            ->whereIn('symbol_id', $symbolIds)
            ->groupBy('symbol_id');

        $snapshots = MarketSnapshot::query()
            ->joinSub($latestBySymbol, 'latest', function ($join): void {
                $join->on('market_snapshots.id', '=', 'latest.id');
            })
            ->select('market_snapshots.symbol_id', 'market_snapshots.payload_json')
            ->get()
            ->all();

        $bySymbol = [];
        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof MarketSnapshot) {
                continue;
            }

            $bySymbol[$snapshot->symbol_id] = $this->normalizeSnapshotPayload($snapshot->payload_json);
        }

        return $bySymbol;
    }

    /**
     * @param  array<string,mixed>|mixed  $payloadJson
     * @return array<string,mixed>
     */
    private function normalizeSnapshotPayload(mixed $payloadJson): array
    {
        if (! is_array($payloadJson)) {
            return [];
        }

        $topLevelMetrics = Arr::get($payloadJson, 'metrics');
        if (is_array($topLevelMetrics)) {
            return $topLevelMetrics;
        }

        $symbolData = Arr::get($payloadJson, 'symbol_data');
        if (is_array($symbolData)) {
            $symbolMetrics = Arr::get($symbolData, 'metrics');
            if (is_array($symbolMetrics)) {
                return $symbolMetrics;
            }

            return $symbolData;
        }

        return $payloadJson;
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

    private function completeRun(Run $run, array $summary): void
    {
        $run->status = 'completed';
        $run->completed_at = now('UTC');
        $run->meta_json = $summary;
        $run->save();
    }

    private function isNearBand(float $currentPrice, float $triggerBandLow, float $triggerBandHigh, float $toleranceFraction): bool
    {
        if ($currentPrice < $triggerBandLow) {
            return (($triggerBandLow - $currentPrice) / $triggerBandLow) <= $toleranceFraction;
        }

        if ($currentPrice > $triggerBandHigh) {
            return (($currentPrice - $triggerBandHigh) / $triggerBandHigh) <= $toleranceFraction;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $keys
     */
    private function extractInputValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
