#!/usr/bin/env bash
#
# Perforce Swarm Trigger Script
#
# @copyright   2013-2016 Perforce Software. All rights reserved.
# @version     2016.1/1400259
#
# This script is meant to be called from a Perforce trigger.
# It should be placed on the Perforce Server machine.
# See usage information below for more details.

# ---------------------------------------------------------------------------- #
# This script requires certain variables defined to operate correctly.
#
# You can utilize one of these default configuration files to define the
# variables needed (SWARM_HOST and SWARM_TOKEN at least):
#   /etc/perforce/swarm-trigger.conf
#   /opt/perforce/etc/swarm-trigger.conf
#   swarm-trigger.conf (in the same directory as this script)
#
# You can also specify '-c <config file>' on the command line to specify a file
# to source; anything defined in this file will override variables defined in
# the default config files above.
#
# Alternatively, you can edit this script directly if you prefer, but note that
# any values defined in the default config files (or one specified via -c) will
# override what is set here. In addition, if you replace or update this script
# to a new version, please ensure you preserve your changes.

# SWARM_HOST (required)
# Hostname of your Swarm instance, with leading "http://" or "https://".
SWARM_HOST="http://my-swarm-host"

# SWARM_TOKEN (required)
# The token used when talking to Swarm to offer some security. To obtain the
# value, log in to Swarm as a super user and select 'About Swarm' to see the
# token value.
SWARM_TOKEN="MY-UUID-STYLE-TOKEN"

# ADMIN_USER (optional)
# For enforcing reviewed changes, optionally specify the Perforce user with
# admin privileges (to read keys); if not set, will use whatever Perforce user
# is set in environment.
ADMIN_USER=

# ADMIN_TICKET_FILE (optional)
# For enforcing reviewed changes, optionally specify the location of the
# p4tickets file if different from the default ($HOME/.p4tickets).
# Ensure this user is a member of a group with an 'unlimited' or very long
# timeout; then, manually login as this user from the Perforce server machine to
# set the ticket.
ADMIN_TICKET_FILE=

# LOGGER (optional, default set here)
# For logging errors, we use the 'logger' command. If it is not available in the
# PATH of the environment in which Perforce trigger scripts run, specify the
# full path here.
LOGGER="logger"

# P4, SED & GREP: (optional, defaults set here)
# For 'enforce' and 'strict' types, we use the following utilities. If they are
# not availabe in the PATH of the environment in which Perforce trigger scripts
# run, specify the full path of the utility here.
P4="p4"
SED="sed"
GREP="grep"

# DO NOT EDIT PAST THIS LINE ------------------------------------------------- #

# This script and its directory
ME="${0##*/}"
MYDIR="$(cd "$(dirname "$0")" && pwd)"

usage()
{
    cat << ! >&2
Usage: $ME -t <type> -v <value> \\
         [-p <p4port>] [-r] [-g <group-to-exclude>] [-c <config file>]
       $ME -o
    -t: specify the Swarm trigger type (e.g. job, shelve, commit)
    -v: specify the ID value
    -p: specify optional (recommended) P4PORT, only intended for
        '-t enforce' or '-t strict'
    -r: when using '-t strict' or '-t enforce', only apply this check
        to changes that are in review.
    -g: specify optional group to exclude for '-t enforce' or
        '-t strict'; members of this group, or subgroups thereof will
        not be subject to these triggers
    -c: specify optional config file to source variables
    -o: convenience flag to output the trigger lines

This script is meant to be called from a Perforce trigger. It should be placed
on the Perforce Server machine and the following entries should be added using
'p4 triggers' (use the -o flag to this script to only output these lines):

!
    display_trigger_entries
    cat << ! >&2
Notes:

* The use of '%quote%' is not supported on 2010.2 servers (they are harmless
  though); if you're using this version, ensure you don't have any spaces in the
  pathname to this script.

* This script requires configuration to be set in an external configuration file
  or directly in the script itself, such as the Swarm host and token.
  By default, this script will source any of these config file:
    /etc/perforce/swarm-trigger.conf
    /opt/perforce/etc/swarm-trigger.conf
    swarm-trigger.conf (in the same directory as this script)
  Lastly, if -c <config file> is passed, that file will be sourced too.

* For 'enforce' triggers (enforce that a change to be submitted is tied to an
  approved review), or 'strict' triggers (verify that the content of a change to
  be submitted matches the content of its associated approved review), uncomment
  the appropriate lines and replace DEPOT_PATH as appropriate. For additional
  paths to check, increment the trigger name suffix so that each trigger name is
  named uniquely.

* For 'enforce' or 'strict' triggers, you can optionally specify a group whose
  members will not be subject to these triggers.

* For 'enforce' or 'strict' triggers, if your Perforce Server is SSL-enabled,
  add the "ssl:" protocol prefix to "%serverport%".

!
    exit 99
}

