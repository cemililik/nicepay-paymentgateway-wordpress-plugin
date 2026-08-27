# 16 — Kapsam Boşlukları ve Ek Bulgular

**Rol:** Eksiksizlik denetçisi (completeness critic)
**Girdi:** 14 boyut / 296 doğrulanmış bulgu (`01`–`14`), PR #3 (`origin/main...development`, 113 dosya, +43.412 / −3.292)
**Yöntem:** (a) 113 dosyanın tamamı listelendi, 296 bulgunun konum alanlarında hiç adı geçmeyen dosyalar çıkarıldı ve tek tek açılıp okundu; (b) hiçbir boyutun sahiplenmediği yedi inceleme açısı (lisans/uyumluluk, PCI-DSS kapsamı, performans/kilitlenme, gözlemlenebilirlik, yükseltme/geri alma, çoklu site/çoklu para birimi, üçüncü parti çakışmaları, REST/webhook yüzeyi) doğrudan kod üzerinde araştırıldı.
**Kural:** Her GAP bulgusu okunmuş koda ve dosyanın şu anki hâlindeki satır numarasına dayanır. Spekülatif olanlar `confidence: low` ile işaretlidir.

---

## İncelenmemiş Dosyalar

296 bulgunun hiçbirinde konum olarak geçmeyen dosyalar. "Karar" sütunu, dosyayı açıp okuduktan sonraki hükmümdür.

| Dosya | Satır (diff) | Karar |
|---|---|---|
| `includes/class-nicepay-offer-resolver.php` | +198 | **KÖR NOKTA.** Sunucu-otoriteli teklif çözümleyici — standalone akışın tüm ticari verisinin tek kaynağı — ve hakkında tek bir bulgu yok (yalnızca `06` boyutunun açık sorusunda `config_fingerprint` bağlamında anılıyor). Okundu: mantık sağlam (KRW zorunlu, method allowlist, `//u` UTF-8 doğrulaması, yapısal girdi reddi). İki yeni bulgu çıktı → **GAP-009**, **GAP-010**. |
| `composer.json` | +14 / −5 | **KÖR NOKTA.** Yalnızca CI bağlamında (`composer audit`, `composer validate`) anıldı; `"license": "MIT"` satırı hiçbir boyutta incelenmemiş → **GAP-001**. |
| `LICENSE` (diff dışı, pakete giriyor) | — | **KÖR NOKTA.** `build-release.sh:58` ile ZIP'e kopyalanıyor; içeriği (MIT) hiçbir boyutta okunmamış → **GAP-001**. |
| `.github/CODEOWNERS` | +1 | **Kısmen kör nokta.** İçerik tek satır: `* @cemililik`. Yönetişim/bus-factor açısı hiç ele alınmamış → **GAP-013** (low). |
| `.github/ISSUE_TEMPLATE/config.yml` | +5 | Önemsiz. `blank_issues_enabled: true` + özel güvenlik advisory bağlantısı; `13` boyutunun SECURITY.md bulgularıyla tutarlı, ek sorun yok. |
| `package.json` | +22 | Büyük ölçüde önemsiz. `"private": true` doğru; `"version": "2.0.0"` `check-version.js` kapsamı dışında (CI-004 zaten bunu söylüyor, yeni bulgu değil). Bağımlılıkların tamamı `devDependencies` ve `build-release.sh` `node_modules`'ü hiç kopyalamıyor → dağıtılan pakette üçüncü parti JS lisansı yok (GAP-001'in kapsamını daraltan olumlu bir gerçek). |
| `tests/bootstrap/bootstrap.php` | +2 | Önemsiz. Diff'te yalnızca iki `require_once` satırı; `NICEPAY_VERSION` sabiti `check-version.js` tarafından zaten kapılı. |
| `tests/fixtures/blocks-abstract-payment-method-type.php` | +18 | **Düşük risk, kapatıldı.** WooCommerce Blocks'un `AbstractPaymentMethodType` sözleşmesinin elle yazılmış kopyası — normalde sürüm sürüklenmesi riski. Ancak `tests/integration/woocommerce-smoke.php:220-233` aynı entegrasyonu **gerçek** WooCommerce sınıfına karşı örnekliyor, yani sürüklenme CI'da yakalanır. Kalan risk yalnızca tek pinlenmiş WC sürümüyle sınırlı → mevcut CI-007'nin altında. |
| `tests/unit/NicePayRateLimitTest.php` | +56 | **KÖR NOKTA (ters yönde).** Test, `REMOTE_ADDR` geçersizken **fail-open** davranışını (satır 48-54) *kasıtlı davranış olarak* çiviliyor. Yani SEC-016'nın "fail-open" bulgusu düzeltilirse bu test kırılacak ve düzeltme bir regresyon gibi görünecek → **GAP-006**'da kanıt olarak kullanıldı. |
| `tests/unit/NicePayInstallerTest.php` | +237 | İncelendi, sağlam. 10 test; duplicate Moid/TID blokajı, sürüm ilerletmeme, eksik tablo fail-closed. DATA-015'in "gerçek v1 fixture'ı yok" tespitini doğruluyor (sahte `wpdb` ile çalışıyor), yeni bulgu yok. |
| `tests/unit/NicePayPrivacyTest.php` | +112 | İncelendi, sağlam. 4 test; TEST-009'un `is_email()` stub eksikliği tespitiyle tutarlı, yeni bulgu yok. |
| `tests/unit/NicePayRetentionTest.php` | +180 | İncelendi, sağlam. 6 test; rollback ve eligible-count guard'ları test ediliyor. DATA-008'in "InnoDB varsayımı" tespitini değiştirmiyor. |
| `tests/unit/NicePayTransactionSchemaTest.php` | +112 | İncelendi, sağlam. 6 test; `prepare_write` allowlist'i ve dbDelta uyumu pinlenmiş. ARCH-017/DATA-035'in "sessizce düşürme" tespitini doğruluyor (test bunu *beklenen* davranış olarak yazıyor). |
| `tests/unit/NicePayRefundTest.php` | +286 | İncelendi. 9 test, **hepsi transport öncesi** dallar (`process_refund` çağrıları 145–238 satırları). TEST-002'nin "transport sonrası dallar test edilmemiş" iddiasıyla **çelişmiyor**, onu doğruluyor. |
| `tests/unit/NicePayGatewayDefaultsTest.php` | +76 | Zayıf ama zararsız: 76 satırda tek test (`test_fresh_gateway_is_disabled_until_merchant_enables_it`). TEST-014'ün "test sayısı şişkinliği" tespitine ek bir örnek. |
| `languages/*.mo` (4 dosya) | binary | Önemsiz. `check-translations.sh:72-78` `msgfmt` + `cmp` ile tazeliği zorunlu kılıyor. |
| `.gitignore` | −3 | Önemsiz; `composer.lock` takibe alınması `12` boyutunda zaten olumlu olarak kaydedilmiş. |
| `docs/analysis/02,03,06,07,08,09,11` | +9.400 | Tek tek incelenmemiş ama META-001/META-009'un blanket kapsamına giriyor; ek bulgu üretmedim. |

**Sonuç:** 113 dosyadan 18'i hiçbir bulguda geçmiyor. Bunlardan **4'ü gerçek kör nokta** (`class-nicepay-offer-resolver.php`, `composer.json`, `LICENSE`, `.github/CODEOWNERS`), 1'i ters yönde kör nokta (`NicePayRateLimitTest.php`), kalan 13'ü gerçekten önemsiz veya zaten dolaylı olarak kapsanmış.

---

## Kapsanmamış İnceleme Açıları

### 1. Lisans / uyumluluk — **hiçbir boyut tarafından ele alınmamış**

Dört ayrı yerde lisans beyanı var ve dördü de tutarlı biçimde **MIT**:

| Yer | Satır | Değer |
|---|---|---|
| `nicepay-payment-gateway.php` | 15 | `* License: MIT` |
| `readme.txt` | 7-8 | `License: MIT` / `License URI: https://opensource.org/license/mit/` |
| `composer.json` | 5 | `"license": "MIT"` |
| `LICENSE` | 1-21 | MIT License, `Copyright (c) 2026 cemililik` |

