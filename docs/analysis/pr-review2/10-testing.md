# 10 — Test Stratejisi ve Kalitesi

> PR #3 sistematik review · düşmanca doğrulamalı · 18 bulgu

## Özet

Suite'in iskeleti ciddi: 20 unit dosyası / 264 test metodu (data-provider genişlemesiyle ~412 vaka), 3 jsdom JS testi ve gerçek MariaDB + gerçek WooCommerce (legacy/HPOS matrisi) üzerinde koşan iki entegrasyon senaryosu var; CI beş PHP sürümünde koşuyor ve `package` job'ı dört gate'e bağlı. İmza testleri NICEPAY dokümanından alınan literal golden digest'lere dayanıyor (altısını da bağımsız olarak yeniden hesapladım, hepsi doğru) — tautolojik değil. Inbound validator, transport ve URL politikası testleri gerçekten negatif senaryo yüklü. Buna karşılık kapsam dağılımı ters: en yüksek riskli ~1.500 satır — `class-nicepay-return-handler.php` (374 satır), `WC_Gateway_NicePay::handle_return()` (313 satır), `receipt_page()/generate_payment_form()` ve nonce korumalı dört AJAX/admin giriş noktası — sıfır test görüyor; buna karşılık kart/banka adı sözlüğü için ~20 tek-assertion'lık test var. `process_refund()`'ın transport sonrası her hata dalı (WP_Error, binding uyuşmazlığı, red, ledger yazamama, tam iade) test edilmemiş. Dahası, "SQL injection güvenli" ve "escape edilmiş" iddiaları `addslashes` tabanlı sahte `$wpdb->prepare()`'lere veya doğrudan dosya metni grep'ine dayanıyor; 8 test metodu üretim kodunu hiç çalıştırmadan `file_get_contents` + `assertStringContainsString` yapıyor. Coverage yapılandırması `admin/`, kök eklenti dosyası ve `templates/`'i tamamen kapsam dışı bırakıyor, eşik de yok — yani raporlanan kapsam sistematik olarak şişik.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **C** |
| 🔴 Kritik | 1 |
| 🟠 Yüksek | 3 |
| 🟡 Orta | 13 |
| 🔵 Düşük | 1 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 4 |
| Bağımsız doğrulama kararı alan bulgu | 7 / 18 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 16 |

## Güçlü yönler

- İmza sözleşme testleri gerçek golden vektörlerle: `tests/unit/NicePaySignatureIntegrationTest.php` ve `NicePayApiTest.php` beklenen digest'i üretim fonksiyonuyla üretmiyor, NICEPAY v2.0.8 dokümanından alınan literal hex'leri kullanıyor. Altı digest'i de (9.1/9.2/9.3/9.4/9.7 ve fixed-width 000000001004 varyantı) bağımsız olarak Python ile yeniden hesapladım — hepsi tutuyor.
- `tests/integration/schema-migration.php` gerçek bir MariaDB'de çalışıyor ve gerçekten değerli şeyler kanıtlıyor: UNIQUE `active_attempt_key` kilidinin ikinci eşzamanlı formu reddettiği (78-101), approval claim'in kardeş satırları atomik olarak `abandoned` yaptığı (105-129), ikinci refund rezervasyonunun reddedildiği (151-152), `EXPLAIN`'in `idx_created_at`'i seçtiği (162-163) ve retention'ın `needs_reconciliation` + `unknown` refund'lu satırları koruduğu (268-276).
- `tests/integration/woocommerce-smoke.php` aynı sözleşmeyi hem legacy post store hem HPOS'ta koşuyor, `pre_http_request` ile tüm dış çıkışı bloklayıp tam `wc_create_refund(refund_payment:true)` yaşam döngüsünü gerçek WooCommerce sırasıyla çalıştırıyor (204-218) ve sonunda tam olarak bir HTTP isteği yapıldığını doğruluyor (243).
- `NicePayInboundValidatorTest` gerçek bir negatif matris: ucuz imzanın pahalı siparişe yönlendirilmesi (81), order-swap (93), flow ayrımı (105), MID/method/currency/AuthResultCode/signature binding (163-172), replay durumları (196-203), offer expiry (205) ve 10 zorunlu protokol alanının her biri (259-266).
- `NicePayTransportTest` transport sınırını sıkı bağlıyor: `timeout/redirection/sslverify/Content-Type` politikası, non-2xx ve bozuk JSON'ın fail-closed olması, net-cancel'ın TID+Amt'e bağlanması, geçerli imzalı **farklı** tutarın reddi (290-303) ve `request_cancel`'ın extra params ile TID/MID/Moid/CancelAmt/SignData'yı override edememesi (411-433).
- CSV export testi gerçek bir `php://temp` stream'e yazıp `fgetcsv` ile okuyor; formül enjeksiyonu (`=`, `+`, `@` öneki), PII/kimlik bilgisi sütunlarının hem çıktıdan hem SQL'den yokluğu ve keyset pagination doğrulanıyor (`NicePayAdminOperationsTest.php:248-317`).
- `tests/js/nicepay-multi-instance.test.js` gerçekten davranışsal: iki shortcode instance'ının izolasyonu, aktif PG oturumu varken ikinci formun bloklanması, UTF-8 byte limiti ihlalinde odak yönetimi ve sağlayıcı yanıtındaki harici `return_url`'in reddi (146-157).
- CI gate'leri gerçekten kırıcı: `set -euo pipefail` + `trap cleanup EXIT` iki entegrasyon script'inde, `wp eval-file` içindeki `RuntimeException` çıkış kodunu bozuyor ve `package` job'ı `needs: [test, lint, database-integration, woocommerce-integration]` ile bunlara bağlı.
- `phpunit.xml` `failOnRisky="true"` ve `failOnWarning="true"` içeriyor; PHP matrisi 7.4/8.0/8.1/8.2/8.3'ün tamamını `fail-fast: false` ile koşuyor.

## Bulgular

### TEST-001 — Ödeme akışının en kritik ~1.500 satırı (return handler, handle_return, ödeme formu, tüm AJAX giriş noktaları) tamamen test edilmemiş

| | |
|---|---|
| **Severity** | 🔴 Kritik |
| **Kategori** | coverage-gap |
| **Konum** | [includes/class-nicepay-return-handler.php:25](../../../includes/class-nicepay-return-handler.php#L25) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
`grep -rn "handle_return|Return_Handler|generate_payment_form|receipt_page|recover_paid_order|ajax_init_payment|handle_payment_return|handle_payment_receipt" tests/` sadece iki eşleşme döndürüyor ve ikisi de `process_payment` (tests/integration/woocommerce-smoke.php:126-129). Test edilmeyen üretim yüzeyleri: `NicePay_Return_Handler::process()` (class-nicepay-return-handler.php:25-227, standalone ödemenin tüm auth-return→approval→ledger yolu), `WC_Gateway_NicePay::handle_return()` (class-nicepay-gateway.php:376-689), `receipt_page()` (:136), `recover_paid_order_from_ledger()` (:173), `generate_payment_form()` (:210), ve nicepay-payment-gateway.php:355/369/532/655/770. Bunlar `nicepay_claim_transaction_for_approval` → `request_approval` → `payment_complete` orkestrasyonunu, sipariş snapshot yeniden doğrulamasını ve her hata yolundaki `nicepay_abort_authenticated_payment` çağrısını içeriyor. Parçalar (validator, claim SQL, transport) ayrı ayrı test edilmiş ama bunları BİRLEŞTİREN kod hiç çalıştırılmamış.
```

**Başarısızlık senaryosu**

Bir refactor sırasında `handle_return()` içinde `nicepay_claim_transaction_for_approval()` çağrısı `NicePay_Inbound_Validator::validate_auth_return()`'dan ÖNCEye taşınır (veya erken `return` ile atlanır). Tüm 412 unit test ve iki entegrasyon senaryosu yeşil kalır; üretimde aynı Moid için iki eşzamanlı tarayıcı POST'u iki `request_approval()` çağrısı üretir ve müşteri iki kez çekilir.

**Etki**

Doğrulama, claim, transport ve ledger yazımı arasındaki sıralama hataları (ör. claim alınmadan approval gönderilmesi, hata dalında net-cancel'ın çağrılmaması, snapshot yeniden doğrulaması başarısızken `abort` yerine `payment_complete` çağrılması) hiçbir testte yakalanamaz. 313 satırlık `handle_return()` içinde tek bir yanlış `return`/`break` sessizce üretime gider.

**Öneri**

`handle_return()` ve `NicePay_Return_Handler::process()` için `$_POST`, `$wpdb` fake'i, kuyruklanmış `wp_remote_post` yanıtı ve WC_Order fake'i ile senaryo testleri yaz. En az şu vakalar: (a) mutlu yol → ledger `paid` + `payment_complete()` çağrıldı + `active_attempt_key = null`; (b) validator hatası → hiç HTTP isteği yok ve `nicepay_abort_authenticated_payment()` çağrıldı; (c) claim başarısız (`$wpdb->query_result = 0`) → approval transport'una hiç gidilmedi; (d) approval `ResultCode` başarısız → net-cancel denendi, sipariş `on-hold`; (e) approval sonrası sipariş total'i değişmiş → abort + `needs_reconciliation`; (f) aynı Moid ile ikinci POST → `nicepay_inbound_replay`. `wp_die`/`exit` çağrılarını test edilebilir kılmak için `NicePayAdminOperationsTest.php:79-84`'teki gibi exception fırlatan bir `wp_die` stub'ı kullan.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: Adversarial verification tarafında iddiayı çürütecek hiçbir kanıt bulamadım; aksine, iddia edilenden biraz DAHA kötü bir tablo çıktı.

1) KONUM DOĞRULAMASI — hepsi tam isabetli. `grep -n "function "` çıktısı iddia edilen satırların tamamını birebir doğruluyor: `class-nicepay-return-handler.php:25` (`process()`, 25-227 — 227'de kapanıyor, 234'te `render_saved_receipt` başlıyor), `class-nicepay-gateway.php:136` (`receipt_page`), `:173` (`recover_paid_order_from_ledger`), `:210` (`generate_payment_form`), `:376` (`handle_return`, 689'da kapanıyor = 313 satır, tam olarak iddia edildiği gibi), `nicepay-payment-gateway.php:355/369/532/655/770`, `admin/class-nicepay-transactions.php:969`.

2) ÇÜRÜTME DENEMESİ — "başka bir yerde test var mı?" İddiadaki grep'i birebir çalıştırdım: `tests/` altında SIFIR eşleşme (iddia "iki eşleşme" diyor; o iki eşleşme aslında `process_payment` — ki grep desenine dahil değil. Yani iddianın kanıt cümlesi hafif yanlış ama YANLIŞ YÖNDE DEĞİL: gerçek kapsam iddia edilenden de az). Sonra isimle yakalanmayacak dolaylı kapsamı aradım: (a) `WC_Gateway_NicePay`'i yükleyen 3 unit test var ama hepsi başka yüzeyi test ediyor — `NicePayRefundTest` (`process_refund`), `NicePayGatewayDefaultsTest` (ayarlar), `NicePayBlocksIntegrationTest` (`newInstanceWithoutConstructor` + Blocks). (b) Reflection ile private metod çağrısı yok. (c) `.github/workflows/` altında e2e/cypress/playwright yok. (d) `NicePayTransactionsAdminTest` yalnızca detay alanı render/escape testi yapıyor, `ajax_cancel_transaction`'a hiç girmiyor.

3) EN GÜÇLÜ KANIT — entegrasyon smoke testi return yolunu AÇIKÇA ATLIYOR. `tests/integration/woocommerce-smoke.php:170` civarında ledger'a `'status' => 'paid'`, `'approval_state' => 'approved'`, `'active_attempt_key' => null` elle yazılıyor ve hemen ardından `$reloaded_order->payment_complete( $tid )` doğrudan çağrılıyor. Yani `handle_return()`'ün ürettiği sonuç durumu test tarafından taklit ediliyor — o durumu ÜRETEN kod hiç çalıştırılmıyor. Bu, iddianın "parçalar test edilmiş ama onları BİRLEŞTİREN kod hiç çalıştırılmamış" tezinin birebir kanıtı.

4) ORKESTRASYONUN GERÇEKTEN ORADA OLDUĞU — `handle_return()` içinde sıra: validator (`:421`) → claim (`:434`) → sipariş snapshot yeniden doğrulama + abort dalları (`:447`, `:465`, `:493`) → `request_approval` (`:511`) → binding uyuşmazlığında `request_net_cancel` (`:551`) → `abort` (`:607`) → `payment_complete` (`:639`). Bu sıralamanın hiçbir adımı testle sabitlenmemiş. Buna karşılık parçalar ayrı ayrı test EDİLMİŞ (`NicePayTransactionRepositoryTest.php:117/129/130/136` claim SQL, `:244/260` abort, `NicePayInboundValidatorTest.php:193` replay) — bu da iddianın "parçalar var, birleştirme yok" ayrımını doğruluyor.

5) AYNI KÖK NEDEN, EK KANIT — shortcode tarafında da aynı desen: `NicePayFunctionsTest.php:396-455` yardımcı fonksiyonları (`nicepay_get_all_shortcodes`, `nicepay_prepare_shortcodes_for_storage`) test ediyor, ama `ajax_save_shortcode` (:655) / `ajax_delete_shortcode` (:770) handler'ları hiç çağrılmıyor.

Severity: bir kapsam boşluğu tek başına canlı bir defect değil, ama bu PR'ın adı "release readiness" ve test edilmeyen yüzey tam olarak çift-çekim/kayıp-ödeme hatalarının yaşadığı yer (KRW, ondalıksız, geri alınamaz çekim). `critical` savunulabilir; düşürmüyorum.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `critical` → `high`
- Düzeltilmiş iddia: Ödeme yaşam döngüsünün en yüksek sonuçlu ~1.000-1.300 satırı için SIFIR davranışsal test var: `WC_Gateway_NicePay::handle_return()` (includes/class-nicepay-gateway.php:376-689, 314 satır), `NicePay_Return_Handler::process()` (includes/class-nicepay-return-handler.php:25-227, 203 satır), `receipt_page()` (:136), `recover_paid_order_from_ledger()` (:173), `generate_payment_form()` (:210) ve tüm AJAX giriş noktaları (nicepay-payment-gateway.php:355/369/532, admin/class-nicepay-transactions.php:969) hiçbir testte çağrılmıyor — grep 0 eşleşme döndürüyor. Boşluk ölçüm tarafında da mevcut: phpunit.xml coverage <include> yalnızca `includes` dizinini kapsadığı için admin/ ve kök eklenti dosyası kapsama raporunda hiç görünmüyor. Parçalar (NicePayInboundValidatorTest, NicePayTransportTest, NicePayTransactionRepositoryTest) ayrı ayrı test edilmiş; onları validate -> claim -> approval -> binding -> ledger -> payment_complete sırasında birleştiren orkestrasyon kodu hiç çalıştırılmamış.

DÜZELTİLMİŞ RİSK İFADESİ (orijinal senaryo üretilebilir değil): Tehlike, "claim'in validator önüne taşınması" DEĞİL — bu satır 434'te `$transaction->id` üzerinden satır 421'e veri-bağımlı olduğu için imkansız. Gerçekten sessizce geçebilecek ve hiçbir testin yakalamayacağı regresyonlar şunlar: (a) `active_attempt_key => null` serbest bırakmasının (:650) `payment_complete()` (:628) ÖNÜNE taşınması -> araya giren bir crash aynı yakalanmış sipariş için ikinci ödenebilir deneme açar; (b) snapshot yeniden doğrulama koşullarından birinin (:494-500) refactor sırasında düşürülmesi -> yetkilendirme sonrası tutarı değişen sipariş `abort` yerine `payment_complete` ile kapatılır; (c) hata dallarından birinde `nicepay_abort_authenticated_payment()`/`request_net_cancel()` çağrısının kaybolması -> müşteride asılı kalan bir yetkilendirme hold'u. Bunların üçü de mevcut 20 unit test dosyası ve iki entegrasyon senaryosu tamamen yeşilken üretime gidebilir.
- Gerekçe: ÇEKİRDEK İDDİA DOĞRU VE BAĞIMSIZ OLARAK DOĞRULANDI. Kendi grep'imi çalıştırdım; iddianın gösterdiği desen tests/ altında SIFIR eşleşme döndürüyor. handle_return, NicePay_Return_Handler, receipt_page, recover_paid_order_from_ledger, generate_payment_form ve hiçbir ajax_* giriş noktası tek bir testte bile çağrılmıyor veya kaynak-düzeyi assert ile kontrol edilmiyor. Gösterilen tüm satır numaraları dosyaların şu anki haliyle birebir eşleşiyor. Gap ayrıca iddianın söylediğinden DAHA GENİŞ: phpunit.xml'in coverage <include> bloğu yalnızca `includes` dizinini kapsıyor, dolayısıyla admin/ ve kök nicepay-payment-gateway.php (belirtilen 4 giriş noktasının bulunduğu yer) ölçülen kapsama alanının tamamen dışında.

ANCAK LENS (SONUÇ VE İSTİSMAR EDİLEBİLİRLİK) ALTINDA İKİ CİDDİ KUSUR VAR:

1) failure_scenario ÜRETİLEBİLİR DEĞİL. Senaryo, `nicepay_claim_transaction_for_approval()` çağrısının `validate_auth_return()` ÖNCESİne taşınmasını varsayıyor. Bu refactor fiziksel olarak imkansız: claim çağrısının tek argümanı `$transaction->id` ve `$transaction` tam da validator'ın dönüş değeri (gateway:421 -> :434). Taşıma denemesi tanımsız değişken/fatal üretir, sessiz bir regresyon değil. Dahası nedensellik zinciri de tutmuyor: karşılıklı dışlamayı sağlayan şey validator değil, claim'in kendi atomik SQL'i; claim'i öne almak iki `request_approval()` çağrısı üretmez. Parantez içindeki alternatif ("erken return ile atlanması") de çift çekim üretmez, çünkü `request_approval()` (:511) claim'den SONRA geliyor — claim'i atlayan erken bir return approval'a hiç ulaşmaz. Yani "müşteri iki kez çekilir" sonucu, tarif edilen değişiklikten çıkmıyor.

2) SEVERITY ABARTILMIŞ. Bugün tetiklenebilir bir kusur yok; okuduğum handle_return gövdesi aksine oldukça disiplinli (claim kapısı, snapshot yeniden doğrulaması, her hata dalında net-cancel/abort, payment_complete etrafında Throwable yakalama, active_attempt_key'in payment_complete'ten SONRA serbest bırakılması). Zarar, varsayımsal bir gelecek düzenleme gerektiriyor. `critical` mevcut ve gerçekleşebilir zarar ima eder; burada durum "en yüksek sonuçlu para yolunda sıfır davranışsal test" — gerçek ve ciddi, ama `high`.

KÜÇÜK HATALAR: (a) kanıt metni kendi içinde tutarsız — alıntılanan grep "iki eşleşme, ikisi de process_payment" diyor ama process_payment desende hiç yok; gerçek sonuç sıfır eşleşme (iddiayı zayıflatmıyor, güçlendiriyor, ama kanıt özensiz). (b) "~1.500 satır" yumuşak: handle_return 314 (376-689), Return_Handler::process 203 (25-227), gateway 136-372 arası 237 satır; tüm AJAX handler'lar dahil edilirse ~1.500'e yaklaşılır, ama sayı türetilmemiş. (c) öneride referans verilen wp_die stub'ı NicePayAdminOperationsTest.php:80-86'da, 79-84'te değil.

---

### TEST-002 — process_refund()'ın transport sonrası hata dallarının tamamı test edilmemiş — paranın kaybolduğu yer

| | |
|---|---|
| **Severity** | 🟠 Yüksek *(doğrulama sonrası; ilk değer 🔴 Kritik)* |
| **Kategori** | coverage-gap |
| **Konum** | [includes/class-nicepay-gateway.php:807](../../../includes/class-nicepay-gateway.php#L807) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
`tests/unit/NicePayRefundTest.php` 9 test içeriyor; hepsi ya transport öncesi kapıları (CELLPHONE/cc_part_cl/clickpay_cl/claim conflict/state) ya da tek mutlu yolu (`test_verified_partial_refund_completes_the_exact_reserved_attempt`, satır 163) kapsıyor. Şu dallar hiçbir testte çalıştırılmıyor: (1) `if ( is_wp_error( $result ) )` → `cancel_status='unknown'` + `reconciliation_status='required'` + sipariş meta `_nicepay_refund_reconciliation_required` (807-828); (2) `is_cancel_success` true ama `$binding_matches` false → `nicepay_refund_response_mismatch` (881-898); (3) sağlayıcı reddi (`2001/2211` dışı kod) → `cancel_status='rejected'` (900-917); (4) `!$ledger_updated || !$attempt_updated` post-confirm eskalasyonu (865-876); (5) tam iade (`$is_partial = false` → `status='refunded'`) — hem unit hem entegrasyon testi yalnızca 500/1000 kısmi iade yapıyor (woocommerce-smoke.php:186); (6) `nicepay_refund_context_error` MID/mode uyuşmazlığı (716-721); (7) `nicepay_refund_audit_error` + `nicepay_release_unsent_refund_claim()` (798-803); (8) `$amount = null` varsayılan tam bakiye yolu (741-742).
```

**Başarısızlık senaryosu**

İade isteği zaman aşımına uğrar (`wp_remote_post` WP_Error döner). Bir regresyon `nicepay_update_transaction(..., true)` çağrısındaki strict bayrağını kaldırır veya `cancel_status='unknown'` yazımını atlar; ledger `cancel_status` boş kalır. Mevcut test suite yeşildir. Operatör iadeyi tekrar dener, `nicepay_claim_transaction_for_refund` engellemez (çünkü `cancel_status` 'requested'/'unknown' değil) ve müşteriye ikinci kez para iadesi yapılır.

**Etki**

İade akışının kapanış yarısı — yani PG'ye para talebi gittikten SONRA olan her şey — hiç doğrulanmamış. Bu tam olarak çift iade, kayıp iade ve mutabakatsız bakiye risklerinin yaşandığı bölge.

**Öneri**

`NicePayRefundTest`'e altı test daha ekle. `$wp_remote_post_test_queue` boş bırakarak (stub WP_Error döner) timeout dalını; `cancelResponse('400')` kuyruklayarak binding mismatch dalını; `ResultCode => '3001'` ile red dalını; `$wpdb->update_result = 0` ile post-confirm eskalasyonunu; `$nicepay_refund_test_order->total_refunded = '1000'` + `process_refund(42, '1000', ...)` ile tam iade dalını (`assertSame('refunded', $wpdb->update_data['status'])` ve `$wp_remote_post_test_requests[0]['args']['body']` üzerinden partial=false gönderildiğini doğrula); `process_refund(42, null, ...)` ile varsayılan tam bakiye dalını test et.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: process_refund()'ın transport SONRASI dallarının çoğu test edilmemiş. NicePayRefundTest.php'deki 9 testten yalnızca ikisi (satır 163 ve 189) request_cancel() çağrısını geçiyor ve ikisi de aynı mutlu yolu (kısmi iade → 'partially_refunded') izliyor. Hiçbir testte çalıştırılmayan GERÇEKTEN ERİŞİLEBİLİR dallar: (1) transport hatası → 'unknown' + reconciliation_required (807-828); (3) sağlayıcı reddi, 2001/2211 dışı ResultCode → 'rejected' (900-917); (4) post-confirm ledger/audit eskalasyonu (865-876); (5) tam iade → status='refunded' (853'teki üçlü operatörün 'refunded' yarısı; hem unit hem woocommerce-smoke.php:186 yalnızca 500/1000 kısmi iade yapıyor); (6) nicepay_refund_context_error MID/mode uyuşmazlığı (720); (7) nicepay_refund_audit_error + nicepay_release_unsent_refund_claim (798-803); (8) $amount = null varsayılan tam bakiye yolu (741-742).

