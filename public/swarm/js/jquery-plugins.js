/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

/**
 * Swarm jQuery Plugins
 *     jQuery plugins were created to support the swarm js modules
 */

// textarea resize plugin
(function($) {
    var measure = function(element) {
        var previous, current;
        element  = $(element);
        if (!element.parent().length) {
            return false;
        }

        previous = element.data('size-data');
        current  = {
            width:  element.width(),
            height: element.height()
        };

        if (!previous || current.width !== previous.width || current.height !== previous.height) {
            element.trigger($.Event('textarea-resize'));
            element.data('size-data', current);
        }

        return !!current.height;
    };

    $.fn.textareaResize = function() {
        return this.each(function() {
            var $this = $(this), mousedown, mousemove, triggered;
            if ($this.data('size-data')) {
                return;
            }

            // store the current size so we know when it changes
            $this.data('size-data', {
                width:  $this.width(),
                height: $this.height()
            });

            // add mousedown listener for best case
            $this.on('mousedown', function() {
                // setInterval produces a smoother result than setTimeout
                mousedown = setInterval(function() {
                    var measured = measure($this);
                    if (measured === false) {
                        clearInterval(mousedown);
                    }
                }, 15);
                triggered = true;
            });

            // add mousemove listeners for browsers that
            // won't fire mousedown on the resize control
            if (swarm.has.nonStandardResizeControl()) {
                $this.on('mousemove', function() {
                    triggered = true;
                    if (mousemove) {
                        return;
                    }

                    mousemove = setTimeout(function() {
                        mousemove = false;
                        measure($this);
                    }, 200);
                });
            }

            // add mouseup cleanup
            $(document).on('mouseup', function() {
                clearInterval(mousedown);
                if (triggered) {
                    measure($this);
                }
                triggered = false;
            });
        });
    };
}(window.jQuery));

