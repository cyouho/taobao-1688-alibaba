<?php
/**
 * Created by PhpStorm.
 * User: zhang.fan
 * Date: 2020/01/29
 * Time: 8:59
 */

class Search_ImageSearch
{
    /**
     * baidu, onesix, taobao, alibaba全てのEndPoint
     * @var array $siteHosts
     */
    private $siteHosts = [];

    /**
     * ajaxからの画像検索入り口
     *
     * @param array $params
     * @return string
     */
    public function imageSearch($params)
    {
        $this->siteHosts = [
            'baidu' => Zend_Registry::get('config')->baidu->image->search->toArray(),
            'onesix' => Zend_Registry::get('config')->onesix->image->search->toArray(),
            'taobao' => Zend_Registry::get('config')->taobao->image->search->toArray(),
            'alibaba' => Zend_Registry::get('config')->alibaba->image->search->toArray(),
        ];

        switch ($params['site']) {
            case 'baidu':
                $url = $this->getBaiduImageSearchUrl($params['image'], $params['site']);
                break;
            case 'onesix':
                $url = $this->getOnesixImageSearchUrl($params['image'], $params['site']);
                break;
            case 'taobao':
                $url = $this->getTaobaoImageSearchUrl($params['image'], $params['site']);
                break;
            case 'alibaba':
                $url = $this->getAlibabaImageSearchUrl($params['image'], $params['site']);
                break;
            default:
                return '#';
                break;
        }

        return $url;
    }

    /**
     * Baidu画像検索用
     *
     * @param string $image
     * @param string $site
     * @return string
     */
    private function getBaiduImageSearchUrl($image, $site)
    {
        $postData = [
            'from' => 'pc',
            'image' => $image,
        ];
        $postData = http_build_query($postData);

        $result = $this->uploadImage($postData, $this->siteHosts['baidu']['post']['url'], $site);

        if (! $result) {
            return $this->getDefaultSiteUrl($site);
        } else {
            $sign = $result['data']['sign'];
        }

        // baidu画像検索用URL構造する
        $url = $this->siteHosts['baidu']['result']['url'] . $sign . '&f=all&tn=pc&tpl_from=pc';

        return $url;
    }

    /**
     * Onesix画像検索用
     *
     * @param string $image
     * @param string $site
     * @return string
     */
    private function getOnesixImageSearchUrl($image, $site)
    {
        // alibabaのtimeサーバーにtimestampを取得
        $postData = [
            'serviceIds' => 'cbu.searchweb.config.system.currenttime',
            'outfmt' => 'json',
        ];
        $postData = http_build_query($postData);
        $result = $this->getCurlResult($postData, $this->siteHosts['onesix']['timestamp']['post']['url']);
        if (! $result) {
            return $this->getDefaultSiteUrl($site);
        }

        $timestamp = (string)$result['cbu.searchweb.config.system.currenttime']['dataSet'];
        $appTemp = 'pc_tusou' . ';' . $timestamp;
        $appkey = base64_encode($appTemp);

        // alibabaにアップする認証データを取得
        $postData = [
            'appName' => 'pc_tusou',
            'appKey' => $appkey,
        ];
        $postData = http_build_query($postData);
        $result = $this->getCurlResult($postData, $this->siteHosts['onesix']['sign']['post']['url'], $site);
        if (! $result) {
            return $this->getDefaultSiteUrl($site);
        }
        $dataArray = $result['data'];

        // alibabaのOSSサーバーに検索画像をアップする
        $postTimestamp = (string)time() * 1000;
        $key = 'cbuimgsearch/' . $this->getRandomString(10) . (string)$postTimestamp . '.jpeg';
        $name = $this->getRandomString(5) . '.jpeg';
        $file = $this->getFileContents($image, $site);
        if (! $file) {
            return $this->getDefaultSiteUrl($site);
        }
        $postData = [
            'name' => $name,
            'key' => $key,
            'policy' => $dataArray['policy'],
            'OSSAccessKeyId' => $dataArray['accessid'],
            'success_action_status' => 200,
            'callback' => '',
            'signature' => $dataArray['signature'],
            'file' => $file,
        ];
        // onesixのサーバーと3回目通信する時jsonの結果チェック不要のため、$lastOneCheck 識別子を $site の代わりに使う
        $lastOneCheck = 'onesixLastOne';
        $this->uploadImage($postData, $this->siteHosts['onesix']['img']['post']['url'], $lastOneCheck);
        if (! $result) {
            return $this->getDefaultSiteUrl($site);
        }

        // 1688画像検索用URLを構造する
        $url = $this->siteHosts['onesix']['result']['url'] . $key;

        return $url;
    }

    /**
     * Taobao画像検索用
     *
     * @param string $image
     * @param string $site
     * @return string
     */
    private function getTaobaoImageSearchUrl($image, $site)
    {
        // 一時的な画像ファイル作成、uploadする
        $result = $this->uploadTempImage($image, $site);
        if (! $result) {
            return $this->getDefaultSiteUrl($site);
        }
        $imageName = $result['name'];

        // taobao画像検索用URLを構造する
        $url = $this->siteHosts['taobao']['result']['url'] . $imageName . '&app=imgsearch';

        return $url;
    }

