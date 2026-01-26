<?php
namespace App\Services;

use App\Providers\OpenRouterAi;
use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use ClickHouseDB\Client as ClickHouseClient;
use GuzzleHttp\Client as HttpClient;

class ClickhouseAgent extends Agent
{
    private static array $config;
    private bool $hasActiveSubscription = false;

    protected int $toolCalls = 0;

    /**
     * Approximate token limit for AI responses
     */
    private const MAX_TOKENS = 50000;

    /**
     * Statbate API base URL and token
     */
    private const STATBATE_API_URL = 'https://plus.statbate.com/api';
    private const STATBATE_API_TOKEN = 'apollo-secret-api-user-76543132456';

    /**
     * Chat action sender for status updates
     */
    private static ?int $chatId = null;
    private static ?int $threadId = null;
    private static ?TelegramSender $sender = null;
    private static int $lastActionTime = 0;

    /**
     * Chat actions to cycle through for visual variety
     */
    private const CHAT_ACTIONS = ['typing', 'upload_document', 'find_location', 'record_video', 'choose_sticker'];

    public function __construct(array $config = [], bool $hasActiveSubscription = false)
    {
        self::$config = $config;
        $this->hasActiveSubscription = $hasActiveSubscription;
    }

    /**
     * Set chat action sender for status updates during long operations
     */
    public static function setChatActionSender(?int $chatId, ?TelegramSender $sender, ?int $threadId = null): void
    {
        self::$chatId = $chatId;
        self::$sender = $sender;
        self::$threadId = $threadId;
        self::$lastActionTime = 0;
    }

    /**
     * Send a chat action if enough time has passed (every 2 seconds)
     */
    private static function sendChatAction(): void
    {
        if (self::$chatId === null || self::$sender === null) {
            return;
        }

        $now = time();
        if ($now - self::$lastActionTime < 2) {
            return;
        }

        self::$lastActionTime = $now;

        // Cycle through different actions for visual variety
        $actionIndex = (int)(($now / 2) % count(self::CHAT_ACTIONS));
        $action = self::CHAT_ACTIONS[$actionIndex];

        try {
            self::$sender->sendChatAction(self::$chatId, $action, self::$threadId);
        } catch (\Exception $e) {
            // Ignore chat action failures
        }
    }


    protected function provider(): AIProviderInterface
    {
        // return an AI provider instance (Anthropic, OpenAI, Mistral, etc.)
//        return new Anthropic(
//            key: self::$config['anthropic']['key'],
//            model: self::$config['anthropic']['model'],
//        );
        // Use OpenRouterAi provider instead of Anthropic
        return new OpenRouterAi(
            key: self::$config['openrouter_key'],
            model: self::$config['openrouter_tool_model'],
        );
    }

