<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default telephone country code
    |--------------------------------------------------------------------------
    |
    | Used by phone normalization. Digits only, without a leading plus.
    | Changing this after numbers are stored requires a renormalization.
    |
    */

    'phone_default_country_code' => env('PHONE_DEFAULT_COUNTRY_CODE', '98'),

    /*
    |--------------------------------------------------------------------------
    | Connector token ability
    |--------------------------------------------------------------------------
    */

    'asterisk_ingest_ability' => 'asterisk:ingest',

    /*
    |--------------------------------------------------------------------------
    | Internal extension-to-extension calls
    |--------------------------------------------------------------------------
    |
    | When false, a caller whose number matches an extension is ignored.
    | Enable this locally so a desk phone can ring another desk phone
    | during testing. External customer calls are unchanged.
    |
    */

    'record_internal_calls' => (bool) env('RECORD_INTERNAL_CALLS', false),

];
