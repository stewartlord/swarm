/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

/**
 * init.js
 *     default listeners and configuration for swarm
 */


$(function() {
    // prevent closing dropdowns that are being used as subforms when clicking within them
    $('body').on(
        'click.swarm.dropdown touchstart.swarm.dropdown',
        '.dropdown-subform',
        function (e) {
            e.stopPropagation();
        }
    );

    // update page on login
    $(document).on('swarm-login', function(e) {
        // reveal 'privileged' elements
        $('body').removeClass('anonymous').addClass('authenticated');

        // if user is admin, reveal 'admin-only' elements
        if (e.user.isAdmin) {
            $('body').removeClass('non-admin').addClass('admin');
        }

        // if user is allowed to add projects, reveal add-project restricted elements
        if (e.user.addProjectAllowed) {
            $('body').removeClass('cannot-add-project').addClass('can-add-project');
        }

        // insert user dropdown
        $('.navbar-fixed-top .user').replaceWith($(e.toolbar).find('.user'));

        // set user data on the body tag
        $('body').data('user', e.user);

        // set the csrf token
        $('body').data('csrf', e.csrf);
    });

    // blur textareas on escape
    $(document).on('keydown', 'textarea', function(e) {
        // ESC === keycode 27
        if (e.which === 27) {
            $(this).blur();
        }
    });

    // monitor form changes
    $(document).on('input.swarm.form change.swarm.form keyup.swarm.form blur.swarm.form', 'form', function() {
        swarm.form.checkInvalid(this);
    });

    // configure expander plugin:
    // explicitly style details to display inline so that the text flows nicely.
    // this requires setting expand speed to 0 to disable the show/hide animation.
    // also add/remove an expanded class as needed
    $.extend($.expander.defaults, {
        slicePoint: 150,

        // set reasonable expand/collapse text
        expandPrefix: '',
        expandText: '...',
        userCollapseText: '&laquo;',

        // defend against values which lack punctuation/whitespace
        widow: 0,
        preserveWords: false,

        // kill animation as it delayed application of display:inline
        // on expand/collapse also toggle expanded class
        expandSpeed: 0,
        collapseSpeed: 0,
        afterExpand: function() {
            $(this).find('.details').css({display: 'inline'});
            $(this).addClass('expanded');
        },
        onCollapse: function() {
            $(this).removeClass('expanded');
        }
    });

    // add url, urlc, class encoders to jsrender to allow escapement of
    // full urls and of url components
    $.views.converters({
        url: function (value) {
            return encodeURI(swarm.url(value));
        },
        urlc: function (value) {
            return encodeURIComponent(value);
        },
        'empty': function (value) {
            return $.isEmptyObject(value);
        },
        'class': function (value) {
            /*jslint regexp: true */
            value = value.replace(/[^_\-a-zA-Z0-9]/, '-');
            /*jslint regexp: false */

            return value;
        },
        'join': function (value) {
            return $.map(value, function(element){
                return $.views.converters.html(element);
            }).join(', ');
        }
    });

    // setup state and hash history handling
    swarm.history.init();
});

// detect partial history support and add History.state property
if (swarm.has.partialHistorySupport()) {
    swarm.history.patchPartialSuppport();
}

// if avatar images error out, set their src to be transparent so we can see the
// default background avatars.
$(document).on('img-error', 'img.avatar', function(e) {
    var $this = $(this);
    if (!$this.hasClass('default')) {
        $this.addClass('default');
        $this.attr('src', 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
});
// mark avatar images as loaded when complete
$(document).on('img-load', 'img.avatar', function(e) {
    $(this).addClass('loaded');
});

// set cache busting as default behaviour on ajax requests
$.ajaxSettings.cache = false;

// listen on all ajaxErrors
$.ajaxSettings.xhrFields = {
    'onerror': function(event) {
        var response = event.target,
            error    = swarm.ignoredAjaxError;

        // we normally ignore readystatechange errors with null/0 response status because they
        // can be canceled/aborted requests. In this case, as long as the browser doesn't report
        // user aborts as errors, we can make sure it is shown in the global ajaxError listener
        if (error && !response.status && !swarm.has.xhrUserAbortAsError()) {
            error.request.isNetworkError = true;
            $(document).trigger('ajaxError', [error.request, error.settings, error.message]);
        }
    },

    'ontimeout': function(event) {
        // just pipe timeout to onerror
        this.onerror(event);
    }
};
$(document).on('ajaxError', function(event, request, settings, message) {
    swarm.ignoredAjaxError = null;
    // ignore handled errors and aborted requests (indicated by status 0)
    if (settings.errorHandled || (!request.status && !request.isNetworkError)) {
        swarm.ignoredAjaxError = {request: request, settings: settings, message: message};
        return;
    }

    // 401's are a likely occurrence when the user's session expires,
    // lets be helpful instead of giving them an error
    if (request.status === 401) {
        swarm.user.login(swarm.t('Your session has expired.'));
        return;
    }

    // close any old notifications that have the same status
    $('.global-notification .alert').filter('.s' + request.status).alert('close');

    // Browser Network error messages don't include details at the XHR level, so
    // we need to craft our own generic message
    var status = request.status;
    if (request.isNetworkError) {
        message = "There was an issue with the Network";
        status  = "Error";
    }

    // create the new alert
    var alertHTML = $.templates('<div class="alert border-box alert-error s{{:status}}">'
        + '<button type="button" class="close" data-dismiss="alert">&times;</button>'
        + '<h4>{{te:status}}</h4>{{te:message}}'
        + '<div>').render({message: message, status: status});

    // wrap the alert in a div that the css can nicely position for us
    var notification = $('<div />', {'class': 'global-notification border-box'}).append(alertHTML).prependTo('body');

    // the alert will fire an event up the tree when
    // it is closed, remove our wrapper when it fires.
    notification.on('closed', function() {
        $(this).remove();
    });
});

// add a csrf token to all non-GET ajax requests that
// are using default data processing and contentType
$.ajaxPrefilter('*', function(settings) {
    var data = settings.data || '';
    if (settings.type === 'GET'
        || !settings.processData
        || typeof data !== "string"
        || settings.contentType !== $.ajaxSettings.contentType
        || !$('body').data('csrf')
    ) {
        return;
    }

    var csrfParam = /(&?)_csrf=[^&]*/,
        csrfToken = $('body').data('csrf');

    // If there is already a '_csrf' parameter, set its value
    // Otherwise add one to the end
    // note: the regex approach used here matches jquery's internal cachebuster param
    settings.data = csrfParam.test(data)
        ? data.replace(csrfParam, "$1_csrf=" + encodeURIComponent(csrfToken))
        : data + ( data.length ? "&" : "" ) + "_csrf=" + encodeURIComponent(csrfToken);
});

// update url for all ajax calls to ensure that base-url is prepended if necessary
$.ajaxPrefilter(function(options) {
    options.url = swarm.url(options.url);
});
