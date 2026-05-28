<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::query()
            ->with(['symbol:id,symbol', 'tradeSetup.sourceCandidate:id,setup_type', 'tradeSetup.tradeReview'])
            ->whereIn('status', ['simulated_tp_hit', 'simulated_sl_hit', 'simulated_closed'])
            ->where(function ($q) {
                $q->whereNull('broker_order_id')
                    ->orWhere('meta_json->execution_driver', 'simulated')
                    ->orWhere('meta_json->simulated', true);
            });

        if ($request->filled('from')) $query->whereDate('filled_at', '>=', $request->string('from'));
        if ($request->filled('to')) $query->whereDate('filled_at', '<=', $request->string('to'));
        if ($request->filled('symbol')) {
            $symbol = strtoupper(trim((string) $request->string('symbol')));
            $query->whereHas('symbol', fn ($s) => $s->where('symbol', $symbol));
        }
        if ($request->filled('setup_type')) {
            $setupType = trim((string) $request->string('setup_type'));
            $query->whereHas('tradeSetup.sourceCandidate', fn ($s) => $s->where('setup_type', $setupType));
        }

        $orders = $query->orderByDesc('filled_at')->orderByDesc('id')->get();

        $rows = $orders->map(function (Order $o) {
            $m = is_array($o->meta_json) ? $o->meta_json : [];
            $review = $o->tradeSetup?->tradeReview?->lessons_json;
            $review = is_array($review) ? $review : [];
            return [
                'closed_at' => $m['simulated_closed_at'] ?? $o->filled_at?->toDateTimeString(),
                'symbol' => $o->symbol?->symbol,
                'setup_type' => $o->tradeSetup?->sourceCandidate?->setup_type,
                'entry' => is_numeric($m['simulated_entry_price'] ?? null) ? (float) $m['simulated_entry_price'] : null,
                'exit' => is_numeric($m['simulated_exit_price'] ?? null) ? (float) $m['simulated_exit_price'] : null,
                'exit_reason' => $m['exit_reason'] ?? null,
                'pnl_amount' => is_numeric($m['pnl_amount'] ?? null) ? (float) $m['pnl_amount'] : null,
                'pnl_percent' => is_numeric($m['pnl_percent'] ?? null) ? (float) $m['pnl_percent'] : null,
                'r_multiple' => is_numeric($m['r_multiple'] ?? null) ? (float) $m['r_multiple'] : null,
                'review_verdict' => $review['final_verdict'] ?? null,
                'review_score' => is_numeric($review['setup_quality_score'] ?? null) ? (float) $review['setup_quality_score'] : null,
            ];
        });

        $isWin = fn ($r) => ($r['pnl_amount'] ?? 0) > 0 || ($r['pnl_percent'] ?? 0) > 0;
        $isLoss = fn ($r) => ($r['pnl_amount'] ?? 0) < 0 || ($r['pnl_percent'] ?? 0) < 0;
        $wins = $rows->filter($isWin)->count();
        $losses = $rows->filter($isLoss)->count();

        $summary = [
            'total_closed' => $rows->count(), 'wins' => $wins, 'losses' => $losses,
            'win_rate' => $rows->count() ? ($wins / $rows->count()) * 100 : 0,
            'total_pnl_amount' => $rows->pluck('pnl_amount')->filter(fn ($v) => $v !== null)->sum(),
            'avg_pnl_percent' => $rows->pluck('pnl_percent')->filter(fn ($v) => $v !== null)->avg() ?? 0,
            'avg_r_multiple' => $rows->pluck('r_multiple')->filter(fn ($v) => $v !== null)->avg() ?? 0,
            'best_pnl_percent' => $rows->pluck('pnl_percent')->filter(fn ($v) => $v !== null)->max(),
            'worst_pnl_percent' => $rows->pluck('pnl_percent')->filter(fn ($v) => $v !== null)->min(),
            'tp_hits' => $rows->where('exit_reason', 'target1_hit')->count(),
            'sl_hits' => $rows->where('exit_reason', 'stop_loss_hit')->count(),
        ];

        $calcGroup = function ($items, $key, $withTotal = false) use ($isWin, $isLoss) {
            return $items->groupBy(fn ($r) => $r[$key] ?: '—')->map(function ($g, $name) use ($isWin, $isLoss, $withTotal, $key) {
                $wins = $g->filter($isWin)->count(); $losses = $g->filter($isLoss)->count();
                $row = [$key => $name, 'total' => $g->count(), 'wins' => $wins, 'losses' => $losses,
                    'win_rate' => $g->count() ? ($wins / $g->count()) * 100 : 0,
                    'avg_pnl_percent' => $g->pluck('pnl_percent')->filter(fn ($v)=>$v!==null)->avg() ?? 0,
                    'avg_r_multiple' => $g->pluck('r_multiple')->filter(fn ($v)=>$v!==null)->avg() ?? 0];
                if ($withTotal) $row['total_pnl_amount'] = $g->pluck('pnl_amount')->filter(fn ($v)=>$v!==null)->sum();
                return $row;
            })->values();
        };

        $bySetupType = $calcGroup($rows, 'setup_type');
        $bySymbol = $calcGroup($rows, 'symbol', true);

        $reviewSummary = null;
        if (Schema::hasTable('trade_reviews')) {
            $reviewed = $orders->filter(fn ($o) => $o->tradeSetup?->tradeReview !== null)
                ->map(fn ($o) => is_array($o->tradeSetup->tradeReview->lessons_json) ? $o->tradeSetup->tradeReview->lessons_json : []);
            $verdicts = $reviewed->pluck('final_verdict')->filter();
            $reviewSummary = [
                'reviewed_count' => $reviewed->count(),
                'avg_setup_quality_score' => $reviewed->pluck('setup_quality_score')->filter('is_numeric')->avg() ?? 0,
                'avg_entry_quality_score' => $reviewed->pluck('entry_quality_score')->filter('is_numeric')->avg() ?? 0,
                'avg_risk_reward_quality_score' => $reviewed->pluck('risk_reward_quality_score')->filter('is_numeric')->avg() ?? 0,
                'prefer_similar' => $verdicts->filter(fn ($v) => $v === 'prefer_similar')->count(),
                'avoid_similar' => $verdicts->filter(fn ($v) => $v === 'avoid_similar')->count(),
                'neutral' => $verdicts->filter(fn ($v) => $v === 'neutral')->count(),
                'needs_more_data' => $verdicts->filter(fn ($v) => $v === 'needs_more_data')->count(),
            ];
        }

        return view('analytics.index', ['summary'=>$summary,'recentRows'=>$rows->take(50),'bySetupType'=>$bySetupType,'bySymbol'=>$bySymbol,'reviewSummary'=>$reviewSummary]);
    }
}
