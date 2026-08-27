# 07 — Frontend JavaScript

> PR #3 sistematik review · düşmanca doğrulamalı · 16 bulgu

## Özet

Dört üretim JS dosyası da beklenenin çok üzerinde: hiçbir yerde `innerHTML`/`.html()`/`insertAdjacentHTML` yok, tüm DOM yazımları `createElement` + `textContent` ile yapılıyor ve bu bir regresyon testiyle korunuyor; ReturnURL sunucudan gelse bile same-origin+HTTPS filtresinden geçiriliyor; ticari alanlar (tutar, Moid, imza) DOM'dan değil her denemede imzalı sunucu yanıtından alınıyor. Buna karşılık akışın "mutlu yol dışı" tarafı zayıf: standalone akışta PG penceresi `XMLHttpRequest.onload` içinden açılıyor (WooCommerce yolu bunu bilinçli olarak senkron yapıyor), XHR'de timeout yok, PG hiç geri çağırmazsa sayfadaki TÜM ödeme butonları kalıcı olarak kilitli kalıyor ve WooCommerce tarafında tam ekran `z-index:99999` overlay sayfayı tamamen bloke ediyor — kullanıcı için çıkış yok. Ayrıca admin tarafında kısayol silme akışında `resp.data.message` korumasız erişiliyor (oturum süresi dolduğunda admin-ajax `0` döndürür → TypeError → modal kapanmış, hiçbir geri bildirim yok), frontend modal focus trap'i `type="hidden"` inputları "odaklanabilir" sayarak ödeme sırasında sızıyor, `nicepay-admin.js` içinde çevrilemeyen sabit İngilizce metinler var ve toast'lar canlı bölge (live region) kuralına aykırı oluşturulduğu için ekran okuyucuya hiç duyurulmuyor. Çoklu-instance testi doğru soruyu soruyor ama devre dışı butona sentetik `dispatchEvent` yaparak gerçek tarayıcıda erişilemeyen bir dalı test ediyor ve XHR'ı senkron çözerek asıl asenkron/popup riskini görünmez kılıyor.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **B** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 1 |
| 🟡 Orta | 7 |
| 🔵 Düşük | 7 |
| ⚪ Bilgi | 1 |
| Düşmanca doğrulamada elenen | 1 |
| Doğrulama sonrası severity düzeltilen | 0 |
| Bağımsız doğrulama kararı alan bulgu | 7 / 16 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 7 |

## Güçlü yönler

- DOM XSS yüzeyi sıfır: `grep -n "innerHTML|\.html\(|insertAdjacentHTML|document.write|eval(" assets/js/*.js` hiçbir eşleşme vermiyor; tüm dinamik metin `document.createElement` + `textContent` ile yazılıyor (nicepay-admin.js:16-25 `element()` yardımcısı, nicepay.js:105-115 ve 173-182, nicepay-shortcode-admin.js:115-124).
- XSS regresyon testi gerçek: tests/js/nicepay-admin.test.js:121-141 lokalize metin ve sunucu mesajı üzerinden `<img src=x onerror=...>` deneyip `window.__nicepayXss === undefined` olduğunu doğruluyor; aynısı nicepay-shortcode-admin.test.js:47,67-68'de SVG ikon payload'ı için yapılıyor.
- Sunucudan gelen `return_url` körü körüne kullanılmıyor: nicepay.js:18-25 `sameOriginHttpsUrl()` ile protokol+origin doğrulanıyor, nicepay.js:248-251'de reddedilirse form hiç doldurulmuyor; tests/js/nicepay-multi-instance.test.js:146-157 `https://attacker.example/capture` senaryosunu kanıtlıyor.
- Ticari alanların hiçbiri (Amt, Moid, SignData, MID) DOM'dan okunmuyor; her denemede `ajax_init_payment` yanıtından geliyor ve nicepay.js:241-246'da eksiksizlik + CELLPHONE/GoodsCl tutarlılığı kontrol ediliyor.
- Sayfa cache'lenmiş formlar için nonce yenileme yolu düşünülmüş (nicepay.js:306-328) ve sonsuz döngüye girmemesi için `wasRetried` bayrağıyla tek denemeye sınırlanmış.
- Alıcı alan doğrulaması NICEPAY'in bayt limitlerine göre yapılıyor: `TextEncoder` ile gerçek UTF-8 bayt sayımı, `unescape(encodeURIComponent())` fallback'i, `aria-invalid`/`aria-describedby` ve ilk hatalı alana focus (nicepay.js:195-238) — test Korece 11 karakterle bunu kanıtlıyor.
- jQuery bağımlılığı doğru: `wp_enqueue_script('nicepay-js', ..., array('jquery','nicepay-pgweb'), ...)` (nicepay-payment-gateway.php:497-503) ve tüm dosyalar `(function($){...})(jQuery)` IIFE'si — noConflict güvenli, global `$` sızıntısı yok.
- Admin modalı tam donanımlı: `role=dialog`/`aria-modal`/`aria-labelledby`/`aria-busy`, Escape, backdrop tıklaması, loading sırasında kapatma engeli, focus geri yükleme (nicepay-admin.js:83-237) ve focus trap testi (nicepay-admin.test.js:96-119).
- Frontend modal da focus'u geri veriyor, `document.body.style.overflow`'u kaydedip geri yüklüyor ve Escape'i destekliyor (nicepay.js:393-441).
- JS kalite kapıları CI'a bağlı: `npm run quality` (eslint + css-tree + jsdom testleri) tests.yml:104-105, ayrıca `composer quality` → `node .github/scripts/check-js.js` sözdizimi kontrolü; 7 JS testi de yeşil.
- `prefers-reduced-motion: reduce` bloğu ödeme wrapper'ı, modal ve loading overlay için animasyonları kapatıyor (assets/css/nicepay.css:581-590).
- Blocks adaptörü `wp.element.createElement` ile düz metin üretiyor, `dangerouslySetInnerHTML` yok; `title`/`description` sunucuda `wp_strip_all_tags()` ile temizlenmiş (class-nicepay-blocks-integration.php:67-68).

## Bulgular

### JS-002 — PG penceresi geri çağırmazsa hiçbir kurtarma yok: XHR timeout'u yok, watchdog yok, sayfadaki tüm ödeme butonları kalıcı kilitleniyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | stuck-state |
| **Konum** | [assets/js/nicepay.js:152](../../../assets/js/nicepay.js#L152) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

````javascript
Yükleme durumu belge genelinde uygulanıyor ve yalnızca `releaseStandalone()` ile geri alınıyor:
```js
function setStandaloneLoading(form, loading) {
    document.querySelectorAll('[data-nicepay-start]').forEach(function(button) {
        button.disabled = loading;   // <-- SAYFADAKİ TÜM formların butonları
    });
```
XHR'de ne `request.timeout` ne `request.ontimeout` ne de `request.onabort` tanımlı (nicepay.js:298-305, 353). Başarılı `nicepayStart()` sonrası (nicepay.js:336) `releaseStandalone` çağrılmıyor — kilidi açmanın TEK yolu PG kütüphanesinin `window.nicepayClose()`'u çağırması (nicepay.js:486). WooCommerce tarafında ise `.nicepay-loading-overlay` CSS'te `position:fixed; inset:0; z-index:99999` (assets/css/nicepay.css:428-443) yani `is-active` olduğu anda tüm sayfa bloke; onu kaldıran tek yer `NicePayHandler.hideLoading()` (nicepay.js:88-94) ve o da yine yalnızca `nicepayClose`/`nicepayStart` istisnasından çağrılıyor.
````

**Başarısızlık senaryosu**

Mobil bağlantı, `admin-ajax.php` isteği 30 sn askıda kalır: `onload` da `onerror` da tetiklenmez (XHR varsayılan timeout = 0, sonsuz). Buton `disabled` + `is-loading` (CSS'te `color: transparent !important`, pointer-events:none), sayfada başka bir `[nicepay_payment]` varsa onun butonu da `disabled`. Kullanıcı sonsuza kadar spinner izler; hiçbir hata mesajı, hiçbir yeniden deneme yolu yok.

**Etki**

Ağ takılması, PG kütüphanesinin yüklenememesi, kullanıcının PG penceresini görev yöneticisinden kapatması veya popup engellemesi durumunda sayfa kalıcı olarak kullanılamaz hale gelir. Kullanıcının tek çıkışı sayfayı yenilemek — ama hiçbir mesaj bunu söylemez. Aynı sayfadaki DİĞER ürünlerin ödeme butonları da devre dışı kalır ve neden devre dışı oldukları hiçbir yerde açıklanmaz.

**Öneri**

1) XHR'e timeout ekle: `request.timeout = 20000; request.ontimeout = function(){ releaseStandalone(form); showStandaloneError(wrapper, form, translated('connectionError', '...')); };` ve `request.onabort` için aynısını yap. 2) `nicepayStart()` sonrası bir watchdog kur: `var wd = window.setTimeout(function(){ releaseStandalone(form); showStandaloneError(wrapper, form, translated('paymentWindowLost','Ödeme penceresi açılamadı veya kapatıldı. Lütfen tekrar deneyin.')); }, 60000);` ve `window.nicepayClose`/`nicepaySubmit` içinde `clearTimeout(wd)`. 3) `setStandaloneLoading` sadece ilgili formun butonunu `disabled` yapsın; diğer formlar için `aria-disabled` + tıklandığında `paymentInProgress` mesajı göstersin (bkz. JS-008). 4) WooCommerce overlay'ine görünür bir "İptal / Geri dön" düğmesi ve `Escape` ile `hideLoading()` çağrısı ekle.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddiayı çürütecek herhangi bir koruma bulamadım; her iki kod yolunu da (standalone shortcode ve WooCommerce/template) okudum.

1) `setStandaloneLoading` gerçekten belge genelinde tüm `[data-nicepay-start]` butonlarını devre dışı bırakıyor (nicepay.js:152-160). Satır numarası doğru.
2) XHR'de timeout/ontimeout/onabort yok: `grep -n "timeout\|ontimeout\|onabort" assets/js/nicepay.js` yalnızca 126, 186, 189 satırlarındaki bildirim fade-out `setTimeout`'larını döndürüyor. Ana istek (298-353) ve nonce yenileme isteği (307-326) için de aynı: sadece `onerror`/`onload`. XHR varsayılan `timeout = 0` (sınırsız) olduğundan askıda kalan bir istek hiçbir handler tetiklemez ve buton kalıcı `disabled` + `is-loading` kalır.
3) Başarılı yolda `window.nicepayStart()` çağrılıp `return` ediliyor (336-337); `releaseStandalone` çağrılmıyor. Kilidi açan tek yer `releaseStandalone` (162-168) ve onu çağıranlar yalnızca hata dalları (286, 302, 311, 323, 340, 349) ile `window.nicepayClose` (486-491). Yani PG kütüphanesi `nicepayClose()`'u çağırmazsa (popup engelleme, kütüphane yüklenememesi, pencerenin dışarıdan kapatılması) kurtarma yok. Watchdog/timer yok.
4) WooCommerce tarafı: `hideLoading()` (88-94) overlay'i kaldıran tek yer; çağıranları yalnızca `startPayment()` içindeki iki istisna dalı (73, 80) ve `nicepayClose` (494). Tüm repoda `hideLoading`/`nicepayClose` başka hiçbir dosyada geçmiyor (grep: sadece nicepay.js). Overlay CSS'i `position:fixed; inset:0; z-index:99999` (nicepay.css:428-444) ve `is-active` ile `display:flex` — tıklamaları yakalar, kapatma düğmesi/Escape yolu yok (Escape yalnızca modal için tanımlı, 402-405).
5) `.nicepay-pay-button.is-loading { color: transparent !important; pointer-events: none; }` (nicepay.css:253-256) — iddiadaki açıklama birebir doğru.