Tutarlılık kapısı da var: `deploy-wordpress-org.yml:106-127` `readme.txt` ve plugin header'ının lisans dizesinin **eşit** olmasını şart koşuyor. Ancak hiçbir kapı lisansın **GPL-uyumlu** olup olmadığını ya da **türev eser** ilişkisini kontrol etmiyor. Ayrıntı ve öneri: **GAP-001**.

Dağıtılan pakette üçüncü parti kod yok: `build-release.sh:41-64` yalnızca `admin assets includes languages templates` dizinlerini ve 8 kök dosyayı kopyalıyor; `vendor/` ve `node_modules/` hiç kopyalanmıyor ve `smoke-check-artifact.sh:48-57` bunları ayrıca yasaklıyor. Dolayısıyla `composer.lock`/`package-lock.json` bağımlılık lisansları dağıtım kapsamına **girmiyor** — bu açı temiz.

### 2. PCI-DSS SAQ kapsamı — **hiçbir boyut tarafından ele alınmamış**

Doğruladığım olumlu gerçek: **kart verisi eklentiye hiç değmiyor.** `grep -rniE "cardno|card_number|cvc|cvv|expiry|pan"` üretim kodunda yalnızca (a) redaksiyon anahtar listesi (`nicepay-functions.php:59`), (b) legacy temizlik (`class-nicepay-installer.php:271-282`), (c) `CardCode`/`CardQuota` (kart şirketi kodu ve taksit sayısı — PAN değil) eşleşmesi veriyor. Kart numarası girişi için tek bir form alanı yok.

Ancak **kapsam bundan ibaret değil**: ödeme sayfası satıcının kendi sayfası ve üçüncü parti betik o sayfaya yükleniyor (`templates/payment-form.php:56-60` + `nicepay-payment-gateway.php:490-491` + `assets/js/nicepay.js:481-484`). Bu, PCI DSS v4.0 altında SAQ A ile SAQ A-EP arasındaki ayrımı belirleyen tam olarak o yapılandırma ve `docs/` içinde tek bir `PCI`/`SAQ` dizesi yok. Ayrıntı: **GAP-002**.

### 3. Performans / ölçeklenebilirlik

Boyut raporları admin sayfası sorgu sayısını (ADMIN-008, DATA-030) ve indeks kapsamını (DATA-009) ele almış. **Ele alınmamış olan: cron'un yazma yolu.** `nicepay_expire_pending_transactions()` (`includes/nicepay-functions.php:958-978`) iki adet **sınırsız, batch'siz, kilitsiz** `UPDATE` çalıştırıyor — oysa aynı kod tabanındaki retention işi bilinçli olarak 100'lük partiler + `add_option` kilidiyle çalışıyor. Ayrıntı: **GAP-003**.

İkinci ele alınmamış performans yüzeyi: kimliksiz uç noktaların `wp_options` yazma amplifikasyonu (`nicepay_check_public_rate_limit`, `includes/nicepay-functions.php:286-330`). Ayrıntı: **GAP-006**.

Üçüncüsü: `maybe_install_schema()` her istekte cron zamanlamasını senkronize ediyor (`nicepay-payment-gateway.php:202-213` → `class-nicepay-retention.php:113-123`). Ayrıntı: **GAP-011**.

**Kilit davranışı hakkında olumlu tespit:** `nicepay_claim_transaction_for_approval()`'ın WooCommerce dalı (`nicepay-functions.php:665-686`) `UPDATE ... INNER JOIN` kullanıyor ve `candidate` tarafı `(flow, source_ref)` üzerinden joinleniyor. `idx_source_ref (flow, source_ref)` şemada mevcut (`class-nicepay-transaction-schema.php:133`), yani normal koşulda bu UPDATE indeksli. Ancak DATA-019'un dediği gibi bu indeks eski InnoDB'de oluşmazsa, aynı sorgu **ödeme sıcak yolunda tam tablo taramalı bir UPDATE**'e döner ve InnoDB defterdeki *her* satıra next-key kilidi koyar — yani DATA-019'un sonucu "sorgular yavaşlar" değil, "tüm ödemeler serileşir ve deadlock üretir" olur. Bu, mevcut DATA-019'un etki tarifinin eksik kaldığı noktadır; ayrı bir bulgu açmıyorum ama DATA-019'un severity gerekçesine eklenmelidir.

### 4. Gözlemlenebilirlik

`nicepay_log()` altyapısı iyi: seviye allowlist'i, CRLF temizliği, `nicepay_redact_log_data()` ile redaksiyon, WooCommerce logger'a `source => nicepay` ile yazma. Ancak dosya bazında log çağrısı sayımı ciddi bir asimetri gösteriyor:

```
class-nicepay-api.php          16      class-nicepay-inbound-validator.php    0
nicepay-functions.php          16      class-nicepay-offer-resolver.php       0
class-nicepay-gateway.php      17      class-nicepay-installer.php            0
class-nicepay-return-handler.php 8     admin/class-nicepay-admin.php          0
class-nicepay-retention.php     2      admin/class-nicepay-transactions.php   0
nicepay-payment-gateway.php     2      class-nicepay-privacy.php              0
```

Yani **her ödeme reddinin gerçek nedenini üreten sınıf (inbound validator) ve teklif çözümleyici hiç log yazmıyor**; yalnızca çağıranları tek bir hata kodu dizesi logluyor. Ayrıntı: **GAP-010**.

### 5. 1.x → 2.0 yükseltme yolu ve **geri alma**

Yükseltme yolu `04` boyutunda derinlemesine incelenmiş (DATA-001, DATA-002, DATA-018, DATA-020, DATA-022). **Geri alma (rollback) hiç incelenmemiş.** `grep -rn "rollback\|roll back\|downgrade"` tüm dokümantasyonda yalnızca *ödeme ters çevirme* anlamında eşleşiyor; sürüm geri alma anlamında tek satır yok. Kodda da `NicePay_Installer` sürüm karşılaştırmasını `version_compare` ile değil düz dize eşitsizliğiyle yapıyor (`class-nicepay-installer.php:40`, `:120`, `:128`). Ayrıntı: **GAP-004**.

`readme.txt:99-103`'teki `== Upgrade Notice ==` — WordPress'in güncelleme diyaloğunda satıcıya gösterdiği tek metin — yalnızca "Review all settings after upgrading" diyor: ne kırıcı değişiklik uyarısı, ne zorunlu yedek adımı, oysa migrasyon `payment_data`'yı geri alınamaz biçimde NULL'lıyor (DATA-003/DATA-028) ve tamamen bloke olabiliyor (DATA-001). DOC-002 bunu CHANGELOG üzerinden yakalamış ama `readme.txt`'in bu bölümüne bakmamış; GAP-004'ün kanıt bölümüne ekledim.

### 6. Çoklu site / çoklu para birimi

**Çoklu site:** `05` (PLAT-005, PLAT-006) ve `04` (DATA-016, DATA-034) boyutları ana sorunları yakalamış. Eksik kalan tek nokta: PLAT-005 `flush_rewrite_rules()`'ün `switch_to_blog()` içinde çağrıldığı yerlerden yalnızca **birini** (aktivasyon, `:170`) gösteriyor; aynı hata deaktivasyon yolunda da var (`:191-195`, `:178-183` içinden çağrılıyor) → **GAP-012**. `install_new_site()` (`:97` kancası) okundu; `switch_to_blog`/`restore_current_blog` çifti `try/finally` ile doğru, `wp_error` durumunda `wp_die` yerine log kullanıyor — aktivasyon yolundan daha iyi.

**Çoklu para birimi:** hiçbir boyut ele almamış. `is_available()` mağaza/görüntüleme para birimini (`get_woocommerce_currency()`, `class-nicepay-gateway.php:93-96`), `generate_payment_form()` ise **siparişin** para birimini (`$order->get_currency()`, `:213`) kullanıyor. İkisi ayrıştığında sipariş oluşuyor, stok düşüyor ve alıcı çıkışsız bir hata sayfasında kalıyor. Ayrıntı: **GAP-005**. Ayrıca tek seçenekli `nicepay_currency` ayarı → **GAP-008**.

