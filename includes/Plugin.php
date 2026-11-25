<?php

namespace FeedAgentX;

defined('ABSPATH') || exit;

use FeedAgentX\Admin\Notices;
use FeedAgentX\Admin\PixelPage;
use FeedAgentX\Admin\ReviewPrompt;
use FeedAgentX\Admin\SettingsPage;
use FeedAgentX\Api\Auth;
use FeedAgentX\Api\Controllers\AckController;
use FeedAgentX\Api\Controllers\ChangesController;
use FeedAgentX\Api\Controllers\FullFeedController;
use FeedAgentX\Api\Controllers\HealthController;
use FeedAgentX\Api\Controllers\ItemController;
use FeedAgentX\Api\RateLimiter;
use FeedAgentX\Api\RestRoutes;
use FeedAgentX\Domain\Currency\MinorUnitsConverter;
use FeedAgentX\Domain\Mappers\AvailabilityMapper;
use FeedAgentX\Domain\Mappers\BarcodeMapper;
use FeedAgentX\Domain\Mappers\BrandResolver;
use FeedAgentX\Domain\Mappers\CategoryMapper;
use FeedAgentX\Domain\Mappers\DescriptionMapper;
use FeedAgentX\Domain\Mappers\MediaMapper;
use FeedAgentX\Domain\Mappers\PriceMapper;
use FeedAgentX\Domain\Mappers\ProductMapper;
use FeedAgentX\Domain\Mappers\SellerMapper;
use FeedAgentX\Domain\Mappers\VariantMapper;
use FeedAgentX\Domain\Mappers\VariantOptionMapper;
use FeedAgentX\Domain\Validation\ProductValidator;
use FeedAgentX\Hooks\CronJobs;
use FeedAgentX\Hooks\TaxonomyEvents;
use FeedAgentX\Hooks\WooEvents;
use FeedAgentX\Infra\Cache;
use FeedAgentX\Infra\ChangeLogRepository;
use FeedAgentX\Infra\Logger;
use FeedAgentX\Infra\MeasurementSettings;
use FeedAgentX\Infra\Migrations;
use FeedAgentX\Infra\ProductRepository;
use FeedAgentX\Infra\SettingsRepository;
use FeedAgentX\Measurement\Consent;
use FeedAgentX\Measurement\ConversionsApi;
use FeedAgentX\Measurement\EventData;
use FeedAgentX\Measurement\OrderAttribution;
use FeedAgentX\Measurement\PixelFrontend;
use FeedAgentX\Measurement\ServerEvents;
use FeedAgentX\Services\DeltaService;
use FeedAgentX\Services\FeedService;
use FeedAgentX\Services\HealthService;
use FeedAgentX\Services\TokenService;

/**
 * Composition root + lightweight service container.
 *
 * Builds every collaborator once and wires them together. Holds singletons
 * keyed by short names so admin/REST/hooks all share the same instances.
 */
final class Plugin
{
    private static ?self $instance = null;

    private array $services = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        // Logger uses a static facade — set the singleton early so any error
        // during boot is captured.
        Logger::set_instance($this->logger());

        // Run pending migrations on every load (cheap when up-to-date).
        $this->migrations()->run(FAXC_VERSION);

        // WordPress loads translations for the registered plugin text domain automatically.

        // REST routes.
        $this->rest_routes()->register();

        // Hooks.
        $this->woo_events()->register();
        $this->taxonomy_events()->register();
        $this->cron_jobs()->register();

        // OpenAI Measurement Pixel + Conversions API. Independent of the
        // FeedAgentX connection; each part checks its own settings.
        $this->pixel_frontend()->register();
        $this->order_attribution()->register();
        $this->server_events()->register();

