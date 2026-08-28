# 05 — WordPress / WooCommerce Platform Entegrasyonu

> PR #3 sistematik review · düşmanca doğrulamalı · 19 bulgu

## Özet

Platform entegrasyonunun temeli sağlam: sipariş verisi baştan sona WooCommerce CRUD ile (`$order->get_meta()` / `update_meta_data()` / `save()`) okunup yazılıyor, kod tabanında tek bir `get_post_meta`/`update_post_meta`/`shop_order` kullanımı yok; HPOS uyumu doğru hook'ta (`before_woocommerce_init`) ve `class_exists` koruması altında beyan ediliyor; gerçek WooCommerce üzerinde legacy/HPOS matrisi CI'da koşuyor; Settings API her seçenek için `sanitize_callback` ile kullanılıyor; admin varlıkları sayfa koşullu yükleniyor; aktivasyon/deaktivasyon cron temizliği yapıyor. Buna karşılık WC_Payment_Gateway sözleşmesinin birkaç idiomatik parçası eksik: `process_payment()` bildirimsiz "failure" dönüyor, `needs_setup()` uygulanmamış, `validate_fields()` yok ve alıcı alanı doğrulaması ancak sipariş oluştuktan sonra receipt sayfasında çalışıyor — bu, ödenemeyen "pending" sipariş üretiyor. Checkout Blocks tarafında kod tam bir ödeme yöntemi kaydediyor ama `cart_checkout_blocks` uyumu bilinçli olarak beyan edilmiyor; sonuç, çalışan bir entegrasyonun WooCommerce tarafından "uyumsuz/belirsiz eklenti" olarak işaretlenmesi. Çok siteli (multisite) sağlama yolu `switch_to_blog()` içinde `flush_rewrite_rules()` çağırarak yanlış sitenin rewrite kurallarını yazıyor. Ayrıca eklentinin hiç `do_action()` uzantı noktası ve tema template override'ı yok, üçüncü taraf NICEPAY betiği alakasız order-pay sayfalarında da yükleniyor, ve beyan edilen minimum sürümler (WP 5.8 / WC 5.0) hiçbir testte doğrulanmıyor.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **B** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 2 |
| 🟡 Orta | 10 |
| 🔵 Düşük | 7 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 0 |
| Bağımsız doğrulama kararı alan bulgu | 11 / 19 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 8 |

## Güçlü yönler

- Sipariş meta erişimi tamamen HPOS-uyumlu CRUD ile: `grep -rn "get_post_meta|update_post_meta|delete_post_meta|'shop_order'|WP_Query" includes admin templates nicepay-payment-gateway.php` hiçbir sonuç döndürmüyor.
- HPOS uyumu doğru hook ve doğru API ile beyan ediliyor (`nicepay-payment-gateway.php:41-50`, `before_woocommerce_init` + `FeaturesUtil::declare_compatibility('custom_order_tables', ...)`), `class_exists` koruması WC < 7.1'de fatal engelliyor.
- `tests/integration/woocommerce-smoke.php` gerçek WooCommerce ile hem legacy hem HPOS depolamada aynı sözleşmeyi koşuyor; `wc_create_refund(refund_payment:true)` ile Woo'nun gerçek iade yaşam döngüsü (WC_Order_Refund önce oluşur) test ediliyor — bu çoğu eklentide kaçırılan bir noktadır.
- Fresh-install fail-closed davranışı testle çivileniyor: `woocommerce-smoke.php:67-69` (`woocommerce_nicepay_settings` yok, `enabled === 'no'`, `is_available()` false).
- Settings API her seçenek için `sanitize_callback` tanımlıyor (`admin/class-nicepay-admin.php:100-157`) ve sanitizer'lar allowlist tabanlı (`sanitize_mode`, `sanitize_language`, `sanitize_enabled_methods`).
- Admin varlıkları sayfa-koşullu yükleniyor (`admin/class-nicepay-admin.php:49-52`), AJAX uçları `nopriv` olmayan yerlerde `current_user_can` + nonce ile korunuyor (`nicepay-payment-gateway.php:656-659`, `771-774`).
- Deaktivasyonda her iki cron event'i de temizleniyor ve rewrite kuralları flush ediliyor (`nicepay-payment-gateway.php:191-195`); aktivasyonda `register_endpoints()` flush'tan önce açıkça çağrılıyor (`:165`).
- Plain permalink (pretty permalink kapalı) senaryosu gerçekten ele alınmış: `nicepay_get_standalone_return_url()` (`includes/nicepay-functions.php:548-554`) permalink yapısı boşsa query-arg URL'ine düşüyor ve `nicepay_return` query var'ı kayıtlı.
- Retention kilidi `add_option(..., '', false)` ile atomik ve TTL'li (`includes/class-nicepay-retention.php:279-286`), autoload kapalı — Options API'nin doğru kullanımı.
- Menü yetkisi filtrelenebilir (`nicepay_manage_transactions_capability`) ve `manage_woocommerce` varsayılanı WooCommerce konvansiyonuna uygun.

## Bulgular

### PLAT-001 — process_payment() bildirimsiz 'failure' dönüyor: müşteri hiçbir hata görmeden checkout'ta takılıyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | wc-gateway-contract |
| **Konum** | [includes/class-nicepay-gateway.php:111](../../../includes/class-nicepay-gateway.php#L111) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````php
```php
111:        if ( ! $order || ! $this->is_available() || 'nicepay' !== $order->get_payment_method() ) {
112:            return array( 'result' => 'failure' );
113:        }
...
123:        if ( ! $order->needs_payment() ) {
124:            return array( 'result' => 'failure' );
125:        }
```
WooCommerce sözleşmesinde `process_payment()` başarısız olduğunda ya bir `Exception` fırlatmalı ya da `wc_add_notice( ..., 'error' )` ile bir bildirim bırakmalıdır. `WC_AJAX::checkout()`, `wc_get_notices('error')` boşsa istemciye boş bir `messages` listesi döner; blok tabanlı checkout'ta ise Store API `PaymentResult` başarısızlığını jenerik bir metne çevirir. Aynı dosyada diğer hata yollarında (`:299`) `wc_add_notice` doğru kullanılmış — yani sınıf içinde tutarsızlık var.
````

**Başarısızlık senaryosu**

Mağaza HTTPS'i geçici olarak kaybetmiş bir reverse-proxy arkasındadır (`is_available()` içindeki `is_ssl()` false döner) ama gateway checkout'ta hâlâ görünür durumdadır (WooCommerce gateway listesini sayfa yüklenirken önbelleğe almış olabilir). Müşteri NicePay'i seçip siparişi verir → `process_payment()` `array('result'=>'failure')` döner → checkout AJAX yanıtı `result: failure, messages: ''` olur → sayfada hiçbir şey değişmez, hiçbir hata görünmez, sipariş 'pending' olarak oluşmuş kalır.

**Etki**

Müşteri 'Siparişi Ver' düğmesine basar, spinner kaybolur ve sayfada hiçbir hata mesajı çıkmaz. Mağaza sahibi için görünmez bir dönüşüm kaybı; destek talebi üretir ve neyin yanlış gittiği loglanmaz.

**Öneri**

Her `failure` dönüşünden önce nedeni bildir; tercihen istisna fırlat:
```php
if ( ! $order || ! $this->is_available() || 'nicepay' !== $order->get_payment_method() ) {
    throw new Exception( esc_html__( 'NicePay is not available for this order right now. Please choose another payment method.', 'nicepay-payment-gateway' ) );
}
if ( ! $order->needs_payment() ) {
    throw new Exception( esc_html__( 'This order does not require a payment.', 'nicepay-payment-gateway' ) );
}
```
WooCommerce istisnayı yakalayıp `wc_add_notice()` ile hem klasik hem Blocks checkout'ta gösterir. Ek olarak her dalda `nicepay_log()` çağrısı ekleyin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `low`
- Düzeltilmiş iddia: process_payment()'in iki savunma dalı (includes/class-nicepay-gateway.php:111-113 ve :123-125) çıplak `array('result'=>'failure')` döndürüyor; hiçbir nicepay_log() kaydı, sipariş notu veya spesifik wc_add_notice() üretmiyor. Bu dallar WooCommerce'in kendi upstream kontrolleriyle (get_available_payment_gateways() → is_available() filtresi, WC_Checkout::validate_checkout()'un "Invalid payment method." hatası, Store API'nin woocommerce_rest_invalid_payment_method exception'ı, WC_Form_Handler::pay_action()'ın needs_payment() kontrolü) zaten korunduğu için pratikte ulaşılamazdır ve ulaşılsa bile WooCommerce'in checkout.js'i result.messages boşken wc_checkout_params.i18n_checkout_error jenerik hatasını gösterir — müşteri "sessiz kilitlenme" yaşamaz. Gerçek kusur ikili: (1) OBSERVABILITY — beklenmedik bir savunma dalına girildiğinde ödeme eklentisi tek satır log bile düşürmüyor, dolayısıyla ulaşılabilir hâle gelirse teşhis edilemez; (2) TUTARLILIK — aynı dosyadaki diğer 7 hata yolu (:299, :429, :478, :506, :539, :562, :685) wc_add_notice + nicepay_log kalıbını kullanıyor, bu iki dal kullanmıyor; ayrıca tests/ altında bu iki failure dalı için hiçbir assertion yok. Öneri geçerliliğini korur (istisna fırlat + nicepay_log ekle) ama gerekçesi "müşteri hiçbir hata görmüyor" değil, "sessiz, teşhis edilemez ve test edilmemiş savunma dalı"dır.
- Gerekçe: İDDİANIN DOĞRU OLAN ÇEKİRDEĞİ (kodu okudum, doğrulandı):

1. Satır numaraları birebir doğru. `includes/class-nicepay-gateway.php:111-113` ve `:123-125` gerçekten çıplak `array( 'result' => 'failure' )` döndürüyor — `wc_add_notice()` yok, `nicepay_log()` yok, `add_order_note()` yok.
2. Sınıf içi tutarsızlık iddiası da doğru: aynı dosyada `:299, :429, :478, :506, :539, :562, :685` satırlarının HEPSİ `wc_add_notice(...,'error')` kullanıyor. Yalnızca `process_payment()`'in iki erken-return dalı sessiz.
3. Telafi edici bir koruma ARADIM, YOK: `grep -rn "process_payment|woocommerce_checkout_process|woocommerce_after_checkout_validation|result.*failure" includes/ templates/ admin/ assets/` sonucu eklenti içinde `process_payment()`'in başka çağıranı ve bildirim ekleyen bir checkout validation hook'u bulunmuyor. Yani "bildirimsiz failure" tespiti bir kod gerçeği.

İDDİANIN ÇÜRÜTÜLEN KISIMLARI — `failure_scenario` ve `etki` yanlış, dolayısıyla `high` severity şişirilmiş:

A) Sunulan senaryo (`is_ssl()` false → müşteri NicePay seçip sipariş verir → `process_payment()` çağrılır) ULAŞILAMAZ. WooCommerce `process_payment()`'i çağırmadan ÖNCE gateway'i `WC()->payment_gateways->get_available_payment_gateways()` üzerinden filtreler; bu metot her gateway'in `is_available()`'ını çağırır. `WC_Checkout::validate_checkout()` bu listede olmayan yöntem için kendi `Invalid payment method.` hatasını üretir ve `process_order_payment()` daha en başında `if ( ! isset( $available_gateways[ $payment_method ] ) ) return;` ile çıkar. Store API tarafında da `get_request_payment_method()` aynı listeye bakıp `woocommerce_rest_invalid_payment_method` RouteException'ı fırlatır. Yani `is_available()` false iken müşteri GÖRÜNÜR bir hata alır ve `:111` dalına hiç girilmez. İddiadaki "WooCommerce gateway listesini sayfa yüklenirken önbelleğe almış olabilir" varsayımı da yanlış: liste request-başına hesaplanır, requestler arası cache'lenmez.

B) "Sayfada hiçbir şey değişmez, hiçbir hata görünmez" iddiası da yanlış. WooCommerce'in kendi `checkout.js`'i `result.messages` boş geldiğinde `wc_checkout_params.i18n_checkout_error` ile jenerik hata bloğunu basar ("There was an error processing your order..."). Yani sonuç "sessiz takılma" değil, "jenerik mesaj". Bu hâlâ ideal değil ama iddia edilen "invisible conversion loss" tablosu değil.

C) Eklentinin kendisi de bu dalları ayrıca önlüyor: `includes/class-nicepay-blocks-integration.php:34,70` → `is_available()` sonucunu `is_available` olarak JS'e geçiriyor ve `assets/js/nicepay-blocks.js:43` `canMakePayment: function () { return settings.is_available === true; }` ile yöntemi listeden düşürüyor.

D) `'nicepay' !== $order->get_payment_method()` ve `! $order->needs_payment()` dalları da upstream'de zaten korunuyor: WC_Checkout siparişi oluştururken payment_method'u set eder; `process_order_payment()` yalnızca `WC()->cart->needs_payment()` iken çağrılır; order-pay sayfasında `WC_Form_Handler::pay_action()` de `needs_payment()` kontrolünü kendisi yapar.

SONUÇ: Bu üç guard defense-in-depth (savunma derinliği) kontrolüdür, normal akışta ulaşılamaz. Geriye kalan gerçek kusur "müşteri kilitlenir" değil, (i) beklenmedik bir savunma dalına girildiğinde HİÇBİR log kaydı düşmemesi — bir ödeme eklentisi için gerçek bir gözlemlenebilirlik boşluğu — ve (ii) sınıf içi stil tutarsızlığı. Bu low seviyesinde bir kalite/observability bulgusudur; `high` bir WC-contract ihlali değildir.

NOT: WooCommerce çekirdeği bu repoda vendor'lanmamış (`ls vendor` → yalnızca phpunit/doctrine/nikic vb.; `find / -name woocommerce -type d` → sonuç yok), bu yüzden yukarıdaki WC davranışlarını repodan alıntılayamıyorum; bunlar WooCommerce çekirdek davranışına dair bilgiye dayanıyor. Kod tarafındaki tüm iddialar ise doğrudan dosyadan doğrulandı.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: process_payment() üç hata dalında da (`:111-113` ve `:123-125`) ne bildirim ne de log bırakıyor; sınıfın geri kalanı (`:299`, `:429`, `:478`, `:506`, `:539`, `:562`, `:685`) tutarlı biçimde `wc_add_notice()` kullandığı için bu bir iç tutarsızlık ve PR bu sessiz dalların sayısını 1'den 3'e çıkararak durumu kötüleştirdi. ANCAK: (1) `is_available()` dalı klasik checkout ve Store API'de pratikte ulaşılamaz — WooCommerce `get_available_payment_gateways()`'i her istekte yeniden değerlendirir ve `process_payment()`'ı yalnızca o dizide bulunan gateway için çağırır; SSL kaybı senaryosunda müşteri WooCommerce'in kendi "Invalid payment method." hatasını görür. (2) Müşteri hiçbir mesaj görmez iddiası da yanlış: `send_ajax_failure_response()` boş `messages` dönse bile checkout.js `wc_checkout_params.i18n_checkout_error` ("Error processing checkout. Please try again.") fallback'ini basar; Blocks da jenerik metin gösterir. Gerçek etki "sessiz/görünmez kayıp" değil, "teşhis edilemez jenerik hata + sunucuda sıfır iz". Gerçekten ulaşılabilir tek dal `:123` `! $order->needs_payment()` yarış durumudur (sipariş ikinci sekmeden/webhook'tan ödenmişken, ya da `recover_paid_order_from_ledger()` `:174-185` tutar/para-birimi uyuşmazlığında sessizce false dönerken); burada ledger 'paid' iken hiçbir order note/`nicepay_log` yazılmaz ve müşteri tekrar denerse WooCommerce yeni sipariş açacağı için mükerrer ödeme riski doğar. Öneri geçerli: her failure dalında `nicepay_log()` + spesifik neden, tercihen `throw new Exception(...)` (WooCommerce hem klasik hem Blocks'ta yakalayıp gösterir); `:123` dalında ayrıca müşteriyi `$this->get_return_url( $order )`'a yönlendirip mükerrer siparişi önlemek daha doğru olur.
- Gerekçe: KOD SEVİYESİ DOĞRU, SONUÇ/SENARYO SEVİYESİ YANLIŞ.

