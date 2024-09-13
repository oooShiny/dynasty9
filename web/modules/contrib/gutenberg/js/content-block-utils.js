/**
 * @file
 * Gutenberg implementation of {@link Drupal.editors} API.
 */

/* eslint func-names: ["error", "never"] */
(function(Drupal, wp, once, $, globalDrupalSettings) {
  const { data } = wp;
  const { dispatch, select } = data;

  Drupal.behaviors.gutenbergContentBlocks = {
    attach(context, drupalSettings) {
      $(document).ajaxComplete((event, xhr, settings) => {
        if (!drupalSettings.contentBlockId) {
          return;
        }

        if (settings.url && settings.url.includes('/editor/content_block_type/settings/')) {
          const elements = once('gutenberg-content-blocks', '#gutenberg-content-block-type-settings', context);
          elements.forEach(async () => {
            const block = await select('core/block-editor').getSelectedBlock();
            const clientId = await select(
              'core/block-editor',
            ).getSelectedBlockClientId();
            const attrs = {
              ...block.attributes,
              contentBlockId: drupalSettings.contentBlockId,
            };
            // Force a refresh.
            await dispatch('core/block-editor').updateBlockAttributes(clientId, {contentBlockId: null, viewMode: 'empty'});
            await dispatch('core/block-editor').updateBlockAttributes(clientId, attrs);
            dispatch('core/block-editor').flashBlock(clientId);
            delete globalDrupalSettings.contentBlockId;
          });
        }
      });
    },
  };
})(Drupal, wp, once, jQuery, drupalSettings);