### 7. Üçüncü parti eklenti çakışmaları

- **Sayfa önbelleği (WP Rocket / LiteSpeed / W3TC / Cloudflare APO):** gerçekten ele alınmış. `ajax_refresh_payment_nonce` (`nicepay-payment-gateway.php:634-650`) + `assets/js/nicepay.js:306-328` bayat nonce'u tek denemeyle tazeliyor. Standalone sonuç sayfası POST-only, makbuz sayfası `nocache_headers()` + `X-Robots-Tag` taşıyor. **Bu açı temiz** (kalan zayıflık JS-010'un teşhis mesajı sorunu).
- **Ters proxy / Cloudflare (`REMOTE_ADDR`):** SEC-016 kapsıyor; ek olarak fail-open'ın testle çivilendiğini tespit ettim → GAP-006.
- **Diğer PG eklentileri / ikinci bir PG-Web entegrasyonu:** `assets/js/nicepay.js` dört global tanımlıyor — `window.NicePayHandler` (`:444`), `window.nicepaySubmit` (`:481`), `window.nicepayClose` (`:486`), ve `window.nicepayStart` okuması (`:68`, `:333`). `nicepaySubmit`/`nicepayClose` PG-Web v3 protokolünün zorunlu kıldığı **sabit adlar**, dolayısıyla namespace'lenemez; ancak hiçbiri "zaten tanımlı mı" kontrolü yapmıyor ve son yüklenen dosya öncekini sessizce eziyor. JS-013 bu ailenin `document.payForm` yarısını kapsıyor; global callback yarısı kapsanmamış → GAP-005'in kapsamı dışında, aşağıda **GAP-012 notu** olarak değil, JS-013'ün genişletilmesi olarak kaydediyorum (aynı kök neden: küresel ad alanına dayalı sözleşme). Yeni bulgu açmıyorum.
- **Güvenlik eklentileri (Wordfence / iThemes / Sucuri):** NICEPAY'in dönüş POST'u `pg-web.nicepay.co.kr`'den `/?wc-api=nicepay_return`'e çapraz-site POST'tur. Bu tür eklentilerin "harici referrer'lı POST" ve "bilinmeyen POST parametreleri" kuralları bu isteği engelleyebilir; eklenti bunu ne belgeliyor ne de teşhis edilebilir kılıyor (istek hiç ulaşmadığı için hiçbir log satırı üretilmez). `confidence: low` — somut bir kural adı doğrulayamadım; **Açık Kalan Belirsizlikler**'e taşındım.

### 8. REST API / webhook yüzeyi

Doğrulandı: **`register_rest_route` kod tabanında hiç yok.** Tek "API" yüzeyi WooCommerce'in legacy `woocommerce_api_nicepay_return` kancası (`class-nicepay-gateway.php:36`) ve `template_redirect` üzerindeki iki standalone uç (`nicepay-payment-gateway.php:102-103`). Yani REST saldırı yüzeyi sıfır — bu bilinçli ve doğru bir tercih.

**Ancak eksik olan yüzey de önemli:** NICEPAY'den **sunucudan sunucuya webhook yok**. Tüm dönüşler tarayıcı üzerinden gelen POST'lar. Bunun üç sonucu var ve hiçbiri belgelenmemiş: (a) alıcı ödeme penceresini onayladıktan sonra tarayıcıyı kapatırsa mağaza sonucu **hiç** öğrenemez (yalnızca 30 dakika sonra `stale_approval_attempt` olarak `needs_reconciliation`'a düşer — MONEY-016); (b) VBANK yatırma bildirimi için gerekli olan tek mekanizma budur ve yok (PROTO-009/MONEY-012'nin gerçek nedeni); (c) PLAT-009'un çerez sorusu ("POST tarayıcıdan mı sunucudan mı geliyor") kod tarafında kesin olarak **tarayıcıdan** yanıtlanıyor — bu açık soruyu kapatıyorum.

---

## Ek Bulgular

### GAP-001 — Lisans çelişkisi: GPL türev eseri MIT olarak dağıtılıyor, hiçbir kapı bunu kontrol etmiyor
- **severity:** high · **confidence:** high (tutarsızlık) / medium (WP.org sonucu)
- **kategori:** lisans/uyumluluk
- **konum:** `nicepay-payment-gateway.php:15`, `readme.txt:7-8`, `composer.json:5`, `LICENSE:1-21`, `includes/class-nicepay-gateway.php:12`, `includes/class-nicepay-blocks-integration.php:15`, `.github/workflows/deploy-wordpress-org.yml:106-127`

**Kanıt.** Eklentinin iki ana sınıfı üçüncü parti GPL kodundan türetiliyor:
```php
// includes/class-nicepay-gateway.php:12
class WC_Gateway_NicePay extends WC_Payment_Gateway {
// includes/class-nicepay-blocks-integration.php:15
final class NicePay_Blocks_Integration extends AbstractPaymentMethodType {
```
WooCommerce ve WooCommerce Blocks GPLv3 lisanslıdır. Buna karşılık dağıtılan dört lisans beyanının dördü de MIT (yukarıdaki tablo). `LICENSE:8` açıkça `sublicense, and/or sell` hakkı veriyor. Yayın hattındaki tek lisans kapısı ise sadece **eşitlik** kontrol ediyor:
```bash
# .github/workflows/deploy-wordpress-org.yml:124-127
[[ "$readme_license" == "$plugin_license" ]] || {
  echo 'ERROR: License differs between readme.txt and the plugin header.' >&2
```
GPL-uyumluluğunu ya da türev eser ilişkisini doğrulayan hiçbir kontrol yok.

