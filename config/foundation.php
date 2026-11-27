<?php

/*
| LabMan's overrides of sunlitlabs/foundation defaults (the rest deep-merges from the
| package's config/foundation.php).
*/

return [

    // Until an admin exists every request goes to /setup; afterwards /setup is gone (404)
    // for good. Only the container health route is exempt.
    'setup' => [
        'except' => ['up'],
        'close_when_complete' => true,
        'cache_key' => 'labman.setup.complete',
    ],

    // The UI uses no inline scripts or styles, so a strict CSP is possible. A route may
    // set a stricter policy of its own (icons do); it is never replaced.
    'headers' => [
        'csp' => "default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    ],

];