Tek küçük not: standalone yol overlay kullanmıyor (overlay yalnızca templates/payment-form.php:23'te), ama iddia bu ayrımı zaten doğru yapıyor. Severity `high` uygun: kullanıcı ödeme akışının ortasında kalıcı kilitlenir, mesaj yok, yalnızca sayfa yenileme kurtarır.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: PG penceresi geri çağırmazsa kurtarma yok: standalone init XHR'ında (nicepay.js:298-305, ve nonce-refresh XHR'ında 307-326) timeout/ontimeout/onabort tanımlı değil, başarılı nicepayStart() sonrası (nicepay.js:336) watchdog yok; kilidi açan tek yol vendor'ın window.nicepayClose() çağrısı (nicepay.js:489). Ayrıca setStandaloneLoading (nicepay.js:152-155) sayfadaki TÜM [data-nicepay-start] butonlarını disabled yaptığı için ilgisiz diğer ödeme formları da açıklamasız kilitlenir. Gerçekleşebilir tetikleyiciler: (a) admin-ajax.php isteğinin askıda kalması — onload/onerror hiç tetiklenmez, XHR varsayılan timeout=0; (b) nicepayStart() sonrası PG penceresinden hiç geri çağrı gelmemesi (popup engelli veya pencere zorla kapatılmış — bu varyant vendor kodu olduğu için confidence low). "PG kütüphanesi yüklenemedi" senaryosu bu bulguya DAHİL DEĞİL: nicepay.js:79-85 ve 333-334/348-351 bunu zaten yakalayıp kilidi açıyor. Sonuç istemci-taraflı, kalıcı olmayan bir UI takılması; sayfa yenilemesi tam kurtarma sağlar, ancak kullanıcıya hiçbir mesaj/çıkış yolu sunulmaz.
- Gerekçe: Mekaniğin tamamını kodda doğruladım: XHR'de timeout/ontimeout/onabort gerçekten yok, başarılı nicepayStart() sonrası kilit yalnızca vendor'ın nicepayClose() geri çağrısıyla açılıyor, setStandaloneLoading belge genelindeki TÜM [data-nicepay-start] butonlarını disabled yapıyor ve WooCommerce overlay'i position:fixed;inset:0;z-index:99999 ile tüm sayfayı bloke ediyor. Konum (assets/js/nicepay.js:152) doğru.

İki düzeltme gerekiyor:

1) İddianın etki listesindeki "PG kütüphanesinin yüklenememesi" maddesi YANLIŞ — bu senaryo zaten ele alınmış. Standalone yolda nicepay.js:333-334 `if (typeof window.nicepayStart !== 'function') throw new Error(...)` ve bu throw satır 348'deki catch tarafından yakalanıp `releaseStandalone(form)` + hata mesajı üretiyor. WooCommerce yolunda da nicepay.js:79-85 `else { this.hideLoading(); this.showNotice(...'Payment system unavailable.') }` var, ayrıca nicepayStart() throw ederse 71-78 arası yakalıyor. Yani kütüphane yüklenememesi kilitlenmeye yol açmaz; geriye gerçek istismar yolları olarak (a) askıda kalan admin-ajax.php XHR'ı, (b) nicepayStart() sonrası hiç geri çağrı gelmemesi (popup engelli / kullanıcı pencereyi zorla kapatmış / vendor script'i pencereyi açamamış) kalıyor. (b)'nin popup-engelleme varyantı vendor kodu (pay/js/nicepay.js dışarıdan) olduğu için kod kanıtıyla doğrulanamıyor — mantıken makul ama confidence low.

2) Severity "high" abartılı. Somut istismar zinciri gerçekçi ve ulaşılabilir (mobil bağlantı, 3G tünel, admin-ajax.php'nin başka bir eklenti yüzünden yavaşlaması → onload/onerror hiç tetiklenmez, XHR varsayılan timeout=0). Ancak sonuç tamamen istemci-taraflı, DOM-yerel bir UI takılması: para kaybı, çift çekim, veri bozulması veya güvenlik etkisi yok; durum hiçbir yerde kalıcılaşmıyor (localStorage/sunucu state yok — activePaymentForm ve form[name=payForm] sadece bellekte), bu yüzden basit bir sayfa yenilemesi tam kurtarma sağlıyor. Kullanıcıya bunun söylenmemesi ve aynı sayfadaki diğer formların da kilitlenmesi gerçek bir dönüşüm/UX kaybı, ama "high" değil "medium" seviyesi. Öneriler (timeout + watchdog + form-scoped disable) aynen geçerli.

---

### JS-003 — WooCommerce'ta PG penceresi kapatılınca kullanıcı zorla /checkout/'a atılıyor — sipariş sayfası kaybediliyor, mükerrer sipariş riski

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | ux-payment-flow |
| **Konum** | [assets/js/nicepay.js:486](../../../assets/js/nicepay.js#L486) |
| **Güven** | medium |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

````javascript
```js
window.nicepayClose = function() {
    var form = activePaymentForm || document.payForm;
    if (form && form.classList && form.classList.contains('nicepay-standalone-form')) { releaseStandalone(form); return; }
    var wrapper = form ? form.closest('.nicepay-payment-wrapper') : null;
    var checkoutUrl = wrapper ? wrapper.getAttribute('data-nicepay-checkout-url') : '';
    NicePayHandler.hideLoading();
    checkoutUrl = sameOriginHttpsUrl(checkoutUrl);
    if (checkoutUrl) window.location.assign(checkoutUrl);
};
```
Bu URL templates/payment-form.php:16'da `data-nicepay-checkout-url="<?php echo esc_url( wc_get_checkout_url() ); ?>"` yani `/checkout/` — mevcut sayfa ise `get_checkout_payment_url(true)` ile üretilen `/checkout/order-pay/{id}/?pay_for_order=true&key=wc_order_...`. Standalone dalı ise (satır 488-490) sayfada kalıyor: iki akış tutarsız.
````

**Başarısızlık senaryosu**

Müşteri sipariş #1042 için NICEPAY penceresini açar, kart bilgisi yanlış olduğu için pencereyi kapatır → `nicepayClose()` → tarayıcı `/checkout/`'a gider → müşteri aynı ürünü tekrar sipariş eder → sipariş #1043 oluşur. Mağaza sahibi elinde bir `pending` #1042 ve bir ödenmiş #1043 ile kalır; stok ve raporlama bozulur.

**Etki**

Kullanıcı ödeme penceresini yanlışlıkla kapattığında (veya PG bir hata sonrası kapattığında) receipt sayfasından koparılıyor. Sepet henüz boşaltılmadığı için `/checkout/` yeni bir sipariş oluşturmaya hazır formu gösterir; kullanıcı yeniden "Sipariş ver" derse ilk sipariş `pending` kalırken ikinci bir sipariş açılır. Oysa order-pay sayfası her yüklendiğinde `nicepay_abandon_pending_transactions()` çağrılıp yeni bir deneme üretiliyor — yani doğru davranış sayfada kalıp tekrar denemek.

**Öneri**

WooCommerce dalında da sayfada kal ve yeniden deneme sun:
```js
NicePayHandler.hideLoading();
NicePayHandler.showNotice(paymentI18n.paymentCancelled || 'Ödeme penceresi kapatıldı. Tekrar deneyebilirsiniz.', 'info');
```
Ayrılmak isteyen için zaten şablonda `.nicepay-cancel-link` (templates/payment-form.php:72-74) var. Otomatik yönlendirme gerekiyorsa hedef `wc_get_checkout_url()` değil, mevcut order-pay URL'i (`$order->get_checkout_payment_url()`) olmalı.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `medium` → `low`
- Düzeltilmiş iddia: WooCommerce akışında PG penceresi kapatıldığında `nicepayClose()` kullanıcıyı order-pay (receipt) sayfasından koparıp `wc_get_checkout_url()`'e (`/checkout/`) yönlendiriyor (assets/js/nicepay.js:492-496, templates/payment-form.php:16). Aynı fonksiyonun standalone dalı ise sayfada kalıp formu yeniden denenebilir hale getiriyor (satır 488-491) — iki akış tutarsız ve WooCommerce dalı, gateway'in her yerde kullandığı `get_checkout_payment_url( true )` hedefiyle de çelişiyor. Ayrıca kullanıcıya hiçbir "ödeme iptal edildi, tekrar deneyebilirsiniz" bildirimi gösterilmiyor; hedef HTTPS değilse (`sameOriginHttpsUrl`, satır 18-25) yönlendirme sessizce hiç yapılmıyor, yani ortama göre iki farklı davranış. Şablonda zaten bir `.nicepay-cancel-link` (satır 72-74) bulunduğu için otomatik yönlendirme gereksiz.

DÜZELTME: İddianın "ikinci bir sipariş (#1043) oluşur, stok/raporlama bozulur" etkisi genel durumda geçerli DEĞİL — `WC_Checkout::create_order()` oturumdaki `order_awaiting_payment` siparişini, durumu pending/failed ve cart hash aynı olduğu sürece yeniden kullanır. Mükerrer sipariş yalnızca sepet değişirse veya oturum kaybolursa mümkündür. Gerçek etki: gereksiz sayfa kaybı, ödemeyi tekrar denemek için fazladan adım ve bilgisiz bırakılan kullanıcı — yani bir UX kusuru, veri bütünlüğü sorunu değil.
- Gerekçe: Kodu okudum; iddianın ÇEKİRDEĞİ doğru ve satır numaraları birebir tutuyor.

DOĞRULANAN KISIMLAR:
1. assets/js/nicepay.js:486-497 — `window.nicepayClose` gerçekten iki dallı: standalone form ise `releaseStandalone(form); return;` (satır 488-491) ile sayfada kalıp formu tekrar kullanılabilir hale getiriyor; WooCommerce dalında ise wrapper'daki `data-nicepay-checkout-url` alınıp `window.location.assign(checkoutUrl)` (satır 496) ile zorla yönlendirme yapılıyor. İki akış arasındaki tutarsızlık gerçek.
2. templates/payment-form.php:16 — `data-nicepay-checkout-url="<?php echo esc_url( wc_get_checkout_url() ); ?>"`. Yani hedef gerçekten `/checkout/`, mevcut order-pay URL'i değil. Gateway'in kendisi başka her yerde `get_checkout_payment_url( true )` kullanıyor (class-nicepay-gateway.php:129, 479, 507, 540, 624, 687) — JS'teki tek bu dal onunla tutarsız.
3. templates/payment-form.php:72-74'te zaten `.nicepay-cancel-link` var, yani kullanıcının ayrılma yolu mevcut; otomatik yönlendirme gereksiz.
4. class-nicepay-gateway.php:262 — receipt sayfası her yüklendiğinde `nicepay_abandon_pending_transactions( 'woocommerce', ... )` çağrılıp yeni bir attempt kaydı (269-294) oluşturuluyor; yani "sayfada kal ve tekrar dene" mimari olarak destekleniyor. Bu kısım da doğru.
5. Sepet gerçekten boşaltılmıyor: `process_payment()` (108-131) yalnızca redirect döndürüyor, eklentinin hiçbir yerinde `empty_cart` yok (grep: includes/admin/templates içinde 0 sonuç).

ÇÜRÜTÜLEN / ABARTILI KISIM — "mükerrer sipariş" etkisi:
İddianın failure_scenario'su "#1042 pending kalır, #1043 oluşur" diyor. Bu WooCommerce çekirdek davranışıyla büyük ölçüde çürüyor: `WC_Checkout::create_order()` oturumdaki `order_awaiting_payment` id'sini okur ve sipariş `pending`/`failed` durumundaysa ve cart hash aynıysa AYNI siparişi yeniden kullanır, yeni sipariş açmaz. Sepet değişmediği sürece (senaryodaki "aynı ürünü tekrar sipariş eder" durumu tam olarak budur) ikinci bir sipariş numarası oluşmaz; #1042 güncellenir. Mükerrer sipariş ancak sepet değişirse veya oturum/cart hash kaybolursa doğar — yani olası ama iddia edildiği gibi tipik değil. Stok/raporlama bozulması iddiası da bu nedenle desteklenmiyor.

EK GÖZLEM (iddiada yok, davranışı daha da tutarsız kılıyor): satır 495 `checkoutUrl = sameOriginHttpsUrl(checkoutUrl)` ve `sameOriginHttpsUrl` (satır 18-25) yalnızca `url.protocol === 'https:'` ise değer döndürüyor. HTTP üzerinden çalışan bir kurulumda yönlendirme sessizce hiç olmuyor; kullanıcı sayfada kalıyor ama hiçbir bilgilendirme de görmüyor (loading kapanıyor, notice yok). Yani aynı kod iki farklı ortamda iki farklı davranış üretiyor.

Sonuç: konum, kod alıntısı ve UX tutarsızlığı doğru; sonuç/etki analizi (mükerrer sipariş) abartılı. Severity medium değil low.

---

### JS-004 — Kısayol silme yanıtında `resp.data.message` korumasız — oturum süresi dolunca TypeError, modal kapanmış, hiçbir geri bildirim yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | error-handling |
| **Konum** | [assets/js/nicepay-admin.js:341](../../../assets/js/nicepay-admin.js#L341) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````javascript
```js
}, function(resp) {
    modal.close();                                   // 336: önce modal kapatılıyor
    if (resp.success) { ... }
    else { NicePayToast.show(resp.data.message || 'Error', 'error'); }   // 341: resp.data korumasız
}).fail(function() { ... });
```
Aynı dosyanın iptal akışı bunu doğru yapıyor (satır 280): `response && response.data && response.data.message ? ... : adminI18n.cancelFailed`. Ayrıca `$.post` çağrısında `dataType` belirtilmemiş (satır 331), yani yanıt JSON değilse jQuery düz string döndürür. `wp_ajax_nicepay_delete_shortcode` yalnızca oturum açmış kullanıcı için kayıtlı (nicepay-payment-gateway.php:120 — `nopriv` yok), bu yüzden çerez süresi dolduğunda admin-ajax gövde olarak `0` döndürür. Aynı korumasız erişim başarı dalında da var: satır 273 `NicePayToast.show(response.data.message, 'success')`.
````

**Başarısızlık senaryosu**

Yönetici, Ayarlar → Kısayollar sekmesini sabah açar, öğleden sonra dönüp bir kısayolun "Sil" düğmesine basar; WP çerezi bu arada sona ermiştir. admin-ajax `0` döndürür → modal sessizce kapanır → hiçbir toast yok → kart hâlâ listede. Yönetici tekrar tıklar, aynı şey olur; hangi durumun geçerli olduğunu anlamaz.

**Etki**

Admin ekranını uzun süre açık bırakıp "Sil"e basan kullanıcı için: `"0".success` → undefined → else dalı → `"0".data` → undefined → `.message` okuma TypeError'ı. İstisna jQuery'nin başarı dispatch'i içinde atıldığı için `.fail()` yakalamaz, toast gösterilmez ve modal satır 336'da zaten kapanmıştır. Kullanıcı işlemin başarılı olduğunu sanır ama kısayol listede durmaya devam eder — sayfa yenilenene kadar sessiz başarısızlık.

**Öneri**

Her iki dalı da savunmacı yaz ve modalı yanıtı değerlendirdikten sonra kapat:
```js
}, function(resp) {
    var ok = resp && true === resp.success;
    var msg = resp && resp.data && resp.data.message ? resp.data.message : null;
    if (ok) { modal.close(); NicePayToast.show(msg || adminI18n.shortcodeDeleted, 'success'); ... }
    else { modal.setLoading(false); modal.showError(msg || adminI18n.requestFailed); }
}, 'json')
```
`'json'` dataType'ı ekleyince JSON olmayan yanıtlar `.fail()`'e düşer ve zaten doğru mesaj gösterilir. Aynı koruma satır 273 için de gerekli.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Düzeltilmiş iddia: Çekirdek iddia doğru. Tek düzeltme: silme akışının BAŞARI dalı (satır 338) `adminI18n.shortcodeDeleted` sabitini kullandığı için güvenlidir; kanıtta "başarı dalı" olarak gösterilen satır 273 iptal akışına aittir ve `response.success` guard'ı sayesinde "0" senaryosunda tetiklenmez — orası bugün tetiklenemeyen, sunucu `wp_send_json_success()`'i argümansız çağırırsa açığa çıkacak teorik bir kırılganlıktır. Tetiklenebilir hata yalnızca satır 341'dedir (+ 336'daki erken modal.close() ve 331'deki eksik dataType).
- Gerekçe: Kodu satır satır okudum; iddia edilen her mekanik doğrulandı.

1) Konum/satır doğru: assets/js/nicepay-admin.js:331 `$.post(adminConfig.ajaxUrl, {...}, function(resp) {` — üçüncü argüman callback, dördüncü `dataType` argümanı YOK. 336'da `modal.close()` yanıt değerlendirilmeden çağrılıyor. 341'de `resp.data.message` hiçbir guard olmadan okunuyor (`if (resp.success)` else dalı, yani `resp` string "0" ise `"0".success === undefined` → else → `"0".data === undefined` → `.message` → TypeError).

2) "0" senaryosu gerçek: nicepay-payment-gateway.php:120'de yalnızca `wp_ajax_nicepay_delete_shortcode` kayıtlı, `wp_ajax_nopriv_...` yok (115-118'de init_payment/refresh_nonce için nopriv AÇIKÇA eklenmiş, yani kasıtlı fark). Çerez süresi dolduğunda admin-ajax nopriv handler bulamaz ve `die('0')` ile HTTP 200 + text/html gövde "0" döner. dataType belirtilmediği için jQuery içerik tipine göre düz string döndürür — `.fail()` tetiklenmez, done callback içinde exception atılır, 343'teki `.fail()` handler çalışmaz, hiçbir toast görünmez ve modal 336'da zaten kapanmıştır. Kart DOM'da kalır → sessiz başarısızlık.

3) Karşı örnek de doğru: iptal akışı aynı dosyada 280'de `response && response.data && response.data.message ? ... : adminI18n.cancelFailed` ile savunmacı yazılmış — yani proje içinde tutarsızlık var.

