/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

swarm.reviews = {
    init: function() {
        // refresh tab panes (active first)
        var active  = $('.reviews .tab-pane.active'),
            filters = $.deparam(location.search, true);
        swarm.reviews.setFilters(filters, active);
        swarm.reviews.load(active, true);
        $('.reviews .tab-pane').not(active).each(function() {
            swarm.reviews.setFilters(filters, this);
            swarm.reviews.load(this, true);
        });

        // listen to filter buttons onclick events
        $('.reviews .btn-filter').on('click.reviews.filter', function(e) {
            e.preventDefault();
            swarm.reviews.toggleFilter(this, true);
        });

        // continuously add reviews when scrolling to bottom
        $(window).scroll(function() {
            if ($.isScrolledToBottom()) {
                swarm.reviews.load($('.reviews .tab-pane.active'));
            }
        });

        // wire-up search filter
        var events = ['input', 'keyup', 'blur'];
        $('.reviews .toolbar .search input').on(
            events.map(function(e){ return e + '.reviews.search'; }).join(' '),
            function(event){
                // apply delayed search
                var tabPane = $(this).closest('.tab-pane');
                clearTimeout(swarm.reviews.searchTimeout);
                swarm.reviews.searchTimeout = setTimeout(function(){
                    if ($(event.target).val() !== (tabPane.data('last-search') || '')) {
                        swarm.reviews.applyFilters(tabPane);
                        tabPane.data('last-search', $(event.target).val());
                    }
                }, 500);
            }
        );
    },

    load: function(tabPane, reset, deficit) {
        tabPane = $(tabPane);
        if (tabPane.data('loading')) {
            if (!reset) {
                return;
            }

            tabPane.data('loading').abort();
            tabPane.data('loading', false);
        }

        var table = $(tabPane).find('.reviews-table');

        // clean the table if reset
        if (reset) {
            table.data('last-seen',   null);
            table.data('end-of-data', null);
            table.find('tbody').empty();
        }

        // if there are no more review records, nothing else to do
        if (table.data('end-of-data')) {
            return;
        }

        // add extra row indicating that we are loading data
        // row is initially hidden, shown after 2s or as soon as we detect a 'deficit'
        table.find('tbody').append(
              '<tr class="loading muted hide">'
            + ' <td colspan="' + table.find('thead th').length + '">'
            + '  <span class="loading">' + swarm.te('Loading...') + '</span>'
            + ' </td>'
            + '</tr>'
        );
        setTimeout(function(){
            table.find('tbody tr.loading').removeClass('hide').find('.loading').addClass('animate');
        }, deficit === undefined ? 2000 : 0);

        var max      = 50,
            _loading = $.ajax({
            url:        location.pathname,
            data:       $.extend(swarm.reviews.getFilters(tabPane), {
                format: 'json',
                max:    max,
                after:  table.data('last-seen')
            }),
            dataType:   'json',
            success:    function(data){
                table.find('tbody tr.loading').remove();

                // if the last-seen id we received is null or same as the one from previous request,
                // set 'end-of-data' to indicate there are no more reviews to fetch
                if (data.lastSeen === null || data.lastSeen === table.data('last-seen')) {
                    table.data('end-of-data', true);
                }

                table.data('last-seen', data.lastSeen);

                // render rows from received data and append them to the table
                $.each(data.reviews, function(key, reviewData){
                    var row = $.templates(
                          '<tr data-id="{{>id}}" class="state-{{>state}}">'
                        + ' <td class="id"><a href="{{url:"/reviews"}}/{{urlc:id}}">{{>id}}</a></td>'
                        + ' <td class="author center">{{:authorAvatar}}</td>'
                        + ' <td class="description">{{:description}}</td>'
                        + ' <td class="project-branch">{{:projects}}</td>'
                        + ' <td class="created"><span class="timeago" title="{{>createDate}}"></span></td>'
                        + ' <td class="state center">'
                        + '  <a href="{{url:"/reviews"}}/{{urlc:id}}"><i class="swarm-icon icon-review-{{>state}}" title="{{te:stateLabel}}"></i></a>'
                        + ' </td>'
                        + ' <td class="test-status center">'
                        + '  {{if testStatus == "pass"}}{{if testDetails.url}}<a href="{{url:testDetails.url}}" target="_blank">{{/if}}'
                        + '  <i class="icon-check" title="{{te:"Tests Pass"}}"></i>{{if testDetails.url}}</a>{{/if}}'
                        + '  {{else testStatus == "fail"}}{{if testDetails.url}}<a href="{{url:testDetails.url}}" target="_blank">{{/if}}'
                        + '  <i class="icon-warning-sign" title="{{te:"Tests Fail"}}"></i>{{if testDetails.url}}</a>{{/if}}'
                        + '  {{/if}}'
                        + ' </td>'
                        + ' <td class="comments center">'
                        + '  <a href="{{url:"/reviews"}}/{{urlc:id}}#comments" {{if comments[1]}}title="{{tpe:"%s archived" "" comments[1]}}"{{/if}}>'
                        + '   <span class="badge {{if !comments[0]}}muted{{/if}}">{{>comments[0]}}</span>'
                        + '  </a>'
                        + ' </td>'
                        + ' <td class="votes center">'
                        + '  <a href="{{url:"/reviews"}}/{{urlc:id}}">'
                        + '   <span class="badge {{if !upVotes.length && !downVotes.length}}muted{{/if}}">'
                        + '    {{>upVotes.length}} / {{>downVotes.length}}'
                        + '   </span>'
                        + '  </a>'
                        + ' </td>'
                        + '</tr>'
                    ).render(reviewData);

                    $(row).appendTo(table.find('tbody'));
                });

                // update tab counter
                var counter = $(tabPane).attr('id') === 'opened'
                    ? $('.reviews .opened-counter')
                    : $('.reviews .closed-counter');
                counter.text(table.data('end-of-data') ? table.find('td.id').length : data.totalCount);

                // convert times to time-ago
                table.find('.timeago').timeago();

                // if we have no reviews to show and there are no more on the server, let the user know
                if (!table.find('tbody tr').length && table.data('end-of-data')) {
                    var message = swarm.te(
                        $(tabPane).find('.btn-filter.active').not('.default').length
                            ? 'No ' + $(tabPane).attr('id') + ' reviews match your filters.'
                            : 'No ' + $(tabPane).attr('id') + ' reviews.'
                    );

                    $('<tr class="reviews-info">'
                        + ' <td colspan="' + table.find('thead th').length + '">'
                        + '  <div class="alert border-box pad3">' + message + '</div>'
                        + ' </td>'
                        + '</tr>'
                    ).appendTo(table.find('tbody'));
                }

                // truncate the description
                table.find('.description').expander({slicePoint: 90});

                // load again if we get less than half the results we asked for
                // or the results don't fill the page (e.g. due to change filtering)
                deficit = (deficit === undefined ? max : deficit) - data.reviews.length;

                // compute table height - table could be in a closed tab
                var height = table.height();
                if (!height) {
                    $.swap(tabPane[0], {display: 'block', visibility: 'hidden'}, function(){
                        height = table.height();
                    });
                }

                if (deficit > Math.round(max / 2) || (height && height < $(window).height())) {
                    tabPane.data('loading', false);
                    return swarm.reviews.load(tabPane, false, deficit);
                }

                // enforce a minimal delay between requests
                setTimeout(function() {
                    if (tabPane.data('loading') === _loading) {
                        tabPane.data('loading', false);
                    }
                }, 500);
            }
        });
        tabPane.data('loading', _loading);
    },

    toggleFilter: function(button, applyFilter) {
        // buttons toggle on and off when clicked
        // items in drop-downs don't toggle - they stay active when clicked
        // if in drop-down, set button label to match the selected item
        if (!$(button).closest('.dropdown-menu').length) {
            $(button).toggleClass('active');
        } else {
            $(button).addClass('active');
            $(button).closest('.btn-group').find('.text').html($(button).data('short-label') || $(button).html());
        }

        // deactivate other buttons if inside btn-radio group
        $(button).closest('.btn-group.group-radio').find('.btn-filter').not(button).removeClass('active');

        // apply the new filter
        if (applyFilter) {
            swarm.reviews.applyFilters($(button).closest('.tab-pane'));
        }
    },

    applyFilters: function(tabPane) {
        var filters = swarm.reviews.getFilters(tabPane);

        // if the state filter contains all the tab states, then it is trying to apply the
        // default filtering rules and we can drop the state filter from the url
        if (filters.state && filters.state.length === swarm.reviews.getAllTabStates(tabPane).length) {
            delete filters.state;
        }

        // only use state content that comes before a colon
        if (filters.state) {
            filters.state = filters.state.split(':')[0];
        }

        // update the url to expose the current filters
        var params = $.isEmptyObject(filters) ? '' : '?' + $.param(filters),
            hash   = '#' + encodeURIComponent(tabPane[0].id);
        swarm.history.replaceState(null, null, location.pathname + params + hash);


        // refresh the tab panes, the applied one first
        swarm.reviews.load(tabPane, true);
        $('.reviews .tab-pane').not(tabPane).each(function() {
            swarm.reviews.setFilters(filters, this);
            swarm.reviews.load(this, true);
        });
    },

    getFilters: function(tabPane) {
        var filters = {};

        // construct filters from toolbar buttons
        $(tabPane).find('.btn-filter.active').each(function(){
            var button      = $(this),
                group       = button.closest('.btn-group'),
                filterKey   = button.data('filterKey') || group.data('filterKey'),
                filterValue = button.data('filterValue');

            if (filterKey && filterValue !== '') {
                if (filters.hasOwnProperty(filterKey)) {
                    if (!$.isArray(filters[filterKey])) {
                        filters[filterKey] = [filters[filterKey]];
                    }
                    filters[filterKey].push(filterValue);
                } else {
                    filters[filterKey] = filterValue;
                }
            }
        });

        // if no states are active, behave as if all states are active
        if (!filters.state) {
            filters.state = swarm.reviews.getAllTabStates(tabPane);
        }

        // add keyword value to the filter
        var keywords = $(tabPane).find('.toolbar .search input').val();
        if (keywords) {
            filters.keywords = keywords;
        }

        return filters;
    },

    getAllTabStates: function(tabPane) {
        var states = [];
        $(tabPane).find('.toolbar [data-filter-key=state] button').each(function() {
            states.push($(this).data('filter-value'));
        });
        return states;
    },

    setFilters: function(filters, tabPane) {
        filters = $.extend({}, filters);

        // if filter.state contains all of tab state filters, drop them
        // from the filter, because the default behavior is being used
        if ($.isArray(filters.state)) {
            var tabStates  = swarm.reviews.getAllTabStates(tabPane);
            var stateUnion = $.grep(filters.state, function(value) {
                return $.inArray(value, tabStates) !== -1;
            });
            if (stateUnion.length === tabStates.length) {
                delete filters.state;
            }
        }

        // set toolbar buttons from filters
        $(tabPane).find('.btn-group[data-filter-key]').each(function() {
            var group     = $(this),
                filterKey = group.data('filter-key'), button;

            // clear the active flag to reset the button group
            group.find('.btn-filter.active').removeClass('active');

            // activate default button if filter does not include the key
            // else activate the button with the matching value
            if (!filters.hasOwnProperty(filterKey)) {
                button = group.find('.btn-filter.default');
            } else {
                var filterValue = String(filters[filterKey]);
                button = group.find('.btn-filter').filter(function() {
                    // for the state type, where states can be combined using a colon,
                    // we consider the button a match if the first state matches
                    var buttonValue = String($(this).data('filter-value'));
                    if (filterKey === 'state') {
                        buttonValue = buttonValue.split(':')[0];
                        filterValue = filterValue.split(':')[0];
                    }

                    return buttonValue === filterValue;
                });
            }

            // active button but don't apply changes yet
            if (button.length) {
                swarm.reviews.toggleFilter(button, false);
            }
        });

        // apply special handling to the 'My Reviews' toolbar button
        // the key is on the individual options, not the group, so the above logic bypasses it
        swarm.reviews.toggleFilter($(tabPane).find('.btn-my-reviews a.btn-filter.default'), false);
        if (filters.author) {
            swarm.reviews.toggleFilter($(tabPane).find('.btn-my-reviews a[data-filter-key=author]'), false);
        }
        if (filters.participants) {
            swarm.reviews.toggleFilter($(tabPane).find('.btn-my-reviews a[data-filter-key=participants]'), false);
        }

        // set search keywords
        $(tabPane).find('.toolbar .search input').val(filters.keywords || '');
        $(tabPane).data('last-search', filters.keywords || '');
    }
};

