<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api\SearchApiException;

/**
 * Maps Search API fields to PostgreSQL columns with vector support and data type validation.
 */
class FieldMapper
{
  /**
   * The backend configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Updated type mapping that includes array support.
   *
   * @var array
   */
  protected $typeMapping = [
    'text' => 'TEXT',
    'string' => 'VARCHAR(255)',
  // Single-value integers.
    'integer' => 'INTEGER',
  // Multi-value integer arrays.
    'integer[]' => 'INTEGER[]',
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
    'postgresql_fulltext', 'vector', 'entity_reference',
  ];

  /**
   * Valid PostgreSQL data types for Search API.
   *
   * @var array
   */
  protected static $validPostgreSQLTypes = [
    'TEXT', 'VARCHAR(255)', 'INTEGER', 'BIGINT', 'DECIMAL(10,2)',
    'NUMERIC', 'TIMESTAMP', 'TIMESTAMPTZ', 'DATE', 'BOOLEAN',
    'TSVECTOR', 'JSON', 'JSONB', 'VECTOR',
  ];

  /**
   * Logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a FieldMapper object.
   */
  public function __construct(array $config)
  {
    $this->config = $config;
    $this->logger = \Drupal::logger('search_api_postgresql');
  }

  /**
   * Gets field definitions for an index with enhanced multi-value array support.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Field definitions keyed by field ID.
   */
  public function getFieldDefinitions(IndexInterface $index)
  {
    $definitions = [];

    foreach ($index->getFields() as $field_id => $field) {
      // Validate field ID.
      $this->validateFieldId($field_id);

      $type = $field->getType();
      $this->validateSearchApiType($type);

      // Check for multi-value fields that should use array columns.
      $is_multi_value = $this->isFieldMultiValue($field);

      // Handle integer and entity_reference fields (both store integers)
      if (in_array($type, ['integer', 'entity_reference'])) {
        if ($is_multi_value) {
          $pg_type = 'INTEGER[]';
        } else {
          $pg_type = 'INTEGER';
        }
      } elseif (in_array($type, ['text', 'string'])) {
        if ($is_multi_value) {
          // Use text array for multi-value text fields.
          $pg_type = 'TEXT[]';
          $this->logger->debug('Field @field (@type) set to TEXT[] (multi-value)', [
            '@field' => $field_id,
            '@type' => $type,
          ]);
        } else {
          $pg_type = $this->mapSearchApiTypeToPostgreSql($type);
        }
      } else {
        $pg_type = $this->mapSearchApiTypeToPostgreSql($type);
      }

      // Handle vector type with dimensions.
      if ($type === 'vector' && isset($this->config['ai_embeddings']['azure']['dimensions'])) {
        $dimensions = (int) $this->config['ai_embeddings']['azure']['dimensions'];
        $this->validateVectorDimensions($dimensions);
        $pg_type = "VECTOR({$dimensions})";
      }

      $definitions[$field_id] = [
        'type' => $pg_type,
        'null' => true,
        'searchable' => $this->isSearchableType($type),
        'facetable' => $this->isFacetableType($type),
        'sortable' => $this->isSortableType($type),
        'vector' => $type === 'vector',
        'multi_value' => $is_multi_value,
      ];
    }

    // Add automatic embedding fields if AI embeddings are enabled.
    if (!empty($this->config['ai_embeddings']['enabled'])) {
      $dimensions = $this->config['ai_embeddings']['azure']['dimension'] ??
              $this->config['ai_embeddings']['openai']['dimension'] ?? 1536;

      $definitions['embedding_vector'] = [
        'type' => "VECTOR({$dimensions})",
        'null' => true,
        'searchable' => false,
        'facetable' => false,
        'sortable' => false,
        'vector' => true,
        'multi_value' => false,
      ];
    }

    return $definitions;
  }

