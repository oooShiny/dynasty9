<?php

namespace Drupal\storybook\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TwigStorybook\Exception\StoryRenderException;
use TwigStorybook\Service\StoryRenderer;

/**
 * Provides an endpoint for Storybook to query.
 *
 * @see https://github.com/storybookjs/storybook/tree/next/app/server
 */
class ServerController extends ControllerBase {

  /**
   * Kill-switch to avoid caching the page.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  private KillSwitch $cacheKillSwitch;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private StateInterface $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  private TimeInterface $time;

  /**
   * Indicates if the site is operating in development mode.
   *
   * @var bool
   */
  private bool $developmentMode;

  /**
   * The story renderer.
   *
   * @var \TwigStorybook\Service\StoryRenderer
   */
  private StoryRenderer $storyRenderer;

  /**
   * Creates an object.
   *
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $cache_kill_switch
   *   The cache kill switch.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(KillSwitch $cache_kill_switch, StateInterface $state, TimeInterface $time, StoryRenderer $story_renderer, bool $development_mode) {
    $this->cacheKillSwitch = $cache_kill_switch;
    $this->state = $state;
    $this->time = $time;
    $this->storyRenderer = $story_renderer;
    $this->developmentMode = $development_mode;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $cache_kill_switch = $container->get('page_cache_kill_switch');
    assert($cache_kill_switch instanceof KillSwitch);
    $state = $container->get('state');
    assert($state instanceof StateInterface);
    $time = $container->get('datetime.time');
    assert($time instanceof TimeInterface);
    $story_renderer = $container->get(StoryRenderer::class);
    assert($story_renderer instanceof StoryRenderer);
    $development_mode = (bool) $container->getParameter('storybook.development');
    return new static($cache_kill_switch, $state, $time, $story_renderer, $development_mode);
  }


  public function renderStory(string $hash, Request $request): array {
    try {
      $markup = $this->storyRenderer->renderStory($hash, $request);
    }
    catch (StoryRenderException $e) {
      throw new HttpException(500, $e->getMessage(), previous: $e);
    }
    return [
      '#attached' => ['library' => ['storybook/attach_behaviors']],
      '#type' => 'container',
      '#cache' => $this->developmentMode ? ['max-age' => 0] : ['contexts' => ['url.query_args']],
      '#attributes' => ['id' => '___storybook_wrapper'],
      'template' => ['#markup' => Markup::create($markup)],
    ];
  }

  /**
   * Checks access for the storybook render route.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    if ($this->developmentMode) {
      return AccessResult::allowed();
    }

    return AccessResult::allowedIfHasPermission($account, 'render storybook stories');
  }

}
