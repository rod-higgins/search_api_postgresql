<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\search_api\Query\ConditionGroupInterface;

/**
 * Builds PostgreSQL queries for Search API with SQL injection prevention.
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
   * Valid system field names that are always safe.
   *
   * @var array
   */
  protected static $systemFields = [
    'search_api_id',
    'search_api_datasource',
    'search_api_language',
    'search_api_relevance',
    'search_api_random',
  ];

  /**
   * Valid comparison operators.
   *
   * @var array
   */
  protected static $validOperators = [
    '=', '<>', '!=', '<', '<=', '>', '>=',
    'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN',
    'CONTAINS', 'STARTS_WITH', 'ENDS_WITH',
    'IS NULL', 'IS NOT NULL',
  ];

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
    $fields = [];
    
    // Add system fields (always safe)
    $fields[] = $this->connector->quoteColumnName('search_api_id');
    $fields[] = $this->connector->quoteColumnName('search_api_datasource');
    $fields[] = $this->connector->quoteColumnName('search_api_language');
    
    // Add requested fields from the index
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $safe_field = $this->validateIndexField($query->getIndex(), $field_id);
      $fields[] = $safe_field;
    }

    // ALWAYS add relevance score - required by Search API specification
    if ($query->getKeys()) {
      // With search keys: use actual relevance calculation
      $fts_config = $this->validateFtsConfiguration();
      $fields[] = "ts_rank(" . $this->connector->quoteColumnName('search_vector') . 
                ", to_tsquery('{$fts_config}', :ts_query)) AS " . 
                $this->connector->quoteColumnName('search_api_relevance');
    } else {
      // Without search keys: provide default relevance value
      $fields[] = "1.0 AS " . $this->connector->quoteColumnName('search_api_relevance');
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
    $conditions = [];

    // Add full-text search condition
    if ($keys = $query->getKeys()) {
      $fts_config = $this->validateFtsConfiguration();
      $conditions[] = $this->connector->quoteColumnName('search_vector') . 
                     " @@ to_tsquery('{$fts_config}', :ts_query)";
    }

    // Add other conditions
    $condition_group = $query->getConditionGroup();
    if ($condition_sql = $this->buildConditionGroupSql($condition_group, $query->getIndex())) {
      $conditions[] = $condition_sql;
    }

    return !empty($conditions) ? implode(' AND ', $conditions) : '1=1';
  }

  /**
   * Builds SQL for a condition group.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The SQL string for the condition group.
   */
  protected function buildConditionGroupSql(ConditionGroupInterface $condition_group, IndexInterface $index) {
    $conditions = [];

    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        if ($sql = $this->buildConditionGroupSql($condition, $index)) {
          $conditions[] = '(' . $sql . ')';
        }
      }
      else {
        if ($sql = $this->buildConditionSql($condition, $index)) {
          $conditions[] = $sql;
        }
      }
    }

    if (empty($conditions)) {
      return '';
    }

    $conjunction = $condition_group->getConjunction() === 'OR' ? ' OR ' : ' AND ';
    return implode($conjunction, $conditions);
  }

  /**
   * Builds SQL for a single condition.
   *
   * @param \Drupal\search_api\Query\ConditionInterface $condition
   *   The condition.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The SQL string for the condition.
   */
  protected function buildConditionSql(ConditionInterface $condition, IndexInterface $index) {
    $field = $condition->getField();
    $value = $condition->getValue();
    $operator = strtoupper($condition->getOperator());

    // Validate operator
    if (!in_array($operator, self::$validOperators)) {
      throw new \InvalidArgumentException("Invalid operator: {$operator}");
    }

    // Validate and quote field name
    $safe_field = $this->validateConditionField($index, $field);

    // Special handling for boolean fields - cast the parameter in SQL
    $parameter_ref = ":{$field}";
    if (in_array($field, ['status', 'sticky']) && in_array($operator, ['=', '<>', '!='])) {
      $parameter_ref = ":{$field}::boolean";
    }

    // Build SQL based on operator
    switch ($operator) {
      case '=':
        return "{$safe_field} = {$parameter_ref}";

      case '<>':
      case '!=':
        return "{$safe_field} <> {$parameter_ref}";

      case '<':
        return "{$safe_field} < {$parameter_ref}";

      case '<=':
        return "{$safe_field} <= {$parameter_ref}";

      case '>':
        return "{$safe_field} > {$parameter_ref}";

      case '>=':
        return "{$safe_field} >= {$parameter_ref}";

      case 'IN':
        if (is_array($value) && !empty($value)) {
          $placeholders = [];
          foreach ($value as $i => $val) {
            $placeholder = ":{$field}_in_{$i}";
            // Cast each placeholder for boolean fields
            if (in_array($field, ['status', 'sticky'])) {
              $placeholder .= "::boolean";
            }
            $placeholders[] = $placeholder;
          }
          return "{$safe_field} IN (" . implode(', ', $placeholders) . ")";
        }
        return '1=0'; // Empty IN should match nothing

      case 'NOT IN':
        if (is_array($value) && !empty($value)) {
          $placeholders = [];
          foreach ($value as $i => $val) {
            $placeholder = ":{$field}_not_in_{$i}";
            // Cast each placeholder for boolean fields
            if (in_array($field, ['status', 'sticky'])) {
              $placeholder .= "::boolean";
            }
            $placeholders[] = $placeholder;
          }
          return "{$safe_field} NOT IN (" . implode(', ', $placeholders) . ")";
        }
        return '1=1'; // Empty NOT IN should match everything

      case 'BETWEEN':
        if (is_array($value) && count($value) === 2) {
          return "{$safe_field} BETWEEN :{$field}_min AND :{$field}_max";
        }
        throw new \InvalidArgumentException('BETWEEN operator requires array with exactly 2 values');

      case 'NOT BETWEEN':
        if (is_array($value) && count($value) === 2) {
          return "{$safe_field} NOT BETWEEN :{$field}_not_between_min AND :{$field}_not_between_max";
        }
        throw new \InvalidArgumentException('NOT BETWEEN operator requires array with exactly 2 values');

      case 'CONTAINS':
        return "{$safe_field} LIKE :{$field}_contains";

      case 'STARTS_WITH':
        return "{$safe_field} LIKE :{$field}_starts_with";

      case 'ENDS_WITH':
        return "{$safe_field} LIKE :{$field}_ends_with";

      case 'LIKE':
      case 'NOT LIKE':
        $like_operator = $operator === 'LIKE' ? 'LIKE' : 'NOT LIKE';
        return "{$safe_field} {$like_operator} {$parameter_ref}";

      case 'IS NULL':
        return "{$safe_field} IS NULL";

      case 'IS NOT NULL':
        return "{$safe_field} IS NOT NULL";

      default:
        throw new \InvalidArgumentException("Unsupported operator: {$operator}");
    }
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
      return '';
    }

    $order_parts = [];
    foreach ($sorts as $field => $direction) {
      $safe_field = $this->validateIndexField($query->getIndex(), $field);
      $safe_direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
      $order_parts[] = "{$safe_field} {$safe_direction}";
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
    $limit = $query->getOption('limit');
    $offset = $query->getOption('offset', 0);

    if (!$limit) {
      return '';
    }

    $sql = 'LIMIT ' . (int) $limit;
    if ($offset > 0) {
      $sql .= ' OFFSET ' . (int) $offset;
    }

    return $sql;
  }

  /**
   * Assembles SQL parts into a complete query.
   *
   * @param array $parts
   *   Array of SQL parts.
   *
   * @return string
   *   The assembled SQL query.
   */
  protected function assembleSqlQuery(array $parts) {
    $sql = [];
    
    foreach (['SELECT', 'FROM', 'WHERE', 'ORDER', 'LIMIT'] as $clause) {
      if (!empty($parts[$clause])) {
        if ($clause === 'SELECT') {
          $sql[] = 'SELECT ' . $parts[$clause];
        }
        elseif ($clause === 'FROM' || $clause === 'WHERE') {
          $sql[] = $clause . ' ' . $parts[$clause];
        }
        else {
          $sql[] = $parts[$clause];
        }
      }
    }

    return implode("\n", $sql);
  }

  /**
   * Gets query parameters.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array of parameters for the query.
   */
  protected function getQueryParameters(QueryInterface $query) {
    $params = [];

    // Add full-text search parameter
    if ($keys = $query->getKeys()) {
      $params[':ts_query'] = $this->processSearchKeys($keys);
    }

    // Add condition parameters
    $this->addConditionGroupParameters($query->getConditionGroup(), $params);

    return $params;
  }

  /**
   * Processes search keys for PostgreSQL full-text search.
   *
   * @param mixed $keys
   *   The search keys.
   *
   * @return string
   *   The processed search query string.
   */
  protected function processSearchKeys($keys) {
    if (is_string($keys)) {
      // Simple string search
      return $this->escapeSearchString($keys);
    }
    
    if (is_array($keys)) {
      // Complex search query
      return $this->processComplexKeys($keys);
    }

    return '';
  }

  /**
   * Escapes a search string for PostgreSQL ts_query.
   *
   * @param string $string
   *   The search string.
   *
   * @return string
   *   The escaped string.
   */
  protected function escapeSearchString($string) {
    // Remove special characters that have meaning in tsquery
    $string = preg_replace('/[&|!():*]/', ' ', $string);
    
    // Trim and collapse whitespace
    $string = preg_replace('/\s+/', ' ', trim($string));
    
    // Convert to tsquery format (AND by default)
    $terms = explode(' ', $string);
    $terms = array_filter($terms);
    
    return implode(' & ', array_map(function($term) {
      return "'" . addslashes($term) . "'";
    }, $terms));
  }

  /**
   * Processes complex search keys.
   *
   * @param array $keys
   *   The complex keys array.
   *
   * @return string
   *   The processed search query string.
   */
  protected function processComplexKeys(array $keys) {
    $conjunction = isset($keys['#conjunction']) ? $keys['#conjunction'] : 'AND';
    $processed = [];

    foreach ($keys as $key => $value) {
      // Check if key is a string and starts with '#' (configuration keys)
      if (is_string($key) && isset($key[0]) && $key[0] === '#') {
        continue;
      }

      if (is_string($value)) {
        $processed[] = $this->escapeSearchString($value);
      }
      elseif (is_array($value)) {
        $sub_query = $this->processComplexKeys($value);
        if ($sub_query) {
          $processed[] = '(' . $sub_query . ')';
        }
      }
      // Handle other types (integers, etc.) by converting to string
      elseif (is_scalar($value)) {
        $processed[] = $this->escapeSearchString((string) $value);
      }
    }

    $operator = $conjunction === 'OR' ? ' | ' : ' & ';
    return implode($operator, $processed);
  }

  /**
   * Adds condition group parameters to the params array.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group.
   * @param array $params
   *   The parameters array to modify.
   */
  protected function addConditionGroupParameters(ConditionGroupInterface $condition_group, array &$params) {
    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $this->addConditionGroupParameters($condition, $params);
      }
      else {
        $this->addConditionParameters($condition, $params);
      }
    }
  }

  /**
   * Adds parameters for a single condition.
   *
   * @param \Drupal\search_api\Query\ConditionInterface $condition
   *   The condition.
   * @param array $params
   *   The parameters array to modify.
   */
  protected function addConditionParameters(ConditionInterface $condition, array &$params) {
    $field = $condition->getField();
    $value = $condition->getValue();
    $operator = strtoupper($condition->getOperator());

    // Validate field name for parameter generation
    $this->connector->validateIdentifier($field, 'field name');

    if ($field === 'node_grants' && is_string($value)) {
      // Convert Database backend format (node_access__all) to PostgreSQL format (node_access_all:0)
      if ($value === 'node_access_all:0') {
        $value = 'node_access__all';
      }
    }

    // SPECIAL HANDLING for boolean fields - convert integer to boolean
    if (in_array($field, ['status', 'sticky']) && is_numeric($value)) {
      $value = (bool) $value;
    }

    switch ($operator) {
      case 'IN':
        if (is_array($value)) {
          foreach ($value as $i => $val) {
            if (in_array($field, ['status', 'sticky']) && is_numeric($val)) {
              $val = (bool) $val;
            }
            $params[":{$field}_in_{$i}"] = $val;
          }
        }
        break;

      case 'NOT IN':
        if (is_array($value)) {
          foreach ($value as $i => $val) {
            if (in_array($field, ['status', 'sticky']) && is_numeric($val)) {
              $val = (bool) $val;
            }
            $params[":{$field}_not_in_{$i}"] = $val;
          }
        }
        break;

      case 'BETWEEN':
        if (is_array($value) && count($value) === 2) {
          $params[":{$field}_min"] = $value[0];
          $params[":{$field}_max"] = $value[1];
        }
        break;

      case 'NOT BETWEEN':
        if (is_array($value) && count($value) === 2) {
          $params[":{$field}_not_between_min"] = $value[0];
          $params[":{$field}_not_between_max"] = $value[1];
        }
        break;

      case 'CONTAINS':
        $params[":{$field}_contains"] = '%' . $this->connector->escapeLikePattern($value) . '%';
        break;

      case 'STARTS_WITH':
        $params[":{$field}_starts_with"] = $this->connector->escapeLikePattern($value) . '%';
        break;

      case 'ENDS_WITH':
        $params[":{$field}_ends_with"] = '%' . $this->connector->escapeLikePattern($value);
        break;

      case 'IS NULL':
      case 'IS NOT NULL':
        // No parameters needed for NULL checks
        break;

      default:
        // For boolean fields that need casting, ensure we bind as integer
        // The ::boolean cast in SQL will handle the conversion
        if (in_array($field, ['status', 'sticky']) && is_bool($value)) {
          // Convert PHP boolean back to integer for PostgreSQL parameter binding
          // The SQL will use ::boolean cast to convert it properly
          $params[":{$field}"] = $value ? 1 : 0;
        } else {
          $params[":{$field}"] = $value;
        }
        break;
    }
  }

  /**
   * Gets the table name for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The safely quoted table name.
   */
  protected function getIndexTableName(IndexInterface $index) {
    $index_id = $index->id();
    
    // Validate index ID
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $index_id)) {
      throw new \InvalidArgumentException("Invalid index ID: {$index_id}");
    }
    
    $table_name = ($this->config['index_prefix'] ?? 'search_api_') . $index_id;
    return $this->connector->quoteTableName($table_name);
  }

  /**
   * Validates that a field exists in the index and returns safe field name.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param string $field_id
   *   The field ID to validate.
   *
   * @return string
   *   The safely quoted field name.
   *
   * @throws \InvalidArgumentException
   *   If the field is not valid for the index.
   */
  protected function validateIndexField(IndexInterface $index, $field_id) {
    // System fields are always allowed
    if (in_array($field_id, self::$systemFields)) {
      return $this->connector->quoteColumnName($field_id);
    }

    // Check if field exists in the index
    if (!$index->getField($field_id)) {
      throw new \InvalidArgumentException("Field '{$field_id}' does not exist in index '{$index->id()}'");
    }

    return $this->connector->quoteColumnName($field_id);
  }

  /**
   * Validates a field for use in conditions.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param string $field_id
   *   The field ID to validate.
   *
   * @return string
   *   The safely quoted field name.
   */
  protected function validateConditionField(IndexInterface $index, $field_id) {
    return $this->validateIndexField($index, $field_id);
  }

  /**
   * Validates the FTS configuration.
   *
   * @return string
   *   The validated FTS configuration.
   */
  protected function validateFtsConfiguration() {
    $fts_config = $this->config['fts_configuration'] ?? 'english';
    
    // Allowed PostgreSQL text search configurations
    $allowed_configs = [
      'simple', 'english', 'french', 'german', 'spanish', 'portuguese',
      'italian', 'dutch', 'russian', 'norwegian', 'swedish', 'danish',
      'finnish', 'hungarian', 'romanian', 'turkish'
    ];
    
    if (!in_array($fts_config, $allowed_configs)) {
      throw new \InvalidArgumentException("Invalid FTS configuration: {$fts_config}");
    }
    
    return $fts_config;
  }
}