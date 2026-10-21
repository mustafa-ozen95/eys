<?php

declare(strict_types=1);

namespace App\Services\Rotation\VSphere;

use App\Services\Rotation\RotationException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * vSphere Web Services (SOAP) için küçük bir istemci.
 *
 * PHP'nin SoapClient'ı kullanılmaz: VMware'in vim25 WSDL'i devasa ve
 * SoapClient onu ayrıştırırken hem çok yavaş hem de sürüm farklarında
 * kırılgan. İhtiyacımız olan beş çağrı küçük XML belgeleri; elle kurmak hem
 * daha hızlı hem de neyin gönderildiğini görünür kılıyor.
 *
 * Oturum çerezle taşınır (`vmware_soap_session`); her istekte geri gönderilir.
 */
class VSphereClient
{
    private ?string $cookie = null;

    /** @var array<string, string> */
    private array $content = [];

    public function __construct(
        private string $baseUrl,
        private int $timeout = 30,
        private bool $verifyTls = false,
    ) {}

    /**
     * Sunucunun kendi bildirdiği nesne kimlikleri.
     *
     * ESXi'de bunlar 'ha-sessionmgr', 'ha-localacctmgr' gibi sabitlerdir ama
     * SABİT YAZILMAZ: vCenter'da farklıdırlar ve sürümle değişebilirler.
     * Sunucuya sormak, tahmin etmekten hem doğru hem ucuz.
     *
     * @return array<string, string>
     */
    public function serviceContent(): array
    {
        if ($this->content !== []) {
            return $this->content;
        }

        $xml = $this->call(
            'RetrieveServiceContent',
            '<_this type="ServiceInstance">ServiceInstance</_this>',
        );

        foreach (['sessionManager', 'accountManager', 'authorizationManager', 'rootFolder'] as $key) {
            $node = $xml->xpath("//*[local-name()='{$key}']")[0] ?? null;

            if ($node !== null) {
                $this->content[$key] = (string) $node;
            }
        }

        if (! isset($this->content['sessionManager'])) {
            throw new RotationException('vSphere servis bilgisi okunamadı.');
        }

        return $this->content;
    }

    public function login(string $username, string $password): void
    {
        $content = $this->serviceContent();

        $this->call('Login', sprintf(
            '<_this type="SessionManager">%s</_this><userName>%s</userName><password>%s</password>',
            $this->escape($content['sessionManager']),
            $this->escape($username),
            $this->escape($password),
        ));
    }

    public function logout(): void
    {
        if ($this->cookie === null) {
            return;
        }

        try {
            $this->call('Logout', sprintf(
                '<_this type="SessionManager">%s</_this>',
                $this->escape($this->serviceContent()['sessionManager']),
            ));
        } catch (Throwable) {
            /* Oturumu kapatamamak turu bozmaz; sunucu kendiliğinden düşürür. */
        }

        $this->cookie = null;
    }

    /**
     * Yerel hesap oluşturur, günceller ya da siler.
     *
     * ÖLÇÜLDÜ (VMware Web Services SDK belgeleri): HostLocalAccountManager
     * üzerinde CreateUser, UpdateUser ve RemoveUser; ilk ikisi HostAccountSpec
     * alıyor.
     */
    public function createUser(string $id, string $password, string $description = ''): void
    {
        $this->accountCall('CreateUser', $this->accountSpec($id, $password, $description));
    }

    public function updateUser(string $id, string $password): void
    {
        $this->accountCall('UpdateUser', $this->accountSpec($id, $password));
    }

    /**
     * ESXi 8.0.3: RemoveUser diğer ikisinden farklı olarak HostAccountSpec
     * değil, düz `<userName>` alır. Belgeler "üçü de HostAccountSpec alır"
     * diyor; WSDL doğru çıktı.
     */
    public function removeUser(string $id): void
    {
        $this->accountCall('RemoveUser', sprintf('<userName>%s</userName>', $this->escape($id)));
    }