Doğrulanan kısım (okudum, satırlar eşleşiyor):
- `includes/class-nicepay-gateway.php:111-113` ve `:123-125` gerçekten çıplak `array( 'result' => 'failure' )` dönüyor; `wc_add_notice()` yok, `throw` yok ve fonksiyonun tamamında (105-131) TEK BİR `nicepay_log()` çağrısı yok (awk ile saydım: 0).
- Sınıf içi tutarsızlık iddiası da doğru: aynı dosyada `:299`, `:429`, `:478`, `:506`, `:539`, `:562`, `:685` hep `wc_add_notice(..., 'error')` kullanıyor. Yani `process_payment()` sınıfın kendi kalıbının dışında kalmış.
- PR bunu kötüleştirmiş: `git show origin/main:...` çıktısında eski `process_payment()` sadece `! $order` dalına sahipti (main:94-96); PR üç yeni sessiz-failure koşulu ekledi (`is_available()`, `payment_method` uyuşmazlığı, `needs_payment()`), yani sessiz dal sayısı 1 -> 3 oldu.

ÇÜRÜTÜLEN 1 — failure_scenario üretilebilir değil (SSL/önbellek hikâyesi yanlış):
İddia "WooCommerce gateway listesini sayfa yüklenirken önbelleğe almış olabilir" diyor. `WC_Payment_Gateways::get_available_payment_gateways()` istek başına çalışır ve her gateway için `is_available()`'ı yeniden değerlendirir; sonucu istekler arası önbelleğe almaz. Klasik checkout'ta `WC_Checkout::validate_checkout()` seçilen yöntemi bu taze diziye karşı doğrular ve yoksa WooCommerce'in KENDİ "Invalid payment method." bildirimini ekler; `WC_Checkout::process_order_payment()` de aynı diziden `isset()` kontrolü yapıp yoksa `process_payment()`'ı hiç çağırmadan `return` eder. Blocks/Store API tarafında da legacy handler gateway'i yine `get_available_payment_gateways()` içinden alır — ve bu repoda Blocks adaptörü `includes/class-nicepay-blocks-integration.php:33-35` içinde `is_active()`'i doğrudan `$this->gateway->is_available()`'a bağlamış. Yani POST anında `is_ssl()` false ise akış `process_payment()`'a HİÇ ULAŞMAZ. `:111`'deki `is_available()` yan koşulu pratikte savunma-derinliği; ancak üçüncü parti bir eklenti `woocommerce_available_payment_gateways` filtresiyle gateway'i geri eklerse tetiklenebilir (dar, gerçekçi olmayan önkoşul).

ÇÜRÜTÜLEN 2 — "hiçbir hata mesajı çıkmaz" etkisi abartılı:
`WC_Checkout::process_checkout()` başarısız ödemede `send_ajax_failure_response()` ile biter; `messages` boş dönse bile WooCommerce'in `checkout.js` dosyası `catch` bloğunda `if ( result.messages ) {...} else { submit_error('<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>') }` fallback'ini uygular — yani müşteri "Error processing checkout. Please try again." jenerik hatasını GÖRÜR. Blocks tarafında da iddianın kendi kabul ettiği gibi jenerik metin gösterilir. Dolayısıyla "spinner kaybolur, sayfada hiçbir şey değişmez, görünmez dönüşüm kaybı" tasviri doğru değil. Gerçek etki: eyleme dönüştürülemeyen jenerik hata + sunucu tarafında sıfır iz (log/order note yok).

GERÇEKTEN ULAŞILABİLİR OLAN DAL — `:123` `! $order->needs_payment()`:
Bu dal, klasik checkout'un normal akışında (sıfır tutarlı sepette WooCommerce zaten `process_payment` çağırmaz) yalnız yarış durumunda tetiklenir: müşteri "Siparişi Ver"e basarken aynı sipariş ikinci sekmeden/webhook'tan ödenirse, ya da `recover_paid_order_from_ledger()` `:174-185` tutar/para-birimi uyuşmazlığı yüzünden sessizce `false` dönüp sipariş yine de ödenmiş durumdaysa. Bu durumda ledger'da `paid` bir kayıt varken müşteri jenerik hata alır, hiçbir `nicepay_log()`/order note yazılmaz ve müşteri yeniden checkout'a girerse WooCommerce yeni bir sipariş oluşturur (mükerrer ödeme riski). Bulgunun asıl değeri burada — ama iddia bunu değil, ulaşılamaz SSL senaryosunu yazmış.

SEVERITY: high -> medium. Sessiz `failure` gerçek bir sözleşme/gözlemlenebilirlik kusuru ve düzeltme önerisi (throw + nicepay_log) doğru; ancak (a) yazılan senaryo üretilemez, (b) her iki checkout da jenerik de olsa bir hata gösterir, (c) tek gerçekten ulaşılabilir dal dar bir yarış koşulu. "Görünmez sessiz kayıp" değil, "teşhis edilemez jenerik hata + sıfır log".

---

### PLAT-002 — Alıcı alanı doğrulaması sipariş oluştuktan SONRA yapılıyor; validate_fields() yok — ödenemeyen sipariş üretiyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | wc-gateway-contract |
| **Konum** | [includes/class-nicepay-gateway.php:251](../../../includes/class-nicepay-gateway.php#L251) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

````php
```php
251:        $buyer = nicepay_validate_buyer_fields(
252:            (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name(),
253:            (string) $order->get_billing_email(),
254:            (string) $order->get_billing_phone()
255:        );
256:        if ( is_wp_error( $buyer ) ) {
257:            echo '<p>' . esc_html( $buyer->get_error_message() ) . '</p>';
258:            echo '<a href="' . esc_url( wc_get_checkout_url() ) . '">' . ... 'Return to Checkout' ...
```
Bu kontrol yalnızca `generate_payment_form()` içinde, yani sipariş zaten `wc_create_order` ile kalıcı hâle geldikten sonra çalışıyor. Limitler sert:
```php
// includes/nicepay-functions.php:1396-1402
'buyer_name'  => 30,   // BAYT
'buyer_tel'   => 20,
'buyer_email' => 60,
// includes/nicepay-functions.php:1429-1430 ($required = true)
if ( $required && '' === $value ) { return new WP_Error( 'nicepay_buyer_fields_required', ... ); }
// includes/nicepay-functions.php:1446
if ( '' !== $values['buyer_tel'] && ! preg_match( '/^[0-9+() -]{7,20}$/', $values['buyer_tel'] ) ) { ... }
```
`WC_Gateway_NicePay` sınıfında `validate_fields()` override'ı yok (`grep -n 'validate_fields' includes/class-nicepay-gateway.php` boş). Aynı sınıf `goods_name` için ise sert reddetme yerine `nicepay_utf8_byte_cut(..., 40)` ile kırpma yapıyor (`:238`) — tutarsız politika.
````

**Başarısızlık senaryosu**

WooCommerce'de telefon alanı varsayılan olarak zorunlu değildir (`woocommerce_checkout_phone_field` 'optional' yapılabilir, sanal/indirilebilir ürünlerde fatura adresi alanları tümüyle kapatılabilir). Telefonsuz bir müşteri siparişi verir → `process_payment()` 'success' döner → order-pay sayfasında `nicepay_validate_buyer_fields()` `nicepay_buyer_fields_required` döner → müşteri 'Buyer information is required.' + 'Return to Checkout' görür ve aynı döngüye tekrar girer. Aynı şekilde 30 baytı aşan bir isim ('Alexandra Konstantinopoulos-Meyer' = 33 bayt) da siparişi kalıcı olarak ödenemez yapar.

**Etki**

Sipariş oluşturulur, stok düşer, e-posta gider, ancak müşteri ödeme formuna hiç ulaşamaz. Mağazada kalıcı 'pending' sipariş çöplüğü ve tamamen engellenmiş bir satış oluşur. Hata mesajı ('Buyer information is required.') müşteriye hangi alanı düzeltmesi gerektiğini de söylemez.

**Öneri**

1) Klasik checkout için `validate_fields()` uygulayın:
```php
public function validate_fields() {
    $buyer = nicepay_validate_buyer_fields(
        trim( (string) ( $_POST['billing_first_name'] ?? '' ) . ' ' . (string) ( $_POST['billing_last_name'] ?? '' ) ),
        (string) ( $_POST['billing_email'] ?? '' ),
        (string) ( $_POST['billing_phone'] ?? '' )
    );
    if ( is_wp_error( $buyer ) ) { wc_add_notice( $buyer->get_error_message(), 'error' ); return false; }
    return true;
}
```
2) Blocks/Store API `validate_fields()` çağırmadığı için aynı kontrolü `process_payment()` başına da koyun ve PLAT-001'deki gibi istisna fırlatın.
3) Hata mesajlarını alan-bazlı yapın (`nicepay_buyer_fields_too_long` hangi alan?) ve `buyer_name` için de `goods_name` gibi bayt-kırpma uygulamayı değerlendirin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Alıcı alanı doğrulaması (`nicepay_validate_buyer_fields`) yalnızca `generate_payment_form()` içinde, yani sipariş kalıcılaştıktan SONRA çalışıyor (includes/class-nicepay-gateway.php:251). `WC_Gateway_NicePay` ne `validate_fields()` override'ı yapıyor (repo genelinde tanım yok) ne de `process_payment()` başında (:108-131) ya da herhangi bir checkout hook'unda eşdeğer bir kontrol içeriyor; Blocks/Store API yolu da (class-nicepay-blocks-integration.php:5-6) aynı doğrulamasız `process_payment()`'a düşüyor.

Sonuç: 30 baytı aşan bir isim (ör. "Alexandra Konstantinopoulos-Meyer" = 33 bayt, ya da 11 Hangul karakterlik bir Korece isim — tests/unit/NicePayFunctionsTest.php:356), boş/optional bırakılmış bir telefon, ya da `^[0-9+() -]{7,20}$` deseniyle uyuşmayan bir telefon, checkout'tan sorunsuz geçip `pending` sipariş yaratıyor; müşteri order-pay sayfasında alan adı içermeyen jenerik bir mesaj ("Buyer information is required." / "...exceeds the payment provider field limits.") görüyor ve o siparişi bir daha asla ödeyemiyor.

ETKİ DÜZELTMESİ (orijinal iddiada yanlış): Sipariş `pending` kaldığı için WooCommerce ne stoğu düşürür (`wc_maybe_reduce_stock_levels` processing/completed/on-hold'da çalışır; pending'de yalnızca geçici hold-stock rezervasyonu olur) ne de müşteri/admin sipariş e-postası gönderir. Gerçek etki: (a) o sipariş kalıcı olarak ödenemez, (b) müşteriye hangi alanı düzelteceği söylenmediği için — özellikle hesabına kayıtlı adresten otomatik dolan müşteride — her deneme yeni bir ölü `pending` sipariş üretir, (c) uzun isimli müşteriler için satış tamamen bloke olur. Stok/e-posta yan etkisi yoktur.

Ayrıca doğrulanan yan bulgular: (1) `is_available()` mağaza para birimini (:93-94), form ise sipariş para birimini (:213/:248) kontrol ediyor — çoklu para birimi eklentisi altında KRW olmayan sipariş :216-219'a düşüp aynı şekilde ödenemez kalır (düşük güven: 3. parti eklenti gerektirir); (2) :264 ve :364 hata dallarında checkout'a dönüş bağlantısı yok, oysa :258/:301/:325'te var; (3) `goods_name` :238'de bayt-kırpma ile kurtarılırken buyer alanları sert reddediliyor — tutarsız politika; (4) Doğru desen kod tabanında zaten mevcut: standalone akış aynı fonksiyonu kayıttan ÖNCE çağırıp 400 dönüyor (nicepay-payment-gateway.php:573-577 vs :589).
- Gerekçe: Çürütmeye çalıştım, çürütemedim. Çekirdek iddia ve TÜM satır referansları birebir doğru:

1) `validate_fields()` override'ı gerçekten YOK. `grep -rn "validate_fields" --include="*.php" .` (vendor hariç) repo genelinde SIFIR sonuç döndü. `WC_Gateway_NicePay` bu WooCommerce sözleşme metodunu hiç uygulamıyor.

2) Alternatif bir koruma katmanı da YOK. Çürütücü olabilecek her yolu aradım ve hiçbiri yok:
   - `woocommerce_after_checkout_validation` / `woocommerce_checkout_process` hook'u yok (grep boş).
   - `payment_fields()` override'ı yok (grep boş) — yani eklenti checkout'ta kendi alanlarını da basmıyor.
   - `process_payment()` (:108-131) tam olarak okundu: `is_available()`, `get_payment_method()`, `needs_payment()` kontrolleri var ama alıcı alanı doğrulaması YOK. Doğrudan `'result' => 'success'` + order-pay redirect dönüyor.
   - `receipt_page()` (:136-163) sadece order key ve `needs_payment()` kontrol ediyor, sonra `generate_payment_form()` çağırıyor.
   - Blocks entegrasyonu (`includes/class-nicepay-blocks-integration.php`) sadece etiket/asset kaydı yapıyor; dosyanın kendi başlık yorumu (:5-6) "The Store API continues to call WC_Gateway_NicePay::process_payment()" diyor — yani Blocks yolunda da hiçbir doğrulama yok. İddianın 2. maddesi doğru.

3) `nicepay_validate_buyer_fields()` tek çağrı yeri WC yolunda `class-nicepay-gateway.php:251` (sipariş `wc_create_order` ile zaten kalıcı olduktan sonra). Sert limitler ve `$required = true` varsayılanı doğrulandı.

4) İddiayı GÜÇLENDİREN ek kanıt buldum: aynı fonksiyon standalone akışta `nicepay-payment-gateway.php:573`'te, `nicepay_save_transaction()`'dan (:589) ÖNCE çağrılıyor ve `wp_send_json_error(..., 400)` ile temiz reddediliyor. Yani doğru desen kod tabanında zaten mevcut — sadece WooCommerce akışında uygulanmamış. Bu, "tutarsız politika" iddiasını pekiştiriyor.

