# 01 — NICEPAY PG-Web v3 Protokol Uyumluluğu

> PR #3 sistematik review · düşmanca doğrulamalı · 16 bulgu

## Özet

İmza katmanı bu PR'ın en güçlü tarafı: dört akışın (auth request/response, approval request/response, cancel request/response, net-cancel) SHA-256 bileşim sırası v2.0.8 manüeliyle birebir uyuşuyor ve `tests/unit/NicePaySignatureIntegrationTest.php` gerçek satıcı digest'lerini literal olarak pinliyor (bağımsız olarak yeniden hesaplayıp doğruladım — 9.1/9.2/9.3/9.4/9.7 ve 12-byte padded varyant hepsi tutuyor, tautoloji değil). EdiDate Asia/Seoul'de üretiliyor ve PHP default TZ'den bağımsızlığı test ediliyor; ResultCode tabloları metod-başına doğru ve bilinmeyen metotta fail-closed; SSRF allowlist'i 20 saldırgan URL vakasıyla karakterize edilmiş; net-cancel artık imza + `2001` + TID/tutar binding doğruluyor. Zayıflıklar protokolün *mutlu yolunda* değil, *başarısızlık yolunda* ve *yanıt formatı varsayımlarında* yoğunlaşıyor: gerçek bir başarısız/iptal edilmiş authentication'ın izlediği kod yolu ölü (PROTO-001), auth-return `Amt` yanıt normalizasyonu yerine istek normalizasyonuyla doğrulanıyor (PROTO-002), `EdiType` hiç gönderilmediği halde yanıt koşulsuz JSON varsayılıyor (PROTO-003) ve net-cancel'ın tek denemesi başarısız olursa hem retry hem de operatöre görünürlük yok (PROTO-004/005). Ayrıca protokolün opsiyonel ama Kore pazarında pratikte zorunlu alanları (`ReqReserved`, `SelectQuota`, `TransType`) hiç kullanılmıyor ve VBANK yarım bırakılmış halde ölü/tehlikeli kod olarak duruyor.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **B** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 1 |
| 🟡 Orta | 12 |
| 🔵 Düşük | 3 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 4 |
| Bağımsız doğrulama kararı alan bulgu | 5 / 16 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 7 |

## Güçlü yönler

- Dört akışın SignData/Signature bileşim SIRASI v2.0.8 ile birebir doğru: auth req = EdiDate+MID+Amt+Key (class-nicepay-api.php:98-101), auth resp = AuthToken+MID+Amt+Key (:107-114), approval req = AuthToken+MID+Amt+EdiDate+Key (:120-123), approval resp = TID+MID+Amt+Key (:129-136), cancel req = MID+CancelAmt+EdiDate+Key (:142-145), cancel/net-cancel resp = TID+MID+CancelAmt+Key (:151-158). Net-cancel bilinçli olarak approval imzasını yeniden kullanıyor (:499-503) — spec'e uygun.
- İmza testleri gerçek sözleşme testi: tests/unit/NicePaySignatureIntegrationTest.php'deki tüm golden digest'leri bağımsız olarak yeniden hesapladım (475979a5…, cc94db19…, 4916540b…, 9439b21e…, 59c36831…, e6959c2f…) — hepsi dokümante edilen preimage'lerle tutuyor. Testler kendi kendini doğrulamıyor, literal digest'e karşı assert ediyor.
- Tüm imza karşılaştırmaları hash_equals ile timing-safe (class-nicepay-api.php:113, 135, 157) ve non-string girdilere karşı tip-güvenli fail-closed (:108-110, 130-132, 152-154).
- EdiDate zaman dilimi doğru ve sağlam: DateTimeImmutable + Asia/Seoul (class-nicepay-api.php:74-77), PHP default TZ'den bağımsızlığı tests/unit/NicePayApiTest.php:89-110'da gerçekten test ediliyor. VbankExpDate da aynı disiplinde (:667-672).
- is_success_code metot-başına tablo tutuyor ve bilinmeyen/boş metotta fail-closed dönüyor (class-nicepay-api.php:650-652); tests/unit/NicePayResultCodeTest.php:124-127 bunu açıkça pinliyor. Sık kaçırılan cancel kodu 2211, 2001 ile birlikte doğru işleniyor (:660-662).
- Approval response imza doğrulaması Amt'yi byte-for-byte kullanıyor, numerik binding ise ayrı bir kanonikleştiriciden geçiyor (class-nicepay-inbound-validator.php:161-165) — 12-byte sıfır-dolgulu yanıt hem imzada hem binding'de doğru ele alınıyor ve test ediliyor (NicePayInboundValidatorTest:329-347).
- NextAppURL/NetCancelURL SSRF allowlist'i host+path+scheme+port+userinfo+query+fragment'i birlikte kısıtlıyor (class-nicepay-api.php:178-200) ve tests/unit/NicePayUrlPolicyTest.php 20 ayrı spoofing vektörünü (trailing dot, %2e encoding, userinfo, subdomain, lookalike suffix) karakterize ediyor.
- Net-cancel artık gerçek: imza doğrulaması + TID/tutar binding + ResultCode '2001' zorunluluğu (class-nicepay-api.php:523-547), ve sonucu her çağrı yerinde ledger'a audit olarak yazılıyor (nicepay-functions.php:236-271). Doğrulanamayan ters çevirme 'needs_reconciliation' olarak fail-closed işaretleniyor.
- request_cancel'da CancelAmt/TID/Moid ve SignData, extension parametreleriyle ezilemiyor ve bu tests/unit/NicePayTransportTest.php:411-439'da saldırgan girdisiyle test ediliyor.
- Transport politikası sıkı ve test edilmiş: redirection=0, sslverify=true, UTF-8 Content-Type, 1-120 sn sınırlı timeout ve URL'e özel scoped CURLOPT_CONNECTTIMEOUT hook'u finally ile kaldırılıyor (class-nicepay-api.php:208-275, NicePayTransportTest:87-127).
- Tutar aritmetiği baştan sona string tabanlı (nicepay-functions.php:1462-1647) — float dönüşümü yok, 12 haneli protokol sınırı (999999999999) DB decimal(14,2) ile tutarlı.

## Bulgular

### PROTO-004 — Net-cancel tek denemelik ve alternatif DC endpoint'ine düşmüyor — anlık bir ağ dalgalanması kart hold'unu kalıcı olarak askıda bırakıyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | net-cancel-reliability |
| **Konum** | [includes/class-nicepay-api.php:435](../../../includes/class-nicepay-api.php#L435) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

````php
`abort_approval()` net-cancel'ı bir kez çağırıyor ve sonucu doğrudan sonuca çeviriyor:
```php
$net_cancel = $this->request_net_cancel( $auth_data );   // :435
if ( is_wp_error( $net_cancel ) ) {
    return new WP_Error( 'nicepay_approval_reconciliation_required', ... 'net_cancel_status' => 'unknown', ... );
}
```
`request_net_cancel()` içinde de tek POST var:
```php
$response = $this->post_to_nicepay( $net_cancel_url, $params, 'net_cancel' );   // :509
if ( is_wp_error( $response ) ) { ... return $response; }
```
Dört çağrı yerinin hiçbirinde (api:435, nicepay-functions.php:238, gateway:551, return-handler:149) tekrar denemesi yok. `NetCancelURL` NICEPAY'in verdiği tek DC'ye işaret ediyor; oysa dokümantasyonun kendi tablosu DC1 ve DC2 olmak üzere iki net-cancel endpoint'i listeliyor (docs/API-REFERENCE.md:52-53) ve `$allowed_hosts` her ikisini de kabul ediyor (:163-167) — yani ikinci endpoint'e düşmek teknik olarak mümkün ama uygulanmamış. Önceki analiz raporu da bunu açıkça istemişti: "retry the net cancel once before giving up" (docs/analysis/02-protocol-conformance.md:202).
````

**Başarısızlık senaryosu**

NICEPAY DC1 tarafında 20 saniyelik bir kesinti var. Approval POST'u 30 sn timeout ile `WP_Error` dönüyor. `abort_approval()` aynı kesinti penceresi içinde `NetCancelURL` (dc1-api) üzerine tek net-cancel POST'u atıyor, o da timeout oluyor. Sonuç: `net_cancel_status='unknown'`, `needs_reconciliation`, sipariş on-hold. Kesinti 5 saniye sonra bitiyor ama plugin bir daha denemiyor — müşterinin 50.000 KRW'si kartında bloke, sipariş bekliyor.

**Etki**

Net-cancel, askıda kalmış bir kart hold'unu koruyan TEK mekanizma. Approval çağrısı zaten bir ağ sorunu yüzünden başarısız olduğunda, hemen ardından yapılan tek net-cancel denemesinin de aynı sorundan etkilenme olasılığı yüksek (korelasyonlu hata). Bu durumda işlem kalıcı olarak `needs_reconciliation` olur, sipariş `on-hold`'a düşer ve tüccar NICEPAY konsolunda elle iptal etmek zorunda kalır. Yüksek hacimde bu, günde onlarca manuel mutabakat demek ve müşteri kartında saatlerce/günlerce duran bloke tutar demek.

**Öneri**

Net-cancel'a sınırlı, idempotent bir yeniden deneme ve DC fallback ekle. Aynı `AuthToken`+`TID`+`Amt`+`NetCancel=1` gövdesi tekrarlandığında NICEPAY zaten idempotent davranır (aynı TID ikinci kez iptal edilemez), bu yüzden tekrar güvenlidir:
```php
private function request_net_cancel_with_retry( array $auth_data, $attempts = 2 ) {
    $urls = $this->net_cancel_endpoints( $auth_data['NetCancelURL'] ); // dc1 → dc2 sırası
    $last = null;
    foreach ( $urls as $url ) {
        for ( $i = 0; $i < $attempts; $i++ ) {
            $ctx = $auth_data; $ctx['NetCancelURL'] = $url;
            $last = $this->request_net_cancel( $ctx );
            if ( ! is_wp_error( $last ) ) { return $last; }
            // yalnızca transport/timeout hatalarında tekrar dene; imza/binding hatasında ASLA
            if ( ! in_array( $last->get_error_code(), array( 'http_request_failed', 'nicepay_net_cancel_http_error' ), true ) ) { return $last; }
            usleep( 500000 * ( $i + 1 ) );
        }
    }
    return $last;
}
```
Deneme sayısını `nicepay_net_cancel_attempts` filtresiyle açıp, kaç deneme yapıldığını `net_cancel_result_msg` alanına yaz. `nicepay_net_cancel_rejected`, `nicepay_net_cancel_signature_error` ve `nicepay_net_cancel_binding_error` durumlarında ASLA tekrar deneme (fail-closed korunmalı).

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Net-cancel tek denemelik: `abort_approval()` (includes/class-nicepay-api.php:435) ve diğer üç çağrı yeri (nicepay-functions.php:238, class-nicepay-gateway.php:551, class-nicepay-return-handler.php:149) `request_net_cancel()`'ı bir kez çağırıyor; `request_net_cancel()` içinde de tek POST var (:509). Bu OLGUSAL kısım doğrulandı. Ancak iddianın iki dayanağı zayıf/yanlış: (1) "DC2'ye fallback teknik olarak mümkün ama uygulanmamış" — `$allowed_hosts` (:163-167) dc1/dc2'yi kabul ediyor çünkü NICEPAY `NetCancelURL`'i işlem başına dinamik döndürüyor (docs/API-REFERENCE.md:56 "returned dynamically... Do NOT hardcode it"); DC1'in ürettiği TID/AuthToken'ın DC2'de net-cancel edilebileceğine dair repoda hiçbir spec kanıtı yok. Önerilen DC fallback spec-dışı bir tahmindir ve `nicepay_net_cancel_binding_error`/signature hatası üretmesi muhtemeldir. (2) Tek denemelik davranış belgelenmemiş bir unutkanlık değil, açıkça yazılmış bir tasarım kararı: docs/API-REFERENCE.md:409 "Exact vendor retry semantics remain part of DG-01/DG-02 certification; ambiguous results are recorded as `needs_reconciliation`, not treated as success or blindly retried." Yani doküman-kod uyuşmazlığı YOK. Ayrıca istismar edilebilirlik sıfır: tetikleyici PG/ağ arızası, saldırgan girdisi değil; sonuç fail-closed (needs_reconciliation + on-hold + admin banner + refund kilidi + retention koruması). Kalan geçerli çekirdek: kısa timeout'lu, transport hatasına özgü TEK bir yeniden deneme (tercihen aynı NetCancelURL'e) yok. İn-request 2 endpoint × 2 deneme × 30 sn timeout (:209-213) önerisi ise PHP max_execution_time'ı aşıp ledger yazımını hiç yapamadan isteği öldürerek bugünkü davranıştan DAHA KÖTÜ sonuç üretebilir; doğru düzeltme kısa timeout'lu tek retry ve/veya arka plan (cron) mutabakat işidir.
- Gerekçe: Kodu okudum: iddia edilen tüm satırlar dosyanın şu anki haliyle birebir eşleşiyor ve hiçbir yerde retry/fallback yok — olgusal çekirdek doğru. Sonuç/istismar merceğinden bakınca severity "high" abartılı: (a) tetikleyici saldırgan tarafından üretilemez, yalnızca NICEPAY DC'sinde eşzamanlı kesinti/timeout gerektirir; (b) başarısızlık sessiz değil, fail-closed olarak kayda geçer (nicepay-functions.php:249-258 status/approval_state=needs_reconciliation, reconciliation_status=required, auth_token saklanır; gateway:533 on-hold; admin/class-nicepay-transactions.php:566,634-644 sayaçlı admin uyarısı; :800-802 refund/cancel kilidi; retention.php:310-313 satırı otomatik silmekten korur) — yani "günlerce fark edilmeyen kayıp" senaryosu geçerli değil, mutabakat kuyruğu görünür durumda. (c) İddianın "doküman kendi tablosunda iki endpoint listeliyor, demek ki fallback bekleniyor" çıkarımı hatalı; tablo yalnızca NICEPAY'in hangi ana bilgisayarları döndürebileceğini belgeliyor ve API-REFERENCE.md:56 URL'in işlem başına dinamik olduğunu, hardcode edilmemesini söylüyor. Cross-DC net-cancel'ın kabul edileceğine dair kanıt yok — öneri spekülatif. (d) İddia "doküman-kod uyuşmazlığı"na yaklaşırken API-REFERENCE.md:409 tam tersini söylüyor: belirsiz sonuçların körlemesine yeniden denenmemesi bilinçli ve belgelenmiş bir karar; docs/analysis/02-protocol-conformance.md:202'deki eski öneri de aynı satırda talep edilen diğer maddelerle (imza doğrulama, ResultCode 2001, ledger flag, order note) birlikte zaten uygulanmış — sadece "retry once" kısmı bilinçli olarak alınmamış. (e) Önerilen düzeltmenin kendisi risk taşıyor: her POST 30 sn genel timeout ile çalışıyor (api:209-213) ve tüm akış müşterinin senkron checkout isteği içinde; 2 URL × 2 deneme + usleep, tipik max_execution_time'ı aşıp `nicepay_update_transaction` çağrısına hiç ulaşamadan süreci sonlandırabilir — o zaman ledger'da needs_reconciliation kaydı bile olmaz. Bu yüzden bulgu "eksik dayanıklılık / hardening" olarak medium; "high" değil.

---

### PROTO-001 — Gerçek bir başarısız/iptal edilmiş NICEPAY authentication'ı `AuthResultCode` dalına hiç ulaşmıyor — ölü kod, yanlış müşteri mesajı, sıfır defter kaydı

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | protocol-failure-path |
| **Konum** | [includes/class-nicepay-inbound-validator.php:36](../../../includes/class-nicepay-inbound-validator.php#L36) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````php
`validate_auth_return()` önce 10 alanı koşulsuz zorunlu kılıyor:
```php
$required = array( 'Moid','MID','Amt','PayMethod','AuthResultCode','AuthToken','TxTid','Signature','NextAppURL','NetCancelURL' );
$missing = self::first_missing_field( $payload, $required );   // :48
```
ve `first_missing_field()` her alanı `'' === trim(...)` ile boş kabul edip reddediyor (:192-202). AuthResultCode kontrolü ise ancak 107. satırda geliyor:
```php
if ( '0000' !== $payload['AuthResultCode'] ) {
    return self::error( 'nicepay_inbound_auth_failed', ... );   // :107-109
}
```
Ancak NICEPAY başarısız bir authentication'da (kullanıcı iptali, kart reddi) `AuthToken`/`TxTid`/`Signature`/`NextAppURL`/`NetCancelURL` alanlarını üretmez — Signature'ın tanımı zaten `sha256(AuthToken+MID+Amt+Key)` olduğundan AuthToken yokken imza da yoktur (docs/API-REFERENCE.md:124-133). Dolayısıyla akış her zaman `nicepay_inbound_missing_field` ile dönüyor, `nicepay_inbound_auth_failed` asla üretilmiyor. Testteki fixture da bunu gizliyor: `'auth rejected' => array( array(), array( 'AuthResultCode' => '3001' ), 'nicepay_inbound_auth_failed' )` (NicePayInboundValidatorTest.php:169) — geçerli AuthToken + geçerli Signature + geçerli NextAppURL taşıyan, gerçekte var olmayan bir payload.
````

**Başarısızlık senaryosu**

Müşteri CARD seçip NICEPAY penceresinde kartını reddettiriyor. NICEPAY ReturnURL'e `AuthResultCode=F100&AuthResultMsg=한도초과&Moid=WC42_...&MID=...&Amt=50000&PayMethod=CARD` POST ediyor; AuthToken/TxTid/Signature/NextAppURL boş. `first_missing_field` 'AuthToken'da duruyor → `nicepay_inbound_missing_field`. Müşteri "We could not verify this payment attempt." görüyor, defterde WC42 için satır hâlâ `pending`, tüccar ne olduğunu asla öğrenemiyor.

**Etki**

Üç somut sonuç: (1) `class-nicepay-return-handler.php:80-82`'deki nazik mesaj ("Payment authentication was not completed. You may try again.") ölü; iptal eden her müşteri bunun yerine "We could not verify this payment attempt." (kurcalama ima eden, korkutucu) mesajını görüyor. (2) `class-nicepay-gateway.php:426-432` hiçbir `nicepay_update_transaction()` çağırmıyor — reddedilen authentication defterde HİÇ iz bırakmıyor; ne `result_code`, ne `AuthResultMsg`. Satır cron `nicepay_expire_pending_transactions()` çalışana kadar `pending` kalıyor ve sonunda `expired` görünüyor, yani tüccar "müşteri kartı reddedildi" ile "müşteri sayfayı kapattı"yı ayırt edemiyor. (3) Gözlemlenebilirlik: `nicepay_log(..., 'warning')` sadece `nicepay_inbound_missing_field` yazıyor, bu da güvenlik alarmı gibi görünüp gerçek dönüşüm kaybını maskeliyor.

**Öneri**

Zorunlu alan listesini iki kademeye ayır: her zaman gelen kimlik alanları (`Moid`,`MID`,`Amt`,`PayMethod`,`AuthResultCode`) önce doğrulansın; `AuthResultCode !== '0000'` ise HEMEN `nicepay_inbound_auth_failed` dönülsün (payload'daki `AuthResultMsg` error data'sına eklenerek); sadece `'0000'` durumunda ikinci kademe (`AuthToken`,`TxTid`,`Signature`,`NextAppURL`,`NetCancelURL`) zorunlu kılınsın.
```php
$identity = array( 'Moid', 'MID', 'Amt', 'PayMethod', 'AuthResultCode' );
if ( null !== ( $m = self::first_missing_field( $payload, $identity ) ) ) { ... }
// ...Moid→satır bağlama, MID/tutar/metot kontrolleri...
if ( '0000' !== $payload['AuthResultCode'] ) {
    return self::error( 'nicepay_inbound_auth_failed', 'NicePay authentication was not successful.',
        array( 'auth_result_code' => $payload['AuthResultCode'],
               'auth_result_msg'  => isset( $payload['AuthResultMsg'] ) ? $payload['AuthResultMsg'] : '' ) );
}
$approval = array( 'AuthToken', 'TxTid', 'Signature', 'NextAppURL', 'NetCancelURL' );
if ( null !== ( $m = self::first_missing_field( $payload, $approval ) ) ) { ... }
```
Her iki handler'da bu hata kodunda satırı `status='failed', approval_state='failed', result_code=<AuthResultCode>, result_msg=<AuthResultMsg>, active_attempt_key=NULL` olarak yaz. Testteki fixture'ı gerçekçileştir: AuthToken/TxTid/Signature/NextAppURL/NetCancelURL boş, AuthResultCode='F100'.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: `validate_auth_return()` (includes/class-nicepay-inbound-validator.php:36-55) onay-aşaması alanlarını (AuthToken, TxTid, Signature, NextAppURL, NetCancelURL) `AuthResultCode` kontrolünden (:107) ÖNCE ve koşulsuz zorunlu kılıyor. Sonuç olarak, NICEPAY başarısız/iptal edilmiş bir auth'ta bu alanlardan herhangi birini boş bırakırsa akış `nicepay_inbound_auth_failed` yerine `nicepay_inbound_missing_field` üretir ve return-handler.php:81-83'teki nazik mesaj devre dışı kalır. Bu koşullu kısım (NICEPAY'in başarısızlıkta bu alanları göndermediği) repoda kanıtlanmamış, hatta docs/analysis/04-security.md:295 tersini iddia ediyor — yani "dal tamamen ölü" ifadesi doğrulanmamış bir satıcı davranışı varsayımına dayanıyor. KOŞULSUZ doğrulanan kusur şudur: hangi hata kodu üretilirse üretilsin, reddedilen bir authentication defterde HİÇ iz bırakmıyor — gateway.php:426-432 ve return-handler.php:79-85 hiçbir `nicepay_update_transaction()` çağırmıyor, ve `AuthResultMsg` (gateway.php:396, return-handler.php:43) toplanıp hiçbir yerde kullanılmadan atılıyor; satır cron'a kadar `pending` kalıp sonunda `expired` görünüyor.
- Gerekçe: Kodla ilgili her yapısal iddia doğrulandı; satır numaraları da dosyaların şu anki hâliyle birebir eşleşiyor:

1) `validate_auth_return()` gerçekten 10 alanı KOŞULSUZ zorunlu kılıyor (:36-47) ve `first_missing_field()` boş string'i eksik sayıyor (:192-202). `AuthResultCode !== '0000'` kontrolü ancak :107'de geliyor. Yani `AuthToken/TxTid/Signature/NextAppURL/NetCancelURL` alanlarından biri boş gelen her payload `nicepay_inbound_auth_failed`'e ULAŞAMADAN `nicepay_inbound_missing_field` ile döner. Çürütecek erken return, üst katman ön-kontrolü veya alternatif dal YOK: her iki çağıran da alanları `isset() ? sanitize : ''` ile topluyor (gateway.php:395-406, return-handler.php:42-53), yani eksik POST alanı `''` olarak validator'a giriyor ve doğrudan `missing_field`'a düşüyor.

2) Defter kaydı iddiası KOŞULSUZ doğru ve ben de doğruladım: `class-nicepay-gateway.php:426-432` validator'dan gelen HERHANGİ bir WP_Error'da sadece `nicepay_log(...,'warning')` + `wc_add_notice` + `wp_safe_redirect` yapıyor; hiçbir `nicepay_update_transaction()` çağrısı yok. Aynı şekilde `class-nicepay-return-handler.php:79-85` sadece `render_result_page()` yapıyor. Ayrıca `$auth_result_msg` her iki handler'da da toplanıyor (gateway.php:396, return-handler.php:43) ve tüm kod tabanında BAŞKA HİÇBİR YERDE kullanılmıyor (`grep -rn "auth_result_msg" includes/` yalnızca bu iki atama satırını veriyor). Yani reddedilen/iptal edilen bir auth, `AuthResultCode` dalına ulaşsa da ulaşmasa da defterde iz bırakmıyor; satır cron'a (`nicepay_expire_pending_transactions()`, nicepay-functions.php:954) kadar `pending` kalıyor.

3) Test fixture'ı da iddia edildiği gibi: `'auth rejected' => array( array(), array( 'AuthResultCode' => '3001' ), 'nicepay_inbound_auth_failed' )` (tests/unit/NicePayInboundValidatorTest.php:169) tam payload üzerine sadece AuthResultCode'u değiştiriyor (`array_merge( $this->auth_payload(), $payload_changes )`, :156) — yani geçerli AuthToken/Signature/NextAppURL taşıyan bir "başarısız auth" senaryosu.

