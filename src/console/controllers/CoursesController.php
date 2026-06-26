<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\console\controllers;

use Craft;
use craft\elements\Entry;
use craft\console\Controller;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use burnthebook\craftcommercehubspotintegration\CommerceHubspotIntegration;
use burnthebook\craftcommercehubspotintegration\jobs\HubspotCourseProvisioningJob;

/**
 * Console actions for course provisioning.
 */
final class CoursesController extends Controller
{
    /**
     * Import all configured source elements into HubSpot courses.
     */
    public function actionImport(int $batchSize = 100, bool $sync = false, ?int $entryId = null): int
    {
        if ($entryId !== null) {
            return $this->importSingleElement($entryId, $sync);
        }

        $settings = CommerceHubspotIntegration::getInstance()->getSettings();
        $sources = $settings->getParsedHubspotCourseProvisioningSourceHandles();
        if ($sources === []) {
            $this->stderr("No provisioning sources selected. Select sources in plugin settings first.\n");
            return self::EXIT_CODE_ERROR;
        }

        $sectionHandles = [];
        $productTypeHandles = [];
        $digitalProductTypeHandles = [];
        foreach ($sources as $source) {
            if (str_starts_with($source, 'section:')) {
                $sectionHandles[] = substr($source, 8);
            }

            if (str_starts_with($source, 'commerceProductType:')) {
                $productTypeHandles[] = substr($source, 20);
            }

            if (str_starts_with($source, 'digitalProductType:')) {
                $digitalProductTypeHandles[] = substr($source, 19);
            }
        }

        $sectionHandles = array_values(array_filter(array_unique($sectionHandles)));
        $productTypeHandles = array_values(array_filter(array_unique($productTypeHandles)));
        $digitalProductTypeHandles = array_values(array_filter(array_unique($digitalProductTypeHandles)));

        $entryQuery = Entry::find()->site('*')->status('enabled')->drafts(false)->revisions(false)->limit(null);
        if ($sectionHandles !== []) {
            $entryQuery->section($sectionHandles);
        } else {
            $entryQuery->id([]);
        }

        $productQuery = Product::find()->site('*')->status('enabled')->drafts(false)->revisions(false)->limit(null);
        if ($productTypeHandles !== []) {
            $productQuery->type($productTypeHandles);
        } else {
            $productQuery->id([]);
        }

        $digitalProducts = [];
        $digitalProductClass = '\\craft\\digitalproducts\\elements\\Product';
        if (class_exists($digitalProductClass) && $digitalProductTypeHandles !== []) {
            /** @var mixed $digitalProductQuery */
            $digitalProductQuery = $digitalProductClass::find();
            $digitalProductQuery
                ->site('*')
                ->status('enabled')
                ->drafts(false)
                ->revisions(false)
                ->limit(null)
                ->type($digitalProductTypeHandles);

            foreach ($digitalProductQuery->all() as $digitalProduct) {
                if ($digitalProduct instanceof ElementInterface) {
                    $digitalProducts[] = $digitalProduct;
                }
            }
        }

        $entryCount = (int)$entryQuery->count();
        $productCount = (int)$productQuery->count();
        $digitalProductCount = count($digitalProducts);
        $this->stdout("Found {$entryCount} entries, {$productCount} commerce products, and {$digitalProductCount} digital products from configured provisioning sources.\n");

        $processed = 0;
        $queued = 0;
        $failed = 0;

        if ($entryCount > 0) {
            foreach ($entryQuery->batch($batchSize) as $batch) {
                foreach ($batch as $entry) {
                    if (!$entry instanceof ElementInterface || !$entry->id || !$entry->siteId) {
                        $failed++;
                        continue;
                    }

                    $this->processElement($entry, $sync, $queued, $failed);
                    $processed++;
                }
            }
        }

        if ($productCount > 0) {
            foreach ($productQuery->batch($batchSize) as $batch) {
                foreach ($batch as $product) {
                    if (!$product instanceof ElementInterface || !$product->id || !$product->siteId) {
                        $failed++;
                        continue;
                    }

                    $this->processElement($product, $sync, $queued, $failed);
                    $processed++;
                }
            }
        }

        foreach ($digitalProducts as $digitalProduct) {
            if (!$digitalProduct->id || !$digitalProduct->siteId) {
                $failed++;
                continue;
            }

            $this->processElement($digitalProduct, $sync, $queued, $failed);
            $processed++;
        }

        $this->stdout(sprintf(
            "Course import complete. Processed: %d, Queued: %d, Failed: %d.\n",
            $processed,
            $queued,
            $failed
        ));

        return $failed > 0 ? self::EXIT_CODE_ERROR : self::EXIT_CODE_NORMAL;
    }

    private function processElement(ElementInterface $element, bool $sync, int &$queued, int &$failed): void
    {
        if ($sync) {
            try {
                CommerceHubspotIntegration::getInstance()
                    ->getHubspotCourseProvisioningService()
                    ->provisionCourse($element);
            } catch (\Throwable $exception) {
                $failed++;
                Craft::error(
                    sprintf('Synchronous course import failed for element %d: %s', (int)$element->id, $exception->getMessage()),
                    'craft-commerce-hubspot-integration'
                );
            }
            return;
        }

        Craft::$app->getQueue()->push(new HubspotCourseProvisioningJob([
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
        ]));
        $queued++;
    }

    private function importSingleElement(int $entryId, bool $sync): int
    {
        $element = Craft::$app->getElements()->getElementById($entryId);
        if (!$element instanceof ElementInterface || !$element->id || !$element->siteId) {
            $this->stderr("Element {$entryId} could not be loaded.\n");
            return self::EXIT_CODE_ERROR;
        }

        if ($sync) {
            CommerceHubspotIntegration::getInstance()
                ->getHubspotCourseProvisioningService()
                ->provisionCourse($element);
            $this->stdout("Provisioned element {$entryId} synchronously.\n");
            return self::EXIT_CODE_NORMAL;
        }

        Craft::$app->getQueue()->push(new HubspotCourseProvisioningJob([
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
        ]));

        $this->stdout("Queued provisioning job for element {$entryId}.\n");
        return self::EXIT_CODE_NORMAL;
    }
}
