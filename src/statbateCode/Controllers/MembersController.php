<?php

namespace App\Http\Controllers;

use App\Services\Utils;
use Cache;
use Inertia\Inertia;

class MembersController extends Controller
{
    public function index()
    {
        $prefix = 'members';
        $user = request()->user();
        $site = 'statbate';
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();

        if (request()->has('timezone')) {
            $targetTz = request()->get('timezone');
            $user->set('timezone', $targetTz);
        }
        if (request()->has('site')) {
            $site = request()->get('site');
        }

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date) = Utils::dateFilter(request(), $user, $targetTz);

        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
            'range' => [
                $_start,
                $_end,
            ],
        ];

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

        $optionalHaving = 'HAVING sum(tokens) / sum(donates) < 1000';

        if (request()->has('highTippers') && request()->get('highTippers') === 'true') {
            $optionalHaving = '';
        }

        $rows = Cache::remember($prefix.'models_query2_'.md5($date.$optionalHaving.$genderFilter).'_'.$site, 60 * 5, static function () use ($date, $site, $optionalHaving, $genderFilter) {
                return app(\ClickHouseDB\Client::class)->select(
                    'select donators.name, subtbl.* from (SELECT did, sum(tokens) / sum(donates) as avg, sum(donates) as tips,  SUM(tokens) as total, count(DISTINCT rid) as models, count(DISTINCT time) as days
                        FROM '.$site.'.income_details
                        left join '.$site.'.rooms on income_details.rid = rooms.id
                        WHERE '.$date.' '.$genderFilter.'
                        GROUP BY did
                        '.$optionalHaving.'
                        ORDER BY total DESC
                        LIMIT 200) as subtbl left join '.$site.'.donators on donators.id = subtbl.did')->rows();
            });

        $dids = [];
        foreach ($rows as $row) {
            $dids[] = $row['did'];
        }

//        $roomsIncome = [];
//        if (count($dids)) {
//            $hourlyRows = Cache::remember($prefix.'models_hourly_query'.md5($date.$genderFilter).'_'.$site.md5(implode(',', $dids)), 60 * 5, static function () use ($date, $site, $dids) {
//                    return app(\ClickHouseDB\Client::class)->select(
//                        'select did, avg(hourly_income) as hrinc, count() as hours from (SELECT
//                                    did,
//                                    toStartOfHour(time) AS hour,
//                                    sum(token) AS hourly_income
//                                FROM '.$site.'.stats_v2
//                                WHERE '.$date.' and did in ('.implode(',', $dids).')
//                                GROUP BY did, hour
//                                ORDER BY hour) group by did')->rows();
//                });
//
//            foreach ($hourlyRows as $row) {
//                $roomsIncome[$row['did']] = $row;
//            }
//        }


        $memberOnline = [];
        if (count($dids)) {
            $memberRows = app(\ClickHouseDB\Client::class)->select(
                'SELECT
                                    did
                                FROM '.$site.'.stats_v2
                                WHERE time >= now() - interval 15 minutes and did in ('.implode(',', $dids).')
                                GROUP BY did')->rows();

            foreach ($memberRows as $row) {
                $memberOnline[$row['did']] = 1;
            }
        }

        $topModels = [];
        foreach ($rows as $i => $row) {
            $topModels[] = [
                'position' => $i + 1,
                'online' => $memberOnline[$row['did']] ?? 0,
                'name' => $row['name'],
                'did' => $row['did'],
                'total' => Utils::toUSD($row['total'], $site),
                'tips' => $row['tips'],
                'avg' => Utils::toUSD($row['avg'], $site),
                'models' => $row['models'],
                'daily' => Utils::toUSD($row['total'] / $row['days'], $site),
                'isFavorite' => $user->favorites()->member($row['did'])->site($site)->exists(),
            ];
        }

        $data['site'] = $site;

        return Inertia::render('Members', [
            'data' => $data,
            'top' => $topModels,
        ]);
    }
}
