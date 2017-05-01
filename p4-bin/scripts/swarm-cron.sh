#!/usr/bin/env bash
#
# Perforce Swarm Cron Script
#
# @copyright   2013-2016 Perforce Software. All rights reserved.
# @version     2016.1/1400259
#
# This script is meant to be called from cron every minute.

# This script
ME="${0##*/}"

usage()
{
    cat << ! >&2
Usage: $ME [-f <config file>] [-h]
    -f: Specify config file of Swarm host(s) to start up workers on
        Default config file: [$DEFAULT_CONFIG_FILE]
    -h: Display this help

Note:
    * The config file format is expected to contain one entry per line (excluding #comments):
      [http[s]://]<hostname>[:<port>]
    * If no file is specified and the default config file does not exist, the script will simply exit.

!
    exit 99
}

start_worker()
{
    [[ -z "$1" ]] &&
        echo "$ME: error: expected hostname argument to start_worker()" >&2 &&
        exit 1

    SWARM_HOST="$1"

    # Note: We intentionally include the --no-check-certificate (for wget) / --insecure (curl) flag to spawn workers
    # against Swarm instances running in HTTPS mode with self-signed certificates.
    # We felt this was justified since we send no data, and do nothing with any response (we throw it away).

    UTIL="wget"
    wget --quiet --no-check-certificate --output-document /dev/null --timeout 5 "${SWARM_HOST}/queue/worker"
    RC=$?

    # If wget spews a high-level error code, try curl instead
    # Reference: http://www.tldp.org/LDP/abs/html/exitcodes.html
    if [[ $RC -ge 126 ]]
    then
        UTIL="curl"
        curl --silent --insecure --output /dev/null --max-time 5 "${SWARM_HOST}/queue/worker"
        RC=$?
    fi

    if [[ $RC -ne 0 ]]
    then
        echo "$ME: Error ($RC) starting a worker on [$SWARM_HOST] via [$UTIL]" >&2
    fi

    return $RC
}

# Default options
DEFAULT_CONFIG_FILE="/opt/perforce/etc/swarm-cron-hosts.conf"

# Parse arguments
OPT=
CONFIG_FILE=
while getopts :f:h OPT
do
    case "$OPT" in
    f)  CONFIG_FILE="$OPTARG" ;;
    h)  usage ;;
    *)  echo "$ME: unknown argument [-$OPTARG]" >&2 && usage ;;
    esac
done

# Check the config file if passed
[[ -n "$CONFIG_FILE" && ! -r "$CONFIG_FILE" ]] &&
    echo "$ME: Error: config file not found [$CONFIG_FILE]" &&
    exit 1

# If no config file specified, use the default config file
[[ -z "$CONFIG_FILE" ]] && CONFIG_FILE="$DEFAULT_CONFIG_FILE"

# Read the config file if it exists
if [[ -r "$CONFIG_FILE" ]]
then
    # Filter out comments, and read the first word of each line
    egrep -v "^\s*#" "$CONFIG_FILE" |
        while read host _
        do
            # Skip any blank lines
            [[ -z "$host" ]] && continue

            # Attempt to start a worker
            start_worker "$host"
        done
else
    # No config file, default to localhost
    start_worker "http://localhost"
fi

# Exit cleanly
exit 0
