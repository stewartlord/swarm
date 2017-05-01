/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

swarm.comments = {
    init: function(topic, context, containerNode, counterNode) {
        // record counter node and context on this container so
        // we can make use of them anytime comments update.
        $(containerNode)
            .data('counter-node', counterNode)
            .data('context', context);

        swarm.comments.load(topic, containerNode);

        $(containerNode).on('click.comment.context', '.context a', function() {
            // if the window hash is already set to this location, the
            // hashchange event is not going to fire, so we need to
            // call the handler
            if($(this).attr('href').match(window.location.hash)) {
                setTimeout(swarm.diff.handleHash, 0);
            }
        });

        // regrab all comments on login
        $(document).on('swarm-login', function() {
            var params = {context: $(containerNode).data('context')};
            $.ajax('/comments/' + topic, {data: params}).done(function(data) {
                swarm.comments.update(containerNode, data);
            });
        });

        // focus the comment-add textarea when we click on the comment link
        // if there are no open comments
        $(document).on('shown.comments.tab', 'li a[href="#comments"]', function() {
            if (!$(containerNode).find('table.opened-comments > tbody > tr.row-main').length) {
                $(containerNode).find('.comment-add textarea').focus();
            }
        });

        // link comment task state changes to handler
        $(document).on('click.task-state.menu', '.task-state-menu a', function(e) {
            e.preventDefault();
            var $this   = $(this),
                button  = $($this.closest('.task-state-menu').data('active-target')),
                comment = button.closest('tr.row-main'),
                id      = swarm.comments.getCommentId(comment);

            // transition to new task state and render the new task transitions
            $.post('/comment/edit/' + id, {taskState: $this.data('task-state')}, function(data) {
                if (data.isValid) {
                    var original = $this.data('task-state');

                    // hide the tooltip if one is present
                    if (comment.find('button.dropdown-toggle').data('tooltip')) {
                        comment.find('button.dropdown-toggle').data('tooltip').hide();
                    }

                    // set the new task state and transitions, and render it
                    $('tr.c' + id)
                        .removeClass('task-state-' + comment.data('task-state'))
                        .data('task-transitions', data.taskTransitions)
                        .data('task-state', data.comment.taskState);

                    swarm.comments.renderTaskControls(comment);

                    // if Verify and Archive was chosen, handle the archive UI
                    if (original === 'verified:archive') {
                        $('tr.c' + id).next('.row-append').addBack().toggleClass('closed');
                        swarm.comments.updateArchived(null, comment, containerNode);
                    }

                    // recalculate open/addressed/verified task totals
                    swarm.comments.renderTaskSummary(containerNode);
                }
            });
        });

        // edit comments using the edit link
        $(document).on('click.comments.edit', '.comments-wrapper .edit-comment', function (e) {
            e.preventDefault();

            // locate the intended comment and show the edit form
            swarm.comments.showEditForm($(this).closest('tr.row-append').prev('.row-main'));
        });

        // close/open comments using their top-right button
        $(document).on('click.comments.close', '.comments-wrapper .btn-close', function(e) {
            e.preventDefault();

            var $this   = $(this),
                comment = $this.closest('tr.row-main'),
                action  = comment.is('.closed') ? 'remove': 'add',
                id      = swarm.comments.getCommentId(comment);

            // disable the button so subsequent clicks will not fire
            $('tr.c' + id).find('button.btn-close').prop('disabled', true);
            $('tr.c' + id).next('.row-append').addBack().toggleClass('closed');

            // send request to edit the flag
            var postData = {};
            postData[action + 'Flags'] = ['closed'];
            $.post('/comment/edit/' + id, postData, function(data) {
                swarm.comments.updateArchived($this, comment, containerNode);
                $('tr.c' + id).find('button.btn-close').prop('disabled', false);

                // ensure task summary counts are updated
                swarm.comments.renderTaskSummary(containerNode);
            });
        });

        // like/unlike comment
        $(document).on('click.comments.like', '.comments-wrapper .likes a', function(e) {
            e.preventDefault();

            // send like request
            var likesLink    = $(this),
                likesWrapper = likesLink.closest('.likes'),
                likesCounter = likesWrapper.find('.likes-counter'),
                commentRow   = likesLink.closest('tr.row-append'),
                id           = swarm.comments.getCommentId(commentRow),
                user         = swarm.user.getAuthenticatedUser().id,
                userLiked    = likesWrapper.is('.user-likes');

            $.post('/comment/edit/' + id, userLiked ? {removeLike: user} : {addLike: user}, function (data) {
                likesWrapper.toggleClass('has-likes', data.comment.likes.length > 0);
                likesWrapper.toggleClass('user-likes', !userLiked);
                likesWrapper.find('i').toggleClass('icon-heart', userLiked).toggleClass('swarm-icon icon-heart-red', !userLiked);
                likesCounter.text(data.comment.likes.length);
                likesCounter.attr({'data-original-title': data.comment.likes.join(', '), title: ''});
                likesLink.attr({'data-original-title': swarm.t(userLiked ? 'Like' : 'Unlike'), title: ''});
                if (likesLink.is(':hover')) {
                    likesLink.tooltip('show');
                }
            });
        });

        // automatically inline any images or youtube links that are referenced
        $(containerNode).on('update', function() {
            $(containerNode).find('.comments-table .comment-body a').each(function(){
                var link  = $(this),
                    href  = link.attr('href'),
                    media = null;

                // detect web-safe images
                if (href.match(/\.(gif|png|jpe?g)(\?|$)/i)) {
                    media = $('<img>').attr('src', href);
                }

                // detect youtube videos
                /*jslint regexp: true */
                var re = /(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i;
                /*jslint regexp: false */
                if (href.match(re)) {
                    media = $('<iframe width=640 height=390 frameborder="0" allowfullscreen></iframe>')
                        .attr('src', 'https://www.youtube.com/embed/' + href.match(re)[1]);
                }

                if (media) {
                    link.closest('td').find('.comment-attachments').append(
                        media.wrap('<div class="referenced-media"></div>').parent()
                    );
                }
            });
        });

        // if we have fullFileAPI support, turn comment forms into drop-zones -
        // we do this on demand because it's hard to connect to comment-form creation
        // else, disable drop zone support
        if (swarm.has.fullFileApi()) {
            $('body').on('dragenter', function() {
                $('.comment-form .can-attach').dropZone({
                    uploaderOptions: {
                        extraData: {'_csrf': $('body').data('csrf')},
                        onStart:   swarm.comments.uploaderCallback,
                        onRemove:  swarm.comments.uploaderCallback
                    }
                });
            });
        } else {
            $(containerNode).on('update', function() {
                $(containerNode).find('.textarea-wrapper.can-attach').removeClass('can-attach');
                $(containerNode).find('.drop-note').hide();
            });
        }

        // initial rendering of task-summary - likely won't have data yet
        // we do it anyway so it isn't too jarring when the data comes in
        swarm.comments.renderTaskSummary();

        // update the body value in the form state whenever a change event is fired from a comment form
        $(document).on('change.comment', '.comment-form textarea', function() {
            swarm.comments.setFormState($(this).closest('form'), {body: $(this).val()});
        });

        // delayed notifications tooltip needs to be initialized on update
        $(containerNode).on('update', function() {
            swarm.comments.initDelayTooltip($(containerNode).find('.comment-form form'));
        });
    },

    initDelayTooltip: function(form) {
        var label = form.find('.delay-notifications');
        if (label.data('tooltip')) {
            return;
        }

        label.tooltip({
            html:        true,
            customClass: 'delay-comments-tooltip',
            title:       function () {
                var delayedCount = parseInt(label.find('.delayed-comments-counter').text(), 10),
                    isDelayed    = label.find(':checked').length > 0;

                var title = isDelayed
                    ? swarm.te('Email notifications will be delayed until you post with this option unchecked')
                    : swarm.te('Email notifications will be sent immediately');

                if (delayedCount) {
                    title += '<br>'
                          +  '<span class="muted">'
                          +  swarm.tpe('%d delayed notification', '%d delayed notifications', delayedCount)
                          +  '</span>';
                }

                return title;
            }
        });

        // focus-out can cause a flicker if still hovered over the element
        // if we are still hovered, ignore this event.
        label.on('focusout', function (e){
            if (label.is(':hover')) {
                e.stopPropagation();
            }
        });

        // call show to update the tooltip title when checkbox state changes
        label.find(':checkbox').on('change', function () {
            label.tooltip('show');
        });
    },

    uploaderCallback: function(e) {
        if (e.type === 'remove') {
            return;
        }

        var form    = this.controlsContainer.closest('form'),
            state   = $.extend({uploaders: []}, swarm.comments.getFormState(form)),
            section = this.controlsContainer.closest('.comments-section');

        // add the uploader and set the form state
        state.uploaders.push(this);
        swarm.comments.setFormState(form, state);

        if (section.length) {
            swarm.comments.sizeCommentRowForDiff(section.filter('.comments-row'));
        }
    },

    updateArchived: function(button, comment, containerNode) {
        var $button = $(button);

        // ask all the comment wrappers to update
        $('.comments-wrapper').each(function() {
            swarm.comments.moveClosedComments(this, true);
        });

        // hide the tooltip
        if ($button.data('tooltip')) {
            $button.data('tooltip').hide();
        }

        if (comment.closest('.comments-section').length) {
            var section  = comment.closest('.comments-section'),
                isRow    = section.hasClass('comments-row'),
                paired   = isRow ? $(swarm.diff.getPairedRow(section)) : $(),
                sections = $().add(section).add(paired);

            // if the open table is now empty, and this row has no pending comments, collapse the row
            // else size the comment row we clicked in
            if (!sections.find('.opened-comments tr').length
                && !swarm.comments.hasPending(section)
                && (!paired.length || !swarm.comments.hasPending(paired))
                ) {
                sections.hide().trigger('hide');
            } else if (isRow) {
                swarm.comments.sizeCommentRowForDiff(section);
            }
        } else {
            // schedule a size when files are shown
            $('.change-tabs a[href="#files"]').off('shown.comments.close').one('shown.comments.close', function() {
                $('.diff-wrapper').each(function() {
                    swarm.comments.sizeForDiff(this);
                });
            });
        }

        // update comment counts
        swarm.comments.updateCounts(containerNode);
    },

    load: function(topic, containerNode) {
        // load all comments and place them in the container
        var params = {context: $(containerNode).data('context')};
        $.ajax('/comments/' + topic, {data: params}).done(function(data) {
            swarm.comments.update(containerNode, data);
            swarm.form.checkInvalid($(containerNode).find('form'));
        });
    },

    update: function(containerNode, data) {
        var container = $(containerNode);

        container.html(data);

        // ensure comment form has the appropriate context set.
        container.find('input[name=context]').val(
            JSON.stringify(container.data('context'))
        );

        // we need a reference to commentContainer on the comment-wrapper
        // because the comment-wrapper gets cloned various places in the dom
        container.find('.comments-wrapper').data('comment-container', containerNode);

        // mark each diff file that contains comments
        // we do this here (as opposed to updateDiff) so it runs on all
        // diff-wrappers even those that have never been expanded.
        $('.diff-wrapper').removeClass('has-comments');
        container.find('.comments-table tr.row-main').each(function() {
            var context = $(this).data('context');
            if (context && context.md5) {
                $('.diff-header-affix > a').filter('[name="' + context.md5 + '"]')
                    .closest('.diff-wrapper').addClass('has-comments');
            }
        });

        // move any closed comments to the comment table
        swarm.comments.moveClosedComments(container.find('.comments-wrapper'));

        // update opened/closed (aka. archived) comment count
        swarm.comments.updateCounts(container);

        // focus the textarea if we are on the comments tab and have no open comments
        if($('.change-tabs .active a').is('[href="#comments"]')
            && !container.find('table.opened-comments > tbody > tr.row-main').length) {
            container.find('.comment-add textarea').focus();
        }

        // initialize comment task interface
        var comments = container.find('table.comments-table > tbody > tr.row-main');
        comments.each(function(index, comment) {
            swarm.comments.renderTaskControls(comment);
        });
        swarm.comments.renderTaskSummary(containerNode);

        swarm.comments.applyCommentState($('#comments'));

        container.trigger($.Event('update'));
    },

    updateCounts: function(containerNode) {
        containerNode   = $(containerNode);
        var counter     = $(containerNode.data('counter-node'));
        var openedCount = containerNode.find('.comments-table.opened-comments tr.row-main').length;
        var closedCount = containerNode.find('.comments-table.closed-comments tr.row-main').length;
        var title       = swarm.tp('%s archived', null, closedCount);
        counter.html(openedCount).attr('title', title).attr('data-original-title', title);
    },

    renderTaskSummary: function (containerNode) {
        var container = $(containerNode),
            comments  = container.find('table.opened-comments'),
            open      = comments.find('tr.task-state-open').length,
            addressed = comments.find('tr.task-state-addressed').length,
            verified  = comments.find('tr.task-state-verified').length;

        // if comments have not yet loaded (e.g. initial rendering) render counts as dashes '-'
        if (!container.find('.comments-wrapper').length) {
            open      = '-';
            addressed = '-';
            verified  = '-';
        }

        $('.task-summary').html(
            $.templates(
                  '<div>{{te:"Tasks"}}</div>'
                + '<div class="task-totals">'
                +   '<span class="tasks-open {{if open > 0}}has-value{{/if}}" '
                +         'title="{{te:"Open Tasks"}}" aria-label="{{te:"Open Tasks"}}">'
                +     '<i class="swarm-icon icon-task-open"></i>{{>open}}'
                +   '</span>'
                +   '<span class="tasks-addressed {{if addressed > 0}}has-value{{/if}}" '
                +         'title="{{te:"Addressed Tasks"}}" aria-label="{{te:"Addressed Tasks"}}">'
                +     '<i class="swarm-icon icon-task-addressed"></i>{{>addressed}}'
                +   '</span>'
                +   '<span class="tasks-verified {{if verified > 0}}has-value{{/if}}" '
                +         'title="{{te:"Verified Tasks"}}" aria-label="{{te:"Verified Tasks"}}">'
                +     '<i class="swarm-icon icon-task-verified"></i>{{>verified}}'
                +   '</span>'
                + '</div>'
            ).render({open: open, addressed: addressed, verified: verified})
        );
    },

    renderTaskControls: function(comment) {
        var id              = swarm.comments.getCommentId(comment),
            $comment        = $(comment),
            taskState       = $comment.data('task-state'),
            taskTransitions = $comment.data('task-transitions'),
            disabled        = !swarm.user.getAuthenticatedUser();

        // formats text for tooltips
        var tooltipify = function(title) {
            title = title === 'comment' ? 'Not a' : title;
            return (title.charAt(0).toUpperCase() + title.substr(1).toLowerCase()) + ' Task';
        };

        // re-jig the allowable transitions into an array so it can be used in the template
        taskTransitions = $.map(taskTransitions, function (label, state) {
                return {state : state, css : state.replace(':', '-'), label : label};
            }
        );

        var content =
            $.templates(
                '<button '
              +     'class="bare pad0 dropdown-toggle btn-{{:taskState}} {{if disabled}}disabled{{/if}}" '
              +     'title="{{te:tooltip}}" '
              +     'data-customClass="task-state-tooltip" '
              +     'data-dropdown-container="body" '
              +     'data-toggle="dropdown"'
              + '>'
              +     '<i class="swarm-icon icon-task-{{:taskState}}"></i>'
              +     '<i class="caret"></i>'
              + '</button>'
              + '<ul role="menu" class="task-state-menu dropdown-menu pull-right">'
              +     '{{for taskTransitions}}'
              +         '<li>'
              +             '<a href="#" data-task-state="{{:state}}">'
              +                 '<i class="swarm-icon icon-task-{{:css}}"></i>'
              +                 ' {{>label}}'
              +             '</a>'
              +         '</li>'
              +     '{{/for}}'
              + '</ul>'
            ).render({taskState: taskState, tooltip: tooltipify(taskState), taskTransitions: taskTransitions, disabled: disabled});

        // update all comments with the same id on all tabs
        $('tr.c' + id + '.row-main').each(function(index, comment) {
            $(comment).addClass('task-state-' + taskState).find('.task-state').html(content);
        });
    },

    moveClosedComments: function(wrapper, sort) {
        wrapper         = $(wrapper);
        var closedTable = wrapper.find('.closed-comments'),
            openTable   = wrapper.find('.opened-comments'),
            isInline    = wrapper.closest('.comments-section').length > 0;

        // early exit if there are no comments
        if (!openTable.length) {
            return;
        }

        // create the table if it doesn't already exist
        if (!closedTable.length) {
            closedTable = openTable.clone().toggleClass('opened-comments closed-comments');
            closedTable.find('>tbody').empty();
            wrapper.prepend(
                    '<div class="closed-comments-wrapper">'
                +       '<div role="button" class="closed-comments-header center" aria-expanded="false" tabIndex="0"></div>'
                +       '<div class="closed-comments-body ' + (isInline ? 'hidden' : 'collapse') + '"></div>'
                +   '</div>'
            );
            wrapper.find('.closed-comments-body').append(closedTable).on('shown hidden', function(e) {
                if (e.target === this) {
                    $(this).parent().toggleClass('expanded', e.type === 'shown');
                    $(this).prev('.closed-comments-header').attr('aria-expanded', e.type === 'shown');
                }
            });
            wrapper.find('.closed-comments-header').on('click', function(e) {
                e.preventDefault();

                var body   = $(this).next('.closed-comments-body'),
                    show   = isInline ? body.hasClass('hidden') : !body.hasClass('in');

                // if we are in inline view, toggle hidden class
                // else animate a collapse/expand
                if (isInline) {
                    body.toggleClass('hidden', !show).trigger(show ? 'shown' : 'hidden');
                } else {
                    body.collapse(show ? 'show' : 'hide');
                }
            });
        }

        // move the closed comments to the closed table
        var comments = openTable.find('tr.closed');
        closedTable.find('>tbody').append(comments);
        comments.find('.btn-close').attr({'data-original-title': swarm.t('Restore'), 'aria-label': swarm.t('Restore')});
        if (sort) {
            swarm.comments.sortComments(closedTable.find('tr'));
        }

        // move the open comments to the open table
        comments = closedTable.find('tr').not('.closed');
        openTable.find('>tbody').append(comments);
        comments.find('.btn-close').attr({'data-original-title': swarm.t('Archive'), 'aria-label': swarm.t('Archive')});
        if (sort) {
            swarm.comments.sortComments(openTable.find('tr'));
        }

        // grab the new rows
        var closedRows = swarm.query.all('tr.row-main', closedTable),
            openedRows = swarm.query.all('tr.row-main', openTable);

        // update the closed comment count
        wrapper.find('.closed-comments-header').html(
              '<strong>' + closedRows.length + '</strong> '
            + swarm.tpe('archived comment', 'archived comments', closedRows.length)
        );

        // hide/show the comments tables based on whether they have rows
        openTable.toggleClass('hidden', !openedRows.length);
        wrapper.find('.closed-comments-wrapper').toggleClass('hidden', !closedRows.length);

        // mark comment sections and their previous toggle row as archived-only if there are no opened comments
        wrapper.closest('.comments-section').prev().toggleClass('archived-only', !openedRows.length);
        wrapper.closest('.comments-section').toggleClass('archived-only', !openedRows.length);

        // hide/show the comment context as appropriate
        // if the context is unchanged, hide it
        // if the context is identical on the previous comment, hide it
        // otherwise, show context
        var showHideContext = function(commentWrapper) {
            $(commentWrapper).find('tr.row-main').each(function() {
                var comment         = $(this),
                    commentContext  = comment.data('context').content,
                    line            = comment.closest('.comments-section').prev('.diff-content'),
                    lineContext     = line.length && swarm.comments.getDiffContext(line),
                    previous        = comment.prev().prev().data('context'),
                    previousContext = previous && previous.content,
                    contextElement  = comment.find('.content-context');

                if (lineContext && JSON.stringify(lineContext) === JSON.stringify(commentContext)) {
                    contextElement.hide();
                } else if (previousContext && JSON.stringify(previousContext) === JSON.stringify(commentContext)) {
                    contextElement.hide();
                } else {
                    contextElement.show();
                }
            });
        };

        showHideContext(openTable);
        showHideContext(closedTable);
    },

    sortComments: function(list) {
        // use the array's sort function on the nodelist to sort the list,
        // then move the rows around to match their new index
        [].sort.call(list, function(a, b) {
            var result = swarm.comments.getCommentId(a) - swarm.comments.getCommentId(b);
            return result || ($(a).is('.row-append') ? 1 : -1);
        }).appendTo(list.parent());
    },

    getCommentId: function(comment) {
        var idMatch = $(comment).attr('class').match(/c([0-9]+)/);
        return idMatch && parseInt(idMatch[1], 10);
    },

    add: function(form) {
        form = $(form);
        swarm.form.post('/comments/add', form, function(response) {
            if (response.isValid) {
                // clear any pending comment state data
                swarm.comments.setFormState(form, null);

                // update comments
                swarm.comments.update(
                    form.closest('.comments-wrapper').data('comment-container'),
                    response.comments
                );
            }
        });
    },

    edit: function(form) {
        form        = $(form);
        var comment = form.closest('tr.row-main'),
            id      = swarm.comments.getCommentId(comment);

        swarm.form.post(
            '/comments/edit/' + id,
            form,
            function(response) {
                if (response.isValid) {
                    // clear form state
                    swarm.comments.setFormState(form, null);

                    // update comments
                    swarm.comments.update(
                        comment.closest('.comments-wrapper').data('comment-container'),
                        response.comments
                    );
                }
            },
            null,
            function(form) {
                var data = $(form).serializeArray();
                data.push({name: 'renderComments', value: 'true'});
                if (!$.grep(data, function(element) { return element.name === 'attachments[]'; }).length) {
                    data.push({name: 'attachments', value: ''});
                }
                return data;
            }
        );
    },

    initDiff: function(commentContainer) {
        // recall user's show/hide comments setting
        if (swarm.localStorage.get('diff.comments.hide') === '1') {
            $('.btn.toggle-comments').removeClass('active');
        }

        // set listener to add inline comments when any diff is loaded
        $('.diff-wrapper').on('load', function() {
            var diffWrapper = $(this);

            // add comments link
            diffWrapper.find('.diff-footer').append(
                $.templates(
                      '<div class="file-comments-handle">'
                    + '<i class="icon-comment"></i>'
                    + '<span class="comments-label variable hidden"></span>'
                    + '<span class="comment-form-link variable">'
                    + '{{if user}}<a href="#" class="add-comment" onclick="return false;">{{te:"Add a Comment"}}</a>'
                    + '{{else}}'
                    + '<a href="{{url:"/login"}}" class="login" onclick="swarm.user.login(); return false;">'
                    +   '{{te:"Log in to comment"}}'
                    + '</a>'
                    + '{{/if}}</span></div>'
                ).render({user: swarm.user.getAuthenticatedUser()})
            );

            // bind the wrapper and the comments together if this is the first load
            if (!diffWrapper.data('comment-container')) {
                // track the commentContainer on the diff wrapper
                diffWrapper.data('comment-container', commentContainer);

                // update diff when comments update
                $(commentContainer).on('update', function() {
                    swarm.comments.updateDiff(diffWrapper);
                });
            }

            swarm.comments.updateDiff(diffWrapper);
        });

        // swap the login link to be an add comment link in the footer after login
        $(document).on('swarm-login', function() {
            $('.diff-footer').find('.file-comments-handle .comment-form-link').html(
                '<a href="#" class="add-comment">' + swarm.te('Add a Comment') + '</a>'
            );
        });

        // when a login link has been clicked, force comment line to remain open until the next update
        $('.diff-wrapper').on('click.comments.login', '.comment-form-link a.login', function(e) {
            var section = $(this).closest('.comments-section');
            if (!section.length) {
                section = $(this).closest('.diff-footer').find('.file-comments-handle');
            }

            swarm.comments.setSectionState(section, {pending: {login:true}});
        });

        // clear pending login states when the login dialog is canceled
        $(document).on('hidden.comments.login', '.login-dialog', function(e) {
            // if we now have a valid user, the login was successful
            // the login state will be cleared as the comments update
            if (swarm.user.getAuthenticatedUser()) {
                return;
            }

            // else, after a cancel we need to clear them ourselves
            $('.diff-wrapper').each(function() {
                swarm.comments.clearPendingLogin(this);
            });
        });

        // listen for click events lines and show/hide comments section
        $('.diff-wrapper').on('click.comment.line', 'tr.diff, .file-comments-handle', function(e) {
            // ignore middle or right clicks, and selections
            var selection      = window.getSelection(),
                selectElements = $(selection.anchorNode).add(selection.focusNode);
            if (e.button !== 0 || (!selection.isCollapsed && selectElements.closest('.diff-body').length)) {
                return;
            }

            // ignore clicks on meta and padding rows
            var line = $(this);
            if (line.hasClass('diff-type-meta') || line.hasClass('line-pad')) {
                return;
            }

            // ignore clicks on file-comments if there are no comments and user is not logged in
            if (!swarm.user.getAuthenticatedUser() && line.hasClass('file-comments-handle')
                    && !line.hasClass('has-comments')) {
                return;
            }

            var lines     = $().add(line),
                isDiffRow = line.hasClass('diff');
            if (isDiffRow) {
                var side       = line.closest('.diff-sideways'),
                    pairedRow  = $(swarm.diff.getPairedRow(line));

                lines          = lines.add(pairedRow);

                // determine which side to show the comment form
                // the only case when the comment form should be shown on left
                // is when the line is delete, in all other cases (add or edit)
                // the form should go on right, thus the only case when we need
                // to switch the sides is when user clicks on left on the row
                // that is not a delete
                if (!line.is('.diff-type-delete') && side.is('.left-side')) {
                    // swap line with the paired line
                    line = pairedRow;
                }
            }

            // if comments are already shown, hide them and exit
            // remove rows with no comments after fading
            var section = line.next('.comments-section');
            if (section.is(':visible') && section.has('.comments-wrapper').length) {
                var commentRows = lines.next('.comments-section');
                commentRows.fadeOut({
                    complete: function(){
                        $(this).trigger('hidden');
                        // remove if both sides are empty
                        if (!commentRows.find('.has-comments').length
                            && !commentRows.find('.comment-add textarea').val()
                            && !commentRows.find('.drop-controls div').length
                        ) {
                            $(this).prev().removeClass('has-comments');
                            $(this).remove();
                        }
                    }
                }).trigger('hide');

                return;
            }

            var commentWrapper = isDiffRow
                               ? swarm.comments.getLineCommentWrapper(line)
                               : swarm.comments.getFileCommentWrapper(line.closest('.diff-wrapper'));

            lines.next('.comments-section').show().trigger('show');

            // if the line contains only archived comments, show them right away
            var archived = lines.filter('.archived-only').next('.comments-section');
            archived.find('.closed-comments-body').removeClass('hidden').trigger('shown');

            // if there are no existing comments, show add form (this will call a resize)
            // else ensure comment is sized correctly
            if (!commentWrapper.find('.has-comments').length) {
                swarm.comments.showAddForm(commentWrapper);
            } else {
                swarm.comments.sizeCommentRowForDiff(commentWrapper.closest('.comments-row'));
            }
        });

        // connect a handler to hide
        // an empty section (i.e. containing just a form,  but no comments)
        // if the section loses focus
        $('.diff-wrapper').on('focusout.comment', '.comments-section', function() {
            var $this = $(this),
                isRow = $this.hasClass('comments-row');

            setTimeout(function() {
                var pairedRow   = isRow && $(swarm.diff.getPairedRow($this)),
                    active      = document.activeElement,
                    state       = swarm.comments.getSectionState($this) || {},
                    isLogin     = state.pending && state.pending.login,
                    isActive    = $this.is(active),
                    hasActive   = isActive || $this.find(active).length,
                    hasValue    = $this.find('textarea').val(),
                    hasControls = $this.find('.drop-controls div').length,
                    hasComments = $this.find('.comments-table tr').length;

                if (pairedRow && pairedRow.length) {
                    var pairedState = swarm.comments.getSectionState(pairedRow) || {};
                    isLogin         = isLogin     || (pairedState.pending && pairedState.pending.login);
                    isActive        = isActive    || pairedRow.is(active);
                    hasActive       = hasActive   || pairedRow.find(active).length;
                    hasValue        = hasValue    || pairedRow.find('textarea').val();
                    hasControls     = hasControls || pairedRow.find('.drop-controls div').length;
                    hasComments     = hasComments || pairedRow.find('.comments-table tr').length;
                }

                if (isLogin || hasActive || hasValue || hasComments || hasControls) {
                    return;
                }

                // fade comment section and remove them after as they contain no comments
                $this.add(pairedRow || null).fadeOut({
                    complete: function() {
                        $(this).prev().removeClass('has-comments');
                        $(this).trigger('hidden');
                        $(this).remove();
                    }
                }).trigger('hide');
            }, 500);
        });

        // update the comment-rows collapsed state whenever the row is hidden/shown
        $('.diff-wrapper').on('show.comment.row hide.comment.row', '.comments-section', function(e) {
            if ($(e.target).is('.comments-section')) {
                swarm.comments.setSectionState($(this), {collapsed: (e.type === 'hide')});
            }
        });

        // apply previous comment states whenever the user enters inline-mode,
        // we don't need a listener for the side-by-side modes because they perform
        // the apply in 'updateSidewaysDiff'
        $('.diff-wrapper').on('show.comment.state.inline', '.diff-inline', function(e) {
            if ($(e.target).is('.diff-inline')) {
                swarm.comments.applyCommentState($(this).closest('.diff-wrapper'));
            }
        });

        // resize comment height when comments tables are shown or, comment errors change, or the textarea size changes
        $('.diff-wrapper').on('textarea-resize', '.comment-form textarea', function() {
            swarm.comments.sizeCommentRowForDiff($(this).closest('.comments-row'));
        });
        $('.diff-wrapper').on('form-errors', '.comment-add form, form.comment-edit', function() {
            swarm.comments.sizeCommentRowForDiff($(this).closest('.comments-row'));
        });
        $('.diff-wrapper').on(
            'shown.closed.comments, hidden.closed.comments', '.comments-wrapper .closed-comments-body',
            function(e) {
                if (this === e.target) {
                    swarm.comments.sizeCommentRowForDiff($(this).closest('.comments-row'));
                }
            }
        );

        // anytime diff is shown sideways, rebuild comments
        $('.diff-wrapper').on('show.comment.sideways', '.diff-sideways.left-side', function(e) {
            if ($(e.target).is('.diff-sideways.left-side')) {
                swarm.comments.updateSidewaysDiff($(this).closest('.diff-wrapper'));
            }
        });

        // anytime full/more context is triggered, update presentation
        $('.diff-wrapper').on('show-full show-more-context', function() {
            swarm.comments.updateDiff(this);
        });

        // update comment heights when the wrapper resizes
        $('.diff-wrapper').on('diff-resize', function() {
            swarm.comments.sizeForDiff(this);
        });
    },

    // update all comments in specified diff wrapper
    updateDiff: function(diffWrapper) {
        diffWrapper          = $(diffWrapper);
        var commentContainer = diffWrapper.data('comment-container'),
            file             = diffWrapper.data('file').depotFile,
            inlineContainers = diffWrapper.find('.diff-inline, .diff-footer');

        // function for cloning and modifying comments for inline display
        var cloneComment = function(comment) {
            var commentCopy = comment.add(comment.next('.row-append')).clone(true);
            commentCopy.find('img.loaded').removeClass('loaded');
            commentCopy.find('.context').remove();
            commentCopy.find('.timeago').timeago();
            commentCopy.find('form').remove();
            commentCopy.removeClass('edit-mode').next().removeClass('edit-mode');
            return commentCopy;
        };

        // if the files tab isn't currently active, we need to wait for it
        // to be shown before we update diff
        if (!$('#files.active').length) {
            $('.change-tabs a[href="#files"]').off('shown.comments.update');
            $('.change-tabs a[href="#files"]').one('shown.comments.update', function() {
                swarm.comments.updateDiff(diffWrapper);
            });
            return;
        }

        // empty all inline comments to keep the page height intact
        inlineContainers.find('tr.has-comments, .file-comments-handle.has-comments').removeClass('has-comments');
        inlineContainers.find('.comments-cell').empty();

        // insert comments for this file
        var fileCommentWrapper;
        $(commentContainer).find('.comments-table tr.row-main').each(function() {
            var comment   = $(this),
                context   = comment.data('context'),
                lineClass = (context.leftLine  ? '.ll' + context.leftLine  : '')
                          + (context.rightLine ? '.lr' + context.rightLine : '');

            // ignore comments that don't match this file
            if (context.file !== file) {
                return true;
            }

            // handle file level comments, comments with no lineClass
            if (!lineClass) {
                // clone and cleanup the comment
                var commentCopy = cloneComment(comment);

                // add the new comment row to the table
                fileCommentWrapper = fileCommentWrapper || swarm.comments.getFileCommentWrapper(diffWrapper);
                fileCommentWrapper.find('.comments-table').addClass('has-comments').find('tbody').append(commentCopy);

                return true;
            }

            // add comments for each inline view
            diffWrapper.find('.diff-inline').each(function() {
                // if the original line commented on only had whitespace changes
                // the line class may no longer return a line, try to match up against
                // one of the lines, starting with the left
                var line = $(this).find(lineClass);
                if (!line.length) {
                    if (context.leftLine) {
                        line = $(this).find('.ll' + context.leftLine);
                    } else {
                        line = $(this).find('.lr' + context.rightLine);
                    }
                }

                // filter out context lines, depending on the current show-context mode
                // in full context mode we filter out the additional-context lines
                // else we filter out just the full context rows
                if (diffWrapper.hasClass('show-full')) {
                    line = line.not('.additional-context-content');
                } else {
                    line = line.not('.diff-full-context');
                }

                // if we still have no line continue with next diff
                if (!line.length) {
                    return true;
                }

                // check if content matches - if it doesn't, try to relocate
                // the comment provided we have at least 3 lines of context.
                var match, current = swarm.comments.getDiffContext(line);
                if (JSON.stringify(current) === JSON.stringify(context.content)) {
                    match = true;
                } else if (context.content && context.content.length >= 3) {
                    match = swarm.comments.findDiffContent(context.content, this);
                    line  = match || line;
                }

                // clone and cleanup the comment
                var commentCopy = cloneComment(comment);

                // add the new comment row to the table
                swarm.comments.getLineCommentWrapper(
                    line
                ).find('.comments-table').addClass('has-comments').find('tbody').append(commentCopy);
            });
        });

        // remove empty comment rows
        inlineContainers.find('.comments-section').not(':has(.has-comments)').remove();

        // update file comment count
        var additionalCount = diffWrapper.find('.diff-footer .comments-section tr.row-main').length;
        diffWrapper.find('.diff-footer .file-comments-handle .comments-label').html(
              '<a href="#" onclick="return false;">'
            + (additionalCount || "") + '</strong> '
            + swarm.tpe('comment', 'comments', additionalCount)
            + '</a>'
        ).toggleClass('hidden', additionalCount === 0);
        diffWrapper.find('.diff-footer .file-comments-handle .comment-form-link').toggleClass('hidden', additionalCount !== 0);

        // ignore diffWrappers with no comments
        if (inlineContainers.find('.comments-section').length === 0 && !swarm.comments.hasPending(diffWrapper)) {
            return;
        }

        // move closed comments into their own table in each row
        diffWrapper.find('.comments-wrapper').each(function() {
            swarm.comments.moveClosedComments(this);
        });

        // if inline comments are supposed to be hidden, hide them now
        // else just hide the sections containing only archived comments
        if (swarm.localStorage.get('diff.comments.hide') === '1') {
            diffWrapper.find('.comments-section').hide();
        } else {
            diffWrapper.find('.comments-section.archived-only').hide();
        }

        // if sideways is active, update it else remove all sideways diff
        if (swarm.diff.getDiffMode(diffWrapper) === 'sideways') {
            swarm.diff.showSideways(diffWrapper.find('.btn-sideways'), true);
        } else {
            diffWrapper.find('.diff-sideways').remove();
            // apply any previous comment state
            swarm.comments.applyCommentState(diffWrapper);
        }

        // only trigger comments loaded event when there are actual comments
        if ($(commentContainer).find('.comments-table tr').length) {
            diffWrapper.data('comments-loaded', true).trigger($.Event('comments-loaded'));
        }

        swarm.comments.clearPendingLogin(diffWrapper);
    },

    // check comment state for pending comments, target can be a state object,
    // a comment section dom object, or the diff wrapper
    hasPending: function(target) {
        // dom target path
        if(target instanceof window.jQuery || target instanceof window.HTMLElement) {
            target = $(target);
            if (target.hasClass('diff-wrapper')) {
                // check for any pending comments on diff-wrapper
                var pendingComments = false;
                $.each(target.data('comment-state') || {}, function(key, state) {
                    pendingComments = swarm.comments.hasPending(state);
                    return !pendingComments;
                });
                return pendingComments;
            }

            // check for pending comments on comment section dom object
            return swarm.comments.hasPending(swarm.comments.getSectionState(target));
        }

        // state path
        // assume target is a state object and check for pending
        var body      = target.pending && !!target.pending.body,
            login     = target.pending && !!target.pending.login,
            uploaders = target.pending && target.pending.uploaders && !!target.pending.uploaders.length;
        return login || body || uploaders;
    },

    // size comment rows in the given diff wrapper
    sizeForDiff: function(diffWrapper) {
        diffWrapper = $(diffWrapper);

        // defer sizing for collapsed diffWrappers because we can't measure their size
        diffWrapper.each(function() {
            var $this   = $(this),
                details = $this.find('.diff-details');
            if ($this.data('diff-loaded') && !details.hasClass('in')) {
                details.off('show.size.comments').on('show.size.comments', function (e) {
                    if (details.is(e.target)) {
                        details.off('show.size.comments');
                        // use a timeout because the details will be 'display: none'
                        // until immediately after this event
                        setTimeout(function() {
                            swarm.comments.sizeForDiff($this);
                        }, 0);
                    }
                });
            }
        });

        // set explicit height on comments-row cells
        diffWrapper.find('.diff-details.in .diff-body').each(function(){
            var diffBody = $(this);
            // if we are not in left side and the paired side is not display:none,
            // do nothing as it gets automatically updated with the paired side
            if (diffBody.filter('.diff-sideways').not('.left-side').length
                    && $(diffBody.data('diff-pair')).css('display') !== 'none') {
                return true;
            }

            diffBody.find('.comments-row').filter(':visible').each(function(){
                swarm.comments.sizeCommentRowForDiff(this);
            });
        });
    },

    sizeCommentRowForDiff: function(row) {
        row          = $(row);
        var diffBody = row.closest('.diff-body'),
            height   = row.find('.comments-wrapper').outerHeight();

        // set the height on comment rows; in sideways mode we ensure that paired
        // rows in both sides will have the same height (set to maximum of both)
        if (diffBody.is('.diff-sideways')) {
            var pairedRow = swarm.diff.getPairedRow(row);
            row           = row.add(pairedRow);
            height        = Math.max(
                height,
                $(pairedRow).find('.comments-wrapper').outerHeight()
            );
        }

        row.find('>td').height(height);
    },

    updateSidewaysDiff: function(diffWrapper) {
        diffWrapper = $(diffWrapper);

        // return if there are no comments at all
        if (!diffWrapper.find('.comments-section').length) {
            // still need to apply state if there are pending comments
            if (swarm.comments.hasPending(diffWrapper)) {
                swarm.comments.applyCommentState(diffWrapper);
            }
            return;
        }

        // remove comments that fall on the wrong side
        diffWrapper.find('.diff-sideways.left-side  tr.comments-row.diff-type-add').remove();
        diffWrapper.find('.diff-sideways.left-side  tr.comments-row.diff-type-same').remove();
        diffWrapper.find('.diff-sideways.right-side tr.comments-row.diff-type-delete').remove();

        // when the diff code builds the side-by-side view it inserts
        // padding rows to ensure that the left and right side line up
        // we want our comments to appear before these padding rows
        diffWrapper.find('.diff-sideways tr.comments-row').each(function() {
            var target = $(this).prev();
            while (target.hasClass('line-pad')) {
                target = $(target).prev();
            }
            $(this).insertAfter(target);
        });

        // loop through rows on the left and right - ensure that comment rows
        // appear on both sides
        diffWrapper.find('.diff-sideways.left-side').each(function() {
            var i, leftRow, rightRow, leftIsComment, rightIsComment, archived,
                leftSideRows  = $(this).find('tr.diff'),
                rightSideRows = $($(this).data('diff-pair')).find('tr.diff');
            for (i = 0; i < leftSideRows.length; i++) {
                leftRow        = $(leftSideRows[i].nextSibling);
                rightRow       = $(rightSideRows.length <= i ? null : rightSideRows[i].nextSibling);
                leftIsComment  = leftRow.hasClass('comments-row');
                rightIsComment = rightRow.hasClass('comments-row');

                if (leftIsComment && !rightIsComment) {
                    rightRow = leftRow.clone();
                    rightRow.find('.comments-cell').empty();
                    rightRow.insertAfter(rightSideRows.eq(i));
                }
                if (rightIsComment && !leftIsComment) {
                    leftRow = rightRow.clone();
                    leftRow.find('.comments-cell').empty();
                    leftRow.insertAfter(leftSideRows.eq(i));
                }

                // if both sides are comments and only one is an archived-only row, make sure it is shown
                if (leftIsComment && rightIsComment && swarm.localStorage.get('diff.comments.hide') !== '1') {
                    archived = leftRow.add(rightRow).filter('.archived-only');
                    if (archived.length === 1) {
                        archived.show();
                    }
                }
            }
        });

        // apply any previous state, pending textarea value, collapsed state, etc
        swarm.comments.applyCommentState(diffWrapper);
    },

    // creates and returns a detached comment wrapper jQuery node
    _createCommentWrapper: function(commentContainer, context) {
        commentContainer = $(commentContainer);

        var wrapper = $(
              '<div class="comments-wrapper border-box variable">'
            + ' <table class="table opened-comments comments-table">'
            + '  <tbody></tbody>'
            + ' </table>'
            + '</div>'
        );

        var addComment = commentContainer.find('.comment-add').clone(),
            form       = addComment.find('form');

        // add the context to the form
        context        = $.extend(context, commentContainer.data('context'));
        form.find('input[name=context]').val(JSON.stringify(context));
        form.find('textarea').val('');
        form.find('.drop-controls').html('');
        addComment.find('img.loaded').removeClass('loaded');
        wrapper.append(addComment);

        swarm.form.clearErrors(form, true);
        swarm.form.checkInvalid(form);

        // copy the comment container reference to the new wrapper
        wrapper.data('commentContainer', commentContainer);

        return wrapper;
    },

    // return comments wrapper for file comments that don't have a row
    getFileCommentWrapper: function(diffWrapper) {
        diffWrapper = $(diffWrapper);

        var commentContainer = diffWrapper.data('comment-container'),
            footer           = diffWrapper.find('.diff-footer'),
            handle           = footer.find('.file-comments-handle'),
            section          = footer.find('.file-comments-section'),
            wrapper          = section.find('.comments-wrapper');

        // create the section if it doesn't already exist
        if (!section.length) {
            section = $(
                '<div class="file-comments-section comments-section comments-cell" tabIndex="0" />'
            ).insertAfter(handle);
        }

        // create the wrapper if it doesn't already exist
        if (!wrapper.length) {
            wrapper = swarm.comments._createCommentWrapper(commentContainer, {
                file: diffWrapper.data('file').depotFile
            }).appendTo(section);
            wrapper.find('.btn').addClass('btn-small');
        }

        handle.addClass('has-comments');

        swarm.comments.initDelayTooltip(wrapper.find('.comment-add form'));

        return wrapper;
    },

    // return comments wrapper for inline comments for a given row
    getLineCommentWrapper: function(line) {
        var commentContainer = $(line).closest('.diff-wrapper').data('comment-container'),
            row              = $(line).next('tr.comments-row'),
            wrapper          = row.find('.comments-wrapper');

        // create the row if it doesn't already exist
        if (!row.length) {
            var rowClass = $(line).attr('class').match(/diff-(type|full)-[a-z]+/gi).join(' ');
            row = $(line).clone().insertAfter(line).attr('class', 'comments-section comments-row ' + rowClass);
            row.find('.line-value').attr('class', 'comments-cell');
            row.find('.line-num').attr('data-num', '');
            row.find('td').empty();
        }

        // create the wrapper if it doesn't already exist
        if (!wrapper.length) {
            var lineNumbers = swarm.diff.getLineNumber(line);
            wrapper         = swarm.comments._createCommentWrapper(commentContainer, {
                file:      $(line).closest('.diff-wrapper').data('file').depotFile,
                leftLine:  lineNumbers.left,
                rightLine: lineNumbers.right,
                content:   swarm.comments.getDiffContext(line)
            }).appendTo(row.find('.comments-cell'));

            // the addcomment table only shows for logged in users
            var addComment = wrapper.find('.comment-add');
            if (addComment.find('table').length) {
                $('<a />', {href:'#', text:swarm.t('Add a Comment')}).click(function() {
                    swarm.comments.showAddForm($(this).closest('.comments-wrapper'));
                    return false;
                }).appendTo($('<div />', {'class': 'comment-form-link'}).appendTo(addComment));
                addComment.find('.btn').addClass('btn-small');
                var hidden = addComment.find('textarea').val()
                           ? addComment.find('.comment-form-link')
                           : addComment.find('table');
                hidden.addClass('hidden');
            }

            // indicate that the line has comments
            $(line).addClass('has-comments');
        }

        // in sideways mode every comment row needs a corresponding comment row on the opposite side
        var pairedRow = swarm.diff.getPairedRow(line);
        if (pairedRow && !$(pairedRow).next('.comments-row').length) {
            row.clone().insertAfter(pairedRow).find('.comments-cell').empty();
        }

        return wrapper;
    },

    applyPendingUploaders: function(form, uploaders) {
        if (!uploaders) {
            return;
        }
        form.find('.can-attach').dropZone({uploaderOptions: {
            extraData: {'_csrf': $('body').data('csrf')},
            onStart:   swarm.comments.uploaderCallback,
            onRemove:  swarm.comments.uploaderCallback
        }});
        $.each(uploaders, function(index, uploader) {
            form.find('.can-attach').data('dropZone').addUploader(uploader);
        });
    },

    applyCommentState: function(container) {
        // we have special handling for comments that appear in the files tab
        // there are additional concerns when comments appear in that context
        if (container.is('.diff-wrapper')) {
            swarm.comments.applyDiffCommentState(container);
            return;
        }

        swarm.comments.applyEditCommentState(container);

        // restore add form state on comments tab
        var form = container.find('.comment-add form');
        if (!form.length) {
            return;
        }

        var state = swarm.comments.getFormState(form);
        swarm.comments.applyPendingUploaders(form, state.uploaders);
        form.find('textarea').val(state.body || '');
        swarm.form.checkInvalid(form);
    },

    // this function applies pending comments to comment forms on the files tab
    // and collapses/expands comment rows based on their stored state
    applyDiffCommentState: function(diffWrapper) {
        var defaultCollapsed = swarm.localStorage.get('diff.comments.hide') === '1',
            diffFooter       = $(diffWrapper).find('.diff-footer');

        var diffBody = swarm.diff.getActiveDiffBody(diffWrapper);
        if (!diffBody.length) {
            return;
        }

        // loop over saved states, states are keyed on lineClass eg. '.ll#.lr#'
        // this makes it easy for us to locate the lines to apply each state to
        $.each($(diffWrapper).data('comment-state') || {}, function(key, state) {
            state = $.extend({collapsed: defaultCollapsed}, state);

            // restore pending file level adds
            diffFooter.find(key).each(function() {
                var hasPending = swarm.comments.hasPending(state);
                // if it doesn't have any comments, remove the comments section
                if (!hasPending && !$(this).next('.comments-section').find('.has-comments').length) {
                    $(this).removeClass('has-comments').next('.comments-section').remove();
                    return;
                }

                // set the textarea value to be the pending comment text
                var wrapper = swarm.comments.getFileCommentWrapper(diffWrapper);
                var pendingBody = (state.pending && state.pending.body) || '';
                wrapper.find('.comment-add textarea').val(pendingBody).trigger('change');
                wrapper.closest('.comments-section').toggle(!state.collapsed);

                // append any uploader data
                swarm.comments.applyPendingUploaders(
                    wrapper.find('.comment-add form'),
                    (state.pending && state.pending.uploaders) || []
                );

                // if it is showing and there was pending data,
                // make sure the commentForm is toggled open
                if (!state.collapsed && hasPending) {
                    swarm.comments.showAddForm(wrapper);
                }
            });

            // restore pending inline adds
            diffBody.find(key).each(function() {
                // the rule for placement of comments in side-by-side mode is:
                //  - comments on deleted lines go on the left-side
                //  - comments on added or context lines go on the right-side

                // skip added/context lines on the left and deleted lines on the right.
                // this works out ok because when we apply the state on one side, we
                // take care of the other side.
                var diffBody = $(this).closest('.diff-body'),
                    isDelete = $(this).hasClass('diff-type-delete');
                if ((diffBody.hasClass('left-side') && !isDelete) || (diffBody.hasClass('right-side') && isDelete)) {
                    return;
                }

                // find any paired line and state
                var pairedRow        = swarm.diff.getPairedRow(this),
                    pairedState      = swarm.comments.getSectionState(pairedRow) || state,
                    hasPending       = swarm.comments.hasPending(state),
                    pairedHasPending = swarm.comments.hasPending(pairedState);

                // if neither our current or paired lines have comments,
                // remove the comments row from both
                var currentHasComments = hasPending || $(this).next().find('.has-comments').length !== 0,
                    pairedHasComments  = pairedHasPending || $(pairedRow).next().find('.has-comments').length !== 0;
                if (!currentHasComments && !pairedHasComments) {
                    $(this).removeClass('has-comments').next('.comments-row').remove();
                    $(pairedRow).removeClass('has-comments').next('.comments-row').remove();
                    return;
                }

                // set the textarea value to be the pending comment text
                var wrapper     = swarm.comments.getLineCommentWrapper(this);
                var pendingBody = (state.pending && state.pending.body) || '';
                wrapper.find('.comment-add textarea').val(pendingBody).trigger('change');

                // append any uploader data
                swarm.comments.applyPendingUploaders(
                    wrapper.find('.comment-add form'),
                    (state.pending && state.pending.uploaders) || []
                );

                // toggle the row/paired visibility based on the collapsed state
                // if either left or right are not collapsed, we need to show this row.
                var show = !state.collapsed || (pairedHasComments && !pairedState.collapsed);
                wrapper.closest('.comments-row').toggle(show);
                $(pairedRow).next('.comments-row').toggle(show);

                // if row is showing and there was pending data,
                // make sure the commentForm is toggled open
                if (show && hasPending) {
                    swarm.comments.showAddForm(wrapper);
                }
            });

            // restore pending edits
            swarm.comments.applyEditCommentState(diffBody);
            swarm.comments.applyEditCommentState(diffFooter);
        });

        // size comment rows (it will automatically sync the height of paired rows)
        swarm.comments.sizeForDiff(diffWrapper);
    },

    applyEditCommentState: function(container) {
        var states = $('#comments').data('comment-state') || {};
        $.each(states, function (commentId) {
            var comment = container.find('.row-main.c' + commentId);
            if (comment.length) {
                swarm.comments.showEditForm(comment);
            }
        });
    },

    clearPendingLogin: function(diffWrapper) {
        // loop over saved states, and clear the pending login
        $.each($(diffWrapper).data('comment-state') || {}, function(key, state) {
            if (state.pending && state.pending.login) {
                swarm.comments.setState(diffWrapper, key, {pending: {login: null}});
            }
        });
    },

    setState: function(diffWrapper, key, state) {
        if (!key) {
            return;
        }

        diffWrapper = $(diffWrapper);
        var states  = diffWrapper.data('comment-state') || {};

        // if null was passed for state, delete the state
        // else apply state changes to the comment-state data property
        if (state === null) {
            delete states[key];
        } else {
            states[key] = $.extend(true, states[key], state);
        }

        diffWrapper.data('comment-state', states);
    },

    setSectionState: function(sections, state) {
        $(sections).each(function() {
            var section = $(this);
            swarm.comments.setState(section.closest('.diff-wrapper'), swarm.comments.getSectionKey(section), state);
        });
    },

    setFormState: function(form, state) {
        form = $(form);

        var section = form.closest('.comments-section');
        if (form.is('.comment-edit')) {
            swarm.comments.setState($('#comments'), swarm.comments.getCommentId(form.closest('.row-main')), state);
        } else if (section.length) {
            swarm.comments.setSectionState(section, {pending: state});
        } else {
            swarm.comments.setState($('#comments'), 'add', state);
        }
    },

    getFormState: function(form) {
        form = $(form);

        var state, section = form.closest('.comments-section');
        if (form.is('.comment-edit')) {
            state = $('#comments').data('comment-state') || {};
            state = state[swarm.comments.getCommentId(form.closest('.row-main'))];
        } else if (section.length) {
            state = swarm.comments.getSectionState(section) || {};
            state = state.pending;
        } else {
            state = $('#comments').data('comment-state') || {};
            state = state.add;
        }

        return state || {};
    },

    getSectionState: function(section) {
        if (!$(section).length) {
            return;
        }

        var defaultState = {
            collapsed : (swarm.localStorage.get('diff.comments.hide') === '1')
        };

        var wrapper = $(section).closest('.diff-wrapper'),
            key     = swarm.comments.getSectionKey(section),
            states  = wrapper.data('comment-state') || {};

        return key && $.extend(defaultState, states[key]);
    },

    getSectionKey: function(section) {
        var key;
        section = $(section);

        if (section.hasClass('comments-row')) {
            var line = section.prev();
            key = line.length ? swarm.diff.getLineSelector(line) : null;
        } else if (section.hasClass('diff')) {
            key = swarm.diff.getLineSelector(section);
        } else if (section.hasClass('file-comments-section') || section.hasClass('file-comments-handle')) {
            key = '.file-comments-handle';
        }

        return key;
    },

    // return line's diff context (i.e. array with several preceding code lines in diff)
    getDiffContext: function(line) {
        // find the line in the inline diff (the diff context might be different in sideways)
        line = $(line).closest('.diff-wrapper').find('.diff-inline.ws').find(swarm.diff.getLineSelector(line));

        // get up to 5 preceding lines of code
        var content = [];
        while (content.length < 5) {
            // capture up to 256 characters of content lines
            // stop if we hit anything that isn't a comment or content
            if (line.is('.diff-content')) {
                content.unshift(line.find('.line-value').text().slice(0, 256));
            } else if (!line.is('.comments-row')) {
                break;
            }
            line = line.prev();
        }

        return content;
    },

    // try to find the given content in the given diff body
    findDiffContent: function(content, diffBody) {
        var match = false;

        $(diffBody).find('.diff-content .line-value').each(function(){
            if (content[content.length - 1] !== $(this).text().slice(0, 256)) {
                return;
            }

            // first line matches, let's check the rest.
            var row     = $(this).closest('tr');
            var context = swarm.comments.getDiffContext(row);
            if (JSON.stringify(context) === JSON.stringify(content)) {
                // record match and break-out
                match = row;
                return false;
            }
        });

        return match;
    },

    showAddForm: function(commentsWrapper) {
        commentsWrapper = $(commentsWrapper);

        swarm.comments.hideUnchangedForms(commentsWrapper);

        // expand the current commentsWrapper's comment table
        if (commentsWrapper.find('.comment-add table').length) {
            commentsWrapper.find('.comment-add table').toggleClass('hidden', false);
            commentsWrapper.find('.comment-add .comment-form-link').toggleClass('hidden', true);
            // add textarea resize handling
            commentsWrapper.find('textarea').textareaResize();
        }

        // size and focus the textarea/section for commenting
        swarm.comments.sizeForDiff(commentsWrapper.closest('.diff-wrapper'));
        var focusTarget = commentsWrapper.find('.comment-add textarea');
        setTimeout(function() {
            focusTarget.focus();
        }, 0);

        swarm.comments.initDelayTooltip(commentsWrapper.find('.comment-add form'));
    },

    showEditForm: function(comment) {
        var wrapper  = comment.closest('.comments-wrapper'),
            height   = comment.find('.comment-body').outerHeight(),
            form     = swarm.comments.prepareEditForm(comment),
            textarea = form.find('textarea'),
            dropZone = form.find('.can-attach').data('dropZone');

        swarm.comments.hideUnchangedForms(wrapper);

        // place comment into edit mode
        comment.addClass('edit-mode')
               .next().addClass('edit-mode');

        // set the form body and append the form
        // then resize the textarea to fit the comment body if this is a long comment
        textarea.val(comment.data('body'));
        comment.find('td:last-child()').append(form);
        textarea.height(height + (textarea.outerHeight() - textarea.height()));

        // apply pending body and uploader state to the form if found,
        // otherwise fall back to existing comment values
        var state     = swarm.comments.getFormState(form),
            uploaders = state.uploaders || comment.find('.attachment').map(function () {
                return $(this).data('attachment');
            });
        textarea.val(state.body || textarea.val());
        if (dropZone) {
            $.each(uploaders, function (index, uploader) {
                dropZone.addUploader(uploader);
            });
        }

        // validate form and then size and focus the textarea/section for commenting
        swarm.form.checkInvalid(form);
        swarm.comments.sizeForDiff(wrapper.closest('.diff-wrapper'));
        setTimeout(function() {
            // focus the cursor at the end of the comment by clearing/resetting the value
            // then re-scroll to the bottom of the textarea in case the cursor is not visible
            var value = textarea.val();
            textarea.focus().val('').val(value).scrollTop(textarea.prop('scrollHeight') - textarea.height());
        }, 0);

        swarm.comments.initDelayTooltip(form);
    },

    hideEditForm: function(comment) {
        comment.removeClass('edit-mode')
               .next().removeClass('edit-mode');
        comment.find('form').remove();
        swarm.comments.sizeCommentRowForDiff(comment.closest('.comments-row'));
    },

    prepareEditForm: function(comment) {
        var form = comment.closest('.comments-wrapper').find('.comment-add form').clone(),
            save = form.find('.btn-primary');

        form.attr('onsubmit', '');
        form.find('textarea').val('');
        form.find('.upload-controls').remove();
        form.addClass('comment-edit comment-form');
        form.find('textarea[name="body"]').attr('placeholder', swarm.te('Edit Comment'));
        form.find('textarea').textareaResize();

        // create dropzone now (instead of on-demand as with add forms)
        // we do this so that we can present any existing attachments for editing
        form.find('.can-attach').dropZone({uploaderOptions: {
            extraData: {'_csrf': $('body').data('csrf')},
            onStart:   swarm.comments.uploaderCallback,
            onRemove:  swarm.comments.uploaderCallback
        }});

        // flag as task doesn't quite make sense when editing
        form.find('label.flag-task').remove();

        // when editing, we default to a valid state, so enable save button
        save.text(swarm.te('Save')).removeClass('disabled').removeAttr('disabled');

        // submit should fire comment edit function
        form.on('submit', function(e) {
            e.preventDefault();
            swarm.comments.edit(this);
        });

        // we need a way to cancel editing
        var cancel = $($.templates(
            '<button type="reset" class="btn btn-default btn-cancel">{{te:"Cancel"}}</button>'
        ).render()).insertAfter(save);
        cancel.on('click', function (event) {
            swarm.comments.setFormState(comment.find('form'), null);
            swarm.comments.hideEditForm(comment);
        });

        // shrink buttons if they are part of a diff row
        if (comment.closest('.diff-wrapper').length) {
            form.find('.btn').addClass('btn-small');
        }

        return form;
    },

    hideUnchangedForms: function(commentsWrapper) {
        commentsWrapper.closest('.diff-wrapper').find('.comments-wrapper').each(function() {
            // collapse any unmodified add-comment forms within this wrapper
            if (!$(this).find('.comment-add textarea').val() && !$(this).find('.upload-controls').length) {
                $(this).find('.comment-add table').toggleClass('hidden', true);
                $(this).find('.comment-add .comment-form-link').toggleClass('hidden', false);
            }

            // collapse any unmodified edit-comment forms within this wrapper
            $(this).find('.comment-edit').each(function() {
                var form        = $(this),
                    formData    = $.deparam(form.serialize()),
                    comment     = form.closest('.row-main'),
                    body        = comment.data('body');

                if (body !== formData.body) {
                    return;
                }

                var attachments = comment.find('.attachment').map(function() {
                    return String($(this).data('attachment').id);
                });
                if (JSON.stringify(attachments.toArray()) !== JSON.stringify(formData.attachments || [])) {
                    return;
                }

                swarm.comments.hideEditForm(comment);
            });
        });
    },

    toggleDiffComments: function(button) {
        $(button).toggleClass('active');
        var show = $(button).is('.active');

        // record user's preference
        swarm.localStorage.set('diff.comments.hide', show ? '0' : '1');

        // use swarm.query api for performance when querying all comment sections
        // and reuse the same 'comment' jquery object for performance
        var section  = $([null]),
            paired   = $([null]);
        var comments = swarm.query.all('.diff-wrapper .comments-section').filter(function() {
            section[0] = this;
            // check if we should be showing this comment if it is
            // archive-only or empty by checking it's paired row
            if (show && (section.hasClass('archived-only') || !section.find('tr').length)) {
                paired[0] = swarm.diff.getPairedRow(this);
                if (!paired[0] || paired.hasClass('archived-only') || !paired.find('tr').length) {
                    return false;
                }
            }

            return section.toggle(show).trigger(show ? 'show' : 'hide') && true;
        });

        swarm.comments.sizeForDiff(comments.closest('.diff-wrapper'));
    }
};
