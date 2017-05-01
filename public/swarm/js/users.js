/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

swarm.user = {
    login: function(message) {
        message = message && $('<div class="alert" />').text(message);

        if ($('.login-dialog.modal').length) {
            swarm.form.clearErrors('.login-dialog.modal form', true);
            $('.login-dialog.modal .modal-body').prepend(message);
            swarm.modal.show('.login-dialog.modal');
            return;
        }

        $.ajax({url: '/login', data: {format: 'partial'}}).done(function(data) {
            // for now we cannot use append due to performance issues with clearing password fields on long pages
            // @todo re-evaluate use of prepend after the following chrome bug is fixed http://crbug.com/180868
            $('body').prepend(data);
            $('.login-dialog.modal .modal-body').prepend(message);
        });
    },

    follow: function(type, id, button) {
        button = $(button);
        swarm.form.disableButton(button);
        button.tooltip('destroy');

        // if we are already following, unfollow
        var unfollow = button.is('.following'),
            action   = unfollow ? 'unfollow' : 'follow';

        $.post(
            '/' + action + '/' + encodeURIComponent(type) + '/' + encodeURIComponent(id),
            function(response) {
                swarm.form.enableButton(button);
                if (response.isValid) {
                    // update button text (e.g. 'Follow' -> 'Unfollow' and vice-versa)
                    button.text(unfollow ? swarm.t('Follow') : swarm.t('Unfollow'));
                    button.toggleClass('following');

                    // indicate success via a temporary tooltip.
                    button.tooltip({
                        title:   swarm.t(unfollow ? 'No longer following %s' : 'Now following %s', [id]),
                        trigger: 'manual'
                    }).tooltip('show');

                    // update the UI immediately
                    swarm.user.updateFollowersSidebar(action);

                    setTimeout(function(){
                        button.tooltip('destroy');
                    }, 3000);
                }
            }
        );

        return false;
    },

    updateFollowersSidebar: function(action) {
        var user      = swarm.user.getAuthenticatedUser(),
            counter   = $('.profile-sidebar .metrics .followers .count'),
            followers = $('.profile-sidebar .followers.profile-block'),
            avatars   = followers.find('.avatars');

        // nothing to do if not authenticated
        if (!user) {
            return;
        }

        // change the opacity of the avatar to indicate it's follow/unfollow state
        var avatar = avatars.find('img[data-user="' + user.id + '"]').closest('span');
        avatar.css('opacity', (action === 'follow' ? 1 : 0.2));

        // if we are following, but the avatar wasn't already in the page, we need to add it
        if (action === 'follow' && !avatar.length) {
            // may need to build a new row if one doesn't exist, or is full
            var row = avatars.find('> div').last();
            row     = (row.length && row.children().length < 5 && row) || $('<div />').appendTo(avatars);
            $('<span class="border-box" />').html(user.avatar).appendTo(row);
            followers.removeClass('hidden');
        }

        counter.text(parseInt(counter.text(), 10) + (action === 'follow' ? 1 : -1));
    },

    getAuthenticatedUser: function() {
        return $('body').data('user');
    }
};