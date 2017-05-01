/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

/**
 * Bootstrap Plugin Extensions.
 * Must be included after bootstrap.js
 */

// enhance tooltips
(function($) {
    // automatically add bootstrap tooltips to elements with titles
    $(function() {
        $('body').tooltip({selector:'[title]:not(.manual-tooltip)', container: 'body'});
    });

    // flag manual tooltips with a 'manual-tooltip' class
    // and support data options on selector-based tooltips
    var old = $.fn.tooltip;
    $.fn.tooltip = function(option) {
        // detect manually triggered tooltips - excluding delegated ones
        // which are only manual because they are invoked via selectors
        if ($.isPlainObject(option)) {
            option.isDelegated = option.isDelegated || !!option.selector;
            var isManual = !option.isDelegated && option.trigger === 'manual';

            // flag elements with manually invoked tooltips as such so that
            // we can exclude them from our auto-show/hide selector
            this.addClass(isManual ? 'manual-tooltip' : '');

            // mark modal tooltips so we can give them additional styles in css
            if (option.trigger === 'manual' && this.closest('.modal').length) {
                this.one('show', function(e) {
                    if (e.target === this) {
                        $(this).data('tooltip').tip().addClass('modal-tooltip');
                    }
                });
            }
        }

        // add support for custom classes via options
        this.off('show.tooltip.class');
        this.on('show.tooltip.class', function(e) {
            if (e.target === this && $(this).data('tooltip')) {
                var options = $(this).data('tooltip').options;
                $(this).data('tooltip').tip().addClass(
                    options.customClass || options.customclass
                );
            }
        });

        return old.apply(this, arguments);
    };
    $.fn.tooltip.Constructor        = old.Constructor;
    $.fn.tooltip.defaults           = old.defaults;

    // Additionally turn off tooltip animations, these were causing problems
    // where you would lose the tooltip, and the arrow would keep shifting
    $.fn.tooltip.defaults.animation = false;
    $.fn.tooltip.defaults.delay     = {show: 150, hide: 0};

    // enhance tooltip positioning to stay within the viewport width
    var tooltip = $.fn.tooltip.Constructor.prototype;
    var parent  = {applyPlacement: tooltip.applyPlacement};
    tooltip.applyPlacement = function(offset, placement) {
        var $tip  = this.tip(),
            width = $tip[0].offsetWidth,
            delta = document.documentElement.clientWidth - (offset.left + width);

        if (delta < 0) {
            offset.left += delta;
        }

        var result = parent.applyPlacement.apply(this, [offset, placement]);

        if (delta < 0) {
            var newWidth = $tip[0].offsetWidth;
            this.replaceArrow((delta * 2) - width + newWidth, newWidth, 'left');
        }

        return result;
    };

    // enhance tooltips to allow for objects/nodes as content in addition to text/html
    var oldSetContent = tooltip.setContent;
    tooltip.setContent = function() {
        oldSetContent.apply(this, arguments);
        if (typeof this.options.title === 'object') {
            this.tip().find('.tooltip-inner').empty().append(this.options.title);
        }
    };
}(window.jQuery));

// enhance popovers
(function($) {
    var old      = $.fn.popover;
    $.fn.popover = function(option) {
        // add support for custom classes via options
        this.off('show.popover.class');
        this.on('show.popover.class', function(e) {
            if (e.target === this && $(this).data('popover')) {
                var options = $(this).data('popover').options;
                $(this).data('popover').tip().addClass(
                    options.customClass || options.customclass
                );
            }
        });

        return old.apply(this, arguments);
    };
    $.fn.popover.Constructor        = old.Constructor;
    $.fn.popover.defaults           = old.defaults;

    // patch bootstrap bug where arrow function was missed
    // @todo remove when bootstrap 3+ is added
    var popover   = $.fn.popover.Constructor.prototype;
    popover.arrow = function() {
        this.$arrow = this.$arrow || this.tip().find(".arrow");
        return this.$arrow;
    };
}(window.jQuery));