// version slider plugin
(function($) {
    // constructor for Slider class
    var Slider = function(element, options) {
        this.$element = $(element);
        this.options  = options;

        // create the slider
        this.getPlot();
        this.renderRevisionPoints();
        this.setMarkerMode(options.markerMode || this.markerMode);

        // determine the radius of the marker now so we don't have to do it on mouse move
        this.markerRadius = this.getMarker().outerWidth() / 2;

        // create a special tooltip for the marker which updates
        // as it travels along the plot
        var slider = this;
        this.$element.tooltip({
            selector:  '.rev, .marker, .connector',
            container: 'body',
            html:      true,
            template:  this.tooltipTemplate,
            title:     function() {
                // show a diff label for connector tooltips
                if ($(this).hasClass('connector')) {
                    var markers  = slider.getMarker('both'),
                        rightRev = slider.getRevisionData(markers.right.data('target')),
                        leftRev  = slider.getRevisionData(markers.left.data('target'));
                    return slider.getDiffLabel(rightRev, leftRev);
                }

                var closest = $($.data(this, 'target'));
                return closest.attr('data-original-title') || closest.attr('title');
            }
        });

        // we only present an interactive slider if we have more than one revision
        if ((this.getRevisionData() || []).length <= 1) {
            return;
        }

        // attach listeners
        this.$element.on('mousedown.slider', $.proxy(this.mousedown, this));
        $(document).on('mouseup.slider', $.proxy(this.mouseup, this));
    };

    Slider.prototype = {
        constructor:       Slider,
        markerMode:        1, // represents the number of supported markers, 1 or 2 are supported

        connectorTemplate: '<div class="connector" data-original-title="" draggable="false">'
                         +     '<div class="connector-inner"></div>'
                         + '</div>',
        markerTemplate:    '<div class="marker" data-original-title="" draggable="false">'
                         +     '<div class="marker-dot border-box"></div>'
                         + '</div>',
        tooltipTemplate:   '<div class="tooltip slider-tooltip">'
                         +     '<div class="tooltip-arrow"></div>'
                         +     '<div class="tooltip-inner"></div>'
                         + '</div>',

        // the distance from the left to the center of a marker element
        markerRadius: 0,

        // updates the marker mode, and refreshes the marker display
        setMarkerMode: function(mode) {
            this.previousRevision = null;
            this.removeModeVisuals();
            if (mode === 1) {
                this.markerMode = mode;
                this.moveMarker(this.$element.find('.active').last(), null, true);
            } else if (mode === 2) {
                var current  = this.$element.find('.active').last(),
                    previous = this.$element.find('.active').not(current).first();
                if (!previous.length) {
                    previous = current.prev('.rev');
                }
                if (!previous.length) {
                    previous = current;
                    current  = current.next('.rev');
                }
                this.markerMode = mode;
                this.$connector = $(this.connectorTemplate).insertAfter(this.getPlot());
                this.moveMarker(current,  'right', true);
                this.moveMarker(previous, 'left',  true);
            }
            this.updateActive();
        },

        // returns the marker closest to the passed position
        _getClosestMarker: function(position) {
            if (this.markerMode === 1) {
                return this.getMarker();
            }

            var markers     = this.getMarker("both"),
                leftOffset  = Math.abs(position - (markers.left.offset().left  + this.markerRadius)),
                rightOffset = Math.abs(position - (markers.right.offset().left + this.markerRadius));
            return leftOffset < rightOffset ? markers.left : markers.right;
        },

        // given a position within the slider, this function will return the closest revision node
        _getClosestRevNode: function(position, marker, elementWidth) {
            // the first and last rev only have one section, otherwise all other
            // revs have two sections, one on either side of their node. These functions
            // determine which section the position falls into, and then matches that
            // section to a revision index
            var numSections  = (this.getRevisionData().length * 2) - 2,
                section      = Math.floor(position / (elementWidth / numSections)),
                rev          = Math.round(section / 2);
            this.setClosestRevNode(this.$element.find('.rev')[rev], marker);

            return marker.data('target');
        },

        // keep track of the closest rev node, so everyone is not always trying to calculate it
        setClosestRevNode: function(node, marker) {
            var closestRevNode = marker.data('target');
            if (closestRevNode === node) {
                return;
            }

            // updates classes to allow for styling current closest node
            // but only remove the style if another marker doesn't also point to it
            if (this.markerMode === 1 || this.getMarker().not(marker[0]).data('target') !== closestRevNode) {
                $(closestRevNode).removeClass('closest');
            }
            $(node).addClass('closest');

            marker.data('target', node);
            this.$element.trigger('slider-closest-change', this);

            // updates the tooltip's position if we are being clicked
            if (this._mouseListen) {
                var tooltip = this.$element.data('tooltip');
                tooltip.leave({currentTarget: closestRevNode});

                // only show the new tooltip if it is not already showing
                var nodeTooltip = $.data(node, 'tooltip');
                if (this.markerMode === 2) {
                    nodeTooltip = this.$connector.data('tooltip');
                    if (nodeTooltip) {
                        nodeTooltip.tip().find('.tooltip-inner').html(nodeTooltip.getTitle());
                    }
                } else if (!nodeTooltip || !nodeTooltip.tip()[0].parentNode) {
                    tooltip.enter({currentTarget: node});
                }
            }
        },

        // called whenever a mouse event is happening on the slider, passing snap
        // as true will result in the marker snapping to the nearest revision
        onMarkerMouseMove: function(e, snap) {
            this.dragging   = true;
            var bounds      = this.$element[0].getBoundingClientRect(),
                position    = e.pageX - this.markerRadius,
                marker      = this.closestMarker,
                slaveMarker = this.slaveMarker,
                isLeft      = marker.data('side') === 'left';

            // when dragging connector, hold position of cursor on connector
            if (this.lockstep) {
                position -= this.markerOffset;
            }

            // constrain position to left/right bounds of the slider
            position = Math.max(position, bounds.left);
            position = Math.max(position, bounds.left  + this.lockstep);
            position = Math.min(position, bounds.right);
            position = Math.min(position, bounds.right + this.lockstep);

            // make position relative to left edge of slider
            position -= bounds.left + $(window).scrollLeft();

            // when dragging connector, move the slave marker as well
            if (this.lockstep) {
                slaveMarker.css('left', position - this.lockstep + 'px');
                this._getClosestRevNode(position - this.lockstep, slaveMarker, bounds.width);
            }

            // in 2 marker mode when not dragging connector, don't let the markers cross
            if (this.markerMode === 2 && !this.lockstep) {
                var distance      = bounds.width / (this.getRevisionData().length - 1),
                    slavePosition = parseFloat(slaveMarker.css('left'), 10);
                position = isLeft
                    ? Math.min(position, slavePosition - distance)
                    : Math.max(position, slavePosition + distance);
            }

            marker.css('left', position + 'px');
            this._getClosestRevNode(position, marker, bounds.width);

            // update the connector if we have two markers
            if (this.markerMode === 2) {
                this.updateConnector();
                // move connector tooltip in a setTimeout to reduce impact to user drag
                setTimeout($.proxy(this.moveConnectorTooltip, this), 0);
            }

            this.$element.trigger('slider-move', this, position);

            // snap to the nearest revision
            if (snap) {
                if (this.lockstep) {
                    this.moveMarker(slaveMarker.data('target'), slaveMarker, true);
                }
                this.moveMarker(marker.data('target'), marker);
            }
        },

        // tooltips are not designed to be moved while being shown,
        // so this function takes care of helping the tooltip update it's position
        moveConnectorTooltip: function() {
            var nodeTooltip = this.$connector.data('tooltip');

            // show the tooltip if it is not already showing
            if (!nodeTooltip || !nodeTooltip.tip()[0].parentNode) {
                this.$element.data('tooltip').enter({currentTarget: this.$connector[0]});
                return;
            }

            var tip          = nodeTooltip.tip(),
                pos          = nodeTooltip.getPosition(),
                actualWidth  = tip[0].offsetWidth,
                actualHeight = tip[0].offsetHeight;

            nodeTooltip.applyPlacement(
                {top: pos.top - actualHeight, left: pos.left + pos.width / 2 - actualWidth / 2}, 'top'
            );
        },

        updateConnector: function() {
            var markers   = this.getMarker('both'),
                left      = markers.left[0].style.left,
                right     = markers.right[0].style.left,
                isPercent = left.indexOf('%') > 0 && right.indexOf('%') > 0,
                units     = isPercent ? '%' : 'px';

            // if we don't have percentages, get computed px values
            // we prefer % because the connector scales with the page
            if (!isPercent) {
                left  = markers.left.css('left');
                right = markers.right.css('left');
            }

            left  = parseFloat(left, 10);
            right = parseFloat(right, 10);

            this.$connector.css({
                left:  left + units,
                width: (right - left) + units
            });
        },

        // move the marker to the same position as the passed target, and update the active node
        moveMarker: function(target, side, positionOnly) {
            target = this.$element.find(target);
            if (!target.length) {
                return;
            }

            // position the marker
            var marker = side instanceof $ ? side : this.getMarker(side),
                index  = parseInt(target.data('rev-index'), 10);
            marker.css(this.getRevNodePosition(index));

            // also update the connector if we are in a 2 marker mode
            if (this.markerMode === 2) {
                this.updateConnector();
            }

            // update the current cached data
            this.setClosestRevNode(target[0], marker);

            // set rev target as active if it is not already
            var isActive    = $(marker.data('target')).is('.active'),
                slaveMarker = this.getMarker().not(marker),
                slaveActive = $(slaveMarker.data('target')).is('.active');
            if (!positionOnly && (!isActive || (slaveMarker.length && !slaveActive))) {
                this.updateActive();
                this.$element.trigger('slider-moved', this);
            }
        },

        // update the active marker classes and store the active version
        updateActive: function() {
            var marker           = this.markerMode === 2 ? this.getMarker('right') : this.getMarker(),
                revNode          = $(marker.data('target'));

            this.currentRevision = this.getRevisionData(revNode);

            this.$element.find('.rev').removeClass('active');
            revNode.addClass('active');

            // set a previousRevision if we are in a 2 marker mode
            if (this.markerMode === 2) {
                var previousMarker    = this.getMarker('left'),
                    previousRevNode   = $(previousMarker.data('target'));
                this.previousRevision = this.getRevisionData(previousRevNode);
                previousRevNode.addClass('active');
            }
        },

        // render revision nodes onto the plot
        renderRevisionPoints: function() {
            this.$element.find('.rev').remove();
            var slider    = this,
                revisions = this.getRevisionData();

            $.each(revisions, function(index, value) {
                var position = slider.getRevNodePosition(index);
                    position = "left: " + position.left + ';';

                // append a new revision node to the slider
                var cls = (value.selected ? "active" : "") + (value.pending ? "" : " committed"),
                    rev = $(
                          '<div class="rev manual-tooltip ' + cls + '" style="' + position + '">'
                        +     '<div class="rev-dot border-box"></div>'
                        + '</div>'
                    );
                rev.data('rev-index', index);
                rev.attr('title', slider.getRevisionLabel(value));
                rev.appendTo(slider.$element);
            });
        },

        // returns the percentage position of a node based on its index
        getRevNodePosition: function(index) {
            var revisions = this.getRevisionData().length;
            return {left: (revisions <= 1 ? 100 : (index / (revisions - 1) * 100)) + '%'};
        },

        // returns label text for revision tooltips
        getRevisionLabel: function(value) {
            return '#' + value.rev + " " + swarm.te('by') + " <b>" + value.user + "</b> "
                 + $.timeago.inWords(Date.now() - (value.time * 1000)) + "<br />"
                 + '<span class="muted">'
                 +   swarm.te(value.pending ? 'Shelved in %s' : 'Committed in %s', [value.change])
                 + '</span>';
        },

        // returns label text for tooltips when comparing two nodes
        getDiffLabel: function(value, against) {
            return '<div class="text-left">#'
                 +   value.rev
                 + ' <span class="muted">'
                 +   swarm.te(value.pending ? 'Shelved in %s' : 'Committed in %s', [value.change])
                 + '</span><br />'
                 + '#' + against.rev
                 + ' <span class="muted">'
                 +   swarm.te(against.pending ? 'Shelved in %s' : 'Committed in %s', [against.change])
                 + '</span></div>';
        },

        // returns revision data, if you pass a revNode
        // it will only return the data for that node
        getRevisionData: function(revNode) {
            return revNode ? this.options.data[$(revNode).data('rev-index')] : this.options.data;
        },

        getMarker: function(side) {
            // supports using a declared marker, otherwise creates a new marker
            this.$marker = this.$marker || this.$element.find('.marker');

            // add as many markers as we need for the mode we are in
            var i;
            for (i = 0; i < this.markerMode; i++) {
                if (!this.$marker[i]) {
                    this.$marker = this.$marker.add($(this.markerTemplate).insertAfter(this.getPlot()));
                }
            }

            // return unfiltered result if a side isn't specified, or we only have one
            if (!side || this.$marker.length === 1) {
                return this.$marker;
            }

            // sort the markers into left and right sides
            var domFirst = this.$marker.eq(0).offset().left,
                domLast  = this.$marker.eq(1).offset().left;
            var sides    = {
                left:  domFirst <  domLast ? this.$marker.eq(0) : this.$marker.eq(1),
                right: domFirst >= domLast ? this.$marker.eq(0) : this.$marker.eq(1)
            };

            // store each markers current position
            sides.left.data('side', 'left');
            sides.right.data('side', 'right');

            // return either both sides, or the specifically requested side
            return side === 'both' ? sides : sides[side];
        },

        // remove any dom attributes or elements that are specific to a markerMode
        // this acts as a reset when changing marker modes
        removeModeVisuals: function() {
            if (this.$marker) {
                this.$marker.remove();
                this.$marker = null;
            }
            if (this.$connector) {
                this.$connector.remove();
                this.$connector = null;
            }
            this.$element.find('.closest').removeClass('closest');
        },

        getPlot: function() {
            // supports using a declared plot, otherwise creates a new plot
            this.$plot = this.$plot || this.$element.find('.plot');
            if (!this.$plot.length) {
                this.$plot = $('<div class="plot" />').prependTo(this.$element);
            }

            return this.$plot;
        },

        mousedown: function(e) {
            // only handle primary button mouse events
            if (e.button !== 0) {
                return;
            }
            e.preventDefault();

            var target  = $(e.target),
                tooltip = this.$element.data('tooltip');

            // normalize inners to positioned parent node
            if (target.is('.marker-dot, .rev-dot, .connector-inner')){
                target = target.parent();
            }

            this.lockstep      = 0;
            this.markerOffset  = 0;
            this._mouseListen  = true;
            this._clickTarget  = target[0];
            this.closestMarker = target.hasClass('marker') ? target : this._getClosestMarker(e.pageX);
            this.slaveMarker   = this.getMarker().not(this.closestMarker);

            this.getMarker().addClass('moving');

            // turn off the tooltip handling, we will move it ourselves with the marker
            this.$element.off('mouseenter.tooltip', '.rev, .marker, .connector');
            this.$element.off('mouseleave.tooltip', '.rev, .marker, .connector');

            // if the user clicks on the connector, move markers in lockstep
            if (this.markerMode === 2) {
                var isLeft        = this.closestMarker.data('side') === 'left',
                    closestLeft   = this.closestMarker.offset().left,
                    slaveLeft     = this.slaveMarker.offset().left,
                    dotRadius     = this.closestMarker.find('.marker-dot').outerWidth() / 2;
                this.markerOffset = e.pageX - (closestLeft + this.markerRadius);
                if (Math.abs(this.markerOffset) > dotRadius
                    && ((isLeft && this.markerOffset > 0) || (!isLeft && this.markerOffset < 0))
                ) {
                    this.lockstep = closestLeft - slaveLeft;
                }
            }

            // if the target is a revision node, move our marker directly to the node
            // else move to the current mouse position
            if (target.hasClass('rev')) {
                this.moveMarker(target[0], this.closestMarker, true);
            } else {
                // open a tooltip if we only have one marker, or if the target is not part of the marker connector
                if (this.markerMode === 1 || !target.hasClass('connector')) {
                    var tooltipTarget = this.markerMode === 2 ? this.$connector[0] : this.closestMarker.data('target');
                    tooltip.enter({currentTarget: tooltipTarget});
                }
                this.onMarkerMouseMove(e);
            }

            // listen for mouse movements
            $(document).on('mousemove.slider', $.proxy(this.onMarkerMouseMove, this));

            // remove the marker's tooltip a bit later to minimize the fliker effect
            // when we are transitioning from a marker tooltip to a revision tooltip
            setTimeout($.proxy(function() {
                if (this.closestMarker) {
                    tooltip.leave({currentTarget: this.closestMarker[0]});
                }
            }, this), 500);
        },

        mouseup: function(e) {
            // ignore mouseup while we are not listening
            if (!this._mouseListen) {
                return;
            }

            // stop listening for mouse movements
            $(document).off('mousemove.slider');
            this.getMarker().removeClass('moving');

            // snap to the closest revision
            if (this.dragging) {
                this.onMarkerMouseMove(e, true);
            } else {
                this.moveMarker(this._clickTarget, this.closestMarker);
            }

            this.closestMarker = null;
            this._mouseListen  = false;
            this._clickTarget  = null;
            this.dragging      = false;

            // restore tooltip control to the bootstrap plugin
            var tooltip = this.$element.data('tooltip');
            this.$element.on('mouseleave.tooltip', '.rev, .marker, .connector', $.proxy(tooltip.leave, tooltip));
            this.$element.on('mouseenter.tooltip', '.rev, .marker, .connector', $.proxy(tooltip.enter, tooltip));
            this.$element.find('.rev, .connector').trigger('mouseout');
        },

        disable: function() {
            this.$element.addClass('disabled');
            this.$element.append('<div class="overlay"></div>');
            this.$element.off('mousedown.slider');
            $(document).off('mouseup.slider');
        }
    };

    // build the jquery plugin for the versionSlider
    $.fn.versionSlider = function(option) {
        return this.each(function() {
            var $this  = $(this),
                slider = $this.data('versionSlider');
            if (!slider) {
                var options = $.extend({}, $this.data(), typeof option === 'object' && option);
                $this.data('versionSlider', new Slider(this, options));
            } else if (typeof option === 'object') {
                $.extend(slider.options, option);
                slider.renderRevisionPoints();
                slider.setMarkerMode(slider.options.markerMode || slider.markerMode);
            }
        });
    };

    $.fn.versionSlider.Constructor = Slider;
}(window.jQuery));