display_trigger_entries()
{
    # Define the trigger entries suitable for this script; replace depot paths as appropriate
    cat << EOF-TRIGGER
	swarm.job        form-commit   job    "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t job          -v %formname%"
	swarm.user       form-commit   user   "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t user         -v %formname%"
	swarm.userdel    form-delete   user   "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t userdel      -v %formname%"
	swarm.group      form-commit   group  "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t group        -v %formname%"
	swarm.groupdel   form-delete   group  "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t groupdel     -v %formname%"
	swarm.changesave form-save     change "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t changesave   -v %formname%"
	swarm.shelve     shelve-commit //...  "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t shelve       -v %change%"
	swarm.commit     change-commit //...  "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t commit       -v %change%"
	#swarm.enforce.1 change-submit  //DEPOT_PATH1/... "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t enforce -v %change% -p %serverport%"
	#swarm.enforce.2 change-submit  //DEPOT_PATH2/... "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t enforce -v %change% -p %serverport%"
	#swarm.strict.1  change-content //DEPOT_PATH1/... "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t strict -v %change% -p %serverport%"
	#swarm.strict.2  change-content //DEPOT_PATH2/... "%quote%$MYDIR/$ME%quote%$CONFIG_FILE_USE -t strict -v %change% -p %serverport%"
EOF-TRIGGER
}

# Source any external configuration
source_config()
{
    # Source a default set of configuration files (if present).
    # Variables contained in these files will override any variables defined
    # at the top of this file.
    for file in \
        "/etc/perforce/swarm-trigger.conf" \
        "/opt/perforce/etc/swarm-trigger.conf" \
        "$MYDIR/swarm-trigger.conf" \
        "$CONFIG_FILE"
    do
        if [[ -s "$file" ]]
        then
            source "$file"
        fi
    done
}

# Show the usage if no arguments passed
[[ -z "$1" ]] && usage

# Default flag(s)
CHECK_REVIEW_CHANGES_ONLY=false
CONFIG_FILE=
CONFIG_FILE_USE=
DISPLAY_FLAG_SET=false

# Trap call to self to post to Swarm
BACKGROUND_FLAG="background-call"
if [[ "$1" == "$BACKGROUND_FLAG" ]]
then
    TYPE="$2"
    VALUE="$3"
    CONFIG_FILE="$4"

    source_config

    # We assume SWARM_HOST and SWARM_TOKEN are properly set at this point
    SWARM_QUEUE="$SWARM_HOST/queue/add/$SWARM_TOKEN"

    UTIL="wget"
    wget --quiet --output-document /dev/null --timeout 10 --post-data "${TYPE},${VALUE}" "${SWARM_QUEUE}"
    RC=$?

    # If wget spews a high-level error code, try curl instead
    # Reference: http://www.tldp.org/LDP/abs/html/exitcodes.html
    if [[ $RC -ge 126 ]]
    then
        UTIL="curl"
        curl --silent --output /dev/null --max-time 10 --data "${TYPE},${VALUE}" "${SWARM_QUEUE}"
        RC=$?
    fi

    if [[ $RC -ne 0 ]]
    then
        "$LOGGER" -p3 -t "$ME" "Error ($RC) trying to post [$TYPE,$VALUE] via [$UTIL] to [$SWARM_QUEUE]"
    fi

    # Exit cleanly
    exit 0
fi

# Sanity check default arguments
if ! command -v "$LOGGER" > /dev/null
then
    echo "$ME: LOGGER is not set properly; please contact your administrator."
    exit 1
fi

# Parse arguments
OPT=
while getopts :t:v:p:g:rc:oh OPT
do
    case "$OPT" in
    t)  TYPE="$OPTARG" ;;
    v)  VALUE="$OPTARG" ;;
    p)  P4_PORT="$OPTARG" ;;
    r)  CHECK_REVIEW_CHANGES_ONLY=true ;;
    g)  GROUP="$OPTARG" ;;
    c)  CONFIG_FILE="$OPTARG"; CONFIG_FILE_USE=" -c %quote%$CONFIG_FILE%quote%";;
    o)  DISPLAY_FLAG_SET=true ;;
    h)  usage ;;
    *)  "$LOGGER" -p3 -t "$ME" -s "unknown argument [-$OPTARG]" && usage ;;
    esac
