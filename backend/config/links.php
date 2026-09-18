<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Destination Encryption
    |--------------------------------------------------------------------------
    |
    | Configuration for encrypting destination URLs stored in the database.
    | keyring: JSON map of key_id => base64-encoded 32-byte key.
    | active_key_id: the key used for new encryptions.
    | self_hosts: the product's own hosts (SHORT_HOST and the host of APP_URL),
    | lowercased, port-free and deduplicated. A destination URL targeting one of
    | these hosts (or a subdomain of one) is rejected to prevent a redirect loop.
    |
    */

    'destination' => [
        'keyring' => env('LINKS_DESTINATION_KEYRING', '{}'),
        'active_key_id' => env('LINKS_DESTINATION_ACTIVE_KEY_ID', ''),
        'self_hosts' => array_values(array_unique(array_filter([
            strtolower((string) env('SHORT_HOST', '')),
            strtolower((string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST)),
        ]))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slug policy
    |--------------------------------------------------------------------------
    |
    | Parameters for automatic slug generation and custom alias validation.
    | length/alphabet: shape of a generated Base36 slug.
    | min_alias_length/max_alias_length: inclusive bounds for a custom alias
    | after normalization.
    | max_collision_attempts: how many failed INSERTs (primary-key collisions)
    | a generated slug may hit before ReserveSlug gives up.
    | max_denylist_discards: how many consecutive reserved candidates the
    | generator may discard before giving up (a separate budget from
    | collisions).
    | reserved_words: exact-match denylist applied, after normalization, to
    | both custom aliases and generated candidates.
    |
    */

    'slug' => [
        'length' => 8,
        'alphabet' => 'abcdefghijklmnopqrstuvwxyz0123456789',
        'min_alias_length' => 3,
        'max_alias_length' => 48,
        'max_collision_attempts' => 5,
        'max_denylist_discards' => 5,
        'reserved_words' => [
            'admin',
            'api',
            'login',
            'register',
            'docs',
            'health',
            'status',
            'support',
            'terms',
            'privacy',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Short URL base
    |--------------------------------------------------------------------------
    |
    | Absolute origin used to build public short_url values ({base}/{slug}).
    | Never derive this from the inbound request host — app host ≠ short host.
    |
    */

    'short_url' => [
        'base_url' => env('SHORT_URL_BASE') ?: ('https://'.env('SHORT_HOST', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'create' => [
            'max_attempts' => 60,
            'decay_seconds' => 60,
        ],
    ],

    'rate_limit_hmac_key' => env('LINKS_RATE_LIMIT_HMAC_KEY'),

    'etag_hmac_key' => env('LINKS_ETAG_HMAC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Exclusive crypto material for create-link idempotency. HMAC secrets are
    | purpose-distinct (key hash vs command fingerprint). The snapshot keyring
    | must never share keys with links.destination.
    |
    */

    'idempotency' => [
        'key_hash_hmac_key' => env('LINKS_IDEMPOTENCY_KEY_HASH_HMAC_KEY'),
        'fingerprint_hmac_key' => env('LINKS_IDEMPOTENCY_FINGERPRINT_HMAC_KEY'),
        'keyring' => env('LINKS_IDEMPOTENCY_KEYRING', '{}'),
        'active_key_id' => env('LINKS_IDEMPOTENCY_ACTIVE_KEY_ID', ''),
    ],

];