DÜZELTME: Orijinal iddianın (2) numaralı maddesi — 881-898'deki binding mismatch dalı — bir kapsam boşluğu DEĞİL, üretimde erişilemeyen savunma kodudur. NicePay_API::request_cancel() aynı TID+tutar bağlamasını class-nicepay-api.php:629-632'de zaten uyguluyor ve uyuşmazlıkta WP_Error('nicepay_cancel_binding_error') döndürüyor; $this->api ise class-nicepay-gateway.php:31'de filtresiz olarak sabit kurulduğu için gateway asla uyuşmayan bir dizi göremez. Dolayısıyla önerideki "cancelResponse('400') ile binding mismatch dalını test et" tarifi HATALIDIR: o test 881'e değil 807'ye düşer ve önerilen timeout testini sessizce tekrarlar. Bu dal için doğru aksiyon, ya bir API test seam'i (enjekte edilebilir $api) eklemek ya da dalı erişilemez-savunma olarak yorumlamak/kaldırmaktır — kör bir fixture yazmak değil.

Severity: coverage-gap olduğu, bugün canlı bir kusur bulunmadığı (başarısızlık senaryosu açıkça varsayımsal bir gelecek regresyonu) ve listelenen sekiz daldan birinin ölü kod olduğu için critical değil high.
- Gerekçe: The core coverage claim is TRUE and every line number is exact. `tests/unit/NicePayRefundTest.php` contains exactly 9 test methods (lines 140, 152, 163, 178, 189, 204, 216, 227, 238). Of those, only two (163, 189) get past `$this->api->request_cancel()`, and both take the same happy path (`is_cancel_success && $binding_matches` → `partially_refunded`). I grepped the entire `tests/` tree for `process_refund`, `nicepay_refund_context_error`, `nicepay_refund_audit_error`, `nicepay_refund_response_mismatch`, `nicepay_refund_reconciliation_required`, `nicepay_release_unsent_refund_claim` — zero hits outside `NicePayRefundTest.php`. The integration smoke test does exactly one 500-of-1000 partial refund. So sub-items (1), (3), (4), (5), (6), (7), (8) are all genuinely untested, and the claim's cite of `tests/integration/woocommerce-smoke.php:186` (`$cancel_amount = '500';`) is precisely right.

TWO CORRECTIONS, both of which the reviewer got wrong:

(A) Sub-item (2) — the `binding mismatch → unknown` branch at `includes/class-nicepay-gateway.php:881-898` — is UNREACHABLE through the production code path, so it is defensive dead code rather than a testable gap. `NicePay_API::request_cancel()` already enforces the identical TID+amount binding at `includes/class-nicepay-api.php:629-632` and returns `WP_Error('nicepay_cancel_binding_error')`. `$this->api` is hard-constructed at `class-nicepay-gateway.php:31` (`$this->api = new NicePay_API();`) with no filter, setter, or injection seam anywhere in the file. Therefore the gateway can never receive an array whose TID/CancelAmt disagrees with the request.

(B) Consequently the recommendation "`cancelResponse('400')` ile binding mismatch dalını" is factually incorrect. I traced it: `cancelResponse('400')` computes `Signature = hash('sha256', $tid . MID . '400' . KEY)`, which passes `verify_cancel_signature()` (`class-nicepay-api.php:151-158`) — but then fails `hash_equals( $cancel_amt, $response_amount )` at `class-nicepay-api.php:629-631` and returns a WP_Error. That test would land on branch 807 (the timeout/unknown branch), NOT 881, and would therefore silently duplicate the proposed timeout test while claiming to cover a different branch. That is exactly the kind of false-confidence test the finding is arguing against.

The other repro recipes DO check out: an empty `$wp_remote_post_test_queue` returns `WP_Error('stub', ...)` per `tests/bootstrap/wp-stubs.php:266` → hits 807. `ResultCode => '3001'` with a matching amount passes the API binding gate and fails `is_cancel_success()` (`class-nicepay-api.php:660-662`, only `2001`/`2211`) → hits 900. `$wpdb->update_result = 0` makes `nicepay_complete_transaction_refund()` fail (it uses `$wpdb->update`, `includes/nicepay-functions.php:896-906`) while leaving the CAS claim, which uses `$wpdb->query`, intact → hits 865.

Severity: "critical" is overstated. This is a coverage gap, not a live defect — the failure scenario is an explicitly hypothetical future regression ("Bir regresyon ... strict bayrağını kaldırır"), and the branches as written today are correct. One of the eight listed branches is dead code. `high` is the honest grade for an untested money-movement close-out path.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: process_refund()'ın transport SONRASI dallarının hiçbiri test edilmemiş (timeout→unknown 807-828, post-confirm eskalasyonu 865-876, binding mismatch 881-898, sağlayıcı reddi 900-917) ve bunlara ek olarak transport öncesi iki kapı (context_error 716-721, audit_error 798-803) ile iki parametre yolu (tam iade `$is_partial=false` → status='refunded' + PartialCancelCode='0', ve `$amount=null` varsayılan tam bakiye 741-742) de hiçbir unit veya entegrasyon testinde çalıştırılmıyor. 9 unit testin tamamı ya transport öncesi kapıları ya da tek kısmi mutlu yolu kapsıyor; entegrasyon smoke testi de yalnızca 500 KRW kısmi mutlu yolu koşuyor. Bu, ödeme eklentisinin para-kritik hata kapanış yarısının regresyon koruması olmadığı anlamına gelir. ANCAK: bu bugün istismar edilebilir bir açık değil — söz konusu dalların kodu okunduğunda doğru yazılmış durumda ve raporun tarif ettiği çift-iade zinciri kod tarafından üretilemiyor, çünkü `nicepay_claim_transaction_for_refund` transport'tan önce `cancel_status='requested'` yazıyor (nicepay-functions.php:732) ve hem gateway kapısı (712) hem claim SQL'i (737) 'requested' durumundaki bir satır için ikinci iadeyi zaten reddediyor. Gerçek risk, gelecekteki bir regresyonun sessizce geçmesidir; somut çift-iade senaryosu için `nicepay_release_unsent_refund_claim`'in yanlışlıkla timeout dalında çağrılması gibi ayrı bir regresyon gerekir.
- Gerekçe: KANIT DOĞRU, SENARYO YANLIŞ, SEVERITY ABARTILMIŞ.

1) Kapsam boşluğu iddiası birebir doğrulandı. `tests/unit/NicePayRefundTest.php` tam olarak 9 test içeriyor (satır 140, 152, 163, 178, 189, 204, 216, 227, 238). `grep -rn "process_refund" tests/ admin/ includes/` sonucuna göre metodun TEK çağrı yeri bu dosya (8 çağrı) ve dolaylı olarak `tests/integration/woocommerce-smoke.php:204` (`wc_create_refund`, amount='500'). Listelenen 8 dalın hiçbirinde test yok; `grep -rn "context_error|audit_error|response_mismatch|reconciliation_required|'refunded'|update_result = 0" tests/` her iki test dosyasında da SIFIR eşleşme veriyor; kuyruğa alınan tek yanıtta `ResultCode => '2001'` var, yani ne red (3001) ne mismatch ne timeout dalı çalışıyor. Tüm satır referansları (694, 716-721, 741-742, 798-803, 807-828, 865-876, 881-898, 900-917) dosyanın şu anki haliyle eşleşiyor.

2) ANCAK failure_scenario'nun nedensellik zinciri koda aykırı. İddia "cancel_status boş kalır → nicepay_claim_transaction_for_refund engellemez → çift iade" diyor. Kod bunu üretmiyor: transport'tan ÖNCE `nicepay_claim_transaction_for_refund` zaten `SET cancel_status = 'requested'` yazıyor (nicepay-functions.php:732). Timeout dalındaki `cancel_status='unknown'` yazımı bir regresyonla atlansa bile satırda 'requested' KALIR — ve hem `process_refund`'ın giriş kapısı (class-nicepay-gateway.php:712, `in_array( $cancel_status, array( 'requested', 'unknown' ), true )`) hem de claim SQL'i (nicepay-functions.php:737, `AND cancel_status NOT IN ('requested','unknown')`) 'requested'i reddediyor. Yani tarif edilen tek regresyon çift iadeye yol açmaz; bunun için `nicepay_release_unsent_refund_claim`'i yanlışlıkla timeout dalında çağıran bambaşka (ve daha zorlama) bir regresyon gerekir. Ayrıca "strict bayrağını kaldırır" varyantı da yanlış: `true` strict bayrağı `cancel_status` alanının yazılıp yazılmamasını değil, güncelleme kısıtını etkiliyor; alan yine yazılır.

3) İSTİSMAR EDİLEBİLİRLİK LENSİ: Bu bulgu bir "eksik test" bulgusu. Hiçbir HTTP isteği, hiçbir kullanıcı rolü (shop_manager/admin refund UI dahil), hiçbir zamanlama bu boşluğu bugün istismar edemez. Okuduğum kadarıyla 807-917 arasındaki dalların KENDİSİ doğru yazılmış (unknown→reconciliation required, mismatch→unknown, red→rejected + strict update). Zarar yalnızca varsayımsal bir gelecek regresyonun sessizce geçmesi. 'critical' mevcut ve sömürülebilir bir zarar ima eder; burada öyle bir zarar yok. Ödeme eklentisinde para-kritik hata yollarının tamamen test edilmemiş olması yine de ciddi — 'high' doğru kalibrasyon.

