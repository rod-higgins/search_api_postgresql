<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Item\ItemInterface;

/**
 * Enhanced IndexManager with Azure AI vector search capabilities.
 */
class AzureVectorIndexManager extends IndexManager
{
  /**
   * The Azure embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $embeddingService;

  /**
   * {@inheritdoc}
   */
  public function __construct(
      PostgreSQLConnector $connector,
      FieldMapper $field_mapper,
      array $config,
      $embedding_service = null
  ) {
    parent::__construct($connector, $field_mapper, $config);
    $this->embeddingService = $embedding_service;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildCreateTableSql($table_name, IndexInterface $index)
  {
    $sql = parent::buildCreateTableSql($table_name, $index);

    // Add vector column for embeddings.
    $vector_dimension = $this->config['azure_embedding']['dimension'] ?? 1536;
    $sql = str_replace(
        "search_vector TSVECTOR\n);",
        "search_vector TSVECTOR,\n  content_embedding VECTOR({$vector_dimension})\n);"
    );

    return $sql;
  }

  /**
   * {@inheritdoc}
   */
  protected function createFullTextIndexes($table_name, IndexInterface $index)
  {
    parent::createFullTextIndexes($table_name, $index);

    // Create vector similarity index optimized for Azure Database for PostgreSQL.
    $index_method = $this->config['vector_index']['method'] ?? 'ivfflat';
    $index_options = '';

    if ($index_method === 'ivfflat') {
      $lists = $this->config['vector_index']['ivfflat_lists'] ?? 100;
      $index_options = "WITH (lists = {$lists})";
    } elseif ($index_method === 'hnsw') {
      $m = $this->config['vector_index']['hnsw_m'] ?? 16;
      $ef_construction = $this->config['vector_index']['hnsw_ef_construction'] ?? 64;
      $index_options = "WITH (m = {$m}, ef_construction = {$ef_construction})";
    }

    $sql = "CREATE INDEX {$table_name}_vector_idx ON {$table_name}
            USING {$index_method} (content_embedding vector_cosine_ops) {$index_options}";
    $this->connector->executeQuery($sql);
  }

  /**
   * {@inheritdoc}
   */
  protected function indexItem($table_name, IndexInterface $index, ItemInterface $item)
  {
    $fields = $item->getFields(true);
    $values = [
      'search_api_id' => $item->getId(),
      'search_api_datasource' => $item->getDatasourceId(),
      'search_api_language' => $item->getLanguage() ?: 'und',
    ];

    // Prepare searchable text for both tsvector and embeddings.
    $searchable_text = '';
    $searchable_fields = $this->fieldMapper->getSearchableFields($index);

    foreach ($fields as $field_id => $field) {
      $field_values = $field->getValues();
      $field_type = $field->getType();

      if (!empty($field_values)) {
        $value = reset($field_values);
        $values[$field_id] = $this->fieldMapper->prepareFieldValue($value, $field_type);

        if (in_array($field_id, $searchable_fields)) {
          $searchable_text .= ' ' . $this->fieldMapper->prepareFieldValue($value, 'text');
        }
      } else {
        $values[$field_id] = null;
      }
    }

    // Generate embedding using Azure AI.
    $embedding = null;
    if ($this->embeddingService && !empty(trim($searchable_text))) {
      try {
        $embedding = $this->embeddingService->generateEmbedding(trim($searchable_text));
      } catch (\Exception $e) {
        \Drupal::logger('search_api_postgresql')->error('Failed to generate Azure embedding: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Prepare tsvector value.
    $fts_config = $this->config['fts_configuration'];
    $values['search_vector'] = "to_tsvector('{$fts_config}', :searchable_text)";

    // Prepare vector value.
    if ($embedding) {
      $values['content_embedding'] = ':content_embedding';
    }

    // Delete existing item.
    $delete_sql = "DELETE FROM {$table_name} WHERE search_api_id = :item_id";
    $this->connector->executeQuery($delete_sql, [':item_id' => $item->getId()]);

    // Insert new item.
    $columns = array_keys($values);
    $placeholders = array_map(function ($col) use ($values) {
      if ($col === 'search_vector') {
        return $values[$col];
      }
      return ":{$col}";
    }, $columns);

    $insert_sql = "INSERT INTO {$table_name} (" . implode(', ', $columns) . ") " .
                  "VALUES (" . implode(', ', $placeholders) . ")";

    $params = [];
    foreach ($values as $key => $value) {
      if ($key !== 'search_vector') {
        $params[":{$key}"] = $value;
      }
    }
    $params[':searchable_text'] = trim($searchable_text);

    if ($embedding) {
      $params[':content_embedding'] = '[' . implode(',', $embedding) . ']';
    }

    $this->connector->executeQuery($insert_sql, $params);
  }
}
