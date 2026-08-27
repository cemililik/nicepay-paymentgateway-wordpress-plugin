# 04 — Veri Katmanı: Şema, Migrasyon, Saklama, Gizlilik

> PR #3 sistematik review · düşmanca doğrulamalı · 37 bulgu

## Özet

**[Geçiş 1]** Veri katmanının tasarım niyeti güçlü: yazma allowlist'i (prepare_write), para için %s formatı (float dönüşümü yok), eşzamanlılığı UNIQUE index + compare-and-set UPDATE ile çözen atomik claim'ler, gerçekten kapalı varsayılanlı opt-in retention, doğru filtrelere bağlanmış GDPR exporter/eraser ve gerçek MariaDB üzerinde EXPLAIN doğrulayan bir entegrasyon testi. Ancak bu PR'ın en kritik vaadi olan v1'den v2'ye güvenli migrasyon pratikte kanıtlanmamış ve iki noktada kırık: (a) tid sütunu NOT NULL DEFAULT '' iken NULL'a çevrilmeye çalışılıyor, dbDelta ise nullability değiştirmez; sonuçta uniq_tid ya migrasyonu ya da yükseltme sonrası ikinci ödeme kaydını boş-string çakışmasıyla kilitler; (b) eski satırlara currency/mid/mode/captured_amount backfill'i hiç yapılmıyor, bu yüzden yükseltme sonrası mevcut tüm NicePay siparişleri kalıcı olarak iade edilemez hale geliyor (v1'de edilebiliyorlardı). Entegrasyon testi taze v2 tablosundan başladığı, unit testler ise her sorguya basarili diyen sahte wpdb kullandığı için bu iki hata testlerde görünmüyor. Ayrıca migrasyon her istekte yeniden deneniyor, payment_data denetim kanıtı geri alınamaz şekilde tümüyle siliniyor, created_at/updated_at DB oturum saat diliminde yazılıp UTC gibi okunuyor ve InnoDB COMPACT satır formatında tablo hiç oluşamayacak kadar geniş.

**[Geçiş 2]** Veri katmanı düşünülmüş bir tasarıma sahip: tek kaynaklı şema tanımı, UNIQUE kısıtlarla kurulan eşzamanlılık modeli, tutarların DECIMAL + %s ile float'sız yazılması, opt-in retention ve WP privacy kancaları. Ancak migrasyon güvenliği iddia edildiği kadar sağlam değil: dbDelta sonrası yalnızca $wpdb->last_error (yani SON sorgunun hatası) ve tablo varlığı kontrol ediliyor; sütun/indeks doğrulaması yok. Bu, tüm çift-ödeme koruma modelinin dayandığı UNIQUE indekslerin sessizce oluşmamasına ve şemanın yine de "güncel" işaretlenmesine izin veriyor — üstelik idx_source_ref (flow, source_ref) utf8mb4'te 844 bayt ile eski InnoDB'nin 767 baytlık ön ek limitini aşarak bu senaryoyu somut kılıyor. v1'den v2'ye hiçbir backfill yapılmadığı için eski işlemler flow='' kalıyor ve iade sorgusunun flow='woocommerce' filtresine takılıp bulunamıyor; yükseltme yapan her mağazada geçmiş siparişler iade edilemez hale geliyor. Cron'un "stale approving" kurtarması active_attempt_key'i temizlemediği için UNIQUE kilit siparişi kalıcı olarak ödenemez bırakabiliyor. Retention tarafında saat dilimi (DB yerel CURRENT_TIMESTAMP vs UTC_TIMESTAMP()), atomik olmayan add_option kilidi, sessizce yutulan get_col hatası ve 500 satır/gün tavanı politikanın vaat edileni yapmamasına yol açıyor. Gizlilik entegrasyonu doğru kayıtlı ve sayfalaması sağlam, ama kapsamı yalnızca ledger tablosuyla sınırlı.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **C** |
| 🔴 Kritik | 2 |
| 🟠 Yüksek | 4 |
| 🟡 Orta | 22 |
| 🔵 Düşük | 9 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 2 |
| Bağımsız doğrulama kararı alan bulgu | 8 / 37 |
| Tarama geçişi | 2 |
| Referans verilen dosya | 8 |

## Güçlü yönler

- Şema tanımı tek kaynakta ve saf: NicePay_Transaction_Schema WordPress yan etkisi olmadan sütun/index/format üretiyor; tablo adı interpolasyondan önce regex ile doğrulanıyor (class-nicepay-transaction-schema.php:188, 214).
- dbDelta'nın katı biçim kuralları doğru uygulanmış: PRIMARY KEY iki boşluklu (satır 124, 168), INDEX yerine KEY, backtick yok, her sütun kendi satırında, IF NOT EXISTS yok ve wpdb->get_charset_collate() kullanılıyor (installer:180-181).
- Para alanları decimal + wpdb formatı %s ile yazılıyor; format_for() yalnız id/wc_order_id/approval_attempts için %d dönüyor, yani hiçbir tutar float'a çevrilmiyor (schema:230-247).
- prepare_write / prepare_refund_write yazma allowlist'i: bilinmeyen sütunlar ve DB'nin sahip olduğu id/created_at/updated_at alanları çağırandan kabul edilmiyor (schema:257-295).
- Eşzamanlılık DB kısıtlarıyla çözülmüş: uniq_moid, uniq_tid, uniq_active_attempt ve tek-ifadeli compare-and-set UPDATE'ler (nicepay-functions.php:653-709, 719-755); entegrasyon testi bunları gerçek MariaDB'de doğruluyor (tests/integration/schema-migration.php:78-152).
- Installer DB hatasında sürüm numarasını ilerletmiyor ve dbDelta sonrası fiziksel tablo varlığını tekrar doğruluyor; sürüm option'ı güncel görünse bile eksik tabloyu yeniden yaratıyor (installer:185-198).
- Retention gerçekten opt-in: varsayılan indefinite, geçersiz/onaysız girdi son geçerli politikayı koruyup settings-error basıyor, add_option tabanlı kilit + 100'lük parti + çözülmemiş para durumları için koruyucu predicate var (class-nicepay-retention.php:29-55, 66-105, 279-316).
- Retention admin metni birinci sınıf: kalıcı silme uyarısı, kapsam dışı kalanlar (Woo siparişleri, yedekler, NICEPAY kayıtları), iade kaybı uyarısı, uygun kayıt sayısı, sıradaki ve son çalışma bilgisi (admin/class-nicepay-admin.php:545-599).
- Gizlilik entegrasyonu doğru filtrelere bağlı, export alanları allowlist, geçersiz e-posta hiç sorgu üretmiyor ve eraser finansal referansları koruyup kullanıcıya nedenini bildiriyor (class-nicepay-privacy.php:18-33, 44-56, 152-154).
- CSV export keyset (created_at, id) imleciyle sayfalanıyor, OFFSET kaymasi yok ve 10.000 satırla sınırlı (admin/class-nicepay-transactions.php:157-176).
- Şema tanımı tek kaynakta (NicePay_Transaction_Schema) toplanmış; tablo adı preg_match('/^[A-Za-z0-9_]+$/') ile doğrulanıyor ve create_table_sql() enjeksiyona kapalı (schema:188, 214).
- Tutarlar decimal(14,2) + wpdb formatı %s ile yazılıyor; format_for() yalnızca id/wc_order_id/approval_attempts için %d döndürerek float dönüşümünü tamamen engelliyor (schema:229-247).
- Tutar üst sınırı (nicepay_normalize_amount max '999999999999') ile decimal(14,2)'nin 12 haneli tamsayı kapasitesi birebir örtüşüyor — sessiz taşma/kırpma riski yok (functions:1522-1526).
- active_attempt_key ve source_ref için varchar(191) seçimi, utf8mb4 tekil indeks bayt limitine (191*4=764 < 767) bilinçli olarak uydurulmuş (schema:50-51).
- prepare_write()/prepare_refund_write() ile yazma allowlist'i uygulanıyor; id/created_at/updated_at DB'ye ait alanlar çağıranlar tarafından ezilemiyor (schema:257-295).
- Migrasyon, duplicate Moid/TID varken UNIQUE indeks eklemeye kalkışmıyor; veriyi silmek yerine WP_Error ile duruyor (installer:138-161).
- Legacy PAN/token/ham yanıt materyali yükseltme sırasında temizleniyor ve yeni kurulumda card_no sütunu hiç yaratılmıyor; birim test bunu ayrıca doğruluyor (installer:262-300, NicePayTransactionSchemaTest:55).
- Retention gerçekten opt-in: varsayılan 'indefinite', geçersiz/onaysız girdi son geçerli politikayı koruyor ve cron yalnızca custom modda kuruluyor (retention:29-55, 66-124).
- purge_batch tek bir DB transaction içinde SELECT ... FOR UPDATE + iki DELETE yapıyor ve satır sayısı uyuşmazsa ROLLBACK ediyor; çözümlenmemiş para durumları (approving/needs_reconciliation/unknown cancel/auth_token dolu) predicate ile korunuyor (retention:193-252, 305-316).
- Privacy exporter/eraser doğru filtrelere kayıtlı, prepare ile bağlanmış, sayfalama mantığı (count < limit) doğru ve sonsuz döngü üretmiyor; finansal referanslar korunup yalnızca iletişim verisi anonimleştiriliyor, items_retained mesajı veriliyor (privacy:18-33, 44-163).
- Gerçek MySQL üzerinde çalışan bir şema entegrasyon testi var; unique aktif-deneme kilidi, atomik approval claim ve retention koruma senaryoları gerçek veritabanında doğrulanıyor (tests/integration/schema-migration.php:78-152, 165-276).
- CSV export keyset (created_at, id) imleci kullanıyor; OFFSET yerine cursor + LIMIT ile sınırlı, akış halinde ve satır tavanlı (admin/class-nicepay-transactions.php:128-218).

## Bulgular

### DATA-001 — v1 to v2 yükseltmesinde tid sütunu NULL yapılamıyor; uniq_tid boş-string çakışmasıyla ya migrasyonu ya da sonraki ödemeleri kilitliyor

| | |
|---|---|
| **Severity** | 🔴 Kritik |
| **Kategori** | migration-safety |
| **Konum** | [includes/class-nicepay-installer.php:269](../../../includes/class-nicepay-installer.php#L269) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
Eski şema (git show origin/main:nicepay-payment-gateway.php:112): tid varchar(50) NOT NULL DEFAULT ''. Yeni şema tid'i nullable yapıp UNIQUE'e alıyor: schema:45 'tid' => 'varchar(50) DEFAULT NULL' ve schema:127 UNIQUE KEY uniq_tid (tid). Installer bunu iki adımla emniyete almaya çalışıyor: (1) duplicate_tid_count_sql boş TID'leri bilerek hariç tutuyor (installer:98 WHERE tid IS NOT NULL AND tid <> ''), (2) scrub adımı UPDATE {table} SET tid = NULL WHERE tid = '' çalıştırıyor (installer:269). Ancak bu UPDATE, sütun HALA NOT NULL iken çalışıyor ve WordPress oturumda STRICT_TRANS_TABLES'ı kaldırdığı için (wpdb::set_sql_mode) MySQL NULL'u sessizce implicit default'a yani tekrar boş stringe çeviriyor, sadece warning üretiyor; wpdb->query etkilenen satır sayısı döndüğü için hata da yakalanmıyor. Ardından çalışan dbDelta sütunu nullable YAPAMAZ: WP core dbDelta yalnızca tablefield->Type ile sorgudaki tipi ve DEFAULT 'deger' regex'ini karşılaştırır, NULL/NOT NULL karşılaştırması içermez. Yani sütun kalıcı olarak NOT NULL DEFAULT '' kalır.
```

**Başarısızlık senaryosu**

Senaryo A (tabloda 2+ boş TID'li satır var, ki her terk edilmiş/başarısız ödeme böyle): scrub NULL'a çeviremez, dbDelta ADD UNIQUE KEY uniq_tid (tid) çalıştırır, MySQL 1062 Duplicate entry '' for key 'uniq_tid' döner, wpdb->last_error dolar, maybe_install nicepay_schema_db_error WP_Error'u döndürür, sürüm option'ı asla ilerlemez. Sonuç: her istekte migrasyon tekrar denenir, admin sadece genel bir uyarı görür ve NicePay ödeme yöntemi kalıcı olarak devre dışı kalır. Senaryo B (0 veya 1 boş TID'li satır): uniq_tid başarıyla eklenir, sütun NOT NULL kalır. nicepay_save_transaction varsayılan tid => null yazar (nicepay-functions.php:344); MySQL tek satırlık INSERT'te ya ER_BAD_NULL_ERROR verir (ilk ödemeden itibaren her ödeme formu üretimi başarısız) ya da boş stringe çevirir; ikinci pending işlem 1062 ile reddedilir. Yani yükseltmeden sonra en geç ikinci müşteri ödeme yapamaz.

**Etki**

Mevcut (v1) kurulumlarda ödeme defteri yükseltme anında ya da ilk iki ödeme denemesinde bozuluyor. Gateway is_available() NicePay_Installer::is_current() gerektirdiği için migrasyon bloklanırsa NicePay checkout'ta tamamen kayboluyor; migrasyon geçerse bu kez yeni kayıt yazılamıyor. Her iki durumda da mağaza para tahsil edemiyor.

**Öneri**

Nullability değişimini dbDelta'ya bırakmayın; scrub'dan ÖNCE açık bir ALTER çalıştırın ve sonucu doğrulayın. Örnek: if (self::column_exists($wpdb,$table,'tid')) { $wpdb->query("ALTER TABLE {$table} MODIFY tid varchar(50) NULL DEFAULT NULL"); if (!empty($wpdb->last_error)) return new WP_Error('nicepay_schema_tid_nullable_failed', ...); $wpdb->query("UPDATE {$table} SET tid = NULL WHERE tid = ''"); } Ardından dbDelta'dan önce SELECT COUNT(*) FROM {table} WHERE tid = '' sonucunun 0 olduğunu assert edin, aksi halde uniq_tid eklenmeden fail-closed dönün. tests/integration/schema-migration.php'ye origin/main'deki gerçek v1 CREATE TABLE ifadesinden başlayan bir senaryo ekleyin (boş TID'li 3 satır dahil) ve yükseltme sonrası iki ardışık nicepay_save_transaction çağrısının başarılı olduğunu doğrulayın.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: v1 -> v2 yükseltmesinde `tid` sütunu NOT NULL DEFAULT '' olarak KALIR ve yükseltilen mağazada hiçbir yeni ödeme kaydı oluşturulamaz.

Kök neden (iddia edildiği gibi): installer:269'daki `UPDATE {table} SET tid = NULL WHERE tid = ''`, sütun hâlâ NOT NULL iken çalışır; WordPress oturumdan STRICT_TRANS_TABLES'ı kaldırdığı için MySQL değeri sessizce implicit default'a ('') geri çevirir, sadece warning üretir, `$wpdb->query()` false dönmez ve installer:290'daki kontrol tetiklenmez. Ardından çalışan dbDelta sütun tipini yalnızca `$tablefield->Type` ile karşılaştırdığından ("varchar(50)" == "varchar(50)") NULL/NOT NULL farkını hiç görmez ve sütun kalıcı olarak NOT NULL kalır.

Gerçek arıza mekanizması (orijinal iddiadan farklı): dbDelta'nın `ADD UNIQUE KEY uniq_tid (tid)` sorgusu 2+ boş TID varsa 1062 ile başarısız olur, ANCAK bu hata YUTULUR — dbDelta kalan indeks sorgularını çalıştırmaya devam eder ve `wpdb::query()` her çağrıda `flush()` ile `last_error`'ı sıfırlar; installer:183'teki ikinci dbDelta (refund CREATE TABLE) de başarıyla çalışır. Bu yüzden installer:185'teki `! empty( $wpdb->last_error )` kontrolü hatayı GÖREMEZ; `maybe_install()` 'installed' döner ve installer:200 sürüm option'ını ilerletir. Yani migrasyon bloklanmaz — "başarılı" raporlanır, uniq_tid sessizce eksik kalır.

Asıl para kaybı, tid'in NOT NULL kalmasından gelir ve İLK ödemede başlar: `nicepay_save_transaction()` (functions:344) tid'i null bırakır, `prepare_write()` tid'i yazılacak alanlar arasında tutar, wpdb null değerleri için literal `NULL` üretir ve tek satırlık INSERT NOT NULL sütuna NULL yazdığı için strict moddan bağımsız olarak ER_BAD_NULL_ERROR (1048) alır. `$wpdb->insert()` false döner, gateway.php:296-300 "Payment initialization failed" göstererek her siparişte ödemeyi durdurur. (İddiadaki "en geç ikinci müşteri" ifadesi yanlış — ilk müşteriden itibaren bozulur.)

Ek olarak strict mode'u `incompatible_sql_modes` filtresiyle geri açan sitelerde installer:269 gerçekten hata verir; o durumda scrub `nicepay_schema_sensitive_data_cleanup_failed` döndürür, is_current() false kalır ve NicePay checkout'tan tamamen kaybolur (gateway.php:81).

Öneri geçerliliğini korur, iki ek maddeyle: (a) scrub'dan ÖNCE açık `ALTER TABLE {table} MODIFY tid varchar(50) NULL DEFAULT NULL` çalıştırıp sonucu doğrulayın; (b) installer:185'teki `last_error` kontrolü dbDelta hatalarını yakalamak için güvenilir değildir — dbDelta sonrası beklenen indekslerin varlığını `SHOW INDEX FROM {table}` ile açıkça assert edip eksikse fail-closed dönün.
- Gerekçe: ÇEKİRDEK İDDİA DOĞRU, MEKANİZMA AÇIKLAMASININ BİR KISMI YANLIŞ.

Doğrulanan kısımlar (hepsi kod okunarak):
1. Satır referansları birebir doğru: installer:269 `UPDATE {$table} SET tid = NULL WHERE tid = ''`, schema:45 `'tid' => 'varchar(50) DEFAULT NULL'`, schema:127 `UNIQUE KEY uniq_tid (tid)`, installer:93-99 duplicate_tid_count_sql boş TID'leri `tid <> ''` ile hariç tutuyor, nicepay-functions.php:344 `'tid' => null` varsayılanı.
2. Eski (v1) şema gerçekten `tid varchar(50) NOT NULL DEFAULT ''` (git show origin/main:nicepay-payment-gateway.php, create_tables()).
3. Kaçış yolu (guard) YOK: repoda hiçbir yerde `ALTER TABLE ... MODIFY/CHANGE tid` yok. `grep -rn "ALTER TABLE\|MODIFY " includes admin tests` sadece testteki iki DDL'i buluyor; migrasyonun tek şema aracı installer:182-183'teki dbDelta.
4. dbDelta nullability'yi DEĞİŞTİRMEZ. WP core `dbDelta()` sütun tipini `preg_match('|`?FIELD`? ([^ ]*( unsigned)?)|i')` ile çıkarır; burada `$fieldtype = "varchar(50)"` ve mevcut `$tablefield->Type = "varchar(50)"` eşit olduğu için CHANGE COLUMN üretilmez. NULL/NOT NULL hiçbir yerde karşılaştırılmaz. Yani sütun kalıcı olarak NOT NULL kalır — iddianın kök nedeni doğru.
5. wpdb, oturumdan STRICT_TRANS_TABLES/STRICT_ALL_TABLES'ı kaldırır (`wpdb::$incompatible_modes` + `set_sql_mode()`), dolayısıyla installer:269'daki UPDATE non-strict modda çalışır: NOT NULL sütuna NULL ataması hata değil warning üretir ve değer implicit default'a ('') döner. `$wpdb->query()` false dönmez, `last_error` boş kalır, installer:290'daki kontrol tetiklenmez. Scrub sessizce hiçbir şey yapmaz. DOĞRU.
6. Sonuç olarak yükseltilmiş sitede tid NOT NULL kalırken `nicepay_save_transaction()` (functions:344) tid'i null bırakır; `prepare_write()` (schema:257-275) tid'i whitelist'te tuttuğu için `$wpdb->insert()` çağrılır. wpdb, null değerleri için format yerine literal `NULL` yazar (`_insert_replace_helper`), yani `INSERT ... VALUES (NULL, ...)`. MySQL/MariaDB'de tek satırlık INSERT'te NOT NULL sütuna NULL yazmak strict moddan BAĞIMSIZ olarak ER_BAD_NULL_ERROR (1048) verir. Gateway'in tek yazma noktası class-nicepay-gateway.php:269 tid göndermiyor -> her ödeme formu üretimi başarısız olur, kullanıcı "Payment initialization failed" görür (gateway.php:296-300).

ÇÜRÜTÜLEN DETAY (Senaryo A'nın kurgusu):
İddia, 2+ boş TID varsa dbDelta'nın 1062 vermesi sonucu `maybe_install`'un `nicepay_schema_db_error` döndüreceğini ve gateway'in checkout'tan kaybolacağını söylüyor. Bu YANLIŞ: dbDelta hata alsa da kalan sorguları çalıştırmaya devam eder ve `wpdb::query()` her çağrıda `flush()` ile `last_error`'ı sıfırlar. uniq_tid'den sonra idx_created_at, idx_updated_at, idx_status_created, idx_flow_status, idx_source_ref, idx_reconciliation_status, idx_receipt_token_hash, idx_vbank_expires_at gibi v1'de bulunmayan indeksler eklenir (hepsi başarılı) ve ardından installer:183 ikinci dbDelta ile refund tablosunu CREATE TABLE ile oluşturur. Bu yüzden installer:185'teki `if ( ! empty( $wpdb->last_error ) )` kontrolü 1062'yi ASLA görmez; maybe_install 'installed' döner ve installer:200 sürüm option'ını ilerletir. Yani migrasyon "başarılı" raporlanır, uniq_tid sessizce oluşmaz. Gerçek arıza uniq_tid çakışması değil, NOT NULL kalan sütuna yapılan NULL INSERT'tir ve İLK ödemeden itibaren olur (iddiadaki "en geç ikinci müşteri" ifadesi yanlış). Ayrıca bu durum ikinci bir zafiyeti ortaya çıkarıyor: installer:185'teki last_error kontrolü dbDelta hatalarını tespit etmek için güvenilir bir mekanizma değil.

Not: strict mode'u `incompatible_sql_modes` filtresiyle geri açan sitelerde installer:269 gerçekten hata verir; o zaman scrub `nicepay_schema_sensitive_data_cleanup_failed` döner ve is_current() false kalarak gateway checkout'tan kaybolur — yani iddianın "gateway kayboluyor" etkisi vardır ama başka bir kod yolundan ve başka hata koduyla.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: v1 -> v2 yükseltmesinde `tid` sütunu NOT NULL DEFAULT '' olarak kalır ve migrasyon bunu SESSİZCE "başarılı" raporlar; sonuç, eksik uniq_tid indeksi + yükseltme sonrası ilk müşteriden itibaren %100 ödeme başlatma hatasıdır.

Zincir (tamamı doğrulandı):
1. installer:269'daki `UPDATE {table} SET tid = NULL WHERE tid = ''` WP'nin non-strict oturumunda 0 satır etkiler ve yalnızca `Warning 1048` üretir; wpdb->query 0 (false değil) döndüğü ve warning last_error'a yazılmadığı için installer:290 kontrolü tetiklenmez.
2. dbDelta nullability'yi hiç karşılaştırmaz (upgrade.php:3206 sadece tip token'ı; :3244 sadece tırnaklı DEFAULT), sütun `NOT NULL DEFAULT ''` kalır.
3. `ALTER TABLE ... ADD UNIQUE KEY uniq_tid (tid)` 1062 ile başarısız olur — ANCAK dbDelta hatada durmaz (upgrade.php:3350-3354) ve wpdb::query() her seferinde flush() ile last_error'ı sıfırlar (class-wpdb.php:1925). uniq_tid indeks listesinin 3.'südür; ardından gelen 8+ başarılı indeks ALTER'ı ve installer:183'teki ikinci dbDelta hatayı siler. Bu yüzden installer:185 kontrolü hatayı KAÇIRIR, installer:200 sürüm option'ını ilerletir, is_current() true döner.
4. Migrasyon "başarılı" görünür (admin sağlık kontrolü class-nicepay-admin.php:433 yeşil), fakat uniq_tid indeksi yoktur — tasarlanan TID tekillik garantisi sessizce kaybolur.
5. Ardından her `nicepay_save_transaction` çağrısı, wpdb'nin null'u literal NULL yazması nedeniyle (class-wpdb.php:2599-2600) `ERROR 1048 Column 'tid' cannot be null` alır — non-strict modda bile, tek satırlık INSERT olduğu için. Her iki çağıran da tid geçmez (class-nicepay-gateway.php:269, nicepay-payment-gateway.php:589), yani nicepay-functions.php:344'teki `'tid' => null` daima kullanılır.

Doğru başarısızlık senaryosu (tek senaryo, iki değil): v1 kullanan bir mağaza eklentiyi günceller; bootstrap maybe_install() çağırır; migrasyon hatasız görünür; İLK müşteri checkout'ta "Place order" der; nicepay_save_transaction 1048 alıp false döner; müşteri "Payment initialization failed. Please try again." görür (class-nicepay-gateway.php:296-299), sipariş notu düşülür ve bu her müşteride tekrarlanır. Standalone akışta ise wp_send_json_error (nicepay-payment-gateway.php:611). Mağaza hiç tahsilat yapamaz ve admin panelinde hiçbir uyarı yoktur.

Öneri (orijinaldeki gibi) geçerlidir: scrub'dan ÖNCE açık `ALTER TABLE {table} MODIFY tid varchar(50) NULL DEFAULT NULL` çalıştırıp last_error'ı hemen kontrol edin, UPDATE'ten sonra `SELECT COUNT(*) ... WHERE tid = ''` = 0 assert edin, aksi halde fail-closed WP_Error dönün. AYRICA: dbDelta çağrılarından sonra last_error'a güvenmeyi bırakın — dbDelta'yı bir sorgu-yakalayan wrapper ile sarın veya dbDelta sonrası `SHOW INDEX FROM {table}` ile uniq_moid/uniq_active_attempt/uniq_tid'in fiilen var olduğunu doğrulayın, yoksa sürüm option'ını ilerletmeyin. Testi de origin/main'in gerçek v1 CREATE TABLE'ından (3 adet tid='' satırıyla) başlatın ve yükseltme sonrası iki ardışık nicepay_save_transaction'ın başarılı olduğunu + uniq_tid'in var olduğunu doğrulayın.
- Gerekçe: Kök neden ve nihai sonuç DOĞRULANDI ve gerçek MariaDB 10.11 (test scriptinin kullandığı imajın aynısı) üzerinde deneysel olarak üretildi. Ancak Senaryo A'nın mekanizması YANLIŞ — ve gerçek davranış iddia edilenden daha kötü.

DOĞRULANANLAR (hepsi kanıtlı):
1. v1 şeması gerçekten `tid varchar(50) NOT NULL DEFAULT ''` (origin/main:nicepay-payment-gateway.php:112) ve v1 `nicepay_save_transaction` her başlangıç kaydına `'tid' => ''` yazıyordu (origin/main:includes/nicepay-functions.php:49). Yani her v1 mağazasında 2+ boş-TID satırı olması istisna değil kural.
2. Scrub UPDATE (installer:269) non-strict modda HİÇBİR ŞEY YAPMIYOR. Canlı MariaDB 10.11 testim: `sql_mode='NO_ENGINE_SUBSTITUTION'` iken `UPDATE v1 SET tid = NULL WHERE tid = ''` → affected_rows=0, 3x `Warning 1048 Column 'tid' cannot be null`, değerler `[]` olarak kalıyor. wpdb->query 0 döner (false değil) ve warning last_error'a yazılmaz → installer:290 `false === $result || ! empty($wpdb->last_error)` kontrolü TETİKLENMEZ.
3. WP gerçekten STRICT_TRANS_TABLES'ı kaldırıyor: class-wpdb.php:644-647 `$incompatible_modes` içinde 'STRICT_TRANS_TABLES', :975-978 set_sql_mode'da uygulanıyor.
4. dbDelta nullability'yi ASLA düzeltmez: wp-admin/includes/upgrade.php:3206 `if ( $tablefield->Type !== $fieldtype_lowercased )` — sadece tip token'ı (`varchar(50)` vs `varchar(50)`) karşılaştırılır, eşit → CHANGE COLUMN yok. :3244 `preg_match( "| DEFAULT '(.*?)'|i", ... )` — `DEFAULT NULL` tırnaksız olduğu için eşleşmez → DEFAULT da değişmez. Sütun kalıcı olarak `NOT NULL DEFAULT ''` kalır.
5. Sonuç: `ALTER TABLE v1 ADD UNIQUE KEY uniq_tid (tid)` → `ERROR 1062 Duplicate entry '' for key 'uniq_tid'` (canlı testte üretildi).
6. Senaryo B doğrulandı ve iddia edilenden daha kesin: wpdb::_insert_replace_helper null değeri literal `NULL` olarak yazar (class-wpdb.php:2599-2600 `if ( is_null( $value['value'] ) ) { $formats[] = 'NULL'; }`). Tek satırlık INSERT'te non-strict modda bile hata: canlı testte `INSERT INTO v2 (tid, moid) VALUES (NULL, 'A')` → `ERROR 1048 Column 'tid' cannot be null`. Boş stringe çevrilme YOK — her zaman hata. Ve her iki çağıran da (class-nicepay-gateway.php:269, nicepay-payment-gateway.php:589) `tid` geçmiyor, yani nicepay-functions.php:344'teki `'tid' => null` varsayılanı devreye girer.

ÇÜRÜTÜLEN DETAY (Senaryo A mekanizması):
İddia "wpdb->last_error dolar, maybe_install nicepay_schema_db_error döner, sürüm option'ı asla ilerlemez, NicePay checkout'tan kaybolur" diyor. Bu YANLIŞ. dbDelta hatada durmaz (upgrade.php:3350-3354 `foreach ( $allqueries as $query ) { $wpdb->query( $query ); }`) ve wpdb::query() her çağrıda `$this->flush()` yapar (class-wpdb.php query() gövdesi), flush() ise `$this->last_error = ''` (class-wpdb.php:1925). `uniq_tid` schema:127'de indeks listesinin 3.'südür; ardından idx_created_at, idx_updated_at, idx_status_created, idx_flow_status, idx_source_ref, idx_reconciliation_status, idx_receipt_token_hash, idx_vbank_expires_at BAŞARIYLA eklenir ve 1062 hatasını last_error'dan siler. Üstüne installer:183 ikinci bir dbDelta ($refund_sql) çalıştırır, o da DESCRIBE/SHOW sorgularıyla last_error'ı temizler. Dolayısıyla installer:185 kontrolü hatayı KAÇIRIR; installer:200 update_option başarılı olur; is_current() true döner; gateway açık kalır.

Yani gerçek sonuç fail-closed değil, SESSİZ FAIL-OPEN: migrasyon "başarılı" raporlanır, admin hiçbir uyarı görmez (admin/class-nicepay-admin.php:433 "Transaction schema" satırı yeşil kalır), uniq_tid indeksi sessizce eksik kalır (tasarlanan TID tekilliği garantisi yok olur), ve ardından İLK müşteriden itibaren HER nicepay_save_transaction 1048 ile başarısız olur → nicepay-functions.php:382 `$result === false` → gateway'de "Payment initialization failed. Please try again." (class-nicepay-gateway.php:296-299) ve standalone AJAX'ta wp_send_json_error (nicepay-payment-gateway.php:611). Senaryo A ve B iki ayrı senaryo değil, aynı tek senaryonun sonuçlarıdır: boş-TID satır sayısından bağımsız olarak sütun NOT NULL kaldığı için ödeme akışı %100 kırılır; boş-TID sayısı sadece uniq_tid indeksinin eklenip eklenmediğini belirler.

İkincil olarak: installer:84 `duplicate_moid_count_sql` boş moid'leri KAPSAR (filtresiz GROUP BY), ama installer:98 `duplicate_tid_count_sql` `WHERE tid IS NOT NULL AND tid <> ''` ile boşları hariç tutar — bu asimetri, moid için fail-closed olan korumanın tid için çalışmamasının doğrudan sebebi. Öneri kısmındaki "scrub'dan önce açık ALTER MODIFY + doğrulama + fail-closed assert" düzeltmesi teknik olarak doğru ve yeterlidir.

TEST BOŞLUĞU DA DOĞRULANDI: tests/integration/schema-migration.php gerçek v1 CREATE TABLE'dan hiç başlamıyor; :35-38 taze (zaten nullable tid'li) tabloda 3 sütun düşürüp sürümü '2026.08.20.5' yapıyor, :46 sadece card_no ekliyor. tid'in NOT NULL olduğu hiçbir durum test edilmiyor — bu yüzden hata CI'da görünmüyor.

Severity: critical DOĞRU, hatta hafife alınmış. Sessiz fail-open olması (migrasyon başarı raporlarken hem tekillik garantisi kayboluyor hem checkout %100 ölüyor, üstelik admin sağlık kontrolü yeşil) engellenen migrasyondan daha kötü. critical'de bırakıyorum çünkü ölçek zaten tavan.

---

### DATA-018 — dbDelta hata tespiti sağlam değil: eksik indeks/sütunla şema 'güncel' işaretlenebiliyor

| | |
|---|---|
| **Severity** | 🔴 Kritik |
| **Kategori** | migration-integrity |
| **Konum** | [includes/class-nicepay-installer.php:185](../../../includes/class-nicepay-installer.php#L185) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
maybe_install() dbDelta'yı iki kez çağırıyor ve sonra sadece tek bir bayrağa bakıyor:

    $changes        = call_user_func( $db_delta, $sql );          // :182
    $refund_changes = call_user_func( $db_delta, $refund_sql );    // :183
    if ( ! empty( $wpdb->last_error ) ) { return new WP_Error( 'nicepay_schema_db_error', ... ); }   // :185-191
    if ( ! self::table_exists( $wpdb, $table ) || ! self::table_exists( $wpdb, $refund_table ) ) { ... }  // :193-198
    if ( $current !== $target && false === update_option( self::VERSION_OPTION, $target, false ) ) ...   // :200

Sorun: wpdb::query() her çağrıda flush() yapıp $this->last_error = '' atar. Yani last_error YALNIZCA en son çalıştırılan sorgunun hatasını taşır. dbDelta ise $cqueries + $iqueries listesini sırayla çalıştırır ve indeks ekleme sorguları en sonda gelir; ayrıca ikinci dbDelta (refund tablosu) çağrısı, birinci tablodaki tüm hataları tamamen üzerine yazar. Doğrulama olarak sadece 'tablo var mı' bakılıyor (:193); hiçbir sütun veya indeks varlığı kontrol edilmiyor. column_exists() helper'ı zaten mevcut (:240) ama migrasyon sonrası doğrulamada kullanılmıyor.
```

**Başarısızlık senaryosu**

MySQL 5.6 / MariaDB 10.1 (ROW_FORMAT=COMPACT) üzerinde yükseltme: dbDelta 'ALTER TABLE wp_nicepay_transactions ADD KEY idx_source_ref (flow, source_ref)' sorgusunu çalıştırır, MySQL '#1071 Specified key was too long' döner (bkz. DATA-002). Ardından dbDelta idx_reconciliation_status, idx_receipt_token_hash, idx_vbank_expires_at sorgularını başarıyla çalıştırır ve last_error temizlenir; sonra refund tablosunun dbDelta'sı çalışır. maybe_install() last_error boş görür, iki tablo da vardır, nicepay_transactions_schema_version = '2026.08.24.7' yazılır. Aynı hata uniq_active_attempt eklemesi sırasında (ör. ALTER anında yarışan bir duplicate satır nedeniyle) oluşursa: iki eşzamanlı receipt sayfası aynı sipariş için iki 'pending' satır açar, ikisi de PG'ye gider, iki ayrı TID ile iki kez tahsilat yapılır ve ledger bunu normal kabul eder.

**Etki**

Eklentinin tüm çift-ödeme/çift-iade koruması uniq_moid, uniq_tid ve uniq_active_attempt indekslerine dayanıyor (bkz. nicepay_save_transaction'ın duplicate insert'e güvenmesi, tests/integration/schema-migration.php:89-101). Bu indekslerden biri sessizce oluşmazsa şema 'güncel' yazılır, is_current() true döner, gateway açılır ve koruma yokken çalışır. Sorun hiçbir logda, hiçbir admin uyarısında görünmez.

**Öneri**

dbDelta'nın dönüş değerine ve last_error'a güvenmeyi bırakıp migrasyon sonrası açık doğrulama ekleyin:

    $required_columns = array_keys( NicePay_Transaction_Schema::columns() );
    foreach ( $required_columns as $column ) {
        if ( ! self::column_exists( $wpdb, $table, $column ) ) {
            return new WP_Error( 'nicepay_schema_column_missing', ..., array( 'column' => $column ) );
        }
    }
    $present = (array) $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
    foreach ( array( 'PRIMARY', 'uniq_moid', 'uniq_tid', 'uniq_active_attempt', 'idx_source_ref' ) as $index ) {
        if ( ! in_array( $index, $present, true ) ) {
            return new WP_Error( 'nicepay_schema_index_missing', ..., array( 'index' => $index ) );
        }
    }

Ayrıca her dbDelta çağrısından hemen sonra last_error'ı ayrı ayrı yakalayın (iki çağrı arasında değişkene alın) ve doğrulama başarısızsa VERSION_OPTION'ı kesinlikle yazmayın.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddia edilen dosya/satırların tamamını okudum; alıntılar birebir doğru ve satır numaraları dosyanın şu anki haliyle eşleşiyor.

1) Konum doğrulaması: includes/class-nicepay-installer.php:182-183 iki ayrı dbDelta çağrısı yapıyor, :185 tek bir `$wpdb->last_error` kontrolü, :193 yalnızca `table_exists()` doğrulaması, :200 sürüm yazımı. `column_exists()` :240'ta tanımlı ve SADECE scrub_legacy_sensitive_data() içinde (:268, :271, :274, :277) kullanılıyor — migrasyon sonrası doğrulamada kullanılmıyor. Grep ile tüm repoyu taradım: üretim kodunda hiçbir yerde `SHOW INDEX` yok (yalnızca tests/integration/schema-migration.php:28-32'de var, o da entegrasyon testi; CI dışı sitede çalışmaz).