**Senaryo.** Bir entegratör `LICENSE`'daki MIT metnine dayanarak eklentiyi fork'layıp kapalı kaynak, yeniden lisanslanmış bir sürüm olarak müşterisine satar — MIT'in açıkça izin verdiği bir kullanım. Ancak dağıttığı ikili, `WC_Payment_Gateway`'den türeyen sınıfları içerdiği için WooCommerce'in GPLv3'ünün kapsamındadır ve dağıtım GPL ihlalidir. Eklenti sahibi, sahip olmadığı bir hakkı yazılı olarak devretmiş olur. İkinci senaryo: WordPress.org gönderiminde inceleme ekibi, GPL kodundan türeyip yalnızca MIT beyan eden bir eklentide lisans netleştirmesi ister; gönderim gecikir. (MIT'in kendisi GPL-uyumlu olduğu için doğrudan *ret* beklemiyorum — bu yüzden bu yarıyı `medium confidence` işaretledim; tutarsızlığın kendisi ise kesin.)

**Öneri.** GPLv2-or-later'a geçin (WordPress ekosisteminin fiilî standardı) ve dört yeri birden güncelleyin:
```
# nicepay-payment-gateway.php header
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
# readme.txt
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
# composer.json
"license": "GPL-2.0-or-later",
# LICENSE -> GPLv2 tam metni
```
MIT'i korumak isteniyorsa çift beyan yapın ("Bu eklenti bir bütün olarak GPLv2-or-later koşullarıyla dağıtılır; yalnızca özgün kısımlar ayrıca MIT altında da kullanılabilir") — ama o zaman `LICENSE` dosyası iki metni birden içermelidir. Her durumda `.github/scripts/check-version.js`'e beş satırlık bir kapı ekleyin:
```js
const GPL_COMPATIBLE = ['GPLv2 or later', 'GPLv3 or later', 'GPL-2.0-or-later'];
if (!GPL_COMPATIBLE.includes(pluginHeader.License)) {
  fail(`License "${pluginHeader.License}" is not on the GPL-compatible allowlist.`);
}
```

---

### GAP-002 — PCI DSS v4.0 kapsamı (SAQ A vs SAQ A-EP) hiçbir yerde ele alınmamış; satıcı yanlış SAQ seçebilir
- **severity:** medium · **confidence:** medium-high
- **kategori:** uyumluluk/dokümantasyon
- **konum:** `templates/payment-form.php:56-60`, `nicepay-payment-gateway.php:490-491`, `assets/js/nicepay.js:481-484`, `templates/standalone-payment-form.php`, `docs/*` (eksiklik)

**Kanıt.** Ödeme sayfası satıcının kendi sayfasıdır ve ödeme formu satıcı tarafından render edilir:
```php
// templates/payment-form.php:56-60
<form id="nicepay-pay-form" name="payForm" method="post"
      action="<?php echo esc_url( WC()->api_request_url( 'nicepay_return' ) ); ?>">
    <?php foreach ( $form_data as $key => $value ) : ?>
        <input type="hidden" name="..." value="...">
```
Üçüncü parti betik aynı sayfaya yükleniyor (`nicepay-payment-gateway.php:490-491`, `NICEPAY_JS_URL`) ve o betik satıcı sayfasındaki formu ele geçirip gönderiyor:
```js
// assets/js/nicepay.js:481-484
window.nicepaySubmit = function() {
    var form = activePaymentForm || document.payForm;
    if (form && typeof form.submit === 'function') form.submit();
};
```
`grep -rn -i "pci\|SAQ" docs/ README.md readme.txt` → sıfır eşleşme.

**Senaryo.** Satıcı "kart verisi bize hiç değmiyor, ödeme penceresi NICEPAY'de açılıyor" diye düşünüp SAQ A doldurur. Değerlendiricisi, ödeme sayfasının satıcı tarafından kontrol edildiğini ve üzerine üçüncü parti betik yüklendiğini görünce SAQ A-EP'ye — ya da PCI DSS v4.0'ın SAQ A'ya bile getirdiği **6.4.3** (ödeme sayfasındaki betiklerin envanteri, yetkilendirilmesi ve bütünlüğünün sağlanması) ve **11.6.1** (ödeme sayfası oynama tespiti) gerekliliklerine — tabi olduğunu belirler. Satıcının bu kontrollerin hiçbiri yoktur, çünkü eklenti ne CSP ne SRI ne de değişiklik tespiti sağlar (SEC-023) ve hiçbir doküman bu yükümlülükten bahsetmez. Sorun ya denetimde ya da bir Magecart tipi olaydan sonra ortaya çıkar.

**Öneri.** `docs/CONFIGURATION.md`'e "PCI DSS scope" başlığı ekleyin ve üç şeyi net yazın: (1) eklentinin hiçbir noktada PAN/CVV toplamadığı ve saklamadığı — bu doğrulanabilir bir iddia; (2) ödeme sayfasının satıcı kontrolünde olduğu ve NICEPAY betiğinin oraya yüklendiği; (3) satıcının 6.4.3/11.6.1 için yapması gerekenler. Ardından kod tarafında bir tutamaç verin — ödeme sayfalarında CSP başlığı eklemeyi kolaylaştıran bir filtre:
```php
$csp = apply_filters(
    'nicepay_payment_page_csp',
    "script-src 'self' https://pg-web.nicepay.co.kr; frame-ancestors 'none'"
);
if ( '' !== $csp ) { header( 'Content-Security-Policy: ' . $csp ); }
```

---

### GAP-003 — Expiry cron'u sınırsız ve batch'siz tek bir UPDATE; retention batch'lerken bu iş defteri kilitliyor
- **severity:** medium · **confidence:** high
- **kategori:** performans/dayanıklılık
- **konum:** `includes/nicepay-functions.php:958-963` ve `:970-978`; karşılaştırma `includes/class-nicepay-retention.php:22`, `:145`

**Kanıt.**
```php
// includes/nicepay-functions.php:958-963
$sql = "UPDATE {$table}
        SET status = 'expired', approval_state = 'expired', auth_token = '', active_attempt_key = NULL
        WHERE status = 'pending' AND approval_state = 'pending'
          AND offer_expires_at IS NOT NULL
          AND offer_expires_at < UTC_TIMESTAMP()";
$count = $wpdb->query( $sql );
```
`LIMIT` yok, döngü yok, kilit yok, ilerleme kaydı yok. İkinci `UPDATE` (`:970-978`, stale-approving kurtarması) de aynı. Buna karşılık aynı kod tabanındaki retention işi bilinçli olarak sınırlı: günde en fazla 500 satır, 100'lük partiler, `add_option` tabanlı kilit. Ayrıca `offer_expires_at` ve `approval_started_at` üzerinde indeks yok; yalnızca `idx_status_created (status, created_at)`'in `status` öneki kullanılabilir (`class-nicepay-transaction-schema.php:131`).

**Senaryo.** SEC-002/SEC-017'nin tarif ettiği kimliksiz akın (IP başına 20 satır/dakika, ~29.000 satır/gün) bir hafta sürerse defterde ~200.000 `pending` satır birikir ve saklama varsayılanı `indefinite` olduğu için hiçbiri silinmez. Saatlik cron tetiklendiğinde tek bir `UPDATE` bu satırların tamamına dokunur: InnoDB hepsine satır kilidi koyar, dev bir undo log üretir ve işlem sürerken `nicepay_claim_transaction_for_approval()` (`nicepay-functions.php:665-686`) aynı tablo üzerinde `Lock wait timeout exceeded` alır — yani **cron çalışırken gerçek müşterilerin ödemeleri başarısız olur**. İşlem `innodb_lock_wait_timeout`/undo sınırlarını aşarsa tamamen geri alınır, hiçbir satır expire olmaz ve bir saat sonra aynı sorgu aynı şekilde denenip aynı şekilde başarısız olur — sistem hiçbir zaman yakınsamaz. Hata `false === $count` dalına düşmediği (rollback edilen UPDATE `false` dönebilir ama tek log satırı `'Pending payment expiry job failed'` olur, satır sayısı yok) için operatör sorunun büyüklüğünü göremez.

**Öneri.** Retention işindeki deseni birebir uygulayın:
```php
$batch = (int) apply_filters( 'nicepay_expiry_batch_size', 500 );
$max_batches = 20;
$total = 0;
for ( $i = 0; $i < $max_batches; $i++ ) {
    $n = $wpdb->query( "UPDATE {$table} SET ... WHERE ... LIMIT {$batch}" );
    if ( false === $n ) { nicepay_log( 'Pending payment expiry job failed', array( 'processed' => $total ), 'error' ); break; }
    $total += (int) $n;
    if ( (int) $n < $batch ) { break; }
}
```
Ayrıca `NicePay_Retention`'ınkiyle aynı `add_option` TTL kilidini paylaşın, işlenen satır sayısını `info` seviyesinde loglayın ve şemaya iki bileşik indeks ekleyin: `KEY idx_status_offer_expiry (status, offer_expires_at)` ve `KEY idx_status_approval_started (status, approval_started_at)`.

---

### GAP-004 — Sürüm geri alma yolu yok; şema sürümü `version_compare` ile değil dize eşitsizliğiyle karşılaştırılıyor
- **severity:** medium · **confidence:** high
- **kategori:** yükseltme/geri alma, dokümantasyon
- **konum:** `includes/class-nicepay-installer.php:40`, `:120`, `:128`, `:200`; `readme.txt:99-103`; `docs/*` (eksiklik)

**Kanıt.** Sürüm kapısının tamamı düz dize karşılaştırması:
```php
// includes/class-nicepay-installer.php:40
if ( (string) get_option( self::VERSION_OPTION, '' ) !== self::schema_version() ) {
// :120,:128
$current = (string) get_option( self::VERSION_OPTION, '' );
if ( $current === $target && $table_exists && $refund_table_exists ) { return array( 'status' => 'current', ... ); }
// :200
if ( $current !== $target && false === update_option( self::VERSION_OPTION, $target, false ) ) {
```
`version_compare` hiçbir yerde kullanılmıyor, dolayısıyla "daha eski bir şemaya inme" durumu "yükseltme" ile aynı kod yolundan geçiyor ve `update_option` daha **eski** sürümü yazıyor. Dokümantasyon tarafında `grep -rn "rollback\|roll back\|downgrade" docs/ README.md CHANGELOG.md readme.txt` sürüm geri alma anlamında **sıfır** eşleşme veriyor (bulunan dört eşleşmenin dördü de ödeme *ters çevirme* bağlamında). WordPress'in güncelleme diyaloğunda gösterilen tek metin ise şu:
```
== Upgrade Notice ==
= 2.0.0 =
Review all settings after upgrading. Payment methods remain disabled until explicitly configured, ...
```
Ne zorunlu yedek adımı, ne kırıcı değişiklik uyarısı, ne de "migrasyon bloke olursa ne yapılacağı".

**Senaryo.** Satıcı 1.x'ten 2.0.0'a günceller. `maybe_install()` DATA-001'deki duplicate TID/Moid nedeniyle `nicepay_schema_duplicate_moid` döndürür; `is_available()` `NicePay_Installer::is_current()` şartına bağlı olduğu için NicePay checkout'tan tamamen kaybolur. Satıcı paniğe kapılıp 1.x'i geri yükler — ama (a) migrasyon çoktan `scrub_legacy_sensitive_data()` ile tüm `payment_data` denetim yükünü NULL'lamıştır (`class-nicepay-installer.php:277-282`, DATA-003/DATA-028) ve bu geri alınamaz; (b) 1.x'in kendi kurulumcusu `nicepay_transactions_schema_version` option'ını görmez veya kendi değerini yazar; (c) hiçbir doküman ona güncelleme öncesi veritabanı yedeği almasının **zorunlu** olduğunu söylememiştir. Sonuç: ne ödeme alabilen ne de geçmişini mutabık kılabilen bir mağaza ve dokümante edilmiş hiçbir çıkış yolu.

**Öneri.** Üç adım:
1. Kodda geri alma kapısı: `schema_version()` değerlerini sıralanabilir kılın (zaten `2026.08.24.7` biçiminde sıralanabilir) ve `maybe_install()`'un başına ekleyin —
```php
if ( '' !== $current && version_compare( $current, $target, '>' ) ) {
    return new WP_Error( 'nicepay_schema_downgrade_blocked',
        'The stored NicePay schema is newer than this plugin version. Restore the newer plugin or a database backup.' );
}
```
2. `docs/USER-GUIDE.md`'e "Upgrading from 1.x" bölümü: zorunlu veritabanı yedeği, `payment_data`'nın geri alınamaz biçimde temizleneceği uyarısı, `nicepay_schema_duplicate_moid`/`_tid` hatalarının SQL kurtarma reçetesi ve "geri alma desteklenmiyor / yalnızca yedekten dönerek mümkün" açık beyanı.
3. `readme.txt`'in `== Upgrade Notice ==` metnini kırıcı değişiklikleri adıyla sayacak şekilde yeniden yazın (`id`siz shortcode'lar artık hata basıyor, standalone varsayılan olarak kapalı, `payment_data` temizleniyor, yedek zorunlu).

