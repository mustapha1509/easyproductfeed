<?php
/**
 * Serves a generated feed file. URL:
 *   index.php?fc=module&module=easyproductfeed&controller=feed&id_profile=X&token=Y
 * Meta's scheduled fetch (Commerce Manager > Data sources) points at this URL.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EasyProductFeedFeedModuleFrontController extends ModuleFrontController
{
    public $auth = false;

    public function postProcess()
    {
        $token = (string) Tools::getValue('token');
        $expected = (string) Configuration::get('EASYPRODUCTFEED_TOKEN');
        if ($expected === '' || !hash_equals($expected, $token)) {
            header('HTTP/1.1 403 Forbidden');
            echo 'Invalid token';
            exit;
        }

        $profile = EasyProductFeedGenerator::getProfile((int) Tools::getValue('id_profile'));
        if (!$profile || !(int) $profile['active']) {
            header('HTTP/1.1 404 Not Found');
            echo 'Feed not found or disabled';
            exit;
        }

        $path = EasyProductFeedGenerator::getFilePath($profile);
        if (!is_file($path)) {
            header('HTTP/1.1 404 Not Found');
            echo 'This feed has not been generated yet. Generate it from the module page or wait for the cron job.';
            exit;
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        $isCsv = substr($path, -4) === '.csv';
        header('Content-Type: ' . ($isCsv ? 'text/csv' : 'application/xml') . '; charset=UTF-8');
        header('Content-Length: ' . filesize($path));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');
        header('Cache-Control: no-cache, must-revalidate');
        readfile($path);
        exit;
    }
}