4) Öneri kısmındaki teknik detaylar uygulanabilir ve doğru: `$wp_remote_post_test_queue` boş bırakıldığında stub'ın WP_Error dönmesi, `$wpdb->update_result = 0` ile post-confirm eskalasyonu, `total_refunded='1000'` + `process_refund(42,'1000')` ile tam iade dalı (`request_cancel`'ın `PartialCancelCode => $partial ? '1' : '0'`, class-nicepay-api.php:578 — yani tam iadede '0' gönderildiği doğrulanabilir) hepsi mevcut fixture'larla mümkün.

---

### TEST-005 — Coverage kapsamı admin/, kök eklenti dosyası ve templates/'i tamamen dışlıyor; eşik de yok

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | coverage-config |
| **Konum** | [phpunit.xml:24](../../../phpunit.xml#L24) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```
`phpunit.xml:22-30` sadece `<directory suffix=".php">includes</directory>` içeriyor. `admin/class-nicepay-admin.php` (1199 satır) ve `admin/class-nicepay-transactions.php` (1030 satır) ölçüm dışında — hâlbuki `NicePayAdminOperationsTest` ve `NicePayTransactionsAdminTest` bu dosyaları test ediyor. `nicepay-payment-gateway.php` (827 satır, tüm AJAX handler'ları ve shortcode) ve `templates/` de dışarıda. Ayrıca `<report>` bloğu yok (kapsam sadece `composer test-coverage`'ın `--coverage-html` bayrağıyla üretiliyor) ve hiçbir minimum eşik tanımlı değil. `.github/workflows/tests.yml:61-72` kapsamı sadece artifact olarak yüklüyor; hiçbir job kapsam düşüşünde kırılmıyor.
```

**Başarısızlık senaryosu**

Bir PR `admin/class-nicepay-transactions.php`'ye 300 satırlık yeni, tamamen test edilmemiş bir mutabakat ekranı ekler. Kapsam raporu değişmez (dosya kapsam dışı), hiçbir CI adımı uyarı vermez ve `package` job'ı yeşil kalır.

**Etki**

Raporlanan kapsam yüzdesi sistematik olarak şişik: test edilen ~2.200 satırlık admin kodu paydaya girmiyor, test EDİLMEYEN 827 satırlık kök dosya da girmiyor. Kapsam metriği ne karar desteği veriyor ne de regresyon koruması sağlıyor.

**Öneri**

`<include>` bloğuna `admin`, `templates` dizinlerini ve `<file>nicepay-payment-gateway.php</file>`'yi ekle. `<report><clover outputFile="tests/coverage/clover.xml"/><text outputFile="php://stdout"/></report>` ekle. CI'a `composer test-coverage` sonrası bir eşik kontrolü koy (ör. clover XML'i ayrıştırıp `includes/class-nicepay-gateway.php` ve `includes/class-nicepay-return-handler.php` için satır kapsamının %70 altına düşmesinde `exit 1`). Mevcut düşük kapsamı baseline olarak sabitleyip yalnızca düşüşleri engelleyen bir ratchet de kabul edilebilir bir ilk adım.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Coverage yapılandırması yalnızca `includes/` dizinini ölçüyor (phpunit.xml:24); testlerce fiilen çalıştırılan `admin/class-nicepay-admin.php` (1199 satır) ve `admin/class-nicepay-transactions.php` (1030 satır) ile hiç test edilmeyen kök dosya `nicepay-payment-gateway.php` (827 satır) ölçüm dışında. Ayrıca `<report>` bloğu yok — kapsam yalnızca `--coverage-html` ile insan-okunur HTML olarak üretiliyor (composer.json:25), makine tarafından ayrıştırılabilir clover/text çıktı yok — ve hiçbir minimum eşik veya ratchet tanımlı değil; CI kapsamı sadece artifact olarak yüklüyor (tests.yml:61-72) ve `package` job'ı buna bağlı değil (tests.yml:138). Sonuç: kapsam metriği ne karar desteği verir ne de regresyon koruması sağlar. NOT: etki "sistematik şişkinlik" değildir — test edilen admin kodunun dışlanması yüzdeyi düşürür, test edilmeyen kök dosyanın dışlanması yükseltir; net yön belirsizdir, metrik yalnızca temsili değildir. Ayrıca öneriden `templates/` çıkarılmalıdır: template'ler unit testlerde çalıştırılmaz, yalnızca `file_get_contents` ile string olarak denetlenir (NicePayStandaloneTemplateTest.php:11), bu yüzden coverage'a eklemek yalnızca %0'lık gürültü üretir. Doğru düzeltme: `<include>`'a `admin` dizinini ve `<file>nicepay-payment-gateway.php</file>`'yi ekle, `<report><clover outputFile="tests/coverage/clover.xml"/><text outputFile="php://stdout"/></report>` ekle ve CI'a mevcut kapsamı baseline alan bir ratchet kontrolü koy.
- Gerekçe: Her somut olgusal iddia kod tarafından doğrulandı: coverage include yalnızca `includes`, `<report>` bloğu yok, hiçbir eşik yok, CI kapsamı sadece artifact olarak yüklüyor ve `package` job'ı kapsama bağlı değil (needs: test, lint, database-integration, woocommerce-integration). Admin sınıflarının testlerce gerçekten yüklendiği de doğrulandı (NicePayAdminOperationsTest.php:88-89 require_once ile her iki admin dosyasını yüklüyor), yani ölçülebilir ama ölçülmeyen ~2.229 satır var. Kök eklenti dosyası testlerde hiç require edilmiyor (tests/bootstrap/bootstrap.php sadece yol sabiti tanımlıyor), dolayısıyla gerçekten test edilmemiş 827 satır da paydanın dışında.

SONUÇ/İSTİSMAR MERCEĞİ ile iki düzeltme:

1) Severity abartılmış. Bu bir CI/metrik boşluğu; çalışma zamanı, güvenlik veya kullanıcı etkisi yok, istismar edilebilir bir yolu yok. failure_scenario üretilebilir ama "üretilebilirliği" trivial: herhangi bir PR admin dosyasına kod ekler, kapsam raporu değişmez. Etkisi "regresyon koruması zayıf" düzeyinde ve kısmen başka kapılarla (lint, php -l, şema/WooCommerce entegrasyon job'ları, package smoke) dengeleniyor. `high` değil `medium`.

2) "Kapsam yüzdesi SİSTEMATİK olarak şişik" ifadesi teknik olarak yanlış yönlü. Test EDİLEN admin kodunun dışlanması yüzdeyi düşürür (pay ve paydadan birlikte çıkar, kapsanan oranı yüksek olduğu için net etki aşağı), test EDİLMEYEN kök dosyanın dışlanması yükseltir. Net yön belirsizdir; doğru ifade "kapsam metriği anlamsız/temsili değil"dir, "sistematik şişkinlik" değil.

3) Önerideki `templates` dizini ekleme kısmı zayıf: NicePayStandaloneTemplateTest.php:11 template'i `file_get_contents` ile string olarak okuyor, çalıştırmıyor. templates/ include edilse `processUncoveredFiles="false"` nedeniyle zaten raporda görünmez; görünse bile %0 gürültü üretir. Asıl değerli kısım `admin/` ve kök dosyanın eklenmesi + clover/text raporu + ratchet eşiği.

---

### TEST-007 — Beş nonce/capability kapısından dördü hiç test edilmiyor; wp_verify_nonce stub'ı bile yok

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | coverage-gap |
| **Konum** | [nicepay-payment-gateway.php:548](../../../nicepay-payment-gateway.php#L548) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
Üretimde beş nonce kapısı var: `nicepay-payment-gateway.php:548` (`ajax_init_payment()`, `nopriv` anonim ödeme başlatma), `:656` (`ajax_save_shortcode()`), `:771` (`ajax_delete_shortcode()`), `admin/class-nicepay-transactions.php:974` (`ajax_cancel_transaction()`, gerçek para iadesini tetikliyor) ve `admin/class-nicepay-transactions.php:246` (`handle_csv_export()`). `grep -rn "check_ajax_referer|wp_verify_nonce|wp_send_json|ajax_cancel_transaction" tests/` HİÇBİR sonuç döndürmüyor. Sadece beşincisi (`check_admin_referer`) `NicePayAdminOperationsTest.php:69-77`'deki boolean-kontrollü stub üzerinden test ediliyor. `wp_verify_nonce` ne `tests/bootstrap/wp-stubs.php`'de ne de herhangi bir test dosyasında tanımlı — yani bu dört handler'ı çağıran bir test yazılsa fatal error verirdi.
```

**Başarısızlık senaryosu**

`ajax_cancel_transaction()` içindeki koşul `if ( ! nicepay_current_user_can_manage_payments() || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) )` bir refactor'da `'nicepay_cancel'` (id'siz) olarak sadeleştirilir. Suite yeşil kalır. Üretimde bir işlem için üretilen nonce başka herhangi bir işlemin iadesini tetiklemek için yeniden kullanılabilir hale gelir — yetkili ama kısıtlı bir kullanıcı ya da XSS ile ele geçirilmiş bir admin oturumu keyfi iade yapabilir.

**Etki**

Anonim `nopriv` AJAX ödeme başlatma ve admin iade tetikleyicisi dahil, CSRF korumalarının hiçbiri doğrulanmıyor. `wp_verify_nonce` çağrısının silinmesi, yanlış action string'i kullanılması veya `!` operatörünün düşürülmesi hiçbir testte yakalanmaz.

**Öneri**

`tests/bootstrap/wp-stubs.php`'ye kontrol edilebilir `wp_verify_nonce($nonce, $action)`, `wp_create_nonce($action)`, `check_ajax_referer()` ve exception fırlatan `wp_send_json_success/error` stub'ları ekle — nonce stub'ı gerçek bir action↔nonce eşlemesi tutsun, böylece YANLIŞ action ile çağrı başarısız olsun. Sonra her dört handler için üç test yaz: geçerli nonce → başarı; eksik nonce → 403/`wp_send_json_error`; DOĞRU nonce ama YANLIŞ action için üretilmiş → reddedilmeli. `ajax_init_payment` için ayrıca rate-limit aşımı ve `nicepay_standalone_enabled='no'` vakalarını ekle.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Beş nonce/capability kapısından dördü (nicepay-payment-gateway.php:548 ajax_init_payment, :656 ajax_save_shortcode, :771 ajax_delete_shortcode, admin/class-nicepay-transactions.php:974 ajax_cancel_transaction) hiçbir testte çalıştırılmıyor ve `wp_verify_nonce` / `wp_create_nonce` / `check_ajax_referer` / `wp_send_json_*` stub'ları tests/ ağacında hiç tanımlı değil — bu handler'ları çağıran bir test bugün fatal error verir. Yalnızca handle_csv_export gerçek yürütmeyle (geçerli + geçersiz nonce) test ediliyor (NicePayAdminOperationsTest.php:349-370).

Bu bir REGRESYON RİSKİ boşluğudur, mevcut bir zafiyet değil: dört handler da bugün doğru action ile nonce doğruluyor. Boşluğun en ağır tarafı iddia edildiği gibi iade tetikleyicisi DEĞİL — `ajax_cancel_transaction` içindeki `nicepay_current_user_can_manage_payments()` kontrolü OR-zincirinin ilk terimidir ve nonce-action refactor'undan etkilenmez; ayrıca per-id nonce, zaten manage_payments yetkisi olan bir kullanıcıya veya sayfadaki nonce'ı okuyabilen bir XSS'e karşı ek yetki sınırı sağlamaz. Asıl maliyetli boşluk `ajax_init_payment`: `nopriv` ile anonim erişilebilir ve dört kapısı da (standalone_enabled :533, is_ssl :543, nonce :548, rate limit :557) hiç yürütülmüyor; buradaki sessiz bir regresyon anonim kötüye kullanım üretir. Ayrıca `nicepay_admin_shortcodes` nonce'ının iki handler arasında paylaşılması (:656 ve :771 aynı action) da test edilmemiş bir tasarım tercihi.
- Gerekçe: Her factual iddia doğrulandı — satır numaraları dosyaların şu anki haliyle birebir eşleşiyor, grep sonuçları iddia edildiği gibi boş. Beş nonce kapısından dördü (ajax_init_payment, ajax_save_shortcode, ajax_delete_shortcode, ajax_cancel_transaction) hiçbir testte ÇALIŞTIRILMIYOR ve `wp_verify_nonce` / `wp_send_json_*` stub'ları tests/ ağacının hiçbir yerinde tanımlı değil (wp-stubs.php 381 satır, "nonce" kelimesi geçmiyor). Beşincisi (handle_csv_export) ise gerçekten çalıştırılıyor: NicePayAdminOperationsTest.php:349/359/370 handler'ı üç kez çağırıyor, :357 nonce'ı geçersizleştiriyor. Yani "coverage-gap" çekirdeği CONFIRMED.

ÇÜRÜTTÜĞÜM KISIM — failure_scenario'nun istismar anlatısı (LENS: sonuç ve istismar edilebilirlik):

1. Bugün istismar edilebilir bir açık YOK. Üretim kodu doğru: dört handler da nonce'ı doğru action ile kontrol ediyor. Senaryo tamamen "gelecekte biri refactor ederse" varsayımına dayanıyor — hipotetik regresyon riski, mevcut zafiyet değil. "Anonim nopriv AJAX ... CSRF korumalarının hiçbiri doğrulanmıyor" ifadesi doğru (test yok) ama okuru korumanın yok olduğuna itebilecek şekilde yazılmış.

2. Öne sürülen somut senaryonun kendisi teknik olarak zayıf. İddia: nonce action'ı `'nicepay_cancel_' . $id` yerine `'nicepay_cancel'` olursa "yetkili ama kısıtlı bir kullanıcı ... keyfi iade yapabilir". Ancak :974'teki koşulda `nicepay_current_user_can_manage_payments()` OR-zincirinin İLK terimi olarak duruyor ve varsayımsal refactor'dan etkilenmiyor. Nonce action'ı per-id olmaktan çıksa bile çağıranın hâlâ manage_payments yetkisi olması gerekir — ve o yetkiye sahip biri zaten herhangi bir işlem için doğru nonce'ı üretip iptal edebilir. "Kısıtlı kullanıcı" diye bir istismar yolu doğmuyor. XSS'lenmiş admin oturumu argümanı da geçersiz: XSS zaten sayfadaki per-id nonce'ı okuyabilir, per-id olması ek koruma sağlamaz.

3. Gerçek risk, iddianın vurguladığı yerde değil: en anlamlı boşluk `ajax_init_payment` (:532-560), çünkü `nopriv` ile anonim erişilebilir ve DÖRT kapısı da (standalone_enabled flag :533, is_ssl :543, nonce :548, rate limit :557) hiç çalıştırılmıyor. Buradaki bir regresyon anonim kötüye kullanım üretir. İddia bunu listeliyor ama vurguyu iade senaryosuna kaydırmış.

Bu nedenle severity high -> medium: kanıt sağlam, ama etki mevcut değil-gelecekte-olası ve gösterilen en güçlü senaryo yetki kontrolüyle bloke.

---

