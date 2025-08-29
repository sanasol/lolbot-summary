<?php

namespace App\Http\Controllers;

use App\Services\Utils;
use Cache;
use Carbon\Carbon;
use ClickHouseDB\Transport\StreamRead;
use Inertia\Inertia;
use Meilisearch\Client;

class ChatController extends Controller
{
    public function rooms()
    {
        $site = request()->get('site') ?? 'statbate';
        $search = request()->get('search');
        $values = request()->get('values');

        // If no search term and no specific values requested, return popular rooms
        if (empty($search) && empty($values)) {
            $cacheKey = 'popular_rooms_' . $site;
            $rows = Cache::remember($cacheKey, 60 * 5, static function () use ($site) {
                return app(\ClickHouseDB\Client::class)->select(
                    'SELECT * FROM ' . $site . '.`rooms`
                     ORDER BY id DESC LIMIT 20'
                )->rows();
            });

            $rooms = [];
            foreach ($rows as $row) {
                $rooms[] = ['label' => $row['name'], 'value' => $row['id']];
            }

            return response()->json(['rows' => $rooms]);
        }

        // If specific room IDs are requested (for maintaining selected values)
        if (!empty($values)) {
            $roomIds = explode(',', $values);
            $placeholders = [];
            $params = [];

            foreach ($roomIds as $index => $id) {
                $key = "id{$index}";
                $placeholders[] = ":{$key}";
                $params[$key] = $id;
            }

            $placeholdersStr = implode(',', $placeholders);

            $rows = app(\ClickHouseDB\Client::class)->select(
                "SELECT * FROM {$site}.`rooms`
                 WHERE id IN ({$placeholdersStr})",
                $params
            )->rows();

            $rooms = [];
            foreach ($rows as $row) {
                $rooms[] = ['label' => $row['name'], 'value' => $row['id']];
            }

            return response()->json(['rows' => $rooms]);
        }

        // Search by room name
        $cacheKey = 'rooms_search_query_' . $site . '_' . $search;
        $rows = Cache::remember($cacheKey, 60 * 5, static function () use ($site, $search) {
            return app(\ClickHouseDB\Client::class)->select(
                "SELECT * FROM {$site}.`rooms`
                 WHERE rooms.name LIKE :search
                 ORDER BY id DESC LIMIT 20",
                ['search' => '%' . $search . '%']
            )->rows();
        });

        $rooms = [];
        foreach ($rows as $row) {
            $rooms[] = ['label' => $row['name'], 'value' => $row['id']];
        }

        return response()->json(['rows' => $rooms]);
    }
    public function search()
    {
        $meiliSearch = new Client('http://meilisearch:7700', env('MEILIKEY', 'jMKyHNuEcK9Jb8EvCZeQnyPaLlIMxC3mzkx7YTZ1oME'));
        $site = request()->get('site', false);
        $room = request()->get('search');
        if (empty($room)) {
            return response()->json(['rows' => []]);
        }

//        $result = ;


        $rows = $meiliSearch
                ->index('rooms')
                ->search($room, !empty($site) ? ['filter' => 'db = '.$site] : [])
                ->getHits();

        $rooms = [];
        foreach ($rows as $row) {
            $rooms[] = [
                'label' => $row['name'],
                'value' => $row['db'],
                'type' => $row['type'] ?? 'model',
            ];
        }

        return response()->json(['rows' => $rooms]);
    }

    public function index()
    {
        $user = request()->user();
        $site = 'statbate';
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();
        $rid = null;

        if (request()->has('site')) {
            $site = request()->get('site');
        }

        $data = [
            'timezones' => $tzList,
            'timezone' => $targetTz,
        ];

        if (request()->has('rid') && !empty(request()->get('rid'))) {
            $rid = request()->get('rid');

            $room = app(\ClickHouseDB\Client::class)->select(
                'SELECT * FROM '.$site.'.`rooms`
                             WHERE id = :rid', ['rid' => $rid]
            )->fetchOne();
            if (!empty($room)) {
                $data['rid'] = $rid;
                $data['selectedRoom'] = $room['name'];
            } else {
                $rid = null;
            }
        }

        $sender = null;
        if (request()->has('sender') && !empty(request()->get('sender'))) {
            $sender = request()->get('sender');
        }
        $data['sender'] = $sender;

        if (request()->has('timezone')) {
            $targetTz = request()->get('timezone');
            $user->set('timezone', $targetTz);
        }

        $search = null;
        if (request()->has('search')) {
            $search = request()->get('search');
            $data['search'] = $search;
        }

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date) = Utils::dateFilter(request(), $user, $targetTz);


