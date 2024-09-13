/**
 * @file
 * Gutenberg implementation of {@link Drupal.editors} API.
 */

/* eslint func-names: ["error", "never"] */
(function(Drupal, DrupalGutenberg, drupalSettings, wp, $) {
  /**
   * Check if Drupal's media module is enabled.
   *
   * @return {boolean} The media module is enabled.
   */
  Drupal.isMediaEnabled = () =>
    (drupalSettings.gutenberg || false) &&
    drupalSettings.gutenberg['media-enabled'];

  /**
   * Lazily query a jQuery element that might or might not yet exist.
   */
  function lazyjQuery(selector, context = null, autoDisconnect = true) {
    return new Promise(resolve => {
      const $elements = $(selector, context);
      if ($elements.length) {
        return resolve($elements);
      }

      const observer = new MutationObserver(mutations => {
        const $found = $(selector, context);
        if ($found.length) {
          if (autoDisconnect) {
            observer.disconnect();
          }
          resolve($found);
        }
      });

      observer.observe(context || document.body, {
        childList: true,
        subtree: true
      });
    });
  }

  /**
   * Check if Drupal's media_library module is enabled.
   *
   * @return {boolean} The media library module is enabled.
   */
  Drupal.isMediaLibraryEnabled = () =>
    (drupalSettings.gutenberg || false) &&
    drupalSettings.gutenberg['media-library-enabled'];

  /**
   * Toggles Gutenberg loader.
   *
   * @param {string} state The loader state.
   */
  Drupal.toggleGutenbergLoader = state => {
    const $gutenbergLoader = $('#gutenberg-loading');
    if (state === 'show') {
      $gutenbergLoader.removeClass('hide');
    } else if (state === 'hide') {
      $gutenbergLoader.addClass('hide');
    }
  };

  /**
   * Display error message.
   *
   * @param {string} message   Notice message.
   * @param {boolean} rawHTML Render as HTML.
   *
   * @return {Object} Action object.
   */
  Drupal.notifyError = (message, rawHTML = false) =>
    wp.data.dispatch('core/notices').createErrorNotice(message, {
      isDismissible: true,
      __unstableHTML: rawHTML,
    });

  /**
   * Display success message.
   *
   * @param {string} message   Notice message.
   * @param {boolean} rawHTML Render as HTML.
   *
   * @return {Object} Action object.
   */
  Drupal.notifySuccess = (message, rawHTML = false) =>
    wp.data.dispatch('core/notices').createSuccessNotice(message, {
      isDismissible: true,
      __unstableHTML: rawHTML,
    });

  /**
   * Add new command for reloading a block.
   */
  Drupal.AjaxCommands.prototype.reloadBlock = function() {
    const { select, dispatch } = wp.data;
    const selectedBlock = select('core/block-editor').getSelectedBlock();
    const { clientId } = selectedBlock;
    const { mediaEntityIds } = selectedBlock.attributes;

    (async () => {
      await dispatch('core/block-editor').updateBlock(clientId, {
        attributes: { mediaEntityIds: [] },
      });

      setTimeout(() => {
        dispatch('core/block-editor').updateBlock(clientId, {
          attributes: { mediaEntityIds },
        });
      }, 100);
    })();
  };

  wp.galleryBlockV2Enabled = false;

  /**
   * @namespace
   */
  Drupal.editors.gutenberg = {
    /**
     * Editor attach callback.
     *
     * @param {HTMLElement} element
     *   The element to attach the editor to.
     * @param {string} format
     *   The text format for the editor.
     *
     * @return {bool}
     *   Whether the call to `CKEDITOR.replace()` created an editor or not.
     */
    async attach(element, format) {
      const $gutenbergLoader = $('#gutenberg-loading');
      $gutenbergLoader.html(
        Drupal.theme.ajaxProgressThrobber(Drupal.t('Loading')),
      );

      // A bit of a hack. This avoids Gutenberg to be reinit'd on AJAX calls.
      // TODO: could be done in another way?
      if (drupalSettings.gutenbergLoaded) {
        return false;
      }
      drupalSettings.gutenbergLoaded = true;

      const { contentType, blackList } = format.editorSettings;
      const { data, blocks } = wp;
      const { dispatch } = data;
      // const { addFilter } = hooks;
      const { unregisterBlockType } = blocks;
      const {
        registerDrupalStore,
        registerDrupalBlocks,
        registerDrupalMedia,
        registerContentBlocks,
      } = DrupalGutenberg;

      // Register plugins.
      // Not needed now. Leaving it here for reference.
      // const { AdditionalFieldsPluginSidebar } = DrupalGutenberg.Plugins;
      // plugins.registerPlugin('drupal', {
      //   icon: 'forms',
      //   render: AdditionalFieldsPluginSidebar,
      // });

      await registerDrupalStore(data);

      await registerDrupalBlocks(contentType);
      await registerDrupalMedia();
      await registerContentBlocks(contentType);

      await this._initGutenberg(element);

      /*
       * This is a hack to deal with an image editing crop issue.
       *
       * @todo Figure out why react-easy-crop is getting container's
       * width and height as 0.
       */
      setTimeout(() => {
        window.dispatchEvent(new Event('resize'));
      }, 200);

      if (drupalSettings.gutenberg._listeners.init) {
        drupalSettings.gutenberg._listeners.init.forEach(callback => {
          callback();
        });
      }

      if (drupalSettings.gutenberg.messages) {
        Object.keys(drupalSettings.gutenberg.messages).forEach(key => {
          drupalSettings.gutenberg.messages[key].forEach(message => {
            switch (key) {
              case 'error':
                dispatch('core/notices').createErrorNotice(message);
                break;
              case 'warning':
                dispatch('core/notices').createWarningNotice(message);
                break;
              case 'success':
                dispatch('core/notices').createSuccessNotice(message);
                break;
              default:
                dispatch('core/notices').createWarningNotice(message);
                break;
            }
          });
        });
      }

      // Handle late messages, i.e. processed after node edit form hook.
      // Example: System update messages are coming after node edit form.
      // TODO: There must be a better way to do this on server side.
      $('div.messages--error').each((index, el) => {
        dispatch('core/notices').createErrorNotice($(el).html(), {
          __unstableHTML: $(el).html(),
        });
        $(el).remove();
      });

      $('div.messages--warning').each((index, el) => {
        dispatch('core/notices').createWarningNotice($(el).html(), {
          __unstableHTML: $(el).html(),
        });
        $(el).remove();
      });

      $('div.messages--success').each((index, el) => {
        dispatch('core/notices').createSuccessNotice($(el).html(), {
          __unstableHTML: $(el).html(),
        });
        $(el).remove();
      });

      // Process blacklist.
      blackList
        .filter(value => !value.includes('drupalblock/'))
        .forEach(value => {
          unregisterBlockType(value);
        });

      // On page load always select sidebar's document tab.
      data.dispatch('core/edit-post').openGeneralSidebar('edit-post/document');

      data.dispatch('core/edit-post').setAvailableMetaBoxesPerLocation({
        advanced: ['drupalSettings'],
      });

      // Disable status panel from sidebar
      data.dispatch('core/edit-post').removeEditorPanel('post-status');

      // Disable Welcome Guide
      const isWelcomeGuide = data
        .select('core/edit-post')
        .isFeatureActive('welcomeGuide');

      if (isWelcomeGuide) {
        data.dispatch('core/edit-post').toggleFeature('welcomeGuide');
      }

      const metaBoxes = [];
      drupalSettings.gutenberg.metaboxes.forEach(id => {
        const $metabox = $(`#${id}`);
        const metabox = $metabox.get(0);

        // Detach the original editors used within the metabox elements
        // which can break after they've been moved.
        // Then queue them up for reattachment once the metabox container is available.
        Drupal.behaviors.editor.detach(metabox, drupalSettings);
        metaBoxes.push(metabox);
      });
      lazyjQuery('.edit-post-meta-boxes-area.is-advanced .edit-post-meta-boxes-area__container')
      // lazyjQuery('.gutenberg-header-settings #edit-actions')
        .then(($metaBoxContainer) => {
          metaBoxes.forEach(metabox => {
            $(metabox).appendTo($metaBoxContainer);
            Drupal.behaviors.editor.attach(metabox, drupalSettings);
          });
        });

      // Create fake form for metabox.
      // On post save, REQUEST_META_BOX_UPDATES action is called and
      // it relies on metaboxes forms.
      // The only way to bypass an exception is to create the "advanced" metabox form.
      // It has no other practical use.
      const metaboxesContainer = $(document.createElement('div'));
      metaboxesContainer.attr('id', 'metaboxes');
      $('body').append(metaboxesContainer);
      const metaboxForm = $(document.createElement('form'));
      metaboxForm.addClass('metabox-location-advanced');
      metaboxesContainer.append(metaboxForm);

      // Disable form validation
      // We need some ninja hacks because every button in Gutenberg will
      // cause the form to submit.
      $(document.forms[0]).attr('novalidate', true);

      let isFormValid = false;

      $('.gutenberg-header-settings .form-submit').on('mousedown', e => {
        const { openGeneralSidebar } = data.dispatch('core/edit-post');

        // is checkValidity supported? If not, validate the form.
        if (typeof element.form.checkValidity === 'function') {
          isFormValid = element.form.checkValidity();
        } else {
          isFormValid = true;
        }

        if (!isFormValid) {
          let isMetaboxValid = true;

          // Expand "More Settings" set on error.
          $('#edit-metabox-fields :input').each((index, el) => {
            if (!el.checkValidity()) {
              $('#edit-metabox-fields').attr('open', '');
              isMetaboxValid = false;
              return false;
            }
          });

          if (isMetaboxValid) {
            // Calling openGeneralSidebar() is not working properly when sidebar
            // is hidden. To overcome this we need to call it twice.
            openGeneralSidebar('edit-post/document');
            openGeneralSidebar('edit-post/document');
          }

          e.preventDefault();
          e.stopPropagation();
          return false;
        }
      });

      $('.gutenberg-header-settings .form-submit').on('click', e => {
        $(e.currentTarget).attr('active', true);

        // Expand "More Settings" set.
        $('#edit-additional-fields').attr('open', '');

        // For these buttons enable form validation.
        $(element.form).removeAttr('novalidate');

        // This will not work on IE (<10?). But it's ok because
        // we have the server side validation fallback.
        isFormValid = element.form.reportValidity();

        if (!isFormValid) {
          $(e.currentTarget).removeAttr('active');
        } else {
          element.form.requestSubmit(e.currentTarget);
        }

        // Then disable form validation again :(
        $(element.form).attr('novalidate', true);

        // No need to proceed to form validation,
        // it'll just throw a "not focusable" console
        // error.
        if (!isFormValid) {
          e.preventDefault();
          e.stopPropagation();
          return false;
        }
      });

      let formSubmitted = false;
      // Gutenberg is full of buttons which cause the form
      // to submit (no default prevent).
      $(element.form).on('submit', e => {
        // Get the original button clicked...
        const $source = $('input[active="true"]');
        // ...and reset its active state.
        $source.removeAttr('active');

        // If none of those buttons were clicked...
        if (
          !$source.hasClass('form-submit') &&
          $source.attr('id') !== 'edit-delete'
        ) {
          // Just stop everything.
          e.preventDefault();
          e.stopPropagation();
          return false;
        }

        // Update editor textarea with gutenberg content.
        $(element).val(data.select('core/editor').getEditedPostContent());

        // We need to update the 'editor-value-is-changed' flag
        // otherwise the content won't be updated.
        $(element).data({ 'editor-value-is-changed': true });
        $(element).attr('data-editor-value-is-changed', true);

        // Clear content "dirty" state.
        if (!formSubmitted) {
          // savePost() is async so we must cancel form submission
          // to avoid to "changes not saved" alert.
          e.preventDefault();
          e.stopPropagation();

          (async () => {
            // Save selected reusable blocks.
            const entitiesToSave = await data
              .select('drupal')
              .getEntitiesToSave();
            // eslint-disable-next-line no-restricted-syntax
            for await (const [
              // eslint-disable-next-line no-unused-vars
              index,
              { kind, name, key, property },
            ] of Object.entries(entitiesToSave)) {
              await data
                .dispatch('core')
                .saveEditedEntityRecord(kind, name, key, property);
            }

            await data.dispatch('core/editor').savePost({ isAutosave: false });

            formSubmitted = true;

            // Submit again to save content on Drupal.
            // We need to submit the form via button click.
            // Drupal's form submit handler needs it.
            // TODO: Could we submit and passing the button reference to formState?
            $source.click();
          })();
        }
      });

      return true;
    },

    /**
     * Attaches an inline editor to a DOM element.
     *
     * @param {HTMLElement} element
     *   The element to attach the editor to.
     * @param {object} format
     *   The text format used in the editor.
     * @param {string} [mainToolbarId]
     *   The id attribute for the main editor toolbar, if any.
     * @param {string} [floatedToolbarId]
     *   The id attribute for the floated editor toolbar, if any.
     *
     * @return {bool}
     *   Whether the call to `CKEDITOR.replace()` created an editor or not.
     */
    attachInlineEditor() {
      // We define this function so that quickedit doesn't throw an error.
      return false;
    },

    /**
     * Editor detach callback.
     *
     * @param {HTMLElement} element
     *   The element to detach the editor from.
     * @param {string} format
     *   The text format used for the editor.
     * @param {string} trigger
     *   The event trigger for the detach.
     *
     * @return {bool}
     *   Whether the call to `CKEDITOR.dom.element.get(element).getEditor()`
     *   found an editor or not.
     */
    detach() {
      return true;
    },

    /**
     * Reacts on a change in the editor element.
     *
     * @param {HTMLElement} element
     *   The element where the change occured.
     * @param {function} callback
     *   Callback called with the value of the editor.
     *
     * @return {bool}
     *   Whether the call to `CKEDITOR.dom.element.get(element).getEditor()`
     *   found an editor or not.
     */
    onChange() {
      return true;
    },

    /**
     * Initializes the editor on a given element.
     *
     * @param {HTMLElement} element
     *   The element where the editor will be initialized.
     */
    async _initGutenberg(element) {
      const { editPost, data } = wp;
      const $textArea = $(element);
      const target = `editor-${$textArea.data('drupal-selector')}`; // 'editor-' + $textArea.data('drupal-selector');
      const $editor = $(`<div id="${target}" class="gutenberg__editor"></div>`); // $('<div id="' + target + '" class="gutenberg__editor"></div>');
      $editor.insertAfter($textArea);
      $textArea.hide();

      wp.node = {
        categories: [],
        content: {
          block_version: 0,
          protected: false,
          raw: $(element).val(),
          rendered: '',
        },
        featured_media: 0,
        // Gutenberg expects an id != 0 and != null. A negative value for new nodes will work.
        id: drupalSettings.gutenberg.entityId || -1,
        parent: 0,
        permalink_template: '',
        revisions: { count: 0, last_id: 1 },
        status: drupalSettings.gutenberg.entityId ? 'draft' : 'auto-draft',
        theme_style: true,
        type: 'page',
        slug: '',
      };

      const editorSettings = {
        ...(DrupalGutenberg.defaultSettings
          ? DrupalGutenberg.defaultSettings
          : {}),
        ...drupalSettings.gutenberg['theme-support'],
        supportsTemplateMode: false,
        availableTemplates: [],
        allowedBlockTypes: true,
        disablePostFormats: false,
        mediaLibrary: true,
        // See issue: https://www.drupal.org/project/gutenberg/issues/3035313
        imageSizes: drupalSettings.gutenberg['image-sizes'],
        titlePlaceholder: Drupal.t('Add title'),
        bodyPlaceholder: Drupal.t('Add text or type / to add content'),
        isRTL: drupalSettings.gutenberg['is-rtl'],
        localAutosaveInterval: 0,
        autosaveInterval: 0, // Must set > 0 for undo and redo to work.
        template: drupalSettings.gutenberg.template || [],
        templateLock:
          drupalSettings.gutenberg['template-lock'] === 'none'
            ? false
            : drupalSettings.gutenberg['template-lock'] || false,
      };

      // Override block lock permission when using a locked template.
      if (editorSettings.template && editorSettings.templateLock === 'all') {
        editorSettings.canLockBlocks = false;
      }

      const onEditorReady = () => {
        // Flag the editor as ready.
        // Moving the action buttons to the header should be the last
        // UI change/shift.
        lazyjQuery('.edit-post-header__settings .gutenberg-header-settings #edit-submit')
          .then(() => {
            $('.gutenberg-full-editor').addClass('ready');
            Drupal.toggleGutenbergLoader('hide');
          });
      };

      let editorReady = false;
      data.subscribe(() => {
        if (!editorReady) {
          const currentPostType = data.select('core/editor').getCurrentPostType();
          const postType = data.select('core').getPostType(currentPostType);
          // The editor is only fully ready once we can successfully resolve the
          // post type.
          if (postType) {
            editorReady = true;
            onEditorReady();
          }
        }

        // Handle action buttons.
        const $postHeaderSettings = $('.edit-post-header__settings');
        const $headerSettings = $postHeaderSettings.find(
          '.gutenberg-header-settings',
        );

        if ($headerSettings.length === 0) {
          $postHeaderSettings.append(
            $('.gutenberg-header-settings'),
          );
        }

        // Handle extra root container classes.
        if (drupalSettings.gutenberg['theme-support'].extraRootContainerClassNames) {
          const $isRootContainer = $('.is-root-container');
          if (!$isRootContainer.hasClass(drupalSettings.gutenberg['theme-support'].extraRootContainerClassNames)) {
            $isRootContainer.addClass(
              drupalSettings.gutenberg['theme-support']
                .extraRootContainerClassNames,
            );
          }
        }

        // We need to deal with the top left logo when in fullscreen mode.
        const isFullscreenMode = data
          .select('core/edit-post')
          .isFeatureActive('fullscreenMode');

        if (isFullscreenMode) {
          const $postHeader = $('.edit-post-header');
          const $fullscreenLink = $postHeader.find('a.edit-post-fullscreen-mode-close:not(.drupal)');
          const $backButtonContainer = $postHeader.find('.drupal-fullscreen-back-button');

          if ($fullscreenLink.length > 0 && $backButtonContainer.length === 0) {
            // Add container for the new button if it doesn't already exist.
            const $domContainer = $('<div class="drupal-fullscreen-back-button" style="display: contents"></div>');
            $fullscreenLink.after($domContainer);

            const { render } = wp.element;
            const { BackButton } = window.DrupalGutenberg.Components;

            render(BackButton, $domContainer[0]);
          }
        }

        // Clear template validation.
        // Force template validity to true.
        if (!data.select('core/block-editor').isValidTemplate()) {
          // see https://github.com/WordPress/gutenberg/issues/11681
          data.dispatch('core/block-editor').setTemplateValidity(true);
        }
      });

      // To avoid restore backup notices from local autosave.
      sessionStorage.removeItem('wp-autosave-block-editor-post-1');
      localStorage.removeItem('wp-autosave-block-editor-post-1');

      await editPost.initializeEditor(
        target,
        wp.node.type,
        wp.node.id,
        editorSettings,
        [],
      );
    },
  };

  /**
   * Gutenberg media library behavior.
   *
   * @type {{attach(): (undefined)}}
   */
  Drupal.behaviors.gutenbergMediaLibrary = {
    attach(context) {
      const $form = $('#media-entity-browser-modal .media-library-add-form');
      const $context = $(context);
      const $dialog = $context.closest('.ui-dialog-content');

      if (!$form.length) {
        return;
      }

      // Altering new media entity form buttons.
      $form
        .find('[data-drupal-selector="edit-save-insert"]')
        .css('display', 'none');

      // Applied only to the add media form modal context.
      if (context && context.id === 'media-library-add-form-wrapper') {
        const saveAndSelectButton = $form.find(
          '[data-drupal-selector="edit-save-select"]',
        );
        if (saveAndSelectButton.length) {
          // Hide button.
          saveAndSelectButton.css({
            display: 'none',
          });

          // Add button to buttonpane.
          const originalButtons = $dialog.dialog('option', 'buttons');
          const buttons = [];
          buttons.push({
            text:
              saveAndSelectButton.html() || saveAndSelectButton.attr('value'),
            class: saveAndSelectButton.attr('class'),
            click(e) {
              saveAndSelectButton
                .trigger('mousedown')
                .trigger('mouseup')
                .trigger('click');
              // Restore buttons
              $dialog.dialog('option', 'buttons', originalButtons);
              e.preventDefault();
            },
          });
          $dialog.dialog('option', 'buttons', buttons);
        }
      }
    },
  };

  /**
   * Update drupal block.
   *
   * @param {integer|string} id
   *   Id.
   */
  async function updateDrupalBlockBasedOnMediaEntity(id) {
    const { dispatch } = wp.data;
    const response = await fetch(Drupal.url(`editor/media/render/${id}`));
    if (response.ok) {
      const mediaEntity = await response.json();

      if (mediaEntity && mediaEntity.view_modes) {
        dispatch('drupal').setMediaEntity(id, mediaEntity);
      }
    }
  }

  /**
   * Add new command for reloading the media block after editing..
   */
  Drupal.AjaxCommands.prototype.gutenbergUpdateMediaEntities = function() {
    const { select } = wp.data;
    const selectedBlock = select('core/block-editor').getSelectedBlock();
    const { attributes } = selectedBlock;
    const { mediaEntityIds } = attributes;
    updateDrupalBlockBasedOnMediaEntity(mediaEntityIds[0]);
  };
})(Drupal, DrupalGutenberg, drupalSettings, window.wp, jQuery);
