# PR #3 Sistematik Review — Yönetici Özeti

> **feat: harden payment lifecycle and release readiness** · `development` → `main` · HEAD `6b7fedf`
> 113 dosya · +43.412 / −3.292 satır
> 14 boyut · 296 bulgu · 56 kritik/yüksek bulgu ikişer bağımsız düşmanca doğrulayıcıdan geçti

---

## Karar

### **SEVK EDİLEMEZ** — 2.0.0 olarak yayınlanamaz; kod yönü doğru, sürüm hazır değil.

Bu PR `main`'e göre net ve büyük bir iyileşme: imza katmanı, para aritmetiği ve eşzamanlılık
kontrolü sektör ortalamasının üzerinde. Kararı belirleyen şey bulgu **sayısı** değil, üç
bulgu sınıfının **niteliği**: (1) PR'ın en riskli iddiası olan 1.x → 2.0 yükseltme yolu depoda
hiç çalıştırılmamış ve kod okuması, rutin bir "Güncelle" tıklamasının çift-onay korumasını
sessizce kaldırıp aynı anda tüm geçmiş siparişleri iade edilemez yaptığını gösteriyor
([CR-1](15-cross-cutting.md));
(2) para hareketini koruyan üç kapı fail-open — test modunda `is_available()` mod kontrolü
yapmıyor, net-cancel saldırganın POST ettiği alanlarla kuruluyor, kilitlenen satırın hiçbir
kurtarma yolu yok; (3) PR'ın başlığındaki "release readiness" iddiası mekanik olarak yanlış —
projenin kendi WordPress.org publish job'ı `readme.txt`'de `Contributors:` olmadığı için durur,
bir sonraki sürüm artışı CI'ı deterministik olarak kırar ve eklenti GPL türev eseri olduğu hâlde
dört yerde MIT beyan ediyor.

