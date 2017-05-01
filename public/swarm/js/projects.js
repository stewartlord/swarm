/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

swarm.project = {
    initEdit: function(wrapper, saveUrl, projectId, enableGroups) {
        var membersElement = $(wrapper).find('#members'),
            ownersElement  = $(wrapper).find('#owners');

        // setup userMultiPicker plugin for selecting members/owners
        membersElement.userMultiPicker({
            required:             true,
            itemsContainer:       $(wrapper).find('.members-list'),
            inputName:            'members',
            groupInputName:       'subgroups',
            enableGroups:         enableGroups,
            excludeProjects:      [projectId]
        });
        ownersElement.userMultiPicker({
            itemsContainer: $(wrapper).find('.owners-list'),
            inputName:      'owners',
            required:       function() {
                return $(wrapper).find('.checkbox-owners').prop('checked');
            }
        });

        // when owners checkbox is clicked, update userMultiPicker required property and, if unchecked,
        // disable input element to prevent from sending data for selected owners when form is posted
        $(wrapper).find('.checkbox-owners').on('click', function(){
            var checked = $(wrapper).find('.checkbox-owners').prop('checked');

            $(wrapper).find('#owners').userMultiPicker('updateRequired');
            $(wrapper).find('.owners-list input').prop('disabled', !checked);
        });

        // wire up the member branches
        swarm.project.branch.init(wrapper);
        $(wrapper).find('.swarm-branch-group').on('click', swarm.project.branch.openNewSubForm);
        $(wrapper).on('click.swarm.branch.clear', '.branches .clear-branch-btn', function(e) {
            $(this).parent().find(".subform-identity-element").val('');
            swarm.project.branch.closeSubForm($(this).closest('.btn-group').find('.btn.dropdown-toggle'));
        });
        $(wrapper).on('click.swarm.branch.close', '.branches .close-branch-btn', function(e) {
            swarm.project.branch.closeSubForm($(this).closest('.btn-group').find('.btn.dropdown-toggle'));
        });

        // add help popover for the automated argument details
        $(wrapper).find('.automated-tests .help-details').popover({container: '.automated-tests', trigger: 'hover focus'});

        // add help popover for the automated deployment details
        $(wrapper).find('.automated-deployment .help-details').popover({container: '.automated-deployment', trigger: 'hover focus'});

        // check the form state and wire up the submit button
        swarm.form.checkInvalid($(wrapper).find('form'));
        $(wrapper).find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post(
                saveUrl,
                $(wrapper).find('form'),
                null,
                null,
                function (form) {
                    var formObject = $.deparam($(form).serialize());

                    // filter branches array to discard elements for removed branches
                    // these elements still contribute to the array length (with undefined values)
                    // and would otherwise be sent to the server
                    if ($.isArray(formObject.branches)) {
                        formObject.branches = formObject.branches.filter(function(value){
                            return value !== undefined;
                        });
                    }

                    // ensure we post owners and branches (unless they are read-only)
                    formObject = $.extend({owners: null}, formObject);
                    if (!formObject.branches && !$(form).find('.branches').is('.readonly')) {
                        formObject.branches = null;
                    }

                    // ensure we post members and subgroups (if they are enabled)
                    formObject = $.extend({members: null}, formObject);
                    if (enableGroups) {
                        formObject = $.extend({subgroups: null}, formObject);
                    }

                    return formObject;
                }
            );
        });

        // wire up project delete
        $(wrapper).find('.btn-delete').on('click', function(e){
            e.preventDefault();

            var button  = $(this),
                confirm = swarm.tooltip.showConfirm(button, {
                    placement:  'top',
                    content:    swarm.te('Delete this project?'),
                    buttons:    [
                        '<button type="button" class="btn btn-primary btn-confirm">' + swarm.te('Delete') + '</button>',
                        '<button type="button" class="btn btn-cancel">' + swarm.te('Cancel') + '</button>'
                    ]
                });

            // wire up cancel button
            confirm.tip().on('click', '.btn-cancel', function(){
                confirm.destroy();
            });

            // wire up delete button
            confirm.tip().on('click', '.btn-confirm', function(){
                // disable buttons when the delete is in progress
                swarm.form.disableButton(confirm.tip().find('.btn-confirm'));
                confirm.tip().find('.buttons .btn').prop('disabled', true);

                // attempt to delete the project via ajax request
                $.post('/projects/delete/' + encodeURIComponent(projectId), function(response) {
                    // if there is an error, present it in a new tooltip, otherwise
                    // redirect to the home page
                    if (response.isValid) {
                        window.location.href = '/';
                    } else {
                        confirm.destroy();
                        var errorConfirm = swarm.tooltip.showConfirm(button, {
                            placement:  'top',
                            content:    response.error,
                            buttons:    [
                                '<button type="button" class="btn btn-primary">' + swarm.te('Ok') + '</button>'
                            ]
                        });
                        errorConfirm.tip().on('click', '.btn', function(){
                            errorConfirm.destroy();
                        });
                    }
                });
            });
        });

        var setPlaceholder = function() {
            var format      = $('select#postFormat').val(),
                placeholder = '';
            if (format === 'URL') {
                placeholder = 'foo=bar&baz=buzz';
            } else {
                placeholder = '{"foo" : "bar", "baz" : "buzz"}';
            }
            $('textarea#postBody').attr('placeholder', placeholder);
        };

        // initialize placeholder for post body
        setPlaceholder();
        $('select#postFormat').on('change', setPlaceholder);

        var showEmailNotificationDetails = function() {
            var hidden = $('input#reviewEmails').prop('checked') || $('input#changeEmails').prop('checked');
            $('.email-flags').find('.help-block').toggleClass('hide', hidden);
        };

        showEmailNotificationDetails();
        $('.email-flags').find('input[type="checkbox"]').on('change', showEmailNotificationDetails);

        // ignore checkbox clicks during the collapsing animation for the corresponding submenu
        $('input[type=checkbox][data-toggle=collapse]').on('click', function(e){
            if ($(this).parent().siblings('div.body').hasClass('collapsing')) {
                e.preventDefault();
            }
        });
    },

    branch: {
        _branchIndex: 0,

        init : function(wrapper) {
            // find any existing branches so we can init them and advance past their index
            $(wrapper).find('.control-group.branches .existing').each(function() {
                swarm.project.branch.initBranch(this);
                $(this).find('.btn.dropdown-toggle').dropdown();

                // advanced past this branch's index if its the highest we've seen
                var index = parseInt(
                    $(this).find('input[name^="branches["]')
                        .attr('name')
                        .match(/branches\[([0-9]+)\]/)[1],
                    10);
                if (index >= swarm.project.branch._branchIndex) {
                    swarm.project.branch._branchIndex = index + 1;
                }
            });

            // wire-up collapsing branch moderators
            // we can't use the default bootstrap's collapse plugin event as the on-click
            // event is stopped propagation due to our tweaks for drop-down menu
            $(wrapper).on('click.moderator.checkbox', '.checkbox-moderators', function() {
                $(this).closest('.control-group-moderators').find('.collapse').collapse(
                    $(this).prop('checked') ? 'show' : 'hide'
                );
            });

            // hide add-branch link if branches are read-only
            $(wrapper).find('.branches.readonly').find('.swarm-branch-link').hide();
        },

        initBranch : function(branch) {
            // add listener to new branch button
            $(branch).find('.btn.dropdown-toggle').on('click', function(e) {
                if ($(this).parent().hasClass('open')) {
                    swarm.project.branch.onCloseSubForm(this);
                    return;
                }
                $('.branches .open .btn.dropdown-toggle').not(this).each(function() {
                    swarm.project.branch.onCloseSubForm(this);
                });
                swarm.project.branch.onOpenSubForm(this);
            });

            // wire up close listener
            $('html').on('click.swarm.branchgroup.close', function(e) {
                swarm.project.branch.onCloseSubForm($(branch).find('.btn.dropdown-toggle'));
            });

            // prepare handler to check if branch sub-form is valid
            var checkBranchSubForm = function() {
                var branchButton = $(branch).closest('.branch-button'),
                    subForm      = branchButton.find('.dropdown-subform');

                swarm.form.checkInvalid(subForm);

                // highlight branch drop-down button and disable branch 'Done' button is subform is invalid
                branchButton.find('.btn.dropdown-toggle').toggleClass('btn-danger', subForm.is('.invalid'));
                subForm.find('.btn.close-branch-btn').prop('disabled', subForm.is('.invalid'));
            };

            // wire up required fields check in branch sub-form
            $(branch).find('input,textarea').on('input keyup blur', checkBranchSubForm);

            // setup userMultiPicker plugin for selecting moderators
            var moderators = $(branch).find('input.input-moderators');
            moderators.userMultiPicker({
                itemsContainer: $(branch).find('.moderators-list'),
                disabled:       $(branch).closest('.branches').is('.readonly'),
                onUpdate:       function() {
                    checkBranchSubForm();

                    // update moderators info
                    var moderatorsList = $(branch).find('.checkbox-moderators:checked').length
                            ? $.map(this.getSelected(), function(value){ return value.label; })
                            : [],
                        infoText       = moderatorsList.length
                            ? swarm.tp('%s Moderator', '%s Moderators', moderatorsList.length)
                            : '';
                    this.$element.closest('.branch-button').find('.moderators-info')
                        .text(infoText)
                        .attr({'data-original-title': moderatorsList.join(', '), title: ''});
                    },
                required:       function() {
                    return $(branch).find('.checkbox-moderators').prop('checked');
                }
            });

            // when moderators checkbox is clicked, update userMultiPicker required property and, if unchecked,
            // disable input element to prevent from sending data for selected moderators when form is posted
            $(branch).find('.checkbox-moderators').on('click', function(){
                var checked = $(this).prop('checked');

                $(branch).find('.moderators-list input').prop('disabled', !checked);
                moderators.userMultiPicker('update');
                checkBranchSubForm();
            });

            // check the branch sub-form for initial errors
            checkBranchSubForm();

            // disable branch input elements if branch is read-only to prevent sending branches data
            if ($(branch).closest('.branches').is('.readonly')) {
                $(branch).find('input,textarea,.item-remove,.clear-branch-btn').prop('disabled', true);
            }
        },

        onOpenSubForm : function(element) {
            setTimeout(function() {
                $(element).parent().find(".subform-identity-element").focus();
            }, 0);
        },

        onCloseSubForm : function(element) {
            // if we have a label, update buttons
            // else remove this particular branch
            var label = $(element).parent().find(".subform-identity-element").val(),
                form  = $(element).closest('form');
            if (label) {
                $(element).html('<span class="branch-label"></span><span class="caret"></span>')
                          .find('span.branch-label')
                          .text(label);
            } else {
                $(element).closest('.branch-button').remove();
            }

            // re-validate the form to clear potential errors from the removed sub-form
            swarm.form.checkInvalid(form);
        },

        closeSubForm : function(element) {
            swarm.project.branch.onCloseSubForm($(element).dropdown('toggle'));
        },

        openNewSubForm: function(e) {
            e.preventDefault();
            e.stopPropagation();

            $('.branches .open .btn.dropdown-toggle').each(function() {
                swarm.project.branch.closeSubForm(this);
            });

            // find the subform template to render
            // and render the template into our dropdown menu
            var branchIndex     = swarm.project.branch._branchIndex,
                template        = $('.controls .branch-template'),
                newBranch       = template.children().clone(),
                nameField       = newBranch.find('.subform-identity-element'),
                pathsField      = newBranch.find('.branch-paths'),
                moderatorsField = newBranch.find('input.input-moderators');

            nameField.attr('name',      'branches[' + branchIndex + '][name]');
            pathsField.attr('name',     'branches[' + branchIndex + '][paths]');
            nameField.attr('id',        'branch-name-'  + branchIndex);
            pathsField.attr('id',       'branch-paths-' + branchIndex);
            pathsField.attr('required', true);

            nameField.siblings('label').attr('for',  'branch-name-'  + branchIndex);
            pathsField.siblings('label').attr('for', 'branch-paths-' + branchIndex);

            moderatorsField.attr('data-input-name', 'branches[' + branchIndex + '][moderators]');

            swarm.project.branch._branchIndex++;

            newBranch.insertBefore($(this).parent());

            swarm.project.branch.initBranch(newBranch);
            newBranch.find('.btn.dropdown-toggle').dropdown('toggle');
            swarm.project.branch.onOpenSubForm(newBranch.find('.btn.dropdown-toggle'));
        }
    }
};

