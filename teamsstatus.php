<?php
/**
 * Teams status icon
 *
 * Always responds with an SVG icon. If the presence of the requested user can be
 * read from Microsoft Graph, a coloured badge in the bottom right corner shows
 * the status, like the presence badge in Microsoft Teams.
 *
 * Usage: teamsstatus.php?user=name@example.com&style=monochrom-outline
 *
 * @license MIT
 * @link    https://github.com/DOEBELING/Teamsstatus.Doebeling.de
 */

declare(strict_types=1);

ini_set('display_errors', '0');

const CONFIG_FILE = __DIR__ . '/config.php';
const CACHE_DIR = __DIR__ . '/.cache';
const DEFAULT_CACHE_TTL = 30;
const HTTP_TIMEOUT = 5;

/** The first style is the default. */
const STYLES = ['monochrom-outline', 'monochrom-filled', 'teams'];

/**
 * Logo on a 32 × 32 grid, drawn back to front. All shapes are centre lines of
 * a 1.5 wide stroke, so outline and filled styles have the same outer edge.
 */
const LAYERS = [
    'small' => '<circle cx="26.5" cy="7.5" r="2.25"/><path d="M23.5 12.75h6v4a3 3 0 0 1-6 0z"/>',
    'big' => '<circle cx="19" cy="6" r="3"/><path d="M13.75 12.75h11.5v5.5a5.75 5.75 0 0 1-11.5 0z"/>',
    'tile' => '<rect x="2.75" y="9.75" width="14.5" height="14.5" rx="2"/>',
];
/** The tile's cutout has a square bottom right corner, so the body behind it does not peek out there. */
const TILE_CUTOUT = LAYERS['tile'] . '<path d="M17.25 17v7.25H10" stroke-linejoin="miter"/>';
const LETTER = '<path d="M6.5 13.5h7M10 13.5v7"/>';
const TEAMS_COLORS = ['small' => '#5059C9', 'big' => '#7B83EB', 'tile' => '#4B53BC', 'letter' => '#FFFFFF'];

/** Presence badges: colour (Fluent UI), glyph and whether the badge is a ring instead of a dot. */
const BADGES = [
    'available' => ['label' => 'Available', 'color' => '#6BB700', 'glyph' => 'M23.6 26.1l1.6 1.6 3.2-3.4', 'ring' => false],
    'away' => ['label' => 'Away', 'color' => '#FFAA44', 'glyph' => 'M26 23.4V26l1.9 1.6', 'ring' => false],
    'busy' => ['label' => 'Busy', 'color' => '#C43148', 'glyph' => '', 'ring' => false],
    'dnd' => ['label' => 'Do not disturb', 'color' => '#C50F1F', 'glyph' => 'M23.4 26h5.2', 'ring' => false],
    'offline' => ['label' => 'Offline', 'color' => '#8A8886', 'glyph' => 'M24.2 24.2l3.6 3.6M27.8 24.2l-3.6 3.6', 'ring' => true],
    'oof' => ['label' => 'Out of office', 'color' => '#B4009E', 'glyph' => 'M28.1 26h-4.2M25.8 24.1 23.9 26l1.9 1.9', 'ring' => true],
];
const BADGE_CUTOUT = '<circle cx="26" cy="26" r="7.5"/>';

main();

function main(): void
{
    $style = in_array($_GET['style'] ?? null, STYLES, true) ? $_GET['style'] : STYLES[0];
    $color = is_string($_GET['color'] ?? null) && preg_match('/^[0-9a-f]{3}([0-9a-f]{3})?$/i', $_GET['color'])
        ? '#' . $_GET['color']
        : 'currentColor';
    $user = is_string($_GET['user'] ?? null) ? $_GET['user'] : '';

    $badge = null;
    try {
        $badge = find_badge($user);
    } catch (Throwable $e) {
        error_log('teamsstatus: ' . $e->getMessage());
    }

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    echo render_svg($style, $color, $badge);
}

/**
 * Returns the badge for the user, or null if the user is not in the allowlist
 * or the presence is unknown.
 */
function find_badge(string $user): ?string
{
    if ($user === '') {
        return null;
    }
    $config = load_config();
    $userId = $config['users'][strtolower(trim($user))] ?? null;
    if ($userId === null) {
        return null;
    }
    $presence = get_presence($config, $userId);
    return $presence === null ? null : badge_for($presence);
}

function load_config(): array
{
    if (!is_file(CONFIG_FILE)) {
        throw new RuntimeException('config.php is missing');
    }
    $config = require CONFIG_FILE;
    foreach (['tenant_id', 'client_id', 'client_secret'] as $key) {
        if (!is_string($config[$key] ?? null) || $config[$key] === '') {
            throw new RuntimeException("config.php: '$key' is missing");
        }
    }

    $users = [];
    foreach ($config['users'] ?? [] as $mail => $id) {
        if (!preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', (string) $id)) {
            throw new RuntimeException('config.php: user IDs must be Entra object IDs (GUIDs)');
        }
        $users[strtolower(trim((string) $mail))] = strtolower((string) $id);
    }
    $config['users'] = $users;
    return $config;
}

/** Reads the presence from Microsoft Graph, cached for a few seconds. Null if unknown. */
function get_presence(array $config, string $userId): ?array
{
    $cacheKey = 'presence-' . $userId;
    $cached = cache_get($cacheKey);
    if ($cached !== null) {
        return $cached['value'];
    }

    $presence = null;
    try {
        $response = http_request(
            'GET',
            'https://graph.microsoft.com/v1.0/users/' . $userId . '/presence',
            ['Authorization: Bearer ' . get_access_token($config)],
        );
        if ($response['status'] === 401) {
            cache_delete('token');
        }
        if ($response['status'] !== 200 || !is_array($response['body'])) {
            throw new RuntimeException('Graph presence request failed with HTTP ' . $response['status']);
        }
        $presence = $response['body'];
    } finally {
        // Errors are cached as well, so a broken setup does not flood Graph with requests.
        cache_set($cacheKey, $presence, (int) ($config['cache_ttl'] ?? DEFAULT_CACHE_TTL));
    }
    return $presence;
}

