<?php

namespace LaunchDarkly;

use Aws\Firehose\FirehoseClient;
use Aws\Exception\AwsException;
use Exception;

/**
 * FirehoseSender handles AWS Kinesis Firehose integration for sending experiment events to S3.
 */
class FirehoseSender
{
    private $firehoseClient;
    private $streamName;

    /**
     * Initialize Firehose client
     *
     * @param string|null $streamName Name of the Kinesis Firehose delivery stream
     * @throws \ValueError If stream name is not provided
     */
    public function __construct(?string $streamName = null)
    {
        $this->streamName = $streamName ?? $_ENV['FIREHOSE_STREAM_NAME'] ?? null;
        
        if (!$this->streamName) {
            throw new \ValueError("Firehose stream name must be provided via parameter or FIREHOSE_STREAM_NAME env var");
        }

        // Initialize Firehose client
        $config = [
            'version' => 'latest',
            'region' => $_ENV['AWS_REGION'] ?? 'us-east-1',
        ];

        // Add credentials if provided
        if (isset($_ENV['AWS_ACCESS_KEY_ID']) && isset($_ENV['AWS_SECRET_ACCESS_KEY'])) {
            $config['credentials'] = [
                'key' => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
            ];
            
            // Add session token if provided (for temporary credentials)
            if (isset($_ENV['AWS_SESSION_TOKEN']) && !empty($_ENV['AWS_SESSION_TOKEN'])) {
                $config['credentials']['token'] = $_ENV['AWS_SESSION_TOKEN'];
            }
        }
        // If credentials not provided, AWS SDK will use default credential chain
        // (IAM roles, environment variables, ~/.aws/credentials, etc.)

        $this->firehoseClient = new FirehoseClient($config);
    }

    /**
     * Extract only the user-defined context data (excluding internal LD properties)
     *
     * @param \LaunchDarkly\LDContext $context LaunchDarkly context object
     * @return array Dictionary containing only the user-defined context attributes
     */
    private function extractContextData($context): array
    {
        $contextData = [];

        // Extract basic attributes that are always present
        $contextData['key'] = $context->getKey();
        $contextData['kind'] = $context->getKind();

        // Get the context as JSON to access user-defined attributes
        try {
            // Convert context to JSON then decode to array
            $contextJson = $context->toJSON();
            $contextArray = json_decode($contextJson, true);

            if (is_array($contextArray)) {
                // Only include user-defined attributes (exclude internal LD properties)
                $internalProps = [
                    'privateAttributes',
                    'private_attributes',
                    'DEFAULT_KIND',
                    'MULTI_KIND',
                    'error',
                    'fullyQualifiedKey',
                    'fully_qualified_key',
                    'individualContextCount',
                    'individual_context_count',
                    'multiple',
                    'valid',
                ];

                foreach ($contextArray as $attrName => $value) {
                    if (!in_array($attrName, $internalProps) && $this->isJsonSerializable($value)) {
                        $contextData[$attrName] = $value;
                    }
                }
            }
        } catch (Exception $e) {
            // Fallback: try to get name if available
            if (method_exists($context, 'getName') && $context->getName()) {
                $contextData['name'] = $context->getName();
            }
        }

        return $contextData;
    }

    /**
     * Check if a value can be serialized to JSON
     *
     * @param mixed $value Value to check
     * @return bool True if serializable, False otherwise
     */
    private function isJsonSerializable($value): bool
    {
        try {
            json_encode($value);
            return json_last_error() === JSON_ERROR_NONE;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Send experiment evaluation event to Firehose
     *
     * @param string $flagKey The feature flag key
     * @param \LaunchDarkly\LDContext $context The evaluation context
     * @param \LaunchDarkly\EvaluationDetail $evaluationDetail The result of a flag evaluation
     * @return bool True if successful, False otherwise
     */
    public function sendExperimentEvent(
        string $flagKey,
        $context,
        $evaluationDetail
    ): bool {
        // Prepare the event data
        $eventData = [
            'timestamp' => date('c'), // ISO 8601 format
            'flag_key' => $flagKey,
            'evaluation_context' => $this->extractContextData($context),
            'flag_value' => $evaluationDetail->getValue(),
            'variation_index' => $evaluationDetail->getVariationIndex(),
            'reason_kind' => $this->getReasonKind($evaluationDetail),
            'metadata' => [
                'source' => 'launchdarkly-php-wrapper',
                'version' => '1.0',
            ],
        ];

        // Convert to JSON string
        $recordData = json_encode($eventData) . "\n"; // Add newline for Firehose

        try {
            // Send to Firehose
            $result = $this->firehoseClient->putRecord([
                'DeliveryStreamName' => $this->streamName,
                'Record' => [
                    'Data' => $recordData,
                ],
            ]);

            $recordId = $result->get('RecordId');
            echo "Successfully sent experiment event to Firehose. Record ID: {$recordId}\n";
            return true;
        } catch (AwsException $e) {
            echo "Error sending to Firehose: " . $e->getMessage() . "\n";
            return false;
        } catch (Exception $e) {
            echo "Error sending to Firehose: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Send multiple events in a batch (more efficient for high volume)
     *
     * @param array $events List of event data arrays
     * @return array|null Response from Firehose or null on error
     */
    public function sendBatchEvents(array $events): ?array
    {
        if (empty($events)) {
            return null;
        }

        // Prepare records for batch
        $records = [];
        foreach ($events as $event) {
            $recordData = json_encode($event) . "\n";
            $records[] = [
                'Data' => $recordData,
            ];
        }

        try {
            // Send batch to Firehose
            $result = $this->firehoseClient->putRecordBatch([
                'DeliveryStreamName' => $this->streamName,
                'Records' => $records,
            ]);

            $failedCount = $result->get('FailedPutCount', 0);
            echo "Successfully sent " . count($events) . " events to Firehose\n";
            
            if ($failedCount > 0) {
                echo "Failed to send {$failedCount} records\n";
            }

            return $result->toArray();
        } catch (AwsException $e) {
            echo "Error sending batch to Firehose: " . $e->getMessage() . "\n";
            return null;
        } catch (Exception $e) {
            echo "Error sending batch to Firehose: " . $e->getMessage() . "\n";
            return null;
        }
    }

    /**
     * Extract reason kind from evaluation detail
     *
     * @param \LaunchDarkly\EvaluationDetail $evaluationDetail
     * @return string|null
     */
    private function getReasonKind($evaluationDetail): ?string
    {
        $reason = $evaluationDetail->getReason();
        if (is_array($reason) && isset($reason['kind'])) {
            return $reason['kind'];
        }
        return null;
    }
}

