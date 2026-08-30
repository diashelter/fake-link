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
    |
    */

    'destination' => [
        'keyring' => env('LINKS_DESTINATION_KEYRING', '{}'),
        'active_key_id' => env('LINKS_DESTINATION_ACTIVE_KEY_ID', ''),
    ],

];
