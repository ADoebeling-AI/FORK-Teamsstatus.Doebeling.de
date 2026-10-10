<?php
// Copy this file to config.php and fill in your values. Never commit config.php.
return [
    // Microsoft Entra ID (formerly Azure AD): Directory (tenant) ID
    'tenant_id' => '00000000-0000-0000-0000-000000000000',

    // App registration with the Microsoft Graph application permission Presence.Read.All
    'client_id' => '00000000-0000-0000-0000-000000000000',
    'client_secret' => '',

    // Allowlist: only these users get a status badge. E-mail address => Entra object ID
    'users' => [
        'name@example.com' => '00000000-0000-0000-0000-000000000000',
    ],

    // Seconds a status is cached. Graph allows 1,500 presence requests per 30 seconds per app and tenant.
    'cache_ttl' => 30,
];