2) Çürütme aranan koruma katmanları YOK: `is_current()` (:39-54) yalnızca option değeri + iki tablonun varlığına bakıyor; sütun/indeks bakmıyor. Yani sürüm bir kez yanlışlıkla yazıldığında sonraki hiçbir istek durumu yeniden tespit edemez — maybe_install() :128'deki erken return `'status' => 'current'` ile dbDelta'yı bir daha hiç çalıştırmaz. Bootstrap (nicepay-payment-gateway.php:202-215) sadece WP_Error durumunda loglama/uyarı yapıyor; sessiz kısmi migrasyon 'installed' döndüğü için hiçbir log/uyarı üretmiyor — "hiçbir logda görünmez" iddiası doğru.

3) Etki zinciri doğrulandı: nicepay_save_transaction() (includes/nicepay-functions.php:338-388) SELECT-then-INSERT guard'ı olmadan doğrudan `$wpdb->insert()` yapıyor; tekillik tamamen DB unique indekslerine bırakılmış. class-nicepay-gateway.php:275 `active_attempt_key = 'woocommerce:<order_id>'` yazıyor ve :305-308'deki yorum bunu açıkça itiraf ediyor: "after this request owns the unique active-attempt row. A concurrent receipt refresh that loses the database race must not overwrite the winning Moid". uniq_active_attempt sessizce oluşmazsa bu "database race" hiç yaşanmaz, iki eşzamanlı receipt isteği de satır açar.

4) `last_error` mekaniği: WP core'da wpdb::query() her çağrıda flush() ile `last_error = ''` yapar ve dbDelta $allqueries = array_merge($cqueries, $iqueries) listesini tek tek $wpdb->query() ile çalıştırır. İkinci dbDelta çağrısı (refund tablosu) daha en başta DESCRIBE/SHOW INDEX introspection sorguları çalıştırdığı için, birinci tablonun TÜM hatalarını kesin olarak siler. Yani :185 pratikte sadece "en son çalışan sorgunun hatası"nı yakalayabilir. (Bu tek premis repo içinden değil WP core davranışından geliyor; WP core burada vendor'lanmamış — ancak bu standart, iyi belgelenmiş davranış.)

5) Ekstra doğrulayıcı kanıt (iddiayı güçlendiriyor, çürütmüyor): maybe_install() :138-161 yalnızca moid ve tid için duplicate ön-kontrolü yapıyor; `active_attempt_key` için HİÇBİR duplicate ön-kontrolü yok. Yani ALTER ADD UNIQUE uniq_active_attempt'in #1062 ile patlaması egzotik değil, ön-kontrolsüz gerçek bir yol.

6) Testin verdiği sahte güven: tests/unit/NicePayInstallerTest.php:137-152 `test_db_error_does_not_advance_schema_version` testi last_error'ı ÖNCEDEN set edip fake db_delta'nın onu hiç temizlemediği bir dünyada geçiyor (fake `query()` :52-55 last_error'a asla dokunmuyor). Gerçek wpdb bu senaryoda last_error'ı temizlerdi; yani yeşil test, var olmayan bir korumayı doğruluyor.

Sonuç: iddianın çekirdeği, konumu, mekanizması ve etkisi kodla doğrulandı.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `critical` → `high`
- Düzeltilmiş iddia: dbDelta hata tespiti gerçekten sağlam değil: `maybe_install()` iki ayrı dbDelta çağrısından SONRA tek bir `$wpdb->last_error` bakıyor (ikinci çağrı birincinin hatasını kesin olarak siler), doğrulama olarak yalnızca "tablo var mı" kontrolü yapıyor (:193), hiçbir sütun/indeks varlığı doğrulanmıyor (`column_exists()` :240'ta var ama kullanılmıyor) ve VERSION_OPTION (:200) yine de yazılıyor. Sonuç: kısmi migrasyon "güncel" işaretlenir, `is_current()` true döner, gateway açılır (class-nicepay-gateway.php:81), sağlık kontrolü yeşil kalır (admin/class-nicepay-admin.php:433) ve eklentinin kendi logunda/admin uyarısında hiçbir iz kalmaz (nicepay-payment-gateway.php:204-207 yalnızca WP_Error'da loglar).

ANCAK iddianın üç detayı fazla iddialı:

(1) YOL SINIRI: Bu yalnızca YÜKSELTME yolunda (tablo zaten varken, dbDelta çok sayıda ayrı ALTER üretirken) istismar edilebilir. Temiz kurulumda tüm indeksler tek bir CREATE TABLE içindedir; başarısız olursa tablo hiç oluşmaz ve :193 bunu yakalar. Yani "eklenti ilk kurulumda korumasız açılır" senaryosu geçersiz.

(2) ÇİFT TAHSİLAT SONUCU ABARTILI: `uniq_active_attempt` eksik olsa bile aynı WooCommerce siparişi için iki kez tahsilat OLMAZ. `nicepay_claim_transaction_for_approval()` (includes/nicepay-functions.php:665-690), approval transport'undan ÖNCE (class-nicepay-gateway.php:434) çağrılır ve tek bir atomik UPDATE ile aynı flow+source_ref'e sahip TÜM kardeş pending satırları 'abandoned' yapar; ikinci callback 0 satır etkiler ve false alır. Bu, indeksten bağımsız ikinci bir savunma katmanıdır. Gerçek sonuç "aynı sipariş için iki pending satır + iki PG auth", "iki capture" değil.

(3) `uniq_active_attempt`'in ALTER anında yarışan duplicate satır yüzünden düşmesi pratikte ulaşılamaz: `active_attempt_key` bu migrasyonda YENİ eklenen `varchar(191) DEFAULT NULL` sütunudur (class-nicepay-transaction-schema.php:51), mevcut tüm satırlarda NULL'dur, MySQL unique indeksinde NULL'lar çakışmaz ve dbDelta ADD COLUMN sorgularını ADD INDEX'ten önce çalıştırır.

Ayrıca "hiçbir logda görünmez" kısmen yanlıştır: bastırılmamış sorgu hataları `wpdb::print_error()` üzerinden PHP error_log'una düşer. Eklentinin kendi log/admin/health yüzeyinde görünmediği doğrudur.

Geriye kalan (ve düzeltilmesi gereken) çekirdek kusur tamamen doğrulanmıştır ve öneri aynen geçerlidir; ek olarak `nicepay_save_transaction()` (includes/nicepay-functions.php:380-387) hiçbir uygulama seviyesi dedup yapmaz, tamamen DB constraint'ine güvenir — bu yüzden sessizce düşen bir UNIQUE indeksin hiçbir ikincil sinyali yoktur.
- Gerekçe: İddianın mekanizması ve satır referansları birebir doğru — kodu okudum, hepsi eşleşiyor. `maybe_install()` :182-183'te iki dbDelta çağırıyor, :185'te tek bir last_error kontrolü yapıyor, :193'te SADECE tablo varlığına bakıyor, :200'de sürüm bayrağını yazıyor. `column_exists()` (:240) yalnızca `scrub_legacy_sensitive_data()` içinde (:268-277) kullanılıyor, migrasyon sonrası doğrulamada kullanılmıyor. Üretim kodunda hiçbir yerde SHOW INDEX/SHOW COLUMNS doğrulaması yok — tek SHOW INDEX kullanımı tests/integration/schema-migration.php:27. Yani testler zaten doğru doğrulamayı biliyor, üretim kodu bilmiyor. wpdb::query() -> flush() -> last_error='' davranışı WP çekirdeğinde yerleşiktir ve ikinci dbDelta (refund tablosu) birincinin hatasını kesinlikle üzerine yazar; bu kısım tartışmasız.

Ancak "sonuç ve istismar edilebilirlik" merceğinden severity düşürülmeli:

a) Ulaşılabilirlik dar: sadece upgrade yolu. Temiz kurulumda tek CREATE TABLE var; başarısız olursa :193 yakalar. İddia bunu ayırt etmiyor ve "gateway korumasız açılır" izlenimi veriyor.

b) İddianın taşıyıcı sonucu (iki TID ile çift tahsilat) somut olarak üretilemiyor. nicepay_claim_transaction_for_approval'ın WooCommerce dalı, indeksten tamamen bağımsız, tek deyimli atomik bir kardeş-iptal + tek-kazanan UPDATE'idir ve approval çağrısından önce çalışır. İki eşzamanlı return callback'i satır kilitleri üzerinde serileşir; ikincisinin target satırı artık 'abandoned' olduğu için join boş döner ve claim false verir. Yani PG'ye ikinci approval hiç gitmez. Kalan gerçek zarar: aynı sipariş için iki pending satır, iki PG auth (capture değil), nicepay_get_active_woocommerce_transaction'ın LIMIT 1 ile yanlış satırı döndürebilmesi ve ledger gürültüsü.

c) uniq_active_attempt'in duplicate-key ile düşme alt senaryosu ulaşılamaz (yeni NULL sütun; NULL'lar unique indekste çakışmaz; ADD COLUMN, ADD INDEX'ten önce çalışır).

