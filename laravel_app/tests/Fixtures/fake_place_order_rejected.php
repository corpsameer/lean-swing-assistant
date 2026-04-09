<?php

echo json_encode([
    'status' => 'success',
    'mode' => 'paper',
    'dry_run' => false,
    'symbol' => 'AAPL',
    'setup_type' => 'breakout',
    'orders' => [
        'parent' => [
            'order_type' => 'STP LMT',
            'action' => 'BUY',
            'quantity' => 1,
            'stop_price' => 184.5,
            'limit_price' => 184.6,
        ],
        'take_profit' => [
            'order_type' => 'LMT',
            'action' => 'SELL',
            'quantity' => 1,
            'limit_price' => 188,
        ],
        'stop_loss' => [
            'order_type' => 'STP',
            'action' => 'SELL',
            'quantity' => 1,
            'stop_price' => 182.5,
        ],
    ],
    'broker_order_ids' => [
        'parent' => 22,
        'stop_loss' => 24,
        'take_profit' => 23,
    ],
    'broker_statuses' => [
        'parent' => 'Inactive',
        'stop_loss' => 'Inactive',
        'take_profit' => 'Inactive',
    ],
    'broker_diagnostics' => [
        'parent' => [
            'status' => 'Inactive',
            'last_message' => 'Error 201, reqId 22: Order rejected - reason:CASH AVAILABLE: 0.00',
        ],
    ],
]);