// multipicker which makes use of typehead to select items for rendering as buttons within the associated container
(function($) {
    // constructor for MultiPicker
    var MultiPicker = function(element, options) {
        this.$element = $(element);
        this.options  = $.extend({}, $.fn.multiPicker.defaults, options);
        this.init();
    };

    MultiPicker.prototype = {
        constructor: MultiPicker,
        itemTemplate:
                '<div class="multipicker-item" data-value="{{>value}}">'
            +       '<div class="pull-left">'
            +           '<div class="btn-group">'
            +               '<button type="button" class="btn btn-mini btn-info button-name" disabled>{{>value}}</button>'
            +               '<button type="button" class="btn btn-mini btn-info item-remove"'
            +                       'title="{{te:"Remove"}}" aria-label="{{te:"Remove"}}">'
            +                   '<i class="icon-remove icon-white"></i>'
            +               '</button>'
            +           '</div>'
            +           '{{if inputName}}<input type="hidden" name="{{>inputName}}[]" value="{{>value}}">{{/if}}'
            +       '</div>'
            +   '</div>',

        init: function() {
            // setup underlying typeahead
            this.$element.typeahead(this.options);
            this.typeahead = this.$element.data('typeahead');

            this.typeahead.select      = $.proxy(this.select, this);
            this.typeahead.highlighter = $.proxy(this.highlighter, this);
            this.typeahead.matcher     = $.proxy(this.matcher, this);

            // add extra classes to multipicker element and the items container to assist with styling
            this.$element.addClass('multipicker-input');
            this.getItemsContainer().addClass('multipicker-items-container clearfix');

            // prevent default enter action on the element
            this.$element.on('keypress', function(e) {
                if(e.keyCode === 13) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });

            // render initially selected items
            $.each(this.options.selected, $.proxy(function(index, label){
                this.addItem(label);
            }, this));

            // initialize element's required state
            this.update();

            // disable the element if the multi-picker is requested disabled in options
            if (this.options.disabled) {
                this.disable();
            }
        },

        setSource: function(source) {
            this.typeahead.source = source;
        },

        highlighter: function(item) {
            // escape the item and the query
            item        = $('<span />').text(item).html();
            var query   = $('<span />').text(this.typeahead.query).html();

            // we highlight by bolding the parts of the item that match the query
            // this is directly taken from bootstraps highlighter method @todo update with bootstrap
            // the query.replace is escaping any characters that would impact the next regexp
            query = query.replace(/[\-\[\]{}()*+?.,\\\^$|#\s]/g, '\\$&');
            return item.replace(new RegExp('(' + query + ')', 'ig'), function ($1, match) {
                return '<strong>' + match + '</strong>';
            });
        },

        matcher: function(item) {
            // exclude items that are already selected
            return item.toLowerCase().indexOf(this.typeahead.query.toLowerCase()) !== -1
                && this.getSelected().indexOf(this.typeahead.updater(item)) === -1;
        },

        addItem: function(value) {
            // don't bother adding already selected item
            if (this.getSelected().indexOf(value) !== -1) {
                return false;
            }

            // render item as button and place it into the associated container
            var container = this.getItemsContainer();
            if (container.length) {
                var itemNode = this.createItem(value).appendTo(container);

                // wire-up remove listener
                var plugin = this;
                itemNode.find('.item-remove').on('click', function (e) {
                    e.stopPropagation();
                    $(this).tooltip('destroy');
                    $(this).closest('.multipicker-item').remove();
                    plugin.update();
                });
            }

            this.update();
        },

        createItem: function(value, inputName) {
            // call passed createItem function if available, otherwise use our default
            if (this.options.createItem) {
                return this.options.createItem.call(this, value);
            }

            return $($.templates(this.itemTemplate).render({
                value: value, inputName: inputName || this.options.inputName
            }));
        },

        select: function() {
            var value  = this.typeahead.$menu.find('.active').attr('data-value'),
                result = this.addItem(this.typeahead.updater(value));

            // clear the element if adding selected item was successful
            if (result !== false) {
                this.$element.val('');
            }

            return this.typeahead.hide();
        },

        getSelected: function() {
            var items = [];
            this.getItemsContainer().find('.multipicker-item').each(function(){
                items.push($(this).data('value'));
            });
            return items;
        },

        getItemsContainer: function() {
            return $(this.options.itemsContainer);
        },

        update: function() {
            this.updateRequired();
            if ($.isFunction(this.options.onUpdate)) {
                this.options.onUpdate.call(this);
            }
        },

        updateRequired: function() {
            if (this.options.required === null) {
                return;
            }

            var required = typeof this.options.required === 'function' ? this.options.required.call(this) : this.options.required,
                isEmpty  = this.getSelected().length === 0;
            this.$element.prop('required', required && isEmpty).trigger('change');
        },

        clear: function() {
            var container = this.getItemsContainer();
            container.find('[title]').tooltip('destroy');
            container.find('.multipicker-item').remove();
            this.update();
        },

        disable: function () {
            this.$element.prop('disabled', true);
        },

        enable: function () {
            this.$element.prop('disabled', false);
        }
    };

    // build the jquery plugin for the multiPicker
    $.fn.multiPicker = function(option) {
        return this.each(function() {
            var $this       = $(this),
                multiPicker = $this.data('multipicker');
            if (!multiPicker) {
                var options = $.extend({}, $this.data(), typeof option === 'object' && option);
                $this.data('multipicker', new MultiPicker(this, options));
            } else if (typeof option === 'string') {
                multiPicker[option]();
            }
        });
    };

    $.fn.multiPicker.defaults = {
        itemsContainer: null,   // element or selector for placing selected items
        inputName:      null,   // name for the input element for selected items
        onUpdate:       null,   // function called when selected items are updated
        required:       null,   // whether the associated input element is required
                                // value can be boolean, callback or null; if null, then
                                // 'required' property will not be modified by this plugin
        createItem:     null,   // optional function that will override the default button creation
        selected:       [],     // list of initially selected items
        source:         [],     // list of all items available to select or callback
        disabled:       false   // set to true to disable the multi-picker
    };

    $.fn.multiPicker.Constructor = MultiPicker;
}(window.jQuery));

// user-multipicker
(function($) {
    var UserMultiPicker = function(element, options) {
        var $this          = this;
        this.$element      = $(element);
        this.options       = $.extend({}, $.fn.userMultiPicker.defaults, options);
        this.groupUsers    = {};
        this.projectPrefix = 'swarm-project-';

        // pull out passed in source so we can normalize
        // it before it gets set on typeahead
        var source = this.options.source || [];
        delete this.options.source;

        // normalize selected items and optionally add groups
        var selected = [];
        $.each(this.options.selected, function(){
            selected.push($this.makeUserItem(this));
        });
        $.each(this.options.enableGroups ? this.options.selectedGroups : [], function(){
            selected.push($this.makeGroupItem(this));
        });
        this.options.selected = selected;

        this.init();
        this.typeahead.updater = $.proxy(this.updater, this);
        this.typeahead.render  = $.proxy(this.render,  this);
        this.typeahead.sorter  = $.proxy(this.sorter,  this);
        this.setSource(source);

        // disable the element and load the users/groups
        this.disable();

        // if the input is required, then explicitly mark as invalid while loading the source
        // because the ':invalid' pseudo-class does not match on disabled elements
        if (this.$element.prop('required') && !this.$element.val()) {
            this.$element.addClass('invalid');
        }

        // load the source unless the user multi-picker is disabled via options
        if (!this.options.disabled) {
            $.fn.userMultiPicker.Promise(this).done($.proxy(this.setSource, this));
        }
    };

    UserMultiPicker.prototype = $.extend({}, $.fn.multiPicker.Constructor.prototype, {
        constructor: UserMultiPicker,

        matcher: function(item) {
            if (item.label.toLowerCase().indexOf(this.typeahead.query.toLowerCase()) === -1) {
                return false;
            }

            // don't match items that are already selected, explicitly excluded or if its
            // a group/project and groups are not enabled
            var excludeUsers    = this.options.excludeUsers    || [],
                excludeProjects = this.options.excludeProjects || [],
                projectId       = item.id.substr(this.projectPrefix.length),
                enableGroups    = this.options.enableGroups;
            if (this.getSelected(item).length
                || (item.type === 'user'    && excludeUsers.indexOf(item.id) !== -1)
                || (item.type === 'project' && excludeProjects.indexOf(projectId) !== -1)
                || (item.type !== 'user'    && !enableGroups)
            ) {
                return false;
            }

            return true;
        },

        setSource: function(source) {
            this.typeahead.source = source;
            this.enable();
            this.$element.removeClass('invalid');
        },

        updater: function(item) {
            return item.split(' (')[0];
        },

        sorter: function(items) {
            // sort by label
            items.sort(function (a, b) {
                a = a.label.toLowerCase();
                b = b.label.toLowerCase();
                return a === b ? 0 : (a > b ? 1 : -1);
            });

            return items;
        },

        addItem: function(item) {
            // determine type based on item type (project is a special case of group)
            var type = item.type === 'project' ? 'group' : item.type;

            // don't bother adding already selected item
            if (this.getSelected(item).length) {
                return false;
            }

            // render item as button and place it into the associated container
            var container = this.getItemsContainer();
            if (container.length) {
                // place the item in a sub-container based on the type (groups on top)
                var subContainer = container.find('.type-' + type);
                if (!subContainer.length) {
                    subContainer = $('<div>')
                        .addClass('clearfix type-' + type)[type === 'group' ? 'prependTo' : 'appendTo'](container);
                }

                var inputName = type === 'group'
                        ? (this.options.groupInputName || this.options.inputName + '-groups')
                        : this.options.inputName,
                    itemNode = this.createItem(item.label, inputName).data('value', item).appendTo(subContainer);

                // tweak button class for groups
                if (type === 'group') {
                    itemNode.find('button').removeClass('btn-info').addClass('btn-primary');
                }

                // set value to be the item id
                itemNode.find('input').val(item.id);

                // attach tooltip to show the type
                var multipicker = this;
                var labels = {
                    'user':    swarm.te('User'),
                    'group':   swarm.te('Group'),
                    'project': swarm.te('Project')
                };
                itemNode.find('.button-name').prop('disabled', false).click(function(e){
                    e.preventDefault();
                }).tooltip({
                    html:        true,
                    container:   'body',
                    customClass: 'multipicker-item',
                    title:       function() {
                        var title = '<span class="label-type type-' + item.type + '">' + labels[item.type] + '</span>'
                                  + '<span class="name">' + item.label + '</span>';

                        // append users for groups/projects
                        if (item.type === 'group' || item.type === 'project') {
                            var users = multipicker.groupUsers[item.id];

                            if ($.type(users) === 'array') {
                                return title
                                    + '<div class="group-users muted">'
                                    + users.slice(0, 100).join(', ')
                                    + (users.length > 100 ? ' ...' : '')
                                    + '</div>';
                            }

                            // if users are not set, load them
                            if (!users) {
                                var tooltip = $(this).data('tooltip');
                                multipicker.groupUsers[item.id] = $.ajax('/users?fields[]=User&group=' + item.id).done(function (users) {
                                    multipicker.groupUsers[item.id] = $.map(users, function (user) {
                                        return user.User;
                                    });

                                    // re-draw tooltip
                                    if (tooltip.tip().hasClass('in')) {
                                        tooltip.show();
                                    }
                                });
                            }
                        }

                        return title;
                    }
                });

                // wire-up remove listener
                var plugin = this;
                itemNode.find('.item-remove').on('click', function (e) {
                    e.stopPropagation();
                    $(this).tooltip('destroy');
                    $(this).closest('.multipicker-item').remove();
                    plugin.update();
                });
            }

            this.update();
        },

        select: function() {
            var value  = this.typeahead.$menu.find('.active').data('value'),
                result = this.addItem(value);

            // clear the element if adding selected item was successful
            if (result !== false) {
                this.$element.val('');
            }

            return this.typeahead.hide();
        },

        getSelected: function(item) {
            var items = [];
            this.getItemsContainer().find('.multipicker-item').each(function(){
                var value = $(this).data('value');
                if (!item || (item.id === value.id && value.type === item.type)) {
                    items.push(value);
                }
            });
            return items;
        },

        render: function(items) {
            var $this     = this,
                typeahead = this.typeahead,
                menu      = this.typeahead.$menu;

            // group items by types
            var types = {'project': [], 'group': [], 'user': []};
            $.each(items, function () {
                types[this.type] = types[this.type] || [];
                types[this.type].push(this);
            });

            // flatten them back out
            // limit total number of items according to options, but do it proportionally for each type
            var scale = this.options.maxItems / items.length;
            items     = [];
            $.each(types, function (type, values) {
                items = $.merge(items, values.slice(0, Math.ceil(values.length * scale)));
            });

            // render
            var labels = {
                'user':    swarm.t('Users'),
                'group':   swarm.t('Groups'),
                'project': swarm.t('Projects')
            };

            var previousType, item, html = [];
            $.each(items, function(){
                item = $(typeahead.options.item).data('value', this).attr('data-label', this.label);
                item.find('a').html($this.highlighter(this.label));

                // add heading if new type (only if groups are enabled)
                if ($this.options.enableGroups && this.type !== previousType) {
                    previousType = this.type;
                    item.prepend($.templates(
                        '<span class="group-heading">{{te:label}}</span>'
                    ).render({label: labels[this.type]}));
                }

                html.push(item[0]);
            });

            menu.html(html);
            return typeahead;
        },

        makeUserItem: function (user) {
            user = $.isPlainObject(user) ? user : {User: user.valueOf()};
            var label = user.User;
            if (user.FullName && user.FullName !== user.User) {
                label += ' (' + user.FullName + ')';
            }
            return {id: user.User, type: 'user', label: label};
        },

        makeGroupItem: function (group) {
            group = $.isPlainObject(group) ? group : {Group: group.valueOf()};
            var isProject = group.Group.indexOf(this.projectPrefix) === 0;
            return {
                id:    group.Group,
                type:  isProject ? 'project' : 'group',
                label: isProject ? group.Group.substr(this.projectPrefix.length) : group.Group
            };
        }
    });

    // build the jquery plugin for the userMultiPicker
    $.fn.userMultiPicker = function(option) {
        return this.each(function() {
            var $this       = $(this),
                multiPicker = $this.data('user-multipicker');
            if (!multiPicker) {
                var options = $.extend({}, $this.data(), typeof option === 'object' && option);
                $this.data('user-multipicker', new UserMultiPicker(this, options));
            } else if (typeof option === 'string') {
                multiPicker[option]();
            }
        });
    };

    $.fn.userMultiPicker.Constructor = UserMultiPicker;
    $.fn.userMultiPicker.defaults    = $.extend({}, $.fn.multiPicker.defaults, {
        excludeUsers:         [],       // list of user ids to exclude from the typeahead
        excludeProjects:      [],       // list of project ids to exclude from the typeahead
        groupInputName:       null,     // name for the input element for selected group items
        enableGroups:         false,    // if true then groups will be included in available items
        maxItems:             20        // max number of items shown (proportionally divided between users/groups)
    });

    var sourceXhr;
    $.fn.userMultiPicker.Promise = function($this) {
        sourceXhr = sourceXhr || $.when(
            $.ajax('/users',  {data: {fields: ['User', 'FullName'], format: 'json'}}),
            $.ajax('/groups', {data: {fields: ['Group'], format: 'json'}})
        ).then(function(users, groups){
            var source = [];
            $.each(users[0], function(){
                source.push($this.makeUserItem(this));
            });
            $.each(groups[0].groups, function(){
                source.push($this.makeGroupItem(this));
            });
            return source;
        });

        return sourceXhr;
    };
}(window.jQuery));

(function($) {
    function buildParamObject(refObj, fullKey, value, traditional, add ) {
        var rbracketSplit = /([^\[\]\s]*)\[([^\[\]\s]*)\](.*)/i;

        // if item represents an array or object, we recursively build it
        // else the item is a simple property and we add it without recursion
        if (rbracketSplit.test(fullKey) && !traditional) {
            var split   = rbracketSplit.exec(fullKey),
                key     = split[1], nextKey = split[2], leftovers = split[3],
                isArray = !nextKey || $.isNumeric(nextKey);

            // create the current object if it does not exist
            var obj = refObj[key] = refObj[key] || (isArray ? [] : {});

            // if we are at the end of this recursive branch, add the value here
            // else recurse down some more
            if (isArray && !leftovers) {
                add(refObj, key, value);
            } else {
                buildParamObject(obj, nextKey + leftovers, value, traditional, add);
            }
        } else {
            // in traditional mode, simply having multiple values assigned indicates an array
            if (traditional && refObj[fullKey] !== undefined && !$.isArray(refObj[fullKey])) {
                refObj[fullKey] = [refObj[fullKey]];
            }
            add(refObj, fullKey, value);
        }
    }

    var parseValue = function(value) {
        switch(value) {
            case    "undefined": value = null; break;
            case    "null"     :
            case    ""         : value = null;      break;
            case    "true"     : value = true;      break;
            case    "false"    : value = false;     break;
            default            : value = $.isNumeric(value) ? +value : value;
        }

        return value;
    };

    // Deserialize a query string into a set of key/values
    $.deparam = function(query, parse, traditional) {
        var add = function(obj, key, value) {
            value = parse ? parseValue(value) : value || "";
            if ($.isArray(obj[key])) {
                obj[key].push(value);
            } else {
                obj[key] = value;
            }
        };

        // support the default traditional setting
        if (traditional === undefined) {
            traditional = $.ajaxSettings.traditional;
        }

        var items  = query.replace(/^\?/, '').replace(/\+/g, '%20').split('&'),
            values = {};

        var i, param, key, value;
        for (i = 0; i < items.length; i++) {
            param = items[i].split('=');
            key   = decodeURIComponent(param[0]);
            value = param.length > 1 ? decodeURIComponent(param[1]) : '';

            buildParamObject(values, key, value, traditional, add);
        }

        // Return the resulting deserialization
        return values;
    };
}(window.jQuery));

(function($) {
    // if within a couple of pixels of the bottom of the page consider user
    // scrolled to the bottom (we need to be a little forgiving for Chrome)
    $.isScrolledToBottom = function(viewport, content) {
        viewport = viewport || window;
        content  = content  || document;
        return $(viewport).scrollTop() + 2 >= $(content).height() - $(viewport).height();
    };
}(window.jQuery));
