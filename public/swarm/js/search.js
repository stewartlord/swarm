/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

$(function(){
    // setup typeahead for search box
    var search         = $('.navbar-site .search input'),
        typeahead      = null,
        maxResults     = 20,
        cachedResults  = {},
        delay          = 250,
        delayTimeout   = null,
        requests       = [],
        completed      = {},
        maxWait        = 2000,
        selected       = null;

    search.typeahead({
        source: function(query, process) {
            // update results from server response
            var update = function(data, status, request){
                // if an error occurred, data will be the jqXHR object
                if (data && data.promise) {
                    request = data;
                    data    = [];
                }

                // if the request was aborted, nothing further to do
                if (status === 'abort') {
                    return;
                }

                if (request) {
                    // track which queries have completed so we don't issue them twice
                    completed[query] = completed[query] || {};
                    completed[query][request.types.join(',')] = true;

                    // remove request from queue
                    requests.splice(requests.indexOf(request), 1);
                }

                // cache results by query so that we don't mix up results from different queries
                cachedResults[query] = cachedResults[query] || {};

                // add new results to cache keyed on type-id to avoid duplicates
                $.each(data || [], function() {
                    this.label = this.label || this.id;
                    cachedResults[query][this.type + '-' + this.id] = this;
                });

                // now we switch to using the current search input value
                // this is to avoid loading results for a previous query
                // note: we also switch to array form as we want to use sort()
                var results = $.map(cachedResults[search.val()] || {}, function(value, index) {
                    return [value];
                });

                // now we sort results by score and label
                results.sort(function(a, b) {
                    var difference = b.score - a.score;
                    a = a.label.toLowerCase();
                    b = b.label.toLowerCase();

                    return difference || ((a === b) ? 0 : ((a > b) ? 1 : -1));
                });

                // group/re-order results by type
                var type, types = {};
                $.each(results, function() {
                    type        = this.type;
                    this.label  = this.label  || this.id;
                    types[type] = types[type] || [];
                    types[type].push(this);
                });

                // flatten them back out again
                // limit total number of results to max results, but do so equitably
                // for instance, if max is 20 and we have 40, each group should be scaled 50%
                var scale = maxResults / results.length;
                results   = [];
                $.each(types, function() {
                    type = this;
                    $.each(type, function(index) {
                        if (index < Math.ceil(type.length * scale)) {
                            results.push(this);
                        }
                    });
                });

                process(results);
            };

            // assemble search query
            var queryString = $.param({
                q:       query,
                max:     maxResults,
                path:    ($('.breadcrumb').data('path') || '').replace(/^\/files\/?/, '/'),
                project: $('.project-navbar').data('project')
            });

            // kill any old pending requests (we need slice to iterate on a copy)
            $.each(requests.slice(), function() {
                this.abort();
            });

            // don't issue server requests immediately, delay them
            clearTimeout(delayTimeout);
            delayTimeout = setTimeout(function(){
                // shotgun approach to querying server (allows results to trickle in)
                // check if request was already done (don't re-issue completed queries)
                var request, url = swarm.url('/search?' + queryString + '&types=');
                $.each(['projects', 'users', 'files-names', 'files-contents'], function(i, type){
                    if ((completed[query] && completed[query][type])
                        || (type === 'files-contents' && !search.data('has-p4-search'))
                    ) {
                        return;
                    }

                    request       = $.get(url + type).always(update);
                    request.types = [type];
                    requests.push(request);
                });

                // results could come entirely from cache, so update manually
                update();

                // if we don't have anything before maxWait, say 'no results'
                setTimeout(function(){
                    var hasResults = $.map(cachedResults[query], function(value) { return value; }).length;
                    if (search.val() && search.val() === query && !hasResults && requests.length) {
                        typeahead.message(swarm.t('No results (yet...)'));
                    }
                }, maxWait);

            }, delay);
        },
        matcher:     function()      { return true; },
        sorter:      function(items) { return items; },
        highlighter: function(item)  { return $.views.converters.html(item.label); },
        items:       maxResults
    });
    typeahead = search.data('typeahead');

    // custom rendering to show group headings and disable auto-select
    var oldRender    = typeahead.render;
    typeahead.render = function(items) {
        var active = this.$menu.children('.active');
        oldRender.call(this, items);
        if (!active.length) {
            this.$menu.children('.active').removeClass('active');
        }

        var labels = {
            'user':    swarm.t('Users'),
            'project': swarm.t('Projects'),
            'file':    swarm.t('Files')
        };

        var type;
        this.$menu.children().each(function(i){
            var $this = $(this),
                item  = items[i];

            $this.data('item', item);

            // format item label
            $this.find('a').html($.templates(
                '{{>label}} {{if detail}}<span class="muted">{{>detail}}</span>{{/if}}'
            ).render(item));

            if (item.type !== type) {
                type = item.type;
                $this.prepend($.templates(
                    '<span class="group-heading">{{te:label}}</span>'
                ).render({label: labels[type]}));
            }
        });

        return this;
    };

    // navigate to item on selection
    typeahead.select = function() {
        var url, item = this.$menu.find('.active').data('item');
        if (!item) {
            return;
        }

        search.val(item.label);

        switch (item.type) {
            case 'user':
                url = '/users/' + swarm.encodeURIPath(item.id);
                break;
            case 'project':
                url = '/projects/' + swarm.encodeURIPath(item.id);
                break;
            case 'file':
                url = '/files/' + item.id.replace(/^\/*/, '');
                break;
        }

        window.location.href = swarm.url(url);

        return this.hide();
    };

    // add/remove active class from search input on show/hide
    var oldShow    = typeahead.show;
    var oldHide    = typeahead.hide;
    typeahead.show = function() {
        oldShow.call(this);
        search.addClass('active');
    };
    typeahead.hide = function() {
        oldHide.call(this);
        search.removeClass('active');
    };

    // custom processing:
    // - only process results when search has focus
    // - display 'no results' message
    // - preserve user's selection
    var oldProcess    = typeahead.process;
    typeahead.process = function(items) {
        if (!search.is(':focus, .active')) {
            return;
        }

        selected = this.$menu.find('.active:not(:first-child)').data('item') || selected;

        if (!items.length && !requests.length && search.val().length) {
            this.message(swarm.t('No results'));
        } else if (items.length) {
            oldProcess.call(this, items);
        }

        // restore user's selection
        var listItems = this.$menu.find('li'),
            selection = listItems.filter(function(){ return selected && $(this).data('item').id === selected.id; });
        if (selection.length) {
            listItems.removeClass('active');
            selection.addClass('active');
        }

        return this;
    };

    // ability to display a message
    typeahead.message = function(message, classes) {
        this.$menu.html($.templates(
            '<li class="message muted {{>classes}}">{{>message}}</li>'
        ).render({message: message, classes: classes}));

        this.show();
    };
});
