<?php

namespace Drupal\dynasty_voicemail\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Uuid\UuidInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service for handling voicemail file storage and conversion.
 */
class VoicemailStorageService {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a VoicemailStorageService.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    FileSystemInterface $file_system,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
  ) {
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Saves an uploaded voicemail file.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $file
   *   The uploaded file.
   * @param string $name
   *   Sender name.
   * @param string $email
   *   Sender email.
   * @param int $duration
   *   Recording duration in seconds.
   *
   * @return array
   *   Array with 'file_path' and 'file_url' keys.
   *
   * @throws \Exception
   *   If file cannot be saved.
   */
  public function saveVoicemail(UploadedFile $file, string $name, string $email, int $duration): array {
    // Ensure directory exists.
    $directory = 'private://voicemails';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Generate unique filename with UUID.
    $uuid = \Drupal::service('uuid')->generate();
    $date_prefix = date('Y-m-d');
    $original_extension = $this->getExtensionFromMime($file->getMimeType());

    $filename = sprintf('%s_%s.%s', $date_prefix, $uuid, $original_extension);
    $destination = $directory . '/' . $filename;

    // Move the uploaded file.
    $temp_path = $file->getRealPath();
    $saved_path = $this->fileSystem->copy($temp_path, $destination, FileSystemInterface::EXISTS_REPLACE);

    if (!$saved_path) {
      throw new \Exception('Failed to save uploaded file.');
    }

    // Attempt FFmpeg conversion to MP3.
    $final_path = $this->convertToMp3($saved_path);

    // Generate URL for the file.
    $file_url = $this->generateFileUrl($final_path);

    // Log metadata.
    $this->logger->info('Voicemail saved: @path from @name (@email), duration: @duration seconds', [
      '@path' => $final_path,
      '@name' => $name,
      '@email' => $email,
      '@duration' => $duration,
    ]);

    return [
      'file_path' => $final_path,
      'file_url' => $file_url,
    ];
  }

  /**
   * Converts audio file to MP3 using FFmpeg.
   *
   * @param string $source_path
   *   The source file path (Drupal stream wrapper).
   *
   * @return string
   *   The path to the final file (MP3 if converted, original otherwise).
   */
  protected function convertToMp3(string $source_path): string {
    // Check if FFmpeg is available.
    $ffmpeg_path = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
    if (empty($ffmpeg_path)) {
      $this->logger->notice('FFmpeg not available, keeping original format.');
      return $source_path;
    }

    $real_source = $this->fileSystem->realpath($source_path);
    if (!$real_source) {
      $this->logger->warning('Could not resolve real path for: @path', ['@path' => $source_path]);
      return $source_path;
    }

    // Generate MP3 destination path.
    $mp3_path = preg_replace('/\.[^.]+$/', '.mp3', $source_path);
    $real_mp3 = preg_replace('/\.[^.]+$/', '.mp3', $real_source);

    // Run FFmpeg conversion.
    $command = sprintf(
      '%s -i %s -codec:a libmp3lame -qscale:a 4 %s 2>&1',
      escapeshellcmd($ffmpeg_path),
      escapeshellarg($real_source),
      escapeshellarg($real_mp3)
    );

    $output = [];
    $return_code = 0;
    exec($command, $output, $return_code);

    if ($return_code !== 0) {
      $this->logger->error('FFmpeg conversion failed: @output', ['@output' => implode("\n", $output)]);
      return $source_path;
    }

    // Verify the MP3 was created.
    if (!file_exists($real_mp3)) {
      $this->logger->error('FFmpeg did not create MP3 file.');
      return $source_path;
    }

    // Delete the original file.
    try {
      $this->fileSystem->delete($source_path);
      $this->logger->info('Converted to MP3 and deleted original: @path', ['@path' => $source_path]);
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not delete original file: @path', ['@path' => $source_path]);
    }

    return $mp3_path;
  }

  /**
   * Generates a URL for accessing the file.
   *
   * @param string $file_path
   *   The file path (Drupal stream wrapper).
   *
   * @return string
   *   The absolute URL to access the file.
   */
  protected function generateFileUrl(string $file_path): string {
    // For private files, we need to generate a system/files URL.
    // This requires authentication to access.
    $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file_path);
    return $url;
  }

  /**
   * Gets file extension from MIME type.
   *
   * @param string $mime_type
   *   The MIME type.
   *
   * @return string
   *   The file extension.
   */
  protected function getExtensionFromMime(string $mime_type): string {
    $map = [
      'audio/webm' => 'webm',
      'video/webm' => 'webm',
      'audio/ogg' => 'ogg',
      'audio/wav' => 'wav',
      'audio/mp4' => 'm4a',
      'audio/mpeg' => 'mp3',
    ];

    return $map[$mime_type] ?? 'webm';
  }

}