d) idx_source_ref (flow varchar(20) + source_ref varchar(191) = utf8mb4'te 844 bayt > 767) tetikleyicisi aritmetik olarak doğru ve MySQL 5.6/COMPACT'ta gerçekten #1071 verir; ama bu yalnızca bir performans indeksidir — düşmesi hiçbir doğruluk garantisini bozmaz. Yine de mekanizma tek tetikleyiciye bağlı değil: lock wait timeout, disk dolu veya herhangi bir geçici ALTER hatası da aynı şekilde yutulur, bu ortamdan bağımsızdır.

Özet: gerçek, somut, düzeltilmesi gereken bir migrasyon-bütünlüğü kusuru (sessiz kısmi şema + "güncel" bayrağı + sıfır operatör sinyali), ama "critical" seviyesini haklı çıkaracak doğrudan çift-tahsilat yolu yok. high doğru seviye. Önerilen düzeltme (sütun + indeks açık doğrulaması, her dbDelta sonrası last_error'ı ayrı yakalama, doğrulama başarısızsa VERSION_OPTION yazmama) aynen uygulanmalı.

---

### DATA-002 — Eski satırlar için backfill yok: v1'den yükselen tüm ödenmiş siparişler kalıcı olarak iade edilemez hale geliyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | migration-data-integrity |
| **Konum** | [includes/class-nicepay-gateway.php:716](../../../includes/class-nicepay-gateway.php#L716) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
Şema yorumu backfill'i bilinçli olarak kapsam dışı bırakıyor (schema:37-38: legacy-value backfills are intentionally outside this schema definition) ve installer'da da hiçbir backfill sorgusu yok (installer:262-300 sadece siliyor/temizliyor). Eski tabloda currency, mid, mode, captured_amount, remaining_amount, flow sütunları hiç yoktu (origin/main:111-137); dbDelta bunları DEFAULT '' / DEFAULT 0 ile ekliyor. process_refund ise ilk kapıda bunları zorunlu kılıyor: gateway:716-721 'KRW' !== $transaction_currency || empty($transaction->mid) || empty($transaction->mode) -> nicepay_refund_context_error. Ayrıca amount'a geri düşme mantığı da bozuk: gateway:724 !empty($transaction->captured_amount) ifadesi decimal(14,2) sütunundan gelen 0.00 stringi için PHP'de TRUE olduğundan (sadece '0' ve '' falsy'dir) fallback hiç devreye girmiyor ve nicepay_normalize_amount('0.00') false döndürüyor. v1'de aynı sipariş iade edilebiliyordu (origin/main:includes/class-nicepay-gateway.php:418-428 sadece _nicepay_tid meta'sını arıyordu).
```

**Başarısızlık senaryosu**

v1.x kullanan bir mağaza 2.0.0'a güncelliyor. Üç gün önce 50.000 KRW ödenmiş #1234 numaralı sipariş için müşteri iade istiyor. Merchant WooCommerce sipariş ekranından Refund via NicePay Payment Gateway diyor; process_refund gateway:716-721'de 'KRW' !== '' testine takılıp The original payment context does not match the active NicePay configuration hatasını dönüyor. Merchant ne yapacağını anlamıyor; NicePay yönetim listesinde de aynı satır Captured 0 KRW ve pasif iade butonu ile görünüyor. Tek çare NICEPAY merchant panelinden manuel iptal, ki bu WooCommerce'te kayıt bırakmıyor.

**Etki**

Yükseltme anında var olan tüm NicePay ödemeleri için iade yeteneği sessizce kayboluyor; bu bir fonksiyonel regresyon ve mağazanın müşteriye para iadesi yapamaması demek. Admin listesi de aynı satırlar için Captured değerini 0 gösteriyor, iade butonunu pasifleştiriyor ve finansal özet SUM(captured_amount) toplamını 0 raporluyor.

**Öneri**

Installer'a dbDelta SONRASI, tek seferlik ve idempotent bir backfill adımı ekleyin: UPDATE {table} SET currency='KRW' WHERE currency=''; UPDATE {table} SET flow='woocommerce' WHERE flow='' AND wc_order_id IS NOT NULL; UPDATE {table} SET captured_amount=amount, remaining_amount=amount WHERE status='paid' AND captured_amount=0; ve mid/mode alanlarını payment_data içindeki MID'den ya da o anki yapılandırmadan türetilemiyorsa satırı needs_reconciliation yerine legacy olarak işaretleyip iade akışında mid/mode kontrolünü flow='' satırları için gevşetin. Ayrıca !empty($x) yerine tutarlar için her yerde nicepay_normalize_ledger_amount($x) === false || '0' === ... kullanın; decimal sütunlarından gelen '0.00' stringi PHP'de truthy olduğu için !empty tabanlı fallback'ler (gateway:724, admin/class-nicepay-transactions.php:797) sessizce yanlış çalışıyor.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Backfill yokluğu ve "v1'den yükselen ödenmiş siparişler artık iade edilemiyor" çekirdek iddiası DOĞRU ve üretilebilir; ancak iddianın gösterdiği başarısızlık yolu YANLIŞ. Legacy satır gateway:716-721 kapısına hiç ulaşmaz, çünkü sorgu katmanı satırı zaten döndürmez: nicepay-functions.php:450 `SELECT * FROM {$table} WHERE tid = %s AND wc_order_id = %d AND flow = 'woocommerce' LIMIT 1` — v1'de `flow` sütunu yoktu, dbDelta onu `DEFAULT ''` ile ekliyor, dolayısıyla legacy satırlar bu WHERE'e takılır ve NULL döner. process_refund gateway:703-705'te durur ve `nicepay_refund_error` / "Transaction ID not found." döner — "The original payment context does not match the active NicePay configuration." mesajı DEĞİL. Bu ayrıca gateway:704'teki `in_array((string)$transaction->flow, array('', 'woocommerce'), true)` legacy toleransını bu yol için ölü kod yapar: yazar legacy flow='' senaryosunu düşünmüş ama repository sorgusu bunu iptal ediyor — tutarsızlık, ayrıca raporlanmalı. Merchant açısından sonuç daha da kötü: TID sipariş meta'sında ve tabloda mevcutken "Transaction ID not found." denmesi tamamen yanıltıcı bir hata mesajı. İkincil alt-iddia: `!empty('0.00')` PHP'de TRUE ve nicepay_normalize_amount('0.00','KRW') gerçekten false döndürür (nicepay-functions.php:1517-1520: ltrim('0','0') === '' -> return false) — bu doğrulandı; ancak gateway:724'teki fallback pratikte ÖLÜ KOD (o satıra ulaşan legacy satır yok, normal satırlarda captured_amount her zaman doluyor: return-handler:188, gateway:602). Aynı hata admin/class-nicepay-transactions.php:797'de ise CANLI: legacy satırlar için Captured 0 gösteriliyor. Admin tarafındaki diğer iddialar doğrulandı: transactions.php:805 `'0' !== nicepay_normalize_ledger_amount($remaining)` legacy '0.00' için iade butonunu pasifleştiriyor; transactions.php:105 SUM(captured_amount) legacy satırlar için 0 raporluyor (ve GROUP BY currency legacy satırları boş bir '' para birimi grubunda topluyor — ek kozmetik bozukluk). Önerideki backfill listesine `flow` sütunu için UPDATE'in ZORUNLU olduğu vurgulanmalı: currency/mid/mode backfill'i tek başına yapılırsa satır yine hiç okunamaz.
- Gerekçe: Önkoşulları tek tek doğruladım: (1) v1 tablosunda currency/mid/mode/captured_amount/remaining_amount/flow sütunları YOK (origin/main:nicepay-payment-gateway.php:110-144). (2) v2 şeması bunları NOT NULL DEFAULT ''/0 ile ekliyor (transaction-schema:49,59,60,61,63,65) ve şema yorumu backfill'i açıkça kapsam dışı bırakıyor (schema:37-38). (3) Installer'da dbDelta öncesi/sonrası hiçbir backfill yok; scrub_legacy_sensitive_data yalnızca siliyor/boşaltıyor (installer:262-300) ve repoda tek bir `UPDATE ... SET currency/flow/captured_amount` sorgusu yok. (4) v1 başarılı ödemede status='paid' yazıyor (origin/main gateway:359) ve _nicepay_tid meta'sını kaydediyor (origin/main gateway:363), yani senaryonun girdi durumu gerçekçi. (5) Gateway `refunds` destekliyor (gateway:22), yani Woo sipariş ekranındaki iade butonu gerçekten process_refund'a ulaşıyor. Buraya kadar iddia doğru: yükseltme sonrası legacy ödenmiş siparişler iade edilemiyor, bu gerçek bir fonksiyonel regresyon (v1'de yalnızca _nicepay_tid meta'sı yeterliydi, origin/main gateway:418-428). Ancak "istismar edilebilirlik/somut adım" merceğinden bakınca iddianın failure_scenario'su üretilemez: nicepay_get_transaction_by_tid, wc_order_id verildiğinde sorguya `AND flow = 'woocommerce'` ekliyor (nicepay-functions.php:447-455), legacy satırların flow'u '' olduğundan get_row NULL döner ve akış gateway:703-705'te "Transaction ID not found." ile biter; gateway:716-721'deki context hatası bu senaryoda ASLA tetiklenmez. Yani konum, hata kodu ve merchant'ın gördüğü mesaj yanlış. Etki ve severity ise doğru — hatta hata mesajının yanıltıcılığı nedeniyle biraz daha kötü; `high` uygun (mağaza iade yapamıyor ama veri kaybı/güvenlik açığı yok, çözüm tek seferlik SQL ile mümkün). '0.00' truthy alt-iddiası kod düzeyinde doğru fakat process_refund yolunda ulaşılamaz; admin listesinde ise gerçekten canlı bir hata.

---

### DATA-020 — v1 -> v2 migrasyonu yeni yaşam döngüsü sütunlarını backfill etmiyor; eski işlemler iade edilemez hale geliyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | migration-data |
| **Konum** | [includes/nicepay-functions.php:450](../../../includes/nicepay-functions.php#L450) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (2/2) |

**Sorun ve kanıt**

```php
Migrasyon salt additive; şema dosyası bunu açıkça beyan ediyor: "legacy-value backfills are intentionally outside this schema definition" (schema:37-38). Dolayısıyla v1 satırlarında flow='' (schema:49 varsayılan ''), captured_amount=0.00, remaining_amount=0.00, source_ref='' kalır.

Ama okuma yolu flow'u zorunlu kılıyor:

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE tid = %s AND wc_order_id = %d AND flow = 'woocommerce' LIMIT 1",   // functions:450
        $tid, $wc_order_id ) );

process_refund() tam da bu çağrıyı order id ile yapıyor (gateway:702), yani legacy satır asla bulunamaz. İronik olarak hemen altındaki iki katman legacy'yi açıkça desteklemek için yazılmış ve artık ölü kod:

    ! in_array( (string) $transaction->flow, array( '', 'woocommerce' ), true )   // gateway:704
    WHERE id = %d AND (flow = 'woocommerce' OR (flow = '' AND wc_order_id IS NOT NULL))  // functions:734

İkinci bir kapı daha var: flow backfill edilse bile

    $captured = nicepay_normalize_amount( ! empty( $transaction->captured_amount ) ? $transaction->captured_amount : $transaction->amount, 'KRW' );  // gateway:723-726

MySQL DECIMAL sütunu wpdb'den "0.00" STRING'i olarak döner. PHP'de empty("0.00") === false olduğu için fallback ($transaction->amount) hiç devreye girmez; nicepay_normalize_amount("0.00") ise ltrim('0','0') sonucu '' verip false döner.
```

**Başarısızlık senaryosu**

Mağaza 1.x ile 3 ay çalışır ve 4.000 kartlı ödeme alır. 2.0.0'a yükseltilir. Bir müşteri dünkü siparişini iade etmek ister. Admin WooCommerce sipariş ekranında 'Refund via NicePay' der; nicepay_get_transaction_by_tid('nicepay00m01...', 812) flow='woocommerce' filtresi yüzünden NULL döner ve iade 'Transaction ID not found.' hatasıyla reddedilir. Admin'in tek çıkışı NICEPAY merchant panelinden manuel iptal etmek, ki bu da WooCommerce ledger'ı ile NICEPAY'i kalıcı olarak ayrıştırır.

**Etki**

v1 kullanan tüm mağazalarda, yükseltmeden önceki her başarılı ödeme WooCommerce üzerinden iade edilemez hale gelir. Kullanıcıya dönen mesaj da yanlış: 'Transaction ID not found.' (gateway:705) veya flow düzeltilse bile 'Refund amount is invalid for this currency.' (gateway:733) — ikisi de gerçek nedeni (eksik migrasyon) gizler. Ayrıca yükseltme anında akışta olan bir ödeme, inbound validator'ın moid+flow='woocommerce' aramasına takılıp onaylanamaz.

**Öneri**

Installer'a idempotent bir backfill adımı ekleyin (dbDelta'dan SONRA, VERSION_OPTION yazılmadan ÖNCE):

    UPDATE {$table} SET flow = 'woocommerce', source_ref = CAST(wc_order_id AS CHAR)
      WHERE flow = '' AND wc_order_id IS NOT NULL;
    UPDATE {$table} SET captured_amount = amount, remaining_amount = amount
      WHERE status = 'paid' AND captured_amount = 0 AND refunded_amount = 0;
    UPDATE {$table} SET approval_state = 'approved' WHERE status = 'paid' AND approval_state = 'pending';

Ayrı olarak gateway:723'teki empty() kapısını DECIMAL round-trip'ine dayanıklı hale getirin:

    $captured = nicepay_normalize_ledger_amount( $transaction->captured_amount );
    if ( false === $captured || '0' === $captured ) { $captured = nicepay_normalize_amount( $transaction->amount, 'KRW' ); }

Backfill mümkün değilse, functions:450'deki flow filtresini kaldırıp (zaten wc_order_id ile bağlı) gateway:704'teki mevcut legacy kontrolüne güvenin; iki katman aynı anda çelişmemeli.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `confirmed`)

- Gerekçe: İddianın her iddia edilen kod gerçeği dosyaların şu anki halinde birebir doğrulandı; senaryo ulaşılabilir ve önkoşulları gerçekçi.

1) Legacy'nin varlığı gerçek: `origin/main` sürümündeki v1 CREATE TABLE'da `flow`, `source_ref`, `captured_amount`, `remaining_amount`, `currency`, `mid`, `mode` sütunları HİÇ YOK (nicepay-payment-gateway.php@main:110-144). CHANGELOG.md:56 `## [1.x] - Previous Versions` girdisiyle 1.x'in yayınlanmış olduğunu doğruluyor. dbDelta ile eklenen bu sütunlar NOT NULL DEFAULT '' / DEFAULT 0 olduğundan mevcut satırlarda flow='', captured_amount=0.00, remaining_amount=0.00, currency='', mid='', mode='' kalır.

2) Migrasyonda backfill yok: `NicePay_Installer::maybe_install()` (includes/class-nicepay-installer.php:111-213) yalnızca duplicate kontrolü, `scrub_legacy_sensitive_data()`, iki `dbDelta` çağrısı ve `update_option( VERSION_OPTION )` yapıyor. dbDelta ile option yazımı arasında tek bir UPDATE/backfill ifadesi yok. Şema dosyası da bunu açıkça beyan ediyor (class-nicepay-transaction-schema.php:37-38).

3) İstismar/tetikleme zinciri somut ve ulaşılabilir: v1'de `_nicepay_tid` sipariş metası zaten yazılıyordu (class-nicepay-return-handler.php@main:363). 2.0.0'a yükseltildikten sonra shop_manager/administrator, WooCommerce sipariş ekranında "Refund" gönderir (admin-ajax `woocommerce_refund_line_items`) -> WC `process_refund()` çağırır -> gateway:701-702 tid'i metadan alır -> functions:450'deki `AND flow = 'woocommerce'` filtresi legacy satırda ('') eşleşmediği için `get_row` NULL döner -> gateway:703-705 `'Transaction ID not found.'`. Ekstra bilgi/zamanlama/yarış gerekmiyor; %100 deterministik.

4) İddianın "çelişkili katmanlar / ölü kod" tespiti de doğrulandı: gateway:704 `in_array( $transaction->flow, array( '', 'woocommerce' ), true )` ve functions:734 `(flow = 'woocommerce' OR (flow = '' AND wc_order_id IS NOT NULL))` legacy'yi bilinçli destekliyor. Dahası functions:741'de `(status = 'paid' AND remaining_amount = 0 AND refunded_amount = 0 AND amount >= %s)` dalı TAM OLARAK sıfır-ledger legacy satırı için yazılmış. Yani alt katmanlar legacy için tasarlanmış ama functions:450'deki okuma filtresi o katmanlara hiç ulaşılmasını engelliyor -> ölü kod iddiası doğru.

5) İkinci kapı da doğrulandı: wpdb DECIMAL'i string döndürür ("0.00"); PHP'de `empty("0.00") === false` olduğundan gateway:724 fallback devreye girmez. `nicepay_normalize_amount("0.00",'KRW')` (functions:1484-1520) regex'i geçer, decimal[0]='0' <5 olduğu için yuvarlama yok, `ltrim('0','0') === ''` -> functions:1518-1519 `return false` -> gateway:731-733 `'Refund amount is invalid for this currency.'`. Not: `nicepay_normalize_ledger_amount()` (functions:1564-1573) tam da bu "0" durumunu ele almak için var ama captured tarafında kullanılmamış — öneri doğru.

Severity: `high` yerinde, hatta hafife alınmış tarafı var. İddianın önerdiği backfill TEK BAŞINA YETMEZ: legacy satırlarda `currency`, `mid`, `mode` de boş kalacağı için gateway:716-720 refund'ı `'The original payment context does not match the active NicePay configuration.'` ile yine reddeder. Yine de `critical`'a çıkarmıyorum: veri kaybı/güvenlik açığı değil, para kaybı yok, merchant panelinden manuel iptal bir çıkış yolu (ledger ayrışması pahasına) ve etki yalnızca 1.x'ten yükselten mağazalarla sınırlı.

---

### DATA-021 — Cron'un stale-approving kurtarması active_attempt_key'i temizlemiyor: sipariş kalıcı olarak ödenemez kalıyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | correctness |
| **Konum** | [includes/nicepay-functions.php:970](../../../includes/nicepay-functions.php#L970) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
Süresi dolmuş 'pending' satırlarda kilit doğru şekilde bırakılıyor:

    SET status = 'expired', approval_state = 'expired', auth_token = '', active_attempt_key = NULL   // :959

Ama 30 dakikadır 'approving' takılı kalan satırlarda bırakılmıyor:

    $stale_sql = "UPDATE {$table}
                  SET status = 'needs_reconciliation',
                      approval_state = 'needs_reconciliation',
                      reconciliation_status = 'required',
                      reconciliation_note = 'stale_approval_attempt'
                  WHERE status = 'approving' AND approval_state = 'approving'
                    AND approval_started_at IS NOT NULL
                    AND approval_started_at < (UTC_TIMESTAMP() - INTERVAL 30 MINUTE)";   // :970-977

active_attempt_key burada NULL yapılmıyor. Bu satırın kilidini bırakabilecek tek fonksiyon nicepay_abandon_pending_transactions() ise sadece status='pending' satırlara dokunuyor (:934-935). Sonraki ödeme denemesi ise aynı anahtarı yeniden insert etmeye çalışıyor:

    'active_attempt_key' => 'woocommerce:' . (string) $order->get_id(),   // gateway:275

ve schema'da bu sütun UNIQUE: 'UNIQUE KEY uniq_active_attempt (active_attempt_key)' (schema:126).
```

**Başarısızlık senaryosu**

Müşteri kartla öder; PG onayı sırasında PHP-FPM request_terminate_timeout devreye girer ve süreç ölür (ledger 'approving', active_attempt_key='woocommerce:9001'). 30 dk sonra saatlik cron satırı 'needs_reconciliation' yapar ama anahtarı bırakmaz. Müşteri (veya admin) 'Pay' derse generate_payment_form() abandon adımını geçer, sonra nicepay_save_transaction() uniq_active_attempt ihlaliyle false döner ve ekrana 'Payment initialization failed. Please try again.' basılır (gateway:296-301). Bu mesaj yanıltıcıdır: tekrar denemek asla işe yaramaz; sipariş DB'ye elle müdahale edilmeden ödenemez.

**Etki**

Onay çağrısı sırasında PHP süreci ölürse (fatal, timeout, deploy, OOM) sipariş kalıcı olarak ödenemez hale gelir. Ayrıca bu satırlar retention predicate'inin 'active_attempt_key IS NULL' şartına da takıldığı için (retention:315) sonsuza kadar silinemez ve needs_reconciliation'dan çıkaracak hiçbir admin aksiyonu yok (bkz. DATA-014).

**Öneri**

Stale kurtarma sorgusuna kilidi bırakmayı ekleyin ve auth_token'ı koruyun (mutabakat için gerekli):

    SET status = 'needs_reconciliation',
        approval_state = 'needs_reconciliation',
        reconciliation_status = 'required',
        reconciliation_note = 'stale_approval_attempt',
        active_attempt_key = NULL

Ek olarak nicepay_save_transaction()'da duplicate-key hatasını ayırt edip (mysql errno 1062 / $wpdb->last_error içinde 'Duplicate entry') kullanıcıya 'Bu sipariş için hâlâ çözümlenmemiş bir ödeme denemesi var, lütfen mağaza ile iletişime geçin' gibi doğru mesaj verin; sessiz genel hata metni yerine.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Düzeltilmiş iddia: Çekirdek iddia aynen geçerli. Yalnızca "retention" gerekçesi hafifçe düzeltilmeli: needs_reconciliation satırları retention:308-311'deki status/approval_state şartları yüzünden zaten silinemez durumda; active_attempt_key IS NULL (retention:315) burada ek/ikincil bir blokaj, tek sebep değil.
- Gerekçe: İddiadaki tüm satır referanslarını ve çevre kodu okudum; iddia doğru ve çürüten bir koruma bulamadım.

1) Stale-recovery sorgusu (nicepay-functions.php:970-977) gerçekten active_attempt_key'i NULL yapmıyor; hemen üstündeki expiry sorgusu (:959) yapıyor. Yani asimetri gerçek.

2) Kilidi bırakabilecek diğer yolları grep'ledim (`grep -rn "active_attempt_key" includes admin templates tests`) ve hepsini tek tek okudum. Hiçbiri 'approving'/'needs_reconciliation' takılı satırı kurtarmıyor:
   - nicepay_abandon_pending_transactions (:933-935): sadece status='pending' AND approval_state='pending'.
   - nicepay_claim_transaction_for_approval (:664-681): WHERE candidate.status='pending' — approving satır yeniden claim edilemez, dolayısıyla ikinci bir callback de kilidi çözemez.
   - return-handler:191/222 ve gateway:318/359/528/664/675: hepsi request içi yollar; süreç zaten ölmüş senaryoda çalışmaz.
   - gateway:203 (recover_paid_order_from_ledger) kilidi bırakan tek "sonradan kurtarma" yolu ama guard'ı 'paid' + 'approved' (gateway:174-177), needs_reconciliation satırı için false döner ve process_payment/receipt_page (gateway:115-121, :155-162) doğrudan generate_payment_form'a düşer.
   - admin/ tarafında reconciliation için hiçbir yazma aksiyonu yok (grep "reconciliation" admin: yalnızca listeleme/uyarı/refund engeli).

3) UNIQUE kısıt gerçek (schema:126) ve sütun nullable (schema:51), yani MySQL çoklu NULL'a izin verir ama aynı 'woocommerce:<id>' değeri ikinci kez insert edilemez. nicepay_save_transaction (:380-385) $wpdb->insert başarısızlığında ayrım yapmadan false döner; gateway:296-303 de kullanıcıya "Payment initialization failed. Please try again." gösterir — tekrar denemek asla işe yaramayacağı için yanıltıcı mesaj iddiası da doğru.

Tek küçük düzeltme: retention etkisi abartılı değil ama tek nedeni active_attempt_key değil — eligible_row_predicate zaten status'ü izinli listeyle sınırlıyor ve 'needs_reconciliation'ı ayrıca dışlıyor (retention:308-311), auth_token='' şartı da var (:314). Yani active_attempt_key IS NULL (:315) burada gereksiz/ikincil bir blokaj; satır zaten status yüzünden silinemiyor. Çekirdek bulguyu değiştirmiyor, severity high yerinde.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: 'approving' durumunda süreç ölürse `active_attempt_key` kilidini bırakan HİÇBİR kod yolu yoktur (cron'un stale kurtarması dahil — o yalnızca etiketi 'needs_reconciliation' yapar, functions.php:970-977). Sonuç: sipariş için yeni bir ödeme denemesi UNIQUE `uniq_active_attempt` ihlaliyle başarısız olur (gateway:275, schema:126) ve müşteri yanıltıcı "Payment initialization failed. Please try again." mesajıyla + "The order remains payable." sipariş notuyla karşılaşır (gateway:296-301); satır retention tarafından da asla silinemez (retention:307-315). Ancak kilidin tutulması kısmen kasıtlı bir güvenlik davranışıdır: onay çağrısı sırasında ölen süreçte para PG'de yakalanmış olabilir, bu yüzden kilidi cron'da körlemesine NULL yapmak çifte tahsilat riski yaratır. Asıl eksikler: (1) duplicate-key hatasının ayırt edilip doğru mesaj verilmemesi ve order note'unun yanlış olması, (2) needs_reconciliation satırını çözüp kilidi bırakacak bir admin aksiyonunun hiç bulunmaması (admin/ içinde `active_attempt_key`'e dokunan tek satır yok), (3) bu satırların retention tarafından sonsuza dek tutulması.
- Gerekçe: Kodu satır satır okudum; iddianın MEKANİĞİ birebir doğru, ama kök neden atfı, önerilen düzeltme ve severity düzeltilmeli.

DOĞRULANAN KISIM (tamamı okundu):
1. `nicepay_claim_transaction_for_approval()` (functions.php:664-690) kazanan satırı 'approving' yaparken `active_attempt_key`'i BİLEREK korur (`candidate.active_attempt_key = IF(candidate.id = target.id, candidate.active_attempt_key, NULL)` :679). Yani 'approving' satır kilidi tutar.
2. Stale kurtarma UPDATE'i (functions.php:970-977) kilidi bırakmaz — expired sorgusunun (:959) aksine SET listesinde `active_attempt_key = NULL` yok.
3. Kilidi bırakabilecek başka hiçbir yol yok: `nicepay_abandon_pending_transactions()` sadece `status='pending' AND approval_state='pending'` satırlara dokunur (:934-935); `recover_paid_order_from_ledger()` sadece `status='paid' && approval_state='approved'` satırlarda çalışır (gateway:257-260); admin ekranında hiçbir "reconcile/unlock" aksiyonu yok (admin/class-nicepay-transactions.php'de yalnızca filtre + uyarı bandı var, :634-644, :800-802; hiçbir yerde `active_attempt_key` yazan admin kodu yok — grep tüm admin/ dizininde sıfır eşleşme).
4. Yeni deneme aynı anahtarı insert etmeye çalışır (gateway:275) ve sütun UNIQUE'tir (schema:126); `nicepay_save_transaction()` `$wpdb->insert` false dönünce sadece `false` döner (functions.php:379-385) — duplicate-key ayırt edilmez, kullanıcı "Payment initialization failed. Please try again." görür (gateway:296-301). Mesaj gerçekten yanıltıcı: tekrar denemek hiçbir zaman çalışmaz. Ek olarak order note'u "The order remains payable." der ki bu da yanlış.
5. Retention predicate'i (retention:307-315) hem `status='needs_reconciliation'`/`reconciliation_status='required'` hem `active_attempt_key IS NULL` şartlarıyla bu satırı sonsuza dek silinmez yapar — doğrulandı.

DÜZELTİLMESİ GEREKENLER:
a) Kök neden cron değil. Kilit, süreç öldüğü ANDAN itibaren tutulur; saatlik cron sadece etiketi 'approving' -> 'needs_reconciliation' yapar. Cron hiç çalışmasa da sipariş aynı şekilde bloke olurdu (hatta 'approving' etiketiyle, hiçbir uyarı bandı görünmeden). Yani başlıktaki "cron temizlemiyor" ifadesi semptomu doğru, nedeni eksik anlatıyor: hiçbir yol 'approving' satırın kilidini bırakmıyor.
b) Önerilen düzeltme (stale sorgusunda körlemesine `active_attempt_key = NULL`) GÜVENSİZ. Süreç PG onay çağrısı SIRASINDA ölmüşse para gerçekten yakalanmış olabilir; kilidi bırakmak müşterinin aynı siparişi ikinci kez ödemesine ve çifte tahsilata yol açar. Kilit, mutabakat bitene kadar bilinçli bir "fail-safe" olarak okunabilir (aynı mantık admin refund butonunda da uygulanmış: `$requires_reconciliation` refund'u engelliyor, admin:800-802). Doğru düzeltme: kilidi otomatik bırakmak değil, (i) duplicate-key durumunu ayırt edip doğru mesaj vermek, (ii) adminde satırı manuel çözümleyip kilidi bırakan bir aksiyon eklemek, (iii) retention'ın bu satırları sonsuza dek tutmasını operasyonel olarak kabullenmek/ayrı işaretlemek.
c) İstismar edilebilirlik/severity: senaryo saldırgan tarafından tetiklenemez, kullanıcı girdisiyle üretilemez; önkoşul PHP sürecinin tam onay penceresinde ölmesi (FPM timeout, OOM, deploy). Gerçekçi ama nadir, sipariş başına etki, veri kaybı/para kaybı yok, DB müdahalesiyle çözülebilir. Ayrıca bloke olma davranışının bir kısmı kasıtlı görünüyor. 'high' abartılı; UX/ops etkisi nedeniyle 'medium'.

---

### DATA-022 — Bloke olmuş migrasyon her istekte iki tam tablo taraması yapıyor; başarısız dbDelta sınırsız yeniden deneniyor, kilit yok

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | performance-resilience |
| **Konum** | [includes/class-nicepay-installer.php:138](../../../includes/class-nicepay-installer.php#L138) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
maybe_install() her istekte plugins_loaded@5'te çalışıyor (nicepay-payment-gateway.php:94) ve versiyon eskiyse şu iki sorgu her seferinde çalışıyor:

    $duplicates = (int) $wpdb->get_var( self::duplicate_moid_count_sql( $table ) );    // :139
    // SELECT COUNT(*) FROM (SELECT moid FROM t GROUP BY moid HAVING COUNT(*) > 1) AS ...
    $duplicate_tids = (int) $wpdb->get_var( self::duplicate_tid_count_sql( $table ) ); // :151

Her ikisi de tüm tabloyu tarayıp geçici tablo üreten GROUP BY sorguları. Duplicate bulunursa WP_Error dönüp erken çıkıyor (:140-161), yani VERSION_OPTION asla yazılmıyor ve bir sonraki istekte aynı iki tarama tekrarlanıyor — sonsuza kadar. Duplicate yoksa ama dbDelta hata verirse (:185-191) aynı döngü, bu kez ek olarak scrub'ın 4 UPDATE'i (:267-286, ikisi longtext üzerinde LIKE '%...%') ve dbDelta'nın tüm DESCRIBE/SHOW INDEX/ALTER seti her istekte yeniden koşuyor. Hiçbir yerde kurulum kilidi, exponential backoff veya 'bu sürüm için deneme sayısı' yok. İki PHP worker aynı anda dbDelta çalıştırabiliyor.
```

**Başarısızlık senaryosu**

800.000 satırlık bir ledger'da v1'den kalan iki adet moid='' satırı var (v1'de moid NOT NULL DEFAULT ''). Eklenti güncellenir. Her sayfa görüntülemesi 800k satırlık iki GROUP BY üretir; 50 eşzamanlı ziyaretçide MySQL tmp_table/CPU doygunluğuna girer ve tüm site (sadece ödeme değil) yavaşlar. Admin gördüğü tek şey: 'Review duplicate transaction references and the server error log.' — kaç grup olduğu, hangi moid'ler olduğu, nasıl düzeltileceği hiçbir yerde yazmıyor; hâlbuki WP_Error data'sında duplicate_groups sayısı zaten var (:146) ve notice'te kullanılmıyor (nicepay-payment-gateway.php:243-252).

**Etki**

Migrasyon bloke olduğu an site sadece ödeme alamaz hale gelmiyor, aynı zamanda her ön yüz isteği veritabanına iki tam tablo taraması bindiriyor. Bu bir arıza modunu (migrasyon durdu) bir kesintiye (DB doygunluğu) dönüştürüyor. Eşzamanlı dbDelta'lar ayrıca aynı ALTER'ı iki kez tetikleyip metadata lock birikmesine yol açabiliyor.

**Öneri**

1) Sonucu bir transient ile hızlandırın: bir kez başarısız olan bir hedef sürüm için sonraki denemeyi set_transient('nicepay_schema_retry_' . $target, 1, 15 * MINUTE_IN_SECONDS) ile geciktirin; transient varsa maybe_install() hemen saklı WP_Error'ı dönsün.
2) dbDelta çevresine kilit koyun (WP core'un WP_Upgrader::create_lock() deseni): INSERT IGNORE INTO wp_options ... /* LOCK */.
3) Duplicate taramasını sadece admin/aktivasyon bağlamında ya da LIMIT 1'li EXISTS sorgusuyla yapın: SELECT 1 FROM t GROUP BY moid HAVING COUNT(*) > 1 LIMIT 1.
4) schema_error_notice()'ı eyleme dönüştürün: duplicate_groups sayısını, örnek moid listesini ve düzeltme yönergesini gösterin; ayrıca current_user_can( 'manage_options' ) kontrolü ekleyin (configuration_notice bunu yapıyor, schema_error_notice yapmıyor).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Düzeltilmiş iddia: İddia geçerli. Tek düzeltme konum alanında: duplicate taramalarının satırları includes/class-nicepay-installer.php:139 (moid) ve :151 (tid); ":138" o sorguları saran `if ( $table_exists ) {` satırı. Diğer tüm referanslar (nicepay-payment-gateway.php:94, :243-252; installer :185-191, :267-286) dosyaların şu anki haliyle bire bir eşleşiyor.
- Gerekçe: İddianın her bileşenini kodda doğruladım; çürütecek bir koruma bulamadım.