ÇÜRÜTÜLEN/ZAYIF kısım: "NICEPAY başarısız auth'ta bu alanları ÜRETMEZ, dolayısıyla dal ASLA çalışmaz" premisi bu repoda kanıtlanamıyor ve repo kendi belgesinde TERSİNİ iddia ediyor: docs/analysis/04-security.md:295 — "NICEPAY signs the auth response regardless of outcome, so a genuine failure notification still carries a verifiable `Signature`". docs/API-REFERENCE.md:117-134 sadece alan listesini veriyor; hiçbir alan için "yalnızca başarıda döner" notu yok, Required sütunu bile yok. `Signature = sha256(AuthToken+MID+Amt+Key)` tanımı (API-REFERENCE.md:129) AuthToken yokken imza olamayacağını semantik olarak destekler ama satıcı davranışına dair birincil kanıt (NICEPAY spesifikasyonu/örnek kodu) repoda mevcut değil. Dolayısıyla "ölü kod" ve "her iptal eden müşteri korkutucu mesaj görüyor" kısmı MUHTEMEL ama kanıtlanmamış; "reddedilen auth defterde iz bırakmıyor + AuthResultMsg atılıyor" kısmı ise koşulsuz KANITLI.

Bu yüzden çekirdek doğru, ama başlıktaki kategorik "hiç ulaşmıyor / ölü kod" ifadesi satıcı davranışına bağlı bir varsayım. Önerilen düzeltme (iki kademeli zorunlu alan listesi + AuthResultCode/AuthResultMsg'i deftere yazma) her iki dünyada da doğru olduğu için geçerliliğini koruyor.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Başarısız/iptal edilmiş bir NICEPAY authentication'ında `AuthResultCode` dalı (`class-nicepay-inbound-validator.php:107-109`) pratikte ulaşılamaz kalıyor, çünkü `validate_auth_return()` yalnızca başarılı auth'ta üretilen `AuthToken`/`TxTid`/`Signature`/`NextAppURL`/`NetCancelURL` alanlarını (:36-47) koşulsuz zorunlu kılıp `nicepay_inbound_missing_field` ile erken çıkıyor (:192-202 boş string'i de reddediyor). Bundan bağımsız ve daha ağır olan asıl kusur: reddedilen bir authentication her iki handler'da da SIFIR defter kaydı bırakıyor — `return-handler.php:78-85` ve `gateway.php:426-432` hiçbir `nicepay_update_transaction()` çağırmıyor, `AuthResultCode`/`AuthResultMsg` hiçbir yere yazılmıyor, satır `pending` kalıp cron'la `expired` görünüyor; `AuthResultCode` dalı ulaşılsa bile durum değişmezdi (o dal da yazma yapmıyor ve `AuthResultMsg`'i error data'sına koymuyor). Düzeltmeler: (a) iddiadaki "yanlış müşteri mesajı" etkisi SADECE standalone akış için geçerli — `gateway.php:426-431` hata kodunu hiç ayırt etmiyor ve her durumda aynı mesajı gösteriyor, dolayısıyla WooCommerce checkout'ta zaten nazik bir mesaj yolu hiç yok. (b) "NICEPAY başarısızlıkta bu alanları hiç üretmez" önermesi repo içinden kanıtlanamıyor ve projenin kendi `docs/analysis/04-security.md:295` satırıyla açıkça çelişiyor ("NICEPAY signs the auth response regardless of outcome"); bu çelişkinin resmi PG-Web v3 spesifikasyonuyla çözülmesi düzeltmenin ön koşuludur. Bu bir güvenlik/para kaybı kusuru değil; dönüşüm, tanılanabilirlik ve destek yükü kusurudur.
- Gerekçe: Kodu satır satır okudum. İddianın ÇEKİRDEK MEKANİZMASI doğrulanıyor:

1) `validate_auth_return()` 10 alanın tamamını koşulsuz zorunlu kılıyor (:36-47) ve `first_missing_field()` `isset` + `is_string` + `'' === trim()` üçlüsüyle hem eksik hem boş string'i reddediyor (:192-202). `AuthResultCode` dalı ancak :107'de geliyor. Yani başarısız bir auth POST'unda `AuthToken`/`TxTid`/`Signature`/`NextAppURL`/`NetCancelURL`'den herhangi biri boş/yoksa akış :49-55'te `nicepay_inbound_missing_field` ile çıkıyor ve :107 hiç çalışmıyor. Ayrıca her iki handler da boş `$_POST` alanını `''` olarak dolduruyor (`return-handler:44-52`, `gateway:399-406`), yani "alan hiç gönderilmemiş" ile "boş gönderilmiş" ayrımı da yok — ikisi de missing_field'a düşüyor.

2) Validator hata döndürdüğünde HİÇBİR defter yazımı yok — bu doğrulandı ve iddianın en sağlam kısmı. `return-handler.php:78-85` sadece `nicepay_log` + `render_result_page`; `gateway.php:426-432` sadece `nicepay_log` + `wc_add_notice` + redirect. Ne `result_code`, ne `AuthResultMsg`, ne `status='failed'`. Satır `pending` kalıyor ve `nicepay_expire_pending_transactions()` (nicepay-functions.php:954-961) `offer_expires_at` geçtikten sonra `expired` yapıyor — "kart reddedildi" ile "müşteri pencereyi kapattı" gerçekten ayırt edilemiyor. Bu etki, AuthResultCode dalına ulaşılsa BİLE geçerli (o dal da yazma yapmıyor), dolayısıyla PROTO-001'in en değerli kısmı bu.

3) Test fixture'ı iddia edildiği gibi: `'auth rejected' => array( array(), array( 'AuthResultCode' => '3001' ), 'nicepay_inbound_auth_failed' )` (tests/unit/NicePayInboundValidatorTest.php:169) — `auth_payload()` üzerine sadece AuthResultCode değiştiriliyor, yani geçerli AuthToken/TxTid/Signature/NextAppURL/NetCancelURL taşıyan, gerçekte oluşmayan bir payload. Negatif matriste gerçekçi bir "reddedilmiş auth" vakası yok.

ÇÜRÜTÜLEN / DÜZELTİLEN DETAYLAR:

