<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api\SearchApiException;

/**
 * Maps Search API fields to PostgreSQL columns with vector support and data type validation.
 */
class FieldMapper {

  /**
   * The backend configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Data type mapping from Search API to PostgreSQL with validation.
   *
   * @var array
   */
  protected $typeMapping = [
    'text' => 'TEXT',
    'string' => 'VARCHAR(255)',
    'integer' => 'INTEGER',
    'decimal' => 'DECIMAL(10,2)',
    'date' => 'TIMESTAMP',
    'boolean' => 'BOOLEAN',
    'postgresql_fulltext' => 'TEXT',
    'vector' => 'VECTOR',
  ];

  /**
   * Valid Search API data types.
   *
   * @var array
   */
  protected static $validSearchApiTypes = [
    'text', 'string', 'integer', 'decimal', 'date', 'boolean', 
    'postgresql_fulltext', 'vector'
  ];

  /**
   * Valid PostgreSQL data types for Search API.
   *
   * @var array
   */
  protected static $validPostgreSQLTypes = [
    'TEXT', 'VARCHAR(255)', 'INTEGER', 'BIGINT', 'DECIMAL(10,2)', 
    'NUMERIC', 'TIMESTAMP', 'TIMESTAMPTZ', 'DATE', 'BOOLEAN', 
    'TSVECTOR', 'JSON', 'JSONB'
  ];

  /**
   * Constructs a FieldMapper object.
   *
   * @param array $config
   *   The backend configuration.
   */
  public function __construct(array $config) {
    $this->config = $config;
  }

  /**
   * Gets field definitions for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Field definitions keyed by field ID.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If field validation fails.
   */
  public function getFieldDefinitions(IndexInterface $index) {
    $definitions = [];

    foreach ($index->getFields() as $field_id => $field) {
      // Validate field ID
      $this->validateFieldId($field_id);
      
      $type = $field->getType();
      $this->validateSearchApiType($type);
      
      $pg_type = $this->mapSearchApiTypeToPostgreSQL($type);

      // Handle vector type with dimensions
      if ($type === 'vector' && isset($this->config['ai_embeddings']['azure_ai']['dimensions'])) {
        $dimensions = (int) $this->config['ai_embeddings']['azure_ai']['dimensions'];
        $this->validateVectorDimensions($dimensions);
        $pg_type = "VECTOR({$dimensions})";
      }

      $definitions[$field_id] = [
        'type' => $pg_type,
        'null' => TRUE,
        'searchable' => $this->isSearchableType($type),
        'facetable' => $this->isFacetableType($type),
        'sortable' => $this->isSortableType($type),
        'vector' => $type === 'vector',
      ];
    }

    // Add automatic embedding fields if AI embeddings are enabled
    if ($this->config['ai_embeddings']['enabled'] ?? FALSE) {
      $dimensions = (int) ($this->config['ai_embeddings']['azure_ai']['dimensions'] ?? 1536);
      $this->validateVectorDimensions($dimensions);
      
      $definitions['embedding_vector'] = [
        'type' => "VECTOR({$dimensions})",
        'null' => TRUE,
        'searchable' => FALSE,
        'facetable' => FALSE,
        'sortable' => FALSE,
        'vector' => TRUE,
        'auto_generated' => TRUE,
      ];
    }

    return $definitions;
  }

