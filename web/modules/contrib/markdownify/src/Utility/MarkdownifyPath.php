<?php

namespace Drupal\markdownify\Utility;

use Symfony\Component\HttpFoundation\Request;

/**
 * Centralized class for handling paths in the Markdownify module.
 *
 * This class contains constants and shared methods used across the module
 * to process and validate Markdownify-related paths.
 */
final class MarkdownifyPath {

  /**
   * The prefix for all Markdownify paths.
   */
  public const PATH_PREFIX = 'markdownify';

  /**
   * Builds the Markdownify route path.
   *
   * Converts a given path into a Markdown-specific format by:
   * - Prepending the "/markdownify" prefix.
   * - Optionally stripping the ".md" suffix, if present.
   *
   * @param string $path
   *   The original path, which may or may not end with ".md".
   * @param bool $strip_md_suffix
   *   (Optional) Whether to remove the ".md" suffix from the original path.
   *   Defaults to TRUE.
   *
   * @return string
   *   The converted Markdownify route path.
   */
  public static function convertToMarkdownifyPath(string $path, bool $strip_md_suffix = TRUE): string {
    // Trim leading slashes and normalize the path.
    $normalized_path = self::normalizePath($path);
    // Optionally strip the ".md" suffix.
    if ($strip_md_suffix) {
      $normalized_path = self::removeExtension($normalized_path);
    }
    // Prepend the "/markdownify" prefix.
    return '/' . self::PATH_PREFIX . '/' . $normalized_path;
  }

  /**
   * Removes the '.md' suffix from a given path if present.
   *
   * @param string $path
   *   The original path.
   *
   * @return string
   *   The path without the '.md' suffix.
   */
  public static function removeExtension(string $path): string {
    // Check if the path ends with '.md'.
    if (!self::isMarkdownifyFormat($path, FALSE)) {
      return $path;
    }
    // Remove Extension.
    return substr($path, 0, -strlen('.md'));
  }

  /**
   * Determines if the given path is a Markdown-related path.
   *
   * @param string $path
   *   The path to check.
   * @param bool $normalize
   *   (Optional) Whether to normalize the path. Defaults to TRUE.
   *
   * @return bool
   *   TRUE if the path starts with "markdownify", FALSE otherwise.
   */
  public static function isMarkdownifyPath(string $path, bool $normalize = TRUE): bool {
    // Normalize the path by trimming spaces and converting to lowercase.
    $path_to_check = $normalize ? self::normalizePath($path) : $path;
    // Check if the path starts with the Markdownify prefix.
    return str_starts_with($path_to_check, self::PATH_PREFIX);
  }

  /**
   * Determines if the given path ends with the ".md" suffix.
   *
   * @param string $path
   *   The path to check.
   * @param bool $normalize
   *   (Optional) Whether to normalize the path. Defaults to TRUE.
   *
   * @return bool
   *   TRUE if the path ends with ".md", FALSE otherwise.
   */
  public static function isMarkdownifyFormat(string $path, bool $normalize = TRUE): bool {
    // Normalize the path by trimming spaces and converting to lowercase.
    $path_to_check = $normalize ? self::normalizePath($path) : $path;
    // Check if the path ends with the ".md" suffix.
    return str_ends_with($path_to_check, '.md');
  }

  /**
   * Checks if the request is targeting a Markdownify format.
   *
   * This checks if:
   * - The `Content-Type` header specifies the 'markdown' format.
   * - The `Accept` header prefers the 'markdown' format.
   * - The requested path ends with ".md".
   * - The requested path starts with the Markdownify prefix.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request object.
   *
   * @return bool
   *   TRUE if the request is targeting a Markdownify format, FALSE otherwise.
   */
  public static function isMarkdownRequest(Request $request): bool {
    // Check if the Content-Type header specifies 'markdown'.
    if ($request->getContentTypeFormat() === 'markdown') {
      return TRUE;
    }
    // Check if the Accept header prefers 'markdown'.
    if ($request->getPreferredFormat() === 'markdown') {
      return TRUE;
    }
    // Check if the path ends with ".md".
    $path = $request->getPathInfo();
    if (self::isMarkdownifyFormat($path, TRUE)) {
      return TRUE;
    }
    // Check if the path starts with the Markdownify prefix.
    if (self::isMarkdownifyPath($path, TRUE)) {
      return TRUE;
    }
    // None of the conditions matched; return FALSE.
    return FALSE;
  }

  /**
   * Normalizes the given path by converting to lowercase and trimming slashes.
   *
   * @param string $path
   *   The request path.
   *
   * @return string
   *   The normalized path.
   */
  protected static function normalizePath(string $path): string {
    $normalized_path = strtolower(trim($path));
    return trim($normalized_path, '/');
  }

}
