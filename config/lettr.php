<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lettr API Key
    |--------------------------------------------------------------------------
    |
    | Here you may specify your Lettr API key. This will be used to
    | authenticate with the Lettr API when sending emails.
    |
    */

    'api_key' => env('LETTR_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Idempotent Sends
    |--------------------------------------------------------------------------
    |
    | When enabled, emails sent from inside a queue job carry a generated
    | `Idempotency-Key`, so a job that is retried after a timeout cannot deliver
    | the same email twice. The key combines the job's uuid with a hash of the
    | payload: stable across retries of one send, different for every fresh
    | dispatch, and different for each email a job sends in a loop.
    |
    | Synchronous sends get no key - a retried HTTP request is a new process,
    | so there is nothing stable to derive one from. Pass a key explicitly with
    | `Mail::lettr()->idempotencyKey(...)` for those.
    |
    | Turn this off to restore the pre-2.6.0 behaviour everywhere at once.
    |
    */

    'idempotency' => [
        'enabled' => env('LETTR_IDEMPOTENCY_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Paths
    |--------------------------------------------------------------------------
    |
    | Configure where pulled templates and generated files should be saved.
    | The html_path is where template HTML files will be stored.
    | The mailable_path and mailable_namespace are used when generating Mailable
    | classes with the --with-mailables option.
    |
    */

    'templates' => [
        'html_path' => resource_path('templates/lettr'),
        'blade_path' => resource_path('views/emails/lettr'),
        'mailable_path' => app_path('Mail/Lettr'),
        'mailable_namespace' => 'App\\Mail\\Lettr',
        'dto_path' => app_path('Dto/Lettr'),
        'dto_namespace' => 'App\\Dto\\Lettr',
        'enum_path' => app_path('Enums'),
        'enum_namespace' => 'App\\Enums',
        'enum_class' => 'LettrTemplate',
    ],

];
