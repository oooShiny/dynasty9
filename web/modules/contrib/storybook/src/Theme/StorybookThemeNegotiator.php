<?php

namespace Drupal\storybook\Theme;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Forces a component to render with the selected theme.
 */
class StorybookThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  private ThemeHandlerInterface $themeHandler;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private RequestStack $requestStack;

  /**
   * Construct the ClServerThemeNegotiator object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack used to retrieve the current request.
   */
  public function __construct(RequestStack $request_stack, ThemeHandlerInterface $theme_handler) {
    $this->requestStack = $request_stack;
    $this->themeHandler = $theme_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    if (!$route_name) {
      return FALSE;
    }
    $theme = $this->getTheme();
    return ($route_name === 'storybook.render' && !empty($theme));
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return $this->getTheme();
  }

  /**
   * Gets the theme as requested in the URL parameters.
   *
   * @return string
   *   The theme name.
   */
  private function getTheme(): string {
    $request = $this->requestStack->getCurrentRequest();
    $theme = (string) $request->query->get('_drupalTheme');
    $theme_list = \Drupal::service('extension.list.theme')->getList();
    $all_themes = array_keys($theme_list);
    if (in_array($theme, $all_themes)) {
      return $theme;
    }
    return $this->themeHandler->getDefault();
  }

}
