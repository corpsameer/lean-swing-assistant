<?php

echo json_encode([
    'status' => 'success',
    'mode' => 'paper',
    'orders' => [
        [
            'broker_order_id' => '1003',
            'broker_status' => 'Filled',
            'diagnostics' => [
                'status' => 'Filled',
                'last_message' => null,
                'log' => [],
            ],
            'child_statuses' => [
                'take_profit' => 'Submitted',
                'stop_loss' => 'Submitted',
            ],
        ],
    ],
]);
