<?php

namespace LaunchDarkly;

/**
 * VariationDetailAnalyticsWrapper wraps LDClient::variationDetail() functionality
 * and automatically sends experiment data to analytics (Firehose/S3).
 * 
 * This follows the same pattern used in LaunchDarkly Mobile SDK integrations,
 * where wrapper methods intercept flag evaluations and forward experiment data.
 */
class VariationDetailAnalyticsWrapper
{
    private $ldClient;
    private $firehoseSender;

    /**
     * Initialize wrapper with LD client and Firehose sender
     *
     * @param \LaunchDarkly\LDClient $ldClient LaunchDarkly client instance
     * @param FirehoseSender|null $firehoseSender Firehose sender instance (optional)
     */
    public function __construct($ldClient, ?FirehoseSender $firehoseSender = null)
    {
        $this->ldClient = $ldClient;
        $this->firehoseSender = $firehoseSender;
    }

    /**
     * Wrapper around LDClient::variationDetail() that automatically sends
     * experiment data to analytics if the user is in an experiment.
     *
     * @param string $flagKey The feature flag key
     * @param \LaunchDarkly\LDContext $context The evaluation context
     * @param mixed $defaultValue Default value if flag evaluation fails
     * @return \LaunchDarkly\EvaluationDetail The evaluation result
     */
    public function variationDetail(
        string $flagKey,
        $context,
        $defaultValue = false
    ) {
        // Call the original variationDetail method
        $evaluationDetail = $this->ldClient->variationDetail($flagKey, $context, $defaultValue);

        // Send to analytics if in an experiment
        $this->sendToAnalytics($flagKey, $context, $evaluationDetail);

        return $evaluationDetail;
    }

    /**
     * Private method to handle analytics forwarding.
     * Checks if the evaluation indicates an experiment and sends data to Firehose if applicable.
     *
     * @param string $flagKey The feature flag key
     * @param \LaunchDarkly\LDContext $context The evaluation context
     * @param \LaunchDarkly\EvaluationDetail $evaluationDetail The evaluation result
     * @return void
     */
    private function sendToAnalytics(
        string $flagKey,
        $context,
        $evaluationDetail
    ): void {
        // Check if the user is in an experiment by looking at the reason
        $reason = $evaluationDetail->getReason();
        $inExperiment = false;

        if (is_array($reason)) {
            // Check if 'inExperiment' key exists and is true
            $inExperiment = isset($reason['inExperiment']) && $reason['inExperiment'] === true;
        }

        // Only send to analytics if in an experiment and Firehose sender is available
        if ($inExperiment && $this->firehoseSender !== null) {
            echo "--------------------------------\n";
            echo "Experiment detected for flag {$flagKey} - sending to Firehose\n";
            
            $success = $this->firehoseSender->sendExperimentEvent($flagKey, $context, $evaluationDetail);
            
            if ($success) {
                echo "Successfully sent experiment event to Firehose\n";
            } else {
                echo "Failed to send experiment event to Firehose\n";
            }
            echo "--------------------------------\n";
        } else {
            if (!$inExperiment) {
                echo "User is NOT in an experiment for flag {$flagKey}!\n";
            }
        }
    }
}

