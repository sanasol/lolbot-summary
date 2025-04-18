<?php
namespace App\Services;

use App\Providers\OpenRouterAi;
use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PDO;

class ClickhouseAgent extends Agent
{
    private static array $config;

    public function __construct(array $config = [])
    {
        self::$config = $config;
    }

    protected function provider(): AIProviderInterface
    {
        // return an AI provider instance (Anthropic, OpenAI, Mistral, etc.)
        return new Anthropic(
            key: self::$config['anthropic']['key'],
            model: self::$config['anthropic']['model'],
        );
    }

    public function instructions(): string
    {
        return new SystemPrompt(
            background: ["You are an AI Agent specialized in writing summaries for data from database.
            
            database definition for clickhouse: " . self::$config['clickhouse_db_definition'] . "
            
            DONT MAKE SUMMARIES OF THE ENTIRE DATABASE OR ANYTHING ELSE THAT IS NOT REQUESTED IN THE MESSAGE!
            DONT MAKE SUMMARIES THAT REQUESTED more than 14 days of data, limit all queries by date to less than 14 days ago or by requestor\'s preference if its not more than 14 days.
            By default use statbate database unless otherwise specified.
            all queries must include database name
            Data in database stored in UTC timezone.
            Current time: ".date('Y-m-d H:i:s').".
            "],
            steps: [
                "Find the requested room or member in the database. Databases available: statbate, stripchat, camsoda, bongacams, mfc. Statbate database is chaturbate actually or CB",
                "By default use statbate database unless otherwise specified.",
                "Use the tools you have available to retrieve the requested data.",
                "Write the analysis and write it down.",
            ],
            output: [
                "Provide a short summary of the content.",
                "Include any relevant details that may be useful for understanding the content.",
            ]
        );
    }

    protected function tools(): array
    {
        return [
            Tool::make(
                'list_databases',
                'List available ClickHouse databases.',
            )->setCallable(function () {
                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [tool call] ";
                $webhookLogFile = self::$config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

                $clickhouse = new PDO("mysql:host=" . self::$config['clickhouse']['host'] . ";port=" . self::$config['clickhouse']['port'], self::$config['clickhouse']['username'], self::$config['clickhouse']['password']);
                $clickhouse->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
                $query = "SHOW DATABASES";

                $result = $clickhouse->query($query)->fetchAll(PDO::FETCH_ASSOC);

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
                $clickhouse = new PDO("mysql:host=" . self::$config['clickhouse']['host'] . ";port=" . self::$config['clickhouse']['port'], self::$config['clickhouse']['username'], self::$config['clickhouse']['password']);
                $clickhouse->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Escape database name for safety
                $database = '`' . str_replace('`', '``', $database) . '`';

                // Build the query
                $query = "SHOW TABLES FROM {$database}";
                if ($like) {
                    $query .= " LIKE " . $clickhouse->quote($like);
                }

                // Get tables
                $tables = $clickhouse->query($query)->fetchAll(PDO::FETCH_COLUMN);

                // Get table comments
                $tableCommentsQuery = "SELECT name, comment FROM system.tables WHERE database = " . $clickhouse->quote(trim($database, '`'));
                $tableCommentsResult = $clickhouse->query($tableCommentsQuery)->fetchAll(PDO::FETCH_KEY_PAIR);

                // Get column comments
                $columnCommentsQuery = "SELECT table, name, comment FROM system.columns WHERE database = " . $clickhouse->quote(trim($database, '`'));
                $columnCommentsResult = $clickhouse->query($columnCommentsQuery)->fetchAll(PDO::FETCH_ASSOC);

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
                    $schemaQuery = "DESCRIBE TABLE {$database}.`" . str_replace('`', '``', $table) . "`";
                    $schemaResult = $clickhouse->query($schemaQuery)->fetchAll(PDO::FETCH_ASSOC);

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
                    $rowCountQuery = "SELECT count() FROM {$database}.`" . str_replace('`', '``', $table) . "`";
                    $rowCount = $clickhouse->query($rowCountQuery);
                    if (!$rowCount) {
                        $rowCount = 0;
                    } else {
                        $rowCount = $rowCount->fetchColumn();
                    }

                    // Get create table query
                    $createTableQuery = "SHOW CREATE TABLE {$database}.`" . str_replace('`', '``', $table) . "`";
                    $createTableResult = $clickhouse->query($createTableQuery)->fetchColumn();

                    $tablesInfo[] = [
                        'database' => trim($database, '`'),
                        'name' => $table,
                        'comment' => $tableCommentsResult[$table] ?? null,
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
                try {
                    // Validate that this is a SELECT query for safety
                    $trimmedQuery = trim($query);
                    if (!preg_match('/^SELECT\s/i', $trimmedQuery)) {
                        return json_encode([
                            'status' => 'error',
                            'message' => 'Only SELECT queries are allowed for security reasons'
                        ]);
                    }

                    $clickhouse = new PDO("mysql:host=" . self::$config['clickhouse']['host'] . ";port=" . self::$config['clickhouse']['port'], self::$config['clickhouse']['username'], self::$config['clickhouse']['password']);
                    $clickhouse->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    // Set a timeout for the query (30 seconds)
                    $clickhouse->setAttribute(PDO::ATTR_TIMEOUT, 30);

                    // Force read-only mode
                    $clickhouse->exec("SET readonly=1");

                    // Execute the query
                    $statement = $clickhouse->query($query);
                    if (!$statement) {
                        return json_encode([
                            'status' => 'error',
                            'message' => 'Query failed.'
                        ]);
                    }
                    $result = $statement->fetchAll(PDO::FETCH_ASSOC);

                    // Log the results
                    $logMessage = $logPrefix . "Executing tool run_select_query with query: " . $query . PHP_EOL;
                    $logMessage .= $logPrefix . "Results: " . json_encode($result). PHP_EOL;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                    return json_encode($result);
                } catch (\PDOException $e) {
                    $logMessage = $logPrefix . "Error executing tool run_select_query with query: " . $query . PHP_EOL;
                    $logMessage .= $logPrefix . "Error message: " . $e->getMessage(). PHP_EOL;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                    return json_encode([
                        'status' => 'error',
                        'message' => 'Query failed: ' . $e->getMessage()
                    ]);
                } catch (\Exception $e) {
                    $logMessage = $logPrefix . "Error executing tool run_select_query with query: " . $query . PHP_EOL;
                    $logMessage .= $logPrefix . "Error message: " . $e->getMessage(). PHP_EOL;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                    return json_encode([
                        'status' => 'error',
                        'message' => 'Unexpected error: ' . $e->getMessage()
                    ]);
                }
            })
        ];
    }
}
