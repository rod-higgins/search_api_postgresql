<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\data_type;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\DataType\DataTypePluginBase;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\SearchApiException;

/**
 * Provides a PostgreSQL-specific full-text data type.
 *
 * This data type is optimized for PostgreSQL's native full-text search
 * capabilities including tsvector indexing, text search configurations,
 * ranking, highlighting, and advanced query operations.
 *
 * @SearchApiDataType(
 *   id = "postgresql_fulltext",
 *   label = @Translation("PostgreSQL Full-text"),
 *   description = @Translation("Full-text field optimized for PostgreSQL tsvector indexing with advanced search features"),
 *   fallback_type = "text",
 *   prefix = "pt"
 * )
 */
class PostgreSQLFulltext extends DataTypePluginBase {

  use PluginFormTrait;

  /**
   * Available PostgreSQL text search configurations.
   *
   * @var array
   */
  protected static $textSearchConfigurations = [
    'simple' => 'Simple',
    'english' => 'English',
    'spanish' => 'Spanish',
    'french' => 'French',
    'german' => 'German',
    'portuguese' => 'Portuguese',
    'italian' => 'Italian',
    'dutch' => 'Dutch',
    'danish' => 'Danish',
    'finnish' => 'Finnish',
    'hungarian' => 'Hungarian',
    'norwegian' => 'Norwegian',
    'russian' => 'Russian',
    'swedish' => 'Swedish',
    'turkish' => 'Turkish',
  ];

  /**
   * Stemming dictionaries for different languages.
   *
   * @var array
   */
  protected static $stemmingDictionaries = [
    'english' => 'english_stem',
    'spanish' => 'spanish_stem',
    'french' => 'french_stem',
    'german' => 'german_stem',
    'portuguese' => 'portuguese_stem',
    'italian' => 'italian_stem',
    'dutch' => 'dutch_stem',
    'danish' => 'danish_stem',
    'finnish' => 'finnish_stem',
    'hungarian' => 'hungarian_stem',
    'norwegian' => 'norwegian_stem',
    'russian' => 'russian_stem',
    'swedish' => 'swedish_stem',
    'turkish' => 'turkish_stem',
  ];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'text_search_config' => 'english',
      'enable_stemming' => TRUE,
      'enable_phrase_search' => TRUE,
      'enable_fuzzy_search' => TRUE,
      'weight_title' => 'A',
      'weight_body' => 'B',
      'weight_keywords' => 'C',
      'weight_description' => 'D',
      'min_word_length' => 3,
      'max_word_length' => 40,
      'enable_highlighting' => TRUE,
      'highlight_max_words' => 35,
      'highlight_min_words' => 15,
      'highlight_max_fragments' => 5,
      'enable_ranking' => TRUE,
      'ranking_normalization' => 1,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['text_search_config'] = [
      '#type' => 'select',
      '#title' => $this->t('Text Search Configuration'),
      '#description' => $this->t('PostgreSQL text search configuration for language-specific processing.'),
      '#options' => static::$textSearchConfigurations,
      '#default_value' => $this->configuration['text_search_config'],
      '#required' => TRUE,
    ];

    $form['stemming'] = [
      '#type' => 'details',
      '#title' => $this->t('Stemming and Language Processing'),
      '#open' => FALSE,
    ];

    $form['stemming']['enable_stemming'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Stemming'),
      '#description' => $this->t('Use language-specific stemming for better search recall.'),
      '#default_value' => $this->configuration['enable_stemming'],
    ];