1) Her istekte çalışma: `nicepay-payment-gateway.php:94` — `add_action( 'plugins_loaded', array( $this, 'maybe_install_schema' ), 5 )`. `is_admin()` guard'ı yok, `wp_doing_ajax`/cron istisnası yok; hook init_hooks() içinde koşulsuz kayıtlı (admin-only bloklar 123-127'de ayrı). Yani frontend dahil TÜM istekler `NicePay_Installer::maybe_install()` çağırıyor (`:202-204`).

2) Erken çıkış guard'ı iddiayı kurtarmıyor: `class-nicepay-installer.php:128` — erken return SADECE `$current === $target && $table_exists && $refund_table_exists` iken. Migrasyon bloke olduğunda VERSION_OPTION hiç yazılmadığı için (`:200` satırına asla ulaşılmıyor) bu koşul hiçbir zaman sağlanmıyor.

3) İki tam tablo taraması: `:139` ve `:151`, SQL'ler `:84` ve `:98`'de — `SELECT COUNT(*) FROM (SELECT moid FROM t GROUP BY moid HAVING COUNT(*)>1) AS ...`. Türetilmiş tablo + tam GROUP BY; LIMIT/EXISTS yok. Duplicate varsa `:140-149` ve `:152-161` WP_Error ile çıkıyor → sonsuz tekrar.

4) dbDelta hatası yolu: `:185-191` WP_Error dönüyor, VERSION_OPTION yazılmıyor. Bu yolda ek olarak `scrub_legacy_sensitive_data()` (`:163`, sorgular `:269-286`) her istekte tekrar koşuyor; `:278-285` gerçekten `payment_data LIKE '%"CardNo"%'` gibi sargable-olmayan LIKE'lar içeriyor.

5) Kilit/backoff/deneme sayacı yok: `grep -n "transient\|create_lock\|nicepay_schema_retry"` hem installer'da hem bootstrap'ta SIFIR sonuç. İki worker aynı anda dbDelta çalıştırabilir.

6) Notice zayıflığı: `nicepay-payment-gateway.php:243-252` sabit metni basıyor; `duplicate_groups` (`installer:146`, `:158`) hiç kullanılmıyor. Ayrıca `current_user_can( 'manage_options' )` kontrolü YOK — hemen altındaki `configuration_notice()` (`:258`) bu kontrolü yapıyor, yani tutarsızlık da doğru.

7) Senaryodaki legacy şema varsayımı da doğru: `git show origin/main:nicepay-payment-gateway.php:115` → `moid varchar(64) NOT NULL DEFAULT ''`, yani birden fazla boş moid satırı mümkün ve `:84`'teki sorgu boş stringleri özellikle kapsıyor.

Tek küçük düzeltme: `konum` alanında verilen `installer.php:138` satırı `if ( $table_exists ) {` bloğunun açılışı; asıl sorgular 139 ve 151. Bu, kanıt metninde zaten doğru verilmiş. Severity'yi high bırakıyorum: tetikleyici zaten arızalı bir durum (bloke migrasyon) olsa da, tasarım bu arızayı sınırsız tekrar eden tam tablo taramasıyla site geneli DB doygunluğuna yükseltiyor ve hiçbir sönümleme mekanizması yok.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Bloke olmuş migrasyon her istekte tekrar deneniyor; kilit, transient/backoff veya "bu sürüm için deneme sayısı" yok — bu doğru ve kodla kanıtlandı. Ancak istismar edilebilirlik/etki detayları abartılmış: (1) moid duplicate senaryosunda her istekte İKİ değil TEK sorgu tekrarlanıyor (installer :139'da erken dönüş var, :151 hiç çalışmıyor); iki sorgu ancak moid'ler temiz + TID'ler duplike olduğunda koşuyor. (2) Legacy tabloda `KEY idx_moid (moid)` ve `KEY idx_tid (tid)` mevcut (origin/main:nicepay-payment-gateway.php:139-142), dolayısıyla bunlar "tam tablo taraması" değil ikincil indeks taraması; GROUP BY indeks sırasından gelir ve materyalize olan türetilmiş tablo yalnızca duplike grupları tutar — "800k satırlık tmp_table doygunluğu" tablosu gerçekçi değil, gerçek maliyet istek başına ~800k girişlik indeks taraması + buffer pool baskısıdır. (3) "İki PHP worker aynı anda dbDelta çalıştırır" duplicate senaryosunda ULAŞILAMAZ bir yol: :140/:152 dönüşleri dbDelta'yı (:169) hiç çalıştırmaz; kilitsizlik gerçek ama penceresi güncelleme sonrası ilk birkaç istekle sınırlı ve ikinci ALTER "duplicate key name" ile başarısız olup bir sonraki istekte kendini toparlar. (4) scrub'ın 4 UPDATE'inin (ikisi longtext LIKE '%…%' ile gerçek tam tablo taraması) her istekte tekrarı yalnızca dbDelta hata verdiğinde olur — çok daha nadir bir önkoşul. Notice ile ilgili kısım tamamen doğru: schema_error_notice() (nicepay-payment-gateway.php:243-252) ne current_user_can('manage_options') kontrolü yapıyor ne de WP_Error data'sındaki duplicate_groups (:146) sayısını gösteriyor.
- Gerekçe: Kodu okudum; mekanizma doğru. maybe_install_schema her istekte plugins_loaded@5'te koşuyor (nicepay-payment-gateway.php:94, 202) ve NicePay_Installer::maybe_install() içinde hiçbir transient/kilit/deneme sayacı yok (grep 'transient|create_lock|GET_LOCK' → sıfır sonuç). VERSION_OPTION yalnızca :200'de, tüm kontroller geçtikten sonra yazıldığı için bloke durum kalıcı ve pahalı sorgu istek başına tekrarlanıyor — bu kısım confirmed.

Önkoşul gerçekçiliği: legacy şemada `moid varchar(64) NOT NULL DEFAULT ''` ve nicepay_save_transaction() defaults'unda `'moid' => ''` var (origin/main:includes/nicepay-functions.php:52), yani moid'siz iki kayıt tek bir duplike grup oluşturur — senaryonun tetiklenmesi makul. Tetikleyen "istek": herhangi bir anonim ön yüz sayfa görüntülemesi; özel rol veya nonce gerekmiyor. Bu yönüyle iddia geçerli.

Abartı noktaları: (a) duplicate-moid vakasında :139 sonrası hemen WP_Error dönüldüğü için :151 çalışmaz — "her istekte iki tam tablo taraması" yanlış; (b) her iki kolon da legacy şemada indeksli olduğundan sorgular indeks taramasıdır, GROUP BY için ayrı bir sıralama/temp gerektirmez ve türetilmiş tablo yalnızca duplike grup satırlarını materyalize eder; "MySQL tmp_table/CPU doygunluğu, tüm site yavaşlar" senaryosu bu haliyle üretilemez, ölçülebilir ama daha ılımlı bir ek yük olur; (c) eşzamanlı dbDelta iddiası, iddianın kendi başarısızlık senaryosunda ulaşılamaz bir kod yolu (dbDelta :169, erken dönüşlerden sonra gelir); (d) scrub'ın gerçek tam tablo taramaları yalnızca dbDelta hatası önkoşuluyla tekrarlanır.