4) Küçük düzeltmeler (bulguyu çürütmüyor, kapsamını daraltıyor):
   - "Aynı korumasız erişim başarı dalında da var: satır 273" — 273 iptal akışının başarı dalı (silme akışının başarı dalı 338, orada `adminI18n.shortcodeDeleted` sabiti kullanılıyor, güvenli). 273 gerçekten `response.data.message`'ı korumasız okuyor; ancak guard `response && response.success` olduğu için "0" senaryosu buraya düşmez ve tek başarı yolu olan class-nicepay-transactions.php:1026 daima `message` gönderir. Yani 273 teorik kırılganlık (sunucu bir gün `wp_send_json_success()` argümansız çağırırsa patlar), 341 ise bugün tetiklenebilir hata.
   - Doğrulama/ID hatası dalları (nicepay-payment-gateway.php ajax_delete_shortcode, 400 statüsü) `.fail()`'e düşüp doğru mesajı gösteriyor; sorun yalnızca HTTP 200 + JSON olmayan gövde durumunda.

Önerilen düzeltme (`'json'` dataType + savunmacı okuma + modal'ı yanıttan sonra kapatma) doğru ve yeterli. Severity medium uygun: veri kaybı/güvenlik yok, ancak admin için gerçek ve tekrarlanabilir sessiz başarısızlık.

---

### JS-005 — Frontend modal focus trap'i `type="hidden"` inputları odaklanabilir sayıyor — ödeme sırasında trap sızıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | accessibility |
| **Konum** | [assets/js/nicepay.js:408](../../../assets/js/nicepay.js#L408) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````javascript
```js
const focusable = modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]');
...
const first = focusable[0];
const last = focusable[focusable.length - 1];
if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
```
`input:not([disabled])` seçicisi templates/standalone-payment-form.php:161-176'daki 13 adet `<input type="hidden">` ile `<input type="hidden" data-nicepay-goods-class>` alanını da yakalar. Bu düğümler asla `document.activeElement` olamaz. Ödeme başlayınca `setStandaloneLoading` "Öde" butonunu `disabled` yapar (nicepay.js:153-155), böylece `button:not([disabled])` onu listeden düşürür ve `last` DOM sırasındaki son gizli input olur — yani ulaşılamayan bir düğüm. Karşılaştırma: nicepay-admin.js:174-176 aynı seçiciyi kullanıyor ama o modalda sadece bir görünür text input olduğu için sorun ortaya çıkmıyor.
````

**Başarısızlık senaryosu**

Modal modundaki `[nicepay_payment]` kısayolu, tüm alıcı alanları önceden dolu (`show_buyer_fields` false). Modal açılır → `focusable` = [kapat butonu, 14 gizli input, öde butonu]. Kullanıcı "Öde"ye basar → buton disabled olur → `focusable` = [kapat butonu, 14 gizli input]. Kullanıcı Tab'a basar: `document.activeElement` (kapat butonu) `last` (gizli input) ile eşleşmez, `first` ile eşleşir ama `shiftKey` false → hiçbir dal çalışmaz → tarayıcı odağı modal dışına, arkadaki sayfaya taşır.

**Etki**

`aria-modal="true"` diyalog açıkken klavye odağı arka plandaki sayfaya ve tarayıcı arayüzüne kaçar. Ekran okuyucu/klavye kullanıcısı için modal artık modal değildir; geri dönüş yolu Escape'e indirgenir. Ödeme sürerken (butonun devre dışı olduğu, yani odağın en kritik olduğu an) bozulması özellikle kötü.

**Öneri**

Seçiciyi görünürlüğe göre filtrele:
```js
const focusable = Array.prototype.filter.call(
    modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]'),
    function (el) {
        return !el.disabled && el.type !== 'hidden' && el.tabIndex !== -1 &&
               el.getAttribute('aria-hidden') !== 'true' &&
               (el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    }
);
if (!focusable.length) { event.preventDefault(); modal.querySelector('.nicepay-payment-modal').focus(); return; }
```
Aynı düzeltmeyi nicepay-admin.js:174-176'ya da uygula (şu an zararsız ama aynı hatalı seçici).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Düzeltilmiş iddia: İddia esas olarak doğru. Tek düzeltme: nicepay-admin.js:174-176 "birebir aynı seçici" değil — jQuery `:disabled` pseudo'su, `select`/`textarea` yok, ek `aria-hidden` filtresi var; ancak `input` genel olarak dahil olduğundan aynı sınıf kusuru taşıyor. Ayrıca hata iddia edilenden bir tık daha kötü: buton disabled edilince odak `<body>`'ye düşer, bu yüzden ilk Tab'da ne `first` ne `last` eşleşir ve odak kesin olarak modal dışına kaçar.
- Gerekçe: Kodu satır satır okudum; iddianın her bileşeni doğrulandı.

1) Seçici gerçekten `input:not([disabled])` içeriyor ve görünürlük/`type=hidden` filtresi YOK — assets/js/nicepay.js:408. Trap mantığı yalnızca `document.activeElement === first/last` eşitliğine dayanıyor (satır 414-421), yani `last` odaklanamayan bir düğüm olduğunda hiçbir dal tetiklenmez ve tarayıcı odağı modal dışına taşır. Çürütecek bir koruma aradım: yakında `tabIndex`, `offsetParent`, `hidden`, `:visible` gibi hiçbir filtre yok (grep `focusable|querySelectorAll` sonucu: 408, 409, 414, 415 dışında ilgili satır yok). CSS ile de gizlenemez, çünkü `type="hidden"` inputlar zaten UA tarafından render edilmez ama `querySelectorAll` onları döndürür.

2) Gizli inputlar gerçekten modal ağacının içinde ve pay butonundan ÖNCE: templates/standalone-payment-form.php:161-176'da 14 adet `<input type="hidden">` (13 isimli + `data-nicepay-goods-class`), ardından 178-186'da submit butonu. Overlay `#<form_id>-modal` 83. satırda açılıyor ve 190-193'te kapanıyor, yani form ve tüm gizli inputlar `modal.querySelectorAll` kapsamında.