### TEST-003 — SQL enjeksiyon ve escaping iddiaları addslashes tabanlı sahte $wpdb->prepare()'lere dayanıyor — yanlış güven

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | stub-fidelity |
| **Konum** | [tests/unit/NicePayAdminOperationsTest.php:99](../../../tests/unit/NicePayAdminOperationsTest.php#L99) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
Paylaşılan `tests/bootstrap/wp-stubs.php` hiç `$wpdb` sağlamıyor; onun yerine altı ayrı test dosyası birbirinden farklı altı `prepare()` taklidi tanımlıyor: `NicePayAdminOperationsTest.php:99-107`, `NicePayTransactionRepositoryTest.php:29-38`, `NicePayRefundTest.php:84-90`, `NicePayPrivacyTest.php:16-22` hepsi `preg_replace('/%[ds]/', "'" . addslashes($value) . "'", $query, 1)` kullanıyor; `NicePayInstallerTest.php:24-26` ve `NicePayBlocksIntegrationTest.php:85-87` sadece TEK değer alan `str_replace('%s', ...)` yapıyor; `NicePayInboundValidatorTest.php:15-20` SQL'i hiç oluşturmuyor, `array('query'=>..., 'values'=>...)` döndürüyor. Sonuç: `NicePayAdminOperationsTest::test_financial_summary_revalidates_direct_filters_and_binds_search_text` (231-246) şunu iddia ediyor: `$this->assertStringContainsString( "merchant\\' OR 1=1 --", $query );` — bu string tamamen sahte prepare'in `addslashes`'inin ürünü, gerçek `$wpdb->prepare()`'in davranışı değil. Hiçbir `%d`/`%f`/`%%`/pozisyonel `%1$s` desteği yok; değerin içinde `%s` geçerse sonraki iterasyon o değere yazıyor.
```

**Başarısızlık senaryosu**

Bir geliştirici `get_financial_summary()` içinde `$wpdb->prepare( $sql, $search )` yerine `esc_like` + manuel tırnak kullanan bir implementasyon yazar. `assertStringContainsString("merchant\\' OR 1=1 --", $query)` assertion'ı geçmeye devam eder çünkü addslashes ile aynı çıktıyı üretir; gerçek MySQL'de ise charset'e bağlı (ör. GBK/SJIS) enjeksiyona açık kalır. Sahte prepare hiçbir zaman `mysqli_real_escape_string` semantiğini modellemiyor.

**Etki**

"Arama metni bind ediliyor", "filtre yeniden doğrulanıyor" gibi güvenlik iddiaları gerçek WordPress escaping'i değil, testin kendi `addslashes` implementasyonunu ölçüyor. `$wpdb->prepare` çağrısının tamamen unutulduğu bir regresyon bu testlerde YAKALANMAZ, çünkü assertion'lar üretilen SQL metnine bakıyor ve string interpolation da aynı metni üretebilir.

**Öneri**

(1) Tek bir `tests/bootstrap/class-wpdb-fake.php` yaz, tüm test dosyaları onu kullansın — `%s`, `%d`, `%f`, `%%` ve pozisyonel placeholder'ları destekleyen, placeholder sayısı ile argüman sayısı uyuşmazsa exception fırlatan bir `prepare()` ile. (2) SQL enjeksiyon iddialarını unit seviyeden entegrasyon seviyesine taşı: `tests/integration/schema-migration.php`'ye gerçek MariaDB'de `NicePay_Transactions::get_financial_summary(array('search' => "x' OR 1=1 -- "))` çağrısı ekleyip dönen satır sayısının 0 olduğunu ve tablonun hâlâ var olduğunu doğrula. (3) Test assertion'larını üretilen SQL metnine değil, gerçek sorgu sonucuna bağla.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Altı unit test dosyası birbirinden farklı, düşük sadakatli `$wpdb->prepare()` taklitleri tanımlıyor (paylaşılan bootstrap `$wpdb` sağlamadığı için); üç ayrı semantik sınıf mevcut: variadic+`addslashes`+`preg_replace('/%[ds]/',…,1)` (NicePayAdminOperationsTest.php:101-109, NicePayTransactionRepositoryTest.php:29-37, NicePayRefundTest.php:84-90, NicePayPrivacyTest.php:16-22), tek değerli `str_replace('%s',…)` (NicePayInstallerTest.php:24-26, NicePayBlocksIntegrationTest.php:85-87) ve hiç SQL üretmeyen array dönüşü (NicePayInboundValidatorTest.php:15-20). `NicePayAdminOperationsTest.php:244` gibi güvenlik assertion'ları üretilen SQL METNİNE bakar; `$wpdb->prepare` yerine manuel escaping kullanan bir regresyon aynı metni üretip testi geçebileceğinden bu testler "arama metni bind ediliyor" iddiasını gerçekten kanıtlamaz. Ayrıca fake'ler `%f`, `%%` ve pozisyonel `%1$s` desteklemez ve `filter_query_values()` (admin/class-nicepay-transactions.php:332,343-347) `%...%` LIKE değerleri gönderdiği için, içinde `%s`/`%d` oluşturan bir arama metni (ör. "sale") fake'te sorguyu bozar — gerçek prepare'de olmayan bir davranış. Üretim kodunda gerçek bir SQLi kanıtı yoktur (satır 100 tablo adını doğrular, satır 113 gerçekten prepare çağırır); bulgu bir test-sadakati/false-confidence sorunudur.
- Gerekçe: Kodu tek tek açıp okudum; iddianın çekirdeği doğrulandı.

1) Paylaşılan bootstrap gerçekten $wpdb sağlamıyor: `grep -rn "wpdb" tests/bootstrap/` HİÇBİR eşleşme döndürmedi (wp-stubs.php ve bootstrap.php içinde "wpdb" kelimesi yok). Yani her test dosyası kendi fake'ini kurmak zorunda.

2) Altı farklı, birbiriyle uyumsuz prepare() taklidi gerçekten var ve iddia edilen üç ayrı davranış sınıfına ayrılıyor (variadic+addslashes / tek-değer str_replace / hiç SQL üretmeyen array dönüşü). Hepsini okudum, alıntılar evidence'ta.

3) `assertStringContainsString( "merchant\\' OR 1=1 --", $query )` assertion'ı gerçekten var (NicePayAdminOperationsTest.php:244) ve tamamen fake'in `addslashes` çıktısına bağlı. Assertion, üretilen SQL METNİNE bakıyor; `$wpdb->prepare` çağrısı yerine `esc_sql`/manuel tırnaklama kullanan bir implementasyon aynı metni üretip testi geçebilir. Bu, iddia edilen "false confidence" etkisini doğruluyor.

4) Fake'lerin düşük sadakati sadece teorik değil, somut olarak kırık: `admin/class-nicepay-transactions.php:17-21` FILTER_SQL 13 placeholder içeriyor ve `filter_query_values()` (satır 332-347) 9. değerden sonra `$like = '%' . esc_like($search) . '%'` gönderiyor. Fake `preg_replace('/%[ds]/', $r, $query, 1)` her turda sorgunun BAŞINDAN tarıyor; `%sale%` gibi bir LIKE değeri sorguya yazıldığı anda içindeki `%s` bir sonraki iterasyonda placeholder sanılıp üzerine yazılıyor. Yani "sale", "such", "d…" ile başlayan arama metinleri fake'te bozuk SQL üretir — gerçek `$wpdb->prepare` bunu asla yapmaz (değerler yeniden taranmaz). Mevcut testler "merchant" kullandığı için bu tetiklenmiyor; yani sadakat açığı gizli duruyor.

DÜZELTMELER (bu yüzden confirmed değil, partially-confirmed):
- Konum satır numarası yanlış: NicePayAdminOperationsTest.php'de prepare() 99-107 değil **101-109** (sınıf 94'te başlıyor, `public function prepare` satır 101). Diğer tüm konumlar (Repository 29-38, Refund 84-90, Privacy 16-22, Installer 24-26, Blocks 85-87, InboundValidator 15-20, assertion testi 231'de başlıyor / assertion 244) doğru.
- İddianın "gerçek `$wpdb->prepare()`'in davranışı değil" ifadesi biraz fazla keskin: WordPress'in prepare'i de değeri `esc_sql`/`mysqli_real_escape_string` ile kaçırıp tek tırnağa alır, yani `merchant\' OR 1=1 --` gerçek prepare'in de üreteceği metne yakındır. Sorun çıktının "tamamen sahte" olması değil, assertion'ın prepare ile manuel escaping'i AYIRT EDEMEMESİ. İddianın etki/senaryo bölümü zaten bunu doğru anlatıyor.
- Severity: bu bir test-kalitesi/bakım bulgusu; üretim kodunda gerçek bir SQLi yok (get_financial_summary satır 113 gerçekten `$wpdb->prepare` çağırıyor, tablo adı satır 100'de `/\A[A-Za-z0-9_]+\z/` ile doğrulanıyor) ve `tests/integration/schema-migration.php` gerçek $wpdb ile çalışıyor. "high" yerine **medium** daha doğru.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Yedi test dosyası, paylaşılan bootstrap'ta $wpdb stub'ı bulunmadığı için birbirinden farklı üç semantikte kendi `prepare()` taklidini tanımlıyor (AdminOperations:101, Repository:29, Refund:84, Privacy:16 = variadic+addslashes; Installer:24, Blocks:85 = tek-değerli str_replace; InboundValidator:15 = SQL üretmeyen array). Bu duplikasyon bakım yüküdür ve `NicePayAdminOperationsTest.php:244`'teki `assertStringContainsString("merchant\\' OR 1=1 --", $query)` assertion'ı gerçek `mysqli_real_escape_string` semantiğini değil testin kendi `addslashes()`'ini ölçer. Ancak: (a) `%d` fake tarafından destekleniyor ve `%f`/`%%`/pozisyonel placeholder'lar üretim kodunda hiç kullanılmıyor; (b) `prepare()` çağrısının tamamen kaldırıldığı bir regresyon BU testte yakalanır (FILTER_SQL literal `%s` şablonudur, prepare yoksa 243/244 assertion'ları fail eder) — kaçan senaryo yalnızca kasıtlı `esc_sql()`/manuel-tırnak interpolasyonudur; (c) mevcut üretim kodunda açık yoktur (admin/class-nicepay-transactions.php:113 prepare çağırıyor, filtreler allowlist ile doğrulanıyor, giriş noktaları capability+nonce korumalı), dolayısıyla istismar edilebilirlik sıfırdır. Ek olarak fake'in `preg_replace(..., 1)` yaklaşımı, bind edilen bir değerin içinde `%s` geçtiğinde sonraki yerleştirmeyi bozarak sahte test HATASI (false-fail) üretebilir. Entegrasyon seviyesinde bu yolun hiç kapsanmaması (tests/integration'da financial_summary/search testi yok) bulgunun en somut kısmıdır.
- Gerekçe: ÇEKİRDEK DOĞRU, AMA ETKİ VE SEVERITY ABARTILMIŞ.

Doğrulanan kısım (kodu okudum):
1. `tests/bootstrap/wp-stubs.php` (381 satır) içinde `wpdb` geçen TEK bir satır yok — `grep -n "wpdb" tests/bootstrap/*.php` boş döndü. Yani paylaşılan bootstrap gerçekten $wpdb sağlamıyor.
2. Yedi ayrı test dosyası birbirinden farklı yedi `prepare()` taklidi tanımlıyor (3 farklı imza/semantik: variadic+addslashes, tek-değerli str_replace, hiç SQL üretmeyen array döndüren). Bu gerçek bir DRY/bakım yükü ve fidelity sorunu.
3. `NicePayAdminOperationsTest.php:244` gerçekten `assertStringContainsString( "merchant\\' OR 1=1 --", $query )` diyor ve bu string tamamen aynı dosyadaki fake'in `addslashes()`'inin ürünü — gerçek `$wpdb->prepare`/`mysqli_real_escape_string` semantiği modellenmiyor.
4. Entegrasyon seviyesinde hiçbir telafi yok: `grep -rn "financial_summary\|OR 1=1\|search" tests/integration/*.php` boş döndü.

Çürütülen / düzeltilen kısımlar:
A) KONUM YANLIŞ: fake sınıf `NicePayAdminOperationsTest.php:93`'te başlıyor, `prepare()` **101-109** satırlarında — iddiadaki "99" ve "99-107" yanlış. Diğer konumlar (Repo 29, Refund 84, Privacy 16, Installer 24, Blocks 85, Inbound 15, assertion 231/244) doğru.

B) "Hiçbir %d/%f/%%/pozisyonel %1$s desteği yok" İDDİASI HATALI VE ÖNEMSİZ: regex `preg_replace('/%[ds]/', ...)` %d'yi DE kapsıyor ve `is_int($value)` dalı sayıyı tırnaksız basıyor — yani %d destekleniyor. %f/%%/pozisyonel ise `includes/` ve `admin/` altında hiç kullanılmıyor (grep "%%\|%1\$\|%f" boş; prepare çağrılarında yalnızca 4×%s + 1×%d var). Desteklenmeyen özellikler üretim kodunda yok, dolayısıyla bu alt-iddia moot.

C) ETKİ İDDİASI ("$wpdb->prepare tamamen unutulursa YAKALANMAZ") YANLIŞ: `FILTER_SQL` (admin/class-nicepay-transactions.php:17-21) literal `%s` token'larından oluşan bir şablon. `prepare()` çağrısı (satır 113) silinirse sorgu metninde ham `%s` kalır ve 240-246'daki `assertStringContainsString("status = ''")` ile `"merchant\\' OR 1=1 --"` assertion'ları BAŞARISIZ olur. Yani "prepare unutuldu" regresyonu bu testte yakalanır. Kaçan senaryo çok daha dar: geliştirici bilerek `esc_sql()`/`addslashes()` ile manuel interpolasyon yazarsa aynı metni üretip testi geçer. Bu gerçek ama niş bir boşluk; failure_scenario "üretilebilir" ama iddia edildiği kadar geniş değil.

D) İSTİSMAR EDİLEBİLİRLİK = SIFIR: Bugünkü üretim kodu doğru — satır 113 `$wpdb->prepare()` çağırıyor, filtreler allowlist ile yeniden doğrulanıyor (44-61), giriş noktaları `nicepay_current_user_can_manage_payments()` + nonce ile korunuyor (242, 246, 535, 974). Hiçbir HTTP isteği/rol/zamanlama ile bu bulgudan bir enjeksiyon üretilemez. Önkoşul "gelecekte bir geliştiricinin belirli bir yanlış refactor yapması" — yani gerçek risk gelecekteki bir regresyonun kaçırılması, mevcut bir açık değil.

E) Fake'in gerçek (belirtilmemiş) hatası: `preg_replace(..., $query, 1)` her zaman ilk eşleşmeyi değiştirdiği için, daha önce yerleştirilmiş bir değerin içinde `%s` geçerse sonraki iterasyon o değerin içine yazar. İddia bunu doğru tespit etmiş, ancak sonucu false-PASS değil false-FAIL olur — güvenlik etkisi değil kırılganlıktır.

Sonuç: gerçek bir "stub-fidelity + test duplication" bulgusu, ama `high` değil `medium`. Öneriler (tek paylaşılan wpdb fake + entegrasyon seviyesinde gerçek sorgu doğrulaması) geçerli ve isabetli.

---

