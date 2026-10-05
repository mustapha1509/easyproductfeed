<?php
/**
 * Minimal Meta Graph API client used to read the errors Meta reports for the
 * latest upload of a catalogue data feed (Commerce Manager > Data sources).
 *
 * Needs: the numeric ID of the data feed, and an access token with catalog access.
 * Read-only: this never modifies anything in your Meta account.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EasyProductFeedMeta
{
    const API = 'https://graph.facebook.com/v20.0/';

    /**
     * @return array ['success' => bool, 'message' => string, 'upload' => array, 'errors' => array]
     */
    public static function latestUploadReport($feedId, $token)
    {
        $feedId = preg_replace('/[^0-9]/', '', (string) $feedId);
        if ($feedId === '' || trim((string) $token) === '') {
            return array('success' => false, 'message' => 'Enter the Meta Data Feed ID (on the profile) and the access token (in Settings) first.', 'upload' => array(), 'errors' => array());
        }

        $uploads = self::get($feedId . '/uploads', array(
            'limit' => 1,
            'fields' => 'id,start_time,end_time,num_detected_items,num_persisted_items,num_invalid_items,error_count,warning_count,url',
        ), $token);
        if (!$uploads['ok']) {
            return array('success' => false, 'message' => $uploads['error'], 'upload' => array(), 'errors' => array());
        }
        if (empty($uploads['data']['data'][0])) {
            return array('success' => true, 'message' => 'Meta has not recorded any upload for this data feed yet.', 'upload' => array(), 'errors' => array());
        }

        $upload = $uploads['data']['data'][0];
        $errors = self::get($upload['id'] . '/errors', array(
            'limit' => 25,
            'fields' => 'summary,description,severity,total_count',
        ), $token);
        if (!$errors['ok']) {
            return array('success' => false, 'message' => $errors['error'], 'upload' => $upload, 'errors' => array());
        }

        return array(
            'success' => true,
            'message' => '',
            'upload' => $upload,
            'errors' => isset($errors['data']['data']) ? $errors['data']['data'] : array(),
        );
    }

    private static function get($path, array $params, $token)
    {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'The PHP cURL extension is not available.', 'data' => array());
        }
        $params['access_token'] = trim((string) $token);
        $url = self::API . $path . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return array('ok' => false, 'error' => 'Connection error: ' . $error, 'data' => array());
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'Unexpected response from Meta.', 'data' => array());
        }
        if (isset($data['error'])) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown Meta API error';

            return array('ok' => false, 'error' => 'Meta API: ' . $msg, 'data' => array());
        }

        return array('ok' => true, 'error' => '', 'data' => $data);
    }
}
