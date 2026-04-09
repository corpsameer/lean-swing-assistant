<?php

echo json_encode([
    'status' => 'success',
    'mode' => 'paper',
    'orders' => [
        [
            'broker_order_id' => '1002',
            'broker_status' => 'Rejected',
            'diagnostics' => [
                'status' => 'Rejected',
                'last_message' => 'Error 201, reqId 1002: Order rejected - reason:CASH AVAILABLE: 0.00',
                'log' => [],
            ],
            'child_statuses' => [],
        ],
    ],
]);
