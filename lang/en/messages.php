<?php

declare(strict_types=1);

return [
    'errors' => [
        'tid_required' => 'Please enter the TID.',
        'order_not_found' => 'Order not found.',
        'invalid_request' => 'Invalid request.',
        'invalid_amount' => 'The requested amount is invalid.',
        'vbank_refund_required_fields' => 'Please provide all required fields: TID, order number, cancel amount, and refund account information (account number, bank code, account holder).',
        'vbank_completed_requires_bank_info' => 'Refund account information is required for completed virtual account deposits. Please process the refund through the admin API.',
        'invalid_refund_amount' => 'The refund amount is invalid. (requested: :amount KRW)',
    ],
    'refund' => [
        'missing_tid' => 'Cannot process refund: transaction ID (TID) is missing.',
        'default_reason' => 'Buyer refund request',
    ],
    'defaults' => [
        'vbank_refund_msg' => 'Virtual account refund',
    ],
];
