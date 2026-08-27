# 15 — Çapraz Kesen Analiz

> **Kapsam.** PR #3 (`main` ← `development`, 113 dosya, +43.412 / −3.292). 14 boyut raporundaki
> 296 doğrulanmış bulgu üzerinden kök neden kümeleri, bileşik risk zincirleri, sahipsiz sistemik
> boşluklar ve boyutlar arası çelişkiler.
>
> **Metodoloji.** Bu doküman hiçbir boyut raporunu olduğu gibi kabul etmez. Aşağıdaki her iddia,
> deponun `development` dalındaki **mevcut** kaynak kodu okunarak yeniden doğrulanmıştır; satır
> numaraları dosyaların şu anki haliyle eşleşir. Kodla doğrulanamayan (çalışan bir MySQL/PSP
> gerektiren) iddialar `güven: düşük` olarak açıkça işaretlenmiştir. Beş bulgu bu geçişte
> **kısmen çürütülmüştür** ve [§4](#4-boyutlar-arası-çelişkiler) altında listelenir.

---

## 1. Kök Neden Kümeleri

Sekiz kümede 118 bulgu toplanıyor. Her kümenin "yapısal düzeltme" sütunu, o kümedeki bulguların
tamamını tek seferde kapatacak müdahaleyi tarif eder.

### Küme genel görünümü

| # | Küme | Bulgu sayısı | En yüksek şiddet | Etkilenen boyutlar |
|---|---|---|---|---|
| RC-1 | 1.x → 2.0 yükseltme yolu hiç çalıştırılmadı | 11 | critical | 02, 04, 10, 13 |
| RC-2 | Kilit alınıyor, kurtarma yolu bırakılmıyor | 10 | high | 01, 02, 04, 09 |
| RC-3 | Protokol yanıt **şekli** varsayım; sözleşme testi yok | 9 | medium | 01, 02, 10 |
| RC-4 | Tek doğru kaynak yok — aynı kural N yerde elle tekrar | 12 | high | 06, 12, 13 |
| RC-5 | Ölü niyet: yarım bırakılmış özellikler şemada yaşıyor | 15 | medium | 01, 02, 04, 05, 06, 11 |
| RC-6 | Fail-open savunma kapıları | 8 | high | 03, 04, 05, 06 |
| RC-7 | Üç ayrı zaman dilimi sözleşmesi bir arada | 6 | high | 01, 04, 09, 11 |
| RC-8 | Kod durum üretiyor, tasarım/çeviri katmanı karşılamıyor | 12 | high | 07, 08, 11 |

---

### RC-1 — "1.x → 2.0 yükseltme yolu hiç çalıştırılmadı"

**Bulgular.** [DATA-001](04-data-layer.md) · [DATA-002](04-data-layer.md) ·
[DATA-015](04-data-layer.md) · [DATA-018](04-data-layer.md) · [DATA-020](04-data-layer.md) ·
[DATA-037](04-data-layer.md) · [MONEY-001](02-payment-money-correctness.md) ·
[MONEY-002](02-payment-money-correctness.md) · [MONEY-015](02-payment-money-correctness.md) ·
[MONEY-018](02-payment-money-correctness.md) · [TEST-013](10-testing.md) · [DOC-002](13-documentation.md)

**Kök neden.** Depoda **hiçbir yerde gerçek bir v1 tablo fixture'ı yok.**
`tests/integration/schema-migration.php` taze bir v2 tablosundan başlar; unit testlerdeki sahte
`$wpdb` her sorguya "başarılı" der. Bu tek boşluk, birbirinden bağımsız görünen dört ayrı hatayı
aynı anda görünmez kılıyor. Bunlar tesadüf değil, aynı doğrulanmamış varsayımın dört yüzü:
*"eski satırlar yeni sözleşmeye kendiliğinden uyar."*

**Kod kanıtı (bu geçişte yeniden doğrulandı):**

| Kanıt | Konum | Ne gösteriyor |
|---|---|---|
| `UPDATE {$table} SET tid = NULL WHERE tid = ''` | `includes/class-nicepay-installer.php:269` | v1'de `tid` `NOT NULL DEFAULT ''` ise WordPress'in kapattığı strict mode altında bu sessizce `''` yazar; `uniq_tid` hiç oluşamaz. **güven: orta** (canlı MariaDB'de doğrulanmadı) |
| `'tid' => 'varchar(50) DEFAULT NULL'` + `'UNIQUE KEY uniq_tid (tid)'` | `class-nicepay-transaction-schema.php:45,127` | Yeni şema nullable bekliyor; dbDelta nullability değiştirmez |
| `... WHERE tid = %s AND wc_order_id = %d AND flow = 'woocommerce'` | `includes/nicepay-functions.php:450` | **`flow=''` satırı buradan asla dönmez** |
| `in_array( (string) $transaction->flow, array( '', 'woocommerce' ), true )` | `includes/class-nicepay-gateway.php:704` | Yukarıdaki nedenle **ölü kod** — v1 hoşgörüsü hiç çalışmıyor |
| `const COLUMN_DECIMAL_14_2 = 'decimal(14,2) NOT NULL DEFAULT 0'` | `class-nicepay-transaction-schema.php:32` | Backfill edilmeyen satırda `captured_amount = '0.00'` |
| `! empty( $transaction->captured_amount ) ? ... : $transaction->amount` | `includes/class-nicepay-gateway.php:723-726` | PHP'de `'0.00'` **truthy**; fallback ölü, `nicepay_normalize_amount('0.00')` `false` döner |

`nicepay_normalize_amount('0.00')` izini elle sürdüm: regex geçer → `$integer='0'` →
`ltrim('0','0') === ''` → `return false` (`nicepay-functions.php:1518-1521`). Sonuç: yükseltilmiş
her ödenmiş sipariş için `nicepay_refund_amount_error` — *"Refund amount is invalid for this
currency."* Hatanın gerçek nedeni para birimi değil, **defterin boş olması**.

**Yapısal düzeltme (tek müdahale, 11 bulguyu kapatır):**
1. `tests/integration/run-schema-migration.sh`'e **gerçek bir v1 `CREATE TABLE` fixture'ı** ekle
   (`tid varchar(50) NOT NULL DEFAULT ''`, `flow` yok, `captured_amount` yok, birkaç ödenmiş satır),
   ardından `maybe_install()` çalıştırıp iade akışını uçtan uca koştur. Bu tek test RC-1'in
   tamamını üretimden önce yakalar.
2. `maybe_install()` içine dbDelta'dan **sonra** açık bir doğrulama adımı ekle: `SHOW INDEX` ile
   `uniq_moid`/`uniq_tid`/`uniq_active_attempt` üçünün de var olduğunu kanıtla; yoksa sürüm
   option'ını ilerletme (DATA-018).
3. Açık bir **backfill migrasyonu** yaz: `flow`, `mid`, `mode`, `currency`, `captured_amount`
   eski satırlar için türetilsin. `tid` NULL'lama işlemini `ALTER TABLE ... MODIFY tid varchar(50)
   NULL` **sonrasına** taşı.
4. CHANGELOG'a ve README'ye kırıcı değişiklik + yükseltme rehberi (DOC-002).

---

### RC-2 — "Kilit alınıyor ama hiçbir kurtarma yolu bırakılmıyor"

**Bulgular.** [MONEY-003](02-payment-money-correctness.md) · [MONEY-009](02-payment-money-correctness.md) ·
[MONEY-016](02-payment-money-correctness.md) · [MONEY-021](02-payment-money-correctness.md) ·
[DATA-014](04-data-layer.md) · [DATA-021](04-data-layer.md) · [DATA-031](04-data-layer.md) ·
[ADMIN-002](09-admin-experience.md) · [PROTO-005](01-protocol-conformance.md) · [ARCH-009](06-architecture-quality.md)

**Kök neden.** `needs_reconciliation` ve `active_attempt_key` **yazılıyor ama hiçbir yerde
okunmuyor/çözülmüyor**. Bu bir "durum" değil, tek yönlü bir kapı.

**Kod kanıtı — üç mekanizmanın kesişimi:**

```php
// includes/nicepay-functions.php:968-976  (stale-approving kurtarma cron'u)
$stale_sql = "UPDATE {$table}
              SET status = 'needs_reconciliation',
                  approval_state = 'needs_reconciliation', ...
              WHERE status = 'approving' AND approval_state = 'approving'
                AND approval_started_at < (UTC_TIMESTAMP() - INTERVAL 30 MINUTE)";
//  ^ active_attempt_key HİÇ TEMİZLENMİYOR
```

```php
// includes/class-nicepay-retention.php:307-315  (silinebilirlik predicate'i)
"... AND ledger.status <> 'needs_reconciliation'
     AND ledger.active_attempt_key IS NULL"
//  ^ yukarıdaki satırlar İKİ koşulu birden ihlal ediyor → asla silinemez
```

Yönetici tarafında ise `admin/class-nicepay-transactions.php` yalnızca **sayaç**
(`nicepay_get_reconciliation_count`, `:566`), **uyarı bandı** (`:634-645`) ve **filtre** sunuyor;
`wp_ajax_*` / `admin_post_*` kancalarını taradım — çözüm aksiyonu yok:

```
nicepay_init_payment · nicepay_refresh_nonce · nicepay_save_shortcode
nicepay_delete_shortcode · nicepay_cancel_transaction · nicepay_export_transactions
```

Üstüne `net_cancel_status` / `net_cancel_result_code` / `reconciliation_note` alanları
`get_safe_detail_fields()` allowlist'inde ve CSV sütun listesinde **yok** (PROTO-005) — yani
operatör "ters çevirme onaylandı mı?" sorusunu ekrandan cevaplayamıyor bile.

**Sonuç:** Kilitlenen satır **ödenemez** (uniq_active_attempt duplicate key) + **iade edilemez**
(`reconciliation_status='required'` kapısı, `gateway:711-713`) + **silinemez** (retention predicate)
+ **teşhis edilemez** (alanlar UI'da yok). Sayaç monoton artar, kalıcı kırmızı bant uyarı körlüğü
yaratır ve gerçek yeni olaylar gürültüde kaybolur.

**Yapısal düzeltme:**
1. Cron'un `UPDATE`'ine `active_attempt_key = NULL` ekle — tek kelimelik değişiklik, kilidi kırar.
2. `nicepay_resolve_reconciliation` admin aksiyonu: yetenek + işlem-özel nonce + zorunlu serbest
   metin gerekçe + `nicepay_refund_attempts` benzeri append-only denetim satırı. İki sonuç sunmalı:
   *"NICEPAY konsolunda ters çevirmenin onaylandığını doğruladım"* → `failed`;
   *"yakalama gerçekleşti"* → `paid` + `captured_amount`.
3. `net_cancel_*` ve `reconciliation_note` alanlarını hem detay görünümüne hem CSV'ye ekle.
4. Retention predicate'indeki ölü `ledger.status <> 'needs_reconciliation'` koşulunu kaldır
   (`IN (...)` listesi zaten dışlıyor) — okuyucuyu yanıltıyor.

---

### RC-3 — "Protokol yanıt ŞEKLİ varsayım; imza sözleşmesi test edilmiş, yanıt sözleşmesi edilmemiş"

**Bulgular.** [PROTO-002](01-protocol-conformance.md) · [PROTO-003](01-protocol-conformance.md) ·
[PROTO-012](01-protocol-conformance.md) · [PROTO-013](01-protocol-conformance.md) ·
[MONEY-006](02-payment-money-correctness.md) · [MONEY-022](02-payment-money-correctness.md) ·
[MONEY-028](02-payment-money-correctness.md) · [TEST-006](10-testing.md) · [DOC-018](13-documentation.md)

**Kök neden.** İmza **bileşim sırası** gerçek satıcı digest'leriyle pinlenmiş (bu PR'ın en güçlü
tarafı, hakkını veriyorum). Ama **yanıtın nasıl göründüğü** — dolgulu mu, JSON mu KV mi, hangi
alanlar geliyor — hiçbir fixture'la sabitlenmemiş. Sonuç: aynı protokol alanı iki farklı
sözleşmeyle doğrulanıyor.

**Kod kanıtı — aynı dosyada, 65 satır arayla iki farklı normalizer:**

```php
// includes/class-nicepay-inbound-validator.php:98   (AUTH dönüşü)
$posted_amount = nicepay_normalize_amount( $payload['Amt'], 'KRW' );
//   → regex '^(?:0|[1-9][0-9]*)...'  ⇒ '000000050000' REDDEDİLİR

// includes/class-nicepay-inbound-validator.php:162  (APPROVAL yanıtı)
$result_amount = nicepay_normalize_response_amount( $result['Amt'], 'KRW' );
//   → regex '^[0-9]{1,12}$' + ltrim('0')  ⇒ '000000050000' KABUL EDİLİR
```

Approval tarafında 12-byte sıfır dolgusu **açıkça destekleniyor ve test ediliyor** — yani ekip
NICEPAY'in bu formatı kullandığını biliyor. Auth tarafında aynı bilgi uygulanmamış. MID'in
sözleşmesi auth dönüşünde de sabit genişlik kullanıyorsa **her ödeme**
`nicepay_inbound_amount_mismatch` ile reddedilir — üstelik bu dalda net-cancel de gönderilmez
(bkz. CR-5 / MONEY-019), yani mağaza tüm ödemeleri kaybederken kart hold'ları da askıda kalır.

Ayrıca `EdiType` hiç gönderilmiyor ama `decode_response()` koşulsuz JSON varsayıyor (PROTO-003),
ve iptal yanıtındaki `Moid`/`RemainAmt` hiç okunmuyor (PROTO-012/MONEY-028) — PG'nin otoriter
kalan bakiyesi yerel defterle **hiç** karşılaştırılmıyor.

**Yapısal düzeltme:**
1. **Tek normalizer.** Gelen tüm protokol tutarlarında `nicepay_normalize_response_amount`
   kullanılsın. Bu güvenliği düşürmez: ikisi de kanonik pozitif tamsayı döndürür, tek fark
   baştaki sıfırlara toleranstır ve imza zaten **ham byte** üzerinden doğrulanıyor.
2. `EdiType=JSON`'u açıkça gönder — bir satır, tüm PROTO-003 riskini kapatır.
3. **Sanitize edilmiş satıcı fixture seti** ekle: gerçek bir auth-return POST'u, bir approval
   yanıtı, bir iptal yanıtı. Bu fixture'lar RC-3'ün tamamını sözleşme testine dönüştürür.
4. İptal yanıtında `Moid` bağlamasını ve `RemainAmt` çapraz kontrolünü ekle.

---

### RC-4 — "Tek doğru kaynak yok — aynı kural N yerde elle tekrarlanıyor"

**Bulgular.** [ARCH-001](06-architecture-quality.md) · [ARCH-002](06-architecture-quality.md) ·
[ARCH-004](06-architecture-quality.md) · [ARCH-013](06-architecture-quality.md) ·
[ARCH-014](06-architecture-quality.md) · [ARCH-015](06-architecture-quality.md) ·
[ARCH-018](06-architecture-quality.md) · [DATA-017](04-data-layer.md) ·
[CI-009](12-build-ci-release.md) · [DOC-003](13-documentation.md) · [DOC-004](13-documentation.md) ·
[ADMIN-010](09-admin-experience.md)

**Kök neden.** Kod tabanında **hiç servis/katman sınırı yok**; tekrar, soyutlama yerine
kopyalamayla çözülmüş. Ölçülebilir kanıt:

| Ölçüm | Değer | Konum |
|---|---|---|
| `nicepay-functions.php` satır sayısı | **1.956** | tek dosya |
| Aynı dosyadaki global fonksiyon | **59** | `grep -c "^function nicepay_"` |
| WooCommerce vs standalone dönüş işleyicisi | 919 vs 374 satır, ~300 satır neredeyse birebir | `class-nicepay-gateway.php:376+` / `class-nicepay-return-handler.php:25+` |
| Sertifikalı yöntem listesi | `array( 'CARD', 'BANK', 'CELLPHONE' )` literal'i | `nicepay-functions.php:1359` + iki yerde daha |
| Tablo adı elle kurulumu | 26 nokta | `$wpdb->prefix . 'nicepay_transactions'` |
| Paketleme tanımı | 4 ayrı yer | `build-release.sh:41` · `smoke-check-artifact.sh:50` · `deploy-wordpress-org.yml:177` · `.gitattributes:27` |

Bu, para akışının en kritik kısmı için doğrudan risk: **onay sonrası defter yazımı ve iptal
denemesi iki yerde bakılıyor.** Bir taraftaki düzeltme diğerine taşınmazsa iki akış sessizce
farklı davranmaya başlar — ve bunu yakalayacak hiçbir test yok (bkz. [TEST-001](10-testing.md)).

**Yapısal düzeltme:**
1. `NicePay_Payment_Completion` servisi çıkar: `complete(transaction, approvalResult, flow)`.
   Her iki dönüş işleyicisi bunu çağırsın; akışa özgü olan **yalnızca** yönlendirme ve bildirim
   olsun. Bu tek refactor ARCH-002'yi kapatır ve TEST-001'in kapsanabilir yüzeyini oluşturur.
2. `nicepay-functions.php`'yi katmanlara böl: `repository`, `money`, `protocol`, `labels`.
3. `NicePay_Method_Registry::certified()` — tek kaynak; VBANK kapısı dahil.
4. `.build-manifest` dosyası: build, smoke-check ve deploy aynı listeyi okusun (CI-009).

---

### RC-5 — "Ölü niyet: yarım bırakılmış özellikler şemada, kodda ve dokümanda yaşamaya devam ediyor"

**Bulgular.** [ARCH-003](06-architecture-quality.md) · [ARCH-007](06-architecture-quality.md) ·
[PROTO-007](01-protocol-conformance.md) · [PROTO-009](01-protocol-conformance.md) ·
[PROTO-015](01-protocol-conformance.md) · [PROTO-016](01-protocol-conformance.md) ·
[MONEY-012](02-payment-money-correctness.md) · [MONEY-024](02-payment-money-correctness.md) ·
[MONEY-027](02-payment-money-correctness.md) · [DATA-010](04-data-layer.md) ·
[DATA-032](04-data-layer.md) · [PLAT-014](05-wp-wc-platform.md) · [I18N-014](11-i18n-l10n.md) ·
[JS-017](07-frontend-js.md) · [DOC-012](13-documentation.md)

**Kök neden.** Planlanıp yarım bırakılan her özellik **geri alınmamış**. Bu yalnızca bakım yükü
değil; okuyucuya var olmayan bir güvence veriyor.

**Kod kanıtı — "yazılıyor ama hiç okunmuyor" envanteri:**

| Artefakt | Yazma | Okuma | Ne ima ediyor |
|---|---|---|---|
| `config_fingerprint` | 3 yer (`offer-resolver.php:157`, `gateway.php:276`, `nicepay-payment-gateway.php:594`) | **0** | "konfigürasyon kayması tespiti var" — yok. Üstelik iki yazıcı **farklı formül** kullanıyor |
| `binding_token_hash` | **0** | **0** | Şemada var; `ReqReserved` bağlamasının planlanıp bırakıldığının izi |
| `$req_reserved` | `gateway.php:406` (atanıyor) | **0** | İkinci bağlama kanalı tasarlanmış, uygulanmamış |
| VBANK (5 sütun + `idx_vbank_expires_at` + `4100` kodu + ayar + UI) | şema/kod hazır | erişilemez | Kapı **tek satır**: `$certified = array('CARD','BANK','CELLPHONE')` (`nicepay-functions.php:1359`) |
| `wp_set_script_translations()` | `blocks-integration.php:47` | işlevsiz | `wp-i18n` bağımlılığı yok, POT `--skip-js`, JS'te `wp.i18n` yok |

VBANK'ın özel önemi var: kapı tek bir dizi literal'i. O dizi bir gün genişletildiğinde
`4100` kodu doğrudan `paid` + `captured_amount` yazacak — yani **para yatırılmamış bir sanal
hesap "ödendi" sayılacak**, stok düşecek, iade edilebilir bakiye görünecek (MONEY-027).
Sertifikasyon kapısının kod yolunu değil yalnızca listeyi koruması, bu riski bir gözden kaçmaya
indirgiyor.

**Yapısal düzeltme:** "ölü yüzey yasak" politikası. (a) `config_fingerprint`/`binding_token_hash`:
ya doğrulamayı uygula ya sütunu düşür. (b) VBANK: kod yolunu tamamen kaldır veya `4100`'ü
`paid` değil `waiting` yapıp deposit webhook'unu yaz. (c) CI'a küçük bir gate: şemadaki her
sütunun en az bir okuma noktası olmalı.

---

### RC-6 — "Fail-open savunma kapıları"

**Bulgular.** [ARCH-011](06-architecture-quality.md) · [SEC-001](03-security.md) ·
[SEC-013](03-security.md) · [SEC-016](03-security.md) · [DATA-018](04-data-layer.md) ·
[DATA-025](04-data-layer.md) · [PLAT-010](05-wp-wc-platform.md) · [ADMIN-007](09-admin-experience.md)

**Kök neden.** Bir güvenlik/bütünlük kapısı, ön koşulu yoksa **geçiriyor**. Kod tabanında 42
`function_exists()` çağrısı var; kritik olanları:

```php
includes/class-nicepay-gateway.php:98   if ( function_exists('is_ssl') && ! is_ssl() ) return false;
admin/class-nicepay-admin.php:430       $https_ready = ! function_exists('is_ssl') || is_ssl();
admin/class-nicepay-admin.php:431       $cron_ready  = ! function_exists('wp_next_scheduled') || (bool) wp_next_scheduled(...);
includes/nicepay-functions.php:294-298  if ( false === filter_var($remote_addr, FILTER_VALIDATE_IP) ) return true;  // hız limiti fail-open
```

`is_ssl` / `wp_next_scheduled` WordPress çekirdek fonksiyonlarıdır — üretimde **her zaman**
vardır. Yani bu dallar ölü kod; ama kapının semantiğini "yoksa geç" yaparak niyeti bulanıklaştırıyor
ve test iskelesini üretim koduna sızdırıyor.

Aynı kalıbın en pahalı örneği `is_available()`: **mod kapısı hiç yok.**

```php
// includes/class-nicepay-gateway.php:76-102 — tam liste
enabled==='yes' · Installer::is_current() · MID+key dolu · enabled_methods boş değil
· currency destekli · is_ssl()
//  ^ 'test' modu için TEK BİR KONTROL YOK
```

`NICEPAY_TEST_MID` / `NICEPAY_TEST_MERCHANT_KEY` (`nicepay-payment-gateway.php:33-34`)
NICEPAY'in **herkese açık** sandbox kimlik bilgileridir. Test modunda "imza doğrulaması"
merchant'a özgü hiçbir güven sağlamaz.

**Yapısal düzeltme:** `function_exists()` korumalarını **sert gereksinime** çevir (eklenti zaten
WP 5.8+ istiyor; yoksa `nicepay_init` hiç çalışmasın). Hız limitinde geçersiz `REMOTE_ADDR`
durumunda operatöre karar veren bir filtre + varsayılan **reddet**. `is_available()`'a mod kapısı:
test modu yalnızca `nicepay_allow_test_mode_checkout` filtresi açıkça `true` dönerse checkout'ta
görünsün.

---

### RC-7 — "Üç ayrı zaman dilimi sözleşmesi bir arada"

**Bulgular.** [DATA-006](04-data-layer.md) · [DATA-023](04-data-layer.md) ·
[ADMIN-001](09-admin-experience.md) · [PROTO-011](01-protocol-conformance.md) ·
[UX-027](08-ux-accessibility.md) · [I18N-011](11-i18n-l10n.md)

**Kök neden.** Aynı tabloda üç farklı zaman kaynağı var ve okuma tarafı hepsini UTC sanıyor.

| Alan | Yazan | Gerçek TZ | Okuyan | Varsaydığı TZ |
|---|---|---|---|---|
| `created_at` / `updated_at` | MySQL `DEFAULT CURRENT_TIMESTAMP` (`schema:112-113`) | **DB oturum TZ'si** | `get_date_from_gmt()` (`transactions:793`) | UTC |
| `approved_at`, `net_cancel_*_at` | PHP `gmdate()` | UTC | aynı ekran | UTC ✅ |
| `EdiDate` | `DateTimeImmutable` + `Asia/Seoul` (`api:74-77`) | KST | NICEPAY | KST ✅ |

Kore'deki bir mağazada (`time_zone = SYSTEM = Asia/Seoul`) admin listesindeki oluşturma zamanları
**9 saat kayıyor**; tarih filtresi ekranda görünen tarihle uyuşmuyor; CSV'de `created_at` (yerel)
ile `approved_at` (UTC) aynı satırda yan yana duruyor. Retention penceresi (`updated_at <
UTC_TIMESTAMP() - INTERVAL n DAY`, `retention:307`) da aynı ofset kadar kayıyor — `MIN_DAYS = 1`
ile bu ofset oransal olarak **devasa**.

Ayrıca her imza `EdiDate`'e bağlıyken sunucu saat kayması için hiçbir tespit/teşhis yok
(PROTO-011) — konteyner ortamlarında yaygın ve sessiz bir arıza.

**Yapısal düzeltme:** `created_at`/`updated_at`'i DB varsayılanından al, `prepare_write()` içinde
PHP `gmdate()` ile yaz (tek noktada, allowlist zaten orada). Tek UTC sözleşmesi + görüntülemede
`get_date_from_gmt()` + `date_i18n()`. Hazırlık paneline saat kayması kontrolü ekle.

---

### RC-8 — "Kod durum üretiyor; tasarım ve çeviri katmanı onu karşılamıyor"

**Bulgular.** [UX-001](08-ux-accessibility.md) · [UX-002](08-ux-accessibility.md) ·
[UX-003](08-ux-accessibility.md) · [UX-017](08-ux-accessibility.md) · [UX-023](08-ux-accessibility.md) ·
[UX-026](08-ux-accessibility.md) · [I18N-001](11-i18n-l10n.md) · [I18N-002](11-i18n-l10n.md) ·
[I18N-005](11-i18n-l10n.md) · [I18N-006](11-i18n-l10n.md) · [JS-006](07-frontend-js.md) ·
[JS-009](07-frontend-js.md)

**Kök neden.** PHP'nin üretebildiği durum kümesi ile CSS/çeviri katmanının kapsadığı küme
arasında **hiçbir bağ ve hiçbir kapı yok.**

**Ölçülen açık:**

| Kaynak | Üretilen | Karşılanan | Açık |
|---|---|---|---|
| Durum rozetleri | 11 (`transactions.php:277`) | 6 (`nicepay.css:528-544`) | **5** — `approving`, `partially_refunded`, `needs_reconciliation`, `abandoned`, `expired` |
| Kısakod hata dalları | 7 × `class="nicepay-error"` (`nicepay-payment-gateway.php:396-432`) | **0** CSS kuralı | **7** |
| `.nicepay-notice` taban sınıfı | `payment-form.php:18` (test modu bandı) | padding/radius var, **arka plan/çerçeve/renk yok** | görsel olarak düz metin |
| ko_KR çalışma zamanı çevirisi | 417 msgid | 417 − 218 boş − 83 fuzzy = **116** | **%72 İngilizce** |

Bunların en tehlikelisi ilk satırın en kritik üyesi: **`needs_reconciliation` — yani acil
müdahale gerektiren tek durum — görsel olarak en sönük satır olarak render ediliyor.** Ürünün
para güvenliği hikâyesinin merkezindeki sinyal, tasarım katmanında hiç yok.

`.nicepay-error` için `grep` doğrulaması: `assets/css/*.css` içinde tanımlı değil (çıkış kodu 1).
Ayrıca bu dallar yönetici teşhislerini ("NicePay credentials are not configured.") **herkese açık
ziyaretçiye** gösteriyor — ziyaretçi için anlamsız, saldırgan için yapılandırma ipucu.

**Yapısal düzeltme:** Durum listesini tek kaynaktan (`NicePay_Status::all()`) türet ve
`.github/scripts/check-css.js`'e bir kapı ekle: *"her durum için bir `.nicepay-status-{slug}`
kuralı olmalı, her kullanılan `.nicepay-*` sınıfının en az bir kuralı olmalı."* Bu tek CI kapısı
UX-001/002/003/026'yı kapatır ve tekrarını önler. Çeviri tarafında ko_KR/zh_CN için tr_TR ile
aynı fuzzy yasağını uygula, hedef pazar dili için bir tamamlanma eşiği koy.

---

## 2. Bileşik Riskler

Tek başına "orta" olan bulguların birleşerek kritik hale geldiği altı zincir. Her zincir kodla
izlenmiştir.

### CR-1 — **[EN TEHLİKELİ]** Yükseltme → sessiz indeks kaybı → çift onay koruması + iade yolu aynı anda ölür

```
DATA-001 (tid=NULL sessizce no-op)
   + MONEY-015 (uniq_tid hiç oluşmaz)
   + DATA-018 (dbDelta hata tespiti sağlam değil → şema "güncel" işaretlenir)
   + MONEY-002 (captured_amount '0.00' → iade reddi)
   + DATA-002 (backfill yok)
   ────────────────────────────────────────────────────────────
   ⇒ Tek bir eklenti güncellemesi mağazayı HEM çift-tahsilata açar
     HEM de tüm geçmiş siparişleri iade edilemez yapar; operatöre sinyal YOK.
```

**Zincirin mekaniği.** 1.x mağazası güncellenir. `scrub_legacy_sensitive_data`
(`installer.php:269`) `tid=''` satırlarını NULL'lamaya çalışır; WordPress strict SQL mode'u
kapattığı için (`wpdb::set_sql_mode` `STRICT_TRANS_TABLES`'ı kaldırır) `NOT NULL` sütuna NULL
yazımı **uyarıya dönüşür ve `''` yazılır**. Ardından dbDelta `uniq_tid`'i eklemeye çalışır ve
çoklu `''` yüzünden başarısız olur. `maybe_install()` bunu `$wpdb->last_error` üzerinden
yakalayabilir — ama DATA-018'e göre bu tespit sağlam değil; index oluşturma hatası
dbDelta'nın içinde yutulabilir ve sürüm option'ı yine de ilerleyebilir.

O noktadan sonra `uniq_tid` **yok**. Bu indeks, `gateway.php:461`'deki
`nicepay_update_transaction( ..., array('tid' => $tx_tid), true )` çağrısının **başka bir işlem
satırına ait bir TID'yi yazmasını engelleyen tek mekanizmadır**. Aynı anda MONEY-002 devreye girer:
dbDelta'nın eklediği `captured_amount decimal(14,2) NOT NULL DEFAULT 0` tüm eski satırlarda
`'0.00'` olur, `! empty('0.00')` **true** döner, `nicepay_normalize_amount('0.00')` `false` döner
ve yükseltme öncesi her ödenmiş sipariş kalıcı olarak iade edilemez hale gelir.

**Neden en tehlikeli:** Diğer zincirler bir kullanıcı eylemi veya bir ağ olayı gerektirir. Bu
zincir **rutin bir "Güncelle" tıklamasıyla**, sessizce, üretimde tetiklenir; ne admin uyarısı
ne test kapsıyor. Mağaza sahibi hem para güvenliğini hem müşteri hizmetini aynı anda kaybettiğini
haftalar sonra öğrenir.

**güven:** kod yolu **yüksek** (birebir okundu); MySQL'in NULL-yazımı davranışı **orta** —
tek kullanımlık bir MariaDB'de mutlaka doğrulanmalı.

---

### CR-2 — Test modu + görünmez uyarı + uyarı körlüğü → bedava ürün

```
SEC-013/SEC-001 (is_available()'da mod kapısı yok; herkese açık sandbox creds)
   + UX-002 (.nicepay-notice tabanının arka planı/çerçevesi yok → "Test mode" bandı düz metin)
   + ADMIN-003 (yapılandırma uyarıları HER wp-admin sayfasında, kapatılamaz → uyarı körlüğü)
   + MONEY-020 (başarılı test ödemesi sipariş yaşam döngüsünü canlıyla AYNI şekilde tamamlar)
   ────────────────────────────────────────────────────────────
   ⇒ Canlıya geçmeyi unutmuş mağazada gerçek siparişler para tahsil edilmeden
     payment_complete() edilir; dijital/indirilebilir ürünler otomatik teslim edilir.
```

Üç savunmanın üçü de aynı anda etkisiz: checkout'taki tek sinyal görsel olarak yok (UX-002),
admin bandı gürültüde kayboluyor (ADMIN-003) ve `is_available()` hiçbir mod kontrolü yapmıyor.
`NICEPAY_TEST_MERCHANT_KEY` herkese açık olduğu için imza doğrulaması da bu modda merchant'a özgü
hiçbir güven vermiyor — yani "imzalı akış" güvencesi tamamen kağıt üzerinde.

---

### CR-3 — PHP süreç ölümü → sipariş hem ödenemez, hem silinemez, hem sessiz

```
MONEY-003/016 + DATA-021 (active_attempt_key kurtarmada serbest bırakılmıyor)
   + ADMIN-002/MONEY-009 (mutabakat çözüm aracı yok)
   + DATA-014 (retention predicate bu satırları kalıcı olarak koruyor)
   + PROTO-005 (net_cancel_* alanları detayda ve CSV'de yok)
   + PLAT-001 (process_payment bildirimsiz 'failure' dönüyor)
   ────────────────────────────────────────────────────────────
   ⇒ Sıfır-çıkışlı kilitlenme: müşteri boş hata döngüsünde, operatörün tek çaresi ham SQL.
```

Approval sırasında worker restart / `max_execution_time` / OOM → satır `approving`'te kilitle
kalır. 30 dakika sonra cron onu `needs_reconciliation` yapar **ama kilidi bırakmaz**
(`nicepay-functions.php:968-976`). WooCommerce sipariş hâlâ `pending`, yani "ödenebilir" görünür;
`order_awaiting_payment` sayesinde checkout aynı siparişi tekrar kullanır; her deneme
`uniq_active_attempt` duplicate key'ine çarpar; `process_payment` (`gateway.php:111`) **hiçbir
notice basmadan** `array('result' => 'failure')` döner → müşteri boş bir checkout hatası görür.
Retention da bu satırı asla silemez (`retention.php:309,315` iki koşulu birden ihlal ediyor).

---

### CR-4 — Kesirli KRW toplamı → ilk tahsilat sapar, ikinci kısmi iade kalıcı bloke olur

```
MONEY-005/017 (WooCommerce ondalık ayarı zorlanmıyor; normalize_amount half-up yuvarlıyor)
   + MONEY-004 (get_total_refunded() ledger + bu istek ile TAM eşleşmeli)
   + MONEY-014 (iki iade-tavanı kaynağı hiç karşılaştırılmıyor)
   + MONEY-026 (üç farklı red tek ve yanıltıcı mesajla dönüyor)
   ────────────────────────────────────────────────────────────
   ⇒ Müşteri sipariş toplamından fazla ödenir; ikinci kısmi iade sonsuza kadar reddedilir;
     hata mesajı yuvarlamadan hiç bahsetmez.
```

`nicepay-functions.php:1513-1515` yarım-yukarı yuvarlıyor. Varsayılan ayarlı Kore mağazasında
vergi/kupon 40.000,50 KRW toplam üretir → müşteri **40.001** öder. İki adet 20.000,25 KRW kısmi
iade: ikincisinde `expected_order_refunded = 20000 + 20000 = 40000` ama
`$order->get_total_refunded()` normalize edilince **40001** → `hash_equals` başarısız →
`"WooCommerce refund records do not match this NicePay refund request."` — kalıcı. Operatör mesajı
okur, WooCommerce iade kayıtlarını kontrol eder, hepsi doğru görünür ve nedeni asla bulamaz.

---

### CR-5 — Auth imzası Moid'i bağlamıyor + net-cancel saldırgan alanlarıyla + tek deneme

```
SEC-012/SEC-004/MONEY-025 (auth imzası = AuthToken+MID+Amt; TxTid ve Moid BAĞLI DEĞİL)
   + SEC-011 (net-cancel POST'lanan TxTid/AuthToken/NetCancelURL ile yapılıyor)
   + PROTO-004 (tek net-cancel denemesi, retry yok, alternatif DC yok)
   + MONEY-019 (yerel doğrulamada reddedilen BAŞARILI auth ne ters çevriliyor ne deftere yazılıyor)
   ────────────────────────────────────────────────────────────
   ⇒ (a) Moid ikamesiyle kurban işlem kalıcı needs_reconciliation'a itilir (+MONEY-002 ile ölür)
      (b) Korelasyonlu ağ hatasında tek net-cancel denemesi de düşer → kart hold'u askıda kalır,
          MONEY-019 dalında ise ledger'da HİÇ İZ kalmaz.
```

**Kod kanıtı.** `verify_auth_signature` yalnızca `AuthToken + MID + Amt + Key` kapsıyor
(`class-nicepay-api.php:107-114`) — `TxTid` ve `Moid` imzanın dışında. `request_net_cancel`
(`api.php:471-500`) `TID`, `AuthToken`, `Amt` alanlarını **doğrudan POST'tan** alıyor;
`NetCancelURL` yalnızca host/path allowlist'inden geçiyor.

`handle_return`'ün ilk dalı (`gateway.php:426-433`): `validate_auth_return` bir `WP_Error`
döndüğünde **hiçbir net-cancel yapılmıyor ve hiçbir satır yazılmıyor** — yani NICEPAY tarafında
**başarılı** olmuş bir kimlik doğrulama, sunucuda hiçbir iz bırakmadan ve ters çevrilmeden
düşürülüyor. `nicepay_get_reconciliation_count()` (`nicepay-functions.php:1032-1043`) bu satırları
saymaz çünkü satır hâlâ `pending`. Operatörün paneli bu olayı **hiç göstermez**.

**güven notu.** (a) senaryosunun para etkisi, NICEPAY'in `AuthToken ↔ TID` eşleşmesini kendi
tarafında doğrulayıp doğrulamadığına bağlı — bu kodla doğrulanamaz. **güven: düşük.**
Ancak yapısal kusur (imzanın `TxTid`'i kapsamaması ve net-cancel'ın imzasız alanlarla kurulması)
koddan **kesin**. (b) senaryosu ve MONEY-019'un görünürlük boşluğu **yüksek güvenli**.

---

### CR-6 — Multisite ağ etkinleştirme → tüm alt sitelerin permalink'leri kırılıyor

```
PLAT-005 (switch_to_blog() içinde flush_rewrite_rules())
   + PLAT-006 (sınırsız get_sites() + döngü ortasında wp_die())
   + PLAT-019 (aktivasyonda textdomain yüklü değil → çevrilmemiş ölüm ekranı)
   + DATA-034 (wpmu_drop_tables kaydı yok)
   ────────────────────────────────────────────────────────────
   ⇒ Ağ genelinde 404'ler (sadece NicePay uçları değil, TÜM yazı/sayfa/ürün URL'leri)
     + yarım sağlanmış ağ + silinen alt sitelerde alıcı PII'si taşıyan yetim tablolar.
```

**Kod kanıtı.** `nicepay-payment-gateway.php:132-146` `get_sites(array('number' => 0))` ile
sınırsız döngü kuruyor; `activate_current_site()` (`:158-172`) `switch_to_blog()` bağlamının
**içinde** `flush_rewrite_rules()` çağırıyor. `flush_rewrite_rules()` global `$wp_rewrite`
nesnesini kullanır ve `switch_to_blog()` bu nesneyi yeniden başlatmaz — dolayısıyla **ana sitenin
permalink yapısına göre üretilmiş kurallar her alt sitenin `rewrite_rules` option'ına yazılır.**
Permalink yapısı farklı olan alt sitelerde sitenin tamamı 404'e düşer.

Döngü ortasında bir migrasyon başarısız olursa `wp_die( esc_html( $result->get_error_message() ) )`
çalışır (`:140`); mesaj çevrilmez (`load_plugin_textdomain` `plugins_loaded`'a bağlı, aktivasyon
isteğinde o hook çoktan geçmiştir) ve **hangi sitenin başarısız olduğunu söylemez.**

---

## 3. Sistemik Boşluklar

Hiçbir boyutun tek başına sahiplenmediği, ama üründe gerçek bir boşluk oluşturan alanlar.

| # | Boşluk | Kod kanıtı | Neden sahipsiz | Etki |
|---|---|---|---|---|
| SG-1 | **Gözlemlenebilirlik / logging** | `nicepay_log()` (`nicepay-functions.php:36-45`): WooCommerce yoksa `debug`/`info` **tamamen düşürülür**, yalnızca `error`/`warning` `error_log`'a gider. Korelasyon kimliği (Moid/TID) her satırda yok. `nicepay_log`'un kendisi test edilmiyor. | 06 kod kalitesi sayar, 10 test kapsamı sayar, 13 dokümantasyonu sayar — kimse "üretimde bir olayı izleyebilir miyiz?" sorusunu sormuyor | Standalone (WooCommerce'siz) kurulumda teşhis imkânsız. [DOC-008](13-documentation.md) tam tersini vaat ediyor. |
| SG-2 | **Denetim izi (kim / ne zaman / neden)** | `wp_ajax_nicepay_cancel_transaction` (`transactions.php:969+`) iadeyi başlatan kullanıcıyı **kaydetmiyor**; `nicepay_refund_attempts` tutarı ve gerekçeyi tutuyor ama **aktörü tutmuyor**. CSV indirme ve reddedilen yetkisiz denemeler hiç kaydedilmiyor. | 03 yetkilendirmeyi, 09 arayüzü, 04 şemayı sahipleniyor; "hesap verebilirlik" hiçbirinin kapsamında değil | Çok yöneticili mağazada *"bu iadeyi kim yaptı?"* sorusunun eklenti içinde cevabı yok |
| SG-3 | **Rollback / geri alma stratejisi** | `NicePay_Installer` yalnızca ileri gider. `scrub_legacy_sensitive_data` (`installer.php:277-286`) `payment_data` denetim yükünü **geri alınamaz şekilde** NULL'lar — üstelik dbDelta'dan **önce**, yani migrasyon sonra başarısız olsa bile veri gitmiştir. Yedek istenmiyor, dry-run yok. | 04 migrasyonu, 12 release'i sahipleniyor; "2.0.0 canlıda kötü giderse ne yaparız?" sorusu hiçbir boyutta yok | 1.x'e dönüş **imkânsız**: şema ileri taşınmış, denetim blob'ları silinmiş |
| SG-4 | **Destek / operasyon araçları** | Kod tabanında **hiç WP-CLI komutu yok**. Mutabakat çözümü yok, "approval'ı yeniden dene" yok, "kilidi bırak" yok. Her sıkışmış durumun dokümante edilmiş çaresi **ham SQL**. | 09 arayüzü sahipleniyor ama arayüz dışı araçlar kimsenin değil | Bir ödeme eklentisinde ham SQL, veri bütünlüğü açısından en riskli müdahale biçimi |
| SG-5 | **1.x → 2.0 yükseltme yolu (uçtan uca)** | RC-1'in tamamı | 04 şemayı, 02 iadeyi, 13 rehberi, 10 fixture'ı sahipleniyor — **uçtan uca akışı kimse sahiplenmiyor** | PR'ın en riskli iddiası ("mevcut kurulumlar veri kaybetmeden yükselir") hiçbir testle desteklenmiyor |
| SG-6 | **Çok-siteli kurulum** | CR-6'nın tamamı; ayrıca `wpmu_drop_tables` kaydı yok (`grep` çıkış kodu 1) | 05 platformu sayıyor ama multisite testi **hiç yok**; CI matrisinde multisite ekseni yok | Ağ genelinde 404 riski + silinen alt sitelerde PII taşıyan yetim tablolar |
| SG-7 | **Kapasite / performans** | Her ön yüz isteğinde 2× `SHOW TABLES` (`installer.php:125`, önbellek yok); admin sayfasında indekssiz `COUNT(*)` + sayfa başına 20× `wc_get_order()`; retention **günde 500 satır** tavanı (`BATCH_SIZE 100 × MAX_BATCHES 5`) | 04 indeksleri, 09 arayüzü, 06 mimariyi sayıyor; "bu defter ne kadar büyüyebilir?" sorusu yok | Tablo **başarısız ve terk edilmiş** denemelerle de büyüyor; [SEC-002/017](03-security.md) uyarınca kimliksiz bir uç nokta bunu **düşmanca** besleyebilir (~29k satır/gün/IP). Retention birikimi asla kapatamaz. |
| SG-8 | **Sürüm / kanal tutarlılığı** | `tests/integration/run-*.sh:80,84` içinde **sabit `2.0.0`**; `check-version.js` `readme.txt` `Stable tag`'i kapsamıyor; `readme.txt`'de `Contributors:` **yok** (`grep` çıkış kodu 1) | 12 CI'ı, 13 dokümanı sayıyor; "yarın 2.0.1 çıkarabilir miyiz?" sorusunu kimse sormuyor | Sürüm artışının **ilk commit'i** CI'ı deterministik olarak kırar; WP.org publish işi SVN'e yazmadan hemen önce durur |

**SG-7 için özel not.** Kapasite boşluğu diğerlerinden farklı: tablo büyümesi yalnızca iş hacmine
değil, **saldırgana** da bağlı. `nicepay_init_payment` kimliksiz erişilebilir, hız limiti
fail-open (`nicepay-functions.php:294-298`) ve proxy arkasında tek küresel kova. Varsayılan
retention `indefinite` olduğu için satırlar kalıcı. Bu üçü birleştiğinde SG-7 bir performans
konusu olmaktan çıkıp bir **kaynak tüketimi zafiyeti** haline geliyor.

---

## 4. Boyutlar Arası Çelişkiler

Kodla çözülen dokuz çelişki. "Hüküm" sütunu, koda bakılarak verilen karardır.

| # | İddia A | İddia B | Kod kanıtı | Hüküm |
|---|---|---|---|---|
| X-1 | [PROTO-002](01-protocol-conformance.md)/[MONEY-006](02-payment-money-correctness.md): "auth-return `Amt` katı normalize ediliyor, dolgulu yanıt tüm ödemeleri kırar" | 01'in güçlü yönü: "12-byte sıfır-dolgulu yanıt hem imzada hem binding'de doğru ele alınıyor ve test ediliyor" | `validator:98` → `nicepay_normalize_amount` (dolgu **reddeder**); `validator:162` → `nicepay_normalize_response_amount` (dolgu **kabul eder**) | **Bulgu haklı.** İkisi de doğru ama **farklı alanlar** hakkında; güçlü yön yalnızca *approval* yanıtı için geçerli, çok geniş yazılmış. Asimetri gerçek. |
| X-2 | [SEC-001](03-security.md) `medium↓` | [SEC-013](03-security.md) `high` | `is_available()` (`gateway.php:76-102`) — mod kontrolü **yok**; `NICEPAY_TEST_*` (`nicepay-payment-gateway.php:33-34`) herkese açık sandbox | **Aynı bulgunun iki kaydı.** Para sonucu (bedava ürün) iki yazımda da aynı → **`high` doğru şiddet**. Dedupe edilmeli. |
| X-3 | [PLAT-003](05-wp-wc-platform.md): "Blocks uyumluluğu **beyan edilmeli**" | [META-007](14-analysis-docs-integrity.md): "Blocks yüzeyi **sertifikasyonsuz ve canlı**, kapatılmalı" | `init_woocommerce_blocks()` ödeme yöntemini **koşulsuz** kaydediyor (`:317-318`); `declare_compatibility` yalnız `custom_order_tables` (`:41-50`) | **İkisi de olgusal olarak doğru, çareler zıt.** Bu bir **ürün kararı**, bug değil: ya beyan et ve test et, ya kaydı kapıla. **Bugün sevk edilen ise tek yanlış seçenek: ikisi de değil.** |
| X-4 | [ADMIN-004](09-admin-experience.md): "CSV 10.000'de sessizce kesiliyor, **hiçbir kesilme işareti yok**" | — | `transactions.php:694-699` export butonunun yanında *"Exports up to 10,000 matching rows."* yazıyor; `:263` `X-NicePay-Export-Row-Limit` header'ı gönderiyor | **KISMEN ÇÜRÜTÜLDÜ.** Sınır arayüzde **açıkça** bildiriliyor. Ayakta kalan kısım: **dosyanın kendisinde** kesilme işareti ve devam kursoru yok. Şiddet `medium → low`, ifade yeniden yazılmalı. |
| X-5 | [CI-002](12-build-ci-release.md): "`Update URI` çözümsüz kilit; dokümante edilen WP.org yolu **izlenemez**" | — | `check-version.js:66-79` `NICEPAY_DISTRIBUTION_CHANNEL=wordpress-org-candidate` kaçışı **var**; `deploy-wordpress-org.yml:72,142` bu env'i hem check-version hem `build-release.sh` çağrısı için **set ediyor** | **KISMEN ÇÜRÜTÜLDÜ.** WP.org iş akışı **kendi içinde tutarlı**. Kilit yalnızca **aynı commit'teki diğer boru hatları** için gerçek: `composer quality` → check-version, `tests.yml` package → `build-release.sh:23`, `release.yml:39` — üçü de `github` varsayılanına düşüyor. Bulgu bu kapsama daraltılmalı. |
| X-6 | [MONEY-001](02-payment-money-correctness.md)/[MONEY-018](02-payment-money-correctness.md): "`flow=''` toleransı **ulaşılamaz kod**" | `gateway.php:704`'teki `in_array($flow, array('', 'woocommerce'), true)` toleransı **canlı görünüyor** | `nicepay_get_transaction_by_tid($tid, $order_id)` (`nicepay-functions.php:447-455`) `AND flow = 'woocommerce'` **sabitliyor** → `flow=''` satırı `:704`'e hiç ulaşmaz | **Bulgu DOĞRULANDI.** Tolerans gerçekten ölü. Bu, yalnızca çağrı zincirini uçtan uca izleyerek görülebilir — nokta okumayla değil. |
| X-7 | [DATA-014](04-data-layer.md)/[MONEY-008](02-payment-money-correctness.md): "retention **iade edilebilir** `paid` satırları siliyor" | 04'ün güçlü yönü: "çözülmemiş para durumları için **koruyucu predicate** var" | `eligible_row_predicate` (`retention.php:307-315`): `pending`/`approving`/`needs_reconciliation`/`unknown` **korunuyor** ✅, ama `status IN ('paid', ...)` tam iade edilebilir satırları **açıkça kapsıyor** | **İkisi de doğru, farklı eksenler.** Predicate **çözülmemiş durumları** korur, **iade edilebilir bakiyeyi** değil. Bulgu ayakta. |
| X-8 | [TEST-005](10-testing.md) `high` | [CI-016](12-build-ci-release.md) `low` | Aynı `phpunit.xml` coverage kapsamı (`admin/`, kök dosya, `templates/` hariç; eşik yok) | **Çelişki değil, mükerrer kayıt.** İki boyut aynı satırı iki farklı şiddetle raporlamış. Dedupe + tek şiddet gerekli. |
| X-9 | [ADMIN-002](09-admin-experience.md) `high` · [DATA-031](04-data-layer.md) `medium` · [MONEY-009](02-payment-money-correctness.md) `medium` | — | Üçü de RC-2'nin aynı kök nedeni | **Üç kayıt, tek bulgu.** Şiddet, en yüksek olanda (`high`) birleştirilmeli; aksi halde önceliklendirme kök nedeni parçalayarak küçültüyor. |

### Kodla çelişen dokümantasyon (ayrı bir kategori)

| Doküman iddiası | Kod gerçeği | Bulgu |
|---|---|---|
| `docs/DEVELOPER-GUIDE.md:416` — approval/imza kayıtları `debug.log`'a yazılır | `nicepay_log()` WooCommerce yoksa `debug`/`info`'yu **tamamen düşürür** (`nicepay-functions.php:36-45`) | [DOC-008](13-documentation.md) ✅ |
| `README.md:106` vd. — shortcode `buyer_*` parametreleri ön-doldurma yapar | Kayıtlı konfigürasyon değerleri koşulsuz eziyor | [DOC-003](13-documentation.md) ✅ |
| `docs/CONFIGURATION.md:160-180` — anahtarı `wp-config.php`'de tut, `option_*` filtresiyle enjekte et | `sanitize_merchant_key()` (`admin/class-nicepay-admin.php:184-196`) boş girdide `get_option($option_name, $default)` döndürür → **filtrelenmiş wp-config değeri** Settings API tarafından DB'ye kalıcılaştırılır | [SEC-014](03-security.md) ✅ **yüksek güven** — dokümanın kendi reçetesi sırrı DB'ye yazdırıyor |
| `admin/class-nicepay-admin.php:460` — hazırlık paneli "HPOS beyanı öncesi ayrı matris gerekir" diyor | HPOS uyumu **beyan edilmiş** (`nicepay-payment-gateway.php:41-50`) ve CI'da legacy/HPOS matrisi koşuyor | [PLAT-013](05-wp-wc-platform.md) ✅ |

---

## 5. Öncelik Sırası (kök neden bazlı)

Bulgu sayısına değil, **kapatılan zincir sayısına** göre sıralanmıştır.

| Sıra | Müdahale | Kapattığı kümeler/zincirler | Tahmini yüzey |
|---|---|---|---|
| 1 | Gerçek v1 fixture'ı + backfill migrasyonu + dbDelta sonrası indeks doğrulaması | RC-1, CR-1, SG-5 | `run-schema-migration.sh`, `class-nicepay-installer.php` |
| 2 | Cron'da `active_attempt_key = NULL` + mutabakat çözüm aksiyonu + `net_cancel_*` alanlarını UI/CSV'ye aç | RC-2, CR-3, SG-2, SG-4 | `nicepay-functions.php:968`, `class-nicepay-transactions.php` |
| 3 | `is_available()`'a mod kapısı + `.nicepay-notice` görsel taban + CSS durum kapısı (CI) | RC-6, RC-8, CR-2 | `class-nicepay-gateway.php:76`, `nicepay.css`, `check-css.js` |
| 4 | Tek yanıt normalizeri + `EdiType=JSON` + satıcı fixture seti | RC-3, CR-5(b) | `class-nicepay-inbound-validator.php`, `class-nicepay-api.php` |
| 5 | `NicePay_Payment_Completion` servisi (iki dönüş işleyicisini birleştir) | RC-4, [TEST-001](10-testing.md) | `class-nicepay-gateway.php`, `class-nicepay-return-handler.php` |
| 6 | KRW ondalık zorlaması + tek iade-tavanı kaynağı + ayrıştırılmış hata mesajları | CR-4 | `class-nicepay-gateway.php:694+` |
| 7 | `flush_rewrite_rules()`'u `switch_to_blog()` dışına al + `wpmu_drop_tables` | CR-6, SG-6 | `nicepay-payment-gateway.php:132-172` |
| 8 | Zaman damgalarını PHP `gmdate()` ile yaz (tek UTC sözleşmesi) | RC-7 | `class-nicepay-transaction-schema.php:112`, `prepare_write()` |

---

## 6. Doğrulama Notları ve Güven Seviyeleri

| İddia | Güven | Gerekçe |
|---|---|---|
| `flow=''` toleransının ölü olması | **yüksek** | Çağrı zinciri uçtan uca okundu (`gateway:702` → `functions:447-455`) |
| `captured_amount = '0.00'` → iade reddi | **yüksek** | `normalize_amount` izi elle sürüldü: `ltrim('0','0') === ''` → `false` |
| `active_attempt_key`'in kurtarmada bırakılmaması | **yüksek** | `functions:968-976` ve `retention:307-315` birlikte okundu |
| `SEC-014` — wp-config reçetesinin sırrı DB'ye yazması | **yüksek** | `sanitize_merchant_key:190-193` + `CONFIGURATION.md:160-180` birlikte doğrulandı |
| `.nicepay-error` CSS'inin olmaması | **yüksek** | `grep` çıkış kodu 1, tüm CSS dosyalarında |
| `do_action()` yokluğu | **yüksek** | Tüm PHP dosyalarında `grep` — 0 eşleşme |
| `tid = NULL` UPDATE'inin sessizce `''` yazması | **orta** | Kod yolu kesin; MySQL davranışı WP'nin strict-mode kaldırmasına bağlı, canlı DB'de doğrulanmadı |
| `DATA-005/019` — 767 bayt indeks limiti | **düşük** | Hosting'in InnoDB `ROW_FORMAT` ve sürümüne bağlı; bu geçişte doğrulanmadı |
| `CR-5(a)` — Moid ikamesinin para etkisi | **düşük** | NICEPAY'in `AuthToken ↔ TID` eşleşmesini doğrulayıp doğrulamadığına bağlı; **yapısal kusur** (imzanın `TxTid`'i kapsamaması) ise kesin |
| `PROTO-002` — auth-return'ün gerçekten dolgulu geldiği | **düşük** | Sandbox'tan tek bir gerçek auth-return yakalanmadan kapanmaz; **asimetri** ise kesin |

**Bu geçişte kısmen çürütülen bulgular:** [ADMIN-004](09-admin-experience.md) (sınır arayüzde
bildiriliyor), [CI-002](12-build-ci-release.md) (WP.org iş akışı kendi içinde tutarlı).
**Mükerrer olarak işaretlenenler:** [SEC-001](03-security.md)/[SEC-013](03-security.md),
[TEST-005](10-testing.md)/[CI-016](12-build-ci-release.md),
[ADMIN-002](09-admin-experience.md)/[DATA-031](04-data-layer.md)/[MONEY-009](02-payment-money-correctness.md),
[MONEY-001](02-payment-money-correctness.md)/[MONEY-018](02-payment-money-correctness.md),
[DATA-002](04-data-layer.md)/[DATA-020](04-data-layer.md),
[MONEY-003](02-payment-money-correctness.md)/[MONEY-016](02-payment-money-correctness.md)/[DATA-021](04-data-layer.md).
Gerçek bağımsız bulgu sayısı, 296'nın belirgin biçimde altındadır.
