<?php

declare(strict_types=1);

namespace App\Services\Rotation\Sso;

use App\Services\Rotation\RotationException;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;

/**
 * vCenter SSO yönetim servisiyle konuşur (vmdir hesapları).
 *
 * NEDEN AYRI BİR YOL: SSO kullanıcıları (administrator@vsphere.local) vmdir'de
 * durur. Appliance'ın yerel hesap uçları onlara DOKUNMAZ ve REST API'de
 * karşılığı YOKTUR — cihazın kendi vAPI kataloğunda parola servisi hiç
 * geçmiyor. Geriye SOAP kalıyor.
 *
 * SIRA:
 *   1) STS'ten SAML onaylaması alınır (WS-Trust, /sts/STSService/<alan>).
 *      Kullanıcı adı + parola YETER; sertifika gerekmez. İstenen anahtar türü
 *      Bearer'dır ve SSO yönetim servisi bunu kabul ediyor (ölçüldü).
 *   2) Onaylama, isteğin wsse:Security başlığında taşınır.
 *
 * İKİ AYRINTI ÖLÇÜLEREK BULUNDU, ikisi de sessizce başarısız oluyordu:
 *
 *   - `SOAPAction: urn:sso/version2` ZORUNLU. Başlıksız istek
 *     "Request method namespace 'urn:sso' is not supported" ile reddediliyor;
 *     hata ad alanını suçladığı için insan yanlış yerde arıyor.
 *   - wsse:Security üzerinde `mustUnderstand` niteliği ad alanı taşımadan
 *     yazılamıyor; STS şema hatası döndürüyor. Nitelik hiç yazılmıyor.
 */
class SsoAdminClient
{
    private const ACTION = 'urn:sso/version2';

    private const MANAGEMENT = '<_this type="SsoAdminPrincipalManagementService">principalManagementService</_this>';

    public function __construct(
        private string $baseUrl,
        private string $domain,
        private int $timeout = 30,
    ) {}