a) Etki (1) WooCommerce için YANLIŞ. `gateway.php:426-431` hata koduna hiç bakmıyor; her WP_Error için aynı mesajı ("We could not verify this payment attempt. Please try again.") gösteriyor. Yani WooCommerce akışında `nicepay_inbound_auth_failed` üretilebilse bile müşteri mesajı değişmezdi — "yanlış müşteri mesajı" etkisi SADECE standalone handler'a (`return-handler.php:80-82`) özgü. Standalone akış ise eklentinin ikincil yolu; WooCommerce checkout'ta zaten hiçbir zaman nazik mesaj yok (bu ayrı ve bağımsız bir kusur).

b) "AuthResultCode dalı ASLA üretilmiyor" mutlak iddiası repo içinden KANITLANAMIYOR. `docs/API-REFERENCE.md:120-133` alanları listeliyor ama "başarısızlıkta hangileri gelmez" demiyor. Dahası projenin kendi analiz dokümanı tam TERSİNİ iddia ediyor: `docs/analysis/04-security.md:295` — "NICEPAY signs the auth response regardless of outcome, so a genuine failure notification still carries a verifiable Signature". Bu iki doküman çelişiyor; hangisinin doğru olduğu NICEPAY resmi spesifikasyonundan teyit edilmeli. İddianın türetimi (Signature = sha256(AuthToken+MID+Amt+Key) olduğundan AuthToken yoksa Signature da yok) mantıklı ve en azından `NextAppURL`/`NetCancelURL`'in başarısız auth'ta anlamsız olması nedeniyle beş alandan en az birinin boş gelmesi çok muhtemel — bu yüzden sonuç pratikte büyük olasılıkla doğru, ama "her zaman" garantisi in-repo kanıta değil dış varsayıma dayanıyor. (Not: bu belirsizlik iddiayı zayıflatıyor ama çürütmüyor: her iki senaryoda da defter kaydı sıfır kalıyor.)

c) Etki (3)'ün ifadesi hatalı: `return-handler.php:79` ve `gateway.php:428` `nicepay_log(..., 'warning')` çağrısına hata kodunu OLDUĞU GİBİ geçiyor; "sadece missing_field yazıyor" değil — pratikte missing_field yazılıyor çünkü dönen kod o. Anlam aynı, ifade yanlış.

d) İSTİSMAR EDİLEBİLİRLİK: Bu bir güvenlik açığı değil. Saldırgan avantajı yok, para kaybı yok, çift çekim yok, terminal duruma yanlış geçiş yok (aksine hiç geçiş yok). Etkisi: dönüşüm kaybı, korkutucu/tanısız müşteri mesajı, tüccar körlüğü ve destek yükü. Ön koşullar tamamen gerçekçi (herhangi bir reddedilen kart / kullanıcı iptali — günlük trafiğin doğal bir yüzdesi), tetiklenme oranı yüksek, ama sonucun ciddiyeti "high" değil. `severity: medium` doğru kalibrasyon.

Öneri kısmı (iki kademeli zorunlu alan listesi + auth_failed'da `status='failed', result_code, result_msg, active_attempt_key=NULL` yazımı + fixture'ın gerçekçileştirilmesi) uygulanabilir ve doğru; ancak "her iki handler'da" derken WooCommerce handler'ının mesaj ayrımını da eklemesi gerekir (şu an gateway hata kodunu hiç ayırt etmiyor).

---

### PROTO-002 — Auth-return `Amt`, yanıt normalizasyonu yerine ISTEK normalizasyonuyla doğrulanıyor — sıfır-dolgulu 12-byte yanıt tüm ödemeleri kırar

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | protocol-field-encoding |
| **Konum** | [includes/class-nicepay-inbound-validator.php:98](../../../includes/class-nicepay-inbound-validator.php#L98) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````php
Aynı sınıfın iki metodu aynı protokol kavramını farklı normalize ediyor. Auth return (:97-101):
```php
$stored_amount = nicepay_normalize_amount( self::transaction_value( $transaction, 'amount' ), 'KRW' );
$posted_amount = nicepay_normalize_amount( $payload['Amt'], 'KRW' );   // :98  ← ISTEK normalizasyonu
```
Approval response (:161-165):
```php
$result_amount = nicepay_normalize_response_amount( $result['Amt'], 'KRW' );   // :162 ← YANIT normalizasyonu
```
`nicepay_normalize_amount()` baştaki sıfırları açıkça reddediyor:
```php
if ( ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $raw ) ) { return false; }   // nicepay-functions.php:1505
```
Oysa `nicepay_normalize_response_amount()`'ın kendi docblock'u durumu açıkça anlatıyor: "Request amounts deliberately reject leading zeroes. Protocol responses may use a fixed-width 12-byte amount" (:1534-1536) ve `/^[0-9]{1,12}$/` kabul edip `ltrim($amount,'0')` uyguluyor (:1546-1550). Projenin kendi referansı da auth response `Amt`'yi 12-byte alan olarak listeliyor (docs/API-REFERENCE.md:128) ve dolgulu formatın gelebileceğini yazıyor (:162). `class-nicepay-api.php:535`'te net-cancel binding'i de aynı hatayı yapıyor: `nicepay_normalize_amount( $auth_data['Amt'], 'KRW' )` — burada `$auth_data['Amt']` doğrudan `$_POST['Amt']`'den geliyor.
````

**Başarısızlık senaryosu**

50.000 KRW'lik sipariş. Plugin `Amt=50000` gönderiyor. NICEPAY auth return'de `Amt=000000050000` (12-byte dolgulu) echo ediyor. `verify_auth_signature()` byte-for-byte doğru çalışıp TRUE dönüyor (imza dolgulu değer üzerinden hesaplandığı için), ama bir satır önce `nicepay_normalize_amount('000000050000')` false döndüğü için akış `nicepay_inbound_amount_mismatch` ile kesiliyor. Müşteri parasını ödemiş kartıyla auth almış durumda ama plugin approval'a hiç geçmiyor ve net-cancel de tetiklenmiyor (validator write/abort yapmıyor) — hold askıda kalıyor.

**Etki**

MID'in sandbox/production sözleşmesi auth-return'de sabit genişlikli `Amt` gönderiyorsa (ki approval yanıtı için bu davranış zaten kabul edilmiş ve test edilmiş), `nicepay_normalize_amount('000000050000')` false döner ve HER ödeme `nicepay_inbound_amount_mismatch` ile reddedilir — üstelik imza doğru olmasına rağmen. Sonuç: gateway tamamen ölü, hiçbir ödeme geçmez, ve hata kodu "tutar uyuşmazlığı" olduğu için operatör yanlış yere (sipariş toplamına) bakar. Aynı hata net-cancel binding'inde tetiklenirse doğrulanmış bir ters çevirme `nicepay_net_cancel_binding_error` sayılıp işlem gereksiz yere `needs_reconciliation`'a düşer.

**Öneri**

Auth-return ve net-cancel binding'inde de yanıt normalizasyonunu kullan:
```php
// class-nicepay-inbound-validator.php:98
$posted_amount = nicepay_normalize_response_amount( $payload['Amt'], 'KRW' );
// class-nicepay-api.php:535
$requested_amount = nicepay_normalize_response_amount( $auth_data['Amt'], 'KRW' );
```
İmza doğrulaması zaten ham byte'ları kullandığı için güvenlik kaybı yok — tersine, kabul edilen değerin imzalı olduğu garanti. Kuralı tek cümlede sabitle: "giden değerler `nicepay_normalize_amount`, gelen protokol değerleri `nicepay_normalize_response_amount`". NicePayInboundValidatorTest'e `Amt => '000000001004'` ile bir auth-return vakası ekle (approval için zaten var, :329-347).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Kod gerçeği doğru: auth-return `Amt` (includes/class-nicepay-inbound-validator.php:98) ve net-cancel binding (includes/class-nicepay-api.php:535) İSTEK normalizasyonu `nicepay_normalize_amount()` kullanıyor; bu fonksiyon baştaki sıfırları reddediyor (nicepay-functions.php:1505), oysa approval yanıtı YANIT normalizasyonu `nicepay_normalize_response_amount()` (validator:162, api.php:536, api.php:628) ile 12-byte dolgulu değeri kabul ediyor. Yani sınıf içinde tutarsız bir kural ve gerçek bir dayanıklılık boşluğu var; dolgulu bir auth-return `Amt` gelirse imza geçse bile akış `nicepay_inbound_amount_mismatch` ile kesilir ve gateway (:427-433, yalnızca redirect; net-cancel/abort yok) sessizce reddeder. ANCAK "tüm ödemeleri kırar / gateway tamamen ölü" etkisi koşullu ve spekülatiftir: auth response tarayıcı üzerinden merchant'ın gönderdiği `Amt` alanının echo'sudur (plugin `Amt` olarak dolgusuz `$amount` gönderiyor, class-nicepay-gateway.php:331) ve projenin kendi referansı dolgu uyarısını yalnızca sunucu-sunucu approval yanıtı için veriyor (docs/API-REFERENCE.md:162), auth response tablosunda (docs/API-REFERENCE.md:120-133) böyle bir not yok. Bu nedenle bulgu "kanıtlanmış üretim kırılması" değil, "tutarsız normalizasyon kuralı + kanıtlanmamış varsayıma bağlı kırılganlık" olarak medium seviyededir. Önerilen düzeltme (her iki yerde de nicepay_normalize_response_amount kullanmak) yine de doğrudur ve güvenlik kaybı yoktur, çünkü imza ham byte'lar üzerinden ayrıca doğrulanıyor (validator:111).
- Gerekçe: İddia edilen tüm satırları açıp okudum; satır numaraları dosyanın şu anki haliyle birebir eşleşiyor ve iddiayı çürüten bir üst-katman koruma bulamadım: `$_POST['Amt']` yalnızca `sanitize_text_field()`'dan geçip (class-nicepay-gateway.php:402, 414) doğrudan validator'a veriliyor; hiçbir yerde `ltrim($amt,'0')` veya benzeri bir ön-kanonikleştirme yok. `nicepay_normalize_amount('000000050000')` gerçekten false döner (regex `^(?:0|[1-9][0-9]*)...` dolgulu diziyi reddeder), dolayısıyla mekanizma iddia edildiği gibi çalışır. Tek düzelttiğim nokta ETKİ/SEVERITY: iddia, dolgulu auth-return'ün gerçekleşeceğini kesinmiş gibi sunuyor; buna dair ne kodda ne dokümanda ne de testlerde kanıt var — aksine, projenin dokümanı dolguyu yalnızca approval yanıtı için not ediyor (docs:162) ve auth response pratikte merchant'ın gönderdiği dolgusuz `Amt`'nin echo'su. Tutarsızlık ve dayanıklılık boşluğu gerçek (confirmed), "her ödeme kırılır" iddiası doğrulanamadı (spekülatif, low confidence).

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Gelen protokol `Amt` değerleri iki yerde (auth-return binding, class-nicepay-inbound-validator.php:98; net-cancel binding, class-nicepay-api.php:535) istek normalizasyonu `nicepay_normalize_amount()` ile işleniyor; oysa aynı sınıfın approval yolu (:162) ve projenin kendi dokümanı (docs/API-REFERENCE.md:162,167-171) gelen protokol değerleri için `nicepay_normalize_response_amount()` kuralını benimsemiş. Bu, bir kural tutarsızlığı ve sertifikasyon kırılganlığıdır: MID'in sözleşmesi auth-return'de sabit genişlikli 12-byte `Amt` echo ediyorsa binding fail-closed olarak kopar ve tüm ödemeler `nicepay_inbound_amount_mismatch` ile — imza geçerli olmasına rağmen — reddedilir. Ancak (a) bu saldırgan tarafından tetiklenebilir değildir, güvenlik etkisi yoktur; (b) auth-return'de dolgunun gerçekten geldiğine dair repoda kanıt yoktur ve API-REFERENCE.md:128'deki "12" alan boyutu maksimum genişliktir, zorunlu dolgu değildir; (c) arıza ikili ve ilk sandbox işleminde görünürdür, üretimde sessizce birikmez. Düzeltme yine de yapılmalı: her iki noktada `nicepay_normalize_response_amount()` kullanılmalı ve NicePayInboundValidatorTest'e `Amt => '000000001004'` ile bir auth-return vakası eklenmelidir.
- Gerekçe: KOD İDDİALARI BİREBİR DOĞRU. Üç asimetriyi de kendim okudum ve satır numaraları dosyanın şu anki haliyle eşleşiyor:

1. `validate_auth_return()` gelen (inbound) `Amt`'yi ISTEK normalizasyonuyla, `validate_approval_response()` aynı protokol kavramını YANIT normalizasyonuyla işliyor (:98 vs :162). Aynı sınıf, aynı kavram, iki farklı kural — tartışmasız bir tutarsızlık.
2. `nicepay_normalize_amount()` regexi `/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/` baştaki sıfırları gerçekten reddediyor; `'000000050000'` için false döner. `nicepay_normalize_response_amount()` ise `/^[0-9]{1,12}$/` + `ltrim(...,'0')` ile kabul ediyor ve docblock'u (:1534-1537) tam da bu ayrımı gerekçelendiriyor.
3. `$payload['Amt']` gerçekten ham `$_POST['Amt']` (yalnızca `sanitize_text_field`) — class-nicepay-gateway.php:402/414 ve class-nicepay-return-handler.php:49/66. Aynı ham değer `$auth_data['Amt']` olarak net-cancel binding'ine (class-nicepay-api.php:535) gidiyor. Yani dolgulu bir değer bu iki noktaya kesinlikle ulaşabilir.
4. `verify_auth_signature()` (class-nicepay-api.php:107-114) `$amt`'yi ham byte olarak kullanıyor, dolayısıyla imza dolgulu değerde de TRUE döner — sıra :111'e gelmeden akış :100'de kesileceği için "imza doğru ama tutar uyuşmazlığı" senaryosu mekanik olarak tutarlı.
5. Doküman kanıtı da doğru: API-REFERENCE.md:128 auth response `Amt` = 12; :162 ve :167-171 dolgulu formatın gelebileceğini ve binding'in kanonikleştirdiğini yazıyor — ama bu yalnızca APPROVAL yanıtı için uygulanmış. Test tarafında da asimetri var: NicePayInboundValidatorTest.php:332-347 `000000001004` vakasını yalnızca approval için pinliyor; auth-return için karşılığı yok.

SEVERITY DÜZELTMESİ (istismar edilebilirlik merceği):
- Saldırgan tetiklenebilirliği YOK. Yol fail-closed: dolgulu `Amt` gönderen ama geçerli imzası olmayan bir saldırgan zaten :100 veya :112'de reddedilir; hatanın yönü "kabul et" değil "reddet". Güvenlik etkisi sıfır.
- Etki, DOĞRULANMAMIŞ bir önkoşula bağlı: NICEPAY'in auth-return'de `Amt`'yi sıfır-dolgulu echo etmesi. Projenin kendi dokümanı bunu yalnızca approval yanıtı için "olabilir" diye işaretliyor ve :170-171'de "üretime geçmeden önce merchant hesabının tam sandbox yanıt sözleşmesini doğrulayın" diyor — yani ekip bu davranışı bilinmeyen olarak kabul etmiş, kanıtlanmış olarak değil. Auth-return'de dolgu OLDUĞUNA dair repoda hiçbir kanıt (fixture, log, vendor doc alıntısı) bulamadım; API-REFERENCE.md:128'deki "12" alan boyutu spec tablosunda MAKSİMUM genişliktir, zorunlu dolgu değil.
- Ayrıca arıza sessiz/gecikmeli değil, ikili ve derhal: dolgu varsa İLK sandbox işleminde patlar, sertifikasyon öncesi yakalanır. "Üretimde sessizce her ödemeyi kırar" senaryosu ancak sandbox ile prod sözleşmesi farklıysa gerçekleşir.
Bu nedenle iddianın çekirdeği (tutarsız normalizasyon + kırılganlık) doğrulanır, ancak "high / gateway tamamen ölü" çerçevesi koşullu; gerçekçi ağırlık MEDIUM. Öneri (her iki noktada `nicepay_normalize_response_amount` kullanmak) hem doğru hem risksiz: imza ham byte'lar üzerinden doğrulandığı için kabul edilen değerin imzalı olduğu garanti kalır.

---

### PROTO-005 — Net-cancel ve iade sonuç alanları defterde saklanıyor ama admin detayında da CSV export'ta da hiç görünmüyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | operator-visibility |
| **Konum** | [admin/class-nicepay-transactions.php:360](../../../admin/class-nicepay-transactions.php#L360) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````php
`nicepay_abort_authenticated_payment()` özenle beş alan yazıyor (nicepay-functions.php:254-258): `net_cancel_status`, `net_cancel_result_code`, `net_cancel_result_msg`, `net_cancel_requested_at`, `net_cancel_completed_at`; iade tarafı da `cancel_status`/`cancel_result_code`/`cancel_result_msg` yazıyor (gateway:889-896, 907-912). Ancak admin detay allowlist'i yalnızca dört şey gösteriyor:
```php
public static function get_safe_detail_fields( $transaction ) {   // :360
    // result_code (:367), result_msg (:374), mode (:381), instrument (:393)
    return $fields;   // :401
}
```
ve CSV export sütun listesi de bunları içermiyor:
```php
$columns = array( 'id','tid','wc_order_id','moid','flow','currency','amount',
    'captured_amount','refunded_amount','remaining_amount',
    'payment_method','status','result_code','mode','created_at','approved_at' );   // :142-145
```
Repo genelinde `grep -rn "net_cancel\|cancel_result" admin/` yalnızca `reconciliation_note` için tek bir satır döndürüyor (:917) — o da ham iç kodu (`auth_context_persistence_failed_before_approval` gibi) çevirisiz basıyor.
````

**Başarısızlık senaryosu**

Approval timeout oluyor, net-cancel onaylanıyor (`net_cancel_status='confirmed'`, ResultCode 2001). Sipariş yine de `on-hold`'a düşüyor (gateway:533). Tüccar admin'de yalnızca "Manual review required" + `nicepay_approval_http_error` görüyor. Para geri çevrildiği hâlde bunu bilemediği için NICEPAY konsolunda bir kez daha iptal denemesi yapıyor veya müşteriyi "ödemeniz alınmış olabilir" diye bekletiyor. Aynı satırdaki `net_cancel_status='confirmed'` alanı sorunu 2 saniyede çözecekken görünmüyor.

**Etki**

Mutabakat, bu eklentinin para güvenliği hikâyesinin merkezinde: PROTO-004'te tarif edilen her askıda hold `needs_reconciliation` olarak işaretleniyor. Ama tüccarın ekranda gördüğü tek şey ham bir iç dize. "Ters çevirme onaylandı mı?" sorusunun cevabı (`net_cancel_status = confirmed|unknown`) — mutabakatta bakılacak İLK bilgi — hiçbir yerde görünmüyor. Aynı şekilde reddedilen bir iadede NICEPAY'in `ResultMsg`/`ErrorMsg`'i saklanıyor (`payment_data`, nicepay-functions.php:105) ama gösterilmiyor; `process_refund` tüccara sadece "NicePay rejected the refund request. Review the transaction details." (gateway:914-917) diyor — oysa gözden geçirilecek detay UI'da yok. Tüccar her vakada NICEPAY konsoluna gitmek ya da veritabanına SQL atmak zorunda.

**Öneri**

1) `get_safe_detail_fields()`'e ters çevirme bloğunu ekle (hiçbiri PII değil):
```php
if ( ! empty( $transaction->net_cancel_status ) ) {
    $fields[] = array( 'label' => __( 'Authorization reversal', 'nicepay-payment-gateway' ),
        'value' => 'confirmed' === $transaction->net_cancel_status
            ? sprintf( __( 'Confirmed (%s)', 'nicepay-payment-gateway' ), self::sanitize_result_code( $transaction->net_cancel_result_code ) )
            : __( 'NOT confirmed — verify in the NICEPAY console', 'nicepay-payment-gateway' ) );
}
if ( ! empty( $transaction->cancel_result_code ) ) { /* iade sonuç kodu + mesajı */ }
```
2) CSV `$columns`/`$headers`'a `net_cancel_status`, `net_cancel_result_code`, `cancel_status`, `reconciliation_status`, `reconciliation_note`, `edi_date` ekle — mutabakat dosyası zaten tam olarak bunun için var.
3) `reconciliation_note`'un ham iç kodlarını `nicepay_get_status_label()` gibi bir eşlemeyle insan-okunur cümlelere çevir.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Doğru çekirdek: `net_cancel_*` alanları (status/result_code/result_msg/requested_at/completed_at) ve `cancel_result_msg` yazılıyor ama admin detay allowlist'inde (`get_safe_detail_fields()`, admin/class-nicepay-transactions.php:360-401 — yalnızca result_code, result_msg, mode, instrument) ve CSV export sütun listesinde (admin/class-nicepay-transactions.php:141-150) hiç yer almıyor; `reconciliation_note` ise admin/class-nicepay-transactions.php:917'de ham iç kod olarak, çevirisiz basılıyor. Ancak iddianın iki ayrıntısı yanlış: (1) Refund sonucu "hiçbir yerde görünmüyor" doğru değil — admin listesinde her satır için iade denemeleri `<details>` bloğunda `status` + `result_code` ile gösteriliyor (admin/class-nicepay-transactions.php:892-903), yani reddedilen iadenin NICEPAY sonuç KODU görünür; görünmeyen yalnızca `cancel_result_msg` metnidir. (2) Başarısızlık senaryosu kurgusal olarak imkânsız: `nicepay_get_approval_error_audit()` (includes/nicepay-functions.php:160-163) `needs_reconciliation`'ı doğrudan `net_cancel_status !== 'confirmed'` olarak tanımlar; dolayısıyla approval timeout'ta net-cancel CONFIRMED ise sipariş on-hold'a DÜŞMEZ — includes/class-nicepay-gateway.php:535'te "NicePay approval did not complete; the reversal was confirmed. The order remains payable." sipariş notu yazılır. Confirmed reversal + on-hold birlikteliği yalnızca fail-closed binding-mismatch yolunda olur (includes/nicepay-functions.php:218 + gateway:903-908) ve orada bile sipariş notu "The original authorization was reversed when possible" der. Yani gerçek boşluk "operatör ters çevirmenin onaylandığını hiç bilemiyor" değil; "denetim/mutabakat detayları (net-cancel sonuç kodu/zamanı, iade red mesajı, reconciliation_note'un insan okunur hâli) UI ve CSV'de yok, sipariş notlarına bakmak gerekiyor" seviyesinde bir operatör-görünürlüğü/UX eksiğidir.
- Gerekçe: İddia edilen dosya/satırların hepsini açıp okudum. Allowlist ve CSV sütun iddiaları satır satır doğru; `grep -rn "net_cancel|cancel_result" admin/` gerçekten hiçbir şey döndürmüyor (yalnızca reconciliation_status/note eşleşiyor). Ancak iddiayı zayıflatan iki koruma var: (a) iade denemesi tablosu admin listesinde result_code ile render ediliyor, (b) sipariş notu/durum metinleri her ters-çevirme sonucunu insan okunur biçimde WooCommerce sipariş ekranına yazıyor ve on-hold ile "confirmed reversal" mantıksal olarak birbirini dışlıyor (needs_reconciliation = net_cancel_status !== 'confirmed'). Bu yüzden "tüccar 2 saniyede çözülecek bilgiyi göremiyor, NICEPAY konsoluna gitmek zorunda" etkisi abartılı; bulgunun kendisi geçerli ama severity high değil medium.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Refund and reversal outcome fields are persisted but never surfaced to the operator — most concretely, `process_refund()` tells the merchant "NicePay rejected the refund request. Review the transaction details." (includes/class-nicepay-gateway.php:914-917) while the transaction detail allowlist (admin/class-nicepay-transactions.php:360-401) renders only result_code/result_msg/mode/instrument and never `cancel_status`, `cancel_result_code`, or `cancel_result_msg`. The same gap applies to `net_cancel_*` and to the CSV export (:140-150). It bites hardest on standalone (non-WooCommerce) transactions, which have no order-note channel at all (includes/class-nicepay-return-handler.php:97-113, 194-206) — for those the transactions screen is the only operator surface. For WooCommerce orders the approval-abort paths are largely mitigated: gateway:531-536 already writes an order note stating in prose whether the reversal was confirmed, and by construction (nicepay-functions.php:160-163) a confirmed reversal never produces an on-hold order — so the claim's headline scenario is not reproducible. Separately, `reconciliation_note` is echoed as a raw internal code at :917 with no human-readable mapping. Fix: add refund/reversal result fields to the detail allowlist and CSV, and map internal notes to translated sentences — but do NOT label a confirmed net-cancel on the binding-mismatch path as "resolved", since nicepay_get_mismatched_approval_audit() (nicepay-functions.php:192-224) intentionally keeps those rows locked regardless of cancel outcome.
- Gerekçe: CORE CLAIM: CONFIRMED. The reversal/refund result columns exist in the schema, are written on every abort path, and appear nowhere in the admin detail allowlist or the CSV export. I verified every cited line.

