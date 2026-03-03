<?php

namespace Drupal\dynasty_voicemail\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\dynasty_voicemail\Service\VoicemailStorageService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling voicemail uploads.
 */
class VoicemailUploadController extends ControllerBase {

  /**
   * The voicemail storage service.
   *
   * @var \Drupal\dynasty_voicemail\Service\VoicemailStorageService
   */
  protected $storageService;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

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
   * Maximum file size in bytes (10MB).
   */
  const MAX_FILE_SIZE = 10485760;

  /**
   * Allowed MIME types.
   */
  const ALLOWED_MIME_TYPES = [
    'audio/webm',
    'audio/ogg',
    'audio/wav',
    'audio/mp4',
    'audio/mpeg',
    'video/webm',
  ];

  /**
   * Constructs a VoicemailUploadController object.
   */
  public function __construct(
    VoicemailStorageService $storage_service,
    FloodInterface $flood,
    MailManagerInterface $mail_manager,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
  ) {
    $this->storageService = $storage_service;
    $this->flood = $flood;
    $this->mailManager = $mail_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dynasty_voicemail.storage'),
      $container->get('flood'),
      $container->get('plugin.manager.mail'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('dynasty_voicemail')
    );
  }

  /**
   * Handles the voicemail upload.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success or failure.
   */
  public function upload(Request $request) {
    $config = $this->configFactory->get('dynasty_voicemail.settings');

    // Validate CSRF token.
    $token = $request->request->get('csrf_token');
    if (!\Drupal::csrfToken()->validate($token, 'voicemail_upload')) {
      return new JsonResponse(['error' => 'Invalid security token. Please refresh the page and try again.'], 403);
    }

    // Check rate limiting.
    $rate_limit = $config->get('rate_limit_per_hour') ?? 5;
    $client_ip = $request->getClientIp();
    if (!$this->flood->isAllowed('dynasty_voicemail.upload', $rate_limit, 3600, $client_ip)) {
      $this->logger->warning('Rate limit exceeded for IP: @ip', ['@ip' => $client_ip]);
      return new JsonResponse(['error' => 'Too many submissions. Please try again later.'], 429);
    }

    // Check honeypot if enabled.
    if ($config->get('honeypot_enabled')) {
      $honeypot = $request->request->get('website');
      if (!empty($honeypot)) {
        $this->logger->warning('Honeypot triggered from IP: @ip', ['@ip' => $client_ip]);
        // Return success to not reveal the honeypot to bots.
        return new JsonResponse(['success' => TRUE, 'message' => 'Thank you for your message!']);
      }
    }

    // Validate required fields.
    $name = trim($request->request->get('name', ''));
    $email = trim($request->request->get('email', ''));
    $duration = (int) $request->request->get('duration', 0);

    if (empty($name)) {
      return new JsonResponse(['error' => 'Name is required.'], 400);
    }

    if (empty($email) || !\Drupal::service('email.validator')->isValid($email)) {
      return new JsonResponse(['error' => 'A valid email address is required.'], 400);
    }

    // Validate uploaded file.
    $file = $request->files->get('audio');
    if (!$file) {
      return new JsonResponse(['error' => 'No audio file uploaded.'], 400);
    }

    // Check file size.
    if ($file->getSize() > self::MAX_FILE_SIZE) {
      return new JsonResponse(['error' => 'File too large. Maximum size is 10MB.'], 400);
    }

    // Check MIME type.
    $mime_type = $file->getMimeType();
    if (!in_array($mime_type, self::ALLOWED_MIME_TYPES)) {
      $this->logger->warning('Invalid MIME type uploaded: @mime from IP: @ip', [
        '@mime' => $mime_type,
        '@ip' => $client_ip,
      ]);
      return new JsonResponse(['error' => 'Invalid audio format.'], 400);
    }

    // Save the file.
    try {
      $result = $this->storageService->saveVoicemail($file, $name, $email, $duration);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save voicemail: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Failed to save voicemail. Please try again.'], 500);
    }

    // Register the flood event.
    $this->flood->register('dynasty_voicemail.upload', 3600, $client_ip);

    // Send notification email.
    $this->sendNotification($name, $email, $duration, $result['file_url']);

    $this->logger->info('Voicemail received from @name (@email)', [
      '@name' => $name,
      '@email' => $email,
    ]);

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Thank you! Your voicemail has been sent.',
    ]);
  }

  /**
   * Sends notification emails about the new voicemail.
   *
   * @param string $name
   *   Sender name.
   * @param string $email
   *   Sender email.
   * @param int $duration
   *   Recording duration in seconds.
   * @param string $file_url
   *   URL to the audio file.
   */
  protected function sendNotification($name, $email, $duration, $file_url) {
    $config = $this->configFactory->get('dynasty_voicemail.settings');
    $emails_raw = $config->get('notification_emails');

    if (empty($emails_raw)) {
      $this->logger->warning('No notification emails configured.');
      return;
    }

    $emails = preg_split('/[\s,]+/', $emails_raw, -1, PREG_SPLIT_NO_EMPTY);

    $params = [
      'name' => $name,
      'email' => $email,
      'duration' => $duration,
      'date' => date('Y-m-d H:i:s'),
      'file_url' => $file_url,
    ];

    foreach ($emails as $recipient) {
      $recipient = trim($recipient);
      if (\Drupal::service('email.validator')->isValid($recipient)) {
        $result = $this->mailManager->mail(
          'dynasty_voicemail',
          'voicemail_notification',
          $recipient,
          'en',
          $params,
          NULL,
          TRUE
        );

        if (!$result['result']) {
          $this->logger->error('Failed to send notification to @email', ['@email' => $recipient]);
        }
      }
    }
  }

}
