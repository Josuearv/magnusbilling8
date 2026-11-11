<?php
/**
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2023 MagnusSolution. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnusbilling/mbilling/issues
 * =======================================
 * Magnusbilling.com <info@magnusbilling.com>
 *
 */
class Loadconfig
{
    public static function syncSystemTimezone($preferredTz = null)
    {
        $candidates = [];
        if (! empty($preferredTz) && is_string($preferredTz)) {
            $candidates[] = trim($preferredTz);
        }
        if (is_readable('/etc/timezone')) {
            $etcTz = trim((string) @file_get_contents('/etc/timezone'));
            if ($etcTz !== '') {
                $candidates[] = $etcTz;
            }
        }
        if (is_link('/etc/localtime')) {
            $linkTarget = @readlink('/etc/localtime');
            if ($linkTarget && preg_match('#zoneinfo/(.+)$#', $linkTarget, $m)) {
                $candidates[] = trim($m[1]);
            }
        }

        $validTimezones = timezone_identifiers_list();
        foreach ($candidates as $tz) {
            if ($tz !== '' && in_array($tz, $validTimezones, true)) {
                @date_default_timezone_set($tz);
                try {
                    $dtz         = new DateTimeZone($tz);
                    $offsetSec   = $dtz->getOffset(new DateTime('now', new DateTimeZone('UTC')));
                    $sign        = $offsetSec >= 0 ? '+' : '-';
                    $absSec      = abs($offsetSec);
                    $hours       = (int) floor($absSec / 3600);
                    $mins        = (int) floor(($absSec % 3600) / 60);
                    $mysqlOffset = sprintf('%s%02d:%02d', $sign, $hours, $mins);
                    Yii::app()->db->createCommand('SET time_zone = :tz')
                        ->bindValue(':tz', $mysqlOffset, PDO::PARAM_STR)
                        ->execute();
                } catch (Exception $e) {
                }
                return $tz;
            }
        }

        return @date_default_timezone_get();
    }

    public static function getConfig()
    {
        $modelConfiguration = Configuration::model()->findAll();

        $config = [];
        foreach ($modelConfiguration as $conf) {
            $config[$conf->config_group_title][$conf->config_key] = $conf->config_value;
        }

        $configuredTz = $config['global']['timezone'] ?? null;
        self::syncSystemTimezone($configuredTz);

        return $config;
    }
}