  /**
   * Validates a field ID.
   *
   * @param string $field_id
   *   The field ID to validate.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the field ID is invalid.
   */
  protected function validateFieldId($field_id) {
    if (empty($field_id) || !is_string($field_id)) {
      throw new SearchApiException("Field ID cannot be empty.");
    }

    // Field IDs should follow Drupal naming conventions
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $field_id)) {
      throw new SearchApiException("Invalid field ID: '{$field_id}'. Must start with lowercase letter and contain only lowercase letters, numbers, and underscores.");
    }

    // Check length limit
    if (strlen($field_id) > 63) {
      throw new SearchApiException("Field ID '{$field_id}' is too long. Maximum 63 characters allowed.");
    }
  }

  /**
   * Validates a Search API data type.
   *
   * @param string $type
   *   The Search API type to validate.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the type is invalid.
   */
  protected function validateSearchApiType($type) {
    if (!in_array($type, self::$validSearchApiTypes)) {
      throw new SearchApiException("Invalid Search API data type: '{$type}'. Allowed types: " . implode(', ', self::$validSearchApiTypes));
    }
  }

  /**
   * Maps Search API type to PostgreSQL type with validation.
   *
   * @param string $search_api_type
   *   The Search API type.
   *
   * @return string
   *   The PostgreSQL type.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If mapping fails.
   */
  protected function mapSearchApiTypeToPostgreSQL($search_api_type) {
    if (!isset($this->typeMapping[$search_api_type])) {
      throw new SearchApiException("No PostgreSQL mapping found for Search API type: '{$search_api_type}'");
    }

    $pg_type = $this->typeMapping[$search_api_type];
    $this->validatePostgreSQLType($pg_type);
    
    return $pg_type;
  }

  /**
   * Validates a PostgreSQL data type.
   *
   * @param string $type
   *   The PostgreSQL type to validate.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the type is invalid.
   */
  protected function validatePostgreSQLType($type) {
    // Handle parameterized types
    if (preg_match('/^VARCHAR\(\d+\)$/', $type)) {
      return;
    }
    
    if (preg_match('/^DECIMAL\(\d+,\d+\)$/', $type)) {
      return;
    }
    
    if (preg_match('/^NUMERIC(\(\d+(,\d+)?\))?$/', $type)) {
      return;
    }
    
    // Handle vector types
    if (preg_match('/^VECTOR\(\d+\)$/', $type)) {
      return;
    }
    
    if (!in_array($type, self::$validPostgreSQLTypes)) {
      throw new SearchApiException("Invalid PostgreSQL data type: '{$type}'. Allowed types: " . implode(', ', self::$validPostgreSQLTypes));
    }
  }

  /**
   * Validates vector dimensions.
   *
   * @param int $dimensions
   *   The number of dimensions.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If dimensions are invalid.
   */
  protected function validateVectorDimensions($dimensions) {
    if (!is_int($dimensions) || $dimensions < 1 || $dimensions > 16000) {
      throw new SearchApiException("Invalid vector dimensions: {$dimensions}. Must be an integer between 1 and 16000.");
    }
  }

  /**
   * Prepares a field value for database storage.
   *
   * @param mixed $value
   *   The field value.
   * @param string $type
   *   The field type.
   *
   * @return mixed
   *   The prepared value.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If value preparation fails.
   */
  public function prepareFieldValue($value, $type) {
    if ($value === NULL) {
      return NULL;
    }

    // Validate the type first
    $this->validateSearchApiType($type);

    switch ($type) {
      case 'date':
        return $this->prepareDateValue($value);

      case 'boolean':
        return $this->prepareBooleanValue($value);

      case 'integer':
        return $this->prepareIntegerValue($value);

      case 'decimal':
        return $this->prepareDecimalValue($value);

      case 'text':
      case 'postgresql_fulltext':
        return $this->prepareTextValue($value);

      case 'string':
        return $this->prepareStringValue($value);

      case 'vector':
        return $this->prepareVectorValue($value);

      default:
        // For unknown types, return as string but validate
        if (is_scalar($value)) {
          return (string) $value;
        }
        throw new SearchApiException("Cannot prepare non-scalar value for type: {$type}");
    }
  }

  /**
   * Prepares a date value.
   *
   * @param mixed $value
   *   The date value.
   *
   * @return string|null
   *   The prepared date string.
   */
  protected function prepareDateValue($value) {
    if (is_numeric($value)) {
      // Unix timestamp
      $timestamp = (int) $value;
      if ($timestamp < 0 || $timestamp > PHP_INT_MAX) {
        throw new SearchApiException("Invalid timestamp: {$value}");
      }
      return date('Y-m-d H:i:s', $timestamp);
    }
    
    if (is_string($value)) {
      // Validate date string format
      $parsed = strtotime($value);
      if ($parsed === FALSE) {
        throw new SearchApiException("Invalid date format: {$value}");
      }
      return date('Y-m-d H:i:s', $parsed);
    }
    
    throw new SearchApiException("Invalid date value type: " . gettype($value));
  }

  /**
   * Prepares a boolean value.
   *
   * @param mixed $value
   *   The boolean value.
   *
   * @return string
   *   The prepared boolean string.
   */
  protected function prepareBooleanValue($value) {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    
    if (is_numeric($value)) {
      return $value ? 'true' : 'false';
    }
    
    if (is_string($value)) {
      $lower = strtolower(trim($value));
      if (in_array($lower, ['true', '1', 'yes', 'on'])) {
        return 'true';
      }
      if (in_array($lower, ['false', '0', 'no', 'off', ''])) {
        return 'false';
      }
    }
    
    throw new SearchApiException("Invalid boolean value: " . var_export($value, TRUE));
  }

  /**
   * Prepares an integer value.
   *
   * @param mixed $value
   *   The integer value.
   *
   * @return int
   *   The prepared integer.
   */
  protected function prepareIntegerValue($value) {
    if (is_int($value)) {
      return $value;
    }
    
    if (is_numeric($value)) {
      $int_value = (int) $value;
      // Check for overflow/underflow
      if ($int_value < PHP_INT_MIN || $int_value > PHP_INT_MAX) {
        throw new SearchApiException("Integer value out of range: {$value}");
      }
      return $int_value;
    }
    
    throw new SearchApiException("Invalid integer value: " . var_export($value, TRUE));
  }

  /**
   * Prepares a decimal value.
   *
   * @param mixed $value
   *   The decimal value.
   *
   * @return float
   *   The prepared decimal.
   */
  protected function prepareDecimalValue($value) {
    if (is_float($value) || is_int($value)) {
      return (float) $value;
    }
    
    if (is_numeric($value)) {
      $float_value = (float) $value;
      // Check for infinity or NaN
      if (!is_finite($float_value)) {
        throw new SearchApiException("Invalid decimal value (infinity or NaN): {$value}");
      }
      return $float_value;
    }
    
    throw new SearchApiException("Invalid decimal value: " . var_export($value, TRUE));
  }

  /**
   * Prepares text values for full-text indexing.
   *
   * @param mixed $value
   *   The text value.
   *
   * @return string
   *   The prepared text.
   */
  protected function prepareTextValue($value) {
    if (is_array($value)) {
      $value = implode(' ', array_filter($value, 'is_scalar'));
    }

    if (!is_scalar($value)) {
      throw new SearchApiException("Text value must be scalar or array of scalars.");
    }

    $value = (string) $value;

    // Strip HTML tags and normalize whitespace
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

    // Limit text length to prevent memory issues
    $max_length = 1000000; // 1MB
    if (strlen($value) > $max_length) {
      $value = substr($value, 0, $max_length);
    }

    return $value;
  }

  /**
   * Prepares a string value.
   *
   * @param mixed $value
   *   The string value.
   *
   * @return string
   *   The prepared string.
   */
  protected function prepareStringValue($value) {
    if (!is_scalar($value)) {
      throw new SearchApiException("String value must be scalar.");
    }

    $value = (string) $value;

    // Limit string length to field capacity
    $max_length = 255;
    if (strlen($value) > $max_length) {
      $value = substr($value, 0, $max_length);
    }

    return $value;
  }

  /**
   * Prepares vector values for database storage.
   *
   * @param mixed $value
   *   The vector value.
   *
   * @return string
   *   The prepared vector in PostgreSQL format.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If vector preparation fails.
   */
  protected function prepareVectorValue($value) {
    if (is_string($value)) {
      // Already in PostgreSQL vector format - validate it
      if (preg_match('/^\[[\d\.,\-\s]+\]$/', $value)) {
        $this->validateVectorString($value);
        return $value;
      }
      throw new SearchApiException("Invalid vector string format: {$value}");
    }

    if (is_array($value)) {
      // Convert array to PostgreSQL vector format
      $this->validateVectorArray($value);
      return '[' . implode(',', array_map('floatval', $value)) . ']';
    }

    throw new SearchApiException("Vector value must be array or string, got: " . gettype($value));
  }

  /**
   * Validates a vector array.
   *
   * @param array $vector
   *   The vector array.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If validation fails.
   */
  protected function validateVectorArray(array $vector) {
    if (empty($vector)) {
      throw new SearchApiException("Vector cannot be empty.");
    }

    if (count($vector) > 16000) {
      throw new SearchApiException("Vector too large: " . count($vector) . " dimensions (max 16000).");
    }

    foreach ($vector as $index => $value) {
      if (!is_numeric($value)) {
        throw new SearchApiException("Vector component at index {$index} is not numeric: " . var_export($value, TRUE));
      }
      
      $float_val = (float) $value;
      if (!is_finite($float_val)) {
        throw new SearchApiException("Vector component at index {$index} is infinite or NaN: {$value}");
      }
    }
  }

  /**
   * Validates a vector string.
   *
   * @param string $vector_string
   *   The vector string.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If validation fails.
   */
  protected function validateVectorString($vector_string) {
    $vector_string = trim($vector_string, '[]');
    if (empty($vector_string)) {
      throw new SearchApiException("Vector string cannot be empty.");
    }

    $components = explode(',', $vector_string);
    if (count($components) > 16000) {
      throw new SearchApiException("Vector string too large: " . count($components) . " dimensions (max 16000).");
    }

    foreach ($components as $index => $component) {
      $trimmed = trim($component);
      if (!is_numeric($trimmed)) {
        throw new SearchApiException("Vector string component at index {$index} is not numeric: {$trimmed}");
      }
      
      $float_val = (float) $trimmed;
      if (!is_finite($float_val)) {
        throw new SearchApiException("Vector string component at index {$index} is infinite or NaN: {$trimmed}");
      }
    }
  }

  /**
   * Creates a result item from a database row.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $row
   *   The database row.
   *
   * @return \Drupal\search_api\Item\ItemInterface
   *   The result item.
   */
  public function createResultItem(IndexInterface $index, array $row) {
    if (!isset($row['search_api_id'])) {
      throw new SearchApiException("Row missing required 'search_api_id' field.");
    }

    $item_id = $row['search_api_id'];
    $item = Utility::createItem($index, $item_id);

    // Set the score if available
    if (isset($row['search_api_relevance'])) {
      $score = $this->prepareDecimalValue($row['search_api_relevance']);
      $item->setScore($score);
    }

    // Set vector similarity score if available
    if (isset($row['vector_similarity'])) {
      $similarity = $this->prepareDecimalValue($row['vector_similarity']);
      $item->setExtraData('vector_similarity', $similarity);
    }

    // Set hybrid score if available
    if (isset($row['hybrid_score'])) {
      $hybrid_score = $this->prepareDecimalValue($row['hybrid_score']);
      $item->setScore($hybrid_score);
      $item->setExtraData('vector_score', $row['vector_score'] ?? 0);
      $item->setExtraData('fulltext_score', $row['text_score'] ?? 0);
    }

    // Set field values
    foreach ($index->getFields() as $field_id => $field) {
      if (isset($row[$field_id])) {
        $value = $this->convertFieldValue($row[$field_id], $field->getType());
        $item->setField($field_id, $value);
      }
    }

    return $item;
  }

  /**
   * Converts a database value back to its original type.
   *
   * @param mixed $value
   *   The database value.
   * @param string $type
   *   The field type.
   *
   * @return mixed
   *   The converted value.
   */
  protected function convertFieldValue($value, $type) {
    if ($value === NULL) {
      return NULL;
    }

    $this->validateSearchApiType($type);

    switch ($type) {
      case 'date':
        return strtotime($value);

      case 'boolean':
        return $value === 'true' || $value === TRUE;

      case 'integer':
        return $this->prepareIntegerValue($value);

      case 'decimal':
        return $this->prepareDecimalValue($value);

      case 'vector':
        return $this->convertVectorValue($value);

      default:
        return $value;
    }
  }

  /**
   * Converts PostgreSQL vector format to array.
   *
   * @param string $value
   *   The PostgreSQL vector value.
   *
   * @return array
   *   The vector as array.
   */
  protected function convertVectorValue($value) {
    if (is_array($value)) {
      $this->validateVectorArray($value);
      return $value;
    }

    if (is_string($value)) {
      $this->validateVectorString($value);
      $value = trim($value, '[]');
      return array_map('floatval', explode(',', $value));
    }

    throw new SearchApiException("Invalid vector value for conversion: " . gettype($value));
  }

  /**
   * Checks if a field type is searchable.
   *
   * @param string $type
   *   The field type.
   *
   * @return bool
   *   TRUE if searchable, FALSE otherwise.
   */
  protected function isSearchableType($type) {
    return in_array($type, ['text', 'postgresql_fulltext', 'string']);
  }

  /**
   * Checks if a field type supports vector similarity search.
   *
   * @param string $type
   *   The field type.
   *
   * @return bool
   *   TRUE if vector searchable, FALSE otherwise.
   */
  protected function isVectorSearchableType($type) {
    return $type === 'vector';
  }

  /**
   * Checks if a field type is facetable.
   *
   * @param string $type
   *   The field type.
   *
   * @return bool
   *   TRUE if facetable, FALSE otherwise.
   */
  protected function isFacetableType($type) {
    return in_array($type, ['string', 'integer', 'boolean', 'date']);
  }

  /**
   * Checks if a field type is sortable.
   *
   * @param string $type
   *   The field type.
   *
   * @return bool
   *   TRUE if sortable, FALSE otherwise.
   */
  protected function isSortableType($type) {
    return in_array($type, ['string', 'integer', 'decimal', 'date', 'boolean']);
  }

  /**
   * Gets searchable fields for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Array of searchable field IDs.
   */
  public function getSearchableFields(IndexInterface $index) {
    $searchable_fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      $this->validateFieldId($field_id);
      if ($this->isSearchableType($field->getType())) {
        $searchable_fields[] = $field_id;
      }
    }

    return $searchable_fields;
  }

  /**
   * Gets vector searchable fields for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Array of vector searchable field IDs.
   */
  public function getVectorSearchableFields(IndexInterface $index) {
    $vector_fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      $this->validateFieldId($field_id);
      if ($this->isVectorSearchableType($field->getType())) {
        $vector_fields[] = $field_id;
      }
    }

    // Add automatic embedding field if enabled
    if ($this->config['ai_embeddings']['enabled'] ?? FALSE) {
      $vector_fields[] = 'embedding_vector';
    }

    return $vector_fields;
  }

  /**
   * Gets fields that should have embeddings generated.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Array of field IDs that should have embeddings.
   */
  public function getEmbeddingSourceFields(IndexInterface $index) {
    $embedding_fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      $this->validateFieldId($field_id);
      $type = $field->getType();
      $configuration = $field->getConfiguration();

      // Include text fields that are marked for embedding or all text fields if auto-embedding is enabled
      if (in_array($type, ['text', 'postgresql_fulltext'])) {
        if (!empty($configuration['generate_embedding']) || ($this->config['ai_embeddings']['enabled'] ?? FALSE)) {
          $embedding_fields[] = $field_id;
        }
      }
    }

    return $embedding_fields;
  }

  /**
   * Generates searchable text from multiple fields for embedding.
   *
   * @param array $field_values
   *   Array of field values keyed by field ID.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   Combined text for embedding generation.
   */
  public function generateEmbeddingText(array $field_values, IndexInterface $index) {
    $embedding_source_fields = $this->getEmbeddingSourceFields($index);
    $text_parts = [];

    foreach ($embedding_source_fields as $field_id) {
      if (isset($field_values[$field_id]) && !empty($field_values[$field_id])) {
        $value = $field_values[$field_id];
        if (is_array($value)) {
          $value = implode(' ', array_filter($value, 'is_scalar'));
        }
        $text_parts[] = $this->prepareTextValue($value);
      }
    }

    $combined_text = implode(' ', array_filter($text_parts));
    return trim($combined_text);
  }

  /**
   * Checks if vector search extensions are available in PostgreSQL.
   *
   * @param \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector $connector
   *   The database connector.
   *
   * @return bool
   *   TRUE if vector extensions are available.
   */
  public function checkVectorSupport(PostgreSQLConnector $connector) {
    try {
      // Check for pgvector extension
      $sql = "SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector')";
      $stmt = $connector->executeQuery($sql);
      return (bool) $stmt->fetchColumn();
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Gets the vector distance function based on configuration.
   *
   * @return string
   *   The PostgreSQL distance function name.
   */
  public function getVectorDistanceFunction() {
    // Default to cosine distance which is most common for text embeddings
    return '<->';
  }

  /**
   * Builds a vector similarity condition.
   *
   * @param string $field_name
   *   The vector field name.
   * @param array $query_vector
   *   The query vector.
   * @param float $threshold
   *   The similarity threshold.
   *
   * @return string
   *   The SQL condition for vector similarity.
   */
  public function buildVectorSimilarityCondition($field_name, array $query_vector, $threshold = 0.7) {
    $this->validateVectorArray($query_vector);
    
    if ($threshold < 0 || $threshold > 1) {
      throw new SearchApiException("Similarity threshold must be between 0 and 1, got: {$threshold}");
    }

    $vector_string = $this->prepareVectorValue($query_vector);
    $distance_function = $this->getVectorDistanceFunction();
    
    // Convert similarity threshold to distance threshold
    // For cosine distance: distance = 1 - similarity
    $distance_threshold = 1 - $threshold;
    
    return "{$field_name} {$distance_function} '{$vector_string}' < {$distance_threshold}";
  }

  /**
   * Builds a vector similarity score expression.
   *
   * @param string $field_name
   *   The vector field name.
   * @param array $query_vector
   *   The query vector.
   *
   * @return string
   *   The SQL expression for similarity score.
   */
  public function buildVectorSimilarityScore($field_name, array $query_vector) {
    $this->validateVectorArray($query_vector);
    
    $vector_string = $this->prepareVectorValue($query_vector);
    $distance_function = $this->getVectorDistanceFunction();
    
    // Convert distance to similarity: similarity = 1 - distance
    return "(1 - ({$field_name} {$distance_function} '{$vector_string}')) AS vector_similarity";
  }

}