FAILURE SCENARIO AS WRITTEN: REFUTED. The claim's headline scenario ("net-cancel confirmed, ResultCode 2001, order still drops to on-hold at gateway:533, merchant sees only 'Manual review required' + nicepay_approval_http_error") cannot occur on the approval-timeout path. In nicepay_get_approval_error_audit() (nicepay-functions.php:160-163) needs_reconciliation is DEFINED as `'confirmed' !== $status`. So confirmed reversal ⇒ needs_reconciliation === false ⇒ the gateway takes the else branch at class-nicepay-gateway.php:534-535, which does NOT set on-hold and instead adds an order note that literally says the reversal was confirmed. The two states the scenario combines are mutually exclusive by construction. The recommendation's own premise ("the net_cancel_status='confirmed' field would solve it in 2 seconds") is therefore false for that path — the order note already says it in plain language.

The one path where confirmed + on-hold does coexist is the binding-mismatch path (gateway:604-611 via nicepay_get_mismatched_approval_audit, nicepay-functions.php:213-224, which hardcodes 'needs_reconciliation' regardless of cancel outcome). But that is a deliberate fail-closed design documented at nicepay-functions.php:192-195 ("A network cancel can only prove reversal of the authenticated TID/amount... Therefore every post-approval binding failure remains locked"), and the on-hold note already says "the original authorization was reversed when possible". Surfacing net_cancel_status='confirmed' there would be informative but must NOT read as "resolved" — the claim's proposed label "Confirmed (2001)" would actively mislead the operator into unlocking a row the design intends to keep locked.

REPRODUCIBLE INSTANCE THAT DOES SURVIVE: the refund rejection path. Concrete steps: shop manager (nicepay_current_user_can_manage_payments) refunds a paid KRW order; NICEPAY returns a non-success ResultCode; gateway:907-912 persists cancel_status='rejected', cancel_result_code, cancel_result_msg; gateway:914-917 returns the error "NicePay rejected the refund request. Review the transaction details." The operator clicks through to Transaction details #N and get_safe_detail_fields() (:360-401) renders only result_code / result_msg / mode / instrument — none of which changed on refund. The message points at a screen that does not contain the referenced detail. That is a real, unprompted, reachable dead end.

Impact is also understated in one respect the claim missed: standalone (non-WooCommerce) transactions have no order-note channel at all — ajax_cancel_transaction:986 confirms standalone rows exist (wc_order_id empty), and the return handler's abort paths (class-nicepay-return-handler.php:97-113, 194-206) only render_result_page() to the BUYER. For those rows the transactions admin page is the sole operator surface, and it shows nothing about reversal state.

SEVERITY: high is overstated → medium. There is no money loss, no data corruption, no privilege issue, and the WooCommerce flow mitigates most of it with explicit order notes that state the reversal outcome in prose. What remains is a genuine UX/operability defect (a "review the details" message pointing at a detail screen with no details; standalone rows with no operator channel; raw internal codes at :917), not a high-severity reconciliation hazard.

---