Severity: kalıcı yeniden deneme + sınırsız tekrar gerçek bir dayanıklılık kusuru, ama (i) tetiklenmesi anormal bir migrasyon durumunu gerektiriyor, (ii) maliyet indeks taraması seviyesinde, (iii) zaten ödeme kesintisi olan bir durumda ek yük. "high" değil "medium". Öneri listesi (transient backoff, kilit, EXISTS ... LIMIT 1, notice'ı eyleme dönüştürme + yetki kontrolü) olduğu gibi geçerli ve uygulanabilir.

---

### DATA-003 — Migrasyon, tüm payment_data denetim kanıtını geri alınamaz şekilde siliyor ve engellenmiş durumda bunu her istekte tekrarlıyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | data-loss |
| **Konum** | [includes/class-nicepay-installer.php:277](../../../includes/class-nicepay-installer.php#L277) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
installer:277-286 tek bir LIKE listesine takılan her satırın payment_data sütununu tamamen NULL'a çekiyor: payment_data LIKE '%"CardNo"%' OR ... OR '%"BuyerEmail"%' OR '%"BuyerTel"%' OR '%"Signature"%'. BuyerEmail ve Signature pratikte her NICEPAY yanıtında bulunur, yani sonuç fiilen tüm geçmiş yanıt gövdelerinin silinmesidir. Oysa aynı kod tabanında satır bazında yeniden yazma için hazır bir allowlist var: nicepay_filter_payment_data() TID/Moid/Amt/AuthCode/ResultCode gibi mutabakat alanlarını koruyup gerisini atıyor (nicepay-functions.php:95-116). Bu dört UPDATE hiçbir LIMIT içermiyor (installer:267-286), transaction dışında çalışıyor, geri alınamıyor ve kullanıcıya hiçbir onay/yedek uyarısı gösterilmiyor. Dahası maybe_install her plugins_loaded'da çağrıldığı için (nicepay-payment-gateway.php:94) migrasyon sonradan herhangi bir nedenle tamamlanamazsa (bkz. DATA-001) bu tam tablo UPDATE'leri HER istekte yeniden çalışır.
```

**Başarısızlık senaryosu**

200.000 işlemli bir mağaza eklentiyi güncelliyor. DATA-001 nedeniyle dbDelta 1062 ile başarısız oluyor ve sürüm option'ı ilerlemiyor. Bundan sonra gelen HER istek (frontend dahil) sırayla: duplicate moid GROUP BY taraması, duplicate tid GROUP BY taraması ve 4 adet tam tablo UPDATE çalıştırıyor. İlk çalıştırmada tüm payment_data sütunları zaten NULL'lanmış oluyor; mağaza sahibi geri dönemiyor, çünkü ne yedek uyarısı ne de dışa aktarma adımı vardı.

**Etki**

Sıradan bir eklenti güncellemesi, mağazanın PG mutabakatı, chargeback savunması ve denetim için sakladığı ham sağlayıcı yanıtlarını kalıcı olarak yok ediyor. Milyon satırlık bir defterde dört adet indekssiz tam tablo UPDATE'i güncelleme isteğini timeout'a düşürebilir; migrasyon bloklu kalırsa bu yazma fırtınası her sayfa yüklemesinde tekrarlanır ve site fiilen kullanılamaz hale gelir.

**Öneri**

(1) Blob'u silmek yerine yeniden yazın: PHP tarafında 500'lük partiler halinde satırları okuyup json_decode + nicepay_filter_payment_data + wp_json_encode ile allowlist'lenmiş sürümü geri yazın; ayrıştırılamayan blob'ları NULL yapın. (2) Tüm scrub adımlarını LIMIT'li parti döngüsüne alın ve ilerlemeyi bir option'da tutun ki yarıda kesilen bir istek baştan başlamasın. (3) Migrasyon başlamadan önce admin'e bir kez gösterilen ve yedek almayı isteyen açık bir ekran/onay ekleyin veya en azından kaç satırın etkileneceğini önceden loglayın. (4) Migrasyon bir kez WP_Error döndürdüğünde bunu bir option'a yazıp (nicepay_schema_migration_blocked) sonraki isteklerde ağır sorguları atlayın; yeniden deneme sadece açık bir admin aksiyonu veya sürüm değişimiyle tetiklensin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Migrasyon, eski (PR öncesi) sürümün sakladığı ham `payment_data` blob'unu satır bazında filtrelemek yerine tamamen NULL'a çekiyor; LIMIT/parti/transaction/yedek uyarısı yok ve migrasyon dbDelta aşamasında bloke olursa bu indekssiz tam tablo taramaları HER istekte tekrarlanıyor. Ancak iddianın üç detayı yanlış/abartılı: (1) Kayıp "tüm denetim kanıtı" değil — mutabakat için gereken alanlar (tid, moid, amount, result_code, result_msg, card_code/name, bank_code/name, vbank_num, approved_at ...) ayrı sütunlarda korunuyor (class-nicepay-transaction-schema.php:55-113); silinen şey ham yanıtın yedek kopyası. Ayrıca yeni yazımlar zaten allowlist'ten geçtiği için (class-nicepay-gateway.php:580,861,911; class-nicepay-return-handler.php:174) etkilenen tek şey legacy satırlar. (2) "İlk çalıştırmadan sonra HER istekte yazma fırtınası" yanlış: UPDATE'ler idempotent, ilk turdan sonra WHERE hiçbir satırı eşlemiyor; tekrar eden maliyet yazma değil, tam tablo TARAMA maliyeti (payment_data üzerinde indeks yok, LIKE '%...%' zaten indekslenemez). (3) Başarısızlık senaryosundaki "duplicate moid nedeniyle dbDelta 1062" kurgusu scrub'a ulaşmaz: duplicate moid/tid kontrolleri scrub'dan ÖNCE erken return yapıyor (class-nicepay-installer.php:138-166); scrub yalnızca bu iki kontrol temiz geçtiğinde çalışır, dolayısıyla tekrar-eden-tarama senaryosu ancak dbDelta'nın başka bir nedenle (ör. ön kontrolü olmayan `uniq_active_attempt` unique indeksi, schema:126) hata vermesi hâlinde geçerlidir.
- Gerekçe: Kodu satır satır okudum. İddianın çekirdeği (geri alınamaz toplu NULL'lama, allowlist'li yeniden yazma seçeneği kullanılmamış, LIMIT/parti yok, transaction yok, yedek onayı yok, maybe_install her plugins_loaded'da çağrılıyor) doğrudur ve satır numaraları dosyanın şu anki haliyle eşleşiyor. Çürüten bir koruma bulamadım: ne bir admin onay ekranı, ne bir `nicepay_schema_migration_blocked` option'ı, ne de scrub öncesi bir sayım/log var; `schema_error_notice()` (nicepay-payment-gateway.php:243-252) yalnızca hata sonrası genel bir uyarı basıyor, veri kaybını önlemiyor. Buna karşılık üç detay abartılı: (a) mutabakat alanları ayrı sütunlarda hayatta kalıyor, yani "chargeback savunması yok oluyor" ifadesi zayıflıyor; (b) UPDATE'ler idempotent olduğundan tekrar eden istekler yeni veri kaybı üretmiyor, sadece tarama yükü üretiyor; (c) verilen somut başarısızlık senaryosu (duplicate moid → dbDelta 1062) kod akışıyla çelişiyor çünkü duplicate kontrolleri scrub'dan önce erken return ediyor. Ayrıca "BuyerEmail ve Signature pratikte her NICEPAY yanıtında bulunur" iddiası repoda kanıtlanamıyor (sağlayıcı davranışı hakkında dışsal varsayım) — ancak origin/main'in ham `$result`'ı olduğu gibi sakladığı doğrulandı, dolayısıyla legacy blob'ların geniş ölçüde eşleşmesi makul. Bu nedenle high değil medium.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Migrasyon, eski (main sürümünden gelen) ham NICEPAY yanıt gövdelerinin tamamını geri alınamaz şekilde NULL'lıyor: `LIKE '%"Signature"%'` koşulu, onay yanıtında Signature zorunlu olduğu için (class-nicepay-api.php:384-389) pratikte her eski satırı yakalar. Kayıp, ham gövde ve yalnızca orada saklanan AuthCode/AuthDate ile sınırlıdır — tid/moid/amount/result_code gibi mutabakat kolonları ayrı kolonlarda korunur. UPDATE'ler LIMIT'siz, transaction'sız ve batch'siz olduğundan büyük tablolarda PHP/lock timeout ile yarıda kesilip kısmi kayıp bırakabilir; aynı kod tabanında hazır `nicepay_filter_payment_data()` allowlist'i (nicepay-functions.php:95-116) kullanılarak yeniden yazma yerine tamamen silme tercih edilmiştir ve hiçbir yedek/onay adımı yoktur. Ayrıca migrasyon bir kez WP_Error dönerse bu durum bir option'a yazılmadığından (installer:141-161, 186-201; bootstrap:202-216) duplicate GROUP BY taramaları ve 4 LIKE taraması HER istekte tekrar çalışır — ancak ilk başarılı scrub'dan sonra bu tekrar YAZMA değil, indekssiz tam tablo OKUMA fırtınasıdır. İddiadaki "duplicate/1062 nedeniyle bloklanmış migrasyon verileri zaten silmiş olur" senaryosu üretilemez: duplicate moid/tid kontrolleri scrub'dan önce erken return eder (installer:138-163), dolayısıyla o durumda payment_data'ya hiç dokunulmaz.
- Gerekçe: ÇEKİRDEK DOĞRU, SENARYO VE ETKİ ABARTILI.

Doğrulanan kısım (kodu okudum):
1) `scrub_legacy_sensitive_data()` gerçekten LIMIT'siz, transaction'sız, tek seferde tüm tabloyu tarayan 4 UPDATE çalıştırıyor ve payment_data'yı tamamen NULL'a çekiyor (installer:267-286). Hata durumunda sadece WP_Error dönüyor, geri alma yok (288-297).
2) Fiilen "her satır" iddiası doğru — ama BuyerEmail üzerinden değil, `Signature` üzerinden. Legacy kod ham `$result` dizisini json_encode edip payment_data'ya yazıyordu (origin/main:includes/class-nicepay-return-handler.php:125 + nicepay-functions.php:103-105) ve onay yanıtında Signature ZORUNLU (class-nicepay-api.php:384-389 eksikse hard fail; docs/API-REFERENCE.md:165). Dolayısıyla `LIKE '%"Signature"%'` pratikte tüm eski onay gövdelerini yakalar. Buna karşılık `BuyerEmail` onay yanıtı alan listesinde yok (API-REFERENCE.md:154-186; BuyerEmail sadece auth REQUEST alanı, satır 82) — iddianın bu gerekçesi yanlış, sonucu değil.
3) Allowlist'li yeniden yazma alternatifi gerçekten aynı kod tabanında hazır (nicepay-functions.php:95-116) ve kullanılmıyor. Kayıp somut: şemada `auth_code`/`auth_date` KOLONU YOK (grep "auth_code" → schema'da hiç geçmiyor); AuthCode/AuthDate yalnızca payment_data JSON'unda yaşıyor. Yani eski satırlar için yetkilendirme kodu kalıcı olarak kayboluyor.
4) Yedek/onay ekranı yok; sadece CHANGELOG.md:43'te bir satır var ("scrubbed legacy PAN/token/raw response material during upgrade"). Yani davranış kasıtlı ve belgelenmiş, ama kullanıcı onayı alınmıyor.
5) Bloklu migrasyonun option'a yazılmaması doğru: maybe_install() hata yolunda hiçbir "blocked" flag'i yazmıyor, bootstrap sadece log + admin notice yapıyor (nicepay-payment-gateway.php:202-216), yani ağır sorgular her plugins_loaded'da tekrar çalışıyor.

ÇÜRÜTÜLEN kısım — failure_scenario üretilebilir DEĞİL:
a) Senaryo "DATA-001 nedeniyle dbDelta 1062 ile başarısız oluyor, ilk çalıştırmada tüm payment_data zaten NULL'lanmış oluyor" diyor. Kod sırası bunu imkânsız kılıyor: duplicate moid kontrolü (138-149) ve duplicate tid kontrolü (151-161) scrub'dan (163) ÖNCE çalışıp erken `return` ediyor. Duplicate varsa scrub HİÇ çalışmaz — payment_data silinmez. Yani "duplicate yüzünden bloklu + veri zaten yok olmuş" kombinasyonu bu kodda oluşamaz.
b) `active_attempt_key` unique index'i de 1062 üretemez: kolon `varchar(191) DEFAULT NULL` (schema:51) ve MySQL'de NULL'lar unique kısıtını tetiklemez.
c) "Her istekte 4 tam tablo UPDATE'i = yazma fırtınası" abartılı: ilk başarılı geçişten sonra WHERE koşulları 0 satır eşler (payment_data zaten NULL, tid zaten NULL, card_no zaten ''). Tekrarlayan maliyet indekssiz TAM TABLO TARAMASI (2 GROUP BY + 4 LIKE taraması), yazma değil. Bu hâlâ ciddi bir performans sorunu ama "site fiilen kullanılamaz hale gelir / yazma fırtınası" tanımı yanlış.

Gerçekten üretilebilir varyant (bunu koruyorum): 200k satırlık longtext tablosunda LIMIT'siz UPDATE, PHP max_execution_time / lock wait timeout ile ORTASINDA kesilebilir → satırların bir kısmı NULL'lanır, versiyon option'ı ilerlemez, sonraki istek aynı taramayı baştan yapar. Bu, yarım kalmış veri kaybı + her istekte tekrarlanan ağır tarama demektir. Ayrıca dbDelta'nın başka bir nedenle (ALTER lock timeout, disk) hata vermesi de aynı tekrar döngüsünü doğurur — sadece iddiadaki 1062 gerekçesiyle değil.

Severity: `high` → `medium`. Gerekçe: (i) mutabakat için kritik kolonlar (tid, moid, amount, result_code, result_msg, card_code/card_name, buyer_*, created_at) ayrı kolonlarda duruyor, silinmiyor — "tüm denetim kanıtı yok oluyor" doğru değil; kaybolan ham gövde + AuthCode/AuthDate. (ii) Silme kasıtlı bir PCI/PII hijyeni ve changelog'da yazılı. (iii) Belirtilen istismar/başarısızlık zinciri kod sırası nedeniyle üretilemiyor. Yine de geri alınamaz olması, allowlist yeniden yazma seçeneğinin göz ardı edilmesi ve batch/LIMIT yokluğu medium'u hak ediyor. Önerilerin (1)-(4) hepsi geçerli kalıyor.

---

### DATA-004 — Şema kapısı her istekte çalışıyor: kilit yok, ek sorgular var ve engellenmiş migrasyon sıcak döngüye giriyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | performance-concurrency |
| **Konum** | [nicepay-payment-gateway.php:202](../../../nicepay-payment-gateway.php#L202) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
maybe_install_schema, plugins_loaded önceliği 5 ile her istekte (frontend, AJAX, cron dahil) çağrılıyor (nicepay-payment-gateway.php:94, 202-216). maybe_install ise erken çıkış öncesinde her seferinde bir option okuması (VERSION_OPTION autoload=false olarak yazıldığı için persistent object cache yoksa ayrı bir sorgu, installer:200) ve iki adet SHOW TABLES LIKE sorgusu (installer:125-126 -> table_exists:228) yapıyor. Ayrıca migrasyonu serileştiren hiçbir kilit yok: iki PHP worker aynı anda güncel olmayan sürümü görüp aynı anda scrub + dbDelta çalıştırabiliyor. Sürüm eşitliği string karşılaştırmasıyla yapılıyor (installer:128 $current === $target), version_compare değil.
```

**Başarısızlık senaryosu**

Kampanya günü, iki PHP-FPM worker aynı anda güncellenmiş eklentiyi yüklüyor. Her ikisi de dbDelta çalıştırıyor; ikinci worker ADD UNIQUE KEY uniq_moid için 1061 Duplicate key name alıyor, maybe_install nicepay_schema_db_error döndürüyor ve o anda checkout sayfasını yükleyen müşteri için NicePay ödeme yöntemi listede görünmüyor. Ayrı olarak, duplicate moid nedeniyle bloklanmış bir mağazada 500.000 satırlık tabloda her istek iki GROUP BY taraması yapıyor ve site çöküyor.

**Etki**

Her sayfa yüklemesinde 2-3 gereksiz veritabanı sorgusu; yoğun bir mağazada ölçülebilir bir maliyet ve ödeme yolu ile alakasız isteklere yayılmış bir yük. Eşzamanlı iki worker aynı ALTER'ı denediğinde biri Duplicate key name hatası alır, o istek için WP_Error üretilir ve o istekte gateway is_available() false döner; müşteri checkout'ta NicePay'i göremez. Migrasyon kalıcı olarak bloklandığında ise her istek iki adet GROUP BY tam tablo taraması çalıştırır.

**Öneri**

maybe_install çağrısını bir kısa ömürlü kilitle sarın: if (!add_option('nicepay_schema_lock', time(), '', false)) { return; } ... delete_option(...) ve kilidi TTL ile kırın (retention'daki acquire_lock deseni zaten var, class-nicepay-retention.php:279-292; onu ortak bir yardımcıya çıkarın). Sürüm güncel olduğunda pahalı probe'ları atlamak için tablo varlık kontrolünü bir transient ile (örn. 1 saat) cache'leyin veya sadece is_admin() && current_user_can('activate_plugins') ile sınırlayın. Sürüm karşılaştırmasında yönü açıkça ele alın: downgrade durumunda (version_compare($current,$target,'>')) dbDelta çalıştırmak yerine sadece uyarı loglayın, çünkü dbDelta yeni sütun/indeksleri düşüremez ve option'ı geriye yazmak yanıltıcıdır.

---

### DATA-005 — 66 sütunluk tablo InnoDB COMPACT satır formatında oluşmaz; birleşik idx_source_ref 767 baytlık anahtar sınırını aşıyor ve ROW_FORMAT beyan edilmiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | schema-portability |
| **Konum** | [includes/class-nicepay-transaction-schema.php:203](../../../includes/class-nicepay-transaction-schema.php#L203) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
create_table_sql yalnızca get_charset_collate() ekliyor, ENGINE veya ROW_FORMAT beyan etmiyor (schema:203). utf8mb4 altında satır genişliği: 60'a yakın varchar/char sütunun toplamı yaklaşık 8.4 KB; buna ek olarak 5 adet TEXT/LONGTEXT sütun (result_msg, reconciliation_note, cancel_result_msg, net_cancel_result_msg, payment_data) COMPACT/REDUNDANT formatında satır içinde ilk 768 baytı + 20 baytlık işaretçiyi tutar, yani 5 x 788 bayt daha. Toplam yaklaşık 12 KB ve InnoDB'nin 16 KB sayfa için satır içi 8126 bayt sınırının çok üzerinde. Ayrıca idx_source_ref (flow, source_ref) = varchar(20) + varchar(191) utf8mb4 = 80 + 764 = 844 bayt ve bu, innodb_large_prefix kapalı COMPACT tablolarda 767 baytlık anahtar sınırını aşıyor (schema:50, 133). Entegrasyon testi yalnızca MariaDB 10.11 imajıyla çalışıyor (run-schema-migration.sh:11), yani DYNAMIC varsayılanı nedeniyle bu sınıf hataları hiç görmüyor.
```

**Başarısızlık senaryosu**

Merchant MySQL 5.6 + innodb_file_format=Antelope kullanan paylaşımlı bir hosting'te eklentiyi etkinleştiriyor. dbDelta CREATE TABLE'ı çalıştırıyor, MySQL ERROR 1118 (42000): Row size too large (> 8126) döndürüyor; maybe_install nicepay_schema_db_error veriyor, admin sadece NicePay payments are unavailable... mesajını görüyor ve sorunun kaynağını anlayamıyor. NicePay ödeme yöntemi checkout'ta hiç görünmüyor.

**Etki**

innodb_default_row_format=compact ile çalışan (MySQL 5.6/MariaDB 10.1 seviyesindeki yönetilen hosting'ler) sunucularda CREATE TABLE ya 1118 Row size too large ya da 1071 Specified key was too long hatasıyla düşer. maybe_install WP_Error döner, gateway hiç etkinleşmez ve kullanıcı sadece genel bir uyarı görür; hata mesajı satır formatı hakkında hiçbir ipucu vermez.

**Öneri**

(1) create_table_sql/create_refund_table_sql sonuna ROW_FORMAT=DYNAMIC ekleyin (dbDelta kapanış parantezinden sonrasını olduğu gibi bırakır) ve MySQL 5.6 için gereken innodb_file_format/innodb_large_prefix koşullarını README'ye yazın. (2) source_ref'i 191'den 100'e indirin veya indeksi KEY idx_source_ref (flow, source_ref(100)) olarak prefix'leyin. (3) Nadiren okunan büyük metin alanlarını (net_cancel_result_msg, cancel_result_msg, reconciliation_note) tek bir JSON detay sütununda birleştirerek satır genişliğini düşürün. (4) Entegrasyon test matrisine innodb_default_row_format=compact ile çalışan bir servis ekleyin veya en azından maybe_install içinde 1118/1071 hata kodlarını yakalayıp aksiyona dönüştürülebilir bir mesaj üretin.

---

### DATA-006 — created_at/updated_at DB oturum saat diliminde yazılıyor ama UTC gibi okunuyor; admin tarihleri, filtreler ve retention penceresi kayıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | correctness-timezone |
| **Konum** | [includes/class-nicepay-transaction-schema.php:112](../../../includes/class-nicepay-transaction-schema.php#L112) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
created_at ve updated_at DEFAULT CURRENT_TIMESTAMP / ON UPDATE CURRENT_TIMESTAMP ile tanımlı (schema:112-113) ve prepare_write bu sütunları çağırandan kabul etmediği için (schema:258) değerleri her zaman MySQL oturum saat dilimine göre üretiliyor; WordPress MySQL time_zone'unu ayarlamaz. Buna karşılık kod tabanındaki diğer tüm zaman damgaları gerçekten UTC: approved_at gmdate ile (return-handler:187, gateway:601), approval_started_at ve cancel_requested_at SQL UTC_TIMESTAMP() ile (nicepay-functions.php:678, 733). Okuma tarafı ise created_at'i UTC varsayıyor: admin listesi get_date_from_gmt($item->created_at) çağırıyor (admin/class-nicepay-transactions.php:793-795). Retention da UTC ile karşılaştırıyor: ledger.updated_at < (UTC_TIMESTAMP() - INTERVAL n DAY) (retention:307). Admin tarih filtresi ise yerel takvim gününü ham string olarak karşılaştırıyor (admin:339-342).
```

**Başarısızlık senaryosu**

Seul'deki mağazada MySQL time_zone SYSTEM (KST). 27 Ağustos 18:00 KST'de bir ödeme alınıyor; DB created_at = 2026-08-27 18:00. Admin listesi get_date_from_gmt ile bunu 2026-08-28 03:00 olarak gösteriyor. Merchant 2026-08-28 filtresi uyguluyor: FILTER_SQL created_at >= '2026-08-28 00:00:00' karşılaştırmasını yaptığı için işlem listede ÇIKMIYOR, ama filtre uygulanmadan bakıldığında 28 Ağustos tarihli görünüyor. Aynı satır CSV'de Created At=2026-08-27 18:00, Approved At=2026-08-27 09:00 (UTC) ile dışa aktarılıyor; yani aynı işlem sanki onaydan 9 saat sonra oluşturulmuş gibi duruyor.

**Etki**

Kore'deki bir mağazada (MySQL time_zone = SYSTEM = Asia/Seoul) admin listesindeki oluşturma zamanları 9 saat ileride gösteriliyor, tarih filtresi ile ekranda görünen tarih birbirini tutmuyor ve CSV export'ta created_at (yerel) ile approved_at (UTC) aynı satırda karışık saat diliminde çıkıyor. NICEPAY ekstresiyle mutabakat yapan muhasebe bu farkı elle düzeltmek zorunda kalıyor. Retention penceresi de aynı ofset kadar kayıyor; negatif ofsetli bir sunucuda kayıtlar politikadan saatlerce ERKEN siliniyor.

**Öneri**

Zaman damgalarını tek bir kaynağa bağlayın: created_at/updated_at için DB varsayılanlarını kaldırıp prepare_write allowlist'ine alın ve yazma anında gmdate('Y-m-d H:i:s') ile UTC yazın (updated_at'i her repository yazımında açıkça set edin). Alternatif olarak DB varsayılanları kalacaksa wpdb bağlantısında bir kez SET time_zone = '+00:00' çalıştırın ve bunu bir test ile sabitleyin. Admin tarih filtresini de get_gmt_from_date() ile yerel takvim gününü UTC aralığına çevirerek uygulayın, CSV başlıklarına (UTC) ekleyin.

---

### DATA-007 — Retention günlük 500 satırla sınırlı; büyük bir birikimde politika hiçbir zaman karşılanamıyor ve arayüz bunu söylemiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | retention-throughput |
| **Konum** | [includes/class-nicepay-retention.php:145](../../../includes/class-nicepay-retention.php#L145) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
run_scheduled en fazla MAX_BATCHES=5 parti x BATCH_SIZE=100 satır işliyor (retention:22-23, 145-157) ve cron günlük olarak planlanıyor (retention:120 wp_schedule_event(..., 'daily', ...)). Yani teorik üst sınır günde 500 satır. Admin ekranı uygun kayıt sayısını gösteriyor (admin/class-nicepay-admin.php:560-571) ama bu sayının ne kadar sürede eriyeceğine dair hiçbir bilgi vermiyor; ayrıca kilit alınamadığında ya da mode custom değilken run_scheduled sessizce 0 döndürüyor (retention:138) ve LAST_RUN_OPTION güncellenmiyor, yani hiç çalışmamakla 0 satır silmek arayüzde ayırt edilemiyor.
```

**Başarısızlık senaryosu**

Merchant KVKK/muhasebe onayı sonrası 365 gün retention açıyor. Arayüz Records currently eligible: 180,000 diyor. Bir yıl sonra denetimde 180.000 kaydın hala 179.500'ünün durduğu görülüyor; ne admin ekranında ne de logda bir uyarı var, çünkü her gün cron başarıyla 500 satır silip error_code boş bırakıyor.

**Etki**

Merchant yasal bir saklama politikası uyguladığını sanırken politika fiilen uygulanmıyor. 3 yıllık 200.000 satırlık bir defterde 365 günlük politikaya geçildiğinde birikimin erimesi 400 günden uzun sürüyor, üstelik bu sürede yeni satırlar da uygun hale geliyor; sistem asla yakalayamıyor. WP-Cron atlanan sitelerde (trafik yoksa) durum daha da kötüleşiyor.

**Öneri**

(1) Uygun kayıt sayısı günlük kapasitenin belirgin katıysa arayüzde tahmini süreyi ve uyarıyı gösterin: örn. printf('Yaklaşık %d gün sürecek (günlük en fazla %d kayıt).', ceil($count/(MAX_BATCHES*BATCH_SIZE)), MAX_BATCHES*BATCH_SIZE). (2) Parti sayısını sabit yerine adaptif yapın: bir zaman bütçesi (örn. 20 saniye) ve microtime kontrolü ile döngüyü sınırlayın, böylece boşta duran bir sitede tek çalıştırmada çok daha fazla satır temizlenir. (3) Kilit alınamadığında veya cron kayıtlı değilken LAST_RUN_OPTION'a bir durum yazın (skipped_locked / not_scheduled) ki arayüz sessiz başarısızlığı gösterebilsin. (4) Ayrıca bir manuel Şimdi çalıştır butonu ekleyin.

---

### DATA-008 — Retention transaction'ı InnoDB varsayıyor; farklı bir motorda ROLLBACK sessizce iade denetim kayıtlarını kalıcı olarak siliyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | data-loss |
| **Konum** | [includes/class-nicepay-retention.php:205](../../../includes/class-nicepay-retention.php#L205) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
purge_batch START TRANSACTION + SELECT ... FOR UPDATE + iki DELETE + COMMIT/ROLLBACK akışı kullanıyor (retention:205-249) ancak şema hiçbir yerde ENGINE=InnoDB beyan etmiyor (schema:203, sadece charset ekleniyor), yani tablo motoru sunucunun varsayılanına bırakılmış. Silme sırası da önce çocuk sonra ebeveyn: satır 231 DELETE FROM {refund_table} WHERE transaction_id IN (...), ardından satır 236 ana tablodan silme ve uyuşmazlıkta satır 242 ROLLBACK. Transaction desteklemeyen bir motorda (MyISAM/Aria) START TRANSACTION ve ROLLBACK sessizce etkisizdir ve wpdb->query bunlar için false döndürmez.
```

**Başarısızlık senaryosu**

Hosting'in varsayılan motoru MyISAM (veya tablolar eski bir yedekten MyISAM olarak geri yüklenmiş). Retention gece çalışıyor; SELECT 100 kayıt seçiyor, refund_attempts satırları siliniyor, ardından ikinci DELETE eşzamanlı bir güncelleme yüzünden 99 satır siliyor. count($ids) !== $deleted olduğu için kod ROLLBACK çağırıp nicepay_retention_delete_mismatch döndürüyor ve merchant her şeyin geri alındığını sanıyor; oysa 100 işlemin iade geçmişi kalıcı olarak yok olmuş durumda.

**Etki**

Motorun InnoDB olmadığı bir kurulumda, mutabakat uyuşmazlığı tespit edilip ROLLBACK çağrıldığında iade denetim kayıtları (nicepay_refund_attempts) çoktan kalıcı olarak silinmiş oluyor, ana işlem satırı ise duruyor. Sonuç: ödemesi duran ama iade geçmişi kaybolmuş, mutabakatı imkansız kayıtlar. FOR UPDATE de kilitlemediği için eşzamanlı bir iade talebi ile yarış durumu oluşur.

**Öneri**

(1) create_table_sql suffix'ine ENGINE=InnoDB ekleyin (dbDelta bunu sorunsuz taşır). (2) purge_batch başında motoru doğrulayın ve InnoDB değilse fail-closed dönün: SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=... ; sonuç InnoDB değilse WP_Error('nicepay_retention_engine_unsupported'). (3) Silme sırasını tersine çevirmek de savunma katmanı ekler: önce ana satırı sil, sonra yetim kalan refund satırlarını sil; böylece kısmi başarısızlıkta denetim kaydı değil, yalnızca yetim satır kalır.

---

### DATA-009 — Index kapsamı sorgu desenleriyle örtüşmüyor: admin defter sayfası ve GDPR araçları tam tablo taraması yapıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | query-performance |
| **Konum** | [includes/nicepay-functions.php:1036](../../../includes/nicepay-functions.php#L1036) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
nicepay_get_reconciliation_count() dört sütun üzerinde OR kullanıyor (nicepay-functions.php:1036-1040) ve bunlardan cancel_status ile net_cancel_status hiç indeksli değil (schema:122-138), yani sorgu her zaman tam tarama. Bu fonksiyon işlem listesi her render edildiğinde çağrılıyor (admin/class-nicepay-transactions.php:566). Aynı sayfa ayrıca filtrelenmiş COUNT(*) (nicepay-functions.php:1241-1245) ve GROUP BY currency ile üç SUM (admin:104-111) çalıştırıyor. Gizlilik exporter ve eraser ise buyer_email üzerinden sorguluyor (privacy:61, 123) ama buyer_email için index yok; üstelik eraser her sayfada 100 satırlık aynı sorguyu tekrarlıyor. Buna karşılık idx_vbank_expires_at (schema:136) hiç yazılmayan bir sütunu indeksliyor.
```

**Başarısızlık senaryosu**

500.000 işlemli bir mağazada admin NicePay Transactions sayfasını açıyor. reconciliation COUNT(*) OR sorgusu, filtreli COUNT(*) ve SUM/GROUP BY birlikte yaklaşık 3 tam tarama üretiyor; sayfa 10+ saniyede açılıyor. Aynı gün 4 GDPR silme talebi işlendiğinde eraser her sayfa için buyer_email üzerinde tam tarama yapıyor ve checkout istekleri yavaşlıyor.

**Etki**

Bir milyon satırlık defterde admin işlem sayfası her açılışta en az üç tam tablo taraması yapıyor; sayfa saniyelerce yükleniyor veya PHP zaman aşımına düşüyor. Bir GDPR silme talebi N sayfa boyunca N tam tarama üretiyor (O(n^2)) ve yoğun saatte DB'yi kilitliyor. Aynı anda kullanılmayan bir index yazma maliyeti ekliyor.

**Öneri**

(1) Mutabakat rozetini indekslenebilir tek bir sütuna dayandırın: needs_attention tinyint(1) türetilmiş bayrağı yazma anında set edin ve KEY idx_needs_attention (needs_attention) ekleyin; ya da OR'u dört ayrı indeksli EXISTS/UNION sorgusuna bölün. (2) buyer_email için KEY idx_buyer_email (buyer_email) ekleyin (varchar(100) utf8mb4 = 404 bayt, sınır altında). (3) Toplamları her render'da hesaplamak yerine kısa ömürlü bir transient ile cache'leyin veya yalnızca filtre uygulandığında gösterin. (4) Yazılmayan idx_vbank_expires_at indeksini kaldırın (bkz. DATA-010).

---

### DATA-010 — Ölü şema yüzeyi: hiç yazılmayan VBANK yaşam döngüsü sütunları ve eski indeksler kalıcı olarak taşınıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | schema-maintainability |
| **Konum** | [includes/class-nicepay-transaction-schema.php:95](../../../includes/class-nicepay-transaction-schema.php#L95) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
vbank_issued_at, vbank_expires_at, vbank_deposited_at (schema:95-97), binding_token_hash (schema:55) ve reconciliation_checked_at (schema:77) sütunları üretim kodunun tamamında hiçbir yerde yazılmıyor veya okunmuyor; grep sonucu bu isimler yalnızca şema dosyasında ve şema unit testinde geçiyor. Buna rağmen KEY idx_vbank_expires_at (vbank_expires_at) (schema:136) her zaman NULL olan bir sütunu indeksliyor. Aynı zamanda VBANK yöntemi ödeme formunda etkin gönderilebiliyor (gateway:346-347 VbankExpDate) ve is_success_code VBANK 4100 kodunu tanıyor, yani sanal hesap ödemeleri üretilebiliyor ama yatırma/son kullanma yaşam döngüsü ne kaydediliyor ne de bir cron ile takip ediliyor. Ayrıca dbDelta index düşüremediği için eski v1 indeksleri (idx_tid, idx_moid, idx_order_id, idx_status - origin/main:139-143) yükseltilmiş kurulumlarda yeni uniq_tid/uniq_moid/idx_status_created ile birlikte kalıcı olarak duruyor.
```

**Başarısızlık senaryosu**

Merchant VBANK yöntemini etkinleştiriyor. Müşteri sanal hesap numarası alıyor ama 3 gün içinde para yatırmıyor. Defterde vbank_expires_at NULL olduğu için hiçbir iş bu kaydı VBANK-özel bir durumla kapatamıyor; admin listesinde kayıt sadece expired/pending olarak görünüyor ve merchant sanal hesabın gerçekten kapanıp kapanmadığını eklentiden öğrenemiyor. Ayrı olarak, yükseltilmiş bir sitede her INSERT hem idx_tid hem uniq_tid, hem idx_moid hem uniq_moid güncelliyor.

**Etki**

Yükseltilmiş sitelerde tablo, aynı sütunları kapsayan çift indekslerle yazma başına gereksiz maliyet üretiyor; şemayı okuyan geliştirici hangi alanların gerçekten dolduğunu anlayamıyor. VBANK tarafında ise sanal hesap süresi dolan ödemeler için hiçbir otomatik durum geçişi yok; kayıt pending kalıp offer_expires_at ile expired oluyor, gerçek yatırma bildirimi ise hiç işlenmiyor.

**Öneri**

(1) Ya VBANK yaşam döngüsünü tamamlayın (yatırma bildirimi/geri dönüş alanlarını vbank_issued_at/vbank_expires_at içine yazın, süresi geçen sanal hesaplar için nicepay_expire_pending_transactions'a bir kural ekleyin) ya da bu sütunlarla birlikte idx_vbank_expires_at indeksini şemadan çıkarın ve VBANK'ı desteklenmeyen yöntem olarak açıkça işaretleyin. (2) binding_token_hash ve reconciliation_checked_at için de aynı kararı verin. (3) Installer'a idempotent bir index temizliği ekleyin: SHOW INDEX ile idx_tid/idx_moid/idx_order_id/idx_status varsa ve yerine geçen yeni index mevcutsa DROP INDEX çalıştırın (hata durumunda sessizce devam edin, migrasyonu bloklamayın).

---

### DATA-011 — uninstall.php ve veri silme yolu yok; kalıcı finansal tablolar ve option'lar kullanıcıya duyurulmuyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | data-lifecycle |
| **Konum** | [nicepay-payment-gateway.php:191](../../../nicepay-payment-gateway.php#L191) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Depoda uninstall.php yok ve register_uninstall_hook çağrısı da yok; deaktivasyon yalnızca cron'ları temizleyip rewrite kurallarını yeniliyor (nicepay-payment-gateway.php:191-195). Eklenti en az 14 option (nicepay-payment-gateway.php:271-285) ve iki tablo bırakıyor. Politika sadece geliştirici dokümanında bir cümle olarak var (docs/DEVELOPER-GUIDE.md:58: Deactivation and uninstall retain the financial ledger) ancak kullanıcıya görünen readme.txt sadece retention'dan bahsediyor (readme.txt:31-33), silme sonrası ne olacağını söylemiyor.
```

**Başarısızlık senaryosu**

Merchant NicePay ile çalışmayı bırakıp eklentiyi siliyor. Aylar sonra bir veri envanteri denetiminde wp_nicepay_transactions tablosunda hala binlerce buyer_email/buyer_tel kaydı bulunuyor; hangi eklentiden geldiği bilinmiyor, çünkü eklenti artık kurulu değil ve kaldırma sırasında hiçbir uyarı gösterilmemişti.

**Etki**

Eklentiyi kaldıran merchant, silinen eklentinin arkasında müşteri adı/e-posta/telefon içeren iki tabloyu ve tüm ayarları bıraktığını bilmiyor. Bu, WordPress.org yönergeleri ve KVKK/GDPR veri minimizasyonu açısından savunulması gereken bir durum ve tek belge, son kullanıcının hiç okumadığı bir geliştirici dokümanında.

**Öneri**

(1) readme.txt'ye net bir Uninstall / Data bölümü ekleyin: kaldırma sonrası hangi tabloların ve option'ların kaldığını, bunun neden (mali kayıt) bilinçli bir seçim olduğunu ve nasıl kalıcı olarak silinebileceğini yazın. (2) uninstall.php ekleyin ama varsayılan olarak veriyi KORUYUN; yalnızca nicepay_purge_on_uninstall option'ı açıkça yes ise tabloları ve option'ları düşürün. (3) Ayarlar ekranına, retention onay kutusuyla aynı ciddiyette ikinci bir onay isteyen bir Tüm NicePay verilerini kaldır aracı ekleyin (silme öncesi CSV export'a yönlendirin).

---

### DATA-012 — Şemadaki UNSIGNED büyük harfle yazıldığı için dbDelta her migrasyonda gereksiz ALTER TABLE CHANGE COLUMN üretiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | migration-performance |
| **Konum** | [includes/class-nicepay-transaction-schema.php:44](../../../includes/class-nicepay-transaction-schema.php#L44) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Şema tip anahtar sözcüklerini büyük harfle yazıyor: bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT (schema:44), bigint(20) UNSIGNED DEFAULT NULL (schema:47), smallint(5) UNSIGNED NOT NULL DEFAULT 0 (schema:73) ve refund tablosunda iki bigint UNSIGNED daha (schema:147-149). WP core dbDelta, SHOW COLUMNS çıktısındaki tipi (MySQL her zaman küçük harf döndürür: bigint(20) unsigned) sorgudan çıkardığı tiple büyük/küçük harf duyarlı olarak karşılaştırır ($tablefield->Type != $fieldtype) ve eşleşmediğinde ALTER TABLE ... CHANGE COLUMN üretir. WP core kendi şemasında (wp-admin/includes/schema.php) tam da bu nedenle bigint(20) unsigned ... auto_increment yazımını kullanır. MySQL 8.0.19+ display width'i kaldırdığı için (bigint unsigned) aynı sütunlar orada da her seferinde farklı görünür.
```

**Başarısızlık senaryosu**

2 milyon satırlık bir defteri olan mağaza yeni bir yama sürümüne geçiyor (şema sürümü 2026.08.24.7 -> .8). Gerçek değişiklik tek bir yeni varchar sütunu olsa da dbDelta ayrıca id ve wc_order_id için CHANGE COLUMN çalıştırıyor; MySQL tabloyu yeniden inşa ediyor, admin isteği 60 saniyede düşüyor, sürüm option'ı yazılmadığı için sonraki istek her şeyi baştan deniyor.

**Etki**

Her şema sürümü yükseltmesinde, gerçekte hiçbir şey değişmese bile birincil anahtar dahil 3-5 sütun için gereksiz ALTER TABLE CHANGE COLUMN çalışıyor. Büyük bir işlem tablosunda bu, eklenti güncelleme isteği sırasında dakikalarca sürebilen bir tablo yeniden yazımı ve kilitlenme demek; DATA-003'teki sınırsız UPDATE'lerle birleşince güncelleme isteği neredeyse kesin timeout'a gidiyor.

**Öneri**

Tüm tip anahtar sözcüklerini küçük harfe çevirin: 'bigint(20) unsigned NOT NULL auto_increment', 'bigint(20) unsigned DEFAULT NULL', 'smallint(5) unsigned NOT NULL DEFAULT 0'. Ek olarak NicePayTransactionSchemaTest'e bir regresyon testi ekleyin: create_table_sql çıktısında /\bUNSIGNED\b/ ve /\bAUTO_INCREMENT\b/ büyük harfli eşleşmesi olmamalı. MySQL 8 display-width farkını da düşünerek dbDelta sonrası dönen changes dizisini loglayıp beklenmedik CHANGE COLUMN'ları görünür kılın.

---

### DATA-014 — Retention, iade bakiyesi duran ödenmiş kayıtları da silmeye uygun sayıyor ve predicate'te ölü bir koşul var

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | retention-policy |
| **Konum** | [includes/class-nicepay-retention.php:307](../../../includes/class-nicepay-retention.php#L307) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
eligible_row_predicate silmeye uygunluk için status IN ('paid','partially_refunded',...) diyor (retention:308) ama remaining_amount hakkında hiçbir koşul içermiyor; yani iade bakiyesi tamamen duran, hiç iade edilmemiş ödemeler de MIN_DAYS=1 kadar kısa bir politikayla silinebiliyor (retention:24). Silindiğinde process_refund tid ile satırı bulamayıp Transaction ID not found dönüyor (gateway:702-706). Ayrıca aynı predicate'te satır 309'daki AND ledger.status <> 'needs_reconciliation' koşulu ölü kod: needs_reconciliation zaten satır 308'deki IN listesinde yok, dolayısıyla hiçbir zaman etkili değil ve okuyucuya yanlış bir güvence veriyor.
```

**Başarısızlık senaryosu**

Merchant 30 günlük retention açıyor. 32 gün önce ödenmiş, hiç iade edilmemiş 50.000 KRW'lik sipariş cron tarafından siliniyor. 35. günde müşteri tüketici hakları kapsamında iade istiyor; WooCommerce iade ekranındaki NicePay iadesi Transaction ID not found ile başarısız oluyor ve merchant iadeyi NICEPAY panelinden manuel yapmak zorunda kalıyor, WooCommerce ile mutabakat bozuluyor.

**Etki**

Merchant kısa bir saklama süresi seçtiğinde (örn. 30 gün) hala iade edilebilir durumdaki ödemelerin defter kaydı siliniyor ve o siparişler için eklenti üzerinden iade kalıcı olarak imkansız hale geliyor. Arayüzdeki uyarı bunu söylüyor ama sistem, tamamen iade edilebilir bakiye ile kapanmış bakiye arasında hiçbir ayrım yapmıyor.

**Öneri**

(1) Predicate'e bakiye koruması ekleyin: AND (ledger.remaining_amount = 0 OR ledger.status IN ('failed','abandoned','expired','cancelled')) veya en azından iade edilebilir bakiyesi olan satırlar için ayrı ve daha uzun bir minimum süre uygulayın (örn. MIN_DAYS_FOR_REFUNDABLE = 180). (2) Ölü koşulu (satır 309) kaldırın; korumak istenen davranış buysa needs_reconciliation kontrolünü status IN listesinin dışına, açık bir NOT IN olarak yazın. (3) Admin ekranındaki uygun kayıt sayısını iki parçaya ayırın: bakiyesi kapanmış olanlar ve hala iade edilebilir olanlar, böylece merchant riski görebilsin.

---

### DATA-015 — Migrasyon testleri gerçek v1 tablo şeklini hiç çalıştırmıyor; unit testlerdeki sahte wpdb her sorguya başarılı diyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | test-coverage |
| **Konum** | [tests/integration/schema-migration.php:34](../../../tests/integration/schema-migration.php#L34) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Entegrasyon testi taze bir v2 tablosundan başlıyor ve yükseltmeyi yalnızca üç yeni sütunu düşürerek simüle ediyor (schema-migration.php:34-43); yani gerçek v1 CREATE TABLE (origin/main:110-144) hiçbir zaman kurulmuyor. tid NOT NULL DEFAULT '' hali, boş TID'li satırlar, eksik currency/mid/mode/captured_amount ve eski idx_tid/idx_moid indeksleri hiç test edilmiyor. Unit tarafta test_existing_table_normalizes_empty_tid_and_scrubs_legacy_sensitive_data yalnızca sorgu metninin üretildiğini doğruluyor (NicePayInstallerTest.php:184-188 assertStringContainsString); sahte wpdb hiçbir MySQL kısıtını uygulamadığı için NOT NULL ihlali görünmüyor. Entegrasyon ortamı da tek bir modern MariaDB 10.11 imajı (run-schema-migration.sh:11), yani DYNAMIC satır formatı ve gevşek uyumluluk varsayımlarını test dışı bırakıyor.
```

**Başarısızlık senaryosu**

CI yeşil, tüm testler geçiyor ve sürüm yayınlanıyor. İlk gerçek v1 kullanıcısı güncelledikten sonra ödemeler duruyor; sorun ne unit ne entegrasyon testinde yakalanmıştı çünkü ikisi de v1 şemasını hiç kurmamıştı.

**Etki**

PR'ın en riskli iddiası (mevcut kurulumlar veri kaybetmeden yükselir) hiçbir testle desteklenmiyor. DATA-001 ve DATA-002 tam olarak bu boşlukta yaşıyor; her ikisi de üretimde ilk gerçek yükseltmede ortaya çıkacak.

**Öneri**

tests/integration/schema-migration.php'ye origin/main'deki CREATE TABLE metnini birebir kuran bir başlangıç adımı ekleyin (legacy_v1 fixture). Ardından sırayla doğrulayın: (a) boş TID'li 3 satır ekledikten sonra maybe_install WP_Error dönmemeli, (b) migrasyon sonrası SHOW COLUMNS FROM t LIKE 'tid' çıktısındaki Null alanı YES olmalı, (c) SELECT COUNT(*) FROM t WHERE tid='' sonucu 0 olmalı, (d) art arda iki nicepay_save_transaction çağrısı da başarılı olmalı, (e) eski paid satırın captured_amount ve currency değerleri backfill edilmiş olmalı ve process_refund ön kontrollerinden geçmeli. Ayrıca MySQL 8.0 ve innodb_default_row_format=compact varyantlarını matrise ekleyin.

---

### DATA-019 — idx_source_ref utf8mb4'te 844 bayt: eski InnoDB'nin 767 baytlık indeks ön ek limitini aşıyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | schema-compat |
| **Konum** | [includes/class-nicepay-transaction-schema.php:133](../../../includes/class-nicepay-transaction-schema.php#L133) |
| **Güven** | medium |
| **Doğrulama** | ⚠️ 1 kısmen, 1 çürütüldü |

**Sorun ve kanıt**

```php
Sütun ve indeks tanımları:

    'flow'       => self::COLUMN_VARCHAR_20,                       // :49  -> varchar(20)
    'source_ref' => "varchar(191) NOT NULL DEFAULT ''",            // :50
    ...
    'KEY idx_source_ref (flow, source_ref)',                        // :133

utf8mb4'te karakter başına 4 bayt: (20 + 191) * 4 = 844 bayt. InnoDB'nin ROW_FORMAT=COMPACT/REDUNDANT (veya innodb_large_prefix=OFF) altındaki indeks ön ek limiti 767 bayttır. Kodun kendisi bu limitin farkında: active_attempt_key tam olarak varchar(191) seçilmiş (191*4 = 764 < 767, :51) ve WordPress core da tam bu nedenle kendi indekslerini 191 karakterle sınırlar. Ama bileşik indeks bütçesi hesaplanmamış.
```

**Başarısızlık senaryosu**

MariaDB 10.1 + ROW_FORMAT=COMPACT bir paylaşımlı hostta eklenti 2.0.0'a yükseltilir. idx_source_ref oluşmaz. Ledger 200.000 satıra ulaştığında her checkout'ta nicepay_claim_transaction_for_approval'ın self-JOIN'li UPDATE'i (functions:665-684) tam tablo taraması + satır kilidi yapar; eşzamanlı iki ödeme birbirini kilitler, PG'nin ReturnURL çağrısı zaman aşımına uğrar ve tahsil edilmiş ödeme 'approving' durumunda asılı kalır.

**Etki**

MySQL 5.6, MariaDB < 10.2 veya innodb_default_row_format=COMPACT ayarlı barındırmalarda ALTER TABLE ... ADD KEY idx_source_ref hata verir. DATA-001 nedeniyle bu hata yutulur; sonuç, source_ref üzerinden çalışan tüm sorguların (nicepay_abandon_pending_transactions, claim sorgusundaki candidate.source_ref JOIN'i) indeksiz tam tablo taraması yapmasıdır — her ödeme denemesinde, kilit tutarken.

**Öneri**

Ya source_ref'i indekse girecek şekilde kısaltın (ör. varchar(100) -> (20+100)*4 = 480 bayt), ya da indeksi ön ek uzunluğuyla tanımlayın: 'KEY idx_source_ref (flow, source_ref(100))'. Ek olarak create_table_sql()'e utf8mb4 bayt bütçesi hesaplayan bir birim test ekleyin (her indeks için sum(len*4) < 767 assert'i) ve schema testine uniq_moid/uniq_tid assert'lerini de ekleyin (NicePayTransactionSchemaTest:58-70 bunları hiç kontrol etmiyor).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: `idx_source_ref (flow, source_ref)` utf8mb4'te 844 bayt tutar (schema:49-50, 133) ve InnoDB'nin eski 767 baytlık ön ek limitini aşar; şema bu bütçeyi hiçbir yerde hesaplamıyor ve bir test de doğrulamıyor. Ancak: (a) indeks UNIQUE değil, bu yüzden limitin geçerli olduğu ortamların varsayılanı olan `innodb_strict_mode=OFF` altında sorgu hata vermez, ön ek 767 bayta kırpılarak indeks yine de oluşur ve sorguları beslemeye devam eder; (b) strict mode açıkken oluşan hata sessizce yutulmaz — installer:185-198'deki `$wpdb->last_error` ve `table_exists` kontrolleri fresh install'da kesin, upgrade'de ise sonraki başarılı refund dbDelta'sının last_error'ı temizlemesi nedeniyle dar bir maskeleme penceresiyle yakalar; (c) etkilenen ortamlar MySQL 5.6 / MariaDB < 10.2 (COMPACT/REDUNDANT) ile sınırlıdır. Bu nedenle bulgu, "her checkout'ta tam tablo taraması ve asılı kalan tahsil edilmiş ödeme" felaket senaryosu değil, taşınabilirlik/şema-hijyeni kusurudur; düzeltme (`source_ref(100)` ön eki veya sütun daraltma) ve bayt bütçesi birim testi yine de yapılmalıdır.
- Gerekçe: Kod okundu; iddianın ÇEKİRDEĞİ (satır numaraları + bayt aritmetiği) doğru: `flow` varchar(20) + `source_ref` varchar(191), utf8mb4'te (20+191)*4 = 844 bayt > 767 baytlık eski InnoDB ön ek limiti. Tablo gerçekten `$wpdb->get_charset_collate()` (utf8mb4) ile oluşturuluyor (installer:180-181), ve testte uniq_moid/uniq_tid assert'i gerçekten yok (SchemaTest:58-70). Bu kadarı doğrulandı.

Ancak ETKİ ve SEVERITY zinciri üç noktada çürütülüyor:

1) "DATA-001 nedeniyle hata yutulur" premisi kodla çelişiyor. Installer, dbDelta çağrılarından sonra `$wpdb->last_error` kontrolü yapıp WP_Error döndürüyor (class-nicepay-installer.php:185-191) ve ayrıca her iki tablonun varlığını doğruluyor (:193-198). Fresh install'da bozuk `KEY` satırı tüm CREATE TABLE'ı düşürür, bu da table_exists kontrolüne takılır — sessiz "indekssiz tablo" durumu oluşmaz. Yalnızca upgrade yolunda kısmi bir maskeleme penceresi var: transactions dbDelta'sının hatası, hemen ardından çalışan başarılı refund dbDelta'sı tarafından `last_error` sıfırlandığı için gözden kaçabilir (:182-184 sırası). Yani "yutulur" iddiası genel değil, dar ve koşullu.

2) `idx_source_ref` UNIQUE değil, düz `KEY`. InnoDB'de ön ek limiti aşan NON-UNIQUE indeksler, `innodb_strict_mode` KAPALI iken (MySQL 5.6 ve MariaDB < 10.2.2 varsayılanı tam olarak budur — senaryonun kendi hedeflediği ortam) hata değil, uyarı üretir ve ön ek 767 bayta kırpılarak indeks yine de oluşturulur. Bu durumda indeks var olmaya devam eder ve flow(80 bayt) + source_ref'in ilk ~171 karakteri üzerinden aynı sorguları besler. Dolayısıyla "indeks hiç oluşmaz -> tam tablo taraması -> 200.000 satırda checkout kilitlenmesi -> tahsil edilmiş ödeme 'approving'de asılı kalır" zinciri, iddianın kendi seçtiği MariaDB 10.1 + COMPACT senaryosunda büyük olasılıkla GERÇEKLEŞMEZ. Hard failure yalnızca strict mode AÇIK olan eski kurulumlarda mümkün ve orada da (1) nedeniyle görünür bir WP_Error olarak yüzeye çıkar.

3) Etkilenen ortamlar MySQL 5.6 (EOL 2021) ve MariaDB 10.1 (EOL 2020) ile sınırlı; MySQL 5.7.9+ ve MariaDB 10.2+ varsayılanı `innodb_default_row_format=DYNAMIC` + large_prefix=ON (3072 bayt) olduğu için sorun oluşmaz. Ayrıca source_ref pratikte kısa değerler alıyor (WC order id — gateway:274, config_id — offer-resolver:156), bu da kırpılmış ön ekin seçiciliğini tam tutar.

Sonuç: gerçek ama dar kapsamlı bir taşınabilirlik/şema-hijyeni kusuru; önerilen düzeltme (`source_ref(100)` ön eki veya sütun daraltma + bayt bütçesi birim testi) geçerli. "high" değil, "medium".

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `refuted`)

- Severity düzeltmesi: `medium` → `info`
- Düzeltilmiş iddia: idx_source_ref (flow, source_ref) hiçbir MySQL/MariaDB sürümünde veya row_format'ında 767 baytlık sınırı ihlal etmez; 767 sınırı sütun başınadır (source_ref 764 bayt, flow 80 bayt) ve bileşik anahtarın toplam sınırı 3072 bayttır. Geriye kalan tek gerçek eksik, NicePayTransactionSchemaTest:58-70'in uniq_moid ve uniq_tid indekslerini assert etmemesidir (test kapsamı boşluğu, severity: info).
- Gerekçe: İddianın çekirdek varsayımı yanlış: InnoDB'nin 767 baytlık sınırı **bileşik indeksin toplam uzunluğuna değil, indeksteki HER SÜTUNUN kendi ön ek uzunluğuna** uygulanır. MySQL kılavuzu (Limits on InnoDB Tables) bunu açıkça ayırır: "The index key prefix length limit is 767 bytes for InnoDB tables that use the REDUNDANT or COMPACT row format" ve ayrıca "The InnoDB internal maximum key length is 3500 bytes, but MySQL itself restricts this to 3072 bytes. This limit applies to the length of the combined index key in a multi-column index." Yani çok sütunlu bir indeksin toplam bütçesi 3072 bayttır, 767 değil. Uygulama tarafında da kontrol per-field yapılır (InnoDB create_index() içinde her alanın col_len'i DICT_MAX_FIELD_LEN_BY_FORMAT ile karşılaştırılır ve "Specified key was too long; max key length is 767 bytes" hatası tek sütun ön eki için üretilir).

Bu ölçütle `KEY idx_source_ref (flow, source_ref)` her yapılandırmada geçerlidir:
- flow varchar(20) utf8mb4 -> 80 bayt (< 767)
- source_ref varchar(191) utf8mb4 -> 764 bayt (< 767)
- toplam anahtar 844 bayt (< 3072 toplam sınır; MyISAM'in 1000 baytlık toplam sınırının bile altında)

Dolayısıyla iddiadaki "(20+191)*4 = 844 > 767 => ALTER TABLE hata verir" çıkarımı, per-column sınırı toplam sınırla karıştırmaktan kaynaklanıyor. Kodun varchar(191) seçimi zaten doğru olan per-column mantığını (WordPress core ile aynı gerekçe) uyguluyor; "bileşik indeks bütçesi hesaplanmamış" eleştirisi hesaplanacak bir bütçe olmadığı için geçersiz.

İstismar edilebilirlik/sonuç açısından: failure_scenario'nun ilk adımı (MariaDB 10.1 + ROW_FORMAT=COMPACT üzerinde idx_source_ref oluşmaz) üretilemez, dolayısıyla ardından gelen zincir (indeksiz tam tablo taraması -> claim UPDATE'inde kilit -> ReturnURL timeout -> 'approving' asılı kalma) hiç tetiklenmez. Ulaşılamaz bir senaryo.

Şemadaki hiçbir indeks per-column 767 sınırını aşmıyor (uniq_active_attempt varchar(191) = 764; char(64) kolonlar 256 bayt; diğerleri daha kısa), yani önerilen "her indeks için sum(len*4) < 767" birim testi eklenirse mevcut geçerli şemayı yanlış yere kırmızıya düşüren hatalı bir test olur.

Geriye kalan tek geçerli ama önemsiz kırıntı, önerinin son cümlesi: NicePayTransactionSchemaTest::test_indexes_cover_operational_queries (satır 58-70) uniq_moid ve uniq_tid'i assert etmiyor. Bu ayrı, "low/info" seviyesinde bir test kapsamı notudur; DATA-019'un iddia ettiği şema uyumluluk hatası değildir.

Ölçmeyi bir MySQL/MariaDB örneğiyle ampirik doğrulamak istedim (docker ve mysql istemcisi bu makinede yok), ancak per-column vs. combined-key ayrımı kılavuzda açık şekilde belgeli olduğu için karar kesin.

---

### DATA-023 — Retention saati yanlış zaman diliminde ve yanlış sütunda: 'N gün' aslında 'son değişiklikten N gün' ve DB tz'sine kayıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | retention-correctness |
| **Konum** | [includes/class-nicepay-retention.php:307](../../../includes/class-nicepay-retention.php#L307) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Silme uygunluk kriteri:

    return "ledger.updated_at < (UTC_TIMESTAMP() - INTERVAL {$days} DAY)   // :307

Ama updated_at değerini PHP yazmıyor: prepare_write() bu sütunu 'database_owned' olarak düşürüyor (schema:258) ve sütun 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' (schema:113). MySQL'in CURRENT_TIMESTAMP'i oturumun time_zone ayarını kullanır — WordPress bu değeri hiç set etmez, yani DB sunucusunun yerel saatidir. Eklentinin PHP tarafı ise her datetime'ı gmdate() ile UTC yazıyor (ör. offer_expires_at, gateway:283; approved_at, gateway:601) ve nicepay_expire_pending_transactions bunları doğru şekilde UTC_TIMESTAMP() ile karşılaştırıyor (functions:962). Yani tek tutarsız sütun tam da retention'ın dayandığı sütun.

İkinci sorun: kriter created_at değil updated_at. UI ise şunu vaat ediyor: 'Permanently delete eligible NicePay financial records after [N] days' (admin/class-nicepay-admin.php:534).

Entegrasyon testi bu hatayı maskeliyor çünkü updated_at'i elle UTC ile yazıyor:

    UPDATE {$table} SET updated_at = UTC_TIMESTAMP() - INTERVAL 400 DAY WHERE id IN (...)   // tests/integration/schema-migration.php:269
```

**Başarısızlık senaryosu**

(a) DB sunucusu time_zone='America/Los_Angeles' (UTC-8) ve merchant GDPR gereği 30 günlük politika seçmiş. updated_at UTC'den 8 saat geride yazıldığı için satırlar politikanın söylediğinden 8 saat ERKEN kalıcı olarak siliniyor — 1 günlük politikada bu %33'lük bir sapma. (b) 365 günlük politika seçilmiş; 300. günde bir mutabakat notu yazıldığı için (nicepay_update_transaction) updated_at sıfırlanıyor ve kayıt 665. güne kadar yaşıyor; merchant 'kayıtlarım 1 yıl sonra siliniyor' beyanını denetimde ispat edemiyor.

**Etki**

Silme zamanı DB sunucusunun UTC ofseti kadar kayıyor ve saklama süresi 'işlem tarihi' değil 'son dokunuş tarihi' üzerinden hesaplanıyor. Her ikisi de hukuki/uyum amaçlı bir politikada yanlış davranış; özellikle MIN_DAYS=1 ile ofset oransal olarak çok büyük.

**Öneri**

1) updated_at'i DB'ye bırakmayın: prepare_write()'ın database_owned listesinden çıkarıp her yazmada gmdate('Y-m-d H:i:s') ile açıkça yazın (ON UPDATE CURRENT_TIMESTAMP'i kaldırın), böylece tüm datetime sütunları tek bir zaman ekseninde olur.
2) Uygunluk kriterini 'ne zaman oluştu' semantiğine taşıyın: GREATEST(ledger.created_at, COALESCE(ledger.approved_at, ledger.created_at)) veya doğrudan created_at kullanın; hangisi seçilirse UI metni ve DEVELOPER-GUIDE aynı ifadeyi kullansın.
3) Entegrasyon testinde updated_at'i elle yazmak yerine gerçek yazma yolundan geçirip tz farkını yakalayan bir assert ekleyin.

---

### DATA-024 — Retention kilidi atomik değil: add_option() yarış koşulunu engellemiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | concurrency |
| **Konum** | [includes/class-nicepay-retention.php:281](../../../includes/class-nicepay-retention.php#L281) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
private static function acquire_lock() {
        $now = time();
        if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {   // :281
            return true;
        }
        ...

WordPress'in add_option() fonksiyonu, var olup olmadığını önce bir get_option() ile kontrol eder, sonra 'INSERT ... ON DUPLICATE KEY UPDATE' çalıştırır. Bu iki adım arasında atomiklik yoktur ve INSERT ON DUPLICATE KEY UPDATE zaten çakışma durumunda başarılı döner. WP core, tam bu nedenle gerçek kilitler için add_option kullanmaz; WP_Upgrader::create_lock() doğrudan "INSERT IGNORE INTO `$wpdb->options` (...) /* LOCK */" sorgusunu çalıştırır ve etkilenen satır sayısına bakar.
```

**Başarısızlık senaryosu**

Bir sunucuda hem WP-Cron hem de sistem cron'u (wp cron event run --due-now) kuruludur. İkisi aynı dakikada nicepay_apply_financial_retention'ı tetikler. İkisi de add_option'dan true alır. A batch'i FOR UPDATE ile kilitler ve siler; B aynı id'leri seçemez veya sildiği satır sayısı uyuşmaz, ROLLBACK eder ve run_scheduled B'nin sonucunu LAST_RUN_OPTION'a yazar (A'nınkinin üzerine). Admin ekranı 'deleted: 0; error: nicepay_retention_delete_mismatch' gösterirken gerçekte 100 kayıt silinmiştir — operatör paniğe kapılır ya da daha kötüsü, gerçek bir mismatch'i gürültü sanır.

**Etki**

İki eşzamanlı cron/WP-CLI çağrısı aynı anda kilidi 'alabilir' ve ikisi de purge_batch döngüsüne girer. Veri güvenliği SELECT ... FOR UPDATE + satır sayısı doğrulaması sayesinde korunur, ama kaybeden taraf nicepay_retention_delete_mismatch WP_Error'ı üretir; bu, LAST_RUN_OPTION'a error_code olarak yazılır ve admin ekranında 'error: nicepay_retention_delete_mismatch' olarak görünür (admin:592-596) — operatöre gerçek bir veri sorunu varmış izlenimi verir.

**Öneri**

Kilidi doğrudan atomik bir INSERT ile alın:

    $lock = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
        self::LOCK_OPTION, (string) $now
    ) );
    if ( 1 !== (int) $lock ) { /* stale TTL kontrolü, sonra false */ }

Ayrıca LAST_RUN_OPTION yazımını yalnızca kilidi gerçekten alan tarafın yapmasını sağlayın ve mismatch hatasını 'concurrent_run' gibi ayrı bir kodla ayırt edin.

---

### DATA-025 — purge_batch'te veritabanı hatası sessizce 'silinecek kayıt yok' olarak raporlanıyor (ölü kod kontrolü)

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | error-handling |
| **Konum** | [includes/class-nicepay-retention.php:219](../../../includes/class-nicepay-retention.php#L219) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
$rows = $wpdb->get_col( "SELECT ledger.id FROM {$transaction_table} AS ledger WHERE {$where} ... FOR UPDATE" );  // :210-217

    if ( ! is_array( $rows ) ) {                     // :219
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'nicepay_retention_select_failed', ... );
    }

    $ids = array_values( array_unique( array_filter( array_map( 'absint', $rows ) ) ) );
    if ( empty( $ids ) ) {
        $wpdb->query( 'COMMIT' );
        return 0;                                    // :227
    }

wpdb::get_col() HER ZAMAN dizi döndürür: hata durumunda last_result boş kalır ve fonksiyon $new_array = array() döner, false dönmez. Yani :219'daki kontrol erişilemez ölü koddur ve her SELECT hatası :225-228'deki 'boş sonuç' dalına düşer — COMMIT edilir ve fonksiyon 0 döner, yani 'silinecek uygun kayıt yok' anlamına gelir.
```

**Başarısızlık senaryosu**

Bir ops hatası sonucu wp_nicepay_refund_attempts tablosu düşürülür (installer bir sonraki istekte onu yeniden yaratır, ama kısa bir pencere vardır) ya da innodb_lock_wait_timeout aşılır. purge_batch'in SELECT'i hata verir, get_col boş dizi döner, COMMIT edilir, 0 döner; run_scheduled ilk batch'te $result(0) < BATCH_SIZE(100) olduğu için döngüyü kırar ve LAST_RUN_OPTION'a error_code='' yazar. Merchant, GDPR silme politikasının çalıştığını sanır; hiçbir kayıt hiç silinmemiştir.

**Etki**

Bozuk bir sorgu (eksik nicepay_refund_attempts tablosu, bozulmuş indeks, lock wait timeout, unknown column) hiçbir hata üretmeden 'temizlik başarıyla çalıştı, 0 kayıt' olarak raporlanır. Retention politikası aylarca hiç çalışmadan 'çalışıyor' görünür ve admin ekranındaki 'Last cleanup ... deleted: 0; error: none' satırı bunu doğrular gibi görünür (admin:587-599).

**Öneri**

get_col'un dönüş değerine değil, wpdb'nin hata durumuna bakın:

    $wpdb->suppress_errors( false );
    $rows = $wpdb->get_col( $sql );
    if ( '' !== (string) $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'nicepay_retention_select_failed', ..., array( 'database_error' => $wpdb->last_error ) );
    }

Aynı deseni count_eligible() için de uygulayın: orada da get_var() hatası null yerine null-benzeri davranıp UI'da 'sayı hesaplanamadı' ile karışıyor (:269-275). Ayrıca 'hiç silinecek yok' ile 'hata' durumlarını LAST_RUN_OPTION'da ayrı alanlarla raporlayın.

---

### DATA-026 — Retention verimi günde 500 satırla sınırlı: politika birikmiş veride asla tamamlanmıyor ve bu hiçbir yerde uyarılmıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | retention-scalability |
| **Konum** | [includes/class-nicepay-retention.php:22](../../../includes/class-nicepay-retention.php#L22) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
const BATCH_SIZE  = 100;   // :22
    const MAX_BATCHES = 5;     // :23

    for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {          // :145
        $result = self::purge_batch( $settings['days'], self::BATCH_SIZE );
        ...
        if ( $result < self::BATCH_SIZE ) { break; }
    }

Cron 'daily' olarak kuruluyor (:120). Yani üst sınır 5 * 100 = 500 satır/gün. purge_batch ayrıca $limit > BATCH_SIZE olan çağrıları reddediyor (:198), bu yüzden bir operatör WP-CLI ile bile daha hızlı temizlik yapamıyor. Admin ekranı 'Records currently eligible: N' gösteriyor (admin:566-570) ama N'in ne kadar sürede işleneceğine dair hiçbir bilgi vermiyor.
```

**Başarısızlık senaryosu**

Günde 800 sipariş alan bir mağaza uyum gereği 90 günlük saklama seçer. Geçmişten 250.000 uygun kayıt vardır. Günlük 500 silme kapasitesine karşılık günlük 800 yeni uygun kayıt oluşur; birikim her gün 300 satır büyür. Admin ekranı 'Records currently eligible: 250.000' der ve ertesi gün 250.300 der. Merchant, DPO'suna 90 günlük politikanın uygulandığını beyan eder; gerçekte 3 yıllık veri duruyordur.

**Etki**

Retention, orta ölçekli bir mağazada bile pratikte yerine getirilmeyen bir vaat haline geliyor. Günlük yeni uygun kayıt sayısı 500'ü aşan bir mağazada birikim asla kapanmaz; politika kalıcı olarak ihlal edilir ve hiçbir uyarı üretilmez.

**Öneri**

1) Birikimi görünür kılın: admin açıklamasına tahmini bitiş süresini ekleyin (ör. ceil($eligible / (BATCH_SIZE*MAX_BATCHES)) gün) ve birikim > 1 günlük kapasiteyse admin_notice ile uyarın.
2) Cron'u 'twicedaily'/'hourly' yapmak yerine adaptif hale getirin: uygun kayıt sayısı yüksekken MAX_BATCHES'i yükseltin (ör. çalışma süresi bütçesi: 20 saniyeyi aşana kadar batch al).
3) Operatör için bounded ama daha hızlı bir manuel/WP-CLI yolu ekleyin ve purge_batch'in $limit üst sınırını parametrik yapın.

---

### DATA-027 — Büyük harfli 'UNSIGNED' ve display-width kullanımı dbDelta'yı her migrasyonda gereksiz ALTER TABLE üretmeye zorluyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | schema-hygiene |
| **Konum** | [includes/class-nicepay-transaction-schema.php:44](../../../includes/class-nicepay-transaction-schema.php#L44) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
'id'                => 'bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT',   // :44
    'wc_order_id'       => 'bigint(20) UNSIGNED DEFAULT NULL',              // :47
    'approval_attempts' => 'smallint(5) UNSIGNED NOT NULL DEFAULT 0',       // :73
    // refund tablosunda da aynısı: :147-149

dbDelta (wp-admin/includes/upgrade.php) sütun tipini şu regex'le çıkarır: preg_match( '|^([^ ]*( unsigned)?)|i', $fielddef, $matches ) ve ardından mevcut tiple KÜÇÜK-BÜYÜK HARFE DUYARLI karşılaştırır: if ( $tablefield->Type != $fieldtype ). MySQL'in SHOW COLUMNS çıktısı her zaman küçük harflidir ('bigint(20) unsigned'), tanım ise 'bigint(20) UNSIGNED'. Eşitsizlik her zaman doğrudur. WP core kendi şemasında (wp-admin/includes/schema.php) tam bu nedenle küçük harfli 'bigint(20) unsigned' yazar.

İkinci tetikleyici: MySQL 8.0.19+ tamsayı display-width'i kaldırır; SHOW COLUMNS 'bigint unsigned' döner, tanım ise 'bigint(20) UNSIGNED' — yine sonsuz uyuşmazlık.
```

**Başarısızlık senaryosu**

1,2 milyon satırlık bir ledger'ı olan mağaza 2.0.0'dan sonraki bir yamayı (yeni schema VERSION) kurar. plugins_loaded@5'te ön yüz isteği içinde dbDelta çalışır ve 'ALTER TABLE wp_nicepay_transactions CHANGE COLUMN `id` id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT' üretir. Tablo tamamen yeniden yazılırken (birkaç dakika) tabloya yazan tüm ödeme istekleri metadata lock'ta bekler; PHP timeout'ları tetiklenir ve ödemeler 'approving' durumunda asılı kalır — DATA-004'ün senaryosunu kitlesel olarak üretir.

**Etki**

Her şema sürümü yükseltmesinde bu 3 (+3 refund) sütun için gereksiz 'ALTER TABLE ... CHANGE COLUMN' üretilir. AUTO_INCREMENT birincil anahtar üzerinde CHANGE COLUMN, MySQL 5.7'de tabloyu tamamen yeniden yazar (kopyalama algoritması) — büyük bir ledger'da dakikalarca metadata lock ve I/O demektir. Ayrıca DATA-001 ile birleşince, bu ALTER'lardan biri fail ederse hata yutulur.

**Öneri**

Tip tanımlarını MySQL'in normalize ettiği biçimde yazın: 'bigint(20) unsigned', 'smallint(5) unsigned'. MySQL 8.0.19+ ile de uyum için display-width'i tamamen bırakmayı değerlendirin ('bigint unsigned') veya en azından migrasyonu ön yüz isteğinden ayırın (admin/aktivasyon/WP-CLI bağlamına taşıyın, bkz. DATA-005). Ek olarak dbDelta'nın döndürdüğü $changes dizisini loglayın: beklenmedik CHANGE COLUMN'lar hemen görünür olur.

---

### DATA-028 — Migrasyon eski payment_data audit yükünü geri alınamaz şekilde NULL'lıyor; kullanıcı uyarılmıyor ve yedek istenmiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | data-loss |
| **Konum** | [includes/class-nicepay-installer.php:277](../../../includes/class-nicepay-installer.php#L277) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
if ( self::column_exists( $wpdb, $table, 'payment_data' ) ) {
        $queries[] = "UPDATE {$table} SET payment_data = NULL
         WHERE payment_data IS NOT NULL AND payment_data <> ''
           AND (payment_data LIKE '%\"CardNo\"%'
             OR payment_data LIKE '%\"AuthToken\"%'
             OR payment_data LIKE '%\"Signature\"%'
             OR payment_data LIKE '%\"BuyerEmail\"%'
             OR payment_data LIKE '%\"BuyerTel\"%'
             OR payment_data LIKE '%\"VbankNum\"%')";   // :277-286
    }

v1 ham PG yanıtını olduğu gibi saklıyordu, yani BuyerEmail/BuyerTel neredeyse her satırda vardır. Sonuç: geçmişteki her ödemenin TÜM ham audit yükü (ResultCode, TID, Amt, AuthCode, AuthDate, CardName...) sıfırlanır — hassas alanlar ayıklanmaz, kayıt komple silinir. İşlem geri alınamaz, transaction içinde değil, ve öncesinde hiçbir onay/dışa aktarma adımı yok. readme.txt'nin 'Upgrade Notice' bölümü (readme.txt:99-103) yalnızca 'Review all settings after upgrading' diyor; CHANGELOG'da 'scrubbed legacy PAN/token/raw response material' geçiyor (CHANGELOG:43) ama bunun tüm audit yükünü kapsadığı ve geri alınamaz olduğu söylenmiyor.
```

**Başarısızlık senaryosu**

Merchant 2.0.0'a günceller. İlk istekte scrub çalışır ve 40.000 geçmiş satırın payment_data'sı NULL olur. Üç ay sonra bir chargeback itirazında NICEPAY, AuthCode ve AuthDate kanıtı ister. Ledger'daki ilgili sütunlar (auth_code v1'de ayrı sütun olarak yok, payment_data içindeydi) boştur; merchant kanıt üretemez ve itirazı kaybeder.

**Etki**

Bir uyum/ihtilaf denetiminde 'PG'nin döndürdüğü ham yanıt' kanıtı tüm geçmiş işlemler için yok olur. Merchant bunu ancak ihtiyaç duyduğunda fark eder ve yedekten geri dönmek de mümkün olmayabilir (yükseltme genellikle yedek alınmadan yapılır).

**Öneri**

Komple NULL'lamak yerine ayıklayarak yeniden yazın — allowlist zaten mevcut (nicepay_filter_payment_data, functions:95-116). PHP tarafında batch'ler halinde: satırı oku, json_decode et, nicepay_filter_payment_data() uygula, geri yaz. Bu hem hassas alanları temizler hem de audit değerini korur. Bu mümkün değilse en azından: (a) readme.txt Upgrade Notice ve admin notice'ta 'yükseltme geçmiş ham PG yanıtlarını kalıcı olarak siler, önce yedek alın' uyarısını açıkça verin, (b) scrub'ı ilk otomatik istekte değil, admin'in onayladığı bir adımda çalıştırın.

---

### DATA-029 — dbDelta indeks/sütun silmediği için yükseltilmiş tablolarda yedek indeksler ve card_no sütunu kalıcı olarak kalıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | schema-hygiene |
| **Konum** | [includes/class-nicepay-transaction-schema.php:122](../../../includes/class-nicepay-transaction-schema.php#L122) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
v1 tablosunda (git show origin/main:nicepay-payment-gateway.php:110-144) şu indeksler vardı: KEY idx_tid (tid), KEY idx_order_id (order_id), KEY idx_moid (moid), KEY idx_status (status) ve card_no varchar(30) sütunu.

v2 indexes() listesinde bunların hiçbiri yok (:122-138); yerine uniq_tid, uniq_moid, idx_status_created geldi. Ama dbDelta hiçbir zaman DROP INDEX/DROP COLUMN üretmez. Sonuç, yükseltilmiş bir tabloda:
- idx_tid ile uniq_tid aynı sütunu iki kez indeksler,
- idx_moid ile uniq_moid aynı sütunu iki kez indeksler,
- idx_status, idx_status_created(status, created_at)'ın gereksiz ön ekidir,
- card_no sütunu (içi boşaltılmış olsa da) fiziksel olarak durur.

Birim test 'Fresh installs must not create a PAN storage column' diyor (NicePayTransactionSchemaTest:55) — 'fresh installs' vurgusu doğru ama yükseltilmiş kurulumlar için bu garanti verilmiyor ve hiçbir yerde belirtilmiyor.
```

**Başarısızlık senaryosu**

v1'den yükselten bir mağazada wp_nicepay_transactions 8 indeksle değil 12 indeksle çalışır. Ledger 2 milyon satıra çıkınca yoğun saatlerde her ödeme insert'i ölçülebilir şekilde yavaşlar. Ayrı olarak, bir güvenlik denetiminde tablo şeması çıkarıldığında card_no sütunu görülür ve eklenti 'kart numarası saklıyor' olarak işaretlenir; kod okumadan aksini ispatlamak mümkün değildir.

**Etki**

Her INSERT/UPDATE 3 gereksiz indeks bakımı yapar (yazma amplifikasyonu ~%30 daha fazla indeks I/O), tablo boyutu büyür. Daha önemlisi, PAN saklama sütununun kaldırıldığı iddiası yükseltilmiş kurulumlar için doğru değil: sütun şemada durduğu için bir yedekten geri yükleme, bir üçüncü parti eklenti veya elle yazılan bir SQL onu yeniden doldurabilir ve PCI kapsamı geri gelir.

**Öneri**

Installer'a sürüme bağlı, tek seferlik ve idempotent bir temizlik adımı ekleyin (dbDelta'dan SONRA, doğrulama geçtikten sonra):

    // Yalnızca uniq_* indeksleri doğrulandıktan sonra:
    foreach ( array( 'idx_tid', 'idx_moid', 'idx_status', 'idx_order_id' ) as $legacy_index ) {
        if ( in_array( $legacy_index, $present_indexes, true ) ) { $wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$legacy_index}`" ); }
    }
    if ( self::column_exists( $wpdb, $table, 'card_no' ) ) { $wpdb->query( "ALTER TABLE {$table} DROP COLUMN card_no" ); }

Entegrasyon testine gerçek v1 CREATE TABLE'ından başlayıp yükselten bir senaryo ekleyin ve sonunda hem yedek indekslerin hem card_no'nun gitmiş olduğunu assert edin.

---

### DATA-030 — Her istekte iki SHOW TABLES; admin listesi sayfası indekssiz COUNT(*) ve 4 yollu OR taramaları yapıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | performance |
| **Konum** | [includes/class-nicepay-installer.php:125](../../../includes/class-nicepay-installer.php#L125) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
maybe_install() erken çıkış kontrolünden ÖNCE iki metadata sorgusu çalıştırıyor:

    $table_exists        = self::table_exists( $wpdb, $table );          // :125
    $refund_table_exists = self::table_exists( $wpdb, $refund_table );   // :126
    if ( $current === $target && $table_exists && $refund_table_exists ) { return array('status' => 'current', ...); }   // :128

table_exists() her çağrıda bir 'SHOW TABLES LIKE %s' çalıştırıyor (:228-229) ve bu plugins_loaded@5'te HER istekte oluyor (nicepay-payment-gateway.php:94). is_current() de aynı iki sorguyu tekrar yapıyor (:52-53) ve 5 ayrı yerden çağrılıyor: gateway is_available (gateway:81), shortcode render (plugin:399), ajax_init_payment (plugin:538), standalone gate (plugin:635), admin (transactions:248, 539; admin:287, 433). Hiçbir request-içi bellekleme (static cache / wp_cache) yok.

Admin işlemler sayfası ayrıca:

    SELECT COUNT(*) FROM {$table} WHERE {$where_clause}                  // functions:1241
    SELECT COUNT(*) FROM {$table} WHERE status = 'needs_reconciliation'
       OR reconciliation_status = 'required' OR cancel_status = 'unknown'
       OR net_cancel_status = 'unknown'                                   // functions:1036-1040
    SELECT currency, COUNT(*), SUM(...) ... GROUP BY currency             // transactions:104-111

cancel_status ve net_cancel_status sütunlarının hiç indeksi yok (schema:122-138) ve dört yollu OR zaten indeks birleşimini pratik olarak imkânsız kılar. Ayrıca nicepay_get_transactions() OFFSET tabanlı sayfalama kullanıyor (:1237) ve per_page hiç clamp'lenmiyor ($limit = (int) $args['per_page'], :1238).
```

**Başarısızlık senaryosu**

1,5 milyon satırlık ledger'a sahip mağazada admin 'NicePay > Transactions' sayfasını açar. COUNT(*) (filtresiz) + 4 yollu OR COUNT(*) + currency GROUP BY toplamı üç tam tarama yapar; sayfa 8-15 saniyede yüklenir ve her yenilemede tekrarlanır. Aynı anda ön yüzdeki her ziyaretçi 2 ek SHOW TABLES üretir; binlerce tablo barındıran bir shared MySQL'de bu information_schema erişimi ölçülebilir gecikme yaratır.

**Etki**

Ön yüzde her istek başına 2 gereksiz metadata sorgusu, checkout'ta 4-6 tanesi. Admin işlemler sayfasında ise sayfa başına en az 3 tam/geniş tarama; büyük ledger'da liste sayfası saniyelerce sürer ve derin sayfalama (OFFSET 20000) lineer olarak kötüleşir.

**Öneri**

1) table_exists sonucunu istek başına belleğe alın (private static $exists cache) ve 'current' durumunda hiç sorgu yapmayın: önce sürüm karşılaştırın, eşleşiyorsa varlık kontrolünü günlük bir transient'e bağlayın.
2) nicepay_get_reconciliation_count() için UNION ALL yapısı kullanın (her dal kendi indeksini kullanabilir) veya tek bir 'needs_attention' tinyint kolonu + indeks ekleyin.
3) Toplam sayımı önbelekleyin (5 dk transient) veya WP_List_Table stili 'yaklaşık toplam' gösterin; per_page'i max( 1, min( 200, (int) $args['per_page'] ) ) ile clamp'leyin ve negatif değerin LIMIT -N üretmesini engelleyin.
4) Derin sayfalama için CSV export'ta zaten kullanılan keyset imlecini liste sayfasına da taşıyın.

---

### DATA-031 — needs_reconciliation terminal bir çıkmaz: operatör için hiçbir çözüm aracı yok, ayrılmış sütun ise ölü

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | operability |
| **Konum** | [includes/class-nicepay-transaction-schema.php:77](../../../includes/class-nicepay-transaction-schema.php#L77) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Şemada mutabakatın kapatılması için bir sütun ayrılmış:

    'reconciliation_checked_at' => self::COLUMN_DATETIME_NULL,   // :77

Ancak bu sütun kod tabanının hiçbir yerinde okunmuyor veya yazılmıyor (includes/ ve admin/ altında schema dosyası dışında 0 eşleşme). Admin tarafında da 'mutabakatı çözümle / kontrol edildi olarak işaretle' türü hiçbir aksiyon yok: NicePay_Transactions yalnızca wp_ajax_nicepay_cancel_transaction (iade) ve admin_post_nicepay_export_transactions (CSV) kaydediyor (transactions:24-25) ve ajax_cancel_transaction, reconciliation_status='required' olan satırları zaten reddediyor (transactions:999).

Bir satır 'needs_reconciliation' olduğunda: iade edilemez (gateway:711-714), retention tarafından silinemez (retention:309-311) ve DATA-004 senaryosunda active_attempt_key'i de tuttuğu için sipariş yeniden ödenemez.
```

**Başarısızlık senaryosu**

Bir hafta içinde 6 ödeme, PG yanıtı zaman aşımına uğradığı için needs_reconciliation olur. Merchant NICEPAY panelinden bakar, 4'ünün gerçekten tahsil edilmediğini, 2'sinin tahsil edildiğini görür ve manuel olarak iade eder. Ledger'da 6 satır da hâlâ 'required' durumundadır. İki ay sonra sayaç 60'a çıkar, admin artık rozete bakmaz ve gerçek bir para tutarsızlığını gözden kaçırır.

**Etki**

Kısmi başarısızlıklar (ağ koptu, PG yazdı DB yazmadı) kalıcı çöp durum bırakıyor. Operatörün tek çıkışı doğrudan SQL çalıştırmak — bir ödeme eklentisinde bu, veri bütünlüğü açısından en riskli müdahale biçimi. Ayrıca reconciliation sayacı (functions:1032) hiç azalmadığı için admin ekranındaki uyarı rozeti sonsuza kadar yanar ve zamanla anlamsızlaşır (alarm yorgunluğu).

**Öneri**

Mutabakat kapatma aksiyonu ekleyin: yetkili kullanıcı (nicepay_manage_transactions_capability) için nonce korumalı bir 'Mark reviewed' işlemi; reconciliation_status='reviewed', reconciliation_checked_at=gmdate(...), reconciliation_note'a kullanıcı id + serbest metin, ve kritik olarak active_attempt_key=NULL yazsın. nicepay_get_reconciliation_count() yalnızca 'required' olanları saysın. Bu aksiyon parayı asla değiştirmemeli, sadece insan incelemesini kayda geçirmeli. Kullanılmayacaksa reconciliation_checked_at sütununu şemadan çıkarın.

---

### DATA-013 — Repository katmanı veritabanı hatasını boş sonuçtan ayırt etmiyor ve kritik yazma hatalarını ayrıntısız logluyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | observability |
| **Konum** | [includes/nicepay-functions.php:382](../../../includes/nicepay-functions.php#L382) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
nicepay_save_transaction insert başarısız olduğunda yalnızca Failed to save transaction mesajını, veri olarak null ile logluyor (nicepay-functions.php:382-385); wpdb->last_error, moid, wc_order_id veya hata kodunun hiçbiri kaydedilmiyor. Bu yüzden beklenen bir durum (uniq_active_attempt çakışması = aynı sipariş için ikinci form denemesi) ile gerçek bir arıza (DB düştü, disk doldu) log üzerinden ayırt edilemiyor. Aynı desen okuma tarafında da var: nicepay_get_transactions hata durumunda items boş ve total 0 döndürüyor (1254-1259), privacy exporter get_results null dönerse done=true ile Hiç kayıt yok anlamına gelen bir sonuç üretiyor (privacy:92-95), nicepay_get_reconciliation_count ise hatada max(0, (int)null) = 0 döndürüyor (1042).
```

**Başarısızlık senaryosu**

Veritabanı replikası kısa süreli okuma hatası veriyor. Bu sırada işlenen bir GDPR export talebi, veri sahibi için NicePay bölümünü boş üretip tamamlanmış olarak işaretliyor; talep kapanıyor ve kimse eksik veriyi fark etmiyor. Aynı pencerede admin mutabakat rozetini 0 görüyor.

**Etki**

Bir DB kesintisi sırasında admin defterin gerçekten boş olduğunu, mutabakat gerektiren kayıt olmadığını ve GDPR export'unun kişi için kayıt bulamadığını görüyor. Ödeme tarafındaki başarısızlıklar ise teşhis edilebilir bir iz bırakmıyor.

**Öneri**

wpdb->last_error kontrolünü okuma/yazma sarmalayıcılarına taşıyın. Yazmada: if (false === $result) { nicepay_log('Failed to save transaction', array('moid' => $data['moid'], 'wc_order_id' => $data['wc_order_id'], 'db_error' => $wpdb->last_error), 'error'); } ve çağırana duplicate anahtar ile diğer hataları ayırt eden bir kod döndürün. Okumada: get_results/get_var sonrası last_error doluysa null döndürüp çağıranın Veri yüklenemedi durumunu göstermesini sağlayın; privacy exporter/eraser hata halinde done=false ve messages ile açıkça hata bildirsin, asla sessizce boş sonuç dönmesin.

---

### DATA-016 — Multisite ağ etkinleştirmesi tüm siteleri sınırsız döngüyle geziyor ve ilk hatada wp_die ile yarım kalmış bir ağ bırakıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | multisite |
| **Konum** | [nicepay-payment-gateway.php:132](../../../nicepay-payment-gateway.php#L132) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
activate() ağ genelinde get_sites(array('fields'=>'ids','number'=>0)) ile TÜM siteleri çekip her biri için switch_to_blog + activate_current_site çalıştırıyor (nicepay-payment-gateway.php:132-144). activate_current_site her site için dbDelta, set_default_options, cron planlama ve flush_rewrite_rules() yapıyor (157-172) - flush_rewrite_rules tek başına pahalı bir işlem. Herhangi bir sitede WP_Error dönerse döngü wp_die ile kesiliyor (140-143); o ana kadar hazırlanan siteler hazır, sonrakiler hazırlanmamış kalıyor ve tekrar denemek için bir yol yok (eklenti etkinleşmediği için hook'lar da çalışmaz).
```

**Başarısızlık senaryosu**

300 siteli bir ağda yönetici eklentiyi network-activate ediyor. 180. sitede eski bir tabloda duplicate moid bulunuyor; maybe_install WP_Error dönüyor ve wp_die sadece NicePay cannot add the unique Moid index... yazıyor. Hangi blog_id olduğu belli değil; ilk 179 site provizyonlanmış, kalan 120 site provizyonsuz ve eklenti etkin değil.

**Etki**

Yüzlerce/binlerce siteli bir ağda etkinleştirme isteği PHP zaman aşımına düşüyor; kısmi provizyon nedeniyle bazı sitelerde tablolar var, bazılarında yok. wp_die mesajı hangi sitenin başarısız olduğunu söylemiyor.

**Öneri**

Ağ etkinleştirmesinde site döngüsünü kaldırın; bunun yerine her site kendi ilk isteğinde plugins_loaded üzerindeki maybe_install_schema ile provizyonlansın (mekanizma zaten var). Zorunlu ise: get_sites'i number ile parçalayın, işi bir cron/queue'ya alın, wp_die yerine hataları site kimliğiyle birlikte toplayıp ağ admin uyarısında listeleyin ve flush_rewrite_rules'ı site başına değil yalnızca gerektiğinde çalıştırın (rewrite kuralları sürümünü bir option'da izleyin).

---

### DATA-017 — Tablo adı 26 yerde elle kuruluyor; tek kaynak (NicePay_Installer::table_name) yalnızca admin tarafında kullanılıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | maintainability |
| **Konum** | [includes/nicepay-functions.php:341](../../../includes/nicepay-functions.php#L341) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
$wpdb->prefix . 'nicepay_transactions' / 'nicepay_refund_attempts' ifadesi 26 ayrı yerde tekrarlanıyor: nicepay-functions.php içinde 18, retention'da 4, privacy'de 2, installer'da 2. Oysa kanonik yardımcılar mevcut (installer:62-69 table_name/refund_table_name) ve admin bunları doğru kullanıyor (admin/class-nicepay-transactions.php:99, 137). Admin ayrıca interpolasyondan önce tablo adını regex ile doğruluyor; repository fonksiyonlarında bu doğrulama hiç yok.
```

**Başarısızlık senaryosu**

Bir sonraki sürümde tablo adına bir sürüm son eki ekleme kararı alınıyor. Geliştirici installer, retention ve privacy'yi güncelliyor ama nicepay-functions.php'deki 18 noktadan üçünü atlıyor; bu üç fonksiyon eski tabloyu okumaya devam ediyor ve iade akışı sessizce boş sonuçlarla çalışıyor.

**Etki**

Tablo adlandırmasında ileride yapılacak herhangi bir değişiklik (ör. çoklu-ağ paylaşımlı tablo, farklı prefix stratejisi) 26 noktada eşzamanlı düzenleme gerektiriyor; tek bir kaçırılan yer sessizce yanlış tabloya yazar. Ayrıca hiçbir repository fonksiyonu tablonun var olup olmadığını kontrol etmediğinden şema bloklu bir sitede tüm sorgular sessizce başarısız olur (bkz. DATA-013).

**Öneri**

Tüm çağrı noktalarını NicePay_Installer::table_name($wpdb) / refund_table_name($wpdb) üzerinden geçirin (veya bu ikisini nicepay_transactions_table() gibi iki küçük yardımcı fonksiyona sarın) ve yardımcıların içinde regex doğrulamasını tek noktada yapın. Bir PHPCS/grep tabanlı CI kuralı ekleyin: prefix . 'nicepay_ deseni yalnızca yardımcı fonksiyonların bulunduğu dosyada geçebilsin.

---

### DATA-032 — Beş ölü sütun ve tamamen boş bir sütuna kurulmuş indeks (VBANK katmanı yalnızca şemada var)

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | maintenance |
| **Konum** | [includes/class-nicepay-transaction-schema.php:136](../../../includes/class-nicepay-transaction-schema.php#L136) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Aşağıdaki sütunlar şema dışında hiçbir üretim dosyasında (includes/, admin/, templates/) geçmiyor:

    'binding_token_hash'        => self::COLUMN_CHAR_64,        // :55  -> 0 kullanım
    'reconciliation_checked_at' => self::COLUMN_DATETIME_NULL,  // :77  -> 0 kullanım
    'vbank_issued_at'           => self::COLUMN_DATETIME_NULL,  // :95  -> 0 kullanım
    'vbank_expires_at'          => self::COLUMN_DATETIME_NULL,  // :96  -> 0 kullanım
    'vbank_deposited_at'        => self::COLUMN_DATETIME_NULL,  // :97  -> 0 kullanım

ve buna rağmen bir indeks kurulmuş:

    'KEY idx_vbank_expires_at (vbank_expires_at)',   // :136

vbank_num / vbank_exp_date de yalnızca nicepay_save_transaction'ın varsayılanlar dizisinde '' olarak geçiyor (functions:364-365); PG yanıtından hiç doldurulmuyor — return handler VbankNum'u ekranda gösteriyor ama saklamıyor (class-nicepay-return-handler.php:259). 'otid' sütunu yalnızca bir yerde yazılıyor (gateway:860) ve hiçbir yerde okunmuyor.
```

**Başarısızlık senaryosu**

Yeni bir geliştirici VBANK desteği eklemek için şemaya bakar, vbank_expires_at/vbank_deposited_at'ın hazır olduğunu görür ve sadece yazma kodunu ekler; oysa expiry cron'u, deposit webhook'u ve durum geçişleri hiç yoktur. Sonuç: kısmen çalışan bir VBANK akışı — sanal hesap süresi dolduğunda sipariş asla iptal olmaz.

**Etki**

Sürümlenmiş ve 'tek doğru kaynak' olduğu iddia edilen bir şemada 5 ölü sütun + her satırda bakımı yapılan ama hiç dolmayan bir indeks var. idx_vbank_expires_at her INSERT/UPDATE'te güncellenir ve hiçbir sorguyu hızlandırmaz. Okuyucu için de yanıltıcı: şema VBANK yaşam döngüsünün uygulandığını ima ediyor, oysa uygulanmamış.

**Öneri**

Ya bu sütunları (ve idx_vbank_expires_at indeksini) şemadan çıkarın, ya da her birinin yanına 'planlanmış, henüz yazılmıyor' şeklinde açık bir yorum ekleyip indeksi VBANK gerçekten uygulanana kadar kaldırın. En az maliyetli adım: indeksi kaldırmak (yazma maliyeti sıfırlanır) ve sütunları tek bir '// Reserved for the not-yet-certified VBANK lifecycle' bloğunda toplamak.

---

### DATA-033 — Privacy eraser kapsamı eksik: refund_attempts serbest metni dokunulmuyor, akıştaki ödemeler anonimleştiriliyor, exporter iade geçmişini vermiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | privacy |
| **Konum** | [includes/class-nicepay-privacy.php:141](../../../includes/class-nicepay-privacy.php#L141) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Eraser yalnızca transactions tablosuna dokunuyor:

    UPDATE {$table}
     SET buyer_name = '', buyer_email = '', buyer_tel = '',
         receipt_token_hash = '', receipt_issued_at = NULL, payment_data = NULL
     WHERE id IN ({$id_list})       // :141-146

Atlanan noktalar:
1) wp_nicepay_refund_attempts.reason sütunu (schema:154, varchar(100)) merchant'ın serbest metni ve process_refund'da sanitize edilip saklanıyor (gateway:783). Kişi adı/telefon içerebilir ve eraser buna hiç dokunmuyor.
2) Eraser satır durumuna bakmıyor: 'pending' veya 'approving' durumundaki, yani hâlâ akışta olan bir ödemenin buyer_email'i de siliniyor. Standalone akışında makbuz e-postası buyer_email'e gönderiliyor (functions:1152-1171); silinmiş satır için makbuz e-postası sessizce gönderilmez.
3) Exporter yalnızca transactions'tan 10 alan veriyor (:58-59); aynı kişiye ait iade denemesi geçmişi (tutar, tarih, sonuç) dışa aktarılmıyor — GDPR Md.15 kapsamında eksik bir 'erişim' yanıtı.
4) buyer_email dışında bir eşleştirme yok; kayıtlarda buyer_email boş olup buyer_tel/buyer_name dolu satırlar hiç bulunamaz.
```

**Başarısızlık senaryosu**

Bir müşteri silme talebi gönderir. Admin daha önce onun iadesini 'Ayşe Yılmaz telefonla iade istedi' notuyla işlemiştir. WP eraser çalışır, transactions satırı anonimleşir, ama wp_nicepay_refund_attempts.reason'da ad ve talep bilgisi kalır ve CSV export'ta değil ama DB'de/ yedeklerde yaşamaya devam eder. Ayrı olarak: müşteri standalone ödemesini başlatmışken (status='pending') aynı gün silme talebi işlenirse, ödeme onaylandığında makbuz e-postası hiç gönderilmez ve müşteri ödeme kanıtı alamaz.

**Etki**

Silme talebinden sonra kişisel veri artıkları kalabiliyor (iade nedeni metni) ve erişim talebi eksik yanıtlanıyor. Ayrıca akıştaki bir ödemenin iletişim verisinin silinmesi, o ödemenin tamamlanmasını sessizce bozabiliyor.

**Öneri**

1) Eraser'a refund_attempts temizliğini ekleyin: UPDATE {$refund_table} SET reason = '' WHERE transaction_id IN ({$id_list}).
2) Akıştaki satırları koruyun ve raporlayın: WHERE ... AND status NOT IN ('pending','approving') ve atlanan satırlar için items_retained=true + açıklayıcı bir messages[] girdisi ('Devam eden bir ödeme tamamlanana kadar iletişim verisi korundu').
3) Exporter'a ikinci bir group (nicepay-refund-attempts) ekleyin ve kişinin iade geçmişini de verin.
4) Eşleştirmeyi genişletmeyi değerlendirin (WooCommerce sipariş e-postası üzerinden wc_order_id ile eşleşen satırlar), en azından bu sınırı DEVELOPER-GUIDE'da açıkça yazın.

---

### DATA-034 — Multisite: alt site silindiğinde nicepay tabloları yetim kalıyor (wpmu_drop_tables kaydı yok)

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | multisite |
| **Konum** | [nicepay-payment-gateway.php:97](../../../nicepay-payment-gateway.php#L97) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Site oluşturma tarafı doğru ele alınmış:

    add_action( 'wp_initialize_site', array( $this, 'install_new_site' ), 20, 1 );   // :97
    // install_new_site() switch_to_blog + activate_current_site + restore_current_blog (:223-240)

Ama silme tarafı yok: kod tabanında 'wpmu_drop_tables', 'wp_uninitialize_site' veya 'wpmu_delete_blog' filtrelerine hiçbir kayıt yok (includes/, admin/, ana dosya üzerinde 0 eşleşme). WordPress bir alt siteyi silerken yalnızca wpmu_drop_tables filtresine eklenmiş tabloları düşürür.
```

