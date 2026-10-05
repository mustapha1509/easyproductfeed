<?php
/**
 * Builds a Meta (Facebook / Instagram) product feed for one profile.
 *
 * Profile types (matches Meta's "primary + localized override feeds" model):
 *  - primary  : full catalogue feed
 *  - language : override feed (id, override, title, description)
 *  - country  : override feed (id, override, price, sale_price, availability, link)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EasyProductFeedGenerator
{
    const TABLE_PROFILE = 'easyproductfeed_profile';
    const TABLE_CATEGORY = 'easyproductfeed_category';

    /** @var array */
    private $profile;
    /** @var array */
    private $settings;
    /** @var int */
    private $idShop;
    /** @var int */
    private $idLang;
    /** @var int */
    private $idCurrency;
    /** @var int */
    private $idCountry;
    /** @var Currency */
    private $currency;
    /** @var Link */
    private $link;
    /** @var string 'https://' or 'http://' */
    private $scheme = 'https://';
    /** @var int products examined (to tell "no products" from "all skipped") */
    private $seen = 0;
    /** @var int */
    private $precision = 2;
    /** @var string */
    private $imageType = null;
    /** @var array */
    private $stats = array('skipped' => array(), 'warnings' => array());
    /** @var array */
    private $categoryMap = array();
    /** @var array */
    private $categoryCache = array();
    /** @var array */
    private $manufacturerCache = array();
    /** @var array */
    private $featureValueCache = array();
    /** @var EasyProductFeedWriter */
    private $writer;

    public function __construct(array $profile)
    {
        $this->profile = $profile;
    }

    /* ------------------------------------------------------------------ */
    /* Public helpers                                                      */
    /* ------------------------------------------------------------------ */

    public static function getDir()
    {
        return _PS_MODULE_DIR_ . 'easyproductfeed/feeds/';
    }

    public static function getFilePath(array $profile)
    {
        $ext = (isset($profile['format']) && $profile['format'] === 'csv') ? 'csv' : 'xml';

        return self::getDir() . 'feed_' . (int) $profile['id_shop'] . '_' . (int) $profile['id_profile'] . '.' . $ext;
    }

    public static function deleteFiles(array $profile)
    {
        foreach (array('xml', 'csv') as $ext) {
            $path = self::getDir() . 'feed_' . (int) $profile['id_shop'] . '_' . (int) $profile['id_profile'] . '.' . $ext;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function getProfile($idProfile)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE_PROFILE . '` WHERE id_profile = ' . (int) $idProfile
        );
    }

    /**
     * Generate one profile by id. Safe to call from admin, cron or CLI.
     *
     * @return array ['success' => bool, 'message' => string, 'count' => int, 'skipped' => int]
     */
    public static function run($idProfile)
    {
        $profile = self::getProfile($idProfile);
        if (!$profile) {
            return array('success' => false, 'message' => 'Profile not found', 'count' => 0, 'skipped' => 0);
        }
        $generator = new self($profile);

        return $generator->generate();
    }

    /* ------------------------------------------------------------------ */
    /* Generation                                                          */
    /* ------------------------------------------------------------------ */

    public function generate()
    {
        $idProfile = (int) $this->profile['id_profile'];
        $dir = self::getDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return $this->fail('The feeds directory is not writable: ' . $dir);
        }

        // One generation per profile at a time (cron + manual click, etc.).
        $lock = @fopen($dir . 'profile_' . $idProfile . '.lock', 'c');
        if (!$lock) {
            return $this->fail('Cannot create the lock file in ' . $dir);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return array('success' => false, 'message' => 'Generation already running for this profile', 'count' => 0, 'skipped' => 0);
        }

        $backup = null;
        $tmpPath = null;
        $result = null;

        try {
            $backup = $this->switchContext();
            $this->setStatus('running', '');
            $this->loadSettings();

            $path = self::getFilePath($this->profile);
            $tmpPath = $path . '.tmp';

            $this->writer = new EasyProductFeedWriter(
                $tmpPath,
                $this->profile['format'],
                $this->getColumns(),
                array(
                    'title' => Configuration::get('PS_SHOP_NAME') . ' - ' . $this->profile['name'],
                    'link' => Context::getContext()->shop->getBaseURL(true),
                    'description' => 'Product feed generated by EasyProductFeed',
                )
            );

            $this->loadCategoryMap();
            Product::flushPriceCache();

            $batch = max(50, min(1000, (int) $this->settings['batch_size']));
            $lastId = 0;
            do {
                $ids = Db::getInstance()->executeS(
                    'SELECT p.`id_product` FROM `' . _DB_PREFIX_ . 'product` p
                     INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                        ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int) $this->idShop . ')
                     WHERE ps.`active` = 1 AND ps.`visibility` != \'none\' AND p.`id_product` > ' . (int) $lastId . '
                     ORDER BY p.`id_product` ASC LIMIT ' . (int) $batch
                );
                if (!$ids) {
                    break;
                }
                foreach ($ids as $row) {
                    $lastId = (int) $row['id_product'];
                    $this->processProduct($lastId);
                }
                // Keep memory flat on big catalogues.
                Product::flushPriceCache();
                $this->categoryCache = array();
            } while (count($ids) === $batch);

            $this->writer->close();
            $count = $this->writer->getCount();

            if (!@rename($tmpPath, $path)) {
                throw new RuntimeException('Could not move the generated feed into place.');
            }
            $tmpPath = null;

            $skipped = array_sum($this->stats['skipped']);
            $this->finish('ok', $count, $skipped);
            $result = array(
                'success' => true,
                'message' => $this->summary($count, $skipped),
                'count' => $count,
                'skipped' => $skipped,
            );
        } catch (Exception $e) {
            $result = $this->fail($e->getMessage());
        } catch (Throwable $e) {
            $result = $this->fail($e->getMessage());
        }

        if ($this->writer) {
            try {
                $this->writer->close();
            } catch (Exception $e) {
                // ignore
            }
        }
        if ($tmpPath && is_file($tmpPath)) {
            @unlink($tmpPath);
        }
        if ($backup) {
            $this->restoreContext($backup);
        }
        flock($lock, LOCK_UN);
        fclose($lock);

        return $result;
    }

    /** Human-readable outcome, with the reasons when products were skipped. */
    private function summary($count, $skipped)
    {
        if ($this->seen === 0) {
            return '0 items: no active, visible product found for shop #' . (int) $this->idShop
                . ' (products must be active and not have visibility "nowhere").';
        }
        $msg = sprintf('%d items written, %d products skipped', $count, $skipped);
        if ($skipped > 0) {
            $names = array(
                'price_hidden' => 'price hidden', 'excluded_category' => 'excluded category', 'out_of_stock' => 'out of stock',
                'no_price' => 'price is 0', 'no_image' => 'no image', 'product_not_loaded' => 'product could not be loaded',
            );
            $parts = array();
            arsort($this->stats['skipped']);
            foreach ($this->stats['skipped'] as $reason => $n) {
                $parts[] = $n . ' x ' . (isset($names[$reason]) ? $names[$reason] : $reason);
            }
            $msg .= ' (' . implode(', ', $parts) . ')';
        }

        return $msg;
    }

    private function fail($message)
    {
        $this->setStatus('error', $message);

        return array('success' => false, 'message' => $message, 'count' => 0, 'skipped' => 0);
    }

    /* ------------------------------------------------------------------ */
    /* Context / settings                                                  */
    /* ------------------------------------------------------------------ */

    private function switchContext()
    {
        $ctx = Context::getContext();
        $backup = array(
            'shop' => $ctx->shop,
            'language' => $ctx->language,
            'currency' => $ctx->currency,
            'country' => $ctx->country,
            'link' => $ctx->link,
            'shop_ctx_type' => Shop::getContext(),
            'shop_ctx_id' => Shop::getContext() === Shop::CONTEXT_SHOP
                ? Shop::getContextShopID()
                : (Shop::getContext() === Shop::CONTEXT_GROUP ? Shop::getContextShopGroupID() : null),
        );

        $this->idShop = (int) $this->profile['id_shop'];
        $this->idLang = (int) $this->profile['id_lang'];
        $this->idCurrency = (int) $this->profile['id_currency'];
        $this->idCountry = (int) $this->profile['id_country'];

        $shop = new Shop($this->idShop);
        $language = new Language($this->idLang);
        $currency = new Currency($this->idCurrency);
        $country = new Country($this->idCountry);
        foreach (array('shop' => $shop, 'language' => $language, 'currency' => $currency, 'country' => $country) as $what => $obj) {
            if (!Validate::isLoadedObject($obj)) {
                throw new RuntimeException('The ' . $what . ' selected in this profile no longer exists.');
            }
        }

        Shop::setContext(Shop::CONTEXT_SHOP, $this->idShop);
        $ctx->shop = $shop;
        $ctx->language = $language;
        $ctx->currency = $currency;
        $ctx->country = $country;
        // Explicit protocol: a Link built without one can return URLs with no "https://",
        // which used to make every product look like it had no image.
        $this->scheme = Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://';
        $this->link = new Link($this->scheme, $this->scheme);
        $ctx->link = $this->link;

        $this->currency = $currency;
        $this->precision = isset($currency->precision) ? (int) $currency->precision : 2;

        return $backup;
    }

    private function restoreContext(array $backup)
    {
        $ctx = Context::getContext();
        $ctx->shop = $backup['shop'];
        $ctx->language = $backup['language'];
        $ctx->currency = $backup['currency'];
        $ctx->country = $backup['country'];
        $ctx->link = $backup['link'];
        if ($backup['shop_ctx_type'] === Shop::CONTEXT_ALL || $backup['shop_ctx_id'] === null) {
            Shop::setContext(Shop::CONTEXT_ALL);
        } else {
            Shop::setContext($backup['shop_ctx_type'], $backup['shop_ctx_id']);
        }
    }

    private function loadSettings()
    {
        $this->settings = EasyProductFeed::getSettings();
        if (isset($this->settings['price_decimals']) && $this->settings['price_decimals'] === '2') {
            $this->precision = 2;
        }

        // Only use an image type that really exists; otherwise fall back to the original image.
        $this->imageType = null;
        $wanted = (string) $this->settings['image_type'];
        if ($wanted !== '') {
            foreach (ImageType::getImagesTypes('products') as $type) {
                if ($type['name'] === $wanted) {
                    $this->imageType = $wanted;
                    break;
                }
            }
            if ($this->imageType === null) {
                $this->warn('image_type_not_found');
            }
        }
    }

    private function loadCategoryMap()
    {
        $this->categoryMap = array();
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE_CATEGORY . '`');
        if ($rows) {
            foreach ($rows as $row) {
                $this->categoryMap[(int) $row['id_category']] = $row;
            }
        }
    }

    private function getColumns()
    {
        switch ($this->profile['feed_type']) {
            case 'language':
                return array('id', 'override', 'title', 'description');
            case 'country':
                return array('id', 'override', 'price', 'sale_price', 'availability', 'link');
            default:
                $cols = array(
                    'id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price',
                    'link', 'image_link', 'additional_image_link', 'brand', 'google_product_category',
                    'product_type', 'gtin', 'mpn', 'item_group_id', 'color', 'size', 'gender', 'age_group',
                    'material', 'pattern',
                );
                if (!empty($this->settings['export_quantity'])) {
                    $cols[] = 'quantity_to_sell_on_facebook';
                }

                return $cols;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Status / stats                                                      */
    /* ------------------------------------------------------------------ */

    private function setStatus($status, $message)
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_PROFILE . '`
             SET status = \'' . pSQL($status) . '\', message = \'' . pSQL(Tools::substr((string) $message, 0, 500)) . '\'
             WHERE id_profile = ' . (int) $this->profile['id_profile']
        );
    }

    private function finish($status, $count, $skipped)
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_PROFILE . '`
             SET status = \'' . pSQL($status) . '\', message = \'\', item_count = ' . (int) $count . ',
                 skipped_count = ' . (int) $skipped . ', stats = \'' . pSQL(json_encode($this->stats), true) . '\',
                 last_generated = \'' . pSQL(date('Y-m-d H:i:s')) . '\'
             WHERE id_profile = ' . (int) $this->profile['id_profile']
        );
    }

    private function skip($reason)
    {
        $this->stats['skipped'][$reason] = (isset($this->stats['skipped'][$reason]) ? $this->stats['skipped'][$reason] : 0) + 1;
    }

    private function warn($reason)
    {
        $this->stats['warnings'][$reason] = (isset($this->stats['warnings'][$reason]) ? $this->stats['warnings'][$reason] : 0) + 1;
    }

    /* ------------------------------------------------------------------ */
    /* Per-product processing                                              */
    /* ------------------------------------------------------------------ */

    private function processProduct($idProduct)
    {
        $this->seen++;
        $product = new Product($idProduct, false, $this->idLang, $this->idShop);
        if (!Validate::isLoadedObject($product)) {
            $this->skip('product_not_loaded');

            return;
        }
        if (isset($product->show_price) && !(int) $product->show_price) {
            $this->skip('price_hidden');

            return;
        }

        $cat = $this->getCategoryInfo((int) $product->id_category_default);
        if ($cat['excluded']) {
            $this->skip('excluded_category');

            return;
        }

        $variants = $this->getVariants($product);
        $isVariantFeed = count($variants) > 1 || $variants[0]['ipa'] > 0;
        $features = ($this->profile['feed_type'] === 'primary') ? $this->getProductFeatures($product) : array();
        $productImages = $this->getImages($product, 0);

        foreach ($variants as $variant) {
            $item = $this->buildItem($product, $variant, $cat, $isVariantFeed, $features, $productImages);
            if ($item !== null) {
                $this->writer->writeItem($item);
            }
        }
    }

    private function buildItem(Product $product, array $variant, array $cat, $isVariantFeed, array $features, array $productImages)
    {
        $ipa = (int) $variant['ipa'];
        $ipaArg = $ipa > 0 ? $ipa : null;
        $type = $this->profile['feed_type'];

        // --- stock / availability ---------------------------------------
        $qty = (int) StockAvailable::getQuantityAvailableByProduct($product->id, $ipaArg, $this->idShop);
        $outOfStockRule = (int) StockAvailable::outOfStock($product->id, $this->idShop);
        if ($outOfStockRule === 2) {
            $outOfStockRule = (int) Configuration::get('PS_ORDER_OUT_OF_STOCK');
        }
        if ($qty > 0) {
            $availability = 'in stock';
        } elseif ($outOfStockRule === 1) {
            $availability = 'available for order';
        } else {
            $availability = 'out of stock';
            if (empty($this->settings['include_out_of_stock'])) {
                $this->skip('out_of_stock');

                return null;
            }
        }

        $id = $this->buildId($product, $variant);
        $item = array('id' => $id);

        // --- eligibility checks shared by ALL feed types ------------------
        // Override feeds must only contain IDs that exist in the primary feed, so they
        // apply exactly the same skip rules (price, image, stock, excluded category).
        $images = $ipa > 0 ? $this->getImages($product, $ipa) : $productImages;
        if (empty($images)) {
            $images = $productImages;
        }
        if (empty($images)) {
            $this->skip('no_image');

            return null;
        }

        // --- price -------------------------------------------------------
        $withTax = !empty($this->settings['price_with_tax']);
        $regular = (float) Product::getPriceStatic($product->id, $withTax, $ipaArg, 6, null, false, false);
        $final = (float) Product::getPriceStatic($product->id, $withTax, $ipaArg, 6, null, false, true);
        if ($regular <= 0 && $final <= 0) {
            $this->skip('no_price');

            return null;
        }
        if ($regular < $final) {
            $regular = $final;
        }
        $price = $this->formatPrice($regular);
        $salePrice = ($final < $regular - 0.00001) ? $this->formatPrice($final) : '';

        // --- language override feed -------------------------------------
        if ($type === 'language') {
            $item['override'] = $this->profile['override_value'];
            $item['title'] = $this->buildTitle($product, $variant, $isVariantFeed);
            $item['description'] = $this->buildDescription($product);

            return $item;
        }

        $link = $this->buildLink($product, $ipa);

        // --- country override feed --------------------------------------
        if ($type === 'country') {
            $item['override'] = $this->profile['override_value'];
            $item['price'] = $price;
            $item['sale_price'] = $salePrice;
            $item['availability'] = $availability;
            $item['link'] = $link;

            return $item;
        }

        // --- primary feed -----------------------------------------------
        $imageLink = array_shift($images);
        $additional = array_slice($images, 0, max(0, min(10, (int) $this->settings['additional_images'])));

        $brand = $this->getBrand($product);
        $gtin = $this->firstNonEmpty(array($variant['ean13'], $variant['isbn'], $variant['upc'], $product->ean13, $product->isbn, $product->upc));
        $mpn = $this->firstNonEmpty(array($variant['mpn'], isset($product->mpn) ? $product->mpn : '', $variant['reference'], $product->reference));
        if ($gtin === '') {
            $this->warn('missing_gtin');
        }
        if ($cat['google_category'] === '') {
            $this->warn('missing_google_category');
        }

        $item['title'] = $this->buildTitle($product, $variant, $isVariantFeed);
        $item['description'] = $this->buildDescription($product);
        $item['availability'] = $availability;
        $item['condition'] = in_array($product->condition, array('new', 'used', 'refurbished'), true) ? $product->condition : 'new';
        $item['price'] = $price;
        $item['sale_price'] = $salePrice;
        $item['link'] = $link;
        $item['image_link'] = $imageLink;
        $item['additional_image_link'] = $additional;
        $item['brand'] = $brand;
        $item['google_product_category'] = $cat['google_category'];
        $item['product_type'] = $cat['path'];
        $item['gtin'] = $gtin;
        $item['mpn'] = $mpn;
        $item['item_group_id'] = $isVariantFeed ? $this->buildGroupId($product) : '';
        $item['color'] = $variant['color'] !== '' ? $variant['color'] : (isset($features['color']) ? $features['color'] : '');
        $item['size'] = $variant['size'] !== '' ? $variant['size'] : (isset($features['size']) ? $features['size'] : '');
        $item['gender'] = $cat['gender'];
        $item['age_group'] = $cat['age_group'];
        $item['material'] = isset($features['material']) ? $features['material'] : '';
        $item['pattern'] = isset($features['pattern']) ? $features['pattern'] : '';
        if (!empty($this->settings['export_quantity'])) {
            $item['quantity_to_sell_on_facebook'] = max(0, $qty);
        }

        return $item;
    }

    /* ------------------------------------------------------------------ */
    /* Field builders                                                      */
    /* ------------------------------------------------------------------ */

    private function buildId(Product $product, array $variant)
    {
        $prefix = (string) $this->settings['id_prefix'];
        $ipa = (int) $variant['ipa'];

        if ($this->settings['id_mode'] === 'reference') {
            $ref = $ipa > 0 ? $this->firstNonEmpty(array($variant['reference'])) : trim((string) $product->reference);
            if ($ref === '') {
                $ref = trim((string) $product->reference);
                $ref = ($ref !== '' ? $ref . '-' : (int) $product->id . '-') . ($ipa > 0 ? $ipa : '');
                $ref = rtrim($ref, '-');
            }
            if ($ref === '') {
                $ref = (string) (int) $product->id;
            }

            return $prefix . $ref;
        }

        return $prefix . (int) $product->id . ($ipa > 0 ? '-' . $ipa : '');
    }

    private function buildGroupId(Product $product)
    {
        return (string) $this->settings['id_prefix'] . (int) $product->id;
    }

    private function buildTitle(Product $product, array $variant, $isVariantFeed)
    {
        $title = trim((string) $product->name);
        if ($isVariantFeed && !empty($this->settings['append_combination_title']) && $variant['designation'] !== '') {
            $title .= ' - ' . $variant['designation'];
        }

        return Tools::substr($title, 0, 200);
    }

    private function buildDescription(Product $product)
    {
        $source = $this->settings['description_source'];
        $long = (string) $product->description;
        $short = (string) $product->description_short;
        if ($source === 'short') {
            $html = $short !== '' ? $short : $long;
        } elseif ($source === 'long') {
            $html = $long !== '' ? $long : $short;
        } else { // long_fallback_short, the default
            $html = trim(strip_tags($long)) !== '' ? $long : $short;
        }

        $html = str_ireplace(array('</p>', '<br>', '<br/>', '<br />', '</li>', '</div>'), ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            $text = trim((string) $product->name);
            $this->warn('missing_description');
        }

        return Tools::substr($text, 0, 9999);
    }

    /** Guarantees an absolute http(s) URL, whatever the Link object returned. */
    private function absoluteUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '//') === 0) {
            return rtrim($this->scheme, '/') . $url;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return $this->scheme . ltrim($url, '/');
        }

        return $url;
    }

    private function buildLink(Product $product, $ipa)
    {
        $url = $this->absoluteUrl($this->link->getProductLink($product, null, null, null, $this->idLang, $this->idShop, (int) $ipa));

        // When the profile prices in a non-default currency, tell the shop which currency to show.
        if ($this->idCurrency !== (int) Configuration::get('PS_CURRENCY_DEFAULT')) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'SubmitCurrency=1&id_currency=' . $this->idCurrency;
        }

        return $url;
    }

    private function formatPrice($value)
    {
        return number_format(round($value, $this->precision), $this->precision, '.', '') . ' ' . $this->currency->iso_code;
    }

    private function getBrand(Product $product)
    {
        $idMan = (int) $product->id_manufacturer;
        if ($idMan > 0) {
            if (!isset($this->manufacturerCache[$idMan])) {
                $this->manufacturerCache[$idMan] = (string) Manufacturer::getNameById($idMan);
            }
            if ($this->manufacturerCache[$idMan] !== '') {
                return $this->manufacturerCache[$idMan];
            }
        }
        $this->warn('brand_defaulted');

        return (string) $this->settings['brand_default'];
    }

    private function getImages(Product $product, $ipa)
    {
        $rows = $ipa > 0
            ? Image::getImages($this->idLang, $product->id, $ipa)
            : Image::getImages($this->idLang, $product->id);
        $urls = array();
        if (!$rows) {
            return $urls;
        }
        foreach ($rows as $row) {
            $url = $this->absoluteUrl($this->link->getImageLink($product->link_rewrite, (int) $row['id_image'], $this->imageType));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function firstNonEmpty(array $values)
    {
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v !== '' && $v !== '0') {
                return $v;
            }
        }

        return '';
    }

    /* ------------------------------------------------------------------ */
    /* Variants (combinations)                                             */
    /* ------------------------------------------------------------------ */

    private function emptyVariant($ipa)
    {
        return array(
            'ipa' => (int) $ipa, 'reference' => '', 'ean13' => '', 'upc' => '', 'isbn' => '', 'mpn' => '',
            'color' => '', 'size' => '', 'designation' => '',
        );
    }

    private function getVariants(Product $product)
    {
        if (empty($this->settings['export_combinations'])) {
            return array($this->emptyVariant(0));
        }
        $rows = $product->getAttributesGroups($this->idLang);
        if (!$rows) {
            return array($this->emptyVariant(0));
        }

        $colorGroups = $this->idList($this->settings['color_groups']);
        $sizeGroups = $this->idList($this->settings['size_groups']);
        $variants = array();

        foreach ($rows as $row) {
            $ipa = (int) $row['id_product_attribute'];
            if (!isset($variants[$ipa])) {
                $variants[$ipa] = $this->emptyVariant($ipa);
                $variants[$ipa]['reference'] = isset($row['reference']) ? (string) $row['reference'] : '';
                $variants[$ipa]['ean13'] = isset($row['ean13']) ? (string) $row['ean13'] : '';
                $variants[$ipa]['upc'] = isset($row['upc']) ? (string) $row['upc'] : '';
                $variants[$ipa]['isbn'] = isset($row['isbn']) ? (string) $row['isbn'] : '';
                $variants[$ipa]['mpn'] = isset($row['mpn']) ? (string) $row['mpn'] : '';
                $variants[$ipa]['_parts'] = array();
            }
            $groupId = (int) $row['id_attribute_group'];
            $value = (string) $row['attribute_name'];
            $isColor = in_array($groupId, $colorGroups, true) || (empty($colorGroups) && !empty($row['is_color_group']));
            $isSize = in_array($groupId, $sizeGroups, true);
            if ($isColor) {
                $variants[$ipa]['color'] = trim($variants[$ipa]['color'] . ' / ' . $value, ' /');
            }
            if ($isSize) {
                $variants[$ipa]['size'] = trim($variants[$ipa]['size'] . ' / ' . $value, ' /');
            }
            $variants[$ipa]['_parts'][] = $value;
        }

        foreach ($variants as $ipa => $v) {
            $variants[$ipa]['designation'] = implode(', ', $v['_parts']);
            unset($variants[$ipa]['_parts']);
        }

        return array_values($variants);
    }

    private function idList($csv)
    {
        $ids = array();
        foreach (explode(',', (string) $csv) as $part) {
            $part = (int) trim($part);
            if ($part > 0) {
                $ids[] = $part;
            }
        }

        return $ids;
    }

    /* ------------------------------------------------------------------ */
    /* Categories / features                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Walks up from the product's default category and returns the first Google category,
     * gender and age group found (deepest wins), whether any level is excluded,
     * and the readable category path.
     */
    private function getCategoryInfo($idCategory)
    {
        if (isset($this->categoryCache[$idCategory])) {
            return $this->categoryCache[$idCategory];
        }

        $info = array('google_category' => '', 'gender' => '', 'age_group' => '', 'excluded' => false, 'path' => '');
        $rootId = (int) Configuration::get('PS_ROOT_CATEGORY');
        $homeId = (int) Configuration::get('PS_HOME_CATEGORY');
        $names = array();
        $current = (int) $idCategory;
        $guard = 0;

        while ($current > 0 && $guard++ < 12) {
            if (isset($this->categoryMap[$current])) {
                $map = $this->categoryMap[$current];
                if (!empty($map['excluded'])) {
                    $info['excluded'] = true;
                }
                if ($info['google_category'] === '' && $map['google_category'] !== '') {
                    $info['google_category'] = (string) $map['google_category'];
                }
                if ($info['gender'] === '' && $map['gender'] !== '') {
                    $info['gender'] = (string) $map['gender'];
                }
                if ($info['age_group'] === '' && $map['age_group'] !== '') {
                    $info['age_group'] = (string) $map['age_group'];
                }
            }
            $category = new Category($current, $this->idLang);
            if (!Validate::isLoadedObject($category)) {
                break;
            }
            if ($current !== $rootId && $current !== $homeId) {
                array_unshift($names, (string) $category->name);
            }
            $current = (int) $category->id_parent;
            if ($current === $rootId) {
                break;
            }
        }

        $info['path'] = implode(' > ', $names);
        $this->categoryCache[$idCategory] = $info;

        return $info;
    }

    /** Reads configured colour / size / material / pattern from product features. */
    private function getProductFeatures(Product $product)
    {
        $wanted = array(
            'color' => $this->idList($this->settings['color_features']),
            'size' => $this->idList($this->settings['size_features']),
            'material' => $this->idList($this->settings['material_features']),
            'pattern' => $this->idList($this->settings['pattern_features']),
        );
        if (!array_filter($wanted)) {
            return array();
        }

        $rows = Product::getFeaturesStatic((int) $product->id);
        $out = array();
        if (!$rows) {
            return $out;
        }
        foreach ($rows as $row) {
            foreach ($wanted as $field => $featureIds) {
                if (in_array((int) $row['id_feature'], $featureIds, true)) {
                    $idValue = (int) $row['id_feature_value'];
                    if (!isset($this->featureValueCache[$idValue])) {
                        $fv = new FeatureValue($idValue, $this->idLang);
                        $this->featureValueCache[$idValue] = Validate::isLoadedObject($fv) ? (string) $fv->value : '';
                    }
                    if ($this->featureValueCache[$idValue] !== '') {
                        $out[$field] = isset($out[$field]) ? $out[$field] . ' / ' . $this->featureValueCache[$idValue] : $this->featureValueCache[$idValue];
                    }
                }
            }
        }

        return $out;
    }
}