### PROTO-006 — NICEPAY host allowlist'i üç sabit isme kilitli ve filtrelenemez — satıcı yeni bir DC açarsa tüm onaylar fail-closed düşer

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-endpoint-policy |
| **Konum** | [includes/class-nicepay-api.php:163](../../../includes/class-nicepay-api.php#L163) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
private static $allowed_hosts = array(
    'dc1-api.nicepay.co.kr',
    'dc2-api.nicepay.co.kr',
    'pg-api.nicepay.co.kr',
);   // :163-167
...
return in_array( $host, self::$allowed_hosts, true ) &&
    in_array( $path, self::$allowed_paths, true );   // :198-199
```
Bu liste `private static` ve hiçbir filtreden geçmiyor. Aynı sınıf timeout'lar için `apply_filters( 'nicepay_http_timeout', ... )` (:211) ve `apply_filters( 'nicepay_http_connect_timeout', ... )` (:245) uzantı noktaları sunuyor — yani mimari filtre kullanmayı biliyor, ama tam da satıcı tarafından dinamik olarak belirlenen değer için kullanmıyor. Dokümantasyon bu URL'in dinamikliğini açıkça vurguluyor: "The approval URL (NextAppURL) is returned dynamically in the auth response. Do NOT hardcode it — each transaction may receive a different URL." (docs/API-REFERENCE.md:55).
````

**Başarısızlık senaryosu**

NICEPAY bakım penceresinde bir MID grubunu `dc3-api.nicepay.co.kr`'ye yönlendiriyor. Auth return `NextAppURL=https://dc3-api.nicepay.co.kr/webapi/pay_process.jsp` taşıyor. `validate_nicepay_url()` false → log'a "Approval URL validation failed" (:339) → net-cancel `NetCancelURL` de dc3 olduğu için o da reddediliyor (:485-488) → `net_cancel='nicepay_url_error'`, `needs_reconciliation`. Sitedeki her ödeme aynı anda kırılıyor ve müşteri kartlarında hold'lar birikiyor.

**Etki**

NICEPAY kapasite genişletirse (dc3-api.nicepay.co.kr) veya trafiği yeni bir hostname'e taşırsa, `validate_nicepay_url()` false döner → `abort_approval()` → net-cancel → HER ödeme başarısız. Düzeltme yalnızca yeni bir plugin sürümü yayınlamakla mümkün; tüccarın veya ajansın site tarafında yapabileceği hiçbir şey yok. Bu, WordPress.org üzerinden dağıtılan bir eklenti için günlerce süren tam kesinti demek. Allowlist'in kendisi doğru bir güvenlik kararı — sorun kaçış valfinin olmaması.

**Öneri**

Allowlist'i filtrelenebilir yap, ama TLD kısıtını koruyarak — böylece filtre yanlış kullanılsa bile SSRF yüzeyi `*.nicepay.co.kr` ile sınırlı kalır:
```php
private function allowed_hosts() {
    $hosts = self::$allowed_hosts;
    if ( function_exists( 'apply_filters' ) ) {
        $hosts = (array) apply_filters( 'nicepay_allowed_api_hosts', $hosts );
    }
    return array_values( array_filter( array_map( 'strtolower', $hosts ), static function ( $h ) {
        return is_string( $h ) && preg_match( '/\A[a-z0-9-]+(?:\.[a-z0-9-]+)*\.nicepay\.co\.kr\z/', $h );
    } ) );
}
```
Alternatif/ek olarak, `*-api.nicepay.co.kr` desenini doğrudan kabul et (path allowlist zaten `/webapi/pay_process.jsp` ve `/webapi/cancel_process.jsp` ile sınırlı). NicePayUrlPolicyTest'e filtrenin `evil.example`'ı kabul edemediğini kanıtlayan negatif bir vaka ekle.

---

### PROTO-007 — `ReqReserved` hiç gönderilmiyor ama dönüşte okunup atılıyor; `TransType`, `MallReserved`, `SelectQuota`, `ShopInterest` gibi opsiyonel protokol alanları hiç desteklenmiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-field-coverage |
| **Konum** | [includes/class-nicepay-gateway.php:406](../../../includes/class-nicepay-gateway.php#L406) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Dönüş handler'ı `ReqReserved`'ı POST'tan okuyup değişkene atıyor ama o değişken dosyanın hiçbir yerinde kullanılmıyor:
```php
$req_reserved = isset( $_POST['ReqReserved'] ) ? sanitize_text_field( wp_unslash( $_POST['ReqReserved'] ) ) : '';   // :406
```
`grep -rn "req_reserved" includes/ admin/ templates/ assets/` bu tek satırı döndürüyor — ne `$auth_data`'ya konuyor, ne loglanıyor, ne karşılaştırılıyor. Ayrıca giden `$form_data` dizisinde `ReqReserved` hiç yok:
```php
$form_data = array( 'GoodsName','Amt','MID','EdiDate','Moid','SignData','ReturnURL',
    'BuyerName','BuyerTel','BuyerEmail','NpLang','CurrencyCode','CharSet' );   // :329-343
```
Standalone formda da yok (templates/standalone-payment-form.php:161-176). Yani dokümante edilen echo-back alanı (docs/API-REFERENCE.md:83 "Custom data, returned as-is" ve :130) hiç kullanılmıyor. Aynı şekilde `TransType` (일반/에스크로), `MallReserved`, `MallUserID`, `UserIP` ve kart tarafında Kore pazarında pratikte zorunlu olan `SelectQuota` (taksit), `ShopInterest`, `QuotaInterest`, `SelectCardCode` (docs/API-REFERENCE.md:95-98) hiçbir yerde geçmiyor.
````

**Başarısızlık senaryosu**

(a) Tüccar 300.000 KRW'lik bir ürün satıyor. Müşteri NICEPAY penceresinde taksit seçmek istiyor ama plugin `SelectQuota` göndermediği için pencere yalnızca 일시불 sunuyor; müşteri sepeti terk ediyor. (b) Bir geliştirici `ReqReserved` doğrulaması eklemek istediğinde `$req_reserved` değişkenini görüp "zaten yapılıyor" sanıyor, oysa hiçbir kontrol yok.

**Etki**

İki ayrı kayıp: (1) Bütünlük — `ReqReserved`, Moid'den bağımsız ikinci bir tüccar-tarafı bağlama kanalı sunar (ör. `hash_hmac(order_id|amount|nonce)`); mevcut tasarım tamamen Moid'e bağlı. Değişkenin okunup atılması, bu doğrulamanın planlanıp yarım bırakıldığını gösteriyor ve statik analizde "kullanılmayan değişken" gürültüsü yaratıyor. (2) Ürün — Kore'de kredi kartı ödemelerinin büyük kısmı taksit (할부) ile yapılır ve 50.000 KRW üzeri işlemlerde taksit seçeneği sunmayan bir gateway ciddi dönüşüm kaybeder. `CardQuota` yanıttan okunup saklanıyor (gateway:588) ama istekte hiç taksit teklif edilmediği için her zaman `00` (일시불) olacak — yani sütun ölü.

**Öneri**

1) `$req_reserved` ölü değişkenini sil VEYA gerçek bir bağlama uygula: `$form_data['ReqReserved'] = hash_hmac('sha256', $moid.'|'.$amount, wp_salt('nonce'))` gönder, dönüşte aynı değeri yeniden hesaplayıp `hash_equals` ile doğrula ve uyuşmazsa `nicepay_inbound_req_reserved_mismatch` döndür (çift tırnak yasağına dikkat, doc:83).
2) `TransType => '0'` alanını açıkça gönder — varsayılana güvenme (escrow yanlışlıkla açık bir MID'de para akışı değişir).
3) Kart taksitleri için ayarlara bir `nicepay_card_quota` seçeneği ekle ve `CARD` etkinken `$form_data['SelectQuota']`/`ShopInterest` gönder; en azından `docs/USER-GUIDE.md`'de "taksit desteklenmiyor" olarak açıkça belgele (şu an dokümantasyon bu konuda sessiz).

---

### PROTO-008 — `AuthResultMsg` / `ResultMsg` / `ErrorMsg` hiçbir zaman kullanıcıya veya tüccara gösterilmiyor — üstelik kullanıcı kılavuzu gösterildiğini iddia ediyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | error-reporting |
| **Konum** | [includes/class-nicepay-return-handler.php:43](../../../includes/class-nicepay-return-handler.php#L43) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Her iki handler `AuthResultMsg`'i okuyor ama hiçbir yerde kullanmıyor:
```php
$auth_result_msg = isset( $_POST['AuthResultMsg'] ) ? sanitize_text_field( wp_unslash( $_POST['AuthResultMsg'] ) ) : '';
// class-nicepay-return-handler.php:43  ve  class-nicepay-gateway.php:396
```
`grep -rn "auth_result_msg" includes/` yalnızca bu iki atama satırını döndürüyor. Red durumunda müşteri sabit bir metin görüyor:
```php
$this->render_result_page( false, __( 'Payment was declined. Please try another payment method.', ... ) );   // return-handler:225
```
WooCommerce tarafında sipariş notu yalnızca kodu içeriyor, mesajı değil:
```php
$order->add_order_note( sprintf( __( 'NicePay declined the payment. Result code: %s. ...' ), $result_code ) );   // gateway:678-682
```
İade reddinde de `ResultMsg`/`ErrorMsg` saklandığı hâlde (nicepay-functions.php:105 allowlist) tüccara dönen mesaj jenerik: "NicePay rejected the refund request. Review the transaction details." (gateway:914-917). Buna karşılık kullanıcı kılavuzu iki ayrı yanlış iddiada bulunuyor: "- Error description from NicePay" (docs/USER-GUIDE.md:563) ve "**WooCommerce:** - Order status set to **Failed**" (docs/USER-GUIDE.md:557) — oysa `grep -rn "update_status(" includes/` hiçbir yerde `'failed'` kullanmıyor; kod on-hold veya pending kullanıyor.
````

**Başarısızlık senaryosu**

Müşterinin kartında günlük limit dolu. NICEPAY `AuthResultCode=F100&AuthResultMsg=일일한도초과` dönüyor. Müşteri "We could not verify this payment attempt." (PROTO-001 nedeniyle bu mesaj çıkıyor) görüyor, aynı kartla 3 kez daha deniyor, her seferinde aynı sonuç. Tüccar destek talebi alıyor; defterde de sipariş notunda da nedene dair tek kelime yok.

**Etki**

NICEPAY'in `AuthResultMsg`/`ResultMsg` alanları müşteriye "한도초과" (limit aşımı), "카드사 점검중" (kart şirketi bakımda) gibi eyleme dönüştürülebilir bilgi verir. Bunları göstermemek müşteriyi aynı kartla tekrar denemeye ve tekrar başarısız olmaya iter. Tüccar tarafında reddedilen bir iadenin gerçek sebebi ("당월 취소 불가" vb.) UI'da hiçbir yerde yok — PROTO-005 ile birleşince operatörün elinde hiçbir teşhis aracı kalmıyor. Dokümantasyon-kod uyuşmazlığı ayrıca destek yükü yaratıyor: tüccar "Failed" sipariş durumu bekleyip filtrelerini ona göre kuruyor, ama siparişler `pending` kalıyor.

**Öneri**

1) `AuthResultMsg`'i `nicepay_utf8_byte_cut(sanitize_text_field(...), 500)` ile defterin `result_msg` alanına yaz ve reddedilen auth'ta `result_code` olarak `AuthResultCode`'u sakla.
2) Müşteriye gösterilecek mesaja PG metnini ekle, ama yalnızca güvenli bir allowlist üzerinden (ham PG metnini doğrudan basmak yerine bilinen kodlar için yerelleştirilmiş açıklama, bilinmeyenler için jenerik metin + referans kodu).
3) `add_order_note`'a `%2$s` olarak `$result_msg`'i ekle ve `process_refund`'ın WP_Error mesajına `ResultCode` + `ResultMsg`'i (kısaltılmış) enterpole et.
4) `docs/USER-GUIDE.md:557`'yi gerçekle değiştir ("Order remains payable/pending; ambiguous outcomes become on-hold") ve :563'ü ya doğrula ya da kaldır.

---

