<?php

namespace Drupal\search_api_postgresql\Service;

/**
 * Interface for embedding services.
 */
interface EmbeddingServiceInterface
{

  /**
   * Generates an embedding for the given text.
   * {@inheritdoc}
   *
   * @param string $text
   *   The text to embed.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   Array of floats representing the embedding, or NULL on failure.
   */
  public function generateEmbedding($text);

  /**
   * Gets the dimension of embeddings produced by this service.
   * {@inheritdoc}
   *
   * @return int
   *   The embedding dimension.
   */
  public function getDimension();

  /**
   * Generates embeddings for multiple texts in batch.
   * {@inheritdoc}
   *
   * @param array $texts
   *   Array of texts to embed.
   *   {@inheritdoc}.
   *
   * @return array
   *   Array of embeddings keyed by input index, or empty array on failure.
   */
  public function generateBatchEmbeddings(array $texts);

  /**
   * Checks if the service is properly configured and available.
   * {@inheritdoc}
   *
   * @return bool
   *   true if service is available, false otherwise.
   */
  public function isAvailable();
}