swarm.review = {
    init: function() {
        swarm.review.initSlider();

        swarm.review.buildStateMenu();

        swarm.review.updateTestStatus();

        swarm.review.updateDeployStatus();

        swarm.review.initEdit();

        swarm.review.initReviewers();

        swarm.review.initReadUnread();

        // rebuild the state menu when user logs in
        $(document).on('swarm-login', function () {
            $.ajax('/reviews/' + $('.review-wrapper').data('review').id, {
                data:     {format: 'json'},
                dataType: 'json',
                success:  function (data) {
                    swarm.review.updateReview($('.review-wrapper'), data);
                }
            });
        });

        var commitPoll = function(data) {
            // rebuild the state menu if data has changed
            if (JSON.stringify(data) !== JSON.stringify($('.review-wrapper').data('review'))) {
                $('.review-wrapper').data('review', data);
                swarm.review.buildStateMenu();
            }

            // if we have errored out; stop polling
            if (data.commitStatus.error) {
                var modal = $('.review-transition.modal');
                modal.find('.messages').append('<div class="alert">' + swarm.te(data.commitStatus.error) + '</div>');
                modal.find('textarea').prop('disabled', false);
                swarm.form.enableButton(modal.find('[type=submit]'));
                return false;
            }

            // if the commit has completed, reload the page
            if ($.isEmptyObject(data.commitStatus) && !data.pending) {
                window.location.reload();
                return false;
            }
        };

        // wire-up state dropdown
        $('.review-header').on('click', '.state-menu a', function(e){
            e.preventDefault();

            var link    = $(this),
                state   = link.data('state'),
                wrapper = link.closest('.review-wrapper'),
                button  = link.closest('.btn-group').find('.btn');

            // close the dropdown
            button.dropdown('toggle');

            if (state === 'attach-commit') {
                swarm.changes.openChangeSelector(swarm.url('/reviews/add'), wrapper.data(), function(modal, response) {
                    // keep the modal dialog disabled
                    var changeId = $(modal).find('.change-input input').val();
                    $(modal).find('.changes-list, input').addClass('disabled').prop('disabled', true);
                    swarm.form.disableButton($(modal).find('[type=submit]'));

                    // prevent user from closing the dialog now
                    $(modal).on('hide', function(e) {
                        e.preventDefault();
                    });

                    // start polling until review is ready
                    swarm.review.pollForUpdate(function(review) {
                        if (!review.commits) {
                            return;
                        }

                        var i;
                        for (i = 0; i < review.commits.length; i++) {
                            if (parseInt(review.commits[i], 10) === parseInt(changeId, 10)) {
                                window.location.reload();
                                return false;
                            }
                        }
                    });
                });
                return;
            }

            swarm.review.openTransitionDialog(
                state,
                wrapper.data(),
                function(modal, response) {
                    swarm.review.updateReview(wrapper, response);

                    // if we were committing, start polling for updates
                    if (state === 'approved:commit' && response.isValid) {
                        $(modal).find('textarea').prop('disabled', true);
                        swarm.form.disableButton($(modal).find('[type=submit]'));
                        swarm.review.pollForUpdate(commitPoll);
                        return;
                    }

                    modal.modal('hide');

                    // indicate success via a temporary tooltip
                    button.tooltip({title: swarm.t('Review Updated'), trigger: 'manual'}).tooltip('show');
                    setTimeout(function(){
                        button.tooltip('destroy');
                    }, 3000);

                    // if a transition was made and a comment provided, refresh comments.
                    if (state !== 'approved:commit' && $.trim(modal.find('form textarea').val())) {
                        swarm.comments.load('reviews/' + wrapper.data('review').id, '#comments');
                    }
                }
            );

            return false;
        });

        // if the page just loaded and a commit is going on; keep polling for updates
        var review = $('.review-wrapper').data('review');
        if (review.commitStatus.start && !review.commitStatus.error) {
            swarm.review.pollForUpdate(commitPoll);
        }

        // wire-up description edit button
        $('.review-header').on('click', '.btn-edit', function(e){
            e.preventDefault();

            var wrapper = $(this).closest('.review-wrapper');

            swarm.review.openEditDialog(
                wrapper.data(),
                function(modal, response) {
                    modal.modal('hide');

                    // update review to reflect changes in description
                    swarm.review.updateReview(wrapper, response);
                }
            );
        });
    },

    initReadUnread: function() {
        var updateStatus = function(wrapper, read, showTooltip) {
            var button = wrapper.find('.btn-file-read');
            button.toggleClass('active btn-inverse', read);
            button.find('i').toggleClass('icon-white', read);
            wrapper.toggleClass('file-read',   read);
            wrapper.toggleClass('file-unread', !read);

            if (showTooltip) {
                // update tooltip with temporary confirmation text
                button.attr('data-original-title', swarm.t(read ? 'Marked as Read' : 'Marked as Unread'));
                button.tooltip('show');

                // switch back to action verbiage shortly thereafter
                setTimeout(function(){
                    button.attr('data-original-title', swarm.t(read ? 'Mark as Unread' : 'Mark as Read'));
                }, 1000);
            } else {
                // not showing the tooltip, go straight to the action verbiage
                button.attr('data-original-title', swarm.t(read ? 'Mark as Unread' : 'Mark as Read'));
            }
        };

        // on login, update read status
        $(document).on('swarm-login', function(e){
            // loop over each file and see if our user has read it.
            // if so, check the 'read by' box
            $('.change-files .diff-wrapper').each(function() {
                var wrapper = $(this);
                if (wrapper.data('readby').hasOwnProperty(e.user.id)) {
                    updateStatus(wrapper, true, false);
                }
            });
        });

        // connect 'file-read' buttons so users can check off files
        $('.change-files').on('click', '.btn-file-read', function(e){
            var button  = $(this),
                read    = !button.is('.active'),
                review  = button.closest('.review-wrapper').data('review'),
                change  = button.closest('.review-wrapper').data('change'),
                against = button.closest('.review-wrapper').data('against'),
                version = (against ? against.rev + ',' : '') + change.rev,
                wrapper = button.closest('.diff-wrapper'),
                file    = wrapper.data('file'),
                details = wrapper.find('.diff-details');

            // update file-info on the server, don't wait for a response
            // as it doesn't impact UI except to slow it down
            $.ajax({
                type:  "POST",
                url:   '/reviews/' + review.id + '/v' + version
                     + '/files/' + swarm.encodeURIDepotPath(file.depotFile),
                data:  {read: read ? 1 : 0, user: swarm.user.getAuthenticatedUser().id},
                error: function() {
                    updateStatus(wrapper, !read, true);
                }
            });

            updateStatus(wrapper, read, true);

            // collapse file if expanded and marking as 'read'
            if (details.is('.in') && read) {
                details.one('hidden', function(){
                    if (button.data('tooltip').tip().parent().length) {
                        button.tooltip('show');
                    }
                });
                details.collapse('hide');
            }
        });
    },

    initSlider: function() {
        var data       = [],
            wrapper    = $('.review-wrapper'),
            review     = wrapper.data('review'),
            change     = wrapper.data('change'),
            against    = wrapper.data('against'),
            changeId   = parseInt(change.id, 10),
            changeRev  = parseInt(change.rev, 10),
            againstId  = against ? parseInt(against.id, 10)  : null,
            againstRev = against ? parseInt(against.rev, 10) : null;

        $.each(review.versions, function(index) {
            this.rev      = index + 1;
            this.change   = parseInt(this.change, 10);
            this.selected = (this.change === changeId  && this.rev === changeRev)
                         || (this.change === againstId && this.rev === againstRev);
            data.push(this);
        });

        $('.review-slider').versionSlider({data: data, markerMode: against ? 2 : 1});
        $('.slider-mode-toggle').toggleClass('active', !!against);

        $(document).off('slider-moved', '.review-slider').on('slider-moved', '.review-slider', function(e, slider) {
            setTimeout(function() {
                var version = (slider.previousRevision ? slider.previousRevision.rev + ',' : '')
                            + slider.currentRevision.rev;
                var path    = document.location.pathname.replace(/(\/v[0-9,]+)?\/?$/, '/v' + version);
                if (path !== document.location.pathname) {
                    slider.disable();
                    document.location = path + document.location.hash;
                }
            }, 0);
        });

        $(document).off('click.slider.mode.toggle').on('click.slider.mode.toggle', '.slider-mode-toggle', function() {
            var slider = $('.review-slider').data('versionSlider');
            slider.setMarkerMode(slider.markerMode === 1 ? 2: 1);
            slider.$element.trigger('slider-moved', slider);
        });
    },

    initEdit: function() {
        // add edit button after the first line (if its not already there)
        if ($('.review-header .change-description .btn-edit').length) {
            return;
        }

        $('<a href="#" class="privileged btn-edit" title="' + swarm.te('Edit Description') + '">'
            + '<i class="swarm-icon icon-review-needsRevision"></i>'
            + '</a>'
        ).insertAfter('.review-header .change-description .first-line');
    },

    initReviewers: function() {
        // create reviewers templates only once
        $.templates({
            userMenu:
                  '<ul role="menu" class="dropdown-menu" aria-label="{{te:"User Reviewer Menu"}}">'
                +   '{{if vote.value < 1 || vote.isStale}}'
                +     '<li role="menuitem"><a href="#" data-action="up">'
                +       '<i class="icon-chevron-up"></i> {{te:"Vote Up"}}'
                +     '</a></li>'
                +   '{{/if}}'
                +   '{{if vote.value !== 0}}'
                +     '<li role="menuitem"><a href="#" data-action="clear">'
                +       '<i class="icon-minus"></i> {{te:"Clear Vote"}}'
                +     '</a></li>'
                +   '{{/if}}'
                +   '{{if vote.value > -1 || vote.isStale}}'
                +     '<li role="menuitem"><a href="#" data-action="down">'
                +       '<i class="icon-chevron-down"></i> {{te:"Vote Down"}}'
                +     '</a></li>'
                +   '{{/if}}'
                +   '<li role="presentation" class="divider"></li>'
                +   '{{if addReviewer}}'
                +     '<li role="menuitem"><a href="#" data-action="join">'
                +       '<i class="icon-plus"></i> {{te:"Join Review"}}'
                +     '</a></li>'
                +   '{{else}}'
                +     '{{if isRequired}}'
                +       '<li role="menuitem"><a href="#" data-action="optional">'
                +         '<i class="icon-star-empty"></i> {{te:"Make my Vote Optional"}}'
                +       '</a></li>'
                +     '{{else}}'
                +       '<li role="menuitem"><a href="#" data-action="required">'
                +         '<i class="icon-star"></i> {{te:"Make my Vote Required"}}'
                +       '</a></li>'
                +     '{{/if}}'
                +     '<li role="menuitem"><a href="#" data-action="leave"><i class="icon-remove"></i> {{te:"Leave Review"}}</a></li>'
                +   '{{/if}}'
                + '</ul>',
            reviewerAvatar:
                  '<div class="reviewer-avatar pull-left '
                +     '{{if current}}current{{/if}} {{if addReviewer}}add-reviewer{{/if}}">'
                +   '{{if current}}'
                +     '<div class="btn pad1 dropdown-toggle" tabIndex="0" data-toggle="dropdown" role="button" aria-haspopup="true">'
                +   '{{/if}}'
                +   '{{:avatar}}'
                +   '{{if vote.value > 0}}'
                +     '<i class="swarm-icon {{if vote.isStale}}icon-vote-up-stale{{else}}icon-vote-up{{/if}}"></i>'
                +   '{{else vote.value < 0}}'
                +     '<i class="swarm-icon {{if vote.isStale}}icon-vote-down-stale{{else}}icon-vote-down{{/if}}"></i>'
                +   '{{/if}}'
                +   '{{if isRequired}}'
                +     '<i class="swarm-icon icon-required-reviewer"></i>'
                +   '{{/if}}'
                +   '{{if current}}'
                +     '<i class="caret"></i></div>'
                +     '{{if true tmpl="userMenu" /}}'
                +   '{{/if}}'
                + '</div>',
            voteSummary:
                  '<div class="vote-summary text-left pull-left muted">'
                +   '<div>'
                +     '{{te:"Reviewers"}}'
                +     '{{if canEdit}}'
                +     '<button type="button" class="bare privileged edit-reviewers pad0 padw1"'
                +             'aria-label="{{te:"Edit Reviewers"}}" title="{{te:"Edit Reviewers"}}">'
                +       '<i class="swarm-icon icon-edit-pencil"></i>'
                +     '</button>'
                +     '{{/if}}'
                +   '</div>'
                +   '<span class="vote-up {{if upCount}}has-value{{/if}}" title="{{te:"Up Votes"}}">'
                +     '<i class="icon-chevron-up"></i>{{>upCount}}'
                +   '</span>'
                +   '<span class="vote-down {{if downCount}}has-value{{/if}}" title="{{te:"Down Votes"}}">'
                +     '<i class="icon-chevron-down"></i>{{>downCount}}'
                +   '</span>'
                + '</div>',
            editReviewersDialog:
                  '<div class="modal hide fade edit-reviewers" tabindex="-1" role="dialog" aria-labelledby="reviewers-edit-title" aria-hidden="true">'
                +   '<form method="post" class="form-horizontal modal-form">'
                +     '<div class="modal-header">'
                +       '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
                +       '<h3 id="reviewers-edit-title">{{te:"Reviewers"}}</h3>'
                +     '</div>'
                +     '<div class="modal-body">'
                +       '<div class="messages"></div>'
                +       '<div class="controls reviewers">'
                +         '<div class="input-prepend" clear="both">'
                +           '<span class="add-on"><i class="icon-user"></i></span>'
                +           '<input type="text" class="input-xlarge reviewer-multipicker" data-items="100"'
                +                  'placeholder="{{te:"Reviewer Name"}}">'
                +         '</div>'
                +         '<div class="reviewers-list"></div>'
                +       '</div>'
                +     '</div>'
                +     '<div class="modal-footer">'
                +       '<button type="submit" class="btn btn-primary">{{te:"Save"}}</button>'
                +       '<button type="button" class="btn" data-dismiss="modal">{{te:"Close"}}</button>'
                +     '</div>'
                +   '</form>'
                + '</div>',
            requiredReviewerButton:
                  '<button type="button" class="btn btn-mini btn-info item-require {{if isRequired}}active{{/if}}" data-toggle="button"'
                + ' title="{{if isRequired}}{{te:"Make Vote Optional"}}{{else}}{{te:"Make Vote Required"}}{{/if}}" '
                + ' aria-label="{{if isRequired}}{{te:"Make Vote Optional"}}{{else}}{{te:"Make Vote Required"}}{{/if}}">'
                +   '<i class="{{if isRequired}}icon-star{{else}}icon-star-empty{{/if}} icon-white"></i>'
                +   '<input type="hidden" name="requiredReviewers[]" value="{{>value}}" {{if !isRequired}}disabled{{/if}}>'
                + '</button>'
        });

        swarm.review.buildReviewers();
        swarm.review.initUserMenu();

        // update add reviewer avatar on login
        $(document).on('swarm-login', function (e) {
            swarm.review.buildReviewers();
        });

        $(document).on('click.edit.reviewers', '.review-header .reviewers .edit-reviewers', function(e) {
            var wrapper = $('.review-wrapper');
            swarm.review.openEditReviewersDialog(wrapper.data('review'), function(modal, response) {
                // close modal and update review to reflect reviewer changes
                modal.modal('hide');
                swarm.review.updateReview(wrapper, response);
            });
        });
    },

    buildReviewers: function() {
        var wrapper     = $('.review-wrapper'),
            review      = wrapper.data('review'),
            canEdit     = wrapper.data('can-edit-reviewers'),
            avatars     = wrapper.data('avatars') || {},
            reviewers   = $.grep(review.participants, function(id) { return id !== review.author; }),
            user        = swarm.user.getAuthenticatedUser(),
            userId      = user && user.id,
            details     = review.participantsData || {},
            defaultVote = {value: 0, version: undefined, isStale: undefined};


        // get count of all non-state up and down votes
        var upCount   = 0,
            downCount = 0;
        $.each(details, function (user, data) {
            if (data.vote && !data.vote.isStale) {
                upCount   += data.vote.value > 0 ? 1 : 0;
                downCount += data.vote.value < 0 ? 1 : 0;
            }
        });

        // only show the reviewers label when we have reviewers, or the
        // active user has the ability to edit the reviewers list
        var html = '';
        if (reviewers.length || canEdit) {
            html += $.templates.voteSummary.render({
                upCount:   upCount,
                downCount: downCount,
                reviewers: reviewers,
                isAuthor:  userId === review.author,
                canEdit:   canEdit
            });
        }

        $.each(reviewers, function(key, reviewer) {
            if (reviewer !== userId) {
                html += $.templates.reviewerAvatar.render({
                    vote:       details[reviewer].vote || defaultVote,
                    avatar:     avatars[reviewer] || '',
                    isRequired: !!details[reviewer].required
                });
            }
        });

        // if current user is a reviewer, show their avatar last
        // otherwise, show add-reviewer if user is authenticated and not the author
        if ($.inArray(userId, reviewers) !== -1) {
            html += $.templates.reviewerAvatar.render({
                vote:       details[userId].vote || defaultVote,
                avatar:     avatars[userId] || '',
                current:    true,
                isRequired: !!details[userId].required
            });
        } else if (userId && userId !== review.author) {
            var avatarWrapper = $(user.avatar),
                avatar        = avatarWrapper.find('.avatar');

            // tweak size and styling of user's avatar before inserting
            avatarWrapper.removeClass('fluid');
            avatar
                .attr('width',  40)
                .attr('height', 40)
                .attr('src',    avatar.attr('src').replace(/s=[0-9]+/,    's=40'))
                .attr('class',  avatar.attr('class').replace(/as-[0-9]+/, 'as-40'));

            html += $.templates.reviewerAvatar.render({
                vote:        defaultVote,
                avatar:      avatarWrapper[0].outerHTML,
                current:     true,
                addReviewer: true
            });
        }

        // destroy existing tooltips so they don't get orphaned
        var $reviewersNode = $('.review-header .reviewers');
        $reviewersNode.find('[title]').tooltip('destroy');

        $reviewersNode.html(html);

        // we don't want the active user's avatar to be a link or have a tooltip - switch it to a div
        $reviewersNode
            .find('.reviewer-avatar .btn .avatar')
            .unwrap()
            .wrapAll('<div class="avatar-wrapper">');

        // tweak users' avatar tooltips to show the version they voted on
        $reviewersNode.find('.avatar-wrapper').attr('title', '').data('customclass', 'user-vote').tooltip({
            container:   'body',
            trigger:     'manual',
            isDelegated: true,
            html:        true,
            title:       function(){
                var name   = $(this).find('img').attr('alt'),
                    userId = $(this).find('img').data('user'),
                    vote   = details[userId] && details[userId].vote ? details[userId].vote : {};

                return $.templates(
                      '{{>name}}'
                    + '{{if vote.value}}'
                    +   '<br><span class="muted">'
                    +     '{{if vote.value > 0}}{{te:"voted up"}}{{else}}{{te:"voted down"}}{{/if}}'
                    +     '{{if !vote.isStale}} {{te:"latest"}}{{else}} #{{>vote.version}}{{/if}}'
                    +   '</span>'
                    + '{{/if}}'
                ).render({name: name, vote: vote});
            }
        });
    },

    initUserMenu: function() {
        // handle clicks within the dropdown menu
        $(document).off('click.review.user.menu');
        $(document).on('click.review.user.menu', '.review-header .reviewers .dropdown-menu a', function(e) {
            e.preventDefault();

            var $this  = $(this),
                action = $this.data('action');

            var callback = function(request, status) {
                var reviewer = $('.review-header .reviewers .current');
                reviewer.removeClass('open');

                // indicate success via a temporary tooltip.
                if ((action === 'join' || action === 'leave') && status === 'success') {
                    var avatar       = reviewer.find('.avatar-wrapper'),
                        actionStatus = swarm.t(action === 'join' ? 'Joined' : 'Left'),
                        oldTip       = avatar.attr('data-original-title');

                    avatar.attr('data-original-title', actionStatus).tooltip('show');
                    setTimeout(function(){
                        avatar.attr('data-original-title', oldTip).tooltip('hide');
                    }, 3000);
                }
            };

            if (action === 'join') {
                swarm.review.join(callback);
                return;
            }
            if (action === 'leave') {
                swarm.review.leave(callback);
                return;
            }
            if (action === 'required' || action === 'optional') {
                swarm.review.setRequiredReviewer(action === 'required', callback);
                return;
            }

            swarm.review.vote(action, callback);
        });
    },

    leave: function(callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id + '/reviewers/' + encodeURIComponent(user.id) + '?_method=DELETE', {
            type:     'POST',
            dataType: 'json',
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    join: function(callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id, {
            type:     'POST',
            dataType: 'json',
            data:     {join: user.id},
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    vote: function(action, callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id + '/vote/' + action, {
            type:     'POST',
            dataType: 'json',
            data:     {user: user.id, version: review.versions.length},
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    setRequiredReviewer: function(isRequired, callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id + '/reviewers/' + encodeURIComponent(user.id) + '?_method=PATCH', {
            type:     'POST',
            dataType: 'json',
            data:     {required: isRequired},
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    openEditReviewersDialog: function(review, callback) {
        var reviewers = $.grep(review.participants, function(id) { return id !== review.author; }),
            details   = review.participantsData || {},
            modal     = $($.templates.editReviewersDialog.render()).appendTo('body');

        // show dialog (auto-width, centered)
        swarm.modal.show(modal);

        // setup multiPicker plugin for selecting reviewers
        var reviewersSelect = modal.find('.reviewer-multipicker');
        reviewersSelect.userMultiPicker({
            itemsContainer: modal.find('.reviewers-list'),
            selected:       reviewers,
            inputName:      'reviewers',
            excludeUsers:   [review.author],
            createItem:     function(value) {
                var item     = $($.templates(this.itemTemplate).render({
                    value: value, inputName: this.options.inputName
                }));

                item.find('.btn-group').prepend(
                    $.templates.requiredReviewerButton.render({
                        isRequired: !!(details[value] && details[value].required),
                        value:      value
                    })
                );

                return item;
            }
        });

        // setup required reviewer click button listener as last handler for the button
        modal.on('click.required', '.item-require', function() {
            var $this = $(this);
            setTimeout(function() {
                var isRequired = $this.hasClass('active');

                $this.find('i').toggleClass('icon-star', isRequired).toggleClass('icon-star-empty', !isRequired);
                $this.find('input').prop('disabled', !isRequired);

                // temporarily show confirmation tooltip
                $this.attr('data-original-title', isRequired ? swarm.t('Vote Required') : swarm.t('Vote Optional') );
                $this.tooltip('show');

                // switch back to action verbiage shortly thereafter
                setTimeout(function(){
                    $this.attr('data-original-title', isRequired ? swarm.t('Make Vote Optional') : swarm.t('Make Vote Required') );
                }, 1000);
            }, 0);
        });

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post('/reviews/' + review.id + '/reviewers', modal.find('form'), function(response) {
                if (callback && response.isValid) {
                    callback(modal, response);
                }
            }, modal.find('.messages')[0]);
        });

        // ensure the input is focused when we show
        modal.on('shown', function(e) {
            if (e.target === this) {
                $(this).find('.reviewers input.multipicker-input').focus();
            }
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    },

    openEditDialog: function(data, callback) {
        var modal = $($.templates(
              '<div class="modal hide fade review-edit" tabindex="-1" role="dialog" aria-labelledby="edit-title" aria-hidden="true">'
            +   '<form method="post" class="form-horizontal modal-form">'
            +       '<div class="modal-header">'
            +           '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
            +           '<h3 id="edit-title">{{te:"Edit Description"}}</h3>'
            +       '</div>'
            +       '<div class="modal-body">'
            +           '<div class="messages"></div>'
            +           '<div class="control-group">'
            +               '<div class="controls">'
            +                   '<textarea name="description" class="border-box monospace"'
            +                       'placeholder="{{te:"Provide a description"}}" rows="15" cols="80" required>'
            +                       '{{>review.description}}'
            +                   '</textarea>'
            +               '</div>'
            +           '</div>'
            +       '</div>'
            +       '<div class="modal-footer">'
            +           '<button type="submit" class="btn btn-primary">{{te:"Update"}}</button>'
            +           '<button type="button" class="btn" data-dismiss="modal">{{te:"Cancel"}}</button>'
            +       '</div>'
            +   '</form>'
            + '</div>'
        ).render({review: data.review})).appendTo('body');

        // show dialog (auto-width, centered)
        swarm.modal.show(modal);

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post('/reviews/' + data.review.id, modal.find('form'), function(response) {
                if (!response.isValid) {
                    return;
                }

                callback(modal, response);
            }, modal.find('.messages')[0]);
        });

        // ensure the textarea is focused when we show
        modal.on('shown', function(e) {
            $(this).find('textarea').focus();
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    },

    openTransitionDialog: function(state, data, callback) {
        // grab count of unverified actionable comments
        var openCount = $('#comments table.opened-comments tr.task-state-open').length;

        var modal = $($.templates(
              '<div class="modal hide fade review-transition" tabindex="-1" role="dialog" aria-labelledby="transition-title" aria-hidden="true">'
            +   '<form method="post" class="form-horizontal modal-form">'
            +       '<input type="hidden" name="state" value="{{>state}}">'
            +       '{{if review.commitStatus.error}}<input type="hidden" name="commitStatus" value="">{{/if}}'
            +       '<div class="modal-header">'
            +           '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
            +           '<h3 id="transition-title">{{if state == "approved:commit"}}{{te:"Commit"}}{{else}}{{te:"Update"}}{{/if}} {{te:"Review"}}</h3>'
            +       '</div>'
            +       '<div class="modal-body">'
            +           '<div class="messages">'
            +               '{{if (state === "approved:commit" || state === "approved") && openCount}}'
            +                   '<div class="alert open-count"><strong>{{te:"Warning!"}}</strong> '
            +                       '{{tpe:"There is an open task on this review" "There are %s open tasks on this review" openCount}}'
            +                   '</div>'
            +               '{{/if}}'
            +           '</div>'
            +           '<div class="control-group">'
            +               '<textarea name="description" class="border-box{{if state == "approved:commit"}} monospace{{/if}}"'
            +                 '{{if state == "approved:commit"}} required rows="15" cols="80">{{>review.description}}'
            +                 '{{else}} placeholder="{{te:"Optionally, provide a comment"}}" rows="10" cols="80">{{/if}}'
            +               '</textarea>'
            +           '</div>'
            +       '</div>'
            +       '<div class="modal-footer">'
            +           '<button type="submit" class="btn btn-primary">{{te:transitions[state]}}</button>'
            +           '<button type="button" class="btn" data-dismiss="modal">{{te:"Cancel"}}</button>'
            +       '</div>'
            +   '</form>'
            + '</div>'
        ).render({state: state, review: data.review, transitions: data.transitions, openCount: openCount})).appendTo('body');

        // if committing, add sub-form for selecting jobs (only if there are jobs attached)
        if (state === 'approved:commit' && data.jobs.length) {
            // render jobs sub-form
            var jobsForm = $(
                  '<div class="control-group jobs-list">'
                  +   '<input type="hidden" name="jobs" value="">'
                  +   '<table class="table"></table>'
                  +   '<input type="hidden" name="fixStatus">'
                + '</div>'
            );
            $.each(data.jobs, function(){
                jobsForm.find('table').append($.templates(
                      '<tr data-job="{{>job}}">'
                    +   '<td>'
                    +     '<input type="checkbox" name="jobs[]" value="{{>job}}" checked="checked">'
                    +   '</td>'
                    +   '<td class="job-id">'
                    +     '<a href="{{:link}}" target="_blank">{{>job}}</a>'
                    +   '</td>'
                    +   '<td class="job-status">'
                    +     '{{te:status}}'
                    +   '</td>'
                    +   '<td class="job-description force-wrap" width="90%">'
                    +     '{{:description}}'
                    +   '</td>'
                    + '</tr>'
                ).render(this));
            });

            // expand descriptions
            jobsForm.find('.job-description').expander({slicePoint: 70});

            // place jobs list in the dialog
            modal.find('form .modal-body').append(jobsForm);

            // if job status field is defined, add drop-down for selecting fix status upon submit
            if (data.jobStatus) {
                // determine default job status:
                // - use default fix status from jobSpec preset (i.e. fixStatus
                //   if preset is in the form of 'jobStatus,fix/fixStatus')
                // - if no fixStatus, use 'closed'
                // - if neither of above is present, use the first option in the list
                var preset         = data.jobStatus['default'],
                    fixStatus      = preset.split(',fix/').pop(),
                    defaultStatus  = fixStatus !== preset ? fixStatus : 'closed';

                // prepare list with available job fix statuses for the drop-down
                var options = '<li data-status="same"><a href="#">' + swarm.te('Same') + '</a></li>'
                            + '<li class="divider"></li>';
                $.each(data.jobStatus.options, function(){
                    var status   = this.valueOf(),
                        selected = status === defaultStatus ? 'selected' : '';
                    options += $.templates(
                        '<li data-status="{{>status}}" class="{{:selected}}"><a href="#">{{te:label}}</a></li>'
                    ).render({status: status, label: swarm.jobs.getFriendlyLabel(status), selected: selected});
                });

                // add drop-down to select job status
                modal.find('.modal-footer').append($.templates(
                      '<div class="pull-left">'
                    +   '<span class="status-label">{{te:"Job Status on Commit"}}</span>'
                    +   '<div class="btn-group status-dropdown">'
                    +     '<button type="button" class="btn dropdown-toggle" data-toggle="dropdown" aria-haspopup="true">'
                    +       '<span class="text"></span>'
                    +       ' <span class="caret"></span>'
                    +     '</button>'
                    +     '<ul class="dropdown-menu" role="menu" aria-label="{{te:"Job Status on Commit"}}">'
                    +       '{{:options}}'
                    +     '</ul>'
                    +   '</div>'
                    + '</div>'
                ).render({options: options}));

                // prepare handler for updating fix status in drop-down button label and in form
                var updateJobStatus = function(){
                    var menu     = modal.find('.status-dropdown .dropdown-menu'),
                        selected = menu.find('.selected').length ? menu.find('.selected') : menu.find('li:first'),
                        status   = selected.data('status'),
                        label    = selected.find('a').html();

                    // update button label to match the selected option
                    modal.find('.status-dropdown .text').text(label);

                    // update fixStatus in form
                    jobsForm.find('[name="fixStatus"]').val(status);
                };
                updateJobStatus();

                // wire-up clicking on job status drop-down menu option
                modal.on('click.job.status', '.modal-footer .dropdown-menu a', function(e){
                    e.preventDefault();

                    // set the clicked option as the only selected
                    $(this).closest('.dropdown-menu').find('li').removeClass('selected');
                    $(this).closest('li').addClass('selected');

                    updateJobStatus();
                });
            }
        }

        // show dialog (auto-width, centered)
        swarm.modal.show(modal);

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post('/reviews/' + data.review.id + '/transition', modal.find('form'), function(response) {
                if (!response.isValid) {
                    return;
                }

                callback(modal, response);
            }, modal.find('.messages')[0]);
        });

        // ensure the textarea is focused when we show
        modal.on('shown', function(e) {
            $(this).find('textarea').focus();
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    },

    // polls the review with a 1 second delay between requests,
    // calling the callback function after the success of each
    // request until the callback returns === false
    _polling: null,
    pollForUpdate: function(callback) {
        window.clearTimeout(swarm.review._polling);
        swarm.review._polling = setTimeout(function() {
            var review  = $('.review-wrapper').data('review');
            // make request
            $.ajax('/reviews/' + review.id, {
                dataType:   'json',
                data:       {format:'json'},
                success:    function(data) {
                    if (callback && callback(data.review) !== false) {
                        swarm.review.pollForUpdate(callback);
                    }
                }
            });
        }, 1000);
    },

    updateReview: function(wrapper, response){
        // update data on the wrapper
        wrapper.data('review',              response.review);
        wrapper.data('avatars',             response.avatars);
        wrapper.data('transitions',         response.transitions);
        wrapper.data('can-edit-reviewers',  response.canEditReviewers);

        // update the slider
        swarm.review.initSlider();

        // update description and re-initialize for edit (as response doesn't contain edit button)
        wrapper.find('.change-description').html(response.description);
        swarm.review.initEdit();

        // rebuild state menu
        swarm.review.buildStateMenu();

        // update test status
        swarm.review.updateTestStatus();

        // update deploy status
        swarm.review.updateDeployStatus();

        // update reviewers area
        swarm.review.buildReviewers();
    },

    updateTestStatus: function(){
        var wrapper = $('.review-wrapper'),
            header  = wrapper.find('.review-header'),
            review  = wrapper.data('review'),
            pass    = review.testStatus === 'pass',
            icon    = pass ? 'check'   : 'warning-sign',
            color   = pass ? 'success' : 'danger',
            details = review.testDetails,
            testUrl = details.url      ?  encodeURI(details.url) : '',
            endTime = details.endTimes && details.endTimes.length
                ? Math.max.apply(null, details.endTimes)
                : null;

        // if test status is null, nothing to show.
        if (!review.testStatus) {
            return;
        }

        // if we have an existing status icon, destroy it.
        header.find('.test-status').remove();

        var button = $(testUrl ? '<a>' : '<span>');
        button.addClass('test-status pull-left btn btn-small btn-' + color)
              .attr('href', testUrl)
              .attr('target', '_blank')
              .attr('disabled', !testUrl)
              .append($('<i>').addClass('icon-' + icon + ' icon-white'));

        // build the tooltip title as an object so that we can have dynamic timeago
        var title = $('<span>');
        title.text(swarm.t('Tests ' + (pass ? 'Pass' : 'Fail')));
        if (endTime) {
            title.append('<br>')
                 .append('<span class="timeago muted" title="' + new Date(endTime * 1000).toISOString() + '"></span>')
                 .find('.timeago').timeago();
        }
        button.tooltip({title: title});

        header.find('.review-status').prepend(button);
    },

    updateDeployStatus: function(){
        var wrapper = $('.review-wrapper'),
            header  = wrapper.find('.review-header'),
            review  = wrapper.data('review'),
            success = review.deployStatus === 'success',
            title   = success ? swarm.te('Try it out!') : swarm.te('Deploy Failed'),
            color   = success ? '' : 'warning',
            url     = review.deployDetails.url ? encodeURI(review.deployDetails.url) : '',
            htmlTag = url ? 'a' : 'span';

        // if deploy status is null or its success but we lack a url, nothing to show.
        if (!review.deployStatus || (success && !url)) {
            return;
        }

        // if we have an existing status icon, destroy it.
        header.find('.deploy-status').remove();

        header.find('.review-status').prepend(
            '<' + htmlTag + ' class="deploy-status pull-left btn btn-small btn-' + color + '" '
                +   'title="' + title + '" '
                +   (url ? 'href="' + url + '" target="_blank"' : 'disabled="disabled"')
                + '><i class="icon-plane ' + (success ? '' : 'icon-white') + '"></i></' + htmlTag + '>'
        );
    },

    buildStateMenu: function(){
        var wrapper     = $('.review-wrapper'),
            header      = wrapper.find('.review-header'),
            review      = wrapper.data('review'),
            transitions = wrapper.data('transitions');

        // if we have an existing state menu, destroy it
        header.find('.state-menu').remove();

        // render menu options individually - jsrender can't iterate objects
        // (see: https://github.com/BorisMoore/jsrender/issues/40)
        var items = "";
        $.each(transitions, function(state, label) {
            items += $.templates(
                '<li><a href="#" data-state="{{>state}}">'
              + ' <i class="swarm-icon icon-review-{{class:state}}"></i> {{te:label}}'
              + '</a></li>'
            ).render({state: state, label: label});
        });

        // if review is still pending, allow user to attach a committed change
        items += items ? '<li class="divider"></li>' : '';
        items += $.templates(
            '<li><a href="#" data-state="attach-commit">' +
            ' <i class="swarm-icon icon-committed"></i>' +
            ' {{if pending}}{{te:"Already Committed"}}{{else}}{{te:"Add a Commit"}}{{/if}}...' +
            '</a></li>'
        ).render({pending: review.pending});

        header.find('.review-status').append(
            $.templates(
                '<div class="state-menu btn-group pull-right">'
                    + ' <button type="button"'
                    + '  class="btn btn-small btn-primary btn-branch dropdown-toggle '
                    + '         {{if review.commitStatus.error}}btn-danger{{/if}}"'
                    + '  {{if !authenticated || transitions === false}}disabled{{else}}aria-haspopup="true"{{/if}}'
                    + '  {{if review.commitStatus.error}}'
                    + '    title="{{te:"Error committing"}} {{te:review.commitStatus.error}}"'
                    + '  {{/if}}'
                    + '  data-toggle="dropdown">'
                    + '{{if review.commitStatus.error}}'
                    + '  <i class="icon-white icon-warning-sign"></i>'
                    + '    {{te:"Error"}}'
                    + '{{else review.commitStatus.start}}'
                    + '  <i class="swarm-icon icon-white icon-committed"></i>'
                    + '    {{if review.commitStatus.status}}{{te:review.commitStatus.status}}{{else}}{{te:"Committing"}}{{/if}}...'
                    + '{{else !review.pending && review.state=="approved"}}'
                    + '  <i class="swarm-icon icon-white icon-committed"></i>'
                    + '    {{te:review.stateLabel}}'
                    + '{{else}}'
                    + '  <i class="swarm-icon icon-white icon-review-{{>review.state}}"></i>'
                    + '    {{te:review.stateLabel}}'
                    + '{{/if}}'
                    + ' <span class="caret"></span>'
                    + '</button>'
                    + ' <ul class="dropdown-menu" role="menu" aria-label="{{te:"Transition Review"}}">{{:items}}</ul>'
                    + '</div>'
            ).render({review: review, items: items, transitions: transitions, authenticated: $('body').is('.authenticated')})
        );
    },

    add: function(button, change) {
        button = $(button);
        change = change || button.closest('.change-wrapper').data('change').id;

        // disable button while talking to the server.
        swarm.form.disableButton(button);

        $.post('/reviews/add', {change: change}, function(response) {
            if (response.id) {
                swarm.form.enableButton(button);

                // convert to a 'view review' button.
                button
                    .removeAttr('onclick')
                    .attr('href', swarm.url('/reviews/' + response.id))
                    .toggleClass('btn-primary btn-success')
                    .find('.text').text(swarm.t('View Review'));

                // for change tables, update the review status icon
                button.closest('tr').find('td.review-status').append(
                    $('<i>').addClass('swarm-icon icon-review-needsReview')
                            .attr('title', swarm.te('Needs Review'))
                );

                // indicate success via a temporary tooltip.
                button.tooltip({title: swarm.t('Review Requested'), trigger: 'manual'}).tooltip('show');
                setTimeout(function(){
                    button.tooltip('destroy');
                }, 3000);
            }
        });

        return false;
    }
};
