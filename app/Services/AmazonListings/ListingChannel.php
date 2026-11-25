<?php

namespace App\Services\AmazonListings;

use App\Models\ListingAudit;
use App\Models\ListingAuditAttributeCatalog;
use App\Models\ListingAuditConfig;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Models\ScListingAudit;
use App\Models\ScListingAuditAttributeCatalog;
use App\Models\ScListingAuditConfig;
use App\Models\ScListingEdit;
use App\Models\ScProduct;

/**
 * Everything that genuinely differs between the Vendor Central and Seller
 * Central listing editors — marketplace, identity, and a couple of renamed
 * attribute keys for the same logical fields. Everything else (the generic
 * attribute engine, nutrition form building) is channel-agnostic on purpose.
 */
enum ListingChannel: string
{
    case Vendor = 'vendor';
    case Seller = 'seller';

    public function marketplaceId(): string
    {
        return match ($this) {
            self::Vendor => config('services.sp_api.vendor_marketplace_id'),
            self::Seller => config('services.sp_api.seller_marketplace_id'),
        };
    }

    /** The path-segment identity SP-API expects: vendor code or seller (merchant) ID. */
    public function identityCode(): string
    {
        return match ($this) {
            self::Vendor => config('services.sp_api.vendor_code'),
            self::Seller => config('services.sp_api.seller_id'),
        };
    }

    public function refreshToken(): string
    {
        return match ($this) {
            self::Vendor => config('services.sp_api.vendor_refresh_token'),
            self::Seller => config('services.sp_api.seller_refresh_token'),
        };
    }

    /** Catalog Items' vendorDetails 400s for a seller-scoped token — only ever request it for Vendor. */
    public function includeVendorDetails(): bool
    {
        return $this === self::Vendor;
    }

    /** Confirmed live A+ Content type name differs per channel for the same concept. */
    public function realAplusContentType(): string
    {
        return match ($this) {
            self::Vendor => 'EMC',
            self::Seller => 'EBC',
        };
    }

    /** Same logical field, different attribute key per channel. */
    public function descriptionAttributeKey(): string
    {
        return match ($this) {
            self::Vendor => 'rtip_product_description',
            self::Seller => 'product_description',
        };
    }

    public function externalIdAttributeKey(): string
    {
        return match ($this) {
            self::Vendor => 'external_product_id',
            self::Seller => 'externally_assigned_product_identifier',
        };
    }

    public function multiEntryAttributes(): array
    {
        return ['bullet_point', $this->externalIdAttributeKey(), 'generic_keyword', 'generic_keywords'];
    }

    public function topSectionAttributes(): array
    {
        return ['item_name', 'bullet_point', 'title_differentiation', $this->descriptionAttributeKey(), 'nutritional_info', $this->externalIdAttributeKey()];
    }

    /**
     * @return class-string<Product|ScProduct>
     */
    public function productModel(): string
    {
        return match ($this) {
            self::Vendor => Product::class,
            self::Seller => ScProduct::class,
        };
    }

    /** @return class-string<ListingEdit|ScListingEdit> */
    public function listingEditModel(): string
    {
        return match ($this) {
            self::Vendor => ListingEdit::class,
            self::Seller => ScListingEdit::class,
        };
    }

    /** @return class-string<ListingAudit|ScListingAudit> */
    public function listingAuditModel(): string
    {
        return match ($this) {
            self::Vendor => ListingAudit::class,
            self::Seller => ScListingAudit::class,
        };
    }

    /** @return class-string<ListingAuditConfig|ScListingAuditConfig> */
    public function listingAuditConfigModel(): string
    {
        return match ($this) {
            self::Vendor => ListingAuditConfig::class,
            self::Seller => ScListingAuditConfig::class,
        };
    }

    /** @return class-string<ListingAuditAttributeCatalog|ScListingAuditAttributeCatalog> */
    public function attributeCatalogModel(): string
    {
        return match ($this) {
            self::Vendor => ListingAuditAttributeCatalog::class,
            self::Seller => ScListingAuditAttributeCatalog::class,
        };
    }
}
