<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\enums;

/**
 * HubSpot CRM object types used by this integration.
 */
enum HubspotObjectType: string
{
    case Contacts = 'contacts';
    case Companies = 'companies';
    case Order = 'order';
    case Course = '0-410';
}