/** App-only token (client credentials flow), cached until shortly before it expires. */
function get_access_token(array $config): string
{
    $cached = cache_get('token');
    if ($cached !== null) {
        return $cached['value'];
    }

    $response = http_request(
        'POST',
        'https://login.microsoftonline.com/' . rawurlencode($config['tenant_id']) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]),
    );
    $token = $response['body']['access_token'] ?? null;
    if ($response['status'] !== 200 || !is_string($token)) {
        throw new RuntimeException('Token request failed with HTTP ' . $response['status']);
    }
    cache_set('token', $token, max(60, (int) ($response['body']['expires_in'] ?? 3600) - 300));
    return $token;
}

/** Maps a Graph presence to a badge, like Teams does. */
function badge_for(array $presence): ?string
{
    $availability = $presence['availability'] ?? '';
    $activity = $presence['activity'] ?? '';
    $outOfOffice = ($presence['outOfOfficeSettings']['isOutOfOffice'] ?? false) === true;

    if ($activity === 'OutOfOffice'
        || ($outOfOffice && in_array($availability, ['Away', 'BeRightBack', 'Offline', 'PresenceUnknown'], true))) {
        return 'oof';
    }
    return match ($availability) {
        'Available' => 'available',
        'AvailableIdle', 'Away', 'BeRightBack' => 'away',
        'Busy', 'BusyIdle' => 'busy',
        'DoNotDisturb' => 'dnd',
        'Offline' => 'offline',
        default => null,
    };
}

function render_svg(string $style, string $color, ?string $badge): string
{
    $colors = $style === 'teams' ? TEAMS_COLORS : array_fill_keys(['small', 'big', 'tile', 'letter'], $color);
    $filled = $style !== 'monochrom-outline';

    // Each layer is masked by everything in front of it, which leaves a small gap.
    $defs = '';
    $layers = '';
    $front = '';
    foreach (array_reverse(LAYERS) as $name => $shapes) {
        $cutout = '<g stroke="#000" stroke-width="3.5" stroke-linejoin="round">' . $front . '</g>'
            . ($badge === null ? '' : BADGE_CUTOUT);
        if ($name === 'tile' && $style === 'monochrom-filled') {
            $cutout .= '<g fill="none" stroke="#000" stroke-width="2.2">' . LETTER . '</g>';
        }
        $defs .= sprintf(
            '<mask id="ts-%s" maskUnits="userSpaceOnUse" x="0" y="0" width="32" height="32">'
            . '<rect width="32" height="32" fill="#fff"/>%s</mask>',
            $name,
            $cutout,
        );
        $layers = sprintf(
            '<g mask="url(#ts-%s)" fill="%s" stroke="%s" stroke-width="1.5" stroke-linejoin="round">%s</g>',
            $name,
            $filled ? $colors[$name] : 'none',
            $colors[$name],
            $shapes,
        ) . $layers;
        $front .= $name === 'tile' ? TILE_CUTOUT : $shapes;
    }
    if ($style !== 'monochrom-filled') {
        $layers .= sprintf('<g fill="none" stroke="%s" stroke-width="2.2">%s</g>', $colors['letter'], LETTER);
    }

    $title = 'Microsoft Teams' . ($badge === null ? '' : ': ' . BADGES[$badge]['label']);
    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" role="img">'
        . '<title>%s</title><defs>%s</defs>%s%s</svg>',
        $title,
        $defs,
        $layers,
        $badge === null ? '' : render_badge(BADGES[$badge]),
    );
}

function render_badge(array $badge): string
{
    $svg = $badge['ring']
        ? sprintf('<circle cx="26" cy="26" r="4.75" fill="none" stroke="%s" stroke-width="1.5"/>', $badge['color'])
        : sprintf('<circle cx="26" cy="26" r="5.5" fill="%s"/>', $badge['color']);
    if ($badge['glyph'] !== '') {
        $svg .= sprintf(
            '<path d="%s" fill="none" stroke="%s" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>',
            $badge['glyph'],
            $badge['ring'] ? $badge['color'] : '#FFFFFF',
        );
    }
    return $svg;
}

/** @return array{status: int, body: mixed} */
function http_request(string $method, string $url, array $headers, ?string $body = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    }
    return [
        'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'body' => json_decode($response, true),
    ];
}

/** @return array{value: mixed}|null Null if there is no fresh entry. */
function cache_get(string $key): ?array
{
    $data = @file_get_contents(CACHE_DIR . "/$key.json");
    $entry = $data === false ? null : json_decode($data, true);
    if (!is_array($entry) || ($entry['expires'] ?? 0) < time()) {
        return null;
    }
    return ['value' => $entry['value'] ?? null];
}

function cache_set(string $key, mixed $value, int $ttl): void
{
    if (!is_dir(CACHE_DIR) && !mkdir(CACHE_DIR, 0700) && !is_dir(CACHE_DIR)) {
        throw new RuntimeException('Cannot create cache directory');
    }
    // Write to a temporary file first, so parallel requests never read half a file.
    $file = CACHE_DIR . "/$key.json";
    $temp = tempnam(CACHE_DIR, 'tmp');
    file_put_contents($temp, json_encode(['expires' => time() + $ttl, 'value' => $value]));
    chmod($temp, 0600);
    rename($temp, $file);
}

function cache_delete(string $key): void
{
    @unlink(CACHE_DIR . "/$key.json");
}