    /**
     * Alibaba画像検索用
     *
     * @param string $image
     * @param string $site
     * @return string
     */
    private function getAlibabaImageSearchUrl($image, $site)
    {
        $result = $this->uploadTempImage($image, $site);
        if (! $result) {
            return $this->getDefaultSiteUrl($site);
        }

        $imageUrl = $result['fs_url'];

        // alibaba画像検索用URLを構造する
        $url = $this->siteHosts['alibaba']['result']['url'] . $imageUrl . '&sourceFrom=imageupload';

        return $url;
    }

    /**
     * BaiduやTaobao系の画像検索サーバーと通信する
     *
     * @param array $postdata
     * @param string $url
     * @param string $site
     * @param array $headers
     * @return array $curl_result
     */
    private function getCurlResult($postdata = null, $url = null, $site = null, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $curlResult = [
            'httpCode' => $httpCode,
            'content' => $result,
        ];

        $curlResult = $this->cURLOfImageSearchExceptionalHandling($curlResult, $site);

        return $curlResult;
    }

    /**
     * @param array $postdata
     * @param string $url
     * @param string $site
     * @return mixed
     */
    private function uploadImage($postdata, $url, $site)
    {
        return $this->getCurlResult($postdata, $url, $site);
    }

    /**
     * ランダム文字列を生成する
     *
     * @param int $len
     * @param string $chars
     * @return string
     */
    private function getRandomString($len, $chars = '')
    {
        if (empty($chars)) {
            $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        }

        for ($i = 0, $str = '', $lc = strlen($chars) - 1; $i < $len; $i++) {
            $str .= $chars[mt_rand(0, $lc)];
        }
        return $str;
    }

    /**
     * cURLの結果をチェックする
     *
     * @param array $curlResult
     * @param string $site
     * @return bool|mixed
     */
    private function cURLOfImageSearchExceptionalHandling($curlResult, $site)
    {
        switch ($site) {
            case 'onesixLastOne':
                $result = $this->checkHttpCode($curlResult['httpCode']);
                break;
            default:
                $result = json_decode($curlResult['content'], true);
                $checkHttpCode = $this->checkHttpCode($curlResult['httpCode']);
                $checkJsonDecode = $this->checkJsonDecode($result);
                $checkResult = [
                    'checkHttpCode' => $checkHttpCode,
                    'checkJsonDecode' => $checkJsonDecode,
                ];
                $result = (array_sum($checkResult) === 0) ? false : $result;
                break;
        }
        return $result;
    }

    /**
     * HttpCodeチェックする
     *
     * @param string $httpCode
     * @return bool
     */
    private function checkHttpCode($httpCode)
    {
        if ($httpCode !== 200) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * json_decodeチェックする
     *
     * @param string $jsonDecode
     * @return bool
     */
    private function checkJsonDecode($jsonDecode)
    {
        if (! $jsonDecode) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Imageファイルの中身をgetする
     *
     * @param string $image
     * @return bool|string
     */
    private function getFileContents($image)
    {
        $file = file_get_contents($image);

        if (! $file) {
            return false;
        }

        return $file;
    }

    /**
     * 全てのdefaultファイルURLを取得する
     *
     * @param string $site
     * @return string
     */
    private function getDefaultSiteUrl($site)
    {
        switch ($site) {
            case 'baidu':
                $defaultSiteUrl = $this->siteHosts['baidu']['default']['url'];
                break;
            case 'onesix':
                $defaultSiteUrl = $this->siteHosts['onesix']['default']['url'];
                break;
            case 'taobao':
                $defaultSiteUrl = $this->siteHosts['taobao']['default']['url'];
                break;
            case 'alibaba':
                $defaultSiteUrl = $this->siteHosts['alibaba']['default']['url'];
                break;
            default:
                $defaultSiteUrl = '#';
                break;
        }
        return $defaultSiteUrl;
    }

    /**
     * 一時的な画像ファイルをアップする
     *
     * @param string $image
     * @param string $site
     * @return bool|mixed
     */
    private function uploadTempImage($image, $site)
    {
        $file = $this->getFileContents($image);
        if (! $file) {
            return false;
        }

        $temp = tmpfile();
        fwrite($temp, $file);

        // 一時的ファイル生成チェック
        if (file_exists(stream_get_meta_data($temp)['uri']) && file_get_contents(stream_get_meta_data($temp)['uri'])) {
            $path = stream_get_meta_data($temp)['uri'];
        } else {
            return false;
        }

        switch ($site) {
            case 'taobao':
                $postData = [
                    'imgfile' => curl_file_create($path, 'image/jpeg'),
                ];
                $result = $this->uploadImage($postData, $this->siteHosts['taobao']['img']['post']['url'], $site);
                break;
            case 'alibaba':
                $imageName = $this->getRandomString(5);
                $postData = [
                    'file' => curl_file_create($path, 'image/jpeg'),
                    'scene' => 'scImageSearchNsRule',
                    'name' => (string)$imageName . '.jpg',
                ];
                $result = $this->uploadImage($postData, $this->siteHosts['alibaba']['img']['post']['url'], $site);
                break;
            default:
                $result = false;
        }
        fclose($temp);
        return $result;
    }
}
?>
