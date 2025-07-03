<?php

namespace Drupal\markdownify_path\PathProcessor;

use Drupal\markdownify\PathProcessor\MarkdownifyPathProcessor;
use Drupal\markdownify\Utility\MarkdownifyPath;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Processes inbound paths to enable Markdownify functionality for path aliases.
 *
 * This class intercepts inbound requests and modifies paths ending with ".md"
 * to ensure they resolve correctly using their associated path aliases. This
 * allows users to access Markdownified versions of content using the
 * human-readable path aliases, improving usability and SEO compliance.
 *
 * Example:
 * - Given a path alias:
 *   /en/articles/give-your-oatmeal-the-ultimate-makeover
 * - Users can access the Markdown version at:
 *   /en/articles/give-your-oatmeal-the-ultimate-makeover.md
 *
 * @see \Drupal\Core\PathProcessor\PathProcessorInterface
 */
class MarkdownifyAliasPathProcessor extends MarkdownifyPathProcessor {

  /**
   * The alias manager service for resolving path aliases.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected AliasManagerInterface $aliasManager;

  /**
   * Constructs a new MarkdownifyAliasPathProcessor object.
   *
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The alias manager service for resolving system paths.
   */
  public function __construct(AliasManagerInterface $alias_manager) {
    $this->aliasManager = $alias_manager;
  }

  /**
   * {@inheritdoc}
   *
   * Modifies inbound paths ending with ".md" to their corresponding
   * Markdown-specific system paths.
   *
   * This method:
   * - Verifies if the path ends with ".md".
   * - Resolves the system path using the alias manager.
   * - Returns the converted path in Markdown format, if applicable.
   */
  public function processInbound($path, Request $request): string {
    // Ensure the path is valid and ends with ".md".
    if (!$this->isPathValid($path) || !MarkdownifyPath::isMarkdownifyFormat($path, TRUE)) {
      return $path;
    }
    // Remove the ".md" extension to get the alias.
    $alias = MarkdownifyPath::removeExtension($path);
    // Resolve the system path from the alias manager.
    $resolved_path = $this->aliasManager->getPathByAlias($alias);
    // If no valid system path is found, return the original path.
    if ($resolved_path === $alias) {
      return $path;
    }
    // Convert the path to a Markdown-specific format.
    return MarkdownifyPath::convertToMarkdownifyPath($resolved_path, FALSE);
  }

}
