((wp, Drupal, DrupalGutenberg) => {
  const {
    MediaLibrary,
    withNativeDialog,
    withGutenbergDialog,
  } = DrupalGutenberg.Components;

  wp.hooks.addFilter(
    'editor.MediaUpload',
    'core/edit-post/components/media-upload/replace-media-upload',
    () =>
      Drupal.isMediaLibraryEnabled()
        ? withNativeDialog(MediaLibrary)
        : withGutenbergDialog(MediaLibrary),
  );

  /**
   * Remove the "Use featured image" option from the media replace flow.
   */
  wp.hooks.addFilter(
    'editor.MediaReplaceFlow',
    'core/edit-post/components/media-replace-flow/replace-media-replace-flow',
    (Component) => {
      return props => (
        <Component
          {...props}
          onToggleFeaturedImage={ false }
        />
      );  
    },
  );

  /**
   * Remove the "Use featured image" option from the media placeholder.
   */
  wp.hooks.addFilter(
    'editor.MediaPlaceholder',
    'core/edit-post/components/media-placeholder/replace-media-placeholder',
    (Component) => {
      return props => (
        <Component
          {...props}
          onToggleFeaturedImage={ false }
        />
      );  
    },
  );
})(wp, Drupal, DrupalGutenberg, drupalSettings);