5) Yan iddiaların hepsi de doğrulandı:
   - Para birimi asimetrisi: `is_available()` :93-94 `get_woocommerce_currency()` (mağaza), form :213/:248 `$order->get_currency()` (sipariş). `nicepay_is_supported_currency()` :1332-1334 sadece KRW; `nicepay_normalize_amount()` :1490-1492 desteklenmeyen para biriminde `false` → `nicepay_get_amount()` :1662 `''` → :216-219 dalı. Çoklu para birimi senaryosu kod olarak geçerli (senaryonun kendisi 3. parti eklenti gerektirdiği için düşük güven).
   - :262-266 ve :364-366 dallarında "Return to Checkout" bağlantısı gerçekten yok; :258, :301, :325 dallarında var. Tutarsızlık doğrulandı.
   - :238 `nicepay_utf8_byte_cut(..., 40)` ile goods_name kırpılıyor, buyer alanları ise :1432-1433'te sert reddediliyor. Tutarsızlık doğrulandı.
   - Hata mesajları alan-bazlı değil (:1430 "Buyer information is required.", :1433 "Buyer information exceeds the payment provider field limits.") — hangi alan olduğu söylenmiyor. Doğrulandı.
   - 33 baytlık isim hesabı doğru ("Alexandra Konstantinopoulos-Meyer" = 33 > 30). Ayrıca `tests/unit/NicePayFunctionsTest.php:356` `str_repeat('한', 11)` = 33 baytın reddedildiğini test ediyor — yani 10 Hangul karakterden uzun Korece isimler de reddediliyor. Hedef pazar KRW/Kore olduğu için bu sıradan bir senaryo.

NEDEN "partially-confirmed": Sadece ETKİ paragrafındaki iki somut iddia WooCommerce davranışıyla uyuşmuyor:
   - "stok düşer" — YANLIŞ. `process_payment()` (:127-130) siparişi `pending` bırakıyor; WooCommerce stoğu `wc_maybe_reduce_stock_levels` ile processing/completed/on-hold geçişinde düşürür, pending'de değil. Yalnızca geçici stok *rezervasyonu* (hold stock) olur.
   - "e-posta gider" — YANLIŞ. Pending siparişler için WooCommerce ne müşteri ne de "New Order" admin e-postası gönderir.
   - "aynı döngüye tekrar girer" ifadesi de kısmen abartılı: klasik checkout'ta sipariş oluşurken sepet boşalmadığı için müşteri checkout'a dönüp ismini kısaltabilir. Ancak hesabına kayıtlı adresten otomatik doldurulan bir müşteri için pratikte gerçekten döngü oluşur ve mesaj hangi alanı düzelteceğini söylemediği için müşterinin bunu keşfetme şansı düşük. Her yeni deneme yeni bir kalıcı `pending` sipariş üretir; ilk sipariş asla ödenemez kalır.

Severity `high` korunmalı: doğrulama boşluğu ve tamamen engellenen satış gerçek; sadece kirlilik/yan etki tarifi düzeltilmeli.

---

### PLAT-003 — Checkout Blocks ödeme yöntemi tam olarak kaydediliyor ama cart_checkout_blocks uyumu bilinçli olarak beyan edilmiyor — WooCommerce eklentiyi 'uyumsuz' işaretliyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | blocks-compatibility |
| **Konum** | [nicepay-payment-gateway.php:41](../../../nicepay-payment-gateway.php#L41) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````php
Bir yandan uyum beyanı kasten sınırlanıyor:
```php
36: /**
37: * Declare only the WooCommerce feature that has passed the real storage
38: * matrix. Checkout Blocks compatibility remains intentionally undeclared
39: * until the browser checkout matrix passes.
40: */
41: function nicepay_declare_woocommerce_compatibility() {
43:         \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
44:             'custom_order_tables', NICEPAY_PLUGIN_FILE, true
```
Öte yandan tam bir Blocks ödeme yöntemi gerçekten kaydediliyor:
```php
312:    public function init_woocommerce_blocks() {
318:        add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_woocommerce_blocks_payment_method' ) );
326:            $registry->register( new NicePay_Blocks_Integration() );
```
Ve test bu çelişkiyi kalıcılaştırıyor:
```php
// tests/integration/woocommerce-smoke.php:53-56
nicepay_wc_it_assert(
    ! in_array( 'cart_checkout_blocks', $compatible_features['compatible'], true ),
    'Checkout Blocks compatibility was declared without the browser matrix.'
);
```
`readme.txt:23` ve `:69` de aynı ifadeyi tekrar ediyor.
````

**Başarısızlık senaryosu**

Mağaza sahibi WooCommerce 9.x ile blok tabanlı Checkout sayfası kurar ve NicePay'i etkinleştirir. Checkout bloğunu düzenlemeye açtığında yan panelde 'NicePay Payment Gateway — uyumluluk beyan edilmedi' uyarısı görür. NicePay ödeme yöntemi aslında blokta doğru çalışıyor olmasına rağmen (adaptör kayıtlı, `canMakePayment` true, `process_payment` order-pay yönlendirmesi döndürüyor), mağaza sahibi uyarıya güvenip eklentiyi devre dışı bırakır ya da klasik checkout'a döner.

**Etki**

WooCommerce'in `FeaturesController`'ı beyan etmeyen eklentileri 'uncertain' kovasına koyar. Blok tabanlı Checkout kullanan mağazalarda: (a) WooCommerce > Ayarlar > Gelişmiş > Özellikler ekranında NicePay uyumsuz/belirsiz listesinde görünür, (b) Checkout bloğu düzenleyicisinde 'bu eklenti uyumluluğunu beyan etmedi' uyarı paneli çıkar, (c) WooCommerce mağaza sahibine kısayol checkout'a geri dönmeyi önerebilir. Yani çalışan bir entegrasyon, ürünün kendi beyanı yüzünden bozuk gibi görünür — birinci sınıf ürün deneyimiyle çelişir.

**Öneri**

İki tutarlı seçenekten birini seçin: (1) Blocks adaptörünü sevk ediyorsanız uyumu da beyan edin — `FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NICEPAY_PLUGIN_FILE, true )` — ve tarayıcı matrisini bir sonraki sürüme kadar 'known limitations' olarak dokümante edin; ya da (2) matris geçene kadar adaptörü de sevk etmeyin (kaydı bir `nicepay_enable_blocks_checkout` filtresinin veya bir ayar kutusunun arkasına alın) ve `false` ile açıkça uyumsuzluk beyan edin, böylece WooCommerce mağaza sahibini kesin bir bilgiyle yönlendirsin. Ne olursa olsun `readme.txt:69` ve `admin/class-nicepay-admin.php:460` metinlerini seçilen davranışla eşitleyin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddiadaki her kod alıntısını ve satır numarasını dosyaların NİHAİ halinde doğruladım; hepsi birebir eşleşiyor.

1) `nicepay-payment-gateway.php:36-50` — `nicepay_declare_woocommerce_compatibility()` yalnızca `custom_order_tables` için `declare_compatibility(..., true)` çağırıyor. `cart_checkout_blocks` için HİÇBİR yerde `declare_compatibility` çağrısı yok — tüm repoda `declare_compatibility` yalnızca bu tek noktada geçiyor (grep ile doğrulandı: `nicepay-payment-gateway.php` içindeki "blocks" eşleşmeleri 38, 96, 309-326; hiçbiri beyan değil). Yani iddiayı çürütecek bir "başka yerde beyan ediliyor" koruması YOK.

2) Buna karşın tam işlevsel bir Blocks ödeme yöntemi gerçekten sevk ediliyor ve kaydediliyor: `nicepay-payment-gateway.php:96` `woocommerce_blocks_loaded` kancası, `:312-319` `init_woocommerce_blocks()`, `:324-328` `register_woocommerce_blocks_payment_method()` → `$registry->register( new NicePay_Blocks_Integration() )`. Adaptör sınıfı `includes/class-nicepay-blocks-integration.php:15-76` `AbstractPaymentMethodType`'ı genişletiyor; `is_active()`, script handle kaydı ve `get_payment_method_data()` (title/description/is_available/place_order_label) eksiksiz. Yani "çalışan entegrasyon ama beyansız" çelişkisi gerçek.

3) Test bu durumu kalıcılaştırıyor: `tests/integration/woocommerce-smoke.php:53-56` `! in_array( 'cart_checkout_blocks', $compatible_features['compatible'], true )` assert'i, biri uyumu beyan etmeye kalkarsa CI'ı KIRAR. Yani bu bilinçli ve kilitlenmiş bir karar.

4) `readme.txt:23` ve `:69` aynı ifadeyi tekrar ediyor ("A Checkout Blocks adapter is included, but Blocks compatibility is not declared...").

