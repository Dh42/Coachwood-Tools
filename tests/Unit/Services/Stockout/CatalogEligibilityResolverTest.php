<?php

namespace Tests\Unit\Services\Stockout;

use App\Services\Stockout\CatalogEligibilityResolver;
use App\Services\Stockout\ShopifyGraphQlClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CatalogEligibilityResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stockout.shopify.store' => 'shopify-test.myshopify.com',
            'stockout.shopify.access_token' => 'test-token',
            'stockout.api_version' => '2026-07',
            'stockout.excluded_product_titles' => ['RTD Sample Box'],
            'stockout.excluded_variant_ids' => ['gid://shopify/ProductVariant/excluded'],
            'stockout.excluded_product_types' => ['Merchandise', 'Hoodie'],
        ]);
    }

    private function product(array $overrides = []): array
    {
        return array_merge([
            'title' => 'BIOSTEEL HOODIE',
            'status' => 'ACTIVE',
            'productType' => 'Sports Hydration Mix',
            'onlineStoreUrl' => 'https://biosteel.ca/products/hoodie',
            'variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU-1', 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]],
            ]],
        ], $overrides);
    }

    private function page(array $products, bool $hasNextPage = false, string $cursor = ''): array
    {
        return ['data' => ['products' => [
            'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $cursor],
            'nodes' => $products,
        ]]];
    }

    public function test_an_eligible_single_variant_product_is_included(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([$this->product()]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertArrayHasKey('biosteel hoodie', $map);
        $this->assertSame([['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU-1']], $map['biosteel hoodie']['variants']);
    }

    public function test_a_product_not_published_to_the_online_store_is_excluded(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([
            $this->product(['onlineStoreUrl' => null]),
        ]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertArrayNotHasKey('biosteel hoodie', $map);
    }

    public function test_a_continue_policy_variant_is_excluded_from_its_product(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([
            $this->product(['variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'inventoryPolicy' => 'CONTINUE', 'inventoryItem' => ['tracked' => true]],
            ]]]),
        ]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertArrayNotHasKey('biosteel hoodie', $map);
    }

    public function test_an_untracked_variant_is_excluded_from_its_product(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([
            $this->product(['variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => false]],
            ]]]),
        ]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertArrayNotHasKey('biosteel hoodie', $map);
    }

    public function test_an_explicitly_excluded_variant_id_is_dropped_but_siblings_remain(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([
            $this->product(['variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/excluded', 'sku' => 'SKU-EX', 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]],
                ['id' => 'gid://shopify/ProductVariant/2', 'sku' => 'SKU-2', 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]],
            ]]]),
        ]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertSame([['id' => 'gid://shopify/ProductVariant/2', 'sku' => 'SKU-2']], $map['biosteel hoodie']['variants']);
    }

    public function test_a_variant_with_no_sku_maps_to_null(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([
            $this->product(['variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'sku' => '', 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]],
            ]]]),
        ]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertNull($map['biosteel hoodie']['variants'][0]['sku']);
    }

    public function test_an_excluded_product_type_is_dropped_case_insensitively(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([
            $this->product(['productType' => 'hoodie']),
        ]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertSame([], $map);
    }

    public function test_a_blank_product_type_is_not_excluded_by_the_merch_filter(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([
            $this->product(['productType' => '']),
        ]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertArrayHasKey('biosteel hoodie', $map);
    }

    public function test_an_excluded_product_title_is_dropped_case_insensitively(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response($this->page([
            $this->product(['title' => 'rtd sample box']),
        ]))]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertSame([], $map);
    }

    public function test_it_paginates_across_multiple_pages(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::sequence()
            ->push($this->page([$this->product(['title' => 'Product A'])], true, 'cursor-1'))
            ->push($this->page([$this->product(['title' => 'Product B'])])),
        ]);

        $map = (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();

        $this->assertArrayHasKey('product a', $map);
        $this->assertArrayHasKey('product b', $map);
        Http::assertSentCount(2);
    }

    public function test_a_shopify_error_throws(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response(['errors' => [['message' => 'boom']]])]);

        $this->expectException(RuntimeException::class);

        (new CatalogEligibilityResolver(app(ShopifyGraphQlClient::class)))->resolve();
    }
}
