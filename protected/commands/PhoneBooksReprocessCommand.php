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

class PhoneBooksReprocessCommand extends ConsoleCommand
{
    public function run($args)
    {
        $reprocessed = CampaignAttemptService::reprocessEligibleCampaigns();
        if ($reprocessed > 0) {
            echo '[' . date('Y-m-d H:i:s') . "] Reprocessed {$reprocessed} phone numbers.\n";
        }
        return 0;
    }
}
