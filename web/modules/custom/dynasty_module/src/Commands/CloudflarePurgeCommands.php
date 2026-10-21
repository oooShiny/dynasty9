<?php

namespace Drupal\dynasty_module\Commands;

use Drupal\cloudflare_purge\Purge;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Cloudflare cache purging.
 */
class CloudflarePurgeCommands extends DrushCommands {

  /**
   * @var \Drupal\cloudflare_purge\Purge
   */
  protected $purge;

  public function __construct(Purge $purge) {
    parent::__construct();
    $this->purge = $purge;
  }

  /**
   * Purge the Cloudflare cache.
   *
   * @param string $url
   *   Optional URL to purge. Omit to purge everything.
   *
   * @command dynasty:cf-purge
   * @aliases cf-purge
   * @usage dynasty:cf-purge
   *   Purge entire Cloudflare cache.
   * @usage dynasty:cf-purge https://patriotsdynasty.com/node/1
   *   Purge a single URL from Cloudflare cache.
   */
  public function purge(string $url = '') {
    // cloudflare_purge 3.x replaced Purge::purge($url) with purgeByUrls() /
    // purgeEverything(), which return a CloudflarePurgeResult. showMessage is
    // FALSE because we report the outcome through drush's own logger.
    if ($url) {
      $this->logger()->notice('Purging Cloudflare cache for: {url}', ['url' => $url]);
      $result = $this->purge->purgeByUrls([$url], FALSE);
    }
    else {
      $this->logger()->notice('Purging entire Cloudflare cache...');
      $result = $this->purge->purgeEverything(FALSE);
    }

    if (!$result->isSuccess()) {
      $this->logger()->error('Cloudflare purge failed (HTTP {status}): {message}', [
        'status' => $result->getStatusCode(),
        'message' => $result->getMessage(),
      ]);
      foreach ($result->getErrors() as $error) {
        $this->logger()->error(is_array($error) ? json_encode($error) : (string) $error);
      }
      throw new \Exception('Cloudflare purge failed.');
    }

    $this->logger()->success('Cloudflare cache purged successfully.');
  }

}
