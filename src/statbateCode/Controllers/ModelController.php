<?php

namespace App\Http\Controllers;

use App\Services\Utils;
use Cache;
use Carbon\Carbon;
use Inertia\Inertia;

class ModelController extends Controller
{
    public function index($site, $name)
    {
        $user = request()->user();
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();

        if (request()->has('timezone')) {
            $targetTz = request()->get('timezone');
            $user->set('timezone', $targetTz);
        }

        $site = Utils::siteToDbName($site);

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date) = Utils::dateFilter(request(), $user, $targetTz);

        $room = Cache::remember('model_id_query'.$site.$name, 60 * 5, static function () use ($date, $site, $name) {
            return app(\ClickHouseDB\Client::class)->select(
                'select * from '.$site.'.`rooms` where name = lower(:name)', ['name' => $name]
            )->rows();
        });

        if(!isset($room[0])) {
            return response('Room not found', 404);
        }

        $room = $room[0];

        $rows = Cache::remember('model_hourly_query1'.md5($date).'_'.$site.$room['id'], 60 * 5, static function () use ($date, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'select rid, avg(hourly_income) as hrinc, count() as hours, toDate(hour) as day from (SELECT
                                rid,
                                toStartOfHour(time) AS hour,
                                sum(token) AS hourly_income
                            FROM '.$site.'.stats_v2
                            WHERE '.$date.' and rid=:rid
                            GROUP BY rid, hour
                            ORDER BY hour) group by rid, day order by day   '
                , ['rid' => $room['id']])->rows();
        });

        $hourlyIncomeLabels = [];
        $hourlyIncomeDays = [];
        $hourlyIncomeHours = [];

        foreach ($rows as $row) {
            $hourlyIncomeLabels[] = $row['day'];
            $hourlyIncomeDays[$row['day']] = Utils::toUSD($row['hrinc'], $site);
            $hourlyIncomeHours[$row['day']] = $row['hours'];
        }

        $groupBy = 'day';
        $groupByTime = 'time';
        if (request()->has('group')) {
            $groupBy = request()->get('group');
            if ($groupBy === 'day') {
                $groupByTime = 'toStartOfDay(time)';
            } elseif ($groupBy === 'week') {
                $groupByTime = 'toStartOfWeek(time)';
            } elseif ($groupBy === 'month') {
                $groupByTime = 'toStartOfMonth(time)';
            }
        }

        $rows = Cache::remember('chart_query_'.md5($date.$groupByTime).'_'.$site.$room['id'], 60 * 5, static function () use ($date, $site, $room, $groupByTime) {
            return app(\ClickHouseDB\Client::class)->select(
                'SELECT rooms.gender, SUM(tokens) as total,'.$groupByTime.' as ndate FROM '.$site.'.`income_details` LEFT JOIN '.$site.'.`rooms` ON income_details.rid = rooms.id WHERE '.$date.' AND rid = :rid GROUP by `gender`, ndate ORDER BY ndate ASC'
                , ['rid' => $room['id']])->rows();
        });

        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
            'hourlyIncomeDays' => $hourlyIncomeDays,
            'hourlyIncomeLabels' => $hourlyIncomeLabels,
            'hourlyIncomeHours' => $hourlyIncomeHours,
            'range' => [
                $_start,
                $_end,
            ],
            'group' => $groupBy,
        ];
        $labels = [];
        $genders = [];

        foreach ($rows as $row) {
            $labels[] = $row['ndate'];
            $genders[$row['ndate']] = Utils::toUSD($row['total'], $site);
        }
        if (isset($genders[now()->format('Y-m-d')]) && now()->format('H') < 12) {
            unset($genders[now()->format('Y-m-d')]);
        }

        $rows = Cache::remember('models_query_'.md5($date).'_'.$site.$room['id'], 60 * 5, static function () use ($date, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'select donators.name, subtbl.* from (SELECT did, SUM(tokens) as total, count(DISTINCT time) as days
                        FROM '.$site.'.income_details
                        WHERE '.$date.' and rid = :rid
                        GROUP BY did
                        HAVING sum(tokens) / sum(donates) < 1000
                        ORDER BY total DESC
                        LIMIT 200) as subtbl left join '.$site.'.donators on donators.id = subtbl.did', ['rid' => $room['id']])->rows();
        });

        $topModels = [];
        foreach ($rows as $i => $row) {
            $topModels[] = [
                'position' => $i + 1,
                'name' => $row['name'],
                'did' => $row['did'],
                'isFavorite' => $user->favorites()->member($row['did'])->site($site)->exists(),
                'total' => Utils::toUSD($row['total'], $site),
                'daily' => Utils::toUSD($row['total'] / $row['days'], $site),
            ];
        }

        $data['labels'] = $labels;
        $data['genders'] = $genders;
        $data['site'] = Utils::dbToSiteName($site);
        $data['heatmap'] = [];

        // Fetch model rank data
        // Create a modified date filter that ensures we include the full first day
        $fullDayDate = str_replace(['time >=', 'time <'], ['toDate(time) >=', 'toDate(time) <'], $date);

        $ranks = Cache::remember('model_rank_query_'.md5($date).'_'.$site.$room['id'], 60 * 60 * 24, static function () use ($fullDayDate, $date, $start, $end, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'WITH date_series AS (
                    SELECT arrayJoin(arrayMap(x -> toDate(:start) + x, range(dateDiff(\'day\', toDate(:start), toDate(:end)) + 1))) as report_date
                ),
                -- 1. Find donors to exclude (based on the entire date range)
                ExcludedDonors AS (
                    SELECT did
                    FROM '.$site.'.income_details
                    WHERE '.$fullDayDate.'
                    GROUP BY did
                    -- Replace divideOrZero with if statement
                    HAVING if(sum(donates) = 0, 0, sum(tokens) / sum(donates)) > 20000
                ),
                -- 2. Find rooms to exclude (based on the entire date range, using non-excluded donors)
                EligibleRoomContributions AS (
                    SELECT rid, tokens, donates
                    FROM '.$site.'.income_details
                    WHERE '.$fullDayDate.'
                      AND did NOT IN (SELECT did FROM ExcludedDonors)
                ),
                ExcludedRooms AS (
                    SELECT rid
                    FROM EligibleRoomContributions
                    GROUP BY rid
                    -- Replace divideOrZero with if statement
                    -- Exclude if average token per donate is >= 1000
                    HAVING if(sum(donates) = 0, 0, sum(tokens) / sum(donates)) >= 1000
                ),
                -- 3. Calculate actual daily token sum for rooms/donors not excluded
                DailyTokens AS (
                    SELECT
                        toDate(time) as report_date,
                        rid,
                        sum(tokens) as daily_total
                    FROM '.$site.'.income_details
                    WHERE '.$fullDayDate.'
                      AND rid NOT IN (SELECT rid FROM ExcludedRooms)
                      AND did NOT IN (SELECT did FROM ExcludedDonors)
                    GROUP BY report_date, rid
                ),
                -- Get all unique rooms that are eligible and had tokens during the period
                EligibleRooms AS (
                    SELECT DISTINCT rid FROM DailyTokens
                ),
                -- Create a grid of all eligible rooms and all dates in the series
                -- This ensures every room has a row for every day, even if daily_total was 0
                DateRoomGrid AS (
                    SELECT
                        ds.report_date,
                        er.rid
                    FROM date_series ds
                    CROSS JOIN EligibleRooms er
                    -- Optional optimization: Limit date range to actual min/max dates with data
                    WHERE ds.report_date >= (SELECT min(report_date) FROM DailyTokens)
                      AND ds.report_date <= (SELECT max(report_date) FROM DailyTokens)
                ),
                -- Join grid with actual daily tokens, filling missing days with 0
                FilledDailyTokens AS (
                    SELECT
                        drg.report_date,
                        drg.rid,
                        COALESCE(dt.daily_total, 0) as daily_total
                    FROM DateRoomGrid drg
                    LEFT JOIN DailyTokens dt ON drg.report_date = dt.report_date AND drg.rid = dt.rid
                ),
                -- 4. Calculate cumulative token sum up to each day for each room
                CumulativeTokens AS (
                    SELECT
                        report_date,
                        rid,
                        sum(daily_total) OVER (PARTITION BY rid ORDER BY report_date ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as cumulative_total_up_to_day
                    FROM FilledDailyTokens
                ),
                -- 5. Rank rooms based on their cumulative sum for each specific day
                DailyRanks AS (
                    SELECT
                        report_date,
                        rid,
                        cumulative_total_up_to_day,
                        -- Rank rooms for each day based on the cumulative total up to that day
                        rank() OVER (PARTITION BY report_date ORDER BY cumulative_total_up_to_day DESC) as daily_overall_rank
                    FROM CumulativeTokens
                    -- Only rank rooms that have a positive cumulative total, mirroring the HAVING clause in the original PHP logic
                    WHERE cumulative_total_up_to_day > 0
                )
                -- 6. Select the daily rank specifically for the target room
                SELECT
                    report_date,
                    daily_overall_rank
                FROM DailyRanks
                WHERE rid = :rid
                ORDER BY report_date',
                [
                    'rid' => $room['id'],
                    'start' => $start,
                    'end' => $end
                ]
            )->rows();
        });

        $rankDates = [];
        $rankValues = [];

        // Skip the first data point to ensure we have complete data for all days
        $skipFirst = true;

        foreach ($ranks as $rank) {
            if ($skipFirst) {
                $skipFirst = false;
                continue; // Skip the first point
            }

            $rankDates[] = $rank['report_date'];
            $rankValues[] = $rank['daily_overall_rank'];
        }

        $data['rankDates'] = $rankDates;
        $data['rankValues'] = $rankValues;

        $rows = Cache::remember('23sheatmap_query_'.md5($date).'_'.$site.$room['id'], 1, static function () use ($date, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'SELECT toStartOfDay(toDateTime(`unix`)) as date, toStartOfHour(toDateTime(`unix`)) as hour, SUM(`token`) as sum
                        FROM '.$site.'.`stats_v2`
                        where '.$date.' AND rid = :rid
                        GROUP BY date, hour
                        ORDER BY date, hour ASC', ['rid' => $room['id']]
            )->rows();
        });

//        [
//            {
//                name: 'Metric1',
//                data: generateData(18, {
//                    min: 0,
//                    max: 90
//                })
//            },
//        ]
        $heatmap = [];
        foreach ($rows as $i => $row) {
            $date = Carbon::parse($row['hour'], 'UTC');
            if ($date === null) {
                continue;
            }

            $date = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d H:i:s'), 'UTC');
            $date->setTimezone($targetTz);
            $maingroup = $date->format('Y F');
            $group = $date->format('F j');
            if (!isset($heatmap[$maingroup])) {
                $heatmap[$maingroup] = [];
            }

            if (!isset($heatmap[$maingroup][$group])) {
                $heatmap[$maingroup][$group] = [
                    0 => 0,
                    1 => 0,
                    2 => 0,
                    3 => 0,
                    4 => 0,
                    5 => 0,
                    6 => 0,
                    7 => 0,
                    8 => 0,
                    9 => 0,
                    10 => 0,
                    11 => 0,
                    12 => 0,
                    13 => 0,
                    14 => 0,
                    15 => 0,
                    16 => 0,
                    17 => 0,
                    18 => 0,
                    19 => 0,
                    20 => 0,
                    21 => 0,
                    22 => 0,
                    23 => 0,
                ];
            }

            $heatmap[$maingroup][$group][(int)$date->format('H')] = Utils::toUSD($row['sum'], $site);
        }

        //
        foreach ($heatmap as $group => $days) {
            if (!isset($data['heatmap'][$group])) {
                $data['heatmap'][$group] = [];
            }

            foreach ($days as $day => $monthData) {
                ksort($monthData);
                $data['heatmap'][$group][] = [
                    'name' => $day,
                    'data' => array_values($monthData),
                ];
            }
        }


        $tags = [];
        if ($site === 'statbate' || $site === 'stripchat' || $site === 'bongacams' || $site === 'camsoda') {
            $tagsRows = Cache::remember(
                'tags_query_'.md5($date).'_'.$site.$room['id'],
                60 * 5,
                static function () use ($date, $site, $room) {
                    return app(\ClickHouseDB\Client::class)->select(
                        'SELECT tags.tag
    FROM '.$site.'.tags
    LEFT JOIN '.$site.'.rooms ON rooms.name = tags.username
    WHERE rooms.id = :rid
    GROUP BY tags.tag order by tags.tag asc',
                        ['rid' => $room['id']]
                    )->rows();
                }
            );

            foreach ($tagsRows as $tagRow) {
                if (str_contains($tagRow['tag'], '/')) {
                    $tagParts = explode('/', $tagRow['tag']);
                    $tags[$tagParts[1]] = [
                        'name' => $tagParts[1],
                        'type' => $tagParts[0],
                        'category' => Utils::getTagCategory($tagParts[1]),
                    ];
                } else {
                    $tags[$tagRow['tag']] = [
                        'name' => $tagRow['tag'],
                        'type' => '',
                        'category' => Utils::getTagCategory($tagRow['tag']),
                    ];
                }
            }
        }

        return Inertia::render('Model', [
            'room' => $room,
            'data' => $data,
            'tagsCategories' => Utils::tagsCategory(),
            'tags' => array_values($tags),
            'top' => $topModels,
            'isFavorite' => $user->favorites()->model($room['id'])->site($site)->exists(),
        ]);
    }

    public function sessions($site, $name)
    {
        $user = request()->user();
        $granularity = Utils::defaultGranularity();
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();
        $windows = Utils::granularityList();

        if (request()->has('timezone')) {
            $targetTz = request()->get('timezone');
            $user->set('timezone', $targetTz);
        }

        if (request()->has('window')) {
            $granularity = (int) request()->get('window');
            $user->set('window', $granularity);
        }

        $site = Utils::siteToDbName($site);

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date) = Utils::dateFilter(request(), $user, $targetTz);

        $room = Cache::remember('model_id_query'.$site.$name, 60 * 5, static function () use ($date, $site, $name) {
            return app(\ClickHouseDB\Client::class)->select(
                'select * from '.$site.'.`rooms` where name = lower(:name)', ['name' => $name]
            )->rows();
        });

        if(!isset($room[0])) {
            return response('Room not found', 404);
        }

        $room = $room[0];

        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
            'windows' => $windows,
            'window' => $granularity,
            'range' => [
                $_start,
                $_end,
            ],
        ];

        $rows = Cache::remember('models_sessions_query_'.md5($date).'_'.$site.$room['id'].$granularity, 60 * 5, static function () use (
            $start,
            $end,
            $granularity, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'WITH activity_periods AS (
    SELECT
        did,
        rid,
        time,
        token,
        toUnixTimestamp(time) AS unix_timestamp,
        if(unix_timestamp - lagInFrame(unix_timestamp) OVER (PARTITION BY rid ORDER BY time) <= '.($granularity * 3600).', 0, 1) AS new_group
    FROM '.$site.'.stats_v2
    WHERE rid = :rid and time >= :start and time <= :end
),
grouped_activity AS (
    SELECT
        did,
        rid,
        time,
        token,
        sum(new_group) OVER (PARTITION BY rid ORDER BY time) AS group_id
    FROM activity_periods
),
activity_windows AS (
    SELECT
        rid,
        group_id,
        min(time) AS start_time,
        max(time) AS end_time,
        sum(token) AS total_tokens,
        count(distinct did) AS dons,
        count() as tips
    FROM grouped_activity
    GROUP BY rid, group_id
)
SELECT
    rid,
    start_time,
    end_time,
    round((toUnixTimestamp(end_time) - toUnixTimestamp(start_time)) / 3600, 2) AS duration_hours,
    total_tokens,
    round(total_tokens / duration_hours)  as tokens_per_hour,
    tips,
    dons
FROM activity_windows
ORDER BY rid, start_time desc',
                [
                    'rid' => $room['id'],
                    'start' => $start,
                    'end' => $end,

                ])->rows();
        });

        $topModels = [];
        foreach ($rows as $i => $row) {
            $startTime = Carbon::parse($row['start_time'], 'UTC');
            if ($startTime === null) {
                continue;
            }

            $start_time = $startTime->format('Y-m-d\TH:i:s');
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $startTime->format('Y-m-d H:i:s'), 'UTC');
            $startTime->setTimezone($targetTz);
            $endTime = Carbon::parse($row['end_time'], 'UTC');
            if ($endTime === null) {
                continue;
            }

            $end_time = $endTime->format('Y-m-d\TH:i:s');

            $endTime = Carbon::createFromFormat('Y-m-d H:i:s', $endTime->format('Y-m-d H:i:s'), 'UTC');
            $endTime->setTimezone($targetTz);


