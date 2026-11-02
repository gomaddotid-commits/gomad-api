<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'production' => filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOL),
        'snap_url' => env(
            'MIDTRANS_SNAP_URL',
            env('MIDTRANS_IS_PRODUCTION', false)
                ? 'https://app.midtrans.com/snap/v1/transactions'
                : 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        ),
        'payment_timeout' => (int) env('MIDTRANS_PAYMENT_TIMEOUT', 30),
        // Tenggang sebelum pemesanan tanpa percobaan pembayaran dilepas otomatis.
        'expire_grace_minutes' => (int) env('MIDTRANS_EXPIRE_GRACE_MINUTES', 15),
    ],

    'withdrawal' => [
        'minimum_amount' => (int) env('WITHDRAWAL_MINIMUM_AMOUNT', 100000),
        'admin_fee' => (int) env('WITHDRAWAL_ADMIN_FEE', 5000),
    ],

    'mad_warung' => [
        'platform_fee_basis_points' => (int) env('MAD_WARUNG_PLATFORM_FEE_BASIS_POINTS', 300),
        'ppn_basis_points' => env('MAD_WARUNG_PPN_BASIS_POINTS') === null
            || env('MAD_WARUNG_PPN_BASIS_POINTS') === ''
            ? null
            : (int) env('MAD_WARUNG_PPN_BASIS_POINTS'),
    ],

    'mad_trans' => [
        'service_fee' => (int) env('MAD_TRANS_SERVICE_FEE', 5000),
        'platform_fee_basis_points' => (int) env('MAD_TRANS_PLATFORM_FEE_BASIS_POINTS', 300),
        'ppn_basis_points' => env('MAD_TRANS_PPN_BASIS_POINTS') === null
            || env('MAD_TRANS_PPN_BASIS_POINTS') === ''
            ? null
            : (int) env('MAD_TRANS_PPN_BASIS_POINTS'),
    ],

    'rental' => [
        'service_fee' => (int) env('RENTAL_SERVICE_FEE', 10000),
        'platform_fee_basis_points' => (int) env('RENTAL_PLATFORM_FEE_BASIS_POINTS', 300),
        'ppn_basis_points' => env('RENTAL_PPN_BASIS_POINTS') === null
            || env('RENTAL_PPN_BASIS_POINTS') === ''
            ? null
            : (int) env('RENTAL_PPN_BASIS_POINTS'),
    ],

    // PR4: hadiah referral selalu ditanggung platform.
    'referral' => [
        'referee_discount_percent' => (float) env('REFERRAL_REFEREE_PERCENT', 10),
        'referee_discount_cap' => (float) env('REFERRAL_REFEREE_DISCOUNT_CAP', 25000),
        'referrer_reward' => (float) env('REFERRAL_REFERRER_REWARD', 25000),
        'referrer_points' => (int) env('REFERRAL_REFERRER_POINTS', 25),
    ],

    'loyalty' => [
        // 1 poin untuk setiap Rp 10.000 nilai transaksi lunas.
        'points_per_amount' => (int) env('LOYALTY_POINTS_PER_AMOUNT', 10000),
        'levels' => [
            ['code' => 'bronze', 'name' => 'Bronze', 'min_points' => 0],
            ['code' => 'silver', 'name' => 'Silver', 'min_points' => 100],
            ['code' => 'gold', 'name' => 'Gold', 'min_points' => 300],
            ['code' => 'platinum', 'name' => 'Platinum', 'min_points' => 1000],
        ],
    ],

    // Kanal peringatan kegagalan uang (Slack/Discord/webhook internal).
    'alerts' => [
        'webhook_url' => env('ALERT_WEBHOOK_URL'),
        'timeout' => (int) env('ALERT_TIMEOUT_SECONDS', 5),
    ],

];