        $data['range'] = [
            $_start,
            $_end,
        ];
        $data['site'] = $site;
//
//
//        $conds = [];
//        $params = [];
//        if ($rid) {
//            $conds[] = 'rid = :rid';
//            $params['rid'] = $rid;
//        }
//
//        if ($search) {
//            $conds[] = 'message LIKE :search';
//            $params['search'] = "%{$search}%";
//        }
//
//        $condsString = '';
//        if (count($conds) > 0) {
//            $condsString = 'and '.implode(' AND ', $conds);
//        }
//
//        $cnt = app(\ClickHouseDB\Client::class)->select("
//            SELECT count() as cnt
//                    FROM {$site}.`messages_v2`
//                    WHERE {$date} {$condsString}",
//            $params
//        )->fetchOne();
//        $data['total'] = $cnt['cnt'];
//
        $perPage = (int) request()->get('per_page', 10);
        if ($perPage > 500) {
            $perPage = 500;
        }
        $page = (int) request()->get('page', 0);
//
        $data['per_page'] = $perPage;
        $data['page'] = $page;
//
//        $rows = Cache::remember('chat_query_1'.md5($date.$condsString.json_encode($params)).'_'.$site.$page.$perPage, 60 * 5, static function () use ($date, $site, $page, $perPage, $condsString, $params) {
//                return app(\ClickHouseDB\Client::class)->select(
//                    'SELECT rooms.id as rid, rooms.name as name, messages_v2.username as username, messages_v2.message as message, messages_v2.time as time, donators.name as donator FROM '.$site.'.`messages_v2`
//                             LEFT JOIN '.$site.'.`rooms` ON messages_v2.rid = rooms.id
//                             LEFT JOIN '.$site.'.`donators` ON messages_v2.username = donators.name
//                             WHERE '.$date.' '.$condsString.' ORDER BY time desc limit '.$perPage.' offset '.$perPage.' * '.$page.'', $params
//                )->rows();
//            });
//
//        $messages = [];
//        foreach ($rows as $row) {
//            $date = Carbon::parse($row['time'], 'UTC');
//            if ($date === null) {
//                continue;
//            }
//
//            $date = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d H:i:s'), 'UTC');
//            $date->setTimezone($targetTz);
//
//            $messages[] = [
//                'room' => $row['name'],
//                'sender' => $row['username'],
//                'message' => $row['message'],
//                'time' => $date->format('Y-m-d H:i:s'),
//            ];
//        }

//        $data['rows'] = $messages;