    /**
     * Estimate token count for a string
     * This is a simple approximation - 1 token is roughly 4 characters for English text
     *
     * @param string $text Text to estimate token count for
     * @return int Estimated token count
     */
    private function estimateTokenCount(string $text): int
    {
        // Simple approximation: 1 token ≈ 4 characters for English text
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Truncate results array to fit within token limit
     *
     * @param array $results Results array to truncate
     * @param int $maxTokens Maximum token count allowed
     * @return array Truncated results
     */
    private function truncateResultsToTokenLimit(array $results, int $maxTokens): array
    {
        // If no results, return empty array
        if (empty($results)) {
            return [];
        }

        // If only one result, handle specially
        if (count($results) === 1) {
            return $this->truncateSingleResult($results[0], $maxTokens);
        }

        // Calculate tokens per result (approximate)
        $resultCount = count($results);
        $jsonOverhead = 20; // Approximate JSON overhead for brackets, commas, etc.

        // Start with a smaller subset and gradually increase
        $truncatedResults = [];
        $currentTokens = $jsonOverhead;

        foreach ($results as $index => $result) {
            $resultJson = json_encode($result);
            $resultTokens = $this->estimateTokenCount($resultJson);

            // If adding this result would exceed the limit, stop adding
            if ($currentTokens + $resultTokens > $maxTokens) {
                // If we haven't added any results yet, add at least one truncated result
                if (empty($truncatedResults) && $index === 0) {
                    $truncatedResults[] = $this->truncateSingleResult($result, $maxTokens - $jsonOverhead);
                }
                break;
            }

            $truncatedResults[] = $result;
            $currentTokens += $resultTokens;
        }

        // Add a note about truncation if needed
        if (count($truncatedResults) < count($results)) {
            $truncatedResults[] = [
                '_note' => 'Results truncated to fit within ' . $maxTokens . ' token limit. ' .
                           'Showing ' . count($truncatedResults) . ' of ' . count($results) . ' results.'
            ];
        }

        return $truncatedResults;
    }

    /**
     * Truncate a single result to fit within token limit
     *
     * @param array $result Single result to truncate
     * @param int $maxTokens Maximum token count allowed
     * @return array Truncated result
     */
    private function truncateSingleResult(array $result, int $maxTokens): array
    {
        $resultJson = json_encode($result);
        $currentTokens = $this->estimateTokenCount($resultJson);

        // If already within limit, return as is
        if ($currentTokens <= $maxTokens) {
            return $result;
        }

        // Truncate each field proportionally
        $truncatedResult = [];
        $fieldCount = count($result);

        // Calculate how much we need to reduce
        $reductionFactor = $maxTokens / $currentTokens;

        foreach ($result as $key => $value) {
            if (is_string($value)) {
                $valueTokens = $this->estimateTokenCount($value);
                $newTokens = (int) floor($valueTokens * $reductionFactor);

                if ($newTokens < $valueTokens) {
                    // Truncate string to approximate token count
                    $charLimit = $newTokens * 4;
                    $truncatedResult[$key] = mb_substr($value, 0, $charLimit) . '...';
                } else {
                    $truncatedResult[$key] = $value;
                }
            } else {
                // For non-string values, keep as is
                $truncatedResult[$key] = $value;
            }
        }

        // Add truncation note
        $truncatedResult['_truncated'] = true;

        return $truncatedResult;
    }

    /**
     * Convert standard array of associative arrays to optimized format with columns and rows
     * This reduces repetition of column names in the JSON output
     *
     * @param array $results Standard array of associative arrays
     * @return array Optimized format with columns and rows
     */
    private function convertToOptimizedFormat(array $results): array
    {
        if (empty($results)) {
            return ['columns' => [], 'rows' => []];
        }

        if (count($results) === 1) {
            $data = $results[0];
            foreach ($data as $key => $value) {
                $data[$key] = (string) $value;
            }
            return [
                'row' => $data
            ];
        }
        // Extract column names from the first result
        $columns = array_keys($results[0]);

        // Extract values only for each row
        $rows = [];
        foreach ($results as $result) {
            $row = [];
            foreach ($columns as $column) {
                $row[] = $result[$column] ?? null;
            }
            $rows[] = $row;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'count' => count($rows)
        ];
    }

    /**
     * Escape a string for use in ClickHouse SQL queries
     *
     * @param string $value The string to escape
     * @return string The escaped string
     */
    private function escapeString(string $value): string
    {
        return str_replace("'", "\'", $value);
    }

    /**
     * Truncate optimized results to fit within token limit
     *
     * @param array $optimizedResults Optimized results (columns and rows format)
     * @param int $maxTokens Maximum token count allowed
     * @return array Truncated optimized results
     */
    private function truncateOptimizedResultsToTokenLimit(array $optimizedResults, int $maxTokens): array
    {
        // If no results, return empty structure
        if (empty($optimizedResults['rows'])) {
            return $optimizedResults;
        }

        $columns = $optimizedResults['columns'];
        $rows = $optimizedResults['rows'];
        $count = $optimizedResults['count'];

        // Calculate base structure tokens (columns, empty rows array, etc.)
        $baseStructure = ['columns' => $columns, 'rows' => [], 'count' => 0];
        $baseJson = json_encode($baseStructure);
        $baseTokens = $this->estimateTokenCount($baseJson);

        // Available tokens for rows
        $availableTokens = $maxTokens - $baseTokens;

        // If we don't have enough tokens even for the base structure, return minimal structure
        if ($availableTokens <= 0) {
            return [
                'columns' => $columns,
                'rows' => [],
                'count' => 0,
                '_note' => 'Results completely truncated due to token limit.'
            ];
        }

        // Start adding rows until we hit the token limit
        $truncatedRows = [];
        $currentTokens = $baseTokens;

        foreach ($rows as $index => $row) {
            $rowJson = json_encode($row);
            $rowTokens = $this->estimateTokenCount($rowJson);

            // If adding this row would exceed the limit, stop adding
            if ($currentTokens + $rowTokens > $maxTokens) {
                break;
            }

            $truncatedRows[] = $row;
            $currentTokens += $rowTokens;
        }

        $result = [
            'columns' => $columns,
            'rows' => $truncatedRows,
            'count' => count($truncatedRows)
        ];

        // Add a note about truncation if needed
        if (count($truncatedRows) < count($rows)) {
            $result['_note'] = 'Results truncated to fit within ' . $maxTokens . ' token limit. ' .
                           'Showing ' . count($truncatedRows) . ' of ' . count($rows) . ' rows.';
        }

        return $result;
    }

    public function instructions(): string
    {
        $timeLimitInstructions = "";
        if (!$this->hasActiveSubscription) {
            $timeLimitInstructions = "
            1. DONT MAKE SUMMARIES THAT REQUESTED more than 30 days of data, limit all queries by date to not more than 30 days ago from current date.
            2. DONT QUERY any data before this date ".date('Y-m-d', strtotime('-1 month'))."
            3. DONT ANSWER questions about before this date ".date('Y-m-d', strtotime('-1 month'))."
            4. DONT MAKE CLICKHOUSE queries that can use return anything before this date ".date('Y-m-d', strtotime('-1 month'))."
            5. DONT return any data before this date ".date('Y-m-d', strtotime('-1 month'))."";
        }

        $prompt = "You are an AI Agent specialized in writing summaries for data from database.
            Answer in English always if user not asked you in different language.
            Current time: " . date('H:i:s') . ".
            Current date: " . date('Y-m-d') . ".

            IMPORTANT: PREFER using call_statbate_api tool for these common queries (faster and more reliable):
            - Member/donator info, tips, activity, top models: use call_statbate_api with endpoint like /members/{site}/{name}/info
            - Model/room info, tips, members, activity: use call_statbate_api with endpoint like /model/{site}/{name}/info
            - Only use run_select_query for complex custom queries NOT covered by the API

            Available API endpoints (use call_statbate_api):
            MEMBER endpoints (donators/tippers):
            - /members/{site}/{name}/info - Stats: total tips, first/last seen, top models
            - /members/{site}/{name}/tips - Tip history (supports: page, per_page, from, to, model, min_amount, max_amount)
            - /members/{site}/{name}/top-models - Top tipped models (supports: from, to)
            - /members/{site}/{name}/activity - Activity timeline (supports: from, to)
            - /members/{site}/{name}/tag-spending - Spending by room tags (supports: page, per_page, from, to)
            - /members/{site}/{name}/model-spending/{model} - Spending on specific model (supports: from, to)

            MODEL endpoints (streamers/performers):
            - /model/{site}/{name}/info - Stats: total earnings, followers, top tippers (supports: from, to)
            - /model/{site}/{name}/tips - Tip history (supports: page, per_page, from, to)
            - /model/{site}/{name}/members - Top tipping members (supports: from, to)
            - /model/{site}/{name}/activity - Sessions/activity (supports: from, to)
            - /model/{site}/{name}/rank - Ranking history (supports: from, to)

            Common params: timezone (e.g. Europe/Bucharest), from/to (Y-m-d dates), page, per_page (max 200)
            Sites: chaturbate, stripchat, bongacams, camsoda, mfc

            Database is clickhouse version 24.10.2.80
            database definition for clickhouse: " . self::$config['clickhouse_db_definition'] . "
            logs_v2 table available but for requests not more than 1 day
            room_activity each record is 1 minute but must be grouped, can contain duplicated records.
            Databases available: chaturbate, stripchat, camsoda, bongacams, mfc
            By default use chaturbate database unless otherwise specified.
            DONT MAKE SUMMARIES OF THE ENTIRE DATABASE OR ANYTHING ELSE THAT IS NOT REQUESTED IN THE MESSAGE!" .
            $timeLimitInstructions . "
            By default use chaturbate database unless otherwise specified.
            all queries must include database name
            Data in database stored in UTC timezone.
            Rooms gender mapping: 0=Male, 1=Female, 2=Trans, 3=Couple.
            Messages gender mapping: f=Female, m=Male, c=Couple, s=Trans.
                use clickhouse CTE queries to avoid joins and too many queries
                dont use too much tool calls, try to fit request into single complex query
                if tool call fails, retry again only 10 times
                Dont make requests that require more than 10 tool calls
                find the requested room or donator in the database.
                NAME MUST BE IN LOWERCASE.
                Use the tools you have available to retrieve the requested data.
                Write the analysis and write it down.

                Provide a summary of the content.
                Include any relevant details that may be useful for understanding the content.
                Include detailed information about what queries made to DB with all important notes, dont report raw queries, but report what tables used and what conditions used

               Use html formatting for final result, dont use html tables";

        return $prompt;
    }

    protected function tools(): array
    {
        return [
            Tool::make(
                'list_databases',
                'List available ClickHouse databases.',
            )->setMaxTries(10)->addProperty(
                new ToolProperty(
                    name: 'test',
                    type: PropertyType::STRING,
                    description: 'test name',
                    required: false
                )
            )->setCallable(function () {
                self::sendChatAction();
                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [tool call] ";
                $webhookLogFile = self::$config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

                $clickhouse = new ClickHouseClient([
                    'host' => self::$config['clickhouse']['host'],
                    'port' => self::$config['clickhouse']['port'],
                    'username' => self::$config['clickhouse']['username'],
                    'password' => self::$config['clickhouse']['password']
                ]);

                $query = "SHOW DATABASES";
                $statement = $clickhouse->select($query);
                $result = $statement->rows();

                $logMessage = $logPrefix . "Executing tool list_databases..." . json_encode(['databases' => $result]). PHP_EOL;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                $this->toolCalls++;
                self::sendChatAction();
                return json_encode(['databases' => $result, 'toolCalls' => $this->toolCalls]);
            }),

            Tool::make(
                'list_tables',
                'List available ClickHouse tables in a database, including schema, comment, row count, and column count.',
            )->setMaxTries(10)->addProperty(
                new ToolProperty(
                    name: 'database',
                    type: PropertyType::STRING,
                    description: 'Database name',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'like',
                    type: PropertyType::STRING,
                    description: 'Filter tables by pattern',
                    required: false
                )
            )->setCallable(function (string $database, ?string $like = null) {
                self::sendChatAction();
                $this->toolCalls++;

                $clickhouse = new ClickHouseClient([
                    'host' => self::$config['clickhouse']['host'],
                    'port' => self::$config['clickhouse']['port'],
                    'username' => self::$config['clickhouse']['username'],
                    'password' => self::$config['clickhouse']['password']
                ]);

                // Escape database name for safety
                $escapedDatabase = '`' . str_replace('`', '``', $database) . '`';

                // Build the query
                $query = "SHOW TABLES FROM {$escapedDatabase}";
                if ($like) {
                    $query .= " LIKE '" . $this->escapeString($like) . "'";
                }

                // Get tables
                $tablesStatement = $clickhouse->select($query);
                $tables = array_column($tablesStatement->rows(), 'name');

                // Get table comments
                $tableCommentsQuery = "SELECT name, comment FROM system.tables WHERE database = '" . $this->escapeString(trim($escapedDatabase, '`')) . "'";
                $tableCommentsResult = $clickhouse->select($tableCommentsQuery)->rows();
                $tableComments = [];
                foreach ($tableCommentsResult as $row) {
                    $tableComments[$row['name']] = $row['comment'];
                }

                // Get column comments
                $columnCommentsQuery = "SELECT table, name, comment FROM system.columns WHERE database = '" . $this->escapeString(trim($escapedDatabase, '`')) . "'";
                $columnCommentsResult = $clickhouse->select($columnCommentsQuery)->rows();

                $columnComments = [];
                foreach ($columnCommentsResult as $row) {
                    $table = $row['table'];
                    $colName = $row['name'];
                    $comment = $row['comment'];

                    if (!isset($columnComments[$table])) {
                        $columnComments[$table] = [];
                    }
                    $columnComments[$table][$colName] = $comment;
                }

                $tablesInfo = [];
                foreach ($tables as $table) {
                    // Get schema info
                    $schemaQuery = "DESCRIBE TABLE {$escapedDatabase}.`" . str_replace('`', '``', $table) . "`";
                    $schemaResult = $clickhouse->select($schemaQuery)->rows();

                    $columns = [];
                    foreach ($schemaResult as $column) {
                        // Add comment from pre-fetched comments
                        if (isset($columnComments[$table]) && isset($columnComments[$table][$column['name']])) {
                            $column['comment'] = $columnComments[$table][$column['name']];
                        } else {
                            $column['comment'] = null;
                        }
                        $columns[] = $column;
                    }

                    // Get row count
                    $rowCountQuery = "SELECT count() as count FROM {$escapedDatabase}.`" . str_replace('`', '``', $table) . "`";
                    $rowCountResult = $clickhouse->select($rowCountQuery)->fetchOne();
                    $rowCount = $rowCountResult['count'] ?? 0;

                    // Get create table query
                    $createTableQuery = "SHOW CREATE TABLE {$escapedDatabase}.`" . str_replace('`', '``', $table) . "`";
                    $createTableResult = $clickhouse->select($createTableQuery)->fetchOne('statement');

                    $tablesInfo[] = [
                        'database' => trim($escapedDatabase, '`'),
                        'name' => $table,
                        'comment' => $tableComments[$table] ?? null,
                        'columns' => $columns,
                        'create_table_query' => $createTableResult,
                        'row_count' => $rowCount,
                        'column_count' => count($columns)
                    ];
                }

                // Log the results
                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [tool call] ";
                $webhookLogFile = self::$config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';
                $logMessage = $logPrefix . "Executing tool list_tables with parameters: " . json_encode(compact('database', 'like')) . PHP_EOL;
                $logMessage .= $logPrefix . "Results: " . json_encode($tablesInfo). PHP_EOL;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                $tablesInfo['toolCalls'] = $this->toolCalls;

                return json_encode($tablesInfo);
            }),

            Tool::make(
                'run_select_query',
                'Run a SELECT query in a ClickHouse database',
            )->setMaxTries(10)->addProperty(
                new ToolProperty(
                    name: 'query',
                    type: PropertyType::STRING,
                    description: 'Clickhouse SELECT query to run.',
                    required: true
                )
            )->setCallable(function (string $query) {
                self::sendChatAction();
                $this->toolCalls++;

                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [tool call] ";
                $webhookLogFile = self::$config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

                // Log the results
                $logMessage = $logPrefix . "Executing tool run_select_query with query: " . $query . PHP_EOL;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                try {
                    self::sendChatAction();
                    // Validate that this is a SELECT query for safety
//                    $trimmedQuery = trim($query);
//                    if (!preg_match('/^SELECT\s/i', $trimmedQuery) && !preg_match('/^with\s/i', $trimmedQuery)) {
//                        return json_encode([
//                            'status' => 'error',
//                            'message' => 'Only SELECT queries are allowed for security reasons'
//                        ]);
//                    }

                    $clickhouse = new ClickHouseClient([
                        'host' => self::$config['clickhouse']['host'],
                        'port' => self::$config['clickhouse']['port'],
                        'username' => self::$config['clickhouse']['username'],
                        'password' => self::$config['clickhouse']['password']
                    ]);

                    // Set a timeout for the query (30 seconds)
                    $clickhouse->setTimeout(120);

                    // Force read-only mode
                    $clickhouse->settings()->set('readonly', 1);

                    try {
                        // Execute the query
                        $statement = $clickhouse->select($query);
                        $result = $statement->rows();
                    } catch (\Exception $e) {
                        $rs = json_encode([
                            'status' => 'Failed to execute query',
                            'error_message' => $e->getMessage(),
                            'toolCalls' => $this->toolCalls,
                        ]);

                        $logMessage = $logPrefix . "Failed to prepare statement for query: " . $rs . PHP_EOL;
                        file_put_contents($webhookLogFile, $logMessage, FILE_APPEND);
                        return $rs;
                    }

                    // Convert to optimized format (columns + rows) to reduce repetition
                    $optimizedResult = $this->convertToOptimizedFormat($result);
//                    $optimizedResult['query'] = $query;
                    if (isset($optimizedResult['rows']) && count($optimizedResult['rows']) === 0) {
                        $optimizedResult['comment'] = 'No results found for this query';
                        $optimizedResult['toolCalls'] = $this->toolCalls;
                    }
                    // Calculate approximate token count and limit results if needed
                    $resultJson = json_encode($optimizedResult);
                    $tokenCount = $this->estimateTokenCount($resultJson);

                    // If token count exceeds limit, truncate results
                    if ($tokenCount > self::MAX_TOKENS) {
                        $logMessage = $logPrefix . "Token count exceeded limit ($tokenCount > " . self::MAX_TOKENS . "). Truncating results." . PHP_EOL;
                        file_put_contents($webhookLogFile, $logMessage, FILE_APPEND);

                        // Truncate optimized results to fit within token limit
                        $optimizedResult = $this->truncateOptimizedResultsToTokenLimit($optimizedResult, self::MAX_TOKENS);
                        $optimizedResult['toolCalls'] = $this->toolCalls;

                        if (count($optimizedResult['rows']) === 0) {
                            $optimizedResult['comment'] = 'No results found for this query';
                            $optimizedResult['toolCalls'] = $this->toolCalls;

                        }
                        $resultJson = json_encode($optimizedResult);
                        $tokenCount = $this->estimateTokenCount($resultJson);
                    }


                    // Log the results
                    $logMessage = $logPrefix . "Finished run_select_query with query: " . $query . PHP_EOL;
                    $logMessage .= $logPrefix . "Results (approx. $tokenCount tokens): " . $resultJson . PHP_EOL;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                    return $resultJson;
                } catch (\Exception $e) {
                    $logMessage = $logPrefix . "Error executing tool run_select_query with query: " . $query . PHP_EOL;
                    $logMessage .= $logPrefix . "Error message: " . $e->getMessage(). PHP_EOL;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                    return json_encode([
                        'toolCalls' => $this->toolCalls,
                        'status' => 'error',
                        'message' => 'Query failed: ' . $e->getMessage()
                    ]);
                }
            }),

            Tool::make(
                'call_statbate_api',
                'Call the Statbate API for stable, optimized data queries. PREFERRED over raw ClickHouse for member/model info, tips, activity.',
            )->setMaxTries(1000)->addProperty(
                new ToolProperty(
                    name: 'endpoint',
                    type: PropertyType::STRING,
                    description: 'API endpoint path. Sites: chaturbate, stripchat, bongacams, camsoda, mfc. ' .
                        'Member endpoints: /members/{site}/{name}/info (stats), /members/{site}/{name}/tips (tip history), ' .
                        '/members/{site}/{name}/top-models (top tipped), /members/{site}/{name}/activity (timeline), ' .
                        '/members/{site}/{name}/tag-spending (spending by tag), /members/{site}/{name}/model-spending/{model} (spending on specific model). ' .
                        'Model endpoints: /model/{site}/{name}/info (stats), /model/{site}/{name}/tips (tip history), ' .
                        '/model/{site}/{name}/members (top tippers), /model/{site}/{name}/activity (sessions), /model/{site}/{name}/rank (ranking history).',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'params',
                    type: PropertyType::STRING,
                    description: 'Query parameters as JSON string. Available params: ' .
                        'timezone (string, e.g. "Europe/Bucharest" - affects date formatting in response), ' .
                        'from (string Y-m-d, start date filter), to (string Y-m-d, end date filter), ' .
                        'page (int, pagination page number starting from 1), per_page (int, items per page, max 200), ' .
                        'model (string, filter tips by model name - for member tips endpoint), ' .
                        'min_amount (int, minimum tip amount filter), max_amount (int, maximum tip amount filter). ' .
                        'Example: {"from": "2024-01-01", "to": "2024-01-31", "page": 1, "per_page": 50, "timezone": "UTC"}',
                    required: false
                )
            )->setCallable(function (string $endpoint, ?string $params = null) {
                self::sendChatAction();
                $this->toolCalls++;

                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [api call] ";
                $webhookLogFile = self::$config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

                // Parse params if provided
                $queryParams = [];
                if ($params) {
                    $decoded = json_decode($params, true);
                    if (is_array($decoded)) {
                        $queryParams = $decoded;
                    }
                }

                // Apply date limit for non-subscribers (30 days)
                if (!$this->hasActiveSubscription) {
                    $minDate = date('Y-m-d', strtotime('-30 days'));
                    // Override 'from' param if not set or older than 30 days
                    if (!isset($queryParams['from']) || $queryParams['from'] < $minDate) {
                        $queryParams['from'] = $minDate;
                    }
                }

                // Build full URL
                $url = self::STATBATE_API_URL . '/' . ltrim($endpoint, '/');
                if (!empty($queryParams)) {
                    $url .= '?' . http_build_query($queryParams);
                }

                $logMessage = $logPrefix . "Calling Statbate API: " . $url . PHP_EOL;
                file_put_contents($webhookLogFile, $logMessage, FILE_APPEND);

                self::sendChatAction();
                try {
                    $client = new HttpClient([
                        'timeout' => 30,
                        'verify' => false, // Skip SSL verification for internal API
                    ]);

                    $response = $client->get($url, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . self::STATBATE_API_TOKEN,
                            'Accept' => 'application/json',
                            'X-Account-Identifier' => self::STATBATE_API_TOKEN,
                        ],
                    ]);

                    $body = $response->getBody()->getContents();
                    $data = json_decode($body, true);

                    // Calculate token count and truncate if needed
                    $tokenCount = $this->estimateTokenCount($body);

                    $logMessage = $logPrefix . "API response (approx. $tokenCount tokens): " . mb_substr($body, 0, 500) . (strlen($body) > 500 ? '...' : '') . PHP_EOL;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                    // Truncate if too large
                    if ($tokenCount > self::MAX_TOKENS && is_array($data)) {
                        $logMessage = $logPrefix . "Token count exceeded limit ($tokenCount > " . self::MAX_TOKENS . "). Truncating API results." . PHP_EOL;
                        file_put_contents($webhookLogFile, $logMessage, FILE_APPEND);

                        // If data has a 'data' key with array, truncate that
                        if (isset($data['data']) && is_array($data['data'])) {
                            $data['data'] = $this->truncateResultsToTokenLimit($data['data'], self::MAX_TOKENS - 1000);
                            $data['_truncated'] = true;
                        }
                    }

                    $data['toolCalls'] = $this->toolCalls;
                    $data['_source'] = 'statbate_api';

                    return json_encode($data);

                } catch (\GuzzleHttp\Exception\ClientException $e) {
                    $statusCode = $e->getResponse()->getStatusCode();
                    $errorBody = $e->getResponse()->getBody()->getContents();

                    $logMessage = $logPrefix . "API error ($statusCode): " . $errorBody . PHP_EOL;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                    // If 404, entity not found - suggest using ClickHouse directly
                    if ($statusCode === 404) {
                        return json_encode([
                            'toolCalls' => $this->toolCalls,
                            'status' => 'not_found',
                            'message' => 'Entity not found via API. Try using run_select_query to search in ClickHouse directly.',
                            '_source' => 'statbate_api'
                        ]);
                    }

                    return json_encode([
                        'toolCalls' => $this->toolCalls,
                        'status' => 'error',
                        'message' => "API error ($statusCode): " . $errorBody,
                        '_source' => 'statbate_api'
                    ]);

                } catch (\Exception $e) {
                    $logMessage = $logPrefix . "API exception: " . $e->getMessage() . PHP_EOL;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                    return json_encode([
                        'toolCalls' => $this->toolCalls,
                        'status' => 'error',
                        'message' => 'API call failed: ' . $e->getMessage() . '. Try using run_select_query instead.',
                        '_source' => 'statbate_api'
                    ]);
                }
            })
        ];
    }
}
