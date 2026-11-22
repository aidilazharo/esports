<?php

declare(strict_types=1);

/**
 * PlayStation-only tournament feed.
 *
 * The feed reads the official PlayStation Malaysia tournaments page and caches
 * the parsed records. No PandaScore token or other API key is required.
 */

if (!defined('PLAYSTATION_TOURNAMENT_FEED_URL')) {
    define('PLAYSTATION_TOURNAMENT_FEED_URL', 'https://www.playstation.com/en-my/tournaments/');
}

if (!defined('TOURNAMENT_FEED_CACHE_SECONDS')) {
    define('TOURNAMENT_FEED_CACHE_SECONDS', 3600);
}

function tournamentFeedCachePath(): string
{
    if (defined('TOURNAMENT_FEED_CACHE_FILE')) {
        return (string) TOURNAMENT_FEED_CACHE_FILE;
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'malaysia_school_playstation_tournaments.json';
}

/**
 * @return array{body: string, error: ?string}
 */
function tournamentFeedHttpGet(string $url): array
{
    $body = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);

        if ($curl === false) {
            return ['body' => '', 'error' => 'Unable to initialise the PlayStation feed request.'];
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: en-MY,en;q=0.9',
                'User-Agent: Mozilla/5.0 Malaysia-School-Esports-Portal/1.0',
            ],
        ]);

        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            return [
                'body' => '',
                'error' => $curlError !== '' ? $curlError : 'PlayStation feed request failed.',
            ];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: en-MY,en;q=0.9',
                    'User-Agent: Mozilla/5.0 Malaysia-School-Esports-Portal/1.0',
                ]),
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if (isset($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)
        ) {
            $httpCode = (int) $matches[1];
        }

        if ($body === false) {
            return ['body' => '', 'error' => 'Unable to connect to the PlayStation tournament page.'];
        }
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'body' => '',
            'error' => 'PlayStation tournament page returned HTTP ' . $httpCode . '.',
        ];
    }

    return ['body' => (string) $body, 'error' => null];
}

