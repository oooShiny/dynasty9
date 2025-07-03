<?php

namespace Drupal\markdownify;

/**
 * Interface for managing and validating supported entity types.
 *
 * Defines methods for retrieving and checking the supported entity types
 * that Markdownify can process.
 */
interface MarkdownifySupportedEntityTypesValidatorInterface {

  /**
   * Retrieves the list of entity types supported by Markdownify.
   *
   * @return string[]
   *   An array of supported entity type IDs.
   */
  public function getSupportedEntityTypes(): array;

  /**
   * Checks if a given entity type is supported by Markdownify.
   *
   * @param string $entity_type
   *   The entity type ID to check.
   *
   * @return bool
   *   TRUE if the entity type is supported, FALSE otherwise.
   */
  public function isSupported(string $entity_type): bool;

}