**Ne yapılmalı:** Aşama 0 ve Aşama 1 ([Önerilen sevkiyat sırası](#önerilen-sevkiyat-sırası))
tamamlandığında karar **KOŞULLU SEVK EDİLEBİLİR**'e döner. Aşama 0 kalemleri koda dokunmuyor ve
saatler sürer; Aşama 1'in tamamı tek bir entegrasyon fixture'ıyla üretimden önce yakalanabilir.

---

## Bir bakışta

| # | Boyut | Not | 🔴 | 🟠 | 🟡 | 🔵 | Rapor |
|---|---|---|---|---|---|---|---|
| 01 | Protokol Uyumluluğu (PG-Web v3) | **B** | 0 | 1 | 12 | 3 | [rapor](01-protocol-conformance.md) · 1.011 satır |
| 02 | Ödeme Akışı ve Para Doğruluğu | **C** | 1 | 6 | 14 | 7 | [rapor](02-payment-money-correctness.md) · 1.545 satır |
| 03 | Güvenlik (AppSec) | **B** | 1 | 2 | 7 | 11 | [rapor](03-security.md) · 1.132 satır |
| 04 | Veri Katmanı (şema/migrasyon/gizlilik) | **C** | 2 | 4 | 22 | 9 | [rapor](04-data-layer.md) · 1.780 satır |
| 05 | WordPress / WooCommerce Platform | **B** | 0 | 2 | 10 | 7 | [rapor](05-wp-wc-platform.md) · 1.207 satır |
| 06 | Mimari ve Kod Kalitesi | **C** | 0 | 2 | 16 | 3 | [rapor](06-architecture-quality.md) · 1.598 satır |
| 07 | Frontend JavaScript | **B** | 0 | 1 | 7 | 7 | [rapor](07-frontend-js.md) · 946 satır |
| 08 | UX, CSS ve Erişilebilirlik | **C** | 0 | 2 | 19 | 6 | [rapor](08-ux-accessibility.md) · 1.078 satır |
| 09 | Yönetim Paneli | **B** | 0 | 2 | 9 | 5 | [rapor](09-admin-experience.md) · 955 satır |
| 10 | Test Stratejisi ve Kalitesi | **C** | 1 | 3 | 13 | 1 | [rapor](10-testing.md) · 838 satır |
| 11 | Uluslararasılaştırma | **C** | 0 | 3 | 12 | 7 | [rapor](11-i18n-l10n.md) · 1.491 satır |
| 12 | Build, CI/CD, Release | **B** | 0 | 2 | 8 | 8 | [rapor](12-build-ci-release.md) · 1.048 satır |
| 13 | Dokümantasyon | **C** | 0 | 4 | 10 | 5 | [rapor](13-documentation.md) · 1.028 satır |
| 14 | docs/analysis Bütünlüğü | **C** | 0 | 4 | 8 | 3 | [rapor](14-analysis-docs-integrity.md) · 812 satır |
| | **Toplam** | | **5** | **38** | **167** | **82** | **16.469 satır** (+ ⚪ 4 bilgi) |

**Sentez dokümanları:** [15 — Çapraz kesen analiz](15-cross-cutting.md) (612 satır · 8 kök neden
kümesi, 6 bileşik risk zinciri, 8 sistemik boşluk) · [16 — Kapsam boşlukları](16-coverage-gaps.md)
(549 satır · 13 ek GAP bulgusu) · [INDEX](INDEX.md) (605 satır · 296 bulgunun tam listesi).

**Not dağılımı:** 6 boyut **B**, 8 boyut **C**. Hiçbir boyut A almadı; hiçbiri D almadı. Bu
profil tutarlı bir hikâye anlatıyor: *çekirdek doğru kurulmuş, çeperi tamamlanmamış.*

---

## Sevkiyatı engelleyenler

5 kritik + 38 yüksek bulgu, 39 satırda toplandı (çapraz kesen analizin
[§4](15-cross-cutting.md)'te mükerrer olarak işaretlediği kayıtlar
birleştirildi). `↓` işareti düşmanca doğrulamada şiddeti düşürülen bulguyu gösterir.

### A. Yükseltme yolu — hiç çalıştırılmadı (9 bulgu)

| ID | Sev | Sorun | Konum | Düzeltme |
|---|---|---|---|---|
| [DATA-001](04-data-layer.md) | 🔴 | v1'de `tid` `NOT NULL DEFAULT ''` iken `SET tid = NULL`, WordPress strict SQL mode'u kaldırdığı için sessizce `''` yazar ve `uniq_tid` hiç oluşamaz | `includes/class-nicepay-installer.php:269` | NULL'lamadan **önce** `ALTER TABLE ... MODIFY tid varchar(50) NULL` çalıştır |
| [DATA-018](04-data-layer.md) | 🔴 | dbDelta hata tespiti yalnız `$wpdb->last_error`'a dayanıyor; yutulmuş bir indeks hatasında şema "güncel" işaretlenir | `includes/class-nicepay-installer.php:185` | dbDelta sonrası `SHOW INDEX` ile üç UNIQUE indeksin varlığını kanıtla; yoksa sürüm option'ını ilerletme |
| [MONEY-015](02-payment-money-correctness.md) | 🔴 | `uniq_tid` oluşmayınca çift-onay koruması yükseltilen sitelerde tamamen yok olur | `includes/class-nicepay-installer.php:269` | Migrasyon öncesi duplicate tarama + DATA-018'deki indeks doğrulaması |
| [DATA-002](04-data-layer.md) · [DATA-020](04-data-layer.md) | 🟠 | `flow`/`mid`/`mode`/`currency`/`captured_amount` eski satırlar için hiç doldurulmuyor | `includes/class-nicepay-gateway.php:716`, `includes/nicepay-functions.php:450` | Açık backfill migrasyonu yaz; `captured_amount`'ı `amount`'tan türet |
| [MONEY-001](02-payment-money-correctness.md) · [MONEY-018](02-payment-money-correctness.md) | 🟠 | `flow=''` toleransı ölü kod — `nicepay_get_transaction_by_tid()` sorguda `flow='woocommerce'` sabitliyor; v1 ödemeleri hiç iade edilemiyor | `includes/nicepay-functions.php:450` | Backfill + sorgudaki `flow` sabitini kaldır; üç katmandaki tolerans mantığını tek yere indir |
| [DATA-022](04-data-layer.md) | 🟠 | Bloke migrasyon her istekte iki tam tablo taraması yapıyor; başarısız dbDelta sınırsız yeniden deneniyor, kilit yok | `includes/class-nicepay-installer.php:138` | `add_option` tabanlı TTL'li kilit + geri çekilme (backoff) + sonuç önbelleği |
| [DOC-002](13-documentation.md) | 🟠 | CHANGELOG mevcut kurulumları "fresh" sayıyor; yükseltme rehberi ve kırıcı değişiklik uyarısı yok | `CHANGELOG.md:26` | Yükseltme rehberi + `== Upgrade Notice ==`'a "önce yedek al" uyarısı |

### B. Para güvenliği ve kilitlenme (7 bulgu)

| ID | Sev | Sorun | Konum | Düzeltme |
|---|---|---|---|---|
| [MONEY-003](02-payment-money-correctness.md) · [MONEY-016](02-payment-money-correctness.md) · [DATA-021](04-data-layer.md) | 🟠 | Stale-approving cron'u `needs_reconciliation` yazıyor ama `active_attempt_key`'i **temizlemiyor**; sipariş kalıcı olarak ödenemez kalıyor | `includes/nicepay-functions.php:970` | Cron `UPDATE`'ine `active_attempt_key = NULL` ekle — tek satır, kilidi kırar |
| [ADMIN-002](09-admin-experience.md) | 🟠 | `needs_reconciliation` kaydını panelden çözmenin hiçbir yolu yok; uyarı bandı ve kilitli iade butonu kalıcı | `admin/class-nicepay-transactions.php:634` | Yetenek + işlem-özel nonce korumalı `nicepay_resolve_reconciliation` aksiyonu + append-only denetim satırı |
| [MONEY-005](02-payment-money-correctness.md) · [MONEY-017](02-payment-money-correctness.md) | 🟠 | KRW ondalıksızlığı yalnız NICEPAY sınırında zorlanıyor; kesirli toplam sessizce yuvarlanıyor ve ikinci kısmi iade kalıcı bloke oluyor | `includes/nicepay-functions.php:1513` | KRW'de `woocommerce_price_decimals`'ı 0'a zorla + sapma varsa admin uyarısı |
| [PROTO-004](01-protocol-conformance.md) | 🟠 | Net-cancel tek denemelik; korelasyonlu bir ağ hatası kart hold'unu kalıcı olarak askıda bırakıyor | `includes/class-nicepay-api.php:435` | Kısa aralıklı 2–3 deneme + alternatif DC endpoint'i; her deneme deftere yazılsın |

### C. Güvenlik (3 bulgu)

| ID | Sev | Sorun | Konum | Düzeltme |
|---|---|---|---|---|
| [SEC-011](03-security.md) | 🔴 | Net-cancel saldırganın POST ettiği `TxTid`/`AuthToken`/`NetCancelURL` ile yapılıyor: aynı MID'e ait keyfi bir yetkilendirmeyi iptal ettiren oracle | `includes/nicepay-functions.php:238` | Ters çevirme alanlarını POST'tan değil, CAS ile claim edilmiş **yerel satırdan** al |
| [SEC-012](03-security.md) | 🟠 | Auth-return imzası `Moid`/`TxTid`'e bağlanmıyor; `binding_token_hash` sütunu şemada var ama hiç yazılmıyor | `includes/class-nicepay-inbound-validator.php:111` | `ReqReserved` ile HMAC bağlama token'ı gönder, dönüşte `binding_token_hash`'e karşı `hash_equals` ile doğrula |
| [SEC-013](03-security.md) | 🟠 | Herkese açık NICEPAY sandbox kimlik bilgileri varsayılan; `is_available()` mod kapısı yok → test modunda kalmış mağazada bedava ürün | `nicepay-payment-gateway.php:34`, `includes/class-nicepay-gateway.php:76` | Test modunda checkout'u yalnız yetkili kullanıcıya aç; test siparişini `on-hold` + görünür etiketle bırak |

### D. Platform, UX ve frontend (6 bulgu)

| ID | Sev | Sorun | Konum | Düzeltme |
|---|---|---|---|---|
| [PLAT-001](05-wp-wc-platform.md) | 🟠 | `process_payment()` bildirimsiz `failure` dönüyor; müşteri boş bir checkout hatasıyla kalıyor | `includes/class-nicepay-gateway.php:111` | Her `failure` dalından önce nedeni ayrıştıran `wc_add_notice()` + `nicepay_log()` |
| [PLAT-002](05-wp-wc-platform.md) | 🟠 | Alıcı alanı doğrulaması sipariş oluştuktan **sonra** receipt sayfasında çalışıyor; `validate_fields()` yok | `includes/class-nicepay-gateway.php:251` | `validate_fields()` uygula; hatayı alan adıyla birlikte checkout'ta göster |
| [JS-002](07-frontend-js.md) | 🟠 | PG penceresi geri çağırmazsa XHR timeout'u/watchdog yok; sayfadaki tüm ödeme butonları kalıcı kilitleniyor | `assets/js/nicepay.js:152` | XHR `timeout` + watchdog zamanlayıcı + "pencere kapandı, tekrar deneyin" kurtarma mesajı |
| [UX-004](08-ux-accessibility.md) | 🟠 | Standalone formda görünür alıcı alanları `<form>` elemanının **dışında**; Enter ile gönderim ve `required` tamamen ölü | `templates/standalone-payment-form.php:123` | Alanları form içine taşı veya `form="<id>"` özniteliğiyle bağla |
| [UX-007](08-ux-accessibility.md) | 🟠 | İade onay diyaloğu tutarı hiç göstermiyor; "Refund" / "Cancel Transaction" terminolojisi çelişiyor | `admin/class-nicepay-transactions.php:924` | Diyaloğa tutar + TID + sipariş no yaz; tek terim seç; kısmi iade yoksa açıkça belirt |
| [ADMIN-001](09-admin-experience.md) | 🟠 | Tarih filtresi ile ekranda gösterilen/CSV'ye yazılan zaman damgaları farklı zaman dilimlerinde; gün bazlı mutabakat sessizce yanlış | `admin/class-nicepay-transactions.php:793` | `created_at`/`updated_at`'i PHP `gmdate()` ile yaz; tek UTC sözleşmesi kur |

### E. Mimari ve test tabanı (6 bulgu)

| ID | Sev | Sorun | Konum | Düzeltme |
|---|---|---|---|---|
| [TEST-001](10-testing.md) | 🔴 | Ödeme akışının en kritik ~1.500 satırı (return handler, `handle_return()`, ödeme formu, dört AJAX ucu) tamamen test edilmemiş | `includes/class-nicepay-return-handler.php:25` | Sıralama sözleşmesini pinleyen entegrasyon testleri: claim → transport → ledger → `payment_complete` |
| [TEST-002](10-testing.md) | 🟠 ↓ | `process_refund()`'ın transport **sonrası** yedi hata dalının tamamı test edilmemiş — paranın kaybolduğu bölge | `includes/class-nicepay-gateway.php:807` | Her dal için bir test: `WP_Error`, binding uyuşmazlığı, red, ledger yazamama, tam iade, claim serbest bırakma |
| [TEST-005](10-testing.md) | 🟠 | Coverage kapsamı `admin/`, kök eklenti dosyası ve `templates/`'i tamamen dışlıyor; eşik de yok | `phpunit.xml:24` | Kapsamı genişlet + kırıcı bir taban eşik tanımla |
| [TEST-007](10-testing.md) | 🟠 | Beş nonce/capability kapısından dördü hiç test edilmiyor; `wp_verify_nonce` stub'ı bile yok | `nicepay-payment-gateway.php:548` | `wp_verify_nonce`/`current_user_can` stub'ları + beş kapının negatif testi |
| [ARCH-002](06-architecture-quality.md) | 🟠 | WooCommerce ve standalone dönüş işleyicileri ~300 satır neredeyse birebir kopya | `includes/class-nicepay-gateway.php:376` | Ortak `NicePay_Payment_Completion` servisi çıkar; iki akış onu çağırsın |
| [ARCH-011](06-architecture-quality.md) | 🟠 | WP çekirdek fonksiyonları için `function_exists()` koruması güvenlik kapılarını fail-open yapıyor; test iskelesi üretim koduna sızmış | `nicepay-payment-gateway.php:417` | Guard'ları kaldır, test iskelesini `tests/bootstrap/wp-stubs.php`'e taşı |

### F. Sürüm mekaniği — PR'ın kendi iddiasını yalanlayanlar (4 bulgu)

| ID | Sev | Sorun | Konum | Düzeltme |
|---|---|---|---|---|
| [GAP-001](16-coverage-gaps.md) | 🟠 | `WC_Payment_Gateway` ve `AbstractPaymentMethodType` (GPLv3) türev eseri dört yerde MIT beyan ediliyor; `LICENSE:8` sahip olunmayan bir `sublicense/sell` hakkı devrediyor | `LICENSE:1-21`, `nicepay-payment-gateway.php:15`, `readme.txt:7-8`, `composer.json:5` | GPLv2-or-later'a geç (dört yeri birden) + `check-version.js`'e GPL-uyumluluk allowlist kapısı |
| [DOC-001](13-documentation.md) | 🟠 | `readme.txt`'de `Contributors:` başlığı yok — projenin **kendi** WordPress.org publish job'ı SVN'e yazmadan hemen önce durur | `readme.txt:1` | Başlığı ekle + `deploy-wordpress-org.yml`'e prepare aşamasında ön kontrol koy |
| [CI-001](12-build-ci-release.md) | 🟠 | Entegrasyon script'lerine gömülü `2.0.0`; ilk sürüm artışı `database-integration` ve `woocommerce-integration` job'larını deterministik olarak kırar | `tests/integration/run-schema-migration.sh:80` | Beklenen sürümü plugin header'ından oku, sabit yazma |
| [CI-002](12-build-ci-release.md) | 🟠 | `Update URI` kapısı GitHub ve WP.org kanallarını kilitliyor — *kısmen çürütüldü:* WP.org iş akışı `NICEPAY_DISTRIBUTION_CHANNEL` kaçışıyla kendi içinde tutarlı | `.github/scripts/check-version.js:75` | Aynı kaçışı `composer quality`, `tests.yml` package ve `release.yml` yollarına da yay |

### G. Dokümantasyon ve analiz arşivi bütünlüğü (9 bulgu)

| ID | Sev | Sorun | Konum | Düzeltme |
|---|---|---|---|---|
| [DOC-003](13-documentation.md) | 🟠 | Shortcode `buyer_name`/`buyer_email`/`buyer_tel` üç dokümanda "ön-doldurma yapar" deniyor; kod bunları kayıtlı konfigürasyondan koşulsuz eziyor | `README.md:106` | Ürün kararını ver: ya attribute'ları gerçekten uygula ya üç dokümandan da kaldır |
| [DOC-004](13-documentation.md) | 🟠 | ARCHITECTURE.md bu PR'ın mimarisini tanımıyor: yeni sınıflar, 70 sütunlu şema, `nicepay_refund_attempts` tablosu ve yetki modeli eksik/çelişkili | `docs/ARCHITECTURE.md:62` | Yeni sınıf envanteri, tam veri modeli, hook/option listesi ve tek yetki modeliyle yeniden yaz |
| [I18N-001](11-i18n-l10n.md) | 🟠 | Birincil pazar dili Korece çalışma zamanında yalnız %28 çevrili (83 fuzzy kayıt `.mo`'ya hiç girmiyor); zh_CN de aynı | `languages/nicepay-payment-gateway-ko_KR.po:1` | 83 fuzzy kaydı gözden geçir; tr_TR'de zaten uygulanan "boş msgstr = bilinçli fallback" politikasını ko/zh'ye de uygula |
| [I18N-002](11-i18n-l10n.md) | 🟠 | Müşterinin gördüğü standalone ödeme formunun 18 string'inden 10'u üç yerel dilin **hiçbirinde** çevrilmemiş | `templates/standalone-payment-form.php:127` | Müşteri yüzeyini öncelikli katalog olarak işaretle ve önce onu tamamla |
| [I18N-004](11-i18n-l10n.md) | 🟠 | CI, POT ile PO katalogları arasında msgid bütünlüğünü hiç doğrulamıyor; dört katalogdan da silinen bir msgid tüm kapılardan geçiyor | `.github/scripts/check-translations.sh:51` | `msgcmp` tabanlı bir kapı ekle |
| [META-001](14-analysis-docs-integrity.md) | 🟠 ↓ | Önceki review'ın 00–12 dokümanları düzeltilmiş kodun yanına şimdiki zaman kipiyle merge ediliyor; 2.373 satır referansı alakasız koda düşüyor | `docs/analysis/00-executive-summary.md:38` | Ya arşivi depodan çıkar ya her dosyanın başına tarihli "donmuş anlık görüntü" bandı koy |
| [META-002](14-analysis-docs-integrity.md) | 🟠 ↓ | Çalışır bir sömürü `curl` komutu içeren güvenlik analizi, düzeltilmiş sürüm de `2.0.0` kaldığı için yayımlanmış sürüme karşı silah — deponun kendi SECURITY.md politikasını ihlal ediyor | `docs/analysis/04-security.md:180` | Sömürü komutlarını redakte et; düzeltilmiş sürümü ayrı numarala |
| [META-004](14-analysis-docs-integrity.md) | 🟠 | Planın kendi P0 kapısı sağlanmamış (10 paketten 7'si KISMİ) ama PR "release readiness" olarak sunuluyor | `docs/analysis/13-remediation-action-plan.md:446` | Kapıyı ya karşıla ya gerekçesiyle resmen gevşet; üç kapıdan hangisinin bağlayıcı olduğunu tek yerde yaz |
| [META-006](14-analysis-docs-integrity.md) | 🟠 | CONFIGURATION.md'nin geri dönüşsüz silme öncesi önerdiği "CSV'yi dışa aktar" adımı 10.000 satırlık dışa aktarma sınırını hiç söylemiyor | `docs/CONFIGURATION.md:71` | Sınırı ve tarih aralığıyla parçalı export prosedürünü prosedürün içine yaz |

---

## En büyük 5 risk

| # | Risk | Neden önemli | Bulgular | Aksiyon |
|---|---|---|---|---|
| 1 | **Rutin bir "Güncelle" tıklaması mağazayı hem çift-tahsilata açıyor hem tüm iadeleri öldürüyor** ([CR-1](15-cross-cutting.md)) | Diğer zincirler bir kullanıcı eylemi veya ağ olayı gerektirir; bu zincir **sessizce, üretimde, tek tıkla** tetiklenir. `uniq_tid` kaybolduğu an `gateway.php:461`'in başka bir satıra TID yazmasını engelleyen tek mekanizma yok olur; aynı anda `captured_amount = '0.00'` PHP'de truthy olduğu için `nicepay_normalize_amount()` `false` döner ve yükseltme öncesi her ödenmiş sipariş kalıcı olarak iade edilemez hâle gelir. Mağaza sahibi bunu haftalar sonra öğrenir. | DATA-001, DATA-018, MONEY-015, DATA-002/020, MONEY-001/018, MONEY-002 | Gerçek bir v1 `CREATE TABLE` fixture'ı + backfill migrasyonu + dbDelta sonrası `SHOW INDEX` doğrulaması. **Tek test tüm kümeyi yakalar.** |
| 2 | **Sıfır-çıkışlı kilitlenme: sipariş hem ödenemez, hem iade edilemez, hem silinemez, hem sessiz** ([CR-3](15-cross-cutting.md)) | Approval sırasında bir worker restart / OOM / `max_execution_time` yeter. Cron satırı `needs_reconciliation` yapar ama kilidi bırakmaz; WooCommerce siparişi hâlâ "ödenebilir" görünür; her deneme `uniq_active_attempt` duplicate key'ine çarpar; `process_payment()` hiçbir notice basmadan `failure` döner. Retention predicate'i iki koşulu birden ihlal ettiği için satır asla silinemez. Operatörün dokümante edilmiş tek çaresi **ham SQL**. | MONEY-003/016, DATA-021, ADMIN-002, MONEY-009, DATA-014, PROTO-005, PLAT-001 | Cron `UPDATE`'ine `active_attempt_key = NULL` (tek satır) **+** mutabakat çözüm aksiyonu **+** `net_cancel_*` alanlarını detay görünümüne ve CSV'ye aç. |
| 3 | **Test modunda kalmış mağaza gerçek siparişleri para almadan tamamlıyor** ([CR-2](15-cross-cutting.md)) | Üç savunmanın üçü de aynı anda etkisiz: `is_available()` mod kontrolü yapmıyor, checkout'taki "Test mode" bandı `.nicepay-notice` tabanının arka planı olmadığı için düz metin olarak kayboluyor, admin bandı her wp-admin sayfasında kapatılamaz şekilde durduğu için uyarı körlüğü yaratıyor. `NICEPAY_TEST_MERCHANT_KEY` herkese açık olduğundan bu modda imza doğrulaması merchant'a özgü hiçbir güven vermiyor. Dijital/indirilebilir ürünler otomatik teslim edilir. | SEC-013, SEC-001, UX-002, ADMIN-003, MONEY-020 | `is_available()`'a mod kapısı + `.nicepay-notice` görsel tabanı + test siparişlerini `on-hold` + etiketle bırak. |
| 4 | **Auth imzası `Moid`/`TxTid`'i bağlamıyor ve net-cancel saldırgan alanlarıyla kuruluyor** ([CR-5](15-cross-cutting.md)) | `verify_auth_signature` yalnız `AuthToken + MID + Amt + Key` kapsıyor; `request_net_cancel` `TID`/`AuthToken`/`Amt` alanlarını **doğrudan POST'tan** alıyor. Ayrıca yerel doğrulamada reddedilen **başarılı** bir authentication ne ters çevriliyor ne deftere yazılıyor — operatörün panelinde bu olayın hiçbir görünürlüğü yok, çünkü satır hâlâ `pending`. Para etkisi NICEPAY'in `AuthToken ↔ TID` eşleşmesini kendi tarafında doğrulamasına bağlı (**güven: düşük**), ama yapısal kusur koddan **kesin**. | SEC-011, SEC-012, SEC-004, MONEY-025, MONEY-019, PROTO-004 | Ters çevirme alanlarını claim edilmiş yerel satırdan al + `ReqReserved` bağlama token'ı + reddedilen başarılı auth'u deftere yaz. |
| 5 | **"Release readiness" iddiası mekanik olarak doğrulanamıyor** | PR'ın başlığı bir hazırlık iddiası; gerçekte projenin kendi publish job'ı `Contributors:` eksikliğinden durur, bir sonraki sürüm artışı CI'ı kırar, eklenti GPL türev eseri olduğu hâlde MIT beyan ediyor, ödeme akışının en kritik ~1.500 satırı test görmüyor ve remediation planının kendi P0 kapısı sağlanmamış. Bu kalemler tek tek küçük ama birlikte iddiayı çürütüyor — ve dördü de birkaç saatlik iş. | DOC-001, CI-001, CI-002, GAP-001, META-004, TEST-001 | Aşama 0'ı bugün kapat; ardından iddiayı ya kanıtla ya PR başlığından çıkar. |

**Ayrıca izlenmesi gerekenler (top-5'e girmedi ama gerçek):**
[CR-4](15-cross-cutting.md) —
varsayılan ayarlı bir Kore mağazasında kesirli toplam müşteriyi fazla tahsil eder ve ikinci kısmi
iadeyi kalıcı olarak bloke eder, hata mesajı yuvarlamadan hiç bahsetmez.
[CR-6](15-cross-cutting.md) —
multisite ağ etkinleştirmesi `switch_to_blog()` içinde `flush_rewrite_rules()` çağırdığı için
alt sitelerin **tüm** yazı/sayfa/ürün URL'lerini 404'e düşürebilir.

---

## Bu PR'ın güçlü yönleri

Bunlar nezaket cümlesi değil; her biri kod üzerinde doğrulandı ve çoğu bu sınıftaki eklentilerde
bulunmuyor.

- **İmza katmanı sözleşme düzeyinde doğru.** Dört akışın (auth request/response, approval
  request/response, cancel request/response, net-cancel) SHA-256 bileşim sırası NICEPAY v2.0.8
  manüeliyle birebir uyuşuyor. `tests/unit/NicePaySignatureIntegrationTest.php` gerçek satıcı
  digest'lerini **literal** olarak pinliyor — üretim fonksiyonuyla üretilmiş tautolojik bir
  beklenti değil; altı digest de bağımsız olarak yeniden hesaplanıp doğrulandı.
- **Para aritmetiği baştan sona string-tabanlı tamsayı.** Defter yolunda tek bir float dönüşümü
  yok; decimal sütunlar `%s` formatıyla yazılıyor; 12 haneli protokol sınırı DB `decimal(14,2)`
  ile tutarlı. Bu, WooCommerce ödeme eklentilerinde nadir görülen bir disiplin.
- **Eşzamanlılık DB kısıtlarıyla çözülmüş, tahminle değil.** `pending → approving` geçişi tek
  atomik `UPDATE`'in etkilenen satır sayısıyla kilitleniyor; `uniq_moid`/`uniq_tid`/
  `uniq_active_attempt` indeksleri gerçek MariaDB üzerinde entegrasyon testiyle doğrulanıyor.
  SELECT-then-UPDATE yarışı yok.
- **Fail-closed disiplini tutarlı.** Belirsiz sonuç asla tahmin edilmiyor: her doğrulanamayan
  onay `needs_reconciliation` + net-cancel denetim kaydı üretiyor; iade denemesi PG'ye gitmeden
  **önce** append-only bir tabloya yazılıyor; sipariş elle iptal edildiğinde sessiz para hareketi
  yapmak yerine sipariş notu bırakılıyor.
- **AppSec temeli sağlam.** Her admin yazma aksiyonunda capability + nonce çifti; sıfır SQL
  enjeksiyon yolu (dinamik tanımlayıcılar regex ile doğrulanıyor, `ORDER BY` allowlist'ten
  geliyor); dört JS dosyasında tek bir `innerHTML` yok; tüm imza karşılaştırmaları istisnasız
  `hash_equals`; SSRF allowlist'i 20 saldırgan URL vakasıyla karakterize edilmiş; CSV formül
  enjeksiyonu Unicode boşluk öneklerini de kapsayan doğru bir desenle engelleniyor.
- **HPOS entegrasyonu gerçek.** Kod tabanında tek bir `get_post_meta`/`update_post_meta`/
  `'shop_order'` kullanımı yok; uyum doğru hook'ta (`before_woocommerce_init`) ve `class_exists`
  koruması altında beyan ediliyor; CI'da legacy/HPOS matrisi gerçek WooCommerce üzerinde koşuyor.
- **CI tedarik zinciri güvenliği örnek düzeyde.** Üçüncü taraf action'ların **tamamı** tam commit
  SHA'sına pinli; her workflow'da `permissions: contents: read`; `pull_request_target` yok; docker
  imajları digest ile pinli; `npm ci --ignore-scripts`; WP.org publish gerçekten manuel,
  varsayılanı `dry-run` ve tam eşleşen onay metniyle kapılı.
- **i18n altyapısı (kataloglar değil) çok iyi.** 506 çeviri çağrısının tamamı literal domain
  kullanıyor; POT CI'da `wp i18n make-pot` ile yeniden üretilip byte-byte karşılaştırılıyor;
  `.mo` tazeliği `msgfmt` + `cmp` ile zorlanıyor; placeholder içeren 22 string'in 22'sinde
  `translators:` yorumu var; erken çeviri çağrısı (WP 6.7 uyarısı) hiç yok.
- **Retention ve GDPR ciddiye alınmış.** Varsayılan kapalı (opt-in), zorunlu onay kutusu, geri
  dönüşsüzlük ve kapsam dışı kalanlar için birinci sınıf uyarı metni, `add_settings_error` ile
  gerçek doğrulama geri bildirimi; exporter/eraser doğru filtrelere bağlı ve finansal referansları
  koruyup kullanıcıya nedenini bildiriyor.
- **Önceki review'ın kapanış iddiaları gerçekten uygulanmış.** 13/14 boyutunda "DÜZELTİLDİ" denen
  maddeler koda karşı örneklendi: atomik CAS claim, sunucu-otoriteli teklif çözümleyici, opak
  shortcode ID'leri, EUC-KR'nin tamamen kaldırılması, gateway varsayılanının `no` olması, merchant
  key'in HTML'e basılmaması. **"Kapatıldı denip düzeltilmemiş" sınıfı bu PR'da neredeyse hiç
  görülmüyor** — bu, ekibin kapanış disiplini açısından güçlü bir sinyal.

---

## Birinci sınıf ürüne giden yol

Hedef "bug'sız" değil, **mükemmel bir ürün deneyimi**. Etki sırasıyla sekiz madde:

1. **Yükseltme yolunu iddia olmaktan çıkarıp kanıta dönüştürün.** Depoda gerçek bir v1
   `CREATE TABLE` fixture'ı yok; PR'ın en riskli vaadi hiçbir testle desteklenmiyor. Tek bir
   entegrasyon senaryosu (v1 tablo + birkaç ödenmiş satır → `maybe_install()` → uçtan uca iade)
   [RC-1](15-cross-cutting.md)'in 11 bulgusunu
   birden kapatır. Yanına bir **geri alma** hikâyesi ekleyin: bugün `payment_data` denetim yükü
   dbDelta'dan **önce** geri alınamaz şekilde siliniyor, yani migrasyon sonradan başarısız olsa
   bile veri gitmiştir.
2. **Her kilide bir kurtarma yolu bırakın.** `needs_reconciliation` ve `active_attempt_key`
   yazılıyor ama hiçbir yerde okunmuyor/çözülmüyor — bunlar durum değil, tek yönlü kapı. Operatöre
   gerekçe zorunlu, denetim kaydı üreten bir çözüm aksiyonu verin; yanına birkaç WP-CLI komutu
   ekleyin (`nicepay reconcile`, `nicepay release-lock`). Bugün her sıkışmış durumun dokümante
   edilmiş tek çaresi ham SQL — bir ödeme eklentisinde bu, veri bütünlüğü açısından en riskli
   müdahale biçimi.
3. **Fail-open kapıları kapatın.** `is_available()`'da mod kontrolü yok, rate limit geçersiz IP'de
   geçiriyor, WP çekirdek fonksiyonları `function_exists()` ile sarıldığı için güvenlik kapıları
   sessizce atlanabilir hâle geliyor. Bir ödeme yüzeyinde varsayılan **reddetmek** olmalı; kapının
   "fonksiyon yoksa geç" semantiği taşıması kapının amacını belirsizleştiriyor.
4. **Tek doğru kaynak kurun.** Bugün: iki farklı `Amt` normalizeri aynı dosyada 65 satır arayla;
   ~300 satır dönüş işleyicisi iki yerde; paketleme dört ayrı yerde tanımlı; üç farklı redaksiyon
   listesi; "NicePay hazır mı?" mantığı altı yerde. Her tekrar, ileride sessizce ayrışacak bir
   çatal. `NicePay_Payment_Completion` servisi tek başına hem ARCH-002'yi hem TEST-001'in yükünü
   yarıya indirir.
5. **Ölü niyeti silin veya tamamlayın.** VBANK alt sistemi (dal + 5 sütun + indeks + ayar + UI)
   tamamen erişilemez ama `4100` kodu tam tahsilat sayacak şekilde yazılmış — sertifikasyon kapısı
   tek bir dizi literal'i. `config_fingerprint` üç kez yazılıp sıfır kez okunuyor,
   `binding_token_hash` hiç yazılmıyor. Bunlar okuyucuya var olmayan bir bütünlük kontrolü
   olduğunu düşündürüyor; bu, incelemede yanlış güvence demek.
6. **Kod ne durum üretiyorsa tasarım ve çeviri katmanı onu karşılasın.** 11 defter durumundan
   5'inin rozet CSS'i yok — en acil müdahale gerektiren `needs_reconciliation` görsel olarak en
   sönük satır. `.nicepay-error` sınıfı CSS'te hiç tanımlı değil. Müşterinin gördüğü formun 18
   string'inden 10'u hiçbir dilde çevrilmemiş. Bunları **CI kapısına** bağlayın: `check-css.js`
   bugün yalnızca "ayrıştırılabilir mi" diye bakıyor; PHP'nin ürettiği durum listesiyle CSS'in
   tanımladığı sınıf listesini karşılaştırmak birkaç düzine satır.
7. **Ürüne bir operasyon yüzeyi verin.** Kod tabanında tek bir `do_action()` yok — ERP,
   muhasebe, bildirim, sadakat entegrasyonlarının tutunacağı hiçbir yaşam döngüsü olayı
   bulunmuyor. Denetim izi de eksik: iadeyi kimin başlattığı hiçbir yerde kaydedilmiyor. Ayrıca
   `nicepay_log()` WooCommerce yoksa `debug`/`info` kayıtlarını **tamamen düşürüyor** — yani
   standalone kurulumda teşhis imkânsız, üstelik doküman tam tersini vaat ediyor.
8. **Sürüm mekaniğini gerçek yapın.** Lisansı GPLv2-or-later'a taşıyın ve bir uyumluluk kapısı
   ekleyin; `Contributors:` başlığını ekleyin; sürümü sabit yazmak yerine header'dan okuyun;
   ZIP'i bit düzeyinde tekrarlanabilir hâle getirin (README bugün kullanıcıdan checksum
   doğrulaması istiyor, bu beklentiyi yaratıyor); `docs/analysis` arşivine ya bir sahip ve
   gözden geçirme kadansı atayın ya depodan çıkarın.

---

## Önerilen sevkiyat sırası

| Aşama | Kapsam | Bulgular | Neden bu sırada |
|---|---|---|---|
| **0 — Sürüm bloğunu kaldır** | Lisansı GPLv2-or-later'a taşı + uyumluluk kapısı; `readme.txt`'ye `Contributors:`; entegrasyon script'lerindeki sabit sürümü header'dan oku; `NICEPAY_DISTRIBUTION_CHANNEL` kaçışını diğer boru hatlarına yay | GAP-001, DOC-001, CI-001, CI-002 | Hiçbiri ödeme koduna dokunmuyor ve toplamı birkaç saat. Bunlar çözülmeden **hiçbir şey yayınlanamaz** — publish job'ı SVN'e yazmadan durur ve ilk sürüm artışı CI'ı kırar. Ayrıca lisans, kod düzeltmeleriyle paralel ilerleyebilecek tek hukuki kalem. |
| **1 — Yükseltme yolunu kanıtla** | Gerçek v1 `CREATE TABLE` fixture'ı; `tid` nullability'sini `ALTER` ile düzelt; backfill migrasyonu; dbDelta sonrası `SHOW INDEX` doğrulaması; migrasyon kilidi; yükseltme rehberi | DATA-001, DATA-018, DATA-002/020, DATA-022, MONEY-015, MONEY-001/018, MONEY-002, DOC-002 | [CR-1](15-cross-cutting.md) rutin bir güncellemeyle sessizce tetikleniyor; ertelenen her gün risk penceresini büyütüyor. Ayrıca **sonraki her aşama bu şemanın doğru olduğunu varsayıyor** — burayı atlarsanız Aşama 2'nin düzeltmeleri de yükseltilmiş sitelerde çalışmaz. |
| **2 — Para güvenliği kapıları** | Net-cancel alanlarını yerel satırdan al; `is_available()`'a mod kapısı; cron'da `active_attempt_key = NULL`; mutabakat çözüm aksiyonu + `net_cancel_*` alanlarını UI/CSV'ye aç; KRW ondalık zorlaması | SEC-011, SEC-013, SEC-012, MONEY-003/016, DATA-021, ADMIN-002, MONEY-005/017 | Gerçek para hareketi. Kilit açma tek satırlık bir değişiklik ama **tek başına yarım kalır**: kilidi bırakan cron olmadan mutabakat aracı iş görmez, mutabakat aracı olmadan kilit açma yalnızca satırı serbest bırakır. Üçü birlikte sevk edilmeli. |
| **3 — Akış dayanıklılığı ve test tabanı** | `NicePay_Payment_Completion` servisi; return handler + `handle_return()` + AJAX uçları için entegrasyon testleri; `process_refund()` transport-sonrası dalları; nonce/capability testleri; coverage kapsamı + eşik; net-cancel retry; PG watchdog; `validate_fields()` | ARCH-002, ARCH-011, TEST-001, TEST-002, TEST-005, TEST-007, PROTO-004, JS-002, PLAT-001, PLAT-002 | Aşama 1–2'nin düzeltmeleri ancak bu testlerle **kalıcı** olur; aksi hâlde bir sonraki refactor onları sessizce geri alır. ARCH-002'nin birleştirmesi önce yapılırsa TEST-001'in yazılacak test yüzeyi yarıya iner — bu yüzden aynı aşamada ve bu sırayla. |
| **4 — Operatör ve alıcı deneyimi** | Tek UTC zaman damgası sözleşmesi; iade diyaloğuna tutar/terminoloji; standalone form yapısı; eksik durum rozetleri ve `.nicepay-notice`/`.nicepay-error` CSS'i; müşteri yüzeyi çevirileri + `msgcmp` kapısı | ADMIN-001, UX-004, UX-007, I18N-001, I18N-002, I18N-004, [RC-8](15-cross-cutting.md) orta bulguları | Para güvenliği sağlanmadan cilalamak yanlış öncelik; ama para güvenliği sağlandıktan sonra ürünün "birinci sınıf" hissi **tam olarak burada** kazanılıyor. Bu aşamanın çoğu düşük riskli ve paralelleştirilebilir. |
| **5 — Doküman ve analiz arşivi** | ARCHITECTURE.md'yi yeniden yaz; `buyer_*` shortcode kararını ver; filtre/durum/özellik dokümantasyonu; `docs/analysis` arşivine banner veya çıkarma kararı; sömürü komutlarını redakte et | DOC-003, DOC-004, DOC-005…DOC-014, META-001, META-002, META-004, META-006 | Kod stabilize olmadan yazılan doküman ilk refactor'da tekrar bayatlar — bu, önceki review setinin başına gelen şeyin ta kendisi. Tek istisna META-002 (yayımlanmış sömürü komutu): **bu, Aşama 0'a alınabilir.** |

---

## Metodoloji ve kısıtlar

**Yöntem.** Beş fazlı çoklu-ajan pipeline'ı: paylaşılan mimari harita → 14 boyutun bağımsız
taranması (dört kritik boyutta iki bağımsız geçiş + dedupe) → kritik/yüksek 56 bulgunun ikişer
düşmanca doğrulayıcıyla sınanması (kod-gerçekliği ve istismar-edilebilirlik lensleri) →
deterministik rapor üretimi → çapraz kesen sentez. Doğrulama sonucu: **28 confirmed,
83 partially-confirmed, 1 refuted**; **24 bulgunun şiddeti düşürüldü, hiçbiri yükseltilmedi.**
Tüm bulgu konumları dosya varlığı ve satır aralığı için otomatik doğrulandı (**296/296**).

**Kısıtlar — bu özet okunurken bilinmesi gerekenler:**

- **Statik inceleme.** Kod okundu, eklenti çalıştırılmadı. Test paketi bu review kapsamında
  **çalıştırılmadı**; PR'ın "393 test / 1112 assertion geçti" iddiası bağımsız olarak yeniden
  üretilmedi — yalnızca test *kalitesi* incelendi.
- **Gerçek NICEPAY sandbox'a karşı doğrulama yapılmadı.** Protokol bulguları koddaki kullanım ile
  PG-Web v3 spesifikasyonunun karşılaştırmasına dayanıyor. Özellikle üç soru sandbox'tan tek bir
  gerçek yanıt yakalanmadan kapanmıyor: auth-return `Amt`'sinin sıfır-dolgulu gelip gelmediği
  (PROTO-002/MONEY-006), MID'in `EdiType` varsayılanının JSON olup olmadığı (PROTO-003) ve
  NICEPAY'in `AuthToken ↔ TID` eşleşmesini kendi tarafında doğrulayıp doğrulamadığı (CR-5a).
- **Gerçek tarayıcı ve erişilebilirlik matrisi yok.** UX/a11y bulguları kaynak koddan (HTML
  üretimi, CSS, JS) çıkarıldı; ekran okuyucu veya cihaz testi yapılmadı. Kontrast değerleri
  CSS'teki renk çiftlerinden hesaplandı.
- **Canlı veritabanı davranışı doğrulanmadı.** CR-1'in en kritik halkası — WordPress strict SQL
  mode'u kaldırdığı için `NOT NULL` sütuna `NULL` yazımının sessizce `''` üretmesi — kod yolu
  olarak **kesin**, MySQL/MariaDB davranışı olarak **orta güvenli**. Tek kullanımlık bir MariaDB
  üzerinde mutlaka doğrulanmalı; bu, Aşama 1'in ilk adımı olmalı.
- **Doğrulama kritik ve yüksek ile sınırlı.** 296 bulgunun 95'i en az bir bağımsız karar aldı;
  orta ve düşük şiddetli bulgular tek ajanın gözlemidir ve raporlarda "Doğrulanmadı" olarak
  işaretlidir.
- **Gerçek bağımsız bulgu sayısı 296'nın altındadır.** Çapraz kesen analiz altı bulgu çiftini
  mükerrer olarak işaretledi ve iki bulguyu ([ADMIN-004](09-admin-experience.md),
  [CI-002](12-build-ci-release.md)) kısmen çürüttü. Sayı, önceliklendirme için değil kapsam
  ölçüsü olarak okunmalı; önceliklendirme
  [kök neden kümelerinden](15-cross-cutting.md) yapılmalı.
- Bulgular `development` dalının HEAD `6b7fedf` hâline göredir; dal ilerledikçe satır numaraları
  kayabilir.
