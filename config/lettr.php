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
    | Default Project ID
    |--------------------------------------------------------------------------
    |
    | The default project ID to use when listing or fetching templates.
    | This can be overridden by passing an explicit project ID to the
    | template service methods.
    |
    */

    'default_project_id' => env('LETTR_DEFAULT_PROJECT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Template Paths
    |--------------------------------------------------------------------------
    |
    | Configure where pulled templates and generated Mailables should be saved.
    | The blade_path is where template HTML files will be stored as Blade views.
    | The mailable_path and mailable_namespace are used when generating Mailable
    | classes with the --with-mailables option.
    |
    */

    'templates' => [
        'blade_path' => resource_path('views/emails/lettr'),
        'mailable_path' => app_path('Mail/Lettr'),
        'mailable_namespace' => 'App\\Mail\\Lettr',
        'dto_path' => app_path('Dto/Lettr'),
        'dto_namespace' => 'App\\Dto\\Lettr',
    ],

];
