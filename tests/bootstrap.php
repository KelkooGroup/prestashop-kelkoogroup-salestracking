<?php
/**
 * Minimal PrestaShop core stubs so the module class can be unit-tested
 * without a running PrestaShop instance. Not a substitute for testing
 * against the real platform - this only exercises the module's own
 * logic (hook registration, config handling, dedup, basket mapping, ...).
 * Every stub exposes a reset()/static reset so tests can isolate state
 * even though the real PrestaShop APIs they mirror are static/singletons.
 */

require_once __DIR__ . '/../vendor/autoload.php';

define('_PS_VERSION_', '9.2.1');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');

class Module
{
    public $id = 1;
    public $name;
    public $tab;
    public $version;
    public $author;
    public $need_instance;
    public $bootstrap;
    public $ps_versions_compliancy;
    public $displayName;
    public $description;
    public $confirmUninstall;
    public $context;
    public $local_path;

    private $registeredHooks = [];

    public function __construct()
    {
        $this->local_path = dirname(__DIR__) . '/' . $this->name . '/';
        $this->context = Context::getContext();
    }

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    public function registerHook($hookName)
    {
        $this->registeredHooks[] = $hookName;

        return true;
    }

    public function getRegisteredHooks(): array
    {
        return $this->registeredHooks;
    }

    // Like PrestaShop's: translations come back HTML-escaped.
    public function l($string)
    {
        return htmlspecialchars($string, ENT_COMPAT, 'UTF-8');
    }

    public function display($file, $template)
    {
        return $template;
    }

    public function displayConfirmation($message)
    {
        return '<div class="alert alert-success">' . $message . '</div>';
    }

    public function displayError($message)
    {
        return '<div class="alert alert-danger">' . $message . '</div>';
    }
}

class Configuration
{
    private static $values = [];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
    }

    public static function updateValue($key, $value)
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function deleteByName($key)
    {
        unset(self::$values[$key]);

        return true;
    }

    public static function reset()
    {
        self::$values = [];
    }
}

class Cookie
{
    private $data = [];

    public function __get($key)
    {
        return $this->data[$key] ?? null;
    }

    public function __set($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function __isset($key)
    {
        return isset($this->data[$key]);
    }

    public function write()
    {
    }

    public function reset()
    {
        $this->data = [];
    }
}

class Context
{
    private static $instance;

    public $cookie;
    public $smarty;

    public static function getContext()
    {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->cookie = new Cookie();
            // Real Smarty, so tests render the module's actual templates;
            // only PrestaShop's {l} translation plugin is stubbed.
            self::$instance->smarty = new Smarty();
            self::$instance->smarty->setCompileDir(sys_get_temp_dir() . '/kst-smarty-compile');
            self::$instance->smarty->registerPlugin('function', 'l', function ($params) {
                return $params['s'];
            });
        }

        return self::$instance;
    }

    public static function reset()
    {
        self::$instance = null;
    }
}

class Tools
{
    public static function getValue($key, $default = false)
    {
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return $default;
    }

    public static function isSubmit($key)
    {
        return isset($_POST[$key]) || isset($_GET[$key]);
    }
}

/**
 * Stubs just enough of PrestaShop's Db class for the module's own dedup
 * table (see createTrackedOrderTable()/markOrderAsTrackedIfNew()): an
 * in-memory primary-key set standing in for "INSERT IGNORE on id_order".
 */
class Db
{
    const INSERT = 0;
    const INSERT_IGNORE = 1;

    private static $instance;
    private $insertedIds = [];
    private $affectedRows = 0;
    private $executedStatements = [];

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function execute($sql)
    {
        $this->executedStatements[] = $sql;

        return true;
    }

    public function insert($table, $data, $nullValues = false, $useCache = true, $type = self::INSERT)
    {
        if ($table === KelkoogroupSalesTracking::TRACKED_ORDER_TABLE) {
            $id = $data['id_order'];

            if (in_array($id, $this->insertedIds, true)) {
                $this->affectedRows = 0;

                return $type === self::INSERT_IGNORE;
            }

            $this->insertedIds[] = $id;
            $this->affectedRows = 1;

            return true;
        }

        $this->affectedRows = 1;

        return true;
    }

    public function Affected_rows()
    {
        return $this->affectedRows;
    }

    public function reset()
    {
        self::$instance = new self();
    }
}

/**
 * Stubs the few Order members the module reads. The real constructor loads
 * an order by id; this one takes its fields, products and "brothers" (the
 * other orders PrestaShop split the same cart into) directly.
 */
class Order
{
    /** id_customer => number of orders, for getCustomerNbOrders(). */
    public static $customerNbOrders = [];

    public $id;
    public $id_currency = 1;
    public $total_paid = 0;
    public $total_paid_tax_incl = 0;
    public $total_paid_tax_excl = 0;
    public $total_shipping_tax_incl = 0;
    public $total_discounts_tax_incl = 0;
    /** Customer returned by getCustomer(); a non-guest with no order if unset. */
    public $customer;
    /** order_cart_rule rows returned by getCartRules(). */
    public $cartRules = [];

    private $products;
    private $brothers;

    public function __construct(array $fields = [], array $products = [], array $brothers = [])
    {
        foreach ($fields as $name => $value) {
            $this->$name = $value;
        }
        $this->products = $products;
        $this->brothers = $brothers;
        $this->customer = $this->customer ?: new Customer(['id' => 1]);
    }

    public static function getCustomerNbOrders($idCustomer)
    {
        return self::$customerNbOrders[$idCustomer] ?? 0;
    }

    public static function reset()
    {
        self::$customerNbOrders = [];
    }

    public function getProducts()
    {
        return $this->products;
    }

    public function getCartRules()
    {
        return $this->cartRules;
    }

    public function getCustomer()
    {
        return $this->customer;
    }

    public function getBrother()
    {
        return new PrestaShopCollection($this->brothers);
    }
}

class Customer
{
    public $id;
    public $is_guest = false;

    public function __construct(array $fields = [])
    {
        foreach ($fields as $name => $value) {
            $this->$name = $value;
        }
    }
}

/** Every currency is EUR, except id 2 (USD). */
class Currency
{
    public $iso_code;

    public function __construct($id)
    {
        $this->iso_code = $id === 2 ? 'USD' : 'EUR';
    }
}

/** Cart rule codes by id, for new CartRule($id); an unknown id loads a null code. */
class CartRule
{
    public static $codes = [];

    public $code;

    public function __construct($id)
    {
        $this->code = self::$codes[$id] ?? null;
    }
}

class PrestaShopCollection
{
    private $results;

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function getResults()
    {
        return $this->results;
    }
}

require_once __DIR__ . '/../kelkoogroupsalestracking/kelkoogroupsalestracking.php';