### PROTO-009 — VBANK yarım implementasyon: `4100` başarı kodu doğrudan `paid`+`captured_amount` yazıyor, ama hesap bilgisini gösterecek alanlar filtreyle siliniyor — sertifikasyon kapısı kalkarsa parasız sipariş onaylanır

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-lifecycle |
| **Konum** | [includes/class-nicepay-api.php:644](../../../includes/class-nicepay-api.php#L644) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
`is_success_code()` VBANK için `4100`'ü başarı sayıyor:
```php
$success_codes = array( 'CARD' => '3001', 'BANK' => '4000', 'VBANK' => '4100', ... );   // :641-648
```
ve her iki handler bu "başarı"yı doğrudan tahsilat olarak yazıyor:
```php
if ( $this->api->is_success_code( $result_code, $result_method ) ) {
    $update_data['status']          = 'paid';
    $update_data['approval_state']  = 'approved';
    $update_data['captured_amount'] = $transaction->amount;   // return-handler:184-188 / gateway:598-603
```
Oysa protokolde `4100` yalnızca **sanal hesabın açıldığı** anlamına gelir; para henüz yatmamıştır (docs/USER-GUIDE.md:491 bunu doğruluyor). Aynı zamanda yatırma bilgisini gösteren dal yapısal olarak ölü:
```php
$is_vbank = ! empty( $data['VbankBankName'] ) || ! empty( $data['VbankNum'] );   // return-handler:259
```
ama `$data` her zaman `nicepay_filter_payment_data( $result )`'ten geliyor ve o fonksiyonun allowlist'inde hiçbir `Vbank*` anahtarı yok (nicepay-functions.php:100-106). Yani `$is_vbank` her zaman false; :313-328 arasındaki tüm "Deposit Information" bloğu erişilemez. Buna karşın `get_vbank_exp_date()` (:667-672), `nicepay_vbank_expiry_days` seçeneği, `idx_vbank_expires_at` indeksi ve `$form_data['VbankExpDate']` (gateway:346-348) hâlâ duruyor.
````

**Başarısızlık senaryosu**

Sertifikasyon tamamlanıp `nicepay_get_enabled_methods()`'a 'VBANK' eklendi. Müşteri 100.000 KRW'lik sipariş için VBANK seçiyor. NICEPAY `ResultCode=4100`, `VbankNum=...`, `VbankExpDate=...` dönüyor. Plugin `status='paid'`, `captured_amount=100000` yazıp `$order->payment_complete()` çağırıyor → sipariş Processing → depo ürünü kargoluyor. Müşteri sonuç sayfasında sadece "Payment Successful" + TID görüyor, hesap numarası yok, hiç para yatırmıyor. Tüccar 100.000 KRW zarar ediyor.

**Etki**

Bugün `nicepay_get_enabled_methods()` (nicepay-functions.php:1359) VBANK'ı filtrelediği için yol erişilemez — ama bu tek satırlık bir kapı. O kapı kaldırıldığı an (sertifikasyon sonrası, ki README/USER-GUIDE bunu planlıyor) iki bug aynı anda patlar: (a) sanal hesap açılır açılmaz sipariş `payment_complete()` ile onaylanır ve WooCommerce ürünü sevk etmeye başlar — para hiç yatmadan; (b) müşteriye hangi hesaba ne kadar yatıracağı hiçbir zaman gösterilmez, dolayısıyla para zaten yatmaz. Ürünler bedava gider. Şu anda ise bu kod yolu bakım yükü ve yanlış güvenlik hissi üretiyor: `is_success_code`'un VBANK satırı ve testi (`NicePayResultCodeTest:57`) 4100'ün 'başarı' olduğunu pinliyor ve gelecekteki bir geliştiriciyi yanlış yönlendiriyor.

**Öneri**

İki yönlü net bir karar ver ve kodu ona göre hizala:
(a) VBANK'ı gerçekten kaldıracaksan: `is_success_code`'dan `'VBANK' => '4100'` ve `'SSG_BANK'`/`'GIFT_CULT'` satırlarını çıkar, `get_vbank_exp_date()`, `nicepay_vbank_expiry_days`, `idx_vbank_expires_at`, gateway:346-348 ve return-handler:309-330'daki VBANK dalını sil; testlerdeki VBANK vakalarını "reddedilir" assertion'larına çevir.
(b) İleride açacaksan: bugünden `4100`'ü ayrı bir duruma bağla ve tahsilat sayma —
```php
if ( 'VBANK' === $result_method && '4100' === $result_code ) {
    $update_data['status']         = 'waiting';           // şema zaten destekliyor (nicepay-functions.php:1678)
    $update_data['approval_state'] = 'issued';
    $update_data['captured_amount'] = '0';
    // payment_complete() ÇAĞIRMA; $order->update_status('on-hold') yap
}
```
ve `nicepay_filter_payment_data()` allowlist'ine `VbankBankCode`,`VbankBankName`,`VbankNum`,`VbankExpDate`,`VbankExpTime` ekle ki :309-330 dalı gerçekten çalışsın.

---

### PROTO-010 — Formlar NICEPAY'e `CharSet=utf-8` beyan ediyor ama tarayıcıya `accept-charset` ile UTF-8 dayatmıyor — UTF-8 olmayan sitede mobil akışta Korece isimler bozulur

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | charset-encoding |
| **Konum** | [templates/payment-form.php:56](../../../templates/payment-form.php#L56) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
WooCommerce formu:
```php
<form id="nicepay-pay-form" name="payForm" method="post"
      action="<?php echo esc_url( WC()->api_request_url( 'nicepay_return' ) ); ?>">   <!-- :56-57 -->
```
Standalone formu:
```php
<form id="..." class="nicepay-standalone-form" ... method="post" action="...">   <!-- :156-160 -->
<input type="hidden" name="CharSet" ... value="utf-8">                            <!-- :174 -->
```
Hiçbirinde `accept-charset="utf-8"` yok (`grep -rn "accept-charset" includes/ templates/ assets/` boş dönüyor). Buna karşın hem API sınıfı (`$this->charset = 'utf-8'`, :27) hem gateway (`$charset = 'utf-8'`, :249) hem de dokümantasyon ("The plugin always sends UTF-8. There is no EUC-KR setting or conversion path.", docs/API-REFERENCE.md:42) UTF-8 taahhüdü veriyor. HTML'de bir formun gönderim kodlaması, `accept-charset` yoksa **belgenin** kodlamasıdır — ki sayfa `<meta charset="<?php bloginfo('charset'); ?>">` ile WordPress `blog_charset` seçeneğini kullanıyor (return-handler:264 aynı deseni gösteriyor).
````

**Başarısızlık senaryosu**

Sitenin `blog_charset` seçeneği eski bir kurulumdan devraldığı `EUC-KR`. Müşteri mobil telefondan "프리미엄 원두커피" adlı ürünü alıyor. Tarayıcı `GoodsName`'i EUC-KR byte'larıyla POST ediyor, plugin `CharSet=utf-8` diyor. NICEPAY ödeme penceresinde ürün adı "?????" görünüyor ve kart ekstresinde de bozuk çıkıyor; müşteri işlemi tanımayıp chargeback açıyor.

**Etki**

PG-Web v3'ün mobil akışında `nicepay-pgweb.js`, `document.payForm`'un action'ını NICEPAY'e çevirip formu **doğrudan** submit eder — yani hidden `GoodsName`/`BuyerName` alanları tarayıcı tarafından belgenin kodlamasıyla serileştirilir. `blog_charset` UTF-8 dışındaysa (eski Kore kurulumlarında EUC-KR hâlâ görülür), NICEPAY'e `CharSet=utf-8` etiketiyle EUC-KR byte'ları gider. Sonuç: ödeme penceresinde ve kart ekstresinde ürün/alıcı adı mojibake olur, ve NICEPAY'in alan uzunluk kontrolü byte tabanlı olduğu için sınır davranışı da öngörülemez hâle gelir. Bu, protokolün en kolay sabitlenebilir tek satırlık garantisi.

**Öneri**

Her iki form etiketine `accept-charset="utf-8"` ekle — tek satır, sıfır risk:
```php
<form id="nicepay-pay-form" name="payForm" method="post" accept-charset="utf-8"
      action="...">
```
Ayrıca `nicepay_get_configuration_warnings()`'a bir kontrol ekle: `strtoupper(get_bloginfo('charset')) !== 'UTF-8'` ise error seviyesinde uyar ("NicePay requires a UTF-8 site charset; the current value is %s"). `tests/unit/NicePayStandaloneTemplateTest.php`'ye `assertStringContainsString('accept-charset="utf-8"', $template)` assertion'ı ekle.

---

### PROTO-011 — Her imza EdiDate'e bağlıyken sunucu saat kayması için hiçbir tespit, tolerans veya teşhis yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-time-sync |
| **Konum** | [includes/class-nicepay-api.php:74](../../../includes/class-nicepay-api.php#L74) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
EdiDate tamamen yerel sistem saatinden türetiliyor:
```php
public function generate_edi_date() {
    $now = new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Seoul' ) );
    return $now->format( 'YmdHis' );   // :74-77
}
```
Bu değer auth SignData'sının (`EdiDate+MID+Amt+Key`), approval SignData'sının ve cancel SignData'sının ilk/üçüncü bileşeni. `decode_response()` HTTP yanıtından yalnızca gövdeyi alıyor, `Date` başlığını hiç okumuyor:
```php
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );   // :298-299
```
`nicepay_get_configuration_warnings()` (nicepay-functions.php:494-532) yalnızca üç şeyi kontrol ediyor: test modu, eksik canlı kimlik bilgisi, geçersiz mod. Saat/NTP ile ilgili tek satır yok. Ayrıca WooCommerce akışında EdiDate makbuz sayfası render edildiği anda sabitleniyor (gateway:211) ve teklif 30 dakika geçerli (gateway:283) — yani imzalanmış EdiDate ile fiili submit arasında 30 dakikaya kadar fark olabiliyor, bunun PG tarafındaki toleransı hiçbir yerde belgelenmemiş.
````

**Başarısızlık senaryosu**

Docker host'unda NTP kapalı; konteyner saati 11 dakika geride. Tüm auth SignData'ları 11 dakika eski EdiDate ile imzalanıyor. NICEPAY penceresi "거래시간 오류" ile kapanıyor veya auth'u reddediyor. Plugin logunda yalnızca `nicepay_inbound_missing_field` var. Tüccar eklentiyi devre dışı bırakıyor, ajans günlerce MID/anahtar kontrol ediyor; gerçek sebep tek bir `ntpdate` çalıştırması.

**Etki**

Konteyner/paylaşımlı barındırma ortamlarında saat kayması yaygındır ve genellikle sessizdir. Sunucu saati birkaç dakika kayarsa NICEPAY, EdiDate tazelik penceresi dışındaki imzaları reddeder ve plugin bunu jenerik bir PG hatası olarak görür: auth tarafında `nicepay_inbound_*` (PROTO-001 nedeniyle `missing_field`), approval tarafında `nicepay_approval_*` + net-cancel + `needs_reconciliation`. Hiçbir log, hiçbir admin uyarısı saati işaret etmez. Operatör günlerce kart/PG tarafında sebep arar. Oysa NICEPAY'in HTTP yanıtındaki `Date` başlığı ücretsiz ve güvenilir bir referans saat sağlıyor ve zaten her istekte elde.

**Öneri**

Bedava referansı kullan: her başarılı PG yanıtında `Date` başlığını yerel saatle karşılaştır ve eşik aşılırsa kalıcı bir uyarı üret.
```php
private function record_clock_skew( $response ) {
    $remote = wp_remote_retrieve_header( $response, 'date' );
    if ( ! $remote ) { return; }
    $ts = strtotime( $remote );
    if ( ! $ts ) { return; }
    $skew = abs( $ts - time() );
    if ( $skew > 120 ) {
        update_option( 'nicepay_clock_skew_seconds', $skew, false );
        nicepay_log( 'NicePay clock skew detected', array( 'skew_seconds' => $skew ), 'error' );
    } else { delete_option( 'nicepay_clock_skew_seconds' ); }
}
```
Bunu `decode_response()` içinden çağır ve `nicepay_get_configuration_warnings()`'a şu kontrolü ekle: `nicepay_clock_skew_seconds > 120` ise error tipinde "Server clock differs from NICEPAY by %d seconds; payments will be rejected until NTP is corrected." Ayrıca EdiDate tazeliği için WooCommerce makbuz sayfasında `offer_expires_at`'i 30 dakikadan daha kısa tutmayı ya da submit anında EdiDate/SignData'yı AJAX ile tazelemeyi (standalone akışının zaten yaptığı gibi) değerlendir.

---

### PROTO-012 — İptal yanıtındaki `Moid` ve `RemainAmt` hiç okunmuyor — kısmi iadede PG'nin kalan bakiyesi yerel defterle karşılaştırılmıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-binding |
| **Konum** | [includes/class-nicepay-api.php:615](../../../includes/class-nicepay-api.php#L615) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
`request_cancel()` yanıtı yalnızca TID ve CancelAmt üzerinden bağlıyor:
```php
if ( ! isset( $result['TID'], $result['CancelAmt'], $result['Signature'], $result['ResultCode'] ) || ... ) { ... }   // :615-621
$response_amount = nicepay_normalize_response_amount( $result['CancelAmt'], 'KRW' );
if ( ! hash_equals( $tid, $result['TID'] ) ||
    false === $response_amount || ! hash_equals( $cancel_amt, $response_amount ) ) {
    return new WP_Error( 'nicepay_cancel_binding_error', ... );   // :628-632
}
```
`Moid` hiç karşılaştırılmıyor, oysa istekte `$cancel_moid` gönderiliyor (:575) ve dokümantasyon iptal yanıtında `Moid`'in döndüğünü listeliyor (docs/API-REFERENCE.md:281). `RemainAmt` (docs/API-REFERENCE.md:287, "Remaining amount after cancel") ise repoda hiç geçmiyor — `grep -rn "RemainAmt" includes/ admin/ templates/` boş; `nicepay_filter_payment_data()` allowlist'inde de yok (nicepay-functions.php:100-106). Buna karşın `process_refund()` kendi kalan bakiyesini yerel aritmetikle hesaplayıp yazıyor:
```php
'remaining_amount' => $remaining_after,   // gateway:855
```
````

**Başarısızlık senaryosu**

300.000 KRW'lik CARD ödemesi. Tüccar konsoldan elle 100.000 KRW kısmi iptal yapmış (plugin bunu bilmiyor). Sonra WooCommerce'ten 100.000 KRW iade başlatıyor. NICEPAY iptali kabul edip `CancelAmt=100000`, `RemainAmt=100000` dönüyor. Plugin `RemainAmt`'i okumadığı için `remaining_amount = 300000 - 100000 = 200000` yazıyor. Tüccar bir iade daha (200.000) deniyor; NICEPAY reddediyor ve işlem `needs_reconciliation`'a düşüyor — oysa ilk yanıttaki `RemainAmt=100000` sapmayı o anda yakalayacaktı.

**Etki**

İki kayıp: (1) `Moid` bağlanmadığı için, aynı TID ve aynı tutarla yapılmış FARKLI bir iptal talebinin yanıtı bu talebin yanıtı gibi kabul edilir — imza yalnızca TID+MID+CancelAmt'i kapsadığından imzasal olarak da ayırt edilemez; bu tam olarak `Moid`'in kısmi iptalde "중복취소 방지" amacıyla var olmasının sebebi. (2) `RemainAmt`, PG'nin otoritatif kalan bakiyesi. Bunu okumayıp yerel hesaba güvenmek, yerel defter ile PG arasındaki sapmanın (ör. konsoldan yapılmış bir iptal, veya daha önce mutabakat gerektirmiş bir işlem) sessizce büyümesine izin verir. Bedava ve kesin bir çapraz kontrol kullanılmıyor.

**Öneri**

İptal yanıtına iki kontrol ekle:
```php
// class-nicepay-api.php, :632'den sonra
if ( isset( $result['Moid'] ) && is_string( $result['Moid'] ) && '' !== $result['Moid'] &&
     ! hash_equals( $moid, $result['Moid'] ) ) {
    return new WP_Error( 'nicepay_cancel_binding_error', __( 'Cancel response did not match the refund request.', 'nicepay-payment-gateway' ) );
}
```
(`Moid` alanı yoksa geriye dönük uyum için tolere et, varsa zorunlu kıl.)
Ve `process_refund()`'da:
```php
$reported_remaining = isset( $result['RemainAmt'] ) ? nicepay_normalize_response_amount( $result['RemainAmt'], 'KRW' ) : false;
if ( false !== $reported_remaining && ! hash_equals( $remaining_after, $reported_remaining ) ) {
    // iade PG tarafında gerçekleşti; para hareket etti — 'confirmed' yaz ama sapmayı işaretle
    $ledger['reconciliation_status'] = 'required';
    $ledger['reconciliation_note']   = 'refund_remaining_balance_divergence';
}
```
`RemainAmt`'i `nicepay_filter_payment_data()` allowlist'ine ekle ki denetim izinde kalsın.

---

### PROTO-013 — Onay/iptal yanıt fixture'ları 4 anahtarlık iskeletler — gerçek NICEPAY yanıt şeklini yansıtan sanitize edilmiş satıcı fixture'ı hiç yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | test-fidelity |
| **Konum** | [tests/unit/NicePayTransportTest.php:468](../../../tests/unit/NicePayTransportTest.php#L468) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Transport testlerinin tüm approval yanıtı bu:
```php
private function valid_approval_response( array $changes = array() ): array {
    return array_merge( array(
        'TID'        => 'nicepay00m01012006221311045107',
        'Amt'        => '1004',
        'Signature'  => '9439b21e...',
        'ResultCode' => '3001',
    ), $changes );   // :468-478
}
```
Gerçek bir CARD onay yanıtı ise en az `MID`, `Moid`, `PayMethod`, `AuthCode`, `AuthDate`, `ResultMsg`, `CardCode`, `CardName`, `CardQuota`, `CcPartCl`, `ClickpayCl`, `CardType`, `AcquCardCode`, `CardCl` içerir (docs/API-REFERENCE.md:158-191). Bu fixture `request_approval()`'ı geçiyor çünkü o metot yalnızca 4 alanı zorunlu kılıyor (:384-388); ama `validate_approval_response()` `TID, MID, Moid, Amt, PayMethod, Signature` istiyor (inbound-validator:135) — yani transport testi hiçbir zaman uçtan uca gerçek bir yanıtı iki katmandan birden geçirmiyor. `tests/fixtures/` dizininde yalnızca `blocks-abstract-payment-method-type.php` var; hiçbir PG yanıt fixture'ı commit edilmemiş — önceki analiz raporu bunu açıkça istemişti (docs/analysis/09-testing.md:695: "Commit tests/fixtures/ with sanitised JSON captured from the test MID for each method"). Auth-başarısızlık fixture'ı da gerçek dışı (PROTO-001'de gösterildi).
````

**Başarısızlık senaryosu**

CI tamamen yeşil. Sertifikasyon testinde ilk gerçek CARD ödemesi denendiğinde NICEPAY yanıtı `Amt=000000001004` (PROTO-002) veya `EdiType` varsayılanı KV (PROTO-003) ile geliyor; ödeme başarısız, net-cancel tetikleniyor. Suite'te bu vakaları temsil eden tek bir fixture olmadığı için hata yalnızca canlı sertifikasyonda, en pahalı noktada ortaya çıkıyor.

**Etki**

İmza sözleşmesi testleri mükemmel (gerçek satıcı digest'leriyle pinlenmiş — bunu bağımsız doğruladım), ama **yanıt şekli** sözleşmesi hiç test edilmiyor. Sonuç: PROTO-002 (dolgulu auth Amt), PROTO-003 (KV yanıt formatı) ve `PayMethod`'un yanıtta gelmeme ihtimali gibi üretimde tüm gateway'i kıracak senaryoların hiçbiri CI'da yakalanamaz. Ayrıca §12 "tolerates unknown response fields" testi yok — NICEPAY yeni bir alan eklerse davranış bilinmiyor. Bu, yeşil bir CI'ın sertifikasyon riski hakkında yanlış güven vermesi demektir.

**Öneri**

1) `tests/fixtures/nicepay/` altına her yaşam döngüsü için sanitize edilmiş JSON commit et: `approval-card-3001.json`, `approval-bank-4000.json`, `approval-cellphone-a000.json`, `approval-declined.json`, `auth-return-success.json`, `auth-return-failed.json` (AuthToken/Signature/NextAppURL BOŞ), `cancel-2001.json`, `cancel-2211.json`, `cancel-rejected.json`, `net-cancel-2001.json`. İmzaları test MID'i ile yeniden hesaplayarak tutarlı tut.
2) `NicePayTransportTest` ve `NicePayInboundValidatorTest`'i bu fixture'lardan data provider ile besle ve en az bir testte zinciri uçtan uca sür: `request_approval()` → `validate_approval_response()` → `is_success_code()`.
3) "Bilinmeyen alanlar tolere edilir" testi ekle: fixture'a `FutureField => 'x'` enjekte edip akışın değişmediğini doğrula.
4) `EdiType=JSON` gönderildiğini ve KV gövdesinin fail-closed davrandığını assert eden testleri PROTO-003 ile birlikte ekle.

---

### PROTO-014 — `GoodsName` 40 BYTE'a sessizce kesiliyor (≈13 Korece karakter) ve çoklu-ürün eki kesmeden önce ekleniyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-field-limits |
| **Konum** | [includes/class-nicepay-gateway.php:238](../../../includes/class-nicepay-gateway.php#L238) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
$extra = count( $items ) - 1;
if ( $extra > 0 ) {
    $goods_name .= sprintf( _n( ' and %d more item', ' and %d more items', $extra, ... ), $extra );   // :228-234
}
// Truncate goods name to 40 bytes (NicePay limit is byte-based)
$goods_name = nicepay_utf8_byte_cut( sanitize_text_field( $goods_name ), 40 );   // :238
```
`nicepay_utf8_byte_cut()` `mb_strcut` ile byte sınırında kesiyor (nicepay-functions.php:1379-1381) — elipsis yok, kısaltma göstergesi yok, uyarı yok. Korece UTF-8'de karakter başına 3 byte olduğundan 40 byte ≈ 13 karakter. Dokümantasyon alanın birimini belirsiz bırakıyor: `| GoodsName | 40 | Product name |` (docs/API-REFERENCE.md:67) — byte mı karakter mi yazmıyor, oysa kod kesin bir yorum seçmiş. Ayrıca ek (" and 2 more items") kesmeden ÖNCE ekleniyor, yani Korece bir ürün adında ekin tamamı kesilip gidiyor ve kesme noktası ürün adının ortasına düşüyor. Standalone tarafında da aynı 40-byte kesme var (offer-resolver:85, template:68).
````

**Başarısızlık senaryosu**

Müşteri 4 kalemlik sipariş veriyor; ilk kalem "핸드메이드 원목 도마 대형" (36 byte). `_n()` eki eklendikten sonra dize 36 + 18 = 54 byte. 40 byte'a kesilince "핸드메이드 원목 도마 대" kalıyor — hem ürün adı yarım hem "3 kalem daha" bilgisi kayıp. Kart ekstresinde de bu yarım metin çıkıyor; müşteri işlemi tanımayıp bankasını arıyor.

**Etki**

`GoodsName` müşterinin NICEPAY ödeme penceresinde ve kart ekstresinde gördüğü metin. "프리미엄 원두커피 1kg 선물세트 (2개입)" gibi tipik bir Kore ürün adı "프리미엄 원두커피 1k" olarak gider — hem ödeme anında güven kırıcı hem de sonradan ekstre üzerinden işlemi tanımayı zorlaştırıp chargeback riskini artırır. Çoklu ürünlü siparişlerde ise "...and 3 more items" eki hiç görünmediği için müşteri tek ürün için ödeme yaptığını sanır. Sınırın byte mı karakter mi olduğu doğrulanmamış olduğundan, gereksiz yere agresif olma ihtimali de var (EUC-KR bağlamında 40 byte = 20 Korece karakter).

**Öneri**

1) Kesme oluştuğunda görsel işaret bırak:
```php
function nicepay_goods_name_for_pg( $name, $max_bytes = 40 ) {
    $name = sanitize_text_field( $name );
    if ( strlen( $name ) <= $max_bytes ) { return $name; }
    return nicepay_utf8_byte_cut( $name, $max_bytes - 3 ) . '...';
}
```
2) Çoklu-ürün ekini kesme bütçesine dahil et: önce eki ölç, ürün adını `40 - strlen($suffix)` byte'a indir, sonra eki ekle — böylece "외 3건" bilgisi her zaman korunur.
3) `docs/API-REFERENCE.md:67`'ye birimi açıkça yaz ("40 bytes in the declared CharSet") ve DG-01 sertifikasyon listesine "GoodsName sınırının byte mı karakter mi olduğunu satıcıdan yazılı teyit al" maddesini ekle — çünkü kod şu an doğrulanmamış bir yorum üzerine kurulu.

---

### PROTO-003 — `EdiType` hiç gönderilmiyor ama yanıt koşulsuz JSON varsayılıyor — MID'in varsayılanı KV ise her onay parse hatasıyla düşer

| | |
|---|---|
| **Severity** | 🔵 Düşük *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | protocol-response-format |
| **Konum** | [includes/class-nicepay-api.php:299](../../../includes/class-nicepay-api.php#L299) |
| **Güven** | medium |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````php
`decode_response()` gövdeyi tartışmasız JSON kabul ediyor:
```php
$body   = wp_remote_retrieve_body( $response );
$result = json_decode( $body, true );          // :299
if ( ! is_array( $result ) ) {
    return new WP_Error( 'nicepay_' . $context . '_parse_error', ... );
}
```
Ama üç istek gövdesinin (`request_approval` :348-360, `request_net_cancel` :492-505, `request_cancel` :572-582) hiçbirinde `EdiType` yok. Projenin kendi referansı bu alanı belgeliyor: `| EdiType | 10 | No | Response format (JSON default, KV for key=value) |` (docs/API-REFERENCE.md:150). Yani kod, sözleşmesi belirsiz bir varsayılana bel bağlıyor. Repo genelinde `EdiType` sadece bu dokümantasyon satırında geçiyor (grep doğrulandı).
````

**Başarısızlık senaryosu**

Tüccarın canlı MID'i KV varsayılanıyla açılmış. Müşteri kartla 3D auth'u başarıyla tamamlıyor. Plugin `pay_process.jsp`'ye POST atıyor, NICEPAY `ResultCode=3001&ResultMsg=...&TID=...&Signature=...` (KV) döndürüyor. `json_decode` null → `nicepay_approval_parse_error` → net-cancel → müşteri "We could not confirm the payment outcome" görüyor. Tüccar konsolunda auth+iptal çiftleri birikiyor, hiçbir satış geçmiyor ve log yalnızca `parse_error` diyor.

**Etki**

MID'in `EdiType` varsayılanı KV (key=value) olarak provizyonlanmışsa — legacy PG-Web v3 hesaplarında bu yaygın bir konfigürasyon — `json_decode` null döner, `request_approval` `nicepay_approval_parse_error` ile `abort_approval()`'a düşer, net-cancel tetiklenir ve HER ödeme başarısız olur. Daha kötüsü: bu senaryo test edilebilir değil çünkü test suite'i yalnızca JSON kuyruğu besliyor (NicePayTransportTest:492-494), yani hata ancak canlı sertifikasyonda ortaya çıkar. Aynı risk `cancel_process.jsp` için de geçerli — iade akışının tamamı aynı varsayıma dayanıyor.

**Öneri**

Yanıt formatını her üç istekte açıkça pinle ve gerekiyorsa KV fallback'i ekle:
```php
$params['EdiType'] = 'JSON';   // request_approval / request_net_cancel / request_cancel
```
Ayrıca `decode_response()`'a savunmacı bir ikinci yol ekle: `json_decode` başarısızsa ve gövde `/^[A-Za-z]+=/` kalıbına uyuyorsa `parse_str()` ile çöz, aksi halde mevcut fail-closed davranışı koru. NicePayTransportTest'e her operasyon için `EdiType === 'JSON'` assertion'ı ve bir KV-gövde vakası ekle. docs/API-REFERENCE.md:149-150'yi "this plugin explicitly sends EdiType=JSON" olacak şekilde güncelle.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Eklenti `EdiType` parametresini hiçbir istekte açıkça göndermiyor (includes/class-nicepay-api.php:348-360, :492-505, :572-582) ve `decode_response()` yanıtı koşulsuz JSON olarak ayrıştırıyor (:298-305). Spesifikasyona ve projenin kendi referansına göre `EdiType` gönderilmediğinde yanıt JSON'dur (docs/API-REFERENCE.md:150; docs/analysis/02-protocol-conformance.md:1018 §6 alıntısı), dolayısıyla bugün gerçek bir bozulma yok — bu bir sertleştirme/açıklık eksiği. Riskler: (a) format örtük bir satıcı varsayılanına bırakılmış, `EdiType=JSON` tek satırla pinlenebilirken pinlenmemiş; (b) `parse_error` dalı KV gövde, EUC-KR gövde, HTML hata sayfası ve boş gövdeyi ayırt etmiyor ve gövdeden redakte edilmiş bir örnek loglamıyor, bu yüzden canlı bir format sürprizi teşhis edilemez halde net-cancel'a düşer. "Legacy MID'lerde KV varsayılanı yaygındır" ve "her ödeme başarısız olur" iddiaları repoda kanıtsızdır; "senaryo test edilemez" iddiası da yanlıştır (tests/unit/NicePayTransportTest.php:496 `queueRaw()` keyfi ham gövde besleyebilir).
- Gerekçe: Kodla ilgili tüm olgusal iddialar doğru ve satır numaraları dosyanın şu anki haliyle birebir eşleşiyor: `decode_response()` gövdeyi koşulsuz `json_decode` ediyor (:298-305), üç istek gövdesinin (:348-360, :492-505, :572-582) hiçbirinde `EdiType` yok ve repo genelinde PHP/JS tarafında `EdiType` hiç geçmiyor (yalnızca docs/API-REFERENCE.md:150 ve analiz dokümanları). Bu kısım CONFIRMED.

Ancak iddianın çekirdek RİSK önermesi — "MID'in EdiType varsayılanı KV olarak provizyonlanmış olabilir; legacy PG-Web v3 hesaplarında bu yaygın bir konfigürasyon" — repodaki hiçbir kanıtla desteklenmiyor, aksine repodaki iki bağımsız kaynak tarafından ÇÜRÜTÜLÜYOR:
- docs/API-REFERENCE.md:150 → "Response format (`JSON` default, `KV` for key=value)"
- docs/analysis/02-protocol-conformance.md:1018, satıcı manüeli §6 alıntısı → "`EdiType(10 — unset = JSON, KV = key=value)`"
Yani spesifikasyona göre `EdiType` istek-başına bir parametredir ve GÖNDERİLMEDİĞİNDE JSON döner; bu bir MID seviyesi provizyon anahtarı değildir. Aynı analiz dokümanı bunu açıkça yazıyor (02-protocol-conformance.md:1037): "Note that the KV concern is theoretical today: the plugin never sends `EdiType`, so JSON is the contracted format." "Legacy hesaplarda yaygın" ifadesi için repoda tek bir kanıt yok; satıcı manüelinin kendisi de repoda yok, dolayısıyla iddia doğrulanamayan bir varsayıma dayanıyor. Bu nedenle "HER ödeme başarısız olur" etkisi ve `high` severity gerekçelendirilmemiş.

İkinci bir yanlışlık: "bu senaryo test edilebilir değil çünkü test suite'i yalnızca JSON kuyruğu besliyor (NicePayTransportTest:492-494)". Aynı dosyada :496 `queueRaw( int $status, string $body )` var ve keyfi ham gövde (KV dahil) kuyruğa alınabiliyor; :492 `queue()` sadece onun JSON sarmalayıcısı. Yani KV gövde vakası bugün de yazılabilir; "test edilemez" iddiası yanlış.

Geriye kalan meşru çekirdek: yanıt formatı örtük varsayıma bırakılmış, kodda açık bir pin yok ve `decode_response()`'ta KV/HTML gövdeleri için tanı yapılabilir bir ayrım/loglama yok. Bu, savunmacı sertleştirme ve dokümantasyon netliği düzeyinde geçerli bir bulgu (`EdiType=JSON` açıkça göndermek maliyetsiz ve doğru), ancak "her ödeme düşer" sınıfı bir high değil.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: `EdiType` hiçbir istek gövdesinde gönderilmiyor (class-nicepay-api.php:348-360, :492-505, :572-582) ve `decode_response()` yanıtı koşulsuz JSON olarak ayrıştırıyor (:299) — KV fallback'i, testlerde KV fixture'ı yok. Bu, protokol ihlali veya tetiklenebilir bir bug DEĞİL: spesifikasyon (repo'nun kendi alıntısı, docs/analysis/02-protocol-conformance.md:1018) "unset = JSON" diyor, yani mevcut davranış sözleşmeye uygun. Kusur, örtük bir vendor varsayılanına bağımlı olmak ve bunu ne kodda ne testte pinlememek — savunmacı sertleştirme eksiği. İstismar edilebilirlik yok: hiçbir kullanıcı rolü, HTTP isteği veya girdi bu yolu tetikleyemez; tek önkoşul, satıcı tarafında MID'in KV ile provizyonlanması ve bunun "legacy hesaplarda yaygın olduğu" iddiasının repoda hiçbir kanıtı yok. Gerçekleşse bile sonuç finansal kayıp değil erişilebilirlik kaybıdır (net-cancel POST'u yine teslim edilip PG tarafında işlenir; yalnızca onayın ayrıştırılması başarısız olur), ve hata ilk sandbox/sertifikasyon işleminde %100 deterministik olarak ortaya çıkar. Düzeltme (üç isteğe `'EdiType' => 'JSON'` ekleyip test assertion'ı ve docs/API-REFERENCE.md:150 notu koymak) ucuz ve değerli; ancak önerilen `parse_str` KV fallback'i, eklenti formatı açıkça JSON'a pinledikten sonra gereksizdir ve mevcut doğru fail-closed duruşunu zayıflatır.
- Gerekçe: CODE FACTS: fully confirmed, every line reference is exact.

1. `decode_response()` (includes/class-nicepay-api.php:284-308) does an unconditional `json_decode($body, true)` at :299 and fail-closes on anything else. There is no `parse_str`, no KV branch, and no alternative decode path anywhere in the file (`grep -n "parse_str\|json_decode" includes/class-nicepay-api.php` → only :299).
2. Neither of the three outbound bodies carries `EdiType`: `request_approval` $params at :348-360, `request_net_cancel` $params at :492-505, `request_cancel` $params at :572-582. `post_to_nicepay`/`request_args` (:205-247) add no params — only timeout filters — so no filter can inject it either.
3. `EdiType` appears in the entire non-.git tree only in docs/API-REFERENCE.md:150 and in docs/analysis/* prose. Confirmed by grep.
4. The test suite only ever feeds JSON: `queue()` at NicePayTransportTest:492-494 wraps every body in `wp_json_encode`; there is no KV-body fixture.
5. The cascade the claim describes is real code: on `decode_response` failure `request_approval` calls `abort_approval()` (:378), which fires `request_net_cancel()` (:435) and returns `nicepay_approval_reconciliation_required` (:437-448) — the merchant-facing "requires reconciliation" message.

WHY IT IS ONLY PARTIALLY CONFIRMED — the exploitability/consequence lens is where it breaks down:

a) NOT REACHABLE BY ANY ACTOR. There is no HTTP request, user role, input, or timing that reaches this path. No customer, admin, or attacker can flip a merchant's response format. The single precondition is a vendor-side MID provisioning value that the plugin never reads or controls. As a triggerable defect it is unreachable; as a robustness gap it is real.

b) THE ASSERTED PRECONDITION IS CONTRADICTED BY THE REPO'S OWN SPEC EXTRACT. The claim's whole severity rests on "legacy PG-Web v3 hesaplarında bu yaygın bir konfigürasyon" — an assertion with zero evidence in the repo. What the repo actually records is the opposite: docs/analysis/02-protocol-conformance.md:1018 quotes the spec as "§6 (`EdiType(10 — unset = JSON, KV = key=value)`)" and §12 as "PG responses default to JSON". The same analysis states outright at :1037: "Note that the KV concern is theoretical today: the plugin never sends `EdiType`, so JSON is the contracted format." So per spec, omitting the field yields JSON — the code is conformant, not gambling on an undefined default. The claim's framing ("sözleşmesi belirsiz bir varsayılan") overstates: the contract is defined, it is just implicit.

c) THE IMPACT IS AVAILABILITY, NOT LOSS. Even in the hypothetical KV MID, the net-cancel POST is still delivered and acted on server-side by NICEPAY regardless of the response encoding the plugin fails to parse; only the confirmation parse fails. So the outcome is "no payment ever completes and reconciliation entries accumulate", not "customers are charged without an order". The claim's "auth+iptal çiftleri birikiyor" is right; there is no silent capture.

d) It also fails in the most detectable possible way — the first sandbox/certification transaction, before any live traffic. The claim calls this "en pahalı nokta"; in practice a 100% deterministic first-transaction failure is the cheapest failure mode there is, far cheaper than an intermittent one.

CORRECTED SEVERITY: high → low. The recommendation itself (pin `EdiType=JSON` explicitly, assert it in NicePayTransportTest, and correct docs/API-REFERENCE.md:150 to state the plugin's actual behaviour) is sound and cheap defensive hardening, and worth doing — it removes a dependence on an implicit default and makes the contract self-documenting. But the KV `parse_str` fallback is speculative gold-plating for a format the plugin would then be explicitly opting out of, and adding a lenient second decode path weakens the currently correct fail-closed posture.

---

### PROTO-015 — `request_cancel()`'ın `$extra_params` parametresi yapısal olarak ölü — `array_intersect_key($extra_params, array())` her zaman boş dizi

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | dead-api-surface |
| **Konum** | [includes/class-nicepay-api.php:587](../../../includes/class-nicepay-api.php#L587) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
public function request_cancel( $tid, $cancel_amt, $cancel_msg, $moid, $partial = false, $extra_params = array() ) {   // :561
    ...
    $extra_params = is_array( $extra_params ) ? array_intersect_key( $extra_params, array() ) : array();   // :587
    $params       = array_merge( $params, $extra_params );   // :588
```
`array_intersect_key($x, array())` matematiksel olarak her zaman `array()` döndürür — yani 587-588 satırları birlikte hiçbir şey yapmayan iki satır ve `$extra_params` imzada duran ama hiçbir zaman etkisi olamayacak bir parametre. Tek çağrı yeri de zaten geçmiyor: `$this->api->request_cancel( $tid, $cancel_amt, $reason, $cancel_moid, $is_partial );` (gateway:805). Bu, dokümantasyonun listelediği `SupplyAmt`, `GoodsVat`, `ServiceAmt`, `TaxFreeAmt`, `RefundAcctNo`, `RefundBankCd`, `RefundAcctNm` alanlarının (docs/API-REFERENCE.md:263-269) yapısal olarak gönderilemez olduğu anlamına geliyor.
````

**Başarısızlık senaryosu**

İleride bir geliştirici VBANK iadesini açıyor ve `request_cancel($tid, $amt, $msg, $moid, false, array('RefundAcctNo'=>'110...','RefundBankCd'=>'020','RefundAcctNm'=>'홍길동'))` çağırıyor. Parametreler sessizce düşüyor; NICEPAY hesap bilgisi olmadan iade talebini reddediyor ve akış `nicepay_refund_error` ile jenerik mesaj döndürüyor. Neden düştüğünü anlamak için API sınıfının 587. satırını okumak gerekiyor.

**Etki**

Güvenlik açısından kasıtlı ve savunulabilir (test bunu doğruluyor: NicePayTransportTest:411-439) ama bakım açısından yanıltıcı: imza bir uzantı noktası vaat ediyor, gövde onu sessizce imkânsız kılıyor. Bir geliştirici VBANK iadesi (`RefundAcctNo` zorunlu) eklemek istediğinde parametreyi görüp kullanmayı deneyecek ve sessizce başarısız olacak — hiçbir hata, hiçbir log. Ayrıca VAT/tedarik tutarı ayrıştırması gerektiren Kore vergi senaryoları (`SupplyAmt`/`GoodsVat`) yapısal olarak kapalı ve bu hiçbir yerde belgelenmemiş.

**Öneri**

Ya parametreyi tamamen kaldır, ya da niyeti kodda ifade eden gerçek bir allowlist'e çevir:
```php
// Şu an hiçbir uzantı alanı sertifikalı değil. Sertifikasyon geldiğinde
// yalnızca bu listeye eklenen alanlar gönderilebilir; kimlik/para/imza
// alanları hiçbir zaman ezilemez.
private static $cancel_extension_allowlist = array(); // ör. array( 'SupplyAmt', 'GoodsVat', 'ServiceAmt', 'TaxFreeAmt' )
...
$extra_params = is_array( $extra_params )
    ? array_intersect_key( $extra_params, array_flip( self::$cancel_extension_allowlist ) )
    : array();
```
Böylece hem mevcut fail-closed davranış birebir korunur (liste boş), hem niyet okunabilir olur, hem de sertifikasyon sonrası tek satırlık bir değişiklikle açılabilir. `request_cancel` docblock'una hangi alanların neden kapalı olduğunu ve DG-02 bağlantısını yaz.

---

### PROTO-016 — Onay yanıtında `PayMethod`/`TID` eksikliği ölü fallback'lerle maskeleniyor ama gerçekte siparişi doğrudan on-hold + mutabakata düşürüyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | protocol-response-tolerance |
| **Konum** | [includes/class-nicepay-gateway.php:569](../../../includes/class-nicepay-gateway.php#L569) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Her iki handler savunmacı fallback yazıyor:
```php
$tid           = isset( $result['TID'] ) ? $result['TID'] : $tx_tid;
$result_method = isset( $result['PayMethod'] ) ? $result['PayMethod'] : $pay_method;   // gateway:569-570, return-handler:163-164
```
Ama bu satırlara ulaşabilmek için akışın `validate_approval_response()`'ı geçmiş olması gerekiyor ve o metot ikisini de zorunlu kılıyor:
```php
$required = array( 'TID', 'MID', 'Moid', 'Amt', 'PayMethod', 'Signature' );   // inbound-validator:135
```
Yani `isset()` kontrolleri her zaman true — fallback'ler erişilemez. Gerçek davranış ise sert: `PayMethod` yanıtta yoksa `nicepay_approval_missing_field` → `nicepay_get_mismatched_approval_audit()` (nicepay-functions.php:202-225) → `status='needs_reconciliation'` + sipariş `on-hold` (gateway:550-565), üstelik ödeme başarıyla tahsil edilmiş olsa bile.
````

**Başarısızlık senaryosu**

NICEPAY, BANK (계좌이체) onay yanıtında `PayMethod` alanını atlıyor (imzalanmayan, opsiyonel bir alan). Ödeme başarıyla tahsil ediliyor (`ResultCode=4000`, imza geçerli, TID/MID/Moid/Amt hepsi doğru). Plugin `nicepay_approval_missing_field` üretiyor, net-cancel deniyor, sipariş on-hold'a düşüyor ve `needs_reconciliation` işaretleniyor. Müşteri parasını ödemiş, sipariş beklemede, tüccar elle çözmek zorunda — hepsi imzalı bir başarı yanıtı üzerine.

**Etki**

İki sorun bir arada. (1) Ölü fallback'ler kodu okuyan kişiye "bu alan opsiyonel, tolere ediliyor" izlenimi veriyor; gerçekte tolerans sıfır. (2) Tolerans politikası PayMethod için, imzanın kapsadığı alanlarla (TID/Amt) aynı sertlikte — oysa `PayMethod` imzalanmıyor ve satıcı yanıt şemasında bulunma garantisi projenin kendi DG-02 uyarısıyla açıkça doğrulanmamış (docs/API-REFERENCE.md:7). Sonuç, para başarıyla tahsil edilmişken siparişin on-hold'a düşmesi ve gereksiz manuel mutabakat.

**Öneri**

1) Ölü fallback'leri sil (`$tid = $result['TID']; $result_method = $result['PayMethod'];`) veya `validate_approval_response()`'ın garantisine yorum düş.
2) `PayMethod`'u iki kademeye ayır: yanıtta VARSA istek metoduyla eşleşmesi zorunlu (mevcut sert kontrol korunur — bu gerçek bir saldırı yüzeyi), YOKSA istek metoduna düş ve mutabakat yerine yalnızca bir uyarı logla:
```php
$required = array( 'TID', 'MID', 'Moid', 'Amt', 'Signature' );   // PayMethod çıkarıldı
...
if ( isset( $result['PayMethod'] ) && is_string( $result['PayMethod'] ) && '' !== trim( $result['PayMethod'] ) ) {
    if ( ! hash_equals( $request_method, $result['PayMethod'] ) ) {
        return self::error( 'nicepay_approval_method_mismatch', ... );
    }
} else {
    nicepay_log( 'Approval response omitted PayMethod; falling back to the authenticated method', $request_method, 'warning' );
}
if ( ! self::method_matches_transaction( $transaction, $request_method ) ) { return self::error( 'nicepay_approval_method_mismatch', ... ); }
```
Bu, imzalanmayan bir alanın yokluğunu para-güvenliği olayı olmaktan çıkarır, ama varlığında kurcalanmasını hâlâ engeller. PROTO-013'teki satıcı fixture'ları geldiğinde alanın gerçekten her zaman gelip gelmediğini doğrulayıp kararı kesinleştir.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İncelenenler (hepsi nihai hâliyle, satır satır okundu): includes/class-nicepay-api.php (739 satır, tamamı), includes/class-nicepay-inbound-validator.php (299, tamamı), includes/class-nicepay-return-handler.php (374, tamamı), includes/class-nicepay-offer-resolver.php (198, tamamı), templates/payment-form.php (77) ve templates/standalone-payment-form.php (194, tamamı), assets/js/nicepay.js (499, tamamı), includes/class-nicepay-gateway.php'nin protokole dokunan tüm bölümleri (1-200 is_available/process_payment/receipt_page/recover, 200-371 generate_payment_form, 376-689 handle_return, 694-918 process_refund), includes/nicepay-functions.php'nin ilgili bölümleri (1-340 log/redaksiyon/filtreleme/net-cancel audit, 484-660 config uyarıları/return URL/goods class/moid sorgusu, 923-1000 abandon/expire, 1332-1470 metot kapısı/byte kesme/alıcı doğrulama, 1478-1690 tutar aritmetiği). Testler: NicePaySignatureIntegrationTest (tamamı), NicePayResultCodeTest (tamamı), NicePayTransportTest (tamamı), NicePayUrlPolicyTest (tamamı), NicePayApiTest (test adı/anahtar satır taraması), NicePayInboundValidatorTest (fixture ve provider bölümleri). Dokümantasyon karşılaştırması: docs/API-REFERENCE.md (1-350 ve 403-420), docs/USER-GUIDE.md ilgili bölümler, README.md destek sözleşmesi, docs/analysis/02 ve 09 (önceki bulguların bu PR'da kapanıp kapanmadığını teyit için).

Bağımsız doğrulama: NicePaySignatureIntegrationTest'teki ve NicePayTransportTest/NicePayInboundValidatorTest'teki tüm golden SHA-256 digest'lerini (475979a5…, cc94db19…, 4916540b…, 9439b21e…, 59c36831…, e6959c2f…) python3 ile dokümante edilen preimage'lerden yeniden hesapladım; altısı da birebir tuttu — yani imza testleri tautolojik değil, gerçek sözleşme testleri.

İnceleyemediklerim ve nedenleri:
- Ortamda `php` binary'si yok (`php -l` ve PHPUnit çalıştırılamadı), bu yüzden hiçbir bulgu çalıştırma ile değil yalnızca statik okuma + bağımsız hash hesaplaması ile doğrulandı.
- `nicepay-pgweb.js` satıcı kütüphanesi uzak bir CDN'den yükleniyor (nicepay-payment-gateway.php:29); içeriği repoda yok, dolayısıyla `nicepayStart()`'ın formu tam olarak nasıl serileştirdiği, mobilde action'ı nasıl değiştirdiği ve hangi alanları eklediği/üzerine yazdığı doğrudan doğrulanamadı. PROTO-010 (accept-charset) bu davranışa dayandığı için confidence medium bırakıldı.
- Gerçek NICEPAY sandbox'ına istek atılamadı; bu yüzden PROTO-002 (auth-return Amt dolgusu), PROTO-003 (EdiType varsayılanı) ve PROTO-016 (PayMethod'un yanıtta her zaman gelip gelmediği) satıcı davranışına bağlı ve dokümantasyonun kendi DG-01/DG-02 uyarısıyla zaten \"doğrulanmamış\" olarak işaretlenmiş konular — her biri için kod tarafındaki kanıt kesin, satıcı tarafındaki tetikleyici varsayımsal.
- `assets/js/nicepay-blocks.js` ve `includes/nicepay-icons.php` protokol yüzeyi taşımadığı için kapsam dışı bırakıldı.
- Kore mevzuatına özgü akışlar (에스크로/TransType=1, 현금영수증 RcptType, 휴대폰 당월 취소 kısıtı) yalnızca \"gönderilmiyor/kontrol edilmiyor\" düzeyinde tespit edildi; bunların tüccar sözleşmesi açısından zorunlu olup olmadığı MID provizyonuna bağlı olduğundan PROTO-007 içinde birleştirilip severity medium tutuldu.

**Açık sorular**

- MID'in `EdiType` varsayılanı gerçekten JSON mu? PROTO-003'ün ciddiyeti tamamen buna bağlı — satıcıdan yazılı teyit (DG-01) alınana kadar `EdiType=JSON` açıkça gönderilmeli.
- NICEPAY auth-return'de `Amt`'yi istekteki gibi mi echo ediyor, yoksa approval yanıtı gibi 12-byte sıfır-dolgulu mu? Doküman auth response `Amt`'yi de 12 byte olarak listeliyor (API-REFERENCE.md:128) ama plugin ikisini farklı normalize ediyor (PROTO-002). Sandbox'tan tek bir gerçek auth-return yakalamak bu soruyu kesin kapatır.
- `GoodsName`/`BuyerName` için dokümante edilen 30/40 sayıları byte mı karakter mi? Kod byte varsayıyor; EUC-KR bağlamında yazılmış bir manüelde 40 "byte" Korece için 20 karakter demekti, UTF-8'de 13 karaktere düşüyor (PROTO-014). Satıcı teyidi gerekiyor.
- NICEPAY'in net-cancel (망취소) için tanımlı bir zaman penceresi var mı (auth'tan itibaren kaç saniye)? Approval timeout'u 30 sn + net-cancel 30 sn olduğundan, pencere dar ise mevcut bütçe yetersiz kalabilir; PROTO-004'teki retry tasarımı da bu pencereye göre boyutlandırılmalı.
- Cancel yanıtında `Moid` her zaman dönüyor mu? PROTO-012'deki bağlama kontrolünün geriye dönük uyumlu mu yoksa zorunlu mu olacağı buna bağlı.
- `2211` iptal kodunun tam anlamı ne — "kısmi iptal başarılı" mı, "zaten iptal edilmiş" mi? İkincisi ise idempotent bir tekrar denemede para hareketi olmadan 'confirmed' yazılıyor demektir ve `RemainAmt` çapraz kontrolü (PROTO-012) bunu yakalamak için kritik hâle gelir.
- Standalone akışında `ajax_init_payment` (nicepay-payment-gateway.php:532-629) WooCommerce'in aksine `nicepay_abandon_pending_transactions()` çağırmıyor ve `active_attempt_key` kullanmıyor; aynı config için iki sekmede iki geçerli teklif üretilebiliyor. Bu bilinçli bir ürün kararı mı (sabit fiyatlı bağış/ürün için tekrar ödeme meşru olabilir) yoksa gözden kaçmış bir asimetri mi?

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 16 |

## Öncelikli aksiyon listesi

1. **PROTO-004** — Net-cancel'a sınırlı, idempotent bir yeniden deneme ve DC fallback ekle. Aynı `AuthToken`+`TID`+`Amt`+`NetCancel=1` gövdesi tekrarlandığında NICEPAY zaten idempotent davranır (aynı TID ikinci kez iptal edilemez), bu yüzden tekrar güvenlidir:
```php
private function request_net_cancel_with_retry( array $auth_data, $attempts = 2 ) {
    $urls = $this->net_cancel_endpoints( $auth_data['NetCancelURL']