function tournamentFeedText(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

function tournamentFeedAbsoluteUrl(string $href): string
{
    $href = trim($href);

    if ($href === '') {
        return (string) PLAYSTATION_TOURNAMENT_FEED_URL;
    }

    if (preg_match('#^https?://#i', $href) === 1) {
        return $href;
    }

    if (str_starts_with($href, '//')) {
        return 'https:' . $href;
    }

    if (str_starts_with($href, '/')) {
        return 'https://www.playstation.com' . $href;
    }

    return rtrim((string) PLAYSTATION_TOURNAMENT_FEED_URL, '/') . '/' . ltrim($href, '/');
}

/**
 * @return array<int, array{name: string, category: string}>
 */
function tournamentKnownGames(): array
{
    return [
        ['name' => 'EA SPORTS FC™ 26', 'category' => 'Sports'],
        ['name' => 'EA SPORTS™ Madden NFL 26', 'category' => 'Sports'],
        ['name' => 'MLB® The Show™ 26', 'category' => 'Sports'],
        ['name' => 'EA SPORTS™ College Football 26', 'category' => 'Sports'],
        ['name' => 'TopSpin 2K25', 'category' => 'Sports'],
        ['name' => 'EA SPORTS™ UFC® 5', 'category' => 'Sports'],
        ['name' => 'NBA 2K26', 'category' => 'Sports'],
        ['name' => 'TEKKEN 8', 'category' => 'Fighting'],
        ['name' => 'Mortal Kombat 1', 'category' => 'Fighting'],
        ['name' => 'Fatal Fury: City of the Wolves', 'category' => 'Fighting'],
        ['name' => 'Demon Slayer -Kimetsu no Yaiba- The Hinokami Chronicles 2', 'category' => 'Fighting'],
        ['name' => 'The King of Fighters XV', 'category' => 'Fighting'],
        ['name' => 'NARAKA: BLADEPOINT', 'category' => 'Fighting'],
        ['name' => 'Guilty Gear -Strive-', 'category' => 'Fighting'],
        ['name' => 'Fortnite', 'category' => 'PlayStation Cup'],
        ['name' => 'Marvel Rivals', 'category' => 'PlayStation Cup'],
        ['name' => 'Asphalt Legends', 'category' => 'Racing'],
    ];
}

function tournamentDetectGame(string $text): string
{
    foreach (tournamentKnownGames() as $game) {
        $name = $game['name'];
        $plainName = str_replace(['™', '®'], '', $name);
        $plainText = str_replace(['™', '®'], '', $text);

        if (stripos($plainText, $plainName) !== false) {
            return $name;
        }
    }

    return '';
}

function tournamentCategoryForGame(string $gameName): string
{
    foreach (tournamentKnownGames() as $game) {
        if (strcasecmp(str_replace(['™', '®'], '', $game['name']), str_replace(['™', '®'], '', $gameName)) === 0) {
            return $game['category'];
        }
    }

    return 'Special Event';
}

/**
 * @return array<string, mixed>
 */
function tournamentMakeItem(
    string $name,
    string $game,
    string $category,
    string $schedule,
    string $description,
    string $url,
    string $status = 'check_ps5'
): array {
    $name = tournamentFeedText($name);
    $game = tournamentFeedText($game);
    $category = tournamentFeedText($category);
    $schedule = tournamentFeedText($schedule);
    $description = tournamentFeedText($description);
    $url = tournamentFeedAbsoluteUrl($url);

    if ($name === '') {
        $name = $game !== '' ? 'PlayStation Tournaments — ' . $game : 'PlayStation Tournament';
    }

    $searchText = strtolower(implode(' ', [
        $name,
        $game,
        $category,
        $schedule,
        $description,
        'playstation ps5 malaysia',
    ]));

    return [
        'id' => sha1($name . '|' . $game . '|' . $schedule),
        'name' => $name,
        'game' => $game !== '' ? $game : 'Multiple Games',
        'category' => $category !== '' ? $category : 'Special Event',
        'schedule' => $schedule !== '' ? $schedule : 'Check schedule on PS5',
        'description' => $description,
        'status' => $status,
        'platform' => 'PlayStation 5',
        'region' => 'Malaysia / participating regions',
        'official_url' => $url,
        'source' => 'Official PlayStation Malaysia',
        'search_text' => $searchText,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function tournamentFallbackItems(): array
{
    $items = [];

    $items[] = tournamentMakeItem(
        'Compete in EA SPORTS FC™ 26 PlayStation® Tournaments',
        'EA SPORTS FC™ 26',
        'Sports',
        'Daily Tournaments',
        'Kick-Off and Ultimate Team competitions are available through PlayStation Tournaments. Check your PS5 tournament card for local times, rewards and eligibility.',
        (string) PLAYSTATION_TOURNAMENT_FEED_URL,
        'daily'
    );

    $items[] = tournamentMakeItem(
        'Step on the court in NBA 2K26 PlayStation® Tournaments',
        'NBA 2K26',
        'Sports',
        'Daily Tournaments',
        'Daily online competitions are available through PlayStation Tournaments. Check your PS5 tournament card for local times, rewards and eligibility.',
        (string) PLAYSTATION_TOURNAMENT_FEED_URL,
        'daily'
    );

    foreach (tournamentKnownGames() as $game) {
        if (in_array($game['name'], ['EA SPORTS FC™ 26', 'NBA 2K26'], true)) {
            continue;
        }

        $items[] = tournamentMakeItem(
            'PlayStation Tournaments — ' . $game['name'],
            $game['name'],
            $game['category'],
            'Check schedule on PS5',
            'Open the tournament card from the game hub or PS5 control centre to view available dates, formats and rewards in your region.',
            (string) PLAYSTATION_TOURNAMENT_FEED_URL,
            'check_ps5'
        );
    }

    return $items;
}

/**
 * @return array<int, array<string, mixed>>
 */
function parsePlayStationTournamentPage(string $html): array
{
    if ($html === '' || !class_exists('DOMDocument')) {
        return [];
    }

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return [];
    }

    $xpath = new DOMXPath($dom);
    $itemsByKey = [];

    // Featured PlayStation tournament headings and their nearby schedule/description.
    $headings = $xpath->query('//h2 | //h3');

    if ($headings !== false) {
        foreach ($headings as $heading) {
            $title = tournamentFeedText($heading->textContent ?? '');
            $lowerTitle = strtolower($title);

            if ($title === ''
                || str_contains($lowerTitle, 'tournaments games')
                || str_contains($lowerTitle, 'tournaments rules')
                || in_array($lowerTitle, ['sports', 'fighting', 'playstation cup', 'racing'], true)
            ) {
                continue;
            }

            $looksLikeTournament = str_contains($lowerTitle, 'tournament')
                || str_contains($lowerTitle, 'challenge')
                || str_contains($lowerTitle, 'warm-up')
                || str_contains($lowerTitle, 'warm up')
                || str_contains($lowerTitle, 'road to');

            if (!$looksLikeTournament) {
                continue;
            }

            $schedule = '';
            $descriptionParts = [];
            $node = $heading->nextSibling;
            $steps = 0;

            while ($node !== null && $steps < 18) {
                $steps++;

                if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['h2', 'h3'], true)) {
                    break;
                }

                if ($node instanceof DOMElement) {
                    $nodeText = tournamentFeedText($node->textContent ?? '');

                    if ($nodeText !== '') {
                        if ($schedule === '' && in_array(strtolower($node->tagName), ['ul', 'ol'], true)) {
                            $schedule = $nodeText;
                        } elseif (strtolower($node->tagName) === 'p' && count($descriptionParts) < 2) {
                            $descriptionParts[] = $nodeText;
                        }
                    }
                }

                $node = $node->nextSibling;
            }

            $game = tournamentDetectGame($title . ' ' . implode(' ', $descriptionParts));
            $category = $game !== '' ? tournamentCategoryForGame($game) : 'Special Event';
            $status = stripos($schedule, 'daily') !== false ? 'daily' : 'featured';
            $item = tournamentMakeItem(
                $title,
                $game,
                $category,
                $schedule,
                implode(' ', $descriptionParts),
                (string) PLAYSTATION_TOURNAMENT_FEED_URL,
                $status
            );

            $key = strtolower(str_replace(['™', '®', ' '], '', $item['name']));
            $itemsByKey[$key] = $item;
        }
    }

    // Competitive game catalogue from the official page.
    $anchors = $xpath->query('//a[@href]');

    if ($anchors !== false) {
        foreach ($anchors as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }

            $text = tournamentFeedText($anchor->textContent ?? '');
            $game = tournamentDetectGame($text);

            if ($game === '') {
                continue;
            }

            $category = tournamentCategoryForGame($game);
            $href = tournamentFeedAbsoluteUrl($anchor->getAttribute('href'));
            $item = tournamentMakeItem(
                'PlayStation Tournaments — ' . $game,
                $game,
                $category,
                'Check schedule on PS5',
                'Open the tournament card from the game hub or PS5 control centre to view available dates, formats and rewards in your region.',
                $href,
                'check_ps5'
            );

            $gameKey = 'game:' . strtolower(str_replace(['™', '®', ' '], '', $game));

            if (!isset($itemsByKey[$gameKey])) {
                $itemsByKey[$gameKey] = $item;
            }
        }
    }

    return array_values($itemsByKey);
}