3) `setStandaloneLoading` gerçekten TÜM `[data-nicepay-start]` butonlarını disabled yapıyor (nicepay.js:153-155 — `document.querySelectorAll('[data-nicepay-start]').forEach(... button.disabled = loading)`), ve ödeme başlatma yolunda çağrılıyor (nicepay.js:383). Dolayısıyla ödeme sırasında `button:not([disabled])` pay butonunu listeden düşürür ve `last` = `data-nicepay-goods-class` gizli inputu olur. Ulaşılamayan düğüm.

4) Aslında hata iddiadan biraz DAHA geniş: butona tıklandığında buton odak alır, sonra disabled edilince `document.activeElement` `<body>`'ye düşer; body ne `first` ne `last` olduğu için Tab tuşunda hiçbir dal çalışmaz ve odak doğrudan belge başına/tarayıcı arayüzüne kaçar. Yani sızıntı ilk Tab'da kesin.

5) `first` referansı da doğru: 85. satırdaki kapatma butonu (`.nicepay-payment-modal-close`) DOM'daki ilk butondur.

Tek küçük düzeltme (severity'yi değiştirmez): iddia nicepay-admin.js:174-176 için "aynı seçici" diyor. Satır numarası doğru (174) ama seçici birebir aynı değil — jQuery `:disabled` pseudo'su kullanıyor, `select`/`textarea` içermiyor ve ek bir `aria-hidden !== 'true'` filtresi var; yine de `input` genel olarak dahil olduğu için aynı sınıf hataya açık, iddianın "şu an zararsız" değerlendirmesi geçerli.

Kapsam sınırı olarak not: sızıntı penceresi, başarılı init'te `form.submit()` ile sayfa değişene kadar sürer; AJAX hatasında `releaseStandalone` butonu yeniden etkinleştirir. Yani kalıcı değil ama ödeme akışının en kritik anında ve yavaş/başarısız ağda uzun sürebilir — medium severity yerinde.

---

### JS-006 — nicepay-admin.js'te çevrilemeyen sabit İngilizce metinler — `copyFailed` çevirisi mevcut ama yanlış localize nesnesinde

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | i18n |
| **Konum** | [assets/js/nicepay-admin.js:305](../../../assets/js/nicepay-admin.js#L305) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````javascript
```js
}).catch(function() { NicePayToast.show('Copy failed', 'error', 2000); });      // 305
...
NicePayToast.show(ok ? (adminI18n.copied || 'Copied!') : 'Copy failed', ok ? 'success' : 'error', 2000);  // 311
...
NicePayToast.show(resp.data.message || 'Error', 'error');                        // 341
...
}).catch(function() { NicePayToast.show('Copy failed', 'error', 2000); });      // 364
...
} else { NicePayToast.show('Copy failed', 'error', 2000); }                      // 375
```
`nicepayAdmin.i18n` (admin/class-nicepay-admin.php:76-97) 16 anahtar taşıyor ama içinde `copyFailed` ve `error` YOK. `'Copy failed'` dizesi aslında çevrilebilir durumda: admin/class-nicepay-admin.php:856 onu `nicepayShortcodeAdmin.i18n.copyFailed` altında sağlıyor ve languages/nicepay-payment-gateway.pot:478'de msgid olarak mevcut. Ancak `nicepay-admin.js` yalnızca `window.nicepayAdmin`'i okuyor (satır 9-14), o nesneye hiç erişemiyor.
````

**Başarısızlık senaryosu**

ko_KR arayüzlü bir mağaza yöneticisi HTTP üzerinden wp-admin'e girer (`navigator.clipboard` tanımsız) → `.nicepay-copy-btn` fallback dalına düşer → `document.execCommand('copy')` bazı tarayıcılarda false döner → ekranda Korece arayüzün ortasında "Copy failed" toast'ı belirir. Kullanıcı ne olduğunu anlamaz, çeviri ekibi de .pot'ta zaten çevrilmiş görünen dizeyi "tamam" sanar.

**Etki**

İşlemler listesinde TID kopyalama başarısız olduğunda ve kısayol kartından kopyalama başarısız olduğunda Korece/Türkçe/Çince yönetim panelinde İngilizce "Copy failed" görünür. `navigator.clipboard` HTTPS olmayan admin'de ve iframe'lerde yok olduğu için bu dal nadir değil. `'Error'` de aynı şekilde çevrilmez.

**Öneri**

`nicepayAdmin.i18n` dizisine eksik anahtarları ekle (admin/class-nicepay-admin.php:76-97):
```php
'copyFailed' => __( 'Copy failed', 'nicepay-payment-gateway' ),
'error'      => __( 'Error', 'nicepay-payment-gateway' ),
```
ve JS'te nicepay-shortcode-admin.js:19-41'deki `text()` yardımcısının eşdeğerini kullan: `adminI18n.copyFailed || 'Copy failed'`. Ayrıca CI'a bir kontrol ekle: `assets/js/*.js` içinde `Toast.show('...')` / `showNotice('...')` biçiminde düz string literal geçen çağrıları reddeden basit bir grep kuralı, bu sınıf hatanın tekrarını önler.

---

### JS-007 — Canlı bölge (aria-live) kullanımı yanlış: toast'lar ve WooCommerce yükleme overlay'i ekran okuyucuya hiç duyurulmuyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | accessibility |
| **Konum** | [assets/js/nicepay-admin.js:33](../../../assets/js/nicepay-admin.js#L33) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

````javascript
Toast: canlı bölge ve içeriği AYNI anda DOM'a giriyor (nicepay-admin.js:33-63):
```js
init: function() { if (!this.container) { this.container = element('div', 'nicepay-toast-container'); ... document.body.appendChild(this.container); } },
show: function(message, type, duration) {
    this.init();                                    // container ilk kullanımda oluşuyor
    var toast = element('div', 'nicepay-toast nicepay-toast-' + safeType);
    ...
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');      // canlı bölge metniyle birlikte ekleniyor
    toast.appendChild(icon); toast.appendChild(text);
    this.container.appendChild(toast);
```
Ekran okuyucular canlı bölgeyi DOM'a girdikten sonraki DEĞİŞİKLİKLERİ duyurur; içeriğiyle birlikte eklenen bir `aria-live` düğümü çoğu AT'de sessiz kalır.

WooCommerce overlay'i tersi hatayı yapıyor (templates/payment-form.php:23-26): düğüm sayfa yüklenirken sabit metniyle var, sadece CSS sınıfı değişiyor:
```html
<div class="nicepay-loading-overlay" id="nicepay-loading" role="alert" aria-live="assertive">
    <div class="nicepay-spinner"></div>
    <span class="nicepay-loading-text">Processing payment...</span>
</div>
```
nicepay.js:66 yalnızca `$('.nicepay-loading-overlay').addClass('is-active')` yapıyor; içerik hiç değişmediği için duyuru tetiklenmiyor. Ayrıca standalone formda hiç yükleme göstergesi yok — sadece butona `is-loading` sınıfı ekleniyor (nicepay.js:158) ve CSS'te `color: transparent !important` (nicepay.css:253-256) olduğu için görsel metin kayboluyor ama erişilebilir ad hâlâ "Pay Now"; `aria-busy` hiçbir yerde set edilmiyor.
````

**Başarısızlık senaryosu**

NVDA + Chrome kullanan yönetici İşlemler ekranında bir işlemi iade eder. Modal kapanır (nicepay-admin.js:272), `NicePayToast.show('Refund completed successfully.', 'success')` çağrılır, toast container ilk kez `document.body`'ye eklenir → NVDA hiçbir şey söylemez. Odak `lastFocus`'a (silinmiş olan iade butonuna, satır 278 `btn.remove()`) dönmeye çalışır; `document.contains` false olduğu için odak `<body>`ye düşer. Kullanıcı iadenin olup olmadığını bilmez.

**Etki**

Ekran okuyucu kullanan bir yönetici, iade başarılı/başarısız toast'ını duymaz — modal kapandığı için hiçbir geri bildirim almaz. Ekran okuyucu kullanan bir müşteri, ödeme başlatıldığında "Processing payment..." duyurusunu almaz; buton sessizce devre dışı olur ve tüm sayfa görünmez bir overlay ile bloke edilir. Ödeme akışının klavye+AT ile uçtan uca tamamlanabilirliği kırılıyor.

**Öneri**

1) Toast container'ını `NicePayToast.init()` ile DOM'a **boş** olarak, script yüklenir yüklenmez ekle ve `role="status" aria-live="polite" aria-atomic="true"` niteliklerini CONTAINER'a taşı; toast düğümleri o zaman içerik değişikliği olarak duyurulur.
2) Overlay için: `is-active` eklenirken metni JS'ten yaz (`$('.nicepay-loading-text').text(paymentI18n.processing)`) veya overlay'i sadece gerektiğinde `createElement` ile oluştur; ayrıca `document.body.setAttribute('aria-busy','true')` ekle ve `hideLoading()`'de kaldır.
3) Standalone butonlara `button.setAttribute('aria-busy', loading ? 'true' : 'false')` ve gizli bir `role="status"` metni ekle.
4) İade toast'ı gösterilirken odağı satır başına (`btn.closest('tr')`) veya durum hücresine taşı, çünkü tetikleyen buton siliniyor.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Canlı bölge (live region) kullanımı hatalı: (a) admin toast'larında `role="status"`/`aria-live="polite"` container'a değil, metniyle birlikte DOM'a giren toast düğümüne veriliyor (nicepay-admin.js:33-39 boş container'ı niteliksiz oluşturuyor, 59-63 dolu live düğümü ekliyor) — ilk toast kesin, sonrakiler AT'ye bağlı olarak sessiz kalır; (b) checkout overlay'i (templates/payment-form.php:23-26) sayfa yüklenirken sabit metniyle duruyor ve nicepay.js:66/90 yalnızca `is-active` class'ını değiştiriyor, içerik değişmediği için duyuru tetiklenmiyor; (c) standalone formda hiç yükleme göstergesi yok, nicepay.js:152-160 yalnızca `disabled` + `is-loading` uyguluyor, CSS metni `transparent` yapıyor (nicepay.css:253-256) ve nicepay.js'te `aria-busy` hiç set edilmiyor (admin modalinde set ediliyor: nicepay-admin.js:116, 211); (d) şablonlarda zaten var olan boş `<output aria-live="polite">` düğümleri (payment-form.php:18, standalone-payment-form.php:91) kullanılmak yerine nicepay.js:102'de siliniyor. Odak kaybı gerekçesi düzeltilmeli: `modal.close()` (272) `btn.remove()`'dan (278) ÖNCE çalıştığı için `document.contains(lastFocus)` TRUE'dur ve odak butona geri verilir; buton hemen ardından silindiği için odak body'ye düşer — yani `document.contains` guard'ı işe yarıyor, sorun çağrı sırası. Düzeltme: iptal başarılı olduğunda önce satır durumunu güncelleyip odağı `btn.closest('tr')` / durum hücresine taşı, sonra butonu sil ve modalı kapat.
- Gerekçe: Çekirdek iddia doğru ve kodla birebir doğrulandı; satır numaraları da doğru.

1) Toast: `NicePayToast.container` DOM'a yalnızca ilk `show()` çağrısında ekleniyor (nicepay-admin.js:33-39, `init()` `show()` içinden 42. satırda çağrılıyor). Container'da HİÇBİR live-region niteliği yok (35-37: sadece class + id). `role="status"`/`aria-live="polite"` toast düğümünün KENDİSİNE, metni eklenmeden hemen önce set ediliyor (59-60), sonra ikon+metin appendleniyor (61-62) ve dolu düğüm container'a ekleniyor (63). Yani ilk toast'ta live region + içeriği aynı anda DOM'a giriyor; sonraki toast'larda da eklenen düğümün kendisi live region olduğu için duyuru AT'ye göre güvenilmez. Bu klasik bir live-region hatası — iddia haklı.