Etki değerlendirmesi de doğru: WooCommerce `FeaturesController::get_compatible_features_for_plugin()` beyan etmeyen eklentileri `uncertain` kovasına koyar (testin kendisi tam da bu API'yi çağırıyor, satır 46-48), ve Cart/Checkout bloğu editörü uyumluluk beyan etmemiş eklentiler için yan panelde uyarı gösterir. Severity `medium` uygun: fonksiyonel bir bug değil, ürün algısı/tutarlılık sorunu.

EK BULGU (iddiayı güçlendiriyor, iddiada yok): `admin/class-nicepay-admin.php:460` metni "Vendor sandbox certification, Blocks checkout **and HPOS** require their separate integration matrix before compatibility is declared" diyor — oysa HPOS uyumu `nicepay-payment-gateway.php:43-47`'de zaten `true` olarak beyan edilmiş ve `woocommerce-smoke.php:50-53` bunu assert ediyor. Yani admin metni sadece Blocks konusunda değil, HPOS konusunda da kodla çelişiyor; iddianın "metinleri seçilen davranışla eşitleyin" önerisi bu satır için iki kat geçerli.

Tek küçük kusur (verdict'i değiştirmiyor): kanıt bloğundaki 312/318/326 satırları `includes/class-nicepay-blocks-integration.php` değil `nicepay-payment-gateway.php` dosyasına ait; iddia bunları dosya adı vermeden alıntılamış. Adaptör sınıfının kendisi için verilen `:15-35` aralığı doğru.

---

### PLAT-004 — Blocks adaptörü gateway başlık/açıklamasını get_title()/get_description() yerine ham özellikten okuyor — WooCommerce filtreleri ve çeviri katmanları Blocks checkout'ta uygulanmıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | blocks-compatibility |
| **Konum** | [includes/class-nicepay-blocks-integration.php:59](../../../includes/class-nicepay-blocks-integration.php#L59) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````php
```php
58:    public function get_payment_method_data() {
59:        $title = $this->gateway instanceof WC_Gateway_NicePay
60:            ? $this->gateway->title
61:            : __( 'NicePay Payment', 'nicepay-payment-gateway' );
62:        $description = $this->gateway instanceof WC_Gateway_NicePay
63:            ? $this->gateway->description
64:            : __( 'Pay securely via NicePay.', 'nicepay-payment-gateway' );
```
`WC_Payment_Gateway::get_title()` `apply_filters( 'woocommerce_gateway_title', $this->title, $this->id )`, `get_description()` ise `woocommerce_gateway_description` filtresini uygular. Klasik checkout WooCommerce çekirdeği üzerinden `get_title()` çağırdığı için filtreleri alır; bu adaptör ham `->title` özelliğini okuduğu için almaz.
````

**Başarısızlık senaryosu**

Türkçe/Korece çift dilli bir mağaza WPML String Translation ile `woocommerce_gateway_title` üzerinden NicePay başlığını Korece'ye çevirir. Klasik checkout'ta '나이스페이 결제' görünür; Blocks checkout'ta ise WooCommerce ayarında kayıtlı ham İngilizce 'NicePay Payment' görünür. Kullanıcı için iki farklı ödeme yöntemi varmış izlenimi doğar.

**Etki**

Klasik checkout ile Blocks checkout aynı mağazada farklı ödeme yöntemi etiketi/açıklaması gösterir. WPML/Polylang string translation, ücret/uyarı ekleyen eklentiler ve mağaza sahibinin kendi `woocommerce_gateway_title` snippet'i Blocks checkout'ta sessizce etkisiz kalır.

**Öneri**

```php
$title       = $this->gateway instanceof WC_Gateway_NicePay ? $this->gateway->get_title()       : __( 'NicePay Payment', 'nicepay-payment-gateway' );
$description = $this->gateway instanceof WC_Gateway_NicePay ? $this->gateway->get_description() : __( 'Pay securely via NicePay.', 'nicepay-payment-gateway' );
```
Ayrıca `initialize()` içinde `$this->settings = get_option( 'woocommerce_nicepay_settings', array() );` atayın — `AbstractPaymentMethodType::get_setting()` sözleşmesi bunu bekler ve şu anda boş kalıyor (`includes/class-nicepay-blocks-integration.php:23-31`). `get_payment_method_data()['supports']` de gateway'in gerçek `$this->supports` dizisinden türetilmelidir; şu anda sabit `array('products')` (`:69`) iken gateway `array('products','refunds')` (`includes/class-nicepay-gateway.php:22`) bildiriyor.

---

### PLAT-005 — switch_to_blog() içinde flush_rewrite_rules(): multisite network aktivasyonu alt sitelere yanlış rewrite kurallarını yazıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | multisite |
| **Konum** | [nicepay-payment-gateway.php:170](../../../nicepay-payment-gateway.php#L170) |
| **Güven** | medium |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````php
```php
130:    public function activate( $network_wide = false ) {
131:        if ( is_multisite() && $network_wide ) {
132:            $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
133:            foreach ( $site_ids as $site_id ) {
134:                switch_to_blog( $site_id );
135:                try {
136:                    $result = $this->activate_current_site();
...
157:    private function activate_current_site() {
...
165:        $this->register_endpoints();
...
170:        flush_rewrite_rules();
```
Aynı desen yeni site sağlamada da var:
```php
228:        switch_to_blog( (int) $new_site->blog_id );
229:        try {
230:            $result = $this->activate_current_site();
```
`flush_rewrite_rules()` global `$wp_rewrite` nesnesini kullanır. `switch_to_blog()` `$wp_rewrite`'ı yeniden başlatmaz; nesne hâlâ isteği başlatan sitenin `permalink_structure`'ı ve kural setiyle doludur. Sonuç: `update_option('rewrite_rules', ...)` çağrısı, hedef sitenin veritabanına kaynak sitenin kurallarını yazar. Bu, WordPress'te bilinen bir `switch_to_blog()` kısıtıdır.
````

**Başarısızlık senaryosu**

Ana site `/%postname%/`, alt site B `/%year%/%monthnum%/%postname%/` permalink yapısını kullanır. Yönetici eklentiyi ağ genelinde etkinleştirir → döngü B'ye geçer → `flush_rewrite_rules()` ana sitenin `%postname%` kurallarını B'nin `rewrite_rules` seçeneğine yazar → B'deki tüm tarih-tabanlı yazı URL'leri 404 döner.

**Etki**

Ağdaki alt sitelerin `rewrite_rules` seçeneği, ana sitenin permalink yapısına göre üretilmiş kurallarla ezilir. Permalink yapısı farklı olan alt sitelerde yazı/sayfa/ürün URL'leri 404 vermeye başlar — yalnızca NicePay uçları değil, sitenin tamamı etkilenir. Kurtarma için her alt sitede Ayarlar > Kalıcı Bağlantılar sayfasını kaydetmek gerekir.

**Öneri**

Anahtarlanmış blogda asla flush etmeyin; bunun yerine kuralları geçersiz kılıp ilgili sitenin kendi isteğinde yeniden üretilmesini sağlayın:
```php
private function activate_current_site() {
    ...
    $this->register_endpoints();
    // switch_to_blog() sırasında $wp_rewrite kaynak sitenin durumunu taşır;
    // kuralları sadece geçersiz kıl, hedef site kendi isteğinde yeniden üretsin.
    delete_option( 'rewrite_rules' );
    ...
}
```
ve `flush_rewrite_rules()` çağrısını yalnızca anahtarlama yapılmayan tek-site aktivasyon yolunda bırakın. Aynı düzeltme `install_new_site()` (`:223-238`) için de gereklidir.

---

### PLAT-006 — Network aktivasyon döngüsünde wp_die(): eklenti etkin olmadan yarı sağlanmış siteler bırakıyor; get_sites() sınırsız

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | activation |
| **Konum** | [nicepay-payment-gateway.php:132](../../../nicepay-payment-gateway.php#L132) |
| **Güven** | medium |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

````php
```php
131:        if ( is_multisite() && $network_wide ) {
132:            $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
133:            foreach ( $site_ids as $site_id ) {
134:                switch_to_blog( $site_id );
...
140:                if ( is_wp_error( $result ) ) {
141:                    wp_die( esc_html( $result->get_error_message() ) );
142:                    return;   // ulaşılamaz
143:                }
144:            }
```
WordPress `activate_plugin()` içinde `active_sitewide_plugins` seçeneği `do_action("activate_{$plugin}")` çağrısından SONRA güncellenir. Döngünün ortasındaki `wp_die()` isteği sonlandırır, dolayısıyla eklenti hiç etkinleşmez — ama döngüde daha önce işlenen siteler `NicePay_Installer::maybe_install()` ile tabloları oluşturmuş, `set_default_options()` ile 14 seçenek yazmış ve `wp_schedule_event()` ile cron kaydetmiş olur. `number => 0` ile tüm siteler tek istekte belleğe alınır ve her biri için `dbDelta` + `flush_rewrite_rules()` koşar.
````

**Başarısızlık senaryosu**

300 siteli bir ağda 120. sitenin `nicepay_transactions` tablosunda mükerrer Moid vardır; `maybe_install()` migrasyonu güvenle bloklar ve WP_Error döner. `wp_die()` çağrılır. 1–119 numaralı sitelerde tablolar, seçenekler ve saatlik `nicepay_expire_pending_transactions` cron event'i kalır, ama `active_sitewide_plugins` güncellenmediği için eklenti etkin değildir — cron hook'unun callback'i kayıtlı olmadığından WP her saat boş bir event tetiklemeye çalışır.

**Etki**

Yönetici ham bir `wp_die()` ekranı görür (aktivasyon sırasında textdomain yüklenmediği için mesaj çevrilmez de — `load_textdomain` `plugins_loaded`'a bağlı, `:93`, ve o hook aktivasyon isteğinde çoktan geçmiştir). Ağın bir kısmında yetim tablolar, seçenekler ve etkin olmayan bir eklentiye ait cron event'leri kalır. Büyük ağlarda (yüzlerce site) döngü PHP `max_execution_time`'a takılıp aynı yarı-durumu üretir.

**Öneri**

Döngüyü kırmadan tamamlayın, hataları toplayın ve sonucu bir transient/site-option üzerinden admin notice ile raporlayın; siteleri toplu (batch) işleyin:
```php
$failures = array();
foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0, 'no_found_rows' => true ) ) as $site_id ) {
    switch_to_blog( $site_id );
    try { $result = $this->activate_current_site(); } finally { restore_current_blog(); }
    if ( is_wp_error( $result ) ) { $failures[ $site_id ] = $result->get_error_code(); }
}
if ( $failures ) { update_site_option( 'nicepay_network_activation_failures', $failures ); }
```
ve `schema_error_notice()` benzeri bir ağ bildirimiyle hangi sitelerin sağlanamadığını listeleyin. `:142`'deki ulaşılamaz `return;` da kaldırılmalı.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Çekirdek iddia doğrulandı: `nicepay-payment-gateway.php:130-146` içindeki network-wide aktivasyon döngüsü, herhangi bir sitede `activate_current_site()` WP_Error döndüğünde `wp_die()` ile isteği sonlandırıyor (`:141`), döngüyü kırmıyor/geri almıyor; `:142`'deki `return;` ulaşılamaz ölü kod. `get_sites( array( 'fields' => 'ids', 'number' => 0 ) )` (`:132`) gerçekten tüm siteleri tek istekte çekiyor ve her site için `maybe_install()` (dbDelta) + `set_default_options()` + `register_endpoints()` + `wp_schedule_event()` + `NicePay_Retention::sync_schedule()` + `flush_rewrite_rules()` koşuyor (`:157-172`). WP core `activate_plugin()` içinde `active_sitewide_plugins` güncellemesi `do_action("activate_{$plugin}")` sonrasında yapıldığından, hata anında eklenti etkinleşmemiş ama önceki sitelerde tablo/opsiyon/cron kalmış olur. Düzeltilmesi gereken iki detay: (1) `set_default_options()` 14 değil 13 `add_option()` çağrısı içeriyor (`:271-285`); (2) "mesaj çevrilmez" alt-iddiası yanıltıcı — `class-nicepay-installer.php` içindeki WP_Error mesajları hiç `__()` ile sarılmamış sabit İngilizce metinler (`:117`, `:143`, `:155`), yani textdomain yüklü olsa bile çevrilmezdi; textdomain gecikmesi bu bulguda etkisiz bir yan gözlem. Ayrıca yetim cron listesi eksik: `nicepay_expire_pending_transactions` yanında `NicePay_Retention::CRON_HOOK` de `sync_schedule()` ile kaydedilebiliyor (`:169`).
- Gerekçe: Dosyayı satır numaralarıyla okudum; iddia edilen kod birebir belirtilen satırlarda mevcut ve iddiayı çürütecek bir koruma yok: döngüde try/finally sadece `restore_current_blog()` garantisi veriyor, hata biriktirme/rollback/batching yok, `wp_die()` sonrası kod ulaşılamaz. `register_activation_hook` (`:811`) `activate()`'i `$network_wide` ile çağırıyor, yani yol gerçekten erişilebilir. `maybe_install()` gerçekten WP_Error dönebiliyor (mükerrer Moid `:141`, mükerrer TID `:153`, wpdb eksikliği `:117`, scrub hatası `:165`) — yani başarısızlık senaryosu hayali değil. Sadece iki tali detay (opsiyon sayısı ve çeviri gerekçesi) yanlış olduğu için tam onay yerine kısmi onay veriyorum; severity medium doğru (yalnızca multisite network-wide aktivasyonda tetikleniyor, veri kaybı değil yarı-sağlama durumu üretiyor).

---

### PLAT-007 — Üçüncü taraf NICEPAY betiği ilgisiz order-pay sayfalarında ve devre dışı standalone shortcode sayfalarında da yükleniyor; is_payment_page() blok/widget/reusable içerikleri kaçırıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | asset-loading |
| **Konum** | [nicepay-payment-gateway.php:330](../../../nicepay-payment-gateway.php#L330) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````php
```php
330:    public function enqueue_scripts() {
331:        $load = $this->is_payment_page();
332:
333:        if ( ! $load && function_exists( 'is_checkout_pay_page' ) ) {
334:            $load = is_checkout_pay_page();
335:        }
336:
337:        if ( $load ) {
338:            $this->enqueue_payment_assets();
339:        }
340:    }
...
794:    private function is_payment_page() {
795:        global $post;
796:        if ( $post && has_shortcode( $post->post_content, 'nicepay_payment' ) ) {
797:            return true;
798:        }
799:        return false;
800:    }
```
`enqueue_payment_assets()` (`:477-525`) uzak `NICEPAY_JS_URL` betiğini (`https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js`) enqueue eder. `is_checkout_pay_page()` siparişin ödeme yöntemini kontrol etmez; `is_payment_page()` ise ne `nicepay_standalone_enabled` ne de kimlik bilgisi kontrolü yapar (bu kontroller yalnızca `render_payment_shortcode()`'da, `:395-424`).
````

**Başarısızlık senaryosu**

Mağazada NicePay ve Stripe birlikte etkindir. Müşteri Stripe ile ödenmemiş bir siparişin 'ödeme yap' bağlantısını açar (`is_checkout_pay_page()` true) → tarayıcı pg-web.nicepay.co.kr'den nicepay-pgweb.js indirir, hiçbir NicePay formu olmamasına rağmen. Ters yönde: yönetici `[nicepay_payment id="cfg_..."]` shortcode'unu bir 'Reusable Block' içine koyar → `has_shortcode($post->post_content, ...)` `<!-- wp:block {"ref":42} /-->` gördüğü için false döner → form stilsiz açılır ve ilk saniyede ham HTML olarak görünür.

**Etki**

Aşırı-kapsayıcı taraf: Stripe/PayPal ile ödenecek bir order-pay sayfasında bile Kore'deki bir üçüncü taraf sunucudan JS çekilir — GDPR/KVKK açısından gereksiz veri aktarımı, ekstra DNS+TLS gecikmesi ve `window.nicepaySubmit`/`window.nicepayClose` global'lerinin (`assets/js/nicepay.js:481-487`) gereksiz tanımlanması. Eksik-kapsayıcı taraf: shortcode blok widget'ında, FSE şablon parçasında, reusable block'ta veya Elementor/Divi gibi post_meta'da içerik saklayan bir sayfa oluşturucuda ise `is_payment_page()` false döner; varlıklar ancak shortcode render edilirken (wp_head'ten sonra) enqueue edilir, CSS `print_late_styles()` ile footer'da basılır ve ödeme formu bir an stilsiz görünür (FOUC).

**Öneri**

1) Order-pay sayfasında siparişin ödeme yöntemini kontrol edin:
```php
if ( ! $load && function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
    $order_id = absint( get_query_var( 'order-pay' ) );
    $order    = $order_id ? wc_get_order( $order_id ) : null;
    $load     = $order && 'nicepay' === $order->get_payment_method();
}
```
2) `is_payment_page()`'i tamamen kaldırıp yalnızca shortcode render'ında enqueue etmeye güvenin (WP geç enqueue'yu zaten destekler) veya `render_block` / `wp_enqueue_scripts`'te `has_block('core/shortcode')` + `wp_block` çözümlemesini de kapsayacak şekilde genişletin.
3) Standalone kapalıyken hiçbir varlık yüklemeyin: `enqueue_payment_assets()` başına `if ( 'yes' !== get_option( 'nicepay_standalone_enabled', 'no' ) && ! is_checkout_pay_page() ) { return; }` benzeri bir kapı ekleyin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddianın her üç bileşenini de kodu okuyarak doğruladım; satır numaraları dosyanın şu anki haliyle birebir eşleşiyor ve iddiayı çürütecek hiçbir üst-katman koruması yok.

1) Satır doğruluğu: `enqueue_scripts()` gerçekten 330-340 arasında, `enqueue_payment_assets()` 477-525 arasında, shortcode içindeki enqueue çağrısı tam olarak 403'te, `is_payment_page()` ise 794-800'de ve iddiada alıntılandığı gibi yalnızca `has_shortcode( $post->post_content, 'nicepay_payment' )` kontrolü yapıyor.

2) Aşırı-kapsayıcı taraf (order-pay): `is_checkout_pay_page()` WooCommerce'in genel "order-pay" endpoint kontrolüdür; siparişin ödeme yöntemine bakmaz. Kodda bu dala eşlik eden hiçbir `get_payment_method()` / gateway kontrolü yok. Ayrıca `grep -rn "enqueue" includes/class-nicepay-gateway.php includes/class-nicepay-blocks-integration.php` HİÇBİR sonuç döndürmüyor — yani gateway'in kendi `receipt_page()` akışı (class-nicepay-gateway.php:35, :136) varlıkları kendisi enqueue etmiyor, tamamen bu genel `is_checkout_pay_page()` dalına bağımlı. Bu da neden geniş tutulduğunu açıklıyor ama iddiayı çürütmüyor: NicePay dışı bir gateway ile ödenecek order-pay sayfasında da uzak `https://pg-web.nicepay.co.kr/...` betiği yüklenir. `enqueue_payment_assets()` içinde tek erken-return `wp_script_is( 'nicepay-pgweb', 'enqueued' )` (:478) — yani sadece çift enqueue koruması; standalone/gateway/kimlik kontrolü yok.

3) Standalone kapısı: `'yes' !== get_option( 'nicepay_standalone_enabled', 'no' )` kontrolü YALNIZCA `render_payment_shortcode()` (:395) ve `ajax_init_payment()` (:533) içinde var; `enqueue_scripts()`/`is_payment_page()` yolunda yok. Dahası, `enqueue_payment_assets()` shortcode içinde satır 403'te — yani `config_id` doğrulaması (:407-410), `nicepay_get_saved_shortcode()` (:412), `is_ssl()` (:417) ve kimlik bilgisi kontrolünden (:422) ÖNCE — çağrılıyor. Bu, iddiayı destekleyen ek bir kanıt: geçersiz/eksik config veya HTTPS olmayan sayfada bile uzak betik yüklenir.

4) Eksik-kapsayıcı taraf: `has_shortcode( $post->post_content, ... )` reusable block (`<!-- wp:block {"ref":42} /-->`), FSE şablon parçası, widget bloğu veya post_meta'da içerik saklayan sayfa oluşturucularda false döner; bunu telafi eden `render_block`, `has_block`, `wp_block` veya `widget` tarafı hiçbir kod yok (grep yalnızca yukarıdaki 4 enqueue noktasını buluyor). Bu durumda tek enqueue yolu shortcode render'ıdır (`the_content` sırasında, `wp_head`'den sonra); script'ler `$in_footer = true` olduğu için sorunsuz basılır ama `wp_enqueue_style( 'nicepay-css', ... )` (:482) geç kaldığı için WP core'un `print_late_styles()` mekanizmasıyla footer'da basılır — CSS'in şablonda inline fallback'i yok (`grep "<style\|wp_add_inline_style" templates` boş; tek `<style>` return-handler'ın kendi tam-sayfa çıktısında, :267). Dolayısıyla FOUC iddiası da geçerli.

5) `window.nicepaySubmit` / `window.nicepayClose` gerçekten `assets/js/nicepay.js:481` ve `:486`'da global olarak tanımlanıyor.

Severity medium uygun: güvenlik açığı değil, ama üçüncü taraf/yurtdışı kaynağa gereksiz istek (gizlilik + gecikme) ve gerçek bir FOUC/asset-eksikliği senaryosu var.

---

### PLAT-008 — needs_setup() uygulanmamış: kimlik bilgileri WC gateway ayarlarının dışında, gateway 'etkin' görünüp checkout'ta sessizce kayboluyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | wc-gateway-contract |
| **Konum** | [includes/class-nicepay-gateway.php:39](../../../includes/class-nicepay-gateway.php#L39) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

````php
```php
39:    public function init_form_fields() {
40:        $this->form_fields = array(
41:            'enabled' => ... 'title' => ... 'description' => ...
61:            'settings_notice' => array(
62:                'title'       => __( 'API Settings', ... ),
63:                'type'        => 'title',
64:                'description' => sprintf(
66:                    __( 'Configure MID, Merchant Key, and other API settings on the <a href="%s">NicePay Settings</a> page.', ... ),
67:                    admin_url( 'admin.php?page=nicepay-settings' )
```
MID/merchant key/etkin yöntemler `woocommerce_nicepay_settings` içinde değil, ayrı `nicepay_*` seçeneklerinde tutuluyor. `grep -n 'needs_setup' includes/class-nicepay-gateway.php` boş — override yok. `is_available()` (`:76-103`) eksik yapılandırmada sessizce `false` döner.
````

**Başarısızlık senaryosu**

Yeni mağaza sahibi eklentiyi kurar, doğrudan WooCommerce > Ödemeler'e gider ve NicePay toggle'ını açar. Hiçbir uyarı almaz. Checkout'a gidip test siparişi dener; NicePay ödeme seçenekleri arasında yoktur. Neden olduğunu anlamak için NicePay > Ayarlar sayfasındaki readiness paneline ulaşması gerekir — ama oraya yönlendiren bir sinyal ödeme listesinde yoktur.

**Etki**

WooCommerce > Ayarlar > Ödemeler listesinde mağaza sahibi NicePay'i 'Etkin' olarak açabilir; liste onu etkin gösterir, ancak checkout'ta yöntem hiç görünmez. `needs_setup()` WooCommerce'in tam bu durumu engellemek için sunduğu sözleşmedir: true dönerse WooCommerce toggle'ı 'Kur' bağlantısına çevirir ve yanlışlıkla etkinleştirmeyi engeller. Ayrıca ayarların iki farklı ekrana bölünmesi (WC gateway ayarları vs NicePay Ayarları) keşfedilebilirliği düşürüyor.

**Öneri**

```php
public function needs_setup() {
    return ! NicePay_Installer::is_current()
        || '' === (string) $this->api->get_mid()
        || '' === (string) $this->api->get_merchant_key()
        || empty( nicepay_get_enabled_methods() );
}
```
Ayrıca `get_admin_options_html()` / `admin_options()` override'ında readiness panelini (mevcut `NicePay_Admin::render_readiness_panel()` mantığı) WC gateway ayar ekranının tepesinde de gösterin, böylece mağaza sahibi tek ekranda neyin eksik olduğunu görsün.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `medium` → `low`
- Düzeltilmiş iddia: `needs_setup()` uygulanmamış (doğrulandı, includes/class-nicepay-gateway.php'de hiç yok), ancak "hiçbir sinyal yok" iddiası yalnızca `is_available()`'ın uyarı üretilmeyen dalları için geçerlidir. Doğru hali: MID/merchant key eksikliği (live mod) ve geçersiz mod, `admin_notices`'a bağlı `configuration_notice()` (nicepay-payment-gateway.php:126, 257-269) sayesinde WooCommerce > Ödemeler ekranında da hata bildirimi olarak görünür ve NicePay Ayarları'na link verir; ayrıca varsayılan kurulumda test MID/key ve `array('CARD')` yöntemi otomatik doldurulduğu için iddianın "yeni mağaza sahibi" senaryosu kimlik bilgisi eksikliğiyle tetiklenmez. Gerçek boşluk daha dardır: HTTPS yok (satır 98-100), desteklenmeyen para birimi (93-96), şema güncel değil (81-83) ve tüm yöntemler kapatılmış (89-91) durumlarında gateway "Etkin" görünüp checkout'ta hiçbir admin sinyali olmadan kaybolur — bu dört durum `nicepay_get_configuration_warnings()` kapsamında değildir ve iddianın önerdiği `needs_setup()` taslağı da bunlardan HTTPS ile para birimini kapsamaz.
- Gerekçe: Çekirdek teknik iddia doğrulandı: `WC_Gateway_NicePay` sınıfında `needs_setup()` override'ı YOK (repo genelinde `needs_setup` yalnızca `docs/analysis/*.md` içinde geçiyor, hiçbir PHP dosyasında değil), kimlik bilgileri gerçekten `woocommerce_nicepay_settings` dışında `nicepay_*` seçeneklerinde tutuluyor ve `is_available()` (satır 76-103) eksik yapılandırmada sessizce `false` dönüyor. Verilen satır numaraları (39-71 form_fields, 76-103 is_available) dosyanın şu anki haliyle birebir eşleşiyor.

Ancak iddianın iki detayı yanlış / abartılı:

1) "Ödeme listesinde onu oraya yönlendiren bir sinyal yoktur" iddiası kısmen çürütülüyor. `nicepay-payment-gateway.php:126` global `admin_notices` hook'una `configuration_notice()` bağlıyor; bu hook WooCommerce > Ayarlar > Ödemeler ekranı dahil TÜM admin sayfalarında çalışır ve `nicepay-settings` sayfasına giden bir "Review NicePay settings" bağlantısı basar (`:257-269`). `nicepay_get_configuration_warnings()` (includes/nicepay-functions.php:501-531) şu durumlarda uyarı üretir: live mod + eksik MID/key (error), geçersiz mod (error), test modu + etkin ödeme yüzeyi (warning). Yani iddianın merkezine koyduğu "MID/merchant key eksik" senaryosunda mağaza sahibi Ödemeler ekranında **kırmızı bir hata bildirimi ve doğru sayfaya link** görür.

2) İddianın "başarısızlık senaryosu" (yeni mağaza sahibi kurar, MID eksik olduğu için checkout'ta kaybolur) varsayılan kurulumla uyuşmuyor: aktivasyon `nicepay_test_mid`/`nicepay_test_merchant_key`'i NICEPAY_TEST_MID/KEY ile (nicepay-payment-gateway.php:272-278) ve `nicepay_enabled_methods`'u `array('CARD')` ile (nicepay-functions.php:1341-1343) doldurur. Yani taze kurulumda kimlik bilgisi ve yöntem zaten doludur.

Geriye kalan gerçek boşluk daha dar ama hâlâ gerçek: `is_available()`'ın **HTTPS yok** (satır 98-100), **desteklenmeyen para birimi** (93-96), **şema güncel değil** (81-83) ve **tüm yöntemler kapatılmış** (89-91) dallarında hiçbir admin uyarısı üretilmez — `nicepay_get_configuration_warnings()` bu dört durumu hiç kapsamaz. Bu koşullarda gateway toggle'ı "Etkin" görünür, checkout'ta yöntem yoktur ve Ödemeler ekranında hiçbir sinyal yoktur; readiness paneli (admin/class-nicepay-admin.php:425-465) bunları listeler ama sadece NicePay Ayarları ekranında. Ayrıca iddianın önerdiği `needs_setup()` taslağı da bu dört durumdan yalnızca ikisini (şema, yöntemler) kapsar — HTTPS/para birimi dallarını kapsamaz, yani öneri de eksiktir.

Bu yüzden bulgu "confirmed" değil "partially-confirmed"; severity de medium'dan low'a çekilmeli çünkü en olası ve en yüksek etkili alt-vaka (live mod, eksik kimlik bilgisi) zaten admin genelinde bir hata bildirimiyle kapatılmış durumda.

---

### PLAT-009 — Cross-site POST dönüşünde WooCommerce oturum çerezi (SameSite=Lax) gönderilmiyor: sepet kaybolur, hata bildirimi boş bir checkout sayfasında gösterilir

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | wc-session |
| **Konum** | [includes/class-nicepay-gateway.php:429](../../../includes/class-nicepay-gateway.php#L429) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
376:    public function handle_return() {
...
387:        if ( 'POST' !== $request_method ) { wp_die( ... 405 ... ); }
...
429:            wc_add_notice( __( 'We could not verify this payment attempt. Please try again.', 'nicepay-payment-gateway' ), 'error' );
430:            wp_safe_redirect( wc_get_checkout_url() );
431:            exit;
```
Dönüş, NICEPAY ödeme penceresinden (`pg-web.nicepay.co.kr`) yapılan üst-seviye, siteler-arası bir form POST'udur. WooCommerce oturum çerezi `wc_setcookie()` ile SameSite niteliği belirtilmeden yazılır; Chrome/Edge/Firefox bu çerezleri varsayılan `Lax` olarak değerlendirir ve siteler-arası POST'ta göndermez. Dolayısıyla `handle_return()` boş bir oturumla çalışır, `wc_add_notice()` yeni oluşan bir oturuma yazılır ve `wc_get_checkout_url()` sepet-tabanlı checkout'a yönlendirir.
````

**Başarısızlık senaryosu**

Müşteri sepete iki ürün ekler, NicePay ile ödemeye gider, NICEPAY penceresinde kartı reddedilir ve `AuthResultCode != 0000` ile dönüş POST'u gelir. `validate_auth_return()` WP_Error döner → `wc_add_notice(...)` + `wp_safe_redirect( wc_get_checkout_url() )`. Tarayıcı yeni oturum çerezini alır, checkout'a gider ve 'Sepetiniz şu anda boş' ile birlikte hata mesajını görür. Sepet içeriği kaybolmuştur.

**Etki**

Doğrulanamayan/başarısız dönüşlerde müşteri, hata bildirimini görür ama sepeti boş bir checkout sayfasında bulur ('Sepetiniz şu anda boş'). Yeniden deneme yolu kırılır. Ayrıca müşterinin oturumu her NICEPAY dönüşünde sıfırlanır; oturumda tutulan kupon, kargo seçimi ve `wc_notices` kaybolur.

**Öneri**

Başarısız dönüşlerde sepet-tabanlı checkout'a değil, siparişe bağlı order-pay URL'ine yönlendirin (bu URL sipariş anahtarı taşıdığı için oturumdan bağımsızdır) — sınıf bunu bazı dallarda zaten yapıyor (`:479`, `:540`, `:687`), ancak `:430` ve `:441`'de yapmıyor:
```php
$fallback_order = ! empty( $transaction->wc_order_id ) ? wc_get_order( $transaction->wc_order_id ) : null;
wp_safe_redirect( $fallback_order ? $fallback_order->get_checkout_payment_url( true ) : wc_get_checkout_url() );
```
Hata mesajını oturuma yazmak yerine order-pay URL'ine imzalı/aktarımsız bir query argümanı olarak taşıyın veya `woocommerce_receipt_nicepay` içinde okunabilecek bir sipariş meta'sına yazın. Ek olarak `woocommerce_set_cookie_options` ile `SameSite=None; Secure` gerektiren senaryoyu dokümante edin.

---

### PLAT-010 — Cron güvenilirliği doğrulanmıyor: DISABLE_WP_CRON farkındalığı yok, readiness paneli çalışmayan cron'u 'Hazır' gösteriyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | cron |
| **Konum** | [admin/class-nicepay-admin.php:431](../../../admin/class-nicepay-admin.php#L431) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

````php
```php
431:        $cron_ready       = ! function_exists( 'wp_next_scheduled' ) || (bool) wp_next_scheduled( 'nicepay_expire_pending_transactions' );
...
439:            array( $cron_ready, __( 'Recovery cron', ... ), __( 'The pending-attempt expiry and stale-approval recovery task must be scheduled.', ... ) ),
```
Zamanlama tarafında da dönüş değeri kontrol edilmiyor:
```php
// nicepay-payment-gateway.php:208-210
} elseif ( ! wp_next_scheduled( 'nicepay_expire_pending_transactions' ) ) {
    wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'nicepay_expire_pending_transactions' );
}
```
`grep -rn "DISABLE_WP_CRON|wp_doing_cron" nicepay-payment-gateway.php includes admin docs readme.txt` hiçbir sonuç vermiyor. Retention görevi bir `LAST_RUN_OPTION` tutuyor (`includes/class-nicepay-retention.php:162-169`) ama expiry cron'unun 'en son ne zaman koştu' kaydı yok.
````

**Başarısızlık senaryosu**

Yönetilen hostingte `DISABLE_WP_CRON` true'dur ve sistem cron'u yalnızca `wp-cron.php`'yi HTTP Basic Auth arkasındaki bir URL'e çağırdığı için 401 alır. NicePay > Ayarlar 'Core readiness checks passed' der. Üç ay sonra tablo, hiç sona ermemiş yüzlerce `pending` satırı ve bir kısmı `approving` durumunda takılı, `active_attempt_key`'i serbest bırakılmamış satırlar içerir; ilgili siparişler yeni bir ödeme denemesi başlatamaz (`nicepay_abandon_pending_transactions()` başarısız olur, `includes/class-nicepay-gateway.php:262-266`).

**Etki**

`define('DISABLE_WP_CRON', true)` çok yaygın bir üretim yapılandırmasıdır (WP Engine, Kinsta ve çoğu yönetilen hosting varsayılanı). Sistem cron'u yanlış kurulmuşsa `wp_next_scheduled()` hâlâ bir zaman damgası döner — yani panel 'Recovery cron: Ready' der — ama `nicepay_expire_pending_transactions` hiç koşmaz. Pending ödeme girişimleri süresiz açık kalır, `active_attempt_key` kilitleri temizlenmez ve takılı 'approving' satırları kurtarılmaz; bunların hepsi para güvenliği için kritik olan yollardır.

**Öneri**

1) Görevin gerçekten koştuğunu kaydedin ve panelde bunu gösterin:
```php
// nicepay_expire_pending_transactions() sonunda
update_option( 'nicepay_expiry_last_run', time(), false );
// readiness paneli
$last = (int) get_option( 'nicepay_expiry_last_run', 0 );
$cron_ready = $last > 0 && ( time() - $last ) < 6 * HOUR_IN_SECONDS;
```
2) `DISABLE_WP_CRON` tanımlıysa panelde ayrı bir uyarı satırı gösterin ve gerçek sistem cron komutunu (`wp cron event run --due-now` veya `curl .../wp-cron.php?doing_wp_cron`) örnekleyin.
3) `wp_schedule_event()` dönüş değerini kontrol edip (WP 5.7+ `false`/`WP_Error` dönebilir) başarısızlığı `nicepay_log()` ile kaydedin.
4) `docs/CONFIGURATION.md` ve `readme.txt`'e bir 'WP-Cron gereksinimleri' bölümü ekleyin — şu anda hiçbirinde geçmiyor.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Çekirdek iddia doğru: readiness paneli cron'un SADECE zamanlanmış olup olmadığına bakar (`wp_next_scheduled()`), gerçekten koşup koşmadığını doğrulamaz; kod tabanında `DISABLE_WP_CRON`/`wp_doing_cron` farkındalığı yoktur; `wp_schedule_event()` dönüş değeri hiçbir çağrı yerinde kontrol edilmez; expiry görevi için (retention'daki `LAST_RUN_OPTION`'ın aksine) "en son ne zaman koştu" kaydı yoktur; `readme.txt`'te "cron" kelimesi hiç geçmez.

Düzeltilmesi gereken iki detay:

1) Başarısızlık senaryosundaki mekanizma yanlış atfedilmiş. `nicepay_abandon_pending_transactions()` (includes/nicepay-functions.php:923-947) cron koşmasa bile `status='pending' AND approval_state='pending'` satırlarını her yeni denemede `abandoned` yapıp `active_attempt_key = NULL` set eder ve `true` döner; sadece DB sorgusu `false` dönerse başarısız olur. Yani "cron koşmadığı için pending satırlar birikir → abandon başarısız olur → sipariş yeni deneme başlatamaz" zinciri kurulmaz. GERÇEK kilitlenme yolu farklıdır: `abandoned` yapma sorgusu yalnızca `pending` satırları hedefler, `approving` durumunda TAKILI KALMIŞ satırlara dokunmaz. `active_attempt_key` üzerinde UNIQUE indeks vardır (includes/class-nicepay-transaction-schema.php:126 `'UNIQUE KEY uniq_active_attempt (active_attempt_key)'`) ve gateway yeni denemede aynı anahtarı (`'woocommerce:' . $order->get_id()`, class-nicepay-gateway.php:275) yazmaya çalışır. Takılı `approving` satırını `needs_reconciliation`'a çeviren tek yol expiry cron'unun ikinci UPDATE'idir (includes/nicepay-functions.php:967-978) — ama o da `active_attempt_key`'i NULL'lamaz; temizlik sonraki kurtarma yollarına kalır. Dolayısıyla cron hiç koşmuyorsa etkilenen sipariş, `approving`'te takılı satır nedeniyle yeni denemede benzersiz anahtar çakışmasına düşer; suçlanan konum `class-nicepay-gateway.php:262-266` değil, `nicepay_save_transaction()` insert'idir.

2) Dokümantasyon iddiası kısmen yanlış: `docs/CONFIGURATION.md:73` ve `:77` WP-Cron'dan bahseder ("Cleanup begins through WP-Cron… ensure WP-Cron operates reliably on low-traffic sites") — ancak yalnızca retention görevi bağlamında; recovery cron için ve `DISABLE_WP_CRON` için hiçbir yerde uyarı yoktur. `readme.txt`'te ise cron hiç geçmez (iddia bu kısımda doğru).

Konum referansları doğru (admin/class-nicepay-admin.php:431/439, nicepay-payment-gateway.php:166-168 ve 208-210, includes/class-nicepay-retention.php:112-124/162-169). Ek olarak sistem raporu da aynı zayıf sinyali kullanır: admin/class-nicepay-admin.php:240-242 `wp_next_scheduled(...) ? 'scheduled' : 'missing'`.
- Gerekçe: İddia edilen dosya/satırların hepsini açıp okudum ve grep'leri kendim çalıştırdım. Satır numaraları dosyaların şu anki haliyle birebir eşleşiyor. Panelin cron sağlığını değil yalnızca "zamanlanmış mı"yı ölçtüğü, DISABLE_WP_CRON farkındalığının olmadığı, wp_schedule_event dönüşünün kontrol edilmediği ve expiry için son-koşma kaydı bulunmadığı doğrudur — bu yüzden çekirdek bulgu ayakta. Ancak iddianın "failure scenario" bölümündeki nedensellik hatalıdır: nicepay_abandon_pending_transactions() cron'dan bağımsız çalışır ve pending satırları her yeni denemede temizler; siparişi kilitleyen şey abandon'ın başarısızlığı değil, abandon'ın hedeflemediği takılı 'approving' satırının UNIQUE active_attempt_key'i tutmasıdır. Ayrıca "docs/CONFIGURATION.md'de cron hiç geçmiyor" ifadesi yanlıştır (retention bağlamında geçer); readme.txt için doğrudur. Severity medium yerinde: gerçek para kaybı değil, kurtarma yolunun sessizce ölmesi ve panelin yanlış güven vermesi söz konusu.

---

### PLAT-011 — Hiç do_action() uzantı noktası ve tema template override'ı yok; standalone sonuç sayfası temayı tamamen atlıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | extensibility |
| **Konum** | [includes/class-nicepay-return-handler.php:251](../../../includes/class-nicepay-return-handler.php#L251) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````php
`grep -rn "do_action" nicepay-payment-gateway.php includes admin templates` → **hiçbir sonuç yok**. Eklentinin tamamı yalnızca 6 `apply_filters` çağrısı içeriyor (`nicepay_http_timeout`, `nicepay_http_connect_timeout`, `nicepay_public_rate_limit_*`, `nicepay_manage_transactions_capability`, `nicepay_goods_cl`) ve bunların hiçbiri ödeme yaşam döngüsü olayı değil.
Şablonlar doğrudan include ediliyor, `wc_get_template()` / `locate_template()` yok:
```php
// includes/class-nicepay-gateway.php:370
include NICEPAY_PLUGIN_DIR . 'templates/payment-form.php';
// nicepay-payment-gateway.php:470
include NICEPAY_PLUGIN_DIR . 'templates/standalone-payment-form.php';
```
Standalone sonuç sayfası ise kendi `<html>` belgesini basıyor; `wp_head()`/`wp_footer()`/`body_class()` yok, tüm CSS satır içi ve bir satır içi `onclick` var:
```php
261:        <!DOCTYPE html>
262:        <html <?php language_attributes(); ?>>
267:            <style> * { box-sizing: border-box; } ... </style>
361:                    <button type="button" class="result-btn result-btn-secondary" onclick="window.print()">
```
````

**Başarısızlık senaryosu**

Bir dernek `[nicepay_payment]` ile bağış formu yayınlar ve başarılı bağışta bağışçıyı Mailchimp listesine eklemek ister. Kanca yoktur: `nicepay_issue_standalone_receipt()` sonrası hiçbir `do_action` tetiklenmez, `render_result_page()` de filtrelenemez. Tek çözüm `wp_mail` filtresini gözetlemek gibi kırılgan bir hack olur. Aynı dernek sonuç sayfasına logosunu koymak ister — mümkün değildir, sayfa sabit satır içi CSS ile basılır.

**Etki**

Standalone (WooCommerce'siz) akışta mağaza sahibinin ödeme sonucuna bağlanabileceği hiçbir nokta yoktur: CRM'e kayıt, üyelik açma, kendi e-postasını gönderme, muhasebe entegrasyonu — hiçbiri mümkün değil. Sonuç sayfası mağazanın markası, dil değiştiricisi, çerez/analitik betikleri ve erişilebilirlik ayarlarından tamamen kopuktur; satır içi `onclick` katı CSP (`script-src 'self'`) uygulayan sitelerde çalışmaz. Şablon override'ı olmadığı için formun görünümünü değiştirmek yalnızca eklentiyi forklayarak mümkündür — WooCommerce ekosisteminin temel beklentisiyle çelişir.

**Öneri**

1) Yaşam döngüsü kancaları ekleyin (en azından): `do_action( 'nicepay_payment_approved', $transaction, $result )`, `do_action( 'nicepay_payment_failed', $transaction, $result_code )`, `do_action( 'nicepay_refund_confirmed', $transaction, $attempt_id )`, `do_action( 'nicepay_reconciliation_required', $transaction, $reason )`.
2) Şablon override'ı ekleyin:
```php
$override = locate_template( array( 'nicepay/' . $template, $template ) );
include $override ? $override : NICEPAY_PLUGIN_DIR . 'templates/' . $template;
```
3) Sonuç sayfasını ya tema şablonuyla (`get_header()`/`get_footer()` veya sanal bir sayfa) render edin ya da en az `apply_filters( 'nicepay_result_page_html', $html, $success, $data )` ile filtrelenebilir yapın; satır içi `onclick="window.print()"` yerine enqueue edilmiş bir dinleyici kullanın.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddianın her maddesini kodda doğruladım.

1) `grep -rn "do_action" nicepay-payment-gateway.php includes admin templates assets` → **sıfır sonuç**. Eklentinin kendi hiçbir yaşam döngüsü kancası yok.
2) `apply_filters` sadece 6 yerde ve hepsi altyapısal (HTTP timeout, rate-limit, capability, GoodsCl). Hiçbiri "ödeme onaylandı / reddedildi / iade edildi / mutabakat gerekli" olayı değil.
3) Şablonlar gerçekten doğrudan include ediliyor; tüm kod tabanında `wc_get_template`, `locate_template`, `get_header`, `wp_head`, `wp_footer`, `body_class` çağrısı YOK (grep boş döndü). Sadece iki include var ve ikisi de iddia edilen satırlarda.
4) `render_result_page()` gerçekten 251. satırda başlıyor, kendi `<!DOCTYPE html>`'ini 261'de basıyor, gömülü `<style>` bloğu 267+ ve satır içi `onclick="window.print()"` tam olarak 361. satırda. `wp_head()`/`wp_footer()` çağrısı yok → hiçbir tema/analitik/çerez/erişilebilirlik betiği yüklenmez, satır içi handler `script-src 'self'` CSP altında çalışmaz.
5) Başarısızlık senaryosunu da doğruladım: `nicepay_issue_standalone_receipt()` (nicepay-functions.php:1052) yalnızca `array('token','url')` döndürür, hiçbir kanca tetiklemez; çağrıldığı tek yer return-handler.php:209 ve hemen ardından 218'de `render_result_page(true, ...)` çağrılıyor — arada hiçbir uzantı noktası yok. `nicepay_send_standalone_receipt_email()` (1152) bile `$subject`/`$body` için filtre sunmuyor, dolayısıyla "wp_mail filtresini gözetleme" hack'i de gerçekten tek çıkış yolu.

Tek nüans (severity'yi değiştirmeyecek kadar küçük ve iddia zaten bunu kapsıyor): WooCommerce akışında `$order->payment_complete()` (class-nicepay-gateway.php:190, 639) ve `$order->update_status()` (473, 501, 533, 558, 619-621, 655) WooCommerce çekirdeğinin kendi kancalarını (`woocommerce_payment_complete`, `woocommerce_order_status_changed`) tetikler; yani Woo akışında geliştiricinin dolaylı bir tutamağı vardır. Ancak iddianın etki ve başarısızlık senaryosu açıkça standalone/`[nicepay_payment]` akışına odaklanıyor ve orada gerçekten hiçbir tutamak yok — ayrıca NICEPAY'e özgü veriler (TID, VbankNum, ReceiptURL, net-cancel durumu) Woo kancalarında da taşınmıyor. Bu yüzden "confirmed", medium severity yerinde.

---

### PLAT-012 — Beyan edilen minimum sürümler (WP 5.8 / WC 5.0) hiçbir testte doğrulanmıyor; plugin header'da 'WC tested up to' yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | compatibility-claims |
| **Konum** | [tests/integration/run-woocommerce-smoke.sh:14](../../../tests/integration/run-woocommerce-smoke.sh#L14) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

````bash
```bash
11: database_image='mariadb@sha256:8020e05c...' # 10.11
12: wordpress_image='wordpress@sha256:b427cec7...'
14: woocommerce_version='11.0.1'
```
CI'daki tek WooCommerce entegrasyon işi budur (`.github/workflows/tests.yml:124-133`) ve yalnızca tek bir WP imajı + WooCommerce 11.0.1 ile koşar. Beyanlar ise:
```
// nicepay-payment-gateway.php:12-14
Requires at least: 5.8
Requires PHP: 7.4
WC requires at least: 5.0
```
Plugin header'ında `WC tested up to:` satırı **yok** (dosyanın 1-16. satırları arasında bulunmuyor), `readme.txt`'te de `Contributors:` alanı yok (`readme.txt:1-8`).
````

**Başarısızlık senaryosu**

Bir mağaza WordPress 5.8 + WooCommerce 5.0 üzerindedir (header buna izin verir). CI hiçbir zaman bu kombinasyonu koşmadığı için, örneğin `wc_create_refund()`'un o sürümdeki `refund_payment` davranış farkı ya da `WC_Payment_Gateway::get_option($key, $default)` imza farkı üretimde ilk iade denemesinde patlar; testler yeşildir çünkü yalnızca WooCommerce 11.0.1 test edilmiştir.

**Etki**

WooCommerce, eklentileri `WC tested up to` header'ına göre değerlendirir; bu satır olmadığı için WooCommerce eklentiler listesinde 'aktif WooCommerce sürümüyle test edilmemiş' uyarısı çıkabilir. Daha önemlisi WP 5.8 / WC 5.0 desteği tamamen iddiadan ibarettir: kullanılan API'lerden hiçbiri o sürümlerde çalıştırılmamıştır, dolayısıyla eski bir kurulumda ortaya çıkacak bir regresyon yayınlanana kadar fark edilmez. Bu, PR'ın 'release readiness' iddiasıyla doğrudan çelişir.

**Öneri**

1) `nicepay-payment-gateway.php` header'ına `WC tested up to: 11.0` ekleyin ve `check-version.js`'e bunun WooCommerce sürümüyle senkron kalmasını doğrulayan bir kontrol koyun.
2) `run-woocommerce-smoke.sh`'i parametreleştirin (`WOOCOMMERCE_VERSION`, `WORDPRESS_IMAGE` env) ve CI matrisinde en az iki nokta koşun: `{WP min, WC min}` ve `{WP latest, WC latest}`.
3) Minimumlar gerçekten test edilemeyecekse header'ları test edilen gerçek tabana yükseltin (ör. `Requires at least: 6.2`, `WC requires at least: 7.1` — HPOS beyanı zaten WC 7.1+ gerektiriyor).
4) `readme.txt`'e `Contributors:` alanını ekleyin (WordPress.org readme doğrulayıcısı bunu bekler).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Plugin header'ında `WC tested up to:` satırı hiç yok (tüm repoda 0 eşleşme), ve beyan edilen minimum platform sürümleri (WP 5.8 / WC 5.0) hiçbir CI işinde koşulmuyor: `run-woocommerce-smoke.sh` tek bir sabit WP imajı ve sabit `woocommerce_version='11.0.1'` ile çalışıyor (satır 12, 14, 97), env ile parametreleştirilebilir değil; içindeki tek "matris" depolama modu (legacy/HPOS, satır 100-107), sürüm matrisi değil. Bu nedenle WC 5.0 / WP 5.8 desteği doğrulanmamış bir beyandır. ANCAK: (a) kodda kullanılan modern WC API'lerinin tamamı `class_exists`/`is_callable`/`Throwable` guard'larıyla korunuyor (nicepay-payment-gateway.php:42, :313; admin/class-nicepay-admin.php:246, :257-266), dolayısıyla eski WC'de beklenen sonuç fatal error değil özellik devre dışı kalmasıdır ve orijinal bulgudaki `wc_create_refund()` / `get_option()` imza farkı senaryosu koddan doğrulanmamış spekülasyondur; (b) `readme.txt`'teki eksik `Contributors:` alanı sessiz bir hata değil, `deploy-wordpress-org.yml:262-265`'te açıkça yayını bloklayan bir kapıyla korunuyor. Ek olarak, orijinal bulgunun 3. önerisi (minimumları yükseltmek) mevcut halde CI'ı kırar: `check-version.js:81-83` `Requires at least` değerini literal `'5.8'`e sabitliyor; düzeltme bu dosyayı da kapsamalı.
- Gerekçe: Çekirdek iddia doğrulandı, ama kanıtın iki detayı yanlış/eksik ve "başarısızlık senaryosu" tamamen spekülatif.

DOĞRULANAN KISIMLAR:
1. `WC tested up to:` header'ı gerçekten YOK. Yalnızca dosyanın 1-16. satırlarında değil, TÜM repoda yok: `grep -rn "WC tested up to"` hiçbir eşleşme döndürmüyor (sadece `nicepay-payment-gateway.php:14: * WC requires at least: 5.0` var). Satır referansları (12-14) dosyanın şu anki haliyle birebir eşleşiyor.
2. WooCommerce entegrasyon işi gerçekten tek noktada koşuyor: `tests/integration/run-woocommerce-smoke.sh:12` tek bir sabit `wordpress_image` digest'i, `:14` sabit `woocommerce_version='11.0.1'`, `:97` bunu `wp plugin install woocommerce --version=` ile kuruyor. Script'te `WOOCOMMERCE_VERSION`/`WORDPRESS_IMAGE` env override'ı YOK. CI'da (`.github/workflows/tests.yml:124-133`) bu tek job, matrix'siz koşuyor. Mevcut matris yalnızca DEPOLAMA MODU matrisi (script:100-107 legacy vs HPOS), sürüm matrisi değil. `run-schema-migration.sh:12` de aynı tek WP imajını kullanıyor. PHP matrisi (tests.yml:25) 7.4-8.3 kapsıyor ama WP/WC sürümünü hiç değiştirmiyor. Yani WP 5.8 / WC 5.0 desteği gerçekten hiç koşulmamış bir iddiadır.
3. `readme.txt`'te `Contributors:` alanı gerçekten yok (satır 1-8 arası: Tags, Requires at least, Tested up to, Requires PHP, Stable tag, License, License URI).

DÜZELTİLMESİ GEREKENLER:
a) `Contributors` eksikliği "WordPress.org doğrulayıcısı bekliyor, fark edilmez" değil — repoda buna karşı AÇIK bir yayın kapısı var: `.github/workflows/deploy-wordpress-org.yml:262-265` publish'ten önce `grep -Eq '^Contributors:...'` yapıp yoksa hata verip çıkıyor. Yani bu bilinçli bir "yayın öncesi doldur" TODO'su; sessiz bir hata değil. Bu alt-bulgunun etkisi bulgunun iddia ettiğinden çok daha düşük.
b) Başarısızlık senaryosunda verilen somut örnekler (`wc_create_refund()`'un WC 5.0'daki `refund_payment` davranış farkı, `WC_Payment_Gateway::get_option($key,$default)` imza farkı) hiçbir şekilde koddan doğrulanmadı ve uydurma. Aksine, kodda kullanılan tüm modern WC API'leri sürüm-güvenli şekilde korumalı: `nicepay-payment-gateway.php:42-43` (`class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')` guard'ı), `:313` (Blocks `AbstractPaymentMethodType` guard'ı), `admin/class-nicepay-admin.php:246-247` ve `:257-266` (`class_exists` + `is_callable` + `Throwable` yakalama, aksi halde `not_available`). Yani eski WC'de fatal error değil, graceful degradation beklenir. Kalan risk gerçek ama teorik: doğrulanmamış destek beyanı.
c) Öneri 3 ("header'ları yükseltin") uygulanamaz halde: `.github/scripts/check-version.js:81-83` `Requires at least`'i literal `'5.8'` değerine SABİTLİYOR (`minimumWordPressMatch[1] !== '5.8'` -> failure). Yani minimumu yükseltmek CI'ı kırar; öneri bu dosyayı da güncellemeyi içermeli. Bu, bulgunun kaçırdığı gerçek bir bakım tuzağı ve öneriyi güçlendiriyor.

Severity: `medium` korunmalı ama gerekçesi değişmeli — asıl somut kusur eksik `WC tested up to` header'ı (WooCommerce eklenti listesinde uyumsuzluk uyarısı) ve sürüm matrisinin olmaması; "iade üretimde patlar" senaryosu kanıtsız.

---

### PLAT-013 — Kod-doküman çelişkisi: readiness paneli 'HPOS uyumluluğu beyan edilmeden önce ayrı matris gerekir' diyor, oysa HPOS uyumu beyan edilmiş durumda

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | doc-code-mismatch |
| **Konum** | [admin/class-nicepay-admin.php:460](../../../admin/class-nicepay-admin.php#L460) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
460:            <p><?php esc_html_e( 'Vendor sandbox certification, Blocks checkout and HPOS require their separate integration matrix before compatibility is declared.', 'nicepay-payment-gateway' ); ?></p>
```
Oysa HPOS uyumluluğu gerçekten beyan ediliyor (`nicepay-payment-gateway.php:41-50`), CI'da legacy/HPOS matrisi koşuyor (`.github/workflows/tests.yml:124-133`) ve `readme.txt:69` 'HPOS compatibility is declared and covered by the automated legacy/HPOS storage test' diyerek panelin tam tersini söylüyor.
````

**Başarısızlık senaryosu**

Yönetici WooCommerce'in 'High-Performance Order Storage'a geç' uyarısını görür, NicePay ayarlarına bakar ve 'HPOS require their separate integration matrix before compatibility is declared' cümlesini okur; HPOS'a geçmekten vazgeçer — oysa eklenti HPOS uyumunu zaten beyan etmiş ve test etmiştir.

**Etki**

Mağaza sahibi her NicePay ayar sekmesinde HPOS'un henüz desteklenmediği izlenimini veren bir cümle okur; aynı ürünün readme'si ise desteklendiğini söyler. Bu, HPOS'a geçmeyi erteleten veya gereksiz destek talebi doğuran bir güven kaybıdır. Ayrıca bu metin çevrilmiş dört katalogda da yer aldığı için düzeltme dört `.po` dosyasını etkiler.

**Öneri**

Cümleyi gerçek duruma göre bölün, ör.:
```php
esc_html_e( 'HPOS order storage is supported and covered by the automated legacy/HPOS test matrix. Vendor sandbox certification and the Cart/Checkout Blocks browser matrix are still required before those are declared.', 'nicepay-payment-gateway' );
```
ve `.pot` + dört `.po`/`.mo` katalogunu güncelleyin. PLAT-003 çözümüyle birlikte Blocks kısmını da netleştirin.

---

### PLAT-014 — wp_set_script_translations() işlevsiz: JS'te wp.i18n kullanılmıyor, wp-i18n bağımlılığı yok ve hiç .json çeviri kataloğu üretilmiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | i18n-platform |
| **Konum** | [includes/class-nicepay-blocks-integration.php:47](../../../includes/class-nicepay-blocks-integration.php#L47) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
39:        wp_register_script(
40:            $handle,
41:            NICEPAY_PLUGIN_URL . 'assets/js/nicepay-blocks.js',
42:            array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
...
47:        if ( function_exists( 'wp_set_script_translations' ) ) {
48:            wp_set_script_translations( $handle, 'nicepay-payment-gateway', NICEPAY_PLUGIN_DIR . 'languages' );
49:        }
```
`ls languages/` yalnızca `.po`/`.mo`/`.pot` gösteriyor — `wp_set_script_translations()`'ın gerektirdiği `nicepay-payment-gateway-{locale}-{md5}.json` dosyaları yok. `assets/js/nicepay-blocks.js` içinde tek bir `wp.i18n` / `__()` çağrısı da yok (tüm metinler `get_payment_method_data()` üzerinden PHP'den geliyor), ayrıca bağımlılık dizisinde `wp-i18n` bulunmuyor.
````

**Başarısızlık senaryosu**

Geliştirici Blocks içeriğine `wp.i18n.__( 'Redirecting to NicePay…', 'nicepay-payment-gateway' )` ekler. Korece bir sitede metin İngilizce kalır çünkü `languages/` altında `.json` katalogu yoktur ve `check-translations.sh` yalnızca `.po`/`.mo` doğruladığı için CI da uyarmaz.

**Etki**

Ölü ve yanıltıcı kod: bakım yapan kişi JS çevirilerinin çalıştığını sanar. Gelecekte `nicepay-blocks.js`'e bir `wp.i18n.__()` eklenirse hem `wp-i18n` bağımlılığı eksik olduğu için `wp` global'i garanti değildir hem de `.json` katalogu üretilmediği için metin çevrilmez — sessiz bir i18n regresyonu.

**Öneri**

İki seçenek: (a) çağrıyı kaldırın ve tüm blok metinlerinin PHP'den geldiğini yorumla belirtin; ya da (b) gerçekten destekleyin — bağımlılığa `wp-i18n` ekleyin, build sürecinde `wp i18n make-json languages --no-purge` çalıştırın, `.github/scripts/check-translations.sh`'e `.json` varlığı kontrolü ekleyin ve `build-release.sh`'in `.json` dosyalarını pakete dahil ettiğini doğrulayın.

---

### PLAT-015 — Sipariş düzenleme ekranındaki NicePay özeti tamamen stilsiz: CSS ne enqueue ediliyor ne de mevcut

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | admin-assets |
| **Konum** | [admin/class-nicepay-admin.php:49](../../../admin/class-nicepay-admin.php#L49) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
49:    public function enqueue_admin_styles( $hook ) {
50:        if ( strpos( $hook, 'nicepay' ) === false ) {
51:            return;
52:        }
```
WooCommerce sipariş düzenleme ekranının hook'u `post.php` (legacy) veya `woocommerce_page_wc-orders` (HPOS) olduğundan hiçbiri 'nicepay' içermez ve CSS/JS yüklenmez. Ancak aynı sınıf o ekrana biçimlendirilmiş markup basıyor:
```php
1167:        <div class="nicepay-order-payment-summary">
1168:            <h4><?php esc_html_e( 'NicePay payment', ... ); ?></h4>
...
1182:                <dl>
```
`grep -n "nicepay-order-payment-summary" assets/css/*.css` → **hiçbir sonuç yok**: sınıf hiçbir stil sayfasında tanımlı değil.
````

**Başarısızlık senaryosu**

Yönetici NicePay ile ödenmiş bir siparişi açar. 'Order details' panelinin altında başlıksız-çerçevesiz bir metin bloğu ve tarayıcının varsayılan `dd` girintisiyle kayan 'Captured / Refunded / Remaining' satırları görür; WooCommerce'in kendi `.order_data_column` ızgarasıyla hizalanmaz.

**Etki**

Sipariş ekranındaki NicePay bloğu, WooCommerce'in kendi sipariş detay panelinin ortasında tarayıcı varsayılan `<h4>`/`<dl>`/`<dd>` stilleriyle görünür — hizalanmamış, girintili ve çevresindeki WooCommerce alanlarıyla görsel olarak uyumsuz. Mağaza sahibinin en sık baktığı ekranda ürün 'yarım bitmiş' görünür.

**Öneri**

`enqueue_admin_styles()`'a WooCommerce sipariş ekranını da dahil edin ve sınıf için stil yazın:
```php
$is_order_screen = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
    || ( function_exists( 'wc_get_page_screen_id' ) && $hook === wc_get_page_screen_id( 'shop-order' ) );
if ( false === strpos( $hook, 'nicepay' ) && ! $is_order_screen ) { return; }
```
Sipariş ekranında yalnızca `nicepay-admin.css`'i yükleyin (form/shortcode JS'i değil) ve `assets/css/nicepay-admin.css` içine `.nicepay-order-payment-summary` için WooCommerce panel ızgarasına uyan kurallar ekleyin.

---

### PLAT-016 — uninstall.php yok: iki tablo, 14+ seçenek ve önbellek verisi kalıcı olarak kalıyor ve bu davranış readme/UI'da hiç açıklanmıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | lifecycle |
| **Konum** | [nicepay-payment-gateway.php:816](../../../nicepay-payment-gateway.php#L816) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
`ls uninstall.php` → yok. `grep -rn "register_uninstall_hook|uninstall" nicepay-payment-gateway.php includes admin` → hiçbir sonuç yok. Eklenti şunları oluşturur: `{prefix}nicepay_transactions` (66 sütun), `{prefix}nicepay_refund_attempts`, ve `set_default_options()` ile 14 seçenek (`nicepay-payment-gateway.php:271-285`) — bunlar arasında canlı merchant key de var (`:276`). Deaktivasyon yalnızca cron'ları temizler:
```php
191:    private function deactivate_current_site() {
192:        wp_clear_scheduled_hook( 'nicepay_expire_pending_transactions' );
193:        wp_clear_scheduled_hook( NicePay_Retention::CRON_HOOK );
194:        flush_rewrite_rules();
195:    }
```
Bu tasarım kararı yalnızca `docs/DEVELOPER-GUIDE.md:58`'de geçiyor; `readme.txt`'te ('uninstall' kelimesi hiç geçmiyor) ve yönetici arayüzünde hiçbir yerde belirtilmiyor.
````

**Başarısızlık senaryosu**

Mağaza sahibi NicePay'den başka bir sağlayıcıya geçer ve eklentiyi WordPress panelinden 'Sil' ile kaldırır. `wp_options` içinde `nicepay_live_merchant_key` düz metin olarak kalır ve `wp_nicepay_transactions` tablosu tüm alıcı adı/e-posta/telefon kayıtlarıyla veritabanında durur. Aylar sonra bir veri ihlali soruşturmasında bu veriler beklenmedik şekilde ortaya çıkar.

**Etki**

Eklentiyi silen mağaza sahibi, veritabanında finansal ledger tablolarının ve şifrelenmemiş canlı merchant key seçeneğinin kaldığını bilmez. Finansal saklama açısından bu davranış savunulabilir, ancak GDPR/KVKK 'silme hakkı' talepleri ve mağaza devri/satışı senaryolarında sürpriz yaratır. Test/staging ortamlarında da temizlenemeyen artıklar bırakır.

**Öneri**

1) Bir `uninstall.php` ekleyin ve varsayılan olarak **hiçbir finansal satırı silmeyin**, ancak sırları temizleyin:
```php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
delete_option( 'nicepay_live_merchant_key' );
delete_option( 'nicepay_test_merchant_key' );
// Tam temizlik yalnızca yönetici açıkça onayladıysa:
if ( 'yes' === get_option( 'nicepay_purge_on_uninstall', 'no' ) ) { /* DROP TABLE ... */ }
```
2) Ayarlar sayfasına 'Kaldırırken tüm NicePay verilerini sil' onay kutusu ekleyin (retention onayıyla aynı iki adımlı doğrulama deseniyle).
3) `readme.txt`'e bir 'Uninstall' SSS maddesi ekleyin — davranış şu anda yalnızca geliştirici dokümanında.

---

### PLAT-017 — Genel (unauthenticated) okuma yolunda seçenek yazımı ve autoload'ı belirtilmeyen add_option/update_option çağrıları

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | options-api |
| **Konum** | [includes/nicepay-functions.php:1930](../../../includes/nicepay-functions.php#L1930) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
1930: function nicepay_get_all_shortcodes() {
1931:     $shortcodes = get_option( 'nicepay_saved_shortcodes', null );
1932:     if ( $shortcodes === null ) {
1933:         $shortcodes = nicepay_get_default_presets();
1934:         update_option( 'nicepay_saved_shortcodes', $shortcodes );
1935:     }
```
Bu fonksiyon ön uçta shortcode render'ında (`nicepay-payment-gateway.php:412` → `nicepay_get_saved_shortcode()` → `nicepay_get_all_shortcodes()`) ve `nopriv` AJAX ucundan (`ajax_init_payment` → `NicePay_Offer_Resolver::resolve_standalone()`) çağrılıyor. Ayrıca tüm varsayılan seçenekler autoload parametresi verilmeden yazılıyor:
```php
// nicepay-payment-gateway.php:271-285
271:    private function set_default_options() {
272:        add_option( 'nicepay_mode', 'test' );
...
276:        add_option( 'nicepay_live_merchant_key', '' );
...
284:        add_option( 'nicepay_saved_shortcodes', nicepay_get_default_presets() );
```
`add_option()`'ın 4. parametresi verilmediğinde autoload `yes` olur.
````

**Başarısızlık senaryosu**

Bir yönetici hata ayıklama sırasında `wp option delete nicepay_saved_shortcodes` çalıştırır. Bundan sonra `[nicepay_payment]` içeren sayfaya gelen her anonim ziyaretçi (ve her `nicepay_init_payment` AJAX isteği), önce `nicepay_get_default_presets()`'i üretip `update_option()` çağırır — trafik anında birden fazla eşzamanlı yazma yarışır ve nesne önbelleği her seferinde `alloptions` anahtarını temizler.

**Etki**

(a) Kimliği doğrulanmamış bir GET/POST isteği, seçenek tablosuna yazma tetikleyebilir — okuma yolunda yazma, salt-okunur replika/nesne önbelleği kullanan kurulumlarda ve yüksek eşzamanlılıkta istenmeyen bir davranıştır ve önbellek invalidasyonu üretir. (b) Canlı merchant key dahil tüm NicePay seçenekleri her istekte (ön uç sayfaları dahil) `alloptions` içine yüklenir; `nicepay_saved_shortcodes` merchant çok sayıda yapılandırma kaydettikçe büyür ve her sayfa yüklemesinin bellek maliyetine eklenir.

**Öneri**

1) Okuma yolunda yazmayın; varsayılanları bellekte döndürüp kalıcılaştırmayı yalnızca admin bağlamına bırakın:
```php
$shortcodes = get_option( 'nicepay_saved_shortcodes', null );
if ( null === $shortcodes ) {
    $shortcodes = nicepay_get_default_presets();
    if ( is_admin() ) { update_option( 'nicepay_saved_shortcodes', $shortcodes, false ); }
}
```
2) Tüm `add_option()`/`update_option()` çağrılarında autoload'ı açıkça belirtin: kimlik bilgileri ve `nicepay_saved_shortcodes` için `false`, yalnızca `nicepay_mode`/`nicepay_enabled_methods` gibi her istekte gereken küçük değerler için `true`.

---

### PLAT-018 — Shortcode instance kimliği wp_rand() ile üretiliyor: aynı sayfadaki iki form çakışabilir

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | shortcode |
| **Konum** | [templates/standalone-payment-form.php:62](../../../templates/standalone-payment-form.php#L62) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
62: $form_id = 'nicepay-standalone-' . wp_rand( 1000, 9999 );
```
Bu değer sayfadaki tüm DOM id'lerinin ve radyo düğmesi `name` niteliğinin temelini oluşturuyor (`:74`, `:83`, `:89`, `:98`, `:106`, `:110`, `:128`, `:156`, `:161-174`, `:180`). Yalnızca 9000 olası değer var ve her shortcode instance'ı bağımsız çekiliş yapıyor.
````

**Başarısızlık senaryosu**

Bir bağış sayfasında 50₩ ve 100₩ için iki shortcode instance'ı vardır. Sayfa render edilirken her ikisi de `wp_rand(1000,9999)` ile 4712 çeker. Her iki formun `id`'si `nicepay-standalone-4712` olur; `startStandalone('nicepay-standalone-4712')` her zaman DOM'daki ilk formu bulur. Kullanıcı ikinci (100₩) düğmesine bassa da birinci formun (50₩) yapılandırma id'si ile AJAX başlatılır ve 50₩ ödenir.

**Etki**

Aynı sayfada iki `[nicepay_payment]` shortcode'u varsa ~%0.011 olasılıkla aynı `$form_id` üretilir; bu durumda iki `<form>` aynı `id`'yi paylaşır, `document.getElementById()` yanlış formu döndürür ve `assets/js/nicepay.js:379-382`'deki `name="payForm"` ataması yanlış forma yapılabilir — müşteri A yapılandırmasının düğmesine basıp B yapılandırmasının tutarını öder. Ayrıca rastgele id'ler, tam sayfa önbelleği (WP Rocket/Varnish) kullanan sitelerde her önbellek yenilemesinde değiştiği için harici CSS/JS hedeflemesini imkânsız kılar ve DOM anlık görüntülerini kararsızlaştırır.

**Öneri**

Rastgelelik yerine deterministik ve çakışmasız bir sayaç kullanın:
```php
global $nicepay_shortcode_instance;
$nicepay_shortcode_instance = isset( $nicepay_shortcode_instance ) ? $nicepay_shortcode_instance + 1 : 1;
$form_id = 'nicepay-standalone-' . sanitize_html_class( $config_id ) . '-' . $nicepay_shortcode_instance;
```
Böylece id hem benzersiz hem önbellek-kararlı hem de hata ayıklanabilir olur. `tests/js/nicepay-multi-instance.test.js`'e aynı `$form_id`'nin asla iki kez üretilmediğini doğrulayan bir vaka ekleyin.

---

### PLAT-019 — load_plugin_textdomain() plugins_loaded'da çalışıyor ve aktivasyon isteğinde hiç çalışmıyor; wp_die mesajları çevrilmiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | hook-hygiene |
| **Konum** | [nicepay-payment-gateway.php:93](../../../nicepay-payment-gateway.php#L93) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
93:        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
94:        add_action( 'plugins_loaded', array( $this, 'maybe_install_schema' ), 5 );
...
287:    public function load_textdomain() {
288:        load_plugin_textdomain( 'nicepay-payment-gateway', false, dirname( NICEPAY_PLUGIN_BASENAME ) . '/languages/' );
```
Aktivasyon yolu ise `plugins_loaded` çoktan geçtikten sonra çalışır:
```php
811: register_activation_hook( __FILE__, function ( $network_wide = false ) {
812:     $plugin = NicePay_Payment_Gateway::instance();
813:     $plugin->activate( (bool) $network_wide );
...
141:                    wp_die( esc_html( $result->get_error_message() ) );
150:            wp_die( esc_html( $result->get_error_message() ) );
```
`instance()` yapıcısı `init_hooks()`'u çağırır ama `plugins_loaded` geçtiği için `load_textdomain` hiç tetiklenmez; `maybe_install_schema` da priority 5 ile `load_textdomain`'in (priority 10) ÖNCE koşar.
````

**Başarısızlık senaryosu**

Korece bir sitede yönetici eklentiyi etkinleştirir; veritabanında mükerrer Moid olduğu için `maybe_install()` WP_Error döner ve `wp_die( 'NicePay could not upgrade ... ' )` İngilizce basılır. Korece konuşan yönetici ne yapması gerektiğini anlamaz ve sitenin bozulduğunu düşünür.

**Etki**

(a) Aktivasyon başarısız olduğunda yönetici, sitenin dili ne olursa olsun İngilizce bir `wp_die()` ekranı görür — Türkçe/Korece bir sitede kritik bir hata mesajı anlaşılmaz kalır. (b) `plugins_loaded` üzerinde textdomain yüklemek, WPML/Polylang gibi locale'i `plugins_loaded` üzerinde değiştiren eklentilerle yarış durumu yaratabilir ve WordPress 6.7+ yönergeleriyle uyumsuzdur (çeviriler `init`'te yüklenmelidir). (c) `maybe_install_schema` (priority 5) textdomain yüklenmeden önce çalışır — bugün `NicePay_Installer` içinde çeviri çağrısı yok (`grep -n "__(" includes/class-nicepay-installer.php` boş) ama ilk eklenen `__()` sessizce just-in-time yüklemeye düşer.

**Öneri**

1) Textdomain'i `init` üzerinde yükleyin (WP 6.7 yönergesi) — veya WordPress.org'dan dağıtılıyorsa çağrıyı tamamen kaldırıp otomatik yüklemeye güvenin.
2) Aktivasyon hatalarını `wp_die()` ile değil, aktivasyonu iptal etmeden bir `set_transient( 'nicepay_activation_error', ... )` + `admin_notices` ile bildirin; bu hem çeviriyi mümkün kılar (notice `init`'ten sonra basılır) hem de PLAT-006'daki yarı-durum sorununu azaltır.
3) `maybe_install_schema`'yı priority 5'ten `load_textdomain`'den sonraya (ör. 9) alın veya installer'ın hiçbir zaman çeviri kullanmayacağını bir yorum/test ile çivileyin.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İncelenen dosyalar (tamamı nihai hâliyle, cat -n / awk ile satır numaralı okundu): `nicepay-payment-gateway.php` (827 satır, tamamı), `includes/class-nicepay-gateway.php` (919 satır, tamamı), `includes/class-nicepay-blocks-integration.php` (76), `assets/js/nicepay-blocks.js` (51), `tests/unit/NicePayBlocksIntegrationTest.php` (157), `tests/fixtures/blocks-abstract-payment-method-type.php` (18), `tests/integration/woocommerce-smoke.php` (253), `tests/integration/run-woocommerce-smoke.sh` (108), `templates/payment-form.php` + `templates/standalone-payment-form.php` (tamamı), `includes/class-nicepay-return-handler.php` (1-120 ve 230-374), `admin/class-nicepay-admin.php` (1-480 ve 1127-1199), `readme.txt` (tamamı), `.github/workflows/tests.yml`, `eslint.config.js`, `.github/scripts/check-js.js`, `includes/class-nicepay-retention.php` (1-60, 100-180, 279-290) ve `includes/nicepay-functions.php`'nin ilgili bölümleri (290-330, 548-590, 994-1024, 1140-1172, 1332-1341, 1396-1451, 1656-1663, 1900-1956).