        return Inertia::render('Chat', [
            'data' => $data,
        ]);
    }

    public function streamMessages()
    {
        $user = request()->user();
        $site = 'statbate';
        $targetTz = Utils::defaultTz();
        $tzList = Utils::tzList();
        $rid = null;

        if (request()->has('site')) {
            $site = request()->get('site');
        }

        $data = [
//            'timezones' => $tzList,
            'timezone' => $targetTz,
        ];

        if (request()->has('rid') && !empty(request()->get('rid'))) {
            $rid = request()->get('rid');

            $room = app(\ClickHouseDB\Client::class)->select(
                'SELECT * FROM '.$site.'.`rooms`
                             WHERE id = :rid', ['rid' => $rid]
            )->fetchOne();
            if (!empty($room)) {
                $data['rid'] = $rid;
                $data['selectedRoom'] = $room['name'];
            } else {
                $rid = null;
            }
        }

        $sender = null;
        if (request()->has('sender') && !empty(request()->get('sender'))) {
            $sender = request()->get('sender');
        }
        $data['sender'] = $sender;

        if (request()->has('timezone')) {
            $targetTz = request()->get('timezone');
            $user->set('timezone', $targetTz);
        }

        $search = null;
        if (request()->has('search')) {
            $search = request()->get('search');
            $data['search'] = $search;
        }

        list('start' => $start, '_start' => $_start, 'end' => $end, '_end' => $_end, 'date' => $date) = Utils::dateFilter(request(), $user, $targetTz);

        $data['range'] = [
            $_start,
            $_end,
        ];
        $data['site'] = $site;


        $conds = [];
        $params = [];
        if ($rid) {
            $conds[] = 'rid = :rid';
            $params['rid'] = $rid;
        }

        if ($search) {
            $conds[] = 'message LIKE :search';
            $params['search'] = "%{$search}%";
        }

        if ($sender) {
            $conds[] = 'username = :sender';
            $params['sender'] = $sender;
        }

        $condsString = '';
        if (count($conds) > 0) {
            $condsString = 'and '.implode(' AND ', $conds);
        }

        $cnt = app(\ClickHouseDB\Client::class)->select("
            SELECT count() as cnt
                    FROM {$site}.`messages_v2`
                    WHERE {$date} {$condsString}",
            $params
        )->fetchOne();
        $data['total'] = $cnt['cnt'];

        $perPage = (int) request()->get('per_page', 10);
        if ($perPage > 500) {
            $perPage = 500;
        }
        $page = (int) request()->get('page', 0);
        if ($page > $data['total']) {
            $page = 0;
        }

        $data['per_page'] = $perPage;
        $data['page'] = $page;

        $stream = fopen('php://memory','r+');
        $streamRead=new StreamRead($stream);
//        $callable = function ($ch, $string) use ($stream) {
//            // some magic for _BLOCK_ data
//            fwrite($stream, str_ireplace('"sin"','"max"',$string));
//            return strlen($string);
//        };
//
//        $streamRead->closure($callable);

        app(\ClickHouseDB\Client::class)->streamRead($streamRead,'SELECT rooms.id as rid, rooms.name as name, messages_v2.username as username, messages_v2.message as message, messages_v2.time as time, donators.name as donator
         FROM '.$site.'.`messages_v2`
         LEFT JOIN '.$site.'.`rooms` ON messages_v2.rid = rooms.id
         LEFT JOIN '.$site.'.`donators` ON messages_v2.username = donators.name
         WHERE '.$date.' '.$condsString.'  ORDER BY time desc limit '.$perPage.' offset '.$perPage.' * '.$page. ' FORMAT JSONEachRow', $params);

        return response()->stream(function() use ($stream, $targetTz, $data) {
            rewind($stream);
            while (($buffer = fgets($stream, 4096)) !== false) {

                $row = json_decode($buffer, true);
                $date = Carbon::parse($row['time'], 'UTC');
                if ($date === null) {

                    continue;
                }

                $date = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d H:i:s'), 'UTC');
                $date->setTimezone($targetTz);

                echo json_encode([
                        'data' => $data,
                        'room' => $row['name'],
                        'sender' => $row['username'],
                        'message' => $row['message'],
                        'time' => $date->format('Y-m-d H:i:s'),
                    ]) . "\n";
                ob_flush();
                flush();
            }
            fclose($stream); // Need Close Stream

        }, 200, ['Content-Type' => 'application/json', 'X-Accel-Buffering' => 'no']);
    }

    public function donators()
    {
        return response()->json(['rows' => []]);
    }

    public function senders()
    {
        $site = request()->get('site') ?? 'statbate';
        $search = request()->get('search');
        $values = request()->get('values');

        // If no search term and no specific values requested, return popular senders
        if (empty($search) && empty($values)) {
            $cacheKey = 'popular_senders_' . $site;
            $rows = Cache::remember($cacheKey, 60 * 60 * 24, static function () use ($site) {
                return app(\ClickHouseDB\Client::class)->select(
                    "SELECT username, COUNT(*) as message_count
                     FROM {$site}.`messages_v2`
                     GROUP BY username
                     ORDER BY message_count DESC
                     LIMIT 20"
                )->rows();
            });

            $senders = [];
            foreach ($rows as $row) {
                if (!empty($row['username'])) {
                    $senders[] = ['label' => $row['username'], 'value' => $row['username']];
                }
            }

            return response()->json(['rows' => $senders]);
        }

        // If specific sender names are requested (for maintaining selected values)
        if (!empty($values)) {
            $senderNames = explode(',', $values);
            $placeholders = [];
            $params = [];

            foreach ($senderNames as $index => $name) {
                $key = "name{$index}";
                $placeholders[] = ":{$key}";
                $params[$key] = $name;
            }

            $placeholdersStr = implode(',', $placeholders);

            $rows = app(\ClickHouseDB\Client::class)->select(
                "SELECT DISTINCT username
                 FROM {$site}.`messages_v2`
                 WHERE username IN ({$placeholdersStr})",
                $params
            )->rows();

            $senders = [];
            foreach ($rows as $row) {
                if (!empty($row['username'])) {
                    $senders[] = ['label' => $row['username'], 'value' => $row['username']];
                }
            }

            return response()->json(['rows' => $senders]);
        }

        // Search by sender name
        $cacheKey = 'senders_search_query_' . $site . '_' . $search;
        $rows = Cache::remember($cacheKey, 60 * 5, static function () use ($site, $search) {
            return app(\ClickHouseDB\Client::class)->select(
                "SELECT DISTINCT username
                 FROM {$site}.`messages_v2`
                 WHERE username LIKE :search
                 LIMIT 20",
                ['search' => '%' . $search . '%']
            )->rows();
        });

        $senders = [];
        foreach ($rows as $row) {
            if (!empty($row['username'])) {
                $senders[] = ['label' => $row['username'], 'value' => $row['username']];
            }
        }

        return response()->json(['rows' => $senders]);
    }
}