2) Overlay: templates/payment-form.php:23-26 düğümü sabit metniyle sayfa yüklenirken var (`role="alert" aria-live="assertive"`), nicepay.js:66 yalnızca `addClass('is-active')` yapıyor, nicepay.js:90 `removeClass`. CSS'te tetiklenen tek şey `display:none` → `display:flex` (nicepay.css:428-444). İçerik değişmediği için duyuru tetiklenmiyor. Doğru.

3) Standalone: templates/standalone-payment-form.php'de hiçbir loading overlay yok (grep: yalnızca modal overlay, satır 83). `setStandaloneLoading` (nicepay.js:152-160) sadece `disabled` ve `is-loading` class'ı; CSS `color: transparent !important` (nicepay.css:253-256) görsel metni gizliyor ama erişilebilir ad değişmiyor. nicepay.js dosyasında `aria-busy` hiç geçmiyor (grep doğruladı). Doğru.

Ek destekleyici bulgu (iddiada yok): payment-form.php:18 ve standalone-payment-form.php:91'de sayfa yüklenirken duran `<output aria-live="polite">` canlı bölgeleri VAR, ama `showNotice` bunları doldurmak yerine `wrapper.find('.nicepay-notice').remove()` ile SİLİP (nicepay.js:102) yerine yeni `role="alert"` div oluşturuyor (105-115). Yani mevcut doğru altyapı kullanılmıyor — iddianın kök nedenini güçlendiriyor.

DÜZELTİLMESİ GEREKEN İKİ DETAY:
a) Başarısızlık senaryosundaki odak mekanizması yanlış açıklanmış. Kod sırası: `modal.close()` satır 272, `btn.remove()` satır 278. `close()` çalıştığı anda buton HÂLÂ DOM'da, dolayısıyla `document.contains(this.lastFocus)` TRUE döner (233-235) ve odak butona geri verilir; buton 6 satır sonra silinir. Sonuç yine "odak body'ye düşer" ama iddiadaki "document.contains false olduğu için" gerekçesi yanlış — bu, önerilen düzeltmenin şeklini de etkiler (sıra değişikliği: önce satırı güncelle/odağı taşı, sonra kapat).
b) Toast metni alıntısı uydurma: kod `NicePayToast.show(response.data.message, 'success')` (273), sabit 'Refund completed successfully.' değil. Ayrıca akış "iade" değil "cancel transaction" (`nicepay_cancel_transaction`, 265) — kullanıcıya görünen etiket iade/refund olsa da.
c) "aria-busy hiçbir yerde set edilmiyor" ifadesi global okunursa yanlış: admin modalinde set ediliyor (nicepay-admin.js:116 ve 211). Sadece frontend (nicepay.js) için doğru.

Severity `medium` yerinde: erişilebilirlik akışını bozuyor ama işlevsel/güvenlik kaybı yok ve `role="alert"` ile eklenen hata bildirimleri (nicepay.js:109, 175) çoğu AT'de duyurulduğu için hata yolu tamamen sessiz değil.

---

### JS-008 — "Başka bir ödeme devam ediyor" korumasi gerçek tarayıcıda ulaşılamaz; test bunu sentetik dispatchEvent ile kanıtlıyor ve asenkronluğu tamamen gizliyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | test-validity |
| **Konum** | [assets/js/nicepay.js:361](../../../assets/js/nicepay.js#L361) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````javascript
Koruma (nicepay.js:361-364):
```js
if (activePaymentForm && activePaymentForm !== form) {
    showStandaloneError(wrapper, form, translated('paymentInProgress', 'Another payment is already in progress.'));
    return;
}
```
Ama `activePaymentForm` set edildiği anda `setStandaloneLoading(form, true)` (satır 383) sayfadaki TÜM `[data-nicepay-start]` butonlarını `disabled` yapıyor. Tarayıcılar `disabled` bir `<button>` üzerinde click olayı üretmez ve olay kabarcıklanmaz, dolayısıyla satır 460'taki belge seviyesi delegasyon hiç tetiklenmez. Test bunu bilerek atlıyor (tests/js/nicepay-multi-instance.test.js:109-114):
```js
assert.equal(document.querySelector('[data-nicepay-start="form-b"]').disabled, true);
document.querySelector('[data-nicepay-start="form-b"]').dispatchEvent(
    new window.MouseEvent('click', { bubbles: true, cancelable: true })   // disabled butonu es geçen sentetik olay
);
assert.equal(document.querySelector('#form-b-wrapper [role="alert"]').textContent, '!Another payment is already in progress.');
```
Ayrıca sahte XHR `send()` içinde `this.onload()`'u SENKRON çağırıyor (test satır 66-88) ve `window.setTimeout` no-op'a çevriliyor (satır 54) — yani testte hiçbir asenkron sınır yok.
````

**Başarısızlık senaryosu**

Bir sayfada iki `[nicepay_payment]` kısayolu var. Müşteri A ürününün butonuna basar, PG penceresi açılır ama müşteri fikrini değiştirip B ürününü almak ister. B'nin butonu `disabled` olduğu için tıklama hiç kaydedilmez — ne mesaj, ne imleç değişikliği ötesinde bir ipucu. Testin doğruladığı "Another payment is already in progress." mesajı asla ekrana gelmez.

**Etki**

İki kat sorun: (a) tasarlanan kullanıcı mesajı gerçekte hiç görünmez; ikinci form için kullanıcı yalnızca sessizce ölü bir buton görür. (b) Test paketi bu davranışı "kanıtlanmış" gösterdiği için JS-001 (asenkron popup) ve JS-002 (takılı durum) gibi gerçek çoklu-instance/asenkron riskleri hiç test edilmiyor; ekip yanlış bir güvenle ilerliyor.

**Öneri**

Butonu `disabled` yapmak yerine `aria-disabled="true"` + `is-loading` sınıfı kullan ve tıklamayı JS'te ele al; böylece koruma dalı gerçekten çalışır:
```js
button.setAttribute('aria-disabled', loading ? 'true' : 'false');
button.classList.toggle('is-loading', loading && button.getAttribute('data-nicepay-start') === form.id);
```
(Yalnızca aktif formun butonu spinner alsın; diğerleri tıklanabilir kalıp mesajı göstersin.) Testte de sahte XHR'ı `queueMicrotask`/`setTimeout` ile asenkron çöz, gerçek `.click()` kullan ve `nicepayStart` çağrısının senkron kullanıcı hareketi içinde mi yoksa callback'te mi yapıldığını doğrulayan bir assertion ekle (ör. `window.__gestureActive` bayrağı).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddiada geçen tüm satır numaraları dosyanın şu anki haliyle bire bir eşleşiyor ve iddiayı çürütecek hiçbir koruma/alternatif yol bulunamadı.

1) Koruma dalı gerçekten assets/js/nicepay.js:361-364'te ve `startStandalone` fonksiyonuna TEK giriş noktası belge seviyesindeki delegasyon (satır 461-465). `grep -n "startStandalone"` başka çağıran göstermiyor.

2) `activePaymentForm` yalnızca iki yerde set ediliyor: satır 62 (legacy NicePayHandler.startPayment) ve satır 378 (startStandalone). Her ikisinde de aynı senkron blokta tüm başlat butonları disable ediliyor:
   - satır 383 -> `setStandaloneLoading(form, true)` -> satır 153-155 `document.querySelectorAll('[data-nicepay-start]')...button.disabled = loading` (form ayrımı yok, TÜM butonlar).
   - satır 65 `$('.nicepay-pay-button').prop('disabled', true)` — ve templates/standalone-payment-form.php:27 `array_unshift($button_classes, 'nicepay-pay-button')` ile standalone başlat butonu da bu sınıfı taşıdığı için (aynı dosya satır 186-187: `class="<?php echo esc_attr($button_class); ?>" data-nicepay-start=...`) legacy yol da standalone butonları disable ediyor.
   Yani `activePaymentForm != null` olan her an, tüm `[data-nicepay-start]` butonları `disabled`. Disabled `<button>` üzerinde tarayıcı click olayı üretmez/kabarcıklandırmaz, dolayısıyla 461'deki delege dinleyici çalışmaz ve 361'deki dal ölü koddur.

3) Başarılı init yolunda (satır 333-337) `releaseStandalone` ÇAĞRILMIYOR — `window.nicepayStart(); return;`. Butonlar PG penceresi kapanıp `window.nicepayClose()` (satır 486-491) tetiklenene kadar disabled kalıyor. Yani "PG penceresi açıkken ikinci forma tıklama" senaryosu tam olarak iddia edilen ölü-buton durumu.

4) Test tarafı da iddia edildiği gibi: satır 109 disabled=true'yu doğruluyor, sonra 110-112'de gerçek `.click()` yerine elle `dispatchEvent(new MouseEvent(...))` kullanıyor (jsdom da disabled butonda `.click()` ile olay tetiklemediği için bu bilinçli bir kaçış), 114'te de gerçekte asla görünmeyecek mesajı assert ediyor. Ayrıca satır 54 `window.setTimeout = function() { return 1; }` ve satır 87 `this.onload()`'un `send()` içinde senkron çağrılması ile testte hiçbir asenkron sınır kalmıyor; `nicepayStart`'ın kullanıcı hareketi (gesture) içinde mi yoksa XHR callback'inde mi çağrıldığı ayırt edilemiyor.

Tek nüans: "sessizce ölü buton" ifadesi biraz yumuşatılabilir — aktif formun butonu `is-loading` sınıfı alıyor (satır 156-158) ve diğerleri native disabled görünümü alıyor; ama diğer form için 361'deki açıklayıcı mesaj gerçekten hiç gösterilmiyor. Bu, iddianın özünü değiştirmiyor. severity: medium yerinde.

---