  /**
   * Enhanced prepareFieldValue with reliable multi-value array support.
   *
   * @param mixed $field_values
   *   The field values to prepare.
   * @param string $field_type
   *   The Search API field type.
   * @param \Drupal\search_api\Item\FieldInterface|null $field
   *   The field object for context.
   *
   * @return mixed
   *   The prepared field value.
   */
  public function prepareFieldValue($field_values, $field_type, $field = null)
  {
    $field_id = $field ?
      (method_exists($field, 'getFieldIdentifier') ?
        $field->getFieldIdentifier() : 'unknown') : 'null';

    // Handle empty values.
    if (empty($field_values)) {
      return null;
    }

    // Handle multi-value fields that should use arrays.
    if ($field && $this->isFieldMultiValue($field)) {
      if (is_array($field_values)) {
        // Handle integer/entity_reference arrays.
        if (in_array($field_type, ['integer', 'entity_reference'])) {
          $integer_values = [];

          foreach ($field_values as $value) {
            $scalar_value = $this->extractScalarValue($value, $field_type);
            if (is_numeric($scalar_value)) {
              $integer_values[] = (int) $scalar_value;
            }
          }

          if (!empty($integer_values)) {
            return '{' . implode(',', $integer_values) . '}';
          }
        } elseif (in_array($field_type, ['text', 'string'])) {
          $text_values = [];

          foreach ($field_values as $value) {
            $scalar_value = $this->extractScalarValue($value, $field_type);
            if (!empty($scalar_value)) {
              // Escape quotes and backslashes for PostgreSQL array format.
              $escaped_value = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $scalar_value);
              $text_values[] = '"' . $escaped_value . '"';
            }
          }

          if (!empty($text_values)) {
            $array_result = '{' . implode(',', $text_values) . '}';

            $this->logger->debug('Prepared text array value for @field (@type): @result', [
              '@field' => $field_id,
              '@type' => $field_type,
              '@result' => $array_result,
            ]);

            return $array_result;
          }
        }
      }
    }

    // Process as single value for non-array fields or single values.
    $single_value = is_array($field_values) ? reset($field_values) : $field_values;
    $scalar_value = $this->extractScalarValue($single_value, $field_type);

