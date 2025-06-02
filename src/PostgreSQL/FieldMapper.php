<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Utility\Utility;

/**
 * Maps Search API fields to PostgreSQL columns with vector support.
 */
class FieldMapper {

  /**
   * The backend configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Data type mapping from Search API to PostgreSQL.
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
   */
  public function getFieldDefinitions(IndexInterface $index) {
    $definitions = [];

    foreach ($index->getFields() as $field_id => $field) {
      $type = $field->getType();
      $pg_type = $this->typeMapping[$type] ?? 'TEXT';

      // Handle vector type with dimensions.
      if ($type === 'vector' && isset($this->config['ai_embeddings']['azure_ai']['dimensions'])) {
        $dimensions = $this->config['ai_embeddings']['azure_ai']['dimensions'];
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

    // Add automatic embedding fields if AI embeddings are enabled.
    if ($this->config['ai_embeddings']['enabled'] ?? FALSE) {
      $dimensions = $this->config['ai_embeddings']['azure_ai']['dimensions'] ?? 1536;
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
   * Prepares a field value for database storage.
   *
   * @param mixed $value
   *   The field value.
   * @param string $type
   *   The field type.
   *
   * @return mixed
   *   The prepared value.
   */
  public function prepareFieldValue($value, $type) {
    if ($value === NULL) {
      return NULL;
    }

    switch ($type) {
      case 'date':
        if (is_numeric($value)) {
          return date('Y-m-d H:i:s', $value);
        }
        return $value;

      case 'boolean':
        return $value ? 'true' : 'false';

      case 'integer':
        return (int) $value;

      case 'decimal':
        return (float) $value;

      case 'text':
      case 'postgresql_fulltext':
        return $this->prepareTextValue($value);

      case 'string':
        return (string) $value;

      case 'vector':
        return $this->prepareVectorValue($value);

      default:
        return $value;
    }
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

    // Strip HTML tags and normalize whitespace.
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

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
   */
  protected function prepareVectorValue($value) {
    if (is_string($value)) {
      // Already in PostgreSQL vector format.
      return $value;
    }

    if (is_array($value)) {
      // Convert array to PostgreSQL vector format.
      return '[' . implode(',', array_map('floatval', $value)) . ']';
    }

    return NULL;
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
    $item_id = $row['search_api_id'];
    $item = Utility::createItem($index, $item_id);

    // Set the score if available.
    if (isset($row['search_api_relevance'])) {
      $item->setScore($row['search_api_relevance']);
    }

    // Set vector similarity score if available.
    if (isset($row['vector_similarity'])) {
      $item->setExtraData('vector_similarity', $row['vector_similarity']);
    }

    // Set hybrid score if available.
    if (isset($row['hybrid_score'])) {
      $item->setScore($row['hybrid_score']);
      $item->setExtraData('vector_score', $row['vector_similarity'] ?? 0);
      $item->setExtraData('fulltext_score', $row['search_api_relevance'] ?? 0);
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

    switch ($type) {
      case 'date':
        return strtotime($value);

      case 'boolean':
        return $value === 'true' || $value === TRUE;

      case 'integer':
        return (int) $value;

      case 'decimal':
        return (float) $value;

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
      return $value;
    }

    if (is_string($value)) {
      $value = trim($value, '[]');
      return array_map('floatval', explode(',', $value));
    }

    return [];
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
      if ($this->isVectorSearchableType($field->getType())) {
        $vector_fields[] = $field_id;
      }
    }

    // Add automatic embedding field if enabled.
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
      $type = $field->getType();
      $configuration = $field->getConfiguration();

      // Include text fields that are marked for embedding or all text fields if auto-embedding is enabled.
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
      // Check for pgvector extension.
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
    // Default to cosine distance which is most common for text embeddings.
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
    $vector_string = $this->prepareVectorValue($query_vector);
    $distance_function = $this->getVectorDistanceFunction();
    
    // Convert similarity threshold to distance threshold.
    // For cosine distance: distance = 1 - similarity.
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
    $vector_string = $this->prepareVectorValue($query_vector);
    $distance_function = $this->getVectorDistanceFunction();
    
    // Convert distance to similarity: similarity = 1 - distance.
    return "(1 - ({$field_name} {$distance_function} '{$vector_string}')) AS vector_similarity";
  }

}