### JS-009 — Blocks JS: hiç JS testi yok, sessizce kayboluyor, `canMakePayment` sabit, `wp_set_script_translations` ölü kod

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | blocks-integration |
| **Konum** | [assets/js/nicepay-blocks.js:4](../../../assets/js/nicepay-blocks.js#L4) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````javascript
```js
if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
    return;                       // tanı çıktısı yok, hata yok, sessiz kayıp
}
...
canMakePayment: function () { return settings.is_available === true; },
```
`is_available`, sunucuda `NicePay_Blocks_Integration::is_active()` ile hesaplanıyor (class-nicepay-blocks-integration.php:70) — ama `AbstractPaymentMethodType::is_active()` false ise WooCommerce script'i zaten hiç kuyruğa almaz. Yani `canMakePayment` pratikte her zaman `true`; sepet para birimi/toplamı üzerinden dinamik bir kontrol yok (`canMakePayment( { cartTotals } )` argümanı hiç kullanılmıyor) — oysa gateway'in kendisi KRW ve HTTPS şartı koyuyor (class-nicepay-gateway.php:76-103).

`wp_set_script_translations( $handle, 'nicepay-payment-gateway', ... )` (class-nicepay-blocks-integration.php:47-49) çağrılıyor ama nicepay-blocks.js `wp.i18n`'i hiç kullanmıyor; tüm metinler `wcSettings` üzerinden PHP'de çevrilmiş geliyor. Yani JSON çeviri dosyası boşuna aranıyor.

tests/js/ altında blocks için hiçbir test yok (`nicepay-admin.test.js`, `nicepay-multi-instance.test.js`, `nicepay-shortcode-admin.test.js`); .github/scripts/check-js.js dosyayı yalnızca `node --check` ile sözdizimi açısından tarıyor.
````

**Başarısızlık senaryosu**

Bir çoklu para birimi eklentisi sepeti USD'ye çevirir. `get_payment_method_data()` sayfa yüklenirken hesaplandığı için `is_available` hâlâ `true` gelir; müşteri Blocks checkout'ta NICEPAY'i seçer, "Continue to NicePay"e basar ve Store API `process_payment()`'ta `is_available()` KRW kontrolünden düşerek hata döner. Müşteri checkout'un sonunda genel bir hata görür.

**Etki**

Blocks checkout, sitelerin çoğunda artık varsayılan. Bir bağımlılık yüklenmezse (başka bir eklenti `wc-blocks-registry`'yi bozarsa, script hatası sıra bozarsa) NICEPAY ödeme yöntemi Blocks checkout'tan sessizce kaybolur ve mağaza sahibi hiçbir uyarı görmez — müşteri "ödeme yöntemi yok" ekranıyla karşılaşır. `canMakePayment`'ın sabit olması, çok para birimli eklentilerle (WPML/Multi-Currency) KRW dışına geçen sepetlerde NICEPAY'in yine de görünmesine yol açar; ödeme `process_payment` aşamasında reddedilir.

**Öneri**

1) Erken çıkışta tanı bırak: `if (window.console && console.warn) { console.warn('[NicePay] WooCommerce Blocks registry unavailable; payment method not registered.'); }`.
2) `canMakePayment`'ı sepete bağla:
```js
canMakePayment: function ( args ) {
    if ( settings.is_available !== true ) { return false; }
    var totals = args && args.cartTotals;
    return ! totals || ! totals.currency_code || totals.currency_code === ( settings.currency || 'KRW' );
}
```
ve `get_payment_method_data()`'ya `'currency' => get_option('nicepay_currency','KRW')` ekle.
3) `wp_set_script_translations` ya kaldırılsın ya da JS gerçekten `wp.i18n.__()` kullansın (o zaman `wp-i18n` bağımlılığa eklenmeli).
4) `tests/js/nicepay-blocks.test.js` ekle: `window.wc.wcBlocksRegistry.registerPaymentMethod`'u yakalayan bir sahte ile `name`, `canMakePayment` sonucu, test-mode etiketi ve XSS güvenliği doğrulansın; `.github/scripts/check-js.js`'teki allowlist gibi bu da düzenli tutulsun.

---

### JS-010 — HTTP 403 körü körüne "eski nonce" sayılıyor; nonce yenileme hatası her durumda "oturum süresi doldu" diyor ve başarısız her deneme sunucuda öksüz `pending` satır bırakıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | error-handling |
| **Konum** | [assets/js/nicepay.js:306](../../../assets/js/nicepay.js#L306) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````javascript
```js
request.onload = function() {
    if (request.status === 403 && !wasRetried) {
        const refresh = new XMLHttpRequest();
        ...
        refresh.onload = function() {
            try { const nonceResponse = JSON.parse(refresh.responseText);
                if (refresh.status >= 200 && refresh.status < 300 && nonceResponse.success && ...) { ...; return; }
            } catch {}
            releaseStandalone(form);
            showStandaloneError(wrapper, form, translated('sessionExpired', 'Payment session expired. ...'));
        };
```
Oysa `ajax_init_payment` 403'ü İKİ farklı sebeple döndürüyor: standalone kapalıysa (nicepay-payment-gateway.php:534) ve nonce geçersizse (satır 552). Ayrıca `ajax_refresh_payment_nonce` 429 (satır 642) veya 503 (satır 637) dönebiliyor — her ikisi de gövdesinde doğru `data.message` taşıyor ama JS onu okumadan sabit "session expired" gösteriyor.

Ayrıca `nicepay_save_transaction()` (satır 589-609) yanıt üretilmeden ÖNCE `pending` satırı yazıyor; JS `populateStandaloneForm` başarısız olursa (satır 249-251, 244-246) veya `nicepayStart` yoksa (satır 333-334) o satır kullanılmadan kalıyor.
````

**Başarısızlık senaryosu**

Bir kampanya sırasında aynı IP'den (kurumsal NAT) çok sayıda kullanıcı ödeme başlatır. `nicepay_check_public_rate_limit('standalone_init')` 429 döndürür → JS doğru mesajı gösterir (bu dal çalışır). Ancak nonce'u eskimiş cache'li bir sayfada 403 alan kullanıcı için `nicepay_refresh_nonce` de 429'a takılır → JS "Payment session expired. Please refresh the page and try again." der → kullanıcı sayfayı yeniler → yeni bir istek daha → limit uzar. Gerçek sebep ("biraz bekleyin") hiç iletilmez.

**Etki**

Mağaza sahibi standalone'u kapattığında müşteri gereksiz bir ek ağ turu yaşar; hız limitine takılan müşteri "çok fazla istek" yerine "oturumunuz doldu, sayfayı yenileyin" duyar ve yenileyip tekrar deneyerek hız limitini derinleştirir. Yanlış teşhis + yanlış kullanıcı eylemi.

**Öneri**

1) Nonce yenilemeyi yalnızca sunucunun açıkça işaretlediği durumda tetikle: `wp_send_json_error( array( 'message' => ..., 'code' => 'invalid_nonce' ), 403 )` ekle ve JS'te `response.data && response.data.code === 'invalid_nonce'` koşulunu ara — HTTP durum koduna değil.
2) Yenileme başarısız olursa gövdedeki gerçek mesajı göster: `showStandaloneError(wrapper, form, (nonceResponse && nonceResponse.data && nonceResponse.data.message) || translated('sessionExpired', ...))`.
3) `nicepayStart` bulunamadığında `translated('systemUnavailable', ...)` kullan — bugün `throw` edilip aynı `catch`'e düştüğü için kullanıcı "An unexpected error occurred." görüyor, oysa doğru mesaj zaten localize edilmiş durumda.
4) İstemci tarafında iptal edilen denemeler için `navigator.sendBeacon` ile hafif bir "abandon" bildirimi gönder ya da `pending` satırını yalnızca form gerçekten gönderilebilir hale geldiğinde yaz.

---

