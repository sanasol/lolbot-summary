<?php

namespace App\Http\Controllers;

use App\Services\Utils;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class CohortAnalysisController extends Controller
{
    /**
     * Display the cohort analysis page
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        return Inertia::render('CohortAnalysis');
    }

    /**
     * Get cohort data for models who received tips
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getModelCohortData(Request $request)
    {
        $site = $request->get('site', 'chaturbate');
        $site = Utils::siteToDbName($site);

        $timeRange = $request->get('timeRange', 3);
        $cohortSize = $request->get('cohortSize', 'month'); // 'month' or 'week'

        // Validate: weekly cohorts are only allowed for 3-month time range
        if ($cohortSize === 'week' && $timeRange != 3) {
            return response()->json([
                'error' => 'Weekly cohorts are only available for 3-month time range',
                'meta' => [
                    'site' => Utils::dbToSiteName($site),
                    'timeRange' => $timeRange,
                    'cohortSize' => $cohortSize
                ]
            ], 400);
        }

        // Calculate date ranges based on cohort size
        $now = Carbon::now();
        $startDate = $now->copy()->subMonths($timeRange)->startOfMonth();

        // Cache key based on parameters
        $cacheKey = "model_cohort_v2_{$site}_{$timeRange}_{$cohortSize}";

        // Return cached data if available (after the first request with the fixed query)
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        // Get cohort data from ClickHouse
        $cohortData = $this->fetchCohortData($site, $startDate, $cohortSize);

        // Cache the result for 24 hours
        Cache::put($cacheKey, $cohortData, 60 * 60 * 24);

        return response()->json($cohortData);
    }

    /**
     * Fetch cohort data from ClickHouse
     *
     * @param string $site
     * @param Carbon $startDate
     * @param string $cohortSize
     * @return array
     */
    private function fetchCohortData($site, $startDate, $cohortSize)
    {
        // Start timing the entire process
        $totalStartTime = microtime(true);
        \Log::info("Starting cohort analysis for site: {$site}, startDate: {$startDate->format('Y-m-d')}, cohortSize: {$cohortSize}");

        // Determine the date format and interval based on cohort size
        $dateFormat = $cohortSize === 'week' ? 'toYearWeek' : 'toYYYYMM';
        $dateFormatLabel = $cohortSize === 'week' ? 'Y-\\WW' : 'Y-m';

        // Calculate cohort periods
        $now = Carbon::now();
        $cohortPeriods = [];
        $currentDate = $startDate->copy();

        // Generate all cohort periods from start date to now
        while ($currentDate <= $now) {
            $cohortValue = $cohortSize === 'week'
                ? $currentDate->format('Y') . str_pad($currentDate->weekOfYear, 2, '0', STR_PAD_LEFT)
                : $currentDate->format('Ym');

            $cohortPeriods[] = $cohortValue;

            // Move to next period
            if ($cohortSize === 'week') {
                $currentDate->addWeek();
            } else {
                $currentDate->addMonth();
            }
        }

        // Initialize cohort data structures
        $cohorts = [];
        $cohortSizes = [];
        $cohortData = [];

        // Process each cohort period
        foreach ($cohortPeriods as $cohortPeriod) {
            // Calculate the start and end dates for this cohort period
            if ($cohortSize === 'week') {
                $year = substr($cohortPeriod, 0, 4);
                $week = substr($cohortPeriod, 4);
                $cohortStartDate = Carbon::now()->setISODate((int)$year, (int)$week, 1)->startOfDay();
                $cohortEndDate = $cohortStartDate->copy()->addWeek()->subSecond();
            } else {
                $year = substr($cohortPeriod, 0, 4);
                $month = substr($cohortPeriod, 4);
                $cohortStartDate = Carbon::createFromDate((int)$year, (int)$month, 1)->startOfDay();
                $cohortEndDate = $cohortStartDate->copy()->addMonth()->subSecond();
            }

            // Skip future cohorts
            if ($cohortStartDate > $now) {
                continue;
            }

            // Get count of models that first appeared in this cohort period
            $cohortSizeQuerySql = "
                SELECT
                    count() as size
                FROM (
                    SELECT
                        r.id as rid
                    FROM {$site}.income_details s
                    JOIN {$site}.rooms r ON s.rid = r.id
                    GROUP BY r.id
                    HAVING min(toDate(s.time)) >= '{$cohortStartDate->format('Y-m-d')}'
                       AND min(toDate(s.time)) <= '{$cohortEndDate->format('Y-m-d')}'
                )
            ";

            // Log the query and measure execution time
            \Log::info("Cohort size query for period {$cohortPeriod}:", ['query' => $cohortSizeQuerySql]);
            $startTime = microtime(true);

            $cohortSizeQuery = app(\ClickHouseDB\Client::class)->select($cohortSizeQuerySql)->rows();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            \Log::info("Cohort size query execution time: {$executionTime}ms");

            // Skip cohorts with no models
            if (empty($cohortSizeQuery) || (int)$cohortSizeQuery[0]['size'] === 0) {
                continue;
            }

            // Add cohort to our list
            $cohorts[] = $cohortPeriod;
            $cohortSizes[$cohortPeriod] = (int)$cohortSizeQuery[0]['size'];

            // Use a CTE to get activity by period for models in this cohort
            $activityQuerySql = "
                WITH cohort_models AS (
                    SELECT
                        r.id as rid
                    FROM {$site}.income_details s
                    JOIN {$site}.rooms r ON s.rid = r.id
                    GROUP BY r.id
                    HAVING min(toDate(s.time)) >= '{$cohortStartDate->format('Y-m-d')}'
                       AND min(toDate(s.time)) <= '{$cohortEndDate->format('Y-m-d')}'
                )
                SELECT
                    {$dateFormat}(s.time) as period,
                    count(DISTINCT s.rid) as active_models
                FROM {$site}.income_details s
                JOIN cohort_models cm ON s.rid = cm.rid
                WHERE {$dateFormat}(s.time) >= '{$cohortPeriod}'
                GROUP BY period
                ORDER BY period
            ";

            // Log the query and measure execution time
            \Log::info("Activity by period query for cohort {$cohortPeriod}:", ['query' => $activityQuerySql]);
            $startTime = microtime(true);

            $activityByPeriod = app(\ClickHouseDB\Client::class)->select($activityQuerySql)->rows();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            \Log::info("Activity query execution time: {$executionTime}ms");

            // Calculate retention rates
            $retentionData = ['cohort' => $cohortPeriod, 'size' => $cohortSizes[$cohortPeriod], 'retention' => []];
            $cohortModelCount = $cohortSizes[$cohortPeriod];

            foreach ($activityByPeriod as $activity) {
                $period = $activity['period'];
                $activeModels = (int) $activity['active_models'];
                $retentionRate = round(($activeModels / $cohortModelCount) * 100, 1);

                $retentionData['retention'][] = [
                    'period' => $period,
                    'activeModels' => $activeModels,
                    'retentionRate' => $retentionRate
                ];
            }

            $cohortData[] = $retentionData;
        }

        // Format cohort labels for display
        $formattedCohorts = [];
        foreach ($cohorts as $cohort) {
            if ($cohortSize === 'week') {
                // Format is YYYYWW
                $year = substr($cohort, 0, 4);
                $week = substr($cohort, 4);
                $date = Carbon::now()->setISODate((int)$year, (int)$week)->startOfWeek();
                $formattedCohorts[$cohort] = $date->format($dateFormatLabel);
            } else {
                // Convert YYYYMM format to a Carbon date
                $year = substr($cohort, 0, 4);
                $month = substr($cohort, 4);
                $date = Carbon::createFromDate((int)$year, (int)$month, 1);
                $formattedCohorts[$cohort] = $date->format($dateFormatLabel);
            }
        }

        // Calculate and log total execution time
        $totalExecutionTime = round((microtime(true) - $totalStartTime) * 1000, 2);
        \Log::info("Total cohort analysis execution time: {$totalExecutionTime}ms", [
            'cohortCount' => count($cohorts),
            'totalPeriods' => count($cohortPeriods)
        ]);

        return [
            'cohorts' => $formattedCohorts,
            'data' => $cohortData,
            'meta' => [
                'site' => Utils::dbToSiteName($site),
                'startDate' => $startDate->format('Y-m-d'),
                'cohortSize' => $cohortSize,
                'executionTimeMs' => $totalExecutionTime
            ]
        ];
    }

    /**
     * Get list of models who received tips and then stopped
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInactiveModels(Request $request)
    {
        $site = $request->get('site', 'chaturbate');
        $site = Utils::siteToDbName($site);

        $inactivePeriod = $request->get('inactivePeriod', 1); // Default to 3 months of inactivity
        $limit = $request->get('limit', 500); // Limit the number of results to avoid large queries

        // Cache key based on parameters
        $cacheKey = "inactive_models_{$site}_{$inactivePeriod}_{$limit}";

        // Return cached data if available
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        // Calculate date thresholds
        $now = Carbon::now();
        $inactiveThreshold = $now->copy()->subMonths($inactivePeriod);
        $historyStart = $now->copy()->subYear(); // Look at models active in the last year

        // Format dates for ClickHouse
        $inactiveThresholdStr = $inactiveThreshold->format('Y-m-d');
        $historyStartStr = $historyStart->format('Y-m-d');

        // Query to find models who were active but haven't received tips since the inactive threshold
        // Limit the result set to avoid large queries
        $inactiveModels = app(\ClickHouseDB\Client::class)->select("
            WITH active_models AS (
                SELECT
                    r.id as rid,
                    r.name,
                    r.gender,
                    min(s.time) as first_tip_date,
                    max(s.time) as last_tip_date,
                    sum(s.token) as total_tokens,
                    count() as tip_count
                FROM {$site}.stats_v2 s
                JOIN {$site}.rooms r ON s.rid = r.id
                WHERE toDate(s.time) >= '{$historyStartStr}'
                GROUP BY r.id, r.name, r.gender
                HAVING toDate(last_tip_date) <= '{$inactiveThresholdStr}'
                ORDER BY last_tip_date DESC
                LIMIT {$limit}
            )
            SELECT
                rid,
                name,
                gender,
                first_tip_date,
                last_tip_date,
                total_tokens,
                tip_count
            FROM active_models
        ")->rows();

        // Format the response
        $formattedModels = [];
        foreach ($inactiveModels as $model) {
            $firstTipDate = Carbon::parse($model['first_tip_date'], 'UTC');
            $lastTipDate = Carbon::parse($model['last_tip_date'], 'UTC');

            $formattedModels[] = [
                'id' => (int) $model['rid'],
                'name' => $model['name'],
                'gender' => (int) $model['gender'],
                'firstTipDate' => $firstTipDate->format('Y-m-d'),
                'lastTipDate' => $lastTipDate->format('Y-m-d'),
                'careerLength' => $firstTipDate->diffInDays($lastTipDate),
                'totalTokens' => (int) $model['total_tokens'],
                'tipCount' => (int) $model['tip_count'],
                'avgTokensPerTip' => $model['tip_count'] > 0 ? round($model['total_tokens'] / $model['tip_count'], 2) : 0,
                'inactiveDays' => $lastTipDate->diffInDays($now)
            ];
        }

        $result = [
            'data' => $formattedModels,
            'meta' => [
                'site' => Utils::dbToSiteName($site),
                'inactivePeriod' => $inactivePeriod,
                'count' => count($formattedModels),
                'limit' => $limit
            ]
        ];

        // Cache the result for 24 hours
        Cache::put($cacheKey, $result, 60 * 60 * 24);

        return response()->json($result);
    }
}