/**
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function tournamentDeduplicate(array $items): array
{
    $result = [];
    $seenNames = [];

    foreach ($items as $item) {
        $nameKey = strtolower(str_replace(['™', '®', ' ', '—', '-'], '', (string) ($item['name'] ?? '')));

        if ($nameKey === '' || isset($seenNames[$nameKey])) {
            continue;
        }

        $seenNames[$nameKey] = true;
        $result[] = $item;
    }

    usort($result, static function (array $a, array $b): int {
        $statusOrder = ['featured' => 0, 'daily' => 1, 'check_ps5' => 2];
        $statusComparison = ($statusOrder[$a['status']] ?? 9) <=> ($statusOrder[$b['status']] ?? 9);

        if ($statusComparison !== 0) {
            return $statusComparison;
        }

        $aGame = (string) ($a['game'] ?? '');
        $bGame = (string) ($b['game'] ?? '');

        if (stripos($aGame, 'EA SPORTS FC') !== false && stripos($bGame, 'EA SPORTS FC') === false) {
            return -1;
        }

        if (stripos($bGame, 'EA SPORTS FC') !== false && stripos($aGame, 'EA SPORTS FC') === false) {
            return 1;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $result;
}

/**
 * @return array{items: array<int, array<string, mixed>>, error: ?string, updated_at: ?string, cached: bool}
 */
