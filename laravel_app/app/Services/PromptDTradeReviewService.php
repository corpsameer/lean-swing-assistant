<?php

namespace App\Services;

use App\Models\MarketSnapshot;
use App\Models\Order;
use App\Models\PromptLog;
use App\Models\TradeReview;
use App\Models\TradeSetup;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PromptDTradeReviewService
{
    public function __construct(
        private readonly OpenAiService $openAiService,
    ) {}

    /**
     * @return array{closed_trades_found:int,trades_reviewed:int,trades_skipped_already_reviewed:int,errors:int}
     */
    public function run(int $limit = 10, bool $force = false): array
    {
        $summary = [
            'closed_trades_found' => 0,
            'trades_reviewed' => 0,
            'trades_skipped_already_reviewed' => 0,
            'errors' => 0,
        ];

        $orders = $this->eligibleOrders($limit);
        $summary['closed_trades_found'] = $orders->count();

        foreach ($orders as $order) {
            try {
                $existingReview = $order->tradeSetup?->tradeReview;
                if ($existingReview && ! $force) {
                    $summary['trades_skipped_already_reviewed']++;
                    continue;
                }

                $payload = $this->buildReviewPayload($order);
                $openAiResult = $this->openAiService->requestStructuredJson($this->promptPayload($payload));
                $reviewJson = $this->parseReviewJson($openAiResult['content']);
                $reviewText = (string) Arr::get($reviewJson, 'summary', 'No summary provided.');

                DB::transaction(function () use ($order, $existingReview, $reviewJson, $reviewText): void {
                    $record = $existingReview ?? new TradeReview();
                    $record->trade_setup_id = $order->trade_setup_id;
                    $record->outcome_status = (string) $order->status;
                    $record->pnl_amount = $this->toNullableFloat(Arr::get($order->meta_json, 'pnl_amount'));
                    $record->pnl_percent = $this->toNullableFloat(Arr::get($order->meta_json, 'pnl_percent'));
                    $record->review_text = $reviewText;
                    $record->lessons_json = $reviewJson;
                    $record->created_at = now('UTC');
                    $record->save();
                });

                $summary['trades_reviewed']++;
            } catch (Throwable) {
                $summary['errors']++;
            }
        }

        return $summary;
    }

    /**
     * @return Collection<int, Order>
     */
    private function eligibleOrders(int $limit): Collection
    {
        return Order::query()
            ->with([
                'tradeSetup.tradeReview',
                'tradeSetup.sourceCandidate:id,reasoning_text,prompt_output_json,setup_type',
                'tradeSetup.symbol:id,symbol',
            ])
            ->whereIn('status', ['simulated_tp_hit', 'simulated_sl_hit', 'simulated_closed'])
            ->whereHas('tradeSetup', fn ($query) => $query->where('status', 'closed'))
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildReviewPayload(Order $order): array
    {
        $setup = $order->tradeSetup;
        if (! $setup) {
            throw new RuntimeException('Order has no related trade setup.');
        }

        $candidate = $setup->sourceCandidate;
        $promptLogs = PromptLog::query()
            ->where('symbol_id', $setup->symbol_id)
            ->whereIn('prompt_type', ['A', 'B', 'C'])
            ->orderByDesc('id')
            ->limit(6)
            ->get(['prompt_type', 'input_json', 'output_json', 'created_at']);

        $latestSnapshot = MarketSnapshot::query()
            ->where('symbol_id', $setup->symbol_id)
            ->whereIn('snapshot_type', ['intraday', 'derived_daily_metrics', 'daily_bars'])
            ->orderByDesc('id')
            ->first();

        return [
            'symbol' => $setup->symbol?->symbol,
            'setup_type' => $candidate?->setup_type,
            'entry_price' => $this->toNullableFloat($setup->entry_price),
            'stop_price' => $this->toNullableFloat($setup->stop_price),
            'target1_price' => $this->toNullableFloat($setup->target1_price),
            'target2_price' => $this->toNullableFloat($setup->target2_price),
            'simulated_entry_price' => $this->toNullableFloat(Arr::get($order->meta_json, 'simulated_entry_price')),
            'simulated_exit_price' => $this->toNullableFloat(Arr::get($order->meta_json, 'simulated_exit_price')),
            'exit_reason' => Arr::get($order->meta_json, 'exit_reason'),
            'pnl_amount' => $this->toNullableFloat(Arr::get($order->meta_json, 'pnl_amount')),
            'pnl_percent' => $this->toNullableFloat(Arr::get($order->meta_json, 'pnl_percent')),
            'r_multiple' => $this->toNullableFloat(Arr::get($order->meta_json, 'r_multiple')),
            'simulated_entered_at' => Arr::get($order->meta_json, 'simulated_entered_at'),
            'simulated_closed_at' => Arr::get($order->meta_json, 'simulated_closed_at'),
            'order_meta_json' => $order->meta_json,
            'candidate_reasoning' => $candidate?->reasoning_text,
            'candidate_prompt_output' => $candidate?->prompt_output_json,
            'prompt_logs' => $promptLogs->map(fn ($log) => [
                'prompt_type' => $log->prompt_type,
                'input_json' => $log->input_json,
                'output_json' => $log->output_json,
                'created_at' => optional($log->created_at)->toDateTimeString(),
            ])->all(),
            'latest_market_snapshot' => $latestSnapshot ? [
                'snapshot_type' => $latestSnapshot->snapshot_type,
                'payload_json' => $latestSnapshot->payload_json,
                'created_at' => optional($latestSnapshot->created_at)->toDateTimeString(),
            ] : null,
        ];
    }

    private function promptPayload(array $userPayload): array
    {
        return [
            'schema_name' => 'trade_review_prompt_d',
            'system_prompt' => 'You are Prompt D for Lean Swing Assistant. Review closed SIMULATED trades only. Use only provided payload facts. If data is missing, explicitly say data is missing. Never invent market events, candles, gaps, or news. This is analysis only, never trading advice or execution.',
            'user_payload' => $userPayload,
            'json_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['summary', 'setup_quality_score', 'entry_quality_score', 'risk_reward_quality_score', 'execution_quality_score', 'outcome_explanation', 'what_worked', 'what_failed', 'improvement_notes', 'future_rule_suggestions', 'final_verdict'],
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'setup_quality_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'entry_quality_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'risk_reward_quality_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'execution_quality_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'outcome_explanation' => ['type' => 'string'],
                    'what_worked' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'what_failed' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'improvement_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'future_rule_suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'final_verdict' => ['type' => 'string', 'enum' => ['prefer_similar', 'avoid_similar', 'neutral', 'needs_more_data']],
                ],
            ],
        ];
    }

    private function parseReviewJson(string $content): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Trade review model response is not valid JSON object.');
        }

        return $decoded;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}

