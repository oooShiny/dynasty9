/**
 * @file
 * Overrides registerCoreBlocks.
 */

/* eslint func-names: ["error", "never"] */
(function(Drupal, wp, drupalSettings) {
  function registerCoreBlocks() {
    const { blocks, blockLibrary } = wp;
    const {
      setDefaultBlockName,
      setGroupingBlockName,
      setUnregisteredTypeHandlerName,
      unregisterBlockVariation,
    } = blocks;
    const { __experimentalGetCoreBlocks } = blockLibrary;
    const {
      allowedBlocks,
    } = drupalSettings.editor.formats.gutenberg.editorSettings;

    const defaultBlocks = [
      'core/block',
      'core/heading',
      'core/list',
      'core/list-item',
      'core/paragraph',
      'core/pattern',
      'core/missing',
    ];

    const coreBlocks = __experimentalGetCoreBlocks();
    const allowedCoreBlocks = coreBlocks.filter(
      block =>
        (allowedBlocks && allowedBlocks.includes(block.name)) ||
        defaultBlocks.includes(block.name),
    );

    allowedCoreBlocks.forEach(block => {
      block.init();
    });

    setUnregisteredTypeHandlerName('core/missing');
    setDefaultBlockName('core/paragraph');
    setGroupingBlockName('core/group');

    // Handle core embed variations.
    const embedBlockType = blocks.getBlockType('core/embed');
    if (embedBlockType) {
      embedBlockType.variations.forEach(variation => {
        if (!allowedBlocks.includes(`core-embed/${variation.name}`)) {
          unregisterBlockVariation('core/embed', variation.name);
        }
      });
    }
  }

  function __experimentalRegisterExperimentalCoreBlocks() {
    return null;
  }

  wp.blockLibrary = {
    ...wp.blockLibrary,
    registerCoreBlocks,
    __experimentalRegisterExperimentalCoreBlocks,
  };
})(Drupal, wp, drupalSettings);