---

### GAP-005 — Çoklu para birimli mağazada sipariş oluşuyor ama alıcı çıkışsız bir hata sayfasına düşüyor
- **severity:** medium · **confidence:** high
- **kategori:** çoklu para birimi / UX
- **konum:** `includes/class-nicepay-gateway.php:93-96`, `:213`, `:216-219`, `:242-245`, `:256-259`, `:264-266`, `:296-302`

**Kanıt.** Uygunluk kontrolü **mağaza/görüntüleme** para birimini kullanıyor:
```php
// includes/class-nicepay-gateway.php:93-96
if ( function_exists( 'get_woocommerce_currency' ) &&
    ! nicepay_is_supported_currency( get_woocommerce_currency() ) ) {
    return false;
}
```
Ödeme formu ise **siparişin** para birimini kullanıyor ve uyuşmazlıkta çıkışsız bir mesaj basıyor:
```php
// :213
$amount = nicepay_get_amount( $order->get_total(), $order->get_currency() );
// :216-219
if ( '' === $amount || ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) {
    echo '<p>' . esc_html__( 'NicePay is not configured for this order.', 'nicepay-payment-gateway' ) . '</p>';
    return;
}
```
`nicepay_get_amount()` → `nicepay_normalize_amount()` (`nicepay-functions.php:1490-1492`) KRW dışı her para biriminde `false` döner, yani `$amount === ''` olur. Ayrıca receipt sayfasının beş hata dalından **üçü** alıcıya hiçbir geri dönüş yolu vermiyor: `:216-219` (para birimi / kimlik bilgisi), `:242-245` (etkin yöntem yok) ve `:264-266` (benzersiz deneme hazırlanamadı). Yalnızca alıcı alanı doğrulaması (`:256-259`) ve kayıt hatası (`:296-302`) `Return to Checkout` bağlantısı basıyor — yani doğru desen kod tabanında zaten var, üç dalda uygulanmamış.

**Senaryo.** WPML WCML / Aelia / CURCY kurulu bir mağazada, ya da satıcı mağaza para birimini bir "pay for order" e-postası gönderdikten sonra değiştirdiğinde: `is_available()` KRW görür ve NicePay checkout'ta çıkar; `process_payment()` `success` döndürür — **sipariş oluşur, stok düşer, "siparişiniz alındı" e-postası gider**; alıcı order-pay sayfasına yönlendirilir ve orada "NicePay is not configured for this order." yazan, hiçbir bağlantısı olmayan bir sayfa görür. Mesaj satıcının hatalı yapılandırdığını ima ediyor, oysa gerçek neden para birimi; alıcı ne tekrar deneyebilir ne de checkout'a dönebilir. Mağazada ödenmeyen `pending` siparişler birikir.

**Öneri.** (1) `is_available()` ve `process_payment()`'a siparişin *kendi* para birimini kontrol ettirin, böylece sipariş hiç oluşmadan önce başarısız olsun:
```php
if ( $order instanceof WC_Order && ! nicepay_is_supported_currency( $order->get_currency() ) ) {
    wc_add_notice( __( 'NicePay only accepts KRW payments.', 'nicepay-payment-gateway' ), 'error' );
    return array( 'result' => 'failure' );
}
```
(2) Bağlantısız üç dala (`:216-219`, `:242-245`, `:264-266`) `:296-302`'de zaten kullanılan `Return to Checkout` bağlantısını ve `wc_add_notice()` çağrısını ekleyin — desen kod tabanında mevcut, yalnızca tutarsız uygulanmış. (3) "Para birimi desteklenmiyor" ile "kimlik bilgileri yapılandırılmamış" mesajlarını ayırın — bugün ikisi aynı cümleyi paylaşıyor.

---

### GAP-006 — Kimliksiz rate-limit sayaçları `wp_options`'a yazıyor; ayrıca fail-open davranışı testle çivilenmiş
- **severity:** medium · **confidence:** medium-high
- **kategori:** performans / dayanıklılık
- **konum:** `includes/nicepay-functions.php:286-330` (özellikle `:311-312`, `:320-327`), `nicepay-payment-gateway.php:116`, `:118`, `tests/unit/NicePayRateLimitTest.php:48-54`

**Kanıt.** Sayaç bir transient'te tutuluyor ve her istekte oku-değiştir-yaz yapılıyor:
```php
// includes/nicepay-functions.php:311-312, 320-327
$key   = 'nicepay_rl_' . substr( hash( 'sha256', $scope . '|' . $identity ), 0, 40 );
$state = get_transient( $key );
...
set_transient( $key, array( 'count' => $count, 'reset_at' => $reset_at ), max( 1, $reset_at - $now ) );
```
Bu iki `nopriv` uç noktadan çağrılıyor (`nicepay-payment-gateway.php:116` 20/dk, `:118` 60/dk). Kalıcı bir nesne önbelleği (Redis/Memcached) **yoksa** — WordPress'in varsayılan durumu — her transient `wp_options` tablosuna iki satır olarak yazılır (`_transient_*` + `_transient_timeout_*`). Ayrıca fail-open dalı bir testle *beklenen davranış* olarak sabitlenmiş:
```php
// tests/unit/NicePayRateLimitTest.php:48-54
public function test_invalid_or_missing_server_address_fails_open_without_global_key(): void {
    $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 1, 60 ) );
    $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 1, 60 ) );
```

