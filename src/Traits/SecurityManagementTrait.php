<?php

namespace Drupal\search_api_postgresql\Traits;

use Drupal\Core\Form\FormStateInterface;

/**
 * Trait for managing secure credentials in Search API PostgreSQL backends.
 */
trait SecurityManagementTrait {
  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Adds key-based credential fields to a form if the Key module is available.
   *
   * @param array $form
   *   The form array to modify.
   */
  protected function addKeyFieldsToForm(array &$form) {
    // Only add key fields if Key module is available.
    if (!$this->keyRepository) {
      return;
    }

    try {
      $key_options = $this->getKeyOptions();

      if (!empty($key_options)) {
        // Database password key field.
        $form['connection']['password_key'] = [
          '#type' => 'select',
          '#title' => $this->t('Database Password Key'),
          '#options' => $key_options,
          '#empty_option' => $this->t('- Select a key -'),
          '#default_value' => $this->configuration['connection']['password_key'] ?? '',
          '#description' => $this->t('Use a key from the Key module instead of entering password directly. More secure for production.'),
        ];

        // Azure OpenAI API key field.
        if (isset($form['ai_embeddings']['azure'])) {
          $form['ai_embeddings']['azure']['api_key_key'] = [
            '#type' => 'select',
            '#title' => $this->t('Azure API Key'),
            '#options' => $key_options,
            '#empty_option' => $this->t('- Select a key -'),
            '#default_value' => $this->configuration['ai_embeddings']['azure']['api_key_key'] ?? '',
            '#description' => $this->t('Use a key from the Key module for Azure OpenAI API key.'),
          ];
        }

        // OpenAI API key field.
        if (isset($form['ai_embeddings']['openai'])) {
          $form['ai_embeddings']['openai']['api_key_key'] = [
            '#type' => 'select',
            '#title' => $this->t('OpenAI API Key'),
            '#options' => $key_options,
            '#empty_option' => $this->t('- Select a key -'),
            '#default_value' => $this->configuration['ai_embeddings']['openai']['api_key_key'] ?? '',
            '#description' => $this->t('Use a key from the Key module for OpenAI API key.'),
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Key module might not be properly configured, continue without it.
      \Drupal::logger('search_api_postgresql')->notice('Key module available but not configured properly: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Gets available key options for form select elements.
   *
   * @return array
   *   Array of key options suitable for form select elements.
   */
  protected function getKeyOptions() {
    if (!$this->keyRepository) {
      return [];
    }

    try {
      $keys = $this->keyRepository->getKeys();
      $options = [];

      foreach ($keys as $key_id => $key) {
        $options[$key_id] = $key->label() . ' (' . $key_id . ')';
      }

      return $options;
    }
    catch (\Exception $e) {
      \Drupal::logger('search_api_postgresql')->error('Failed to load keys: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Retrieves a credential value, either from direct configuration or Key module.
   *
   * @param string $credential_type
   *   The type of credential ('database_password', 'azure_api_key', 'openai_api_key').
   * @param array $config
   *   The configuration array containing credential info.
   *
   * @return string|null
   *   The credential value or NULL if not found.
   */
  protected function getCredential($credential_type, array $config) {
    switch ($credential_type) {
      case 'database_password':
        return $this->getPasswordCredential($config['connection'] ?? []);

      case 'azure_api_key':
        return $this->getAzureApiKeyCredential($config['ai_embeddings']['azure'] ?? []);

      case 'openai_api_key':
        return $this->getOpenAiApiKeyCredential($config['ai_embeddings']['openai'] ?? []);

      default:
        return NULL;
    }
  }

  /**
   * Gets the database password from configuration or Key module.
   *
   * @param array $connection_config
   *   The connection configuration.
   *
   * @return string|null
   *   The password value or NULL if not found.
   */
  protected function getPasswordCredential(array $connection_config) {
    // Try key first.
    if (!empty($connection_config['password_key']) && $this->keyRepository) {
      try {
        $key = $this->keyRepository->getKey($connection_config['password_key']);
        if ($key) {
          return $key->getKeyValue();
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('search_api_postgresql')->warning('Failed to retrieve password key @key: @error', [
          '@key' => $connection_config['password_key'],
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Fall back to direct password.
    return $connection_config['password'] ?? NULL;
  }

  /**
   * Gets the Azure OpenAI API key from configuration or Key module.
   *
   * @param array $azure_config
   *   The Azure configuration.
   *
   * @return string|null
   *   The API key value or NULL if not found.
   */
  protected function getAzureApiKeyCredential(array $azure_config) {
    // Try key first.
    if (!empty($azure_config['api_key_key']) && $this->keyRepository) {
      try {
        $key = $this->keyRepository->getKey($azure_config['api_key_key']);
        if ($key) {
          return $key->getKeyValue();
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('search_api_postgresql')->warning('Failed to retrieve Azure API key @key: @error', [
          '@key' => $azure_config['api_key_key'],
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Fall back to direct API key.
    return $azure_config['api_key'] ?? NULL;
  }

  /**
   * Gets the OpenAI API key from configuration or Key module.
   *
   * @param array $openai_config
   *   The OpenAI configuration.
   *
   * @return string|null
   *   The API key value or NULL if not found.
   */
  protected function getOpenAiApiKeyCredential(array $openai_config) {
    // Try key first.
    if (!empty($openai_config['api_key_key']) && $this->keyRepository) {
      try {
        $key = $this->keyRepository->getKey($openai_config['api_key_key']);
        if ($key) {
          return $key->getKeyValue();
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('search_api_postgresql')->warning('Failed to retrieve OpenAI API key @key: @error', [
          '@key' => $openai_config['api_key_key'],
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Fall back to direct API key.
    return $openai_config['api_key'] ?? NULL;
  }

  /**
   * Validates credential configuration.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $credential_type
   *   The type of credential to validate.
   */
  protected function validateCredentials(array &$form, FormStateInterface $form_state, $credential_type) {
    $values = $form_state->getValues();

    switch ($credential_type) {
      case 'database_password':
        $this->validateDatabasePasswordCredentials($form, $form_state, $values);
        break;

      case 'azure_api_key':
        $this->validateAzureApiKeyCredentials($form, $form_state, $values);
        break;

      case 'openai_api_key':
        $this->validateOpenAiApiKeyCredentials($form, $form_state, $values);
        break;
    }
  }

  /**
   * Validates database password credentials.
   */
  protected function validateDatabasePasswordCredentials(array &$form, FormStateInterface $form_state, array $values) {
    $connection = $values['connection'] ?? [];
    $password_key = $connection['password_key'] ?? '';
    $direct_password = $connection['password'] ?? '';

    // It's acceptable to have no password (for development with trust auth)
    // but validate that if a key is specified, it exists.
    if (!empty($password_key) && $this->keyRepository) {
      try {
        $key = $this->keyRepository->getKey($password_key);
        if (!$key) {
          $form_state->setErrorByName('connection][password_key', $this->t('The specified password key does not exist.'));
        }
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('connection][password_key', $this->t('Failed to validate password key: @error', ['@error' => $e->getMessage()]));
      }
    }
  }

  /**
   * Validates Azure API key credentials.
   */
  protected function validateAzureApiKeyCredentials(array &$form, FormStateInterface $form_state, array $values) {
    if (empty($values['ai_embeddings']['enabled'])) {
      // AI embeddings not enabled, skip validation.
      return;
    }

    if ($values['ai_embeddings']['provider'] !== 'azure') {
      // Not using Azure provider.
      return;
    }

    $azure = $values['ai_embeddings']['azure'] ?? [];
    $api_key_key = $azure['api_key_key'] ?? '';
    $direct_api_key = $azure['api_key'] ?? '';
    $existing_api_key = $this->configuration['ai_embeddings']['azure']['api_key'] ?? '';

    // Require API key if Azure is enabled.
    if (empty($api_key_key) && empty($direct_api_key) && empty($existing_api_key)) {
      $form_state->setErrorByName('ai_embeddings][azure][api_key', $this->t('Azure OpenAI API key is required when Azure provider is enabled.'));
    }

    // Validate key exists if specified.
    if (!empty($api_key_key) && $this->keyRepository) {
      try {
        $key = $this->keyRepository->getKey($api_key_key);
        if (!$key) {
          $form_state->setErrorByName('ai_embeddings][azure][api_key_key', $this->t('The specified Azure API key does not exist.'));
        }
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('ai_embeddings][azure][api_key_key', $this->t('Failed to validate Azure API key: @error', ['@error' => $e->getMessage()]));
      }
    }
  }

  /**
   * Validates OpenAI API key credentials.
   */
  protected function validateOpenAiApiKeyCredentials(array &$form, FormStateInterface $form_state, array $values) {
    if (empty($values['ai_embeddings']['enabled'])) {
      // AI embeddings not enabled, skip validation.
      return;
    }

    if ($values['ai_embeddings']['provider'] !== 'openai') {
      // Not using OpenAI provider.
      return;
    }

    $openai = $values['ai_embeddings']['openai'] ?? [];
    $api_key_key = $openai['api_key_key'] ?? '';
    $direct_api_key = $openai['api_key'] ?? '';
    $existing_api_key = $this->configuration['ai_embeddings']['openai']['api_key'] ?? '';

    // Require API key if OpenAI is enabled.
    if (empty($api_key_key) && empty($direct_api_key) && empty($existing_api_key)) {
      $form_state->setErrorByName('ai_embeddings][openai][api_key', $this->t('OpenAI API key is required when OpenAI provider is enabled.'));
    }

    // Validate key exists if specified.
    if (!empty($api_key_key) && $this->keyRepository) {
      try {
        $key = $this->keyRepository->getKey($api_key_key);
        if (!$key) {
          $form_state->setErrorByName('ai_embeddings][openai][api_key_key', $this->t('The specified OpenAI API key does not exist.'));
        }
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('ai_embeddings][openai][api_key_key', $this->t('Failed to validate OpenAI API key: @error', ['@error' => $e->getMessage()]));
      }
    }
  }

  /**
   * Processes credential submission during form submission.
   *
   * @param array $values
   *   The submitted form values.
   *
   * @return array
   *   Processed configuration array.
   */
  protected function processCredentialSubmission(array $values) {
    $processed_config = [];

    // Process database password.
    if (isset($values['connection'])) {
      $processed_config['connection'] = $values['connection'];

      // Keep password_key but don't store direct password if key is used.
      if (!empty($values['connection']['password_key'])) {
        unset($processed_config['connection']['password']);
      }
    }

    // Process AI credentials.
    if (isset($values['ai_embeddings'])) {
      $processed_config['ai_embeddings'] = $values['ai_embeddings'];

      // Azure credentials.
      if (isset($values['ai_embeddings']['azure'])) {
        if (!empty($values['ai_embeddings']['azure']['api_key_key'])) {
          unset($processed_config['ai_embeddings']['azure']['api_key']);
        }
      }

      // OpenAI credentials.
      if (isset($values['ai_embeddings']['openai'])) {
        if (!empty($values['ai_embeddings']['openai']['api_key_key'])) {
          unset($processed_config['ai_embeddings']['openai']['api_key']);
        }
      }
    }

    return $processed_config;
  }

  /**
   * Checks if a credential is available (either direct or via key).
   *
   * @param string $credential_type
   *   The credential type to check.
   * @param array $config
   *   The configuration array.
   *
   * @return bool
   *   TRUE if credential is available, FALSE otherwise.
   */
  protected function hasCredential($credential_type, array $config) {
    $credential = $this->getCredential($credential_type, $config);
    return !empty($credential);
  }

}
