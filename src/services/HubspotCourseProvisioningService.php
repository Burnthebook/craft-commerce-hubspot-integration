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
     * @return array<int, array{sku: string, name: string, courseDate: string|null, typeId: string|null}>
     */
    public function extractProvisioningPayloads(ElementInterface $element): array
    {
        $payloads = [];

        foreach ($this->resolveProvisioningTargets($element) as $target) {
            $sku = $this->normalizeValue($this->resolveSku($target))
                ?? $this->normalizeValue($this->readValue($target, ['sku', 'craftCourseId', 'courseId', 'hsCourseId']))
                ?? $this->normalizeValue($this->readValue($element, ['sku', 'craftCourseId', 'courseId', 'hsCourseId']));

            $payloads[] = [
                'sku' => $sku ?? '',
                'name' => $this->resolveDisplayName($element, $target),
                'courseDate' => $this->normalizeValue($this->readValue($target, ['ConferenceStartDate', 'conferenceStartDate', 'courseDate']))
                    ?? $this->normalizeValue($this->readValue($element, ['ConferenceStartDate', 'conferenceStartDate', 'courseDate'])),
                'typeId' => $this->normalizeValue($this->readValue($target, ['type_id', 'typeId']))
                    ?? $this->normalizeValue($this->readValue($element, ['type_id', 'typeId'])),
            ];
        }

        return $payloads;
    }

    public function payloadHash(ElementInterface $element): string
    {
        $payloads = $this->extractProvisioningPayloads($element);
        return hash('sha256', json_encode($payloads) ?: '');
    }

    /**
     * Upsert one or more courses in HubSpot and return object IDs keyed by SKU.
     *
     * @return array<string, string>
     */
    public function provisionCourses(ElementInterface $element): array
    {
        $payloads = $this->extractProvisioningPayloads($element);

        if ($payloads === []) {
            throw new \RuntimeException('Course provisioning skipped: no provisioning targets were resolved from source element.');
        }

        $hubspotIds = [];

        foreach ($payloads as $payload) {
            if ($payload['sku'] === '') {
                throw new \RuntimeException('Course provisioning skipped: missing SKU value on source element or variant.');
            }

            $hubspotIds[$payload['sku']] = $this->courseHandler->upsertCourseBySku(
                sku: $payload['sku'],
                description: $payload['name'] !== '' ? $payload['name'] : null,
                status: null,
                conferenceStartDate: $payload['courseDate'],
                typeId: $payload['typeId']
            );
        }

        return $hubspotIds;
    }

    /**
     * Backwards-compatible single-call wrapper for provisioning.
     */
    public function provisionCourse(ElementInterface $element): string
    {
        $hubspotIds = $this->provisionCourses($element);
        if ($hubspotIds === []) {
            throw new \RuntimeException('Course provisioning skipped: no HubSpot course IDs were returned.');
        }

        return (string)reset($hubspotIds);
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

    /**
     * @return array<int, ElementInterface>
     */
    private function resolveProvisioningTargets(ElementInterface $element): array
    {
        if (!method_exists($element, 'getVariants')) {
            return [$element];
        }

        $variants = $element->getVariants();
        if (is_object($variants) && method_exists($variants, 'all')) {
            $variants = $variants->all();
        }

        if (!is_iterable($variants)) {
            return [$element];
        }

        $targets = [];

        foreach ($variants as $variant) {
            if ($variant instanceof ElementInterface && $this->isElementEnabledForProvisioning($variant)) {
                $targets[] = $variant;
            }
        }

        return $targets !== [] ? $targets : [$element];
    }

    private function resolveDisplayName(ElementInterface $sourceElement, ElementInterface $targetElement): string
    {
        $sourceTitle = $this->normalizeValue($this->readValue($sourceElement, ['title', 'description', 'courseName'])) ?? '';

        if ($sourceElement === $targetElement) {
            return $sourceTitle;
        }

        $targetTitle = $this->normalizeValue($this->readValue($targetElement, ['title', 'description', 'courseName'])) ?? '';

        if ($sourceTitle !== '' && $targetTitle !== '' && $targetTitle !== $sourceTitle) {
            return $sourceTitle . ': ' . $targetTitle;
        }

        return $targetTitle !== '' ? $targetTitle : $sourceTitle;
    }

    private function isElementEnabledForProvisioning(ElementInterface $element): bool
    {
        if (method_exists($element, 'getEnabledForSite')) {
            return (bool)$element->getEnabledForSite();
        }

        if (isset($element->enabled)) {
            return (bool)$element->enabled;
        }

        return true;
    }
}
