/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

// define our global variable
var swarm = {};

// wrapper around requestAnimationFrame to use a timeout if not present
// requestFrame is to make sure the user isn't blocked by js while performing
// actions like scrolling and resizing
swarm.requestFrame = function(callback) {
    // Use requestAnimationFrame in modern browsers
    // WebkitRequestAnimationFrame in Safari 6
    // and fallback to a timeout in older browsers: Safari 5.1.x and IE9
    if (window.requestAnimationFrame) {
        window.requestAnimationFrame(callback);
    } else if (window.webkitRequestAnimationFrame) {
        window.webkitRequestAnimationFrame(callback);
    } else {
        setTimeout(callback, 1000 / 60);
    }
};

swarm.encodeURIPath = function(path) {
    return encodeURIComponent(path).replace(/\%2F/g, "/");
};

swarm.encodeURIDepotPath = function(depotPath) {
    return swarm.encodeURIPath(depotPath.replace(/^\/*/, ''));
};

// prepend base-url if necessary (only if url starts with a slash and doesn't appear
// to already include base url)
swarm.url = function(url) {
    var baseUrl = $('body').data('base-url') || '';
    return url.charAt(0) === '/' && url.indexOf(baseUrl) !== 0 ? (baseUrl + url) : url;
};

// thin wrapper around local-storage to avoid
// errors if the browser does not support it.
swarm.localStorage = {
    set: function(key, value) {
        if (!swarm.localStorage.canStore()) {
            return null;
        }

        return window.localStorage.setItem(key, value);
    },

    get: function(key) {
        if (!swarm.localStorage.canStore()) {
            return null;
        }

        return window.localStorage.getItem(key);
    },

    canStore: function() {
        try {
            return window.localStorage !== undefined && window.localStorage !== null;
        } catch (e) {
            return false;
        }
    }
};

// Convient methods for using native browser querySelector for performance.
// Note that it these depend on a browser's support for the passed selector.
// So these functions should only be used when working with a lot of nodes.
swarm.query = {
    // returns the first matched jquery object, similar to using :first in
    // a $().find but much more performant.
    first: function(selector, element) {
        element = element || document;
        element = element instanceof window.jQuery ? element[0] : element;
        if (element && element.querySelector) {
            return $(element.querySelector(selector));
        }

        return $(selector, element).first();
    },

    // returns all matched jquery objects, just like a regular $().find
    // but much more performant because it uses the browser's native querySelectorAll
    // but also can't support as many selector types
    all: function(selector, element) {
        element = element || document;
        element = element instanceof window.jQuery ? element[0] : element;
        if (element && element.querySelectorAll) {
            return $(element.querySelectorAll(selector));
        }

        return $(selector, element);
    },

    // like jQuery.each but retains the performance of swarm.query.all
    // applies callback to select elements under given root nodes
    apply: function(selector, roots, callback) {
        var i;
        for (i = 0; i < roots.length; i++) {
            swarm.query.all(selector, roots[i]).each(callback);
        }
    }
};

swarm.history = {
    supported:   !!(window.history && window.history.pushState),
    isPageShow:  false,
    initialized: false,

    init: function() {
        // set active tab based on the current url
        // we do this even if history isn't supported, so links load the proper tabs
        swarm.history.switchTab();

        if (swarm.history.initialized) {
            return;
        }

        if (!swarm.history.supported) {
            // for browsers that don't support the history api, change the
            // current url to reflect the tab state when tabs are shown
            var defaultTab = location.hash ? '' : $('.nav-tabs').find('li.active > a[href]').attr('href');
            $(document).on('shown.swarm.tab', '[data-toggle="tab"]', function (e) {
                var href = $(this).attr('href');
                href     = href.substr(href.indexOf('#'));

                // only add history when the new location doesn't match the old hash, or
                // when the old hash is empty, and we are trying to hit the default tab
                if(href && href !== location.hash && !(href === defaultTab && !location.hash)) {
                    location.assign(location.pathname + location.search + href);
                }
            });

            // switch tabs based on hash when navigating history
            $(window).on('hashchange.swarm.history', function() {
                var tab = location.hash || defaultTab;
                swarm.history.switchTab(tab);
            });

            return;
        }

        // push new history state anytime the active tab changes
        $(document).on('shown.swarm.tab', '[data-toggle="tab"]', function (e) {
            var currentTab = window.history.state && window.history.state.tab,
                href       = $(this).attr('href');
            if(href && href !== currentTab) {
                swarm.history.pushState({tab: href}, null, href);
            }
        });

        // switch tabs based on state when navigating history
        swarm.history.onPopState(function(e) {
            e = e.originalEvent;
            if (e.state && e.state.tab && e.state.autoSwitchTab !== false) {
                swarm.history.switchTab(e.state.tab);
            }
        });

        // webkit/blink browser sometimes fire popstate right
        // after pageshow when there is no pending state
        $(window).on('pageshow', function() {
            swarm.history.isPageShow = true;

            // if the browser was going to fire popstate, it would do so right
            // away. It should be safe to reset the pending state
            setTimeout(function() {
                swarm.history.isPageShow = false;
            }, 0);
        });

        // global state defaults for swarm
        $(window).on('beforeSetState', function(e, defaults) {
            $.extend(defaults, {
                tab: $('.nav-tabs').find('li.active > a[href]').attr('href'),
                route: swarm.history.getPageRoute()
            });
        });

        // flag history as initialized and call doStateUpdate to add our default state to the history
        swarm.history.initialized = true;
        swarm.history.doStateUpdate();
    },

    replaceState: function(state, title, url) {
        if (!swarm.history.supported) {
            return;
        }

        var defaults = {};
        $(window).triggerHandler('beforeSetState', [defaults, 'replace']);
        state = $.extend(defaults, state);
        window.history.replaceState(state, title, url);
    },

    pushState: function (state, title, url) {
        if (!swarm.history.supported) {
            return;
        }

        var defaults = {};
        $(window).triggerHandler('beforeSetState', [defaults, 'push']);
        state = $.extend(defaults, state);
        window.history.pushState(state, title, url);
    },

    clearState: function() {
        if (swarm.history.supported) {
            window.history.replaceState(null, null, null);
        }
    },

    doStateUpdate: function() {
        // tickle updating the current state with defaults
        // use this function when you want your default state functions to be
        // re-run and applied to the current state
        if (swarm.history.supported && swarm.history.initialized) {
            swarm.history.replaceState(null, null, null);
        }
    },

    onPopState: function(listener) {
        if (!swarm.history.supported) {
            return;
        }
        $(window).on('popstate', function(e) {
            // some browsers fire a pop when the page first
            // loads which we want to ignore.
            if (swarm.history.isPageShow) {
                return;
            }

            listener.apply(this, arguments);
        });
    },

    getPageRoute: function() {
        var routeMatch = $('body')[0].className.match(/\broute\-(\S+)/i);
        return routeMatch && routeMatch[1];
    },

    switchTab: function(tab) {
        var hash = (
            tab || window.location.hash || $('.nav-tabs').find('li.active > a[href]').attr('href') || ''
        ).replace(/^#/, '');

        // early exit if we don't have a hash, or the hash doesn't match a tab, or the tab is already active
        if (!hash || !$('.nav-tabs a[href="#' + hash + '"]').length
                || $('.nav-tabs li.active a[href="#' + hash + '"]').length) {
            return;
        }

        var element = $('#' + hash + '.fade'),
            active  = element.parent().find('> .fade.active');

        // disable animation
        element.removeClass('fade');
        active.removeClass('fade');

        // show the tab then enable animation
        $('.nav-tabs a[href="#' + hash + '"]').one('shown', function() {
            active.addClass('fade');
            element.addClass('in fade');
        }).tab('show');
    },

    patchPartialSuppport: function() {
        // Add window.history.state property for browsers that support the api,
        // but didn't include access to the current state
        if (!swarm.history.supported || !swarm.has.partialHistorySupport()) {
            return;
        }

        var oldPushState    = window.history.pushState,
            oldReplaceState = window.history.replaceState,
            oldPopState     = window.onpopstate;

        window.history.state = null;
        window.history.pushState = function(state, title, url) {
            window.history.state = state;
            return oldPushState.call(window.history, state, title, url);
        };
        window.history.replaceState = function(state, title, url) {
            window.history.state = state;
            return oldReplaceState.call(window.history, state, title, url);
        };
        window.onpopstate = function(e) {
             window.history.state = e.state;
             if (oldPopState) {
                oldPopState.call(window, e);
             }
        };
    }
};

swarm.modal = {
    show: function(modal) {
        var getMarginLeft =  function() {
            // read the computed margin and adjust if necessary
            return (parseInt($(this).css('marginLeft'), 10) < 0) ? -($(this).width() / 2) : 0;
        };

        // bring in the modal and set it's position
        $(modal).css({width: 'auto', marginLeft: ''}).modal('show').css({marginLeft: getMarginLeft});

        // add resize listener if it doesn't already have one
        if (!$(modal).data('resize-swarm-modal')) {
            var resize = $(window).on('resize', function() {
                $(modal).css('marginLeft', '');
                $(modal).css({marginLeft: getMarginLeft});
            });
            $(modal).data('resize-swarm-modal', resize);
        }
    }
};

swarm.tooltip = {
    showConfirm: function (element, options) {
        // render the popover and show it
        $(element).popover($.extend(
            {
                trigger:   'manual',
                container: 'body',
                placement: 'top'
            },
            options,
            {
                html: true,
                content:
                      '<div class="pad2 padw0 content">'
                    +   (options.content || '')
                    + '</div>'
                    + '<div class="buttons center pad2 padw0">'
                    +   (options.buttons.length ? options.buttons.join('') : '')
                    + '</div>',
                template:
                      '<div class="popover popover-confirm" tabindex="0">'
                    + ' <div class="arrow"></div>'
                    + ' <div class="popover-content center"></div>'
                    + '</div>'
            }
        )).popover('show');

        // set focus on the tooltip
        $(element).data('popover').tip().focus();

        // return the popover object
        return $(element).data('popover');
    }
};

swarm.form = {
    checkInvalid: function(form) {
        var numInvalid = $(form).find('.invalid').length;

        // use the :invalid selector on modern browsers to take advantage of
        // using rules other than just 'required', if :invalid is not available,
        // we will fallback to just looking at required fields
        try {
            numInvalid += $(form).find(':invalid').length;
        } catch (e) {
            numInvalid += $(form).find('[required]:enabled').filter(function() {
                return $(this).val() ? false : true;
            }).length;
        }

        $(form).toggleClass('invalid', numInvalid > 0);
        $(form).find('[type="submit"]').not('.loading').prop('disabled', !!numInvalid);
    },

    post: function(url, form, callback, errorNode, prepareData) {
        var triggerNode = $(form).find('[type="submit"]');
        swarm.form.disableButton(triggerNode);
        swarm.form.clearErrors(form);

        $.ajax(url, {
            type: 'POST',
            data: prepareData ? prepareData(form) : $(form).serialize(),
            complete: function(jqXhr, status) {
                swarm.form.enableButton(triggerNode);

                // disable triggers on invalid forms
                if ($(form).is('.invalid')) {
                    triggerNode.prop('disabled', true);
                }

                // call postHandler for successful resquests
                if (status === 'success' && jqXhr.responseText) {
                    var response = JSON.parse(jqXhr.responseText);
                    swarm.form.postHandler.apply(this, [form, callback, errorNode, response]);
                }
            }
        });
    },

    postHandler: function(form, callback, errorNode, response) {
        form = $(form);
        if (response.isValid === false) {
            var errors   = response.error ? [response.error] : [],
                event    = $.Event('form-errors');
            event.action = 'error';

            $.each(response.messages || [], function(key, value) {
                var element     = swarm.form.getElement(form, key),
                    controls    = element.closest('.controls'),
                    group       = controls.closest('.control-group');

                group.addClass('error');

                // clear errors on focus
                group.one('focusin.swarm.form.error', function() {
                    swarm.form.clearErrors(this);
                });

                $.each(value, function(errorId, message) {
                    // show the message or add it to a general errors if we can't
                    // locate the corresponding form element to attach it to
                    if (!controls.length) {
                        errors.push(message);
                    } else {
                       $('<span />').text(message).addClass('help-block help-error').appendTo(controls);
                       return false;
                    }
                });
            });

            // show error message and other remaining form error messages
            if (errors.length) {
                errorNode = (errorNode && $(errorNode)) || form;
                errorNode.prepend(
                    $.templates(
                        '<div class="alert">{{for errors}}<div>{{>#data}}</div>{{/for}}</div>'
                    ).render({errors: errors})
                );
                form.one('focusin.swarm.form.error', function(){
                    errorNode.find('> .alert').remove();
                });
            }

            form.trigger(event);
        } else if (response.redirect) {
            // if we are redirecting, ensure the button is disabled
            // we don't want the user posting multiple times
            swarm.form.disableButton($(form).find('[type="submit"]'));
            window.location = swarm.url(response.redirect);
        }

        if (callback) {
            callback(response, form);
        }
    },

    getElement: function(form, key) {
        return $(form).find('[name="' + key + '"], [name^="'+key+'["]');
    },

    // takes a form or an input and clears the error markup
    clearErrors: function(element, silent) {
        element = $(element);
        if (!element.is('form')) {
            element = element.closest('.control-group.error');
        }

        element.find('.alert').remove();
        element.find('.help-error').remove();
        element.find('.control-group.error').removeClass('error');
        element.removeClass('error');

        if(!silent) {
            var event    = $.Event('form-errors');
            event.action = 'clear';
            element.trigger(event);
        }
    },

    disableButton: function(button) {
        $(button).prop('disabled', true).addClass('loading');
        var animation = setTimeout(function(){
            $(button).addClass('animate');
        }, 500);
        $(button).data('animationTimeout', animation);
    },

    enableButton: function(button) {
        $(button).prop('disabled', false).removeClass('loading animate');
        clearTimeout($(button).data('animationTimeout'));
    }
};

swarm.about = {
    show: function() {
        if ($('.about-dialog.modal').length) {
            swarm.modal.show('.about-dialog.modal');
            return;
        }

        $.ajax({url: '/about', data: {format: 'partial'}}).done(function(data) {
            $('body').append(data);
            $('.about-dialog .token').click(function(){ $(this).select(); });
        });
    }
};

swarm.info = {
    init: function() {
        // load latest log entries in log tab
        swarm.info.refreshLog();

        // resize iframe on load
        $('iframe').load(function(){swarm.info.resizeIframe(this);});
        $('a[data-toggle="tab"]').on('shown', function(){
            var href = $(this).attr('href');

            // resize the iframe when phpinfo tab is shown (bound to click)
            if (href === '#phpinfo') {
                swarm.info.resizeIframe($('iframe')[0]);
            }

            // hide refresh/download buttons on all tabs but the swarm log
            $('.nav-tabs .btn-group').toggleClass('hidden', href !== '#log');
        });
        $('.btn-refresh').click(function(e) {
            e.preventDefault();
            swarm.info.refreshLog();
        });

        // show download/refresh buttons if the swarm log tab is already open
        if ($('.nav-tabs .active a[href="#log"]').length) {
            $('.nav-tabs .btn-group').removeClass('hidden');
        }
    },

    refreshLog: function() {
        // remove the existing data, and replace with a loading indicator
        var tbody = $('.swarmlog-latest tbody');
        tbody.empty();
        tbody.append(
            '<tr class="loading"><td colspan="3">'
                +  '<span class="loading animate">' + swarm.te('Loading...') + '</span>'
                + '</td></tr>'
        );
        $.ajax({url: '/info/log', data: {format: 'partial'}}).done(function(data) {
            $('.swarmlog-latest tbody').html(data);
            $('.timeago').timeago();

            // hide/show for entry details, and change chevron icon to point right/down
            $('tr.has-details').click(function(e){
                var details = $(this).next('tr.entry-details');
                $(this).find('.icon-chevron-right').toggleClass('icon-chevron-down');
                details.toggle();
            });
        });
    },

    resizeIframe: function(e) {
        e.style.height = e.contentWindow.document.body.offsetHeight + 'px';
    }
};

swarm.has = {
    nonStandardResizeControl: function() {
        // webkit uses a custom resize control that eats events
        return !!navigator.userAgent.match(/webkit/i);
    },

    historyAnimation: function() {
        // Safari animates history navigation when using gestures
        return !!(navigator.userAgent.match(/safari/i) && !navigator.userAgent.match(/chrome/i));
    },

    fullFileApi: function() {
        // Sane browsers will offer full support for FormData and File APIs
        return  !(window.FormData === undefined || window.File === undefined);
    },

    _cssCalcSupport: null,
    cssCalcSupport: function() {
        if (swarm.has._cssCalcSupport === null) {
            var testDiv = $(
                '<div />',
                { css: { position: 'absolute', top: '-9999px', left: '-9999px', width: 'calc(10px + 5px)' } }
            ).appendTo('body');
            swarm.has._cssCalcSupport = testDiv[0].offsetWidth !== 0;
            testDiv.remove();
        }

        return swarm.has._cssCalcSupport;
    },

    partialHistorySupport: function() {
        // Safari 5.1.x/Phantom JS support the history API, but don't support accessing the current state
        return !!(window.history && window.history.state === undefined);
    },

    xhrUserAbortAsError: function() {
        // Firefox considers user xhr aborts to be the same as network errors
        return  !!(navigator.userAgent.match(/gecko/i)
            && navigator.userAgent.match(/firefox/i)
            && !navigator.userAgent.match(/webkit/i)
            && !navigator.userAgent.match(/trident/i)
        );
    }
};

swarm.bees = {
    origin:  null,
    sprites: [],
    scale:   1,

    init: function(x, y){
        // setup origin element for the swarm
        swarm.bees.origin = $('<div class="bees-origin"></div>');
        swarm.bees.origin.appendTo('body');
        swarm.bees.origin.css({
            position: 'fixed',
            left:     x + 'px',
            top:      y + 'px',
            pointerEvents: 'none'
        });

        // track mouse - we do this in a gated timeout to normalize movement across browsers/platforms
        var moveTimeout = null;
        $('body').mousemove(function(e){
            if (!moveTimeout) {
                moveTimeout = setTimeout(function(){
                    setTimeout(function(){
                        swarm.bees.origin.css({top: (e.pageY - 50) + 'px', left: (e.pageX - 50) + 'px'});
                    }, 100);

                    // periodically make more bees (1 in 4 movements) max of 15
                    if (swarm.bees.sprites.length < 15 && Math.random() < 0.25) {
                        swarm.bees.makeBee();
                    }
                    moveTimeout = null;
                }, 10);
            }
        });

        swarm.bees.makeBee();

        // kill off 1 in 5 bees every second
        setInterval(function(){
            var i;
            for (i = 0; swarm.bees.sprites.length && i < Math.round(swarm.bees.sprites.length/5) + 1; i++) {
                swarm.bees.killBee(Math.floor(Math.random() * swarm.bees.sprites.length));
            }
        }, 1000);
    },

    makeBee: function(){
        var bee = $('<div class="little-bee"><img src="' + swarm.url('/swarm/img/errors/little-bee.png') + '"></div>');
        swarm.bees.randomize(bee);
        swarm.bees.sprites.push(bee);
        bee.appendTo(swarm.bees.origin);
    },

    killBee: function(i){
        var bee = swarm.bees.sprites[i];
        if (bee) {
            bee.fadeOut(500, function(){
                $(this).remove();
            });
            swarm.bees.sprites.splice(i, 1);
        }
    },

    randomize: function(bee){
        // randomize size
        bee.find('img').css({
            width: Math.floor(Math.random() * 30) + 5
        });

        // randomize direction, speed, offset and radius
        var w = Math.floor(Math.random() * 100) + 50,
            h = Math.floor(Math.random() * 100) + 50;
        bee.removeClass('clockwise counter-clockwise');
        bee.addClass(Math.random() < 0.5 ? 'clockwise' : 'counter-clockwise');
        bee.addClass(Math.random() < 0.5 ? 'fast'      : 'slow');
        bee.css({
            width:  w + 'px',
            height: h + 'px'
        });
    }
};

$(function(){
    // add bees to cursor on error pages - requires transition/animation support
    if ($.support.transition) {
        $('body.error').one('mousemove', function(e){
            swarm.bees.init(e.pageX, e.pageY);
        });
    }

    // check for running workers when viewing the home page
    // if no workers are running, insert a warning
    if ($('body').is('.route-home.authenticated')) {
        $.getJSON(swarm.url('/queue/status'), function(data) {
            if (data.workers === 0) {
                $('body > .container-fluid').first().prepend(
                    '<div class="alert alert-error center">' +
                        swarm.te('Hmm... no queue workers? Ask your administrator to check the') + ' <a href="' +
                        swarm.url('/docs/setup.worker.html') + '">' + swarm.te("worker setup") + '</a>.' +
                    '</div>'
                );
            }
        });
    }
});
