<?php

namespace App\Http\Controllers;

use App\Services\Utils;
use Cache;
use Carbon\Carbon;
use Inertia\Inertia;

class DataController extends Controller
{
    public function charts()
    {
        $user = request()->user();
        $site = 'statbate';
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();

        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
        ];

        if (request()->has('timezone')) {
            $requestedTz = request()->get('timezone');
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
                // No need to set user timezone with invalid value
            }
        }
        if (request()->has('site')) {
            $site = request()->get('site');
        }

        $genderFilter = '';
        if (request()->has('selectedGenders')) {
            $selectedGenders = request()->get('selectedGenders');
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
        if (request()->has('selectedTags')) {
            $selectedTags = request()->get('selectedTags');
            if (is_array($selectedTags) && count($selectedTags) > 0) {
                $tagsList = [];
                foreach ($selectedTags as $tag) {
                    $tagsList[] = $tag;
                }

                if (count($tagsList) > 0) {
                    // Create a subquery to filter rooms by tags
                    $tagFilter = ' AND rooms.id IN (
                        SELECT DISTINCT rooms.id
                        FROM ' . $site . '.rooms
                        JOIN ' . $site . '.tags ON rooms.name = tags.username
                        WHERE tags.tag IN (\'' . implode('\',\'', $tagsList) . '\')
                    )';

                    $data['selectedTags'] = $selectedTags;
                }
            }
        }

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date, 'datehis' => $datehis) = Utils::dateFilter(request(), $user, $targetTz);

        $data['range'] = [
            $_start,
            $_end,
        ];

        $rows = Cache::remember('chart_query_'.md5($date.$genderFilter.$tagFilter).'_'.$site, 60 * 5, static function () use ($date, $site, $genderFilter, $tagFilter) {
                try {
                    return app(\ClickHouseDB\Client::class)->select(
                        'SELECT rooms.gender, SUM(tokens) as total, time as ndate FROM '.$site.'.`income_details` LEFT JOIN '.$site.'.`rooms` ON income_details.rid = rooms.id WHERE '.$date.' '.$genderFilter.' '.$tagFilter.' AND did not in (SELECT did
                                              FROM '.$site.'.income_details
                                              WHERE '.$date.'
                                              GROUP BY did
                                              HAVING sum(tokens) / sum(donates) > 20000) GROUP by `gender`, ndate ORDER BY ndate ASC'
                    )->rows();
                } catch (\Exception $e) {
                    // Return empty array if database doesn't exist or query fails
                    return [];
                }
            });

        $labels = [];
        $genders = [];

        foreach ($rows as $row) {
            $labels[] = $row['ndate'];
            if (!isset($genders[$row['gender']])) {
                $genders[$row['gender']] = [];
            }
            $genders[$row['gender']][$row['ndate']] = Utils::toUSD($row['total'], $site);
        }

        foreach ($genders as $gender => $dates) {
            if (isset($genders[$gender][now()->format('Y-m-d')]) && now()->format('H') < 12) {
                unset($genders[$gender][now()->format('Y-m-d')]);
            }
        }

        $data['labels'] = array_values(array_unique($labels));
        $data['genders'] = $genders;
        $data['site'] = $site;
        $data['heatmap'] = [];

        $rows = Cache::remember('23sheatmap_query_'.md5($datehis.$genderFilter.$tagFilter).'_'.$site, 600, static function () use ($datehis, $date, $site, $genderFilter, $tagFilter) {
                try {
                    return app(\ClickHouseDB\Client::class)->select(
                        'SELECT toStartOfDay(toDateTime(`unix`)) as date, toStartOfHour(toDateTime(`unix`)) as hour, SUM(`token`) as sum
                            FROM '.$site.'.`stats_v2`
                            left join '.$site.'.`rooms` on rooms.id = stats_v2.rid
                            where '.$datehis.' '.$genderFilter.' '.$tagFilter.' AND did not in (SELECT did
                                              FROM '.$site.'.income_details
                                              WHERE '.$date.'
                                              GROUP BY did
                                              HAVING sum(tokens) / sum(donates) > 20000)
                            GROUP BY date, hour
                            ORDER BY date, hour ASC'
                    )->rows();
                } catch (\Exception $e) {
                    // Return empty array if database doesn't exist or query fails
                    return [];
                }
            });

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
            if (count($days) < 2) {
                continue;
            }
            if (!isset($data['heatmap'][$group])) {
                $data['heatmap'][$group] = [];
            }

            foreach ($days as $day => $monthData) {
                if (count($monthData) < 3) {
                    continue;
                }
                ksort($monthData);
                $data['heatmap'][$group][] = [
                    'name' => $day,
                    'data' => array_values($monthData),
                ];
            }
        }

        return Inertia::render('Charts', [
            'data' => $data,
        ]);
    }

    public function index()
    {
        $user = request()->user();
        $site = 'statbate';
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();


        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
        ];

        if (request()->has('timezone')) {
            $requestedTz = request()->get('timezone');
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
                // No need to set user timezone with invalid value
            }
        }
        if (request()->has('site')) {
            $site = request()->get('site');
        }

        $genderFilter = '';
        if (request()->has('selectedGenders')) {
            $selectedGenders = request()->get('selectedGenders');
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
        if (request()->has('selectedTags')) {
            $selectedTags = request()->get('selectedTags');
            if (is_array($selectedTags) && count($selectedTags) > 0) {
                $tagsList = [];
                foreach ($selectedTags as $tag) {
                    $tagsList[] = $tag;
                }

                if (count($tagsList) > 0) {
                    // Create a subquery to filter rooms by tags
                    $tagFilter = ' AND rooms.id IN (
                        SELECT DISTINCT rooms.id
                        FROM ' . $site . '.rooms
                        JOIN ' . $site . '.tags ON rooms.name = tags.username
                        WHERE tags.tag IN (\'' . implode('\',\'', $tagsList) . '\')
                    )';

                    $data['selectedTags'] = $selectedTags;
                }
            }
        }

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date, 'datehis' => $datehis) = Utils::dateFilter(request(), $user, $targetTz);

        $data['range'] = [
            $_start,
            $_end,
        ];

        $rows = Cache::remember('models_query2_'.md5($date.$genderFilter.$tagFilter).'_'.$site, 60 * 5, static function () use ($date, $site, $genderFilter, $tagFilter) {
                try {
                    return app(\ClickHouseDB\Client::class)->select(
                        'select (rooms.last >= now() - interval 15 minute) as online, rooms.name, rooms.gender, subtbl.* from (SELECT rid, SUM(tokens) as total, count(DISTINCT did) as dons, count(DISTINCT time) as days
                            FROM '.$site.'.income_details
                            WHERE '.$date.'
                              AND did not in (SELECT did
                                              FROM '.$site.'.income_details
                                              WHERE '.$date.'
                                              GROUP BY did
                                              HAVING sum(tokens) / sum(donates) > 20000)
                            GROUP BY rid
                            HAVING sum(tokens) / sum(donates) < 1000
                            ORDER BY total DESC) as subtbl left join '.$site.'.rooms on rooms.id = subtbl.rid where 1=1 '.$genderFilter.' '.$tagFilter.'
                            LIMIT 200')->rows();
                } catch (\Exception $e) {
                    // Return empty array if database doesn't exist or query fails
                    return [];
                }
            });

        $rids = [];
        foreach ($rows as $row) {
            $rids[] = $row['rid'];
        }

        $roomsIncome = [];
        if (count($rids)) {
            $hourlyRows = Cache::remember('models_hourly_query'.md5($date.$genderFilter).'_'.$site.md5(implode(',', $rids)), 60 * 5, static function () use ($date, $site, $rids) {
                    try {
                        return app(\ClickHouseDB\Client::class)->select(
                            'select rid, avg(hourly_income) as hrinc, count() as hours from (SELECT
                                        rid,
                                        toStartOfHour(time) AS hour,
                                        sum(token) AS hourly_income
                                    FROM '.$site.'.stats_v2
                                    WHERE '.$date.' and rid in ('.implode(',', $rids).')
                                    GROUP BY rid, hour
                                    ORDER BY hour) group by rid')->rows();
                    } catch (\Exception $e) {
                        // Return empty array if database doesn't exist or query fails
                        return [];
                    }
                });

            foreach ($hourlyRows as $row) {
                $roomsIncome[$row['rid']] = $row;
            }
        }

        $topModels = [];
        foreach ($rows as $i => $row) {
            $topModels[] = [
                'position' => $i + 1,
                'online' => $row['online'],
                'name' => $row['name'],
                'gender' => $row['gender'] ?? 1,
                'rid' => $row['rid'],
                'total' => Utils::toUSD($row['total'], $site),
                'hourly' => Utils::toUSD($roomsIncome[$row['rid']]['hrinc'] ?? 0, $site),
                'hours' => $roomsIncome[$row['rid']]['hours'] ?? 0,
                'dons' => $row['dons'],
                'daily' => Utils::toUSD($row['total'] / $row['days'], $site),
                'isFavorite' => (int) $user->favorites()->model($row['rid'])->site($site)->exists(),
            ];
        }

        $data['site'] = $site;

        return Inertia::render('Dashboard', [
            'data' => $data,
            'top' => $topModels,
        ]);
    }
}
