<?php

namespace App\Http\Controllers;

use App\Services\Utils;
use Cache;
use Carbon\Carbon;
use Inertia\Inertia;

class MemberController extends Controller
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

        $room = Cache::remember('member_id_query'.$site.$name, 60 * 5, static function () use ($date, $site, $name) {
            return app(\ClickHouseDB\Client::class)->select(
                'select * from '.$site.'.`donators` where name = lower(:name)', ['name' => $name]
            )->rows();
        });

        if(!isset($room[0])) {
            return response('Member not found', 404);
        }

        $room = $room[0];

        $rows = Cache::remember('member_hourly_query1'.md5($date).'_'.$site.$room['id'], 60 * 5, static function () use ($date, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'select did, avg(hourly_income) as hrinc, count() as hours, toDate(hour) as day from (SELECT
                                did,
                                toStartOfHour(time) AS hour,
                                sum(token) AS hourly_income
                            FROM '.$site.'.stats_v2
                            WHERE '.$date.' and did=:did
                            GROUP BY did, hour
                            ORDER BY hour) group by did, day order by day   '
                , ['did' => $room['id']])->rows();
        });

        $hourlyIncomeLabels = [];
        $hourlyIncomeDays = [];
        $hourlyIncomeHours = [];

        foreach ($rows as $row) {
            $hourlyIncomeLabels[] = $row['day'];
            $hourlyIncomeDays[$row['day']] = Utils::toUSD($row['hrinc'], $site);
            $hourlyIncomeHours[$row['day']] = $row['hours'];
        }

        $rows = Cache::remember('member_chart_query_'.md5($date).'_'.$site.$room['id'], 60 * 5, static function () use ($date, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'SELECT rooms.gender, SUM(tokens) as total, time as ndate FROM '.$site.'.`income_details` LEFT JOIN '.$site.'.`rooms` ON income_details.rid = rooms.id WHERE '.$date.' AND did = :did GROUP by `gender`, ndate ORDER BY ndate ASC'
                , ['did' => $room['id']])->rows();
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

        $rows = Cache::remember('member_query_'.md5($date).'_'.$site.$room['id'], 60 * 5, static function () use ($date, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'select rooms.name, subtbl.* from (SELECT rid, SUM(tokens) as total, count(DISTINCT time) as days
                        FROM '.$site.'.income_details
                        WHERE '.$date.' and did = :did
                        GROUP BY rid
                        ORDER BY total DESC
                        LIMIT 200) as subtbl left join '.$site.'.rooms on rooms.id = subtbl.rid', ['did' => $room['id']])->rows();
        });

        $topModels = [];
        foreach ($rows as $i => $row) {
            $topModels[] = [
                'position' => $i + 1,
                'name' => $row['name'],
                'rid' => $row['rid'],
                'total' => Utils::toUSD($row['total'], $site),
                'daily' => Utils::toUSD($row['total'] / $row['days'], $site),
            ];
        }

        $data['labels'] = $labels;
        $data['genders'] = $genders;
        $data['site'] = Utils::dbToSiteName($site);
        $data['heatmap'] = [];

        // Get tip statistics for the member
        $tipStats = Cache::remember('member_tip_stats_'.$site.$room['id'], 60 * 5, static function () use ($site, $room) {
            // Get first tip info
            $firstTip = app(\ClickHouseDB\Client::class)->select(
                'SELECT time as first_tip_date, token as first_tip_amount
                FROM '.$site.'.stats_v2
                WHERE did = :did
                ORDER BY time ASC
                LIMIT 1',
                ['did' => $room['id']]
            )->rows();

            // Get last tip info
            $lastTip = app(\ClickHouseDB\Client::class)->select(
                'SELECT time as last_tip_date, token as last_tip_amount
                FROM '.$site.'.stats_v2
                WHERE did = :did
                ORDER BY time DESC
                LIMIT 1',
                ['did' => $room['id']]
            )->rows();

            // Get total stats
            $totalStats = app(\ClickHouseDB\Client::class)->select(
                'SELECT
                    COUNT() as total_tip_count,
                    SUM(token) as total_tip_sum
                FROM '.$site.'.stats_v2
                WHERE did = :did',
                ['did' => $room['id']]
            )->rows();

            // Combine results
            return [
                [
                    'first_tip_date' => $firstTip[0]['first_tip_date'] ?? null,
                    'first_tip_amount' => $firstTip[0]['first_tip_amount'] ?? 0,
                    'last_tip_date' => $lastTip[0]['last_tip_date'] ?? null,
                    'last_tip_amount' => $lastTip[0]['last_tip_amount'] ?? 0,
                    'total_tip_count' => $totalStats[0]['total_tip_count'] ?? 0,
                    'total_tip_sum' => $totalStats[0]['total_tip_sum'] ?? 0,
                ]
            ];
        });

        // Get tip statistics for the selected period
        $periodTipStats = Cache::remember('member_period_tip_stats_'.md5($date).'_'.$site.$room['id'], 60 * 5, static function () use ($date, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'SELECT
                    COUNT() as period_tip_count,
                    SUM(token) as period_tip_sum
                FROM '.$site.'.stats_v2
                WHERE '.$date.' AND did = :did',
                ['did' => $room['id']]
            )->rows();
        });

        if (isset($tipStats[0])) {
            $data['tipStats'] = [
                'firstTipDate' => Carbon::parse($tipStats[0]['first_tip_date'], 'UTC')->setTimezone($targetTz)->format('Y-m-d H:i:s'),
                'firstTipAmount' => Utils::toUSD($tipStats[0]['first_tip_amount'], $site),
                'lastTipDate' => Carbon::parse($tipStats[0]['last_tip_date'], 'UTC')->setTimezone($targetTz)->format('Y-m-d H:i:s'),
                'lastTipAmount' => Utils::toUSD($tipStats[0]['last_tip_amount'], $site),
                'totalTipCount' => $tipStats[0]['total_tip_count'],
                'totalTipSum' => Utils::toUSD($tipStats[0]['total_tip_sum'], $site),
                'periodTipCount' => isset($periodTipStats[0]) ? $periodTipStats[0]['period_tip_count'] : 0,
                'periodTipSum' => isset($periodTipStats[0]) ? Utils::toUSD($periodTipStats[0]['period_tip_sum'], $site) : 0,
            ];
        } else {
            $data['tipStats'] = [
                'firstTipDate' => 'N/A',
                'firstTipAmount' => 0,
                'lastTipDate' => 'N/A',
                'lastTipAmount' => 0,
                'totalTipCount' => 0,
                'totalTipSum' => 0,
                'periodTipCount' => 0,
                'periodTipSum' => 0,
            ];
        }

        $rows = Cache::remember('member23sheatmap_query_'.md5($date).'_'.$site.$room['id'], 1, static function () use ($date, $site, $room) {
            return app(\ClickHouseDB\Client::class)->select(
                'SELECT toStartOfDay(toDateTime(`unix`)) as date, toStartOfHour(toDateTime(`unix`)) as hour, SUM(`token`) as sum
                        FROM '.$site.'.`stats_v2`
                        where '.$date.' AND did = :did
                        GROUP BY date, hour
                        ORDER BY date, hour ASC', ['did' => $room['id']]
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

        return Inertia::render('Member', [
            'room' => $room,
            'data' => $data,
            'top' => $topModels,
            'isFavorite' => $user->favorites()->member($room['id'])->site($site)->exists(),
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

        $room = Cache::remember('member_id_query'.$site.$name, 60 * 5, static function () use ($date, $site, $name) {
            return app(\ClickHouseDB\Client::class)->select(
                'select * from '.$site.'.`donators` where name = lower(:name)', ['name' => $name]
            )->rows();
        });

        if(!isset($room[0])) {
            return response('Member not found', 404);
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

        $rows = Cache::remember('member_sessions_query_'.md5($date).'_'.$site.$room['id'].$granularity, 60 * 5, static function () use (
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
        if(unix_timestamp - lagInFrame(unix_timestamp) OVER (PARTITION BY did ORDER BY time) <= '.($granularity * 3600).', 0, 1) AS new_group
    FROM '.$site.'.stats_v2
    WHERE did = :did and time >= :start and time <= :end
),
grouped_activity AS (
    SELECT
        did,
        rid,
        time,
        token,
        sum(new_group) OVER (PARTITION BY did ORDER BY time) AS group_id
    FROM activity_periods
),
activity_windows AS (
    SELECT
        did,
        group_id,
        min(time) AS start_time,
        max(time) AS end_time,
        sum(token) AS total_tokens,
        count(distinct rid) AS dons,
        count() as tips
    FROM grouped_activity
    GROUP BY did, group_id
)
SELECT
    did,
    start_time,
    end_time,
    round((toUnixTimestamp(end_time) - toUnixTimestamp(start_time)) / 3600, 2) AS duration_hours,
    total_tokens,
    round(total_tokens / duration_hours)  as tokens_per_hour,
    tips,
    dons
FROM activity_windows
ORDER BY did, start_time desc',
                [
                    'did' => $room['id'],
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

            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $startTime->format('Y-m-d H:i:s'), 'UTC');
            $startTime->setTimezone($targetTz);
            $endTime = Carbon::parse($row['end_time'], 'UTC');
            if ($endTime === null) {
                continue;
            }

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
            ];
        }

        $data['site'] = Utils::dbToSiteName($site);

        return Inertia::render('MemberSessions', [
            'room' => $room,
            'data' => $data,
            'rows' => $topModels,
            'isFavorite' => $user->favorites()->member($room['id'])->site($site)->exists(),
        ]);
    }
}
