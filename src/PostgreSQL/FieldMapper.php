<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Utility\Utility;

/**
 * Maps Search API fields to PostgreSQL columns.
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
      $definitions[$field_id] = [
        'type' => $this->typeMapping[$type] ?? 'TEXT',
        'null' => TRUE,
        'searchable' => $this->isSearchableType($type),
        'facetable' => $this->isFacetableType($type),
        'sortable' => $this->isSortableType($type),
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
      $value = implode(' ', $value);
    }

    // Strip HTML tags and normalize whitespace.
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

    return $value;
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

      default:
        return $value;
    }
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

}