### TEST-004 — Sekiz test metodu üretim kodunu hiç çalıştırmadan kaynak dosya metnini grep'liyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | tautological-test |
| **Konum** | [tests/unit/NicePayStandaloneTemplateTest.php:10](../../../tests/unit/NicePayStandaloneTemplateTest.php#L10) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
`NicePayStandaloneTemplateTest`'in TAMAMI (2 test, 39 satır) `file_get_contents` + `assertStringContainsString` ile şablon ve JS dosyasının metnini kontrol ediyor; şablon hiç render edilmiyor, JS hiç çalıştırılmıyor. Örnek satır 20: `$this->assertStringContainsString( "array_unshift( \$button_classes, 'nicepay-pay-button' )", $template );`. Aynı desen: `NicePayAdminOperationsTest.php:324-340` (`handle_csv_export()`'un kaynak metnindeki `strpos()` pozisyonlarını karşılaştırarak "guard sırası" iddia ediyor); `NicePayAdminOperationsTest.php:414-430` (12 string'i iki admin dosyasında arıyor); `NicePayTransactionsAdminTest.php:97-107` (`esc_html( $detail['label'] )` string'ini arıyor ve `$transaction->auth_token` string'inin dosyada geçmediğini iddia ediyor); `NicePayRetentionTest.php:171-179` (birebir İngilizce UI cümleleri arıyor: `'This is a permanent, legally significant deletion policy.'`); `NicePayBlocksIntegrationTest.php:152-155`; ve `tests/js/nicepay-shortcode-admin.test.js:36` — `assert.equal(php.includes('<script>\n        jQuery(function($)'), false)` (boşluk sayısına duyarlı).
```

**Başarısızlık senaryosu**

Geliştirici `render_transaction_detail()` içinde `esc_html( $detail['value'] )` satırını `<?php echo $detail['value']; ?>` olarak değiştirir ama dosyanın başka bir yerinde (ör. farklı bir metot veya PHPDoc örneği) `esc_html( $detail['value'] )` metni kalır. `test_detail_view_escapes_every_label_and_value_at_output_boundary` yeşil kalır ve stored XSS üretime gider. Tersi: çeviri ekibi retention uyarı metnini düzeltir, `test_admin_surface_explains_scope_and_permanent_effects` kırmızıya döner ve gerçek bir hata yokken build kırılır.

**Etki**

Bu testler ne doğruluk ne güvenlik kanıtlıyor; sadece belirli karakter dizilerinin dosyada bulunduğunu kanıtlıyor. Yorum satırındaki bir string bile geçer; ölü/erişilemez kod geçer. Aynı zamanda zararsız refactor'larda (değişken adı, girinti, farklı ama eşdeğer escaping fonksiyonu, çeviri metni güncellemesi) yanlış alarm üretiyorlar — yani hem yanlış güven hem bakım yükü.

**Öneri**

Her birini davranışsal teste çevir. `NicePayStandaloneTemplateTest` için şablonu gerçekten `include` edip `ob_start()` ile HTML üret, `DOMDocument` ile parse edip `data-nicepay-config-id` attribute'unun her form instance'ında farklı olduğunu ve hiç inline `onclick` bulunmadığını doğrula. `test_detail_view_escapes...` için `NicePayAdminOperationsTest.php:432`'deki gibi gerçek render + `ob_get_clean()` kullan ve `result_msg => '<script>alert(1)</script>'` ile çıktıda `&lt;script&gt;` olduğunu assert et. `test_export_action_is_registered_and_guards_before_output_headers` için `handle_csv_export()`'u gerçekten çağır (zaten bir sonraki test bunu yapıyor) ve bir `header()` shim ile başlıkların guard'lardan sonra yazıldığını doğrula. `NicePayRetentionTest`'teki UI metin grep'ini sil — o bir dokümantasyon iddiası, test değil.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Altı test metodu (NicePayStandaloneTemplateTest.php:10 ve :24, NicePayAdminOperationsTest.php:324 ve :414, NicePayTransactionsAdminTest.php:97, NicePayRetentionTest.php:171) üretim kodunu hiç çalıştırmadan yalnızca kaynak dosya metnini `file_get_contents` + `assertStringContainsString`/`strpos` ile denetliyor; ayrıca iki davranışsal testin içine (NicePayBlocksIntegrationTest.php:152-155 ve tests/js/nicepay-shortcode-admin.test.js:36) kırılgan metin-grep assertion'ları serpiştirilmiş. En kritik boşluk NicePayTransactionsAdminTest.php:97: transaction detay sayfası hiçbir testte render edilmiyor, dolayısıyla escaping regresyonu yakalanmaz. NicePayRetentionTest.php:171 birebir İngilizce UI cümlelerine bağlı olduğu için çeviri güncellemesinde yanlış alarm üretir. Buna karşılık CSV export guard'ları (NicePayAdminOperationsTest.php:342) ve blocks script kaydı (NicePayBlocksIntegrationTest.php:142-151) davranışsal olarak da kapsandığından bu ikisinde risk sadece bakım yükü.
- Gerekçe: Çekirdek iddia doğru ve satır numaraları dosyaların şu anki haliyle birebir eşleşiyor: PR'da üretim kodunu hiç çalıştırmadan kaynak dosya metnini grep'leyen testler gerçekten var ve bunlardan biri (transaction detail escaping) gerçek bir güvenlik yüzeyini kapsıyor gibi görünürken aslında hiçbir render yapmıyor — `render_transaction_detail` çıktısını üreten hiçbir test yok (grep: `get_safe_detail_fields` çağrılıyor ama detay sayfası render'ı hiçbir testte yok, sadece `render_order_payment_summary` gerçekten render ediliyor, NicePayAdminOperationsTest.php:464-466). Standalone şablon için de tek kapsam metin grep'i: `grep -rn "standalone-payment-form" tests/` yalnızca NicePayStandaloneTemplateTest.php:11'i döndürüyor, yani şablon hiçbir yerde include/render edilmiyor. Failure scenario ve öneriler geçerli.

ANCAK iki nokta yanlış, bu yüzden "confirmed" değil:

1) Başlıktaki "Sekiz test metodu üretim kodunu hiç çalıştırmadan" sayımı hatalı. Saf metin-grep testleri altı tane: NicePayStandaloneTemplateTest:10 ve :24, NicePayAdminOperationsTest:324 ve :414, NicePayTransactionsAdminTest:97, NicePayRetentionTest:171. Listelenen diğer iki konum karma testler:
   - NicePayBlocksIntegrationTest:140 `test_blocks_script_uses_the_official_registry_and_declared_dependencies` ÖNCE gerçek nesneyi çalıştırıyor (`new NicePay_Blocks_Integration(); $integration->get_payment_method_script_handles();` ve kayıtlı script bağımlılıklarını `assertSame` ile doğruluyor, satır 142-151); grep sadece son üç assertion (152-155).
   - tests/js/nicepay-shortcode-admin.test.js:36 tamamen davranışsal bir testin İÇİNDEKİ tek bir grep assertion'ı: aynı test JSDOM kurup `window.eval(source)` ile gerçek `assets/js/nicepay-shortcode-admin.js`'i çalıştırıyor, XSS'i (`window.__nicepayXss === undefined`), aria-invalid/focus davranışını, kontrast değişkenini vs. doğruluyor (satır 57-77). Yani "JS hiç çalıştırılmıyor" ifadesi bu dosya için yanlış; `NicePayStandaloneTemplateTest` için doğru (orada gerçekten sadece `assets/js/nicepay.js` metni okunuyor).

2) severity `high` fazla. Ortada üretimde bir defekt yok; risk "yanlış güven + kırılgan test" kategorisinde ve etkilenen yüzeyin bir kısmı (blocks kaydı, shortcode admin JS, CSV export guard'ları — :342'deki test `handle_csv_export()`'u gerçekten çağırıp 403/403/400 davranışını doğruluyor) başka testlerle davranışsal olarak zaten kapsanıyor. `medium` daha doğru.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Six test methods (not eight) assert only on source-file text without executing production code: NicePayStandaloneTemplateTest::test_template_carries_instance_configuration_without_inline_global_handlers (L10-22), ::test_frontend_reads_config_from_the_clicked_form_instance (L24-38), NicePayAdminOperationsTest::test_export_action_is_registered_and_guards_before_output_headers (L324-340), ::test_admin_ui_exposes_escaped_report_filtered_export_and_contrast_variables (L414-430), NicePayTransactionsAdminTest::test_detail_view_escapes_every_label_and_value_at_output_boundary (L97-107), NicePayRetentionTest::test_admin_surface_explains_scope_and_permanent_effects (L171-179). The other two cited locations are single brittle assertions inside genuinely behavioral tests, not grep-only tests. The real defect is brittleness plus low sensitivity, NOT false-green on the named regressions: the stored-XSS scenario as written is not reproducible, and the export-guard and nicepay.js behaviors are already proven behaviorally by adjacent tests. The one genuine coverage gap is that templates/standalone-payment-form.php is never rendered by any test, so drift between the template and the hand-written fixture markup in tests/js/nicepay-multi-instance.test.js is unguarded.
- Gerekçe: The structural core survives: I read every cited line and all eight source-text assertions exist exactly as quoted. But under the consequence/exploitability lens three material details are wrong.

(1) Count and characterization. tests/unit/NicePayBlocksIntegrationTest.php:140-156 instantiates NicePay_Blocks_Integration, calls get_payment_method_script_handles() and asserts the registered dependency array; the three source greps at L152-155 are an appendix to a real behavioral test. tests/js/nicepay-shortcode-admin.test.js:34-76 does window.eval(source) at L56 and then asserts real DOM state, including window.__nicepayXss === undefined at L68; L36 is one brittle assertion inside it. Calling these "test methods that never execute production code" is inaccurate. Six methods, not eight.

(2) The headline failure scenario is not reproducible. grep -Fc "esc_html( $detail['value'] )" on admin/class-nicepay-transactions.php returns exactly 1 (line 620). Removing or weakening that sink turns the test RED, not green. The scenario requires the developer to simultaneously leave a byte-identical duplicate of that string elsewhere in the same file; no duplicate exists and there is no plausible reason to create one. So the test does catch the specific regression it names. Its actual weakness is different and milder: it is insensitive to NEW unescaped sinks, to property renames (`$row->auth_token` or `$transaction->{'auth_token'}` both defeat the assertStringNotContainsString guards, which currently pass only because auth_token appears 0 times in the file), and to equivalent-but-wrong escaping choices.

(3) The "proves no security" impact is false for two of the six. NicePayAdminOperationsTest:342-375 immediately follows the grep test and really invokes $handler->handle_csv_export() three times, asserting 403 on capability failure, that $nicepay_admin_test_nonce_checks is still empty at that point (proving capability precedes nonce), 403 on bad nonce, and 400 on invalid filters. The guard ordering the grep test claims via strpos() is independently proven behaviorally. Likewise tests/js/nicepay-multi-instance.test.js:11 reads assets/js/nicepay.js and line 91 does window.eval(source), driving real multi-instance behavior — so NicePayStandaloneTemplateTest::test_frontend_reads_config_from_the_clicked_form_instance is redundant, not load-bearing.

What is fully confirmed is the brittleness half: NicePayRetentionTest.php:175 asserts the verbatim translatable sentence 'This is a permanent, legally significant deletion policy.' against admin/class-nicepay-admin.php, and tests/js/nicepay-shortcode-admin.test.js:36 asserts on '<script>\n        jQuery(function($)' — eight literal spaces. A copy edit or a reindent breaks CI with zero underlying defect. Also confirmed and arguably understated: templates/standalone-payment-form.php has no rendering test anywhere (the only reference in tests/ is the file_get_contents at NicePayStandaloneTemplateTest.php:11), while the JS test that does exercise behavior builds its own fixture markup in formMarkup() — so template drift silently passes both suites.

Severity high is overstated. There is no production defect, no reachable exploit, no user-facing consequence; the two false-green scenarios offered are contrived or already backstopped. The cost is maintenance burden, weak assurance, and one real coverage gap. Medium.

---

### TEST-006 — Approval sırasında ağ kopması (wp_remote_post WP_Error) senaryosu — bir ödeme ağ geçidinin en kritik hata modu — hiç test edilmemiş

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | negative-test-gap |
| **Konum** | [tests/unit/NicePayTransportTest.php:171](../../../tests/unit/NicePayTransportTest.php#L171) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
`NicePayTransportTest`'teki tüm hata testleri HTTP durum kodu (`queueRaw(502, ...)`, satır 172) veya bozuk gövde (`queueRaw(200, '<html>...')`, satır 186) üzerinden gidiyor. Hiçbir test `wp_remote_post`'un `WP_Error` döndürdüğü — yani DNS hatası, TLS el sıkışma hatası veya bağlantı zaman aşımı — durumunu approval sınırında çalıştırmıyor. `decode_response()` bu durumda WP hatasını OLDUĞU GİBİ geri döndürüyor (`includes/class-nicepay-api.php:284-287`: `if ( is_wp_error( $response ) ) { return $response; }`), yani hata kodu `nicepay_approval_*` namespace'inde DEĞİL, `http_request_failed` gibi WP'nin kendi kodu oluyor. `test_unconfirmed_net_cancel_requires_reconciliation` (366-378) 502/503 kullanıp `assertSame('nicepay_net_cancel_http_error', $audit['net_cancel_result_code'])` iddia ediyor — gerçek timeout'ta bu değer `http_request_failed` olurdu. Ayrıca `test_transport_stub_remains_fail_closed_without_callback_or_queue` (42-50) ve `test_transport_stub_callback_receives_and_captures_url_and_args` (52-68) üretim kodunu değil, testin KENDİ stub'ını test ediyor.
```

**Başarısızlık senaryosu**

`request_approval()` çağrısı 30 saniye sonra `WP_Error('http_request_failed', 'cURL error 28')` ile döner. `nicepay_get_approval_error_audit()` bu namespace dışı kodu beklemiyorsa boş/eksik audit üretir; `handle_return()` `needs_reconciliation` yerine `failed` yazar; müşterinin kartından çekilmiş 290.000 KRW için hiçbir mutabakat bayrağı kalmaz. Suite tamamen yeşildir.

**Etki**

Bir kart onayı sırasında ağ kopması üretimde en sık görülen ciddi olaydır (para çekilmiş olabilir de olmayabilir de). Bu yolda `nicepay_get_approval_error_audit()`'in ne döndürdüğü, net-cancel'ın tetiklenip tetiklenmediği ve ledger'a hangi `reconciliation_note`'un yazıldığı hiçbir testte doğrulanmamış.

**Öneri**

`NicePayTransportTest`'e ekle: kuyruğu boş bırakarak (stub `WP_Error('stub', ...)` döner) `request_approval($this->auth_context())` çağır ve (a) net-cancel'ın denendiğini, (b) `nicepay_get_approval_error_audit($result)`'un `needs_reconciliation` ve `net_cancel_status` alanlarını doğru döndürdüğünü assert et. Ayrıca `decode_response()`'un WP transport hatasını namespace'lemesini öner: `return new WP_Error('nicepay_' . $context . '_transport_error', ..., array('wp_code' => $response->get_error_code()))` — böylece `reconciliation_note` her zaman kararlı bir kod olur ve testlenebilir hale gelir. `test_transport_stub_*` iki testini sil (stub'ın stub'ını test ediyorlar).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Approval sınırında `wp_remote_post`'un WP_Error döndürdüğü (DNS/TLS/timeout) yol hiçbir testte çalıştırılmıyor; bu yol üretim kodunda doğru davranıyor (class-nicepay-api.php:370-373 net-cancel'ı tetikliyor ve nicepay_get_approval_error_audit hata koduna bağımlı olmadığı için `needs_reconciliation` doğru hesaplanıyor), ancak `decode_response`/`abort_approval` WP hatasını namespace'lemediği için bu yolda `reconciliation_note` ve `net_cancel_result_code` alanlarına `http_request_failed` gibi kararsız, WP/cURL'e bağlı bir değer yazılıyor — namespace'li `nicepay_*` kodlarıyla tutarsız ve raporlamada kırılgan. Ayrıca `test_transport_stub_remains_fail_closed_without_callback_or_queue` (42-50) ve `test_transport_stub_callback_receives_and_captures_url_and_args` (52-68) üretim kodunu değil testin kendi stub'ını doğruluyor.
- Gerekçe: Test boşluğu iddiası DOĞRU: hiçbir test approval sınırında wp_remote_post'un WP_Error döndürmesini çalıştırmıyor. `request_approval` çağıran tüm testler (NicePayTransportTest.php:74, 102, 214, 349, 370, 386, 397 ve invokeOperation:443/467) ya kuyruğa HTTP yanıtı koyuyor ya da transport'a hiç ulaşmadan (eksik context, SSRF URL) fail ediyor. Kuyruk boşken stub `WP_Error('stub')` döndürüyor (tests/bootstrap/wp-stubs.php:266) ama bunu approval yolunda tetikleyen tek test yok; `'stub'` kodunu bekleyen tek yer stub'ın kendi testi (satır 48). `decode_response` gerçekten WP_Error'u namespace'lemeden geçiriyor (class-nicepay-api.php:284-287) ve iddia edilen satır numaraları (171/172, 186, 366-378, 42-68) dosyanın şu anki haliyle birebir eşleşiyor. `test_transport_stub_*` iki testinin üretim kodunu değil stub'ı test ettiği de doğru.

Ancak ETKİ ve BAŞARISIZLIK SENARYOSU ÇÜRÜTÜLDÜ. İki koruma iddiayı boşa çıkarıyor:
(1) `request_approval` transport WP_Error'unu satır 370-373'te yakalayıp `abort_approval()`'a veriyor — yani net-cancel gerçekten deneniyor, "net-cancel tetiklenip tetiklenmediği" davranışsal olarak 502 senaryosuyla aynı kod yolundan geçiyor (`is_wp_error($response)` dalı da `decode_response` dalı da aynı `abort_approval`'a gidiyor).
(2) `nicepay_get_approval_error_audit()` (nicepay-functions.php:156-186) hata KODUNA hiç bakmıyor; yalnızca `get_error_data()` içindeki `net_cancel_status` / `net_cancel_result_code` alanlarını okuyor. Dolayısıyla "namespace dışı kod gelirse boş/eksik audit üretir" iddiası yanlış; `needs_reconciliation` doğru hesaplanır ve `class-nicepay-return-handler.php:120-136` `needs_reconciliation ? 'needs_reconciliation' : 'failed'` dalını doğru seçer. 290.000 KRW için mutabakat bayrağının kaybolduğu senaryo gerçekleşmez.

Geriye kalan gerçek (ve daha küçük) sorun: `reconciliation_note` (return-handler:126, gateway:520/817) ve `net_cancel_result_code` (api.php:443-445) bu yolda `http_request_failed` gibi kararsız, WP/cURL'e bağlı bir değer alır — namespace'li kodlarla tutarsızdır ve raporlama/filtreleme açısından kırılgandır. Bu bir "high, ödeme kaybı" bulgusu değil, orta seviye bir test boşluğu + kod tutarsızlığıdır.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Transport katmanı WP_Error döndürdüğünde (DNS/TLS/timeout) approval yolu test edilmiyor — ancak üretim kodu bu senaryoda DOĞRU çalışıyor: `request_approval()` (class-nicepay-api.php:370-372) WP_Error'u decode_response'a hiç göndermeden `abort_approval()`'a veriyor, net-cancel deneniyor ve `nicepay_get_approval_error_audit()` hata koduna değil `net_cancel_status` alanına baktığı için fail-closed davranıp `needs_reconciliation` yazıyor. Dolayısıyla bulgu bir "para bayrağı kaybı" değil, (a) ödeme ağ geçidinin en sık ciddi hata modunda regresyon koruması olmaması ve (b) net-cancel doğrulandığında `reconciliation_note` sütununa namespace dışı `http_request_failed` kodunun yazılması (class-nicepay-return-handler.php:126) şeklinde bir kalite/gözlemlenebilirlik boşluğudur. Öneri geçerliliğini korur: kuyruğu boş bırakıp (stub `WP_Error('stub')` döner) `request_approval()` çağıran bir test eklenmeli ve transport hatası namespace'lenmelidir.
- Gerekçe: Test-boşluğu doğru, ama iddianın "kanıt" ve "başarısızlık senaryosu" kısımları üretim kodunu yanlış okuyor ve bu yüzden severity abartılmış.

DOĞRU OLAN: Tüm test suite'inde `wp_remote_post`'un WP_Error döndürdüğü (DNS/TLS/timeout) durumu approval sınırında çalıştıran tek bir test yok. `grep -rn "http_request_failed\|'stub'" tests` sadece stub'ın kendi tanımını (wp-stubs.php:266) ve stub-testlerini (NicePayTransportTest.php:42-68) buluyor; `NicePayTransportTest`'teki tüm hata testleri `queueRaw(502/503, ...)` veya bozuk gövde üzerinden gidiyor. `test_transport_stub_*` iki testinin üretim kodu yerine testin kendi stub'ını doğruladığı da doğru (yine de "queue boşsa fail-closed" sözleşmesini kilitledikleri için tamamen değersiz değiller — silmek yerine bırakmak savunulabilir).

YANLIŞ OLAN 1 — decode_response iddiası approval yolu için ulaşılamaz: `request_approval()` transport WP_Error'unu decode_response'a HİÇ göndermiyor. class-nicepay-api.php:370-372 önce yakalayıp `abort_approval()`'a veriyor. decode_response:285'teki `return $response;` yalnızca zaten WP_Error olan bir yanıtı geçiriyor ve o yol approval'da çağrılmıyor. Yani "decode_response WP_Error'u namespace'lemeden geçiriyor → audit bozulur" zinciri kopuk.

YANLIŞ OLAN 2 — başarısızlık senaryosu üretilemez: `nicepay_get_approval_error_audit()` (nicepay-functions.php:156-176) hata KODUNA hiç bakmıyor; sadece `get_error_data()['net_cancel_status']`'a bakıp `'confirmed' !== $status` ise fail-closed olarak `needs_reconciliation = true` üretiyor. `abort_approval()` (417-462) her üç dalda da bu data'yı dolduruyor. cURL error 28 senaryosunda: net-cancel de büyük olasılıkla WP_Error döner → 436-449 dalı → kod `nicepay_approval_reconciliation_required`, `net_cancel_status = 'unknown'` → return-handler:121-124 `status/approval_state = 'needs_reconciliation'`, `reconciliation_status = 'required'`, `auth_token` saklanır. Yani "290.000 KRW için hiçbir mutabakat bayrağı kalmaz" iddiası kod tarafından çürütülüyor. Net-cancel başarılı olursa `net_cancel_status = 'confirmed'` → `failed` yazılması zaten DOĞRU davranıştır (ters çevrilme kanıtlanmıştır).

GEÇERLİ KALAN İKİNCİL NOKTA: net-cancel'ın doğrulandığı dalda (452-462) dönen kod orijinal hata kodudur, yani gerçek timeout'ta `reconciliation_note` sütununa (return-handler:126) `http_request_failed` gibi WP'ye ait, namespace dışı bir kod yazılır. Bu para güvenliği değil, gözlemlenebilirlik/tutarlılık sorunudur — namespace'leme önerisi bu nedenle hâlâ makul.

Sonuç: gerçek etki "kritik hata modunda para bayrağı kaybı" değil, "kritik hata modu doğru davranıyor ama regresyona karşı korumasız + telemetri kodu kararsız". Bu high değil, medium/low seviyesidir; ben low-medium arası, medium'a yuvarlıyorum (ödeme ağ geçidinin en sık ciddi hata modu olduğu ve tek satırlık bir regresyonun fail-closed'ı fail-open'a çevirebileceği için).

---

### TEST-008 — wp-stubs.php'deki sanitize/escape taklitleri gerçek WordPress semantiğinden sapıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | stub-fidelity |
| **Konum** | [tests/bootstrap/wp-stubs.php:197](../../../tests/bootstrap/wp-stubs.php#L197) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Denetlediğim sapmalar: `sanitize_text_field()` (197-201) sadece `trim(strip_tags($str))` yapıyor — gerçek WP ayrıca satır sonlarını/sekmeleri boşluğa çevirir, geçersiz UTF-8 ve oktetleri siler, `%` ile başlayan kodlanmış dizileri temizler; üretimde `nicepay_utf8_byte_cut( sanitize_text_field( $reason ), 100 )` (class-nicepay-gateway.php:783) iade sebebini NICEPAY'e gönderiyor, yani CRLF koruması gerçek WP'den geliyor ve stub'da YOK. `esc_url()`/`esc_url_raw()` (322-332) `filter_var($url, FILTER_SANITIZE_URL)` kullanıyor — gerçek `esc_url` protokol allowlist uygular ve `javascript:`/`data:` şemalarını siler; stub bunları aynen geçirir, oysa `class-nicepay-return-handler.php:46-47` ve `class-nicepay-gateway.php:399-400` gelen `$_POST['NextAppURL']`'i `esc_url_raw()` ile geçiriyor. `sanitize_email()` (203-207) `FILTER_SANITIZE_EMAIL` ile sadece geçersiz karakterleri siler, geçersiz e-postayı `''` yapmaz — gerçek WP yapar. `esc_html__()`/`esc_html_e()` (344-354) `$wp_translate_test_callback` seam'ini yok sayıyor ama `__()` (334-342) sayıyor; dolayısıyla `NicePayFunctionsTest.php:405`'teki i18n testi `esc_html__()` ile üretilen hiçbir metni kapsamıyor. `add_query_arg()` (227-234) mevcut query string'i korumadan körü körüne ekliyor.
```

**Başarısızlık senaryosu**

`nicepay_utf8_byte_cut( sanitize_text_field( $reason ), 100 )` yerine `nicepay_utf8_byte_cut( $reason, 100 )` yazılır. Test suite'te fark yok (stub zaten newline silmiyordu, dolayısıyla ilgili bir assertion da yazılmamıştı). Üretimde satır sonu içeren bir iade sebebi `cancel_process.jsp` gövdesine gider ve PG tarafında beklenmeyen davranışa yol açar.

**Etki**

Güvenlik testlerinin ölçtüğü davranış üretimdeki davranış değil. Bazı sapmalar fail-open (esc_url), bazıları fail-closed yönde — her iki durumda da test sonucu üretim davranışını temsil etmiyor ve `test_..._never_...` tipi assertion'lar aldatıcı.

**Öneri**

Stub'ları gerçek WP kaynağına yaklaştır: `sanitize_text_field`'a `preg_replace('/[\r\n\t ]+/', ' ', ...)` ve geçersiz UTF-8 temizliği ekle; `esc_url`'e `http`/`https` dışını `''` yapan bir allowlist koy; `sanitize_email`'i `filter_var(..., FILTER_VALIDATE_EMAIL)` ile bitir; `esc_html__`/`esc_html_e`'yi `__()` üzerinden geçir. Daha sağlam alternatif: `wp-phpunit/wp-phpunit` ile gerçek WP çekirdeğini yükleyen ikinci bir test suite ekle ve escape/sanitize'a bağlı testleri oraya taşı. Her stub'ın başına hangi WP sürümünün davranışını modellediğini yazan bir yorum ekle.

---

### TEST-009 — is_email() hiç stub'lanmamış — üretimdeki e-posta doğrulama dalı testlerde asla çalışmıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | stub-fidelity |
| **Konum** | [includes/class-nicepay-privacy.php:166](../../../includes/class-nicepay-privacy.php#L166) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Üretimde üç yerde `function_exists('is_email')` guard'ı var. `includes/class-nicepay-privacy.php:166-170`:
```php
private static function is_valid_email( $email ) {
    return function_exists( 'is_email' )
        ? (bool) is_email( $email )
        : false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}
```
Ayrıca `includes/nicepay-functions.php:1155` ve `:1438-1439`. `grep -rn "is_email" tests/` sadece üretim kodundaki bu satırları buluyor — hiçbir test dosyası veya `wp-stubs.php` `is_email` tanımlamıyor. Dolayısıyla `NicePayPrivacyTest::test_invalid_email_never_queries_the_ledger` (106-111) ve `NicePayFunctionsTest::test_optional_buyer_fields_allow_empty_values_but_validate_present_values` (368-378) HER ZAMAN `filter_var` fallback dalını çalıştırıyor; WordPress'te çalışan `is_email()` dalı hiç test edilmiyor.
````

**Başarısızlık senaryosu**

GDPR silme talebi `user@[127.0.0.1]` formatındaki bir adres için gelir. Test ortamı (`filter_var`) bunu geçerli sayar ve test yazarı silme sorgusunun çalıştığını doğrular; WordPress üretiminde `is_email()` false döner, `NicePay_Privacy::erase()` sessizce `items_removed => false` döndürür ve kişisel veri ledger'da kalır — uyum ihlali, hiçbir test uyarmaz.

**Etki**

İki doğrulayıcı aynı fikirde değil: `is_email('user@[127.0.0.1]')` false döner, `filter_var(..., FILTER_VALIDATE_EMAIL)` true döner; `is_email` etki alanında nokta zorunlu kılar ve ardışık noktaları reddeder, `filter_var` tırnaklı yerel kısımları kabul eder. Yani üretimde geçerli/geçersiz sayılan e-posta kümesi testlerdekinden farklı — GDPR export/erase eşleşmesi ve makbuz gönderimi kapsamını etkiler.

**Öneri**

`tests/bootstrap/wp-stubs.php`'ye WordPress'in `is_email()` semantiğine sadık bir stub ekle (yerel kısım/etki alanı ayrımı, etki alanında en az bir nokta, ardışık nokta reddi, izinli karakter seti) ve `NicePayPrivacyTest`'e `is_email` ile `filter_var`'ın ayrıştığı en az iki fixture ekle (`user@[127.0.0.1]`, `a@b`). Alternatif olarak üretimdeki `function_exists('is_email')` fallback'ini kaldırıp `is_email`'i zorunlu kıl (eklenti zaten WordPress içinde çalışıyor) — böylece test edilen kod = üretim kodu olur.

---

### TEST-010 — phpunit.xml sıkılık ayarları eksik: PHP 8.1+ deprecation'ları, test çıktısı ve global state değişiklikleri sessizce geçiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | test-config |
| **Konum** | [phpunit.xml:8](../../../phpunit.xml#L8) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```
`phpunit.xml:2-10` yalnızca `failOnRisky` ve `failOnWarning` ayarlıyor. Kurulu PHPUnit 9.6.36'nın `vendor/phpunit/phpunit/phpunit.xsd`'sinden doğruladığım varsayılanlar: `convertDeprecationsToExceptions` default="false" (xsd:216) — ayarlanmamış; `beStrictAboutOutputDuringTests` default="false" (xsd:237) — ayarlanmamış; `beStrictAboutChangesToGlobalState` default="false" (xsd:236) — ayarlanmamış; `executionOrder` ayarlanmamış. CI matrisi PHP 7.4→8.3'ü koşuyor (`.github/workflows/tests.yml:25`) ama `convertDeprecationsToExceptions="false"` olduğu için 8.1/8.2/8.3'te üretilen `E_DEPRECATED` bildirimleri testi kırmıyor.
```

**Başarısızlık senaryosu**

`nicepay_normalize_amount( $transaction->amount )` çağrısında `$transaction->amount` null olduğunda PHP 8.1'de `Deprecated: Passing null to parameter #1 of type string|int|float is deprecated` üretilir. Beş PHP sürümünde de suite yeşil kalır. PHP 9'a geçişte aynı çağrı `TypeError` fırlatır ve ödeme akışı fatal olur — geçiş sırasında hiçbir uyarı alınmamış olur.

**Etki**

Eklenti PHP 8.3 desteği iddia ediyor ama beş sürümlü matris deprecation'lara karşı hiçbir koruma sağlamıyor — matrisin ana varlık nedeni bu olmasına rağmen. Ayrıca test sırası bağımlılıkları (bkz. TEST-011) ve testler arası global sızıntı tespit edilemiyor.

**Öneri**

`phpunit.xml`'e ekle: `convertDeprecationsToExceptions="true" convertNoticesToExceptions="true" convertErrorsToExceptions="true" beStrictAboutOutputDuringTests="true" beStrictAboutChangesToGlobalState="true" executionOrder="random" resolveDependencies="true"`. `beStrictAboutChangesToGlobalState` başta çok sayıda hata verecektir (TEST-011); onları düzelttikten sonra açık bırak. `executionOrder="random"` ile CI'da seed değişken bırakılırsa sıralama bağımlılıkları otomatik ortaya çıkar. Ayrıca `<coverage>` bloğuna `<report>` ekle (bkz. TEST-005).

---

### TEST-011 — Test izolasyonu bozuk: dört test sınıfında tearDown yok, global $wpdb ve $wp_options sızıyor, suite sıra bağımlı

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | test-isolation |
| **Konum** | [tests/unit/NicePayBlocksIntegrationTest.php:102](../../../tests/unit/NicePayBlocksIntegrationTest.php#L102) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`NicePayBlocksIntegrationTest` `setUp()`'ta (102-125) `global $wpdb`'yi `new NicePayBlocksSchemaWpdbFake()` ile değiştiriyor ve beş `update_option()` çağrısı yapıyor — ama sınıfta HİÇ `tearDown()` yok. Aynı şekilde `NicePayTransactionsAdminTest`'te de `tearDown()` yok ve `test_unknown_or_missing_mode_is_not_inferred_from_current_settings` (80-95) `update_option('nicepay_mode', 'live')` yazıp temizlemiyor. `for f in tests/unit/*.php; do grep -q "function tearDown" $f || echo $f; done` dört dosyayı listeliyor: Blocks, StandaloneTemplate, TransactionSchema, TransactionsAdmin. `phpunit.xml`'de ne `processIsolation` ne `beStrictAboutChangesToGlobalState` açık; `<testsuite><directory>` alfabetik sıra kullanıyor. Blocks → Functions → GatewayDefaults zinciri bugün tesadüfen çalışıyor çünkü `NicePayFunctionsTest` yalnızca saf yardımcıları çağırıyor ve `NicePayGatewayDefaultsTest` `$wp_options`'ı sıfırlayarak `$wpdb`'ye hiç ulaşmıyor.
```

**Başarısızlık senaryosu**

`NicePayFunctionsTest`'e ledger'a dokunan yeni bir test eklenir (ör. `nicepay_get_transaction_by_moid` için). Tam suite koşumunda `NicePayBlocksIntegrationTest`'in sızdırdığı `NicePayBlocksSchemaWpdbFake` (yalnızca `get_var` ve `prepare` metodu var, `get_row` YOK) global `$wpdb` olarak kalmıştır → `Call to undefined method NicePayBlocksSchemaWpdbFake::get_row()` fatal error. Geliştirici `--filter NicePayFunctionsTest` ile koşunca test geçtiği için hatayı reprodüce edemez.

**Etki**

Suite'in yeşil kalması test sırasına ve hangi testlerin $wpdb'ye dokunduğuna dair tesadüfe bağlı. Yeni bir test eklemek veya `--filter` ile alt küme koşmak sahte hatalar (ya da daha kötüsü: sahte başarılar) üretebilir.

**Öneri**

Dört sınıfa da `tearDown()` ekle: `$wpdb`'yi `$this->previous_wpdb`'ye geri yükle (diğer sınıfların kullandığı desen, ör. `tests/unit/NicePayTransactionRepositoryTest.php:85-94`), `$wp_options = array()` ve `$wp_transients = array()` sıfırla. Daha kalıcı çözüm: ortak bir `tests/bootstrap/NicePayTestCase.php` temel sınıfı yaz; `setUp`/`tearDown`'da tüm global state'i (`$wp_options`, `$wp_transients`, `$wp_test_hooks`, `$wpdb`, `$_GET`, `$_POST`, `$_SERVER['REMOTE_ADDR']`, `$wp_remote_post_test_*`) deterministik olarak sıfırlasın ve tüm test sınıfları ondan türesin. Ardından `beStrictAboutChangesToGlobalState="true"` ve `executionOrder="random"` ile doğrula.

---

### TEST-012 — Zayıf ve tautolojik assertion'lar: assertTrue(true), sahte sabitin geri okunması, expiry cron'unun asıl UPDATE'inin hiç doğrulanmaması

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | assertion-quality |
| **Konum** | [tests/unit/NicePayFunctionsTest.php:488](../../../tests/unit/NicePayFunctionsTest.php#L488) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
1. `NicePayFunctionsTest.php:481-489` — `test_log_does_not_error_when_debug_off` üç `nicepay_log()` çağrısı yapıp `$this->assertTrue( true ); // No exception = pass` diyor; bu, `failOnRisky="true"` + `beStrictAboutTestsThatDoNotTestAnything` (xsd default true) korumasını bilerek atlatıyor. `nicepay_log`'un WP_DEBUG AÇIKKEN ne yaptığı (redaksiyon uygulanıyor mu, `wc_get_logger` dalı) hiç test edilmiyor.
2. `NicePayTransactionRepositoryTest.php:299-306` — `assertSame( 2, nicepay_expire_pending_transactions() )`. Üretim fonksiyonu iki ayrı `$wpdb->query()` çağırıyor ve fake `query()` her zaman `$this->query_result` = 1 döndürüyor → 1+1=2. Dahası fake yalnızca `last_query`'yi tutuyor, yani testin assert ettiği tek SQL İKİNCİ sorgu; asıl işi yapan BİRİNCİ `UPDATE ... SET status='expired', auth_token='', active_attempt_key=NULL` sorgusu hiç doğrulanmıyor. `false === $count` DB hata dalları da test edilmiyor.
3. `NicePayTransactionRepositoryTest.php:107` — `assertSame( 17, $result )` sadece fake'in `public $insert_id = 17;` sabitini geri okuyor.
4. Zayıf tip assertion'ları: `assertIsArray` (NicePayApiTest:163,449,515; TransportTest:76,104,125,135,157,431), `assertIsString` (FunctionsTest:199), `assertIsInt` ×4 (AdminOperationsTest:333-336), `assertNotEmpty` (RefundTest:244).
```

**Başarısızlık senaryosu**

`nicepay_expire_pending_transactions()`'daki ilk UPDATE'ten `auth_token = ''` kaldırılır (ör. "gereksiz" görülüp). `assertSame(2, ...)` hâlâ geçer (fake her sorguya 1 döner), `assertStringContainsString("status = 'needs_reconciliation'", ...)` hâlâ geçer (ikinci sorguya bakıyor). Üretimde süresi dolmuş binlerce satırda kullanılmamış AuthToken veritabanında kalır ve TEST-001'deki test edilmemiş return handler ile birleşince yeniden oynatma riski doğar.

**Etki**

Assertion sayısı gerçek doğrulama gücünü abartıyor. Özellikle expiry cron'unun asıl UPDATE'i (`auth_token = ''` ve `active_attempt_key = NULL` yazan, güvenlik açısından kritik satır) tamamen doğrulanmamış durumda.

**Öneri**

(1) `assertTrue(true)`'yu sil; `nicepay_log`'u gerçek testle değiştir — bir `nicepay_log_writer` seam'i veya yakalanabilir bir logger stub'ı ile log içeriğini yakala ve `nicepay_redact_log_data`'nın uygulandığını assert et. (2) `NicePayTransactionRepositoryWpdbFake`'e `public $queries = array();` ekle ve her `query()` çağrısını biriktir; `test_expiry_job...`'da `$wpdb->queries[0]` üzerinde `auth_token = ''`, `active_attempt_key = NULL`, `status = 'expired'` ve `offer_expires_at < UTC_TIMESTAMP()` assert et. Fake'e sorgu bazlı dönüş değeri ver (`$query_results = array(3, 1)`) ve `assertSame(4, ...)` ile toplamanın doğruluğunu kanıtla; ayrıca `false` dönüşü ile hata dalını test et. (3) `assertIsArray`/`assertIsString` yerine gerçek değer karşılaştırmaları kullan.

---

### TEST-013 — Entegrasyon matrisi tek bir WooCommerce ve tek bir WordPress imajına sabitlenmiş; iddia edilen minimum sürümler (WC 5.0, WP 5.8) hiç doğrulanmıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | coverage-gap |
| **Konum** | [tests/integration/run-woocommerce-smoke.sh:14](../../../tests/integration/run-woocommerce-smoke.sh#L14) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````bash
`tests/integration/run-woocommerce-smoke.sh:12-14`:
```bash
wordpress_image='wordpress@sha256:b427cec767f5de2aa649390cb8805aa1fe320e1e0d57fc1f467754edb6cc0a49'
wp_cli_image='wordpress@sha256:837d55d02196b5f4c92d236317c6d089ab1471348b31d1708888d444a0390979' # CLI 2.12.0, PHP 8.2
woocommerce_version='11.0.1'
```
Tek WC sürümü, tek WP imajı, tek PHP sürümü (8.2). `tests/integration/run-schema-migration.sh:11-13` aynı şekilde tek MariaDB 10.11 imajına sabit. Buna karşılık readme/eklenti başlığı WP >= 5.8, WC >= 5.0, PHP >= 7.4 desteği iddia ediyor ve unit matrisi beş PHP sürümü koşuyor. Ayrıca smoke test WC Blocks'un `AbstractPaymentMethodType` sınıfının varlığına bağlı (woocommerce-smoke.php:223-226) — WC 5.0'da bu sınıfın konumu farklıydı.
````

**Başarısızlık senaryosu**

Bir merchant WooCommerce 6.x çalıştırıyor. `NicePay_Blocks_Integration` `Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType`'ı extend ediyor; o sürümde sınıf ayrı `woocommerce-blocks` eklentisinde yaşadığı için mevcut değil → eklenti aktivasyonunda fatal error. CI tamamen yeşildir çünkü yalnızca WC 11.0.1 test edilmiştir.

**Etki**

"WooCommerce 5.0+ destekleniyor" iddiası test edilmemiş bir pazarlama cümlesi. HPOS/legacy matrisi doğru kurgulanmış ama tek eksende (order storage) çalışıyor; sürüm ekseni yok. Aynı şekilde MySQL 5.7 (WP'nin hâlâ desteklediği) altında `dbDelta` ve `DELETE ledger FROM ... JOIN` davranışı doğrulanmamış.

**Öneri**

`run-woocommerce-smoke.sh`'de `woocommerce_version`'ı parametre yap ve `.github/workflows/tests.yml`'de `woocommerce-integration` job'ına matris ekle: `wc: ['5.0.0', '8.0.0', '11.0.1']` (en azından minimum ve güncel). Aynı şekilde `database-integration` için MariaDB 10.4 + MySQL 8.0 ekseni ekle. `NicePay_Blocks_Integration`'ın yükleme guard'ını (`class_exists` kontrolü) minimum WC sürümünde doğrulayan bir assertion koy. Eğer minimum sürümler gerçekten test edilemeyecekse `readme.txt`'deki `Requires WooCommerce` değerini test edilen sürüme yükselt — dokümantasyon ile kanıt arasındaki boşluğu kapat.

---

### TEST-014 — Test sayısı çoğaltma ile şişirilmiş: imza ve result-code testleri iki dosyada birebir tekrar ediyor, ~25 test tek satırlık sözlük araması

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | test-redundancy |
| **Konum** | [tests/unit/NicePaySignatureIntegrationTest.php:127](../../../tests/unit/NicePaySignatureIntegrationTest.php#L127) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Birebir tekrarlar (aynı girdi, aynı beklenen literal): `NicePaySignatureIntegrationTest::test_doc_example_9_1` (48) ≡ `NicePayApiTest::test_create_auth_sign_data` (182); `9_2` (63) ≡ `test_verify_auth_signature_valid` (212); `9_3` (77) ≡ `test_create_approval_sign_data` (250); `9_5` (127) aynı dosyadaki 9_3 ile BİREBİR aynı çağrı ve aynı beklenen digest; `9_4` (96) ≡ `test_verify_approval_signature_valid` (268); `9_8` (179) 9_6 (147) ile birebir aynı (aynı tid/amt/imza); `9_7` (164) ≡ `test_create_cancel_sign_data` (291). `NicePayResultCodeTest` (37 vaka) `NicePayApiTest`'in 331-381 arasındaki dokuz `is_success_code`/`is_cancel_success` testiyle büyük ölçüde örtüşüyor. Ayrıca `NicePayFunctionsTest` içinde 91 vakadan ~25'i tek assertion'lık statik sözlük araması: `test_card_name_bc` (265)…`test_card_name_unknown_returns_code` (301), `test_bank_name_kdb` (309)…`test_bank_name_unknown_returns_code` (345), ve `test_status_label_pending/paid/failed/cancelled/refunded/waiting` (216-240) — sonuncular zaten `lifecycleStatusProvider` (243-256) ile aynı fonksiyonu data provider olarak test ediyor. Buna ek olarak `NicePayTransportTest.php:42` ve `:52` test stub'ının kendisini test ediyor.
```

**Başarısızlık senaryosu**

Bir gözden geçiren "412 test, hepsi geçiyor" görüp yayına onay verir. Gerçekte `handle_return()`'ın 313 satırında sıfır test vardır; sayının önemli bir kısmı `nicepay_get_card_name('01') === 'BC Card'` gibi risksiz eşlemelerden gelmektedir.

**Etki**

Toplam ~412 test vakasının yaklaşık dörtte biri ya birebir tekrar ya da sabit dizi araması. Bu, kapsam boşluklarını (TEST-001, TEST-002) sayısal olarak maskeliyor ve "N test geçiyor" güvencesini yanıltıcı kılıyor; ayrıca her sözlük genişletmesinde mekanik test yazma yükü doğuruyor.

**Öneri**

(1) `NicePaySignatureIntegrationTest`'ten 9_5 ve 9_8'i sil (dosya içi kopyalar) ve `NicePayApiTest`'teki yedi imza testini kaldırıp tek kanonik yer olarak `NicePaySignatureIntegrationTest`'i bırak. (2) `NicePayApiTest`'teki `is_success_code`/`is_cancel_success` testlerini kaldır, `NicePayResultCodeTest`'i tek kaynak yap. (3) 20 kart/banka adı testini iki data provider'a indir (`cardNameProvider`, `bankNameProvider`). (4) `test_status_label_*` altı testini `lifecycleStatusProvider`'a taşı. (5) İki stub-testini sil. (6) Kazanılan bakım bütçesini TEST-001 ve TEST-002'deki senaryo testlerine harca.

---

### TEST-015 — nicepay_http_connect_timeout filtresinin DÖNÜŞ değeri hiç doğrulanmıyor; hook'un URL guard'ının negatif dalı ölü

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | assertion-quality |
| **Konum** | [tests/unit/NicePayTransportTest.php:87](../../../tests/unit/NicePayTransportTest.php#L87) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Test filtre callback'i `return 4;` yapıyor ama sadece filtreye GİREN varsayılanı doğruluyor:
```php
// tests/unit/NicePayTransportTest.php:104-107
$this->assertIsArray( $result );
$this->assertSame( array( 5, 'approval' ), $seen );
$this->assertSame( 1, $wp_http_api_curl_test_invocations );
$this->assertArrayNotHasKey( 'http_api_curl', $wp_test_hooks );
```
Üretimde dönüş değeri `$connect_timeout = max( 1, min( (int) $args['timeout'], $connect_timeout ) );` (class-nicepay-api.php:251) ile kırpılıp `curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, $connect_timeout )` (:259) olarak uygulanıyor. Ne uygulanan değer ne de kırpma mantığı assert ediliyor. Ayrıca `$configure_curl` içindeki `if ( $request_url !== $url ) { return; }` guard'ı (256-258) hiç negatif olarak test edilmiyor — `wp-stubs.php:251` her zaman aynı `$url`'i geçiriyor, yani guard'ın "başka isteklere dokunma" davranışı ölü kod olarak kalıyor.
````

**Başarısızlık senaryosu**

Refactor sırasında `$connect_timeout = max( 1, min( (int) $args['timeout'], $connect_timeout ) );` satırı yanlışlıkla `$connect_timeout = 5;` olarak sadeleştirilir. Test hâlâ geçer (çünkü sadece filtreye giren 5'i kontrol ediyor). Yavaş bir NICEPAY DC'sine bağlanan merchant `nicepay_http_connect_timeout` filtresini 15'e çeker ama hiçbir etkisi olmaz; onaylar zaman aşımına uğramaya devam eder ve TEST-006'daki test edilmemiş mutabakat yoluna düşer.

**Etki**

`nicepay_http_connect_timeout` bir dokümante uzantı noktası. Filtre sonucunun yok sayıldığı veya kırpmanın ters çevrildiği bir regresyon testte görünmez; merchant'ın ayarladığı bağlantı zaman aşımı sessizce etkisiz kalır.

**Öneri**

`wp-stubs.php`'deki `wp_remote_post` stub'ını, `http_api_curl` hook'una geçirilen handle üzerine yapılan `curl_setopt` çağrılarını kaydeden bir seam ile genişlet (ör. global `$wp_curl_setopt_calls` biriktiren bir shim, veya `$configure_curl` closure'ını doğrudan çağıran bir yardımcı). Sonra assert et: filtre 4 döndüğünde `CURLOPT_CONNECTTIMEOUT = 4`; filtre 999 döndüğünde `min(timeout=30, 999) = 30`; filtre 0 döndüğünde `max(1, 0) = 1`. Ayrı bir test ekle: `do_action('http_api_curl', $handle, array(), 'https://baska-eklenti.example/')` çağrıldığında hiçbir `curl_setopt` yapılmadığını doğrula.

---

### TEST-016 — Test edilmeyen para yolu yardımcıları: iade claim'inin serbest bırakılması, standalone return URL üretimi, makbuz e-postası

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | coverage-gap |
| **Konum** | [includes/nicepay-functions.php:1155](../../../includes/nicepay-functions.php#L1155) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`includes/nicepay-functions.php`'deki 59 `nicepay_*` fonksiyonundan 10'u `tests/` altında hiç geçmiyor (isim bazlı grep ile doğrulandı): `nicepay_release_unsent_refund_claim`, `nicepay_get_standalone_return_url`, `nicepay_send_standalone_receipt_email`, `nicepay_utf8_byte_cut`, `nicepay_increment_integer_string`, `nicepay_manage_transactions_capability`, `nicepay_get_saved_shortcode`, `nicepay_localize_preset`, `nicepay_get_preset_labels`, `nicepay_buyer_field_limits`. Bunlardan üçü doğrudan para/güvenlik yolunda: `nicepay_release_unsent_refund_claim()` — `class-nicepay-gateway.php:799`'da audit kaydı oluşturulamadığında rezerve edilmiş bakiyeyi geri veren tek mekanizma; `nicepay_get_standalone_return_url()` — PG'nin auth sonucunu POST edeceği URL (`templates/standalone-payment-form.php:160`); `nicepay_send_standalone_receipt_email()` — `function_exists('wp_mail')` guard'lı (`:1171`) ve `wp_mail` hiç stub'lanmadığı için `nicepay_issue_standalone_receipt` testinde (`NicePayTransactionRepositoryTest.php:330-347`) bu dal sessizce atlanıyor; test yalnızca token hash'ini doğruluyor.
```

**Başarısızlık senaryosu**

`nicepay_save_refund_attempt()` bir DB hatası nedeniyle `false` döner. `nicepay_release_unsent_refund_claim()` yanlış `cancel_moid` ile çağrıldığı için 0 satır günceller ve `false` döner; ledger'da `cancel_status='requested'` kalır. Bundan sonra o siparişe hiçbir iade yapılamaz (`nicepay_claim_transaction_for_refund` `cancel_status NOT IN ('requested','unknown')` şartında takılır) ve `process_refund` her seferinde `nicepay_refund_state_error` döndürür. Merchant müşteriye iade yapamaz, hiçbir test uyarmaz.

**Etki**

İade kilidi serbest bırakma yolu (TEST-002'nin 7. dalı) hem çağıran tarafta hem fonksiyon seviyesinde test edilmemiş — birleşik bir kör nokta. Standalone akışının başlangıç ve bitiş URL'leri de doğrulanmamış.

**Öneri**

`NicePayTransactionRepositoryTest`'e `nicepay_release_unsent_refund_claim()` için iki test ekle: doğru `cancel_moid` ile çağrıldığında `UPDATE ... WHERE cancel_status = 'requested' AND cancel_moid = ...` üretip `cancel_status`'u geri açtığını, yanlış `cancel_moid` ile 0 satır etkileyip `false` döndüğünü assert et. `NicePayRefundTest`'te `$wpdb->insert()`'i `false` döndürecek şekilde ayarlayıp `nicepay_refund_audit_error` dalını uçtan uca kapsa. `wp-stubs.php`'ye çağrıları biriktiren bir `wp_mail` stub'ı ekle ve `nicepay_send_standalone_receipt_email`'in makbuz linkini gönderdiğini, PAN/AuthToken içermediğini doğrula. `nicepay_get_standalone_return_url()` için permalink açık/kapalı iki senaryoyu test et.

---

### TEST-017 — JS testleri hata yollarını hiç kapsamıyor: xhr.onerror, 403 nonce yenileme, success:false ve $.post().fail() dalları ölü

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | negative-test-gap |
| **Konum** | [tests/js/nicepay-multi-instance.test.js:58](../../../tests/js/nicepay-multi-instance.test.js#L58) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
`nicepay-multi-instance.test.js`'deki `FakeXMLHttpRequest` (58-89) HER ZAMAN `this.status = 200` ve `success: true` döndürüp `this.onload()` çağırıyor. `assets/js/nicepay.js` içindeki test edilmeyen dallar: `request.onerror` (301), `request.status === 403 && !wasRetried` → `nicepay_refresh_nonce` ile nonce yenileme ve isteği tekrarlama (306-326), `refresh.onerror` (310) ve nonce yanıtı geçersizken fallback (317), `request.status >= 200 && < 300 && response.success` false dalı (332), ve modal görüntüleme modu (`openModal`/`closeModal`, 394-479). `nicepay-admin.test.js`'de `$.post` stub'ı (48-56) `.fail()` metodunu döndürüyor ama callback'i HİÇ çağırmıyor; dolayısıyla `assets/js/nicepay-admin.js:285-287` ve `:343-345`'teki `requestFailed` toast dalları test edilmiyor — buna rağmen test fixture'ı `requestFailed: 'Request failed.'` i18n anahtarını tanımlıyor (satır 44), yani yazar bu dalı düşünmüş ama test etmemiş. Ayrıca JS için hiç kapsam ölçümü yok (`package.json:9` sadece `node --test`).
```

**Başarısızlık senaryosu**

`wasRetried` bayrağı bir refactor'da yanlış scope'a taşınır. Uzun süre açık kalan bir ödeme sayfasında nonce süresi dolar → 403 → yenile → tekrar dene → tekrar 403 → sonsuz istek döngüsü. Kullanıcı butona basar, hiçbir şey olmaz, sunucu rate-limit'e takılır. Üç JS testi de yeşildir çünkü hiçbiri 403 üretmiyor.

**Etki**

Ödeme başlatmanın en sık gerçek dünya hataları (süresi dolmuş nonce sonrası 403, rate limit, ağ kesintisi) tarayıcı tarafında hiç doğrulanmamış. Nonce yenileme mantığı özellikle kırılgan (iç içe iki XHR, `wasRetried` bayrağı) ve tek satırlık bir hata sonsuz döngü veya sessiz başarısızlık üretebilir.

**Öneri**

`createEnvironment()`'a yapılandırılabilir yanıt sırası ekle (`createEnvironment({ responses: [{status:403}, {status:200, body:{...}}] })`). Şu testleri yaz: (a) ilk istek 403 → nonce yenileme çağrısı yapıldı → ikinci istek yeni nonce ile gönderildi → form dolduruldu; (b) 403 → nonce yenileme de 403 → TAM OLARAK iki istek yapıldı, kullanıcıya hata mesajı gösterildi, sonsuz döngü yok; (c) `onerror` tetiklendiğinde `role="alert"` mesajı gösterildi ve buton yeniden etkinleştirildi; (d) `success:false` yanıtında form alanları boş kaldı ve `nicepayStart` çağrılmadı; (e) `display_mode: 'modal'` ile modal açılışı, odak tuzağı ve ESC ile kapanma. `nicepay-admin.test.js`'de `$.post` stub'ının `.fail(cb)` çağrısında `cb()`'yi tetikleyen bir varyant ekle. `package.json`'a `"test:coverage": "node --experimental-test-coverage --test tests/js/*.test.js"` ekleyip CI'da raporla.

---

### TEST-018 — Eşzamanlılık iddiaları yalnızca sıralı çağrılarla ve SQL metin eşleşmesiyle kanıtlanıyor; gerçek paralel bağlantı testi yok

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | coverage-gap |
| **Konum** | [tests/unit/NicePayTransactionRepositoryTest.php:114](../../../tests/unit/NicePayTransactionRepositoryTest.php#L114) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Unit seviyede atomiklik yalnızca üretilen SQL metnine bakılarak iddia ediliyor:
```php
// tests/unit/NicePayTransactionRepositoryTest.php:117-122
$this->assertTrue( nicepay_claim_transaction_for_approval( 17, 'standalone' ) );
$this->assertStringContainsString( "status = 'approving'", $wpdb->last_query );
$this->assertStringContainsString( "status = 'pending'", $wpdb->last_query );
```
Fake `query()` her zaman `$query_result` (varsayılan 1) döndürdüğü için compare-and-set semantiği değil, string varlığı doğrulanıyor. Entegrasyon tarafı daha iyi (`tests/integration/schema-migration.php:124-129, 151-152`) ama oradaki çağrılar da SIRALI: tek bir PHP process'inde tek bir DB bağlantısı üzerinden. Gerçek yarış (iki eşzamanlı bağlantının aynı satıra aynı anda `UPDATE ... WHERE status='pending'` çalıştırması, veya `nicepay_abandon_pending_transactions()` ile `INSERT` arasındaki pencerede ikinci bir formun araya girmesi) hiçbir yerde simüle edilmiyor. Benzer şekilde `NicePay_Retention::purge_batch()`'in `START TRANSACTION`/`ROLLBACK` davranışı yalnızca fake ile test ediliyor (`NicePayRetentionTest.php:148-158`); gerçek InnoDB rollback'i entegrasyonda hiç çalıştırılmıyor.
````

**Başarısızlık senaryosu**

`purge_batch()` çalışırken başka bir istek aynı satırlara dokunur ve deadlock oluşur. `$wpdb->query()` `false` döner; kod bu dönüşü `null === $ledger_delete_result` gibi ele almadığı için `ROLLBACK` yapılmaz ve refund_attempts satırları kalıcı olarak silinmiş, parent transaction satırları ise duruyor halde kalır. `NicePayRetentionTest` fake'i asla `false` döndürmediği için bu dal hiç görülmez.

**Etki**

Sıralı testler bir CAS'in MySQL'de atomik olduğunu satır kilidi sayesinde kanıtlar ama izolasyon seviyesine bağlı davranışları (REPEATABLE READ altında `SELECT ... FOR UPDATE` + `DELETE` kombinasyonu, deadlock, lock wait timeout) kanıtlamaz. Retention rollback'i özellikle riskli: gerçek bir `ROLLBACK` başarısız olursa refund_attempts satırları silinmiş ama parent transaction'lar kalmış olur (yetim kayıtlar).

**Öneri**

`tests/integration/schema-migration.php`'ye gerçek eşzamanlılık ekle: `mysqli` ile ikinci bir bağlantı aç ve aynı satır üzerinde iki `nicepay_claim_transaction_for_approval` eşdeğeri UPDATE'i, birinci bağlantıyı `SELECT ... FOR UPDATE` ile kilitli tutarken çalıştır; ikincinin 0 satır etkilediğini doğrula. `purge_batch` için: işlem içindeyken ikinci bağlantıdan bir satırı sil (parent delete sayısını değiştir), `nicepay_retention_delete_mismatch` döndüğünü VE refund_attempts satırlarının hâlâ mevcut olduğunu (gerçek ROLLBACK) assert et. Ayrıca `SET SESSION innodb_lock_wait_timeout = 1` ile lock timeout dalını ve `$wpdb->query()` `false` döndüğünde ROLLBACK yapıldığını test et.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İNCELENENLER (tamamı baştan sona okundu): tests/bootstrap/bootstrap.php (28 satır) ve tests/bootstrap/wp-stubs.php (381 satır, her stub tek tek denetlendi); phpunit.xml ve kurulu PHPUnit 9.6.36'nın vendor/phpunit/phpunit/phpunit.xsd'sindeki varsayılan değerler (convertDeprecationsToExceptions, beStrictAbout* için birebir doğrulandı); tests/unit altındaki 20 dosyanın tamamı (264 test metodu); tests/fixtures/blocks-abstract-payment-method-type.php; tests/js altındaki 3 dosyanın tamamı; tests/integration/*.sh (2) ve *.php (2) tam metin; .github/workflows/tests.yml; composer.json + package.json script'leri; .github/scripts/lint-php.sh ve check-js.js; eslint.config.js; CONTRIBUTING.md'nin test bölümü.

ÇAPRAZ DOĞRULAMA: NicePaySignatureIntegrationTest ve NicePayApiTest'teki altı golden digest'i Python/hashlib ile bağımsız yeniden hesapladım — hepsi doğru, dolayısıyla tautolojik değiller. Test edilmeyen dalları belirlemek için üretim kodunu da okudum: includes/class-nicepay-gateway.php:690-919 (process_refund'ın tamamı), includes/class-nicepay-api.php:236-330 (post_to_nicepay + decode_response), includes/class-nicepay-inbound-validator.php (299 satır, tamamı), includes/class-nicepay-privacy.php'nin ilgili bölümleri ve nicepay_expire_pending_transactions(). Kapsam boşluklarını `grep -rn` ile ve nicepay-functions.php'deki 59 fonksiyonun tests/ altında referans alınıp alınmadığını script'le doğruladım. Tüm bulgu satır numaraları dosyaların şu anki halinden `cat -n`/`grep -n` ile alındı.

İNCELENEMEYENLER: (1) PHP bu makinede kurulu değil (`php: command not found`), dolayısıyla suite'i gerçekten koşamadım — test sayıları statik analizle (264 metot) ve yerel `.phpunit.result.cache`'teki 412 vaka kaydıyla çapraz kontrol edildi; ancak o cache eski (artık var olmayan `test_get_amount_krw_strips_decimals` içeriyor), bu yüzden "~412" yaklaşık bir değerdir ve gerçek assertion sayısını doğrulayamadım. (2) Docker olmadığı için iki entegrasyon script'ini çalıştıramadım; değerlendirme yalnızca kaynak okumasına dayanıyor — `wp eval-file`'ın RuntimeException'da gerçekten nonzero çıkış kodu ürettiğini deneysel olarak doğrulayamadım (WP-CLI davranışına dayanarak varsaydım). (3) Gerçek kapsam yüzdesini üretemedim (xdebug + PHP gerekiyor), bu yüzden TEST-005 kapsam yapılandırmasının yapısal kusuruna dayanıyor, ölçülen bir sayıya değil. (4) docs/analysis/09-testing.md (1025 satır) yalnızca "393 test" iddiasının kaynağını aramak için tarandı; o dosya bu PR'dan ÖNCEKİ duruma ait bir analiz raporu olduğu için bulgu kanıtı olarak kullanılmadı. (5) tests/js için jsdom'un gerçek tarayıcıya sadakati (özellikle odak yönetimi ve KeyboardEvent Tab davranışı) değerlendirilmedi. (6) TEST-018'deki `purge_batch` deadlock senaryosu için `NicePay_Retention::purge_batch()`'in tam kaynağını satır satır okumadım; bu yüzden confidence "medium" işaretlendi.

**Açık sorular**

- "393 test / 1112 assertion" rakamı hangi commit'te ve hangi komutla üretildi? Statik sayımım 264 test metodu / ~412 data-provider genişlemiş vaka veriyor. Bu rakam bir yerde (PR açıklaması, release notu, readme) yayımlanacaksa güncel bir `phpunit --testdox` çıktısıyla yeniden üretilmeli.
- `class-nicepay-return-handler.php` ve `handle_return()` için test yazılmaması bilinçli bir karar mı yoksa kapsam boşluğu mu? Eğer "entegrasyon testinde kapsanıyor" varsayımıyla atlandıysa, woocommerce-smoke.php o yolu hiç çalıştırmıyor — bu boşluğun en azından kayda geçirilmesi gerekir.
- `tests/integration/run-schema-migration.sh:21-34`'teki `cleanup()` fonksiyonu, `run-woocommerce-smoke.sh:25-38`'de bulunan container/network adı regex guard'larını içermiyor. Bu bilinçli bir fark mı, yoksa ikinci script yazılırken yapılan sertleştirme ilkine geri taşınmayı unuttu mu?
- Entegrasyon job'larında `timeout-minutes` tanımlı değil ve her ikisi de 30×2s'lik hazır-olma döngüleri içeriyor. Docker imajı çekilemediğinde veya MariaDB hiç ayağa kalkmadığında job GitHub'ın varsayılan 6 saatlik limitine kadar asılı kalabilir — kasıtlı mı?
- `nicepay_log()`'un WP_DEBUG açıkken davranışı (özellikle `function_exists('wc_get_logger')` dalı) neden hiç test edilmiyor? Redaksiyon fonksiyonu (`nicepay_redact_log_data`) ayrıca test ediliyor ama loglayıcının onu gerçekten çağırdığı doğrulanmıyor — bu kabul edilen bir risk mi?
- Coverage artifact'ı yalnızca 5 gün saklanıyor ve hiçbir eşiğe bağlı değil. Kapsam metriğinin amacı nedir — insan incelemesi mi, yoksa ileride gate'e bağlanması mı planlanıyor?
- `nicepay_utf8_byte_cut()` doğrudan test edilmiyor (yalnızca `NicePayOfferResolverTest::test_truncates_goods_name_to_40_utf8_bytes_without_splitting_a_character` üzerinden dolaylı). `function_exists('mb_strcut')` fallback dalı mbstring olmayan bir ortamda ne yapıyor ve bu senaryo desteklenen bir kurulum mu?

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 18 |

## Öncelikli aksiyon listesi

1. **TEST-001** — `handle_return()` ve `NicePay_Return_Handler::process()` için `$_POST`, `$wpdb` fake'i, kuyruklanmış `wp_remote_post` yanıtı ve WC_Order fake'i ile senaryo testleri yaz. En az şu vakalar: (a) mutlu yol → ledger `paid` + `payment_complete()` çağrıldı + `active_attempt_key = null`; (b) validator hatası → hiç HTTP isteği yok ve `nicepay_abort_authenticated_payment()` çağrıldı; (c) claim başarısız (`$
2. **TEST-002** — `NicePayRefundTest`'e altı test daha ekle. `$wp_remote_post_test_queue` boş bırakarak (stub WP_Error döner) timeout dalını; `cancelResponse('400')` kuyruklayarak binding mismatch dalını; `ResultCode => '3001'` ile red dalını; `$wpdb->update_result = 0` ile post-confirm eskalasyonunu; `$nicepay_refund_test_order->total_refunded = '1000'` + `process_refund(42, '1000', ...)` ile tam iade dalını (`ass
3. **TEST-005** — `<include>` bloğuna `admin`, `templates` dizinlerini ve `<file>nicepay-payment-gateway.php</file>`'yi ekle. `<report><clover outputFile="tests/coverage/clover.xml"/><text outputFile="php://stdout"/></report>` ekle. CI'a `composer test-coverage` sonrası bir eşik kontrolü koy (ör. clover XML'i ayrıştırıp `includes/class-nicepay-gateway.php` ve `includes/class-nicepay-return-handler.php` için satır k
4. **TEST-007** — `tests/bootstrap/wp-stubs.php`'ye kontrol edilebilir `wp_verify_nonce($nonce, $action)`, `wp_create_nonce($action)`, `check_ajax_referer()` ve exception fırlatan `wp_send_json_success/error` stub'ları ekle — nonce stub'ı gerçek bir action↔nonce eşlemesi tutsun, böylece YANLIŞ action ile çağrı başarısız olsun. Sonra her dört handler için üç test yaz: geçerli nonce → başarı; eksik nonce → 403/`wp_se
