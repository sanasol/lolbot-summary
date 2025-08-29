<?php

namespace App\Http\Controllers;

use App\Services\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class PrivateShowsAnalysisController extends Controller
{
    /**
     * Display the private shows analysis page
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $site = 'statbate'; // Default to Chaturbate
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();

        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
        ];

        if ($request->has('timezone')) {
            $requestedTz = $request->get('timezone');
            try {
                // Check if timezone is an array with a 'value' key
                if (is_array($requestedTz) && isset($requestedTz['value'])) {
                    $requestedTz = $requestedTz['value'];
                }
                
                // Validate timezone by attempting to create a DateTimeZone object
                new \DateTimeZone($requestedTz);
                $targetTz = $requestedTz;
                $user->set('timezone', $targetTz);
            } catch (\Exception $e) {
                // If invalid timezone, keep using the default
            }
        }

        if ($request->has('site')) {
            $site = Utils::siteToDbName($request->get('site'));
        }

        $genderFilter = '';
        if ($request->has('selectedGenders')) {
            $selectedGenders = $request->get('selectedGenders');
            $allowed = [0, 1, 2, 3];
            $selectedGenders = array_map('intval', $selectedGenders);
            $gendersList = [];
            foreach ($selectedGenders as $gender) {
                if (!in_array($gender, $allowed, true)) {
                    continue;
                }
                $gendersList[] = $gender;
            }

            if (count($gendersList) > 0) {
                $genderFilter = ' AND rooms.gender IN (';
                foreach ($gendersList as $gender) {
                    $genderFilter .= "$gender,";
                }
                $genderFilter = rtrim($genderFilter, ',');
                $genderFilter .= ')';

                $data['selectedGenders'] = $gendersList;
            }
        }

        $tagFilter = '';
        if ($request->has('selectedTags')) {
            $selectedTags = $request->get('selectedTags');
            if (is_array($selectedTags) && count($selectedTags) > 0) {
                $tagsList = [];
                foreach ($selectedTags as $tag) {
                    $tagsList[] = $tag;
                }

                if (count($tagsList) > 0) {
                    // Create a CTE to filter rooms by tags
                    // Create a tag filter using a JOIN condition
                $tagFilter = '';
                $data['selectedTags'] = $selectedTags;
                $data['tagsList'] = $tagsList; // Store for use in the queries
                }
            }
        }

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date, 'datehis' => $datehis) = Utils::dateFilter($request, $user, $targetTz);

        $data['range'] = [
            $_start,
            $_end,
        ];

        // Get average price directly from the database
        $avgPriceData = Cache::remember('avg_private_price_'.$site, 60 * 30, function () use ($site) {
            try {
                $result = app(\ClickHouseDB\Client::class)->select(
                    'WITH latest_settings AS (
                        SELECT
                            room_id,
                            private_show_price,
                            ROW_NUMBER() OVER (PARTITION BY room_id ORDER BY recorded_at DESC) as rn
                        FROM '.$site.'.room_settings
                    ),
                    valid_settings AS (
                        SELECT
                            room_id,
                            private_show_price
                        FROM latest_settings
                        WHERE rn = 1 AND private_show_price > 0
                    )
                    SELECT
                        AVG(private_show_price) as avg_price,
                        COUNT() as room_count
                    FROM valid_settings'
                )->rows();

                return $result[0] ?? ['avg_price' => 0, 'room_count' => 0];
            } catch (\Exception $e) {
                // Return default values if query fails
                return ['avg_price' => 0, 'room_count' => 0];
            }
        });

        // If no rooms with settings, return empty data
        if ($avgPriceData['room_count'] == 0) {
            $data['site'] = Utils::dbToSiteName($site);
            $data['globalStats'] = [
                'total_rooms' => 0,
                // 'total_minutes' => 0, // Hidden as requested
                'total_estimated_earnings' => 0,
                'avg_minutes_per_room' => 0,
                'avg_earnings_per_room' => 0,
                'avg_price' => 0,
                'total_shows' => 0,
            ];

            // Empty chart structure
            $data['chartData'] = [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Total Private Time (minutes)',
                        'data' => [],
                        'yAxisID' => 'y',
                    ],
                    [
                        'label' => 'Estimated Earnings ($)',
                        'data' => [],
                        'yAxisID' => 'y1',
                    ],
                    [
                        'label' => 'Avg. Time per Room (minutes)',
                        'data' => [],
                        'yAxisID' => 'y2',
                    ],
                    [
                        'label' => 'Total Private Shows',
                        'data' => [],
                        'yAxisID' => 'y3',
                    ]
                ]
            ];

            return Inertia::render('PrivateShowsAnalysis', [
                'data' => $data,
                'rooms' => [],
            ]);
        }

        // Calculate global statistics using a separate query with CTE
        $globalStats = Cache::remember('private_shows_global_stats_'.md5($date.$genderFilter.json_encode($data['tagsList'] ?? [])).'_'.$site, 60 * 5, function () use ($date, $site, $genderFilter, $data) {
            try {
                // Build the query with proper tag filtering
                $query = 'WITH valid_rooms AS (
                    SELECT
                        rooms.id as room_id
                    FROM '.$site.'.rooms
                    JOIN (
                        SELECT
                            room_id,
                            private_show_price
                        FROM '.$site.'.room_settings
                        WHERE private_show_price > 0
                        ORDER BY recorded_at DESC
                    ) AS settings ON rooms.id = settings.room_id
                )';

                // Add tag filtering CTE if needed
                if (!empty($data['tagsList'])) {
                    $query .= ',
                    rooms_with_tags AS (
                        SELECT DISTINCT
                            rooms.id as room_id
                        FROM '.$site.'.rooms
                        JOIN '.$site.'.tags ON rooms.name = tags.username
                        WHERE tags.tag IN (\''.implode('\',\'', $data['tagsList']).'\')
                    )';
                }

                $query .= '
                SELECT
                    COUNT(DISTINCT rooms.id) as total_rooms,
                    SUM(duration_minutes) as total_minutes,
                    COUNT() as total_shows,
                    AVG(duration_minutes) as avg_minutes_per_session
                FROM '.$site.'.sessions_grouped
                JOIN '.$site.'.rooms ON sessions_grouped.room_id = rooms.id
                JOIN valid_rooms ON rooms.id = valid_rooms.room_id';

                // Add tag join if needed
                if (!empty($data['tagsList'])) {
                    $query .= '
                    JOIN rooms_with_tags ON rooms.id = rooms_with_tags.room_id';
                }

                $query .= '
                WHERE '.str_replace('time', 'start_date', $date).' '.$genderFilter;

                $result = app(\ClickHouseDB\Client::class)->select($query
                )->rows();

                return $result[0] ?? [
                    'total_rooms' => 0,
                    'total_minutes' => 0,
                    'total_shows' => 0,
                    'avg_minutes_per_session' => 0
                ];
            } catch (\Exception $e) {
                // Return empty stats if query fails
                return [
                    'total_rooms' => 0,
                    'total_minutes' => 0,
                    'total_shows' => 0,
                    'avg_minutes_per_session' => 0
                ];
            }
        });

        // Use the average price from the database query
        $avgPrice = round($avgPriceData['avg_price'], 2);

        // Calculate total estimated earnings
        $totalEarningsWithSettings = $globalStats['total_minutes'] * $avgPrice;

        // Get table data (top rooms by earnings) using CTE
        $tableData = Cache::remember('private_shows_table_data_'.md5($date.$genderFilter.json_encode($data['tagsList'] ?? [])).'_'.$site, 60 * 5, function () use ($date, $site, $genderFilter, $data) {
            try {
                // Build the query with proper tag filtering
                $query = 'WITH room_settings AS (
                    SELECT
                        room_id,
                        private_show_price,
                        spy_private_show_price,
                        ROW_NUMBER() OVER (PARTITION BY room_id ORDER BY recorded_at DESC) as rn
                    FROM '.$site.'.room_settings
                ),
                valid_settings AS (
                    SELECT
                        room_id,
                        private_show_price,
                        spy_private_show_price
                    FROM room_settings
                    WHERE rn = 1 AND private_show_price > 0
                )';

                // Add tag filtering CTE if needed
                if (!empty($data['tagsList'])) {
                    $query .= ',
                    rooms_with_tags AS (
                        SELECT DISTINCT
                            rooms.id as room_id
                        FROM '.$site.'.rooms
                        JOIN '.$site.'.tags ON rooms.name = tags.username
                        WHERE tags.tag IN (\''.implode('\',\'', $data['tagsList']).'\')
                    )';
                }

                $query .= ',
                room_stats AS (
                    SELECT
                        rooms.id as room_id,
                        rooms.name as room_name,
                        rooms.gender as gender,
                        COUNT() as private_count,
                        SUM(duration_minutes) as total_minutes,
                        AVG(duration_minutes) as avg_minutes_per_session,
                        valid_settings.private_show_price,
                        valid_settings.spy_private_show_price
                    FROM '.$site.'.sessions_grouped
                    JOIN '.$site.'.rooms ON sessions_grouped.room_id = rooms.id
                    JOIN valid_settings ON rooms.id = valid_settings.room_id';

                // Add tag join if needed
                if (!empty($data['tagsList'])) {
                    $query .= '
                    JOIN rooms_with_tags ON rooms.id = rooms_with_tags.room_id';
                }

                $query .= '
                    WHERE '.str_replace('time', 'start_date', $date).' '.$genderFilter.'
                    GROUP BY
                        rooms.id,
                        rooms.name,
                        rooms.gender,
                        valid_settings.private_show_price,
                        valid_settings.spy_private_show_price
                )
                SELECT
                    room_id,
                    room_name,
                    gender,
                    private_count,
                    total_minutes,
                    avg_minutes_per_session,
                    private_show_price,
                    spy_private_show_price,
                    total_minutes * private_show_price as estimated_earnings
                FROM room_stats
                ORDER BY estimated_earnings DESC
                LIMIT 200';

                $roomsData = app(\ClickHouseDB\Client::class)->select($query)->rows();

                // No need to sort as the query already sorts by estimated_earnings
                // No need to limit as the query already limits to 200 rooms
                return $roomsData;
            } catch (\Exception $e) {
                dump($e);
                // Return empty array if database doesn't exist or query fails
                return [];
            }
        });

        // Process table data for display
        $processedData = [];
        foreach ($tableData as $show) {
            $roomId = $show['room_id'];
            $totalMinutes = $show['total_minutes'];
            $privateCount = $show['private_count'];
            $privatePrice = $show['private_show_price'];
            $spyPrice = $show['spy_private_show_price'];
            $estimatedEarnings = $show['estimated_earnings'];
            $earningsPerMinute = ($totalMinutes > 0) ? ($estimatedEarnings / $totalMinutes) : 0;

            $processedData[] = [
                'room_id' => $roomId,
                'name' => $show['room_name'],
                'gender' => $show['gender'],
                'private_count' => $privateCount,
                'total_minutes' => $totalMinutes,
                'avg_minutes_per_show' => round($show['avg_minutes_per_session'], 2),
                'private_price' => $privatePrice,
                'spy_price' => $spyPrice,
                'estimated_earnings' => Utils::toUSD($estimatedEarnings, $site),
                'earnings_per_minute' => Utils::toUSD($earningsPerMinute, $site),
                'isFavorite' => $user->favorites()->model($roomId)->site($site)->exists(),
            ];
        }

        // Get daily data for chart
        $dailyData = Cache::remember('private_shows_daily_data_'.md5($date.$genderFilter.json_encode($data['tagsList'] ?? [])).'_'.$site, 60 * 5, function () use ($date, $site, $genderFilter, $data, $avgPrice) {
            try {
                // Build the query with proper tag filtering
                $query = 'WITH valid_rooms AS (
                    SELECT
                        rooms.id as room_id
                    FROM '.$site.'.rooms
                    JOIN (
                        SELECT
                            room_id,
                            private_show_price
                        FROM '.$site.'.room_settings
                        WHERE private_show_price > 0
                        ORDER BY recorded_at DESC
                    ) AS settings ON rooms.id = settings.room_id
                )';

                // Add tag filtering CTE if needed
                if (!empty($data['tagsList'])) {
                    $query .= ',
                    rooms_with_tags AS (
                        SELECT DISTINCT
                            rooms.id as room_id
                        FROM '.$site.'.rooms
                        JOIN '.$site.'.tags ON rooms.name = tags.username
                        WHERE tags.tag IN (\''.implode('\',\'', $data['tagsList']).'\')
                    )';
                }

                $query .= ',
                daily_stats AS (
                    SELECT
                        toDate(start_date) as day,
                        COUNT(DISTINCT rooms.id) as daily_rooms,
                        SUM(duration_minutes) as daily_minutes,
                        COUNT() as daily_shows
                    FROM '.$site.'.sessions_grouped
                    JOIN '.$site.'.rooms ON sessions_grouped.room_id = rooms.id
                    JOIN valid_rooms ON rooms.id = valid_rooms.room_id';

                // Add tag join if needed
                if (!empty($data['tagsList'])) {
                    $query .= '
                    JOIN rooms_with_tags ON rooms.id = rooms_with_tags.room_id';
                }

                $query .= '
                    WHERE '.str_replace('time', 'start_date', $date).' '.$genderFilter.'
                    GROUP BY day
                    ORDER BY day
                )
                SELECT
                    day,
                    daily_rooms,
                    daily_minutes,
                    daily_shows,
                    daily_minutes / daily_rooms as avg_minutes_per_room,
                    daily_minutes * '.$avgPrice.' as estimated_earnings
                FROM daily_stats';

                $result = app(\ClickHouseDB\Client::class)->select($query
                )->rows();

                return $result;
            } catch (\Exception $e) {
                // Return empty array if query fails
                return [];
            }
        });

        // Format chart data
        $chartData = [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Total Private Time (minutes)',
                    'data' => [],
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Estimated Earnings ($)',
                    'data' => [],
                    'yAxisID' => 'y1',
                ],
                [
                    'label' => 'Avg. Time per Room (minutes)',
                    'data' => [],
                    'yAxisID' => 'y2',
                ],
                [
                    'label' => 'Total Private Shows',
                    'data' => [],
                    'yAxisID' => 'y3',
                ]
            ]
        ];

        foreach ($dailyData as $day) {
            $chartData['labels'][] = date('M d', strtotime($day['day']));
            $chartData['datasets'][0]['data'][] = (int)$day['daily_minutes'];
            // Format the earnings value as a number for the chart
            $chartData['datasets'][1]['data'][] = round(Utils::toUSD($day['estimated_earnings'], $site), 2);
            $chartData['datasets'][2]['data'][] = round($day['avg_minutes_per_room'], 2);
            $chartData['datasets'][3]['data'][] = (int)$day['daily_shows'];
        }

        // Finalize global stats (hide total_minutes)
        $statsData = [
            'total_rooms' => (int)$globalStats['total_rooms'],
            // 'total_minutes' => (int)$globalStats['total_minutes'], // Hidden as requested
            'total_shows' => (int)$globalStats['total_shows'],
            'avg_minutes_per_room' => $globalStats['total_rooms'] > 0 ? round($globalStats['total_minutes'] / $globalStats['total_rooms'], 2) : 0,
            'avg_earnings_per_room' => $globalStats['total_rooms'] > 0 ? Utils::toUSD($totalEarningsWithSettings / $globalStats['total_rooms'], $site) : Utils::toUSD(0, $site),
            'total_estimated_earnings' => Utils::toUSD($totalEarningsWithSettings, $site),
            'avg_price' => $avgPrice,
        ];

        $data['site'] = Utils::dbToSiteName($site);
        $data['globalStats'] = $statsData;
        $data['chartData'] = $chartData;

        return Inertia::render('PrivateShowsAnalysis', [
            'data' => $data,
            'rooms' => $processedData,
        ]);
    }
}