**Başarısızlık senaryosu**

Bir multisite network'te 200 mağaza deneme süresi sonunda silinir. 400 yetim tablo kalır; içlerinde binlerce alıcı adı, e-posta ve telefon vardır. Bir GDPR denetiminde 'silinen mağazaların verisi ne oldu?' sorusuna verilecek cevap yoktur ve tabloların hangi siteye ait olduğu ancak prefix'ten tahmin edilebilir.

**Etki**

Her silinen alt site, veritabanında wp_N_nicepay_transactions ve wp_N_nicepay_refund_attempts tablolarını kalıcı olarak bırakır. Bu tablolar buyer_name/buyer_email/buyer_tel içerir — yani 'siteyi sildim' diyen bir network yöneticisi aslında kişisel veriyi silmemiş olur. Aynı zamanda DATA-013'teki SHOW TABLES maliyetini de artırır.

**Öneri**

add_filter( 'wpmu_drop_tables', function ( $tables, $blog_id ) { global $wpdb; $prefix = $wpdb->get_blog_prefix( $blog_id ); $tables[] = $prefix . 'nicepay_transactions'; $tables[] = $prefix . 'nicepay_refund_attempts'; return $tables; }, 10, 2 ); Ayrıca DEVELOPER-GUIDE'daki 'Deactivation and uninstall retain the financial ledger' notunun multisite site silme durumunda geçerli olmadığını (veya olacağını) açıkça yazın; şu an davranış belgelenmiş politikayla çelişiyor.

