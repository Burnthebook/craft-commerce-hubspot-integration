<?php

namespace burnthebook\craftcommercehubspotintegration;

use Craft;
use yii\base\Event;
use Psr\Log\LogLevel;
use craft\base\Model;
use craft\base\Plugin;
use craft\base\Element;
use craft\elements\Entry;
use craft\models\Section;
use craft\log\MonologTarget;
use craft\events\ModelEvent;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use Monolog\Formatter\LineFormatter;
use burnthebook\craftcommercehubspotintegration\models\Settings;
use burnthebook\craftcommercehubspotintegration\jobs\HubspotOrderSyncJob;
use burnthebook\craftcommercehubspotintegration\services\HubspotApiClient;
use burnthebook\craftcommercehubspotintegration\services\HubspotApiService;
use burnthebook\craftcommercehubspotintegration\jobs\HubspotCourseProvisioningJob;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotOrderHandler;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotCourseHandler;
use burnthebook\craftcommercehubspotintegration\services\HubspotCourseProvisioningService;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotAssociationHandler;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotContactsCompaniesHandler;

/**
 * Craft Commerce HubSpot Integration plugin bootstrap.
 *
 * @method static CommerceHubspotIntegration getInstance()
 * @method Settings getSettings()
 *
 * @author Burnthebook <support@burnthebook.co.uk>
 * @copyright Burnthebook
 * @license MIT
 */
class CommerceHubspotIntegration extends Plugin
{
    /**
     * Plugin schema version used for migrations.
     */
    public string $schemaVersion = '0.0.2';

    /**
     * Whether the plugin exposes CP settings.
     */
    public bool $hasCpSettings = true;

    /**
     * Configure plugin service components.
     *
     * @return array<string, array<string, callable(): object>>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'hubspotApiClient' => function (): HubspotApiClient {
                    $plugin = self::getInstance();

                    if (!$plugin instanceof self) {
                        throw new \RuntimeException('Unable to resolve plugin instance for HubSpot API client.');
                    }

                    /** @var Settings $settings */
                    $settings = $plugin->getSettings();

