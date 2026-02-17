<?php

/**
 * Create a State Machine in moto for the demo environment.
 *
 * This script is called from docker-entrypoint.sh at container startup.
 * It is idempotent — if the State Machine already exists, it is skipped.
 */

require __DIR__ . '/../vendor/autoload.php';

$endpoint = getenv('SFN_ENDPOINT') ?: 'http://localhost:5001';
$region = getenv('AWS_DEFAULT_REGION') ?: 'ap-northeast-1';

$client = new \Aws\Sfn\SfnClient([
    'region' => $region,
    'version' => 'latest',
    'endpoint' => $endpoint,
    'credentials' => [
        'key' => getenv('AWS_ACCESS_KEY_ID') ?: 'test',
        'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: 'test',
    ],
]);

$definition = file_get_contents(__DIR__ . '/../stepfunctions/state-machine.json');

try {
    $client->createStateMachine([
        'name' => 'DemoSchedulerStateMachine',
        'definition' => $definition,
        'roleArn' => 'arn:aws:iam::000000000000:role/stepfunctions-role',
    ]);
    echo "State machine created successfully.\n";
} catch (\Aws\Exception\AwsException $e) {
    if ($e->getAwsErrorCode() === 'StateMachineAlreadyExists') {
        echo "State machine already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
