<?php

use PHPUnit\Framework\TestCase;

class KelkoogroupSalesTrackingTest extends TestCase
{
    protected function setUp(): void
    {
        Configuration::reset();
        Context::reset();
        Db::getInstance()->reset();
        Order::reset();
        CartRule::$codes = [];
        unset($_GET, $_POST);
        $_GET = [];
        $_POST = [];
        putenv('KELKOOGROUP_SALESTRACKING_BASE_URL');
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('KelkoogroupSalesTracking'));
    }

    public function testModuleMetadata(): void
    {
        $module = new KelkoogroupSalesTracking();

        $this->assertSame('kelkoogroupsalestracking', $module->name);
        $this->assertSame('advertising_marketing', $module->tab);
        $this->assertNotEmpty($module->displayName);
    }

    public function testSupportsPrestashop17AndAbove(): void
    {
        $module = new KelkoogroupSalesTracking();

        $this->assertSame('1.7', $module->ps_versions_compliancy['min']);
    }

    public function testInstallRegistersExpectedHooks(): void
    {
        $module = new KelkoogroupSalesTracking();
        $module->install();

        $this->assertContains('displayHeader', $module->getRegisteredHooks());
        $this->assertContains('displayOrderConfirmation', $module->getRegisteredHooks());
    }

    public function testUninstallClearsConfiguration(): void
    {
        Configuration::updateValue('KELKOOGROUPSALESTRACKING_CAMPAIGNS', '[{"country":"fr","merchantId":"123"}]');

        $module = new KelkoogroupSalesTracking();
        $module->uninstall();

        $this->assertFalse(Configuration::get('KELKOOGROUPSALESTRACKING_CAMPAIGNS'));
    }

    public function testHookMethodsExist(): void
    {
        $module = new KelkoogroupSalesTracking();

        $this->assertTrue(method_exists($module, 'hookDisplayHeader'));
        $this->assertTrue(method_exists($module, 'hookDisplayOrderConfirmation'));
    }

    public function testGetContentRendersSettingsForm(): void
    {
        $module = new KelkoogroupSalesTracking();

        $html = $module->getContent();

        $this->assertStringContainsString('kst_country[]', $html);
        $this->assertStringContainsString('kst_merchant_id[]', $html);
        $this->assertStringContainsString('kst-add-campaign', $html);
        $this->assertStringContainsString('Merchant Identifier', $html);
    }

    public function testGetContentSavesSubmittedCampaigns(): void
    {
        $_POST['submitKelkoogroupSalesTracking'] = '1';
        $_POST['kst_country'] = ['fr', 'be'];
        $_POST['kst_merchant_id'] = ['123', '456'];

        $module = new KelkoogroupSalesTracking();
        $html = $module->getContent();

        $this->assertSame(
            [
                ['country' => 'fr', 'merchantId' => '123'],
                ['country' => 'be', 'merchantId' => '456'],
            ],
            $module->getCampaigns()
        );
        $this->assertStringContainsString('alert-success', $html);
    }

    public function testGetContentDropsRowsWithoutMerchantId(): void
    {
        $_POST['submitKelkoogroupSalesTracking'] = '1';
        $_POST['kst_country'] = ['fr', 'be'];
        $_POST['kst_merchant_id'] = ['123', '  '];

        $module = new KelkoogroupSalesTracking();
        $module->getContent();

        $this->assertSame([['country' => 'fr', 'merchantId' => '123']], $module->getCampaigns());
    }

    public function testGetContentNormalizesCountryAndAcceptsNoCountry(): void
    {
        $_POST['submitKelkoogroupSalesTracking'] = '1';
        $_POST['kst_country'] = [' FR ', ''];
        $_POST['kst_merchant_id'] = ['123', '456'];

        $module = new KelkoogroupSalesTracking();
        $module->getContent();

        $this->assertSame(
            [['country' => 'fr', 'merchantId' => '123'], ['country' => '', 'merchantId' => '456']],
            $module->getCampaigns()
        );
    }

    public function testGetContentRejectsInvalidCampaignsWithoutSaving(): void
    {
        Configuration::updateValue('KELKOOGROUPSALESTRACKING_CAMPAIGNS', json_encode([['country' => 'fr', 'merchantId' => '123']]));
        $_POST['submitKelkoogroupSalesTracking'] = '1';
        $_POST['kst_country'] = ['france', 'de'];
        $_POST['kst_merchant_id'] = ['123', '<b>abc</b>'];

        $module = new KelkoogroupSalesTracking();
        $html = $module->getContent();

        $this->assertSame([['country' => 'fr', 'merchantId' => '123']], $module->getCampaigns());
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('&quot;france&quot; is not a valid country', $html);
        $this->assertStringContainsString('&lt;b&gt;abc&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>abc</b>', $html);
        // Escaped once: l() already escapes the translated text.
        $this->assertStringNotContainsString('&amp;', $html);
        // The form shows what was typed, to fix it.
        $this->assertStringContainsString('value="france"', $html);
    }

    public function testBuildBasketMapsOrderProductsToCurrentShape(): void
    {
        $module = new KelkoogroupSalesTracking();

        $basket = $module->buildBasket([
            [
                'product_name' => 'Product 1',
                'product_reference' => 'SKU-1',
                'product_quantity' => '2',
                'unit_price_tax_incl' => '10.50',
            ],
        ]);

        $this->assertSame([
            [
                'productid' => 'SKU-1',
                'productname' => 'Product 1',
                'price' => 10.5,
                'quantity' => 2,
            ],
        ], $basket);
    }

    public function testBuildSaleCoversEveryOrderOfASplitCart(): void
    {
        $module = new KelkoogroupSalesTracking();
        $first = new Order(['id' => 10, 'total_paid' => 30.1], [
            ['product_reference' => 'A', 'product_name' => 'Shirt', 'unit_price_tax_incl' => 25.1, 'product_quantity' => 1],
        ]);
        $second = new Order(['id' => 11, 'total_paid' => 20.2], [
            ['product_reference' => 'B', 'product_name' => 'Sofa', 'unit_price_tax_incl' => 15.2, 'product_quantity' => 1],
        ]);

        $sale = $module->buildSale([$first, $second]);

        $this->assertSame(50.3, $sale['orderValue']);
        $this->assertSame(['A', 'B'], array_column($sale['basket'], 'productid'));
    }

    public function testBuildSaleFillsOptionalFieldsForTheWholeCart(): void
    {
        CartRule::$codes = [5 => 'WELCOME10', 6 => '', 7 => 'FREESHIP'];
        Order::$customerNbOrders = [42 => 3];
        $customer = new Customer(['id' => 42]);
        $module = new KelkoogroupSalesTracking();

        $sale = $module->buildSale([
            new Order([
                'id_currency' => 2,
                'total_paid_tax_incl' => 30.1, 'total_paid_tax_excl' => 25.1,
                'total_shipping_tax_incl' => 4.9, 'total_discounts_tax_incl' => 10,
                'customer' => $customer,
                'cartRules' => [['id_cart_rule' => 5], ['id_cart_rule' => 6], ['id_cart_rule' => 8]],
            ]),
            new Order([
                'total_paid_tax_incl' => 20.2, 'total_paid_tax_excl' => 17,
                'total_shipping_tax_incl' => 0, 'total_discounts_tax_incl' => 2.5,
                'customer' => $customer,
                'cartRules' => [['id_cart_rule' => 5], ['id_cart_rule' => 7]],
            ]),
        ]);

        $this->assertSame('USD', $sale['currency']);
        $this->assertSame(8.2, $sale['vatAmount']);
        $this->assertSame(4.9, $sale['shippingCost']);
        $this->assertSame(12.5, $sale['discountAmount']);
        $this->assertSame('WELCOME10,FREESHIP', $sale['coupon']);
        // 3 orders, 2 of them this cart's: one earlier order.
        $this->assertSame('true', $sale['returningUser']);
    }

    public function testBuildSaleReturningUser(): void
    {
        $module = new KelkoogroupSalesTracking();
        Order::$customerNbOrders = [42 => 1];

        $firstOrder = $module->buildSale([new Order(['customer' => new Customer(['id' => 42])])]);
        $guestOrder = $module->buildSale([new Order(['customer' => new Customer(['id' => 42, 'is_guest' => true])])]);

        $this->assertSame('false', $firstOrder['returningUser']);
        $this->assertNull($guestOrder['returningUser']);
        $this->assertNull($firstOrder['coupon']);
    }

    public function testBuildTrackingUrlSendsOptionalFields(): void
    {
        $module = new KelkoogroupSalesTracking();

        parse_str(parse_url($module->buildTrackingUrl([
            'currency' => 'EUR', 'vatAmount' => 5.0, 'shippingCost' => 0.0, 'discountAmount' => 2.5,
            'coupon' => 'WELCOME10', 'returningUser' => 'false',
        ]), PHP_URL_QUERY), $params);

        $this->assertSame('EUR', $params['currency']);
        $this->assertSame('5', $params['vatAmount']);
        $this->assertSame('0', $params['shippingCost']);
        $this->assertSame('2.5', $params['discountAmount']);
        $this->assertSame('WELCOME10', $params['coupon']);
        $this->assertSame('false', $params['returningUser']);
    }

    public function testBuildBasketFallsBackToProductIdWhenNoReference(): void
    {
        $module = new KelkoogroupSalesTracking();

        $basket = $module->buildBasket([
            ['id_product' => 42, 'product_name' => 'No reference'],
        ]);

        $this->assertSame('42', $basket[0]['productid']);
    }

    /**
     * Decodes productsInfos the way the tracking server does: URL-decodes the
     * query string twice, then a strict standard-alphabet base64 decode.
     * '?', '>' and '~' make base64_encode() emit '+' or '/' depending on
     * their offset - hence the three product ids of different lengths.
     */
    public function testServerDecodesBasketWhateverItsCharacters(): void
    {
        $module = new KelkoogroupSalesTracking();

        foreach (['R', 'RR', 'RRR'] as $productId) {
            foreach (['Jeans 42?', 'Cable USB > 2m', 'T-shirt ~ promo', 'Bol à café'] as $productName) {
                $basket = [['productid' => $productId, 'productname' => $productName, 'price' => 9.9, 'quantity' => 1]];

                parse_str(parse_url($module->buildTrackingUrl(['basket' => $basket]), PHP_URL_QUERY), $params);
                $decoded = base64_decode(urldecode($params['productsInfos']), true);

                $this->assertNotFalse($decoded, "productsInfos for '$productName' is not standard base64");
                $this->assertSame($basket, json_decode($decoded, true));
            }
        }
    }

    public function testJsonForInlineScriptCannotCloseTheScriptTag(): void
    {
        $module = new KelkoogroupSalesTracking();
        $basket = [['productname' => '</script><script>alert("x") & \'y\'</script>']];

        $json = $module->jsonForInlineScript($basket);

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        $this->assertSame($basket, json_decode($json, true));
    }

    public function testAmountsAreEncodedAsTypedWhateverSerializePrecision(): void
    {
        $module = new KelkoogroupSalesTracking();
        $serializePrecision = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $json = $module->jsonForInlineScript(['orderValue' => 34.8, 'basket' => [['price' => 19.9]]]);
            parse_str(parse_url($module->buildTrackingUrl(['basket' => [['price' => 19.9]]]), PHP_URL_QUERY), $params);
            $basketJson = base64_decode(urldecode($params['productsInfos']));
            $restored = ini_get('serialize_precision');
        } finally {
            ini_set('serialize_precision', $serializePrecision);
        }

        $this->assertSame('{"orderValue":34.8,"basket":[{"price":19.9}]}', $json);
        $this->assertSame('[{"price":19.9}]', $basketJson);
        $this->assertSame('17', $restored);
    }

    public function testSanitizeIdentifierRejectsInjectionAttempts(): void
    {
        $module = new KelkoogroupSalesTracking();

        $this->assertSame('abc123-def.456', $module->sanitizeIdentifier('abc123-def.456'));
        $this->assertSame('0', $module->sanitizeIdentifier('0'));
        $this->assertNull($module->sanitizeIdentifier('abc&comId=999'));
        $this->assertNull($module->sanitizeIdentifier(null));
        $this->assertNull($module->sanitizeIdentifier(''));
    }

    public function testGenerateSaleIdReturnsFloatBetweenZeroAndOne(): void
    {
        $module = new KelkoogroupSalesTracking();

        $saleId = $module->generateSaleId();

        $this->assertIsFloat($saleId);
        $this->assertGreaterThanOrEqual(0, $saleId);
        $this->assertLessThanOrEqual(1, $saleId);
    }

    public function testScriptsAreLoadedFromProductionCdnByDefault(): void
    {
        $module = new KelkoogroupSalesTracking();

        $this->assertSame('https://s.kk-resources.com/leadtag.js', $module->getScriptUrl('leadtag.js'));
        $this->assertSame('https://s.kk-resources.com/ks.js', $module->getScriptUrl('ks.js'));
    }

    public function testBaseUrlOverrideRedirectsScriptsAndServerToServerCall(): void
    {
        putenv('KELKOOGROUP_SALESTRACKING_BASE_URL=https://tracking.example.test/');
        $module = new KelkoogroupSalesTracking();

        $this->assertSame(
            'https://tracking.example.test/assets/javascripts/ks.js',
            $module->getScriptUrl('ks.js')
        );
        $this->assertStringStartsWith(
            'https://tracking.example.test/st?',
            $module->buildTrackingUrl(['orderId' => 1, 'country' => 'fr', 'merchantId' => '789'])
        );
    }

    public function testBuildTrackingUrlContainsExpectedParams(): void
    {
        $module = new KelkoogroupSalesTracking();

        $url = $module->buildTrackingUrl([
            'orderId' => 123,
            'orderValue' => 45.6,
            'country' => 'fr',
            'merchantId' => '789',
            'basket' => [['productid' => '1', 'productname' => 'A', 'price' => 1, 'quantity' => 1]],
            'saleId' => '0.42',
            'kelkooId' => 'kk-id',
            'gclid' => null,
        ]);

        $this->assertStringStartsWith('https://s.kelkoogroup.net/st?', $url);

        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertSame('fr', $params['country']);
        $this->assertSame('123', $params['orderId']);
        $this->assertSame('789', $params['comId']);
        $this->assertSame('45.6', $params['orderValue']);
        $this->assertSame('kk-id', $params['kelkooId']);
        $this->assertSame('serverToServer', $params['source']);
        $this->assertSame('prestashop', $params['ecommercePlatform']);
        $this->assertArrayNotHasKey('gclid', $params);
    }

    public function testGetCampaignsDecodesStoredJson(): void
    {
        Configuration::updateValue(
            'KELKOOGROUPSALESTRACKING_CAMPAIGNS',
            json_encode([
                ['country' => 'nl', 'merchantId' => '111'],
                ['country' => 'be', 'merchantId' => '222'],
            ])
        );

        $module = new KelkoogroupSalesTracking();

        $this->assertSame(
            [
                ['country' => 'nl', 'merchantId' => '111'],
                ['country' => 'be', 'merchantId' => '222'],
            ],
            $module->getCampaigns()
        );
    }

    public function testGetCampaignsReturnsEmptyArrayWhenUnconfigured(): void
    {
        $module = new KelkoogroupSalesTracking();

        $this->assertSame([], $module->getCampaigns());
    }

    public function testHasMerchantIdentifierConfigured(): void
    {
        $module = new KelkoogroupSalesTracking();
        $this->assertFalse($module->hasMerchantIdentifierConfigured());

        Configuration::updateValue(
            'KELKOOGROUPSALESTRACKING_CAMPAIGNS',
            json_encode([['country' => 'fr', 'merchantId' => '123']])
        );
        $this->assertTrue($module->hasMerchantIdentifierConfigured());
    }

    public function testMarkOrderAsTrackedIfNewOnlyOnce(): void
    {
        $module = new KelkoogroupSalesTracking();

        $this->assertTrue($module->markOrderAsTrackedIfNew(42));
        $this->assertFalse($module->markOrderAsTrackedIfNew(42));
        $this->assertTrue($module->markOrderAsTrackedIfNew(43));
    }

    public function testStoreIdentifiersFromUrlThenGetClickIdentifiers(): void
    {
        $_GET['kk'] = 'kelkoo-123';
        $_GET['gclid'] = 'gclid-abc.def';
        $_GET['msclkid'] = 'bad&value=1';

        $module = new KelkoogroupSalesTracking();
        $module->storeIdentifiersFromUrl();

        $identifiers = $module->getClickIdentifiers();

        $this->assertSame('kelkoo-123', $identifiers['kelkooId']);
        $this->assertSame('gclid-abc.def', $identifiers['gclid']);
        $this->assertNull($identifiers['msclkid']);
    }

    public function testStoreIdentifiersFromUrlKgclidAliasesGclid(): void
    {
        $_GET['kgclid'] = 'kgclid-value';

        $module = new KelkoogroupSalesTracking();
        $module->storeIdentifiersFromUrl();

        $this->assertSame('kgclid-value', $module->getClickIdentifiers()['gclid']);
    }
}
