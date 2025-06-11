/**
 * Provides a PostgreSQL-specific full-text data type.
 *
 * @SearchApiDataType(
 *   id = "postgresql_fulltext",
 *   label = @Translation("PostgreSQL Full-text"),
 *   description = @Translation("Full-text field optimized for PostgreSQL tsvector indexing"),
 *   fallback_type = "text",
 *   prefix = "pt"
 * )
 */
class PostgreSQLFulltext extends DataTypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function getValue($value) {
    // Ensure we have a string value for tsvector processing.
    if (is_array($value)) {
      $value = implode(' ', array_filter($value, 'is_scalar'));
    }
    
    return (string) $value;
  }

}