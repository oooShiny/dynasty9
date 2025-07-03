<?php

declare(strict_types=1);

namespace Drupal\markdownify_file_attachment\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\file\Plugin\Field\FieldFormatter\FileFormatterBase;
use Drupal\file\Plugin\Field\FieldType\FileFieldItemList;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'entity_reference_file_attachment' formatter.
 *
 * @FieldFormatter(
 *   id = "md_file_attachment_file_embed",
 *   label = @Translation("Embed file as Markdown"),
 *   field_types = {
 *     "file"
 *   }
 * )
 */
final class MdFileAttachmentFieldFormatter extends FileFormatterBase implements ContainerFactoryPluginInterface {

  /**
   * File URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  private FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Renderer interface.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  private RendererInterface $renderer;

  /**
   * Logger interface.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * File system interface.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = new self(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
    );

    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->renderer = $container->get('renderer');
    $instance->logger = $container->get('logger.channel.markdownify');
    $instance->fileSystem = $container->get('file_system');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'allowed_extensions' => ['yml', 'txt'],
      'max_size' => 1024,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    assert($items instanceof FileFieldItemList);
    $element = [];

    $allowed_extensions = $this->getSetting('allowed_extensions');
    $max_size = $this->getSetting('max_size');

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $file) {

      if (!$file) {
        continue;
      }

      $file_size = $file->getSize();
      $file_name = $file->getFilename();
      $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
      $uri = $file->getFileUri();
      $url = '';
      try {
        $context = new RenderContext();
        $url = $this->renderer->executeInRenderContext($context, function () use ($uri) {
          return $this->fileUrlGenerator->generateAbsoluteString($uri);
        });
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to render file URL: @message', ['@message' => $e->getMessage()]);
      }

      $real_path = $this->fileSystem->realpath($uri);

      if (in_array($extension, $allowed_extensions, TRUE) && $file_size <= $max_size) {
        $content = @file_get_contents($real_path);
        $element[$delta] = [
          '#type' => 'markup',
          '#markup' => $this->t('Attached file %filename with %extension extension available at %url follows:<br>%file', [
            '%filename' => $file_name,
            '%extension' => $extension,
            '%file' => $content,
            '%url' => $url,
          ]),
        ];
      }
      else {
        $element[$delta] = [
          '#type' => 'markup',
          '#markup' => $this->t('Attached file %filename with %extension extension available at %url', [
            '%filename' => $file_name,
            '%extension' => $extension,
            '%url' => $url,
          ]),
        ];
      }

    }

    return $element;
  }

}