/* match bootstrap 3's better collapse functionality */
(function($) {
    $.fn.collapse.Constructor.prototype.dimension = function () {
        this.$element.addClass('collapsing');
        return this.$element.hasClass('width') ? 'width' : 'height';
    };
    $(document).on('shown.collapse.data-api hidden.collapse.data-api', '.collapse', function (e) {
        if (this === e.target) {
            $(this).removeClass('collapsing');
        }
    });
}(window.jQuery));

/* add container support for dropdowns */
(function($) {
    var dropdown = $.fn.dropdown.Constructor.prototype,
        parent   = {toggle: dropdown.toggle, keydown: dropdown.keydown};

    var applyPosition = function (menu) {
        var el = menu.data('active-target');
        if (!el || !el.parentNode) {
            menu.removeClass('open').detach();
            return;
        }

        var bounds   = (typeof el.getBoundingClientRect === 'function')
                     ? el.getBoundingClientRect()
                     : {width: el.offsetWidth, height: el.offsetHeight};

        var position = $.extend({}, bounds, $(el).offset());

        if (menu.hasClass('pull-right')) {
            var left = position.left + position.width - menu[0].offsetWidth;
            menu.offset({top: position.top + position.height, left: left});
        } else {
            menu.offset({top: position.top + position.height, left: position.left});
        }
    };

    var clearContainerMenus = function() {
        $('.contained-dropdown').removeClass('open').detach();
    };

    var applyMenusPosition = function() {
        $('.contained-dropdown').each(function() {
            applyPosition($(this));
        });
    };

    dropdown.toggle = function(e) {
        var menu, isActive,
            $this     = $(this),
            container = $this.attr('data-dropdown-container');

        if (container) {
            if ($this.is('.disabled, :disabled')) {
                return;
            }

            menu = $this.data('dropdown-menu');
            if (!menu) {
                menu = $this.parent().find('.dropdown-menu');
                menu.detach();
                menu.removeClass('open').addClass('contained-dropdown');
                $this.data('dropdown-menu', menu);
            } else {
                isActive  = menu.hasClass('open');
            }
        }

        clearContainerMenus();
        parent.toggle.apply(this, arguments);

        if (container && menu) {
            // show
            if (!isActive) {
                menu.data('active-target', this);
                menu.addClass('open').css({top:0, left: 0}).appendTo(container);
                applyPosition(menu);
            }
        }

        return false;
    };

    dropdown.keydown = function(e) {
        if (!/(38|40|27)/.test(e.keyCode)) {
            return;
        }

        var $this  = $(this),
            menu   = $this.is('[role=menu]') ? $this : $this.data('dropdown-menu'),
            button = menu && menu.data('active-target');

        if (!button || !menu || !menu.is($this)) {
            parent.keydown.apply(this, arguments);
        } else {
            e.preventDefault();
            e.stopPropagation();
        }

        if (!button || !menu || $this.is('.disabled, :disabled')) {
            return;
        }

        var isActive = menu.hasClass('open');
        if (!isActive || (isActive && e.keyCode === 27)) {
            if (e.which === 27) {
                $(menu.data('active-target')).focus();
            }
            if ($this.is(menu)) {
                $this.click();
            }
            return;
        }

        var index,
            $items = $('li:not(.divider):visible a', menu);

        if (!$items.length) {
            return;
        }

        index = $items.index($items.filter(':focus'));

        if (e.keyCode === 38 && index > 0) {
            index--; // up
        }
        if (e.keyCode === 40 && index < $items.length - 1) {
            index++; // down
        }
        if (index === -1) {
            index = 0;
        }

        $items.eq(index).focus();
    };

    $(window).on('resize.dropdown.clear', clearContainerMenus);
    $(document)
        .off('click.dropdown.data-api', '[data-toggle=dropdown]')
        .off('keydown.dropdown.data-api', '[data-toggle=dropdown], [role=menu]')
        .on('click.dropdown.container.data-api', clearContainerMenus)
        .on('click.dropdown.data-api'  , '[data-toggle=dropdown]', dropdown.toggle)
        .on('keydown.dropdown.data-api', '[data-toggle=dropdown], [role=menu]' , dropdown.keydown)
        .on('shown.dropdown.position hidden.dropdown.position', applyMenusPosition);
}(window.jQuery));

