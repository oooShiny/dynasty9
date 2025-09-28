<?php

namespace Drupal\storybook\EventSubscriber;

use Drupal\storybook\Util;
use Masterminds\HTML5;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class AlterBodySubscriber implements EventSubscriberInterface {

  /**
   * Remove the X-Frame-Options header from the response for our route.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   *
   * @throws \DOMException
   */
  public function alter(ResponseEvent $event): void {
    if (!Util::isRenderController($event->getRequest())) {
      return;
    }
    $response = $event->getResponse();
    if ($response->isClientError() || $response->isServerError()) {
      return;
    }
    $response->headers->remove('X-Frame-Options');
    $html = $response->getContent();
    if (empty($html)) {
      return;
    }
    $html5 = new HTML5(['disable_html_ns' => TRUE, 'encoding' => 'UTF-8']);
    $dom = $html5->loadHTML($html);
    $wrapper_contents = $dom->getElementById('___storybook_wrapper');
    if (is_null($wrapper_contents)) {
      return;
    }
    $crawler = new Crawler($dom);
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) {
      throw new HttpException(500, 'Unable to process a response without a body.');
    }
    $body_scripts = $crawler->filter('body script');

    // Now create the new body to attach all the things to it.
    $new_body = $dom->createElement('body');
    // Clone the node attributes.
    foreach ($body->attributes as $attr_name => $attr_value) {
      $new_body->setAttribute($attr_name, $attr_value->value);
    }
    // Add into the new body, everything that we found inside the wrapper.
    $new_body->appendChild($wrapper_contents);
    // We also need any script that is found in the body, since there is no way
    // to ensure the script isn't necessary for our rendered template.
    foreach ($body_scripts as $body_script) {
      $new_body->appendChild($body_script);
    }

    // Make the new body take the place of the old.
    $dom->getElementsByTagName('html')
      ->item(0)
      ?->replaceChild($new_body, $body);

    // Set the new HTML in the response.
    $response->setContent($dom->saveHTML());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['alter', -10];
    return $events;
  }

}