done

$DISPLAY_FLAG_SET && display_trigger_entries && exit 0

# Sanity check supplied arguments
[[ -z "$TYPE" ]]  && "$LOGGER" -p3 -t "$ME" -s "no event type supplied"   && usage
[[ -z "$VALUE" ]] && "$LOGGER" -p3 -t "$ME" -s "$TYPE: no value supplied" && usage

# Source any configuration files
source_config

# If -t enforce/strict is specified, perform that logic here
if [[ "$TYPE" == "enforce" || "$TYPE" == "strict" ]]
then
    # Sanity test program variables
    for cmd_var in P4 SED GREP
    do
        cmd="${!cmd_var}"
        if ! command -v "$cmd" > /dev/null
        then
            echo "$ME: $cmd_var is not set properly; please contact your administrator."
            exit 1
        fi
    done

    # Set up how we call p4
    P4_CMD=("$P4" "-zprog=p4($ME)")
    [[ -n "$P4_PORT" ]]    && P4_CMD+=("-p" "$P4_PORT")
    [[ -n "$ADMIN_USER" ]] && P4_CMD+=("-u" "$ADMIN_USER")

    [[ -n "$ADMIN_TICKET_FILE" ]] && export P4TICKETS="$ADMIN_TICKET_FILE"

    # Set character-set explicitly if talking to a unicode server
    if "${P4_CMD[@]}" -ztag info | grep -q '^\.\.\. unicode enabled'; then
        P4_CMD+=("-C" "utf8")
    fi

    # Verify our credentials
    if ! "${P4_CMD[@]}" login -s > /dev/null 2>&1
    then
        echo "Invalid login credentials to [$P4_PORT] within this trigger script; please contact your administrator."
        "$LOGGER" -p3 -t "$ME" "$TYPE: reject change $VALUE: invalid login credentials to [$P4_PORT]"
        exit 1
    fi

    # Check if a group was specified
    if [[ -n "$GROUP" ]]
    then
        # Obtain the user from the change
        CHANGE_USER="$("${P4_CMD[@]}" -ztag change -o "$VALUE" | "$SED" -n '/^\.\.\. User /s///p')"

        # Check the user's groups and see if the group to exclude is there
        if [[ -n "$CHANGE_USER" ]] &&
            "${P4_CMD[@]}" groups -i -u "$CHANGE_USER" | "$GREP" -qx "$GROUP"
        then
            # User belong to group to exclude, exit cleanly
            "$LOGGER" -p5 -t "$ME" "$TYPE: accept change $VALUE: $CHANGE_USER belongs to exempt group $GROUP"
            exit 0
        fi
    fi

    # Search for the review key based on the encoded change number
    SEARCH_CMD="search "1301=$(echo "$VALUE" | "$SED" -e 's,\(.\),3\1,g')""
    REVIEW_KEY="$("${P4_CMD[@]}" $SEARCH_CMD | "$SED" '1q')"
    RC=$?

    # Detect if there is any problem with the command
    if [[ $RC -ne 0 ]]
    then
        echo "Error searching Perforce for reviews involving this change ($VALUE); please contact your administrator."
        "$LOGGER" -p3 -t "$ME" "$TYPE: reject change $VALUE: error ($RC) from [${P4_CMD[@]} $SEARCH_CMD]"
        exit $RC
    fi

    # Detect if no review is found
    if [[ -z "$REVIEW_KEY" ]]
    then
        # if enforcement is only set for reviews, exit happy for changes not associated to any
        if $CHECK_REVIEW_CHANGES_ONLY
        then
            exit 0
        fi

        echo "Cannot find a Swarm review associated with this change ($VALUE)."
        "$LOGGER" -p5 -t "$ME" "$TYPE: reject change $VALUE: no Swarm review found"
        exit 1
    fi

    # Detect if the key name is badly formated
    if ! echo "$REVIEW_KEY" | "$GREP" -qx "swarm-review-[0-9a-f]*"
    then
        echo "Bad review key for this change ($VALUE); please contact your administrator."
        "$LOGGER" -p3 -t "$ME" "$TYPE: reject change $VALUE: bad Swarm review key ($REVIEW_KEY)"
        exit 1
    fi

    # Obtain the JSON value of the associated review
    REVIEW_JSON="$("${P4_CMD[@]}" counter -u "$REVIEW_KEY")"
    RC=$?

    # Detect if there is an error or no value for the key (stale index?)
    if [[ $RC -ne 0 || -z "$REVIEW_JSON" ]]
    then
        echo "Cannot find Swarm review data for this change ($VALUE)."
        "$LOGGER" -p4 -t "$ME" "$TYPE: reject change $VALUE: empty value for $REVIEW_KEY"
        exit 1
    fi

    # Calculate the human friendly review ID
    REVIEW_ID="$(($((0xffffffff))-$((0x${REVIEW_KEY##swarm-review-}))))"

    # Locate the change inside the review's associated changes
    REVIEW_CHANGES="$(echo "$REVIEW_JSON" | "$SED" -ne 's/.*\"changes\":\[\([0-9, ]*\)\].*/\1/p')"
    if [[ -z "$REVIEW_CHANGES" || -z "$(echo "$REVIEW_CHANGES" | "$GREP" -w "$VALUE")" ]]
    then
        echo "This change ($VALUE) is not associated with its linked Swarm review $REVIEW_ID."
        "$LOGGER" -p5 -t "$ME" "$TYPE: reject change $VALUE: change not part of $REVIEW_KEY ($REVIEW_ID)"
        exit 1
    fi

    # Obtain review state and see if it's approved
    REVIEW_STATE="$(echo "$REVIEW_JSON" | "$SED" -e 's/.*"state":"\([^"]*\)".*/\1/')"
    if [[ "$REVIEW_STATE" != "approved" ]]
    then
        echo "Swarm review $REVIEW_ID for this change ($VALUE) is not approved ($REVIEW_STATE)."
        "$LOGGER" -p5 -t "$ME" "$TYPE: reject change $VALUE: $REVIEW_KEY ($REVIEW_ID) not approved ($REVIEW_STATE)"
        exit 1
    fi

    # for -t strict, check that the change's content matches that of its review
    if [[ "$TYPE" == "strict" ]]
    then
        REVIEW_FSTAT="$("${P4_CMD[@]}" fstat -Ol -T "depotFile, headType, digest" @="$REVIEW_ID")"
        RC1=$?
        CHANGE_FSTAT="$("${P4_CMD[@]}" fstat -Ol -T "depotFile, headType, digest" @="$VALUE")"
        RC2=$?
        if [[ $RC1 -ne 0 || $RC2 -ne 0 || -z "$REVIEW_FSTAT" || -z "$CHANGE_FSTAT" ]]
        then
            echo "Error obtaining fstat output for this change ($VALUE) or its associated review ($REVIEW_ID); please contact your administrator."
            "$LOGGER" -p3 -t "$ME" "$TYPE: reject change $VALUE: error obtaining fstat output for either change or review ($REVIEW_ID)"
            exit 1
        fi

        # check that the fstat output matches
        if [[ "$REVIEW_FSTAT" != "$CHANGE_FSTAT" ]]
        then
            echo "The content of this change ($VALUE) does not match the content of the associated Swarm review ($REVIEW_ID)."
            "$LOGGER" -p5 -t "$ME" "$TYPE: reject change $VALUE: content does not match review ($REVIEW_ID)"
            exit 1
        fi
    fi

    # Return success at this point
    exit 0
fi

# Sanity check global variables we need for posting events to Swarm
if [[ -z "$SWARM_HOST" || "$SWARM_HOST" == "http://my-swarm-host" ]]
then
    echo "SWARM_HOST is not set properly; please contact your administrator."
    "$LOGGER" -p3 -t "$ME"  "$TYPE: SWARM_HOST empty or default"
    exit 1
fi
if [[ -z "$SWARM_TOKEN" || "$SWARM_TOKEN" == "MY-UUID-STYLE-TOKEN" ]]
then
    echo "SWARM_TOKEN is not set properly; please contact your administrator."
    "$LOGGER" -p3 -t "$ME" "$TYPE: SWARM_TOKEN empty or default"
    exit 1
fi

# For other Swarm trigger types, post the event to Swarm asynchronously
# (call self, but detach to the background)
$0 "$BACKGROUND_FLAG" "$TYPE" "$VALUE" "$CONFIG_FILE" > /dev/null 2>&1 < /dev/null &

# Always return success to avoid affecting Perforce users
exit 0