// Affix Elements
//
// Add ".affix" class to a element when it scrolls out of view;
// however, when the entire bounding element is out of view, remove the
// .affix class.
(function($) {
    function Affix(element, options) {
        this.options   = options;
        this.$el       = $(element);
        this.$parent   = this.$el.closest(this.options.parentSelector);
        this.$target   = this.$parent.find(this.options.targetSelector);
        this.$boundary = this.options.boundary ? $(this.options.boundary) : this.$parent;

        this.$el.addClass('swarm-affix');

        // setup events right away if target is showing
        if (this.$target.hasClass('in')) {
            this.listen();
        }

        // listen for show/hide events on child elements
        // and then make sure we have all the affixed elements check their position
        this.$target.on("hide show", function(e) {
            if (e.target !== this) {
                $.fn.swarmAffix.checkAll();
            }
        });

        // listen whenever target's collapsed state is toggled
        var affix = this;
        this.$target.on("hidden shown", function(e) {
            if (e.target === this) {
                // for hidden we need to have this affix check itself before it removes the listener
                if (e.type === 'hidden') {
                    affix.check();
                }
                affix.listen(e.type === 'hidden');
            }
            $.fn.swarmAffix.checkAll();
        });
        if (this.options.scrollToTarget) {
            this.$target.on("hide", function(e) {
                if (e.target === this) {
                    // scroll the area into view if it is now offscreen
                    var top = affix.$parent.offset().top - affix.getFixedTop();
                    if (top < $(window).scrollTop()) {
                        $('html, body').animate({'scrollTop': top}, 'fast', 'swing');
                    }
                }
            });
        }
    }

    var fixedTop = null;
    Affix.prototype = {
        listen: function(disable) {
            // turn off eventListener if it exists
            this.eventListener = this.eventListener && $(window).off("scroll resize", this.eventListener) && null;
            if (disable) {
                return;
            }

            // listen on window scroll and resize
            var check = $.proxy(this.check, this);
            this.eventListener = function(e) {
                if (
                    e.target === this ||
                    e.target === document ||
                    e.target === document.documentElement ||
                    e.target === document.body
                ) {
                    // prevent interfering with scrolling
                    swarm.requestFrame(check);
                }
            };
            $(window).on("scroll resize", this.eventListener);
        },

        // find the space taken up by fixed elements that come before us
        getFixedTop: function() {
            if (fixedTop === null) {
                fixedTop = 0;
                $('.' + this.options.offsetClass).each(function() {
                    if ($(this).hasClass('swarm-affix')) {
                        return false;
                    }

                    // only consider elements that are currently position:fixed
                    if ($(this).css('position') === 'fixed') {
                        fixedTop += this.offsetHeight;
                    }
                });
                setTimeout(function() {
                    fixedTop = null;
                }, 1000);
            }

            return fixedTop;
        },

        check: function() {
            // early exit if element is hidden
            var elementHeight = this.$el[0].offsetHeight;
            if (!elementHeight) {
                return;
            }

            // grab all the positioning information
            // we use getBoundingClientRect directly here instead of $.fn.offset
            // in order to also get the height
            var useTop           = this.options.position === 'top',
                pageY            = $(window).scrollTop(),
                collapsePosition = this.$boundary[0].getBoundingClientRect(),
                fixedTop         = useTop && this.getFixedTop();

            // calculate our top and bottom locations
            // we need to add the pageY to the positions because getBoundingClientRec did not include it
            var view             = useTop ? pageY + fixedTop : pageY + $(window).height() - elementHeight,
                constrainTop     = collapsePosition.top + pageY,
                constrainBottom  = collapsePosition.top + pageY + collapsePosition.height,
                oldShowAffix     = this.showAffix,
                oldShowMiddle    = this.showAffixMiddle,
                oldShowBottom    = this.showAffixBottom;

            // showAffix is true if our view is within our two constraints, and the collapse target is showing
            this.showAffix       = (constrainTop <= view) && (view <= constrainBottom) && this.$target.hasClass("in");
            this.showAffixMiddle = this.showAffixBottom = false;

            if (this.options.animate) {
                // affix-middle as soon as the view passes the midway point, this is useful for
                // being able to turn off transitions before it hits the bottom
                this.showAffixMiddle = this.showAffix && (view >= constrainBottom - (collapsePosition.height / 2));
                // affix-bottom when header hits the bottom of the constrain area
                this.showAffixBottom = this.showAffix && (view >= constrainBottom - elementHeight);
            }

            // affix function so we can optionally delay these actions in order to run animations
            var affix = $.proxy(function() {
                // provide the bootstrap affix class if showAffix is true
                var cls = useTop ? this.options.offsetClass : '';
                this.$el.toggleClass("affix " + cls, this.showAffix);

                if (this.options.animate) {
                    if (this.showAffix && !oldShowAffix) {
                        this.$el.css({'top' : '', 'bottom': ''});
                    }

                    this.$el.toggleClass('affix-middle', this.showAffixMiddle);
                    this.$el.toggleClass('affix-bottom', this.showAffixBottom);
                }
            }, this);

            // only run affix if something has changed
            if (
                oldShowAffix !== this.showAffix
                || oldShowMiddle !== this.showAffixMiddle
                || oldShowBottom !== this.showAffixBottom
            ) {
                if (this.options.animate && this.showAffix && !oldShowAffix) {
                    // if we overshot by > 10 pixels, we will animate our affix
                    if (useTop) {
                        var startingTop = collapsePosition.top >= fixedTop - 10 ? fixedTop : collapsePosition.top + 1;
                        this.$el.css('top', startingTop + 'px');
                    } else {
                        var startingBottom = collapsePosition.bottom >= -10 ? 0 : collapsePosition.bottom;
                        this.$el.css('bottom', startingBottom + 'px');
                    }
                    swarm.requestFrame(affix);
                    return;
                }

                affix();
            }
        }
    };

    $.fn.swarmAffix = function(options) {
        $.fn.swarmAffix.checkAll(true);
        return this.each(function() {
            var data = $.data(this, 'swarm-affix');
            if (!data) {
                options = $.extend({}, $.fn.swarmAffix.defaults, options);
                data    = new Affix(this, options);
                $.data(this, 'swarm-affix', data);
            } else if(options && options.boundary) {
                data.$boundary = $(options.boundary);
            }

            // schedule the hard work for the next repaint
            swarm.requestFrame(function() {
                data.check();
            });
        });
    };

    var checked = [false, false];
    $.fn.swarmAffix.checkAll = function(affixedOnly) {
        var key = affixedOnly ? 0 : 1;
        // schedule for next repaint
        swarm.requestFrame(function() {
            // only allow checkAll to run once per frame
            if (checked[key]) {
                return;
            }

            // do the check
            $('.swarm-affix' + (affixedOnly ? '.affix' : '')).each(function() {
                var plugin = $.data(this, 'swarm-affix');
                // only run check on affix plugins with active listeners
                if (plugin.eventListener) {
                    plugin.check();
                }
            });

            // mark that we have checked
            // and then reset checking after all other frame work
            checked[key] = true;
            setTimeout(function() {
                checked[key] = false;
            }, 0);
        });
    };

    $.fn.swarmAffix.defaults = {
        offsetClass:    'offset-fixed',
        parentSelector: '.diff-wrapper',
        targetSelector: '.diff-details',
        boundary:       null,
        scrollToTarget: false,
        animate:        true,
        position:       'top'
    };
}(window.jQuery));
