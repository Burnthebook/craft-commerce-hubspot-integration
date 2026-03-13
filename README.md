# Craft Commerce Hubspot Integration

Integrates Craft Commerce with Hubspot.

> [!WARNING]  
> This plugin is built for burnthebook internal use currently. Not a lot is generally configurable, and it is specific to our clients workflows.
> Eventually we would like to make this generic enough that anyone could use it, hence it's existence as a public repository.
> You can also use the code as a reference to create your own.

## Requirements

- Craft CMS 5.0.0+
- PHP 8.3+

## Installation

```bash
# Go to your Craft project
cd /path/to/project

# Install the plugin
composer require burnthebook/craft-commerce-hubspot-integration

# Enable the plugin
php craft plugin/install craft-commerce-hubspot-integration
```

## Setup

Go to Admin -> Settings -> Craft Commerce Hubspot Integration, configure your Hubspot API Base URL (default https://api.hubapi.com) and HubSpot Private App Token (It is recommended to use an .env variable for this).

## Order Sync Lifecycle

When an order completes, sync is queued and executed in staged jobs. The flow below references the concrete classes in this plugin.

### Runtime wiring

- Plugin bootstrap: `src/CommerceHubspotIntegration.php`
- Event attachment: `CommerceHubspotIntegration::attachEventHandlers()`
- Queue entrypoint: `src/jobs/HubspotOrderSyncJob.php`
- Orchestration service: `src/services/HubspotApiService.php`
- Low-level API client: `src/services/HubspotApiClient.php`
- Stage handlers:
  - `src/services/handlers/HubspotContactsCompaniesHandler.php`
  - `src/services/handlers/HubspotCourseHandler.php`
  - `src/services/handlers/HubspotOrderHandler.php`
  - `src/services/handlers/HubspotAssociationHandler.php`

### End-to-end order flow

1. `craft\commerce\elements\Order::EVENT_AFTER_COMPLETE_ORDER` fires.
2. `CommerceHubspotIntegration::attachEventHandlers()` enqueues `HubspotOrderSyncJob` with `orderId`.
3. `HubspotOrderSyncJob::execute()`:
   - loads/creates `HubspotSyncRecord` (`src/records/HubspotSyncRecord.php`),
   - computes payload hash from `Order::toArray()`,
   - skips if previously `succeeded` with identical hash,
   - marks `in_progress`, then queues stage 1 (`HubspotContactsCompaniesSyncJob`).
4. `HubspotContactsCompaniesSyncJob::execute()`:
   - resolves order,
   - calls `HubspotApiService::syncOrderContactsAndCompanies()`,
   - queues stage 2 (`HubspotCoursesSyncJob`) with returned contact/company context.
5. `HubspotCoursesSyncJob::execute()`:
   - calls `HubspotApiService::syncOrderCourses()`,
   - queues stage 3 (`HubspotOrderRecordSyncJob`) with `courseMap`.
6. `HubspotOrderRecordSyncJob::execute()`:
   - calls `HubspotApiService::syncOrderRecord()`,
   - queues stage 4 (`HubspotAssociationsSyncJob`) with `hubspotOrderId`.
7. `HubspotAssociationsSyncJob::execute()`:
   - calls `HubspotApiService::syncOrderAssociations()`,
   - marks sync record `succeeded` and sets `syncedAt`.

### Failure and retry behavior

- Stage jobs extend `AbstractHubspotSyncStageJob` (`src/jobs/AbstractHubspotSyncStageJob.php`).
- On exception, `AbstractHubspotSyncStageJob::markFailed()` updates sync record status to `failed`, stores error text, and stores HubSpot correlation ID when available.
- Queue retry behavior is implemented via `RetryableJobInterface` (`canRetry()` / `getTtr()`).

### Auth and settings resolution

- Plugin settings model: `src/models/Settings.php`.
- Service components are built in `CommerceHubspotIntegration::config()`.
- Env vars are resolved via `craft\helpers\App::parseEnv(...)` before building `HubspotApiClient`.
- API client sends Bearer auth (`Authorization: Bearer <token>`) and applies retry/backoff for `429` and `5xx` responses.
