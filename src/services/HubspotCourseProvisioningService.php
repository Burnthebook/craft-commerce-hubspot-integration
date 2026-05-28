<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\services;

use Craft;
use craft\base\ElementInterface;
use craft\errors\InvalidFieldException;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotCourseHandler;

/**
 * Provisions HubSpot course objects from CMS element data.
 */
final class HubspotCourseProvisioningService
{
    public function __construct(private readonly HubspotCourseHandler $courseHandler)
    {
    }

    /**
     * @return array{sku: string, name: string, courseDate: string|null, typeId: string|null}
     */
    public function extractProvisioningPayload(ElementInterface $element): array
    {
        $sku = $this->normalizeValue($this->resolveSku($element))
            ?? $this->normalizeValue($this->readValue($element, ['sku', 'craftCourseId', 'courseId', 'hsCourseId']));
        $name = $this->normalizeValue($this->readValue($element, ['title', 'description', 'courseName'])) ?? '';
        $courseDate = $this->normalizeValue($this->readValue($element, ['ConferenceStartDate', 'conferenceStartDate', 'courseDate']));
        $typeId = $this->normalizeValue($this->readValue($element, ['type_id', 'typeId']));

        return [
            'sku' => $sku ?? '',
            'name' => $name,
            'courseDate' => $courseDate,
            'typeId' => $typeId,
        ];
    }

    public function payloadHash(ElementInterface $element): string
    {
        $payload = $this->extractProvisioningPayload($element);
        return hash('sha256', json_encode($payload) ?: '');
    }

    /**
     * Upsert course in HubSpot and return object ID.
     */
    public function provisionCourse(ElementInterface $element): string
    {
        $payload = $this->extractProvisioningPayload($element);

        if ($payload['sku'] === '') {
            throw new \RuntimeException('Course provisioning skipped: missing SKU value on source element.');
        }

        return $this->courseHandler->upsertCourseBySku(
            sku: $payload['sku'],
            description: $payload['name'] !== '' ? $payload['name'] : null,
            status: null,
            conferenceStartDate: $payload['courseDate'],
            typeId: $payload['typeId']
        );
    }

    private function readValue(ElementInterface $element, array $candidates): mixed
    {
        foreach ($candidates as $candidate) {
            if ($candidate === 'title' && isset($element->title)) {
                return $element->title;
            }

            if ($candidate === 'sku' && method_exists($element, 'getSku')) {
                $sku = $element->getSku();
                if ($sku !== null && $sku !== '') {
                    return $sku;
                }
            }

            try {
                $fieldValue = $element->getFieldValue($candidate);
            } catch (InvalidFieldException) {
                continue;
            }

            if ($fieldValue !== null && $fieldValue !== '') {
                if ($fieldValue instanceof \DateTimeInterface) {
                    return $fieldValue->format(DATE_ATOM);
                }

                return $fieldValue;
            }
        }

        return null;
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string)$value);
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Resolve SKU from known product element APIs before field fallback.
     */
    private function resolveSku(ElementInterface $element): ?string
    {
        if (method_exists($element, 'getSku')) {
            $sku = $this->normalizeValue($element->getSku());
            if ($sku !== null) {
                return $sku;
            }
        }

        if (method_exists($element, 'getDefaultVariant')) {
            $variant = $element->getDefaultVariant();
            if ($variant !== null) {
                if (method_exists($variant, 'getSku')) {
                    $sku = $this->normalizeValue($variant->getSku());
                    if ($sku !== null) {
                        return $sku;
                    }
                }

                if (isset($variant->sku)) {
                    $sku = $this->normalizeValue($variant->sku);
                    if ($sku !== null) {
                        return $sku;
                    }
                }
            }
        }

        return null;
    }
}
