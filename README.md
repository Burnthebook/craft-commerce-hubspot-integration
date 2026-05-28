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

## Course Provisioning (On Save + Batch Import)

This plugin supports standalone HubSpot course provisioning outside the order lifecycle.

### What it does

- On-save provisioning: when a selected source element is saved, a `HubspotCourseProvisioningJob` is queued.
- Batch import provisioning: a CLI command imports selected source elements in bulk.
- Shared pipeline: both paths use the same provisioning service and upsert logic.

### Configure provisioning sources

In plugin settings (`Admin -> Settings -> Craft Commerce Hubspot Integration`):

- Enable `Enable CMS Course Provisioning`.
- Under `Provisioning Sources`, tick the sources that should provision courses:
  - Commerce Product Types
  - Digital Product Types
  - Entry Sections

If a source is not selected, saves from that source are skipped.

### On-save behavior

- Trigger: save a selected Commerce Product, Digital Product, or Entry.
- Queue job: `Provision HubSpot course for element #<id> (site <id>)`.
- Idempotency: unchanged payloads are skipped by payload hash.
- Result tracking: status/errors are stored in `{{%btb_hubspot_course_sync_records}}`.

### Batch import (CLI)

Import all elements from selected provisioning sources:

```bash
php craft courses/import
```

Queue in smaller chunks:

```bash
php craft courses/import --batch-size=100
```

Process immediately (synchronous, no queue):

```bash
php craft courses/import --sync=1
```

Import one element only:

```bash
php craft courses/import --entry-id=123
php craft courses/import --entry-id=123 --sync=1
```

Run queued jobs manually:

```bash
php craft queue/listen --verbose
```

### Notes

- Provisioning requires a stable SKU for dedupe/upsert.
- Missing optional fields (for example date/type fields not present on a source) are skipped.
- HubSpot validation errors are recorded in the sync record and Craft logs.
