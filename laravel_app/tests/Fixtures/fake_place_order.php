<?php

$arguments = $argv;
array_shift($arguments);

$parsed = [];
for ($i = 0; $i < count($arguments); $i++) {
    $arg = $arguments[$i];
    if (str_starts_with($arg, '--')) {
        $key = substr($arg, 2);
        $next = $arguments[$i + 1] ?? null;
        if ($next === null || str_starts_with($next, '--')) {
            $parsed[$key] = true;
            continue;
        }
        $parsed[$key] = $next;
        $i++;
    }
}

$setupType = strtolower((string) ($parsed['setup-type'] ?? 'pullback'));
$quantity = (float) ($parsed['quantity'] ?? 1);
$entryPrice = (float) ($parsed['entry-price'] ?? 0);
$stopPrice = (float) ($parsed['stop-price'] ?? 0);
$target1Price = (float) ($parsed['target1-price'] ?? 0);
$buffer = (float) ($parsed['breakout-stop-limit-buffer'] ?? 0.10);
$dryRun = array_key_exists('dry-run', $parsed);

$parent = [
    'order_type' => $setupType === 'breakout' ? 'STP LMT' : 'LMT',
    'action' => 'BUY',
    'quantity' => $quantity,
    'limit_price' => $setupType === 'breakout' ? $entryPrice + $buffer : $entryPrice,
];
if ($setupType === 'breakout') {
    $parent['stop_price'] = $entryPrice;
}

$response = [
    'status' => 'success',
    'mode' => 'paper',
    'dry_run' => $dryRun,
    'symbol' => strtoupper((string) ($parsed['symbol'] ?? 'AAPL')),
    'setup_type' => $setupType,
    'orders' => [
        'parent' => $parent,
        'take_profit' => [
            'order_type' => 'LMT',
            'action' => 'SELL',
            'quantity' => $quantity,
            'limit_price' => $target1Price,
        ],
        'stop_loss' => [
            'order_type' => 'STP',
            'action' => 'SELL',
            'quantity' => $quantity,
            'stop_price' => $stopPrice,
        ],
    ],
];

if (! $dryRun) {
    $response['broker_order_ids'] = [
        'parent' => 1001,
        'take_profit' => 1002,
        'stop_loss' => 1003,
    ];
    $response['broker_statuses'] = [
        'parent' => 'Submitted',
        'take_profit' => 'PreSubmitted',
        'stop_loss' => 'PreSubmitted',
    ];
}

echo json_encode($response);
