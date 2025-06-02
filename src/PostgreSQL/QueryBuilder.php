<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\search_api\Query\ConditionGroupInterface;

/**
 * Builds PostgreSQL queries for Search API.
 */
class QueryBuilder {

  /**
   * The PostgreSQL connector.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   */
  protected $connector;

  /**
   * The field mapper.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\FieldMapper
   */
  protected $fieldMapper;

  /**
   * The backend configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs a QueryBuilder object.
   *
   * @param \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector $connector
   *   The PostgreSQL connector.
   * @param \Drupal\search_api_postgresql\PostgreSQL\FieldMapper $field_mapper
   *   The field mapper.
   * @param array $config
   *   The backend configuration.
   */
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $config) {
    $this->connector = $connector;
    $this->fieldMapper = $field_mapper;
    $this->config = $config;
  }

  /**
   * Builds a search query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  public function buildSearchQuery(QueryInterface $query) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);

    $sql_parts = [
      'SELECT' => $this->buildSelectClause($query),
      'FROM' => $table_name,
      'WHERE' => $this->buildWhereClause($query),
      'ORDER' => $this->buildOrderClause($query),
      'LIMIT' => $this->buildLimitClause($query),
    ];

    $sql = $this->assembleSqlQuery($sql_parts);
    $params = $this->getQueryParameters($query);

    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Builds a count query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  public function buildCountQuery(QueryInterface $query) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);

    $sql_parts = [
      'SELECT' => 'COUNT(*)',
      'FROM' => $table_name,
      'WHERE' => $this->buildWhereClause($query),
    ];

    $sql = $this->assembleSqlQuery($sql_parts);
    $params = $this->getQueryParameters($query);

    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Builds the SELECT clause.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string
   *   The SELECT clause.
   */
  protected function buildSelectClause(QueryInterface $query) {
    $fields = ['search_api_id', 'search_api_datasource', 'search_api_language'];
    
    // Add requested fields.
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $fields[] = $field_id;
    }

    // Add relevance score if there's a full-text search.
    if ($query->getKeys()) {
      $ts_query = $this->buildTsQuery($query->getKeys());
      $fts_config = $this->config['fts_configuration'];
      $fields[] = "ts_rank(search_vector, to_tsquery('{$fts_config}', :ts_query)) AS search_api_relevance";
    }

    return implode(', ', $fields);
  }

  /**
   * Builds the WHERE clause.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string
   *   The WHERE clause.
   */
  protected function buildWhereClause(QueryInterface $query) {
    $conditions = ['1=1']; // Always true base condition

    // Handle full-text search.
    if ($keys = $query->getKeys()) {
      $fts_config = $this->config['fts_configuration'];
      $conditions[] = "search_vector @@ to_tsquery('{$fts_config}', :ts_query)";
    }

    // Handle filters.
    if ($condition_group = $query->getConditionGroup()) {
      $condition_sql = $this->buildConditionGroup($condition_group);
      if (!empty($condition_sql)) {
        $conditions[] = $condition_sql;
      }
    }

    // Handle language filtering.
    if ($languages = $query->getLanguages()) {
      if ($languages !== [NULL]) {
        $language_placeholders = [];
        foreach ($languages as $i => $language) {
          $language_placeholders[] = ":language_{$i}";
        }
        $conditions[] = 'search_api_language IN (' . implode(', ', $language_placeholders) . ')';
      }
    }

    return implode(' AND ', $conditions);
  }

  /**
   * Builds a condition group.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group.
   *
   * @return string
   *   The condition SQL.
   */
  protected function buildConditionGroup(ConditionGroupInterface $condition_group) {
    $conditions = [];
    $conjunction = $condition_group->getConjunction() === 'OR' ? 'OR' : 'AND';

    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_condition = $this->buildConditionGroup($condition);
        if (!empty($nested_condition)) {
          $conditions[] = "({$nested_condition})";
        }
      }
      elseif ($condition instanceof ConditionInterface) {
        $condition_sql = $this->buildCondition($condition);
        if (!empty($condition_sql)) {
          $conditions[] = $condition_sql;
        }
      }
    }

    return implode(" {$conjunction} ", $conditions);
  }

  /**
   * Builds a single condition.
   *
   * @param \Drupal\search_api\Query\ConditionInterface $condition
   *   The condition.
   *
   * @return string
   *   The condition SQL.
   */
  protected function buildCondition(ConditionInterface $condition) {
    $field = $condition->getField();
    $value = $condition->getValue();
    $operator = $condition->getOperator();

    // Handle special fields.
    if ($field === 'search_api_id') {
      $field = 'search_api_id';
    }
    elseif ($field === 'search_api_datasource') {
      $field = 'search_api_datasource';
    }
    elseif ($field === 'search_api_language') {
      $field = 'search_api_language';
    }

    $placeholder = $this->getConditionPlaceholder($field, $value);

    switch ($operator) {
      case '=':
        return "{$field} = {$placeholder}";

      case '<>':
      case '!=':
        return "{$field} <> {$placeholder}";

      case '<':
        return "{$field} < {$placeholder}";

      case '<=':
        return "{$field} <= {$placeholder}";

      case '>':
        return "{$field} > {$placeholder}";

      case '>=':
        return "{$field} >= {$placeholder}";

      case 'IN':
        if (is_array($value)) {
          $placeholders = [];
          foreach ($value as $i => $val) {
            $placeholders[] = ":{$field}_in_{$i}";
          }
          return "{$field} IN (" . implode(', ', $placeholders) . ")";
        }
        return "{$field} = {$placeholder}";

      case 'NOT IN':
        if (is_array($value)) {
          $placeholders = [];
          foreach ($value as $i => $val) {
            $placeholders[] = ":{$field}_not_in_{$i}";
          }
          return "{$field} NOT IN (" . implode(', ', $placeholders) . ")";
        }
        return "{$field} <> {$placeholder}";

      case 'BETWEEN':
        if (is_array($value) && count($value) === 2) {
          return "{$field} BETWEEN :{$field}_between_start AND :{$field}_between_end";
        }
        break;

      case 'NOT BETWEEN':
        if (is_array($value) && count($value) === 2) {
          return "{$field} NOT BETWEEN :{$field}_not_between_start AND :{$field}_not_between_end";
        }
        break;

      case 'CONTAINS':
        return "{$field} ILIKE :{$field}_contains";

      case 'STARTS_WITH':
        return "{$field} ILIKE :{$field}_starts_with";

      case 'ENDS_WITH':
        return "{$field} ILIKE :{$field}_ends_with";

      default:
        return "{$field} = {$placeholder}";
    }

    return '';
  }

  /**
   * Builds the ORDER BY clause.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string
   *   The ORDER BY clause.
   */
  protected function buildOrderClause(QueryInterface $query) {
    $sorts = $query->getSorts();
    
    if (empty($sorts)) {
      // Default sort by relevance if there's a search, otherwise by ID.
      if ($query->getKeys()) {
        return 'ORDER BY search_api_relevance DESC';
      }
      return 'ORDER BY search_api_id ASC';
    }

    $order_parts = [];
    foreach ($sorts as $field => $direction) {
      $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
      
      if ($field === 'search_api_relevance') {
        $order_parts[] = "search_api_relevance {$direction}";
      }
      elseif ($field === 'search_api_id') {
        $order_parts[] = "search_api_id {$direction}";
      }
      elseif ($field === 'search_api_random') {
        $order_parts[] = "RANDOM()";
      }
      else {
        $order_parts[] = "{$field} {$direction}";
      }
    }

    return 'ORDER BY ' . implode(', ', $order_parts);
  }

  /**
   * Builds the LIMIT clause.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string
   *   The LIMIT clause.
   */
  protected function buildLimitClause(QueryInterface $query) {
    $limit = $query->getRange();
    
    if ($limit === NULL) {
      return '';
    }

    $offset = $limit[0] ?? 0;
    $length = $limit[1] ?? 50;

    if ($offset > 0) {
      return "LIMIT {$length} OFFSET {$offset}";
    }

    return "LIMIT {$length}";
  }

  /**
   * Builds a tsquery for full-text search.
   *
   * @param mixed $keys
   *   The search keys.
   *
   * @return string
   *   The tsquery string.
   */
  protected function buildTsQuery($keys) {
    if (is_string($keys)) {
      // Simple keyword search - escape and sanitize.
      return $this->sanitizeSearchTerms($keys);
    }

    if (is_array($keys)) {
      // Complex query with operators.
      return $this->buildComplexTsQuery($keys);
    }

    return '';
  }

  /**
   * Builds a complex tsquery from nested array structure.
   *
   * @param array $keys
   *   The search keys array.
   *
   * @return string
   *   The tsquery string.
   */
  protected function buildComplexTsQuery(array $keys) {
    $conjunction = $keys['#conjunction'] ?? 'AND';
    $terms = [];

    foreach ($keys as $key => $value) {
      if ($key === '#conjunction') {
        continue;
      }

      if (is_array($value)) {
        $nested_query = $this->buildComplexTsQuery($value);
        if (!empty($nested_query)) {
          $terms[] = "({$nested_query})";
        }
      }
      else {
        $sanitized = $this->sanitizeSearchTerms($value);
        if (!empty($sanitized)) {
          $terms[] = $sanitized;
        }
      }
    }

    $operator = ($conjunction === 'OR') ? ' | ' : ' & ';
    return implode($operator, $terms);
  }

  /**
   * Sanitizes search terms for tsquery.
   *
   * @param string $terms
   *   The search terms.
   *
   * @return string
   *   The sanitized terms.
   */
  protected function sanitizeSearchTerms($terms) {
    // Remove special characters that could break tsquery.
    $terms = preg_replace('/[^a-zA-Z0-9\s\-_]/', ' ', $terms);
    
    // Split into words and filter.
    $words = preg_split('/\s+/', trim($terms));
    $words = array_filter($words, function($word) {
      return strlen($word) > 1; // Filter out single characters
    });

    // Join with AND operator.
    return implode(' & ', array_map(function($word) {
      return $word . ':*'; // Add prefix matching
    }, $words));
  }

  /**
   * Executes facet queries.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array of facet results.
   */
  public function executeFacetQueries(QueryInterface $query) {
    $facets = $query->getOption('search_api_facets', []);
    $facet_results = [];

    foreach ($facets as $facet_id => $facet_info) {
      $facet_query = $this->buildFacetQuery($query, $facet_id, $facet_info);
      $results = $this->connector->executeQuery($facet_query['sql'], $facet_query['params']);
      
      $facet_results[$facet_id] = [];
      while ($row = $results->fetch()) {
        $facet_results[$facet_id][] = [
          'filter' => $row['value'],
          'count' => (int) $row['count'],
        ];
      }
    }

    return $facet_results;
  }

  /**
   * Builds a facet query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The main search query.
   * @param string $facet_id
   *   The facet field ID.
   * @param array $facet_info
   *   The facet configuration.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildFacetQuery(QueryInterface $query, $facet_id, array $facet_info) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);
    
    // Base WHERE clause from main query (excluding this facet's conditions).
    $where_clause = $this->buildWhereClause($query);
    
    $sql = "
      SELECT {$facet_id} as value, COUNT(*) as count
      FROM {$table_name}
      WHERE {$where_clause}
        AND {$facet_id} IS NOT NULL
      GROUP BY {$facet_id}
      ORDER BY count DESC, value ASC
    ";

    $limit = $facet_info['limit'] ?? 50;
    $sql .= " LIMIT {$limit}";

    $params = $this->getQueryParameters($query);

    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Gets autocomplete suggestions.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   * @param string $incomplete_key
   *   The incomplete search term.
   * @param string $user_input
   *   The full user input.
   *
   * @return array
   *   Array of suggestions.
   */
  public function getAutocompleteSuggestions(QueryInterface $query, $incomplete_key, $user_input) {
    $table_name = $this->getIndexTableName($query->getIndex());
    $fts_config = $this->config['fts_configuration'];

    // Build a simple prefix search query.
    $search_term = $this->sanitizeSearchTerms($incomplete_key);
    
    $sql = "
      SELECT DISTINCT 
        ts_headline('{$fts_config}', 
          COALESCE(title, '') || ' ' || COALESCE(body, ''), 
          to_tsquery('{$fts_config}', :search_term),
          'MaxWords=3, MinWords=1, MaxFragments=1'
        ) as suggestion,
        ts_rank(search_vector, to_tsquery('{$fts_config}', :search_term)) as rank
      FROM {$table_name}
      WHERE search_vector @@ to_tsquery('{$fts_config}', :search_term)
      ORDER BY rank DESC
      LIMIT 10
    ";

    $params = [':search_term' => $search_term];
    $results = $this->connector->executeQuery($sql, $params);

    $suggestions = [];
    while ($row = $results->fetch()) {
      // Extract meaningful suggestions from headlines.
      $suggestion = strip_tags($row['suggestion']);
      $suggestion = trim($suggestion);
      
      if (!empty($suggestion) && strlen($suggestion) > strlen($incomplete_key)) {
        $suggestions[] = [
          'suggestion_prefix' => $incomplete_key,
          'suggestion_suffix' => substr($suggestion, strlen($incomplete_key)),
          'user_input' => $user_input,
        ];
      }
    }

    return array_slice($suggestions, 0, 10);
  }

  /**
   * Assembles the SQL query from parts.
   *
   * @param array $sql_parts
   *   Array of SQL parts.
   *
   * @return string
   *   The complete SQL query.
   */
  protected function assembleSqlQuery(array $sql_parts) {
    $sql = "SELECT {$sql_parts['SELECT']} FROM {$sql_parts['FROM']}";
    
    if (!empty($sql_parts['WHERE'])) {
      $sql .= " WHERE {$sql_parts['WHERE']}";
    }
    
    if (!empty($sql_parts['ORDER'])) {
      $sql .= " {$sql_parts['ORDER']}";
    }
    
    if (!empty($sql_parts['LIMIT'])) {
      $sql .= " {$sql_parts['LIMIT']}";
    }

    return $sql;
  }

  /**
   * Gets query parameters.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array of query parameters.
   */
  protected function getQueryParameters(QueryInterface $query) {
    $params = [];

    // Add full-text search parameter.
    if ($keys = $query->getKeys()) {
      $params[':ts_query'] = $this->buildTsQuery($keys);
    }

    // Add condition parameters.
    if ($condition_group = $query->getConditionGroup()) {
      $this->addConditionParameters($condition_group, $params);
    }

    // Add language parameters.
    if ($languages = $query->getLanguages()) {
      if ($languages !== [NULL]) {
        foreach ($languages as $i => $language) {
          $params[":language_{$i}"] = $language ?: 'und';
        }
      }
    }

    return $params;
  }

  /**
   * Adds condition parameters.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group.
   * @param array $params
   *   The parameters array to modify.
   */
  protected function addConditionParameters(ConditionGroupInterface $condition_group, array &$params) {
    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $this->addConditionParameters($condition, $params);
      }
      elseif ($condition instanceof ConditionInterface) {
        $this->addConditionParameter($condition, $params);
      }
    }
  }

  /**
   * Adds a single condition parameter.
   *
   * @param \Drupal\search_api\Query\ConditionInterface $condition
   *   The condition.
   * @param array $params
   *   The parameters array to modify.
   */
  protected function addConditionParameter(ConditionInterface $condition, array &$params) {
    $field = $condition->getField();
    $value = $condition->getValue();
    $operator = $condition->getOperator();

    switch ($operator) {
      case 'IN':
        if (is_array($value)) {
          foreach ($value as $i => $val) {
            $params[":{$field}_in_{$i}"] = $val;
          }
        }
        else {
          $params[":{$field}"] = $value;
        }
        break;

      case 'NOT IN':
        if (is_array($value)) {
          foreach ($value as $i => $val) {
            $params[":{$field}_not_in_{$i}"] = $val;
          }
        }
        else {
          $params[":{$field}"] = $value;
        }
        break;

      case 'BETWEEN':
        if (is_array($value) && count($value) === 2) {
          $params[":{$field}_between_start"] = $value[0];
          $params[":{$field}_between_end"] = $value[1];
        }
        break;

      case 'NOT BETWEEN':
        if (is_array($value) && count($value) === 2) {
          $params[":{$field}_not_between_start"] = $value[0];
          $params[":{$field}_not_between_end"] = $value[1];
        }
        break;

      case 'CONTAINS':
        $params[":{$field}_contains"] = '%' . $value . '%';
        break;

      case 'STARTS_WITH':
        $params[":{$field}_starts_with"] = $value . '%';
        break;

      case 'ENDS_WITH':
        $params[":{$field}_ends_with"] = '%' . $value;
        break;

      default:
        $params[":{$field}"] = $value;
        break;
    }
  }

  /**
   * Gets a placeholder for a condition.
   *
   * @param string $field
   *   The field name.
   * @param mixed $value
   *   The field value.
   *
   * @return string
   *   The placeholder.
   */
  protected function getConditionPlaceholder($field, $value) {
    return ":{$field}";
  }

  /**
   * Gets the table name for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The table name.
   */
  protected function getIndexTableName(IndexInterface $index) {
    return $this->config['index_prefix'] . $index->id();
  }

}