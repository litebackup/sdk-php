<?php

declare(strict_types=1);

// Litebackup's Laravel settings. Publish this file with
// php artisan vendor:publish --tag=litebackup-config.
return [
    // Whether queue:work, horizon:work, schedule:work, and Octane workers
    // register the backups when they start. Null means yes, except in the
    // testing environment, which is never backed up.
    'enabled' => null,

    // The database connections to back up, each with backups->start()
    // options: name, prefix, maximum_local_databases, offload_durability,
    // and offload_rollback_journal. php artisan litebackup:start registers
    // them; run it at every deploy.
    //
    //     'connections' => ['sqlite' => ['name' => 'app/main.sqlite']],
    'connections' => [],

    // The client's settings; null leaves each to LITEBACKUP_API_KEY,
    // LITEBACKUP_SESSION, the session's socket, and LITEBACKUP_PATH.
    'api_key' => null,
    'session' => null,
    'socket_path' => null,
    'binary_path' => null,
];
