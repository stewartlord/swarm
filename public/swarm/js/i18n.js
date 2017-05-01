/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

/*global Jed*/
swarm.translator = {
    locales:        {},
    jed:            null,
    fallbackJed:    null,

    init: function (locale, fallbackLocale) {
        // pull out locale data for safety and brevity
        locale         = swarm.translator.locales[locale]         || {};
        fallbackLocale = swarm.translator.locales[fallbackLocale] || {};

        // timeago handles message translation in its own way
        if (locale.timeago) {
            $.timeago.settings.strings = locale.timeago;
        }

        // we use jed to do the grunt work
        // we add a callback to throw for missing messages, this allows us to do fallbacks
        // jed gets mad if you give it empty messages, so we only set them if we have them
        var options = {
            missing_key_callback: function (key) {
                throw new Error("Missing message key: " + key);
            }
        };
        if (locale.messages) {
            options.domain      = 'default';
            options.locale_data = locale.messages;
        }
        swarm.translator.jed = new Jed(options);

        // we use a second instance of jed to handle the fallback locale
        options = {};
        if (fallbackLocale.messages) {
            options.domain      = 'default';
            options.locale_data = fallbackLocale.messages;
        }
        swarm.translator.fallbackJed = new Jed(options);

        // setup shorthand functions for ease of use and escapement
        var escape = $.views.converters.html;
        swarm.t = function (key, replacements, context, domain) {
            replacements = $.map($.isArray(replacements) ? replacements : [], escape);
            return swarm.translator.translate(key, replacements, context, domain);
        };
        swarm.tp = function (singular, plural, number, replacements, context, domain) {
            replacements = $.map($.isArray(replacements) ? replacements : [], escape);
            return swarm.translator.translatePlural(singular, plural, number, replacements, context, domain);
        };
        swarm.te = function (key, replacements, context, domain) {
            return escape(swarm.translator.translate(key, replacements, context, domain));
        };
        swarm.tpe = function (singular, plural, number, replacements, context, domain) {
            return escape(swarm.translator.translatePlural(singular, plural, number, replacements, context, domain));
        };

        // register translation converters in js-render for easy translation when templating
        $.views.converters({
            t: function (key, replacements, context, domain) {
                return swarm.t(key, replacements, context, domain);
            },
            te: function (key, replacements, context, domain) {
                return swarm.te(key, replacements, context, domain);
            },
            tp: function (singular, plural, number, replacements, context, domain) {
                return swarm.tp(singular, plural, number, replacements, context, domain);
            },
            tpe: function (singular, plural, number, replacements, context, domain) {
                return swarm.tpe(singular, plural, number, replacements, context, domain);
            }
        });
    },

    translate: function (message, replacements, context, domain) {
        try {
            message = swarm.translator.jed.dpgettext(domain, context, message);
        } catch (error) {
            try {
                message = swarm.translator.fallbackJed.dpgettext(domain, context, message);
            } catch (ignore) {
            }
        }

        return $.isArray(replacements) && replacements.length
            ? Jed.sprintf(message, replacements)
            : message;
    },

    translatePlural: function (singular, plural, number, replacements, context, domain) {
        var message;
        try {
            message = swarm.translator.jed.dnpgettext(domain, context, singular, plural, number);
        } catch (error) {
            try {
                message = swarm.translator.fallbackJed.dnpgettext(domain, context, singular, plural, number);
            } catch (ignore) {
            }
        }

        return Jed.sprintf(
            message,
            $.isArray(replacements) && replacements.length ? replacements : [number]
        );
   }
};