**Senaryo.** Nesne önbelleği olmayan bir mağazada 1.000 farklı IP'den dakikada birer istek gelen dağıtık bir tarama, dakikada 2.000 `wp_options` satırı yazar. Süresi dolmuş transient'ler yalnızca günlük `wp_scheduled_delete` cron'unda temizlenir — ki PLAT-010'un tarif ettiği `DISABLE_WP_CRON` + bozuk sistem cron'u yapılandırmasında bu cron **hiç çalışmaz**. Sonuç: `wp_options` sınırsız büyür, her istekte çalışan option yükleme sorgusu ve tablo indeksi yavaşlar, veritabanı yedekleri şişer — ve bunların hiçbiri NicePay'e atfedilmez çünkü satır adları jenerik `_transient_nicepay_rl_<hash>`'tir. İkinci sonuç: SEC-016'nın "fail-open" bulgusu düzeltilmek istendiğinde `NicePayRateLimitTest.php:48-54` kırılır ve düzeltme bir regresyon gibi görünür — testin kendisi düzeltmenin önünde bir engel hâline gelmiştir.

**Öneri.** (1) Nesne önbelleği varsa onu kullanın, yoksa yazma yapmayın:
```php
if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
    $state = wp_cache_get( $key, 'nicepay_rl' );  // ... wp_cache_set(...)
} else {
    // options'a yazmak yerine ya kendi sınırlı tablonuzu ya da yalnızca bellek-içi sayacı kullanın
}
```
(2) Alternatif olarak sayaçları mevcut saatlik cron'da temizleyin (`DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_nicepay\_rl\_%' AND option_value < UNIX_TIMESTAMP()`). (3) `NicePayRateLimitTest.php:48-54`'ün test adını ve doc-block'unu "bu bilinçli bir takas, SEC-016'ya bakınız" diye işaretleyin ki gelecekteki düzeltme bir regresyon sanılmasın. (4) Nesne önbelleği önerisini `docs/CONFIGURATION.md`'e yazın.

---

### GAP-007 — Sürüm paketi allowlist değil dizin kopyası; denylist `.env.local`/`*.map`/`*.bak`'i kaçırıyor ve JS lint yalnızca `assets/js`'te
- **severity:** medium · **confidence:** high
- **kategori:** build/tedarik zinciri
- **konum:** `.github/scripts/build-release.sh:41-47`, `:58-64`; `.github/scripts/smoke-check-artifact.sh:48-57`, `:83-85`

**Kanıt.** Paketleme blanket dizin kopyası:
```bash
# .github/scripts/build-release.sh:41-47
for directory in admin assets includes languages templates; do
  ...
  cp -R "$repository_root/$directory" "$package_root/"
done
```
Doğrulama tarafındaki denylist üç desenden ibaret ve `\.env` **tam ad** olarak eşleşiyor:
```bash
# .github/scripts/smoke-check-artifact.sh:48-51
'(^|/)(\.git|\.github|tests|vendor|node_modules|build)(/|$)' \
'(^|/)docs/analysis(/|$)' \
'(^|/)(composer\.(json|lock)|package(-lock)?\.json|eslint\.config\.js|phpunit\.xml|\.env)(/|$)'
```
`.env.local`, `.env.production`, `*.map`, `*.orig`, `*.bak`, `*.swp`, `.DS_Store` ve `*.zip` bu desenlerin hiçbirine takılmaz. Ayrıca sözdizimi kontrolü asimetrik: `php -l` paketteki **tüm** `.php` dosyalarına uygulanıyor (`:79-81`) ama `node --check` yalnızca `assets/js` altına (`:83-85`).

**Senaryo.** Bir geliştirici yerel deneme sırasında `includes/.env.local` (API anahtarı içeren) veya `assets/js/nicepay.js.map` (kaynak haritası, iç yapıyı ifşa eder) bırakır. `build-release.sh` bunları `cp -R` ile pakete alır, `smoke-check-artifact.sh` hiçbir desene takılmadığı için **geçer**, `release.yml` dosyanın SHA-256'sını yayımlar ve `deploy-wordpress-org.yml` aynı sha256 zincir-teslim mührüyle WordPress.org SVN trunk'ına push eder. Sır, imzalı bir "doğrulanmış artefakt" olarak yayımlanmış olur.

**Öneri.** (1) Kopyalamayı allowlist'e çevirin — `rsync -a --include='*/' --include='*.php' --include='*.js' --include='*.css' --include='*.po' --include='*.mo' --include='*.pot' --exclude='*'` ya da `git archive HEAD` + `.gitattributes export-ignore` (zaten mevcut, `12` boyutunun CI-009 bulgusuyla birleşir). (2) Denylist'i genişletin:
```bash
'\.(map|orig|bak|swp|swo|zip|tar\.gz|tgz)$' \
'(^|/)\.env' \
'(^|/)\.DS_Store$'
```
(3) `node --check`'i pakedeki tüm `.js` dosyalarına uygulayın, yalnızca `assets/js`'e değil.

---

### GAP-008 — `nicepay_currency` tek seçenekli ölü bir ayar ama beş yerde okunuyor ve dışarıdan filtrelenebilir
- **severity:** low · **confidence:** high
- **kategori:** bakım / tutarlılık
- **konum:** `admin/class-nicepay-admin.php:112`, `:437`, `:514-516`; `nicepay-payment-gateway.php:280`, `:449`; `includes/nicepay-functions.php:1271`, `:1486`, `:1658`

**Kanıt.** Ayar bir `<select>` olarak render ediliyor ama içinde tek bir `<option>` var:
```php
// admin/class-nicepay-admin.php:514-516
<select id="nicepay-currency" name="nicepay_currency">
    <option value="KRW" <?php selected( get_option( 'nicepay_currency' ), 'KRW' ); ?>>KRW</option>
