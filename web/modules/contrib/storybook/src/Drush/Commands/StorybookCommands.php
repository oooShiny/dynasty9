<?php

namespace Drupal\storybook\Drush\Commands;

use Drupal\Core\Url;
use Drupal\storybook\Drush\RegexRecursiveFilterIterator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use TwigStorybook\Service\StoryRenderer;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 */
final class StorybookCommands extends DrushCommands {

  /**
   * Constructs a StorybookCommands object.
   */
  public function __construct(
    private readonly StoryRenderer $storyRenderer
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(StoryRenderer::class),
    );
  }

  /**
   * Finds all the Twig stories, and generates the JSON files, if necessary.
   */
  #[CLI\Command(name: 'storybook:generate-all-stories', aliases: ['generate-all-stories'])]
  #[CLI\Option(name: 'force', description: 'Generate JSON files even for stories that have not changed.')]
  #[CLI\Option(name: 'omit-server-url', description: 'Omits the server url parameter from the generated JSON files.')]
  public function generateAllStories($options = ['force' => FALSE, 'omit-server-url' => FALSE]): void {
    // Find all templates in the site and call generateStoriesForTemplate.
    $scan_dirs = ['modules', 'profiles', 'themes'];
    $template_files = array_reduce(
      $scan_dirs,
      fn(array $files, string $scan_dir) => [
        ...$files,
        ...$this->scanDirectory($scan_dir),
      ],
      [],
    );
    array_walk(
      $template_files,
      fn (\SplFileInfo $template_file) => $this->generateStoriesForTemplate(
        $template_file->getPathname(),
        $options,
      ),
    );
  }

  private function scanDirectory(string $directory): array {

    // Skip if directory doesn't exist.
    if (!is_dir($directory)) {
      return [];
    }

    // Use FilesystemIterator to not iterate over the . and .. directories.
    $flags = \FilesystemIterator::KEY_AS_PATHNAME
      | \FilesystemIterator::CURRENT_AS_FILEINFO
      | \FilesystemIterator::SKIP_DOTS;
    $directory_iterator = new \RecursiveDirectoryIterator($directory, $flags);
    // Detect "my_component.component.yml".
    $regex = '/^([a-z0-9_-])+\.stories\.twig$/i';
    $filter = new RegexRecursiveFilterIterator($directory_iterator, $regex);
    $it = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY, $flags);
    $files = [];
    foreach ($it as $file) {
      $this->validateTemplatePath($file);
      $files[] = $file;
    }
    return $files;
  }

  private function validateTemplatePath(string $template_path): void {
    // Validate path.
    if (!str_ends_with($template_path, '.stories.twig')) {
      throw new UnprocessableEntityHttpException(sprintf(
        'Invalid template path for the stories "%s".',
        $template_path
      ));
    }
    if (!str_starts_with(realpath($template_path), \Drupal::root())) {
      throw new UnprocessableEntityHttpException(sprintf(
        'Invalid template name for the stories "%s". Paths outside the Drupal application are not allowed.',
        $template_path
      ));
    }
  }

  /**
   * Given a template path, relative to the Drupal root, generate the stories.
   */
  #[CLI\Command(name: 'storybook:generate-stories', aliases: ['generate-stories'])]
  #[CLI\Argument(name: 'template_path', description: 'Path to the *.stories.twig template file. This path should be relative to the Drupal root.')]
  #[CLI\Option(name: 'force', description: 'Generate JSON files even for stories that have not changed.')]
  #[CLI\Option(name: 'omit-server-url', description: 'Omits the server url parameter from the generated JSON files.')]
  public function generateStoriesForTemplate(string $template_path, $options = ['force' => FALSE, 'omit-server-url' => FALSE]): void {
    $root = \Drupal::root();
    $url = '';
    if (!$options['omit-server-url']) {
      $url = Url::fromUri('internal:/storybook/stories/render', ['absolute' => TRUE])
        ->toString(TRUE)
        ->getGeneratedUrl();
    }
    $template_file = new \SplFileInfo($root . DIRECTORY_SEPARATOR . $template_path);
    $destination_path = preg_replace('/\.stories\.twig/', '.stories.json', $template_path);
    $should_generate = TRUE;
    if (file_exists($root . DIRECTORY_SEPARATOR . $destination_path)) {
      $destination_file = new \SplFileInfo($root . DIRECTORY_SEPARATOR . $destination_path);
      $should_generate = $destination_file->getMTime() < $template_file->getMTime();
    }
    $should_generate = $should_generate || $options['force'];
    if (!$should_generate) {
      $this->logger()->success(dt('Skipping JSON file generation for %path.', ['%path' => $destination_path]));
      return;
    }
    $data = $this->storyRenderer
      ->generateStoriesJsonFile($template_path, $url);
    if ($template_path === $destination_path) {
      throw new CommandFailedException('Cannot overwrite the current template path.');
    }
    try {
      file_put_contents($destination_path, json_encode($data, JSON_THROW_ON_ERROR));
    }
    catch (\JsonException $e) {
      throw new CommandFailedException('JSON encoding failed.', previous: $e);
    }
    $options['verbose'] ?? FALSE
      ? $this->logger()->success(dt("JSON file generated for %path.\n\n@contents", ['%path' => $destination_path, '@contents' => json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]))
      : $this->logger()->success(dt('JSON file generated for %path.', ['%path' => $destination_path]));
  }

}
