<?php
namespace App\Services;

use App\Providers\OpenRouterAi;
use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use ClickHouseDB\Client as ClickHouseClient;

class ClickhouseAgent extends Agent
{
    private static array $config;

    /**
     * Approximate token limit for AI responses
     */
    private const MAX_TOKENS = 200000;

    public function __construct(array $config = [])
    {
        self::$config = $config;
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
        return new SystemPrompt(
            background: ["You are an AI Agent specialized in writing summaries for data from database.
            Database is clickhouse version 24.10.2.80
            database definition for clickhouse: " . self::$config['clickhouse_db_definition'] . "
            Databases available: statbate, stripchat, camsoda, bongacams, mfc. Statbate database is chaturbate actually or CB
            By default use statbate database unless otherwise specified.
            DONT MAKE SUMMARIES OF THE ENTIRE DATABASE OR ANYTHING ELSE THAT IS NOT REQUESTED IN THE MESSAGE!
            DONT MAKE SUMMARIES THAT REQUESTED more than 14 days of data, limit all queries by date to less than 14 days ago or by requestor\'s preference if its not more than 14 days.
            By default use statbate database unless otherwise specified.
            all queries must include database name
            Data in database stored in UTC timezone.
            Current time: ".date('Y-m-d H:i:s').".
                use clickhouse CTE queries to avoid joins and too many queries

                find the requested room or donator in the database.
                NAME MUST BE IN LOWERCASE.
                Use the tools you have available to retrieve the requested data.
                Write the analysis and write it down.
                
                Provide a summary of the content.
                Include any relevant details that may be useful for understanding the content.
                Include detailed information about what queries made to DB with all important notes, dont report raw queries, but report what tables used and what conditions used
                
               Use plain text response without markdown or html formatting
"]
        );
    }

    protected function tools(): array
    {
        return [
            Tool::make(
                'list_databases',
                'List available ClickHouse databases.',
            )->addProperty(
                new ToolProperty(
                    name: 'test',
                    type: 'string',
                    description: 'test name',
                    required: false
                )
            )->setCallable(function () {
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

                return json_encode(['databases' => $result]);
            }),

            Tool::make(
                'list_tables',
                'List available ClickHouse tables in a database, including schema, comment, row count, and column count.',
            )->addProperty(
                new ToolProperty(
                    name: 'database',
                    type: 'string',
                    description: 'Database name',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'like',
                    type: 'string',
                    description: 'Filter tables by pattern',
                    required: false
                )
            )->setCallable(function (string $database, ?string $like = null) {
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
                    $query .= " LIKE " . $clickhouse->quote($like);
                }

                // Get tables
                $tablesStatement = $clickhouse->select($query);
                $tables = array_column($tablesStatement->rows(), 'name');

                // Get table comments
                $tableCommentsQuery = "SELECT name, comment FROM system.tables WHERE database = " . $clickhouse->quote(trim($escapedDatabase, '`'));
                $tableCommentsResult = $clickhouse->select($tableCommentsQuery)->rows();
                $tableComments = [];
                foreach ($tableCommentsResult as $row) {
                    $tableComments[$row['name']] = $row['comment'];
                }

                // Get column comments
                $columnCommentsQuery = "SELECT table, name, comment FROM system.columns WHERE database = " . $clickhouse->quote(trim($escapedDatabase, '`'));
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

                return json_encode($tablesInfo);
            }),

            Tool::make(
                'run_select_query',
                'Run a SELECT query in a ClickHouse database',
            )->addProperty(
                new ToolProperty(
                    name: 'query',
                    type: 'string',
                    description: 'Clickhouse SELECT query to run.',
                    required: true
                )
            )->setCallable(function (string $query) {
                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [tool call] ";
                $webhookLogFile = self::$config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

                // Log the results
                $logMessage = $logPrefix . "Executing tool run_select_query with query: " . $query . PHP_EOL;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                try {
                    // Validate that this is a SELECT query for safety
                    $trimmedQuery = trim($query);
                    if (!preg_match('/^SELECT\s/i', $trimmedQuery) && !preg_match('/^with\s/i', $trimmedQuery)) {
                        return json_encode([
                            'status' => 'error',
                            'message' => 'Only SELECT queries are allowed for security reasons'
                        ]);
                    }

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
                        $optimizedResult['query'] = $query;
                        if (count($optimizedResult['rows']) === 0) {
                            $optimizedResult['comment'] = 'No results found for this query';
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
                        'status' => 'error',
                        'message' => 'Query failed: ' . $e->getMessage()
                    ]);
                }
            })
        ];
    }
}
