<?php
/**
 * EasyProductFeed - Facebook & Instagram product catalogue feeds for PrestaShop 8.x / 9.x
 *
 * @author Nasraoui Mustapha
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/EasyProductFeedWriter.php';
require_once dirname(__FILE__) . '/classes/EasyProductFeedGenerator.php';
require_once dirname(__FILE__) . '/classes/EasyProductFeedMeta.php';

class EasyProductFeed extends Module
{
    const CONF_SETTINGS = 'EASYPRODUCTFEED_SETTINGS';
    const CONF_TOKEN = 'EASYPRODUCTFEED_TOKEN';
    const CATEGORIES_PER_PAGE = 50;

    /** @var int */
    private $idShop;

    public function __construct()
    {
        $this->name = 'easyproductfeed';
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'Nasraoui Mustapha';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => '9.99.99');

        parent::__construct();

        $this->displayName = $this->l('EasyProductFeed – Facebook & Instagram Product Catalogue');
        $this->description = $this->l('Generate multilingual, multi-currency product feeds for Meta Commerce Manager.');
    }

    /* ------------------------------------------------------------------ */
    /* Install / uninstall                                                 */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && Configuration::updateValue(self::CONF_TOKEN, Tools::passwdGen(32))
            && $this->prepareFeedDir();
    }

    public function uninstall()
    {
        $profiles = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'easyproductfeed_profile`');
        foreach ((array) $profiles as $profile) {
            EasyProductFeedGenerator::deleteFiles($profile);
        }
        foreach (glob(EasyProductFeedGenerator::getDir() . '*.lock') ?: array() as $lock) {
            @unlink($lock);
        }
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'easyproductfeed_profile`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'easyproductfeed_category`');
        Configuration::deleteByName(self::CONF_SETTINGS);
        Configuration::deleteByName(self::CONF_TOKEN);

        return parent::uninstall();
    }

    private function installDb()
    {
        $engine = _MYSQL_ENGINE_;
        $ok = Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'easyproductfeed_profile` (
                `id_profile` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `name` VARCHAR(128) NOT NULL,
                `feed_type` VARCHAR(16) NOT NULL DEFAULT \'primary\',
                `id_lang` INT UNSIGNED NOT NULL,
                `id_currency` INT UNSIGNED NOT NULL,
                `id_country` INT UNSIGNED NOT NULL,
                `override_value` VARCHAR(16) NOT NULL DEFAULT \'\',
                `format` VARCHAR(8) NOT NULL DEFAULT \'xml\',
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `facebook_feed_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'never\',
                `message` VARCHAR(512) NOT NULL DEFAULT \'\',
                `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `stats` TEXT NULL,
                `last_generated` DATETIME NULL DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_profile`),
                KEY `id_shop` (`id_shop`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'
        );
        $ok = $ok && Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'easyproductfeed_category` (
                `id_category` INT UNSIGNED NOT NULL,
                `google_category` VARCHAR(255) NOT NULL DEFAULT \'\',
                `gender` VARCHAR(16) NOT NULL DEFAULT \'\',
                `age_group` VARCHAR(16) NOT NULL DEFAULT \'\',
                `excluded` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_category`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'
        );

        return $ok;
    }

    private function prepareFeedDir()
    {
        $dir = EasyProductFeedGenerator::getDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return is_dir($dir);
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    public static function getSettings()
    {
        $defaults = array(
            'brand_default' => (string) Configuration::get('PS_SHOP_NAME'),
            'description_source' => 'long_fallback_short',
            'image_type' => 'large_default',
            'additional_images' => 5,
            'export_combinations' => 0,
            'append_combination_title' => 1,
            'id_mode' => 'id',
            'id_prefix' => '',
            'include_out_of_stock' => 1,
            'price_with_tax' => 1,
            'price_decimals' => 'currency',
            'export_quantity' => 0,
            'color_groups' => '',
            'size_groups' => '',
            'color_features' => '',
            'size_features' => '',
            'material_features' => '',
            'pattern_features' => '',
            'batch_size' => 200,
            'access_token' => '',
        );
        $stored = json_decode((string) Configuration::get(self::CONF_SETTINGS), true);

        return array_merge($defaults, is_array($stored) ? $stored : array());
    }

    private function saveSettings()
    {
        $intList = function ($v) {
            $ids = array();
            foreach (explode(',', (string) $v) as $p) {
                $p = (int) trim($p);
                if ($p > 0) {
                    $ids[] = $p;
                }
            }

            return implode(',', array_unique($ids));
        };
        $flag = function ($name) {
            return Tools::getValue($name) ? 1 : 0;
        };
        $choice = function ($name, array $allowed, $default) {
            $v = (string) Tools::getValue($name);

            return in_array($v, $allowed, true) ? $v : $default;
        };

        $settings = array(
            'brand_default' => trim((string) Tools::getValue('brand_default')),
            'description_source' => $choice('description_source', array('long_fallback_short', 'long', 'short'), 'long_fallback_short'),
            'image_type' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) Tools::getValue('image_type')),
            'additional_images' => max(0, min(10, (int) Tools::getValue('additional_images'))),
            'export_combinations' => $flag('export_combinations'),
            'append_combination_title' => $flag('append_combination_title'),
            'id_mode' => $choice('id_mode', array('id', 'reference'), 'id'),
            'id_prefix' => preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) Tools::getValue('id_prefix')),
            'include_out_of_stock' => $flag('include_out_of_stock'),
            'price_with_tax' => $flag('price_with_tax'),
            'price_decimals' => $choice('price_decimals', array('currency', '2'), 'currency'),
            'export_quantity' => $flag('export_quantity'),
            'color_groups' => $intList(Tools::getValue('color_groups')),
            'size_groups' => $intList(Tools::getValue('size_groups')),
            'color_features' => $intList(Tools::getValue('color_features')),
            'size_features' => $intList(Tools::getValue('size_features')),
            'material_features' => $intList(Tools::getValue('material_features')),
            'pattern_features' => $intList(Tools::getValue('pattern_features')),
            'batch_size' => max(50, min(1000, (int) Tools::getValue('batch_size'))),
            'access_token' => trim((string) Tools::getValue('access_token')),
        );
        Configuration::updateValue(self::CONF_SETTINGS, json_encode($settings));
    }

    /* ------------------------------------------------------------------ */
    /* Admin page                                                          */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        if (Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return $this->displayWarning(
                $this->l('EasyProductFeed keeps one catalogue per shop. Please select a specific shop in the shop selector (top of the page) to configure it.')
            );
        }
        $this->idShop = (int) $this->context->shop->id;

        $html = $this->handleActions();

        return $html
            . $this->renderProfiles()
            . $this->renderSettings()
            . $this->renderCategoryMap()
            . $this->renderCron()
            . $this->renderGuide();
    }

    private function h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function formAction($extra = '')
    {
        return AdminController::$currentIndex . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules') . $extra;
    }

    private function getProfile($idProfile)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'easyproductfeed_profile`
             WHERE id_profile = ' . (int) $idProfile . ' AND id_shop = ' . (int) $this->idShop
        );
    }

    /* ---- actions ------------------------------------------------------ */

    private function handleActions()
    {
        $html = '';

        if (Tools::isSubmit('submitEasyProductFeedSettings')) {
            $this->saveSettings();
            $html .= $this->displayConfirmation($this->l('Settings saved. Regenerate your feeds to apply them.'));
        }

        if (Tools::isSubmit('submitEasyProductFeedProfile')) {
            $html .= $this->saveProfile();
        }

        if (Tools::isSubmit('submitEasyProductFeedCategories')) {
            $this->saveCategoryMap();
            $html .= $this->displayConfirmation($this->l('Category mapping saved. Regenerate your feeds to apply it.'));
        }

        $actionProfile = (int) Tools::getValue('action_profile');
        if ($actionProfile > 0) {
            $profile = $this->getProfile($actionProfile);
            if (!$profile) {
                $html .= $this->displayError($this->l('Profile not found.'));
            } elseif (Tools::isSubmit('deleteEasyProductFeedProfile')) {
                EasyProductFeedGenerator::deleteFiles($profile);
                Db::getInstance()->delete('easyproductfeed_profile', 'id_profile = ' . (int) $profile['id_profile']);
                $html .= $this->displayConfirmation($this->l('Profile deleted.'));
            } elseif (Tools::isSubmit('generateEasyProductFeed')) {
                $r = EasyProductFeedGenerator::run($profile['id_profile']);
                if (!$r['success']) {
                    $html .= $this->displayError($this->h($profile['name']) . ': ' . $this->h($r['message']));
                } elseif ((int) $r['count'] === 0) {
                    $html .= $this->displayWarning($this->h($profile['name']) . ': ' . $this->h($r['message']));
                } else {
                    $html .= $this->displayConfirmation($this->h($profile['name']) . ': ' . $this->h($r['message']));
                }
            } elseif (Tools::isSubmit('generateEasyProductFeedBg')) {
                $html .= $this->startBackgroundGeneration($profile);
            } elseif (Tools::isSubmit('checkEasyProductFeedMeta')) {
                $html .= $this->renderMetaReport($profile);
            }
        }

        return $html;
    }

    private function saveProfile()
    {
        $id = (int) Tools::getValue('id_profile');
        $type = (string) Tools::getValue('feed_type');
        $name = trim((string) Tools::getValue('name'));
        $idLang = (int) Tools::getValue('id_lang');
        $idCurrency = (int) Tools::getValue('id_currency');
        $idCountry = (int) Tools::getValue('id_country');
        $format = Tools::getValue('format') === 'csv' ? 'csv' : 'xml';
        $override = trim((string) Tools::getValue('override_value'));

        if ($name === '' || !in_array($type, array('primary', 'language', 'country'), true)) {
            return $this->displayError($this->l('Please enter a name and choose a valid feed type.'));
        }
        if (!Validate::isLoadedObject(new Language($idLang))
            || !Validate::isLoadedObject(new Currency($idCurrency))
            || !Validate::isLoadedObject(new Country($idCountry))) {
            return $this->displayError($this->l('Please choose a valid language, currency and country.'));
        }
        if ($type === 'primary') {
            $override = '';
        } elseif ($override === '') {
            return $this->displayError($this->l('Localized feeds need an override value (language or country code).'));
        }

        $data = array(
            'name' => pSQL($name),
            'feed_type' => pSQL($type),
            'id_lang' => $idLang,
            'id_currency' => $idCurrency,
            'id_country' => $idCountry,
            'override_value' => pSQL(preg_replace('/[^A-Za-z0-9_\-]/', '', $override)),
            'format' => pSQL($format),
            'active' => Tools::getValue('active') ? 1 : 0,
            'facebook_feed_id' => pSQL(preg_replace('/[^0-9]/', '', (string) Tools::getValue('facebook_feed_id'))),
        );

        if ($id > 0) {
            $existing = $this->getProfile($id);
            if (!$existing) {
                return $this->displayError($this->l('Profile not found.'));
            }
            if ($existing['format'] !== $format) {
                EasyProductFeedGenerator::deleteFiles($existing);
                $data['status'] = 'never';
                $data['item_count'] = 0;
            }
            Db::getInstance()->update('easyproductfeed_profile', $data, 'id_profile = ' . $id);

            return $this->displayConfirmation($this->l('Profile updated. Regenerate it to apply the changes.'));
        }

        $data['id_shop'] = (int) $this->idShop;
        $data['date_add'] = date('Y-m-d H:i:s');
        Db::getInstance()->insert('easyproductfeed_profile', $data);

        return $this->displayConfirmation($this->l('Profile created. Click "Generate now" to build the feed.'));
    }

    private function startBackgroundGeneration(array $profile)
    {
        if (!function_exists('curl_init')) {
            return $this->displayError($this->l('The PHP cURL extension is required for background generation. Use "Generate now" or the cron job instead.'));
        }
        $url = $this->context->link->getModuleLink(
            $this->name,
            'cron',
            array('token' => Configuration::get(self::CONF_TOKEN), 'id_profile' => (int) $profile['id_profile']),
            null,
            null,
            $this->idShop
        );

        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'easyproductfeed_profile` SET status = \'queued\', message = \'\'
             WHERE id_profile = ' . (int) $profile['id_profile']
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 1500,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_FOLLOWLOCATION => true,
        ));
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // errno 28 = our deliberate short timeout: the request is running server-side.
        if ($errno === 28 || ($errno === 0 && $code > 0 && $code < 400)) {
            return $this->displayConfirmation(
                $this->l('Generation started in the background. Reload this page in a moment to see the result.')
            );
        }
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'easyproductfeed_profile` SET status = \'error\',
             message = \'Could not start the background request (HTTP ' . (int) $code . ', cURL ' . (int) $errno . ')\'
             WHERE id_profile = ' . (int) $profile['id_profile']
        );

        return $this->displayError(
            $this->l('Could not start background generation from the back office (the shop URL may not be reachable from the server). Use "Generate now" or the cron job.')
        );
    }

    private function renderMetaReport(array $profile)
    {
        $settings = self::getSettings();
        $r = EasyProductFeedMeta::latestUploadReport($profile['facebook_feed_id'], $settings['access_token']);
        if (!$r['success']) {
            return $this->displayError($this->h($r['message']));
        }
        if ($r['message'] !== '') {
            return $this->displayWarning($this->h($r['message']));
        }

        $u = $r['upload'];
        $html = '<div class="panel"><div class="panel-heading">' . $this->l('Meta report for') . ' ' . $this->h($profile['name']) . '</div>';
        $html .= '<p>';
        foreach (array('start_time' => 'Started', 'end_time' => 'Finished', 'num_detected_items' => 'Detected items',
            'num_persisted_items' => 'Imported items', 'num_invalid_items' => 'Invalid items',
            'error_count' => 'Errors', 'warning_count' => 'Warnings') as $key => $label) {
            if (isset($u[$key])) {
                $html .= '<strong>' . $this->h($label) . ':</strong> ' . $this->h($u[$key]) . ' &nbsp; ';
            }
        }
        $html .= '</p>';
        if (empty($r['errors'])) {
            $html .= '<p class="text-success">' . $this->l('Meta reports no errors for the latest upload.') . '</p>';
        } else {
            $html .= '<table class="table"><thead><tr><th>' . $this->l('Severity') . '</th><th>' . $this->l('Problem') . '</th><th>'
                . $this->l('Items') . '</th></tr></thead><tbody>';
            foreach ($r['errors'] as $e) {
                $html .= '<tr><td>' . $this->h(isset($e['severity']) ? $e['severity'] : '') . '</td><td><strong>'
                    . $this->h(isset($e['summary']) ? $e['summary'] : '') . '</strong><br>'
                    . $this->h(isset($e['description']) ? $e['description'] : '') . '</td><td>'
                    . $this->h(isset($e['total_count']) ? $e['total_count'] : '') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html . '</div>';
    }

    /* ---- small HTML helpers ------------------------------------------- */

    private function options(array $options, $selected)
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . $this->h($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '')
                . '>' . $this->h($label) . '</option>';
        }

        return $html;
    }

    private function yesNo($name, $current)
    {
        return '<select name="' . $this->h($name) . '" class="form-control">'
            . $this->options(array(1 => $this->l('Yes'), 0 => $this->l('No')), (int) $current ? 1 : 0) . '</select>';
    }

    private function row($label, $input, $help = '')
    {
        return '<div class="form-group row"><label class="control-label col-lg-3">' . $label . '</label>'
            . '<div class="col-lg-6">' . $input . ($help !== '' ? '<p class="help-block">' . $help . '</p>' : '') . '</div></div>';
    }

    private function text($name, $value, $placeholder = '')
    {
        return '<input type="text" class="form-control" name="' . $this->h($name) . '" value="' . $this->h($value)
            . '" placeholder="' . $this->h($placeholder) . '">';
    }

    private function feedUrl(array $profile)
    {
        return $this->context->link->getModuleLink(
            $this->name,
            'feed',
            array('id_profile' => (int) $profile['id_profile'], 'token' => Configuration::get(self::CONF_TOKEN)),
            null,
            null,
            (int) $profile['id_shop']
        );
    }

    /* ---- profiles panel ------------------------------------------------ */

    private function renderProfiles()
    {
        $langs = array();
        foreach (Language::getLanguages(true, $this->idShop) as $l) {
            $langs[(int) $l['id_lang']] = $l['name'] . ' (' . $l['iso_code'] . ')';
        }
        $currencies = array();
        foreach (Currency::getCurrenciesByIdShop($this->idShop) as $c) {
            $c = (array) $c;
            $currencies[(int) $c['id_currency']] = $c['iso_code'];
        }
        $countries = array();
        foreach (Country::getCountries((int) $this->context->language->id, true) as $c) {
            $countries[(int) $c['id_country']] = $c['name'] . ' (' . $c['iso_code'] . ')';
        }

        $profiles = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'easyproductfeed_profile` WHERE id_shop = ' . (int) $this->idShop . ' ORDER BY id_profile'
        );

        $typeLabels = array(
            'primary' => $this->l('Primary (full catalogue)'),
            'language' => $this->l('Language override'),
            'country' => $this->l('Country override (prices)'),
        );

        $html = '<div class="panel" id="epf-profiles"><div class="panel-heading">' . $this->l('Feed profiles') . '</div>';
        $html .= '<p class="help-block">' . $this->l('Create one Primary profile for your main language, then optional Language / Country override profiles for other languages, currencies and country-specific prices. Add each feed URL to the same Meta catalogue as a data source.') . '</p>';

        if (!$profiles) {
            $html .= '<p class="alert alert-info">' . $this->l('No profile yet. Create your first one below.') . '</p>';
        } else {
            $html .= '<table class="table"><thead><tr><th>' . $this->l('Name') . '</th><th>' . $this->l('Type') . '</th><th>'
                . $this->l('Language / Currency / Country') . '</th><th>' . $this->l('Format') . '</th><th>' . $this->l('Status') . '</th><th>'
                . $this->l('Actions') . '</th></tr></thead><tbody>';
            foreach ($profiles as $p) {
                $html .= $this->renderProfileRow($p, $typeLabels, $langs, $currencies, $countries);
            }
            $html .= '</tbody></table>';
        }

        $edit = null;
        if ((int) Tools::getValue('edit_profile') > 0) {
            $edit = $this->getProfile((int) Tools::getValue('edit_profile'));
        }
        $html .= $this->renderProfileForm($edit, $typeLabels, $langs, $currencies, $countries);

        return $html . '</div>';
    }

    private function renderProfileRow(array $p, array $typeLabels, array $langs, array $currencies, array $countries)
    {
        $statusMap = array(
            'ok' => array('success', $this->l('OK')),
            'error' => array('danger', $this->l('Error')),
            'running' => array('warning', $this->l('Running')),
            'queued' => array('warning', $this->l('Queued')),
            'never' => array('default', $this->l('Not generated')),
        );
        $st = isset($statusMap[$p['status']]) ? $statusMap[$p['status']] : $statusMap['never'];
        $stats = json_decode((string) $p['stats'], true);
        $labels = array(
            'price_hidden' => 'price hidden (catalogue mode)', 'excluded_category' => 'excluded category',
            'out_of_stock' => 'out of stock', 'no_price' => 'no price', 'no_image' => 'no image',
            'missing_gtin' => 'no GTIN/EAN', 'missing_google_category' => 'no Google category mapped',
            'missing_description' => 'no description (title used)', 'brand_defaulted' => 'no brand (default used)',
            'image_type_not_found' => 'image type not found (original image used)',
        );

        $id = (int) $p['id_profile'];
        $hidden = '<input type="hidden" name="action_profile" value="' . $id . '">';
        $btn = function ($name, $label, $class, $confirm = '') use ($hidden) {
            return '<form method="post" action="' . $this->formAction() . '" style="display:inline-block;margin:2px"' .
                ($confirm !== '' ? ' onsubmit="return confirm(\'' . $this->h(addslashes($confirm)) . '\');"' : '') . '>'
                . $hidden . '<button type="submit" name="' . $name . '" value="1" class="btn btn-' . $class . ' btn-sm">' . $label . '</button></form>';
        };

        $row = '<tr><td><strong>' . $this->h($p['name']) . '</strong>' . ((int) $p['active'] ? '' : ' <span class="label label-default">' . $this->l('disabled') . '</span>') . '</td>';
        $row .= '<td>' . $this->h($typeLabels[$p['feed_type']]) . ($p['override_value'] !== '' ? '<br><small>override: ' . $this->h($p['override_value']) . '</small>' : '') . '</td>';
        $row .= '<td>' . $this->h(isset($langs[(int) $p['id_lang']]) ? $langs[(int) $p['id_lang']] : '?') . '<br>'
            . $this->h(isset($currencies[(int) $p['id_currency']]) ? $currencies[(int) $p['id_currency']] : '?') . ' / '
            . $this->h(isset($countries[(int) $p['id_country']]) ? $countries[(int) $p['id_country']] : '?') . '</td>';
        $row .= '<td>' . strtoupper($this->h($p['format'])) . '</td>';
        $row .= '<td><span class="label label-' . $st[0] . '">' . $st[1] . '</span>';
        if ($p['last_generated']) {
            $row .= '<br><small>' . $this->h($p['last_generated']) . '<br>' . (int) $p['item_count'] . ' ' . $this->l('items') . '</small>';
        }
        if ($p['status'] === 'error' && $p['message'] !== '') {
            $row .= '<br><small class="text-danger">' . $this->h($p['message']) . '</small>';
        }
        $row .= '</td><td>';
        $row .= $btn('generateEasyProductFeed', $this->l('Generate now'), 'primary');
        $row .= $btn('generateEasyProductFeedBg', $this->l('Generate in background'), 'default');
        $row .= '<a class="btn btn-default btn-sm" style="margin:2px" href="' . $this->formAction('&edit_profile=' . $id) . '#epf-profile-form">' . $this->l('Edit') . '</a>';
        if ($p['facebook_feed_id'] !== '') {
            $row .= $btn('checkEasyProductFeedMeta', $this->l('Check Meta errors'), 'default');
        }
        $row .= $btn('deleteEasyProductFeedProfile', $this->l('Delete'), 'danger', $this->l('Delete this profile and its feed file?'));
        $row .= '</td></tr>';

        $details = '<tr><td colspan="6" style="border-top:0;padding-top:0"><small>' . $this->l('Feed URL:') . '</small> '
            . '<input type="text" readonly class="form-control" style="display:inline-block;width:80%;font-size:11px" onclick="this.select()" value="' . $this->h($this->feedUrl($p)) . '">';
        if (is_array($stats) && (!empty($stats['skipped']) || !empty($stats['warnings']))) {
            $details .= '<details style="margin-top:5px"><summary>' . $this->l('Generation report') . '</summary><small>';
            foreach (array('skipped' => $this->l('Skipped'), 'warnings' => $this->l('Warnings')) as $key => $title) {
                if (!empty($stats[$key])) {
                    $details .= '<strong>' . $title . ':</strong> ';
                    $parts = array();
                    foreach ($stats[$key] as $reason => $n) {
                        $parts[] = (int) $n . ' × ' . $this->h(isset($labels[$reason]) ? $labels[$reason] : $reason);
                    }
                    $details .= implode(', ', $parts) . '<br>';
                }
            }
            $details .= '</small></details>';
        }
        $details .= '</td></tr>';

        return $row . $details;
    }

    private function renderProfileForm($edit, array $typeLabels, array $langs, array $currencies, array $countries)
    {
        $d = $edit ?: array(
            'id_profile' => 0, 'name' => '', 'feed_type' => 'primary',
            'id_lang' => (int) Configuration::get('PS_LANG_DEFAULT'),
            'id_currency' => (int) Configuration::get('PS_CURRENCY_DEFAULT'),
            'id_country' => (int) Configuration::get('PS_COUNTRY_DEFAULT'),
            'override_value' => '', 'format' => 'xml', 'active' => 1, 'facebook_feed_id' => '',
        );

        $html = '<hr><h4 id="epf-profile-form">' . ($edit ? $this->l('Edit profile') : $this->l('Add a profile')) . '</h4>';
        $html .= '<form method="post" action="' . $this->formAction() . '" class="form-horizontal">';
        $html .= '<input type="hidden" name="id_profile" value="' . (int) $d['id_profile'] . '">';
        $html .= $this->row($this->l('Name'), $this->text('name', $d['name'], 'e.g. Main feed (EN)'));
        $html .= $this->row(
            $this->l('Feed type'),
            '<select name="feed_type" class="form-control">' . $this->options($typeLabels, $d['feed_type']) . '</select>',
            $this->l('Primary = all product data. Language override = translated title/description. Country override = country-specific price, availability and link.')
        );
        $html .= $this->row($this->l('Language'), '<select name="id_lang" class="form-control">' . $this->options($langs, $d['id_lang']) . '</select>');
        $html .= $this->row($this->l('Currency'), '<select name="id_currency" class="form-control">' . $this->options($currencies, $d['id_currency']) . '</select>',
            $this->l('Prices are converted by PrestaShop using this currency\'s conversion rate.'));
        $html .= $this->row($this->l('Country'), '<select name="id_country" class="form-control">' . $this->options($countries, $d['id_country']) . '</select>',
            $this->l('Determines taxes and country-specific prices.'));
        $html .= $this->row(
            $this->l('Override value'),
            $this->text('override_value', $d['override_value'], 'e.g. FR (language) or TN (country)'),
            $this->l('Localized feeds only: the language or country code Meta should apply this feed to. Check Meta\'s "Localized catalog" documentation for the exact code list.')
        );
        $html .= $this->row($this->l('Format'), '<select name="format" class="form-control">' . $this->options(array('xml' => 'XML (RSS)', 'csv' => 'CSV'), $d['format']) . '</select>');
        $html .= $this->row($this->l('Active'), $this->yesNo('active', $d['active']), $this->l('Inactive profiles are skipped by the cron job and their URL returns 404.'));
        $html .= $this->row(
            $this->l('Meta Data Feed ID'),
            $this->text('facebook_feed_id', $d['facebook_feed_id'], $this->l('optional')),
            $this->l('Optional. The numeric ID of this data source in Commerce Manager, used by the "Check Meta errors" button.')
        );
        $html .= '<div class="form-group row"><div class="col-lg-6 col-lg-offset-3"><button type="submit" name="submitEasyProductFeedProfile" value="1" class="btn btn-primary">'
            . $this->l('Save profile') . '</button> ';
        if ($edit) {
            $html .= '<a class="btn btn-default" href="' . $this->formAction() . '">' . $this->l('Cancel') . '</a>';
        }
        $html .= '</div></div></form>';

        return $html;
    }

    /* ---- settings panel ------------------------------------------------ */

    private function renderSettings()
    {
        $s = self::getSettings();
        $html = '<div class="panel" id="epf-settings"><div class="panel-heading">' . $this->l('Settings') . '</div>';
        $html .= '<form method="post" action="' . $this->formAction() . '" class="form-horizontal">';

        $html .= $this->row($this->l('Default brand'), $this->text('brand_default', $s['brand_default']),
            $this->l('Used when a product has no manufacturer. Meta requires a brand (or a GTIN/MPN).'));
        $html .= $this->row(
            $this->l('Description source'),
            '<select name="description_source" class="form-control">' . $this->options(array(
                'long_fallback_short' => $this->l('Long description, fall back to short'),
                'long' => $this->l('Long description'),
                'short' => $this->l('Short description'),
            ), $s['description_source']) . '</select>'
        );
        $html .= $this->row($this->l('Image type'), $this->text('image_type', $s['image_type']),
            $this->l('Name of a product image type (Design > Image Settings). Meta needs at least 500×500 px; "large_default" is usually right. If the name does not exist, original images are used.'));
        $html .= $this->row($this->l('Additional images'), $this->text('additional_images', $s['additional_images']), $this->l('0 to 10.'));
        $html .= $this->row($this->l('Prices include tax'), $this->yesNo('price_with_tax', $s['price_with_tax']));
        $html .= $this->row(
            $this->l('Price decimals'),
            '<select name="price_decimals" class="form-control">' . $this->options(array(
                'currency' => $this->l('Currency default (e.g. 3 for TND)'),
                '2' => $this->l('Always 2 decimals'),
            ), $s['price_decimals']) . '</select>',
            $this->l('Some currencies use 3 decimals. If Meta rejects those prices on the first upload, choose "Always 2 decimals".')
        );
        $html .= $this->row($this->l('Include out-of-stock products'), $this->yesNo('include_out_of_stock', $s['include_out_of_stock']),
            $this->l('If "No", products that are out of stock and not orderable are left out of the feed.'));
        $html .= $this->row($this->l('Send stock quantity'), $this->yesNo('export_quantity', $s['export_quantity']),
            $this->l('Adds quantity_to_sell_on_facebook (used by Facebook / Instagram Shops with checkout).'));

        $html .= '<hr><h4>' . $this->l('Identifiers & variants') . '</h4>';
        $html .= $this->row(
            $this->l('Item ID'),
            '<select name="id_mode" class="form-control">' . $this->options(array('id' => $this->l('Product ID'), 'reference' => $this->l('Reference (SKU)')), $s['id_mode']) . '</select>',
            $this->l('IMPORTANT: this ID must match the content_ids your Meta Pixel sends, otherwise Dynamic Ads cannot match events to products. EasyPixel sends the Product ID. If you switch to Reference, first check that all references are unique and not empty.')
        );
        $html .= $this->row($this->l('ID prefix'), $this->text('id_prefix', $s['id_prefix']), $this->l('Optional, e.g. "shop1_". Letters, digits, - _ . only.'));
        $html .= $this->row(
            $this->l('Export combinations as variants'),
            $this->yesNo('export_combinations', $s['export_combinations']),
            $this->l('Each combination becomes its own catalogue item (id = product ID-combination ID) linked by item_group_id. Note: EasyPixel currently reports the product ID, which will not match variant IDs; keep this "No" until your pixel reports variant IDs.')
        );
        $html .= $this->row($this->l('Append combination to title'), $this->yesNo('append_combination_title', $s['append_combination_title']));
        $html .= $this->row($this->l('Colour attribute group IDs'), $this->text('color_groups', $s['color_groups'], 'e.g. 2'), $this->l('Comma-separated. Leave empty to use PrestaShop colour-type groups.'));
        $html .= $this->row($this->l('Size attribute group IDs'), $this->text('size_groups', $s['size_groups'], 'e.g. 1'), $this->l('Comma-separated.'));

        $html .= '<hr><h4>' . $this->l('Product features to export') . '</h4>';
        $html .= '<p class="help-block">' . $this->l('Feature IDs (Catalog > Features), comma-separated. Used for products that have no combinations, or for material and pattern.') . '</p>';
        $html .= $this->row($this->l('Colour feature IDs'), $this->text('color_features', $s['color_features']));
        $html .= $this->row($this->l('Size feature IDs'), $this->text('size_features', $s['size_features']));
        $html .= $this->row($this->l('Material feature IDs'), $this->text('material_features', $s['material_features']));
        $html .= $this->row($this->l('Pattern feature IDs'), $this->text('pattern_features', $s['pattern_features']));

        $html .= '<hr><h4>' . $this->l('Advanced') . '</h4>';
        $html .= $this->row($this->l('Batch size'), $this->text('batch_size', $s['batch_size']), $this->l('Products processed per batch (50 to 1000). Lower it if generation runs out of memory.'));
        $html .= $this->row($this->l('Meta access token'), $this->text('access_token', $s['access_token']),
            $this->l('Optional. Only used by "Check Meta errors" to read the upload report. Create it in Meta Business Settings > System users, with catalog access. Never shared with anyone else.'));

        $html .= '<div class="form-group row"><div class="col-lg-6 col-lg-offset-3"><button type="submit" name="submitEasyProductFeedSettings" value="1" class="btn btn-primary">'
            . $this->l('Save settings') . '</button></div></div></form></div>';

        return $html;
    }

    /* ---- category mapping ---------------------------------------------- */

    private function getCategoryPage($page)
    {
        $idLang = (int) $this->context->language->id;
        $shop = (int) $this->idShop;
        $from = ' FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category = c.id_category AND cs.id_shop = ' . $shop . ')
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.id_category = c.id_category AND cl.id_shop = ' . $shop . ' AND cl.id_lang = ' . $idLang . ')
            WHERE c.level_depth > 0';
        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*)' . $from);
        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category, c.level_depth, cl.name' . $from . ' ORDER BY c.nleft ASC LIMIT '
            . (int) (($page - 1) * self::CATEGORIES_PER_PAGE) . ', ' . (int) self::CATEGORIES_PER_PAGE
        );

        return array($total, (array) $rows);
    }

    private function renderCategoryMap()
    {
        $page = max(1, (int) Tools::getValue('cat_page', 1));
        list($total, $rows) = $this->getCategoryPage($page);
        $pages = max(1, (int) ceil($total / self::CATEGORIES_PER_PAGE));

        $existing = array();
        if ($rows) {
            $ids = array();
            foreach ($rows as $r) {
                $ids[] = (int) $r['id_category'];
            }
            $found = Db::getInstance()->executeS(
                'SELECT * FROM `' . _DB_PREFIX_ . 'easyproductfeed_category` WHERE id_category IN (' . implode(',', $ids) . ')'
            );
            foreach ((array) $found as $f) {
                $existing[(int) $f['id_category']] = $f;
            }
        }

        $genders = array('' => '—', 'female' => 'female', 'male' => 'male', 'unisex' => 'unisex');
        $ages = array('' => '—', 'newborn' => 'newborn', 'infant' => 'infant', 'toddler' => 'toddler', 'kids' => 'kids', 'adult' => 'adult');

        $html = '<div class="panel" id="epf-categories"><div class="panel-heading">' . $this->l('Google product categories') . '</div>';
        $html .= '<p class="help-block">' . $this->l('Map your categories to Google\'s product taxonomy (an ID such as 1604, or the full path such as "Apparel & Accessories > Clothing"), and set gender / age group for dynamic ads. A product inherits the mapping of the closest mapped parent category. "Exclude" removes every product whose default category is that category (or a child of it) from all feeds.') . ' '
            . '<a href="https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt" target="_blank" rel="noopener">' . $this->l('Google taxonomy list') . '</a></p>';

        if (!$rows) {
            return $html . '<p>' . $this->l('No categories found.') . '</p></div>';
        }

        $html .= '<form method="post" action="' . $this->formAction('&cat_page=' . $page) . '#epf-categories">';
        $ids = array();
        $html .= '<table class="table"><thead><tr><th>' . $this->l('Category') . '</th><th>' . $this->l('Google category') . '</th><th>'
            . $this->l('Gender') . '</th><th>' . $this->l('Age group') . '</th><th>' . $this->l('Exclude') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = (int) $r['id_category'];
            $ids[] = $id;
            $m = isset($existing[$id]) ? $existing[$id] : array('google_category' => '', 'gender' => '', 'age_group' => '', 'excluded' => 0);
            $html .= '<tr><td>' . str_repeat('— ', max(0, (int) $r['level_depth'] - 1)) . $this->h($r['name']) . ' <small class="text-muted">#' . $id . '</small></td>'
                . '<td><input type="text" class="form-control" name="cat[' . $id . '][google]" value="' . $this->h($m['google_category']) . '"></td>'
                . '<td><select class="form-control" name="cat[' . $id . '][gender]">' . $this->options($genders, $m['gender']) . '</select></td>'
                . '<td><select class="form-control" name="cat[' . $id . '][age]">' . $this->options($ages, $m['age_group']) . '</select></td>'
                . '<td><input type="checkbox" name="cat[' . $id . '][excluded]" value="1"' . ((int) $m['excluded'] ? ' checked' : '') . '></td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<input type="hidden" name="cat_ids" value="' . $this->h(implode(',', $ids)) . '">';
        $html .= '<button type="submit" name="submitEasyProductFeedCategories" value="1" class="btn btn-primary">' . $this->l('Save this page') . '</button>';

        if ($pages > 1) {
            $html .= ' &nbsp; ' . $this->l('Page') . ' ';
            for ($i = 1; $i <= $pages; $i++) {
                $html .= $i === $page
                    ? '<strong>' . $i . '</strong> '
                    : '<a href="' . $this->formAction('&cat_page=' . $i) . '#epf-categories">' . $i . '</a> ';
            }
        }

        return $html . '</form></div>';
    }

    private function saveCategoryMap()
    {
        $ids = array();
        foreach (explode(',', (string) Tools::getValue('cat_ids')) as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        $posted = Tools::getValue('cat');
        $posted = is_array($posted) ? $posted : array();
        $genders = array('female', 'male', 'unisex');
        $ages = array('newborn', 'infant', 'toddler', 'kids', 'adult');

        foreach (array_keys($ids) as $id) {
            $row = isset($posted[$id]) && is_array($posted[$id]) ? $posted[$id] : array();
            $google = trim(isset($row['google']) ? (string) $row['google'] : '');
            $gender = isset($row['gender']) && in_array($row['gender'], $genders, true) ? $row['gender'] : '';
            $age = isset($row['age']) && in_array($row['age'], $ages, true) ? $row['age'] : '';
            $excluded = !empty($row['excluded']) ? 1 : 0;

            if ($google === '' && $gender === '' && $age === '' && !$excluded) {
                Db::getInstance()->delete('easyproductfeed_category', 'id_category = ' . (int) $id);
                continue;
            }
            Db::getInstance()->insert('easyproductfeed_category', array(
                'id_category' => (int) $id,
                'google_category' => pSQL(Tools::substr($google, 0, 255)),
                'gender' => pSQL($gender),
                'age_group' => pSQL($age),
                'excluded' => $excluded,
            ), false, true, Db::REPLACE);
        }
    }

    /* ---- cron ---------------------------------------------------------- */

    private function renderCron()
    {
        $token = Configuration::get(self::CONF_TOKEN);
        $url = $this->context->link->getModuleLink($this->name, 'cron', array('token' => $token), null, null, $this->idShop);

        $html = '<div class="panel" id="epf-cron"><div class="panel-heading">' . $this->l('Automatic updates (cron job)') . '</div>';
        $html .= '<p>' . $this->l('Add this to your server\'s crontab (or your hosting panel\'s "Cron jobs") to regenerate all active feeds of this shop every night at 03:00:') . '</p>';
        $html .= '<pre>0 3 * * * curl -s "' . $this->h($url) . '" &gt; /dev/null 2&gt;&amp;1</pre>';
        $html .= '<p>' . $this->l('Cron URL:') . '</p><input type="text" readonly class="form-control" onclick="this.select()" value="' . $this->h($url) . '">';
        $html .= '<p class="help-block">' . $this->l('The token in the URL protects it: keep it private. Meta then fetches your feed on its own schedule (set in Commerce Manager); schedule Meta a little after your cron.') . '</p>';

        return $html . '</div>';
    }

    /* ---- guide --------------------------------------------------------- */

    private function renderGuide()
    {
        $html = '<div class="panel" id="epf-guide"><div class="panel-heading">' . $this->l('Step-by-step guide') . '</div><ol>';
        $steps = array(
            $this->l('Create a "Primary" profile (your main language, currency and country) and click "Generate now". Fix anything in the generation report (missing images, prices, ...).'),
            $this->l('Map your categories to Google categories, and set gender / age group where relevant (Google product categories panel), then regenerate.'),
            $this->l('In Meta Commerce Manager: create a catalogue (or open yours) > Data sources > Add items > Data feed > "Scheduled feed", and paste the profile\'s Feed URL. Choose a schedule that runs after your cron job.'),
            $this->l('Other languages or currencies: create Language / Country override profiles, then in Commerce Manager add them as language / country feeds of the same catalogue (they only carry the fields that change; ids must match the primary feed).'),
            $this->l('Dynamic Ads: connect your Meta Pixel to the catalogue (Commerce Manager > Events). The pixel\'s content_ids must equal the feed item IDs; with EasyPixel that is the Product ID, so keep Item ID = "Product ID" and variants off.'),
            $this->l('Product Sets (Commerce Manager > Catalog > Sets) let you filter the catalogue for ads. They live in Meta, so they survive every feed update.'),
            $this->l('Instagram product tagging and the Facebook Shop tab: link the catalogue to your Facebook Page / Instagram account in Commerce Manager (Shops / Business Settings), after Meta has approved the catalogue.'),
            $this->l('Optional: paste your Meta Data Feed ID on the profile and an access token in Settings to see Meta\'s upload errors here with "Check Meta errors".'),
        );
        foreach ($steps as $step) {
            $html .= '<li style="margin-bottom:6px">' . $this->h($step) . '</li>';
        }

        return $html . '</ol></div>';
    }
}
