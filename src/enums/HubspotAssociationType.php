<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\enums;

/**
 * Semantic association type keys used by the integration.
 */
enum HubspotAssociationType: string
{
    case ContactToCompanyPrimary = 'contact_to_company_primary';
    case ContactToCourseBooker = 'contact_to_course_booker';
    case ContactToCourseDelegate = 'contact_to_course_delegate';
    case ContactToOrderBooker = 'contact_to_order_booker';
    case ContactToOrderDelegate = 'contact_to_order_delegate';
    case CompanyToCourseRegistrations = 'company_to_course_registrations';
    case CompanyToOrderPrimary = 'company_to_order_primary';

    /**
     * Return HubSpot numeric association type ID.
     */
    public function id(): int
    {
        return match ($this) {
            self::ContactToCompanyPrimary => 1,
            self::ContactToCourseBooker => 2,
            self::ContactToCourseDelegate => 4,
            self::ContactToOrderBooker => 6,
            self::ContactToOrderDelegate => 8,
            self::CompanyToCourseRegistrations => 10,
            self::CompanyToOrderPrimary => 510,
        };
    }
}
