<?php

namespace burnthebook\craftcommercehubspotintegration;

use Craft;
use yii\base\Event;
use Psr\Log\LogLevel;
use craft\base\Model;
use craft\base\Plugin;
use craft\log\MonologTarget;
use craft\commerce\elements\Order;
use Monolog\Formatter\LineFormatter;
use burnthebook\craftcommercehubspotintegration\models\Settings;
use burnthebook\craftcommercehubspotintegration\jobs\HubspotOrderSyncJob;
use burnthebook\craftcommercehubspotintegration\services\HubspotApiClient;
use burnthebook\craftcommercehubspotintegration\services\HubspotApiService;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotOrderHandler;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotCourseHandler;
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
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

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
        return Craft::$app->view->renderTemplate('craft-commerce-hubspot-integration/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
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

}
