<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Apple Sign-In Defaults
    |--------------------------------------------------------------------------
    |
    | Scopes requested when no explicit scopes are passed to appleSignIn().
    | Apple only returns the user's name and email on their FIRST
    | authorization with your app — subsequent sign-ins only return the
    | stable user identifier and identity token.
    |
    */
    'apple' => [
        'default_scopes' => ['fullName', 'email'],
    ],

];
