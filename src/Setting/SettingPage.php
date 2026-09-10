<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Setting;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\AdminMenu;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages\BlacklistSettingsPage;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages\QrCodeSettingsPage;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin\Pages\StatisticsPage;

class SettingPage
{
    public function __construct()
    {
        new AdminMenu();
        new BlacklistSettingsPage();
        new QrCodeSettingsPage();
        new StatisticsPage();
    }
}
