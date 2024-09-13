(($, Drupal) => {
  Drupal.AjaxCommands.prototype.openInSidebar = (
    ajax,
    response,
    status,
  ) => {
    if (!response.selector) {
      return false;
    }

    // An element matching the selector will be added to the page if it does not exist yet.
    const $dialog = $(response.selector);

    if (!ajax.wrapper) {
      ajax.wrapper = $dialog.attr('id');
    }

    // Get the markup inserted into the page.
    response.command = 'insert';
    response.method = 'html';
    ajax.commands.insert(ajax, response, status);
  };
})(jQuery, Drupal);