---

### DATA-035 — prepare_write bilinmeyen sütunu sessizce düşürüyor: bir yazım hatası tespit edilmeden finansal alanın yazılmamasına yol açar

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | robustness |
| **Konum** | [includes/class-nicepay-transaction-schema.php:262](../../../includes/class-nicepay-transaction-schema.php#L262) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
foreach ( $data as $column => $value ) {
        if ( in_array( $column, $database_owned, true ) || null === self::format_for( $column ) ) {
            continue;                                   // :263-265  sessizce atla
        }
        $prepared[ $column ] = $value;
        $formats[]           = self::format_for( $column );   // :268
    }

Bilinmeyen bir anahtar hiçbir log, uyarı veya hata üretmeden yok sayılıyor. Çağıranlar da bunu fark edemiyor: nicepay_update_transaction yalnızca $prepared['data'] TAMAMEN boşsa false döner (functions:408-410); bir tek alan düşerse update 'başarılı' sayılır. prepare_refund_write da aynı desende (:287).

Yan not: format_for() her çağrıda 66 elemanlı columns() dizisini yeniden inşa ediyor ve prepare_write her sütun için onu İKİ kez çağırıyor (:263 ve :268) — 40 sütunluk bir yazmada 80 dizi inşası.
```

**Başarısızlık senaryosu**

Gelecekte bir yamada iade tamamlama kodu 'remaining_amt' gibi yanlış bir anahtar kullanır. nicepay_complete_transaction_refund() prepare_write'tan bu alanı düşürür, ama refunded_amount ve cancel_status doğru yazıldığı için $wpdb->update 1 satır etkiler ve fonksiyon true döner. Ledger'da remaining_amount eski değerde kalır; bir sonraki kısmi iade, gerçekte kalmayan bakiyeyi 'kalan' sanarak claim'i geçer ve PG'ye fazla iade talebi gider.

**Etki**

Şema yeniden adlandırıldığında veya bir çağıran alan adını yanlış yazdığında (ör. 'refund_amount' yerine 'refunded_amount'), para alanı sessizce yazılmaz ve kod başarı raporlar. Bu, tespit edilmesi en zor hata sınıfı: ledger sessizce yanlış kalır.

**Öneri**

Düşürülen anahtarları görünür kılın:

    $dropped = array();
    foreach ( $data as $column => $value ) { ... else { $dropped[] = $column; continue; } }
    if ( ! empty( $dropped ) && function_exists( 'nicepay_log' ) ) {
        nicepay_log( 'Transaction write dropped unknown columns', $dropped, 'error' );
    }

(database_owned olanları bu logdan hariç tutun, onlar kasıtlı.) Ek olarak columns() sonucunu static bir değişkende bellekleyin ve format_for()'u sütun başına bir kez çağırın.

---

### DATA-036 — WordPress strict SQL mode'u kapattığı için PG kaynaklı uzun değerler sessizce kırpılıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | data-integrity |
| **Konum** | [includes/class-nicepay-transaction-schema.php:86](../../../includes/class-nicepay-transaction-schema.php#L86) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Şema alıcı alanları için doğru şekilde dar tutulmuş (buyer_name varchar(100) vs 30 baytlık protokol limiti, functions:1398) — orada risk yok. Ancak PG'den gelen ve hiçbir uzunluk doğrulamasından geçmeyen alanlar var:

    'card_name'       => self::COLUMN_VARCHAR_50,    // :86
    'bank_name'       => self::COLUMN_VARCHAR_50,    // :92
    'pay_method_name' => self::COLUMN_VARCHAR_50,    // :67
    'result_code'     => self::COLUMN_VARCHAR_64,    // :69

wpdb::set_sql_mode() STRICT_TRANS_TABLES ve STRICT_ALL_TABLES'ı oturum sql_mode'undan KALDIRIR (WP core davranışı). Strict mode olmadan MySQL, sığmayan değeri hata vermek yerine sessizce kırpar ve yalnızca bir warning üretir — wpdb bu warning'i okumaz, $wpdb->insert/update true döner.

Ayrıca aynı mekanizma 'result_msg text NOT NULL' gibi varsayılansız sütunlara değer vermeden insert yapılmasına da izin veriyor (tests/integration/schema-migration.php:156-160 tam olarak bunu yapıyor) — strict mode açık bir ortamda bu insert hata verirdi.
```

**Başarısızlık senaryosu**

NICEPAY yeni bir cüzdan entegrasyonu için CardName alanında 60 karakterlik Korece bir isim döndürür (utf8mb4'te karakter sayısı sığar ama daha uzun bir isimde sığmaz). Değer 50 karaktere kırpılarak saklanır; admin CSV export'unda ve sipariş özetinde yarım kalmış bir kart adı görünür ve bu hiçbir logda iz bırakmaz.

**Etki**

NICEPAY beklenenden uzun bir CardName/BankName/ResultMsg döndürdüğünde ledger'da sessizce kesilmiş veri oluşur; bu veri mutabakat ve müşteri desteğinde kullanılır. Ayrıca strict mode'a bağımlı olmayan bir kod yazılmadığı için, strict mode'un açık olduğu (ör. özel bir mu-plugin ile) bir kurulumda insert'ler tamamen fail edebilir.

**Öneri**

PG'den gelen tüm string alanları yazmadan önce sütun genişliğine göre nicepay_utf8_byte_cut() ile açıkça kısaltın (kod bunu result_msg için zaten yapıyor, functions:244 — deseni tüm alanlara yayın). Alternatif olarak prepare_write() içine sütun genişliği metadata'sı ekleyip otomatik kırpma + nicepay_log uyarısı üretin. Ek olarak 'result_msg text NOT NULL' için bir DEFAULT değeri olmadığından, testlerde/kodda her zaman değer verildiğini bir assert ile garanti edin.

---

### DATA-037 — Şema entegrasyon testi gerçek v1 -> v2 yükseltmesini hiç çalıştırmıyor ve EXPLAIN assert'i üretim sorgusunu yansıtmıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | test-coverage |
| **Konum** | [tests/integration/schema-migration.php:34](../../../tests/integration/schema-migration.php#L34) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
'Legacy' yükseltme testi gerçek v1 tablosundan başlamıyor; taze v2 tablosundan 3 sütun düşürüp geri ekliyor:

    foreach ( array( 'cc_part_cl', 'clickpay_cl', 'card_type' ) as $column ) {
        $wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );      // :35-37
    }
    update_option( NicePay_Installer::VERSION_OPTION, '2026.08.20.5', false );

Gerçek v1 şeması (git show origin/main:nicepay-payment-gateway.php:110-144) 25 sütun ve 5 indeks içeriyordu; tid NOT NULL DEFAULT '', amount decimal(12,2), result_code varchar(10), idx_tid/idx_moid/idx_status. Bu şekilden başlayan tek bir test yok — yani 45 sütun ekleme + tid'in NULL'a çevrilmesi + decimal genişletme + UNIQUE indeks eklemesi gerçek MySQL'de hiç denenmemiş.

İndeks kullanımı testi de üretim sorgusundan farklı:

    $plan = $wpdb->get_row( "EXPLAIN SELECT id, created_at FROM {$table} ORDER BY created_at DESC LIMIT 20" );   // :162

Üretimdeki sorgu 17 sütun seçiyor (functions:1248-1250), yani covering index değil; MySQL'in plan seçimi farklı olabilir. Ayrıca tablo test sırasında ~55 satır (:155-161) — optimizer kararı gerçek veri hacmini temsil etmiyor.
```

**Başarısızlık senaryosu**

CI yeşil kalır ve sürüm yayınlanır. İlk gerçek v1 kullanıcısı yükselttiğinde: idx_source_ref (varsa eski MySQL'de) sessizce oluşmaz, flow backfill edilmediği için tüm geçmiş iadeler kırılır ve idx_tid/idx_moid yedek indeks olarak kalır. Hiçbiri CI'da görünmez çünkü CI hiçbir zaman v1 tablosundan başlamamıştır.

**Etki**

PR'ın ana iddiası ('versioned migrations with migration checks') tam da en riskli senaryoda test edilmemiş. DATA-002, DATA-003, DATA-010 ve DATA-012'nin hepsi gerçek bir v1 fixture'ı olsaydı bu testte yakalanabilirdi.

**Öneri**

Entegrasyon testine gerçek v1 CREATE TABLE'ını (main branch'teki metin, bir fixture dosyasına alınmış olarak) çalıştıran ve içine tipik v1 verisi (paid satır, moid='' satır, duplicate tid='' satırları) yazıp maybe_install() koşan bir senaryo ekleyin; sonunda: (a) tüm columns() sütunlarının var olduğunu, (b) uniq_moid/uniq_tid/uniq_active_attempt/idx_source_ref indekslerinin SHOW INDEX'te göründüğünü, (c) legacy paid satırın nicepay_get_transaction_by_tid ile bulunabildiğini assert edin. EXPLAIN testini üretimdeki tam sütun listesiyle ve en az birkaç bin satırla çalıştırın.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

[Geçiş 1] İncelenenler (tamamı baştan sona okundu): includes/class-nicepay-transaction-schema.php (296 satır), includes/class-nicepay-installer.php (301), includes/class-nicepay-retention.php (340), includes/class-nicepay-privacy.php (200), includes/nicepay-functions.php repository katmanı (satır 330-500, 590-1000, 1032-1270, 1480-1660), tests/integration/schema-migration.php, tests/integration/run-schema-migration.sh, tests/unit/NicePayInstallerTest.php + NicePayTransactionSchemaTest.php + NicePayRetentionTest.php + NicePayPrivacyTest.php (test metodu listeleri ve kritik metodların gövdeleri), admin/class-nicepay-transactions.php'nin veri erişim kısımları (satır 1-200, 313-349, 540-570, 790-812), admin/class-nicepay-admin.php retention UI'ı (satır 460-600) ve nicepay-payment-gateway.php bootstrap/aktivasyon/multisite yolu (satır 1-330). Migrasyon iddialarını doğrulamak için git show origin/main ile v1 CREATE TABLE ve v1 process_refund karşılaştırıldı. Şema-kod tutarlılığı için ölü sütunlar (vbank_*, binding_token_hash, reconciliation_checked_at) ve tutar yardımcılarının çağrı noktaları grep ile tarandı.

İncelenemeyenler / sınırlamalar: (1) Ortamda PHP ve MySQL yok, bu yüzden hiçbir test veya sorgu fiilen çalıştırılamadı; DATA-001, DATA-005 ve DATA-012'deki motor davranışı iddiaları MySQL/WordPress dbDelta semantiğine ve şemanın statik okunmasına dayanıyor, canlı doğrulama yapılmadı. Bunların doğrulanması için önerilen yol: gerçek v1 tablosunu kuran bir docker senaryosu (DATA-015'teki öneri). (2) WordPress core kaynağı (wp-admin/includes/upgrade.php) ortamda mevcut değil; dbDelta'nın nullability karşılaştırması yapmadığı ve tip karşılaştırmasının büyük/küçük harf duyarlı olduğu iddiaları core davranışına dair bilgiye dayanıyor - PR sahibinin bunu tek bir entegrasyon testiyle sabitlemesi önerilir. (3) Ödeme/iade yaşam döngüsünün iş mantığı (imza doğrulama, inbound validator, net cancel) yalnızca veri katmanına dokunduğu ölçüde incelendi; bu boyutun kapsamı dışında. (4) languages/*.po, JS testleri ve CI workflow'ları bu boyutun kapsamı dışında bırakıldı. (5) DATA-005'teki satır boyutu hesabı elle yapıldı (yaklaşık 12 KB); kesin değer sunucunun karakter setine ve InnoDB sürümüne göre değişebilir, bu yüzden confidence medium işaretlendi.

[Geçiş 2] İnceledim (hem PR diff'i hem dosyaların nihai hali): includes/class-nicepay-transaction-schema.php (296 satır, tamamı), includes/class-nicepay-installer.php (301, tamamı), includes/class-nicepay-retention.php (340, tamamı), includes/class-nicepay-privacy.php (200, tamamı), includes/nicepay-functions.php'nin repository/ledger bölümleri (satır 88-160, 200-300, 330-500, 590-1000, 1030-1270, 1390-1670), admin/class-nicepay-transactions.php'nin veri erişim bölümleri (1-230, 539-600, 969-1030), admin/class-nicepay-admin.php'nin retention/şema UI bölümleri (110-130, 270-300, 425-610), nicepay-payment-gateway.php (1-240, 380-470), includes/class-nicepay-gateway.php'nin ledger yazma/iade bölümleri (165-300, 595-790), tests/integration/schema-migration.php (tamamı), tests/unit/NicePayTransactionSchemaTest.php (tamamı) ve NicePayInstallerTest.php'nin ilgili kısımları. Karşılaştırma için `git show origin/main:nicepay-payment-gateway.php` ve `origin/main:includes/*` üzerinden v1 şeması ve v1 durum yazımları okundu. Ayrıca readme.txt, CHANGELOG.md ve docs/DEVELOPER-GUIDE.md'nin veri politikası ifadeleri kod ile karşılaştırıldı; uninstall.php'nin bulunmadığı doğrulandı (politika DEVELOPER-GUIDE:58'de belgelenmiş, readme.txt'de değil).

İnceleyemediklerim ve nedeni: (1) Ortamda `php` binary'si yok (`php -l` ve şema SQL'ini render etme denemesi başarısız oldu), bu yüzden üretilen CREATE TABLE metnini ve dbDelta davranışını çalıştırarak değil, statik okuma + WP core dbDelta/wpdb semantiği bilgisiyle doğruladım — DATA-001, DATA-002, DATA-010 ve DATA-019 bu nedenle canlı MySQL üzerinde deneysel olarak teyit edilmedi (WP core davranışları: wpdb::flush() last_error sıfırlaması, wpdb::set_sql_mode() strict mode kaldırması, dbDelta'nın case-sensitive tip karşılaştırması, add_option'ın atomik olmaması). (2) MySQL/MariaDB kurulu olmadığı için tests/integration/run-schema-migration.sh çalıştırılamadı; entegrasyon testinin iddialarını yalnızca kaynak okuyarak değerlendirdim. (3) NicePayRetentionTest.php ve NicePayPrivacyTest.php dosyalarını satır satır değil, kapsam açısından (hangi davranışlar assert ediliyor) değerlendirdim; birim testlerin kendi doğruluğuna dair bir bulgu üretmedim. (4) VBANK, standalone makbuz ve inbound-validator akışlarının iş mantığını yalnızca veri katmanına dokunduğu ölçüde inceledim; bunların protokol doğruluğu başka bir boyutun kapsamında.

Doğrulama notu: bulgulardaki tüm satır numaraları dosyaların şu anki (development branch, commit 6b7fedf) haliyle `cat -n`/`awk` çıktısından alınmıştır.

**Açık sorular**

- v1 kurulumlarda tid sütunu gerçekten NOT NULL DEFAULT '' ile mi kaldı, yoksa ara bir 2.0 beta sürümü bunu zaten nullable yapmış mıydı? Yayınlanmış tek sürüm origin/main ise DATA-001 tüm mevcut kullanıcıları etkiler.
- Duplicate moid/tid nedeniyle migrasyon bloklandığında operatörün izleyeceği kurtarma prosedürü nedir? Kodda ve dokümanlarda hiçbir WP-CLI komutu, SQL reçetesi veya admin aracı bulamadım; admin uyarısı yalnızca duplikeleri gözden geçirin diyor.
- captured_amount/currency/mid/mode alanları eski satırlar için kasıtlı olarak mı boş bırakıldı (yani eski siparişlerin iade edilememesi bilinçli bir karar mı)? Öyleyse bu, arayüzde ve CHANGELOG'da açık bir kırıcı değişiklik olarak duyurulmalı.
- VBANK yöntemi 2.0.0'da desteklenen bir yöntem olarak mı sunuluyor? Ödeme formu VbankExpDate gönderiyor ve 4100 başarı kodu tanınıyor, ancak yatırma/son kullanma sütunları hiç doldurulmuyor ve enabled_methods varsayılanında VBANK'ın olup olmadığı netleştirilmeli.
- Retention politikası KRW ödemeleri için Kore'deki yasal asgari saklama süresiyle (ticari defterler için tipik olarak 5 yıl) çelişebilecek MIN_DAYS=1 değerine neden izin veriyor? Alt sınırın daha yüksek tutulması veya en azından 5 yıldan kısa seçimlerde ek bir uyarı gösterilmesi düşünüldü mü?
- Hedef barındırma matrisinde asgari MySQL/MariaDB sürümü ve InnoDB ROW_FORMAT'ı nedir? DATA-002'nin (767 bayt indeks limiti) gerçek etkisi buna bağlı; CI yalnızca tek bir MariaDB sürümünde koşuyor gibi görünüyor.
- v1'den yükseltecek gerçek bir kurulum var mı, yoksa 2.0.0 pratikte 'temiz kurulum' olarak mı dağıtılacak? Yanıt 'yükseltme var' ise DATA-003 ve DATA-011 kritik seviyeye çıkar.
- Retention'ın saklama saati bilinçli olarak mı updated_at (son dokunuş) seçildi, yoksa created_at kastedildi mi? UI metni ve DEVELOPER-GUIDE created_at semantiğini ima ediyor (DATA-006).
- needs_reconciliation durumundan çıkış için bir operatör aracı bilinçli olarak mı ertelendi? reconciliation_checked_at sütununun varlığı planlandığını ama uygulanmadığını gösteriyor (DATA-014, DATA-015).
- Şema sürümü ('2026.08.24.7') string eşitliğiyle karşılaştırılıyor (installer:40, 128). Bu, downgrade senaryosunda (eski eklenti sürümü yeni şemayı görür) dbDelta'yı tekrar çalıştırıp eski şemaya doğru ALTER üretmesine izin verir. version_compare tabanlı 'yalnızca ileri' bir kapı bilinçli olarak mı tercih edilmedi?
- WP.org readme.txt'de kaldırma (uninstall) sonrası verinin korunduğu ve bunun nasıl silineceği neden belirtilmemiş? Politika yalnızca docs/DEVELOPER-GUIDE.md:58'de yazıyor.

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 37 |

## Öncelikli aksiyon listesi

1. **DATA-001** — Nullability değişimini dbDelta'ya bırakmayın; scrub'dan ÖNCE açık bir ALTER çalıştırın ve sonucu doğrulayın. Örnek: if (self::column_exists($wpdb,$table,'tid')) { $wpdb->query("ALTER TABLE {$table} MODIFY tid varchar(50) NULL DEFAULT NULL"); if (!empty($wpdb->last_error)) return new WP_Error('nicepay_schema_tid_nullable_failed', ...); $wpdb->query("UPDATE {$table} SET tid = NULL WHERE tid = ''"); 
2. **DATA-018** — dbDelta'nın dönüş değerine ve last_error'a güvenmeyi bırakıp migrasyon sonrası açık doğrulama ekleyin:

    $required_columns = array_keys( NicePay_Transaction_Schema::columns() );
    foreach ( $required_columns as $column ) {
        if ( ! self::column_exists( $wpdb, $table, $column ) ) {
            return new WP_Error( 'nicepay_schema_column_missing', ..., array( 'column' => $column ) );
    
3. **DATA-002** — Installer'a dbDelta SONRASI, tek seferlik ve idempotent bir backfill adımı ekleyin: UPDATE {table} SET currency='KRW' WHERE currency=''; UPDATE {table} SET flow='woocommerce' WHERE flow='' AND wc_order_id IS NOT NULL; UPDATE {table} SET captured_amount=amount, remaining_amount=amount WHERE status='paid' AND captured_amount=0; ve mid/mode alanlarını payment_data içindeki MID'den ya da o anki yapıla
4. **DATA-020** — Installer'a idempotent bir backfill adımı ekleyin (dbDelta'dan SONRA, VERSION_OPTION yazılmadan ÖNCE):

    UPDATE {$table} SET flow = 'woocommerce', source_ref = CAST(wc_order_id AS CHAR)
      WHERE flow = '' AND wc_order_id IS NOT NULL;
    UPDATE {$table} SET captured_amount = amount, remaining_amount = amount
      WHERE status = 'paid' AND captured_amount = 0 AND refunded_amount = 0;
    UPD
5. **DATA-021** — Stale kurtarma sorgusuna kilidi bırakmayı ekleyin ve auth_token'ı koruyun (mutabakat için gerekli):

    SET status = 'needs_reconciliation',
        approval_state = 'needs_reconciliation',
        reconciliation_status = 'required',
        reconciliation_note = 'stale_approval_attempt',
        active_attempt_key = NULL

Ek olarak nicepay_save_transaction()'da duplicate-key hatasını ayırt edip 
6. **DATA-022** — 1) Sonucu bir transient ile hızlandırın: bir kez başarısız olan bir hedef sürüm için sonraki denemeyi set_transient('nicepay_schema_retry_' . $target, 1, 15 * MINUTE_IN_SECONDS) ile geciktirin; transient varsa maybe_install() hemen saklı WP_Error'ı dönsün.
2) dbDelta çevresine kilit koyun (WP core'un WP_Upgrader::create_lock() deseni): INSERT IGNORE INTO wp_options ... /* LOCK */.
3) Duplicate tar
