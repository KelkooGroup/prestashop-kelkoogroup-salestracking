<?php
/**
 * Kelkoo Group Sales Tracking - official PrestaShop module.
 *
 * @author    Kelkoo Group
 * @copyright Kelkoo Group
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class KelkoogroupSalesTracking extends Module
{
    // One campaign per row ({country, merchantId}), JSON-encoded - most shops
    // have exactly one row, some have several (see getContent()/getCampaigns()).
    const CONFIG_CAMPAIGNS = 'KELKOOGROUPSALESTRACKING_CAMPAIGNS';

    const TRACKED_ORDER_TABLE = 'kst_tracked_order';

    // Unset on merchant shops, so they always hit production. Kelkoo Group's
    // test shops set it to send the tags and the server2server call to a
    // non-production tracking server, which serves the same scripts under
    // /assets/javascripts.
    const BASE_URL_ENV = 'KELKOOGROUP_SALESTRACKING_BASE_URL';

    public function __construct()
    {
        $this->name = 'kelkoogroupsalestracking';
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'Kelkoo Group';
        $this->need_instance = 0;
        $this->bootstrap = true;
        // Primary target is PrestaShop 9.x, with 1.7 and 8.x kept as
        // supported back-compat versions (1.6 dropped: EOL, PHP 5.6-7.1, no
        // public install guide). Being a module rather than a copy-pasted
        // script, it isn't bound by what the public 1.7 install guide tells
        // merchants to paste (the legacy s.js tag, svn1/svn2 fields) - it
        // emits the current basket shape on every supported version.
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Kelkoo Group Sales Tracking');
        $this->description = $this->l('Official Kelkoo Group sales tracking integration for PrestaShop.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Kelkoo Group Sales Tracking?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayOrderConfirmation')
            && $this->createTrackedOrderTable();
    }

    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TRACKED_ORDER_TABLE . '`');

        Configuration::deleteByName(self::CONFIG_CAMPAIGNS);

        return parent::uninstall();
    }

    /**
     * One row per order we've already sent, so a re-render of the
     * confirmation page (or any other reason hookDisplayOrderConfirmation
     * could fire twice for the same order) doesn't send the sale twice.
     */
    private function createTrackedOrderTable()
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TRACKED_ORDER_TABLE . '` (
                `id_order` INT UNSIGNED NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_order`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8'
        );
    }

    /**
     * Admin configuration screen: one row per Kelkoo campaign (most shops
     * have exactly one), added/removed from the page without a reload.
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitKelkoogroupSalesTracking')) {
            $countries = Tools::getValue('kst_country', []);
            $merchantIds = Tools::getValue('kst_merchant_id', []);
            $campaigns = [];
            $errors = [];

            foreach ($merchantIds as $i => $merchantId) {
                $merchantId = trim((string) $merchantId);
                if ($merchantId === '') {
                    continue;
                }
                $country = strtolower(trim((string) (isset($countries[$i]) ? $countries[$i] : '')));
                $errors = array_merge($errors, $this->validateCampaign($country, $merchantId));
                $campaigns[] = ['country' => $country, 'merchantId' => $merchantId];
            }

            if ($errors) {
                // Nothing saved: the form comes back with what was typed, to fix.
                return $this->displayError(implode('<br>', $errors)) . $this->renderForm($campaigns);
            }

            Configuration::updateValue(self::CONFIG_CAMPAIGNS, json_encode($campaigns));
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        return $output . $this->renderForm();
    }

    /**
     * Merchant identifiers (comId) are numeric. The country is optional -
     * the tracking server can tell the campaign from the merchant id alone -
     * but when given it must be a 2-letter code like the help text lists.
     *
     * @return string[] error messages, empty when the campaign is valid
     */
    private function validateCampaign($country, $merchantId)
    {
        $errors = [];

        if (!preg_match('/^\d+$/', $merchantId)) {
            $errors[] = sprintf($this->l('"%s" is not a valid Merchant Identifier: digits only.'), $this->escapeHtml($merchantId));
        }
        if ($country !== '' && !preg_match('/^[a-z]{2}$/', $country)) {
            $errors[] = sprintf($this->l('"%s" is not a valid country: use its 2-letter code (fr, de...).'), $this->escapeHtml($country));
        }

        return $errors;
    }

    /**
     * Only the merchant's input needs escaping in these messages: l() already
     * returns HTML-escaped text, so escaping the whole message again would
     * print &quot; instead of ".
     */
    private function escapeHtml($value)
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Own template instead of PrestaShop's HelperForm: this module's form
     * doesn't need HelperForm's tab/multi-shop machinery, and HelperForm
     * doesn't have a repeatable-row field type to reuse here anyway.
     */
    protected function renderForm(?array $campaigns = null)
    {
        if ($campaigns === null) {
            $campaigns = $this->getCampaigns();
        }
        if (empty($campaigns)) {
            $campaigns = [['country' => '', 'merchantId' => '']];
        }

        $this->context->smarty->assign([
            'kst_action_url' => $_SERVER['REQUEST_URI'] ?? '',
            'kst_campaigns' => $campaigns,
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }

    /**
     * Lead tag, injected on every front page, plus capturing kelkooId/gclid/
     * msclkid from the URL (merchants link to the shop with these) so
     * hookDisplayOrderConfirmation can still read them at checkout time.
     */
    public function hookDisplayHeader($params)
    {
        $this->storeIdentifiersFromUrl();

        $this->context->smarty->assign('kkstrackLeadTagUrl', $this->getScriptUrl('leadtag.js'));

        return $this->display(__FILE__, 'views/templates/hook/header.tpl');
    }

    /**
     * Conversion tag (client ks.js) + server2server call, fired on the order
     * confirmation page - the PrestaShop equivalent of WooCommerce's
     * woocommerce_thankyou hook. Guarded against being rendered more than
     * once per order for the same reason WooCommerce needs its own guard:
     * a customer can reload or revisit this page after the sale already
     * fired.
     */
    public function hookDisplayOrderConfirmation($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;

        if (!$order || empty($order->id) || !$this->hasMerchantIdentifierConfigured()) {
            return '';
        }

        if (!$this->markOrderAsTrackedIfNew((int) $order->id)) {
            return '';
        }

        $sale = $this->buildSale(array_merge([$order], $order->getBrother()->getResults()));
        $campaigns = $this->getCampaigns();
        $saleId = $this->generateSaleId();
        $identifiers = $this->getClickIdentifiers();

        foreach ($campaigns as $campaign) {
            $url = $this->buildTrackingUrl(array_merge($identifiers, $sale, [
                'orderId' => (int) $order->id,
                'country' => $campaign['country'],
                'merchantId' => $campaign['merchantId'],
                'saleId' => $saleId,
            ]));

            $this->sendServerToServerCall($url);
        }

        $kkstrack = array_filter(array_merge($sale, [
            'merchantInfo' => $campaigns,
            'orderId' => (string) $order->id,
        ]), function ($value) {
            return $value !== null;
        });

        $this->context->smarty->assign([
            'kkstrack' => $this->jsonForInlineScript($kkstrack),
            'kkstrackConversionTagUrl' => $this->getScriptUrl('ks.js'),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order-confirmation.tpl');
    }

    /**
     * JSON printed with nofilter inside order-confirmation.tpl's <script>:
     * escapes < > & ' " as \uXXXX, so a product name or merchant id holding
     * "</script>" can't close the tag and inject markup. Same value once
     * parsed by the browser.
     */
    public function jsonForInlineScript($value)
    {
        return $this->encodeJson($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * json_encode() with amounts written as typed (19.9, not
     * 19.899999999999999): it follows the serialize_precision ini setting,
     * which the official PrestaShop Docker image and many hosts set to 17.
     * -1 is the shortest form that reads back as the same float.
     */
    public function encodeJson($value, $flags = 0)
    {
        $serializePrecision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        $json = json_encode($value, $flags);
        ini_set('serialize_precision', $serializePrecision);

        return $json;
    }

    /**
     * True once at least one campaign has a merchantId configured. Without
     * this, sending would just hit /st with an empty comId.
     */
    public function hasMerchantIdentifierConfigured()
    {
        return !empty($this->getCampaigns());
    }

    /**
     * The configured campaigns, each a {country, merchantId} pair - rows
     * with no merchantId are already dropped in getContent() on save, so
     * every campaign returned here is usable.
     */
    public function getCampaigns()
    {
        $decoded = json_decode((string) Configuration::get(self::CONFIG_CAMPAIGNS), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One sale for the whole cart. PrestaShop splits a cart into several
     * orders (one per carrier/warehouse, sharing the same reference) but
     * shows a single confirmation page, whose hook only gets the first one:
     * $orders is that order plus Order::getBrother(), and the sale covers
     * all of them (same total as the public manual install's
     * getOrdersTotalPaid()). orderId stays the first order's id.
     *
     * Keys are the KST field names, shared by the conversion tag's _kkstrack
     * and the server2server call. Amounts are tax included, like orderValue.
     * coupon/returningUser are null when there is nothing to send.
     */
    public function buildSale(array $orders)
    {
        $orderValue = 0.0;
        $vatAmount = 0.0;
        $shippingCost = 0.0;
        $discountAmount = 0.0;
        $products = [];
        $coupons = [];

        foreach ($orders as $order) {
            $orderValue += (float) $order->total_paid;
            $vatAmount += (float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl;
            $shippingCost += (float) $order->total_shipping_tax_incl;
            $discountAmount += (float) $order->total_discounts_tax_incl;
            $products = array_merge($products, $order->getProducts());
            $coupons = array_merge($coupons, $this->getCouponCodes($order));
        }

        $first = $orders[0];
        $currency = new Currency((int) $first->id_currency);

        return [
            'orderValue' => round($orderValue, 2),
            'basket' => $this->buildBasket($products),
            'currency' => $currency->iso_code,
            'vatAmount' => round($vatAmount, 2),
            'shippingCost' => round($shippingCost, 2),
            'discountAmount' => round($discountAmount, 2),
            'coupon' => $coupons ? implode(',', array_unique($coupons)) : null,
            'returningUser' => $this->isReturningCustomer($first->getCustomer(), count($orders)),
        ];
    }

    /**
     * Codes of the vouchers applied to $order. Cart rules applied
     * automatically have no code, so they are a discount but not a coupon
     * (nor is a cart rule deleted since, which loads with a null code).
     */
    private function getCouponCodes($order)
    {
        $codes = [];

        foreach ($order->getCartRules() as $orderCartRule) {
            $cartRule = new CartRule((int) $orderCartRule['id_cart_rule']);
            $code = (string) $cartRule->code;
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * 'true'/'false' as ks.js expects, or null for guest checkouts: a guest
     * gets a new customer account per order, so we can't tell. $cartOrders
     * (this cart's own orders) are already counted by getCustomerNbOrders().
     */
    private function isReturningCustomer($customer, $cartOrders)
    {
        if ($customer->is_guest) {
            return null;
        }

        return Order::getCustomerNbOrders((int) $customer->id) > $cartOrders ? 'true' : 'false';
    }

    /**
     * Maps PrestaShop's Order::getProducts() rows to the current,
     * non-deprecated basket shape (productid/productname/price/quantity -
     * NOT svn1/svn2 or prQty/prPrice, see the constructor comment).
     */
    public function buildBasket(array $orderProducts)
    {
        $basket = [];

        foreach ($orderProducts as $product) {
            $productId = '';
            if (!empty($product['product_reference'])) {
                $productId = $product['product_reference'];
            } elseif (!empty($product['id_product'])) {
                $productId = $product['id_product'];
            }

            $basket[] = [
                'productid' => (string) $productId,
                'productname' => isset($product['product_name']) ? $product['product_name'] : '',
                'price' => (float) (isset($product['unit_price_tax_incl']) ? $product['unit_price_tax_incl'] : 0),
                'quantity' => (int) (isset($product['product_quantity']) ? $product['product_quantity'] : 0),
            ];
        }

        return $basket;
    }

    /**
     * Inserts a dedup row for $orderId if it isn't there yet. Uses
     * INSERT IGNORE on the table's primary key rather than a separate
     * SELECT-then-INSERT, so there's no race window between the two (unlike
     * the WooCommerce plugin's order-meta check-then-set).
     *
     * @return bool true if this call newly tracked the order (proceed),
     *              false if it was already tracked (skip).
     */
    public function markOrderAsTrackedIfNew($orderId)
    {
        Db::getInstance()->insert(
            self::TRACKED_ORDER_TABLE,
            ['id_order' => (int) $orderId, 'date_add' => date('Y-m-d H:i:s')],
            false,
            true,
            Db::INSERT_IGNORE
        );

        return Db::getInstance()->Affected_rows() > 0;
    }

    /**
     * URL of a KST script (leadtag.js, ks.js): the production CDN, or the
     * tracking server named by KELKOOGROUP_SALESTRACKING_BASE_URL.
     */
    public function getScriptUrl($script)
    {
        $baseUrl = $this->getBaseUrlOverride();

        return $baseUrl
            ? $baseUrl . '/assets/javascripts/' . $script
            : 'https://s.kk-resources.com/' . $script;
    }

    private function getBaseUrlOverride()
    {
        $baseUrl = getenv(self::BASE_URL_ENV);

        return $baseUrl ? rtrim($baseUrl, '/') : null;
    }

    /**
     * Builds the https://s.kelkoogroup.net/st URL for the server2server
     * call. $params: orderId, orderValue, country, merchantId, basket
     * (array, will be encoded), saleId, the optional fields of buildSale(),
     * kelkooId, gclid, msclkid.
     */
    public function buildTrackingUrl(array $params)
    {
        $basket = isset($params['basket']) ? $params['basket'] : [];

        $query = [
            'country' => isset($params['country']) ? $params['country'] : null,
            'orderId' => isset($params['orderId']) ? $params['orderId'] : null,
            'comId' => isset($params['merchantId']) ? $params['merchantId'] : null,
            'orderValue' => isset($params['orderValue']) ? $params['orderValue'] : null,
            'productsInfos' => $this->encodeBasket($basket),
            'saleId' => isset($params['saleId']) ? $params['saleId'] : null,
            'currency' => isset($params['currency']) ? $params['currency'] : null,
            'vatAmount' => isset($params['vatAmount']) ? $params['vatAmount'] : null,
            'shippingCost' => isset($params['shippingCost']) ? $params['shippingCost'] : null,
            'discountAmount' => isset($params['discountAmount']) ? $params['discountAmount'] : null,
            'coupon' => isset($params['coupon']) ? $params['coupon'] : null,
            'returningUser' => isset($params['returningUser']) ? $params['returningUser'] : null,
            'kelkooId' => isset($params['kelkooId']) ? $params['kelkooId'] : null,
            'gclid' => isset($params['gclid']) ? $params['gclid'] : null,
            'msclkid' => isset($params['msclkid']) ? $params['msclkid'] : null,
            'source' => 'serverToServer',
            'ecommercePlatform' => 'prestashop',
            'plgVersion' => $this->version,
        ];

        $query = array_filter($query, function ($value) {
            return $value !== null && $value !== '';
        });

        $baseUrl = $this->getBaseUrlOverride() ?: 'https://s.kelkoogroup.net';

        return $baseUrl . '/st?' . http_build_query($query);
    }

    /**
     * Standard base64 alphabet, like ks.js's btoa(): the tracking server
     * rejects the URL-safe '-' and '_' and then drops the whole basket.
     * urlencode() although http_build_query() encodes it again: the tracking
     * server URL-decodes the parameter twice, which would otherwise turn a
     * '+' into a space.
     */
    public function encodeBasket(array $basket)
    {
        return urlencode(base64_encode($this->encodeJson($basket)));
    }

    public function generateSaleId()
    {
        return mt_rand() / mt_getrandmax();
    }

    /**
     * kelkooId/gclid/msclkid, read back from the cookie(s)
     * hookDisplayHeader() stores them in via storeIdentifiersFromUrl().
     * Re-validated here too (defence in depth, not just on the way in).
     */
    public function getClickIdentifiers()
    {
        $cookie = Context::getContext()->cookie;

        return [
            'kelkooId' => $this->sanitizeIdentifier(isset($cookie->kelkooId) ? $cookie->kelkooId : null),
            'gclid' => $this->sanitizeIdentifier(isset($cookie->gclid) ? $cookie->gclid : null),
            'msclkid' => $this->sanitizeIdentifier(isset($cookie->msclkid) ? $cookie->msclkid : null),
        ];
    }

    public function storeIdentifiersFromUrl()
    {
        $parameters = [
            'kk' => 'kelkooId',
            'gclid' => 'gclid',
            'kgclid' => 'gclid',
            'msclkid' => 'msclkid',
        ];

        $cookie = Context::getContext()->cookie;

        foreach ($parameters as $queryParam => $cookieKey) {
            if (isset($_GET[$queryParam])) {
                $sanitized = $this->sanitizeIdentifier($_GET[$queryParam]);
                if ($sanitized !== null) {
                    $cookie->$cookieKey = $sanitized;
                }
            }
        }

        $cookie->write();
    }

    /**
     * Rejects anything that isn't a plausible click id: these values come
     * from the URL or a cookie (attacker-controlled) and get query-string
     * concatenated in buildTrackingUrl() - reject anything that could
     * inject extra query parameters into the tracking request.
     */
    public function sanitizeIdentifier($value)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return preg_match('/^[\w.-]+$/', $value) ? $value : null;
    }

    /**
     * Bounded-wait (not truly async) HTTP GET, same trade-off as the
     * WooCommerce plugin's wp_remote_get(blocking:false, timeout:0.7): a
     * short timeout keeps checkout fast without needing a job queue.
     */
    public function sendServerToServerCall($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 700,
            CURLOPT_CONNECTTIMEOUT_MS => 300,
            CURLOPT_HTTPHEADER => ['Referer: ' . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '')],
        ]);
        curl_exec($ch);
        // No curl_close(): deprecated on PHP 8.5 (a notice in debug mode),
        // and the handle is freed on return on every PHP version anyway.
    }
}
