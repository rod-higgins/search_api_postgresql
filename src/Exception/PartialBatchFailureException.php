<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Exception for partial failures in batch operations.
 */
class PartialBatchFailureException extends GracefulDegradationException
{
  /**
   * Successful items.
   * {@inheritdoc}
   *
   * @var array
   */
  protected $successfulItems = [];

  /**
   * Failed items with their errors.
   * {@inheritdoc}
   *
   * @var array
   */
  protected $failedItems = [];

  /**
   * Constructs a PartialBatchFailureException.
   * {@inheritdoc}
   *
   * @param array $successful_items
   *   Items that were processed successfully.
   * @param array $failed_items
   *   Items that failed processing.
   * @param string $operation
   *   The operation that partially failed.
   */
  public function __construct(array $successful_items, array $failed_items, $operation = 'batch operation')
  {
    $this->successfulItems = $successful_items;
    $this->failedItems = $failed_items;

    $total = count($successful_items) + count($failed_items);
    $success_count = count($successful_items);
    $failure_count = count($failed_items);

    $message = "Partial failure in {$operation}: {$success_count}/{$total} items succeeded";
    $this->userMessage = "Some content may not be fully searchable due to " .
      "processing issues. Search functionality remains available.";
    $this->fallbackStrategy = 'continue_with_partial_results';
    // Only log if >50% failed.
    $this->shouldLog = $failure_count > ($total * 0.5);

    // 206 Partial Content
    parent::__construct($message, 206);
  }

  /**
   * Gets the successful items.
   * {@inheritdoc}
   *
   * @return array
   *   The successful items.
   */
  public function getSuccessfulItems()
  {
    return $this->successfulItems;
  }

  /**
   * Gets the failed items.
   * {@inheritdoc}
   *
   * @return array
   *   The failed items with their errors.
   */
  public function getFailedItems()
  {
    return $this->failedItems;
  }

  /**
   * Gets the success rate.
   * {@inheritdoc}
   *
   * @return float
   *   Success rate as a percentage.
   */
  public function getSuccessRate()
  {
    $total = count($this->successfulItems) + count($this->failedItems);
    return $total > 0 ? (count($this->successfulItems) / $total) * 100 : 0;
  }
}
