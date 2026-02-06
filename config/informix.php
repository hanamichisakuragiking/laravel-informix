<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Informix Database Connection
    |--------------------------------------------------------------------------
    |
    | Add this to your config/database.php connections array.
    |
    */

    'informix' => [
        'driver' => 'informix',
        'host' => env('INFORMIX_HOST', 'localhost'),
        'service' => env('INFORMIX_SERVICE', '9088'),
        'database' => env('INFORMIX_DATABASE', 'forge'),
        'server' => env('INFORMIX_SERVER', 'informix'),
        'username' => env('INFORMIX_USERNAME', 'informix'),
        'password' => env('INFORMIX_PASSWORD', ''),
        'protocol' => env('INFORMIX_PROTOCOL', 'onsoctcp'),
        'db_locale' => env('INFORMIX_DB_LOCALE', 'en_US.utf8'),
        'client_locale' => env('INFORMIX_CLIENT_LOCALE', 'en_US.utf8'),
        'prefix' => '',
        'prefix_indexes' => true,
    ],
];
