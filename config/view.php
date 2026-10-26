<?php

return [
    'paths' => [resource_path('views')],
    'compiled' => env('VIEW_COMPILED_PATH', storage_path(env('APP_ENV') === 'testing' ? 'framework/views/testing' : 'framework/views')),
];
