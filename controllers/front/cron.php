<?php
/**
 * Generates feeds. Used by the server cron job AND by the admin "Generate in background" button.
 *   index.php?fc=module&module=easyproductfeed&controller=cron&token=Y            -> all active profiles of this shop
 *   index.php?fc=module&module=easyproductfeed&controller=cron&token=Y&id_profile=X -> one profile
 *
 * The request keeps running after the caller disconnects, so a "fire and forget"
 * call from the admin (or a cron `curl`) does not need to wait for completion.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EasyProductFeedCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;

    public function postProcess()
    {
        ignore_user_abort(true);
        @set_time_limit(0);

        $token = (string) Tools::getValue('token');
        $expected = (string) Configuration::get('EASYPRODUCTFEED_TOKEN');
        if ($expected === '' || !hash_equals($expected, $token)) {
            header('HTTP/1.1 403 Forbidden');
            echo 'Invalid token';
            exit;
        }

        $idProfile = (int) Tools::getValue('id_profile');
        if ($idProfile > 0) {
            $ids = array($idProfile);
        } else {
            $ids = array();
            $rows = Db::getInstance()->executeS(
                'SELECT id_profile FROM `' . _DB_PREFIX_ . 'easyproductfeed_profile`
                 WHERE active = 1 AND id_shop = ' . (int) $this->context->shop->id . ' ORDER BY id_profile'
            );
            foreach ((array) $rows as $row) {
                $ids[] = (int) $row['id_profile'];
            }
        }

        $lines = array();
        foreach ($ids as $id) {
            $r = EasyProductFeedGenerator::run($id);
            $lines[] = sprintf('profile %d: %s - %s', $id, $r['success'] ? 'OK' : 'ERROR', $r['message']);
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=UTF-8');
        echo $lines ? implode("\n", $lines) : 'No active profiles to generate.';
        exit;
    }
}
