/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

swarm.diff = {
    _activeScroller:    null,
    _navElement:        null,
    _navQueue:          null,
    _scrolling:         null,

    moreContextLines:   10,

    init: function() {
        swarm.diff._navQueue = [];
        // hook up collapse listeners (e.g. load diffs when expanded)
        $('.diff-wrapper .diff-details.collapse').on('shown hidden', function(e){
            if (e.target !== this) {
                return;
            }

            var isHidden = e.type === 'hidden';
            var wrapper  = $(this).closest('.diff-wrapper');

            wrapper.toggleClass('collapsed', isHidden);

            // skip loaded if we are hidden or no 'loading' element is present.
            if (!isHidden && wrapper.find('.loading').length) {
                swarm.diff.loadDiff(wrapper);
            }

            // call appropriate resizing methods if size is dirty
            if (wrapper.data('dirty-scroll-width')) {
                swarm.diff.updateScrollerWidth(wrapper);
            }
            if (wrapper.data('dirty-sideways-width')) {
                swarm.diff.updateSidewaysWidth(wrapper);
            }
        });

        // make diff headers scroll with the page
        $('.diff-wrapper').on('diff-loaded', function() {
            // run after other diff-loaded work
            setTimeout($.proxy(function() {
                $('.diff-header .diff-header-affix', this).swarmAffix({scrollToTarget:true});
            }, this), 0);
        });

        // don't adjust url when expanding/collapsing
        $('.diff-header a.filename').on('click.file', function(e){
            e.preventDefault();
        });

        // don't expand/collapse when clicking buttons in diff header.
        $(document).on('click.diff.btn', '.diff-header .btn', function(e) {
            e.stopPropagation();
        });

        // add mode button listeners
        $(document).on('click.diff.ws.btn', '.btn-ws', function (e) {
            swarm.diff.toggleWhitespace($(e.target).closest('.diff-wrapper'));
        });
        $(document).on('click.diff.inline.btn', '.btn-inline', function (e) {
            swarm.diff.showInline(this);
            // update header position after size change
            $(this).closest('.diff-header-affix').swarmAffix({scrollToTarget:true});
        });
        $(document).on('click.diff.sideways.btn', '.btn-sideways', function (e) {
            swarm.diff.showSideways(this);
            // update header position after size change
            $(this).closest('.diff-header-affix').swarmAffix({scrollToTarget:true});
        });
        $(document).on('click.diff.full.btn', '.btn-full', function (e) {
            swarm.diff.toggleFullFile($(e.target).closest('.diff-wrapper'));
        });

        // check header scroll position whenever full context is toggled
        $('.diff-wrapper').on('show-full show-more-context', function() {
            $('.diff-header .diff-header-affix', this).swarmAffix({scrollToTarget:true});
        });

        // add more context listener
        $(document).on('click.diff.more.context', '.diff-type-meta', function () {
            // ignore snipped lines
            if (!$(this).hasClass('is-cut')) {
                swarm.diff.renderMoreContext($(this), swarm.diff.moreContextLines);
            }
        });

        // if there is only one file in this change, expand it.
        // else if there is only one file, and the files tab isn't shown
        // expand the file when the files tab is shown
        if ($('#files.active .diff-wrapper').length === 1) {
            $('.diff-wrapper .diff-details').collapse('show');
        } else if ($('#files .diff-wrapper').length === 1) {
            $('.change-tabs a[href="#files"]').one('shown', function() {
                $('.diff-wrapper .diff-details').collapse('show');
            });
        }

        // handle hash fragments in the url for locating files and specific
        // lines/comments
        $(window).on('hashchange.diff.context', swarm.diff.handleHash);
        swarm.diff.handleHash();

        // adjust side-by-side table width on resize
        // and update scroll proxy size if needed
        $(window).resize(function() {
            $('.diff-wrapper').trigger('diff-resize');

            // need to resize proxy scrollbars if there is no calc-support
            if (!swarm.has.cssCalcSupport()) {
                $('.diff-wrapper .diff-details.in').each(function() {
                    swarm.diff.updateScrollerWidth($(this).closest('.diff-wrapper'));
                });
            }
        });

        // listen onkeydown for n/p to go to next/previous diffs
        var isKeyDown = false;
        $(window).on('keydown', function(e) {
            // don't act on already handled events or if the files tab isn't active
            if (isKeyDown || e.altKey || e.ctrlKey || e.metaKey || e.isDefaultPrevented()
                    || $(e.target).is('input, textarea, select') || !$('#files.active').length) {
                return;
            }

            isKeyDown = true;
            if (e.which === 78) { // n for next
                e.preventDefault();
                swarm.diff._navQueue.push('n');
                swarm.diff.processNavQueue();
            } else if (e.which === 80) { // p for previous
                e.preventDefault();
                swarm.diff._navQueue.push('p');
                swarm.diff.processNavQueue();
            }
        });
        $(window).on('keyup', function() { isKeyDown = false; });
    },

    processNavQueue: function(active, force) {
        if (!force && swarm.diff._navigating) {
            return;
        }
        swarm.diff._navigating = true;

        // find the nav elements
        active      = (active && $(active)) || $(swarm.diff._navElement);
        var diffs   = swarm.diff.getNavElements();

        // add active if it is still part of the dom
        var index   = (swarm.diff._navQueue[0] === 'n' ? 0 : -1);
        if (active.length && active[0].parentNode) {
            index   = diffs.index(active) + (swarm.diff._navQueue[0] === 'n' ? 1 : -1);
        }

        // wrap the index when it exceeds the bounds of the diffs array
        index       = (index <= -1 ? diffs.length - 1 : index);
        index       = (index === diffs.length ? 0 : index);

        // wait for diff-details to load if it hasn't yet
        // else skip to the next target if it has loaded
        var target  = diffs.eq(index);
        if (target.is('.diff-details') && !target.closest('.diff-wrapper').data('diff-loaded')) {
            target.closest('.diff-wrapper').one('diff-loaded', function() {
                swarm.diff.processNavQueue(null, true);
            });
            target.collapse('show');
            return;
        }
        if (target.is('.diff-details')) {
            swarm.diff.processNavQueue(target, true);
            return;
        }

        // complete when we have a valid target to navigate to
        var complete = function() {
            // finally finished with this queued item
            swarm.diff._navElement = target[0];
            swarm.diff._navQueue.shift();
            swarm.diff._navigating = false;

            // if other events have made it onto the queue,
            // we should process instead of scrolling
            if (swarm.diff._navQueue.length) {
                swarm.diff.processNavQueue();
                return;
            }

            // focus and scroll to target
            swarm.diff.focusNavElement(target);
            swarm.diff.scrollToElement(target);
        };

        // call complete if details are already shown,
        // otherwise show the details first
        var details = target.closest('.diff-details');
        if (details.is('.in')) {
            complete();
        } else {
            swarm.diff._navigating = true;
            details.one('shown', complete);
            details.collapse('show');
        }
    },

    focusNavElement: function(element) {
        element = $(element);
        swarm.diff._navElement = element[0];

        // remove focus from last area
        $('.diff-nav-focus-start, .diff-nav-focus-end').removeClass('diff-nav-focus-start diff-nav-focus-end');

        var targetStart = element,
            targetEnd   = $(),
            diffBody    = element.closest('.diff-body');

        // we need to add the diff pair to the starting target for diff-sideways
        if (diffBody.is('.diff-sideways')) {
            targetStart = targetStart.add(swarm.diff.getPairedRow(element[0]));
        }

        // find the end target for each start target
        targetStart.each(function() {
            // if the first is the last, use it for the targetEnd
            // otherwise find the last of the chunk
            if ($(this).next('tr.diff-type-same, tr.diff-type-meta').length || $(this).next().length < 1) {
                targetEnd = targetEnd.add(this);
            } else {
                targetEnd = targetEnd.add($(this).nextUntil('tr.diff-type-same, tr.diff-type-meta').last());
            }
        });

        // mark the starting and ending target
        targetStart.addClass('diff-nav-focus-start');
        targetEnd.addClass('diff-nav-focus-end');
    },

    getNavElements: function(root) {
        // initialize elements to be empty if we passed
        // a root, otherwise include diff-details
        var elements = (root && $()) || $('.diff-details');

        // scope the query to valid diff-bodies
        root         = (root && $(root)) || $('.diff-details');
        var scope    = root.find('.diff-body').filter(function() {
            var display     = $(this).css('display'),
                pairDisplay = $($(this).data('diff-pair')).css('display');

            // ignore hidden elements and the right side body unless it is the only one showing
            return display === 'block' && (!$(this).is('.right-side') || pairDisplay !== 'block');
        });

        // set scope to root if no valid diff-bodies were found
        // and add valid nav elements to the elements list
        scope    = scope.length ? scope : root;
        elements = elements.add(scope.find(
              '.diff-type-meta + .diff-type-add, .diff-type-meta + .diff-type-delete,'
            + '.diff-type-same + .diff-type-add, .diff-type-same + .diff-type-delete,'
            + '.diff-image, .diff-description'
        ));

        // return elements
        return elements;
    },

    handleHash: function() {
        // we support passing a filename md5 in the url fragment.
        // detect if that was done below and expand the selected file.
        var hash = window.location.hash.replace(/^#/, '');
        if (!hash) {
            return;
        }

        // hash can be split into filename-md5[,line|comment]
        // e.g. c3a8...a340,lr123, c3a8...a340,c123
        hash = hash.split(',');

        // exit early if hash does not reference a diffWrapper
        var diffWrapper = $('a[name=' + hash[0] + ']').closest('.diff-wrapper');
        if (!diffWrapper.length) {
            return;
        }

        // find the elements needed to navigate to the file
        var details     = diffWrapper.find('.diff-details.collapse'),
            tabPane     = diffWrapper.closest('.tab-pane'),
            tabButton   = $('.nav-tabs a[href="#' + tabPane[0].id + '"]');

        // wait for the file pane to be shown
        tabButton.one('shown', function() {
            // expand the details if they are collapsed
            details.not('.in').collapse('show');

            // if we don't have a sub-hash, just scroll to the wrapper
            if (!hash[1]) {
                return swarm.diff.scrollToElement(diffWrapper);
            }

            swarm.diff.handleSubHash(hash[1], diffWrapper);
        });

        // show the tab if it is not already active
        // else trigger the shown event to trigger our listeners
        if (!tabPane.is('.active')) {
            tabButton.tab('show');
        } else {
            tabButton.trigger($.Event('shown'));
        }
    },

    handleSubHash: function(subHash, diffWrapper) {
        // must wait until comments are loaded
        if (!diffWrapper.data('comments-loaded')) {
            diffWrapper.one('comments-loaded', function() {
                swarm.diff.handleSubHash(subHash, diffWrapper);
            });
            return false;
        }

        // locate the active diffTarget
        var diffTarget = diffWrapper.find('.diff-body').filter(function() {
            return $(this).css('display') === 'block';
        }).add(diffWrapper.find('.diff-footer'));

        // look for the specified line or comment in the diff-body
        var found, section;
        if (subHash.charAt(0) === 'l') {
            found   = diffTarget.find('tr.' + subHash);
            section = found;
        } else {
            found   = diffTarget.find('.' + subHash);
            section = found.closest('.comments-section');
        }

        // if not found within normal context, try loading full context.
        var file = diffWrapper.data('file');
        if ((!found.length || section.hasClass('diff-full-context'))
            && (file.isEdit || file.fromFile)
            && !diffWrapper.hasClass('show-full')
        ) {
            diffWrapper.one('show-full', function() {
                var found = swarm.diff.handleSubHash(subHash, diffWrapper);

                // turn off full context if sub-hash not found
                if (!found) {
                    diffWrapper.find('.btn-full').removeClass('active');
                    swarm.diff.toggleFullFile(diffWrapper);
                }
            });

            diffWrapper.find('.btn-full').addClass('active');
            swarm.diff.toggleFullFile(diffWrapper);
            return false;
        }

        // scroll to element if we found it
        if (found.length) {
            if (section.hasClass('comments-section')) {
                if (section.css('display') === 'none') {
                    section.toggle(true).trigger('show');

                    if (section.hasClass('comments-row')) {
                        $(swarm.diff.getPairedRow(section)).toggle(true).trigger('show');
                        swarm.comments.sizeCommentRowForDiff(section);
                    }
                }

                // expand collapsed archived comments
                if (section.find('.closed-comments-body.hidden').find(found).length) {
                    section.find('.closed-comments-header').click();
                }
            }

            swarm.diff.scrollToElement(found);
            return true;
        }

        return false;
    },

    scrollToElement: function(element) {
        if (!element.length) {
            return;
        }

        var top     = element.offset().top,
            bodyPad = parseInt($('body').css('padding-top'), 10);

        $('html, body').animate({'scrollTop': top - (bodyPad*3)}, 'fast', 'swing');
    },

    getDiffMode: function(wrapper) {
        wrapper = $(wrapper);

        // always return inline for diff's that don't support sideways
        if(!wrapper.find('.diff-details .diff-table').length) {
            return 'inline';
        }

        // check for active modes
        if (wrapper.find('.btn-sideways').hasClass('active')) {
            return 'sideways';
        }
        if (wrapper.find('.btn-inline').hasClass('active')) {
            return 'inline';
        }

        // check localStorage, default to sideways mode
        return swarm.localStorage.get('diff.mode') !== 'inline' ? 'sideways' : 'inline';
    },

    getActiveDiffBody: function(wrapper, ignoreHidden) {
        wrapper        = $(wrapper);
        var whitespace = wrapper.hasClass('ignore-ws') ? 'no-ws' : 'ws',
            mode       = swarm.diff.getDiffMode(wrapper),
            bodies     = wrapper.find('.diff-' + mode + '.' + whitespace);

        // pure adds and pure deletes hide one of their two diff sides,
        // if requested we will exclude the hidden side
        if (ignoreHidden) {
            var isAdd    = wrapper.hasClass('pure-add'),
                isRemove = wrapper.hasClass('action-delete');

            bodies = bodies.filter(function() {
                var $this = $(this);
                return (isAdd && !$this.hasClass('left-side'))
                    || (isRemove && !$this.hasClass('right-side'))
                    || true;
            });
        }

        return bodies;
    },

    _getDiffFiles: function(wrapper) {
        wrapper   = $(wrapper);
        var file  = wrapper.data('file'),
            sides = {};

        // if the server told us what revs to diff, trust it.
        if (file.diffLeft || file.diffRight) {
            if (file.diffRight) {
                sides.right = { path: file.depotFile, rev: file.diffRight };
            }
            if (file.diffLeft) {
                sides.left  = { path: file.depotFile, rev: file.diffLeft };
            }
            return sides;
        }

        var change    = wrapper.closest('.change-wrapper').data('change'),
            isPending = change.status === "pending",
            isMoveAdd = file.fromFile && file.fromRev;

        sides.right = {
            path: file.depotFile,
            rev:  isPending ? '@=' + change.id : '#' + file.rev
        };

        // don't provide the left side for adds unless they are move-adds
        // also don't specify the left side if the rev is 'none' this represents no-file being applicable
        if ((!file.isAdd || isMoveAdd) && file.rev !== 'none') {
            sides.left = {
                path: isMoveAdd ? file.fromFile : file.depotFile,
                rev: '#' + (isMoveAdd ? file.fromRev : (isPending ? file.rev : (parseInt(file.rev, 10)) - 1))
            };
        }

        return sides;
    },

    loadDiff: function(wrapper, callback) {
        wrapper = $(wrapper);

        // in the case of move/delete files, no need to query server
        // the move/add half has the diffs, so we simply link to it.
        var file = wrapper.data('file');
        if (file.isDelete && file.action.match('move')) {
            var toFile;
            $('.diff-wrapper').each(function() {
                if ($(this).data('file').fromFile === file.depotFile) {
                    toFile = $(this);
                    return false;
                }
            });

            wrapper.find('.diff-details').html(
                '<div class="diff-body">'
              + '  <div class="diff-description pad3">'
              +      file.type.charAt(0).toUpperCase() + file.type.substr(1)
              + '    ' + swarm.te(toFile ? 'file moved to' : 'file moved') + (toFile ? ' <a href="#"></a>' : '') + '.'
              + '  </div>'
              + '</div>'
            ).append(
                '<div class="diff-footer"></div>'
            );

            // link up with toFile
            wrapper.find('.diff-details a')
                   .text(toFile.data('file').depotFile)
                   .click(function(e) {
                       window.location = '#' + toFile.find('a[name]').attr('name');
                       if (toFile.find('.diff-details').hasClass('out')) {
                           toFile.find('.diff-details').collapse('show');
                       }
                       e.preventDefault();
                   });

            callback = callback && callback();
            wrapper.data('diff-loaded', true).trigger($.Event('diff-loaded'));
            return callback;
        }

        // compose left/right filespecs
        var files = swarm.diff._getDiffFiles(wrapper);
        $.ajax({
            url:        '/diff',
            data:       {
                left:       files.left  && files.left.path  + files.left.rev,
                right:      files.right && files.right.path + files.right.rev,
                ignoreWs:   wrapper.is('.ignore-ws') ? 1 : 0,
                action:     file.action || null
            },
            dataType:   'text',
            success:    function(response) {
                var diff = $($.parseHTML(response, document, true));

                // inserting the diff into the dom is differed so we can run subDiff without causing excess layout
                var insertDiff = function(isCode) {
                    wrapper.find('.loading, .diff-scroll-container, .diff-footer').remove();

                    // highlight whitespace on non-diff lines
                    swarm.diff.spanifyWhitespaceOnPlainLines(diff);

                    var details = wrapper.find('.diff-details').append(diff);

                    // hook up scrollbar for code diff
                    if (isCode) {
                        details.append(
                              '<div class="diff-scroll-container">'
                            +   '<div class="diff-scroll">'
                            +     '<div class="scroll-content"></div>'
                            +   '</div>'
                            + '</div>'
                        );
                        swarm.diff.addScrollListeners(wrapper, wrapper.find('.diff-scroll'));
                    }

                    details.append('<div class="diff-footer"></div>');
                };

                // if we didn't get a line-based diff back, all done.
                if (!diff.find('.diff-table').length) {
                    insertDiff();
                    wrapper.trigger($.Event('load'));
                    callback = callback && callback();
                    wrapper.data('diff-loaded', true).trigger($.Event('diff-loaded'));
                    return callback;
                }

                // identify pure-adds (e.g. add, move/add or branch where no edits happened)
                if (file.isAdd && swarm.query.all('tr.diff-type-delete, tr.diff-type-same', diff).length === 0) {
                    wrapper.addClass('pure-add');
                } else if (!file.isDelete) {
                    // identify sub-line differences for files that are not deletes
                    swarm.diff.subDiff(diff);

                    // remove show-more context options from the first meta line if we already
                    // have the first line or the first line has no line number
                    var firstMeta = swarm.query.first('tr.diff-type-meta', diff),
                        firstLine = firstMeta.next();
                    if (firstLine.hasClass('ll1') || !firstLine.find('.line-num-left').data('num')) {
                        firstMeta.addClass('all-context-loaded');
                    }
                }

                // mark the diffs as diff-content
                swarm.query.all('tr.diff', diff).not('.diff-type-meta').addClass('diff-content');

                // insert the code diff in the wrapper
                insertDiff(true);

                // add diff mode buttons to diff toolbar if they don't already exist
                var diffMode = swarm.diff.getDiffMode(wrapper);
                if (wrapper.find('.btn-inline, .btn-sideways').length === 0) {
                    var buttons =
                          '<div class="btn-group collapse-hidden">'
                        + '    <button type="button" title="' + swarm.te('Show In-Line') + '"'
                        + '            class="btn btn-mini btn-inline ' + (diffMode === 'inline' ? 'active' : '') + '">'
                        + '        <i class="swarm-icon icon-diff-inline"></i>'
                        + '    </button>'
                        + '    <button type="button" title="' + swarm.te('Show Side-by-Side') + '"'
                        + '            class="btn btn-mini btn-sideways ' + (diffMode === 'sideways' ? 'active' : '') + '">'
                        + '        <i class="swarm-icon icon-diff-sideways"></i>'
                        + '    </button>'
                        + '</div>'
                        + '<div class="btn-group collapse-hidden">'
                        + '    <button type="button" title="' + swarm.te('Show Whitespace') + '"'
                        + '        class="btn btn-mini btn-show-whitespace"'
                        + '        onclick="swarm.diff.toggleShowWhitespace(this);">'
                        + '        <span>&bull;</span>'
                        + '    </button>'
                        + '</div>'
                        + '<div class="btn-group collapse-hidden">'
                        + '    <button type="button" title="' + swarm.te('Ignore Whitespace') + '"'
                        + '            class="btn btn-mini btn-ws" data-toggle="button">'
                        + '        <i class="swarm-icon icon-ignore-ws"></i>'
                        + '    </button>'
                        + '</div>';

                    // only show full context button for edits (and move/adds)
                    if (file.isEdit || file.fromFile) {
                        buttons +=
                              '<div class="btn-group collapse-hidden">'
                            + '    <button type="button" title="' + swarm.te('Show Full Context') + '"'
                            + '            class="btn btn-mini btn-full" data-toggle="button">'
                            + '        <i class="swarm-icon icon-expand-file"></i>'
                            + '    </button>'
                            + '</div>';
                    }

                    wrapper.find('.diff-toolbar').prepend($(buttons));
                }

                wrapper.trigger($.Event('load'));

                // if in inline mode, we want to set the scroller width to the content size
                if (diffMode === 'inline') {
                    swarm.diff.updateScrollerWidth(wrapper);
                }

                // if in sideways mode, showSideways, but only if not already active
                if (diffMode === 'sideways' && !swarm.diff.getActiveDiffBody(wrapper).length) {
                    swarm.diff.showSideways(wrapper.find('.btn-sideways'));
                }

                callback = callback && callback();
                wrapper.data('diff-loaded', true).trigger($.Event('diff-loaded'));
                return callback;
            },
            error:      function(response) {
                // show access denied message if we received 403: Forbidden
                if (response.status === 403) {
                    this.errorHandled = true;
                    wrapper.find('.diff-details').html(
                        $(
                              '<div class="alert pad3">'
                            + swarm.te("You don't have permission to view this file.")
                            + '</div>'
                        )
                    );
                }
            }
        });
    },

    subDiff: function(content) {
        var getEnding = function(line) {
            line = $(line);
            return (line.hasClass('lf')   && 'lf') ||
                   (line.hasClass('crlf') && 'crlf') ||
                   (line.hasClass('cr')   && 'cr');
        };
        var moveWhitespace = function() {
            var starting, ending;
            $(this).html($(this).html().replace(
                /(^[\t ]+)|([\t ]+$)/g,
                function(match, p1, p2) {
                    starting = p1 || starting;
                    ending   = p2 || ending;
                    return '';
                }
            ));

            $(this).before(starting);
            $(this).after(ending);
        };

        // identify edit chunks (ie. a delete immediately followed by an add)
        swarm.query.all('tr.diff-type-delete + tr.diff-type-add', content).each(function() {
            var $this   = $(this),
                prev    = $this,
                next    = $this,
                deletes = [],
                adds    = [this];

            // collect all of the add and delete rows in this edit chunk.
            while ((prev = prev.prev('tr.diff-type-delete')).length) {
                deletes.unshift(prev[0]);
            }
            while ((next = next.next('tr.diff-type-add')).length) {
                adds.push(next[0]);
            }
            adds    = $(adds);
            deletes = $(deletes);

            // mark the chunks so we can easily find them later
            adds.addClass('diff-type-edit');
            deletes.addClass('diff-type-edit');

            // find line ending differences
            var markChunkEndings = false;
            adds.each(function(i) {
                // quit loop if there are no more matching delete rows
                if (i >= deletes.length) {
                    return false;
                }
                // mark if their line endings differ
                if (getEnding(adds[i]) !== getEnding(deletes[i])) {
                    markChunkEndings = true;
                    return false;
                }
            });

            // mark changed line endings
            if (markChunkEndings) {
                adds.addClass('line-end-changed');
                deletes.addClass('line-end-changed');
            }

            // when a diff is shown in side-by-side mode we need the left and right sides
            // to have equal number of lines, we record the amount of padding needed to
            // achieve that for efficiency (see showSideways).
            $(deletes[deletes.length-1]).data('padChunkLength', Math.max(adds.length - deletes.length, 0));
            $(adds[adds.length-1]).data('padChunkLength', Math.max(deletes.length - adds.length, 0));

            // run actual diff on a chunk, and wrap differences in spans
            swarm.diff._subDiffChunk(deletes, adds);

            // move starting and ending whitespace in the subline diff
            // if our content is the ignore-whitespace diff.
            if (content.is('.no-ws')) {
                swarm.query.apply('span.insert, span.delete', adds, moveWhitespace);
                swarm.query.apply('span.insert, span.delete', deletes, moveWhitespace);
            }
        });
    },

    addScrollListeners: function(wrapper, scrollContainer) {
        scrollContainer.scroll(function() {
            // all hooked up scrollbars will be firing scroll events,
            // we only want to listen to the one the user is moving.
            // Ignore the event if we aren't the master scrollbar
            if (swarm.diff._activeScroller && swarm.diff._activeScroller.source !== this) {
                return;
            }

            // use a timeout to manage only having one master scroller at a time
            clearTimeout(swarm.diff._scrolling);
            swarm.diff._scrolling = setTimeout(function() {
                swarm.diff._activeScroller = null;
            }, 1000);

            // store all the associated scrollbars if we are just setting the master scrollbar now
            if (!swarm.diff._activeScroller) {
                var associatedScrollbars = swarm.diff.getActiveDiffBody(wrapper).find('.diff-scroll')
                    .add(wrapper.find('.diff-scroll-container .diff-scroll'));
                swarm.diff._activeScroller = {'source': this, 'targets': associatedScrollbars.not(this)};
            }

            // update connected scrollbars
            swarm.diff._activeScroller.targets.scrollLeft(this.scrollLeft);
        });
    },

    showSideways: function(button, rebuild) {
        if (!button || !$(button).length) {
            return;
        }

        var wrapper  = $(button).closest('.diff-wrapper');
        var inline   = wrapper.find('.diff-inline.active');
        var original = wrapper.hasClass('ignore-ws')
                     ? wrapper.find('.diff-sideways.no-ws')
                     : wrapper.find('.diff-sideways.ws');

        var sideways = rebuild ? $() : original;

        wrapper.find('.btn-inline').removeClass('active');
        wrapper.find('.btn-sideways').addClass('active');

        // build side-by-side diffs from inline diffs.
        if (!sideways.length) {
            // clone with data and events
            var mode  = inline.hasClass('ws') ? 'ws' : 'no-ws',
                left  = inline.clone(true).attr(
                    'class',
                    'diff-body diff-sideways border-box ' + mode + ' left-side'
                ),
                right = inline.clone(true).attr(
                    'class',
                    'diff-body diff-sideways border-box ' + mode + ' right-side'
                );

            // clean up the loading-state of any loaded images that got cloned
            swarm.query.all('img.loaded', left).removeClass('loaded');
            swarm.query.all('img.loaded', right).removeClass('loaded');

            // clean up the placeholder value of any inputs clones
            var cleanPlaceholder = function() {
                if (this.value && this.value === this.placeholder) {
                    this.value = '';
                }
            };
            swarm.query.apply('[placeholder]', left, cleanPlaceholder);
            swarm.query.apply('[placeholder]', right, cleanPlaceholder);

            // we use our own remove function. It works exactly like $().remove()
            // but only works on regular domNodes, (not script or object tags)
            // it has better performance because it avoids type-checking
            var remove      = function() {
                $.cleanData([this]);
                this.parentNode.removeChild(this);
            };

            // blank out cell, assign the className directly to avoid jquery objects
            // and make use of template cloning for additional speed
            var lineValue   = $('<td class="line-value">&nbsp;</td>'),
                dropClasses = ['diff-content', 'has-comments', 'archived-only'];
            var blankCells  = function() {
                var className = " " +  this.parentNode.className + " ", i;
                for (i = 0; i < dropClasses.length; i++) {
                    className = className.replace(" " + dropClasses[i] + " ", " ");
                }
                this.parentNode.className = $.trim(className) + ' line-pad';
                this.parentNode.replaceChild(lineValue[0].cloneNode(true), this);
            };

            sideways = sideways.add(left).add(right);
            left.data('diff-pair',  right[0]);
            right.data('diff-pair', left[0]);

            // ensure an equal number of rows on the left/right side of edit chunks
            // left-side: keep/pad deletes and remove adds
            // right-side: keep/pad adds and remove deletes
            swarm.query.all('tr.diff.diff-type-add.diff-type-edit', left).each(remove);
            swarm.query.all('tr.diff.diff-type-delete.diff-type-edit', right).each(remove);
            swarm.query.apply('tr.diff.diff-type-edit', sideways, function() {
                // using $.data saves us from creating an extra jquery object
                var padding = $.data(this, 'padChunkLength');
                if (padding !== undefined && padding > 0) {
                    swarm.diff.pad(padding, this);
                }
            });

            // remove first character of each line value (ie. '-', '+')
            // reuse the same 'parentNode' jquery object for performance
            var parentNode = $([1]);
            swarm.query.apply('td.line-value', sideways, function() {
                parentNode[0] = this.parentNode;
                if (parentNode.hasClass('diff-type-meta')) {
                    return;
                }

                // splitting and removing nodes is more performant
                // than calling innerHTML to remove the first character
                var firstChild = this.firstChild;
                if (firstChild && firstChild.nodeType === 3) {
                    firstChild.splitText(1);
                    this.removeChild(firstChild);
                }
            });

            // remove right-nums on left, left-nums on right.
            swarm.query.all('td.line-num-right', left).each(remove);
            swarm.query.all('td.line-num-left', right).each(remove);

            // blank out adds on left, deletes on right.
            swarm.query.all('tr.diff.diff-type-add td.line-value', left).each(blankCells);
            swarm.query.all('tr.diff.diff-type-delete td.line-value', right).each(blankCells);

            // add sides to DOM after most of the dom changes
            // has already occurred to prevent reflow
            sideways.insertAfter(wrapper.find('.diff-body').last());

            // hook up synced scrollbars
            swarm.diff.addScrollListeners(wrapper, sideways.find('.diff-scroll'));
        }

        // cleanup original if we were rebuilding
        if (rebuild) {
            original.remove();
        }

        // directly set display to save from /hide/shows getComputedStyle overhead
        inline.css('display', 'none').trigger('hide');
        sideways.css('display', 'block').trigger('show');

        // update the navElement
        if (wrapper.find(swarm.diff._navElement).length) {
            swarm.diff.focusNavElement(swarm.diff.getNavElements(wrapper)[0]);
        }

        // We always need to update our proxy scroller width here.
        // If our content was new, make sure both sides are the same width for scrolling
        // which also will take care of updating our proxy scroller.
        // Otherwise we still need to directly call the update for the scroller width.
        if (rebuild || !original.length) {
            swarm.diff.updateSidewaysWidth(wrapper);
        } else {
            swarm.diff.updateScrollerWidth(wrapper);
        }
    },

    updateScrollerWidth: function(wrapper, contentWidth) {
        // get the active bodies that could be sized (are visible)
        var activeBodies = swarm.diff.getActiveDiffBody(wrapper, true).find('table.diff-table');

        // if width was not passed, we need to measure it from the active diff bodies
        if (contentWidth === null || contentWidth === undefined) {
            // if the wrapper is collapsed, flag it as being dirty and return
            if (wrapper.hasClass('collapsed')) {
                wrapper.data('dirty-scroll-width', true);
                return;
            }

            contentWidth = 0;
            activeBodies.each(function(index) {
                $.swap(this, {'minWidth': '', 'width': '1px'}, function() {
                    contentWidth = Math.max(contentWidth, activeBodies.eq(index).width());
                });
            });
        }

        // If we are in dual-pane mode, we need to measure the overflow to determine the area to scroll.
        var minWidth;
        if (activeBodies.length === 2) {
            // If calcSupport is available, we can have the browser do the calculation during layout.
            // Otherwise we need to do the calculation ourselves here,
            // and will need this method called during window resize (handled in swarm.diff.init)
            if (swarm.has.cssCalcSupport()) {
                minWidth = 'calc(' + contentWidth + 'px - (100% / 2) + 100%)';
            } else {
                var availableWidth = wrapper.width();
                minWidth = ((contentWidth - (availableWidth / 2)) + availableWidth) + 'px';
            }
        } else {
            minWidth = contentWidth + 'px';
        }

        wrapper.data('dirty-scroll-width', contentWidth < 1);
        wrapper.find('.scroll-content').css('minWidth', minWidth);

        // If this is the first run this will create the affix plugin, otherwise
        // this call will cause the plugin to update the affixed state of the scrollbar
        // and will also update the diff-body boundaries if the diff-mode has changed
        wrapper.find('.diff-scroll-container .diff-scroll').swarmAffix({
            position: 'bottom',
            animate:  false,
            boundary: activeBodies
        });
    },

    updateSidewaysWidth: function(wrapper) {
        // ignore pure adds/or deletes, they don't need sizing
        // just update their scroller width
        if (wrapper.hasClass('pure-add') || wrapper.hasClass('action-delete')) {
            swarm.diff.updateScrollerWidth(wrapper);
            return;
        }

        // if the wrapper is collapsed, flag it as being dirty and return
        if(wrapper.hasClass('collapsed')) {
            wrapper.data('dirty-sideways-width', true);
            return;
        }

        wrapper.data('dirty-sideways-width', false);
        wrapper.find('.diff-sideways.left-side').each(function() {
            var left       = $(this),
                right      = $(left.data('diff-pair')),
                leftTable  = left.find('table.diff-table'),
                rightTable = right.find('table.diff-table'),
                sideways   = $().add(leftTable).add(rightTable),
                width      = [];

            // shrink the tables so we can determine actual width of their content
            // otherwise, they will grow to take up all available space and will
            // be too large if the user makes their window smaller.
            sideways.css({'minWidth': '', 'width': '1px'});

            // measure content width
            // if this sideways view is hidden, temporarily show it
            if (left[0].style.display === 'none' && right[0].style.display === 'none') {
                var showCss  = {'display': 'block', 'visibility': 'hidden'},
                    getWidth = function(table) { width.push(table.width()); };
                $.swap(left[0],  showCss, getWidth, [leftTable]);
                $.swap(right[0], showCss, getWidth, [rightTable]);
            } else {
                width = [leftTable.width(), rightTable.width()];
                swarm.diff.updateScrollerWidth(wrapper, Math.max(width[0], width[1]));
            }

            // set the minWidth to the largest content-width, and restore the width of the tables
            sideways.css({'minWidth': Math.max(width[0], width[1]) + 'px', width: ''});
        });

        wrapper.trigger('diff-resize');
    },

    _subDiffChunk: function(deletes, adds) {
        var Diff    = window.diff_match_patch,
            dmp     = new Diff(),
            before  = [],
            after   = [];

        // grab the line values of each row for diff ignoring the first +/- character
        deletes.each(function(index, value) {
            before.push(swarm.query.first('td.line-value', value).text().substring(1));
        });
        adds.each(function(index, value) {
            after.push(swarm.query.first('td.line-value', value).text().substring(1));
        });

        // diff the full chunk together
        var subChunks       = dmp.diff_main(before.join('\n'), after.join('\n')),
            beforeMarkup    = '',
            afterMarkup     = '';

        // run the human friendly cleanup
        dmp.diff_cleanupSemantic(subChunks);

        // go through each diff and regroup the changes into the before/after diffs
        $.each(subChunks, function(chunkIndex, chunk) {
            var beforeLines = [], afterLines = [];

            // split chunks by lines before marking up
            // so we don't wrap markup around multiple lines
            // note: chunk[0] is type, chunk[1] is value
            $.each(chunk[1].split('\n'), function(lineIndex, line) {
                line = swarm.diff.spanifyWhitespace($.views.converters.html(line));
                switch (chunk[0]) {
                    case 1: // insertion, place only in afterLines
                        afterLines.push('<span class="insert">' + line + '</span>');
                        break;
                    case -1: // deletion, place only in beforeLines
                        beforeLines.push('<span class="delete">' + line + '</span>');
                        break;
                    default: // equals, place in both
                        line = '<span>' + line + '</span>';
                        afterLines.push(line);
                        beforeLines.push(line);
                }
            });

            // chunks can start and end in mid line, or often don't span multiple lines
            // so they have to be added all together before they are split again
            beforeMarkup    += beforeLines.join('\n');
            afterMarkup     += afterLines.join('\n');
        });

        // go through the markup line-by-line and override the original input
        // and add back in the preceeding +/- character that we removed above
        $.each(beforeMarkup.split('\n'), function(index, value) {
            swarm.query.first('td.line-value', deletes[index]).html('-' + value);
        });
        $.each(afterMarkup.split('\n'), function(index, value) {
            swarm.query.first('td.line-value', adds[index]).html('+' + value);
        });
    },

    showInline: function(button) {
        var wrapper  = $(button).closest('.diff-wrapper');
        var inline   = wrapper.find('.diff-inline.active');
        var sideways = wrapper.find('.diff-sideways');

        wrapper.find('.btn-inline').addClass('active');
        wrapper.find('.btn-sideways').removeClass('active');

        inline.show().trigger($.Event('show'));
        sideways.hide().trigger($.Event('hide'));

        // update the navElement
        if (wrapper.find(swarm.diff._navElement).length) {
            swarm.diff.focusNavElement(swarm.diff.getNavElements(wrapper)[0]);
        }

        swarm.diff.updateScrollerWidth(wrapper);
    },

    pad: function(count, refNode) {
        refNode       = $(refNode);
        var lineClass = swarm.diff.getLineSelector(refNode).replace('.', ' ');

        var cloneNode;
        while(count-- > 0) {
            cloneNode = $(refNode).clone();
            cloneNode.removeClass('diff-content has-comments archived-only' + lineClass).addClass('line-pad');
            cloneNode.find('td').html(' &nbsp;');
            cloneNode.find('.line-num').attr('data-num', '');
            cloneNode.insertAfter(refNode);
        }
    },

    // note for expandAll/collapseAll we don't call the collapse
    // plugin because it performs slowly when dealing with lots of files.
    collapseAll: function() {
        $(".diff-details.collapse.in").trigger('hide').removeClass('in').css('height', '0').trigger('hidden');
    },

    expandAll: function() {
        $(".diff-details.collapse").not('.in').trigger('show').addClass('in').css('height', 'auto').trigger('shown');
    },

    toggleFullFile: function(diffWrapper) {
        // only support toggling full file on edits and move-adds
        var file = diffWrapper.data('file');
        if (!file.isEdit && !file.fromFile) {
            return;
        }


        // if the full file has been loaded already, skip loading again
        if (diffWrapper.data('full-file')) {
            return swarm.diff._renderFullFile(diffWrapper);
        }

        // request the full file
        var files = swarm.diff._getDiffFiles(diffWrapper);
        $.ajax({
            url:        '/files/' + swarm.encodeURIDepotPath(files.left.path) + '?v=' + encodeURIComponent(files.left.rev),
            data:       'view',
            dataType:   'text',
            success:    function(response) {
                var allLines = response.split(/\r\n|\n|\r/);

                // store the full file so we can apply it
                // to other diff modes (ignore-whitespace).
                diffWrapper.data('full-file', allLines);

                swarm.diff._renderFullFile(diffWrapper);
            }
        });
    },

    buildContextRowsHtml: function(lines, leftStart, rightStart, cls) {
        cls          = cls || "";
        var html     = "";
        var template = $.templates(
              '<tr class="diff diff-type-same diff-content {{>class}}" tabindex="0">'
            + '  <td class="line-num line-num-left" data-num="{{>left}}"></td>'
            + '  <td class="line-num line-num-right" data-num="{{>right}}"></td>'
            + '  <td class="line-value"> {{:value}}</td>'
            + '</tr>'
        );

        $.each(lines, function(index, line) {
            var left      = leftStart  + index,
                right     = rightStart + index,
                pieces    = line.split(/(\r\n|\n|\r)/),
                ending    = pieces[1] || '',
                endingCls = ending.replace(/\r/, 'cr').replace(/\n/, 'lf');

            html += template.render({
                'class': cls + ' ll' + left + ' lr' + right + ' ' + endingCls,
                left:    left,
                right:   right,
                value:   swarm.diff.spanifyWhitespace($.views.converters.html(pieces[0]))
            });
        });

        return html;
    },

    _renderFullFile: function(diffWrapper) {
        diffWrapper.toggleClass('show-full', diffWrapper.find('.btn-full').hasClass('active'));

        // if full file has already been rendered, return
        if (diffWrapper.find('.diff-inline.active').hasClass('fully-loaded')) {
            swarm.diff.updateSidewaysWidth(diffWrapper);
            diffWrapper.trigger($.Event('show-full'));
            return;
        }

        var allLines    = diffWrapper.data('full-file'),
            inlineTable = $('.diff-inline.active tbody', diffWrapper).first(),
            metaRows    = $('.diff-inline.active tr.diff-type-meta', diffWrapper);

        // loop through each meta row
        // determine the lines we need to pull from the file
        var nextLine = 0, leftStart, leftLength, rightStart, rightLength;
        metaRows.each(function() {
            // extract left/right chunk start and length
            var matches = $('td.line-value .meta-value', this).html().match(
                /@@ \-([0-9]+),([0-9]+) \+([0-9]+),([0-9]+) @@/
            );

            if (!matches) {
                return;
            }

            leftStart   = parseInt(matches[1], 10);
            leftLength  = parseInt(matches[2], 10);
            rightStart  = parseInt(matches[3], 10);
            rightLength = parseInt(matches[4], 10);

            // generate the markup for each line
            var metaRow    = $(this),
                missing    = allLines.slice(nextLine, leftStart - 1);

            metaRow.before(swarm.diff.buildContextRowsHtml(
                missing,
                leftStart - missing.length,
                rightStart - missing.length,
                'diff-full-context'
            ));

            nextLine = leftStart + leftLength - 1;
        });

        // if there are still missing lines add them to the table
        if (nextLine < allLines.length) {
            inlineTable.append(swarm.diff.buildContextRowsHtml(
                allLines.slice(nextLine),
                leftStart + leftLength,
                rightStart + rightLength,
                'diff-full-context'
            ));
        }

        // sideways diff is now stale, destroy it and rebuilt if active
        if (swarm.diff.getDiffMode(diffWrapper) === 'sideways') {
            swarm.diff.showSideways($('.btn-sideways', diffWrapper), true);
        } else {
            $('.diff-sideways', diffWrapper).remove();
        }

        $('.diff-inline.active', diffWrapper).addClass('fully-loaded');
        diffWrapper.trigger($.Event('show-full'));
    },

    renderMoreContext: function(metaRow, howMany) {
        metaRow = $(metaRow);
        if (!metaRow.length) {
            return;
        }

        var diffWrapper = metaRow.closest('.diff-wrapper'),
            files       = swarm.diff._getDiffFiles(diffWrapper);

        // make sure further renderMore calls on this file don't go through
        if (diffWrapper.data('rendering-more-context')) {
            return;
        }
        diffWrapper.data('rendering-more-context', true);

        // use the matching inline meta row, instead of the provided one, which may be
        // a side-by-side meta line. we use the inline diff as our source of truth.
        // we use a low-level query when we are dealing with table rows
        var inlineMetaRow;
        var metaText = metaRow.find('.meta-value').html();
        swarm.query.all('.diff-inline.active tr.diff-type-meta', diffWrapper).each(function() {
            if ($(this).find('.meta-value').html() === metaText) {
                inlineMetaRow = $(this);
                return false;
            }
        });

        // function for walking the tree to find the next available left
        // line number. it will also check the currently passed row
        var getLeftLineNumber = function(row, checkForward) {
            row = $(row);
            if (!row.length) {
                return null;
            }

            // only parse line numbers on diff-content rows, ignoring diff-full context rows,
            // and ignoring .diff-type-add rows, which won't have left line numbers
            if (row.filter('.diff-content').not('.diff-full-context,.diff-type-add').length) {
                return parseInt(row.find('td.line-num-left').data('num'), 10) || null;
            }

            var direction = checkForward ? 'next' : 'prev';
            return getLeftLineNumber(row[direction](), checkForward);
        };

        // find the previous and next line numbers adjacent to this meta line
        var ranges   = [], start, end,
            prevLine = getLeftLineNumber(inlineMetaRow, false),
            nextLine = getLeftLineNumber(inlineMetaRow, true);

        // push on the ranges for the lines that we want to grab
        if (prevLine) {
            start = prevLine + 1;
            end   = prevLine + howMany;

            // include the nextLine right away if we are going to overlap
            if (nextLine && end + 1 >= nextLine) {
                end      = nextLine - 1;
                nextLine = false;
            }
            ranges.push(start + '-' + end);
        }
        if (nextLine) {
            // normalize requests for rows less than 1
            start = nextLine - howMany > 0 ? nextLine - howMany : 1;
            end   = nextLine - 1       > 0 ? nextLine - 1       : 1;
            ranges.push(start + '-' + end);
        }

        // if there are no ranges, then this meta line can be hidden
        // for example: when the left file was empty so there are no
        // left line numbers
        if (!ranges.length) {
            swarm.query.all('tr.diff-type-meta', diffWrapper).each(function() {
                if ($(this).find('.meta-value').html() === metaText) {
                    $(this).addClass('all-context-loaded');
                }
            });

            // hide any tooltips triggered from the original meta row
            metaRow.find('.icon-more-context').each(function() {
                if ($(this).data('tooltip')) {
                    $(this).data('tooltip').hide();
                }
            });
            diffWrapper.data('rendering-more-context', false);
            return;
        }

        $.ajax({
            url:        '/view/' + swarm.encodeURIDepotPath(files.left.path),
            data:       {
                'format' : 'json',
                'v'      : files.left.rev,
                'lines'  : ranges
            },
            dataType:   'json',
            success:    function(response) {
                var insertRows = function(startNum, lines) {
                    if (!startNum || !lines || !lines.length) {
                        return;
                    }

                    // find an existing adjacent row to render these lines beside
                    // first try and find the previous row to these lines
                    // if a previous row was not found, then try and find
                    // the row that would come after these lines
                    var insertBefore;
                    var targetRow = swarm.query.all(
                        '.diff-inline.active tr.ll' + (startNum - 1),
                         diffWrapper
                    ).not('.diff-full-context');
                    if (!targetRow.length) {
                        insertBefore = true;
                        targetRow    = swarm.query.all(
                            '.diff-inline.active tr.ll' + (startNum + lines.length),
                            diffWrapper
                        ).not('.diff-full-context');
                    }

                    // if we still don't have a target row, give up
                    if (!targetRow.length) {
                        return;
                    }

                    var leftRow, rightRow;
                    leftRow = rightRow = targetRow;

                    // if targetRow is a delete, we need to grab the last row of the edit
                    if (targetRow.hasClass('diff-type-delete')) {
                        leftRow   = targetRow;
                        targetRow = targetRow.nextUntil(':not(.diff-type-edit)').last();
                        rightRow  = targetRow;
                    }

                    // calculate how much the right num is offset from the left
                    var sideOffset = parseInt(rightRow.find('td.line-num-right').data('num'), 10)
                                   - parseInt(leftRow.find('td.line-num-left').data('num'), 10);

                    // render the lines into html
                    var rowsHtml   = swarm.diff.buildContextRowsHtml(
                        lines,
                        startNum,
                        startNum + sideOffset,
                        'additional-context-content'
                    );

                    if (insertBefore) {
                        targetRow.before(rowsHtml);
                    } else {
                        targetRow.after(rowsHtml);
                    }
                };

                // split the content we received from the server into
                // sequential chunks and insert them into the dom
                var startLineNum, chunk = [];
                $.each(response, function(lineNum, value) {
                    lineNum = parseInt(lineNum, 10);

                    // if we are the first line, or we have hit a non-sequential chunk
                    // from the previous, we go ahead and insert the rows and initialize
                    // the next chunk
                    if (!startLineNum || startLineNum + chunk.length !== lineNum) {
                        insertRows(startLineNum, chunk);
                        startLineNum = lineNum;
                        chunk        = [];
                    }

                    chunk.push(value);
                });
                insertRows(startLineNum, chunk);

                // if we loaded a large number of lines, we have have made multiple meta lines no longer needed
                // loop through and hide meta lines that no longer have any context to load
                var responseLength = Object.keys(response).length;
                swarm.query.all('.diff-inline.active tr.diff-type-meta', diffWrapper).each(function() {
                    // find the new prevLine number and nextLine number from the metaline
                    // that was clicked so we can detect if the range was filled
                    var $this    = $(this),
                        prevLine = getLeftLineNumber($this, false),
                        nextLine = getLeftLineNumber($this, true);

                    // we need to hide if:
                    //  - the range was filled
                    //  - we hit the beginning of the file
                    //  - we have hit the end of the file
                    if ((prevLine && nextLine && prevLine + 1 >= nextLine)
                        || (nextLine && nextLine === 1)
                        || (!nextLine && $this.is(inlineMetaRow) && responseLength < howMany)
                    ) {
                        $this.addClass('all-context-loaded');
                    }
                });

                // hide any tooltips triggered from the original meta row
                metaRow.find('.icon-more-context').each(function() {
                    if ($(this).data('tooltip')) {
                        $(this).data('tooltip').hide();
                    }
                });

                // sideways diff is now stale, destroy it and rebuilt if active
                if (swarm.diff.getDiffMode(diffWrapper) === 'sideways') {
                    swarm.diff.showSideways(diffWrapper.find('.btn-sideways'), true);
                } else {
                    diffWrapper.find('.diff-sideways').remove();
                }

                diffWrapper.trigger($.Event('show-more-context'));
            },
            complete: function() {
                diffWrapper.data('rendering-more-context', false);
            }
        });
    },

    inlineAll: function() {
        $(".btn-inline").not('.active').each(function() {
            swarm.diff.showInline(this);
        });
        swarm.localStorage.set('diff.mode', 'inline');
    },

    sidewaysAll: function() {
        $(".btn-sideways").not('.active').each(function() {
            swarm.diff.showSideways(this);
        });
        swarm.localStorage.set('diff.mode', 'sideways');
    },

    toggleWhitespace: function(wrapper) {
        var active   = $('.diff-inline.active', wrapper),
            inActive = $('.diff-inline', wrapper).not('.active');

        var toggle   = function() {
            inActive.addClass('active');
            inActive.show().trigger('show');
            active.hide().trigger('hide');

            // toggle full-context if it was active
            if ($('.btn-full', wrapper).is('.active')) {
                swarm.diff.toggleFullFile(wrapper);
            }

            // show sideways mode if it was active
            // otherwise update inline mode appropriately
            if ($('.btn-sideways', wrapper).is('.active')) {
                $('.diff-sideways', wrapper).hide().trigger('hide');

                // don't load a sideways mode for diffs with description
                if (!inActive.find('.diff-description').length) {
                    swarm.diff.showSideways($('.btn-sideways', wrapper));
                }
            } else {
                // update the navElement
                if (wrapper.find(swarm.diff._navElement).length) {
                    swarm.diff.focusNavElement(swarm.diff.getNavElements(wrapper)[0]);
                }

                // update the proxy scroller width
                swarm.diff.updateScrollerWidth(wrapper);
            }
        };

        active.removeClass('active');
        wrapper.toggleClass('ignore-ws', $('.btn-ws', wrapper).is('.active'));

        // toggle and return if we have already loaded the whitespace diff
        if (wrapper.find('.diff-inline.no-ws').length) {
            return toggle();
        }

        swarm.diff.loadDiff(wrapper, function() {
            inActive = wrapper.find('.diff-inline.no-ws');
            toggle();
        });
    },

    getPairedRow: function (row) {
        var side = $(row).closest('.diff-body');
        if (!side.is('.diff-sideways')) {
            return null;
        }

        var other = $(side.data('diff-pair')).find('.diff-table > tbody'),
            index = $(row).index();

        return other.children().length > index ? other.children()[index] : null;
    },

    // grabs line number from the line's classes, this is more accurate than
    // looking in the html line numbers, which are half lost in side-by-side view
    getLineNumber: function(line) {
        var leftClass  = $(line).attr('class').match(/ll([0-9]+)/),
            rightClass = $(line).attr('class').match(/lr([0-9]+)/);

        return {
            left:  leftClass  && parseInt(leftClass[1], 10),
            right: rightClass && parseInt(rightClass[1], 10)
        };
    },

    // returns a string that can be reliably used to find the line within a diff-body
    getLineSelector: function(line) {
        // use left/right numbers from getLineNumber so we can return
        // a class in a predictable order '.ll#.lr#'
        var classes = [],
            lines   = swarm.diff.getLineNumber(line);

        lines.left  = lines.left  && classes.push('ll' + lines.left);
        lines.right = lines.right && classes.push('lr' + lines.right);

        return classes.length ? '.' + classes.join('.') : '';
    },

    spanifyWhitespace: function(content) {
        return content
            .replace(/( )/g,  '<span class="space">$1</span>')
            .replace(/(\t)/g, '<span class="tab">$1</span>');
    },

    spanifyWhitespaceOnPlainLines: function (scope) {
        swarm.query.all('tr:not(.diff-type-edit):not(.diff-type-meta) > td.line-value', scope).each(function() {
            this.innerHTML = this.innerHTML.substr(0, 1) + swarm.diff.spanifyWhitespace(this.innerHTML.substr(1));
        });
    },

    toggleShowWhitespace: function (button) {
        button = $(button);
        button.toggleClass('active');
        button.closest('.diff-wrapper').toggleClass('show-whitespace', button.is('.active'));
    }
};

swarm.changes = {
    _loading: false,

    init: function (path, target, options) {
        target  = $(target);
        var tab = $('.nav-tabs a[href="#' + target.attr('id') + '"]');

        // load changes if they weren't sent with the page and the specified tab is active
        if (!target.children().length && $(tab).has('.active')) {
            swarm.changes.load(path, target, options);
        }

        // load changes when tab is shown and changes are not yet loaded
        // clear existing listener first to avoid connecting multiple times
        $(document).off('shown.changes');
        $(document).on('shown.changes', tab, function() {
            if (!target.children().length) {
                swarm.changes.load(path, target, options);
            }
        });

        // load more changes when user scrolls down
        // clear existing listener first to avoid connecting multiple times
        $(window).off('scroll.changes');
        $(window).on('scroll.changes', function() {
            if (!target.is('.active') || !$('.change-history').length) {
                return;
            }
            if ($.isScrolledToBottom()) {
                swarm.changes.load(path, target, options);
            }
        });
    },

    load: function(path, target, options) {
        if (swarm.changes._loading) {
            return;
        }

        options            = options || {};
        var user           = options.user  instanceof $ ? options.user.val()  : options.user,
            range          = options.range instanceof $ ? options.range.val() : options.range,
            reset          = options.reset,
            changeStatus   = options.status,
            includeReviews = options.includeReviews;

        swarm.changes._loading = true;

        // normalize path (strip leading and trailing slashes
        path = path ? path.replace(/^\/+|\/+$/g, '') : '';

        // normalize user (trim whitespace)
        user = $.trim(user);

        // when reset is true, make sure we only talk to the server when range/user/path have changed
        var table = $('.change-history', target);
        if (reset && path === table.data('path') && range === table.data('range') && user === table.data('user')) {
            swarm.changes._loading = false;
            return;
        }

        // determine which page to load (each page is ~50 changes)
        //  - if no table, page is 1
        //  - if we have a table, but no page number, page is 2
        //  - if we have a table and a page number, increment it.
        var page = table.data('page');
        if (!table.length) {
            page = 1;
        } else if (!page) {
            table.data('page', page = 2);
        } else {
            table.data('page', ++page);
        }

        // only load changes older than the last loaded row
        var last = $('.change-history tbody tr:last', target).attr('id');

        // if reset is true, clear pagination parameters
        if (reset) {
            page = 1;
            last = null;
        }

        // assemble url - if project is set, we route via project-browse route (/project/<project-id>/...)
        // we try to determine project id from table if possible, otherwise from target
        var projectId = table.data('project-id') || $(target).data('project-id'),
            url       = (projectId ? swarm.url('/projects/') + projectId : '') + '/changes/' + path;

        $.ajax({
            url:        url,
            data:       {
                user:           user,
                range:          range,
                after:          last,
                max:            (50 * page),
                format:         'partial',
                status:         changeStatus,
                includeReviews: includeReviews
            },
            dataType:   'text',
            success:    function(data, status, xhr) {
                var responseTable   = $('<div />').append($.parseHTML(data)).find('table'),
                    rows            = responseTable.find('tbody tr');

                if (reset) {
                    table.find('tbody').empty();
                }

                if (!rows.length && (reset || !table.length)) {
                    var message = responseTable.is('.remote')
                        ? swarm.te("Remote depot (change details are not available).")
                        : swarm.te(changeStatus === 'shelved' ? "No shelved changes." : "No matching changes.");

                    if (xhr.getResponseHeader('X-Swarm-Range-Error')) {
                        $(target).html(
                            '<div class="alert alert-danger pad3">' + swarm.te('Range Syntax Error') + '</div>'
                        );
                        $('.range-filter').addClass('control-group error');
                    } else {
                        $(target).html('<div class="alert pad3">' + message + '</div>');
                    }
                    return;
                }

                $(target).find('.alert').remove();
                $('.range-filter').removeClass('control-group error');

                if (target && !table.length) {
                    $(target).append(responseTable);
                } else {
                    $('.change-history tbody', target).append(rows);
                }

                // record current user and path
                table.data('path', path);
                table.data('user', user);
                table.data('range', range);

                // convert times to time-ago
                rows.find('.timeago').timeago();
                rows.find('.description').expander({slicePoint: 90});
            },
            complete: function() {
                $(target).trigger('changes-loaded');

                // enforce a minimal delay between requests
                setTimeout(function(){ swarm.changes._loading = false; }, 500);
            },
            error: function(xhr, status, error) {
                if (xhr.getResponseHeader('X-Swarm-Range-Error')) {
                    $(target).html(
                        '<div class="alert alert-danger pad3">' + swarm.te('Range Syntax Error') + '</div>'
                    );
                    $('.range-filter').addClass('control-group error');
                    this.errorHandled = true;
                }
            }
        });
    },

    openChangeSelector: function(url, data, callback) {
        data.review.path = data.change.basePath || '';
        var modal = $($.templates(
              '<div class="modal hide fade select-change-modal" tabindex="-1" role="dialog" '
            +      'aria-labelledby="select-change-title" aria-hidden="true">'
            +   '<form method="post" class="form-horizontal modal-form">'
            +       '<div class="modal-header">'
            +           '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
            +           '<h3 id="select-change-title">{{te:"Select Change"}}</h3>'
            +       '</div>'
            +       '<div class="modal-body">'
            +           '<input type="hidden" name="id" value="{{>id}}">'
            +           '<div class="messages"></div>'
            +           '<div class="change-input control-group pad2 padw0">'
            +               '<div class="input-prepend">'
            +                   '<span class="add-on">@</span>'
            +                   '<input class="input-small" type="text" pattern="[0-9]*" name="change" id="change" '
            +                          'required placeholder="{{te:"Change"}}">'
            +               '</div>'
            +           '</div>'
            +           '<div class="clearfix">'
            +               '<h4 class="muted">{{te:"Commits"}}</h4>'
            +               '<input class="path pull-left border-box" type="text" name="path" '
            +                      'placeholder="{{te:"Path"}}" value="{{>path}}">'
            +               '<input class="user pull-right border-box" type="text" name="user" '
            +                      'placeholder="{{te:"User"}}" value="{{>author}}">'
            +           '</div>'
            +           '<div class="changes-list">'
            +               '<div class="loading animate muted pad3">{{te:"Loading..."}}</div>'
            +           '</div>'
            +       '</div>'
            +       '<div class="modal-footer">'
            +           '<button type="submit" class="btn btn-primary">{{te:"Select"}}</button>'
            +           '<button type="button" class="btn" data-dismiss="modal">{{te:"Cancel"}}</button>'
            +       '</div>'
            +   '</form>'
            + '</div>'
        ).render(data.review)).appendTo('body');

        // open dialog (auto-width, centered)
        swarm.modal.show(modal);

        swarm.form.checkInvalid($(modal).find('form'));

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post(url, modal.find('form'), function(response) {
                if (!response.isValid) {
                    return;
                }

                callback(modal, response);
            }, modal.find('.messages')[0]);
        });

        // update change field when user clicks on a change table row
        modal.find('.changes-list').on('click', 'tr', function(e) {
            // allow user to middle-click/right-click so they can still
            // open the links if they want
            if (e.button !== 0) {
                return;
            }
            e.preventDefault();

            // only update the value when the change-list isn't disabled
            if (!$(this).closest('.changes-list').is('.disabled')) {
                modal.find('#change').val(parseInt($(this).find('td.change a').text(), 10)).trigger('change');
                modal.find('.changes-list tr').removeClass('active');
                $(this).addClass('active');
            }
        });

        // clear active row if user edits change field manually
        modal.find('#change').on('input keyup blur', function(){
            modal.find('.changes-list tr').removeClass('active');
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });

        // change the changes markup after it loads to removing loading text and better fit the modal
        modal.find('.changes-list').on('changes-loaded', function() {
            $(this).find('.loading').remove();
            $(this).find('thead, td.time').remove();
            $(this).find('.description').expander('destroy').expander({slicePoint: 70});
        });

        // load a list of changes from the server
        swarm.changes.load(
            modal.find('input.path').val(),
            modal.find('.changes-list'),
            { user: modal.find('input.user').val() }
        );

        // try to load more when user scrolls to the bottom
        modal.find('.changes-list').scroll(function() {
            var list = modal.find('.changes-list');
            if ($.isScrolledToBottom(list, list.find('>table'))) {
                swarm.changes.load(
                    modal.find('input.path').val(),
                    modal.find('.changes-list'),
                    { user: modal.find('input.user').val() }
                );
            }
        });

        // reload changes if path or user changes
        modal.find('input.path, input.user').on(
            'input keyup blur',
            function(){
                clearTimeout(swarm.changes.filterTimeout);
                swarm.changes.filterTimeout = setTimeout(function(){
                    swarm.changes.load(
                        modal.find('input.path').val(),
                        modal.find('.changes-list'),
                        { user: modal.find('input.user').val(), reset: true }
                    );
                }, 500);
            }
        );
    }
};