swarm.projects = {
    init: function(table) {
        table = $(table);

        // enable all/my-projects dropdown if user is logged in
        // prefix heading with all or my as per default setting
        var handleLogin = function() {
            var scope  = swarm.localStorage.get('projects.scope') || 'all',
                prefix = scope === 'user' ? 'My ' : 'All ';
            table.find('thead .projects-title').text(swarm.t(prefix + 'Projects'));
            table.find('thead .projects-dropdown').addClass('dropdown');
        };

        if ($('body').is('.authenticated')) {
            handleLogin();
        }

        // enable dropdown and reload the table when user logs in
        $(document).on('swarm-login', function () {
            handleLogin();
            swarm.projects.load(table);
        });

        // connect to drop-down to switch between all/my-projects
        table.find('ul.dropdown-menu a').on('click', function (e) {
            e.preventDefault();
            var scope = $(this).closest('li').data('scope');
            swarm.localStorage.set('projects.scope', scope);
            swarm.projects.update(table, scope);
        });

        swarm.projects.load(table);
    },

    load: function(table) {
        table = $(table);
        table.addClass('loading');

        $.ajax('/projects').done(function(data) {
            // each project gets ranked based on how many members and followers it has
            var rankings = data;
            rankings.sort(function(a, b) {
                return (b.members + b.followers) - (a.members + a.followers);
            });

            table.find('tbody').empty();
            $.each(data, function(){
                // determine project ranking on a scale of 0.5-1.0.
                var project   = this;
                project.users = project.members + project.followers;
                project.score = $.inArray(project, rankings.slice().reverse()) / (rankings.length - 1);
                project.score = Math.round((project.score * 0.5 + 0.5) * 10) / 10;

                var row = $.templates(
                      '<tr class="project{{if isMember}} is-member{{/if}}">'
                    + ' <td>'
                    + '  <div class="metrics pull-right padw1 muted">'
                    + '   <span class="users badge count-{{>users}}"'
                    + '         style="opacity: {{>score}};"'
                    + '         title="{{>members}}&nbsp;{{tpe:"Member" "Members" members}},'
                    + '                {{>followers}}&nbsp;{{tpe:"Follower" "Followers" followers}}">'
                    + '     {{>users}}'
                    + '   </span>'
                    + '  </div>'
                    + '  <a href="{{url:"/projects"}}/{{urlc:id}}" class="name">{{>name}}</a>'
                    + '  <p class="description muted"><small>'
                    + '   {{if description}}{{:description}}{{else}}{{te:"No description"}}{{/if}}'
                    + '  </small></p>'
                    + ' </td>'
                    + '</tr>'
                ).render(project);

                table.find('tbody').append(row);
            });

            // truncate the description
            table.find('.description').expander({slicePoint: 70});

            // update table to refresh heading and rows visibility
            swarm.projects.update(table);

            table.removeClass('loading');
        });
    },

    update: function(table, scope) {
        // determine whether to show all projects or 'my-projects'
        //  - if scope is explicitly passed, we always honor it
        //  - else, if user is logged in we check local-storage for a preference
        //          if user is not in any projects we show all to avoid an empty table
        var isAuthenticated = $('body').is('.authenticated'),
            memberRows      = table.find('tbody tr.is-member'),
            filterByMember  = scope === 'user';
        if (!scope) {
            filterByMember  = isAuthenticated && swarm.localStorage.get('projects.scope') === 'user';
            filterByMember  = memberRows.length ? filterByMember : false;
        }

        // update header - if dropdown enable, prefix with all/my
        var prefix = '';
        if (table.find('thead .projects-dropdown').is('.dropdown')) {
            prefix = filterByMember ? 'My ' : 'All ';
        }
        table.find('thead .projects-title').text(swarm.t(prefix + 'Projects'));

        // show/hide rows as per filterByMember
        var rows    = table.find('tbody tr').hide(),
            visible = filterByMember ? memberRows : rows;
        visible.show();

        // show alert if there are no visible projects
        table.find('tbody tr.projects-info').remove();
        if (!visible.length) {
            table.find('tbody').append(
                $(
                      '<tr class="projects-info"><td><div class="alert border-box pad3">'
                    + swarm.te('No projects.')
                    + '</div></td></tr>'
                )
            );
        }

        // set 'first/last-visible' class on the first/last visible row to assist
        // with styling via CSS as :first-child won't work when in 'my-projects' view
        rows.removeClass('first-visible last-visible');
        visible.first().addClass('first-visible');
        visible.last().addClass('last-visible');
    }
};