function fetchTournamentFeed(bool $forceRefresh = false): array
{
    $cachePath = tournamentFeedCachePath();
    $cacheSeconds = max(300, (int) TOURNAMENT_FEED_CACHE_SECONDS);

    if (!$forceRefresh && is_file($cachePath)) {
        $cacheAge = time() - (int) filemtime($cachePath);

        if ($cacheAge >= 0 && $cacheAge < $cacheSeconds) {
            $cached = json_decode((string) @file_get_contents($cachePath), true);

            if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
                return [
                    'items' => array_values($cached['items']),
                    'error' => !empty($cached['error']) ? (string) $cached['error'] : null,
                    'updated_at' => isset($cached['updated_at']) ? (string) $cached['updated_at'] : null,
                    'cached' => true,
                ];
            }
        }
    }

    $response = tournamentFeedHttpGet((string) PLAYSTATION_TOURNAMENT_FEED_URL);
    $items = [];
    $error = $response['error'];

    if ($response['error'] === null) {
        $items = parsePlayStationTournamentPage($response['body']);

        if ($items === []) {
            $error = 'The official page was reached, but its tournament layout could not be parsed. Showing the built-in PlayStation list.';
        }
    }

    if ($items === []) {
        $items = tournamentFallbackItems();
    } else {
        // Ensure the page still has enough records if a layout change hides some catalogue links.
        $items = array_merge($items, tournamentFallbackItems());
    }

    $result = [
        'items' => tournamentDeduplicate($items),
        'error' => $error,
        'updated_at' => gmdate('c'),
        'cached' => false,
    ];

    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded !== false) {
        @file_put_contents($cachePath, $encoded, LOCK_EX);
    }

    return $result;
}

/**
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function featuredTournaments(array $items, int $limit = 2): array
{
    usort($items, static function (array $a, array $b): int {
        $aGame = (string) ($a['game'] ?? '');
        $bGame = (string) ($b['game'] ?? '');

        $aScore = 0;
        $bScore = 0;

        if (stripos($aGame, 'EA SPORTS FC') !== false) {
            $aScore += 100;
        }
        if (stripos($bGame, 'EA SPORTS FC') !== false) {
            $bScore += 100;
        }
        if (($a['status'] ?? '') === 'featured') {
            $aScore += 50;
        }
        if (($b['status'] ?? '') === 'featured') {
            $bScore += 50;
        }
        if (($a['status'] ?? '') === 'daily') {
            $aScore += 30;
        }
        if (($b['status'] ?? '') === 'daily') {
            $bScore += 30;
        }

        return $bScore <=> $aScore;
    });

    return array_slice($items, 0, max(1, $limit));
}

/**
 * @param array<string, mixed> $item
 */
function tournamentDateLabel(array $item): string
{
    return tournamentFeedText($item['schedule'] ?? 'Check schedule on PS5');
}

function tournamentStatusLabel(string $status): string
{
    return match ($status) {
        'daily' => 'Daily',
        'featured' => 'Featured Event',
        default => 'Check on PS5',
    };
}

function tournamentStatusBadge(string $status): string
{
    return match ($status) {
        'daily' => 'success',
        'featured' => 'primary',
        default => 'secondary',
    };
}