    switch ($field_type) {
      case 'text':
          return $this->prepareTextValue($scalar_value);

      case 'string':
          return $this->prepareStringValue($scalar_value);

      case 'integer':
      case 'entity_reference':
          return $this->prepareIntegerValue($scalar_value);

      case 'decimal':
          return $this->prepareDecimalValue($scalar_value);

      case 'boolean':
          return $this->prepareBooleanValue($scalar_value);

      case 'date':
          return $this->prepareDateValue($scalar_value);

      case 'vector':
          return $this->prepareVectorValue($scalar_value);

      default:
          return $this->prepareTextValue($scalar_value);
    }
  }

  /**
   * Check if a field is multi-value (unlimited cardinality) with enhanced detection.
   *
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   The Search API field.
   *
   * @return bool
   *   TRUE if field is multi-value, FALSE otherwise.
   */
  public function isFieldMultiValue($field)
  {
    try {
      $field_id = $field ?
        (method_exists($field, 'getFieldIdentifier') ?
          $field->getFieldIdentifier() : 'unknown') : 'null';

      // First check field configuration for cardinality.
      $field_config = $field->getConfiguration();
      if (isset($field_config['cardinality'])) {
        $cardinality = $field_config['cardinality'];
        $is_multi = ($cardinality === -1 || $cardinality > 1);

        return $is_multi;
      }

      // Try to get cardinality from datasource field definition.
      $datasource_id = $field->getDatasourceId();
      if ($datasource_id) {
        $result = $this->checkEntityFieldCardinality($field);

        return $result;
      }

      return false;
    } catch (\Exception $e) {
      $this->logger->error('Error detecting multi-value status for @field: @error', [
        '@field' => $field_id ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Check entity field cardinality through field storage definition - WITH SAFE LOGGING.
   */
  protected function checkEntityFieldCardinality($field)
  {
    try {
      $property_path = $field->getPropertyPath();

      // Extract base field name from property path like "field_document_topics:entity:target_id".
      $path_parts = explode(':', $property_path);
      $base_field_name = $path_parts[0];

      // Get the datasource and check the field definition.
      $datasource_id = $field->getDatasourceId();

      if ($datasource_id) {
        // Parse datasource ID to get entity type (e.g., "entity:node" -> "node")
        $entity_type_parts = explode(':', $datasource_id);
        $entity_type = end($entity_type_parts);

        // Load field storage definition.
        $field_storage = FieldStorageConfig::loadByName($entity_type, $base_field_name);

        if ($field_storage) {
          $cardinality = $field_storage->getCardinality();

          $is_multi = ($cardinality === -1 || $cardinality > 1);
          return $is_multi;
        }
      }

      return false;
    } catch (\Exception $e) {
      $this->logger->error('Error checking entity field cardinality: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Gets the database column name for a Search API field.
   *
   * @param string $field_name
   *   The Search API field name.
   * @param string $field_type
   *   The Search API field type (optional for context).
   *
   * @return string
   *   The database column name.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the field name is invalid.
   */
  public function getColumnName($field_name, $field_type = null)
  {
    // Validate the field ID first.
    $this->validateFieldId($field_name);

    // Entity reference fields store their values in {field_name}_target_id columns
    // but Search API may pass just the field name, so we need to resolve this correctly.
    // For entity reference fields, Search API typically stores the target_id directly
    // in the field column, so we should return the field name as-is
    // The actual database column creation handles the _target_id suffix.
    return $field_name;
  }

  /**
   * Enhanced method to get the actual storage column name for entity reference fields.
   *
   * @param string $field_name
   *   The Search API field name.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index for context.
   *
   * @return string
   *   The actual storage column name.
   */
  public function getStorageColumnName($field_name, IndexInterface $index)
  {
    $fields = $index->getFields();

    if (isset($fields[$field_name])) {
      $field = $fields[$field_name];
      $field_type = $field->getType();

      // For entity reference fields, check if we need to append _target_id.
      if ($field_type === 'entity_reference') {
        // If the field name doesn't already end with _target_id, append it.
        if (!str_ends_with($field_name, '_target_id')) {
          return $field_name . '_target_id';
        }
      }
    }

    return $field_name;
  }

  /**
   * Prepares an entity reference value for storage.
   *
   * @param mixed $value
   *   The entity reference value.
   *
   * @return int|null
   *   The entity ID or NULL.
   */
  protected function prepareEntityReferenceValue($value)
  {
    if ($value === null || $value === '') {
      return null;
    }

    // Handle direct entity ID values.
    if (is_numeric($value)) {
      return (int) $value;
    }

    // Handle entity objects.
    if (is_object($value)) {
      // Try to get entity ID from entity object.
      if (method_exists($value, 'id')) {
        return (int) $value->id();
      }

      // Try to get from target_id property (common in field items)
      if (property_exists($value, 'target_id')) {
        return (int) $value->target_id;
      }

      // Try getValue() method for field items.
      if (method_exists($value, 'getValue')) {
        $field_value = $value->getValue();
        if (is_array($field_value) && isset($field_value['target_id'])) {
          return (int) $field_value['target_id'];
        }
      }
    }

    // Handle array format (common from form submissions)
    if (is_array($value)) {
      if (isset($value['target_id'])) {
        return (int) $value['target_id'];
      }

      // Handle case where array contains the ID directly.
      if (isset($value[0]) && is_numeric($value[0])) {
        return (int) $value[0];
      }

      // Handle case where it's just an array with a single numeric value.
      if (count($value) === 1) {
        $first_value = reset($value);
        if (is_numeric($first_value)) {
          return (int) $first_value;
        }
      }
    }

    // Handle string representations of entity IDs.
    if (is_string($value)) {
      // Try to parse as JSON first (in case it's serialized)
      $decoded = json_decode($value, true);
      if (is_array($decoded) && isset($decoded['target_id'])) {
        return (int) $decoded['target_id'];
      }

      // Try direct string to int conversion.
      if (is_numeric($value)) {
        return (int) $value;
      }
    }

    // Log warning for unhandled entity reference format.
    \Drupal::logger('search_api_postgresql')->warning(
        'Could not extract entity ID from entity reference value: @value (@type)',
        [
            '@value' => print_r($value, true),
            '@type' => is_object($value) ? get_class($value) : gettype($value),
          ]
    );

    return null;
  }

  /**
   * Enhanced extractScalarValue with better entity reference handling.
   */
  public function extractScalarValue($field_value, $field_type)
  {
    // Handle different field value types.
    if (is_scalar($field_value)) {
      return $field_value;
    }

    // Special handling for entity reference fields.
    if ($field_type === 'entity_reference') {
      return $this->extractEntityReferenceId($field_value);
    }

    // Handle TextItem objects (formatted text fields)
    if (is_object($field_value)) {
      // Check for common text extraction methods.
      if (method_exists($field_value, 'getValue')) {
        $value = $field_value->getValue();
        // For formatted text, extract the actual text.
        if (is_array($value) && isset($value['value'])) {
          return $value['value'];
        }
        return $value;
      }

      if (method_exists($field_value, 'getString')) {
        return $field_value->getString();
      }

      if (method_exists($field_value, '__toString')) {
        return (string) $field_value;
      }

      // For entity references, try to get target_id.
      if (method_exists($field_value, 'getTarget')) {
        $target = $field_value->getTarget();
        if ($target && method_exists($target, 'id')) {
          return $target->id();
        }
      }

      // Handle typed data objects.
      if (method_exists($field_value, 'value')) {
        $property_value = $field_value->value;
        if (is_scalar($property_value)) {
          return $property_value;
        }
      }
    }

    // Handle arrays recursively.
    if (is_array($field_value)) {
      $scalar_values = [];
      foreach ($field_value as $item) {
        $scalar_item = $this->extractScalarValue($item, $field_type);
        if (is_scalar($scalar_item)) {
          $scalar_values[] = $scalar_item;
        }
      }
      return $scalar_values;
    }

    // Last resort - try to convert to string.
    if (is_object($field_value) || is_array($field_value)) {
      // Log a warning for debugging.
      \Drupal::logger('search_api_postgresql')->warning(
          'Could not extract scalar value from field type @type: @value',
          [
          '@type' => $field_type,
          '@value' => is_object($field_value) ? get_class($field_value) : gettype($field_value),
          ]
      );

      // Return empty string to prevent indexing failure.
      return '';
    }

    return $field_value;
  }

  /**
   * Extract entity ID from various entity reference formats.
   */
  protected function extractEntityReferenceId($field_value)
  {
    if (is_numeric($field_value)) {
      return (int) $field_value;
    }

    if (is_object($field_value)) {
      // EntityReferenceItem objects.
      if (method_exists($field_value, 'getValue')) {
        $value = $field_value->getValue();
        if (is_array($value) && isset($value['target_id'])) {
          return (int) $value['target_id'];
        }
      }

      // Direct entity objects.
      if (method_exists($field_value, 'id')) {
        return (int) $field_value->id();
      }

      // Field item objects with target_id property.
      if (property_exists($field_value, 'target_id')) {
        return (int) $field_value->target_id;
      }
    }

    if (is_array($field_value) && isset($field_value['target_id'])) {
      return (int) $field_value['target_id'];
    }

    return null;
  }

  /**
   * Update isFacetableType to include entity_reference.
   */
  protected function isFacetableType($type)
  {
    return in_array($type, ['string', 'integer', 'boolean', 'date', 'entity_reference']);
  }

  /**
   * Update isSortableType to include entity_reference.
   */
  protected function isSortableType($type)
  {
    return in_array($type, ['string', 'integer', 'decimal', 'date', 'boolean', 'entity_reference']);
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
  public function getSearchableFields(IndexInterface $index)
  {
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
  public function getVectorSearchableFields(IndexInterface $index)
  {
    $vector_fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      $this->validateFieldId($field_id);
      if ($this->isVectorSearchableType($field->getType())) {
        $vector_fields[] = $field_id;
      }
    }

    // Add automatic embedding field if enabled.
    if ($this->config['ai_embeddings']['enabled'] ?? false) {
      $vector_fields[] = 'embedding_vector';
    }

    return $vector_fields;
  }

  /**
   * Gets fields that can be used for faceting.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Array of facetable field IDs.
   */
  public function getFacetableFields(IndexInterface $index)
  {
    $facetable_fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      $this->validateFieldId($field_id);
      if ($this->isFacetableType($field->getType())) {
        $facetable_fields[] = $field_id;
      }
    }

    return $facetable_fields;
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
  public function getEmbeddingSourceFields(IndexInterface $index)
  {
    $embedding_fields = [];

    foreach ($index->getFields() as $field_id => $field) {
      $this->validateFieldId($field_id);
      $type = $field->getType();
      $configuration = $field->getConfiguration();

      // Include text fields that are marked for embedding or all text fields if auto-embedding is enabled.
      if (in_array($type, ['text', 'postgresql_fulltext'])) {
        if (!empty($configuration['generate_embedding']) || ($this->config['ai_embeddings']['enabled'] ?? false)) {
          $embedding_fields[] = $field_id;
        }
      }
    }

    return $embedding_fields;
  }

  /**
   * Extracts searchable text from item fields.
   *
   * @param array $fields
   *   Array of fields from the item.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The combined searchable text.
   */
  public function extractSearchableText(array $fields, IndexInterface $index)
  {
    $searchable_fields = $this->getSearchableFields($index);
    $text_parts = [];

    foreach ($fields as $field_id => $field) {
      if (in_array($field_id, $searchable_fields)) {
        $values = $field->getValues();
        foreach ($values as $value) {
          if (is_scalar($value)) {
            $text_parts[] = $this->prepareTextValue($value);
          }
        }
      }
    }

    return trim(implode(' ', array_filter($text_parts)));
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
  public function generateEmbeddingText(array $field_values, IndexInterface $index)
  {
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
  public function checkVectorSupport(PostgreSQLConnector $connector)
  {
    try {
      // Check for pgvector extension.
      $sql = "SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector')";
      $stmt = $connector->executeQuery($sql);
      return (bool) $stmt->fetchColumn();
    } catch (\Exception $e) {
      return false;
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
  public function createResultItem(IndexInterface $index, array $row)
  {
    if (!isset($row['search_api_id'])) {
      throw new SearchApiException("Row missing required 'search_api_id' field.");
    }

    $item_id = $row['search_api_id'];
    $item = Utility::createItem($index, $item_id);

    // Set the score if available.
    if (isset($row['search_api_relevance'])) {
      $score = $this->prepareDecimalValue($row['search_api_relevance']);
      $item->setScore($score);
    }

    // Set vector similarity score if available.
    if (isset($row['vector_similarity'])) {
      $similarity = $this->prepareDecimalValue($row['vector_similarity']);
      $item->setExtraData('vector_similarity', $similarity);
    }

    // Set field values.
    foreach ($index->getFields() as $field_id => $field) {
      if (isset($row[$field_id])) {
        $value = $this->convertFieldValue($row[$field_id], $field->getType());
        $item->setField($field_id, $value);
      }
    }

    return $item;
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
  protected function validateFieldId($field_id)
  {
    if (empty($field_id) || !is_string($field_id)) {
      throw new SearchApiException("Field ID cannot be empty.");
    }

    // Field IDs should follow Drupal naming conventions.
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $field_id)) {
      throw new SearchApiException(
          "Invalid field ID: '{$field_id}'. Must start with lowercase letter and " .
          "contain only lowercase letters, numbers, and underscores."
      );
    }

    // Check length limit.
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
  protected function validateSearchApiType($type)
  {
    if (!in_array($type, self::$validSearchApiTypes)) {
      throw new SearchApiException(
          "Invalid Search API data type: '{$type}'. Allowed types: " .
          implode(', ', self::$validSearchApiTypes)
      );
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
  public function mapSearchApiTypeToPostgreSql($search_api_type)
  {
    if (!isset($this->typeMapping[$search_api_type])) {
      throw new SearchApiException("No PostgreSQL mapping found for Search API type: '{$search_api_type}'");
    }

    $pg_type = $this->typeMapping[$search_api_type];

    return $pg_type;
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
  protected function validateVectorDimensions($dimensions)
  {
    if (!is_int($dimensions) || $dimensions < 1 || $dimensions > 16000) {
      throw new SearchApiException("Invalid vector dimensions: {$dimensions}. Must be an integer between 1 and 16000.");
    }
  }

  /**
   * Prepares a date value.
   *
   * @param mixed $value
   *   The date value.
   *
   * @return string
   *   The prepared date string.
   */
  protected function prepareDateValue($value)
  {
    if (is_numeric($value)) {
      // Assume Unix timestamp.
      $timestamp = (int) $value;
    } elseif (is_string($value)) {
      $timestamp = strtotime($value);
      if ($timestamp === false) {
        throw new SearchApiException("Invalid date value: {$value}");
      }
    } else {
      throw new SearchApiException("Date value must be numeric timestamp or date string, got: " . gettype($value));
    }

    // Format for PostgreSQL TIMESTAMP.
    return date('Y-m-d H:i:s', $timestamp);
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
  protected function prepareBooleanValue($value)
  {
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

    throw new SearchApiException("Invalid boolean value: " . var_export($value, true));
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
  protected function prepareIntegerValue($value)
  {
    if (is_int($value)) {
      return $value;
    }

    if (is_numeric($value)) {
      $int_value = (int) $value;
      // Check for overflow/underflow.
      if ($int_value < PHP_INT_MIN || $int_value > PHP_INT_MAX) {
        throw new SearchApiException("Integer value out of range: {$value}");
      }
      return $int_value;
    }

    throw new SearchApiException("Invalid integer value: " . var_export($value, true));
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
  protected function prepareDecimalValue($value)
  {
    if (is_float($value) || is_int($value)) {
      return (float) $value;
    }

    if (is_numeric($value)) {
      $float_value = (float) $value;
      // Check for infinity or NaN.
      if (!is_finite($float_value)) {
        throw new SearchApiException("Invalid decimal value (infinity or NaN): {$value}");
      }
      return $float_value;
    }

    throw new SearchApiException("Invalid decimal value: " . var_export($value, true));
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
  protected function prepareTextValue($value)
  {
    if (is_array($value)) {
      $value = implode(' ', array_filter($value, 'is_scalar'));
    }

    if (!is_scalar($value)) {
      throw new SearchApiException("Text value must be scalar or array of scalars.");
    }

    $value = (string) $value;

    // Strip HTML tags and normalize whitespace.
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

    // Limit text length to prevent memory issues.
    // 1MB.
    $max_length = 1000000;
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
  protected function prepareStringValue($value)
  {
    if (!is_scalar($value)) {
      throw new SearchApiException("String value must be scalar.");
    }

    $value = (string) $value;

    // Limit string length to field capacity.
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
  protected function prepareVectorValue($value)
  {
    if (is_string($value)) {
      // Already in PostgreSQL vector format - validate it.
      if (preg_match('/^\[[\d\.,\-\s]+\]$/', $value)) {
        $this->validateVectorString($value);
        return $value;
      }
      throw new SearchApiException("Invalid vector string format: {$value}");
    }

    if (is_array($value)) {
      // Convert array to PostgreSQL vector format.
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
  protected function validateVectorArray(array $vector)
  {
    if (empty($vector)) {
      throw new SearchApiException("Vector cannot be empty.");
    }

    if (count($vector) > 16000) {
      throw new SearchApiException("Vector too large: " . count($vector) . " dimensions (max 16000).");
    }

    foreach ($vector as $index => $value) {
      if (!is_numeric($value)) {
        throw new SearchApiException("Vector component at index {$index} is not numeric: " . var_export($value, true));
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
  protected function validateVectorString($vector_string)
  {
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
  protected function convertFieldValue($value, $type)
  {
    if ($value === null) {
      return null;
    }

    $this->validateSearchApiType($type);

    switch ($type) {
      case 'date':
          return strtotime($value);

      case 'boolean':
          return $value === 'true' || $value === true;

      case 'integer':
      case 'entity_reference':
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
  protected function convertVectorValue($value)
  {
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
  protected function isSearchableType($type)
  {
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
  protected function isVectorSearchableType($type)
  {
    return $type === 'vector';
  }
}