                    return new HubspotApiClient(
                        baseUri: $settings->getParsedHubspotApiBaseUrl(),
                        accessToken: $settings->getParsedHubspotPrivateAppToken()
                    );
                },
                'hubspotApiService' => function (): HubspotApiService {
                    $plugin = self::getInstance();

                    if (!$plugin instanceof self) {
                        throw new \RuntimeException('Unable to resolve plugin instance for HubSpot API service.');
                    }

                    /** @var Settings $settings */
                    $settings = $plugin->getSettings();

                    $client = $plugin->getHubspotApiClient();

                    return new HubspotApiService(
                        contactsCompaniesHandler: new HubspotContactsCompaniesHandler($client),
                        courseHandler: new HubspotCourseHandler(
                            client: $client,
                            coursePipelineId: $settings->getParsedHubspotCoursePipelineId(),
                            courseStageOpenId: $settings->getParsedHubspotCourseStageOpenId(),
                            courseStageClosedId: $settings->getParsedHubspotCourseStageClosedId()
                        ),
                        orderHandler: new HubspotOrderHandler(
                            client: $client,
                            orderPipelineId: $settings->getParsedHubspotOrderPipelineId(),
                            orderStageOpenId: $settings->getParsedHubspotOrderStageOpenId(),
                            orderStageProcessedId: $settings->getParsedHubspotOrderStageProcessedId(),
                            orderStageShippedId: $settings->getParsedHubspotOrderStageShippedId(),
                            orderStageDeliveredId: $settings->getParsedHubspotOrderStageDeliveredId(),
                            orderStageCancelledId: $settings->getParsedHubspotOrderStageCancelledId(),
                            orderSourceStore: $settings->getParsedHubspotOrderSourceStore()
                        ),
                        associationHandler: new HubspotAssociationHandler($client)
                    );
                },
                'hubspotCourseProvisioningService' => function (): HubspotCourseProvisioningService {
                    $plugin = self::getInstance();

                    if (!$plugin instanceof self) {
                        throw new \RuntimeException('Unable to resolve plugin instance for HubSpot course provisioning service.');
                    }

                    /** @var Settings $settings */
                    $settings = $plugin->getSettings();

                    return new HubspotCourseProvisioningService(
                        courseHandler: new HubspotCourseHandler(
                            client: $plugin->getHubspotApiClient(),
                            coursePipelineId: $settings->getParsedHubspotCoursePipelineId(),
                            courseStageOpenId: $settings->getParsedHubspotCourseStageOpenId(),
                            courseStageClosedId: $settings->getParsedHubspotCourseStageClosedId()
                        )
                    );
                },
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->request->isConsoleRequest) {
            $this->controllerNamespace = 'burnthebook\\craftcommercehubspotintegration\\console\\controllers';
        }

        // Register a custom log target, keeping the format as simple as possible.
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'craft-commerce-hubspot-integration',
            'categories' => ['craft-commerce-hubspot-integration'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function () {
            if (!Craft::$app->request->isConsoleRequest) {
                $this->attachEventHandlers();
            }
            // ...
        });
    }

    /**
     * Create the plugin settings model.
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * Render plugin CP settings HTML.
     */
    protected function settingsHtml(): ?string
    {
        $provisioningSourceOptions = [];

        if (class_exists(\craft\commerce\Plugin::class)) {
            $productTypes = \craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes();

            foreach ($productTypes as $productType) {
                $handle = (string)($productType->handle ?? '');
                if ($handle === '') {
                    continue;
                }

                $provisioningSourceOptions[] = [
                    'label' => 'Commerce Product Type: ' . $handle,
                    'value' => 'commerceProductType:' . $handle,
                ];
            }
        }

        if (class_exists(\craft\digitalproducts\Plugin::class)) {
            $digitalProductTypesService = \craft\digitalproducts\Plugin::getInstance()->getProductTypes();
            $digitalProductTypes = $digitalProductTypesService->getAllProductTypes();

            foreach ($digitalProductTypes as $productType) {
                $handle = (string)($productType->handle ?? '');
                if ($handle === '') {
                    continue;
                }

                $provisioningSourceOptions[] = [
                    'label' => 'Digital Product Type: ' . $handle,
                    'value' => 'digitalProductType:' . $handle,
                ];
            }
        }

        $sections = Craft::$app->getEntries()->getAllSections();
        foreach ($sections as $section) {
            if (!$section instanceof Section) {
                continue;
            }

            $provisioningSourceOptions[] = [
                'label' => 'Entry Section: ' . $section->handle,
                'value' => 'section:' . $section->handle,
            ];
        }

        return Craft::$app->view->renderTemplate('craft-commerce-hubspot-integration/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'provisioningSourceOptions' => $provisioningSourceOptions,
        ]);
    }

    /**
     * Attach Craft and Commerce event listeners.
     */
    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)


        // After Order Completed
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function (Event $event) {
                /** @var Order $order */
                $order = $event->sender;

                Craft::info(
                    'After complete order event triggered for order #' . $order->number,
                    'craft-commerce-hubspot-integration'
                );

                Craft::$app->getQueue()->push(new HubspotOrderSyncJob([
                    'orderId' => (int)$order->id,
                ]));
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event): void {
                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }

                $settings = $this->getSettings();
                if (!$settings->getParsedHubspotCourseProvisioningEnabled()) {
                    return;
                }

                $entrySectionHandle = '';
                if (method_exists($entry, 'getSection')) {
                    $entrySection = $entry->getSection();
                    $entrySectionHandle = (string)($entrySection?->handle ?? '');
                }

                if ($entrySectionHandle === '') {
                    return;
                }

                if (!$this->isElementEnabledForProvisioning($entry)) {
                    return;
                }

                $allowedSources = $settings->getParsedHubspotCourseProvisioningSourceHandles();
                if (!$this->isProvisioningSourceAllowed($allowedSources, 'section:' . $entrySectionHandle)) {
                    Craft::info(
                        sprintf(
                            'Skipping HubSpot course provisioning for entry %d: section "%s" is not in configured provisioning sources.',
                            (int)$entry->id,
                            $entrySectionHandle
                        ),
                        'craft-commerce-hubspot-integration'
                    );
                    return;
                }

                $isDraft = method_exists($entry, 'getIsDraft') ? (bool)$entry->getIsDraft() : false;
                $isRevision = method_exists($entry, 'getIsRevision') ? (bool)$entry->getIsRevision() : false;
                $isProvisionalDraft = method_exists($entry, 'getIsProvisionalDraft') ? (bool)$entry->getIsProvisionalDraft() : false;

                if ($isDraft || $isRevision || $isProvisionalDraft) {
                    return;
                }

                if (!$entry->id || !$entry->siteId) {
                    return;
                }

                Craft::$app->getQueue()->push(new HubspotCourseProvisioningJob([
                    'elementId' => (int)$entry->id,
                    'siteId' => (int)$entry->siteId,
                ]));
            }
        );

        Event::on(
            Product::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event): void {
                $product = $event->sender;
                if (!$product instanceof Product) {
                    return;
                }

                $settings = $this->getSettings();
                if (!$settings->getParsedHubspotCourseProvisioningEnabled()) {
                    return;
                }

                $productTypeHandle = (string)($product->type?->handle ?? '');
                $allowedSources = $settings->getParsedHubspotCourseProvisioningSourceHandles();
                if (!$this->isProvisioningSourceAllowed($allowedSources, 'commerceProductType:' . $productTypeHandle)) {
                    Craft::info(
                        sprintf(
                            'Skipping HubSpot course provisioning for product %d: product type "%s" is not in configured provisioning sources.',
                            (int)$product->id,
                            $productTypeHandle
                        ),
                        'craft-commerce-hubspot-integration'
                    );
                    return;
                }

                $isDraft = method_exists($product, 'getIsDraft') ? (bool)$product->getIsDraft() : false;
                $isRevision = method_exists($product, 'getIsRevision') ? (bool)$product->getIsRevision() : false;
                $isProvisionalDraft = method_exists($product, 'getIsProvisionalDraft') ? (bool)$product->getIsProvisionalDraft() : false;

                if ($isDraft || $isRevision || $isProvisionalDraft) {
                    return;
                }

                if (!$product->id || !$product->siteId) {
                    return;
                }

                if (!$this->isElementEnabledForProvisioning($product)) {
                    return;
                }

                Craft::$app->getQueue()->push(new HubspotCourseProvisioningJob([
                    'elementId' => (int)$product->id,
                    'siteId' => (int)$product->siteId,
                ]));

                Craft::info(
                    sprintf(
                        'Queued HubSpot course provisioning for product %d (site %d).',
                        (int)$product->id,
                        (int)$product->siteId
                    ),
                    'craft-commerce-hubspot-integration'
                );
            }
        );

        $digitalProductClass = '\\craft\\digitalproducts\\elements\\Product';
        if (class_exists($digitalProductClass)) {
            Event::on(
                $digitalProductClass,
                Element::EVENT_AFTER_SAVE,
                function (ModelEvent $event): void {
                    $digitalProduct = $event->sender;

                    $settings = $this->getSettings();
                    if (!$settings->getParsedHubspotCourseProvisioningEnabled()) {
                        return;
                    }

                    $digitalTypeHandle = (string)($digitalProduct->type?->handle ?? '');
                    $allowedSources = $settings->getParsedHubspotCourseProvisioningSourceHandles();
                    if (!$this->isProvisioningSourceAllowed($allowedSources, 'digitalProductType:' . $digitalTypeHandle)) {
                        Craft::info(
                            sprintf(
                                'Skipping HubSpot course provisioning for digital product %d: digital product type "%s" is not in configured provisioning sources.',
                                (int)($digitalProduct->id ?? 0),
                                $digitalTypeHandle
                            ),
                            'craft-commerce-hubspot-integration'
                        );
                        return;
                    }

                    $isDraft = method_exists($digitalProduct, 'getIsDraft') ? (bool)$digitalProduct->getIsDraft() : false;
                    $isRevision = method_exists($digitalProduct, 'getIsRevision') ? (bool)$digitalProduct->getIsRevision() : false;
                    $isProvisionalDraft = method_exists($digitalProduct, 'getIsProvisionalDraft') ? (bool)$digitalProduct->getIsProvisionalDraft() : false;

                    if ($isDraft || $isRevision || $isProvisionalDraft) {
                        return;
                    }

                    if (!isset($digitalProduct->id, $digitalProduct->siteId) || !$digitalProduct->id || !$digitalProduct->siteId) {
                        return;
                    }

                    if (!$this->isElementEnabledForProvisioning($digitalProduct)) {
                        return;
                    }

                    Craft::$app->getQueue()->push(new HubspotCourseProvisioningJob([
                        'elementId' => (int)$digitalProduct->id,
                        'siteId' => (int)$digitalProduct->siteId,
                    ]));

                    Craft::info(
                        sprintf(
                            'Queued HubSpot course provisioning for digital product %d (site %d).',
                            (int)$digitalProduct->id,
                            (int)$digitalProduct->siteId
                        ),
                        'craft-commerce-hubspot-integration'
                    );
                }
            );
        }
    }

    /**
     * Resolve the HubSpot API client component.
     */
    public function getHubspotApiClient(): HubspotApiClient
    {
        /** @var HubspotApiClient $client */
        $client = $this->get('hubspotApiClient');

        return $client;
    }

    /**
     * Resolve the HubSpot orchestration service component.
     */
    public function getHubspotApiService(): HubspotApiService
    {
        /** @var HubspotApiService $service */
        $service = $this->get('hubspotApiService');

        return $service;
    }

    /**
     * Resolve the HubSpot course provisioning service component.
     */
    public function getHubspotCourseProvisioningService(): HubspotCourseProvisioningService
    {
        /** @var HubspotCourseProvisioningService $service */
        $service = $this->get('hubspotCourseProvisioningService');

        return $service;
    }

    /**
     * @param array<int, string> $allowedSources
     */
    private function isProvisioningSourceAllowed(array $allowedSources, string $candidate): bool
    {
        if ($allowedSources === []) {
            return true;
        }

        return in_array($candidate, $allowedSources, true);
    }

    private function isElementEnabledForProvisioning(object $element): bool
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