    /**
     * Hesaba kök klasör üzerinde yönetici yetkisi verir.
     *
     * Rol -1, vSphere'de yerleşik Administrator rolüdür ve bu değer sabittir.
     * propagate=true olmadan yetki yalnızca klasörün kendisinde geçerli olur.
     */
    public function grantAdmin(string $username): void
    {
        $content = $this->serviceContent();

        if (! isset($content['authorizationManager'], $content['rootFolder'])) {
            throw new RotationException('vSphere yetki yöneticisi bulunamadı.');
        }

        /*
         * Permission.entity BİLİNÇLİ OLARAK BOŞ: resmi tip tanımı
         * "Left unset when calling setPermissions" diyor. Doldurulursa
         * sunucu isteği reddedebilir; hedef zaten dıştaki <entity> ile
         * belirtiliyor.
         */
        $this->call('SetEntityPermissions', sprintf(
            '<_this type="AuthorizationManager">%s</_this>'
            .'<entity type="Folder">%s</entity>'
            .'<permission><principal>%s</principal><group>false</group>'
            .'<roleId>-1</roleId><propagate>true</propagate></permission>',
            $this->escape($content['authorizationManager']),
            $this->escape($content['rootFolder']),
            $this->escape($username),
        ));
    }

    private function accountCall(string $method, string $body): void
    {
        $content = $this->serviceContent();

        if (! isset($content['accountManager'])) {
            throw new RotationException('Bu sunucuda yerel hesap yöneticisi yok (vCenter olabilir).');
        }

        $this->call($method, sprintf(
            '<_this type="HostLocalAccountManager">%s</_this>%s',
            $this->escape($content['accountManager']),
            $body,
        ));
    }

    /**
     * HostAccountSpec.
     *
     * ELEMAN ADI 'user' — 'account' değil. WSDL'den üretilen resmi SDK
     * (govmomi, vim25/types) CreateUserRequestType.User alanını
     * `xml:"user,typeattr"` olarak tanımlıyor; typeattr da xsi:type'ın
     * ZORUNLU olduğunu söylüyor, çünkü alan bir taban tipe bakıyor.
     *
     * Alanlar: id (zorunlu), password, description.
     */
    private function accountSpec(string $id, string $password, string $description = ''): string
    {
        return sprintf(
            '<user xsi:type="HostAccountSpec"><id>%s</id><password>%s</password><description>%s</description></user>',
            $this->escape($id),
            $this->escape($password),
            $this->escape($description),
        );
    }

    /**
     * SOAP çağrısı yapar ve gövdeyi döndürür.
     *
     * Hata gövdesi (soap:Fault) HTTP 500 ile gelir; durum koduna bakıp
     * geçmek, sunucunun anlattığı nedeni kaybetmek olurdu.
     */
    private function call(string $method, string $body): SimpleXMLElement
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema">'
            ."<soapenv:Body><{$method} xmlns=\"urn:vim25\">{$body}</{$method}></soapenv:Body>"
            .'</soapenv:Envelope>';

        $request = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => 'urn:vim25',
        ])->withOptions(['verify' => $this->verifyTls])->timeout($this->timeout);

        if ($this->cookie !== null) {
            $request = $request->withHeaders(['Cookie' => $this->cookie]);
        }

        try {
            $response = $request->send('POST', rtrim($this->baseUrl, '/').'/sdk', ['body' => $envelope]);
        } catch (Throwable $e) {
            throw new RotationException('vSphere sunucusuna ulaşılamadı: '.$e->getMessage());
        }

        $this->rememberCookie($response->header('Set-Cookie'));

        $xml = $this->parse($response->body());

        $fault = $xml->xpath("//*[local-name()='faultstring']")[0] ?? null;

        if ($fault !== null) {
            throw new RotationException('vSphere reddetti: '.trim((string) $fault));
        }

        if ($response->failed()) {
            throw new RotationException('vSphere isteği başarısız ('.$response->status().').');
        }

        return $xml;
    }

    private function parse(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new RotationException('vSphere yanıtı anlaşılamadı.');
        }

        return $xml;
    }

    private function rememberCookie(?string $header): void
    {
        if ($header === null || ! str_contains($header, 'vmware_soap_session')) {
            return;
        }

        $this->cookie = trim(explode(';', $header)[0]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
