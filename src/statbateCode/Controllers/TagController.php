<?php

namespace App\Http\Controllers;

use App\Services\Utils;
use Inertia\Inertia;

class TagController extends Controller
{
    public function index()
    {
        $prefix = 'tags';
        $user = request()->user();
        $site = 'statbate';
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();

        $perPage = min((int)request()->get('per_page', 50), 500);
        $page = (int)request()->get('page', 0);

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

        $selectedGenders = [];
        $genderFilter = '';
        if (request()->has('selectedGenders')) {
            $selectedGenders = array_map('intval', request()->get('selectedGenders'));
            $allowed = [0, 1, 2, 3];
            $gendersList = array_filter($selectedGenders, fn($gender) => in_array($gender, $allowed, true));

            if ($gendersList) {
                $genderFilter = ' AND rooms.gender IN ('.implode(',', $gendersList).')';
                $data['selectedGenders'] = $gendersList;
            }
        }

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date, 'datehis' => $datehis) = Utils::dateFilter(
            request(),
            $user,
            $targetTz
        );
        // Check if the site has tags available
        $siteHasTags = true;

        // Get sites configuration from the JavaScript utils file
        $sites = config('sites', []);
        if (empty($sites)) {
            // Fallback to hardcoded sites if config is not available
            $sites = [
                ['value' => 'statbate', 'tagsAvailable' => true],
                ['value' => 'stripchat', 'tagsAvailable' => true],
                ['value' => 'bongacams', 'tagsAvailable' => true],
                ['value' => 'camsoda', 'tagsAvailable' => true],
                ['value' => 'mfc', 'tagsAvailable' => false],
            ];
        }

        foreach ($sites as $siteConfig) {
            if ($siteConfig['value'] === $site && isset($siteConfig['tagsAvailable']) && $siteConfig['tagsAvailable'] === false) {
                $siteHasTags = false;
                break;
            }
        }

        $total = ['total_tags' => 0];
        if ($siteHasTags) {
            $total = cache()->remember(
                'total_tags_'.$site.'_'.$genderFilter.'_'.$date,
                3600,
                function () use ($site, $genderFilter, $date) {
                    try {
                        return app(\ClickHouseDB\Client::class)->select(
                            sql: 'WITH tag_rooms AS (
            SELECT rooms.id, tags.tag
            FROM '.$site.'.tags
            LEFT JOIN '.$site.'.rooms ON rooms.name = tags.username
            WHERE 1=1 '.$genderFilter.'
            GROUP BY rooms.id, tags.tag
        ),
        tag_tokens AS (
            SELECT tr.tag, tr.id, SUM(id.tokens) AS total_tokens
            FROM tag_rooms tr
            JOIN '.$site.'.income_details AS id ON id.rid = tr.id
            WHERE '.$date.'
            GROUP BY tr.tag, tr.id
        )
        SELECT COUNT(DISTINCT tag_tokens.tag) AS total_tags
        FROM tag_tokens'
                        )->fetchOne();
                    } catch (\Exception $e) {
                        // Return zero if database doesn't exist or query fails
                        return ['total_tags' => 0];
                    }
                }
            );
        }

        $rows = [];
        if ($siteHasTags) {
            $rows = cache()->remember(
                'tag_rows_'.$site.'_'.$genderFilter.'_'.$date.'_'.$perPage.'_'.$page,
                3600,
                function () use ($site, $genderFilter, $date, $perPage, $page) {
                    try {
                        return app(\ClickHouseDB\Client::class)->select(
                            'WITH tag_rooms AS (
            SELECT rooms.id, tags.tag
            FROM '.$site.'.tags
            LEFT JOIN '.$site.'.rooms ON rooms.name = tags.username
            WHERE 1=1 '.$genderFilter.'
            GROUP BY rooms.id, tags.tag
        ),
        tag_tokens AS (
            SELECT tr.tag, tr.id, SUM(id.tokens) AS total_tokens
            FROM tag_rooms tr
            JOIN '.$site.'.income_details AS id ON id.rid = tr.id
            WHERE '.$date.'
            GROUP BY tr.tag, tr.id
        )
        SELECT tag, COUNT(id) AS models, SUM(total_tokens) AS tokens
        FROM tag_tokens
        GROUP BY tag_tokens.tag
        ORDER BY tokens DESC
        LIMIT '.$perPage.' OFFSET '.$perPage.' * '.$page
                        )->rows();
                    } catch (\Exception $e) {
                        // Return empty array if database doesn't exist or query fails
                        return [];
                    }
                }
            );
        }
        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
            'range' => [$_start, $_end],
            'per_page' => $perPage,
            'page' => $page,
            'total' => (int)$total['total_tags'],
            'site' => $site,
            'selectedGenders' => $selectedGenders,
        ];

        $topModels = array_map(fn($row, $i) => [
            'position' => $i + 1 + ($page) * $perPage,
            'tag' => $row['tag'],
            'models' => $row['models'],
            'usd' => Utils::toUSD($row['tokens'], $site),
        ], $rows, array_keys($rows));

        return Inertia::render('Tags', [
            'data' => $data,
            'tableRows' => $topModels,
        ]);
    }
}
