// Perforce Documentation Javascript
//
// vim: set ts=2 sw=2 tw=80 ai si:

$("#header button.search").css("display", "block");
var wNSC = '-=-';

!function ($) {
  $(function() {

    var $window   = $(window),
        $body     = $(document.body),
        navHeight = $("#header").outerHeight(true),
        preName;

    // generate the sidebar toc
    p4.generateSidebar();

    // handle nav pane scroll position
    var navInfo     = false;
    var navScroll   = 0;
    var navItemPos  = 0;

    // if a nav scroll position has been recorded, use it
    if (window.name.search('^'+ window.location.host +wNSC+ '(\\d+)' +wNSC+ '(\\d+)') == 0) {
      var zname   = window.name.split(wNSC);
      navItemPos  = Number(zname[1]);
      navScroll   = Number(zname[2]);
      navInfo     = true;
      window.name = zname.slice(3).join(wNSC);
      $("#nav > ul").scrollTop(navScroll);
    }

    // determine if we have an active item in the nav pane
    var actives = $("#nav.toc .active");
    if (actives.length) {
      var last = actives[actives.length - 1];
      var itemPos = $(last).offset().top;
      if (itemPos !== undefined) {
        if (navInfo) {
          navScroll = navScroll + itemPos - navItemPos;
        }
        else {
          var parentPos = $("#nav > ul").offset().top;
          navScroll = itemPos - parentPos;
        }
        if (navScroll < 0) navScroll = 0;
      }
    }

    if (navScroll > 0) {
      $("#nav > ul").scrollTop(navScroll);
    }
    $("#nav .cover").hide();

    // hookup ScrollSpy
    $("body").scrollspy({
      target: '#nav',
      offset: navHeight + 40,
    }).focus();

    // hookup swiping for navigation
    $window.touchwipe({
      wipeLeft: function() {
        if (!$("link[rel='next']").length) return;
        e.preventDefault();
        window.location = $("link[rel='next']").attr("href");
      },
      wipeRight: function() { 
        if (!$("link[rel='prev']").length) return;
        e.preventDefault();
        window.location = $("link[rel='prev']").attr("href");
      },
      preventDefaultEvents: false
    });

    // hookup reading progress
    $(document).on("scroll", function (e) {
      var contentHeight = $("#content").innerHeight(),
          headerHeight  = $("#header").innerHeight(),
          w             = $(window),
          wHeight       = w.innerHeight(),
          scrolled      = Math.max(w.scrollTop() - headerHeight, 0),
          area          = Math.max(contentHeight - wHeight, 1),
          percentage    = Math.round(100 * scrolled / area) + "%";
      $("#progress").css({ width: percentage });
    });

    // estimate reading time
    var totalWords = $("#content").text().trim().split(/\s+/g).length;
    var timeEst = Math.ceil(totalWords / 240);
    $("#content .container").prepend(
      $('<div class="eta"><span class="time">'
        + p4.t("Reading time: about ")
        + timeEst
        + ' '
        + (timeEst != 1 ? p4.t("minutes") : p4.t("minute"))
        + '</span> <span class="words">('
        + p4.formatThousands(totalWords)
        + ' '
        + p4.t("words")
        + ')</span></div>')
    );

    // hookup menu
    $("button.toc").on("click", function (e) {
      e.preventDefault();
      p4.toggleMenu();
    });

    $("a.expander").on("click", function (e) {
      e.preventDefault();
      var pNode  = $(this).parent("li"),
          ulNode = $(this).next("ul");
      if (pNode.hasClass("expanded")) {
        pNode.removeClass("expanded");
        ulNode.css("maxHeight", "");
      }
      else {
        pNode.addClass("expanded");
        var ulHeight = 0;
        ulNode.find("li").each(function () {
          ulHeight += $(this).height();
        });
        ulNode.css("maxHeight", ulHeight + "px");
      }
    });

    // hookup search
    $("button.search").on("click", function (e) {
      e.preventDefault();
      p4.toggleSearch();
    });

    $("button.clear").on("click", function (e) {
      e.preventDefault();
      $("#search-text").val("");
      p4.searcher.clear();
    });

    $("#search-text").on("keyup", function (e) {
      var results = p4.searcher.search($("#search-text").val());
      p4.searcher.unhighlight();
      p4.searcher.render(results);
      p4.searcher.highlight(p4.searcher.theWords);
    });

    $("#search input.substring").on("change", function (e) {
      var results = p4.searcher.search($("#search-text").val());
      p4.searcher.render(results);
    });

    $("#search input.highlight").on("change", function (e) {
      p4.searcher.toggleHighlight();
    });

    p4.searcher.highlight();

    // record nav scroll on nav link click
    $("#nav ul.toc a[href]").on("click", function (e) {
      if (typeof(preName) === 'undefined') preName = window.name;

      var curLoc = location.pathname.split('/').reverse()[0];
      var hrefLoc = $(this).attr("href").split('#')[0];
      if (curLoc !== hrefLoc) {
        var navScroll = $("#nav > ul").scrollTop();
        var itemPos = $(this).parent().offset().top;
        var newName = window.location.host +wNSC+ itemPos +wNSC+ navScroll +wNSC+ preName;
        window.name = newName;
      }
    });

    // setup keyboard help dialog
    var helpDialogHTML = [
      '<div>',
      '  <a href="#myModal" id="hotkeys" role="button" data-toggle="modal"></a>',
      '  <div id="myModal" class="modal fade in" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="false">',
      '    <div class="modal-dialog">',
        '    <div class="modal-content">',
      '        <div class="modal-header">',
      '          <button type="button" class="close" data-dismiss="modal">×</button>',
      '          <h4 id="myModalLabel" class="modal-title">'
      + p4.t("Documentation Keyboard Shortcuts")
      + '</h4>',
      '        </div>',
      '        <div class="modal-body">',
      '          <dl>',
      '            <dt>?</dt>',
      '            <dd>'
      + p4.t("Show this help")
      + ' (<b>' + p4.t("ESC") + '</b> '
      + p4.t("to close") + ')</dd>',
      '            <dt>n '
      + p4.t("or") + ' <i class="glyphicon glyphicon-arrow-right"/></dt>',
      '            <dd>' + p4.t("Next page") + '</dd>',
      '            <dt>p '
      + p4.t("or") + ' <i class="glyphicon glyphicon-arrow-left"/></dt>',
      '            <dd>' + p4.t("Previous page") + '</dd>',
      '            <dt>t</dt>',
      '            <dd>' + p4.t("Scroll to top of current page") + '</dd>',
      '            <dt>h</dt>',
      '            <dd>' + p4.t("Go to the home page of this guide") + '</dd>',
      '            <dt>u</dt>',
      '            <dd>' + p4.t("Go up one level in this guide") + '</dd>',
      '            <dt>s ' + p4.t("or") + ' f</dt>',
      '            <dd>' + p4.t("Open the search sidebar") + '</dd>',
      '            <dt>c</dt>',
      '            <dd>' + p4.t("Clear the search term(s)") + '</dd>',
      '          </dl>',
      '        </div>',
      '      </div>',
      '    </div>',
      '  </div>',
      '</div>'
    ].join("\n");
    $("body").append(helpDialogHTML);

    // hookup keyboard doc navigation
    $(document).on("keydown", function(e) {
      // don't act on special keys, already handled events, or text input areas
      if (e.altKey || e.ctrlKey || e.metaKey || e.isDefaultPrevented()
        || $(e.target).is("input, textarea, select")
      ) {
        return;
      }

      switch (e.which) {
        case 191: // ?
          if (e.shiftKey) {
            $("#hotkeys").trigger("click");
          }
          break;	

        case 78: // n
        case 39: // right-arrow
          if ($("link[rel='next']").length) {
            e.preventDefault();
            window.location = $("link[rel='next']").attr("href");
          }
          break;

        case 80: // p
        case 37: // left-arrow
          if ($("link[rel='prev']").length) {
            e.preventDefault();
            window.location = $("link[rel='prev']").attr("href");
          }
          break;

        case 85: // u
          if ($("link[rel='up']").length) {
            e.preventDefault();
            window.location = $("link[rel='up']").attr("href");
          }
          break;

        case 72: // h
          if ($("link[rel='top']").length) {
            e.preventDefault();
            window.location = $("link[rel='top']").attr("href");
          }
          break;

        case 84: // t
          e.preventDefault();
          $("#content").scrollTop(0);
          break;

        case 70: // f
        case 83: // s
          e.preventDefault();
          p4.toggleSearch();
          break;

        case 67: // c
          e.preventDefault();
          p4.searcher.clear();
          break;
      }
    });

    // hookup prettify
    $("pre.programlisting").each(function () {
      var $this = $(this),
          lang = $this.attr("lang");
      if (lang) {
        $this.addClass("lang-" + lang);
        if (lang !== "bash") {
          $this.addClass("prettyprint");
        }
      }
    });
    window.prettyPrint();

    // fix up images in lists of instructions
    $("span.thumb img").each(function () {
        $(this).attr("title", $(this).attr("alt"));
    })
    $("span.popup img").each(function () {
      var title = $(this).attr("alt") ? ": " + $(this).attr("alt") : "";
      $(this).attr("title", p4.t("Click to expand") + title);
      var expander = $('<span class="expand">'
      + p4.t("Click to expand") + ' <i class="glyphicon glyphicon-fullscreen"></i></span>');
      $(this).parent().append(expander);
    });
    $(document).on("click", "span.popup img,span.popup .expand", function (e) {
      p4.docShowPopup($("img", $(this).parent()));
    });
    $(document).on("click", ".doc-image-popup", function(e) {
      p4.docHidePopup();
    });

    // make external reference links open a new browser tab/window
    $("a.link[href^=http]").each(function () {
      $(this).attr("target", "_blank");
    });

    // tweak the offset to links with hashtags
    $('a[href*=#]:not([href=#]):not([href^=javascript])').click(function (e) {
      if (
        location.pathname.replace(/^\//,'') == this.pathname.replace(/^\//,'') 
        && location.hostname == this.hostname
      ) {
        var zhash = this.hash.split('#')[1];
        p4.animateScroll(p4.hashScrollPos(zhash));
      }
    });

    // Scroll on hash change
    $(window).on('hashchange', p4.scrollToHash);

    // Scroll on page load with URL containing an anchor tag.
    p4.scrollToHash();

    // make code sample shell prompts non-selectable
    $("pre").each(function () {
      var $this = $(this),
          lines = $this.html().split("\n"),
          newlines = [],
          i = 0;
      for (i = 0; i < lines.length; i++) {
        var line = lines[i];
        if (line.match(/^(.*\$|(PS )?[Cc]:\\[^>&]*[>&][^ ]*) <span/)) {
          line = line.replace(/^(.*\$|(PS )?[Cc]:\\[^>&]*[>&][^ ]*) /, '<span class="prompt" data-prompt="$1"></span>');
        }
        else if (line.match(/^# <span/)) {
          line = line.replace(/^(#) /, '<span class="prompt root" data-prompt="$1"></span>');
        }
        newlines.push(line);
      }
      $this.html(newlines.join("\n"));
    });
  });
}(window.jQuery);

// ensure we have an Object.keys() method
if (!Object.keys) Object.keys = function(o) {
  if (o !== Object(o))
    throw new TypeError("Object.keys called on non-object");
  var ret=[],p;
  for (p in o) {
    if (Object.prototype.hasOwnProperty.call(o, p)) ret.push(p);
  }
  return ret;
}

// provide an array intersection function
$.arrayIntersect = function(a, b) {
  return $.grep(a, function(i) {
    return $.inArray(i, b) > -1;
  });
}

// add a case-insensitive Contains method
jQuery.expr[":"].Contains = jQuery.expr.createPseudo(function(arg) {
    return function( elem ) {
        return jQuery(elem).text().toUpperCase().indexOf(arg.toUpperCase()) >= 0;
    };
});

// ====================================================================
// Customized Bootstrap ScrollSpy for Perforce documentation navigation
// ====================================================================

+function ($) { "use strict";

  // SCROLLSPY CLASS DEFINITION
  // ==========================

  function ScrollSpy(element, options) {
    var href
    var process  = $.proxy(this.process, this)

    this.$element       = $(element).is('body') ? $(window) : $(element)
    this.$body          = $('body')
    this.$scrollElement = this.$element.on('scroll.bs.scroll-spy.data-api', process)
    this.options        = $.extend({}, ScrollSpy.DEFAULTS, options)
    this.selector       = (this.options.target
      || ((href = $(element).attr('href')) && href.replace(/.*(?=#[^\s]+$)/, '')) //strip for ie7
      || '') + ' .nav li > a'
    this.offsets        = $([])
    this.targets        = $([])
    this.activeTarget   = null

    this.refresh()
    this.process()
  }

  ScrollSpy.DEFAULTS = {
    offset: 10
  }

  ScrollSpy.prototype.refresh = function () {
    var offsetMethod = this.$element[0] == window ? 'offset' : 'position'

    this.offsets = $([])
    this.targets = $([])

    var dURL = window.location.pathname.match(/([^\/]+)$/)
    var self     = this
    var $targets = this.$body
      .find(this.selector)
      .map(function () {
        var $el    = $(this)
        var href   = $el.data('target') || $el.attr('href')
        var $href  = /^#\w/.test(href) && $(href)
        var href2  = /\/?([^/#]+)(#.+)$/.exec(href);
        var $href2;
        if (href2 && href2[2] && dURL && href2[1] === dURL[1]) {
          $href2 = $(href2[2].replace(/\./g, "\\."));
        }

        if (!$href2 || !$href2.length) {
          return null;
        }

        return (
          [[ $href2[offsetMethod]().top + (!$.isWindow(self.$scrollElement.get(0)) && self.$scrollElement.scrollTop()), href2[2] ]]
        ) || null;
      })
      .sort(function (a, b) { return a[0] - b[0] })
      .each(function () {
        self.offsets.push(this[0])
        self.targets.push(this[1])
      })
  }

  ScrollSpy.prototype.process = function () {
    var scrollTop    = this.$scrollElement.scrollTop() + this.options.offset
    var scrollHeight = this.$scrollElement[0].scrollHeight || this.$body[0].scrollHeight
    var maxScroll    = scrollHeight - this.$scrollElement.height()
    var offsets      = this.offsets
    var targets      = this.targets
    var activeTarget = this.activeTarget
    var i

    if (scrollTop >= maxScroll) {
      return activeTarget != (i = targets.last()[0]) && this.activate(i)
    }

    for (i = offsets.length; i--;) {
      activeTarget != targets[i]
        && scrollTop >= offsets[i]
        && (!offsets[i + 1] || scrollTop <= offsets[i + 1])
        && this.activate( targets[i] )
    }
  }

  ScrollSpy.prototype.activate = function (target) {
    this.activeTarget = target

    $(this.selector)
      .parents('.active')
      .removeClass('active')

    var selector = this.selector
      + '[data-target$="' + target + '"],'
      + this.selector + '[href$="' + target + '"]'

    var active = $(selector)
      .parents('li')
      .addClass('active')

    if (active.parent('.dropdown-menu').length)  {
      active = active
        .closest('li.dropdown')
        .addClass('active')
    }

    active.trigger('activate')
  }


  // SCROLLSPY PLUGIN DEFINITION
  // ===========================

  var old = $.fn.scrollspy

  $.fn.scrollspy = function (option) {
    return this.each(function () {
      var $this   = $(this)
      var data    = $this.data('bs.scrollspy')
      var options = typeof option == 'object' && option

      if (!data) $this.data('bs.scrollspy', (data = new ScrollSpy(this, options)))
      if (typeof option == 'string') data[option]()
    })
  }

  $.fn.scrollspy.Constructor = ScrollSpy


  // SCROLLSPY NO CONFLICT
  // =====================

  $.fn.scrollspy.noConflict = function () {
    $.fn.scrollspy = old
    return this
  }


  // SCROLLSPY DATA-API
  // ==================

  $(window).on('load', function () {
    $('[data-spy="scroll"]').each(function () {
      var $spy = $(this)
      $spy.scrollspy($spy.data())
    })
  })

}(window.jQuery);

// ====================================================================
// Customized Search facility, adapted from DocBook WebHelp code
// ====================================================================

p4 = {};

// localization setup
p4.locale_text = {
  "Reading time: about":                "Reading time: about",
  "minute":                             "minute",
  "minutes":                            "minutes",
  "words":                              "words",
  "Documentation Keyboard Shortcuts":   "Documentation Keyboard Shortcuts",
  "Show this help":                     "Show this help",
  "ESC":                                "ESC",
  "to close":                           "to close",
  "or":                                 "or",
  "Next page":                          "Next page",
  "Previous page":                      "Previous page",
  "Scroll to top of current page":      "Scroll to top of current page",
  "Go to the home page of this guide":  "Go to the home page of this guide",
  "Go up one level in this guide":      "Go up one level in this guide",
  "Open the search sidebar":            "Open the search sidebar",
  "Clear the search term(s)":           "Clear the search term(s)",
  "Click to expand":                    "Click to expand",
  "Click to shrink":                    "Click to shrink",
  "Exact Matches":                      "Exact Matches",
  "Fuzzy Matches":                      "Fuzzy Matches",
  "Phrase Matches":                     "Phrase Matches",
}

p4.generateSidebar = function () {
  var i, ul = $('<ul class="toc nav"/>');
  var curLoc = location.pathname.split('/').reverse()[0];
  $.each(sidebar_toc.n, function (i) {
    var li = p4.makeNav(sidebar_toc.n[i], curLoc);
    li.appendTo(ul);
  });
  ul.appendTo("#nav");
}

p4.t = function(key) {
  if (p4.locale_text.hasOwnProperty(key)) {
    return p4.locale_text[key];
  }
  return key;
}

p4.makeNav = function (nav, curLoc) {
  var active = (curLoc === nav.u) ? '<li class="active">' : "<li>";
  var li = $(active + '<a href="' + nav.u +'"><span class="prefix"></span>'+ nav.t +'</a></li>');
  if (nav.hasOwnProperty('n')) {
    var a = $('<a class="expander"><span class="glyphicon glyphicon-chevron-down"></span></a>');
    a.appendTo(li);
    var i, sa = 0, ul = $('<ul class="nav"/>');
    $.each(nav.n, function (i) {
      var subnav = nav.n[i];
      var sli = p4.makeNav(subnav, curLoc);
      if (sli.attr('class') == 'active') sa = 1;
      sli.appendTo(ul);
    });
    if (sa) li.attr('class', 'active');
    ul.appendTo(li);
  }
  return li;
}

p4.revStr = function (str) {
  return (str + "").split('').reverse().join('');
}

p4.formatThousands = function (num) {
  return p4.revStr(p4.revStr(num).replace(/(\d{3})(?=\d)(?!d*\.)/g,"$1,"));
}

p4.scrollToHash = function () {
  if (window.location.hash && window.location.hash.length) {
    p4.animateScroll(p4.hashScrollPos(window.location.hash.split('#')[1]));
  }
}

p4.animateScroll = function (scrollPos) {
  if (scrollPos < 0) scrollPos = 0;
  $("html,body").animate({
    scrollTop: Math.floor(scrollPos)
  }, 300);
}

p4.hashScrollPos = function (zhash) {
  if (zhash === undefined || zhash.length == 0) return 0;

  var target;
  var vOffset = 45; // to position items below the header

  switch (zhash[0]) {
    case 'S':
      // scroll to a percentage of the content
      var pct = Math.floor(zhash.split("S")[1]);
      if (pct >= 0) {
        var container = $("#content .container");
        return Math.floor(
          (container.height() + container.offset().top) * pct / 100
        );
      }
      return p4.hashScrollPosDefault(zhash);

      break;

    case 'P':
      // scroll to a specific paragraph
      var par = Math.floor(zhash.split("P")[1]);
      if (par > 0) {
        target = $("p, dt, dd, pre", "#content .container");
        if (target !== undefined && target.length
            && target[par - 1] !== undefined
        ) {
          return $(target[par - 1]).offset().top - vOffset;
        }
      }
      return p4.hashScrollPosDefault(zhash);

      break;

    case '/':
      // scroll to the first instance of a term
      var term = decodeURIComponent(zhash.split("/")[1]);
      if (term !== undefined && term.length) {
        target = $("#content .container *:Contains('"+ term +"')")
              .filter(function () {
                return $(this).children().length === 0;
              });
        if (target !== undefined && target.length) {
          return $(target[0]).offset().top - vOffset;
        }
      }
      break;

    default:
      return p4.hashScrollPosDefault(zhash);
  }

  return 0;
};

p4.hashScrollPosDefault = function (zhash) {
  if (document.getElementById(zhash) !== null) {
    target = $('#' + zhash.replace(/\./g, "\\."));
    if (target !== undefined && target.length) {
      return target.offset().top - 45;
    }
  }
  return 0;
};

p4.toggleMenu = function() {
  var elem = $("#nav");
  if (elem.hasClass("open")) {
    elem.removeClass("open");
    $("button.toc").removeClass("active");
  }
  else {
    elem.addClass("open");
    $("button.toc").addClass("active");
    $("#search").removeClass("open");
    $("button.search").removeClass("active");
  }
};

p4.toggleSearch = function() {
  var elem = $("#search");
  if (elem.hasClass("open")) {
    elem.removeClass("open");
    $("button.search").removeClass("active");
    $("#search-text").blur();
  }
  else {
    elem.addClass("open");
    $("button.search").addClass("active");
    $("#search-text").focus();
    $("#nav").removeClass("open");
    $("button.toc").removeClass("active");
  }
};

p4.docShowPopup = function (img) {
  p4.docHidePopup();
  var $img = $(img);
  var title = $img.attr("alt") ? ": " + $(img).attr("alt") : "";
  var newImg = $img.clone().attr("style", null).attr("title", p4.t("Click to shrink") + title);
  var popup = $('<div class="doc-image-popup"><span class="close-icon"></span></div>');
  popup.prepend(newImg);
  var rect = $img.offset(),
      navHeight = $("#header").outerHeight(true),
      container = $("#content .container"),
      rpad = parseInt(container.css("padding-right"), 10);
  popup.css({
    "position": "absolute",
    "top": rect.top - navHeight + "px",
    "left": rpad + 18 + "px",
    "right": rpad - 18 + "px",
    "z-index": "55"
  });
  popup.appendTo(container);
}

p4.docHidePopup = function () {
  $('.doc-image-popup').remove();
}

p4.searcher = {
  cookieName: "p4doc_search",
  reAlpha:    /[^a-zA-Z]/g,
  reNumeric:  /[^0-9]/g,
  theWords:   "",
  tokens:     (typeof document_index !== 'undefined') ? Object.keys(document_index["i"]) : {},

  search: function(searchFor) {
    if (searchFor === undefined || searchFor.length < 2) return;

    var terms        = this.wordList(searchFor),
        fuzzy_match  = {},
        exact_match  = {},
        starts_match = {},
        ends_match   = {},
        x, y, z, i, j;

    if (terms.length < 1) return;

    // find all term matches
    for (x = 0; x < terms.length; x++) {
      var term = terms[x];

      // exact match
      exact_match[term] = (document_index["i"][term] !== undefined) ? true : false;

      // fuzzy match
      fuzzy_match[term] = $.grep(this.tokens, function (item, index) {
        return (item.indexOf(term) > -1);
      });

      // first term in a phrase is allowed to be and ends-with
      ends_match[term] = $.grep(this.tokens, function (item, index) {
        return (item.indexOf(term, item.length - term.length) > -1);
      });

      // the last term in a phrase is allowed to be a starts-with
      starts_match[term] = $.grep(this.tokens, function (item, index) {
        return (item.indexOf(term) === 0);
      });
    }

    // determine the set of documents containing all fuzzy matches
    var fuzzies       = Object.keys(fuzzy_match),
        matching_docs = [];

    for (x = 0; x < document_index["f"].length; x++) {
      var matches = 0;

      for (y = 0; y < fuzzies.length; y++) {
        var term = fuzzies[y];

        for (z = 0; z < fuzzy_match[term].length; z++) {
          var fuzzy_term = fuzzy_match[term][z];

          if (document_index["i"][fuzzy_term][x] !== undefined) {
            matches += 1;
            break;
          }
        }
      }

      if (matches === fuzzies.length) {
        matching_docs.push(x);
      }
    }

    // determine which documents might have phrase matches
    var phrase_match = {};
    if (terms.length > 1 && ends_match[terms[0]] !== undefined) {
      // find which documents might contain the phrase
      for (x = 0; x < matching_docs.length; x++) {
        var doc_id = matching_docs[x];

        // prime the match count
        phrase_match[doc_id] = 0;

        // prime the list of starting word offsets from the list of tokens
        // that end with the first term
        var start_offsets = [];
        for (y = 0; y < ends_match[terms[0]].length; y++) {
          var end_term    = ends_match[terms[0]][y],
              doc_offsets = document_index["i"][end_term][doc_id];

          if (doc_offsets === undefined) continue;
          start_offsets = start_offsets.concat(doc_offsets);
        }

        // evaluate all of the starting offsets
        for (y = 0; y < start_offsets.length; y++) {
          var start_offset = start_offsets[y],
              found = 1;

          // discover whether all the remaining terms appear in sequence
          for (z = 1; z < terms.length; z++) {
            var term = terms[z],
                term_docs = document_index["i"][term];

            // if this term is the last one, but there is no exact match
            // evaluate the starts with contenders
            if (z === terms.length - 1 && term_docs === undefined) {
              var starters      = Object.keys(starts_match[term]),
                  found_starter = 0;
              for (i = 0; i < starters.length; i++) {
                var starter = starts_match[term][i];
                term_docs = document_index["i"][starter];

                if (term_docs !== undefined && term_docs[doc_id] !== undefined) {
                  var term_offsets = term_docs[doc_id];

                  for (j = 0; j < term_offsets.length; j++) {
                    var term_offset = term_offsets[j];

                    if (term_offset === start_offset + z) {
                      found_starter += 1;
                      break;
                    }
                  }
                }
              }
              if (found_starter > 0) {
                found += 1;
              }
              break;
            }

            if (term_docs !== undefined && term_docs[doc_id] !== undefined) {
              var term_offsets = term_docs[doc_id];

              for (i = 0; i < term_offsets.length; i++) {
                var term_offset = term_offsets[i];

                if (term_offset === start_offset + z) {
                  found += 1;
                  break;
                }
              }
            }
          }

          // if all terms appear in sequence, this document contains the phrase
          if (found === terms.length) {
            phrase_match[doc_id] += 1;
          }
        }
      }
    }

    // compute scores
    var results = [],
        exact_results = [],
        fuzzy_results = [],
        phrase_results = [],
        pushed = [],
        total_docs = document_index["f"].length,
        exacts = Object.keys(exact_match);

    var stop_words = [
      "a", "about", "above", "after", "again", "against", "all", "am", "an",
      "and", "any", "are", "arent", "as", "at",
      "be", "because", "been", "before", "being", "below", "between", "both",
      "but", "by",
      "cant", "cannot", "could", "couldnt", "did", "didnt", "do", "does",
      "doesnt", "doing", "dont", "down", "during",
      "each",
      "few", "for", "from", "further",
      "had", "hadnt", "has", "hasnt", "have", "havent", "having", "he", "hed",
      "hell", "hes", "her", "here", "heres", "hers", "herself", "him",
      "himself", "his", "how", "hows",
      "i", "id", "ill", "im", "ive", "if", "in", "into", "is", "isnt", "it",
      "its", "its", "itself",
      "lets",
      "me", "more", "most", "mustnt", "my", "myself",
      "no", "nor", "not",
      "of", "off", "on", "once", "only", "or", "other", "ought", "our",
      "ours", "ourselves", "out", "over", "own",
      "same", "shant", "she", "shed", "shell", "shes", "should", "shouldnt",
      "so", "some", "such",
      "than", "that", "thats", "the", "their", "theirs", "them", "themselves",
      "then", "there", "theres", "these", "they", "theyd", "theyll", "theyre",
      "theyve", "this", "those", "through", "to", "too",
      "under", "until", "up",
      "very",
      "was", "wasnt", "we", "wed", "well", "were", "weve", "were", "werent",
      "what", "whats", "when", "whens", "where", "wheres", "which", "while",
      "who", "whos", "whom", "why", "whys", "with", "wont", "would", "wouldnt",
      "you", "youd", "youll", "youre", "youve", "your", "yours", "yourself",
      "yourselves",
    ];

    var matching_count = matching_docs.length;
    for (x = 0; x < matching_count; x++) {
      var doc_id        = matching_docs[x],
          exact_score   = 0,
          fuzzy_score   = 0,
          phrase_score  = 0;

      // process exact term matches
      for (y = 0; y < exacts.length; y++) {
        var term = exacts[y],
            info = document_index["i"][term];

        if (exact_match[term] && info[doc_id] !== undefined) {
          // var tf  = Math.log(1 + info[doc_id].length),
          //     df  = Math.log(
          //             1 +
          //             (total_docs / matching_count)
          //             * (Object.keys(info).length / matching_count)
          //           );

          // exact_score += tf * df;

          // score based on frequency in this doc
          var nominal_score = 0;
          if (info[doc_id] !== undefined) {
            nominal_score = Object.keys(info[doc_id]).length; // frequency in this doc
          }

          // scale scoring based on doc frequency
          nominal_score = nominal_score * (total_docs / Object.keys(info).length);

          // scale scoring based on stop words
          if ($.inArray(term, stop_words)) {
            nominal_score = nominal_score * 0.01;
          }
          
          exact_score += nominal_score;
        }
      }

      // process fuzzy term matches
      for (y = 0; y < fuzzies.length; y++) {
        var term = fuzzies[y];

        for (z = 0; z < fuzzy_match[term].length; z++) {
          var fuzzy_term = fuzzy_match[term][z],
              info       = document_index["i"][fuzzy_term];

          // skip if we have already scored the exact match for the term
          if (fuzzy_term === term)  continue;

          if (info[doc_id] !== undefined) {
            // var tf  = Math.log(1 + (fuzzy_term.length / term.length) * info[doc_id].length),
            //     df  = Math.log(
            //             1 +
            //             (total_docs / matching_count)
            //             * (Object.keys(info).length / matching_count)
            //           );

            // fuzzy_score += tf * df;

            var nominal_score = 0;
            if (info[doc_id] !== undefined) {
              nominal_score = Object.keys(info[doc_id]).length; // frequency in this doc
            }

            // scale scoring based on doc frequency
            nominal_score = nominal_score * (total_docs / Object.keys(info).length);

            // scale scoring based on stop words
            if ($.inArray(fuzzy_term, stop_words)) {
              nominal_score = nominal_score * 0.01;
            }

            // scale scoring based on fuzziness
            nominal_score = nominal_score * (term.length / fuzzy_term.length);

            fuzzy_score += nominal_score;
          }
        }
      }

      // process phrase match
      if (phrase_match[doc_id] !== undefined && phrase_match[doc_id] > 0) {
        // phrase_score = exact_score / phrase_match[doc_id];
        phrase_score = exact_score * phrase_match[doc_id];
      }

      var title = document_index["f"][doc_id]["title"];
          title = title.replace(/ \/\/.*$/, ""),
          doc_score = exact_score + fuzzy_score;

      doc_score = exact_score + phrase_score;
      if (this.wantSubstring()) {
        doc_score += fuzzy_score;
      }

      if (phrase_score > 0) {
        phrase_results.push({
          id: doc_id,
          url: document_index["f"][doc_id]['name'],
          path: document_index["f"][doc_id]['path'],
          title: title,
          score: doc_score,
          type: 'p',
        });
        pushed.push(doc_id);
      }

      if (exact_score > 0) {
        if ($.inArray(doc_id, pushed) < 0) {
          exact_results.push({
            id: doc_id,
            url: document_index["f"][doc_id]['name'],
            path: document_index["f"][doc_id]['path'],
            title: title,
            score: doc_score,
            type: 'e',
          });
          pushed.push(doc_id);
        }
      }

      if (fuzzy_score > 0) {
        if ($.inArray(doc_id, pushed) < 0) {
          fuzzy_results.push({
            id: doc_id,
            url: document_index["f"][doc_id]['name'],
            path: document_index["f"][doc_id]['path'],
            title: title,
            score: doc_score,
            type: 'f',
          });
          pushed.push(doc_id);
        }
      }
    }

    // sort results, by score, finally by title
    exact_results.sort(this.resultSort);
    fuzzy_results.sort(this.resultSort);
    phrase_results.sort(this.resultSort);

    results = results.concat(phrase_results, exact_results);
    if (this.wantSubstring()) {
      results = results.concat(fuzzy_results);
    }

    return results;
  },

  resultSort: function(a, b) {
    // compare scores
    // var diff = a.score - b.score;
    var diff = b.score - a.score;
    if (diff !== 0) return diff;

    // compare titles
    var aTitle = a.title.replace(this.reAlpha, ""),
        bTitle = b.title.replace(this.reAlpha, "");
    if (aTitle === bTitle) {
      // compare titles numerically
      aTitle = parseInt(a.title.replace(this.reNumeric, ""), 10);
      bTitle = parseInt(b.title.replace(this.reNumeric, ""), 10);
      return aTitle === bTitle ? 0 : aTitle > bTitle ? 1 : -1;
    }
    else {
      return aTitle > bTitle ? 1 : -1;
    }
  },


  // turn on highlighting of search terms
  highlight: function(searchText) {
    if (searchText === undefined) {
      searchText = $.cookie(this.cookieName);
      $("#search-text").val(searchText);
      var results = p4.searcher.search(searchText);
      p4.searcher.render(results);
    }
    if (searchText !== undefined) {
      var wordList = this.wordList(searchText);
      wordList = wordList.concat(wordList[0]);
      $("#content").highlight(wordList);
    }
  },

  // render search results
  render: function(results) {
    if (results === undefined) results = [];
    var html = '', i,
        cur_type = '',
        types = {
          "e": p4.t("Exact Matches"),
          "f": p4.t("Fuzzy Matches"),
          "p": p4.t("Phrase Matches"),
        };
    if (results.length > 0) {
      for (i = 0; i < results.length; i++) {
        var result = results[i];
        if (result["type"] !== cur_type) {
          cur_type = result["type"];
          html += '<li><span class="type">' + types[cur_type] + '</span></li>';
        }
        html += $.templates(
          '<li>'
          + '<a href="{{:url}}">'
          + '<div class="title">{{:title}}</div>'
          + '</a>'
          + '</li>'
        ).render(result);
      }
    }

    $("#search .results").html('').append($(html));
    $("#search .count .number").html(results.length);
  },

  // toggle highlighting of search terms
  toggleHighlight: function () {
    if ($("#search input.highlight").is(":checked")) {
      this.highlight(p4.searcher.theWords);
    }
    else {
      this.unhighlight();
    }
  },

  // turn off highlighting of search terms
  unhighlight: function () {
    $("#content").unhighlight();
  },

  // remove duplicate, and shorter than 3 chars, array entries
  unique: function(list) {
    var hash  = {},
        uniq  = [],
        i, l;
    for (i = 0, l = list.length; i < l; ++i) {
      if ((list[i] && list[i].length < 3) || hash.hasOwnProperty(list[i])) continue;
      uniq.push(list[i]);
      hash[list[i]] = 1;
    }
    return uniq;
  },

  wantHighlight: function() {
    return ($("#search input.highlight").is(":checked")) ? true : false;
  },

  wantSubstring: function() {
    var ret = ($("#search input.substring").is(":checked")) ? false : true;
    return ret;
  },

  // convert a string into a space-separated list of words
  wordList: function(searchString) {
    // new variant
    var string = this.theWords;
    if (searchString !== undefined) {
      var words = $.trim(searchString).split(" ");
      words = $.map(words, function (word) {
        return $.trim(
          word.replace(/['"]/g, "")
              .replace(/\.$/, "")
              .replace(/  +/g, " ")
        ).toLowerCase();
      });

      string = this.theWords = words.join(" ");
      $.cookie(this.cookieName, string);
    }

    return string.split(" ");
  },

  clear: function() {
    $.cookie(this.cookieName, "");
    this.unhighlight();
    this.render();
  }
};
