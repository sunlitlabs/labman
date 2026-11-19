<?php

/*
| Password hashing. Argon2id (not bcrypt) because bcrypt silently ignores everything after the first 72 bytes,
| which matters for the long, symbol-heavy passwords a password manager generates. Accounts created with the
| old bcrypt hashes keep working (verify is off) and are upgraded to argon2id the next time they sign in.
*/
return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', false),
        'limit' => null,
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', false),
    ],

    'rehash_on_login' => true,

];
