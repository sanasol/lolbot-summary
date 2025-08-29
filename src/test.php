<?php

use Inspector\Configuration;
use Inspector\Inspector;
use NeuronAI\Observability\AgentMonitoring;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;

require_once __DIR__ . '/../vendor/autoload.php';


// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    $configDistPath = __DIR__ . '/../config/config.php.dist';
    if (file_exists($configDistPath)) {
        copy($configDistPath, $configPath);
        echo "Configuration file created at {$configPath}. Update it if needed.\n";
    } else {
        die('Error: Configuration file not found.');
    }
}
$config = require $configPath;


// Create a user message with the input
//$userMessage = new \NeuronAI\Chat\Messages\UserMessage("User sanasol says: hi");
//
//$inspector = new Inspector(
//    (new Configuration($config['inspector_ingestion_key']))
//        ->setTransport('curl')
//);
//
//// Initialize the ClickhouseAgent
//$agent = \App\Services\ClickhouseAgent::make($config)
//    ->observe(
//        new AgentMonitoring($inspector)
//    );
//
//// Get response from the agent
//$response = $agent->chat($userMessage);
//$content = $response->getContent();
//$usage = $response->getUsage();
//
//var_dump($usage);
//var_dump($content);
//
//$provider = new \NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider(
//    key: 'pa-dwCoxfN7QcW0n57a7ND9vuSY-yrSafF3bEjfqmOh01d',
//    model: 'voyage-code-3',
//);
//
//$embeddings = $provider->embedText("Hi, I'm Valerio, CTO of Inspector.dev");
//
//
//var_dump($embeddings);


$files = [
    // list of file paths...
];

$provider = new \NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider(
    key: 'pa-dwCoxfN7QcW0n57a7ND9vuSY-yrSafF3bEjfqmOh01d',
    model: 'voyage-code-3',
);
$vectorStore = new PineconeVectorStore(
    key: 'pcsk_69TdUV_AMvUnpgXuucbZj6KHPEWSNv1aGbKUjbjFghL6SMVcrdhBrceuBLPRSzvKWW8YvG',
    indexUrl: 'https://statbate-69i05hm.svc.aped-4627-b74a.pinecone.io'
);
//
//$documents = \NeuronAI\RAG\DataLoader\FileDataLoader::for('src/statbateCode/Controllers')
//    ->addReader('php', new \NeuronAI\RAG\DataLoader\TextFileReader())
//    ->getDocuments();
////var_dump($documents);
//
//$embeddedDocuments = $provider->embedDocuments($documents);
//
////var_dump($embeddedDocuments);
//
//// Save the embedded documents into the vector store for later use running your Agent.
//$vectorStore->addDocuments($embeddedDocuments);

$inspector = new Inspector(
    (new Configuration($config['inspector_ingestion_key']))
        ->setTransport('curl')
);

// Initialize the ClickhouseAgent
$rag = \App\Services\RagAgent::make($config)
    ->observe(
        new AgentMonitoring($inspector)
    )->withInstructions(
        "You are an AI Agent specialized in writing summaries for data from database.
            Database is clickhouse version 24.10.2.80
            database definition for clickhouse: " . $config['clickhouse_db_definition'] . "
            logs_v2 table available but for requests not more than 1 day
            room_activity each record is 1 minute but must be grouped, can contain duplicated records.
            Databases available: statbate, stripchat, camsoda, bongacams, mfc. Statbate database is chaturbate actually or CB
            By default use statbate database unless otherwise specified.
            DONT MAKE SUMMARIES OF THE ENTIRE DATABASE OR ANYTHING ELSE THAT IS NOT REQUESTED IN THE MESSAGE!
            DONT MAKE SUMMARIES THAT REQUESTED more than 30 days of data, limit all queries by date to less than 30 days ago.
            DONT ANSWER \"ALL TIME\" or similar requests ONLY 30 days or lesser Allowed
            By default use statbate database unless otherwise specified.
            all queries must include database name
            Data in database stored in UTC timezone.
            Current time: ".date('Y-m-d H:i:s').".
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
                
               Use html formatting for final result, dont use html tables
"
    );

//$rag = new \App\Services\RagAgent($config);

$resp = $rag->answer(
    new \NeuronAI\Chat\Messages\UserMessage('succubus_room rank by date')
);

var_dump($resp->getUsage());
var_dump($resp->getContent());
//$response = $rag->chat(new \NeuronAI\Chat\Messages\UserMessage("What is the best way to use inspector?"));
