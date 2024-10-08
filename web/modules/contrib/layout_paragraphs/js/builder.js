/**
* DO NOT EDIT THIS FILE.
* See the following change record for more information,
* https://www.drupal.org/node/2815083
* @preserve
**/
"use strict";

(function ($, Drupal, debounce, dragula, once) {
  var idAttr = 'data-lpb-id';
  function attachUiElements($container, settings) {
    var id = $container.attr('data-lpb-ui-id');
    var lpbBuilderSettings = settings.lpBuilder || {};
    var uiElements = lpbBuilderSettings.uiElements || {};
    var containerUiElements = uiElements[id] || [];
    Object.values(containerUiElements).forEach(function (uiElement) {
      var element = uiElement.element,
        method = uiElement.method;
      $container[method]($(element).addClass('js-lpb-ui'));
    });
  }
  function repositionDialog(intervalId) {
    var $dialogs = $('.lpb-dialog');
    if ($dialogs.length === 0) {
      clearInterval(intervalId);
      return;
    }
    $dialogs.each(function (i, dialog) {
      var bounding = dialog.getBoundingClientRect();
      var viewPortHeight = window.innerHeight || document.documentElement.clientHeight;
      if (bounding.bottom > viewPortHeight) {
        var $dialog = $('.ui-dialog-content', dialog);
        var height = viewPortHeight - 200;
        $dialog.dialog('option', 'height', height);
        $dialog.css('overscroll-behavior', 'contain');
      }
    });
  }
  function doReorderComponents($element) {
    var id = $element.attr(idAttr);
    var order = $('.js-lpb-component', $element).get().map(function (item) {
      var $item = $(item);
      return {
        uuid: $item.attr('data-uuid'),
        parentUuid: $item.parents('.js-lpb-component').first().attr('data-uuid') || null,
        region: $item.parents('.js-lpb-region').first().attr('data-region') || null
      };
    });
    Drupal.ajax({
      url: "".concat(drupalSettings.path.baseUrl).concat(drupalSettings.path.pathPrefix, "layout-paragraphs-builder/").concat(id, "/reorder"),
      submit: {
        components: JSON.stringify(order)
      },
      error: function error() {}
    }).execute();
  }
  var reorderComponents = debounce(doReorderComponents);
  function moveErrors(settings, el, target, source, sibling) {
    return Drupal._lpbMoveErrors.map(function (validator) {
      return validator.apply(null, [settings, el, target, source, sibling]);
    }).filter(function (errors) {
      return errors !== false && errors !== undefined;
    });
  }
  function updateMoveButtons($element) {
    var lpbBuilderElements = Array.from($element[0].querySelectorAll('.js-lpb-component-list, .js-lpb-region'));
    var lpbBuilderComponent = lpbBuilderElements.filter(function (el) {
      return el.querySelector('.js-lpb-component');
    });
    $element[0].querySelectorAll('.lpb-up, .lpb-down').forEach(function (el) {
      el.setAttribute('tabindex', '0');
    });
    lpbBuilderComponent.forEach(function (el) {
      var _components$0$querySe, _components$querySele;
      var components = Array.from(el.children).filter(function (n) {
        return n.classList.contains('js-lpb-component');
      });
      (_components$0$querySe = components[0].querySelector('.lpb-up')) === null || _components$0$querySe === void 0 || _components$0$querySe.setAttribute('tabindex', '-1');
      (_components$querySele = components[components.length - 1].querySelector('.lpb-down')) === null || _components$querySele === void 0 || _components$querySele.setAttribute('tabindex', '-1');
    });
  }
  function hideEmptyRegionButtons($element) {
    $element.find('.js-lpb-region').each(function (i, e) {
      var $e = $(e);
      if ($e.find('.js-lpb-component').length === 0) {
        $e.find('.lpb-btn--add.center').css('display', 'block');
      } else {
        $e.find('.lpb-btn--add.center').css('display', 'none');
      }
    });
  }
  function updateUi($element) {
    reorderComponents($element);
    updateMoveButtons($element);
    hideEmptyRegionButtons($element);
  }
  function move($moveItem, direction) {
    var $sibling = direction === 1 ? $moveItem.nextAll('.js-lpb-component').first() : $moveItem.prevAll('.js-lpb-component').first();
    var method = direction === 1 ? 'after' : 'before';
    var _window = window,
      scrollY = _window.scrollY;
    var destScroll = scrollY + $sibling.outerHeight() * direction;
    if ($sibling.length === 0) {
      return false;
    }
    var animateProp = $sibling[0].getBoundingClientRect().top == $moveItem[0].getBoundingClientRect().top ? 'translateX' : 'translateY';
    var dimmensionProp = animateProp === 'translateX' ? 'offsetWidth' : 'offsetHeight';
    var siblingDest = $moveItem[0][dimmensionProp] * direction * -1;
    var itemDest = $sibling[0][dimmensionProp] * direction;
    var distance = Math.abs(Math.max(siblingDest, itemDest));
    var duration = distance * .25;
    var siblingKeyframes = [{
      transform: "".concat(animateProp, "(0)")
    }, {
      transform: "".concat(animateProp, "(").concat(siblingDest, "px)")
    }];
    var itemKeyframes = [{
      transform: "".concat(animateProp, "(0)")
    }, {
      transform: "".concat(animateProp, "(").concat(itemDest, "px)")
    }];
    var timing = {
      duration: duration,
      iterations: 1
    };
    var anim1 = $moveItem[0].animate(itemKeyframes, timing);
    anim1.onfinish = function () {
      $moveItem.css({
        transform: 'none'
      });
      $sibling.css({
        transform: 'none'
      });
      $sibling[method]($moveItem);
      $moveItem.closest("[".concat(idAttr, "]")).trigger('lpb-component:move', [$moveItem.attr('data-uuid')]);
    };
    $sibling[0].animate(siblingKeyframes, timing);
    if (animateProp === 'translateY') {
      window.scrollTo({
        top: destScroll,
        behavior: 'smooth'
      });
    }
  }
  function nav($item, dir, settings) {
    var $element = $item.closest("[".concat(idAttr, "]"));
    $item.addClass('lpb-active-item');
    if (dir === -1) {
      $('.js-lpb-region .lpb-btn--add.center, .lpb-layout:not(.lpb-active-item)', $element).before('<div class="lpb-shim"></div>');
    } else if (dir === 1) {
      $('.js-lpb-region', $element).prepend('<div class="lpb-shim"></div>');
      $('.lpb-layout:not(.lpb-active-item)', $element).after('<div class="lpb-shim"></div>');
    }
    var targets = $('.js-lpb-component, .lpb-shim', $element).toArray().filter(function (i) {
      return !$.contains($item[0], i);
    }).filter(function (i) {
      return i.className.indexOf('lpb-layout') === -1 || i === $item[0];
    });
    var currentElement = $item[0];
    var pos = targets.indexOf(currentElement);
    while (targets[pos + dir] !== undefined && moveErrors(settings, $item[0], targets[pos + dir].parentNode, null, $item.next().length ? $item.next()[0] : null).length > 0) {
      pos += dir;
    }
    if (targets[pos + dir] !== undefined) {
      $(targets[pos + dir])[dir === 1 ? 'after' : 'before']($item);
    }
    $('.lpb-shim', $element).remove();
    $item.removeClass('lpb-active-item').focus();
    $item.closest("[".concat(idAttr, "]")).trigger('lpb-component:move', [$item.attr('data-uuid')]);
  }
  function startNav($item) {
    var $msg = $("<div id=\"lpb-navigating-msg\" class=\"lpb-tooltiptext lpb-tooltiptext--visible js-lpb-tooltiptext\">".concat(Drupal.t('Use arrow keys to move. Press Return or Tab when finished.'), "</div>"));
    $item.closest('.lp-builder').addClass('is-navigating').find('.is-navigating').removeClass('is-navigating');
    $item.attr('aria-describedby', 'lpb-navigating-msg').addClass('is-navigating').prepend($msg);
    $item.before('<div class="lpb-navigating-placeholder"></div>');
  }
  function stopNav($item) {
    $item.removeClass('is-navigating').attr('aria-describedby', '').find('.js-lpb-tooltiptext').remove();
    $item.closest("[".concat(idAttr, "]")).removeClass('is-navigating').find('.lpb-navigating-placeholder').remove();
  }
  function cancelNav($item) {
    var $builder = $item.closest("[".concat(idAttr, "]"));
    $builder.find('.lpb-navigating-placeholder').replaceWith($item);
    updateUi($builder);
    stopNav($item);
  }
  function preventLostChanges($element) {
    var events = ['lpb-component:insert.lpb', 'lpb-component:update.lpb', 'lpb-component:move.lpb', 'lpb-component:drop.lpb'].join(' ');
    $element.on(events, function (e) {
      $(e.currentTarget).addClass('is_changed');
    });
    window.addEventListener('beforeunload', function (e) {
      if ($(".is_changed[".concat(idAttr, "]")).length) {
        e.preventDefault();
        e.returnValue = '';
      }
    });
    $('.form-actions').find('input[type="submit"], a').click(function () {
      $element.removeClass('is_changed');
    });
  }
  function attachEventListeners($element, settings) {
    preventLostChanges($element);
    $element.on('click.lp-builder', '.lpb-up', function (e) {
      move($(e.target).closest('.js-lpb-component'), -1);
      return false;
    });
    $element.on('click.lp-builder', '.lpb-down', function (e) {
      move($(e.target).closest('.js-lpb-component'), 1);
      return false;
    });
    $element.on('click.lp-builder', '.js-lpb-component', function (e) {
      $(e.currentTarget).focus();
    });
    $element.on('click.lp-builder', '.lpb-drag', function (e) {
      var $btn = $(e.currentTarget);
      startNav($btn.closest('.js-lpb-component'));
    });
    $(document).off('keydown');
    $(document).on('keydown', function (e) {
      var $item = $('.js-lpb-component.is-navigating');
      if ($item.length) {
        switch (e.code) {
          case 'ArrowUp':
          case 'ArrowLeft':
            nav($item, -1, settings);
            break;
          case 'ArrowDown':
          case 'ArrowRight':
            nav($item, 1, settings);
            break;
          case 'Enter':
          case 'Tab':
            stopNav($item);
            break;
          case 'Escape':
            cancelNav($item);
            break;
          default:
            break;
        }
      }
    });
  }
  function initDragAndDrop($element, settings) {
    var containers = once('is-dragula-enabled', '.js-lpb-component-list, .js-lpb-region', $element[0]);
    var drake = dragula(containers, {
      accepts: function accepts(el, target, source, sibling) {
        return moveErrors(settings, el, target, source, sibling).length === 0;
      },
      moves: function moves(el, source, handle) {
        var $handle = $(handle);
        if ($handle.closest('.lpb-drag').length) {
          return true;
        }
        if ($handle.closest('.lpb-controls').length) {
          return false;
        }
        return true;
      }
    });
    drake.on('drop', function (el) {
      var $el = $(el);
      if ($el.prev().is('a')) {
        $el.insertBefore($el.prev());
      }
      $element.trigger('lpb-component:drop', [$el.attr('data-uuid')]);
    });
    drake.on('drag', function (el) {
      $element.addClass('is-dragging');
      if (el.className.indexOf('lpb-layout') > -1) {
        $element.addClass('is-dragging-layout');
      } else {
        $element.addClass('is-dragging-item');
      }
      $element.trigger('lpb-component:drag', [$(el).attr('data-uuid')]);
    });
    drake.on('dragend', function () {
      $element.removeClass('is-dragging').removeClass('is-dragging-layout').removeClass('is-dragging-item');
    });
    drake.on('over', function (el, container) {
      $(container).addClass('drag-target');
    });
    drake.on('out', function (el, container) {
      $(container).removeClass('drag-target');
    });
    return drake;
  }
  Drupal._lpbMoveErrors = [];
  Drupal.registerLpbMoveError = function (f) {
    Drupal._lpbMoveErrors.push(f);
  };
  Drupal.registerLpbMoveError(function (settings, el, target) {
    if (el.classList.contains('lpb-layout') && $(target).parents('.lpb-layout').length > settings.nesting_depth) {
      return Drupal.t('Exceeds nesting depth of @depth.', {
        '@depth': settings.nesting_depth
      });
    }
  });
  Drupal.registerLpbMoveError(function (settings, el, target) {
    if (settings.require_layouts) {
      if (el.classList.contains('js-lpb-component') && !el.classList.contains('lpb-layout') && !target.classList.contains('js-lpb-region')) {
        return Drupal.t('Components must be added inside sections.');
      }
    }
  });
  Drupal.AjaxCommands.prototype.LayoutParagraphsEventCommand = function (ajax, response) {
    var layoutId = response.layoutId,
      componentUuid = response.componentUuid,
      eventName = response.eventName;
    var $element = $("[data-lpb-id=\"".concat(layoutId, "\"]"));
    $element.trigger("lpb-".concat(eventName), [componentUuid]);
  };
  function updateDialogButtons(context) {
    var $lpDialog = $(context).closest('.ui-dialog-content');
    if (!$lpDialog) {
      return;
    }
    var buttons = [];
    var $buttons = $lpDialog.find('.layout-paragraphs-component-form > .form-actions input[type=submit], .layout-paragraphs-component-form > .form-actions a.button');
    if ($buttons.length === 0) {
      return;
    }
    $buttons.each(function (_i, el) {
      var $originalButton = $(el).css({
        display: 'none'
      });
      buttons.push({
        text: $originalButton.html() || $originalButton.attr('value'),
        class: $originalButton.attr('class'),
        click: function click(e) {
          if ($originalButton.is('a')) {
            $originalButton[0].click();
          } else {
            $originalButton.trigger('mousedown').trigger('mouseup').trigger('click');
            e.preventDefault();
          }
        }
      });
    });
    $lpDialog.dialog('option', 'buttons', buttons);
  }
  Drupal.behaviors.layoutParagraphsBuilder = {
    attach: function attach(context, settings) {
      var jsUiElements = once('lpb-ui-elements', '[data-has-js-ui-element]');
      jsUiElements.forEach(function (el) {
        attachUiElements($(el), settings);
      });
      once('lpb-events', '[data-lpb-id]').forEach(function (el) {
        $(el).on('lpb-builder:init.lpb lpb-component:insert.lpb lpb-component:update.lpb lpb-component:move.lpb lpb-component:drop.lpb lpb-component:delete.lpb', function (e) {
          var $element = $(e.currentTarget);
          updateUi($element);
        });
      });
      once('lpb-enabled', '[data-lpb-id].has-components').forEach(function (el) {
        var $element = $(el);
        var id = $element.attr(idAttr);
        var lpbSettings = settings.lpBuilder[id];
        $element.data('drake', initDragAndDrop($element, lpbSettings));
        attachEventListeners($element, lpbSettings);
        $element.trigger('lpb-builder:init');
      });
      once('is-dragula-enabled', '.js-lpb-region').forEach(function (c) {
        var builderElement = c.closest('[data-lpb-id]');
        var drake = $(builderElement).data('drake');
        drake.containers.push(c);
      });
      if (jsUiElements.length) {
        Drupal.attachBehaviors(context, settings);
      }
      updateDialogButtons(context);
    }
  };
  $(window).on('dialog:aftercreate', function (event, dialog, $dialog) {
    if ($dialog.attr('id').indexOf('lpb-dialog-') === 0) {
      updateDialogButtons($dialog);
    }
  });
  var lpDialogInterval;
  $(window).on('dialog:aftercreate', function (event, dialog, $dialog) {
    if ($dialog[0].id.indexOf('lpb-dialog-') === 0) {
      clearInterval(lpDialogInterval);
      lpDialogInterval = setInterval(repositionDialog.bind(null, lpDialogInterval), 500);
    }
  });
})(jQuery, Drupal, Drupal.debounce, dragula, once);