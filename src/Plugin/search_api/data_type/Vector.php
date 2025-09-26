<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides a vector data type for AI embeddings.
 * {@inheritdoc}
 *
 * @SearchApiDataType(
 *   id = "vector",
 *   label = @Translation("Vector"),
 *   description = @Translation(
 *     "Vector field for AI text embeddings and similarity search"
 *   ),
 *   fallback_type = "text",
 *   prefix = "v"
 * )
 */
class Vector extends DataTypePluginBase
{

  /**
   * {@inheritdoc}
   */
  public function getValue($value)
  {
    // Handle different input formats for vectors.
    if (is_array($value)) {
      // Ensure all values are floats.
      return array_map('floatval', $value);
    }

    if (is_string($value)) {
      // Handle PostgreSQL vector format: [1.0,2.0,3.0].
      $value = trim($value, '[]');
      if (!empty($value)) {
        return array_map('floatval', explode(',', $value));
      }
    }

    // Return empty array for invalid input.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareValue($value)
  {
    $value = $this->getValue($value);

    // Validate that we have a non-empty numeric array.
    if (empty($value) || !is_array($value)) {
      return null;
    }

    // Ensure all values are numeric.
    foreach ($value as $component) {
      if (!is_numeric($component)) {
        return null;
      }
    }

    return $value;
  }
}
