<?php

echo json_encode([
    'status' => 'success',
    'mode' => 'paper',
    'orders' => [
        [
            'broker_order_id' => '1001',
            'broker_status' => 'Submitted',
            'diagnostics' => [
                'status' => 'Submitted',
                'last_message' => null,
                'log' => [],
            ],
            'child_statuses' => [
                'take_profit' => 'PreSubmitted',
                'stop_loss' => 'PreSubmitted',
            ],
        ],
    ],
]);
