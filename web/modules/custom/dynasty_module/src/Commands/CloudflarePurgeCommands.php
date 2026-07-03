<?php

namespace Drupal\dynasty_module\Commands;

use Drupal\cloudflare_purge\Purge;
use Drupal\Core\Messenger\MessengerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Cloudflare cache purging.
 */
class CloudflarePurgeCommands extends DrushCommands {

  /**
   * @var \Drupal\cloudflare_purge\Purge
   */
  protected $purge;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  public function __construct(Purge $purge, MessengerInterface $messenger) {
    parent::__construct();
    $this->purge = $purge;
    $this->messenger = $messenger;
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
    $this->messenger->deleteAll();

    if ($url) {
      $this->logger()->notice('Purging Cloudflare cache for: {url}', ['url' => $url]);
    }
    else {
      $this->logger()->notice('Purging entire Cloudflare cache...');
    }

    $this->purge->purge($url);

    $messages = $this->messenger->all();
    $this->messenger->deleteAll();

    if (!empty($messages[MessengerInterface::TYPE_ERROR])) {
      foreach ($messages[MessengerInterface::TYPE_ERROR] as $msg) {
        $this->logger()->error((string) $msg);
      }
      throw new \Exception('Cloudflare purge failed.');
    }

    $this->logger()->success('Cloudflare cache purged successfully.');
  }

}