### JS-011 — Kısayol üreticinin önizlemesi gerçek formdan sapıyor: tek yöntemde bile seçici gösteriliyor, ikonlar düşürülüyor, KRW dışı biçimlendirme ölü kod

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | preview-fidelity |
| **Konum** | [assets/js/nicepay-shortcode-admin.js:147](../../../assets/js/nicepay-shortcode-admin.js#L147) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````javascript
```js
if (!payMethod) { renderMethodOptions(); $('#sc-pv-methods').show(); }
else { $('#sc-pv-methods').hide(); }
```
Gerçek şablon ise ek bir koşul taşıyor (templates/standalone-payment-form.php:59):
```php
$show_method_selector = empty( $pay_method ) && count( $enabled_methods ) > 1;
```
Ayrıca `renderMethodOptions` (satır 115-124) ikonu bilerek atıyor — `enabledMethods` yalnızca `label` taşıyor (admin/class-nicepay-admin.php:838-842) — oysa gerçek form `nicepay_get_method_icon( $method )` ile SVG ikon basıyor (standalone-payment-form.php:113). Son olarak satır 139-142 KRW dışı için `parseFloat(...).toFixed(2)` dalı barındırıyor ama `#sc-currency` yalnızca tek bir `<option value="KRW">` içeriyor (admin/class-nicepay-admin.php:923-925) — ölü kod. Mevcut test bu farkı yakalamıyor çünkü `pay_method: 'CARD'` ile çalışıyor (nicepay-shortcode-admin.test.js:46,62).
````

**Başarısızlık senaryosu**

Ayarlar'da yalnızca CARD etkin. Yönetici Kısayol Üretici'de "Tüm Etkin" (value="") seçili bırakır. Sağdaki önizleme "Ödeme Yöntemi: Kart" bloğunu gösterir. Yönetici kısayolu kaydedip sayfaya gömer; gerçek formda o blok yoktur çünkü `count($enabled_methods) > 1` false'tur. Yönetici bir hata olduğunu düşünüp destek açar.

**Etki**

Yalnızca CARD etkinken "Tüm Etkin" seçeneğiyle kısayol kuran yönetici, önizlemede bir ödeme yöntemi seçici görür ama yayımladığı sayfada seçici olmaz. Önizleme ile gerçek arasındaki her sapma, aracı "tahmin edilebilir" olmaktan çıkarır; ikonların yokluğu da düğme rengini/dengesini yanlış değerlendirmeye yol açar.

**Öneri**

`config.enabledMethods.length` bilgisini kullanarak şablonla aynı koşulu kur:
```js
var methodCount = (config.enabledMethods || []).length;
if (!payMethod && methodCount > 1) { renderMethodOptions(); $('#sc-pv-methods').show(); }
else { $('#sc-pv-methods').hide(); }
```
`$method_options` dizisine ikonun güvenli bir temsilini ekle (ör. `'code' => $code`) ve önizlemede CSS ile aynı ikonu göster — ya da gerçek formdaki ikonları kaldırıp iki tarafı eşitle. `parseFloat`/`toFixed(2)` dalını, para birimi seçimi gerçekten çoklu hale gelene kadar kaldır.

---

### JS-012 — Frontend modal durumu referans sayımlı değil: aynı modal iki kez açılırsa keydown dinleyicisi sızıyor, iki farklı modalda body scroll kilidi kalıcı olabiliyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | listener-leak |
| **Konum** | [assets/js/nicepay.js:393](../../../assets/js/nicepay.js#L393) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````javascript
```js
function openModal(formId) {
    var modal = document.getElementById(formId + '-modal');
    if (!modal) return;                       // "zaten açık mı?" kontrolü YOK
    var state = { trigger: document.activeElement, bodyOverflow: document.body.style.overflow };
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    ...
    state.keyHandler = keyHandler;
    modalState.set(formId, state);            // eski state üzerine yazılıyor
    document.addEventListener('keydown', keyHandler);   // eski dinleyici hiç kaldırılmıyor
```
`closeModal` yalnızca `modalState`'teki SON handler'ı kaldırıyor (satır 437). Ayrıca `bodyOverflow` her açılışta o anki değerden kaydedildiği için iç içe/sıralı açılışlarda 'hidden' değeri "orijinal" sanılıp geri yazılabiliyor (satır 398 ve 435).
````

**Başarısızlık senaryosu**

Klavye kullanıcısı modal tetikleyicisine odaklanıp Space'e basar (buton aktivasyonu) ardından hâlâ odakta olduğu için Enter'a da basar — `openModal` iki kez çalışır. `modalState` ikinci state ile ezilir; birinci `keyHandler` belgede asılı kalır. Kullanıcı modalı kapatır (`closeModal` ikinci handler'ı kaldırır, `bodyOverflow`='hidden' geri yazılır çünkü ikinci açılışta o değer kaydedilmişti) → modal kapalı ama sayfa artık kaydırılamıyor; sayfanın geri kalanı kullanılamaz.

**Etki**

Aynı `data-nicepay-open-modal` tetikleyicisi iki kez etkinleşirse (klavye ile Enter+Space, hızlı çift tık, ya da tema JS'inin programatik click'i) belge üzerinde kalıcı bir `keydown` dinleyicisi kalır: kullanıcı sayfanın herhangi bir yerinde Escape'e bastığında görünmez bir `closeModal` çalışır ve `document.body.style.overflow` yanlış değere geri döner. Kötü senaryoda sayfa modal kapalıyken kaydırılamaz halde kalır.

**Öneri**

Açılışta yeniden giriş koruması ve tek bir kilit sayacı kullan:
```js
function openModal(formId) {
    var modal = document.getElementById(formId + '-modal');
    if (!modal || modalState.has(formId)) { return; }
    if (modalState.size === 0) { originalBodyOverflow = document.body.style.overflow; }
    ...
}
function closeModal(formId) {
    ...
    modalState.delete(formId);
    if (modalState.size === 0) { document.body.style.overflow = originalBodyOverflow; }
}
```
Ayrıca ödeme sürerken modal kapatılırsa (`activePaymentForm` hâlâ o formu gösteriyorsa) ya kapatmayı engelle ya da `releaseStandalone()` çağırarak durumu temizle — bugün modal kapanıp buton kilitli kalabiliyor.

---

### JS-013 — `document.payForm` küresel adına dayalı sözleşme kırılgan: WooCommerce şablonu adı kalıcı veriyor, standalone geçici; ikisi aynı sayfada olursa `document.payForm` bir koleksiyona dönüşüyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | fragility |
| **Konum** | [assets/js/nicepay.js:379](../../../assets/js/nicepay.js#L379) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````javascript
Standalone akış PG kütüphanesinin `document.payForm`'u okuduğunu varsayıp adı dolaştırıyor (nicepay.js:379-382):
```js
document.querySelectorAll('.nicepay-standalone-form[name="payForm"]').forEach(function(otherForm) {
    otherForm.removeAttribute('name');
});
form.setAttribute('name', 'payForm');
```
Ama seçici `.nicepay-standalone-form` ile sınırlı; WooCommerce şablonu adı kalıcı olarak veriyor (templates/payment-form.php:56): `<form id="nicepay-pay-form" name="payForm" ...>`. İkisi aynı belgede bulunursa `document.payForm` iki elemanlı bir `HTMLCollection` döner ve `nicepayStart()` form yerine koleksiyon alır.

Ayrıca `NicePayHandler.showNotice` bu eski API'ye yaslanıyor (nicepay.js:98): `var wrapper = $(document.payForm).closest('.nicepay-payment-wrapper');` — standalone sayfalarda `document.payForm` çoğu zaman `undefined` olduğu için sessizce satır 100'deki `$('.nicepay-payment-wrapper').first()` fallback'ine düşüyor, yani çoklu instance'ta yanlış wrapper'a yazma riski var.
````

**Başarısızlık senaryosu**

Mağaza order-pay sayfasına "Bağış ekle" amacıyla bir `[nicepay_payment]` kısayolu gömer. Müşteri WooCommerce "Ödemeye geç" butonuna basar → `startPayment()` → `nicepayStart()` → kütüphane `document.payForm` okur → iki `name="payForm"` olduğu için RadioNodeList döner → `goPay` alan okumaya çalışırken hata verir → nicepay.js:71-77 catch'i devreye girer ve kullanıcı "Payment error occurred." görür; hiç ödeme yapılamaz.

**Etki**

Bir tema/eklenti order-pay şablonunu özelleştirip aynı sayfaya bir `[nicepay_payment]` kısayolu koyduğunda ödeme başlatma sessizce bozulur. Ayrıca `showNotice`'ın "aktif formu bul" mantığı gerçekte hiç çalışmıyor; çoklu standalone formda hata mesajı ilk wrapper'a yazılabilir.

**Öneri**

WooCommerce şablonunda da adı kalıcı vermeyi bırak (`name` niteliğini kaldır) ve `startPayment()` içinde standalone akışıyla aynı şekilde geçici olarak ata:
```js
document.querySelectorAll('form[name="payForm"]').forEach(function (f) { f.removeAttribute('name'); });
form.setAttribute('name', 'payForm');
```
(Temizleme seçicisinden `.nicepay-standalone-form` kısıtını da kaldır.) `showNotice` içinde `document.payForm` yerine `activePaymentForm`'u kullan: `var wrapper = $(activePaymentForm || document.getElementById('nicepay-pay-form')).closest('.nicepay-payment-wrapper');`

---

### JS-014 — Standalone form kimliği `wp_rand(1000, 9999)` ile üretiliyor — aynı sayfadaki iki kısayol çakışabilir ve JS yanlış formu hedefler

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | multi-instance |
| **Konum** | [templates/standalone-payment-form.php:62](../../../templates/standalone-payment-form.php#L62) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
$form_id = 'nicepay-standalone-' . wp_rand( 1000, 9999 );
```
Tüm JS kancaları bu kimliğe bağlı: `startStandalone(formId)` → `document.getElementById(formId)` ve `document.getElementById(formId + '-wrapper')` (nicepay.js:357-358), `openModal`/`closeModal` → `formId + '-modal'` (nicepay.js:394, 432). Yalnızca 9000 olası değer var; `document.getElementById` yinelenen kimlikte belge sırasındaki İLK düğümü döndürür.
````

**Başarısızlık senaryosu**

Bir fiyatlandırma sayfasında 3 paket için 3 `[nicepay_payment]` kısayolu var. `wp_rand` iki kez 4271 üretir. Müşteri "Pro paket" butonuna (`data-nicepay-start="nicepay-standalone-4271"`) basar; `getElementById` belgedeki İLK 4271 formunu, yani "Başlangıç paketi" formunu döndürür. Sunucuya o formun `data-nicepay-config-id`'si gider ve müşteri Pro'ya tıklamışken Başlangıç tutarını öder.

**Etki**

Aynı sayfada iki kısayol varsa ~%0,011 çakışma olasılığı (sayfa başına; birçok kısayol içeren fiyat/paket sayfalarında doğum günü problemine göre hızla büyür). Çakışma olduğunda ikinci butona basmak birinci formu doldurur ve YANLIŞ ürün/tutar için ödeme başlatılır — üstelik bu, sessiz ve tekrarlanamaz bir hata olarak görünür. Ayrıca kimlik her sayfa yüklemesinde değiştiği için tam sayfa cache'i ile üretilen HTML, cache anındaki kimliği dondurur (bu tarafı zararsız ama kimliğin rastgeleliğinin hiçbir faydası da yok).

**Öneri**

Deterministik ve çakışmasız bir kimlik üret; sayfa içi bir sayaç veya konfigürasyon kimliğini kullan:
```php
static $instance = 0;
$instance++;
$form_id = 'nicepay-standalone-' . sanitize_html_class( $config_id ) . '-' . $instance;
```
Bu aynı zamanda otomasyon testleri ve tema CSS'i için de kararlı bir seçici sağlar. `wp_rand` gerekiyorsa en az `wp_unique_id()` kullan (WP 5.0+) — o zaten belge içinde artan ve benzersizdir.

---

### JS-015 — ESLint yapılandırması `@eslint/js` recommended'ı genişletmiyor — üç kural dışında hiçbir koruma yok; üretimde `console.error` kalmış; check-js.js elle bakımlı allowlist taşıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | tooling |
| **Konum** | [eslint.config.js:22](../../../eslint.config.js#L22) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````javascript
```js
rules: {
    'no-undef': 'error',
    'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
    'no-empty': ['error', { allowEmptyCatch: true }]
}
```
`js.configs.recommended` hiç dahil edilmemiş; yani `no-dupe-keys`, `no-unreachable`, `no-fallthrough`, `no-cond-assign`, `no-dupe-else-if`, `no-self-assign`, `no-constant-condition` gibi klasik hata yakalayıcılar KAPALI. Doğrulama: recommended kural setiyle çalıştırdığımda tek yeni ihlal `nicepay.js:322 no-empty` (bilinçli olarak izinli) ve `nicepay.js:72 no-console` çıktı:
```js
console.error('[NicePay] nicepayStart() threw:', e);
```
Bu satır üretim ödeme sayfasında müşteri konsoluna iç detay yazıyor. Ayrıca .github/scripts/check-js.js:9-24 elle tutulan bir dosya adı allowlist'i içeriyor ve yaptığı iş (`node --check`) ESLint'in ayrıştırmasıyla zaten örtüşüyor:
```js
if (JSON.stringify(discoveredFilenames) !== JSON.stringify(expectedFilenames)) { console.error('ERROR: assets/js allowlist is stale; ...'); process.exit(1); }
```
````

**Başarısızlık senaryosu**

Bir geliştirici `paymentI18n` nesnesine yanlışlıkla aynı anahtarı iki kez ekler veya `translated()` switch'inde `break`/`return` unutur. `npm run quality` ve `composer quality` yeşil geçer, hata üretime çıkar; çeviri metni sessizce yanlış anahtardan gelir.

**Etki**

Bugün kod temiz olduğu için ihlal yok, ama kapı da yok: ileride eklenen `switch` düşmesi, yinelenen nesne anahtarı veya ulaşılamaz `return` sonrası kod CI'dan sorunsuz geçer. `no-console` kapalı olduğu için üretim konsol gürültüsü de kalıcı. `check-js.js`'in allowlist'i yeni bir JS dosyası eklendiğinde CI'ı hataya düşürerek gereksiz sürtünme yaratıyor.

**Öneri**

eslint.config.js'i sertleştir:
```js
const js = require('@eslint/js');
module.exports = [
  { files: ['assets/js/**/*.js'], ...js.configs.recommended,
    languageOptions: { /* mevcut */ },
    rules: { ...js.configs.recommended.rules,
      'no-console': ['error', { allow: ['warn'] }],
      eqeqeq: ['error', 'smart'],
      'no-empty': ['error', { allowEmptyCatch: true }],
      'no-implicit-globals': 'error'
    } }, ... ];
```
`nicepay.js:72`'deki `console.error`'ı kaldır veya `console.warn` ile bir kez, ayıklanabilir bir ön ek altında bırak. `check-js.js`'i ya tamamen kaldır (ESLint aynı ayrıştırmayı yapıyor) ya da allowlist'i kaldırıp `assets/js/*.js` glob'unu doğrudan tara.

---

### JS-016 — Ödeme hata bildirimleri kendiliğinden kayboluyor ve "solma" için CSS geçişi tanımlı değil

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | ux-polish |
| **Konum** | [assets/js/nicepay.js:186](../../../assets/js/nicepay.js#L186) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````javascript
```js
window.setTimeout(function() {
    if (!notice.parentNode) return;
    notice.style.opacity = '0';
    window.setTimeout(function() { if (notice.parentNode) notice.remove(); }, 300);
}, 8000);
```
`.nicepay-notice` için CSS'te yalnızca giriş animasyonu var, `opacity` üzerinde `transition` YOK (assets/css/nicepay.css:469-478: `padding/border-radius/margin-bottom/font-size/display/align-items/gap/animation`). Yani `opacity='0'` anında uygulanır, 300 ms'lik bekleme boş yere geçer ve kullanıcı bir "kaybolma" değil ani bir sıçrama görür. WooCommerce dalında da aynı desen 6 sn ile var (nicepay.js:126-128, orada jQuery `fadeOut` kullanıldığı için görsel olarak düzgün ama süre yine kısa).
````

**Başarısızlık senaryosu**

Müşteri "Öde"ye basar, sunucu 400 ile "Payment configuration contains an invalid amount..." döndürür. Müşteri tam o sırada telefonuna bakar; 8 saniye sonra ekrana döndüğünde hata kaybolmuş, buton yeniden etkin. Tekrar basar, aynı şey olur. Neyin yanlış olduğunu asla öğrenmez.

**Etki**

Ödeme hataları ("Payment initialization failed.", "Connection error...") 6-8 saniye sonra kendiliğinden yok oluyor. Yavaş okuyan, ekran büyütücü kullanan veya sekmeye geri dönen kullanıcı hatayı hiç görmeden kaybeder ve neden ödeyemediğini anlayamaz. Ödeme hataları kalıcı olmalı; kendiliğinden kaybolan bildirimler bilgi/başarı mesajları içindir.

**Öneri**

Hata bildirimlerini otomatik kapatma; bunun yerine erişilebilir bir kapatma butonu ekle:
```js
var dismiss = document.createElement('button');
dismiss.type = 'button';
dismiss.className = 'nicepay-notice-dismiss';
dismiss.setAttribute('aria-label', translated('dismiss', 'Kapat'));
dismiss.textContent = '×';
dismiss.addEventListener('click', function () { notice.remove(); });
```
Sadece bilgi/başarı tipleri için zamanlayıcıyı koru. Solma isteniyorsa CSS'e `.nicepay-notice { transition: opacity 300ms ease; }` ekle — aksi halde 300 ms'lik `setTimeout` yanıltıcı ölü koddur.

---

### JS-017 — Kullanılmayan/ölü sözleşme parçaları: `nicepayParams.returnUrl` ve `i18n.processing` hiç okunmuyor, cancel AJAX'ına gönderilen `tid` sunucuda yok sayılıyor

| | |
|---|---|
| **Severity** | ⚪ Bilgi |
| **Kategori** | maintainability |
| **Konum** | [nicepay-payment-gateway.php:507](../../../nicepay-payment-gateway.php#L507) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`wp_localize_script('nicepay-js', 'nicepayParams', ...)` içinde iki alan JS'te hiç kullanılmıyor:
- satır 507 `'returnUrl' => nicepay_get_standalone_return_url()` — nicepay.js dönüş URL'ini her zaman AJAX yanıtından alıyor (satır 248-262); `paymentConfig.returnUrl` hiçbir yerde geçmiyor.
- satır 510 `'processing' => __( 'Processing payment...' )` — `translated()` switch'inde (nicepay.js:132-146) ve `paymentI18n` erişimlerinde yok; overlay metni şablondan basılıyor (templates/payment-form.php:25).

Benzer şekilde nicepay-admin.js:266 `tid: tid` gönderiyor ama `ajax_cancel_transaction` (admin/class-nicepay-transactions.php:969-972) yalnızca `id`, `reason`, `nonce` okuyor; `tid` doğrulaması sunucuda `_nicepay_tid` meta'sı üzerinden yapılıyor (satır 996-997).

Ayrıca `standaloneField(form, 'BuyerName').value` (nicepay.js:295-297) null koruması olmadan okunurken hemen üstündeki `methodInput` için koruma var (satır 285) — tutarsız savunma seviyesi.
```

**Başarısızlık senaryosu**

Yeni bir geliştirici `nicepay_get_standalone_return_url()`'i değiştirir ve `nicepayParams.returnUrl` üzerinden yayıldığını sanarak JS tarafında ek doğrulamayı gereksiz görüp `sameOriginHttpsUrl` kontrolünü kaldırır; gerçekte JS o alanı hiç okumadığı için değişikliğin etkisi görülmez ama güvenlik kontrolü kaybolur.

**Etki**

İstemci-sunucu sözleşmesi olduğundan daha geniş görünüyor; bir sonraki geliştirici `returnUrl`'in kullanıldığını varsayıp güvenlik akıl yürütmesini yanlış kurabilir. Kullanılmayan `tid` parametresi ise okuyucuya sunucunun onu doğruladığı izlenimini verir.

**Öneri**

`nicepayParams`'tan `returnUrl` ve `i18n.processing`'i kaldır (ya da `processing`'i gerçekten `translated()` üzerinden overlay metnine bağla). nicepay-admin.js:266'daki `tid` alanını kaldır veya sunucuda `hash_equals` ile ek bir doğrulama olarak kullan. `standaloneField(form,'BuyerName'|'BuyerEmail'|'BuyerTel')` çağrılarını `methodInput` ile aynı şekilde koru:
```js
var nameInput = standaloneField(form, 'BuyerName');
var emailInput = standaloneField(form, 'BuyerEmail');
var telInput = standaloneField(form, 'BuyerTel');
if (!ajaxUrl || !methodInput || !nameInput || !emailInput || !telInput) { /* systemUnavailable */ }
```

---

## Düşmanca doğrulamada elenen iddialar

| ID | İddia | Konum | Neden elendi |
|---|---|---|---|
| JS-001 | Standalone akışta PG penceresi XHR callback'inden açılıyor — popup blocker ve kaybolan user activation | `assets/js/nicepay.js` | JS-001'in kod yapısına dair gözlemi doğru (WC yolu senkron, standalone yolu XHR onload içinde), ama bulgunun ÇEKİRDEK MEKANİZMASI — "nicepayStart() → goPay() PC'de window.open kullanır, dolayısıyla transient user activation gerekir ve popup blocker devreye girer" — vendor script'in gerçek kaynağıyla |

## Kapsam

İncelenenler (tamamı baştan sona okundu): assets/js/nicepay.js (499 satır), assets/js/nicepay-admin.js (380), assets/js/nicepay-shortcode-admin.js (312), assets/js/nicepay-blocks.js (51), tests/js/nicepay-multi-instance.test.js (158), tests/js/nicepay-admin.test.js (142), tests/js/nicepay-shortcode-admin.test.js (79), eslint.config.js, .github/scripts/check-js.js, package.json. JS'in bağlandığı sunucu tarafı da doğrulandı: nicepay-payment-gateway.php (enqueue 477-525, ajax_init_payment 532-629, ajax_refresh_payment_nonce 634-650, ajax_delete/save_shortcode 655-792, hook kayıtları 93-127), admin/class-nicepay-admin.php (enqueue+localize 49-98, kısayol üretici markup ve localize 818-1108, order özeti 1127-1197), admin/class-nicepay-transactions.php (buton markup 842/924, ajax_cancel_transaction 969-1027), includes/class-nicepay-blocks-integration.php (tamamı), templates/payment-form.php ve templates/standalone-payment-form.php (tamamı), assets/css/nicepay.css'in ilgili blokları (is-loading 253-268, modal 355-375, loading overlay 428-443, notice 469-490, reduced-motion 581-590), includes/class-nicepay-offer-resolver.php'nin goods_class tip akışı. Çalıştırılan doğrulamalar: `npm test` (7/7 geçiyor), `npx eslint` (temiz), recommended kural setiyle karşılaştırmalı ESLint çalıştırması (tek yeni bulgu: nicepay.js:72 no-console), `grep` ile innerHTML/.html()/insertAdjacentHTML/document.write/eval taraması (sıfır eşleşme), `copyFailed` çevirisinin .pot/.po içindeki varlığının doğrulanması. İNCELENEMEYENLER: (1) `https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js` üçüncü taraf kütüphanesinin kaynağı — `nicepayStart()`/`goPay()`'in masaüstünde `window.open` mü yoksa iframe mi kullandığı, mobilde `document.payForm.submit()` ile mi yönlendirdiği ve pencere kapandığında `nicepayClose()`'u gerçekten çağırıp çağırmadığı doğrulanamadı; JS-001 ve JS-002'nin şiddet tahmini bu davranış varsayımına dayanıyor (bu yüzden JS-001 `medium` güven). (2) Gerçek tarayıcıda uçtan uca ödeme denemesi (test PG kimlik bilgileri ve HTTPS bir site yok) — popup engelleme ve mobil yönlendirme davranışı canlı olarak gözlenemedi. (3) WooCommerce Blocks checkout'un gerçek çalışma zamanı (Store API `process_payment` → `redirect` akışının Blocks tarafında yönlendirme yapıp yapmadığı) yalnızca kod okumasıyla değerlendirildi. (4) Ekran okuyucu ile gerçek duyuru testi yapılamadı; JS-007 bilinen ARIA canlı bölge semantiğine dayanıyor.

**Açık sorular**

- `nicepay-pgweb.js` masaüstünde ödeme penceresini `window.open` ile mi açıyor? Eğer iframe/overlay kullanıyorsa JS-001'in şiddeti düşer; `window.open` ise standalone akışı Safari'de tamamen kırık demektir. NICEPAY entegrasyon dokümanı veya bir test MID ile canlı doğrulama gerekiyor.
- PG kütüphanesi kullanıcı ödeme penceresini kapattığında `window.nicepayClose()`'u her durumda çağırıyor mu (özellikle mobil yönlendirme modunda ve pencere OS düzeyinde kapatıldığında)? Çağırmıyorsa JS-002'deki kalıcı kilit sanılandan çok daha sık yaşanır.
- Aynı sayfada birden fazla `[nicepay_payment]` kısayolu desteklenen bir senaryo mu, yoksa fiilen tek instance mı hedefleniyor? Destekleniyorsa JS-014 (kimlik çakışması) ve JS-008 (küresel buton kilidi) öncelik kazanır; hedeflenmiyorsa şablon bunu açıkça reddetmeli (ikinci kısayol için uyarı basmalı).
- Blocks checkout'ta `process_payment()`'ın döndürdüğü `redirect` (order-pay sayfası) Store API üzerinden gerçekten uygulanıyor mu? Bir Blocks smoke testi (tests/integration/woocommerce-smoke.php) bunu PHP tarafında doğruluyor gibi görünüyor ama tarayıcı tarafında yönlendirmenin gerçekleştiği hiçbir yerde kanıtlanmıyor.
- WooCommerce akışında PG penceresi kapatılınca `/checkout/`'a yönlendirme bilinçli bir ürün kararı mı, yoksa standalone dalıyla (sayfada kalma) tutarsızlık gözden mi kaçtı? (JS-003)

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 16 |

## Öncelikli aksiyon listesi

1. **JS-002** — 1) XHR'e timeout ekle: `request.timeout = 20000; request.ontimeout = function(){ releaseStandalone(form); showStandaloneError(wrapper, form, translated('connectionError', '...')); };` ve `request.onabort` için aynısını yap. 2) `nicepayStart()` sonrası bir watchdog kur: `var wd = window.setTimeout(function(){ releaseStandalone(form); showStandaloneError(wrapper, form, translated('paymentWindowLost'