```
Aynı option beş ayrı yerde okunuyor; `nicepay_normalize_amount()` para birimi verilmediğinde ona düşüyor (`nicepay-functions.php:1486`), oysa `NicePay_Offer_Resolver::resolve_standalone()` (`class-nicepay-offer-resolver.php:54-59`) KRW'yi sabit olarak zorunlu kılıyor.

**Senaryo.** Bir çoklu para birimi eklentisi ya da bir "options manager" `option_nicepay_currency` filtresini `USD`'ye çevirir (bu, WordPress'te herhangi bir eklentinin tek satırla yapabileceği bir şeydir). O andan itibaren `nicepay_normalize_amount( $x )` — para birimi argümanı verilmeyen her çağrı — `false` döner, `nicepay_format_amount()` biçimlendirmeyi değiştirir; ama teklif çözümleyici hâlâ KRW dayattığı için standalone akış çalışmaya devam eder. İki katman farklı bir dünya görüyor, hata mesajları ("The saved payment amount is invalid.") gerçek nedeni göstermiyor ve satıcı sorunu NICEPAY'e atfediyor.

**Öneri.** Ayarı ve option'ı tamamen kaldırın; üç okuma noktasında `'KRW'` sabitini kullanın; `class-nicepay-admin.php:437`'deki hazırlık satırını sabit bir bilgi cümlesine ("Only KRW is supported") çevirin. Kaldırma sırasında `nicepay-payment-gateway.php:280`'deki `add_option` ve `:449`'daki okuma da temizlenmelidir.

---

### GAP-009 — Kimliksiz init uç noktası çözümleyici hata mesajlarını aynen döndürüyor: yapılandırma sayımı/durumu oracle'ı
- **severity:** low · **confidence:** medium
- **kategori:** güvenlik (bilgi ifşası)
- **konum:** `nicepay-payment-gateway.php:567-571`; `includes/class-nicepay-offer-resolver.php:36-48`, `:54-59`, `:63-68`, `:104-129`, `:139-144`

**Kanıt.** Kimlik doğrulaması gerektirmeyen uç nokta çözümleyicinin mesajını doğrudan istemciye veriyor:
```php
// nicepay-payment-gateway.php:567-571
$offer = NicePay_Offer_Resolver::resolve_standalone( $config_id, $pay_method );
if ( is_wp_error( $offer ) ) {
    wp_send_json_error( array( 'message' => $offer->get_error_message() ), 400 );
```
Çözümleyici ise en az altı ayırt edilebilir durum döndürüyor: `Invalid payment configuration identifier.` (biçimsel hata), `Payment configuration not found.` (biçimsel olarak geçerli ama yok), `This payment currency is not supported.`, `The saved payment amount is invalid.`, `The saved payment method is not available.`, `A content or physical-goods classification is required for mobile payments.`

**Senaryo.** Kimliksiz bir saldırgan `cfg_<16 hex>` uzayını tarar. Yanıt farkı ona "bu ID hiç var olmadı" ile "bu ID var ama yapılandırması bozuk" arasını ayırt ettirir; ikinci grup, satıcının hangi yapılandırmalarını yarım bıraktığını ve hangi yöntemlerin kapalı olduğunu ifşa eder. Hız sınırı IP başına 20/dk ve `REMOTE_ADDR` geçersizse fail-open (GAP-006), yani sayım pratikte engellenmiyor. Doğrudan para riski yok — bu yüzden `low` — ama SEC-002'nin defter şişirme primitifiyle birleşince hedefli bir keşif adımı sağlıyor.

**Öneri.** Kamuya tek bir jenerik mesaj döndürün, gerçek kodu sunucuda loglayın:
```php
if ( is_wp_error( $offer ) ) {
    nicepay_log( 'Standalone offer rejected', array(
        'code' => $offer->get_error_code(),
        'config_id' => $config_id,
    ), 'warning' );
    wp_send_json_error( array( 'message' => __( 'This payment form is not available.', 'nicepay-payment-gateway' ) ), 400 );
}
```

---

### GAP-010 — Ödeme reddinin gerçek nedenini üreten iki sınıf hiç log yazmıyor; destek ekibi PROTO-002'yi kurcalamadan ayırt edemiyor
- **severity:** low · **confidence:** high
- **kategori:** gözlemlenebilirlik
- **konum:** `includes/class-nicepay-inbound-validator.php` (0 log çağrısı), `includes/class-nicepay-offer-resolver.php` (0), `includes/class-nicepay-installer.php` (0), `admin/class-nicepay-transactions.php` (0); çağıran taraf `includes/class-nicepay-return-handler.php:79` ve `includes/class-nicepay-gateway.php:428`

**Kanıt.** `grep -c "nicepay_log("` sayımı yukarıdaki "Gözlemlenebilirlik" bölümünde. Reddin loglandığı tek yer çağıranlar ve orada yalnızca hata kodu var:
```php
// includes/class-nicepay-gateway.php:428
nicepay_log( 'WooCommerce auth return rejected', $error_code, 'warning' );
// includes/class-nicepay-return-handler.php:79
nicepay_log( 'Standalone auth return rejected', $transaction->get_error_code(), 'warning' );
```
Doğrulayıcı ~20 ayrı hata kodu üretiyor ve reddi verirken beklenen/gelen değerleri **zaten elinde tutuyor** (ör. `nicepay_inbound_amount_mismatch` dalında normalize edilmiş tutar ile POST'lanan `Amt`), ama bunların hiçbiri log satırına geçmiyor. `Moid`, `wc_order_id`, `mid` de yok.

**Senaryo.** Satıcı "bazı ödemeler başarısız oluyor" diye destek açar. Elindeki tek kanıt tekrar eden şu satırdır: `[NicePay] WooCommerce auth return rejected | nicepay_inbound_amount_mismatch`. Bu satır PROTO-002/MONEY-006'nın senaryosunu (MID sıfır-dolgulu `Amt` döndürüyor, yani **her** ödeme başarısız) gerçek bir kurcalama girişiminden ayırt edemez; hangi siparişlerin etkilendiğini de göstermez, çünkü Moid yok. Destek ekibi ancak veritabanına doğrudan SQL çalıştırarak ilerleyebilir. Aynı sessizlik migrasyon tarafında da var: `maybe_install()` `nicepay_schema_duplicate_moid` döndürdüğünde installer hiçbir şey loglamaz (log yalnızca `nicepay-payment-gateway.php:207`'de, hata kodu düzeyinde).

**Öneri.** Doğrulayıcının `WP_Error` verisine teşhis bağlamı koyun (imza/token **asla** değil) ve çağıranlarda onu loglayın:
```php
// class-nicepay-inbound-validator.php::error()
return new WP_Error( $code, __( ... ), array( 'moid' => $moid, 'mid' => $mid, 'expected' => $expected, 'received' => $received ) );
// çağıranlarda
nicepay_log( 'WooCommerce auth return rejected', array_merge(
    array( 'code' => $error_code ), (array) $transaction->get_error_data()
), 'warning' );
```
Ayrıca çözümleyiciye bir (GAP-009), installer'ın bloke dalına bir `nicepay_log( ..., 'error' )` ekleyin.

---

### GAP-011 — `maybe_install_schema()` her istekte cron zamanlamasını senkronize ediyor; kimliksiz GET option yazabiliyor
- **severity:** low · **confidence:** medium
- **kategori:** performans / doğruluk
- **konum:** `nicepay-payment-gateway.php:202-213`, `includes/class-nicepay-retention.php:113-123`

**Kanıt.**
```php
// nicepay-payment-gateway.php:202-213 (plugins_loaded, priority 5 — HER istekte)
public function maybe_install_schema() {
    $result = NicePay_Installer::maybe_install();
    ...
    } elseif ( ! wp_next_scheduled( 'nicepay_expire_pending_transactions' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'nicepay_expire_pending_transactions' );
    }
    if ( ! is_wp_error( $result ) ) {
        NicePay_Retention::sync_schedule();
    }
```
`sync_schedule()` de duruma göre `wp_schedule_event()` ya da `wp_clear_scheduled_hook()` çağırabiliyor (`class-nicepay-retention.php:113-123`) — ikisi de `cron` option'ına **yazma** işlemidir.

**Senaryo.** Cron event'i herhangi bir nedenle kaybolduğunda (başka bir eklenti `cron` option'ını sıfırladı, bir migrasyon aracı kopyaladı, bir "cron temizleyici" eklentisi sildi), sıradaki **kimliksiz ön yüz GET isteği** option tablosuna yazar. Yüksek eşzamanlılıkta iki worker aynı anda okur-yazarsa biri diğerinin cron dizisini eziyor ve kaybolan event bir sonraki istekte yeniden yazılıyor — bu döngü kalıcı bir option yazma trafiği üretir. Ayrıca salt-okunur replika kullanan kurulumlarda bu, ön yüz isteğinde yazma denemesi demektir. ARCH-008/DATA-030'un `SHOW TABLES` maliyetine ek olarak her istekte bir cron dizisi okuması ve potansiyel bir yazma ekleniyor.

**Öneri.** Zamanlama senkronizasyonunu yazma bağlamlarıyla sınırlayın:
```php
if ( ! is_wp_error( $result ) && ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) ) {
    NicePay_Retention::sync_schedule();
}
```
Aynı koruma `wp_schedule_event` dalına da uygulanmalı. GAP-011 ile ARCH-008'in düzeltmesi (şema kapısını önbellekleme) birlikte tasarlanmalıdır.

---

### GAP-012 — PLAT-005'in ikinci konumu: deaktivasyon da `switch_to_blog()` içinde `flush_rewrite_rules()` çağırıyor
- **severity:** low · **confidence:** high
- **kategori:** çoklu site (mevcut bulgunun eksik kalan yarısı)
- **konum:** `nicepay-payment-gateway.php:178-183`, `:191-195` (PLAT-005 yalnızca `:170`'i gösteriyor)

**Kanıt.**
```php
// nicepay-payment-gateway.php:178-183
switch_to_blog( $site_id );
try {
    $this->deactivate_current_site();
} finally {
    restore_current_blog();
}
// :191-195
private function deactivate_current_site() {
    wp_clear_scheduled_hook( 'nicepay_expire_pending_transactions' );
    wp_clear_scheduled_hook( NicePay_Retention::CRON_HOOK );
    flush_rewrite_rules();
}
```
`flush_rewrite_rules()` global `$wp_rewrite` nesnesini kullanır; `switch_to_blog()` bu nesneyi alt sitenin permalink yapısına göre yeniden kurmaz. Dolayısıyla ana sitenin kurallarıyla üretilmiş `rewrite_rules` option'ı her alt siteye yazılır.

**Senaryo.** Ağ yöneticisi eklentiyi ağ genelinde **devre dışı bırakır** — yani en zararsız görünen işlem — ve permalink yapısı ana siteden farklı olan her alt sitede yazı/sayfa/ürün URL'leri 404 vermeye başlar. Aktivasyon senaryosundan (PLAT-005) daha sinsi, çünkü satıcı "eklentiyi kaldırdım, artık etkisi olamaz" diye düşünür.

**Öneri.** PLAT-005'in düzeltmesiyle aynı: `switch_to_blog()` bloklarının içinden `flush_rewrite_rules()`'ü çıkarın; bunun yerine her sitede `delete_option( 'rewrite_rules' )` yapın (kurallar o sitenin bir sonraki isteğinde kendi `$wp_rewrite` bağlamıyla yeniden üretilir). PLAT-005'in düzeltmesi uygulanırken **iki** konumun da düzeltildiği doğrulanmalıdır.

---

### GAP-013 — Para hareketi işleyen bir eklentide tek CODEOWNER; dört-göz kuralı repoda kanıtlanamıyor
- **severity:** low · **confidence:** low
- **kategori:** yönetişim
- **konum:** `.github/CODEOWNERS:1`, `CONTRIBUTING.md:97-104`, `.github/workflows/deploy-wordpress-org.yml:214`, `:247-250`

**Kanıt.** `.github/CODEOWNERS` dosyasının tamamı tek satır:
```
* @cemililik
```
`CONTRIBUTING.md` `main`'i "korumalı release dalı" ilan ediyor ama bu korumanın gerçekten yapılandırıldığına dair repoda hiçbir kanıt yok (META-011 aynı boşluğu WP.org environment'ı için işaret ediyor). Yayın hattındaki tek insan kapısı `wordpress-org-production` environment'ının zorunlu reviewer ayarı — ki o da repo dışında yapılandırılıyor ve CODEOWNER ile aynı kişi olabilir.

**Senaryo.** Para yolunda tek bir hatalı commit (ör. bir `hash_equals` çağrısının kaldırılması) hiçbir bağımsız incelemeden geçmeden `development`'a, oradan tag'e ve WordPress.org'a ulaşabilir. Bu bir kod kusuru değil, bir süreç kusurudur; ama bir ödeme ağ geçidi için tedarik zinciri riski budur. `confidence: low` — tek geliştiricili bir projede meşru bir tercih olabilir; kapatmak için proje sahibinin niyeti gerekiyor.

**Öneri.** İkisinden birini yapın: ikinci bir CODEOWNER ekleyin ve `main` üzerinde zorunlu inceleme açın, **veya** `SECURITY.md`/`CONTRIBUTING.md`'de bus-factor'ü açıkça beyan edin ("Bu proje tek bakımcılıdır; bağımsız kod incelemesi yoktur") ki satıcılar ve denetçiler riski bilerek kabul etsin. Sessiz kalmak en kötü seçenektir.

---

## Açık Kalan Belirsizlikler

Aşağıdakiler `confidence: low` kalan ya da bu incelemenin araçlarıyla kapatılamayan konulardır. Her biri için **kapatmak için tam olarak ne gerektiğini** yazdım.

1. **GAP-001'in WordPress.org sonucu.** MIT, WordPress.org'un GPL-uyumlu lisans listesindedir; dolayısıyla gönderinin doğrudan reddedileceğini iddia etmiyorum. Kapatmak için: WordPress.org Plugin Check'in (`wp plugin check`) mevcut sürümünü ZIP üzerinde çalıştırıp lisans kategorisinde uyarı üretip üretmediğini görmek yeterlidir — bu, CI-005'in önerdiği Plugin Check entegrasyonuyla aynı adımdır. Türev-eser çelişkisi ise hukuki bir sorudur ve Plugin Check'in kapsamı dışındadır.

2. **GAP-002'nin SAQ sınıfı.** SAQ A mı A-EP mi olduğu satıcının kart markası/edinen banka programına ve değerlendiricinin yorumuna bağlıdır. Kapatmak için: bir QSA'dan tek sayfalık bir kapsam görüşü alıp `docs/`'a eklemek. Kesin olan ve zaten yazılabilecek olan kısım şudur: eklenti hiçbir noktada PAN/CVV toplamıyor veya saklamıyor (bunu kod üzerinde doğruladım) ve ödeme sayfası satıcı kontrolündedir.

3. **Güvenlik eklentisi çakışması (Wordfence / iThemes / Sucuri).** NICEPAY'in çapraz-site dönüş POST'unun bu eklentiler tarafından engellenip engellenmediğini kodla doğrulayamadım; somut bir kural adı gösteremediğim için bulgu açmadım. Kapatmak için: bir sandbox mağazada Wordfence "Extended Protection" açıkken tek bir gerçek dönüş POST'u yapıp bloklanıp bloklanmadığını gözlemlemek. Etkisi büyük olurdu — bloklandığında hiçbir log satırı üretilmez, çünkü istek PHP'ye hiç ulaşmaz.

4. **DATA-019'un gerçek etkisi.** `idx_source_ref` oluşmadığında `nicepay_claim_transaction_for_approval()`'ın `UPDATE ... INNER JOIN`'i tam tablo taramalı bir kilitleme UPDATE'ine dönüşür. Kapatmak için: `innodb_default_row_format=COMPACT` ayarlı tek kullanımlık bir MariaDB'de tabloyu kurup `EXPLAIN` almak — DATA-002'nin açık sorusunda istenen aynı deneyle birlikte yapılabilir. Sonucuna göre DATA-019'un severity'si yükseltilmelidir.

5. **`window.nicepaySubmit` / `nicepayClose` çakışması.** PG-Web v3 bu adları protokol düzeyinde zorunlu kılıyorsa namespace'lenemezler ve tek çözüm defansif bir "zaten tanımlı" kontrolü + uyarı loglamasıdır. Kapatmak için: NICEPAY entegrasyon dokümanında callback adlarının sabit mi yoksa yapılandırılabilir mi olduğunu teyit etmek (`01` boyutunun DG-01 satıcı teyidi listesine eklenmeli).

6. **GAP-013'ün meşruluğu.** Tek bakımcılı bir açık kaynak projesinde tek CODEOWNER normaldir; bu bir kusur mu yoksa beyan edilmesi gereken bir gerçek mi, proje sahibinin ürün kararıdır.

7. **`nicepay_expire_pending_transactions` cron'unun gerçek yükü.** GAP-003'ün senaryosu SEC-002'nin akın senaryosunun gerçekleşmesine bağlı. Akın olmadan da, normal terk oranıyla biriken `pending` satır sayısı zamanla 500'ü aşacaktır (retention varsayılanı `indefinite`); ancak "ne zaman kilitlenmeye dönüşür" eşiği ölçülmedi. Kapatmak için: 200.000 satırlık sentetik bir defterde `run-schema-migration.sh` altyapısıyla tek bir cron çalıştırıp `SHOW ENGINE INNODB STATUS` kilit sayısını görmek — mevcut entegrasyon test altyapısına ~20 satırlık bir ek.

8. **Geri alma senaryosunun gerçekliği (GAP-004).** `04` boyutunun açık sorusu "gerçekten 1.x'ten yükseltecek kurulum var mı?" hâlâ yanıtsız. Yanıt "hayır, 2.0.0 pratikte temiz kurulum" ise GAP-004'ün severity'si `low`'a iner ve yalnızca dokümantasyon düzeltmesi kalır; "evet" ise `high`'a çıkar ve `version_compare` kapısı yayın öncesi zorunlu hâle gelir.