        // Admin only.
        if (is_admin()) {
            $this->settings_page()->register();
            $this->notices()->register();
        }
    }

    // ───────────────────────── Infra ─────────────────────────

    public function settings(): SettingsRepository
    {
        return $this->services[SettingsRepository::class] ??= new SettingsRepository();
    }

    public function logger(): Logger
    {
        return $this->services[Logger::class] ??= new Logger();
    }

    public function cache(): Cache
    {
        return $this->services[Cache::class] ??= new Cache();
    }

    public function migrations(): Migrations
    {
        return $this->services[Migrations::class] ??= new Migrations($this->settings());
    }

    public function change_log(): ChangeLogRepository
    {
        return $this->services[ChangeLogRepository::class] ??= new ChangeLogRepository();
    }

    public function product_repo(): ProductRepository
    {
        return $this->services[ProductRepository::class] ??= new ProductRepository($this->settings());
    }

    // ───────────────────────── Domain ─────────────────────────

    public function minor_units(): MinorUnitsConverter
    {
        return $this->services[MinorUnitsConverter::class] ??= new MinorUnitsConverter();
    }

    public function product_mapper(): ProductMapper
    {
        return $this->services[ProductMapper::class] ??= new ProductMapper(
            $this->variant_mapper(),
            $this->description_mapper(),
            $this->media_mapper(),
            $this->brand_resolver(),
            $this->settings(),
        );
    }

    public function variant_mapper(): VariantMapper
    {
        return $this->services[VariantMapper::class] ??= new VariantMapper(
            new PriceMapper($this->minor_units()),
            $this->description_mapper(),
            $this->media_mapper(),
            new AvailabilityMapper(),
            new CategoryMapper(),
            new BarcodeMapper(),
            new VariantOptionMapper(),
            $this->seller_mapper(),
            $this->settings(),
        );
    }

    public function seller_mapper(): SellerMapper
    {
        return $this->services[SellerMapper::class] ??= new SellerMapper($this->settings());
    }

    public function description_mapper(): DescriptionMapper
    {
        return $this->services[DescriptionMapper::class] ??= new DescriptionMapper();
    }

    public function media_mapper(): MediaMapper
    {
        return $this->services[MediaMapper::class] ??= new MediaMapper();
    }

    public function brand_resolver(): BrandResolver
    {
        return $this->services[BrandResolver::class] ??= new BrandResolver($this->settings());
    }

    public function product_validator(): ProductValidator
    {
        return $this->services[ProductValidator::class] ??= new ProductValidator();
    }

    // ───────────────────────── Services ─────────────────────────

    public function feed_service(): FeedService
    {
        return $this->services[FeedService::class] ??= new FeedService(
            $this->product_repo(),
            $this->product_mapper(),
            $this->product_validator(),
            $this->settings(),
            $this->seller_mapper(),
        );
    }

    public function delta_service(): DeltaService
    {
        return $this->services[DeltaService::class] ??= new DeltaService($this->change_log());
    }

    public function token_service(): TokenService
    {
        return $this->services[TokenService::class] ??= new TokenService($this->settings(), $this->cache());
    }

    public function health_service(): HealthService
    {
        return $this->services[HealthService::class] ??= new HealthService(
            $this->settings(),
            $this->product_repo(),
            $this->change_log(),
            $this->cache(),
            $this->seller_mapper(),
        );
    }

    // ───────────────────────── API ─────────────────────────

    public function rate_limiter(): RateLimiter
    {
        return $this->services[RateLimiter::class] ??= new RateLimiter($this->cache(), $this->settings());
    }

    public function auth(): Auth
    {
        return $this->services[Auth::class] ??= new Auth($this->token_service(), $this->rate_limiter(), $this->settings());
    }

    public function rest_routes(): RestRoutes
    {
        return $this->services[RestRoutes::class] ??= new RestRoutes(
            $this->auth(),
            new HealthController($this->health_service()),
            new FullFeedController($this->feed_service()),
            new ChangesController($this->delta_service()),
            new ItemController($this->feed_service()),
            new AckController($this->delta_service()),
        );
    }

    // ───────────────────────── Hooks ─────────────────────────

    public function woo_events(): WooEvents
    {
        return $this->services[WooEvents::class] ??= new WooEvents($this->change_log());
    }

    public function taxonomy_events(): TaxonomyEvents
    {
        return $this->services[TaxonomyEvents::class] ??= new TaxonomyEvents($this->change_log());
    }

    public function cron_jobs(): CronJobs
    {
        return $this->services[CronJobs::class] ??= new CronJobs(
            $this->settings(),
            $this->change_log(),
            $this->logger(),
        );
    }

    // ───────────────────────── Measurement ─────────────────────────

    public function measurement_settings(): MeasurementSettings
    {
        return $this->services[MeasurementSettings::class] ??= new MeasurementSettings();
    }

    public function event_data(): EventData
    {
        return $this->services[EventData::class] ??= new EventData($this->minor_units(), $this->measurement_settings());
    }

    public function consent(): Consent
    {
        return $this->services[Consent::class] ??= new Consent($this->measurement_settings());
    }

    public function conversions_api(): ConversionsApi
    {
        return $this->services[ConversionsApi::class] ??= new ConversionsApi($this->measurement_settings());
    }

    public function pixel_frontend(): PixelFrontend
    {
        return $this->services[PixelFrontend::class] ??= new PixelFrontend($this->measurement_settings(), $this->event_data());
    }

    public function order_attribution(): OrderAttribution
    {
        return $this->services[OrderAttribution::class] ??= new OrderAttribution($this->measurement_settings(), $this->consent());
    }

    public function server_events(): ServerEvents
    {
        return $this->services[ServerEvents::class] ??= new ServerEvents(
            $this->measurement_settings(),
            $this->conversions_api(),
            $this->event_data(),
        );
    }

    // ───────────────────────── Admin ─────────────────────────

    public function settings_page(): SettingsPage
    {
        return $this->services[SettingsPage::class] ??= new SettingsPage(
            $this->token_service(),
            $this->settings(),
            $this->health_service(),
            $this->change_log(),
            new PixelPage($this->measurement_settings(), $this->conversions_api(), $this->settings()),
            new ReviewPrompt($this->settings(), $this->measurement_settings()),
        );
    }

    public function notices(): Notices
    {
        return $this->services[Notices::class] ??= new Notices($this->settings());
    }

}
