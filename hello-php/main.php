<?php

require_once __DIR__ . '/vendor/autoload.php';

use LaunchDarkly\LDClient;
use LaunchDarkly\LDContext;
use LaunchDarkly\FirehoseSender;
use LaunchDarkly\VariationDetailAnalyticsWrapper;
use Dotenv\Dotenv;

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Get configuration from environment variables
$sdkKey = $_ENV['LAUNCHDARKLY_SDK_KEY'] ?? null;
$flagKey = $_ENV['LAUNCHDARKLY_FLAG_KEY'] ?? null;

// Validate required environment variables
if (!$sdkKey) {
    echo "*** Please set the LAUNCHDARKLY_SDK_KEY env first\n";
    exit(1);
}

if (!$flagKey) {
    echo "*** Please set the LAUNCHDARKLY_FLAG_KEY env first\n";
    exit(1);
}

// Initialize LaunchDarkly client
try {
    $ldClient = new LDClient($sdkKey);
    echo "*** SDK successfully initialized\n";
} catch (Exception $e) {
    echo "*** SDK failed to initialize: " . $e->getMessage() . "\n";
    echo "*** Please check your internet connection and SDK credential for any typo.\n";
    exit(1);
}

// Initialize Firehose sender (with error handling)
$firehoseSender = null;
try {
    $firehoseSender = new FirehoseSender();
    echo "Firehose sender initialized successfully\n";
} catch (Exception $e) {
    echo "Failed to initialize Firehose sender: {$e->getMessage()}\n";
    echo "Continuing without Firehose integration...\n";
    // Continue without Firehose - wrapper will handle null gracefully
}

// Initialize variation detail analytics wrapper
$analyticsWrapper = new VariationDetailAnalyticsWrapper($ldClient, $firehoseSender);

// Create evaluation context
// This context should appear on your LaunchDarkly contexts dashboard
// soon after you run the demo.
$context = LDContext::builder('testing-user-v3')
    ->kind('user')
    ->set('tier', 'silver')
    ->build();

// Evaluate flag using wrapper (automatically exports if in experiment)
echo "\nEvaluating flag: {$flagKey}\n";
echo "Context: user={$context->getKey()}, kind={$context->getKind()}\n\n";

$evaluationDetail = $analyticsWrapper->variationDetail($flagKey, $context, 'Control');

// Display results
echo "\n*** Flag Evaluation Result ***\n";
echo "Flag key: {$flagKey}\n";
echo "Flag value: " . json_encode($evaluationDetail->getValue()) . "\n";
echo "Variation index: " . ($evaluationDetail->getVariationIndex() ?? 'N/A') . "\n";

$reason = $evaluationDetail->getReason();
// EvaluationReason object has getKind() and isInExperiment() methods
echo "Reason kind: " . $reason->getKind() . "\n";
if ($reason->isInExperiment()) {
    echo "In experiment: Yes\n";
} else {
    echo "In experiment: No\n";
}

echo "\n";