    $form['stemming']['min_word_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Word Length'),
      '#description' => $this->t('Minimum length for words to be indexed.'),
      '#default_value' => $this->configuration['min_word_length'],
      '#min' => 1,
      '#max' => 10,
    ];

    $form['stemming']['max_word_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Word Length'),
      '#description' => $this->t('Maximum length for words to be indexed.'),
      '#default_value' => $this->configuration['max_word_length'],
      '#min' => 10,
      '#max' => 100,
    ];

    $form['search_features'] = [
      '#type' => 'details',
      '#title' => $this->t('Search Features'),
      '#open' => TRUE,
    ];

    $form['search_features']['enable_phrase_search'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Phrase Search'),
      '#description' => $this->t('Support for quoted phrase searches.'),
      '#default_value' => $this->configuration['enable_phrase_search'],
    ];

    $form['search_features']['enable_fuzzy_search'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Fuzzy Search'),
      '#description' => $this->t('Support for fuzzy/similarity search using trigrams.'),
      '#default_value' => $this->configuration['enable_fuzzy_search'],
    ];

    $form['weighting'] = [
      '#type' => 'details',
      '#title' => $this->t('Field Weighting'),
      '#description' => $this->t('Assign different weights to different types of content for relevance scoring.'),
      '#open' => FALSE,
    ];

    $weight_options = [
      'A' => $this->t('A (Highest)'),
      'B' => $this->t('B (High)'),
      'C' => $this->t('C (Medium)'),
      'D' => $this->t('D (Low)'),
    ];

    $form['weighting']['weight_title'] = [
      '#type' => 'select',
      '#title' => $this->t('Title Weight'),
      '#options' => $weight_options,
      '#default_value' => $this->configuration['weight_title'],
    ];

    $form['weighting']['weight_body'] = [
      '#type' => 'select',
      '#title' => $this->t('Body Weight'),
      '#options' => $weight_options,
      '#default_value' => $this->configuration['weight_body'],
    ];

    $form['weighting']['weight_keywords'] = [
      '#type' => 'select',
      '#title' => $this->t('Keywords Weight'),
      '#options' => $weight_options,
      '#default_value' => $this->configuration['weight_keywords'],
    ];

    $form['weighting']['weight_description'] = [
      '#type' => 'select',
      '#title' => $this->t('Description Weight'),
      '#options' => $weight_options,
      '#default_value' => $this->configuration['weight_description'],
    ];

    $form['highlighting'] = [
      '#type' => 'details',
      '#title' => $this->t('Search Result Highlighting'),
      '#open' => FALSE,
    ];

    $form['highlighting']['enable_highlighting'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Highlighting'),
      '#description' => $this->t('Highlight search terms in results using PostgreSQL ts_headline.'),
      '#default_value' => $this->configuration['enable_highlighting'],
    ];

    $form['highlighting']['highlight_max_words'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Words in Highlight'),
      '#default_value' => $this->configuration['highlight_max_words'],
      '#min' => 10,
      '#max' => 100,
      '#states' => [
        'visible' => [
          ':input[name="data_type_config[highlighting][enable_highlighting]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['highlighting']['highlight_min_words'] = [
      '#type' => 'number',
      '#title' => $this->t('Min Words in Highlight'),
      '#default_value' => $this->configuration['highlight_min_words'],
      '#min' => 5,
      '#max' => 50,
      '#states' => [
        'visible' => [
          ':input[name="data_type_config[highlighting][enable_highlighting]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['highlighting']['highlight_max_fragments'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Highlight Fragments'),
      '#default_value' => $this->configuration['highlight_max_fragments'],
      '#min' => 1,
      '#max' => 10,
      '#states' => [
        'visible' => [
          ':input[name="data_type_config[highlighting][enable_highlighting]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ranking'] = [
      '#type' => 'details',
      '#title' => $this->t('Relevance Ranking'),
      '#open' => FALSE,
    ];

    $form['ranking']['enable_ranking'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Ranking'),
      '#description' => $this->t('Use PostgreSQL ts_rank for relevance scoring.'),
      '#default_value' => $this->configuration['enable_ranking'],
    ];

    $form['ranking']['ranking_normalization'] = [
      '#type' => 'select',
      '#title' => $this->t('Ranking Normalization'),
      '#options' => [
        0 => $this->t('No normalization'),
        1 => $this->t('Divide by document length'),
        2 => $this->t('Divide by number of unique words'),
        4 => $this->t('Divide by mean harmonic distance'),
        8 => $this->t('Divide by number of unique words + 1'),
        16 => $this->t('Divide by rank + 1'),
        32 => $this->t('Rank/(rank+1)'),
      ],
      '#default_value' => $this->configuration['ranking_normalization'],
      '#states' => [
        'visible' => [
          ':input[name="data_type_config[ranking][enable_ranking]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue($value) {
    // Handle array input by joining values
    if (is_array($value)) {
      $value = implode(' ', array_filter($value, 'is_scalar'));
    }
    
    // Ensure we have a string
    if (!is_scalar($value)) {
      return '';
    }
    
    $value = (string) $value;
    
    // Apply text processing
    return $this->processText($value);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareValue($value) {
    $processed_value = $this->getValue($value);
    
    if (empty($processed_value)) {
      return NULL;
    }
    
    // Validate processed text
    $this->validateText($processed_value);
    
    return $processed_value;
  }

  /**
   * Processes text according to PostgreSQL fulltext requirements.
   *
   * @param string $text
   *   The input text.
   *
   * @return string
   *   The processed text.
   */
  protected function processText($text) {
    if (empty($text)) {
      return '';
    }

    // Strip HTML tags but preserve spacing
    $text = preg_replace('/<[^>]*>/', ' ', $text);
    
    // Normalize whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Trim
    $text = trim($text);
    
    // Apply word length filters
    if ($this->configuration['min_word_length'] > 1 || $this->configuration['max_word_length'] < 100) {
      $text = $this->filterWordsByLength($text);
    }
    
    // Remove control characters that could interfere with tsvector
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    
    return $text;
  }

  /**
   * Filters text by word length.
   *
   * @param string $text
   *   The input text.
   *
   * @return string
   *   The filtered text.
   */
  protected function filterWordsByLength($text) {
    $min_length = $this->configuration['min_word_length'];
    $max_length = $this->configuration['max_word_length'];
    
    $words = preg_split('/\s+/', $text);
    $filtered_words = [];
    
    foreach ($words as $word) {
      $word_length = mb_strlen($word);
      if ($word_length >= $min_length && $word_length <= $max_length) {
        $filtered_words[] = $word;
      }
    }
    
    return implode(' ', $filtered_words);
  }

  /**
   * Validates processed text.
   *
   * @param string $text
   *   The text to validate.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If validation fails.
   */
  protected function validateText($text) {
    // Check for maximum text length (PostgreSQL has limits)
    $max_length = 1000000; // 1MB limit
    if (strlen($text) > $max_length) {
      throw new SearchApiException("Text is too long for PostgreSQL fulltext indexing (max: {$max_length} bytes)");
    }
    
    // Check for valid UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
      throw new SearchApiException("Text contains invalid UTF-8 encoding");
    }
  }

  /**
   * Generates a tsvector SQL expression for the given text and configuration.
   *
   * @param string $column_name
   *   The column name containing the text.
   * @param array $options
   *   Additional options for tsvector generation.
   *
   * @return string
   *   The SQL expression for generating tsvector.
   */
  public function getTsVectorSql($column_name, array $options = []) {
    $config = $options['config'] ?? $this->configuration['text_search_config'];
    $weight = $options['weight'] ?? 'A';
    
    // Validate configuration exists
    if (!isset(static::$textSearchConfigurations[$config])) {
      $config = 'english';
    }
    
    // Build tsvector expression with weighting
    $sql = "setweight(to_tsvector('{$config}', COALESCE({$column_name}, '')), '{$weight}')";
    
    return $sql;
  }

  /**
   * Generates a tsquery SQL expression for search terms.
   *
   * @param string $search_terms
   *   The search terms.
   * @param array $options
   *   Search options.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  public function getTsQuerySql($search_terms, array $options = []) {
    $config = $options['config'] ?? $this->configuration['text_search_config'];
    $enable_phrase = $options['phrase'] ?? $this->configuration['enable_phrase_search'];
    $enable_fuzzy = $options['fuzzy'] ?? $this->configuration['enable_fuzzy_search'];
    
    // Process search terms
    $processed_terms = $this->processSearchTerms($search_terms, [
      'phrase' => $enable_phrase,
      'fuzzy' => $enable_fuzzy,
    ]);
    
    if (empty($processed_terms)) {
      return ['sql' => 'TRUE', 'params' => []];
    }
    
    $sql = "to_tsquery('{$config}', :search_query)";
    $params = [':search_query' => $processed_terms];
    
    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Processes search terms for tsquery.
   *
   * @param string $terms
   *   The search terms.
   * @param array $options
   *   Processing options.
   *
   * @return string
   *   The processed query string.
   */
  protected function processSearchTerms($terms, array $options = []) {
    if (empty($terms)) {
      return '';
    }
    
    $terms = trim($terms);
    
    // Handle phrase search (quoted strings)
    if ($options['phrase'] && preg_match_all('/"([^"]+)"/', $terms, $matches)) {
      $phrases = [];
      foreach ($matches[1] as $phrase) {
        $phrase = trim($phrase);
        if (!empty($phrase)) {
          // Convert phrase to tsquery format
          $phrase_words = preg_split('/\s+/', $phrase);
          $phrase_words = array_map([$this, 'escapeSearchTerm'], $phrase_words);
          $phrases[] = implode(' <-> ', $phrase_words);
        }
      }
      
      // Remove quoted phrases from original terms
      $terms = preg_replace('/"[^"]+"/', '', $terms);
      
      if (!empty($phrases)) {
        $phrase_query = '(' . implode(' | ', $phrases) . ')';
        $terms = trim($terms);
        if (!empty($terms)) {
          $word_query = $this->processWordTerms($terms);
          return $phrase_query . ' & ' . $word_query;
        }
        return $phrase_query;
      }
    }
    
    return $this->processWordTerms($terms);
  }

  /**
   * Processes individual word terms.
   *
   * @param string $terms
   *   The word terms.
   *
   * @return string
   *   The processed word query.
   */
  protected function processWordTerms($terms) {
    if (empty($terms)) {
      return '';
    }
    
    // Split into words
    $words = preg_split('/\s+/', trim($terms));
    $words = array_filter($words);
    
    if (empty($words)) {
      return '';
    }
    
    // Process each word
    $processed_words = [];
    foreach ($words as $word) {
      $word = trim($word);
      if (empty($word)) {
        continue;
      }
      
      // Handle boolean operators
      if (in_array(strtoupper($word), ['AND', 'OR', 'NOT'])) {
        $processed_words[] = strtoupper($word);
        continue;
      }
      
      // Escape and add prefix matching for stemming
      $escaped_word = $this->escapeSearchTerm($word);
      
      if ($this->configuration['enable_stemming']) {
        $escaped_word .= ':*';
      }
      
      $processed_words[] = $escaped_word;
    }
    
    // Join with AND by default
    return implode(' & ', $processed_words);
  }

  /**
   * Escapes a search term for tsquery.
   *
   * @param string $term
   *   The search term.
   *
   * @return string
   *   The escaped term.
   */
  protected function escapeSearchTerm($term) {
    // Remove characters that have special meaning in tsquery
    $term = preg_replace('/[&|!():*<>]/', '', $term);
    
    // Quote the term if it contains spaces or special characters
    if (preg_match('/[\s\'"\\\\]/', $term)) {
      $term = "'" . addslashes($term) . "'";
    }
    
    return $term;
  }

  /**
   * Generates SQL for highlighting search results.
   *
   * @param string $column_name
   *   The column to highlight.
   * @param string $search_query
   *   The search query parameter name.
   * @param array $options
   *   Highlighting options.
   *
   * @return string
   *   The SQL expression for highlighting.
   */
  public function getHighlightSql($column_name, $search_query, array $options = []) {
    if (!$this->configuration['enable_highlighting']) {
      return $column_name;
    }
    
    $config = $options['config'] ?? $this->configuration['text_search_config'];
    $max_words = $options['max_words'] ?? $this->configuration['highlight_max_words'];
    $min_words = $options['min_words'] ?? $this->configuration['highlight_min_words'];
    $max_fragments = $options['max_fragments'] ?? $this->configuration['highlight_max_fragments'];
    
    $highlight_options = "MaxWords={$max_words}, MinWords={$min_words}, MaxFragments={$max_fragments}";
    
    return "ts_headline('{$config}', {$column_name}, {$search_query}, '{$highlight_options}')";
  }

  /**
   * Generates SQL for ranking search results.
   *
   * @param string $tsvector_column
   *   The tsvector column name.
   * @param string $tsquery_param
   *   The tsquery parameter name.
   * @param array $options
   *   Ranking options.
   *
   * @return string
   *   The SQL expression for ranking.
   */
  public function getRankingSql($tsvector_column, $tsquery_param, array $options = []) {
    if (!$this->configuration['enable_ranking']) {
      return '1.0';
    }
    
    $normalization = $options['normalization'] ?? $this->configuration['ranking_normalization'];
    
    return "ts_rank({$tsvector_column}, {$tsquery_param}, {$normalization})";
  }

  /**
   * Gets available text search configurations.
   *
   * @return array
   *   Available configurations.
   */
  public static function getTextSearchConfigurations() {
    return static::$textSearchConfigurations;
  }

  /**
   * Gets stemming dictionaries.
   *
   * @return array
   *   Available stemming dictionaries.
   */
  public static function getStemmingDictionaries() {
    return static::$stemmingDictionaries;
  }

  /**
   * Creates a combined tsvector from multiple weighted fields.
   *
   * @param array $field_definitions
   *   Array of field definitions with weights.
   *
   * @return string
   *   The combined tsvector SQL expression.
   */
  public function getCombinedTsVectorSql(array $field_definitions) {
    $config = $this->configuration['text_search_config'];
    $expressions = [];
    
    foreach ($field_definitions as $field_id => $definition) {
      $weight = $definition['weight'] ?? 'D';
      $column = $definition['column'] ?? $field_id;
      
      $expressions[] = "setweight(to_tsvector('{$config}', COALESCE({$column}, '')), '{$weight}')";
    }
    
    if (empty($expressions)) {
      return "''::tsvector";
    }
    
    return implode(' || ', $expressions);
  }

  /**
   * Validates that required PostgreSQL extensions are available.
   *
   * @param \PDO $connection
   *   The database connection.
   *
   * @return array
   *   Array of validation results.
   */
  public function validatePostgreSQLSupport(\PDO $connection) {
    $results = [
      'fulltext' => FALSE,
      'trigram' => FALSE,
      'configurations' => [],
      'errors' => [],
    ];
    
    try {
      // Check for text search support
      $stmt = $connection->query("SELECT to_tsvector('english', 'test')");
      $results['fulltext'] = TRUE;
    } catch (\Exception $e) {
      $results['errors'][] = 'PostgreSQL full-text search not available: ' . $e->getMessage();
    }
    
    try {
      // Check for trigram extension (for fuzzy search)
      $stmt = $connection->query("SELECT similarity('test', 'text')");
      $results['trigram'] = TRUE;
    } catch (\Exception $e) {
      $results['errors'][] = 'PostgreSQL pg_trgm extension not available: ' . $e->getMessage();
    }
    
    try {
      // Get available text search configurations
      $stmt = $connection->query("SELECT cfgname FROM pg_ts_config");
      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $results['configurations'][] = $row['cfgname'];
      }
    } catch (\Exception $e) {
      $results['errors'][] = 'Could not retrieve text search configurations: ' . $e->getMessage();
    }
    
    return $results;
  }

}