Doğrulama için koşulan taramalar: `grep -rn "get_post_meta|update_post_meta|delete_post_meta|'shop_order'|post_type|get_posts|WP_Query" includes admin templates nicepay-payment-gateway.php` (boş → HPOS CRUD kullanımı doğrulandı); `grep -rn "%i" includes admin` (boş → WP 6.2+ placeholder kullanımı yok); `grep -rn "do_action" ...` (boş → uzantı noktası yok); `grep -rn "DISABLE_WP_CRON|wp_doing_cron" ...` (boş); `grep -n "nicepay-order-payment-summary" assets/css/*.css` (boş).

`sanitize_hex_color()`'ın WP 5.8'de ön uçta mevcut olup olmadığı iddia edilmeden önce WordPress 5.8 etiketinden `wp-includes/formatting.php` indirilip doğrulandı (satır 5990'da tanımlı, `wp-settings.php`'de her istekte yükleniyor) — bu nedenle bir bulgu olarak raporlanmadı.

İNCELENEMEYEN / SINIRLI: Gerçek bir WordPress + WooCommerce kurulumu bu ortamda mevcut değil; PHP yorumlayıcısı da yok (`php -l` koşulamadı). Bu nedenle çalışma zamanı davranışları (Blocks checkout'ta gerçek görünüm, WooCommerce FeaturesController'ın 'uncertain' eklentiyi editörde nasıl gösterdiği, `wc_add_notice`'ın gerçek oturum davranışı) kod ve WordPress/WooCommerce çekirdek sözleşmesi bilgisiyle akıl yürütülerek değerlendirildi; PLAT-009 ve PLAT-005 bu nedenle `medium` güvenle işaretlendi. `admin/class-nicepay-transactions.php`'nin tamamı, `assets/js/nicepay-admin.js`, `assets/js/nicepay-shortcode-admin.js`, `includes/class-nicepay-api.php`, `class-nicepay-installer.php`/`-transaction-schema.php`/`-inbound-validator.php`/`-offer-resolver.php`/`-privacy.php` yalnızca bu boyutla kesişen noktalarda (hook kaydı, seçenek/cron kullanımı, kapasite kontrolü) hedefli olarak incelendi; protokol, para doğruluğu ve güvenlik boyutları kapsam dışıdır. `docs/analysis/*` dosyaları kanıt olarak kullanılmadı, yalnızca `readme.txt`/`docs/DEVELOPER-GUIDE.md` kod-doküman uyumu için karşılaştırıldı.

**Açık sorular**

- Checkout Blocks adaptörü bilinçli olarak sevk edilip uyumluluğu beyan edilmiyorsa, blok tabanlı checkout kullanan bir mağazada ürün ekibinin beklediği davranış tam olarak nedir — yöntem görünsün mü, yoksa gizlensin mi? (PLAT-003 iki farklı çözüm öneriyor, ikisi de tutarlı ama farklı ürün kararları.)
- `WC requires at least: 5.0` ve `Requires at least: 5.8` beyanları gerçek bir hedef mi, yoksa varsayılan mı? HPOS beyanı zaten WooCommerce 7.1+ gerektiriyor; minimumlar yükseltilirse PLAT-012'nin CI matris maliyeti ciddi biçimde düşer.
- Standalone akışın sonuç sayfası bilinçli olarak temadan yalıtılmış mı (ör. tema JS'inin ödeme sayfasına karışmasını önlemek için)? Öyleyse bu karar dokümante edilmeli; değilse PLAT-011'deki şablon/kanca çözümü uygulanmalı.
- NICEPAY dönüş POST'unun tarayıcıdan mı yoksa NICEPAY sunucusundan mı geldiği (server-to-server callback var mı?) — PLAT-009'un etkisi buna göre değişir; sunucudan geliyorsa oturum/çerez hiç yoktur ve `wc_add_notice` çağrıları tümüyle etkisizdir.

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 19 |

## Öncelikli aksiyon listesi

1. **PLAT-001** — Her `failure` dönüşünden önce nedeni bildir; tercihen istisna fırlat:
```php
if ( ! $order || ! $this->is_available() || 'nicepay' !== $order->get_payment_method() ) {
    throw new Exception( esc_html__( 'NicePay is not available for this order right now. Please choose another payment method.', 'nicepay-payment-gateway' ) );
}
if ( ! $order->needs_payment() ) {
    throw new Exception( esc_html__
2. **PLAT-002** — 1) Klasik checkout için `validate_fields()` uygulayın:
```php
public function validate_fields() {
    $buyer = nicepay_validate_buyer_fields(
        trim( (string) ( $_POST['billing_first_name'] ?? '' ) . ' ' . (string) ( $_POST['billing_last_name'] ?? '' ) ),
        (string) ( $_POST['billing_email'] ?? '' ),
        (string) ( $_POST['billing_phone'] ?? '' )
    );
    if ( is_wp_error( $buyer
