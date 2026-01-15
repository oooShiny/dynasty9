<?php

namespace Drupal\dynasty_newsletter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to manually generate a newsletter.
 */
class NewsletterGenerateForm extends FormBase {

  /**
   * The newsletter content builder service.
   *
   * @var \Drupal\dynasty_newsletter\Service\NewsletterContentBuilder
   */
  protected $contentBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->contentBuilder = $container->get('dynasty_newsletter.content_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynasty_newsletter_generate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Manually generate a newsletter draft. This will create a new unpublished Simplenews issue with pre-populated content from recent news, games, podcasts, and historical data.') . '</p>',
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Newsletter Title'),
      '#default_value' => 'Patriots Dynasty Weekly - ' . date('F j, Y'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Newsletter'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      // Build newsletter content
      $html = $this->contentBuilder->buildNewsletterContent();

      // Create Simplenews issue node
      $newsletter = Node::create([
        'type' => 'simplenews_issue',
        'title' => $form_state->getValue('title'),
        'body' => [
          'value' => $html,
          'format' => 'full_html',
        ],
        'simplenews_issue' => [
          'target_id' => 'patriots_dynasty_weekly',
        ],
        'status' => 0, // Unpublished draft
      ]);
      $newsletter->save();

      $this->messenger()->addStatus($this->t('Newsletter draft created: <a href=":url">@title</a>', [
        ':url' => $newsletter->toUrl('edit-form')->toString(),
        '@title' => $newsletter->getTitle(),
      ]));

      // Redirect to the newsletter edit form
      $form_state->setRedirect('entity.node.edit_form', ['node' => $newsletter->id()]);

    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to generate newsletter: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->getLogger('dynasty_newsletter')->error('Newsletter generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
