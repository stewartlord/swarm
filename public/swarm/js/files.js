/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

swarm.browse = {
    _pathCache:    [{}, {}],
    _pushingState: false,
    _showDeleted:  false,

    init: function() {
        // only run init once
        if ($('body').data('browse-initialized')) {
            return;
        }
        $('body').data('browse-initialized', true);

        // update timeago fields
        $('.timeago').timeago();

        // run prettyPrint if we have any prettyprint elements
        if ($('.prettyprint').length) {
            swarm.browse.prettify();
        }

        // initialize change history tab
        swarm.browse.initHistory();

        // run expander plugin on file history
        $('.file-history .description').expander({slicePoint: 90});

        // setup tab filters
        swarm.browse.handleTabChange();

        // setup page based on the current hash
        // move there early in case the user doesn't have a saved scroll position
        swarm.browse.handleLineHash();

        // modern browsers are going to try to reset to the last position onload
        // in order to account for images, whereas our current code is running earlier
        // in DOMContentLoaded. Run handleHash again on window load.
        $(window).one('load', function(e) {
            // Chrome's scroll load handler is run last, make ourselves run after it.
            // see: http://crbug.com/61674
            setTimeout(function() {
                swarm.browse.handleLineHash();
            }, 0);
        });

        // enable ajax history handling
        if (swarm.history.supported) {
            // take over navigation, instead we ajax load and animate
            $('body').on(
                'click.browse-files',
                '.browse-files .dir a, .browse-files .file a, .breadcrumb li a',
                function(e) {
                    if (!e.ctrlKey && !e.metaKey) {
                        swarm.browse.openPath(this.href, false, $(this).closest('.file').length);
                        e.preventDefault();
                    }
                }
            );

            // add url information to the state, this allows us to request the proper path
            // information in the case of the request being forwarded, /changes for instance.
            // also turn off autoTabSwitching, we need to call it ourselves after each ajaxLoad
            $(window).on('beforeSetState', function(e, defaults) {
                $.extend(defaults, {
                    browseUrl:     $('.breadcrumb').data('url'),
                    isFile:        $('.browse-title .rev').length,
                    autoSwitchTab: false
                });
            });
            swarm.history.doStateUpdate();

            swarm.history.onPopState(function(event) {
                // early exit if browseUrl didn't make it into state (if the ajax animation failed for instance)
                var state = event.originalEvent.state || {};
                if (!state.browseUrl) {
                    return location.reload();
                }

                swarm.browse.openPath(state.browseUrl, true, state.isFile);
            });
        }

        // update buttons and toolbars on tab change
        $(document).on(
            'show.browse-content',
            '.browse-content .nav-tabs a[data-toggle="tab"]',
            swarm.browse.handleTabChange
        );
    },

    // this is run after the path response has been handled AND the animation is complete
    pathLoaded: function(fromHistory) {
        // handle line hash navigation
        swarm.browse.handleLineHash();

        // (re)initialize history
        swarm.browse.initHistory();

        $('.deleted-files button').toggleClass('active', swarm.browse._showDeleted);

        // if we are navigating history, make sure we show the proper tab
        // else update the current state to match the elements on the page
        if (fromHistory) {
            swarm.history.switchTab(window.history.state.tab);
        } else {
            swarm.history.doStateUpdate();
        }

        // update buttons and toolbars to match the current tab
        swarm.browse.handleTabChange();
    },

    openPath: function(path, isHistory, isFile) {
        // normalize path (trim trailing slashes and #hash)
        path = path.replace(/\/$/, '').replace(/\#.*$/, '');

        // determine if we should animate left or right based on the depth
        // of the path compared against our current location
        var currentPath  = $('.breadcrumb').data('path'),
            currentDepth = currentPath.match(/\//g).length,
            newPath      = path.substring(path.indexOf('/files')),
            newDepth     = newPath.match(/\//g).length,
            type         = newDepth - currentDepth < 0 ? 'prev' : 'next',
            direction    = type === 'next' ? 'left' : 'right';

        // don't animate into ourself
        // note that we have to call switchTab here,
        // because we have turned off autoSwitchTab in our state
        if (currentPath === newPath) {
            return isHistory && swarm.history.switchTab(window.history.state.tab);
        }

        // push state early...
        if (!isHistory) {
            swarm.history.pushState({isFile: isFile}, null, path);
        }

        // cache data for the current path if it needs an update
        if (!swarm.browse.getCached(currentPath)) {
            swarm.browse.setCached(currentPath);
        }

        // update title optimistically and create a loading pane to animate in
        var title   = newPath.lastIndexOf('/') > 0 ? newPath.substr(newPath.lastIndexOf('/') + 1) : '//';
        var newItem = $('<div />', {'class': 'item ' + type}).appendTo('.browse-files-carousel');
        var loading = swarm.browse.getLoadingPane(newPath, isFile);
        $('.browse-title').text(title);
        newItem.append(loading);

        // remove any other active items that have animated in
        // and set the new item as active
        var animationComplete = function() {
            $('.browse-files-carousel .item.active').remove();
            newItem.removeClass(type + ' ' + direction).addClass('active');
            $('.browse-files-carousel').height('');
            $('body').css('overflowY', '');

            // allow elements to bleed out now that animation is done
            $('.browse-files-carousel').css('overflow', '');

            // run pathLoaded if the content is already loaded
            // else it will be called in handleResponse
            if (!newItem.find('.loading').length) {
                swarm.browse.pathLoaded(isHistory);
            }
        };

        if ($.support.transition && !(isHistory && swarm.has.historyAnimation())) {
            // clip elements when animating
            $('.browse-files-carousel').css('overflow', 'hidden');

            // set the height to be the viewport size
            $('body').css('overflowY', 'hidden');
            $('.browse-files-carousel').height($(window).height());

            // trigger transition
            $('.browse-files-carousel .item.active').add(newItem).addClass(direction);
            newItem.one($.support.transition.end, animationComplete);
        } else {
            animationComplete();
        }

        var cached = swarm.browse.getCached(newPath);
        if (cached) {
            swarm.browse.handlePathResponse(cached.data, newItem, isHistory,  cached.inputs, true);
        } else {
            $.ajax({
                url:     path,
                data:    {format: 'partial', showDeleted: swarm.browse._showDeleted || undefined},
                success: function(response) {
                    swarm.browse.handlePathResponse(response, newItem, isHistory);
                },
                error:   function(response) {
                    // show full error page if we received 403: Forbidden
                    if (response.status === 403) {
                        this.errorHandled = true;

                        // provide error message shown in the file browser
                        newItem.html($(
                              '<div class="browse-content">'
                            + ' <div class="alert">'+swarm.te("You don't have permission to view this file.")+'</div>'
                            + '</div>'
                        ));

                        if (newItem.is('.active')) {
                            swarm.browse.pathLoaded();
                        }

                        // update breadcrumbs path and head title
                        $('.breadcrumb').data('path', path);
                        $('head title').html(
                            $('head title').html().split('-').shift() + ' - ' + swarm.te('Not Allowed')
                        );
                    }
                }
            });
        }
    },

    handlePathResponse: function(response, pane, fromHistory, inputs, fromCache) {
        // nothing to do if the item has already been removed
        if (pane[0].parentNode === null) {
            return;
        }

        // parse the response as HTML and grab the nodes to update
        var responseNodes   = $('<div />').append($.parseHTML(response, document, true)),
            headTitle       = responseNodes.find('title'),
            title           = responseNodes.find('.browse-title'),
            crumbs          = responseNodes.find('.breadcrumb'),
            source          = responseNodes.find('.browse-content');

        pane.html(source);

        // restore form input values
        $.each(inputs || [], function() {
            pane.find('input[name=' + this.name + ']').val(this.value);
        });

        // update title and breadcrumbs
        $('.browse-title').replaceWith(title);
        $('.breadcrumb').replaceWith(crumbs);
        $('head title').html(
            $('head title').html().split('-').shift() + ' - ' + headTitle.html()
        );

        // apply prettyPrint and expander to pages not pulled from cache
        if (!fromCache) {
            swarm.browse.prettify();
            $('.file-history .description').expander({slicePoint: 90});
        }

        $('.timeago').timeago();

        // run pathLoaded here if the animation is already complete
        // otherwise it will be called in the animationComplete function
        if (pane.is('.active')) {
            swarm.browse.pathLoaded(fromHistory);
        }
    },

    getCached: function(path) {
        var cache   = swarm.browse._pathCache[swarm.browse._showDeleted ? 1 : 0],
            expired = new Date().getTime() - (10 * 60 * 1000);

        return cache.hasOwnProperty(path) && cache[path].time > expired && cache[path];
    },

    setCached: function(path, deleted) {
        deleted     = deleted !== undefined ? deleted : swarm.browse._showDeleted;
        var cache   = swarm.browse._pathCache[deleted ? 1 : 0];
        cache[path] = {
            time:   new Date().getTime(),
            data:   $('title')[0].outerHTML + $('body').html(),
            inputs: $('input').serializeArray()
        };
    },

    initHistory: function() {
        var path           = $('.breadcrumb').data('path'),
            userInput      = $('.browse-content .user-filter input'),
            rangeInput     = $('.browse-content .range-filter input'),
            target         = $('#commits');

        // remove '<base-url>/files' from the beginning of the path to make it suitable for loading changes
        var prefix = swarm.url('/files');
        if (path.indexOf(prefix) === 0) {
            path = path.slice(prefix.length);
        }

        swarm.changes.init(
            path,
            target,
            {user: userInput, range: rangeInput}
        );

        // reload history when user-filter or range-filter values change
        // clear existing listener first to avoid connecting multiple times
        var events = 'input.history-filters keyup.history-filters blur.history-filters';
        userInput.add(rangeInput).off(events).on(
            events,
            function(){
                clearTimeout(swarm.changes.filterTimeout);
                swarm.changes.filterTimeout = setTimeout(function(){
                    swarm.changes.load(path, target, { user: userInput.val(), reset: true, range: rangeInput.val() });
                }, 500);
            }
        );

        // toggle range filter help when user clicks on it
        rangeInput.off('click.range-filter').on('click.range-filter', function(e) {
            e.stopPropagation();
            if (!rangeInput.closest('.range-filter').find('.popover').length) {
                rangeInput.data('popover').show();
            }
        });

        // close the popover when user clicks outside
        $(document).off('click.range-filter').on('click.range-filter', function(e) {
            if (rangeInput.length && e.which === 1) {
                rangeInput.data('popover').hide();
            }
        });

        // let user click on the help popover without making it disappear
        $(document).off('click.range-filter-popover').on('click.range-filter-popover', '.range-filter .popover', function (e) {
            e.stopPropagation();
        });

        // add help popover for range filter details
        if (rangeInput.length) {
            rangeInput.popover({container: '.range-filter', trigger: 'manual'});
        }
    },

    toggleAnnotations: function(button) {
        button = $(button);

        // toggle state
        var active = button.is('.active');
        button.toggleClass('active');
        $('.view-text ol').toggleClass('annotated');

        // if already showing, hide them and exit.
        if (active) {
            $('.view-text ol li .annotation').hide();
            return;
        }

        // if annotations are already loaded, show them and exit.
        if ($('.view-text .annotation').length) {
            $('.view-text ol li .annotation').show();
            return;
        }

        // build placeholder annotations to give the user immediate feedback
        $('.view-text ol li').each(function(){
            $(this).prepend('<span class="annotation">&nbsp;</span>');
        });

        // fetch annotated content
        $.ajax({
            url:        window.location,
            data:       {annotate: true},
            dataType:   'json',
            success:    function(data) {
                var i     = 0,
                    last  = null,
                    lines = data.annotate;

                // there don't appear to be any lines in this file
                if (lines.length === 0) {
                    return;
                }

                var userTemplate    = $.templates(
                        '<a href="' + $.views.converters.url("/changes/") + '{{:id}}">'
                        + '<span class="text">{{>change.user}}</span></a>'
                    ),
                    tooltipTemplate = $.templates(
                        '<p class="monospace">{{:change.desc}}</p>' +
                        '<span class="timeago muted" title="{{>change.time}}"></span>'
                    );
                $('.view-text').on('mouseenter', '.annotation a', function () {
                    var $this  = $(this),
                        id     = $this.attr('href').match(/changes\/([0-9]+)/)[1],
                        change = data.changes[id];

                    $this.popover({
                        title:      swarm.te('Change') + ' ' + id,
                        animation:  false,
                        trigger:    'hover',
                        html:       true,
                        content:    $(tooltipTemplate.render({change: change}))
                    }).popover('show');

                    $this.data('popover').tip().find('span.timeago').timeago();
                });

                $('.view-text ol li .annotation').each(function(){
                    var $this  = $(this),
                        line   = lines[i],
                        id     = line.lower,
                        change = data.changes[id],
                        repeat = id === last;

                    // insert author as a link to change
                    $this.html(userTemplate.render({id: encodeURIComponent(id), change: change}));

                    // if the same change appears consecutively, diminish it
                    $this.addClass(repeat ? 'repeat' : '');

                    last = id;
                    i++;
                });
            }
        });
    },

    getShortLink: function(button) {
        button = $(button);
        button.toggleClass('active');

        // hide and disable the tooltip so it won't overlap the popover
        // or, re-enable it if button is now inactive
        if (button.is('.active')) {
            button.tooltip('disable');
            button.tooltip('hide');
        } else {
            button.tooltip('enable');
        }

        // if we have already made the popover, nothing more to do!
        if (button.data('popover')) {
            return;
        }

        // prepare url for shortlink - strip base url from uri pathname as shortLink
        // will add it when qualifying url
        var pathName = window.location.pathname,
            baseUrl  = swarm.url('/');
        if (pathName.indexOf(baseUrl) === 0) {
            // we want pathname to contain leading slash after stripping base url,
            // thus we remove base url without the last character (which is always a slash)
            pathName = pathName.slice(baseUrl.length - 1);
        }

        // request a short-link
        $.post(
            '/l',
            {uri: pathName + window.location.search},
            function(data) {
                // how to copy varies by platform
                var hint = 'Press CTRL-C';
                if (navigator.userAgent.indexOf('Mobile') !== -1) {
                    hint = 'Long-press';
                } else if (navigator.userAgent.indexOf('Mac') !== -1) {
                    hint = 'Press &#8984;-C';
                }

                // prepare popover contents
                var content = $.templates(
                    '<div class="short-link">'
                  +  '<input class="center" type="text" value="{{>uri}}" size="{{>size}}" readonly><br>'
                  +  '<span class="muted"><small>{{t:hint}} {{te:"to copy"}}</small></span>'
                  + '</div>'
                ).render({uri: data.uri, hint: hint, size: data.uri.length});

                button.popover({
                    html:        true,
                    content:     content,
                    placement:   'top',
                    container:   'body',
                    customClass: 'short-link-popover'
                });

                // auto-select short url when shown or clicked
                var tip = button.data('popover').tip();
                button.popover().on('shown', function(){
                    tip.find('input').select();
                    tip.find('input').on('click', function(){
                        $(this).select();
                    });
                });

                // the popover will be orphaned when the user switches tabs or navigates
                // to prevent this, we connect to push/pop state and tab shown to hide it
                var close = function(){
                    button.removeClass('active').popover('hide');
                };
                swarm.history.onPopState(close);
                $(window).on('beforeSetState', close);
                $(document).on('show.swarm.tab', '[data-toggle="tab"]', close);

                // close the popover automatically if user clicks outside of it
                $(document).on('click', function(e) {
                    if (!tip.is(e.target)
                        && tip.has(e.target).length === 0
                        && !button.is(e.target)
                        && button.has(e.target).length === 0
                    ) {
                        close();
                    }
                });

                button.popover('show');
           },
            'json'
        );
    },

    getArchive: function(link) {
        link = $(link);
        link.toggleClass('active');

        // cancel download when link is toggled off
        if (!link.is('.active')) {
            swarm.browse.cancelArchiveDownload(link);
            return;
        }

        // init archive popover if the link doesn't already have one
        if (!link.data('popover')) {
            swarm.browse.initArchivePopover(link);
        }

        link.popover('show');

        // make a request to build archive in the background and start polling the status
        $.ajax({
            url:     link.attr('href'),
            data:    {background: true, format: 'json'},
            success: function(response) {
                swarm.browse.pollForArchive(response.digest, link);
            },
            error: function(response, status, error) {
                swarm.browse.updateArchiveStatus(link, {error: error});
                this.errorHandled = true;
            }
        });
    },

    initArchivePopover: function(link) {
        link.popover({
            trigger:   'manual',
            container: '.browse-content',
            html:      true,
            placement: 'top',
            content:
                  '<div class="pad1 padw0 content archive-status">'
                +   '<div class="close">&times;</div>'
                +   '<div class="progress active">'
                +     '<div class="bar underlay">'
                +       '<span class="padw2">' + swarm.te('Initializing...') + '</span>'
                +     '</div>'
                +     '<div class="bar">'
                +       '<span class="padw2">' + swarm.te('Initializing...') + '</span>'
                +     '</div>'
                +   '</div>'
                + '</div>'
        });

        // cancel/hide download if user clicks on close button or outside of the link
        var cancel = function(){ swarm.browse.cancelArchiveDownload(link); };
        $(document).on('click', function(e) {
            var isOutsideClick = !link.data('popover').tip().is(e.target)
                && link.data('popover').tip().has(e.target).length === 0
                && !link.is(e.target)
                && link.has(e.target).length === 0;

            if (isOutsideClick || $(e.target).is('.archive-status .close')) {
                cancel();
            }
        });

        // cancel/hide download if user navigates away
        swarm.history.onPopState(cancel);
        $(window).on('beforeSetState', cancel);
        $(document).on('show.swarm.tab', '[data-toggle="tab"]', cancel);
    },

    updateArchiveStatus: function(link, response) {
        var tip = link.data('popover') ? link.data('popover').tip() : null;
        if (!tip) {
            return;
        }

        // there are three distinct cases:
        // 1. in progress, either syncing or compressing
        // 2. done and successful
        // 3. done, but an error occurred
        var progress, message, cssClass;
        if (!response.success && !response.error) {
            progress = parseInt(response.progress, 10) || 0;
            message  = response.phase === 'compress' ? swarm.t('Compressing...') : swarm.t('Syncing...');
        } else if (response.success) {
            progress = 100;
            message  = swarm.t('Downloading...');
            cssClass = 'progress-success';

            // close the popover after 3 seconds delay
            setTimeout(function(){
                link.removeClass('active').popover('hide');
            }, 3000);
        } else {
            progress = 100;
            message  = swarm.t('Error')+': ' + swarm.t(response.error) || swarm.t('Unknown');
            cssClass = 'progress-danger';
        }

        tip.find('.progress .bar').css('width', progress + '%');
        tip.find('.progress .bar span').text(message);
        tip.find('.progress').removeClass('progress-success progress-danger').addClass(cssClass);

        // if its error, convert link to danger
        link.removeClass('btn-danger').find('i').attr('class', 'icon-briefcase');
        if (response.error) {
            link.addClass('btn-danger').find('i').attr('class', 'icon-warning-sign icon-white');
        }
    },

    cancelArchiveDownload: function(link) {
        // close the popover and stop polling the status update and unset the _archivePolling
        // flag to indicate that downloading was cancelled
        link.removeClass('active').popover('hide');
        if (swarm.browse._archivePolling) {
            window.clearTimeout(swarm.browse._archivePolling);
            swarm.browse._archivePolling = null;
        }
    },

    // polls the archive status and updates the presentation
    // depending on the response, we either keep polling or download the archive
    _archivePolling: null,
    pollForArchive: function(digest, link) {
        window.clearTimeout(swarm.browse._archivePolling);
        swarm.browse._archivePolling = setTimeout(function() {
            $.ajax('/archive-status/' + digest, {
                data:    {format: 'json'},
                success: function(response) {
                    swarm.browse.updateArchiveStatus(link, response);

                    // all done if no longer polling (cancelled) or an error occurred
                    if (!swarm.browse._archivePolling || response.error) {
                        return;
                    }

                    // if the archive is built, redirect to download url
                    if (response.success) {
                        window.location.href = link.attr('href');
                        return;
                    }

                    swarm.browse.pollForArchive(digest, link);
                },
                error: function(response, status, error) {
                    swarm.browse.updateArchiveStatus(link, {error: error});
                    this.errorHandled = true;
                }
            });
        }, 1000);
    },

    prettify: function() {
        window.prettyPrint();

        // wrap each line of plaintext code in a span so we can css target it
        // without targeting the linenumbers
        $('.prettyprint.nocode li').filter(function() {
            // filter out lines that already have an element
            return !this.firstChild || this.firstChild.nodeType !== 1;
        }).wrapInner('<span class="pln" />');
    },

    getLoadingPane: function(path, isFile) {
        // prepare different content for loading a file vs. a dir
        var content = '<span class="loading muted pad2">'+swarm.te('Loading...')+'</span>';
        if (!isFile) {
            content =
                  '<table class="table table-compact browse-files">'
                +  '<thead>'
                +   '<tr>'
                +    '<th class="file-name">' + swarm.te('Name') + '</th>'
                +    '<th class="file-time">' + swarm.te('Modified') + '</th>'
                +    '<th class="file-size">' + swarm.te('Size') + '</th>'
                +   '</tr>'
                +  '</thead>'
                +  '<tbody>'
                +   '<tr class="dir">'
                +    '<td colspan="3"><span class="loading muted">' + swarm.te('Loading...') + '</span></td>'
                +   '</tr>'
                +  '</tbody>'
                + '</table>';
        }

        var pane = $(
              '<div class="browse-content">'
            +  '<ul class="nav nav-tabs browse-tabs">'
            +   '<li class="active">'
            +    '<a href="#browse" data-toggle="tab">'
            +     '<i class="icon-' + (isFile ? 'file' : 'folder-open') + '"></i> '
            +     swarm.te(isFile ? 'View' : 'Browse')
            +    '</a>'
            +   '</li>'
            +   '<li>'
            +    '<a href="#commits" data-toggle="tab">'
            +     '<i class="icon-time"></i> ' + swarm.te('Commits')
            +    '</a>'
            +    '</li>'
            +  '</ul>'
            +  '<div class="tab-content">'
            +   '<div class="tab-pane fade in active" id="browse">' + content + '</div>'
            +  '</div>'
            + '</div>'
        );

        // loading text is initially hidden, shown after 500ms
        pane.find('.loading').css('visibility', 'hidden');
        setTimeout(function(){
            pane.find('.loading').css('visibility', '').addClass('animate');
        }, 500);

        return pane;
    },

    handleLineHash: function() {
        var hash = window.location.hash.replace(/^#l?/i, '');
        if (!hash) {
            return;
        }

        // we support passing a line number as the hash/anchor value in the url.
        // detect if that was done below and scroll to the line if we can find
        // it. we leave a fixed buffer to allow for the toolbar and then some.
        var start = parseInt(hash, 10),
            line  = start > 0 ? $('ol.linenums li:nth-child(' + start + ')') : [];
        if (line.length) {
            $('html, body').scrollTop(line.offset().top - Math.round($(window).height() / 4));

            // highlight the targeted line - if hash is a range (x-y), highlight range
            line.addClass('highlight');
            if (hash.match(/[0-9]+\-[0-9]+/)) {
                var i = 0, end = parseInt(hash.split('-').pop(), 10);
                for (i = start; i < end; i++) {
                    $('ol.linenums li:nth-child(' + (i + 1) + ')').addClass('highlight');
                }
            }
        }
    },

    // wire-up clicking on browse nav tabs to do the following:
    //  - set active link in project toolbar depending on the active tab
    //  - show the blame button only on the view tab
    //  - show the user-filter only on the history tab
    //  - show the range-filter only on the history tab
    //  - show the deleted-filter only on the browse tab
    //  - show the btn-group-archive only on the browse tab
    handleTabChange: function(e) {
        var href = $((e && e.target) || '.nav-tabs .active a[href]').attr('href');

        // only show the blame button on the view tab
        $('.btn-blame').closest('.btn-group').toggle(href === '#view');

        // only show the user-filter on the history tab
        $('.user-filter').toggle(href === '#commits');

        // only show the range-filter on the history tab
        $('.range-filter').toggle(href === '#commits');

        // only show the deleted files checkbox on the browse tab
        $('.deleted-filter').toggle(href === '#browse');

        // only show the download archive button on the browse tab
        $('.btn-group-archive').toggle(href === '#browse');

        // set active link in project toolbar
        var toolbar = $('.project-navbar');
        toolbar.find('ul.nav li').removeClass('active');
        if (href === '#browse' || href === '#view') {
            toolbar.find('ul.nav a.browse-link').closest('li').addClass('active');
        } else if (href === '#commits') {
            toolbar.find('ul.nav a.history-link').closest('li').addClass('active');
        }
    },

    toggleDeletedFiles: function(button) {
        swarm.browse._showDeleted = !swarm.browse._showDeleted;
        $(button).toggleClass('active', swarm.browse._showDeleted);

        var path      = $('.breadcrumb').data('url'),
            cachePath = $('.breadcrumb').data('path'),
            cached    = swarm.browse.getCached(cachePath);

        // ensure the current path is cached (it won't be on initial load)
        swarm.browse.setCached(cachePath, !swarm.browse._showDeleted);

        // response handling is the same for cached vs. server responses.
        var handleResponse = function(response) {
            var responseNodes   = $('<div />').append($.parseHTML(response)),
                source          = responseNodes.find('.tab-content #browse');
            $('.tab-content #browse').replaceWith(source);
            $('.timeago').timeago();
        };

        if (cached) {
            handleResponse(cached.data);
        } else {
            $('table.browse-files tbody').html(
                  '<tr><td colspan="3"><span class="loading animate muted pad2">'
                +   swarm.te('Loading...')
                + '</span></td></tr>'
            );
            $.ajax({
                url:     path,
                data:    {format: 'partial', showDeleted: swarm.browse._showDeleted || undefined},
                success: function(response) {
                    handleResponse(response);
                    swarm.browse.setCached(cachePath);
                }
            });
        }
    }
};
