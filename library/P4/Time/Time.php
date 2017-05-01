<?php
/**
 * Abstract class for helping with timezones; specifically converting strings to DateTime objects.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace P4\Time;

class Time
{
    // translation map derived from http://unicode.org/repos/cldr/trunk/common/supplemental/windowsZones.xml
    // using the script collateral/scripts/timezonemap.php
    // we filter for php supported zones and prefer territory 001 where possible.
    protected static $tzWindowsToPhp    = array(
        'afghanistan standard time'       => 'Asia/Kabul',
        'alaskan standard time'           => 'America/Anchorage',
        'arabian standard time'           => 'Asia/Dubai',
        'arabic standard time'            => 'Asia/Baghdad',
        'arab standard time'              => 'Asia/Riyadh',
        'atlantic standard time'          => 'America/Halifax',
        'aus central standard time'       => 'Australia/Darwin',
        'aus eastern standard time'       => 'Australia/Sydney',
        'azerbaijan standard time'        => 'Asia/Baku',
        'azores standard time'            => 'Atlantic/Azores',
        'bahia standard time'             => 'America/Bahia',
        'bangladesh standard time'        => 'Asia/Dhaka',
        'canada central standard time'    => 'America/Regina',
        'cape verde standard time'        => 'Atlantic/Cape_Verde',
        'caucasus standard time'          => 'Asia/Yerevan',
        'cen. australia standard time'    => 'Australia/Adelaide',
        'central america standard time'   => 'America/Guatemala',
        'central asia standard time'      => 'Asia/Almaty',
        'central brazilian standard time' => 'America/Cuiaba',
        'central european standard time'  => 'Europe/Warsaw',
        'central europe standard time'    => 'Europe/Budapest',
        'central pacific standard time'   => 'Pacific/Guadalcanal',
        'central standard time'           => 'America/Chicago',
        'central standard time (mexico)'  => 'America/Mexico_City',
        'china standard time'             => 'Asia/Shanghai',
        'e. africa standard time'         => 'Africa/Nairobi',
        'e. australia standard time'      => 'Australia/Brisbane',
        'e. south america standard time'  => 'America/Sao_Paulo',
        'eastern standard time'           => 'America/New_York',
        'egypt standard time'             => 'Africa/Cairo',
        'ekaterinburg standard time'      => 'Asia/Yekaterinburg',
        'fiji standard time'              => 'Pacific/Fiji',
        'fle standard time'               => 'Europe/Kiev',
        'georgian standard time'          => 'Asia/Tbilisi',
        'gmt standard time'               => 'Europe/London',
        'greenland standard time'         => 'America/Godthab',
        'greenwich standard time'         => 'Atlantic/Reykjavik',
        'gtb standard time'               => 'Europe/Bucharest',
        'hawaiian standard time'          => 'Pacific/Honolulu',
        'iran standard time'              => 'Asia/Tehran',
        'israel standard time'            => 'Asia/Jerusalem',
        'jordan standard time'            => 'Asia/Amman',
        'kaliningrad standard time'       => 'Europe/Kaliningrad',
        'korea standard time'             => 'Asia/Seoul',
        'libya standard time'             => 'Africa/Tripoli',
        'magadan standard time'           => 'Asia/Magadan',
        'mauritius standard time'         => 'Indian/Mauritius',
        'middle east standard time'       => 'Asia/Beirut',
        'montevideo standard time'        => 'America/Montevideo',
        'morocco standard time'           => 'Africa/Casablanca',
        'mountain standard time'          => 'America/Denver',
        'mountain standard time (mexico)' => 'America/Chihuahua',
        'myanmar standard time'           => 'Asia/Rangoon',
        'n. central asia standard time'   => 'Asia/Novosibirsk',
        'namibia standard time'           => 'Africa/Windhoek',
        'newfoundland standard time'      => 'America/St_Johns',
        'new zealand standard time'       => 'Pacific/Auckland',
        'north asia east standard time'   => 'Asia/Irkutsk',
        'north asia standard time'        => 'Asia/Krasnoyarsk',
        'pacific sa standard time'        => 'America/Santiago',
        'pacific standard time'           => 'America/Los_Angeles',
        'pacific standard time (mexico)'  => 'America/Santa_Isabel',
        'pakistan standard time'          => 'Asia/Karachi',
        'paraguay standard time'          => 'America/Asuncion',
        'romance standard time'           => 'Europe/Paris',
        'russian standard time'           => 'Europe/Moscow',
        'sa eastern standard time'        => 'America/Cayenne',
        'samoa standard time'             => 'Pacific/Apia',
        'sa pacific standard time'        => 'America/Bogota',
        'sa western standard time'        => 'America/La_Paz',
        'se asia standard time'           => 'Asia/Bangkok',
        'singapore standard time'         => 'Asia/Singapore',
        'south africa standard time'      => 'Africa/Johannesburg',
        'sri lanka standard time'         => 'Asia/Colombo',
        'syria standard time'             => 'Asia/Damascus',
        'taipei standard time'            => 'Asia/Taipei',
        'tasmania standard time'          => 'Australia/Hobart',
        'tokyo standard time'             => 'Asia/Tokyo',
        'tonga standard time'             => 'Pacific/Tongatapu',
        'turkey standard time'            => 'Europe/Istanbul',
        'ulaanbaatar standard time'       => 'Asia/Ulaanbaatar',
        'us mountain standard time'       => 'America/Phoenix',
        'utc+12'                          => 'Pacific/Tarawa',
        'utc-02'                          => 'America/Noronha',
        'utc-11'                          => 'Pacific/Pago_Pago',
        'venezuela standard time'         => 'America/Caracas',
        'vladivostok standard time'       => 'Asia/Vladivostok',
        'w. australia standard time'      => 'Australia/Perth',
        'w. central africa standard time' => 'Africa/Lagos',
        'w. europe standard time'         => 'Europe/Berlin',
        'west asia standard time'         => 'Asia/Tashkent',
        'west pacific standard time'      => 'Pacific/Port_Moresby',
        'yakutsk standard time'           => 'Asia/Yakutsk',
    );

    /**
     * This method attempts to turn the passed timezone name/id into a DateTimeZone object.
     * The passed zone can be a windows timezone name, timezone abbreviation or
     * supported long-form PHP timezone.
     *
     * If we are not able to get the passed zone into a value DateTimeZone will recognize,
     * an exception will be thrown.
     *
     * @param   string          $name       the name or abbreviated timezone to turn into a date time zone
     * @param   string|int|null $offset     optional - the offset in minutes e.g. "-0800" or -800
     * @return  \DateTimeZone   an object representing the specified timezone
     * @throws  \Exception      if the passed timezone isn't parsable
     */
    public static function toDateTimeZone($name, $offset = null)
    {
        // if we got a windows timezone name, map it over to the PHP compatible value
        if (isset(static::$tzWindowsToPhp[strtolower($name)])) {
            $name = static::$tzWindowsToPhp[strtolower($name)];
        }

        // the 'daylight' version of windows time still map to the same olson value; check that too
        if (isset(static::$tzWindowsToPhp[str_replace('daylight', 'standard', strtolower($name))])) {
            $name = static::$tzWindowsToPhp[str_replace('daylight', 'standard', strtolower($name))];
        }

        // if we have a known abbreviation, resolve it into a full timezone name
        if (array_key_exists(strtolower($name), array_change_key_case(timezone_abbreviations_list(), CASE_LOWER))) {
            // if we have an offset it is expected to be in the format -430 or "+0800"
            // convert this value from hours and minutes to seconds
            $gmtOffset = null;
            if ($offset) {
                $gmtOffset  = ($offset % 100) * 60;
                $gmtOffset += ((int) ($offset / 100)) * 60 * 60;
            }

            $name = timezone_name_from_abbr($name, $gmtOffset);
        }

        return new \DateTimeZone($name);
    }
}
