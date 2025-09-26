<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Item\ItemInterface;

/**
 * Enhanced IndexManager with vector search capabilities.
 */
class VectorIndexManager extends IndexManager
{
  /**
   * The embedding service.
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
      array $configuration,
      $embedding_service = null
  ) {
    // Call parent with only the 3 parameters it expects.
    parent::__construct($connector, $field_mapper, $configuration);

    // Handle embedding service in this enhanced version.
    $this->embeddingService = $embedding_service;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildCreateTableSql($table_name, IndexInterface $index)
  {
    $sql = parent::buildCreateTableSql($table_name, $index);

    // Add vector column for embeddings.
    // OpenAI default.
    $vector_dimension = $this->config['vector_dimension'] ?? 1536;
    $sql = str_replace(
        "search_vector TSVECTOR\n);",
        "search_vector TSVECTOR,\n  content_embedding " .
        "VECTOR({$vector_dimension})\n);"
    );

    return $sql;
  }

  /**
   * {@inheritdoc}
   */
  protected function createFullTextIndexes($table_name, IndexInterface $index)
  {
    parent::createFullTextIndexes($table_name, $index);

    // Create vector similarity index.
    $index_method = $this->config['vector_index_method'] ?? 'ivfflat';
    $index_options = '';

    if ($index_method === 'ivfflat') {
      $lists = $this->config['ivfflat_lists'] ?? 100;
      $index_options = "WITH (lists = {$lists})";
    } elseif ($index_method === 'hnsw') {
      $m = $this->config['hnsw_m'] ?? 16;
      $ef_construction = $this->config['hnsw_ef_construction'] ?? 64;
      $index_options = "WITH (m = {$m}, ef_construction = {$ef_construction})";
    }

    $sql = "CREATE INDEX {$table_name}_vector_idx ON {$table_name} " .
        "USING {$index_method} (content_embedding vector_cosine_ops) " .
        "{$index_options}";
    $this->connector->executeQuery($sql);
  }

  /**
   * {@inheritdoc}
   */
  protected function indexItem(
      $table_name,
      IndexInterface $index,
      ItemInterface $item
  ) {
    // Get the parent indexing logic.
    $fields = $item->getFields(true);
    $values = [
      'search_api_id' => $item->getId(),
      'search_api_datasource' => $item->getDatasourceId(),
      'search_api_language' => $item->getLanguage() ?: 'und',
    ];

    // Map field values.
    $searchable_text = '';
    foreach ($fields as $field_id => $field) {
      $field_values = $field->getValues();
      if (!empty($field_values)) {
        $field_value = reset($field_values);
        $values[$field_id] = $field_value;

        // Collect text for full-text search and embeddings.
        if (in_array($field->getType(), ['text', 'string'])) {
          $searchable_text .= ' ' . $field_value;
        }
      }
    }

    // Generate embedding if service is available.
    $embedding = null;
    if ($this->embeddingService && !empty(trim($searchable_text))) {
      try {
        $embedding = $this->embeddingService->generateEmbedding(
            trim($searchable_text)
        );
      } catch (\Exception $e) {
        // Log error but continue indexing without embedding.
        \Drupal::logger('search_api_postgresql')->warning(
            'Failed to generate embedding for item @id: @error',
            ['@id' => $item->getId(), '@error' => $e->getMessage()]
        );
      }
    }

    // Build full-text search vector.
    $fts_config = $this->config['fts_configuration'] ?? 'english';
    $values['search_vector'] = "to_tsvector('{$fts_config}', :searchable_text)";

    // Build INSERT query.
    $placeholders = [];
    foreach ($values as $key => $value) {
      if ($key === 'search_vector') {
        // Raw SQL function.
        $placeholders[] = $value;
      } elseif ($key === 'content_embedding' && $embedding) {
        $placeholders[] = ':content_embedding';
      } else {
        $placeholders[] = ":{$key}";
      }
    }

    $insert_sql = "INSERT INTO {$table_name} (" .
        implode(', ', array_keys($values)) .
        ") VALUES (" . implode(', ', $placeholders) . ")";

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
