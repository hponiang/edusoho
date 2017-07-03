<?php

namespace AppBundle\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Codeages\RestApiClient\RestApiClient;
use Codeages\RestApiClient\Specification\JsonHmacSpecification2;

class MarketingController extends BaseController
{
    public function toMarketingAction(Request $request)
    {
        $merchantUrl = $request->getSchemeAndHttpHost();

        $site = $this->getSettingService()->get('site', array());
        $storage = $this->getSettingService()->get('storage', array());
        $developerSetting = $this->getSettingService()->get('developer', array());

        $user = $this->getCurrentUser();
        $marketingDomain = isset($developerSetting['marketing_domain']) ? $developerSetting['marketing_domain'] : 'http://wyx.edusoho.cn';

        $config = array(
            'accessKey' => $storage['cloud_access_key'],
            'secretKey' => $storage['cloud_secret_key'],
            'endpoint' => $marketingDomain.'/merchant',
        );
        $siteInfo = $this->getSiteInfo();
        $spec = new JsonHmacSpecification2('sha1');
        $client = new RestApiClient($config, $spec);

        try {
            $login = $client->post('/login', array(
                'site' => $siteInfo,
                'url' => $merchantUrl,
                'user_id' => $user['id'],
                'user_name' => $user['nickname'],
            ));
        } catch (\Exception $e) {
            return $this->createMessageResponse('error', $e->getMessage());
        }

        return  $this->redirect($login['url']);
    }

    private function getSiteInfo()
    {
        $site = $this->getSettingService()->get('site', array());

        $site['logo'] = preg_replace('#files/#', '', $site['logo'], 1);
        $consult = $this->getSettingService()->get('consult', array());
        $wechatFile = isset($consult['webchatURI']) ? $consult['webchatURI'] : '';
        $consult['webchatURI'] = preg_replace('#files/#', '', $wechatFile, 1);

        $siteInfo = array(
            'name' => $site['name'],
            'logo' => empty($site['logo']) ? '' : $this->getWebExtension()->getFurl($site['logo']),
            'about' => $site['slogan'],
            'wechat' => empty($consult['webchatURI']) ? '' : $this->getWebExtension()->getFurl($consult['webchatURI']),
            'qq' => empty($consult['qq']) ? '' : $consult['qq'][0]['number'],
            'telephone' => empty($consult['phone']) ? '' : $consult['phone'][0]['number'],
        );

        return $siteInfo;
    }

    public function canOpenMarketing(Request $request)
    {
        return true;
    }

    protected function getFileService()
    {
        return $this->createService('Content:FileService');
    }

    protected function getSettingService()
    {
        return $this->createService('System:SettingService');
    }
}