    /**
     * WS-Trust ile SAML onaylaması alır.
     *
     * Dönen değer, isteğin güvenlik başlığına olduğu gibi konacak XML
     * parçasıdır; ad alanlarıyla birlikte sökülür, yoksa sunucu onu
     * çözemez.
     */
    public function token(string $username, string $password): string
    {
        $response = $this->send(
            '/sts/STSService/'.$this->domain,
            $this->tokenRequest($username, $password),
            'http://docs.oasis-open.org/ws-sx/ws-trust/200512/RST/Issue',
        );

        $document = new DOMDocument;

        if (! @$document->loadXML($response)) {
            throw new RotationException('STS yanıtı okunamadı.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('saml2', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $assertion = $xpath->query('//saml2:Assertion')->item(0);

        if ($assertion === null) {
            throw new RotationException('vCenter SSO kimliği kabul edilmedi.');
        }

        return (string) $document->saveXML($assertion);
    }

    /** Yeni bir yerel kişi hesabı açar. */
    public function createUser(string $assertion, string $username, string $password): void
    {
        $this->admin($assertion, '<CreateLocalPersonUser xmlns="urn:sso">'.self::MANAGEMENT
            .'<userName>'.$this->escape($username).'</userName>'
            .'<userDetails>'
            .'<description>EYS geçici kurtarma hesabı</description>'
            .'<firstName>EYS</firstName><lastName>Kurtarma</lastName>'
            .'</userDetails>'
            .'<password>'.$this->escape($password).'</password>'
            .'</CreateLocalPersonUser>', 'Kurtarma hesabı açılamadı');
    }

    /** Hesabı bir yerel gruba alır (yetki oradan gelir). */
    public function addToGroup(string $assertion, string $username, string $group): void
    {
        $this->admin($assertion, '<AddUsersToLocalGroup xmlns="urn:sso">'.self::MANAGEMENT
            .'<userIds><name>'.$this->escape($username).'</name>'
            .'<domain>'.$this->escape($this->domain).'</domain></userIds>'
            .'<groupName>'.$this->escape($group).'</groupName>'
            .'</AddUsersToLocalGroup>', 'Kurtarma hesabına yetki verilemedi');
    }

    /**
     * Parolayı değiştirir.
     *
     * Alan adı `userName`'dir, `userId` DEĞİL: ikincisi
     * "Missing required parameter 'userName'" ile reddediliyor (ölçüldü).
     */
    public function resetPassword(string $assertion, string $username, string $newPassword): void
    {
        $this->admin($assertion, '<ResetLocalPersonUserPassword xmlns="urn:sso">'.self::MANAGEMENT
            .'<userName>'.$this->escape($username).'</userName>'
            .'<newPassword>'.$this->escape($newPassword).'</newPassword>'
            .'</ResetLocalPersonUserPassword>', 'Parola değiştirilemedi');
    }

    public function deleteUser(string $assertion, string $username): void
    {
        $this->admin($assertion, '<DeleteLocalPrincipal xmlns="urn:sso">'.self::MANAGEMENT
            .'<principalName>'.$this->escape($username).'</principalName>'
            .'</DeleteLocalPrincipal>', 'Kurtarma hesabı silinemedi');
    }

    /** Yönetim servisine imzasız (Bearer) çağrı. */
    private function admin(string $assertion, string $body, string $failure): string
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
            .'<soap:Header><wsse:Security>'.$assertion.'</wsse:Security></soap:Header>'
            .'<soap:Body>'.$body.'</soap:Body></soap:Envelope>';

        return $this->send('/sso-adminserver/sdk/'.$this->domain, $envelope, self::ACTION, $failure);
    }

    private function send(string $path, string $body, string $action, ?string $failure = null): string
    {
        $response = Http::withOptions(['verify' => false])
            ->withHeaders(['Content-Type' => 'text/xml; charset=UTF-8', 'SOAPAction' => $action])
            ->timeout($this->timeout)
            ->send('POST', $this->baseUrl.$path, ['body' => $body]);

        $content = $response->body();

        /*
         * Neden GÖVDEDE anlatılıyor; yalnızca duruma bakmak "500" demekle
         * yetinir ve politika ihlali gibi düzeltilebilir hataları gizlerdi.
         */
        if (preg_match('/<faultstring>(.*?)<\/faultstring>/s', $content, $match) === 1) {
            throw new RotationException(($failure ?? 'vCenter SSO reddetti').': '.trim(strip_tags($match[1])));
        }

        if ($response->failed()) {
            throw new RotationException(($failure ?? 'vCenter SSO isteği başarısız').' ('.$response->status().').');
        }

        return $content;
    }

    private function tokenRequest(string $username, string $password): string
    {
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $until = gmdate('Y-m-d\TH:i:s.000\Z', time() + 600);
        $user = $this->escape($username);
        $pass = $this->escape($password);

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
                       xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"
                       xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd"
                       xmlns:wst="http://docs.oasis-open.org/ws-sx/ws-trust/200512">
          <soap:Header><wsse:Security>
            <wsu:Timestamp><wsu:Created>{$now}</wsu:Created><wsu:Expires>{$until}</wsu:Expires></wsu:Timestamp>
            <wsse:UsernameToken>
              <wsse:Username>{$user}</wsse:Username>
              <wsse:Password>{$pass}</wsse:Password>
            </wsse:UsernameToken>
          </wsse:Security></soap:Header>
          <soap:Body><wst:RequestSecurityToken>
            <wst:TokenType>urn:oasis:names:tc:SAML:2.0:assertion</wst:TokenType>
            <wst:RequestType>http://docs.oasis-open.org/ws-sx/ws-trust/200512/Issue</wst:RequestType>
            <wst:Lifetime><wsu:Created>{$now}</wsu:Created><wsu:Expires>{$until}</wsu:Expires></wst:Lifetime>
            <wst:KeyType>http://docs.oasis-open.org/ws-sx/ws-trust/200512/Bearer</wst:KeyType>
            <wst:SignatureAlgorithm>http://www.w3.org/2001/04/xmldsig-more#rsa-sha256</wst:SignatureAlgorithm>
            <wst:Renewing Allow="true" OK="false"/>
            <wst:Delegatable>true</wst:Delegatable>
          </wst:RequestSecurityToken></soap:Body>
        </soap:Envelope>
        XML;
    }

    /** Parolada tırnak ve & olabiliyor; kaçış yapılmazsa zarf bozulur. */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
