<?php

namespace Drupal\book_library\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * One uploaded EPUB in the private book library.
 *
 * The book's searchable text isn't stored on the entity itself -- it's
 * extracted into {book_library_passage} by \Drupal\book_library\BookIndexer
 * whenever the book is saved through its form (or by
 * `drush book-library:reindex`).
 *
 * @ContentEntityType(
 *   id = "library_book",
 *   label = @Translation("Book"),
 *   label_collection = @Translation("Books"),
 *   label_singular = @Translation("book"),
 *   label_plural = @Translation("books"),
 *   handlers = {
 *     "list_builder" = "Drupal\book_library\LibraryBookListBuilder",
 *     "access" = "Drupal\book_library\LibraryBookAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\book_library\Form\LibraryBookForm",
 *       "edit" = "Drupal\book_library\Form\LibraryBookForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     }
 *   },
 *   base_table = "library_book",
 *   admin_permission = "administer book library",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "title",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/books/{library_book}",
 *     "add-form" = "/admin/content/books/add",
 *     "edit-form" = "/admin/content/books/{library_book}/edit",
 *     "delete-form" = "/admin/content/books/{library_book}/delete",
 *     "collection" = "/admin/content/books"
 *   }
 * )
 */
class LibraryBook extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t("Leave blank to use the title from the EPUB's own metadata."))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ]);

    $fields['author'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Author'))
      ->setDescription(t("Leave blank to use the author from the EPUB's own metadata."))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 1,
      ]);

    $fields['epub'] = BaseFieldDefinition::create('file')
      ->setLabel(t('EPUB file'))
      ->setRequired(TRUE)
      ->setSettings([
        'file_extensions' => 'epub',
        // Never public:// -- see book_library_requirements().
        'uri_scheme' => 'private',
        'file_directory' => 'books',
        'description_field' => FALSE,
      ])
      ->setDisplayOptions('form', [
        'type' => 'file_generic',
        'weight' => -5,
      ]);

    $fields['passage_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Passages'))
      ->setDescription(t('Number of searchable passages extracted from the EPUB.'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['indexed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last indexed'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Added'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);
    \Drupal::service('book_library.passage_store')->deleteBooks(array_keys($entities));
  }

}
