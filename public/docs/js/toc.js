var sidebar_toc = {
  n: [
    { u: "index.html", t: "1.  Perforce Swarm", n: [
      { u: "whatsnew.html", t: "What's new in 2016.1", n: [
        { u: "whatsnew.html#major-new-functionality", t: "Major new functionality" },
        { u: "whatsnew.html#minor-new-functionality", t: "Minor new functionality" },
        { u: "whatsnew.html#known-limitations", t: "Known limitations" },
      ] },
    ] },
    { u: "chapter.quickstart.html", t: "2.  Quickstart", n: [
      { u: "quickstart.start_code_review.html", t: "How do I start a code review?", n: [
        { u: "quickstart.start_code_review.html#once-a-review-has-started", t: "Once a review has started" },
      ] },
      { u: "quickstart.code_review_contribute.html", t: "How do I contribute comments or code changes to a code review?", n: [
        { u: "quickstart.code_review_contribute.html#quickstart.code_review_contribute.review_comment", t: "Commenting on a review" },
        { u: "quickstart.code_review_contribute.html#quickstart.code_review_contribute.file_comment", t: "Commenting on a specific line in a file" },
        { u: "quickstart.code_review_contribute.html#quickstart.code_review_contribute.edit_files_p4", t: "Editing files in a review" },
        { u: "quickstart.code_review_contribute.html#quickstart.code_review_contribute.edit_files_git", t: "Edit files in a review with Git Fusion" },
      ] },
      { u: "quickstart.local_copy.html", t: "How do I get a local copy of the review's code for evaluation?", n: [
        { u: "quickstart.local_copy.html#quickstart.local_copy.determine_review_id", t: "Determine the changelist containing the review's files" },
        { u: "quickstart.local_copy.html#quickstart.local_copy.using_p4", t: "Using P4" },
        { u: "quickstart.local_copy.html#quickstart.local_copy.using_p4v", t: "Using P4V" },
        { u: "quickstart.local_copy.html#quickstart.local_copy.using_git_fusion", t: "Using Git Fusion" },
      ] },
      { u: "quickstart.fix_non_mergeable.html", t: "How can I fix 'not mergeable' errors in a review?", n: [
        { u: "quickstart.fix_non_mergeable.html#quickstart.fix_non_mergeable.via_p4", t: "Resolve via P4" },
        { u: "quickstart.fix_non_mergeable.html#quickstart.fix_non_mergeable.via_p4v", t: "Resolve via P4V" },
      ] },
      { u: "quickstart.view_git_reviews.html", t: "How do I see a list of Git-created reviews?" },
      { u: "quickstart.integrate_test_suite.html", t: "How can I integrate my test suite to inform review acceptance or rejection?", n: [
        { u: "quickstart.integrate_test_suite.html#quickstart.integrate_test_suite.configure_jenkins", t: "Configuring Jenkins for Swarm integration" },
      ] },
      { u: "quickstart.review_deployment.html", t: "How can I automatically deploy code within a review?" },
      { u: "quickstart.manage_branches.html", t: "How do I manage project branches?", n: [
        { u: "quickstart.manage_branches.html#quickstart.manage_branches.adding-a-branch", t: "Adding a branch" },
        { u: "quickstart.manage_branches.html#quickstart.manage_branches.editing_a_branch", t: "Editing a branch" },
        { u: "quickstart.manage_branches.html#quickstart.manage_branches.removing_a_branch", t: "Removing a branch" },
      ] },
      { u: "quickstart.logging_level.html", t: "How do I change the logging level?" },
      { u: "quickstart.workers.html", t: "How do I check on the queue workers?" },
    ] },
    { u: "chapter.setup.html", t: "3.  Setting up", n: [
      { u: "setup.dependencies.html", t: "Runtime dependencies", n: [
        { u: "setup.dependencies.html#setup.dependencies.os", t: "Supported operating system platforms" },
        { u: "setup.dependencies.html#setup.dependencies.apache", t: "Apache web server" },
        { u: "setup.dependencies.html#setup.dependencies.php", t: "PHP" },
        { u: "setup.dependencies.html#setup.dependencies.perforce", t: "Perforce Server requirements" },
        { u: "setup.dependencies.html#setup.dependencies.triggers", t: "Trigger and worker dependencies" },
        { u: "setup.dependencies.html#setup.dependencies.browsers", t: "Supported web browsers" },
        { u: "setup.dependencies.html#setup.dependencies.optional", t: "Optional dependencies" },
        { u: "setup.dependencies.html#setup.dependencies.selinux", t: "Security-enhanced Linux (SELinux)" },
        { u: "setup.dependencies.html#setup.dependencies.install", t: "Choose installation approach" },
      ] },
      { u: "setup.packages.html", t: "Swarm packages", n: [
        { u: "setup.packages.html#setup.packages.install", t: "Installation" },
        { u: "setup.packages.html#setup.packages.updating", t: "Updating" },
        { u: "setup.packages.html#setup.packages.uninstall", t: "Uninstall" },
        { u: "setup.packages.html#setup.packages.configure", t: "Post-installation configuration" },
      ] },
      { u: "setup.ova.html", t: "OVA configuration", n: [
        { u: "setup.ova.html#setup.ova.vmware", t: "VMWare OVA import" },
        { u: "setup.ova.html#setup.ova.virtualbox", t: "Oracle VirtualBox import" },
      ] },
      { u: "setup.installation.html", t: "Initial manual installation" },
      { u: "setup.apache.html", t: "Apache configuration" },
      { u: "setup.php.html", t: "PHP configuration", n: [
        { u: "setup.php.html#setup.php.apc", t: "Alternative PHP Cache (APC) extension for PHP" },
        { u: "setup.php.html#setup.php.zendopcache", t: "Zend OPCache extension for PHP", n: [
          { u: "setup.php.html#setup.php.zendopcache.install", t: "Install Zend OPCache" },
          { u: "setup.php.html#setup.php.zendopcache.enable", t: "Enable Zend OPCache" },
        ] },
        { u: "setup.php.html#setup.php.imagick", t: "ImageMagick (imagick) extension for PHP" },
      ] },
      { u: "setup.swarm.html", t: "Swarm configuration", n: [
        { u: "setup.swarm.html#setup.swarm.config_file", t: "Swarm configuration file" },
        { u: "setup.swarm.html#setup.swarm.optional", t: "Optional additional Swarm configuration", n: [
          { u: "setup.swarm.html#setup.swarm.optional.hostname", t: "Swarm hostname" },
        ] },
      ] },
      { u: "setup.trigger_token.html", t: "Establish trigger token" },
      { u: "setup.perforce.html", t: "Perforce configuration for Swarm", n: [
        { u: "setup.perforce.html#setup.perforce.triggers", t: "Using triggers to push events to Swarm", n: [
          { u: "setup.perforce.html#setup.perforce.triggers.windows", t: "Setup Swarm triggers with a Windows-hosted Helix Versioning Engine" },
          { u: "setup.perforce.html#setup.perforce.triggers.linux", t: "Setup Swarm triggers with a Linux-hosted Helix Versioning Engine" },
        ] },
        { u: "setup.perforce.html#setup.perforce.dm_keys", t: "Hiding Swarm storage from regular users" },
      ] },
      { u: "setup.worker.html", t: "Set up a recurring task to spawn workers", n: [
        { u: "setup.worker.html#setup.worker.verification", t: "curl/wget verification" },
      ] },
      { u: "setup.upgrade.html", t: "Upgrading Swarm", n: [
        { u: "setup.upgrade.html#setup.upgrade.2015.4", t: "Upgrade Swarm 2015.4 to 2016.1" },
        { u: "setup.upgrade.html#setup.upgrade.2015.3", t: "Upgrade Swarm 2015.3 to 2015.4" },
        { u: "setup.upgrade.html#setup.upgrade.2015.2", t: "Upgrade Swarm 2015.2 to 2015.3" },
        { u: "setup.upgrade.html#setup.upgrade.2015.1", t: "Upgrade Swarm 2015.1 to 2015.2" },
        { u: "setup.upgrade.html#setup.upgrade.2014.4", t: "Upgrade Swarm 2014.4 to 2015.1" },
      ] },
    ] },
    { u: "chapter.basics.html", t: "4.  Basics", n: [
      { u: "basics.activity_streams.html", t: "Activity streams" },
      { u: "basics.files.html", t: "Files", n: [
        { u: "basics.files.html#basics.files.zip", t: "Downloading files as ZIP archive" },
        { u: "basics.files.html#basics.files.browse_deleted", t: "Browsing deleted files and folders" },
        { u: "basics.files.html#basics.files.display", t: "File display", n: [
          { u: "basics.files.html#basics.files.text-files", t: "Text Files" },
          { u: "basics.files.html#basics.files.images", t: "Images" },
          { u: "basics.files.html#basics.files.3d", t: "3D models" },
          { u: "basics.files.html#basics.files.other-file-types", t: "Other file types" },
        ] },
      ] },
      { u: "basics.commits.html", t: "Commits", n: [
        { u: "basics.commits.html#basics.commits.range", t: "Range filter" },
        { u: "basics.commits.html#basics.commits.file", t: "File Commits" },
        { u: "basics.commits.html#basics.commits.remote-depot-commits", t: "Remote depot commits" },
      ] },
      { u: "basics.jobs.html", t: "Jobs", n: [
        { u: "basics.jobs.html#basics.jobs.adjust_columns", t: "Adjusting Jobs columns" },
        { u: "basics.jobs.html#basics.jobs.display", t: "Job display" },
        { u: "basics.jobs.html#basics.jobs.add", t: "Adding jobs" },
        { u: "basics.jobs.html#basics.jobs.unlink", t: "Unlinking jobs" },
      ] },
      { u: "basics.changelists.html", t: "Changelists", n: [
        { u: "basics.changelists.html#basics.changelists.display", t: "Changelist Display" },
      ] },
      { u: "basics.diffs.html", t: "Diffs", n: [
        { u: "basics.diffs.html#basics.diffs.view", t: "Viewing a diff" },
      ] },
      { u: "basics.comments.html", t: "Comments", n: [
        { u: "basics.comments.html#basics.comments.tasks", t: "Tasks" },
        { u: "basics.comments.html#basics.comments.features", t: "Comment features", n: [
          { u: "basics.comments.html#basics.comments.features.emoji", t: "Emoji" },
          { u: "basics.comments.html#basics.comments.features.links", t: "Links in comments" },
          { u: "basics.comments.html#basics.comments.features.like", t: "Liking comments" },
          { u: "basics.comments.html#basics.comments.features.attachments", t: "Comment attachments" },
          { u: "basics.comments.html#basics.comments.features.context", t: "Comment context" },
          { u: "basics.comments.html#basics.comments.features.delayed_notifications", t: "Delayed notifications" },
        ] },
        { u: "basics.comments.html#basics.comments.changelist", t: "Commenting on a changelist or review" },
        { u: "basics.comments.html#basics.comments.line", t: "Commenting on a specific line in a file" },
        { u: "basics.comments.html#basics.comments.file", t: "Commenting on a file in a changelist or code review" },
        { u: "basics.comments.html#basics.comments.editing", t: "Editing comments" },
        { u: "basics.comments.html#basics.comments.archiving", t: "Archiving comments" },
        { u: "basics.comments.html#basics.comments.restore", t: "Restore comments" },
      ] },
      { u: "basics.users.html", t: "Users", n: [
        { u: "basics.users.html#basics.users.view", t: "Viewing users" },
      ] },
      { u: "basics.groups.html", t: "Groups", n: [
        { u: "basics.groups.html#basics.groups.list", t: "Listing groups" },
        { u: "basics.groups.html#basics.groups.display", t: "Viewing a group" },
      ] },
      { u: "basics.projects.html", t: "Projects", n: [
        { u: "basics.projects.html#basics.projects.view", t: "Viewing a project", n: [
          { u: "basics.projects.html#basics.projects.reviews", t: "Reviews" },
          { u: "basics.projects.html#basics.projects.files", t: "Files" },
          { u: "basics.projects.html#basics.projects.commits", t: "Commits" },
          { u: "basics.projects.html#basics.projects.jobs", t: "Jobs" },
        ] },
      ] },
      { u: "basics.notifications.html", t: "Notifications", n: [
        { u: "basics.notifications.html#basics.notifications.mentions", t: "@mention notifications" },
        { u: "basics.notifications.html#basics.notifications.commit", t: "Committed change notifications (*)", n: [
          { u: "basics.notifications.html#basics.notifications.commit.via_p4", t: "Update Reviews with p4" },
          { u: "basics.notifications.html#basics.notifications.commit.via_p4v", t: "Update Reviews with P4V" },
        ] },
        { u: "basics.notifications.html#basics.notifications.review", t: "Review start notifications (**)" },
        { u: "basics.notifications.html#basics.notifications.moderators", t: "Moderator notifications (***)" },
        { u: "basics.notifications.html#basics.notifications.groups", t: "Group member notifications (****)" },
      ] },
      { u: "basics.login_logout.html", t: "Log in / Log out", n: [
        { u: "basics.login_logout.html#basics.login_logout.login", t: "To log in" },
        { u: "basics.login_logout.html#basics.login_logout.logout", t: "To log out" },
        { u: "basics.login_logout.html#basics.login_logout.require_login", t: "require_login" },
      ] },
      { u: "basics.notable.html", t: "Notable minor features", n: [
        { u: "basics.notable.html#basics.notable.quick_urls", t: "Quick URLs" },
        { u: "basics.notable.html#basics.notable.mentions", t: "@mentions" },
        { u: "basics.notable.html#basics.notable.search", t: "Search" },
        { u: "basics.notable.html#basics.notable.jira", t: "JIRA integration" },
        { u: "basics.notable.html#basics.notable.avatars", t: "Avatars" },
        { u: "basics.notable.html#basics.notable.follow", t: "Following" },
        { u: "basics.notable.html#basics.notable.time", t: "Time" },
        { u: "basics.notable.html#basics.notable.shortcuts", t: "Keyboard shortcuts" },
        { u: "basics.notable.html#basics.notable.about", t: "About Swarm" },
        { u: "basics.notable.html#basics.notable.custom_error_pages", t: "Custom error pages" },
        { u: "basics.notable.html#basics.notable.short_links", t: "Short links" },
        { u: "basics.notable.html#basics.notable.mobile_browser_compatibility", t: "Mobile browser compatibility" },
      ] },
    ] },
    { u: "chapter.groups.html", t: "5.  Groups", n: [
      { u: "groups.add.html", t: "Add a group" },
      { u: "groups.edit.html", t: "Edit a group" },
      { u: "groups.remove.html", t: "Remove a group" },
    ] },
    { u: "chapter.projects.html", t: "6.  Projects", n: [
      { u: "projects.add.html", t: "Add a project" },
      { u: "projects.edit.html", t: "Edit a project" },
      { u: "projects.membership.html", t: "Membership", n: [
        { u: "projects.membership.html#projects.membership.add", t: "Add a member" },
        { u: "projects.membership.html#projects.membership.remove", t: "Remove a member" },
        { u: "projects.membership.html#projects.membership.owners", t: "Owners" },
        { u: "projects.membership.html#projects.membership.moderators", t: "Moderators" },
      ] },
      { u: "projects.remove.html", t: "Remove a project" },
    ] },
    { u: "chapter.code_reviews.html", t: "7.  Code reviews", n: [
      { u: "code_reviews.model.html", t: "Models", n: [
        { u: "code_reviews.model.html#code_reviews.model.precommit", t: "Pre-commit model" },
        { u: "code_reviews.model.html#code_reviews.model.postcommit", t: "Post-commit model" },
        { u: "code_reviews.model.html#code_reviews.model.git_fusion", t: "Git Fusion model" },
        { u: "code_reviews.model.html#code_reviews.internal_representation", t: "Internal representation" },
      ] },
      { u: "code_reviews.queues.html", t: "Review queues", n: [
        { u: "code_reviews.queues.html#code_reviews.filtering", t: "Filtering open reviews" },
        { u: "code_reviews.queues.html#code_reviews.queues.filtering_closed", t: "Filtering closed reviews" },
      ] },
      { u: "code_reviews.display.html", t: "Review display" },
      { u: "code_reviews.activities.html", t: "Activities", n: [
        { u: "code_reviews.activities.html#code_reviews.activities.start", t: "Start a review" },
        { u: "code_reviews.activities.html#code_reviews.activities.update", t: "Update a review" },
        { u: "code_reviews.activities.html#code_reviews.activities.fetch", t: "Fetch a review's files", n: [
          { u: "code_reviews.activities.html#code_reviews.activities.fetch.via_p4", t: "Using P4" },
          { u: "code_reviews.activities.html#code_reviews.activities.fetch.via_p4v", t: "Using P4V" },
          { u: "code_reviews.activities.html#code_reviews.activities.fetch.via_gf", t: "Using Git Fusion" },
        ] },
        { u: "code_reviews.activities.html#code_reviews.activities.edit_reviewers", t: "Edit reviewers" },
      ] },
      { u: "code_reviews.responsibility.html", t: "Responsibility", n: [
        { u: "code_reviews.responsibility.html#code_reviews.responsibility.moderators", t: "Moderators" },
        { u: "code_reviews.responsibility.html#code_reviews.responsibility.required", t: "Required reviewers" },
        { u: "code_reviews.responsibility.html#code_reviews.responsibility.add", t: "Add yourself as a reviewer" },
        { u: "code_reviews.responsibility.html#code_reviews.responsibility.remove", t: "Remove yourself as a reviewer" },
      ] },
      { u: "code_reviews.workflow.html", t: "Review workflow", n: [
        { u: "code_reviews.workflow.html#code_reviews.workflow.other_reviewer", t: "Another developer reviews your code" },
        { u: "code_reviews.workflow.html#code_reviews.workflow.you_review", t: "You review another developer's code" },
      ] },
      { u: "code_reviews.states.html", t: "States", n: [
        { u: "code_reviews.states.html#code_reviews.states.self_approve", t: "Self-approval by review authors" },
        { u: "code_reviews.states.html#code_reviews.states.moderation", t: "State change restrictions with moderation" },
        { u: "code_reviews.states.html#code_reviews.states.required_reviewers", t: "Required reviewers" },
        { u: "code_reviews.states.html#code_reviews.states.state_actions", t: "State actions" },
      ] },
    ] },
    { u: "chapter.integrations.html", t: "8.  Integrations", n: [
      { u: "integrations.jira.html", t: "JIRA", n: [
        { u: "integrations.jira.html#integrations.jira.enable", t: "Enabling the JIRA module" },
      ] },
      { u: "integrations.libreoffice.html", t: "LibreOffice", n: [
        { u: "integrations.libreoffice.html#integrations.libreoffice.limitations", t: "Limitations" },
        { u: "integrations.libreoffice.html#integrations.libreoffice.installation", t: "Installation" },
      ] },
    ] },
    { u: "chapter.administration.html", t: "9.  Administration", n: [
      { u: "admin.archives.html", t: "Archives configuration" },
      { u: "admin.avatars.html", t: "Avatars", n: [
        { u: "admin.avatars.html#admin.avatars.disable", t: "Disable avatar lookups" },
      ] },
      { u: "admin.backup.html", t: "Backups" },
      { u: "admin.changelist_files.html", t: "Changelist files limit" },
      { u: "admin.client_integration.html", t: "Client integration" },
      { u: "admin.comment_attachments.html", t: "Comment attachments" },
      { u: "admin.commit_credit.html", t: "Commit credit" },
      { u: "admin.commit_edge.html", t: "Commit-edge deployment" },
      { u: "admin.commit_timeout.html", t: "Commit timeout" },
      { u: "admin.configuration.html", t: "Configuration overview" },
      { u: "admin.email.html", t: "Email configuration", n: [
        { u: "admin.email.html#admin.email.sender", t: "Sender" },
        { u: "admin.email.html#admin.email.transport", t: "Transport" },
        { u: "admin.email.html#admin.email.recipients", t: "Recipients" },
        { u: "admin.email.html#admin.email.use_bcc", t: "Use BCC" },
        { u: "admin.email.html#admin.email.use_replyto", t: "Use Reply-To" },
        { u: "admin.email.html#admin.email.path", t: "Save all messages to disk" },
      ] },
      { u: "admin.emoji.html", t: "Emoji" },
      { u: "admin.environment.html", t: "Environment", n: [
        { u: "admin.environment.html#admin.environment.mode", t: "Mode" },
        { u: "admin.environment.html#admin.environment.hostname", t: "Hostname" },
      ] },
      { u: "admin.exclude_users.html", t: "Excluding Users from Activity Streams" },
      { u: "admin.ignored_users.html", t: "Ignored users for reviews" },
      { u: "admin.license.html", t: "License" },
      { u: "admin.locale.html", t: "Locale" },
      { u: "admin.logging.html", t: "Logging", n: [
        { u: "admin.logging.html#admin.logging.web", t: "Web server logging" },
        { u: "admin.logging.html#admin.logging.perforce", t: "Helix Versioning Engine logs" },
        { u: "admin.logging.html#admin.logging.swarm", t: "Swarm logs" },
        { u: "admin.logging.html#admin.logging.trigger_token_errors", t: "Trigger Token Errors" },
        { u: "admin.logging.html#admin.logging.performance", t: "Performance logging" },
      ] },
      { u: "admin.mainline.html", t: "Mainline branch identification" },
      { u: "admin.notifications.html", t: "Notifications" },
      { u: "admin.ova.html", t: "OVA Management", n: [
        { u: "admin.ova.html#admin.ova.conflicts", t: "Dependency Conflicts" },
      ] },
      { u: "admin.p4trust.html", t: "P4TRUST" },
      { u: "admin.projects.html", t: "Projects", n: [
        { u: "admin.projects.html#admin.projects.limit_project_add_admin", t: "Limit adding projects to administrators" },
        { u: "admin.projects.html#admin.projects.limit_project_add_group", t: "Limit adding projects to members of specific groups" },
      ] },
      { u: "admin.review_keyword.html", t: "Review keyword" },
      { u: "admin.reviews.html", t: "Reviews", n: [
        { u: "admin.reviews.html#admin.reviews.enforcement", t: "Review enforcement", n: [
          { u: "admin.reviews.html#admin.reviews.group_exclusion", t: "Group exclusion" },
        ] },
        { u: "admin.reviews.html#admin.reviews.disable_self_approve", t: "Disable self-approval of reviews by authors" },
      ] },
      { u: "admin.search.html", t: "Search" },
      { u: "admin.security.html", t: "Security", n: [
        { u: "admin.security.html#admin.security.require_login", t: "Require login" },
        { u: "admin.security.html#admin.security.prevent_login", t: "Prevent login" },
        { u: "admin.security.html#admin.security.sessions", t: "Sessions" },
        { u: "admin.security.html#admin.security.x_frame_options", t: "X-Frame-Options header" },
        { u: "admin.security.html#admin.security.disable_commit", t: "Disable commit" },
        { u: "admin.security.html#admin.security.restricted_changes", t: "Restricted Changes" },
        { u: "admin.security.html#admin.security.limit_project_add_admin", t: "Limit adding projects to administrators" },
        { u: "admin.security.html#admin.security.limit_project_add_group", t: "Limit adding projects to members of specific groups" },
        { u: "admin.security.html#admin.security.ip_protections", t: "IP address-based protections emulation", n: [
          { u: "admin.security.html#admin.security.limitations", t: "Known limitations" },
        ] },
        { u: "admin.security.html#admin.security.disable_system_info", t: "Disable system info" },
        { u: "admin.security.html#admin.security.http_client_options", t: "HTTP client options" },
        { u: "admin.security.html#admin.security.strict_https", t: "Strict HTTPS" },
        { u: "admin.security.html#admin.security.apache", t: "Apache security" },
        { u: "admin.security.html#admin.security.php", t: "PHP security" },
      ] },
      { u: "admin.short_links.html", t: "Short links" },
      { u: "admin.system_information.html", t: "System Information", n: [
        { u: "admin.system_information.html#admin.system_information.log", t: "Log" },
        { u: "admin.system_information.html#admin.system_information.php_info", t: "PHP Info" },
      ] },
      { u: "admin.trigger.html", t: "Trigger options", n: [
        { u: "admin.trigger.html#admin.trigger.options", t: "Command-line options", n: [
          { u: "admin.trigger.html#admin.trigger.options.synopsis", t: "Synopsis" },
          { u: "admin.trigger.html#admin.trigger.options.informational", t: "Informational options" },
          { u: "admin.trigger.html#admin.trigger.options.operational", t: "Operational options" },
        ] },
        { u: "admin.trigger.html#admin.trigger.configuration_items", t: "Configuration items" },
      ] },
      { u: "admin.unapprove_modified.html", t: "Unapprove modified reviews" },
      { u: "admin.uninstall.html", t: "Uninstall Swarm", n: [
        { u: "admin.uninstall.html#admin.uninstall.background", t: "Background" },
        { u: "admin.uninstall.html#admin.uninstall.steps", t: "Uninstall steps" },
      ] },
      { u: "admin.workers.html", t: "Workers", n: [
        { u: "admin.workers.html#admin.workers.status", t: "Worker status" },
        { u: "admin.workers.html#admin.workers.configuration", t: "Worker configuration" },
        { u: "admin.workers.html#admin.workers.manual_start", t: "Manually start workers" },
        { u: "admin.workers.html#admin.workers.restart", t: "Manually restart workers" },
      ] },
    ] },
    { u: "chapter.extending.html", t: "10.  Extending Swarm", n: [
      { u: "extending.resources.html", t: "Resources", n: [
        { u: "extending.resources.html#extending.resources.jquery", t: "jQuery" },
        { u: "extending.resources.html#extending.resources.javascript", t: "JavaScript Resources" },
        { u: "extending.resources.html#extending.resources.php", t: "PHP Resources" },
        { u: "extending.resources.html#extending.resources.zf2", t: "Zend Framework 2 Resources" },
      ] },
      { u: "extending.development.html", t: "Development mode", n: [
        { u: "extending.development.html#extending.development.enable", t: "To enable development mode" },
        { u: "extending.development.html#extending.development.disable", t: "To disable development mode" },
      ] },
      { u: "extending.modules.html", t: "Modules", n: [
        { u: "extending.modules.html#extending.modules.influence", t: "Influence activity events, emails, etc." },
        { u: "extending.modules.html#extending.modules.templates", t: "Templates" },
        { u: "extending.modules.html#extending.modules.view_helpers", t: "View helpers", n: [
          { u: "extending.modules.html#extending.modules.view_helpers.options", t: "Set options on existing helpers" },
          { u: "extending.modules.html#extending.modules.view_helpers.register", t: "Register new helpers" },
        ] },
      ] },
      { u: "extending.example_linkify.html", t: "Example linkify module" },
      { u: "extending.example_email.html", t: "Example email module" },
      { u: "extending.clients.html", t: "CSS &amp; JavaScript", n: [
        { u: "extending.clients.html#extending.clients.javascript", t: "Sample JavaScript extension" },
        { u: "extending.clients.html#extending.clients.css", t: "Sample CSS customization" },
      ] },
    ] },
    { u: "api.html", t: "11.  Swarm API", n: [
      { u: "api.endpoints.html", t: "API Endpoints", n: [
        { u: "api.endpoints.html#api.endpoints.Reviews", t: "Reviews : Swarm Reviews", n: [
          { u: "api.endpoints.html#api.endpoints.Reviews.getReview", t: "GET /api/v2/reviews/{id}", n: [
            { u: "api.endpoints.html#api.endpoints.Reviews.getReview.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Reviews.getReview.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Reviews.getReview.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Reviews.getReview.errorExamples", t: "Example 404 Response:" },
            { u: "api.endpoints.html#api.endpoints.Reviews.getReview.usageExamples", t: "Fetching a review" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Reviews.createReview", t: "POST /api/v2/reviews/", n: [
            { u: "api.endpoints.html#api.endpoints.Reviews.createReview.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Reviews.createReview.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Reviews.createReview.successExamples", t: "Successful Response contains Review Entity:" },
            { u: "api.endpoints.html#api.endpoints.Reviews.createReview.usageExamples", t: "Starting a review" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Reviews.addChange", t: "POST /api/v2/reviews/{id}/changes/", n: [
            { u: "api.endpoints.html#api.endpoints.Reviews.addChange.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Reviews.addChange.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Reviews.addChange.successExamples", t: "Successful Response contains Review Entity:" },
            { u: "api.endpoints.html#api.endpoints.Reviews.addChange.usageExamples", t: "Adding a change to a review" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Reviews.state", t: "PATCH /api/v2/reviews/{id}/state/", n: [
            { u: "api.endpoints.html#api.endpoints.Reviews.state.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Reviews.state.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Reviews.state.successExamples", t: "Examples of successful responses" },
            { u: "api.endpoints.html#api.endpoints.Reviews.state.usageExamples", t: "Committing a review" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Reviews.getReviews", t: "GET /api/v2/reviews/", n: [
            { u: "api.endpoints.html#api.endpoints.Reviews.getReviews.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Reviews.getReviews.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Reviews.getReviews.successExamples", t: "Examples of successful responses" },
            { u: "api.endpoints.html#api.endpoints.Reviews.getReviews.usageExamples", t: "Examples of usage" },
          ] },
        ] },
        { u: "api.endpoints.html#api.endpoints.Groups", t: "Groups : Swarm Groups", n: [
          { u: "api.endpoints.html#api.endpoints.Groups.listGroups", t: "GET /api/v2/groups/", n: [
            { u: "api.endpoints.html#api.endpoints.Groups.listGroups.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Groups.listGroups.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Groups.listGroups.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Groups.listGroups.usageExamples", t: "Examples of usage" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Groups.getGroup", t: "GET /api/v2/groups/{id}", n: [
            { u: "api.endpoints.html#api.endpoints.Groups.getGroup.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Groups.getGroup.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Groups.getGroup.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Groups.getGroup.usageExamples", t: "Examples of usage" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Groups.createGroup", t: "POST /api/v2/groups/", n: [
            { u: "api.endpoints.html#api.endpoints.Groups.createGroup.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Groups.createGroup.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Groups.createGroup.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Groups.createGroup.usageExamples", t: "Creating a group" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Groups.patchGroup", t: "PATCH /api/v2/groups/{id}", n: [
            { u: "api.endpoints.html#api.endpoints.Groups.patchGroup.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Groups.patchGroup.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Groups.patchGroup.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Groups.patchGroup.usageExamples", t: "Editing a group" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Groups.deleteGroup", t: "DELETE /api/v2/groups/{id}", n: [
            { u: "api.endpoints.html#api.endpoints.Groups.deleteGroup.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Groups.deleteGroup.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Groups.deleteGroup.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Groups.deleteGroup.usageExamples", t: "Deleting a group" },
          ] },
        ] },
        { u: "api.endpoints.html#api.endpoints.Index", t: "Index : Basic API controller providing a simple version action", n: [
          { u: "api.endpoints.html#api.endpoints.Index.version", t: "GET /api/v2/version", n: [
            { u: "api.endpoints.html#api.endpoints.Index.version.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Index.version.successExamples", t: "Successful Response:" },
          ] },
        ] },
        { u: "api.endpoints.html#api.endpoints.Activity", t: "Activity : Swarm Activity List", n: [
          { u: "api.endpoints.html#api.endpoints.Activity.addActivity", t: "POST /api/v2/activity", n: [
            { u: "api.endpoints.html#api.endpoints.Activity.addActivity.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Activity.addActivity.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Activity.addActivity.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Activity.addActivity.usageExamples", t: "Examples of usage" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Activity.listActivity", t: "GET /api/v2/activity", n: [
            { u: "api.endpoints.html#api.endpoints.Activity.listActivity.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Activity.listActivity.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Activity.listActivity.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Activity.listActivity.usageExamples", t: "Examples of usage" },
          ] },
        ] },
        { u: "api.endpoints.html#api.endpoints.Projects", t: "Projects : Swarm Projects", n: [
          { u: "api.endpoints.html#api.endpoints.Projects.listProjects", t: "GET /api/v2/projects/", n: [
            { u: "api.endpoints.html#api.endpoints.Projects.listProjects.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Projects.listProjects.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Projects.listProjects.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Projects.listProjects.usageExamples", t: "Listing projects" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Projects.getProject", t: "GET /api/v2/projects/{id}", n: [
            { u: "api.endpoints.html#api.endpoints.Projects.getProject.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Projects.getProject.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Projects.getProject.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Projects.getProject.usageExamples", t: "Fetching a project" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Projects.createProject", t: "POST /api/v2/projects/", n: [
            { u: "api.endpoints.html#api.endpoints.Projects.createProject.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Projects.createProject.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Projects.createProject.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Projects.createProject.usageExamples", t: "Creating a new project" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Projects.patchProject", t: "PATCH /api/v2/projects/{id}", n: [
            { u: "api.endpoints.html#api.endpoints.Projects.patchProject.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Projects.patchProject.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Projects.patchProject.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Projects.patchProject.usageExamples", t: "Editing a project" },
          ] },
          { u: "api.endpoints.html#api.endpoints.Projects.deleteProject", t: "DELETE /api/v2/projects/{id}", n: [
            { u: "api.endpoints.html#api.endpoints.Projects.deleteProject.notes", t: "Description" },
            { u: "api.endpoints.html#api.endpoints.Projects.deleteProject.parameters", t: "Parameters" },
            { u: "api.endpoints.html#api.endpoints.Projects.deleteProject.successExamples", t: "Successful Response:" },
            { u: "api.endpoints.html#api.endpoints.Projects.deleteProject.usageExamples", t: "Deleting a project" },
          ] },
        ] },
      ] },
    ] },
    { u: "contact.html", t: "A.  Contact Perforce" },
    { u: "glossary.html", t: "B.  Glossary" },
  ]
}