//            dump($row['end_time'], $endTime->format('Y-m-d H:i:s'));
            $topModels[] = [
                'start_time' => $startTime->format('c'),
                'end_time' => $endTime->format('c'),
                'duration_hours' => $row['duration_hours'],
                'total_tokens' => $row['total_tokens'],
                'total_usd' => Utils::toUSD($row['total_tokens'], $site),
                'usd_per_hour' => Utils::toUSD($row['tokens_per_hour'], $site),
                'tokens_per_hour' => $row['tokens_per_hour'],
                'tips' => $row['tips'],
                'dons' => $row['dons'],
                'details_url' => route('model.session.details', ['site' => $site, 'name' => $name, 'start_time' => $start_time, 'end_time' => $end_time]),
            ];
        }

        $data['site'] = Utils::siteToDbName($site);

        return Inertia::render('ModelSessions', [
            'room' => $room,
            'data' => $data,
            'rows' => $topModels,
            'isFavorite' => $user->favorites()->model($room['id'])->site($site)->exists(),
        ]);
    }

    public function tips($site, $name)
    {
        $user = request()->user();
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();

        if (request()->has('timezone')) {
            $targetTz = request()->get('timezone');
            $user->set('timezone', $targetTz);
        }

        $site = Utils::siteToDbName($site);
        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date) = Utils::dateFilter(request(), $user, $targetTz);

        $room = Cache::remember('model_id_query'.$site.$name, 60 * 5, static function () use ($site, $name) {
            return app(\ClickHouseDB\Client::class)->select(
                'select * from '.$site.'.`rooms` where name = lower(:name)', ['name' => $name]
            )->rows();
        });

        if(!isset($room[0])) {
            return response('Room not found', 404);
        }

        $room = $room[0];

        // Get paginated tips for the model in the date range
        $page = request()->get('page', 1);
        $perPage = request()->get('per_page', 20);
        $offset = ($page - 1) * $perPage;

        $tips = Cache::remember('model_tips_query_'.md5($date.$page.$perPage).'_'.$site.$room['id'], 60 * 5, static function () use ($date, $site, $room, $perPage, $offset) {
            $totalRows = app(\ClickHouseDB\Client::class)->select(
                'SELECT count(*) as total
                FROM '.$site.'.stats_v2 s
                WHERE '.$date.' AND s.rid = :rid',
                ['rid' => $room['id']]
            )->rows()[0]['total'];

            $rows = app(\ClickHouseDB\Client::class)->select(
                'SELECT
                    s.time,
                    s.token,
                    s.did,
                    d.name as donator_name
                FROM '.$site.'.stats_v2 s
                LEFT JOIN '.$site.'.donators d ON d.id = s.did
                WHERE '.$date.' AND s.rid = :rid
                ORDER BY s.time DESC
                LIMIT :limit OFFSET :offset',
                [
                    'rid' => $room['id'],
                    'limit' => (int) $perPage,
                    'offset' => (int) $offset
                ]
            )->rows();

            return [
                'data' => $rows,
                'total' => $totalRows
            ];
        });

        $formattedTips = [];
        foreach ($tips['data'] as $tip) {
            $time = Carbon::createFromFormat('Y-m-d H:i:s', $tip['time'], 'UTC');
            $time->setTimezone($targetTz);

            $formattedTips[] = [
                'time' => $time->format('c'),
                'tokens' => $tip['token'],
                'usd' => Utils::toUSD($tip['token'], $site),
                'donator' => $tip['donator_name'],
            ];
        }

        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
            'range' => [
                $_start,
                $_end,
            ],
            'site' => $site,
            'tips' => [
                'data' => $formattedTips,
                'total' => $tips['total'],
                'per_page' => $perPage,
                'current_page' => $page,
            ],
        ];

        return Inertia::render('ModelTips', [
            'room' => $room,
            'data' => $data,
            'isFavorite' => $user->favorites()->model($room['id'])->site($site)->exists(),
        ]);
    }

    public function sessionDetails($site, $name, $start_time, $end_time)
    {
        $user = request()->user();
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();

        if (request()->has('timezone')) {
            $targetTz = request()->get('timezone');
            $user->set('timezone', $targetTz);
        }

        $site = Utils::siteToDbName($site);

        $room = Cache::remember('model_id_query'.$site.$name, 60 * 5, static function () use ($site, $name) {
            return app(\ClickHouseDB\Client::class)->select(
                'select * from '.$site.'.`rooms` where name = lower(:name)', ['name' => $name]
            )->rows();
        });

        if(!isset($room[0])) {
            return response('Room not found', 404);
        }

        $room = $room[0];

        $startDateTime = Carbon::parse($start_time)->setTimezone('UTC');
        $endDateTime = Carbon::parse($end_time)->setTimezone('UTC');
        $isLive = $endDateTime->isAfter(now()->subMinutes(30)->setTimezone('UTC'));

        if ($isLive) {
            $endDateTime = now()->setTimezone('UTC');
        }

//        $endDateTime = (clone $startDateTime)->addHours(3); // Default window of 3 hours to look for session end

        // Get session details including all tips and donators
        $sessionDetails = Cache::remember(
            'session_details_'.$site.$room['id'].$start_time.$endDateTime->format('Y-m-d H:i:s'),
            60 * 5,
            static function () use ($site, $room, $startDateTime, $endDateTime) {
                return app(\ClickHouseDB\Client::class)->select(
                    'SELECT
                        s.time,
                        s.token,
                        s.did,
                        d.name as donator_name
                    FROM '.$site.'.stats_v2 s
                    LEFT JOIN '.$site.'.donators d ON d.id = s.did
                    WHERE s.rid = :rid
                    AND s.time >= :start_time
                    AND s.time <= :end_time
                    ORDER BY s.time DESC',
                    [
                        'rid' => $room['id'],
                        'start_time' => $startDateTime->format('Y-m-d H:i:s'),
                        'end_time' => $endDateTime->format('Y-m-d H:i:s')
                    ]
                )->rows();
            }
        );

        $tips = [];
        $donatorStats = [];
        $totalTokens = 0;

        foreach ($sessionDetails as $detail) {
            $originalTime = Carbon::parse($detail['time'], 'UTC');
            $time = Carbon::createFromFormat('Y-m-d H:i:s', $detail['time'], 'UTC');
            $time->setTimezone($targetTz);


            $totalTokens += $detail['token'];

            // if ($originalTime->isAfter($endDateTime)) {
            //     $endDateTime = $originalTime;
            // }

            $tips[] = [
                'time' => $time->format('c'),
                'tokens' => $detail['token'],
                'usd' => Utils::toUSD($detail['token'], $site),
                'donator' => $detail['donator_name'],
            ];

            if (!isset($donatorStats[$detail['did']])) {
                $donatorStats[$detail['did']] = [
                    'name' => $detail['donator_name'],
                    'total_tokens' => 0,
                    'tips_count' => 0,
                ];
            }

            $donatorStats[$detail['did']]['total_tokens'] += $detail['token'];
            $donatorStats[$detail['did']]['tips_count']++;
        }

        // Sort donators by total tokens
        uasort($donatorStats, function($a, $b) {
            return $b['total_tokens'] - $a['total_tokens'];
        });

        // Add USD values to donator stats
        foreach ($donatorStats as &$stat) {
            $stat['total_usd'] = Utils::toUSD($stat['total_tokens'], $site);
        }

        $sessionDuration = $startDateTime ? $endDateTime->diffInHours($startDateTime, true) : 0;

        $messages = [];
        if (in_array($site, ['stripchat', 'mfc', 'statbate'])) {
            // Get chat messages for the session
            $chatMessages = Cache::remember(
                'session_chat_'.$site.$room['id'].$start_time.$endDateTime->format('Y-m-d H:i:s'),
                60 * 5,
                static function () use ($site, $room, $startDateTime, $endDateTime) {
                    return app(\ClickHouseDB\Client::class)->select(
                        'SELECT
                            m.time,
                            m.message,
                            m.username as sender
                        FROM '.$site.'.messages_v2 m
                        WHERE m.rid = :rid
                        AND m.time >= :start_time
                        AND m.time <= :end_time
                        ORDER BY m.time DESC',
                        [
                            'rid' => $room['id'],
                            'start_time' => $startDateTime->format('Y-m-d H:i:s'),
                            'end_time' => $endDateTime->format('Y-m-d H:i:s')
                        ]
                    )->rows();
                }
            );

            foreach ($chatMessages as $message) {
                $time = Carbon::createFromFormat('Y-m-d H:i:s', $message['time'], 'UTC');
                $time->setTimezone($targetTz);

                $messages[] = [
                    'time' => $time->format('c'),
                    'message' => $message['message'],
                    'sender' => $message['sender'],
                ];
            }
        }

        $displayStartDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $startDateTime->format('Y-m-d H:i:s'), 'UTC');
        $displayStartDateTime->setTimezone($targetTz);

        $displayEndDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $endDateTime->format('Y-m-d H:i:s'), 'UTC');
        $displayEndDateTime->setTimezone($targetTz);

        $data = [
            'isLive' => $isLive,
            'timezones' => $tzList,
            'timezone' => $targetTz,
            'session_start' => $displayStartDateTime ? $displayStartDateTime->format('c') : null,
            'session_end' => $displayEndDateTime ? $displayEndDateTime->format('c') : null,
            'duration_hours' => $sessionDuration,
            'total_tokens' => $totalTokens,
            'total_usd' => Utils::toUSD($totalTokens, $site),
            'tokens_per_hour' => $sessionDuration > 0 ? round($totalTokens / $sessionDuration) : 0,
            'usd_per_hour' => $sessionDuration > 0 ? Utils::toUSD(round($totalTokens / $sessionDuration), $site) : 0,
            'tips_count' => count($tips),
            'unique_donators' => count($donatorStats),
            'tips' => $tips,
            'donator_stats' => array_values($donatorStats),
            'site' => $site,
            'messages' => $messages,
            'chatAvailable' => in_array($site, ['stripchat', 'mfc', 'statbate']),
            'details_url' => route('model.session.details', [
                'site' => $site,
                'name' => $name,
                'start_time' => $startDateTime->format('Y-m-d\TH:i:s'),
                'end_time' => $endDateTime->format('Y-m-d\TH:i:s'),
            ]),
        ];

        return Inertia::render('ModelSessionDetails', [
            'room' => $room,
            'data' => $data,
            'isFavorite' => $user->favorites()->model($room['id'])->site($site)->exists(),
        ]);
    }
}
