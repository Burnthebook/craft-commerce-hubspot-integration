<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\enums;

/**
 * HubSpot association category values.
 */
enum HubspotAssociationCategory: string
{
    case HubspotDefined = 'HUBSPOT_DEFINED';
    case UserDefined = 'USER_DEFINED';
}
