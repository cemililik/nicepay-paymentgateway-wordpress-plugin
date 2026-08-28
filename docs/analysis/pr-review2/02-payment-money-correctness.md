# 02 — Ödeme Akışı ve Para Doğruluğu

> PR #3 sistematik review · düşmanca doğrulamalı · 28 bulgu

## Özet

**[Geçiş 1]** Para matematiği bu PR'ın en güçlü tarafı: tutarlar baştan sona string-tabanlı tamsayı aritmetiğiyle işleniyor (nicepay_add/subtract/compare_integer_amounts), decimal sütunlar %s formatıyla yazılıyor ve defter yolunda hiçbir yerde float dönüşümü yok. Durum makinesi de ciddi: pending→approving geçişi tek atomik UPDATE'in etkilenen satır sayısıyla kilitleniyor, iade rezervasyonu koşullu UPDATE ile CAS yapıyor, iade denemesi PG'ye gitmeden ÖNCE kalıcılaştırılıyor ve her belirsiz sonuç tahmin yerine needs_reconciliation + net-cancel denetim kaydı üretiyor. Çift tahsilat, tutar düşürme veya replay için somut bir açık bulamadım; imza + Moid/TID/MID/tutar/metot bağlaması onay öncesi eksiksiz. Buna karşılık paranın kaybolmadığı ama sıkıştığı dört yüksek etkili kusur var: v1.x'ten yükseltilen kayıtlar flow backfill'i olmadığı için hiç iade edilemiyor; captured_amount fallback'i DECIMAL sütunlarda ölü kod olduğu için hem iade hem operatör ekranı yanlış çalışıyor; active_attempt_key kilidini bırakan hiçbir yol yok (cron bile bırakmıyor), bu yüzden takılı bir approving satırı siparişi kalıcı olarak ödenemez hale getiriyor; ve WooCommerce'te elle yapılan tek bir iade o siparişin sonraki tüm NicePay iadelerini kalıcı olarak bloke ediyor. Ayrıca KRW'nin ondalıksızlığı WooCommerce tarafında hiç zorlanmıyor.

**[Geçiş 2]** Bu PR'ın para katmanı çoğu WooCommerce ödeme eklentisinden belirgin şekilde daha disiplinli: tutar aritmetiği tamamen string tabanlı (float yok), pending→approving geçişi tek atomik UPDATE, iade rezervasyonu compare-and-set, PG'ye gitmeden önce audit satırı, ve her belirsiz sonuç fail-closed olarak needs_reconciliation'a düşüyor. Buna rağmen üç ciddi kırılma noktası var. Birincisi 1.x→2.0 migrasyonu: WordPress strict SQL mode'u kapattığı için `UPDATE ... SET tid = NULL` NOT NULL sütunda sessizce no-op oluyor, ardından `UNIQUE KEY uniq_tid` duplicate '' ile başarısız oluyor ve hata sonraki başarılı sorgularla siliniyor — yani yükseltilen sitelerde çift-onay koruması olan tekil TID indeksi hiç oluşmadan şema "güncel" işaretleniyor. İkincisi approving/needs_reconciliation durumunun terminal olması: `active_attempt_key` hiçbir yerde serbest bırakılmıyor ve adminde hiçbir çözüm eylemi yok, dolayısıyla approval sırasında çöken bir istek siparişi kalıcı olarak ödenemez hale getiriyor. Üçüncüsü KRW ondalıksızlığının yalnızca NICEPAY sınırında zorlanması: WooCommerce tarafında 2 ondalıklı bir toplam sessizce yuvarlanıyor, bu hem tahsilat/sipariş sapması hem de ikinci kısmi iadenin ve admin tek-tık iadesinin kalıcı bloke olması demek. Ayrıca başarılı bir kimlik doğrulama yerel doğrulamada reddedilirse ne NetCancel ne defter kaydı yapılıyor.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **C** |
| 🔴 Kritik | 1 |
| 🟠 Yüksek | 6 |
| 🟡 Orta | 14 |
| 🔵 Düşük | 7 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 2 |
| Bağımsız doğrulama kararı alan bulgu | 9 / 28 |
| Tarama geçişi | 2 |
| Referans verilen dosya | 8 |

## Güçlü yönler

- Tüm para aritmetiği string-tabanlı tamsayı (includes/nicepay-functions.php:1581-1647); defter sütunları decimal(14,2) ve wpdb formatı %s (includes/class-nicepay-transaction-schema.php:229-247) — yazma yolunda tek bir float dönüşümü yok.
- nicepay_claim_transaction_for_approval() gerçek CAS: tek UPDATE + etkilenen satır kontrolü; WooCommerce dalında JOIN'li sorgu kardeş pending denemeleri aynı anda abandoned yapıyor (includes/nicepay-functions.php:653-709). SELECT-then-UPDATE yarışı yok.
- İade denemesi PG'ye gönderilmeden önce ayrı bir append-only tabloya yazılıyor ve tam kimlikle (id + cancel_moid + status='requested') kapatılıyor (includes/nicepay-functions.php:763-828, class-nicepay-gateway.php:789-803).
- Replay savunması katmanlı: validate_auth_return status/approval_state='pending' şartı (class-nicepay-inbound-validator.php:72-75) + CAS claim + uniq_moid/uniq_tid/uniq_active_attempt indeksleri (class-nicepay-transaction-schema.php:125-127). F5 / çift POST çift onaya dönüşmüyor.
- Onay yanıtı yerel satıra MID, Moid, tutar, metot, TID ve imza üzerinden bağlanıyor ve bu bağlama BAŞARISIZ olursa net-cancel başarılı olsa bile kayıt fail-closed olarak needs_reconciliation'da kilitleniyor (class-nicepay-inbound-validator.php:130-183, nicepay-functions.php:189-225).
- Kimlik doğrulama sonrası sipariş anlık görüntüsü (toplam, para birimi, order_key hash, needs_payment) onay çağrısından hemen önce yeniden doğrulanıyor (class-nicepay-gateway.php:485-509) — auth ile approval arasında sipariş değiştirilirse para çekilmiyor.
- İade yanıtı hem imza hem TID+CancelAmt eşitliğiyle doğrulanıyor ve defter yalnızca rezerve edilmiş satırda güncelleniyor (class-nicepay-api.php:623-632, nicepay-functions.php:877-914).
- recover_paid_order_from_ledger() capture ile payment_complete arasındaki çökme penceresini kapatıyor ve tutar/para birimi/order_id hash_equals ile yeniden doğruluyor (class-nicepay-gateway.php:173-205).
- Sipariş elle iptal edildiğinde otomatik iade yapmak yerine sipariş notu ile uyarıyor (nicepay-functions.php:994-1025) — sessiz para hareketi yok.
- Kısmi iade kapıları protokol-bilinçli ve muhafazakâr: CELLPHONE tamamen yasak, CARD için CcPartCl='1' şartı ve geri alınamaz simple-pay cüzdan kara listesi (class-nicepay-gateway.php:763-776).
- request_cancel() genişletme parametrelerini array_intersect_key ile tamamen boşaltıyor — kimlik/tutar/imza alanları hiçbir şekilde dışarıdan ezilemiyor (class-nicepay-api.php:587).
- nicepay_update_transaction() opsiyonel require_change ile tam bir satır değişmediğinde başarısız sayıyor; kritik para geçişlerinin hepsi bu katı modda çağrılıyor (nicepay-functions.php:398-430).
- Tutar aritmetiğinin tamamı string tabanlı: nicepay_add/subtract/compare_integer_amounts (includes/nicepay-functions.php:1581-1647) float'a hiç düşmüyor; decimal sütunlar wpdb'ye %s formatıyla yazılıyor (includes/class-nicepay-transaction-schema.php:237-247).
- pending→approving geçişi tek bir atomik UPDATE ile yapılıyor ve etkilenen satır sayısına bakılıyor (includes/nicepay-functions.php:653-709); SELECT-then-UPDATE yarışı yok. Aynı siparişin kardeş pending satırları aynı ifadede 'abandoned' yapılıyor.
- İade rezervasyonu gerçek bir compare-and-set: cancel_status NOT IN ('requested','unknown') + remaining_amount >= tutar koşullu tek UPDATE (includes/nicepay-functions.php:719-755), ve eşzamanlı ikinci iade PG'ye hiç ulaşmıyor.
- Refund attempt kaydı PG çağrısından ÖNCE yazılıyor (includes/class-nicepay-gateway.php:789-803) ve yazılamazsa rezervasyon geri alınıp istek hiç gönderilmiyor (nicepay_release_unsent_refund_claim).
- nicepay_complete_transaction_refund / nicepay_complete_refund_attempt WHERE koşuluna cancel_moid + status='requested' koyup tam 1 satır şartı arıyor (includes/nicepay-functions.php:796-914) — yanlış rezervasyonun tamamlanması engelleniyor.
- Approval yanıtı MID/Moid/tutar/method/TID/imza olarak siparişe bağlanıyor (includes/class-nicepay-inbound-validator.php:130-183) ve bağlanamazsa net cancel denense bile satır fail-closed şekilde needs_reconciliation'da kilitleniyor (nicepay_get_mismatched_approval_audit).
- payment_complete() öncesi sipariş anlık görüntüsü (toplam, para birimi, order_key hash, needs_payment) yeniden doğrulanıyor (includes/class-nicepay-gateway.php:485-509) — auth sonrası sipariş değişimi yakalanıyor.
- active_attempt_key üzerindeki UNIQUE indeks, aynı sipariş için iki eşzamanlı ödeme formunun hazırlanmasını gerçekten engelliyor ve bu gerçek MySQL ile entegrasyon testinde doğrulanmış (tests/integration/schema-migration.php:78-101).
- Kısmi iade kapıları protokol farkındalığıyla yazılmış: CELLPHONE kısmi iade yasak, CARD için CcPartCl='1' şartı ve geri alınamaz simple-pay cüzdan kara listesi (includes/class-nicepay-gateway.php:763-776).
- WooCommerce'in in-flight refund kaydı ile defter mutabakatı zorunlu tutuluyor (includes/class-nicepay-gateway.php:754-759) — bu, WooCommerce'e kaydedilmemiş 'gizli' uzaktan iadeleri engelliyor.
- Sipariş elle iptal edildiğinde otomatik iade yapılmıyor, bunun yerine sipariş notu ile uyarılıyor (includes/nicepay-functions.php:994-1025).

## Bulgular

### MONEY-015 — 1.x→2.0 migrasyonu uniq_tid tekil indeksini sessizce oluşturamıyor: çift-onay koruması yükseltilen sitelerde hiç yok

| | |
|---|---|
| **Severity** | 🔴 Kritik |
| **Kategori** | migration-integrity |
| **Konum** | [includes/class-nicepay-installer.php:269](../../../includes/class-nicepay-installer.php#L269) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
Installer, dbDelta'dan ÖNCE şunu çalıştırıyor:

    if ( self::column_exists( $wpdb, $table, 'tid' ) ) {
        $queries[] = "UPDATE {$table} SET tid = NULL WHERE tid = ''";
    }

Ancak o an sütun hâlâ 1.x şemasındaki hâliyle `tid varchar(50) NOT NULL DEFAULT ''` (git show origin/main:nicepay-payment-gateway.php:112). WordPress `wpdb::set_sql_mode()` ile STRICT_TRANS_TABLES/STRICT_ALL_TABLES/TRADITIONAL modlarını kaldırdığı için MySQL bu UPDATE'te NULL yerine sütunun örtük varsayılanını ('') yazar, yalnızca warning üretir; sorgu başarılı döner ve `$wpdb->last_error` boş kalır. Yani scrub hiçbir şey yapmaz.

Ardından dbDelta `UNIQUE KEY uniq_tid (tid)` eklemeye çalışır. 1.x'te her yeni işlem `'tid' => ''` ile insert edildiği için (git show origin/main:includes/nicepay-functions.php:49) onaylanmamış her denemede tid='' vardır; ikiden fazlası varsa ALTER TABLE 1062 Duplicate entry '' hatası verir. duplicate_tid_count_sql bu satırları bilerek hariç tutuyor (`WHERE tid IS NOT NULL AND tid <> ''`), dolayısıyla ön kontrol de yakalamıyor.

Daha kötüsü: dbDelta indeksleri tanım sırasına göre ekler; uniq_tid'den SONRA idx_created_at, idx_updated_at, idx_status_created, idx_flow_status, idx_source_ref, idx_reconciliation_status, idx_receipt_token_hash, idx_vbank_expires_at sorguları çalışır ve `wpdb::query()` her çağrıda `flush()` ile `last_error`'ı sıfırlar. Sonuçta maybe_install()'ın 185. satırdaki `if ( ! empty( $wpdb->last_error ) )` kontrolü hatayı GÖRMEZ ve 200. satırda şema sürümü 'güncel' olarak yazılır.

Ek olarak dbDelta sütun karşılaştırmasında yalnızca tip ve tırnaklı DEFAULT'a bakar; NOT NULL→NULL değişimini algılamaz, yani `tid` sütunu NOT NULL olarak kalır ve yeni satırlarda `'tid' => null` yine '' olarak yazılır.
```

**Başarısızlık senaryosu**

1.9 sürümünü kullanan bir mağazada 3 müşteri ödeme penceresini kapatmış, tabloda tid='' olan 3 satır var. Mağaza 2.0'a günceller. plugins_loaded sırasında maybe_install() çalışır: scrub UPDATE'i 0 satır değiştirir (warning: Column 'tid' cannot be null), dbDelta `ALTER TABLE wp_nicepay_transactions ADD UNIQUE KEY uniq_tid (tid)` sorgusunda 1062 hatası alır, sonraki 8 indeks sorgusu başarılı olur ve last_error temizlenir. maybe_install() 'installed' döner, nicepay_transactions_schema_version = '2026.08.24.7' yazılır, NicePay_Installer::is_current() true olur. Yönetici hiçbir uyarı görmez. `SHOW INDEX FROM wp_nicepay_transactions` çıktısında uniq_tid yoktur. Bundan sonra saldırgan/hatalı bir tekrar POST'u ile aynı TxTid iki farklı transaction satırına yazılabilir ve nicepay_get_transaction_by_tid() rastgele birini döndürür.

**Etki**

1.x'ten yükselten her sitede tekil TID indeksi yok. Bu indeks, class-nicepay-gateway.php:461'deki `nicepay_update_transaction( ..., array('tid' => $tx_tid), true )` çağrısının farklı bir işlem satırına ait bir TID'yi yazmasını engelleyen tek mekanizma. İndeks olmadığı için aynı PG TID'si birden fazla defter satırına bağlanabilir, çift onay/çift tahsilat tespiti sessizce devre dışı kalır ve tüm testlerin/dokümanların dayandığı 'tid tekildir' invaryantı üretimde geçerli değildir. Migrasyon hatası hiçbir yere raporlanmaz.

**Öneri**

Scrub sırasını düzelt ve boş TID'leri de bloklayıcı kontrole al:
1) dbDelta'dan önce sütunu nullable yap: `if ( column_exists(tid) ) { $wpdb->query("ALTER TABLE {$table} MODIFY tid varchar(50) NULL DEFAULT NULL"); }` ardından `UPDATE {$table} SET tid = NULL WHERE tid = ''`.
2) duplicate_tid_count_sql'e boş/çoklu '' durumunu da ekle veya scrub sonrası `SELECT COUNT(*) FROM {$table} WHERE tid = ''` > 0 ise WP_Error döndür.
3) dbDelta çağrılarının etrafına gerçek hata yakalama koy: her dbDelta çağrısından hemen sonra `$last_error = $wpdb->last_error;` sakla, ayrıca migrasyon sonunda beklenen indekslerin varlığını doğrula:
   `$have = $wpdb->get_col("SHOW INDEX FROM {$table}", 2); foreach ( array('uniq_moid','uniq_tid','uniq_active_attempt') as $ix ) { if ( ! in_array($ix, $have, true) ) return new WP_Error('nicepay_schema_index_missing', ...); }`
4) tests/integration/schema-migration.php'e gerçek 1.x CREATE TABLE gövdesini (tid NOT NULL DEFAULT '', amount decimal(12,2), flow sütunu yok) fixture olarak ekleyip birden çok boş tid'li satırla yükseltmeyi test et.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: 1.x -> 2.0 migrasyonu `tid` sütununun nullability'sini hiçbir zaman değiştiremiyor ve bunu sessizce başarılı sayıyor.

Zincir: (a) installer:269'daki `UPDATE ... SET tid = NULL WHERE tid = ''` sütun hâlâ 1.x'teki `NOT NULL DEFAULT ''` hâlindeyken çalışır; WP strict mode'u kaldırdığı için MySQL değeri implicit default'a ('') çevirip yalnızca uyarı üretir, sorgu başarılı döner ve installer:290'daki guard geçilir — scrub no-op. (b) installer:98'deki ön kontrol boş TID'leri bilerek dışladığı için bu satırlar bloklayıcı kontrole hiç girmez. (c) dbDelta NOT NULL -> NULL değişimini algılamaz (yalnızca `Type` eşitliği ve tırnaklı `DEFAULT 'x'` regex'i karşılaştırılır), dolayısıyla sütun kalıcı olarak NOT NULL DEFAULT '' kalır; repoda telafi edici hiçbir ALTER yok. (d) `ADD UNIQUE KEY uniq_tid (tid)` iki veya daha fazla tid='' satırı varsa 1062 ile başarısız olur; ardından schema:129-136'daki 8 indeks sorgusu ve installer:183'teki ikinci dbDelta çağrısı `wpdb::query()` -> `flush()` yoluyla `last_error`'ı temizler, bu yüzden installer:185 hatayı göremez ve installer:200 şema sürümünü 'güncel' yazar; `is_current()` true döner, admin hiçbir uyarı görmez. dbDelta üstelik `$changes` içinde 'Added index ... uniq_tid' der.

Bulgunun ASIL üretim semptomu, orijinal iddiada anlatılandan farklıdır: sütun NOT NULL kaldığı için `nicepay_save_transaction()` (nicepay-functions.php:344 `'tid' => null`) her yeni işlemde tek satırlık `INSERT ... VALUES (NULL, ...)` üretir; MySQL/MariaDB tek satırlık INSERT'te NOT NULL sütuna NULL yazmayı non-strict modda bile ER_BAD_NULL_ERROR (1048) HATASI sayar (uyarı değil). Sonuç: yükseltilen her 1.x sitesinde — uniq_tid eklenebilmiş olsun ya da olmasın — hiçbir yeni işlem satırı oluşturulamaz ve NicePay checkout'u tamamen çalışmaz hâle gelir. Orijinal iddiadaki "yeni satırlarda tid yine '' olarak yazılır" ifadesi ve buna dayanan "saldırgan aynı TxTid'i iki YENİ satıra yazabilir" senaryosu bu nedenle geçersizdir; çift-TID riski yalnızca yükseltmeden önce var olan 1.x pending satırları için kalır.

Test boşluğu iddiada belirtildiği gibi gerçektir ve daha geniştir: tests/integration/schema-migration.php:28-32 uniq_tid'i hiç assert etmez ve test taze v2 tablosundan başlayıp yalnızca 2026.08.20.4/.5 sürümlerine geri sarar; gerçek 1.x CREATE TABLE gövdesi hiçbir testte kurulmaz.

Öneri, orijinal iddiadakine ek olarak nullability'yi açıkça düzeltmelidir: dbDelta'dan ÖNCE `ALTER TABLE {$table} MODIFY tid varchar(50) NULL DEFAULT NULL` çalıştırıp last_error'ı kontrol et, sonra `UPDATE ... SET tid = NULL WHERE tid = ''` yap, ardından `SELECT COUNT(*) ... WHERE tid = ''` sıfır değilse fail-closed dön; her dbDelta çağrısından hemen sonra last_error'ı yakala ve migrasyon sonunda `SHOW INDEX` ile uniq_moid/uniq_tid/uniq_active_attempt varlığını doğrula.
- Gerekçe: Kodu satır satır okudum. İddianın ÇEKİRDEĞİ ve TÜM SATIR REFERANSLARI doğru; çürütecek hiçbir koruma bulamadım. Ancak iddianın son paragrafındaki bir mekanizma detayı ve buna bağlı etki anlatısı yanlış.

DOĞRULANAN KISIMLAR:

1) Satır numaraları birebir doğru. installer:269 `UPDATE {$table} SET tid = NULL WHERE tid = ''`; installer:98 `WHERE tid IS NOT NULL AND tid <> ''`; installer:185 `if ( ! empty( $wpdb->last_error ) )`; installer:200 `update_option( self::VERSION_OPTION, $target, false )`; schema:45 `'tid' => 'varchar(50) DEFAULT NULL'`; schema:127 `'UNIQUE KEY uniq_tid (tid)'`; gateway:461-464 `nicepay_update_transaction( $transaction->id, array( 'tid' => $tx_tid, ... ), true )`.

2) 1.x şeması gerçekten `tid varchar(50) NOT NULL DEFAULT ''` (git show origin/main:nicepay-payment-gateway.php, create_tables gövdesi) ve 1.x insert varsayılanı `'tid' => ''` (origin/main:includes/nicepay-functions.php:49). 1.x moid'i `generate_moid()` timestamp+wp_rand ile üretiyor, yani moid'ler tekil; duplicate_moid_count_sql ön kontrolü geçiyor ve akış tid adımına gerçekten ulaşıyor. Yani senaryo erişilebilir.

3) UPDATE-ile-NULL mekanizması doğru. WordPress `wpdb::set_sql_mode()` STRICT_TRANS_TABLES/STRICT_ALL_TABLES/TRADITIONAL'ı her bağlantıda kaldırır. MySQL/MariaDB'de UPDATE yolunda `thd->count_cuted_fields = CHECK_FIELD_WARN` olduğu için `set_field_to_null_with_conversions()` alanı implicit default'a ('') çevirip yalnızca uyarı üretir; sorgu başarılı döner. Değer '' -> '' olduğu için rows_affected 0'dır, `false === $result` tetiklenmez ve last_error boştur; installer:290'daki guard geçilir. Scrub gerçekten no-op.

4) dbDelta nullability'yi değiştirmez. WP core dbDelta yalnızca `$tablefield->Type != $type` (burada 'varchar(50)' == 'varchar(50)') ve `| DEFAULT +'(.*?)'|i` regex'ini karşılaştırır; `DEFAULT NULL` tırnaksız olduğu için eşleşmez. NULL/NOT NULL hiç karşılaştırılmaz. Repoda hiçbir yerde telafi edici ALTER yok: `grep -rn "ALTER TABLE|MODIFY|CHANGE COLUMN" includes admin nicepay-payment-gateway.php` -> sıfır sonuç.

5) last_error körlüğü doğru ve iddiadan daha da güçlü. `wpdb::query()` başında `flush()` çağırır, flush() `$this->last_error = ''` yapar. uniq_tid'den sonra tanım sırasına göre 8 KEY daha eklenir (schema:129-136: idx_created_at, idx_updated_at, idx_status_created, idx_flow_status, idx_source_ref, idx_reconciliation_status, idx_receipt_token_hash, idx_vbank_expires_at) VE ayrıca installer:183'te refund tablosu için ikinci bir dbDelta çağrısı çalışır. installer:185'teki kontrol dolayısıyla işlem tablosundaki 1062'yi asla göremez. Üstüne dbDelta `$for_update[...] = 'Added index ...'` kaydını ALTER başarısız olsa da döndürdüğü için `$changes` dizisi de yalanlar.

6) Raporlama yok. installer:200 sürümü yazar, is_current() true döner; nicepay-payment-gateway.php:203'teki `maybe_install_schema()` yalnızca is_wp_error durumunda loglar. admin/class-nicepay-admin.php:433'teki hazırlık kontrolü de sadece `NicePay_Installer::is_current()` bakar. Hiçbir yerde `SHOW INDEX` doğrulaması yok (grep: tüm repoda tek sonuç tests/integration/schema-migration.php:28).

7) Test boşluğu doğru. tests/integration/schema-migration.php:28-32 yalnızca uniq_moid, uniq_active_attempt, idx_created_at, idx_updated_at assert ediyor — uniq_tid'i HİÇ kontrol etmiyor. Ayrıca test taze v2 tablosundan başlayıp sadece 2026.08.20.4/.5 sürümlerine geri sarıyor (satır 38, 60); gerçek 1.x CREATE TABLE gövdesi hiç kurulmuyor.

8) Uygulama katmanında alternatif koruma yok: nicepay_update_transaction (nicepay-functions.php:398-430) yalnızca `WHERE id = %d` ile yazıyor, TID sahipliği kontrolü yok; nicepay_get_transaction_by_tid (nicepay-functions.php:438-458) `LIMIT 1` ile sessizce tek satır seçiyor. Yani uniq_tid gerçekten tek savunma.

DÜZELTİLMESİ GEREKEN KISIM:

İddianın son cümlesi — "tid sütunu NOT NULL olarak kalır ve yeni satırlarda 'tid' => null yine '' olarak yazılır" — YANLIŞ. nicepay-functions.php:344 `'tid' => null` varsayılanı prepare_write'tan geçer (format_for('tid') = '%s', null değer korunur) ve wpdb::_insert_replace_helper null değeri `'NULL'` literal'i olarak tek satırlık bir INSERT'e koyar. MySQL/MariaDB tek satırlık INSERT'te `count_cuted_fields = CHECK_FIELD_ERROR_FOR_NULL` kullanır; NOT NULL sütuna NULL yazmak non-strict modda bile ER_BAD_NULL_ERROR (1048) HATASI verir, uyarı değil (MySQL Reference, "Constraints on Invalid Data": "an error occurs for single-row INSERT statements"). Yani yükseltilen her 1.x sitesinde — uniq_tid eklenmiş olsun ya da olmasın — `nicepay_save_transaction()` false döner ve HİÇBİR yeni işlem satırı oluşturulamaz; ödeme akışı tamamen ölür.

Bunun etkiye yansıması: iddianın anlattığı bitiş durumu (saldırganın tekrar POST'u ile aynı TxTid'in iki YENİ satıra yazılması) pratikte erişilemez, çünkü yükseltmeden sonra yeni satır insert edilemiyor. Çift-TID riski yalnızca yükseltmeden ÖNCE var olan 1.x pending satırları için geçerli. Bulgunun asıl üretim semptomu "sessizce eksik indeks" değil, "yükseltmeden sonra checkout tamamen bozuk + üstüne sessizce eksik indeks + şema 'güncel' işaretli". Severity düşmüyor, aksine yükseliyor; critical olarak kalmalı.

Not: MySQL/MariaDB davranışını ampirik doğrulayamadım (ortamda mysql/mariadb binary yok, docker daemon çalışmıyor). Bu tek detay sunucu kaynağı + resmi dokümantasyon bilgisine dayanıyor; geri kalan her şey doğrudan dosya okumasıyla kanıtlandı.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `confirmed`)

- Düzeltilmiş iddia: 1.x→2.0 migrasyonu `tid` sütununu asla nullable yapmadığı için hem uniq_tid tekil indeksi sessizce kurulamıyor HEM DE — daha kritik olarak — yükseltilen sitede hiçbir yeni işlem satırı yazılamıyor.

Doğrulanan mekanizma (iddiadaki gibi): scrub UPDATE (installer:269) dbDelta'dan önce, sütun hâlâ `NOT NULL DEFAULT ''` iken çalışıyor; WP'nin strict-mode'u kaldırdığı bağlantıda bu UPDATE yalnızca Warning 1048 üretip sessizce hiçbir şey yapmıyor; duplicate_tid_count_sql (installer:98) `tid <> ''` ile bu satırları dışladığı için ön kontrol de yakalamıyor; dbDelta NOT NULL→NULL değişimini algılamıyor (upgrade.php:3205/3244); 1062 hatası sonraki index sorgularının `wpdb::flush()` çağrısıyla siliniyor (class-wpdb.php:1925) ve installer:185 kontrolü hatayı görmüyor, installer:200 sürümü 'güncel' yazıyor.

İddianın KAÇIRDIĞI asıl sonuç: sütun NOT NULL kaldığı için `nicepay_save_transaction()`'ın `'tid' => null` varsayılanı (nicepay-functions.php:344) wpdb tarafından literal `NULL` olarak yazılıyor ve tek satırlık INSERT non-strict modda bile `ERROR 1048 Column 'tid' cannot be null` veriyor (deneysel). WooCommerce checkout (gateway.php:269) tam bu yoldan geçtiği için 1.x'ten yükselten her sitede ödeme başlatma tamamen çöküyor — üstelik tid='' satır sayısı 0/1 ise uniq_tid indeksi BAŞARIYLA kuruluyor, migrasyon "installed" dönüyor, is_current() true, admin sağlık göstergesi yeşil, ama hiçbir sipariş ödeme kaydı oluşturamıyor. Yani bu bir "sessiz eksik index" değil, "sessiz tam hizmet kesintisi".

İddianın iki abartılı/yanlış detayı düzeltilmeli:
(a) "nicepay_get_transaction_by_tid() rastgele birini döndürür" — üretimde ulaşılamaz; üç çağıranın üçü de $wc_order_id geçiyor (nicepay-functions.php:1010, gateway.php:702, admin/class-nicepay-admin.php:1140), kapsamsız `LIMIT 1` dalı (nicepay-functions.php:457) ölü kod.
(b) "çift onay/çift tahsilat tespiti devre dışı kalır" — abartılı; satır-içi replay koruması nicepay_claim_transaction_for_approval CAS'ı (gateway.php:434) + uniq_active_attempt + uniq_moid ile sağlanıyor ve bunlar migrasyonda sorunsuz kuruluyor. uniq_tid satırlar-arası defter invaryantı ve gateway.php:461'deki abort tetikleyicisi; kaybı derinlemesine savunma kaybı (TxTid imza kapsamında olmadığı için — validator:111 — saldırgan kontrollü, dolayısıyla gerçek bir kayıp).

Önerilen düzeltmeye ek madde: dbDelta'dan önce koşulsuz olarak `ALTER TABLE {$table} MODIFY tid varchar(50) NULL DEFAULT NULL` çalıştırılmalı (yalnızca sütun varsa ve zaten NOT NULL ise), sonra `UPDATE ... SET tid = NULL WHERE tid = ''`, sonra duplicate_tid ön kontrolü, sonra dbDelta; ve migrasyon sonunda uniq_moid/uniq_tid/uniq_active_attempt varlığı SHOW INDEX ile doğrulanıp eksikse WP_Error dönülmeli. Ayrıca aynı NOT NULL→NULL körlüğü `active_attempt_key`, `wc_order_id`, `payment_data`, `reconciliation_note`, `cancel_result_msg` gibi diğer nullable sütunlar için de kontrol edilmeli (1.x'te var olan ve NOT NULL tanımlı olanlar aynı tuzağa düşer).
- Gerekçe: İddianın her mekanik adımını hem kodda okudum hem de tek kullanımlık MariaDB 10.11 (test suite'in kendi image digest'i) üzerinde deneysel olarak doğruladım. Sonuç: iddia doğru; dahası, gerçek sonuç iddia edilenden DAHA AĞIR.

DOĞRULANAN ZİNCİR

1) Sıralama hatası gerçek. includes/class-nicepay-installer.php:268-269 dbDelta'dan (satır 182) ÖNCE çalışıyor ve sütun o an hâlâ 1.x şemasındaki `tid varchar(50) NOT NULL DEFAULT ''` (git show origin/main:nicepay-payment-gateway.php, create_tables()). includes/ ve admin/ altında tek bir `ALTER TABLE`/`MODIFY` yok (grep boş) — yani sütunu nullable yapan hiçbir kod yolu mevcut değil.

2) Scrub sessizce hiçbir şey yapmıyor — DENEYSEL OLARAK KANITLANDI. WP `wpdb::$incompatible_modes` (class-wpdb.php:644-651) STRICT_TRANS_TABLES/STRICT_ALL_TABLES/TRADITIONAL'ı siliyor ve set_sql_mode() bunu bağlantıya uyguluyor (class-wpdb.php:975-985). Bu sql_mode ile üç adet tid='' satırında `UPDATE ... SET tid = NULL WHERE tid = ''` çalıştırdım: 3× "Warning 1048 Column 'tid' cannot be null", affected=0, hata YOK, satırlar hâlâ ''. Dolayısıyla scrub_legacy_sensitive_data'nın 290. satırdaki `false === $result || ! empty($wpdb->last_error)` kontrolü tetiklenmiyor.

3) Ön kontrol boşluğu gerçek. duplicate_tid_count_sql (satır 93-99) `WHERE tid IS NOT NULL AND tid <> ''` ile tam da bu satırları dışlıyor; duplicate_moid_count_sql (satır 79-85) ise boş moid'leri dışlamıyor — yani ikisi arasındaki asimetri bilinçli ve tid tarafı korumasız. Ayrıca 1.x her satıra benzersiz moid yazıyor (git show origin/main:includes/class-nicepay-gateway.php:123,158-161 → generate_moid), yani moid ön kontrolü geçiyor ve akış sessiz yola giriyor.

4) 1062 ve hatanın kaybolması gerçek — DENEYSEL. Aynı tabloda `ALTER TABLE ... ADD UNIQUE KEY uniq_tid (tid)` → "ERROR 1062 Duplicate entry '' for key 'uniq_tid'"; information_schema.STATISTICS'te uniq_tid yok. WP core'da dbDelta index sorgularını `$cqueries`'e ekleyip (upgrade.php:3341) hepsini `foreach ($allqueries as $query) { $wpdb->query($query); }` (upgrade.php:3350-3354) ile hata kontrolü OLMADAN çalıştırıyor; `wpdb::query()` başında `$this->flush()` çağırıyor ve flush() `$this->last_error = ''` yapıyor (class-wpdb.php:1925). uniq_tid'den sonra 8 index sorgusu + ayrıca refund tablosu için ikinci dbDelta çağrısı (installer:183) çalıştığı için installer:185'teki `! empty($wpdb->last_error)` kontrolü hatayı asla göremez. installer:200'de sürüm 'güncel' yazılır, is_current() true döner (installer:39-54), admin sağlık göstergesi (admin/class-nicepay-admin.php:433) "yeşil" gösterir. Hiçbir yerde SHOW INDEX doğrulaması yok (grep: sadece tests/integration/schema-migration.php:28).

5) dbDelta NOT NULL→NULL körlüğü gerçek — KAYNAKTAN DOĞRULANDI. upgrade.php:3205 sadece `$tablefield->Type !== $fieldtype_lowercased` karşılaştırıyor; fieldtype regex'i `varchar(50)`'yi çıkarıyor, iki taraf da eşit → CHANGE COLUMN yok. DEFAULT karşılaştırması `preg_match("| DEFAULT '(.*?)'|i", ...)` (upgrade.php:3244) tırnaklı default arıyor; `DEFAULT NULL` eşleşmiyor → hiçbir şey değişmiyor.

6) Test boşluğu gerçek. tests/integration/schema-migration.php:28-32 sadece uniq_moid/uniq_active_attempt/idx_created_at/idx_updated_at'i ve yalnızca TAZE kurulumda kontrol ediyor; uniq_tid hiç kontrol edilmiyor ve gerçek 1.x CREATE TABLE gövdesi hiç fixture edilmiyor (satır 34-46 sadece kolon düşürüp geri ekliyor).

İDDİANIN KAÇIRDIĞI, DAHA AĞIR SONUÇ (deneysel)
Sütun NOT NULL kaldığı için `nicepay_save_transaction()` (includes/nicepay-functions.php:344 `'tid' => null`) artık HİÇ çalışmıyor: wpdb::_insert_replace_helper null değeri literal `NULL` olarak SQL'e basıyor (class-wpdb.php) ve tek satırlık INSERT'te MySQL/MariaDB non-strict modda bile hata veriyor. Test ettim: `INSERT INTO caseb (tid, moid) VALUES (NULL,'NEW1')` → "ERROR 1048 (23000) Column 'tid' cannot be null". WooCommerce checkout tam da bu yoldan geçiyor (includes/class-nicepay-gateway.php:269, tid hiç geçilmiyor) → $wpdb->insert false → nicepay_save_transaction false → yükseltilen her sitede HİÇBİR yeni ödeme kaydı oluşturulamıyor.
Ve bu, tid='' satır sayısından bağımsız: 2+ boş satırda index kurulamıyor ama INSERT yine 1048; 0-1 boş satırda ise index BAŞARIYLA kuruluyor (SHOW CREATE TABLE ile doğruladım: `tid varchar(50) NOT NULL DEFAULT ''` + `UNIQUE KEY uniq_tid`) ve INSERT yine 1048. Yani migrasyon "başarılı" raporluyor, is_current() true, ama ödeme akışı %100 ölü.

DÜZELTİLMESİ GEREKEN İKİ İDDİA DETAYI (aleyhte)
- "nicepay_get_transaction_by_tid() rastgele birini döndürür": üretimde ulaşılamaz. Üç çağıranın üçü de $wc_order_id geçiyor (nicepay-functions.php:1010, class-nicepay-gateway.php:702, admin/class-nicepay-admin.php:1140), yani her zaman satır 448-454'teki kapsamlı sorgu çalışıyor; kapsamsız `LIMIT 1` dalı (satır 457) ölü kod.
- "çift onay/çift tahsilat tespiti sessizce devre dışı kalır": abartılı. Aynı satır için replay koruması nicepay_claim_transaction_for_approval (gateway:434) CAS'ı + uniq_active_attempt + uniq_moid ile sağlanıyor ve bunlar etkilenmiyor (uniq_moid ve uniq_active_attempt migrasyonda sorunsuz kuruluyor). uniq_tid, satırlar-arası defter invaryantı / derinlemesine savunma. Yine de gateway:461-465'teki abort mekanizmasının tetikleyicisi olduğu doğru; TxTid imzayla korunmuyor (validator:111 `verify_auth_signature($payload['AuthToken'], $payload['Amt'], $payload['Signature'])` — TxTid ve Moid imza kapsamında değil), yani saldırgan kendi pending işlemi için gelen tarayıcı POST'unda TxTid'yi serbestçe değiştirebilir; index olmadan bu yazım başarılı olur.

---

### MONEY-001 — v1.x'ten yükseltilen işlemler hiç iade edilemez: flow backfill'i yok, flow='' toleransı ulaşılamaz kod

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | migration-money-correctness |
| **Konum** | [includes/nicepay-functions.php:450](../../../includes/nicepay-functions.php#L450) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (2/2) |

**Sorun ve kanıt**

```php
nicepay_get_transaction_by_tid() sipariş kimliği verildiğinde sorguyu sertçe daraltıyor: "SELECT * FROM {$table} WHERE tid = %s AND wc_order_id = %d AND flow = 'woocommerce' LIMIT 1" (satır 450). Ancak process_refund bu fonksiyonun sonucunu alıp hemen ardından legacy kayıtlara izin vermeye çalışıyor: "! in_array( (string) $transaction->flow, array( '', 'woocommerce' ), true )" (class-nicepay-gateway.php:704) ve nicepay_claim_transaction_for_refund da aynı toleransı taşıyor: "WHERE id = %d AND (flow = 'woocommerce' OR (flow = '' AND wc_order_id IS NOT NULL))" (nicepay-functions.php:734). origin/main'deki eski şemada flow sütunu hiç yok (nicepay-payment-gateway.php:110-144), NicePay_Installer::scrub_legacy_sensitive_data() ise yalnızca tid/card_no/auth_token/payment_data temizliği yapıyor — flow için hiçbir backfill UPDATE'i yok (class-nicepay-installer.php:262-300). Yani dbDelta sonrası tüm eski satırlar flow='' kalıyor ve lookup onları asla bulamıyor; flow='' için yazılmış iki tolerans dalı ölü kod.
```

**Başarısızlık senaryosu**

Mağaza 1.x ile 3 ay çalışıyor ve 500 ödenmiş sipariş var. 2.0.0'a yükseltilir; dbDelta flow sütununu '' varsayılanıyla ekler. Müşteri #412 numaralı siparişi için iade ister. Yönetici WooCommerce sipariş ekranında 'Refund via NicePay' der. process_refund çağrılır, tid meta doludur, ancak nicepay_get_transaction_by_tid(tid, 412) flow='woocommerce' filtresi yüzünden NULL döner ve fonksiyon WP_Error('nicepay_refund_error', 'Transaction ID not found.') ile biter. wc_create_refund oluşturduğu iade nesnesini siler; müşteriye hiçbir para dönmez ve hata mesajı sorunun migrasyon kaynaklı olduğunu söylemez.

**Etki**

Eklentiyi 1.x'ten 2.0.0'a yükselten her mağazada, yükseltmeden önce ödenmiş tüm siparişler WooCommerce üzerinden iade edilemez hale gelir. Operatör iadeyi NICEPAY konsolundan elle yapmak zorunda kalır ve WooCommerce iade kaydı ile PG arasında kalıcı mutabakat farkı oluşur. Kod okuyucu için de yanıltıcıdır: iki ayrı yerde legacy desteği varmış gibi görünür.

**Öneri**

NicePay_Installer içinde, unique indeksler eklenmeden önce çalışan idempotent bir backfill ekleyin ve şema sürümünü artırın: (a) `UPDATE {$table} SET flow = 'woocommerce' WHERE flow = '' AND wc_order_id IS NOT NULL`, (b) `UPDATE {$table} SET flow = 'standalone' WHERE flow = '' AND wc_order_id IS NULL AND moid <> ''`, (c) `UPDATE {$table} SET currency = 'KRW' WHERE currency = ''`, (d) `UPDATE {$table} SET captured_amount = amount, remaining_amount = amount WHERE status = 'paid' AND captured_amount = 0`, (e) mid/mode için o günkü seçili konfigürasyondan değil, veri yoksa refund_context_error yerine açık bir 'legacy_context_unverified' mesajı üretin. Backfill eklendikten sonra ya flow='' toleranslarını (gateway:704, functions:734) kaldırın ya da nicepay_get_transaction_by_tid'i `AND flow IN ('woocommerce','')` yapın — iki uç mutlaka aynı sözleşmeyi konuşmalı.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddiada geçen her satırı açıp okudum; kod tam olarak iddia edildiği gibi ve iddiayı çürüten hiçbir koruma/alternatif yol yok.

1) Lookup gerçekten sert filtreli. includes/nicepay-functions.php:438-458 — $wc_order_id > 0 verildiğinde sorgu `AND flow = 'woocommerce'` içeriyor (satır 450). Satır numarası doğru.

2) process_refund tek giriş noktasıdır ve legacy toleransı lookup'tan SONRA uygulanır. includes/class-nicepay-gateway.php:701-706 — önce `nicepay_get_transaction_by_tid( $tid, $order->get_id() )` (702), sonra `! in_array( (string) $transaction->flow, array( '', 'woocommerce' ), true )` (704). Lookup zaten flow='woocommerce' dışını elediği için 704'teki `''` dalı mantıksal olarak ulaşılamaz; kontrol tümüyle gereksiz.

3) İkinci tolerans da ulaşılamaz. nicepay_claim_transaction_for_refund (nicepay-functions.php:719, WHERE satırı 734: `WHERE id = %d AND (flow = 'woocommerce' OR (flow = '' AND wc_order_id IS NOT NULL))`) üretimde SADECE class-nicepay-gateway.php:785'ten çağrılıyor (grep: diğer tüm çağrılar tests/ altında). O çağrı da lookup'ın gerisinde olduğundan flow='' dalı yalnızca mock wpdb'li testlerde tetiklenebilir — ölü kod tespiti doğru.

4) Backfill gerçekten yok. `grep -n "flow" includes/class-nicepay-installer.php` HİÇBİR eşleşme vermiyor. scrub_legacy_sensitive_data (262-300) yalnızca tid/card_no/auth_token/payment_data UPDATE'leri kuruyor. flow yalnızca yeni kayıt eklenirken set ediliyor (class-nicepay-gateway.php:273 `'flow' => 'woocommerce'`); şemada varsayılan `varchar(20) NOT NULL DEFAULT ''` (class-nicepay-transaction-schema.php:28,49). Yani dbDelta sonrası tüm eski satırlar flow='' kalır.

5) Legacy satırların gerçekten var olabileceği doğrulandı: origin/main şemasında flow sütunu yok (nicepay-payment-gateway.php:110-144) ve eski eklenti de aynı `_nicepay_tid` meta'sını yazıyordu (origin/main:includes/class-nicepay-gateway.php:363), dolayısıyla senaryodaki "tid meta dolu ama satır bulunamaz" durumu birebir gerçekleşir.

EK BULGU (iddiayı zayıflatmıyor, güçlendiriyor): Eski şemada currency, mid, mode, captured_amount, remaining_amount sütunları da yok. Flow backfill'i tek başına eklense bile legacy satırlar class-nicepay-gateway.php:716-721'deki `'KRW' !== $transaction_currency || empty($transaction->mid) || empty($transaction->mode)` kontrolüne takılıp nicepay_refund_context_error ile reddedilir. Yani düzeltme tek bir UPDATE değil, iddianın (a)-(e) maddelerinin tamamını gerektirir; etki tahmini fazla değil eksik hesaplanmış.

Severity 'high' yerinde: para kaybı değil ama yükseltilen her mağazada tüm geçmiş siparişler için WooCommerce iadesi tümden kapanıyor ve hata mesajı ('Transaction ID not found.') kök nedeni gizliyor.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `confirmed`)

- Gerekçe: Her iddia unsurunu kodu okuyarak doğruladım; hiçbiri çürütülemedi.

1) Sorgu daraltması gerçek. includes/nicepay-functions.php:438-458 — sipariş kimliği verildiğinde tek yol `AND flow = 'woocommerce'` içeren dal.

2) Backfill gerçekten yok. `grep -rn "SET flow" --include='*.php' .` tüm repoda SIFIR eşleşme. includes/class-nicepay-installer.php dosyasında "flow" kelimesi hiç geçmiyor (grep boş). scrub_legacy_sensitive_data() (262-300) yalnızca tid/card_no/auth_token/payment_data işliyor. Dahası class-nicepay-transaction-schema.php:36-38 yorumu bunu açıkça itiraf ediyor: "legacy-value backfills are intentionally outside this schema definition" — ama şemanın dışında da hiçbir yerde yapılmıyor.

3) Eski şemada flow yok. origin/main:nicepay-payment-gateway.php create_tables() sütun listesinde flow, currency, mid, mode, captured_amount, cc_part_cl, clickpay_cl yok. dbDelta bunları COLUMN_VARCHAR_20 = NOT NULL DEFAULT '' ile ekler → tüm eski satırlar flow=''.

4) Senaryo gerçekten ulaşılabilir. origin/main:includes/class-nicepay-gateway.php:363 `$order->update_meta_data( '_nicepay_tid', $tid )` — yani 1.x siparişlerinde tid meta DOLU. origin/main gateway:359 başarılı ödemeye `status = 'paid'` yazıyor — yani satır durum kapısını da geçecek durumda. Dolayısıyla yönetici "Refund" dediğinde akış tam olarak iddia edildiği yerde ölüyor: gateway.php:702 tid dolu → 703 lookup flow filtresi yüzünden NULL → 704-706 `! $transaction` dalı → WP_Error 'Transaction ID not found.'

5) Ölü kod iddiası da doğru. gateway.php:704'teki `''` toleransı, kendisinden bir satır önceki lookup zaten flow='woocommerce' dayattığı için asla '' göremez. nicepay_claim_transaction_for_refund'un TEK üretim çağıranı gateway.php:785 (grep ile doğrulandı, diğer eşleşmeler yalnızca tests/) ve o da bu lookup'ın arkasında — yani functions.php:734'teki `(flow = '' AND wc_order_id IS NOT NULL)` dalı da üretimde ulaşılamaz.

6) Test boşluğu ek kanıt: tests/integration/schema-migration.php içinde flow geçen 8 satırın hepsi 'woocommerce'; legacy flow='' satırı hiç simüle edilmiyor, bu yüzden regresyon yakalanmıyor.

Severity 'high' yerinde: para YANLIŞ hareket etmiyor (fail-closed), operatörün NICEPAY konsolundan manuel iade yolu var — bu yüzden critical değil. Ama yükseltme öncesi TÜM ödenmiş siparişler için Woo iadesi sessizce ve yanıltıcı bir mesajla kırılıyor; medium'a indirilecek kadar dar değil.

Tek düzeltmem, iddianın kendi lehine olan bir nüans: etki iddia edilenden DAHA geniş. Sadece flow filtresini gevşetmek sorunu çözmez — legacy satırlarda currency='', mid='', mode='' olduğu için akış bu sefer gateway.php:717-721'deki `'KRW' !== $transaction_currency || empty($transaction->mid) || empty($transaction->mode)` kapısına takılıp 'nicepay_refund_context_error' döner. Yani flow, en az üç kapıdan yalnızca ilki. İddianın önerisi (c) ve (e) maddeleriyle bunu zaten kapsadığı için bu bir çürütme değil, kapsam teyidi.

---

### MONEY-003 — active_attempt_key kilidi hiçbir kurtarma yolunda serbest bırakılmıyor — sipariş kalıcı olarak ödenemez hale geliyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | state-machine |
| **Konum** | [includes/nicepay-functions.php:970](../../../includes/nicepay-functions.php#L970) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
Bayat onay kurtarma sorgusu active_attempt_key'e dokunmuyor: "UPDATE {$table} SET status = 'needs_reconciliation', approval_state = 'needs_reconciliation', reconciliation_status = 'required', reconciliation_note = 'stale_approval_attempt' WHERE status = 'approving' AND approval_state = 'approving' AND approval_started_at < (UTC_TIMESTAMP() - INTERVAL 30 MINUTE)" (satır 970-977). nicepay_get_mismatched_approval_audit() da bırakmıyor (nicepay-functions.php:213-224) ve NicePayTransactionRepositoryTest.php:283 bunu bilinçli olarak assert ediyor. nicepay_abandon_pending_transactions() yalnızca status='pending' satırları için NULL'lıyor (satır 933-935). Şemada UNIQUE KEY uniq_active_attempt (active_attempt_key) var (class-nicepay-transaction-schema.php:126), dolayısıyla generate_payment_form içindeki "'active_attempt_key' => 'woocommerce:' . (string) $order->get_id()" (class-nicepay-gateway.php:275) INSERT'i duplicate key ile başarısız olur ve tek çıktı jenerik bir mesajdır (satır 296-303). Yönetici arayüzünde kilidi serbest bırakacak hiçbir aksiyon yok (admin/class-nicepay-transactions.php'de active_attempt_key hiç geçmiyor) ve NicePay_Retention de bu satırları temizlemiyor (class-nicepay-retention.php:315).
```

**Başarısızlık senaryosu**

Müşteri 89.000 KRW'lik siparişte kart onayını tamamlar. request_approval çağrısı sırasında PHP-FPM worker'ı 30 sn timeout ile öldürülür. Satır status='approving', active_attempt_key='woocommerce:1041' kalır. Müşteri sipariş e-postasındaki 'Pay for order' bağlantısına tıklar; receipt_page çalışır, nicepay_abandon_pending_transactions hiçbir şeyi değiştirmez (satır pending değil), nicepay_save_transaction duplicate key alır ve ekranda 'Payment initialization failed. Please return to checkout.' yazar. Ertesi gün cron satırı needs_reconciliation yapar ama kilit kalır; sipariş bir daha asla bu bağlantıdan ödenemez.

**Etki**

PHP süreci onay çağrısı sırasında ölürse (fatal, max_execution_time, worker restart) satır approving durumunda kilitle kalır. Sipariş WooCommerce'te hâlâ 'pending' yani ödenebilir görünür, fakat her ödeme denemesi INSERT'te duplicate key ile düşer ve müşteri sonsuz bir 'Payment initialization failed' döngüsüne girer. 30 dakika + saatlik cron sonrası kayıt needs_reconciliation olur ama kilit hâlâ durur; kurtarmanın tek yolu doğrudan SQL çalıştırmaktır. Aynı sorun approval-binding mismatch dalında da oluşur.

**Öneri**

Kilidin ömrünü sınırlayın ve operatöre bir kaçış yolu verin. (1) Bayat onay sorgusuna `active_attempt_key = NULL` ekleyin ama aynı UPDATE'te siparişi de kilitleyin — cron çalıştıktan sonra ilgili WC siparişini 'on-hold' + 'needs_reconciliation' notu ile işaretleyen bir kanca ekleyin ki müşteri boş yere denemesin. (2) nicepay_get_mismatched_approval_audit()'e de `'active_attempt_key' => null` ekleyin; para güvenliği zaten sipariş on-hold olmasıyla sağlanıyor. (3) Transactions ekranına, needs_reconciliation satırları için 'Mark reconciled / release attempt lock' aksiyonu ekleyin (nonce + nicepay_manage_transactions_capability + sipariş notu). (4) nicepay_save_transaction'ı duplicate key ile diğer DB hatalarını ayırt edecek şekilde değiştirin (`$wpdb->last_error` içinde 'Duplicate entry' kontrolü) ve kullanıcıya 'Bu sipariş için devam eden bir ödeme var, lütfen mağazayla iletişime geçin' gibi doğru mesajı gösterin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: active_attempt_key kilidi, 'approving' durumunda takılı kalan satırlar için hiçbir otomatik veya yönetici yolunda serbest bırakılmıyor. Bayat onay cron'u (nicepay-functions.php:970-977) satırı needs_reconciliation yapar ama kilidi bırakmaz VE WooCommerce siparişine hiç dokunmaz; sipariş 'pending' kaldığı için receipt_page guard'ını geçer, nicepay_abandon_pending_transactions (933-935) sadece 'pending' satırları hedeflediğinden hiçbir şey yapmaz ve nicepay_save_transaction UNIQUE uniq_active_attempt ihlaliyle false döner (nicepay-functions.php:380-385) — müşteri kalıcı olarak yanlış "Payment initialization failed / The order remains payable" mesajı alır; kurtarma yalnızca elle SQL ile mümkündür (admin'de aksiyon yok, retention satırı hariç tutar). Approval-binding mismatch dalı (gateway:550-564) için ise etki daha düşüktür: orada sipariş hemen on-hold'a alındığı için müşteri döngüye girmez; oradaki eksiklik, manuel mutabakat sonrası kilidi bırakacak bir yönetici aksiyonunun bulunmamasıdır (medium).
- Gerekçe: Kodu satır satır okudum; iddianın çekirdeği DOĞRU ve satır referansları dosyanın şu anki haliyle birebir eşleşiyor.

Doğrulananlar:
1. Bayat onay kurtarma UPDATE'i (nicepay-functions.php:970-977) yalnızca status/approval_state/reconciliation_* alanlarını değiştiriyor; `active_attempt_key`e dokunmuyor. Diğer iki UPDATE (933 ve 959) ise açıkça `active_attempt_key = NULL` içeriyor — yani ihmal, bilerek/bilmeyerek yalnızca 'approving' dalında var.
2. nicepay_get_mismatched_approval_audit() dönüş dizisinde (213-224) `active_attempt_key` anahtarı yok; NicePayTransactionRepositoryTest.php:283 bunu `assertArrayNotHasKey` ile sabitliyor.
3. Şemada gerçekten `UNIQUE KEY uniq_active_attempt (active_attempt_key)` var (class-nicepay-transaction-schema.php:126) ve generate_payment_form her denemede aynı deterministik değeri yazıyor: `'active_attempt_key' => 'woocommerce:' . (string) $order->get_id()` (class-nicepay-gateway.php:275). nicepay_save_transaction (nicepay-functions.php:380-385) $wpdb->insert dönüşünü ayrıştırmadan false döndürüyor; tek çıktı 296-303'teki jenerik mesaj.
4. Kurtarma yolu yok: receipt_page (154-158) sadece `recover_paid_order_from_ledger` deniyor, o da `'paid' !== status || 'approved' !== approval_state` ise erken false dönüyor (167-171) — 'approving'/'needs_reconciliation' satırında kilidi bırakmaz. nicepay_abandon_pending_transactions WHERE'i `status = 'pending' AND approval_state = 'pending'` (934-935) olduğu için hiçbir şey yapmaz (ve `false` dönmediği için 271-275'teki erken çıkış da tetiklenmez).
5. admin/class-nicepay-transactions.php içinde `active_attempt_key` hiç geçmiyor (grep tüm repoda doğrulandı); tek yazma aksiyonları ajax_cancel_transaction ve CSV export. NicePay_Retention::eligible_row_predicate (class-nicepay-retention.php:315) `ledger.active_attempt_key IS NULL` şartı koyduğu için bu satırları asla temizlemez. Yani gerçekten tek kurtarma yolu doğrudan SQL.
6. Kilitlemenin diğer tüm yollarda bilinçli olarak bırakıldığı görülüyor (gateway 203, 318, 359, 528, 664, 675; return-handler 134, 191, 222) — bu da 'approving' dalındaki eksikliğin tutarsızlık olduğunu güçlendiriyor.

Düzeltilmesi gereken tek nokta: iddianın son cümlesi ("Aynı sorun approval-binding mismatch dalında da oluşur") müşteri deneyimi açısından yanıltıcı. O dalda kod hemen ardından siparişi on-hold'a alıyor (class-nicepay-gateway.php:558, aynısı return-handler'da) — dolayısıyla receipt_page'in `! $order->needs_payment()` guard'ı (gateway:149-152) devreye girer ve müşteri "Payment initialization failed" döngüsüne DEĞİL, "This order is not eligible for a new NicePay payment." mesajına düşer. Mismatch dalında kilidin tutulması para güvenliği açısından kasıtlıdır; oradaki gerçek eksiklik, operatör manuel mutabakattan sonra siparişi tekrar ödenebilir yapmak isterse kilidi bırakacak bir arayüzün olmaması (yani öneri #3 geçerli, ama etki 'high' değil 'medium').

Ayrıca 'stale_approval_attempt' dalında sipariş HİÇBİR ZAMAN on-hold'a alınmıyor (cron sadece SQL çalıştırıyor, WC siparişine dokunmuyor), yani WooCommerce tarafında sipariş 'pending' kalır → needs_payment() true → guard geçilir → duplicate key. Bu, iddianın asıl senaryosunu tam olarak doğrular. 298. satırdaki sipariş notu "The order remains payable." demesi de bu durumda düpedüz yanlış bilgi veriyor — iddiaya ek bir kanıt.

Severity 'high' korunmalı: müşteri tarafında kalıcı ödenemez sipariş + yanlış hata mesajı + yalnızca elle SQL ile kurtarma. Not: sipariş bazında sıkışma; müşteri yeni bir sipariş oluşturursa (yeni order_id → yeni attempt key) ödeyebilir, mağaza tamamen bloke olmaz.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Bayat 'approving' kurtarma cron'u satırı needs_reconciliation'a çevirirken ne active_attempt_key kilidini bırakıyor ne de ilgili WooCommerce siparişini 'on-hold' yapıyor (nicepay-functions.php:970-977; cron kancası nicepay-payment-gateway.php:104). Sonuç: PHP süreci onay çağrısı sırasında ölürse sipariş WooCommerce'te 'pending'/ödenebilir kalır, ancak receipt_page'deki her yeni deneme UNIQUE uniq_active_attempt (schema:126) yüzünden INSERT'te düşer ve nicepay_save_transaction hata türünü ayırt etmediği için (functions:380-385) müşteriye jenerik "Payment initialization failed" gösterilir (gateway:296-303). Kilit hiçbir kurtarma yolunda bırakılmaz (gateway:174-178 yalnızca paid/approved satırlarını kurtarır), yönetici arayüzünde serbest bırakma aksiyonu yoktur ve retention satırı asla temizlemez (retention:315) — çözüm yalnızca doğrudan SQL'dir. Kilidin tutulması para güvenliği açısından kasıtlıdır; asıl kusur siparişin on-hold'a alınmaması, operatöre kaçış yolu bırakılmaması ve duplicate-key hatasının doğru mesaja çevrilmemesidir. Approval-binding mismatch dalı bu döngüden etkilenmez, çünkü orada sipariş açıkça on-hold yapılır (gateway:558) ve receipt_page 150-153'te doğru mesajla durdurulur; o dalda kalan sorun sadece purge engeli ve admin aksiyonu eksikliğidir.
- Gerekçe: Çekirdek mekanik iddia doğrulandı, ancak (a) mismatch dalına ilişkin "aynı sorun" ifadesi müşteri deneyimi açısından yanlış ve (b) severity abartılmış.

DOĞRULANANLAR (kod okundu):
1. Bayat onay kurtarma UPDATE'i (nicepay-functions.php:970-977) gerçekten active_attempt_key'e dokunmuyor. Hemen üstündeki iki sorgu (933, 959) dokunuyor — yani ihmal göze çarpıyor.
2. nicepay_abandon_pending_transactions yalnızca status='pending' AND approval_state='pending' satırlarını hedefliyor (935), dolayısıyla 'approving' kilidi bırakılmıyor.
3. generate_payment_form her çağrıda 'woocommerce:<order_id>' ile YENİ satır INSERT ediyor (gateway:269-294); şemada UNIQUE KEY uniq_active_attempt var (schema:126); nicepay_save_transaction $wpdb->insert dönüşü false olunca hata ayrımı yapmadan false dönüyor (functions:380-385) ve kullanıcı jenerik "Payment initialization failed" görüyor (gateway:296-303). Yani INSERT'in duplicate key ile düşüp jenerik mesaj üretmesi zinciri tam olarak iddia edildiği gibi.
4. Kurtarma yolları sınırlı: receipt_page/process_payment yalnızca satır status='paid' + approval_state='approved' ise kilidi bırakıyor (gateway:115-121, 155-160, 173-204). 'approving'/'needs_reconciliation' satırı bu filtreden geçmez (174-178) → kilit kalır.
5. Yönetici arayüzünde kilidi bırakan aksiyon YOK: `grep -rn active_attempt_key admin` hiç sonuç vermiyor.
6. NicePay_Retention::eligible_row_predicate "ledger.active_attempt_key IS NULL" şartı koştuğu için (retention:315) satır asla temizlenmiyor — kalıcı.
7. Cron hook'u yalnızca DB UPDATE yapıyor; sipariş tarafında hiçbir şey yok (nicepay-payment-gateway.php:104 -> nicepay_expire_pending_transactions), yani sipariş 'pending'/ödenebilir kalıyor. Bu, iddianın "müşteri boşuna dener" kısmını doğruluyor.

İSTİSMAR/ÜRETİLEBİLİRLİK: senaryo gerçekçi ama tetikleyicisi dar. Önkoşul, request_approval sırasında sürecin ölmesi (fatal / max_execution_time / worker kill). Bu adımda dış HTTP çağrısı yapıldığı için pencere gerçekten var. Sonrasında: müşteri sipariş e-postasındaki pay-for-order bağlantısına GET ile gider (rol: giriş yapmış veya order key taşıyan misafir), key doğrulaması geçer (gateway:144-148), needs_payment() hâlâ true (sipariş 'pending'), abandon hiçbir satır etkilemez, INSERT duplicate key ile düşer, jenerik mesaj. Tekrarlanabilir ve kalıcı. Kurtarma yalnızca doğrudan SQL ile — doğru.

DÜZELTİLEN DETAYLAR:
- "Aynı sorun approval-binding mismatch dalında da oluşur" yanıltıcı. O dalda sipariş açıkça 'on-hold' yapılıyor (gateway:558), böylece receipt_page 150-153'teki `! $order->needs_payment()` kontrolü devreye girer ve müşteri "This order is not eligible for a new NicePay payment." mesajını görür — sonsuz jenerik hata döngüsü YOK. Orada kilidin tutulması kasıtlı fail-closed para güvenliği tasarımıdır; kalan sorun yalnızca operasyonel (retention purge engeli + admin serbest bırakma aksiyonunun yokluğu). Bu nedenle iddianın 2. önerisi (mismatch audit'e active_attempt_key => null eklemek) tasarımı zayıflatır, tavsiye edilmez.
- Kilidin 'approving' satırında tutulması da aslında kasıtlıdır: onay NICEPAY tarafında başarılı olduysa ikinci bir ödeme denemesini engeller. Yani asıl kusur "kilit bırakılmıyor" değil, "cron satırı needs_reconciliation'a çevirirken WooCommerce siparişini on-hold'a almıyor ve operatöre hiçbir çıkış yolu bırakmıyor" — yani UX/operability boşluğu.
- Severity: 'high' abartılı. Para kaybı, çifte çekim veya yetki aşımı yok; etki tek bir siparişin pay-link'inin kalıcı olarak kullanılamaz olması, müşteri yeni sipariş açabiliyor, tetikleyici de süreç ölümü gibi seyrek bir olay. Kalıcı olması ve yalnızca SQL ile çözülebilmesi nedeniyle 'medium' uygundur.

---

### MONEY-005 — KRW ondalıksız varsayılıyor ama WooCommerce'in ondalık ayarı hiç zorlanmıyor — tahsil edilen tutar sipariş toplamından sessizce sapıyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | money-precision |
| **Konum** | [includes/nicepay-functions.php:1513](../../../includes/nicepay-functions.php#L1513) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
nicepay_normalize_amount() ondalığı yuvarlıyor: "if ( '' !== $decimal && (int) $decimal[0] >= 5 ) { $integer = nicepay_increment_integer_string( $integer ); }" (satır 1513-1515) ve bu davranış testte de sabitlenmiş (tests/unit/NicePayFunctionsTest.php:69-75: 10000.50 → '10001'). Ödeme tutarı bu fonksiyondan geçiyor: "$amount = nicepay_get_amount( $order->get_total(), $order->get_currency() );" (class-nicepay-gateway.php:213). Ancak is_available() yalnızca para birimi kodunu kontrol ediyor ("! nicepay_is_supported_currency( get_woocommerce_currency() )", satır 93-96) — woocommerce_price_num_decimals / wc_get_price_decimals hiçbir yerde okunmuyor (tüm includes/admin/templates ağacında tek eşleşme yok). WooCommerce'in varsayılanı 2'dir ve KRW için otomatik olarak 0'a düşmez.
```

**Başarısızlık senaryosu**

Mağaza woocommerce_price_num_decimals = 2 (varsayılan). Sipariş toplamı 10000.50 KRW. Müşteriden 10001 KRW tahsil edilir (nicepay_get_amount → '10001'), sipariş 10000.50 olarak 'paid' işaretlenir, defterde captured_amount=10001. Yönetici iki eşit kısmi iade yapar: birincisi 5000.25 → normalize '5000', get_total_refunded()='5000.25' → '5000', beklenen '5000' → geçer, PG'den 5000 KRW iade edilir. İkincisi yine 5000.25 → cancel_amt '5000', ledger refunded '5000', beklenen '10000'; ancak get_total_refunded() artık '10000.50' → normalize '10001' → hash_equals başarısız → ikinci iade kalıcı olarak reddedilir. Müşteri 10001 KRW ödemiş, 5000 KRW geri almış, kalan 5001 KRW eklenti üzerinden iade edilemez.

**Etki**

Varsayılan ayarlı bir Kore mağazasında vergi/kupon hesapları 2 ondalıklı toplamlar üretir. Müşteriden tahsil edilen tutar sipariş toplamından farklı olur (en fazla 0.5 KRW) ve WooCommerce sipariş tam ödenmiş sayılır. Asıl ciddi sonuç iade tarafında: yuvarlama farkı biriktiği için son kısmi iade MONEY-004'teki eşitlik kapısına takılır ve müşterinin parasının bir kısmı iade edilemez hale gelir.

**Öneri**

Ondalık politikasını fail-closed hale getirin. (1) is_available() içine ekleyin: `if ( function_exists( 'wc_get_price_decimals' ) && 0 !== (int) wc_get_price_decimals() ) { return false; }` — KRW zero-decimal olduğu için bu doğru davranıştır. (2) Admin readiness listesine (admin/class-nicepay-admin.php:427-440) 'Price decimals must be 0 for KRW' satırı ekleyin ve düzeltme bağlantısı verin. (3) generate_payment_form içinde ek bir emniyet: `if ( nicepay_normalize_amount( $order->get_total(), 'KRW' ) !== rtrim( rtrim( (string) $order->get_total(), '0' ), '.' ) ) { hata }` yerine daha basiti — sipariş toplamı tamsayı değilse ödemeyi başlatmayın ve operatöre net bir mesaj gösterin. (4) İade kapısını sipariş toplamı yerine defterin captured_amount'ına dayandırın (MONEY-004 önerisiyle birlikte).

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Düzeltilmiş iddia: Çekirdek iddia doğrudur ve olduğu gibi kalabilir. Tek düzeltme: iade tarafındaki sonuç "kalan bakiye kalıcı olarak iade edilemez" değil, "eşit kısmi iadeler öngörülemez biçimde 'WooCommerce refund records do not match this NicePay refund request.' hatasıyla reddedilir; ancak yönetici tutarı 1 kuruş kaydırarak (ör. 5000.24, ardından 0.50) tesadüfen geçebilir" şeklindedir — yani hata teşhis edilemez ve operatörü tahmin oyununa zorlar. Ayrıca "WooCommerce KRW için otomatik olarak 0'a düşmez" ifadesi çoğu kurulum için doğrudur, fakat WooCommerce Admin onboarding akışı para birimi seçimine göre woocommerce_price_num_decimals'ı ayarlayabilir; dolayısıyla sorun her mağazada değil, ayarın 2'de kaldığı mağazalarda ortaya çıkar. Bu, fail-closed bir ondalık kapısının gerekliliğini değiştirmez.
- Gerekçe: Her kod iddiasını dosyaların nihai halinde açıp doğruladım; satır numaraları birebir tutuyor.

1) Yuvarlama gerçekten var: `nicepay_normalize_amount()` içinde 1513-1515 satırları kesirli kısmın ilk hanesi >=5 ise tamsayıyı artırıyor (half-up), aksi halde kesiri atıyor (truncate). Test dosyası bu davranışı sabitlemiş (tests/unit/NicePayFunctionsTest.php:69-71: `assertSame('10001', nicepay_get_amount(10000.50,'KRW'))`).

2) Ödeme tutarı gerçekten bu fonksiyondan geçiyor: class-nicepay-gateway.php:213 `nicepay_get_amount( $order->get_total(), ... )`; aynı normalizasyon 181, 485 ve 730'da da kullanılıyor. Yani sipariş toplamı kesirliyse PG'ye giden Amt ile Woo'nun sipariş toplamı ayrışıyor ve fark hiçbir yerde tespit edilmiyor.

3) Ondalık ayarı hiç okunmuyor: `grep -rn "wc_get_price_decimals|price_num_decimals|get_price_decimals|wc_get_rounding_precision" includes admin templates assets tests` → SIFIR eşleşme. `is_available()` (satır 76-103) yalnızca enabled / installer / kimlik bilgileri / etkin yöntem / para birimi kodu / SSL kontrol ediyor; ondalık politikası yok. Admin readiness listesi (admin/class-nicepay-admin.php:432-440) da 7 kontrol içeriyor, ondalık kontrolü yok. Yani iddiayı çürüten bir üst-katman guard'ı YOK; başka yerde de fail-closed bir koruma bulamadım.

4) İade kapısı iddiası da doğru: satır 730-732 `hash_equals($captured, $order_total)` — 10000.50 → '10001' olduğu için ilk kapı geçiliyor (yani drift burada yakalanmıyor). Asıl kilit satır 754-758: `nicepay_normalize_ledger_amount((string)$order->get_total_refunded())` ile `refunded + cancel_amt` hash_equals ile karşılaştırılıyor. `nicepay_normalize_ledger_amount()` (1564-1574) sadece "tam sıfır" için özel durum yapıyor, geri kalanı yine half-up yuvarlayan `nicepay_normalize_amount()`'a devrediyor. Senaryodaki 2×5000.25 akışı: 1. iade geçer (5000.25→'5000', beklenen '5000'), 2. iade `get_total_refunded()=10000.50→'10001'` vs beklenen '10000' → hash_equals fail → 'WooCommerce refund records do not match this NicePay refund request.' Bire bir doğrulandı.

Tek düzeltmem impact metninde: kalan bakiye "kalıcı olarak iade edilemez" değil — yönetici tutarı elle kaydırırsa (ör. 5000.24 sonra 0.50) kapı geçilebilir. Bu, bulguyu geçersiz kılmıyor; sadece sonucu "keyfi/anlaşılmaz şekilde reddedilen iade + operatörün tahmin oyunu oynaması" olarak yumuşatıyor. Severity high olarak kalmayı hak ediyor: hem sessiz para sapması hem de kullanıcıya hiçbir teşhis vermeyen iade reddi var.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: KRW zero-decimal varsayılıyor ama WooCommerce'in ondalık ayarı hiçbir yerde okunmuyor veya zorlanmıyor (`wc_get_price_decimals` üretim kodunda hiç geçmiyor; is_available() yalnızca para birimi kodunu kontrol ediyor — class-nicepay-gateway.php:93-96; readiness paneli de kontrol etmiyor — admin/class-nicepay-admin.php:432-440). Ondalık ayarı 0'dan farklı bir mağazada kesirli bir sipariş toplamı oluştuğunda (yüzdelik kupon / kesirli vergi oranı), nicepay_normalize_amount() yarım-yukarı yuvarlar (nicepay-functions.php:1513-1515) ve tahsil edilen tutar sipariş toplamından |Δ| < 1 KRW sapar (fazla ya da eksik); sipariş yine de tam ödenmiş işaretlenir çünkü tüm binding kontrolleri (213, 485-491, 180-183, 730-733) aynı normalize edilmiş değeri karşılaştırır. Asıl operasyonel etki iade tarafındadır: yöneticinin varsayılan "kalanı iade et" aksiyonu (tam kesirli bakiye, ör. 5000.25) 754-758'deki eşitlik kapısına takılır ve "WooCommerce refund records do not match this NicePay refund request." gibi teşhis edilemez bir hatayla reddedilir. Ancak bu KALICI bir kilit DEĞİLDİR — yönetici yuvarlak bir tutar (5000.00) girerse kapı geçer ve iade işlenir; kalıcı olarak sıkışan tutar yalnızca yuvarlama artığıdır (≤ 1 KRW), çünkü kalan kesirli Woo bakiyesi (0.25) normalize edilirken false döner (nicepay-functions.php:1517-1520). Sonuç: sipariş başına ≤1 KRW mutabakat artığı, sonsuza dek 'partially_refunded' kalan işlem kayıtları ve anlaşılmaz bir yönetici hata mesajı.
- Gerekçe: ÇEKİRDEK MEKANİZMA DOĞRU, KANITLANDI — ama sonuç/severity abartılmış.

Doğrulanan kısımlar (hepsini kendim okudum):
1. `nicepay_normalize_amount()` gerçekten yarım-yukarı yuvarlıyor (nicepay-functions.php:1513-1515) ve bu davranış testte sabitlenmiş (tests/unit/NicePayFunctionsTest.php:69-71, `assertSame('10001', nicepay_get_amount(10000.50,'KRW'))`).
2. Ödeme tutarı bu yoldan geçiyor (class-nicepay-gateway.php:213).
3. Ondalık politikası HİÇBİR YERDE zorlanmıyor. `grep -rni "decimal"` tüm repoda (vendor/node_modules hariç) yalnızca şema sütunları (`decimal(14,2)`), bir test fixture'ı ve eski `docs/analysis/*.md` bulgularını döndürüyor — `wc_get_price_decimals` / `woocommerce_price_num_decimals` tek bir üretim kodu satırında bile geçmiyor. `is_available()` (76-103) yalnızca kod bazında para birimi kontrol ediyor (93-96).
4. Admin readiness paneli (admin/class-nicepay-admin.php:432-440) 7 kontrol içeriyor (schema, HTTPS, mod, kimlik bilgileri, currency, methods, cron) — ondalık kontrolü YOK. İddianın "readiness listesine ekleyin" önerisi geçerli.
5. Sipariş "tam ödenmiş" işaretleniyor: dönüş yolundaki binding (485-491) `nicepay_get_amount($order->get_total())` ile karşılaştırıyor, yani 10000.50 → '10001' saklanan '10001' ile eşleşiyor; sapma hiçbir yerde yakalanmıyor. Aynı durum recover_paid_order_from_ledger'da (180-183) da geçerli.
6. İkinci kısmi iadenin reddi de doğru: 754-758'deki `hash_equals($expected_order_refunded, $order_refunded)` kapısı, `get_total_refunded()`='10000.50' → normalize → '10001' iken beklenen '10000' olduğu için başarısız oluyor.

ABARTILAN / YANLIŞ OLAN KISIM — "kalan 5001 KRW eklenti üzerinden iade edilemez":
Bunu adım adım kodla yürüttüm ve YANLIŞ. Yönetici tam kalanı (5000.25) girerse ret alır, ama 5000.00 girerse geçer:
- cancel_amt = normalize('5000.00') = '5000'
- expected_order_refunded = add('5000','5000') = '10000'
- get_total_refunded() = 5000.25 + 5000.00 = 10000.25 → nicepay_normalize_ledger_amount → ondalık '25', ilk hane 2 < 5 → '10000'
- hash_equals('10000','10000') → GEÇER, PG'den 5000 KRW iade edilir.
Yani iade edilemeyen tutar 5001 değil, yalnızca yuvarlama artığı olan **1 KRW**'dir. Bu 1 KRW gerçekten kalıcı olarak sıkışır (kalan Woo bakiyesi 0.25; normalize('0.25') → tamsayı '0' → ltrim → '' → 1518-1520'de `false` → "Refund amount is invalid for this currency"), işlem sonsuza dek `partially_refunded`/remaining_amount=1 kalır.

SEVERITY — high değil, medium:
- Sipariş başına parasal sapma matematiksel olarak |Δ| < 1 KRW (~0.0007 USD). Yarım-yukarı olduğu için hem fazla tahsilat (10000.50 → 10001) hem eksik tahsilat (10000.49 → 10000) mümkün.
- Ön koşullar iddiada sunulduğundan daha dar: yalnızca `price_num_decimals != 0` yetmez, ayrıca toplamın gerçekten kesirli çıkması gerekir (yüzdelik kupon, kesirli vergi oranı, kargo vergisi). Tamsayı fiyat + %10 KDV'li tipik Kore mağazasında toplam tamsayı kalır ve bug hiç tetiklenmez.
- İstismar edilebilirlik: saldırgan kontrollü bir vektör YOK. Bu bir mağaza yapılandırma hatası; kötü niyetli bir müşteri sepetiyle 0.5 KRW'lik sapmayı tekrar tekrar üretse bile ekonomik kazanç sıfıra yakın.
- Gerçek zarar operasyoneldir: yöneticinin "kalanı iade et" (varsayılan, tam bakiye) aksiyonu sessizce ve **anlaşılmaz** bir mesajla ("WooCommerce refund records do not match this NicePay refund request") reddedilir; yönetici neden reddedildiğini anlayamaz ve elle yuvarlak tutar denemesi gerektiğini bilemez. Artı sipariş başına ≤1 KRW kalıcı mutabakat artığı.

Öneri kısmı büyük ölçüde sağlam; ancak (1)'deki `is_available()` fail-closed dönüşü tek başına kötü bir UX'tir — ağ geçidi ödeme sayfasında sebepsiz kaybolur; readiness paneli + admin notice ile birlikte verilmelidir. Öneri (3)'ün ikinci hali ("sipariş toplamı tamsayı değilse ödemeyi başlatma") doğru ve yeterlidir.

---

### MONEY-016 — approving/needs_reconciliation terminal durum: active_attempt_key hiç serbest bırakılmıyor ve operatör için çözüm yolu yok

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | state-machine |
| **Konum** | [includes/nicepay-functions.php:970](../../../includes/nicepay-functions.php#L970) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (2/2) |

**Sorun ve kanıt**

```php
Bayat approval kurtarma sorgusu active_attempt_key'e dokunmuyor:

    $stale_sql = "UPDATE {$table}
                  SET status = 'needs_reconciliation',
                      approval_state = 'needs_reconciliation',
                      reconciliation_status = 'required',
                      reconciliation_note = 'stale_approval_attempt'
                  WHERE status = 'approving' AND approval_state = 'approving'
                    AND approval_started_at < (UTC_TIMESTAMP() - INTERVAL 30 MINUTE)";

`grep -rn active_attempt_key includes admin` çıktısında NULL'a çeken tek yerler: abandon (yalnızca status='pending'), expire (yalnızca status='pending'), abort/başarı/başarısızlık yolları. 'approving' veya 'needs_reconciliation' durumundaki bir satırın kilidini bırakan hiçbir kod ve hiçbir admin eylemi yok.

Kilit dururken yeni deneme insert'i UNIQUE uniq_active_attempt yüzünden başarısız olur ve kullanıcı şunu görür:

    echo '<p>' . esc_html__( 'Payment initialization failed. Please return to checkout.', ... ) . '</p>';

Ayrıca reconciliation_status='required' olduğu sürece process_refund 713. satırda 'nicepay_refund_state_error' döndürür ve admin listesinde iade butonu $can_refund=false ile gizlenir. Yönetici panelinde bu durumu çözecek (kilidi bırak / mutabakatı kapat) hiçbir buton yok.
```

**Başarısızlık senaryosu**

Sipariş #1042 için müşteri NICEPAY penceresinde kartı onaylar, ReturnURL POST'u gelir, nicepay_claim_transaction_for_approval başarılı olur (status='approving', active_attempt_key='woocommerce:1042'), tam bu sırada PHP-FPM worker'ı max_execution_time nedeniyle öldürülür. Saatlik cron 30 dakika sonra satırı needs_reconciliation yapar ama active_attempt_key'i 'woocommerce:1042' olarak bırakır. Müşteri e-postadaki 'Ödemeyi tamamla' bağlantısına tıklar → receipt_page → generate_payment_form → nicepay_save_transaction, uniq_active_attempt duplicate key hatası verir → tx_id=false → müşteri 'Payment initialization failed. Please return to checkout.' görür. Yeniden checkout yapsa da WooCommerce aynı #1042 siparişini yeniden kullanır ve aynı hatayı alır. Yönetici panelinde iade butonu da gizlidir. Sipariş sonsuza dek ölü.

**Etki**

Approval çağrısı sırasında PHP süreci ölürse (timeout, OOM, deploy, 502) sipariş kalıcı olarak ödenemez ve iade edilemez hale gelir. WooCommerce checkout'u aynı bekleyen siparişi tekrar kullandığı için (order_awaiting_payment) müşteri döngüde kalır. Tek çözüm doğrudan SQL müdahalesi. Bu, mutabakat iş akışını 'kaydedildi ama kapatılamaz' hâle getiriyor.

**Öneri**

Mutabakat için gerçek bir operatör iş akışı ekle: (a) admin/class-nicepay-transactions.php'ye nonce+cap korumalı 'Mutabakatı kapat' eylemi — operatörün NICEPAY konsolundaki sonucu seçmesini iste (para çekilmedi → status='failed', reconciliation_status='not_required', active_attempt_key=NULL; para çekildi → status='paid', captured_amount/remaining_amount doldur, siparişi payment_complete ile kapat), her iki durumda da satırı ve sipariş notunu operatör kimliğiyle işaretle. (b) Bayat approval kurtarmasında, net cancel 'confirmed' ise kilidi otomatik bırak. (c) uniq_active_attempt ihlali durumunda kullanıcıya jenerik hata yerine 'Bu sipariş için devam eden bir ödeme incelemesi var, lütfen mağaza ile iletişime geçin' mesajı göster ve sipariş notu düş.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddianın her bileşenini kodu açarak doğruladım.

1) Bayat approval kurtarma sorgusu gerçekten active_attempt_key'e dokunmuyor (includes/nicepay-functions.php:968-978). Satır referansı ~970 doğru aralıkta (SQL bloğu 968-978, note satırı 974, INTERVAL satırı 977).

2) `grep -rn active_attempt_key includes admin` ile TÜM yazma noktalarını çıkardım: NULL'a çeken yerlerin hiçbiri 'approving'/'needs_reconciliation' satırlarını kapsamıyor:
   - nicepay-functions.php:933 (abandon) → WHERE status='pending' AND approval_state='pending'
   - nicepay-functions.php:959 (expire) → WHERE status='pending' AND approval_state='pending'
   - nicepay-functions.php:679 (claim) → yalnızca KAYBEDEN adayları NULL'lar, kazanan 'approving' satırı kilidi TUTAR
   - return-handler:191/222, gateway:203/318/359/528/664/675 → hepsi senkron istek içi yollar; süreç ölürse hiçbiri çalışmaz.
   Yani 'approving' → (cron) → 'needs_reconciliation' geçişinde kilit kalıcı olarak asılı kalıyor.

3) Yeni deneme gerçekten patlıyor: şema UNIQUE KEY uniq_active_attempt (class-nicepay-transaction-schema.php:126) + gateway.php:275 aynı 'woocommerce:<id>' değerini yeniden insert ediyor; nicepay_save_transaction (nicepay-functions.php:380-385) düz $wpdb->insert yapıp duplicate'te false dönüyor → gateway.php:296-303 jenerik "Payment initialization failed." Bundan önceki abandon çağrısı (gateway.php:262) status='pending' filtresi yüzünden no-op.
   receipt_page (gateway.php:154-160) ve process_payment (gateway.php:95-101) yolları yalnızca status='paid' + approval_state='approved' satırını kurtarıyor (recover_paid_order_from_ledger, gateway.php:173-177 erken return), 'needs_reconciliation' satırı için hiçbir kaçış yok.

4) İade gerçekten reddediliyor: gateway.php:711-713 'required' === $reconciliation → 'nicepay_refund_state_error'. Admin listesinde $can_refund=false (admin/class-nicepay-transactions.php:800-805) ve AJAX iptal de aynı koşulla bloklu (admin/class-nicepay-transactions.php:999).

5) Operatör eylemi yok: admin'de kayıtlı yalnızca iki handler var — wp_ajax_nicepay_cancel_transaction ve admin_post_nicepay_export_transactions (admin/class-nicepay-transactions.php:24-25). Mutabakatı kapatan / kilidi bırakan hiçbir eylem yok; UI yalnızca uyarı sayacı + filtre linki (634-646).

EK AĞIRLAŞTIRICI (iddiada yok, lehine): NicePay_Retention::eligible_row_predicate (includes/class-nicepay-retention.php:308-315) `active_attempt_key IS NULL` ve `reconciliation_status <> 'required'` şartlarını koştuğu için bu satır retention tarafından da asla temizlenmiyor — kilit süresiz kalıcı. Ayrıca gateway.php:628-661'deki payment_complete hata yolu da kilidi bırakmadan needs_reconciliation'a düşüyor; bu yolda para GERÇEKTEN çekilmiş oluyor, yani aynı kök neden para çekilmiş siparişte de kilitlenmeye yol açıyor.

Tek küçük abartı: "müşteri sonsuza dek döngüde" — WooCommerce order_awaiting_payment yeniden kullanımı sepet değişmediğinde geçerlidir; sepet değişirse yeni sipariş (yeni active_attempt_key) oluşur ve müşteri ödeyebilir. Ancak ilgili sipariş #1042 gerçekten kalıcı olarak ödenemez + iade edilemez kalır. Bu, çekirdek iddiayı değiştirmiyor; severity=high yerinde.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `confirmed`)

- Gerekçe: Kodu satır satır okudum; iddianın her halkası doğrulandı ve failure_scenario üretilebilir.

1) Kilit alınıyor ve 'approving' durumunda korunuyor: nicepay_claim_transaction_for_approval (includes/nicepay-functions.php:651-687) yalnızca KAYBEDEN adayların active_attempt_key'ini NULL yapıyor (`candidate.active_attempt_key = IF(candidate.id = target.id, candidate.active_attempt_key, NULL)`), kazanan satır kilidi tutuyor. Kilit gateway'de generate_payment_form içinde konuluyor (class-nicepay-gateway.php:275, 'woocommerce:'.$order_id) ve şema UNIQUE (class-nicepay-transaction-schema.php:126 `UNIQUE KEY uniq_active_attempt (active_attempt_key)`).

2) Bayat kurtarma kilidi bırakmıyor: nicepay-functions.php:970-977 stale_sql yalnızca status/approval_state/reconciliation_status/reconciliation_note yazıyor; active_attempt_key SET listesinde yok. Cron gerçekten saatlik (nicepay-payment-gateway.php:166-167).

3) Başka hiçbir yer 'approving'/'needs_reconciliation' satırının kilidini bırakmıyor: grep sonucu NULL'a çeken tüm noktalar ya status='pending' koşullu (functions:933 abandon, functions:959 expire) ya da abort/başarı/başarısızlık yolları (functions:262, gateway:203/318/359/528/664/675, return-handler:134/191/222). Admin tarafında yalnızca iki eylem kayıtlı: wp_ajax_nicepay_cancel_transaction ve admin_post_nicepay_export_transactions (admin/class-nicepay-transactions.php:24-25) — mutabakat kapatma/kilit bırakma eylemi YOK; ekranda sadece sayaç + filtre linki var (satır 634-648).

4) Yeni deneme gerçekten patlıyor: receipt_page (gateway:136-161) → recover_paid_order_from_ledger false döner (status 'paid' değil, satır 172-175) → generate_payment_form → nicepay_abandon_pending_transactions yalnızca pending satırları hedeflediği için 0 satır etkiler ve true döner → nicepay_save_transaction aynı active_attempt_key ile INSERT → UNIQUE ihlali → $wpdb->insert false (functions:380-385) → gateway:293-299 jenerik "Payment initialization failed. Please return to checkout." Bu döngü, WooCommerce aynı bekleyen siparişi yeniden kullandığı sürece kalıcıdır.

5) İade yolu da kapalı: process_refund gateway:711-714 `'required' === $reconciliation` ise 'nicepay_refund_state_error'; admin listesinde $requires_reconciliation → $can_refund=false (transactions.php:800-805), yani buton gizli.

6) Ek olarak satır silinemez de: class-nicepay-retention.php:308-315 temizlik sorgusu `active_attempt_key IS NULL` ve `reconciliation_status <> 'required'` şartı koyuyor — satır sonsuza dek kalıyor.

Severity high doğru: NICEPAY tarafında para gerçekten çekilmiş olabilecek bir onay yarıda kesildiğinde, sipariş ödenemez + iade edilemez hâle geliyor ve tek çözüm doğrudan SQL. Abartı yok; tek nüans aşağıda.

---

### MONEY-017 — KRW ondalıksızlığı yalnızca NICEPAY sınırında zorlanıyor: kesirli sipariş toplamı sessizce yuvarlanıyor, kısmi iadeler kalıcı bloke oluyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | money-correctness |
| **Konum** | [includes/nicepay-functions.php:1513](../../../includes/nicepay-functions.php#L1513) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
nicepay_normalize_amount kesirli değeri sessizce yarım-yukarı yuvarlıyor:

    $parts   = explode( '.', $raw, 2 );
    $integer = $parts[0];
    $decimal = isset( $parts[1] ) ? $parts[1] : '';
    if ( '' !== $decimal && (int) $decimal[0] >= 5 ) {
        $integer = nicepay_increment_integer_string( $integer );
    }

Eklentide woocommerce_price_num_decimals / wc_get_price_decimals ile ilgili hiçbir kontrol veya uyarı yok (`grep -rn "price_num_decimals\|wc_get_price_decimals" includes admin` → sonuç yok). WooCommerce KRW için varsayılan olarak 2 ondalık kullanır. Sipariş toplamı 10998.90 iken:
- generate_payment_form Amt='10999' gönderir (gateway:213),
- templates/payment-form.php:33 müşteriye `$order->get_formatted_order_total()` ile ₩10,998.90 gösterir,
- handle_return'de her iki taraf aynı yuvarlamadan geçtiği için hash_equals uyuşur ve sipariş tam ödenmiş sayılır.

İade tarafında sapma birikiyor: process_refund `nicepay_normalize_ledger_amount( (string) $order->get_total_refunded() )` ile Woo toplamını, `nicepay_add_integer_amounts( $refunded, $cancel_amt )` ile defter toplamını karşılaştırıp hash_equals istiyor (gateway:754-759). Yuvarlama her iade için ayrı ayrı yapıldığından iki toplam ayrışıyor.
```

**Başarısızlık senaryosu**

Mağaza ondalık = 2, sipariş toplamı 10998.90 KRW (vergi sonrası). Ödeme: PG'ye Amt=10999 gider, müşteri 10,999 çekilir, ekranda 10,998.90 yazar. Ardından yönetici iki kez 5499.45 KRW kısmi iade yapar. 1. iade: cancel_amt=normalize(5499.45)='5499', Woo total_refunded=5499.45→'5499', beklenen 0+5499='5499' → uyuşur, PG 5499 iade eder, defter refunded=5499. 2. iade: Woo total_refunded=10998.90→'10999', cancel_amt='5499', beklenen 5499+5499='10998'. hash_equals('10998','10999') false → 'nicepay_refund_amount_error: WooCommerce refund records do not match this NicePay refund request.' Kalan 5500 KRW artık eklenti üzerinden hiçbir şekilde iade edilemez. Aynı siparişte admin'in tek-tık iadesi de çöker: nicepay_normalize_ledger_amount(remaining)='10999' → (float)10999.0 → wc_create_refund, 10999 > get_remaining_refund_amount()=10998.90 olduğu için 'Invalid refund amount' WP_Error döndürür ve kullanıcı yalnızca 'Refund could not be completed.' görür.

**Etki**

Her ödemede sipariş toplamı ile çekilen tutar arasında 0.5 KRW'ye kadar sapma; müşteriye gösterilen tutar ile çekilen tutar farklı. Daha ciddisi: kesirli tutarlı siparişlerde ikinci kısmi iade ve admin'in tek-tık tam iadesi kalıcı olarak reddediliyor, yani para müşteriye iade edilemiyor.

**Öneri**

KRW'yi uçtan uca tam sayı olarak zorla: (1) is_available() içinde veya bir admin uyarısında `wc_get_price_decimals() !== 0` ise gateway'i kapat/uyar; ayrıca `add_filter('wc_get_price_decimals', ...)` yerine yöneticiye açık bir 'KRW için ondalık sayısını 0 yapın' bildirimi göster. (2) generate_payment_form'da toplamın tam sayı olmadığını tespit edip ödemeyi reddet: `if ( (string) $amount !== rtrim(rtrim((string) $order->get_total(), '0'), '.') ) { ... }` yerine daha net olarak `if ( 0 !== bccomp( (string) $order->get_total(), $amount, 2 ) )` veya string karşılaştırmasıyla kesir varsa hata ver. (3) nicepay_normalize_amount'a `$allow_rounding = false` parametresi ekle; ödeme/iade yolları kesirli girdide false dönsün, yalnızca görüntüleme yolları yuvarlasın. (4) Woo iade mutabakatını kuruş toleransıyla değil, WooCommerce iade kaydının tam sayı olmasını şart koşarak yap.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddianın her bir konumunu açıp okudum; kod tam olarak iddia edildiği gibi ve satır numaraları dosyanın şu anki haliyle eşleşiyor.

1) Yuvarlama gerçek ve sessiz: includes/nicepay-functions.php:1510-1516 kesirli kısmın yalnızca ilk hanesine bakıp half-up yuvarlıyor; hata döndürmüyor. nicepay_get_amount (1656-1663) bunun ince bir sarmalayıcısı, ek doğrulama yok.

2) Ödeme yolu: class-nicepay-gateway.php:213 `nicepay_get_amount( $order->get_total(), ... )` ile Amt üretiyor; 216'daki tek kontrol `'' === $amount`, yani kesir varlığı reddedilmiyor. templates/payment-form.php:33 müşteriye `get_formatted_order_total()` (yuvarlanmamış) gösteriyor — iki değer arasında 0.5 KRW'ye kadar sapma mümkün.

3) Hiçbir yerde ondalık koruması yok: `grep -rn "price_num_decimals\|wc_get_price_decimals\|get_price_decimals" includes admin templates assets` → hiç sonuç yok. is_available() (76-103) para birimi, SSL, MID vs. kontrol ediyor ama ondalık sayısını kontrol etmiyor; admin uyarısı da yok. Depo genelinde `round(` yalnızca görüntüleme fonksiyonunda (functions.php:1275) kullanılıyor.

4) İade mutabakatı: gateway:723-734 captured ile order_total'ı aynı yuvarlamadan geçirdiği için ödeme anında sapma gizleniyor (10999 vs normalize(10998.90)='10999' → hash_equals geçer). Asıl kırılma gateway:754-759: `nicepay_normalize_ledger_amount( (string) $order->get_total_refunded() )` toplam Woo iadesini bir kez yuvarlarken, `nicepay_add_integer_amounts( $refunded, $cancel_amt )` her iadeyi ayrı ayrı yuvarlanmış tam sayılardan topluyor. İki 5499.45'lik iadede: Woo tarafı 10998.90 → '10999', defter tarafı '5499'+'5499'='10998' → hash_equals false → 'nicepay_refund_amount_error' ve kalan 5500 KRW kalıcı bloke. Bu ayrışma matematiksel olarak kaçınılmaz (yuvarlama toplamayla değişmeli değil).

5) Admin tek-tık iadesi: admin/class-nicepay-transactions.php:1005-1019 defterdeki tam sayı `remaining`'i `(float)` yapıp wc_create_refund'a veriyor; WooCommerce'in wc_create_refund'ı tutarı get_remaining_refund_amount() ile karşılaştırıp aşımda "Invalid refund amount" WP_Error atar; 1021-1023 bu hatayı yutup kullanıcıya yalnızca genel 'Refund could not be completed.' mesajı gösteriyor — teşhis edilemez.

6) Yuvarlamanın kasıtlı olduğu tests/unit/NicePayFunctionsTest.php:70 (`10000.50 → '10001'`) ile doğrulanıyor; yani bu bir kaza değil ama kesirli mağaza yapılandırmasına karşı hiçbir savunma eklenmemiş. İddiayı çürüten hiçbir erken return, guard, filtre veya üst katman doğrulaması bulamadım.

Tek küçük not: senaryo yalnızca mağaza ondalık ayarı > 0 iken ve sipariş toplamı (tipik olarak vergi hesabı sonrası) gerçekten kesirli olduğunda tetiklenir — ancak bu WooCommerce'in varsayılan yapılandırması (woocommerce_price_num_decimals varsayılanı 2, para birimine göre değişmez), dolayısıyla varsayılan kurulumda ulaşılabilir. Severity 'high' yerinde.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: KRW'nin ondalıksızlığı yalnızca NICEPAY sınırında zorlanıyor; mağazanın WooCommerce ondalık ayarı (varsayılan 2) hiçbir yerde kontrol edilmiyor. Sonuç: (a) kesirli sipariş toplamları sessizce half-up yuvarlanarak PG'ye gönderiliyor — müşteriye gösterilen `get_formatted_order_total()` ile çekilen Amt 0.5 KRW'ye kadar ayrışıyor ve sipariş yine de "tam ödenmiş" sayılıyor (gateway:213, 730-732, payment-form.php:33); (b) iade mutabakatı kümülatif Woo toplamını TEK kez, defteri ise iade-başına yuvarlayıp karşılaştırdığı için (gateway:754-759) kesirli tutarlı kısmi iadeler jenerik `nicepay_refund_amount_error` ile reddediliyor; (c) admin'in tek-tık iadesi kesirli toplamlı her siparişte çöküyor, çünkü `(float) $remaining` yukarı yuvarlanmış tam sayı Woo'nun `get_remaining_refund_amount()` değerini aşıyor ve kullanıcı yalnızca "Refund could not be completed." görüyor (transactions:1005-1022). DÜZELTME: iade kalıcı olarak imkânsız hale gelmiyor — aynı senaryoda tam-won bir tutar (ör. 5499.00) mutabakatı geçer, dolayısıyla paranın çoğu kurtarılabilir; kalıcı olarak sıkışan şey artık bakiyedir (defterde 1 KRW, Woo'da 0.45 gibi) ve sipariş Woo tarafında asla "tam iade edildi" durumuna ulaşamaz, çünkü <0.5'lik kalan normalize_amount'ta false döndürüp reddedilir. Ayrıca sapma "her ödemede" değil, yalnızca toplam kesirli olduğunda oluşur.
- Gerekçe: Kodu satır satır okudum; mekanizma iddia edildiği gibi. Doğrulananlar:

1) Yuvarlama gerçekten sessiz ve half-up: nicepay_normalize_amount (includes/nicepay-functions.php:1484-1529) kesirli girdiyi reddetmiyor, ilk ondalık basamak >=5 ise tam sayıyı artırıyor. Yuvarlamayı devre dışı bırakan bir parametre yok.

2) Ödeme yolu kesir kontrolü yapmıyor: generate_payment_form (class-nicepay-gateway.php:213) doğrudan nicepay_get_amount( $order->get_total(), ... ) çağırıyor; nicepay_get_amount (nicepay-functions.php:1656-1663) yalnızca normalize'ı sarmalıyor. is_available() (gateway:76-103) yalnızca enabled/installer/MID/key/method/currency/SSL kontrol ediyor — ondalık ayarı kontrolü YOK. `grep -rn "decimal" includes admin templates` yalnızca schema sabiti ve normalize'ın kendi değişkenini döndürüyor; wc_get_price_decimals / woocommerce_price_num_decimals ile ilgili tek bir kontrol veya admin uyarısı yok. Yani "ondalık != 0 olan KRW mağazasında kesirli toplam sessizce yuvarlanır" doğru.

3) Müşteriye gösterilen tutar ile imzalanan tutar farklı kaynaktan geliyor: templates/payment-form.php:33 `$order->get_formatted_order_total()` kullanıyor, PG'ye giden Amt ise normalize edilmiş tam sayı. Kesirli toplamda ekran ile çekim ayrışır.

4) İade mutabakatı gerçekten iki ayrı yuvarlamayı hash_equals ile karşılaştırıyor (gateway:754-759): `nicepay_normalize_ledger_amount( (string) $order->get_total_refunded() )` (kümülatif Woo toplamı, TEK yuvarlama) vs `nicepay_add_integer_amounts( $refunded, $cancel_amt )` (her iade ayrı yuvarlanıp toplanmış). 5499.45 + 5499.45 senaryosunda: kümülatif 10998.90 -> '10999', defter 5499+5499 = '10998' -> hash_equals false -> WP_Error('nicepay_refund_amount_error'). Aritmetik doğrulandı (normalize: '9' >= 5 -> increment; '4' < 5 -> kes).

5) Admin tek-tık iadesi de doğrulandı: admin/class-nicepay-transactions.php:1005-1019 ledger remaining'i `(float) $remaining` yapıp wc_create_refund'a veriyor. Toplam 10998.90 iken remaining='10999' -> 10999.0 > $order->get_remaining_refund_amount() (10998.90) -> WooCommerce "Invalid refund amount" WP_Error, kullanıcı 1022. satırdaki jenerik "Refund could not be completed." mesajını görüyor. (WooCommerce'in wc_create_refund tutar üst-sınır kontrolü çekirdek davranışı; repo içinde vendor yok, çekirdek bilgisine dayanıyor — tek "okunmamış" halka bu.)

Düzeltilen abartılar (bu yüzden confirmed değil, partially-confirmed):

a) "Kalan 5500 KRW artık eklenti üzerinden HİÇBİR ŞEKİLDE iade edilemez" yanlış. Blok kalıcı değil; yalnızca yönetici yuvarlamayı tersine mühendislik yapacak bir tutar seçerse çalışıyor. Aynı senaryoda 5499.00 (tam won) iadesi geçer: Woo kümülatif 10998.45 -> '10998', beklenen 5499+5499 = '10998' -> hash_equals TRUE. Yani paranın büyük kısmı kurtarılabilir; kalıcı olarak sıkışan şey artık bakiyedir (bu örnekte defterde 1 KRW, Woo'da 0.45). Üstelik 0.45'lik kalanı iade denemesi normalize_amount'ta '0' -> ltrim -> false döndüğü için (nicepay-functions.php:1517-1521) "Refund amount is invalid for this currency." ile reddedilir; sipariş Woo tarafında asla tam iade edilmiş duruma gelemez. Yani gerçek etki "para tamamen kilitleniyor" değil, "iade akışı anlaşılmaz hatalarla kırılıyor + artık kuruş/won kalıcı sıkışıyor + sipariş tam-iade durumuna asla ulaşamıyor".

b) "Her ödemede 0.5 KRW'ye kadar sapma" yanlış. Sapma yalnızca toplam kesirli olduğunda oluşur; tam sayı toplamlarda (KRW mağazalarının çoğunluk vakası) sapma sıfırdır. Önkoşul zinciri: ondalık ayarı 2 (WooCommerce varsayılanı, KRW'ye göre otomatik 0 yapılmaz) VE vergi/kupon sonucu kesirli toplam.

c) Ödeme tarafındaki hash_equals'ler (gateway:181-182, 730-732) iddia edildiği gibi "gizlemiyor" ama zararsız da değil: her iki taraf aynı yuvarlamadan geçtiği için sipariş, aslında müşteriden 10999 çekilmişken 10998.90 olarak "tam ödenmiş" sayılıyor — bu kısım iddiada doğru.

Severity: high olarak kalmalı. Tetikleyici koşullar niş değil (WooCommerce varsayılan ondalık = 2), sonuç para mutabakatsızlığı + iadenin jenerik hata mesajıyla kırılması. Ancak "kalıcı kilitlenme" değil "kırılgan, tersine-mühendislik gerektiren iade" olarak düzeltilmeli.

Öneri notu: önerideki `bccomp` bcmath eklentisine bağlı, PHP 7.4-8.3 hedefinde garanti değil; string tabanlı kontrol (normalize öncesi `preg_match('/\.[0-9]*[1-9]/', $raw)`) tercih edilmeli.

---

### MONEY-018 — flow='' olan eski (1.x) işlemler hiçbir zaman iade edilemiyor; üç fonksiyon flow konusunda birbiriyle çelişiyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | upgrade-path |
| **Konum** | [includes/nicepay-functions.php:450](../../../includes/nicepay-functions.php#L450) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
nicepay_get_transaction_by_tid, sipariş kimliği verildiğinde flow'u zorla 'woocommerce' yapıyor:

    if ( $wc_order_id > 0 ) {
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE tid = %s AND wc_order_id = %d AND flow = 'woocommerce' LIMIT 1",
                $tid, $wc_order_id
            )
        );
    }

Ama process_refund bunun sonucunu alıp eski satırları da kabul etmeye çalışıyor:

    if ( ! $transaction || (int) $transaction->wc_order_id !== (int) $order->get_id() ||
        ! in_array( (string) $transaction->flow, array( '', 'woocommerce' ), true ) ) {

ve nicepay_claim_transaction_for_refund de açıkça eski satırları destekliyor:

    WHERE id = %d AND (flow = 'woocommerce' OR (flow = '' AND wc_order_id IS NOT NULL))

1.x şemasında `flow` sütunu hiç yok (git show origin/main:nicepay-payment-gateway.php:110-144); dbDelta sütunu `varchar(20) NOT NULL DEFAULT ''` olarak ekliyor ve installer hiçbir backfill yapmıyor. Yani tüm eski satırlar flow='' ile kalıyor ve SELECT tarafından asla döndürülmüyor.
```

**Başarısızlık senaryosu**

Mağaza 1.9 ile 400 sipariş tahsil etmiş. 2.0'a yükseltiliyor. Müşteri eski #830 siparişi için iade istiyor. Yönetici WooCommerce sipariş ekranında iade oluşturuyor → process_refund → nicepay_get_transaction_by_tid('nicepay00m01...', 830) sorgusu `AND flow = 'woocommerce'` yüzünden NULL döner → WP_Error('nicepay_refund_error', 'Transaction ID not found.'). WooCommerce oluşturduğu iade kaydını siler. Yönetici, para NICEPAY'de duruyorken eklentiden hiçbir şekilde iade yapamaz; NICEPAY konsoluna geçmek zorunda kalır ve o iade yerel deftere hiç yansımaz.

**Etki**

2.0'a yükselttikten sonra 1.x döneminde alınmış tüm ödemeler WooCommerce üzerinden iade edilemez hâle geliyor; yönetici yalnızca 'Transaction ID not found.' mesajını görüyor. Kodun üç ayrı katmanındaki flow toleransı ölü kod, bu da bakım yükü ve yanlış güvenlik hissi yaratıyor.

**Öneri**

Installer'a idempotent bir backfill ekle ve şema sürümünü artır:

    if ( self::column_exists( $wpdb, $table, 'flow' ) ) {
        $queries[] = "UPDATE {$table} SET flow = 'woocommerce', source_ref = CAST(wc_order_id AS CHAR)
                      WHERE flow = '' AND wc_order_id IS NOT NULL";
        $queries[] = "UPDATE {$table} SET flow = 'standalone' WHERE flow = '' AND wc_order_id IS NULL";
        $queries[] = "UPDATE {$table} SET captured_amount = amount, remaining_amount = amount
                      WHERE status = 'paid' AND captured_amount = 0 AND refunded_amount = 0";
    }

Backfill yapılmayacaksa nicepay_get_transaction_by_tid sorgusunu `AND flow IN ('', 'woocommerce')` yap ve process_refund/claim ile hizala; her iki durumda da bir entegrasyon testi ekle.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: Her üç kod noktasını da açıp okudum; iddia edilen satır numaraları dosyanın şu anki haliyle birebir eşleşiyor ve iddiayı çürütecek hiçbir koruma/backfill yok.

1) includes/nicepay-functions.php:438-455 — nicepay_get_transaction_by_tid, $wc_order_id > 0 olduğunda sorguya koşulsuz `AND flow = 'woocommerce'` ekliyor. Erken return yok, filtre atlanabilir değil.
2) Çağıranlar (grep ile tamamı): includes/class-nicepay-gateway.php:702 (process_refund), admin/class-nicepay-admin.php:1140, includes/nicepay-functions.php:1010, tests/integration/woocommerce-smoke.php:215. Bunlardan process_refund (703-706) ve nicepay_claim_transaction_for_refund (nicepay-functions.php:734) açıkça flow='' eski satırları kabul etmeye çalışıyor → tolerans ölü kod.
3) Eski şema: `git show origin/main:nicepay-payment-gateway.php` (create_tables, ~110-144) içinde `flow` sütunu YOK; `grep -n "flow"` eski dosyada hiç eşleşme vermiyor.
4) Yeni şema: includes/class-nicepay-transaction-schema.php:49 `'flow' => self::COLUMN_VARCHAR_20` ve satır 28 `varchar(20) NOT NULL DEFAULT ''` → dbDelta eski satırlara flow='' yazar.
5) Backfill gerçekten yok: `grep -rn "SET flow" includes admin tests` → SIFIR eşleşme. includes/class-nicepay-installer.php:262-300 scrub_legacy_sensitive_data yalnızca tid/card_no/auth_token/payment_data'yı normalize ediyor; flow'a dokunmuyor. maybe_install (installer:111-214) tabloyu drop/recreate etmiyor, yerinde dbDelta yapıyor, yani eski satırlar flow='' ile korunur.
6) Eski satırların gerçekten 'paid' olduğu doğrulandı: git show origin/main:includes/class-nicepay-return-handler.php:143 `$update_data['status'] = ( $result_method === 'VBANK' ) ? 'waiting' : 'paid';` — yani claim sorgusundaki status koşulunu geçerlerdi, tek engel flow.

Sonuç: 1.x'ten yükseltilmiş bir mağazada, eski bir siparişin TID'i ile process_refund çağrıldığında SELECT NULL döner ve WP_Error('nicepay_refund_error', 'Transaction ID not found.') üretilir. Etki ve başarısızlık senaryosu doğru; severity high yerinde.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: 1.x'ten yükseltilen tüm işlem satırları eksik migration yüzünden WooCommerce üzerinden iade edilemez ve admin'de görünmez hâle geliyor. İlk kapı `nicepay_get_transaction_by_tid()`'in `AND flow = 'woocommerce'` kısıtı (includes/nicepay-functions.php:450) ile 1.x'te var olmayan `flow` sütununun dbDelta tarafından `NOT NULL DEFAULT ''` eklenmesi (includes/class-nicepay-transaction-schema.php:27,49) ve installer'da hiçbir backfill bulunmaması (includes/class-nicepay-installer.php:262-300; `grep -rn "SET flow"` → 0 sonuç). Ancak sorun flow ile SINIRLI DEĞİL: SELECT gevşetilse bile eski satırlar `currency`/`mid`/`mode` sütunları 1.x'te var olmadığı için boş kalır ve includes/class-nicepay-gateway.php:716-721'deki bağlam kontrolünde 'nicepay_refund_context_error' ile reddedilir. Dolayısıyla doğru düzeltme, orijinal öneride yer alan flow/source_ref/tutar backfill'ine ek olarak currency='KRW' ve mid/mode backfill'ini (ya da 'legacy' olarak işaretlenip ayrı ele alınmasını) de içermeli. Ayrıca aynı SELECT admin/class-nicepay-admin.php:1140'ta kullanıldığından eski siparişlerde `render_order_payment_summary` sessizce çıkıyor ve ödeme paneli hiç render edilmiyor; includes/nicepay-functions.php:1010-1018'deki iptal uyarısı da her zaman $transaction=null görüp gereksiz not ekliyor. gateway.php:703-704 ve nicepay-functions.php:734'teki flow='' toleransları bu çağrı zincirinde gerçekten ölü koddur.
- Gerekçe: Kodu satır satır okudum. İddianın ÇEKİRDEĞİ doğru ve istismar/tetiklenme yolu gerçekten ulaşılabilir:

1) `nicepay_get_transaction_by_tid()` sipariş kimliği verildiğinde gerçekten `AND flow = 'woocommerce'` zorluyor (nicepay-functions.php:450). Bu, `process_refund`'un tek çağrısı (class-nicepay-gateway.php:702).
2) Yeni şemada `flow` = `varchar(20) NOT NULL DEFAULT ''` (class-nicepay-transaction-schema.php:49 + 27). 1.x CREATE TABLE'da `flow` sütunu YOK (git show origin/main:nicepay-payment-gateway.php, create_tables()), dolayısıyla dbDelta sonrası tüm eski satırlar flow=''.
3) Depoda HİÇBİR flow backfill'i yok: `grep -rn "SET flow" includes/ admin/ tests/ nicepay-payment-gateway.php` → 0 sonuç. `class-nicepay-installer.php` içinde "flow" kelimesi hiç geçmiyor; scrub_legacy_sensitive_data yalnızca tid/card_no/auth_token/payment_data'ya dokunuyor. Şema dosyasındaki yorum ("legacy-value backfills are intentionally outside this schema definition", satır 36-37) bunun bilinçli bir boşluk olduğunu gösteriyor ama boşluğu dolduran kod hiçbir yerde yok.
4) Ön koşullar gerçekçi: 1.x zaten `_nicepay_tid` sipariş meta'sını yazıyordu (origin/main:includes/class-nicepay-gateway.php:363) ve başarılı kart ödemesinde status='paid' yazıyordu (origin/main:...:359). Yani $tid dolu, satır 'paid', admin Woo iade formunu kullanıyor → process_refund → SELECT NULL → WP_Error 'Transaction ID not found.'. Tetikleme: `wp-admin` sipariş ekranı, `shop_manager`/`administrator` rolü, "Refund" butonu → `woocommerce_refund_line_items` AJAX. Ulaşılamaz kod yolu değil.
5) Ölü kod iddiası da doğru: gateway.php:703-704'teki `in_array($transaction->flow, array('', 'woocommerce'), true)` ve nicepay-functions.php:734'teki `(flow = '' AND wc_order_id IS NOT NULL)` dalı bu çağrı zinciriyle ASLA flow='' bir satır göremez (claim'in tek çağıranı gateway.php:785, o da aynı SELECT'ten geçmiş satırı kullanıyor).

Ancak iddia iki noktada EKSİK/YANLIŞ — bu yüzden "confirmed" değil:

A) **Önerilen düzeltme senaryoyu ÇÖZMEZ.** Bulgu, "backfill yapılmayacaksa SELECT'i `flow IN ('', 'woocommerce')` yap" diyor. Bunu yapsanız bile eski satır bir sonraki kapıda ölür: gateway.php:716-721 `'KRW' !== $transaction_currency || empty($transaction->mid) || empty($transaction->mode)` kontrolünü yapıyor. 1.x tablosunda `currency`, `mid`, `mode` sütunları HİÇ YOKTU; dbDelta bunları `char(3) NOT NULL DEFAULT ''` / `varchar(20) ... DEFAULT ''` / `varchar(10) ... DEFAULT ''` olarak ekliyor (schema:59-61). Yani eski satırlar currency='' , mid='' ile geliyor ve hata sadece 'Transaction ID not found.' yerine 'nicepay_refund_context_error' olur. Bulgunun önerdiği backfill SQL'i de currency/mid/mode'u doldurmuyor. Yani bu tek bir "flow" sorunu değil, **tüm 1.x satırları için genel bir migration boşluğu**; flow sadece ilk kapı.

B) **Etki eksik raporlanmış (hafife alınmış).** Aynı SELECT admin/class-nicepay-admin.php:1140'ta da kullanılıyor: eski siparişlerde `render_order_payment_summary` sessizce `return` ediyor, yani yönetici sipariş ekranında NICEPAY ödeme özeti paneli hiç görünmüyor. Ayrıca nicepay-functions.php:1010'daki iptal uyarısı da eski siparişlerde her zaman $transaction=null ile çalışıp gereksiz "cancelled funds" notu ekliyor. Yani sadece iade değil, eski siparişlerin tüm admin görünürlüğü kayboluyor.

Severity: 'high' korunmalı (para NICEPAY'de kalırken eklentiden hiçbir iade yolu yok, yerel defter kalıcı olarak gerçeklikle uyumsuzlaşıyor); güvenlik açığı değil, kalıcı veri/işlem tutarsızlığı ve manuel konsol workaround'u olduğu için 'critical' değil. Sadece 1.x'ten yükselten mağazaları etkiliyor — ama onların TAMAMINI ve GERİ DÖNÜŞSÜZ etkiliyor.

---

### MONEY-002 — ! empty( captured_amount ) fallback'i DECIMAL sütunlarda ölü kod: '0.00' PHP'de truthy

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | correctness |
| **Konum** | [includes/class-nicepay-gateway.php:724](../../../includes/class-nicepay-gateway.php#L724) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
process_refund: "$captured = nicepay_normalize_amount( ! empty( $transaction->captured_amount ) ? $transaction->captured_amount : $transaction->amount, 'KRW' );" (satır 723-726). captured_amount şemada `decimal(14,2) NOT NULL DEFAULT 0` (class-nicepay-transaction-schema.php:63), dolayısıyla MySQL her zaman '0.00' string'i döndürür. PHP'de empty('0.00') === false (yalnızca '' ve '0' falsy'dir), yani ! empty('0.00') === true olur ve ternary hiçbir zaman $transaction->amount'a düşmez. Aynı kalıp yönetici listesinde de var: "$captured = ! empty( $item->captured_amount ) ? $item->captured_amount : $item->amount;" (admin/class-nicepay-transactions.php:797) ve "$remaining = isset( $item->remaining_amount ) ? $item->remaining_amount : $item->amount;" (satır 799) — isset() decimal sütunda daima true.
```

**Başarısızlık senaryosu**

MONEY-001'deki backfill ile flow düzeltilse bile legacy bir 'paid' satırda captured_amount = '0.00' kalır. Yönetici 50.000 KRW'lik iadeyi başlatır; nicepay_normalize_amount('0.00') integer '0' üretir, ltrim('0','0') === '' olduğu için false döner ve iade 'nicepay_refund_amount_error' ile durur. Ayrı olarak: müşteri 120.000 KRW'lik ödemeyi başlatıp popup'ı kapatır; operatör Transactions ekranında satırı 'Pending — 0 KRW' olarak görür ve hangi tutarın askıda olduğunu anlayamaz.

**Etki**

İki ayrı yerde hatalı sonuç: (1) captured_amount'ı doldurulmamış her paid satırında iade, nicepay_normalize_amount('0.00') === false olduğu için yanlış hata koduyla ('Refund amount is invalid for this currency.') reddedilir — gerçek neden defterin eksik olmasıdır. (2) Yönetici işlem listesindeki Amount sütunu pending, failed, abandoned, expired ve capture öncesi needs_reconciliation olan her satır için 0 KRW gösterir. Mutabakat gerektiren kayıtlar tam da operatörün risk tutarını görmesi gereken kayıtlardır ve orada 0 KRW yazar.

**Öneri**

Boş kontrolünü tipe uygun hale getirin. Yardımcı bir fonksiyon ekleyin ve üç çağrı yerinde de kullanın: `function nicepay_ledger_value_or( $primary, $fallback ) { $normalized = nicepay_normalize_ledger_amount( $primary ); return ( false === $normalized || '0' === $normalized ) ? $fallback : $primary; }`. Yönetici listesinde ise capture öncesi durumlar için ayrı bir sunum kullanın: status paid/partially_refunded/refunded değilse `amount` (Requested) gösterin ve etiketi 'Requested' yapın; CSV export'ta zaten iki sütun ayrı (admin/class-nicepay-transactions.php:141-150) — ekran da aynı ayrımı yapmalı.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Ternary/isset kalıbı gerçekten ölü koddur (DECIMAL sütun '0.00' döndürür, `! empty('0.00') === true`, `isset()` daima true) ve satır referansları doğrudur. Ancak iddianın iki etkisinden yalnızca biri gerçekten ulaşılabilir:

(1) YÖNETİCİ LİSTESİ — DOĞRULANDI. admin/class-nicepay-transactions.php:797/799 fallback'leri hiç devreye girmez; capture öncesi tüm satırlar (pending, failed, abandoned, expired, capture öncesi needs_reconciliation) Amount sütununda `nicepay_format_amount('0.00','KRW')` = "0 KRW" gösterir (satır 869-880 çıktısı). Operatör askıdaki tutarı göremez.

(2) İADE AKIŞI — ÇÜRÜTÜLDÜ (bu haliyle). captured_amount='0.00' olan bir satırın includes/class-nicepay-gateway.php:733'e ulaşması pratikte mümkün değil:
  - Mevcut kodda status='paid' ile captured_amount tek bir atomik `nicepay_update_transaction` çağrısında birlikte yazılır (gateway:599-605, return-handler:185-188); 'paid' ataması yalnızca bu iki yerde var.
  - Legacy (main'den yükseltilmiş) 'paid' satırlarda captured_amount gerçekten '0.00' kalır, ama bu satırlarda `currency`, `mid`, `mode` sütunları da yeni ve DEFAULT '' olduğundan (schema:59-61) akış daha satır 716-721'deki guard'da `nicepay_refund_context_error` ile durur; 733'teki `nicepay_refund_amount_error` hiç çalışmaz.
  Yani iade tarafındaki bulgu "yanlış hata mesajıyla reddedilir" değil, "ölü/yanıltıcı savunma kodu + legacy satırlar için ayrı bir backfill eksikliği (MONEY-001 kapsamı)" olarak yeniden ifade edilmelidir.
- Gerekçe: Kalıbın kendisi (PHP semantiği + DECIMAL sütun) tam olarak iddia edildiği gibi ve satır numaraları dosyanın şu anki haliyle birebir eşleşiyor. nicepay_normalize_amount('0.00') gerçekten false döner (nicepay-functions.php:1507-1519: regex geçer, decimal '00' ilk hane <5 olduğu için yuvarlama yok, ltrim('0','0')==='' → false). Ancak "gerçek yanlış sonuç" testi yalnızca yönetici listesi için geçiyor: iade yolunda üst katmanda iki guard (status paid/partially_refunded + currency/mid/mode hash_equals) captured_amount='0.00' olabilecek tek gerçek popülasyonu (legacy satırlar) daha erken ve farklı bir hata koduyla eliyor. Bu yüzden high değil medium; öneri (tip-uygun boşluk kontrolü + listede 'Requested' ayrımı) yine de geçerli.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: '0.00' PHP'de truthy olduğu için `! empty( captured_amount )` / `isset( remaining_amount )` fallback'leri DECIMAL sütunlarda üç noktada ölü koddur (includes/class-nicepay-gateway.php:724, admin/class-nicepay-transactions.php:797, 799). Gerçek zarar tek yerde: yönetici Transactions listesindeki Amount hücresi doğrudan captured_amount'ı bastığı için (admin/class-nicepay-transactions.php:869) pending, failed, abandoned, expired ve capture öncesi needs_reconciliation satırları "0 KRW · Refunded: 0 · Remaining: 0" olarak görünür — asıl tutar `amount` sütununda durur ve ekranda hiç gösterilmez; CSV export aynı ayrımı zaten 'Requested/Captured' olarak yapıyor (satır 141-150), ekran yapmıyor. İade tarafında ise etki YOKTUR: 'paid' statüsünü yazan iki yol da (gateway:599-602, return-handler:185-188) captured_amount'ı aynı update'te dolduruyor; upgrade edilmiş legacy satırlar ise currency=''/mid='' olduğu için satır 716-719'daki context kontrolünde 'nicepay_refund_context_error' ile satır 723'e ulaşmadan reddediliyor; ayrıca satır 731-732'deki hash_equals(captured, order_total) kontrolü sıfır bir captured'ı zaten yakalar. Yani gateway:724'teki fallback yanlış sonuç üretmiyor, sadece yanıltıcı bakım yükü — doğru düzeltme onu tamamen kaldırmaktır (captured_amount tek doğruluk kaynağı, gateway:180'deki fail-closed kullanımla tutarlı olacak şekilde).
- Gerekçe: Teknik çekirdek doğru, ama iddia edilen ETKİ ve BAŞARISIZLIK SENARYOSU'nun yarısı üretilemez; severity abartılmış.

DOĞRULANAN KISIM (dil semantiği + ölü kod):
1. captured_amount/refunded_amount/remaining_amount gerçekten `decimal(14,2) NOT NULL DEFAULT 0` (class-nicepay-transaction-schema.php:32,63-66). wpdb bunları '0.00' string'i olarak döndürür; PHP'de empty('0.00') === false, isset('0.00') === true. Dolayısıyla gateway:724'teki ternary ve transactions.php:797/799'daki fallback'ler HİÇBİR ZAMAN ikinci operanda düşmez → ölü kod. Bu kısım confirmed.
2. nicepay_normalize_amount('0.00') gerçekten false döner: nicepay-functions.php:1503 regex '0.00'u kabul eder, decimal[0]='0' (<5) yuvarlama yok, ltrim('0','0') === '' → satır 1515-1518 `return false`. İddianın bu adımı da doğru.

ÇÜRÜTÜLEN KISIM — Etki (1) "iade yanlış hata koduyla reddedilir" GERÇEKLEŞTİRİLEMEZ:
a) Yeni kodda 'paid' statüsü sadece iki yerde yazılıyor (class-nicepay-gateway.php:599 ve class-nicepay-return-handler.php:185) ve HER İKİSİ de aynı update dizisinde captured_amount = amount atıyor (gateway:602, return-handler:188). Yani bu kod tabanında captured_amount = 0 olan bir 'paid'/'partially_refunded' satırı üretecek bir yol yok; process_refund'un fallback'e ihtiyacı olan durum ulaşılamaz.
b) İddianın somut senaryosu ("legacy bir paid satır") satır 723'e HİÇ ULAŞMIYOR. origin/main'deki eski tabloda currency, mid, mode, flow, captured_amount kolonlarının hiçbiri yok (origin/main:nicepay-payment-gateway.php:110-144 CREATE TABLE). Upgrade sonrası legacy satırlarda currency='' ve mid='' olur ve process_refund satır 716-720'deki kontrol ( `'KRW' !== $transaction_currency || ... empty($transaction->mid) ...` ) bu satırı satır 723'ten ÖNCE 'nicepay_refund_context_error' ile reddeder. (Zaten satır 704-706'daki flow kontrolü de legacy flow='' için geçse bile bu duvara takılır.) Yani üretilen hata kodu iddiadaki 'nicepay_refund_amount_error' değil.
c) Farazi olarak captured_amount=0 bir satır DB'ye elle konsa bile, satır 731-732'deki `! hash_equals( $captured, $order_total )` kontrolü bunu yine yakalar; fallback'i düzeltmek davranışı değiştirmez. Sonuç: para kaybı/yanlış tutar riski yok, sadece yanıltıcı savunma kodu.
d) Karşılaştırma noktası: aynı sütun recover_paid_order_from_ledger'da fallback'siz ve fail-closed kullanılıyor (gateway:180-184) — yani tutarsızlık argümanı geçerli ama zarar düşük.

DOĞRULANAN KISIM — Etki (2) yönetici listesi "0 KRW" gösterimi GERÇEK:
Amount hücresi doğrudan $captured basıyor (admin/class-nicepay-transactions.php:869: `nicepay_format_amount( $captured, $currency )`) ve nicepay_format_amount('0.00','KRW') → number_format(0.0) = "0 KRW" (nicepay-functions.php:1274-1276). captured_amount'ı hiç yazılmayan statüler gerçekten var: needs_reconciliation/failed yazan update'te captured_amount yok (gateway:515-522) ve pending kayıt insert'inde de yok (gateway:287 sadece 'amount'). Yani operatör approval hatası sonrası mutabakat gereken satırı "0 KRW · Refunded: 0 · Remaining: 0" olarak görür; asıl tutar $item->amount'ta durur ama ekranda hiç görünmez. CSV export ise 'Requested Amount' ve 'Captured Amount' kolonlarını ayırıyor (admin/class-nicepay-transactions.php:141-150) — ekran/CSV tutarsızlığı iddiası da doğru. Bu gerçek bir ops/UX kusuru, ancak salt gösterim: can_refund kontrolü ayrıca nicepay_normalize_ledger_amount($remaining) kullanıyor (satır 805) ve bu '0.00' için '0' döndürüp butonu doğru şekilde kapatıyor, yani yanlış işlem tetiklemiyor.

SEVERITY: high değil. Gerçek etki = "capture öncesi satırlarda yanıltıcı 0 KRW gösterimi + üç yerde ölü/yanıltıcı fallback". Para doğruluğu etkilenmiyor, istismar edilebilir bir yol yok → medium.

Öneri kısmı da kısmen yanlış: yazar `nicepay_ledger_value_or` helper'ını "üç çağrı yerinde de" kullanmayı öneriyor; oysa gateway:724'te doğru düzeltme fallback'i tamamen KALDIRMAK (captured_amount tek doğruluk kaynağı olmalı, aksi halde amount'a düşmek defteri gizler). Sadece admin listesindeki iki yer sunum düzeltmesi istiyor.

---

### MONEY-004 — WooCommerce tarafında yapılan tek bir elle iade, o siparişin sonraki tüm NicePay iadelerini kalıcı olarak bloke ediyor

| | |
|---|---|
| **Severity** | 🟡 Orta *(doğrulama sonrası; ilk değer 🟠 Yüksek)* |
| **Kategori** | refund-lifecycle |
| **Konum** | [includes/class-nicepay-gateway.php:754](../../../includes/class-nicepay-gateway.php#L754) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```php
"$order_refunded = nicepay_normalize_ledger_amount( (string) $order->get_total_refunded() ); $expected_order_refunded = nicepay_add_integer_amounts( $refunded, $cancel_amt ); if ( false === $order_refunded || false === $expected_order_refunded || ! hash_equals( $expected_order_refunded, $order_refunded ) ) { return new WP_Error( 'nicepay_refund_amount_error', 'WooCommerce refund records do not match this NicePay refund request.' ); }" (satır 754-759). $refunded yalnızca NicePay defterinden okunuyor (satır 727-729), $order->get_total_refunded() ise siparişteki TÜM WC_Order_Refund nesnelerini toplar — 'Refund manually' ile oluşturulanlar ve refund_payment=false ile programatik oluşturulanlar dahil. Bu iki değer arasında tam eşitlik (hash_equals) aranıyor; hiçbir tolerans, hiçbir kurtarma yolu yok.
```

**Başarısızlık senaryosu**

100.000 KRW'lik sipariş NicePay ile ödenir. Müşteri 30.000 KRW'lik kısmi iade ister; yönetici sekmeyi karıştırıp 'Refund manually' der (para gerçekte dönmez, sadece WC kaydı oluşur). Hatayı fark edip 30.000 KRW için 'Refund via NicePay' der: ledger refunded='0', cancel_amt='30000', beklenen '30000', ama get_total_refunded() artık '60000' döner → hash_equals başarısız → iade PG'ye hiç gitmez. Yönetici elle oluşturduğu iadeyi siler; bu sefer get_total_refunded()='30000' olur ama ilk NicePay denemesi zaten iptal edilmiştir ve yeni denemede WC yeni bir iade nesnesi oluşturduğu için toplam yine '60000' olur. Sipariş sıkışır; müşteriye para yalnızca NICEPAY konsolundan elle iade edilebilir ve o iade defterde hiç görünmez.

**Etki**

Yönetici bir kez 'Refund manually' düğmesine basarsa (WooCommerce iade ekranında NicePay düğmesinin hemen yanında durur) veya yanlışlıkla oluşturduğu bir iade kaydını siler, o sipariş için API üzerinden iade sonsuza dek imkânsız hale gelir. Hata mesajı sorunu tarif ediyor ama çözüm yolu sunmuyor ve bu durum defterde needs_reconciliation olarak da işaretlenmiyor — yani mutabakat sayacında bile görünmez.

**Öneri**

Katı eşitlik yerine 'bu isteğin WC tarafında karşılığı var mı' kontrolüne geçin. wc_create_refund tarafından oluşturulan in-flight iade nesnesini doğrudan bulun (woocommerce_order_refunded / wc_get_order_refunds ile en yeni refund) ve yalnızca onun tutarının $cancel_amt'e eşit olduğunu doğrulayın; ayrıca üst sınır olarak `compare(add($refunded,$cancel_amt), $captured) <= 0` şartını koruyun. Ek olarak defterde 'gateway dışı iade' kavramını modelleyin: `external_refunded_amount` sütunu ekleyip get_total_refunded() ile ledger arasındaki farkı bir kez kaydedin ve sonraki hesaplamalarda baz alın. Hata mesajını da eyleme dönüştürün: 'Bu siparişte NicePay dışında kaydedilmiş X KRW iade var; NicePay üzerinden iade edilebilecek kalan Y KRW.'

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Refund akışı, NicePay defteri ile WooCommerce'in get_total_refunded() değeri arasında birebir eşitlik dayatıyor (class-nicepay-gateway.php:754-759). Sonuç: siparişte NicePay dışında kaydedilmiş herhangi bir iade kaydı (Refund manually veya refund_payment=false ile oluşturulmuş kayıt) DURDUĞU sürece, o sipariş için API üzerinden hiçbir iade — kısmi veya tam — mümkün olmaz. Blok kalıcı değildir: yabancı iade kaydı silinince akış normale döner ve başarısız denemeler kalıcı kayıt bırakmaz, çünkü wc_create_refund() gateway WP_Error döndürdüğünde in-flight WC_Order_Refund'u `$refund->delete( true )` ile siler. Asıl kusur, (a) meşru bir gateway-dışı iade kaydını (nakit/havale iadesinin muhasebe kaydı) tutmak isteyen mağazanın API iadelerini tamamen kaybetmesi ve (b) hata mesajının teşhis/eylem içermemesi: yöneticiye ne kadar dış iade bulunduğu ve NicePay üzerinden kalan iade edilebilir tutarın ne olduğu söylenmiyor.
- Gerekçe: Kodun kendisi iddia edildiği gibi: includes/class-nicepay-gateway.php:754-759 gerçekten NicePay defterindeki refunded + bu istek toplamının, siparişin TÜM WC_Order_Refund kayıtlarının toplamına (get_total_refunded) hash_equals ile birebir eşit olmasını şart koşuyor; tolerans veya "gateway dışı iade" modeli yok. Dolayısıyla çekirdek mekanizma DOĞRU: siparişte NicePay dışı bir iade kaydı (Refund manually, veya refund_payment=false ile programatik oluşturulmuş bir kayıt) varsa, o kayıt durdukça API üzerinden hiçbir iade geçmez — kısmi de, tam da.

Ancak iddianın "KALICI olarak bloke", "sonsuza dek imkânsız", "sipariş sıkışır, sadece NICEPAY konsolundan elle iade edilebilir" kısmı ve başarısızlık senaryosunun ikinci yarısı YANLIŞ, çünkü WooCommerce çekirdeğinin wc_create_refund() akışını yanlış modelliyor. Core'da (WC 3.0+ wc-order-functions.php) sıra şudur: refund nesnesi kaydedilir, sonra 'refund_payment' true ise wc_refund_payment() → gateway->process_refund() çağrılır ve dönen değer WP_Error ise `$refund->delete( true ); return $result;` ile in-flight iade nesnesi SİLİNİR. Yani başarısız NicePay denemesi kalıcı bir WC_Order_Refund kaydı bırakmaz; get_total_refunded() eski değerine döner. Senaryodaki "yönetici elle oluşturduğu iadeyi siler ama toplam yine 60000 olur" adımı gerçekleşmez: elle oluşturulan kayıt silindiğinde get_total_refunded()=0 olur, yeni denemede WC 30000'lik nesneyi oluşturur, beklenen 0+30000=30000 = gerçek 30000 → hash_equals geçer ve iade PG'ye gider. Kurtarma yolu vardır ve yöneticinin elindedir (hatalı iade kaydını silmek).

Ayrıca "hiçbir kurtarma yolu yok" iddiasının aksine, blok yalnızca yabancı iade kaydı sipariş üzerinde DURDUĞU sürece geçerlidir; ancak yönetici o kaydı meşru bir sebeple tutmak istiyorsa (ör. gerçekten nakit/havale ile yapılmış kısmi iadenin muhasebe kaydı) o zaman API iadesi gerçekten kalıcı olarak kapanır — bu kısım geçerli bir bulgudur. "needs_reconciliation olarak işaretlenmiyor" gözlemi de doğru, fakat bu noktada PG'ye hiçbir istek gitmemiştir (nicepay_claim_transaction_for_refund satır 785'te, kontrolden SONRA çalışır), yani ortada mutabakatsızlık değil, salt fail-closed bir reddetme vardır; "mutabakat sayacında görünmüyor" eleştirisi bu yüzden zayıf.

Davranışın kasıtlı ve test edilmiş olduğunu da not etmek gerekir: 750-753'teki yorum ("This both models the real Woo lifecycle and prevents direct, unrecorded remote refunds"), tests/unit/NicePayRefundTest.php:189-202 ve tests/integration/woocommerce-smoke.php:157-218 bu sözleşmeyi doğrudan test ediyor. Yine de UX tarafı zayıf: hata mesajı ne yapılacağını söylemiyor ve gateway dışı iade kavramı hiç modellenmemiş. Bu nedenle severity high → medium.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Siparişte NicePay defteri dışında oluşturulmuş bir WC_Order_Refund kaydı (ör. "Refund manually" ile kaydedilen iade) bulunduğu sürece, includes/class-nicepay-gateway.php:754-759'daki katı `hash_equals` kontrolü o sipariş için tüm API iadelerini bloke eder ve hata mesajı ne uyuşmazlığın tutarını ne de çözüm yolunu söyler. Blok kalıcı DEĞİLDİR: WooCommerce başarısız gateway iadesinde in-flight refund nesnesini sildiği için, elle oluşturulan iade kaydı silindiğinde API iadesi tekrar çalışır. Gerçek kalıcı etki yalnızca gateway dışı iadeyi muhasebe kaydı olarak tutmak isteyen mağazalarda ortaya çıkar: kalan bakiye NicePay üzerinden hiçbir zaman iade edilemez. Kontrol PG çağrısından önce çalıştığı için fail-closed'dur; para kaybı, çift iade veya yarım kalmış durum oluşmaz.
- Gerekçe: Çekirdek mekanizma doğrulandı: `process_refund()` içinde NicePay defterinden okunan `$refunded` ile WooCommerce'in TÜM iade nesnelerini toplayan `$order->get_total_refunded()` arasında `hash_equals` ile TAM eşitlik aranıyor; tolerans, "defter dışı iade" modeli veya kurtarma yolu yok. Repoda `woocommerce_order_refunded` / refund-created/deleted için hiçbir hook yok (`grep add_action includes/class-nicepay-gateway.php` yalnızca 3 hook döndürüyor, hiçbiri iade senkronizasyonu değil) — yani elle oluşturulan bir WC iadesi defterе asla yansımıyor. Dolayısıyla siparişte NicePay dışı bir WC_Order_Refund kaydı bulunduğu SÜRECE o sipariş için API iadesi yapılamaz. İstismar/üretim adımları gerçekçi: rol = shop_manager/administrator; WC sipariş ekranı > Refund > tutar gir > "Refund manually" (admin-ajax.php `action=woocommerce_refund_line_items`, `api_refund=false`) → WC_Order_Refund oluşur, defter '0' kalır. Ardından aynı ekranda "Refund via NicePay" (`api_refund=true`) → beklenen '30000', gerçek '60000' → 754-759 satırındaki dal WP_Error döndürür ve istek PG'ye hiç gitmez.

ANCAK failure_scenario'nun "kalıcı/sonsuza dek imkânsız" kısmı YANLIŞ ve iddianın en dramatik cümlesi çürütülüyor: WooCommerce'te `wc_create_refund()`, `wc_refund_payment()` WP_Error döndürdüğünde in-flight iade nesnesini `$refund->delete( true )` ile siler. Yani başarısız NicePay denemesi kalıcı bir iade kaydı BIRAKMAZ. İddiadaki "yönetici elle iadeyi siler, ama yeni denemede toplam yine 60000 olur" adımı üretilemez: elle oluşturulan 30.000'lik kayıt silindikten sonra `get_total_refunded()` = 0 olur, yeni NicePay denemesinde in-flight nesne ile toplam 30.000 = beklenen 30.000 → hash_equals geçer ve iade normal şekilde gider. Bu, NicePay konsolundan elle iade zorunluluğu ve "defterde hiç görünmeyen iade" sonucunu ortadan kaldırıyor.

Geriye kalan gerçek (ve daha dar) sorun: (a) meşru bir "gateway dışı iade" (havale ile iade edilip WC'ye kayıt düşülen tutar) kaydı, kalan bakiyenin NicePay üzerinden iadesini muhasebe kaydını SİLMEDEN imkânsız kılıyor — örn. 100.000 KRW siparişte 30.000 elle kayıtlı iade varken kalan 70.000 için API iadesi her zaman bloke (defter '0', beklenen '70000', gerçek '100000'); (b) hata mesajı eylemsiz: hangi tutarın uyuşmadığını, ne kadarının iade edilebileceğini veya "elle oluşturulan iade kaydını silin" yolunu söylemiyor. İddianın "needs_reconciliation olarak işaretlenmiyor" vurgusu teknik olarak doğru ama etkisi abartılmış: kontrol PG çağrısından ÖNCE (satır 754, `nicepay_claim_transaction_for_refund` satır 785'ten önce) çalıştığı için hiçbir şey yarım kalmaz, uzlaştırılacak bir durum da yoktur — fail-closed davranış.

Severity: high → medium. Para kaybı yok, çift iade yok, güvenlik etkisi yok, kontrol fail-closed; tetiklenmesi yönetici hatası veya olağandışı bir iş akışı gerektiriyor ve tek adımlı bir kurtarma yolu (elle oluşturulan iade kaydını silmek) mevcut. Kalıcı olan tek senaryo, muhasebe kaydını silmek istemeyen mağazanın gateway dışı kısmi iade kaydettiği durum. Öneri kısmı (in-flight refund nesnesini doğrudan doğrulamak + `external_refunded_amount` modeli + eyleme dönük hata mesajı) geçerliliğini koruyor.

---

### MONEY-006 — Auth-return Amt'si sıfır dolgulu gelirse her ödeme reddedilir: iki farklı normalizasyon fonksiyonu, iki farklı sözleşme

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-robustness |
| **Konum** | [includes/class-nicepay-inbound-validator.php:98](../../../includes/class-nicepay-inbound-validator.php#L98) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Auth dönüşünde katı istek normalizasyonu kullanılıyor: "$posted_amount = nicepay_normalize_amount( $payload['Amt'], 'KRW' );" (satır 98). Bu fonksiyonun regex'i baştaki sıfırları reddediyor: "/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/" (nicepay-functions.php:1505) ve test bunu açıkça doğruluyor ('leading zero' => '0100' → false, NicePayFunctionsTest.php:96). Buna karşılık onay yanıtı esnek olanı kullanıyor: "$result_amount = nicepay_normalize_response_amount( $result['Amt'], 'KRW' );" (satır 162) ve NicePayInboundValidatorTest.php:332-350 sabit genişlikte '000000001004' değerini kabul ettiğini doğruluyor. nicepay_normalize_response_amount'ın doc-block'u da nedeni yazıyor: 'Protocol responses may use a fixed-width 12-byte amount' (nicepay-functions.php:1534-1536). request_net_cancel de aynı katı fonksiyonu kullanıyor: "$requested_amount = nicepay_normalize_amount( $auth_data['Amt'], 'KRW' );" (class-nicepay-api.php:535).
```

**Başarısızlık senaryosu**

NICEPAY merchant hesabı auth ReturnURL POST'unda Amt='000000010000' gönderiyor. Defterdeki amount '10000.00' → normalize '10000'. Posted amount nicepay_normalize_amount('000000010000') → regex başarısız → false. validate_auth_return 'nicepay_inbound_amount_mismatch' döner; handle_return wc_add_notice('We could not verify this payment attempt.') yazıp checkout'a yönlendirir (class-nicepay-gateway.php:426-432) ve hiçbir net-cancel gönderilmez. Kartta hold kalır, sipariş pending kalır, defterde satır hâlâ pending görünür ve mutabakat sayacında çıkmaz.

**Etki**

Auth dönüşü de NICEPAY'den gelen bir yanıttır. Merchant hesabı Amt'yi 12 byte sabit genişlikte dönerse validate_auth_return her ödemede nicepay_inbound_amount_mismatch üretir; müşteri kimlik doğrulamayı tamamlamış olmasına rağmen checkout'a geri atılır ve net-cancel de denenmediği için authorization hold PG'de asılı kalır. Aynı hesapta net-cancel çağrısı da nicepay_net_cancel_binding_error verir, yani kurtarma yolu da kırılır. Ödeme %100 başarısızlık oranına düşer ve hata mesajı kök nedeni göstermez.

**Öneri**

Gelen her NICEPAY alanı için tek bir yanıt normalizasyonu kullanın. validate_auth_return içinde `nicepay_normalize_amount( $payload['Amt'], 'KRW' )` yerine `nicepay_normalize_response_amount( $payload['Amt'], 'KRW' )` çağırın (imza doğrulaması zaten ham byte'larla yapıldığı için güvenlik kaybı yok — class-nicepay-inbound-validator.php:111). class-nicepay-api.php:535'te de aynı değişikliği yapın. Ayrıca validate_auth_return'ün amount/method/MID gibi 'imza sonrası' olmayan reddetme dallarında, imza doğrulandıysa net-cancel tetikleyin ki askıda hold bırakılmasın; imza doğrulanmadan önce ise mevcut sessiz reddetme davranışı korunmalı. NicePayInboundValidatorTest'e sıfır dolgulu auth Amt için pozitif bir vaka ekleyin.

---

### MONEY-007 — Receipt sayfasının yeniden yüklenmesi devam eden bir kimlik doğrulamayı geçersiz kılıyor; müşteri onayı tamamlıyor ama ödeme reddediliyor ve hold geri alınmıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | concurrency-ux |
| **Konum** | [includes/class-nicepay-gateway.php:262](../../../includes/class-nicepay-gateway.php#L262) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Her receipt render'ı önceki denemeyi kayıtsız şartsız emekliye ayırıyor: "if ( ! nicepay_abandon_pending_transactions( 'woocommerce', (string) $order->get_id() ) )" (satır 262) ve sorgu status='pending' olan tüm satırları abandoned yapıyor (nicepay-functions.php:931-938). Ardından validate_auth_return, satır pending değilse reddediyor: "if ( 'pending' !== self::transaction_value( $transaction, 'status' ) || 'pending' !== self::transaction_value( $transaction, 'approval_state' ) ) { return self::error( 'nicepay_inbound_replay', ... ); }" (class-nicepay-inbound-validator.php:72-75). handle_return bu durumda hiçbir ters çevirme yapmadan yalnızca yönlendiriyor: "wc_add_notice( 'We could not verify this payment attempt. Please try again.' ); wp_safe_redirect( wc_get_checkout_url() ); exit;" (satır 429-431).
```

**Başarısızlık senaryosu**

Müşteri mobilde 45.000 KRW'lik siparişte 'Proceed to Payment' der; NICEPAY sayfası açılır (Moid=WC77_...a1). Sayfa yavaş yüklenirken müşteri geri tuşuna basar, receipt sayfası yeniden render edilir ve tx1 abandoned olur, tx2 (Moid=WC77_...b2) oluşur. Müşteri geri ileri gidip ilk NICEPAY penceresinde kart onayını tamamlar. ReturnURL'e Moid=WC77_...a1 ile POST gelir; validate_auth_return tx1'i abandoned bulur, nicepay_inbound_replay döner, hiçbir net-cancel gönderilmez ve müşteri jenerik bir hata ile checkout'a atılır. Sipariş ödenmemiş, kartta 45.000 KRW hold asılı.

**Etki**

Mobil akışta NICEPAY tam sayfa yönlendirme kullanır; kullanıcının geri tuşuna basması veya sekmeyi yenilemesi yeni bir deneme üretir. Eski Moid ile dönen başarılı kimlik doğrulama 'replay' sayılıp reddedilir. Müşteri bankasından onay SMS'i/3DS ekranını görmüş olmasına rağmen 'ödemeniz doğrulanamadı' mesajı alır; kartında bekleyen tutar net-cancel gönderilmediği için kendiliğinden düşene kadar (bankaya göre günlerce) asılı kalır. Destek yükü ve güven kaybı doğurur.

**Öneri**

İki iyileştirme: (1) abandon işlemini akıllandırın — yeni bir deneme oluşturmadan önce mevcut pending satırın yaşını kontrol edin; offer_expires_at hâlâ geçerliyse ve satır son N dakika içinde oluşturulmuşsa yeni satır üretmek yerine mevcut Moid/SignData'yı yeniden gösterin (aynı EdiDate/Amt ile SignData deterministiktir). Böylece yenileme/geri tuşu devam eden denemeyi bozmaz. (2) validate_auth_return 'replay' verdiğinde, imza doğrulanabiliyorsa (AuthToken + Amt + Signature) abandoned satır için de nicepay_abort_authenticated_payment() çağırıp hold'u geri alın ve kullanıcıya 'Bu ödeme oturumu yenilendiği için iptal edildi, kartınızdaki blokaj kaldırıldı' gibi doğru mesajı gösterin. En azından bu senaryoyu docs/USER-GUIDE.md'de belgeleyin.

---

### MONEY-008 — Saklama politikası, iade edilebilir bakiyesi olan 'paid' satırlarını 1 gün gibi kısa bir sürede kalıcı olarak siliyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | data-retention-money |
| **Konum** | [includes/class-nicepay-retention.php:308](../../../includes/class-nicepay-retention.php#L308) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Silme yordamı 'paid' ve 'partially_refunded' satırlarını doğrudan kapsıyor: "AND ledger.status IN ('paid', 'partially_refunded', 'refunded', 'failed', 'abandoned', 'expired', 'cancelled')" (satır 308). Kalan bakiye kontrolü yok — remaining_amount predikatta hiç geçmiyor (satır 305-316). Minimum saklama süresi bir gün: "const MIN_DAYS = 1;" (satır 24) ve purge_batch bu değeri kabul ediyor (satır 198). Silinen satırdan sonra process_refund işlemi bulamaz: "$transaction = $tid ? nicepay_get_transaction_by_tid( $tid, $order->get_id() ) : null; if ( ! $transaction ... ) { return new WP_Error( 'nicepay_refund_error', 'Transaction ID not found.' ); }" (class-nicepay-gateway.php:702-706).
```

**Başarısızlık senaryosu**

Merchant retention'ı 7 gün olarak ayarlar ve onay kutusunu işaretler. 10. günde bir müşteri 8 gün önceki 200.000 KRW'lik siparişi için iade ister (Kore'de yasal cayma süresi 7 günden uzundur). Günlük cron o satırı çoktan silmiştir; status 'paid', remaining_amount 200000 olmasına rağmen predikat onu uygun sayar. Yönetici 'Refund via NicePay' der ve 'Transaction ID not found.' hatası alır. İade yalnızca NICEPAY konsolundan yapılabilir ve WooCommerce ile PG kayıtları kalıcı olarak ayrışır.

**Etki**

Merchant KVKK/GDPR endişesiyle kısa bir saklama süresi seçtiğinde, hâlâ iade edilebilir olan ödemelerin defter kaydı silinir ve WooCommerce üzerinden iade tamamen imkânsız hale gelir. Ayarın uyarı metni kalıcı silme riskini anlatıyor ama iade yeteneğinin kaybolacağını söylemiyor (admin/class-nicepay-admin.php:550 civarındaki liste yalnızca çözülmemiş durumların korunduğunu belirtiyor).

**Öneri**

Silme predikatına para güvenliği koşulu ekleyin: `AND NOT ( ledger.status IN ('paid','partially_refunded') AND ledger.remaining_amount > 0 )` — yani iade edilebilir bakiyesi kalan hiçbir kayıt silinmesin; tam iade edilmiş (refunded) ve bakiyesi sıfır olanlar silinebilir. MIN_DAYS'i finansal kayıt için makul bir tabana çekin (ör. 365) veya en az 'paid' durumu için ayrı ve daha uzun bir minimum uygulayın. Ayarlar ekranındaki uyarıya açık bir cümle ekleyin: 'Silinen kayıtlar için WooCommerce üzerinden iade yapılamaz.' Ek olarak silmeden önce ilgili WC siparişine tek satırlık bir özet not düşün (TID, tutar, tarih) ki iz tamamen kaybolmasın.

---

### MONEY-009 — needs_reconciliation kayıtları için operatör tarafında hiçbir çözüm yolu yok — yalnızca sayaç ve filtre var

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | operability |
| **Konum** | [admin/class-nicepay-transactions.php:634](../../../admin/class-nicepay-transactions.php#L634) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Arayüz yalnızca sayıyor ve filtreliyor: "$reconciliation_count = nicepay_get_reconciliation_count();" (satır 566) ve uyarı bloğu bir filtre bağlantısı sunuyor (satır 634-646). Satır seviyesinde tek yaptığı iade düğmesini gizlemek: "$can_refund = $order && ! empty( $item->tid ) && ! $requires_reconciliation && ..." (satır 802) ve reconciliation_note'u küçük yazıyla basmak (satır 916-918). Hiçbir yerde 'mark reconciled', 'release lock' veya 'force refund' aksiyonu yok — dosyada yalnızca ajax_cancel_transaction ve CSV export handler'ları kayıtlı (satır 24-25). Üstelik payment_complete Throwable dalında sipariş meta'sı kaydedilemeyebiliyor (class-nicepay-gateway.php:632-662), ki process_refund işlemi bulmak için tam da o meta'ya bağımlı (satır 701).
```

**Başarısızlık senaryosu**

Bir haftada üç sipariş approval-binding mismatch nedeniyle needs_reconciliation olur. Operatör NICEPAY konsolunda üçünü de kontrol eder: birinde para hiç çekilmemiş, ikisinde çekilmiş ve elle iade edilmiş. Üçü de artık kapanmış işlerdir ama Transactions ekranındaki 'Needs Reconciliation' rozeti kalıcı olarak 3 gösterir, satırlar hiçbir şekilde temizlenemez, retention da onları silmez (class-nicepay-retention.php:309-310). Bir ay sonra sayaç 11'e çıkar ve operatör uyarıyı okumayı bırakır.

**Etki**

Mutabakat gerektiren kayıt sayısı zamanla artar ve operatörün onları kapatacak hiçbir aracı yoktur; sayaç kalıcı bir kırmızı uyarıya dönüşür ve gerçek yeni sorunlar gürültü içinde kaybolur. Kilitli active_attempt_key (MONEY-003) ve kaydedilememiş _nicepay_tid ile birleştiğinde bu satırlar hem siparişi ödenemez hem de iade edilemez bırakır; tek çözüm doğrudan veritabanına müdahaledir. Bir ödeme eklentisi için bu, üretimde kabul edilebilir bir operasyon modeli değil.

**Öneri**

Transactions ekranına satır bazlı bir mutabakat kapatma aksiyonu ekleyin: nonce + nicepay_manage_transactions_capability korumalı bir admin-post/AJAX handler, operatörden zorunlu bir sonuç seçimi (captured_and_refunded_externally / not_captured / captured_and_kept) ve serbest metin not alsın; ardından reconciliation_status='resolved', reconciliation_checked_at=UTC_TIMESTAMP(), reconciliation_note=<operatör notu>, active_attempt_key=NULL yazsın ve ilgili WC siparişine kim/ne zaman/hangi karar bilgisiyle bir sipariş notu düşsün. nicepay_get_reconciliation_count()'u 'resolved' durumunu hariç tutacak şekilde güncelleyin. Ayrıca process_refund'un işlem bulma yolunu sipariş meta'sına tek başına bağımlı bırakmayın: _nicepay_tid boşsa nicepay_get_active_woocommerce_transaction()/wc_order_id üzerinden defterden düşün.

---

### MONEY-010 — process_payment iki dalda sebep bildirmeden 'failure' dönüyor; müşteri boş bir checkout hatasıyla kalıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | ux-error-handling |
| **Konum** | [includes/class-nicepay-gateway.php:111](../../../includes/class-nicepay-gateway.php#L111) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
"if ( ! $order || ! $this->is_available() || 'nicepay' !== $order->get_payment_method() ) { return array( 'result' => 'failure' ); }" (satır 111-113) ve "if ( ! $order->needs_payment() ) { return array( 'result' => 'failure' ); }" (satır 123-125). Her iki dalda da wc_add_notice() çağrısı yok ve dizi 'messages' anahtarı taşımıyor. Karşılaştırma için aynı sınıfın diğer hata yolları hep bir bildirim üretiyor (ör. satır 299 wc_add_notice, satır 429, 478, 506).
```

**Başarısızlık senaryosu**

Merchant canlıya geçerken live merchant key'i temizler ama gateway'i enabled bırakır. Müşteri sepeti doldurup 'Place order' der; is_available() get_merchant_key() boş olduğu için false döner, process_payment array('result'=>'failure') döndürür, hiçbir notice yazılmaz. Checkout AJAX'ı boş yanıtla düşer, müşteri tekrar tekrar dener, sipariş 'pending' olarak birikir ve ne müşteri ne de merchant hatanın nedenini görür — nicepay_log da bu dalda çağrılmıyor.

**Etki**

WooCommerce, process_payment 'success' dönmediğinde ve hiçbir notice yoksa AJAX checkout'a anlamlı bir gövde göndermez; müşteri ya boş bir hata ya da jenerik 'Internal server error' görür ve neyin yanlış gittiğini anlayamaz. is_available() beş ayrı nedenle false dönebiliyor (enabled, şema, kimlik bilgileri, metot, para birimi, HTTPS — satır 76-103) ve bunların hiçbiri müşteriye veya loglara yansımıyor.

**Öneri**

Her iki dalda da hem müşteriye hem loga sebep verin. is_available() yerine sebep döndüren bir yardımcı kullanın (`nicepay_get_configuration_warnings()` zaten mevcut, nicepay-functions.php:494) ve şunu döndürün: `wc_add_notice( __( 'NicePay is currently unavailable for this order. Please choose another payment method or contact us.', ... ), 'error' ); nicepay_log( 'process_payment rejected', $reason_code, 'error' ); return array( 'result' => 'failure', 'messages' => wc_print_notices( true ) );`. needs_payment() dalı için ayrı ve daha spesifik bir mesaj kullanın ('Bu sipariş için ödeme zaten alınmış görünüyor'), çünkü müşterinin doğru aksiyonu farklıdır.

---

### MONEY-011 — Standalone akışında sunucu tarafı tek-deneme kilidi yok: iki sekme iki ödenebilir teklif üretiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | concurrency |
| **Konum** | [nicepay-payment-gateway.php:589](../../../nicepay-payment-gateway.php#L589) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
ajax_init_payment doğrudan yeni bir satır ekliyor; ne nicepay_abandon_pending_transactions() çağrısı ne de active_attempt_key ataması var: "$tx_id = nicepay_save_transaction( array( 'order_id' => $moid, 'moid' => $moid, 'flow' => 'standalone', 'source_ref' => $offer['source_ref'], ... 'status' => 'pending', ... ) );" (satır 589-609). WooCommerce tarafında ise ikisi de var (class-nicepay-gateway.php:262 ve 275). Tek koruma istemci tarafında: paymentInProgress i18n mesajı ve buton disable etme (assets/js/nicepay.js:65, nicepay-payment-gateway.php:522). Sunucu tarafında yalnızca IP başına 20/60sn oran sınırı var (nicepay-functions.php:286, çağrı satır 556).
```

**Başarısızlık senaryosu**

Bağış sayfasındaki 50.000 KRW'lik shortcode'u kullanıcı iki sekmede açar (biri yavaş yüklendiği için sekmeyi kopyalamıştır). Her iki sekmede de 'Pay Now' der; iki ajax_init_payment çağrısı iki pending satır (SP_...x ve SP_...y) üretir. Kullanıcı ikisini de tamamlar, iki auth dönüşü de kendi Moid'ini bulur, ikisi de pending olduğu için claim başarılı olur ve iki ayrı onay çekilir. Kullanıcıya toplam 100.000 KRW tahsil edilir ve iki ayrı makbuz e-postası gider; hiçbir sunucu tarafı kontrol bunu engellemez.

**Etki**

Aynı ziyaretçi iki sekmede aynı shortcode'u açıp ikisinde de ödeme başlatırsa iki bağımsız Moid oluşur ve ikisi de başarıyla onaylanabilir; müşteri iki kez tahsil edilir. Defterde bu iki satırı birbirine bağlayan hiçbir alan yoktur (source_ref aynıdır ama bu alan yalnızca konfigürasyon kimliğidir, alıcıya özgü değildir), dolayısıyla operatör çift tahsilatı ancak alıcı e-postasını gözle karşılaştırarak fark edebilir. WooCommerce akışı bu senaryodan korunuyorken standalone akışının korunmaması mimari bir tutarsızlık.

**Öneri**

Standalone akışına da sunucu tarafı bir tek-deneme anahtarı verin. Alıcı e-postası + config_id üzerinden deterministik bir anahtar üretin ve WooCommerce'deki ile aynı uniq_active_attempt indeksini kullanın: `'active_attempt_key' => 'standalone:' . $offer['source_ref'] . ':' . substr( hash( 'sha256', $buyer['buyer_email'] ), 0, 32 )` ve ekleme öncesi `nicepay_abandon_pending_transactions( 'standalone', $offer['source_ref'] )` yerine yalnızca aynı anahtardaki pending satırı emekliye ayıran daraltılmış bir sorgu çağırın (aynı shortcode'u ödeyen farklı alıcıları etkilememeli). Duplicate key durumunda 409 ile 'Bu e-posta için devam eden bir ödeme var' mesajı döndürün. Alternatif olarak makbuz e-postasında olduğu gibi kısa bir idempotency token'ı istemciden alıp binding_token_hash sütununda saklayın (sütun şemada zaten mevcut ama hiçbir yerde kullanılmıyor).

---

### MONEY-012 — VBANK para yolunda yarım bırakılmış: 4100 tam tahsilat sayılıyor, sütunlar/etiketler/ayar ölü ama kod hazır bekliyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | dead-code-money-risk |
| **Konum** | [includes/class-nicepay-api.php:644](../../../includes/class-nicepay-api.php#L644) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
is_success_code() VBANK için '4100'ü başarı sayıyor (satır 640-655). Bu kod NICEPAY'de 'sanal hesap tahsis edildi' anlamına gelir, para henüz yatırılmamıştır. Buna rağmen başarı dalı koşulsuz olarak tam tahsilat yazıyor: "$update_data['status'] = 'paid'; ... $update_data['captured_amount'] = $transaction->amount; $update_data['remaining_amount'] = $transaction->amount;" ve hemen ardından "$order->payment_complete( $tid );" (class-nicepay-gateway.php:598-639). Bugün ulaşılamaz durumda, çünkü nicepay_get_enabled_methods() sertifikalı kümeyi CARD/BANK/CELLPHONE ile sınırlıyor (nicepay-functions.php:1359). Ancak VBANK'a ait bütün iskele duruyor: form dalı (class-nicepay-gateway.php:346-348), vbank_num/vbank_exp_date/vbank_issued_at/vbank_expires_at/vbank_deposited_at sütunları ve idx_vbank_expires_at indeksi (class-nicepay-transaction-schema.php:93-97, 136), 'waiting' => 'Waiting for Deposit' etiketi (nicepay-functions.php:1678), filtre listesi (admin/class-nicepay-transactions.php:277), get_vbank_exp_date() (class-nicepay-api.php:667) ve hiçbir arayüzde gösterilmeyen nicepay_vbank_expiry_days ayarı (admin/class-nicepay-admin.php:152-156).
```

**Başarısızlık senaryosu**

Gelecekte bir geliştirici 'VBANK sertifikalandı' diyerek nicepay-functions.php:1359'daki diziye 'VBANK' ekler. Müşteri 300.000 KRW'lik siparişte sanal hesap seçer; NICEPAY hesap numarası tahsis edip ResultCode='4100' döner. is_success_code true verir, defterde captured_amount=300000 ve remaining_amount=300000 yazılır, payment_complete() çağrılır, sipariş 'processing' olur ve otomatik stok düşümü + kargo bildirimi tetiklenir. Müşteri hiçbir zaman para yatırmaz; mağaza ürünü göndermiş ve defterinde 300.000 KRW tahsil edilmiş görünen ama gerçekte olmayan bir alacak taşımaktadır.

**Etki**

İki yönlü risk. Bakım tarafında: beş sütun, bir indeks, bir durum etiketi, bir ayar ve iki kod dalı hiç çalışmadan taşınıyor; okuyucu VBANK'ın desteklendiğini sanır. Para tarafında: sertifikasyon kapısı tek bir dizi ($certified) olduğu için, birisi VBANK'ı oraya eklediği anda sanal hesap tahsisi tam tahsilat gibi işlenir — sipariş 'processing' olur, stok düşer, kargo çıkabilir ve para hiç yatmamış olabilir. Bu, tek satırlık bir değişiklikle tetiklenebilen ciddi bir mali hata.

**Öneri**

İki yoldan birini seçin ve yarım halde bırakmayın. (a) Kaldırın: VBANK'ı is_success_code()/get_payment_method_name()/get_available_methods()'tan, form dalını, vbank_* sütunlarını (yeni bir şema sürümüyle), 'waiting' etiketini, filtre listesinden ve nicepay_vbank_expiry_days ayarını çıkarın; readme/CHANGELOG'da 'VBANK bu sürümde desteklenmiyor' diye yazın. (b) Doğru modelleyin: is_success_code'u ikiye ayırın — `is_capture_success()` (CARD 3001, BANK 4000, CELLPHONE A000) ve `is_issued_without_capture()` (VBANK 4100). VBANK yolunda status='waiting', captured_amount=0, remaining_amount=0 yazın, payment_complete() yerine `$order->update_status('on-hold')` kullanın, vbank_issued_at/vbank_expires_at doldurun ve tahsilatı ancak deposit bildirimi geldiğinde kaydedin. Her iki durumda da mevcut belirsizliği ortadan kaldırın.

---

### MONEY-019 — Yerel doğrulamada reddedilen BAŞARILI kimlik doğrulama ne NetCancel ediliyor ne de deftere yazılıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-hygiene |
| **Konum** | [includes/class-nicepay-gateway.php:426](../../../includes/class-nicepay-gateway.php#L426) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
validate_auth_return AuthResultCode='0000' ve geçerli imza olsa bile şu durumlarda WP_Error döndürüyor: teklif süresi dolmuş (:81-83), tutar uyuşmazlığı (:99-101), yöntem uyuşmazlığı (:103-105), flow uyuşmazlığı (:68-70). Çağıran tarafta yapılan tek şey:

    if ( is_wp_error( $transaction ) || empty( $transaction->wc_order_id ) ) {
        $error_code = is_wp_error( $transaction ) ? $transaction->get_error_code() : 'nicepay_inbound_order_missing';
        nicepay_log( 'WooCommerce auth return rejected', $error_code, 'warning' );
        wc_add_notice( ... );
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

nicepay_abort_authenticated_payment() (NetCancel + audit yazan tek fonksiyon) yalnızca claim BAŞARILI olduktan sonraki yollarda çağrılıyor. Reddedilen başarılı auth için ne request_net_cancel() çağrılıyor, ne transaction satırına net_cancel_* alanları yazılıyor, ne de bir mutabakat kaydı oluşuyor. Tek iz, seviyesi 'warning' olan bir log satırı.
```

**Başarısızlık senaryosu**

Müşteri ödeme formunu açar (offer_expires_at = +30 dk), NICEPAY penceresinde banka OTP'siyle uğraşıp 31. dakikada onayı tamamlar. ReturnURL'e AuthResultCode='0000', geçerli Signature ve TxTid ile POST gelir. validate_auth_return, 81. satırda `nicepay_inbound_offer_expired` döndürür. Sunucu NetCancel göndermez, defterde satır hâlâ 'pending' kalır (cron bir sonraki saatte 'expired' yapar) ve müşteri 'We could not verify this payment attempt. Please try again.' mesajıyla checkout'a atılır. Yönetici panelinde ne mutabakat sayacı artar ne de bu TxTid ile ilgili herhangi bir kayıt görünür; NICEPAY konsolunda ise karşılıksız bir auth kaydı durur.

**Etki**

NICEPAY tarafında başarılı bir kimlik doğrulama, sunucu tarafında hiçbir iz bırakmadan ve tersine çevrilmeden düşürülüyor. Operatörün panelinde bu olayın hiçbir görünürlüğü yok (nicepay_get_reconciliation_count bu satırları saymaz, çünkü satır hâlâ 'pending'). Protokol açısından, alınan bir auth sonucuna karşılık ne approval ne de net cancel gönderilmemiş oluyor.

**Öneri**

validate_auth_return'e, imza doğrulandıktan ve AuthResultCode='0000' olduktan sonra ortaya çıkan reddetmeler için ayrı bir sınıf ekle (ör. WP_Error data'sına 'authenticated' => true koy). Çağıran taraf bu durumda: (1) $this->api->request_net_cancel( $auth_data ) çağırsın, (2) ilgili satır bulunabiliyorsa nicepay_update_transaction ile status='failed'/'needs_reconciliation' + net_cancel_* alanlarını yazsın, (3) satır bulunamıyorsa (Moid eşleşmiyor) en azından bir 'orphan auth' kaydı üretsin ki nicepay_get_reconciliation_count bunu görsün. Ayrıca teklif TTL'ini (30 dk) mobil banka/CELLPHONE akışları için yeniden değerlendir veya süresi dolmuş ama imzası geçerli auth'lar için NetCancel + net bir 'ödeme süresi doldu' mesajı ver.

---

### MONEY-020 — Zaten ödenmiş bir dönüşün tekrarı müşteriye 'doğrulayamadık, tekrar deneyin' diyor; nazik kurtarma dalı pratikte erişilemez

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | idempotency-ux |
| **Konum** | [includes/class-nicepay-gateway.php:429](../../../includes/class-nicepay-gateway.php#L429) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Doğrulayıcı, replay'i claim'den ÖNCE yakalıyor:

    if ( 'pending' !== self::transaction_value( $transaction, 'status' ) ||
        'pending' !== self::transaction_value( $transaction, 'approval_state' ) ) {
        return self::error( 'nicepay_inbound_replay', 'Payment transaction is not pending approval.' );
    }

Bu WP_Error olduğu için gateway'de wc_order_id bilinmiyor ve müşteri şunu alıyor:

    wc_add_notice( __( 'We could not verify this payment attempt. Please try again.', ... ), 'error' );
    wp_safe_redirect( wc_get_checkout_url() );

Oysa hemen altında (434-443) siparişi tanıyıp 'ödenmişse order-received sayfasına götür' mantığı var:

    $claimed_order = wc_get_order( $transaction->wc_order_id );
    wp_safe_redirect( $claimed_order && ! $claimed_order->needs_payment()
        ? $this->get_return_url( $claimed_order ) : ... );

Ancak status='paid' olduğu an doğrulayıcı zaten hata döndürdüğü için bu dala ulaşmak için iki isteğin doğrulayıcıyı aynı anda geçmesi gerekir — yani gerçek dünyada neredeyse hiç çalışmaz. Standalone tarafında aynı senaryo HTTP 400 + 'We could not verify this payment attempt.' üretiyor.
```

**Başarısızlık senaryosu**

Müşteri kartla öder, sipariş 'processing' olur, order-received sayfasına yönlendirilir. Mobil tarayıcıda geri tuşuna basıp 'formu yeniden gönder' onayını verir (veya NICEPAY mobil akışı ReturnURL'e ikinci kez POST atar). handle_return çalışır, validate_auth_return status='paid' gördüğü için nicepay_inbound_replay döndürür, müşteri checkout sayfasında 'We could not verify this payment attempt. Please try again.' mesajıyla karşılaşır ve sepetini yeniden doldurup ikinci bir sipariş oluşturarak tekrar öder.

**Etki**

Parasını ödemiş müşteri, tarayıcı geri/yenile veya NICEPAY'in tekrar POST'u sonrası ödemenin başarısız olduğunu düşünüp ikinci kez ödemeye yöneliyor; standalone tarafında ise ödediği hâlde 400 hata sayfası görüyor. Bu, hem güven kaybı hem de gereksiz ikinci tahsilat riski.

**Öneri**

Replay tespitini bilgi kaybetmeden yap: validate_auth_return, WP_Error'ın data'sında ilgili satırı taşısın (`self::error('nicepay_inbound_replay', ..., array('transaction' => $transaction))`) veya çağıran taraf hata kodu 'nicepay_inbound_replay' olduğunda Moid ile satırı yeniden okuyup şu davranışı uygulasın: satır 'paid' ve sipariş ödenmişse `wp_safe_redirect( $this->get_return_url( $order ) )` ve wc_add_notice YOK; 'failed'/'expired' ise mevcut hata mesajı. Standalone tarafta da 'paid' satır için render_saved_receipt/başarı sayfasını 200 ile göster.

---

### MONEY-021 — Mutabakat gerektiren iade sonuçları WooCommerce siparişine hiç yazılmıyor; WooCommerce ise iade kaydını siliyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | auditability |
| **Konum** | [includes/class-nicepay-gateway.php:881](../../../includes/class-nicepay-gateway.php#L881) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
PG başarı kodu döndürdüğü hâlde TID/CancelAmt bağlaması tutmadığında:

    if ( $this->api->is_cancel_success( $result_code ) ) {
        nicepay_complete_refund_attempt( ... 'status' => 'unknown' ... );
        nicepay_update_transaction( $transaction->id, array(
            'status'                => 'needs_reconciliation',
            ...
        ), true );
        return new WP_Error( 'nicepay_refund_reconciliation_required', ... );
    }

Bu blokta ne `$order->add_order_note(...)` ne de `_nicepay_refund_reconciliation_required` meta'sı var. Aynı şekilde 900-917'deki 'rejected' yolunda da sipariş üzerinde hiçbir iz bırakılmıyor. Karşılaştırma için, transport hatası yolunda (814-827) hem meta hem sipariş notu yazılıyor. WooCommerce, process_refund WP_Error döndürdüğünde oluşturduğu WC_Order_Refund nesnesini siler (wc_create_refund), dolayısıyla sipariş ekranında da hiçbir kayıt kalmaz.
```

**Başarısızlık senaryosu**

Yönetici 3.000 KRW kısmi iade ister. NICEPAY ResultCode='2001' ile yanıt verir ama CancelAmt alanında (proxy/parse anomalisi ya da yanlış eşleşen bir işlem nedeniyle) '3500' döner. binding_matches false olur → defter needs_reconciliation olur, WP_Error döner, WooCommerce iade kaydını siler. Sipariş #1042 ekranında ne bir not, ne bir iade satırı, ne bir meta vardır — sanki hiç iade denenmemiş gibidir. Oysa müşterinin kartına 3.500 KRW iade edilmiş olabilir.

**Etki**

Para NICEPAY tarafında iade edilmiş olabilecekken (ResultCode 2001/2211 döndü) WooCommerce sipariş geçmişinde hiçbir iz kalmıyor. Sipariş ekranından bakan bir operatör olayı hiç göremiyor; yalnızca ayrı NicePay İşlemler ekranındaki mutabakat sayacı artıyor. Muhasebe/denetim açısından sipariş kaydı ile defter kaydı ayrışıyor.

**Öneri**

Her iki bloğa da mutabakat izini ekle:

    $order->update_meta_data( '_nicepay_refund_reconciliation_required', 'yes' );
    $order->add_order_note( sprintf(
        __( 'NicePay refund response did not match the request (code %s). No WooCommerce refund was recorded; reconcile in the merchant console before retrying.', 'nicepay-payment-gateway' ),
        $result_code
    ) );
    $order->save();

ve 'rejected' yolunda da en azından reddedilme nedenini sipariş notu olarak bırak. Ek olarak admin/class-nicepay-transactions.php'deki mutabakat listesine sipariş düzenleme bağlantısını her zaman göster.

---

### MONEY-022 — Auth dönüşündeki Amt katı normalizasyonla, approval yanıtındaki Amt esnek normalizasyonla doğrulanıyor — asimetri fail-safe değil

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | protocol-conformance |
| **Konum** | [includes/class-nicepay-inbound-validator.php:98](../../../includes/class-nicepay-inbound-validator.php#L98) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Auth dönüşü katı fonksiyonu kullanıyor:

    $posted_amount = nicepay_normalize_amount( $payload['Amt'], 'KRW' );

Approval yanıtı ise dolgulu (leading-zero) yanıtı kabul eden fonksiyonu:

    $result_amount = nicepay_normalize_response_amount( $result['Amt'], 'KRW' );

nicepay_normalize_amount baştaki sıfırları reddediyor:

    if ( ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $raw ) ) { return false; }

nicepay_normalize_response_amount'ın doc-block'u ise açıkça 'Protocol responses may use a fixed-width 12-byte amount' diyor. docs/API-REFERENCE.md:162 aynı uyarıyı yalnızca approval yanıtı için veriyor; :128'deki auth yanıtı tablosunda böyle bir not yok, yani auth Amt'ının dolgusuz geleceği varsayımı hiçbir yerde doğrulanmamış bir kabul.
```

**Başarısızlık senaryosu**

NICEPAY merchant hesabı auth ReturnURL POST'unda Amt='000000010000' gönderir. validate_auth_return: nicepay_normalize_amount('000000010000') regex'e takılır ve false döner → 99. satırda 'nicepay_inbound_amount_mismatch'. İmza doğrulaması hiç çalışmaz (111. satıra ulaşılmaz), NetCancel gönderilmez, defter satırı 'pending' kalır. Mağazada hiçbir ödeme tamamlanamaz ve loglarda yalnızca 'WooCommerce auth return rejected' warning'i görünür.

**Etki**

Eğer merchant hesabının sandbox/prod konvansiyonunda auth dönüşündeki Amt de 12 bayta sıfırla doldurulursa, her ödeme 'nicepay_inbound_amount_mismatch' ile reddedilir. Üstelik MONEY-005 gereği bu red için NetCancel de gönderilmez; yani mağaza tüm ödemeleri kaybederken NICEPAY tarafında karşılıksız auth'lar birikir. İki farklı normalizasyon kullanımı ayrıca bakım açısından tuzak.

**Öneri**

Her iki tarafta aynı esnek fonksiyonu kullan: `$posted_amount = nicepay_normalize_response_amount( $payload['Amt'], 'KRW' );` (imza doğrulaması zaten ham baytlarla yapılıyor, bu yüzden güvenlik kaybı yok). Ayrıca amount mismatch hata mesajına gerçek ve beklenen kanonik değerleri redakte edilmiş biçimde loglayarak (tutar değil, uzunluk/format bilgisi) sahada teşhisi mümkün kıl ve sertifikasyon kontrol listesine 'auth Amt dolgu konvansiyonunu doğrula' maddesini ekle.

---

### MONEY-023 — Standalone akışında aktif-deneme kilidi ve kardeş iptali yok: aynı teklif için sınırsız eşzamanlı deneme ve çift tahsilat mümkün

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | concurrency |
| **Konum** | [nicepay-payment-gateway.php:589](../../../nicepay-payment-gateway.php#L589) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
ajax_init_payment satırı oluştururken ne active_attempt_key veriyor ne de önceki bekleyenleri iptal ediyor:

    $tx_id = nicepay_save_transaction( array(
        'order_id'    => $moid,
        'moid'        => $moid,
        'flow'        => 'standalone',
        'source_ref'  => $offer['source_ref'],
        ... // active_attempt_key YOK, nicepay_abandon_pending_transactions çağrısı YOK
    ) );

WooCommerce tarafındaki karşılığı ise hem iptal hem kilit uyguluyor:

    if ( ! nicepay_abandon_pending_transactions( 'woocommerce', (string) $order->get_id() ) ) { ... }
    'active_attempt_key' => 'woocommerce:' . (string) $order->get_id(),

Standalone claim dalı da yalnızca kendi satırını güncelliyor, kardeş pending satırlara dokunmuyor (nicepay-functions.php:693-708). Tek sınır 60 saniyede 20 istek olan IP tabanlı rate limit (nicepay_check_public_rate_limit).
```

**Başarısızlık senaryosu**

Bağış butonu 50.000 KRW'lik kayıtlı bir teklif kullanıyor. Kullanıcı butona iki kez tıklar (veya sayfayı iki sekmede açar); iki ayrı ajax_init_payment çağrısı iki pending satır (SP_..._a, SP_..._b) üretir. Kullanıcı birinci pencerede ödemeyi tamamlar, sonra ikinci penceredeki 'Öde' akışını da tamamlar. Her iki auth dönüşü de kendi Moid'ini bulur, ikisi de 'pending' olduğu için ikisi de claim alır ve iki approval çağrısı yapılır → kullanıcıdan toplam 100.000 KRW çekilir. Eklentide standalone iade yolu olmadığı için para NICEPAY konsolundan elle iade edilmek zorunda kalır.

**Etki**

Aynı kayıtlı teklif için aynı anda birden çok geçerli Moid/SignData üretilebiliyor; kullanıcı iki sekmede iki ödeme penceresi açıp ikisini de tamamlarsa iki ayrı gerçek tahsilat oluşur ve hiçbir katman bunu engellemez. WooCommerce akışıyla kasıtlı olmayan bir tutarsızlık; standalone iadeler de desteklenmediği için (readme.txt:23) düzeltmesi tamamen manuel.

**Öneri**

Standalone tarafında da tekil bir deneme anahtarı kullan. Örneğin oturum/istemci bazlı bir kimlik türetip (`hash_hmac('sha256', $config_id . '|' . $client_identity, wp_salt('nonce'))`) `active_attempt_key = 'standalone:' . $key` olarak yaz ve insert öncesi `nicepay_abandon_pending_transactions( 'standalone', $offer['source_ref'] )` benzeri bir daraltılmış iptal çalıştır (kardeşleri yalnızca aynı anahtar için iptal et). Ayrıca nicepay_claim_transaction_for_approval'ın standalone dalını da, WooCommerce dalındaki gibi aynı anahtarın diğer pending satırlarını 'abandoned' yapacak şekilde genişlet.

---

### MONEY-013 — Tamsayı para yardımcıları girdi doğrulaması yapmıyor; kanonik olmayan bir string sessizce yanlış para değeri üretiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | defensive-programming |
| **Konum** | [includes/nicepay-functions.php:1599](../../../includes/nicepay-functions.php#L1599) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
nicepay_add_integer_amounts() girdiyi hiç doğrulamadan basamak basamak işliyor: "$sum = ( $index < strlen( $left ) ? (int) $left[ $index ] : 0 ) + ( $index < strlen( $right ) ? (int) $right[ $index ] : 0 ) + $carry;" (satır 1607-1608). PHP'de (int)'.' === 0 ve (int)'-' === 0 olduğu için '1000.00' gibi bir defter değeri sessizce yanlış toplanır; hiçbir yerde `preg_match('/^[0-9]+$/')` kontrolü yok. Aynı durum nicepay_subtract_integer_amounts (satır 1624-1647) ve nicepay_compare_integer_amounts (satır 1581-1592) için de geçerli. Ek olarak yönetici iptal yolunda tek bir float cast var: "$amount = (float) $remaining;" (admin/class-nicepay-transactions.php:1011) — bu, dosyanın geri kalanındaki 'float yok' doktrinine aykırı.
```

**Başarısızlık senaryosu**

Bir bakım değişikliği ile refunded toplamı normalize edilmeden doğrudan geçirilirse (ör. `nicepay_add_integer_amounts( $transaction->refunded_amount, $cancel_amt )` — DB'den '3000.00' gelir) sonuç '3000.00' + '2000' için ters çevrilmiş basamak toplamı olarak '00.0005' benzeri bir çöp string üretir; bu değer decimal(14,2) sütununa yazıldığında MySQL onu 0.00'a çevirir ve iade edilmiş tutar defterde sıfırlanır. Hiçbir uyarı loglanmaz.

**Etki**

Bugün tüm çağrı yerleri (class-nicepay-gateway.php:736, 755, 761) girdileri önce nicepay_normalize_amount/nicepay_normalize_ledger_amount'tan geçirdiği için sömürülebilir değil. Ancak bunlar global, tema/eklenti tarafından çağrılabilir fonksiyonlardır ve içeride de yeni bir çağrı yeri eklendiğinde hata sessizce para değeri üretir — istisna atmaz, false dönmez. KRW için (float) cast 2^53 altında kayıpsızdır ama örüntü olarak tehlikelidir ve okuyucuya yanlış sinyal verir.

**Öneri**

Üç fonksiyonun başına kanoniklik kapısı koyun ve sözleşmeyi kodla zorlayın: `if ( ! is_string( $left ) || ! is_string( $right ) || ! preg_match( '/^[0-9]+$/', $left ) || ! preg_match( '/^[0-9]+$/', $right ) ) { nicepay_log( 'Non-canonical amount passed to integer math', null, 'error' ); return false; }` (add için de false döndürüp çağrı yerlerinde kontrol edin — bugün compare/subtract zaten false dönebiliyor). admin/class-nicepay-transactions.php:1011'deki cast yerine string'i doğrudan geçirin (`'amount' => $remaining`); wc_create_refund wc_format_decimal ile normalize eder. NicePayFunctionsTest::test_ledger_amount_math_is_exact_without_float_conversion'a negatif vakalar ekleyin ('1000.00', '-5', '1e3', '').

---

### MONEY-014 — İade tavanı iki farklı kaynaktan hesaplanıyor: PHP captured-refunded, SQL ise remaining_amount sütunu — ikisi hiç karşılaştırılmıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | ledger-consistency |
| **Konum** | [includes/class-nicepay-gateway.php:736](../../../includes/class-nicepay-gateway.php#L736) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
PHP tarafı kalan bakiyeyi türetiyor: "$remaining_before = nicepay_subtract_integer_amounts( $captured, $refunded );" (satır 736) ve üst sınır kontrolü buna dayanıyor (satır 746). SQL claim ise saklanan sütuna dayanıyor: "AND ( (remaining_amount > 0 AND remaining_amount >= %s) OR (status = 'paid' AND remaining_amount = 0 AND refunded_amount = 0 AND amount >= %s) )" (nicepay-functions.php:739-741). İki kaynak yalnızca capture anında senkronlanıyor ("$update_data['captured_amount'] = $transaction->amount; $update_data['remaining_amount'] = $transaction->amount;", class-nicepay-gateway.php:602-603) ve sonrasında tutarlılıkları hiçbir yerde doğrulanmıyor. Ayrıca SQL'deki ikinci dal (remaining_amount = 0 AND refunded_amount = 0 AND amount >= X) tam olarak iki kaynağın ayrıştığı durumu — capture sonrası defterin doldurulamadığı hali — sessizce kabul ediyor.
```

**Başarısızlık senaryosu**

Bir operatör MONEY-002'yi geçici olarak aşmak için legacy bir satırda elle `UPDATE ... SET captured_amount = 100000` çalıştırır ama remaining_amount'ı 0 bırakır. process_refund $remaining_before = 100000 - 0 = '100000' hesaplar ve tam iadeye izin verir; claim sorgusu ise ikinci dal sayesinde (remaining_amount=0, refunded_amount=0, amount>=100000) geçer. İade PG'ye gider. Ardından nicepay_complete_transaction_refund remaining_amount'ı '0' yazar — ama bu satır zaten iade edilmiş bir bakiye için ikinci kez claim alabilecek durumdaydı; yalnızca cancel_status='confirmed' olması ikinci turu engeller. Denetim izi tutarsız kalır ve hangi tutarın gerçekten iade edildiği yalnızca refund_attempts tablosundan çıkarılabilir.

**Etki**

İki muhasebe kaynağının ayrışması (elle SQL müdahalesi, yarım kalmış migrasyon, MONEY-001/002'deki legacy satırlar) tespit edilmez. SQL'deki ikinci dal sayesinde captured_amount/remaining_amount doldurulmamış bir 'paid' satır yine de claim alabilir; PHP tarafı ise aynı satırda $captured'ı '0.00' okuyup farklı bir sonuca varır. Sistem tutarsız iki cevap üretir ve hangisinin doğru olduğunu belirleyen bir kontrol yoktur.

**Öneri**

Defterde tek bir doğruluk kaynağı belirleyin. Tercihen remaining_amount'ı türetilmiş değil, otoriter kılın ve process_refund'un başında bir tutarlılık kapısı ekleyin: `$derived = nicepay_subtract_integer_amounts( $captured, $refunded ); $stored = nicepay_normalize_ledger_amount( $transaction->remaining_amount ); if ( false === $stored || 0 !== nicepay_compare_integer_amounts( $derived, $stored ) ) { nicepay_update_transaction( $transaction->id, array( 'reconciliation_status' => 'required', 'reconciliation_note' => 'ledger_balance_divergence' ) ); return new WP_Error( 'nicepay_refund_ledger_divergence', ... ); }`. Claim sorgusundaki ikinci dalı (remaining_amount = 0 AND refunded_amount = 0) kaldırın ve bunun yerine MONEY-001'deki migrasyon backfill'i ile remaining_amount'ı doğru dolduran bir yol sağlayın.

---

### MONEY-024 — config_fingerprint her denemede hesaplanıp saklanıyor ama hiçbir yerde doğrulanmıyor — var olmayan bir bütünlük kontrolü izlenimi veriyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | dead-control |
| **Konum** | [includes/class-nicepay-gateway.php:276](../../../includes/class-nicepay-gateway.php#L276) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
WooCommerce tarafında yazılıyor:

    'config_fingerprint' => hash(
        'sha256',
        wp_json_encode( array( $order->get_id(), $amount, $currency, $enabled_methods ) )
    ),

Offer resolver de her teklif için bir parmak izi üretip döndürüyor (class-nicepay-offer-resolver.php:157) ve ajax_init_payment bunu saklıyor. Ancak `grep -rn "config_fingerprint" includes admin nicepay-payment-gateway.php` çıktısında yalnızca bu üç yazma noktası ve şema tanımı var; hiçbir okuma/karşılaştırma yok. validate_auth_return ve validate_approval_response bu alana hiç bakmıyor.
```

**Başarısızlık senaryosu**

Yönetici, bir müşteri ödeme penceresini açtıktan sonra kayıtlı shortcode'un yöntemini CARD'dan BANK'a çevirir. Dönen auth, satırda saklı expected_method ile doğrulandığı için doğru davranır — ama bu doğrulama config_fingerprint sayesinde değil, expected_method sayesindedir. Bir sonraki geliştirici expected_method kontrolünü kaldırıp 'zaten fingerprint var' diye düşünürse hiçbir koruma kalmaz ve yanlış yöntemle onay bağlanabilir.

**Etki**

Sınıf doc-block'ları ve sütun adı, teklif konfigürasyonunun değişip değişmediğinin doğrulandığı izlenimini veriyor; gerçekte hiçbir kontrol yok. Bu, incelemede yanlış güvence, bakımda ise ölü şema alanı ve gereksiz hash maliyeti demek.

**Öneri**

Ya kontrolü gerçekten uygula — validate_auth_return içinde teklifi yeniden çözüp (`NicePay_Offer_Resolver::resolve_standalone( $transaction->source_ref, $transaction->expected_method )`) parmak izini `hash_equals` ile karşılaştır ve uyuşmazlıkta ticari koşullar değişmiş sayarak reddet — ya da alanı ve hesaplamaları tamamen kaldırıp şemadan düşür. Ara durum en kötüsü.

---

### MONEY-025 — Auth dönüş imzası Moid'i bağlamıyor: yanlış eşleşen bir kimlik doğrulama ancak para hareket ettikten sonra tespit ediliyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | binding |
| **Konum** | [includes/class-nicepay-api.php:111](../../../includes/class-nicepay-api.php#L111) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Auth imzası yalnızca token, MID ve tutarı kapsıyor:

    $plain = $auth_token . $this->mid . $amt . $this->merchant_key;
    $expected = hash( 'sha256', $plain );
    return hash_equals( $expected, $received_signature );

validate_auth_return ise satırı POST'taki Moid ile buluyor (:57-58) ve Moid ile AuthToken arasında hiçbir kriptografik bağ doğrulamıyor. Moid'in gerçekten bu auth'a ait olduğu ancak approval yanıtında kontrol ediliyor:

    if ( '' === $stored_moid || ! hash_equals( $stored_moid, $result['Moid'] ) ) {
        return self::error( 'nicepay_approval_moid_mismatch', ... );
    }

yani sıralama: yanlış Moid ile gelen geçerli bir auth önce claim alır, sonra request_approval() ile GERÇEK bir onay çağrısı yapılır, uyuşmazlık ancak yanıt geldiğinde anlaşılır ve net cancel denenir.
```

**Başarısızlık senaryosu**

Saldırgan aynı mağazada 10.000 KRW'lik iki sipariş oluşturur; birinci siparişin ödeme formunu tamamlar ve ReturnURL POST'unu tarayıcıda yakalayıp Moid alanını ikinci siparişin Moid'i ile değiştirip gönderir. validate_auth_return ikinci satırı bulur (pending, aynı MID, aynı tutar, imza AuthToken+Amt üzerinden geçerli) ve doğrular. Claim alınır, request_approval çağrılır ve NICEPAY birinci auth'u gerçekten onaylar. validate_approval_response, yanıttaki Moid birinci sipariş olduğu için uyuşmazlık verir; net cancel denenir ama ikinci sipariş kalıcı olarak needs_reconciliation'a kilitlenir ve MONEY-002 gereği bir daha ödenemez.

**Etki**

Geçerli bir auth bağlamına sahip olan taraf (kendi ödemesini yapan bir kullanıcı), aynı MID ve aynı tutarda başka bir bekleyen işlemin Moid'ini bilirse o işlemi kalıcı olarak needs_reconciliation'a itebilir; bu MONEY-002 nedeniyle o siparişi ölü hâle getirir. Moid 16 hex rastgele bayt içerdiği için tahmin edilemez olması riski büyük ölçüde sınırlıyor, ancak koruma tesadüfi (rastgelelik) değil yapısal (imza) olmalı.

**Öneri**

Onaylanmadan önce yerel bir bağ kur: ödeme formunda ReqReserved alanına satır kimliğinden türetilmiş bir HMAC koy (`hash_hmac('sha256', $moid . '|' . $tx_id, wp_salt('nonce'))`) ve validate_auth_return içinde POST'taki ReqReserved'ı bu değerle hash_equals ile karşılaştır (gateway zaten $req_reserved'ı okuyor ama hiç kullanmıyor — class-nicepay-gateway.php:406). Alternatif olarak approval çağrısından ÖNCE ek bir yerel tutarlılık kontrolü (aynı MID + aynı tutarda birden fazla pending varsa reddet) uygula.

---

### MONEY-026 — Üç farklı iade reddi tek ve yanıltıcı bir hata koduyla/mesajıyla dönüyor: operatör neyi düzelteceğini anlayamıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | error-quality |
| **Konum** | [includes/class-nicepay-gateway.php:733](../../../includes/class-nicepay-gateway.php#L733) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Aynı kod ve aynı 'para birimi' odaklı mesaj üç ayrı nedene veriliyor:

    // 1) yakalanan tutar ile sipariş toplamı uyuşmuyor
    if ( false === $captured || false === $refunded || false === $order_total ||
        ! hash_equals( $captured, $order_total ) ) {
        return new WP_Error( 'nicepay_refund_amount_error', __( 'Refund amount is invalid for this currency.', ... ) );
    }
    // 2) talep bakiyeden büyük
    if ( false === $cancel_amt || nicepay_compare_integer_amounts( $cancel_amt, $remaining_before ) > 0 ) {
        return new WP_Error( 'nicepay_refund_amount_error', __( 'Refund amount exceeds the captured balance.', ... ) );
    }
    // 3) Woo iade defteri uyuşmuyor
    if ( ... ! hash_equals( $expected_order_refunded, $order_refunded ) ) {
        return new WP_Error( 'nicepay_refund_amount_error', __( 'WooCommerce refund records do not match this NicePay refund request.', ... ) );
    }

1. dal, sipariş toplamı ödemeden sonra elle değiştirildiğinde de tetikleniyor ve 'para birimi' mesajı tamamen alakasız kalıyor. Admin tek-tık iadesinde ise tüm bu ayrımlar `__( 'Refund could not be completed. Review the order notes before retrying.' )` içinde kayboluyor — üstelik MONEY-007 gereği sipariş notu da yazılmıyor.
```

**Başarısızlık senaryosu**

Yönetici ödeme sonrası siparişe 500 KRW'lik kargo ücreti ekler (get_total 10.000'den 10.500'e çıkar, captured_amount 10.000 kalır). Ardından iade denemesi yapar. process_refund 732. satırda hash_equals('10000','10500') false görür ve 'Refund amount is invalid for this currency.' döndürür. Yönetici para biriminde bir sorun arar, gerçek neden olan 'ödeme sonrası sipariş toplamı değiştirildi' bilgisine hiçbir yerde ulaşamaz.

**Etki**

Yönetici, iadenin neden reddedildiğini anlayamıyor: sipariş toplamı mı değişti, WooCommerce iade kaydı mı uyuşmuyor, bakiye mi yetersiz? Özellikle MONEY-003 senaryosunda mesaj tamamen yanlış yönlendiriyor ('para birimi' sorunu yok, yuvarlama sapması var).

**Öneri**

Her nedene ayrı ve konuşan bir hata kodu ver: `nicepay_refund_order_total_changed` ('Sipariş toplamı ödemeden sonra değiştirildi; iade için önce toplamı orijinal tahsil edilen tutara döndürün'), `nicepay_refund_exceeds_balance`, `nicepay_refund_ledger_mismatch` (beklenen ve gerçek toplamları mesajda göster). ajax_cancel_transaction içinde wc_create_refund'ın döndürdüğü WP_Error mesajını (sanitize ederek) yöneticiye ilet ve her red için bir sipariş notu bırak.

---

### MONEY-027 — Ölü VBANK para yolu: sertifikasyon dışı olmasına rağmen kod, sanal hesap 'basımını' tahsilat sayacak şekilde yazılmış

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | latent-defect |
| **Konum** | [includes/class-nicepay-api.php:644](../../../includes/class-nicepay-api.php#L644) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
is_success_code VBANK'ı başarı sayıyor:

    $success_codes = array(
        'CARD'      => '3001',
        'BANK'      => '4000',
        'VBANK'     => '4100',
        ...
    );

Handle_return bu 'başarı'yı doğrudan tahsilat gibi işliyor:

    $update_data['status']           = 'paid';
    $update_data['captured_amount']  = $transaction->amount;
    $update_data['remaining_amount'] = $transaction->amount;
    ...
    $order->payment_complete( $tid );

Oysa 4100, sanal hesabın MÜŞTERİYE ATANDIĞI (henüz para yatırılmadığı) anlamına gelir. Şu an nicepay_get_enabled_methods() yalnızca CARD/BANK/CELLPHONE döndürdüğü için bu yol erişilemez; gateway:346'daki VBANK bloğu, vbank_num/vbank_issued_at/vbank_deposited_at sütunları ve return-handler'daki VBANK ekranı da ölü.
```

**Başarısızlık senaryosu**

Gelecekte bir geliştirici, sertifikasyonun tamamlandığını düşünüp nicepay_get_enabled_methods()'daki $certified dizisine 'VBANK' ekler. Müşteri sanal hesap seçer, NICEPAY ResultCode='4100' ile hesap numarasını döner. is_success_code true olur → status='paid', captured_amount=50.000, remaining_amount=50.000 yazılır, payment_complete() çağrılır, stok düşer ve sipariş 'processing' olur. Müşteri hiç para yatırmaz. Mağaza ürünü gönderir; ayrıca yönetici panelinde 50.000 KRW 'iade edilebilir bakiye' görünür ve iade denemesi PG'de reddedilir.

**Etki**

Şu an istismar edilemez, ancak sertifikasyon listesine VBANK eklendiği anda para yatırılmamış bir sanal hesap 'ödendi' sayılacak: sipariş processing'e geçecek, stok düşecek, iade edilebilir bakiye görünecek ve mağaza hiç almadığı para için ürün gönderecek. Ayrıca ölü VBANK kodu/şeması bakım yükü ve okuyucu için yanıltıcı.

**Öneri**

VBANK'ı ya tam yaşam döngüsüyle uygula (4100 → status='waiting', vbank_num/vbank_expires_at yaz, payment_complete YOK, `$order->update_status('on-hold')`, yatırma bildirimi/webhook geldiğinde payment_complete) ya da şimdilik tamamen çıkar: is_success_code'dan VBANK/SSG_BANK/GIFT_CULT satırlarını, gateway:346-348 bloğunu ve return-handler'daki VBANK ekranını sil, sütunları da bir sonraki şema sürümünde kaldırmayı planla. En azından is_success_code'a savunma ekle: `if ( ! in_array( $payment_method, nicepay_get_enabled_methods(), true ) ) { return false; }`.

---

### MONEY-028 — İptal yanıtındaki RemainAmt yok sayılıyor: PG'nin otoriter kalan bakiyesi ile yerel defter hiç çapraz kontrol edilmiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | reconciliation |
| **Konum** | [includes/class-nicepay-gateway.php:852](../../../includes/class-nicepay-gateway.php#L852) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Başarılı iade sonrası kalan bakiye tamamen yerel aritmetikle belirleniyor:

    $ledger_updated = nicepay_complete_transaction_refund( $transaction->id, $cancel_moid, array(
        'status'           => $is_partial ? 'partially_refunded' : 'refunded',
        'refunded_amount'  => nicepay_add_integer_amounts( $refunded, $cancel_amt ),
        'remaining_amount' => $remaining_after,
        ...
    ) );

request_cancel yanıt doğrulaması yalnızca TID, CancelAmt ve imzayı kontrol ediyor (class-nicepay-api.php:615-632); `$result['RemainAmt']` hiçbir yerde okunmuyor (`grep -rn RemainAmt includes admin` → yalnızca docs). Oysa docs/API-REFERENCE.md:287 bu alanı 'Remaining amount after cancel' olarak listeliyor.
```

**Başarısızlık senaryosu**

Yönetici 10.000 KRW'lik bir ödemenin 4.000 KRW'sini NICEPAY konsolundan iade eder (eklenti bunu bilmez; defter remaining_amount=10.000 der). Sonra WooCommerce'ten 6.000 KRW iade eder; claim geçer (10.000 >= 6.000), PG iadeyi kabul eder ve RemainAmt='0' döner. Eklenti bunu okumadığı için remaining_amount=4.000 yazar. Panelde 4.000 KRW iade edilebilir görünür; yönetici üçüncü bir iade denediğinde PG reddeder ve satır needs_reconciliation'a düşer — MONEY-002 gereği oradan çıkış yolu yoktur.

**Etki**

Yerel defter ile NICEPAY'in kayıtları sessizce ayrışabilir (ör. konsoldan yapılmış bir iade, ya da MONEY-003 kaynaklı yuvarlama sapması). Eklenti bu ayrışmayı tespit edebilecek tek ücretsiz sinyali kullanmıyor; sonuç olarak 'kalan bakiye' ekranda doğru görünürken gerçekte yanlış olabilir ve bir sonraki iade PG tarafından reddedilir.

**Öneri**

request_cancel yanıtında RemainAmt varsa kanonikleştirip yerel hesapla karşılaştır ve uyuşmazlıkta iadeyi başarılı saymaya devam et ama satırı mutabakat için işaretle:

    $remote_remaining = isset( $result['RemainAmt'] )
        ? nicepay_normalize_response_amount( (string) $result['RemainAmt'], 'KRW' )
        : null;
    if ( null !== $remote_remaining && false !== $remote_remaining &&
        ! hash_equals( $remaining_after, $remote_remaining ) ) {
        // remaining_amount = $remote_remaining, reconciliation_status = 'required',
        // reconciliation_note = 'remote_remaining_mismatch', sipariş notu ekle
    }

Ayrıca aynı alanı düzenli bir 'defter sağlık kontrolü' için sakla.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

[Geçiş 1] İncelenen dosyalar (tamamı, nihai hali, satır satır): includes/class-nicepay-gateway.php (919 satır — is_available, process_payment, receipt_page, recover_paid_order_from_ledger, generate_payment_form, handle_return, process_refund), includes/nicepay-functions.php (para/defter/claim/expiry/receipt bölümleri: 90-500, 540-1180, 1260-1700), includes/class-nicepay-return-handler.php (374 satır, tamamı), includes/class-nicepay-transaction-schema.php (296 satır, tamamı), includes/class-nicepay-offer-resolver.php (198 satır, tamamı), includes/class-nicepay-inbound-validator.php (299 satır, tamamı), includes/class-nicepay-api.php (imza/URL politikası/approval/net-cancel/cancel/result-code bölümleri: 60-200, 350-460, 455-720), includes/class-nicepay-installer.php (100-300, migrasyon ve legacy scrub), includes/class-nicepay-retention.php (1-340, tamamı), includes/class-nicepay-blocks-integration.php (76 satır, tamamı), nicepay-payment-gateway.php (90-660: hook kaydı, aktivasyon, cron, shortcode render, ajax_init_payment), admin/class-nicepay-transactions.php (finansal özet, liste render, CSV export sütunları, ajax_cancel_transaction), admin/class-nicepay-admin.php (ayar kaydı/sanitizasyon, readiness kontrolleri), templates/payment-form.php, assets/js/nicepay.js (WC dalı + nicepaySubmit/nicepayClose). Testler: NicePayRefundTest, NicePayTransactionRepositoryTest, NicePayFunctionsTest (tutar bölümleri), NicePayInboundValidatorTest (Amt bölümleri), NicePayGatewayDefaultsTest test adları. Karşılaştırma için origin/main:nicepay-payment-gateway.php içindeki eski CREATE TABLE tanımı okundu (legacy şema doğrulaması için). DOĞRULANAMAYAN NOKTALAR: (1) Ortamda PHP yok (php: command not found), bu yüzden hiçbir bulgu çalıştırılarak değil, kodun okunmasıyla doğrulandı; empty('0.00') === false ve nicepay_normalize_amount yuvarlama davranışı PHP semantiğinden ve mevcut birim testlerinden (NicePayFunctionsTest.php:69-75, 96) türetildi. (2) NICEPAY PG-Web'in auth-return POST'unda Amt'yi sabit genişlikte gönderip göndermediğini üretici dokümanı olmadan doğrulayamadım — MONEY-006 bu nedenle medium confidence. (3) WooCommerce çekirdeğinin process_payment 'failure' dönüşündeki tam davranışı (WC_Checkout::process_order_payment) kaynak kodundan değil bilgiden hatırlandı — MONEY-010 medium confidence. (4) MySQL'in çok-tablolu self-JOIN UPDATE'inde eşzamanlı claim'lerin semi-consistent read davranışını gerçek bir veritabanında test etmedim; okuma sonucu doğru göründüğü için bulgu açmadım ama tests/integration/schema-migration.php ile gerçek MySQL üzerinde eşzamanlılık testi eklenmesi önerilir. (5) VBANK deposit bildirimi (webhook) akışı bu PR'da hiç yok; webhook + return yarışını bu yüzden değerlendiremedim — mevcut mimaride yalnızca browser-return vardır.

[Geçiş 2] İncelenen dosyalar (tam okuma): includes/class-nicepay-gateway.php (919 satır, özellikle process_payment/receipt_page/recover_paid_order_from_ledger/generate_payment_form/handle_return/process_refund), includes/nicepay-functions.php (para yardımcıları 1454-1663, repository 338-476, claim/abandon/expire 596-985, mutabakat/makbuz 994-1172, abort/audit 120-330), includes/class-nicepay-return-handler.php (374 satır), includes/class-nicepay-transaction-schema.php (296 satır), includes/class-nicepay-inbound-validator.php (299 satır), includes/class-nicepay-offer-resolver.php (198 satır), includes/class-nicepay-installer.php (301 satır), includes/class-nicepay-retention.php (340 satır), includes/class-nicepay-api.php (1-700), includes/class-nicepay-blocks-integration.php, templates/payment-form.php, nicepay-payment-gateway.php (30-700, özellikle ajax_init_payment ve return/receipt yönlendirmeleri), admin/class-nicepay-transactions.php (iade butonu gating 780-830 ve ajax_cancel_transaction 969-1027). Testler: tests/unit/NicePayRefundTest.php (tam) ve tests/integration/schema-migration.php (1-140) okundu; NicePayTransactionRepositoryTest/NicePayFunctionsTest/NicePayOfferResolverTest yalnızca dosya listesi düzeyinde teyit edildi. Ayrıca `git show origin/main` ile 1.x şeması ve 1.x nicepay_save_transaction varsayılanları karşılaştırıldı; docs/API-REFERENCE.md'nin alan boyutu/dolgu notları kod ile çapraz kontrol edildi.

İncelenemeyenler ve neden: Ortamda `php` binary'si yok (`which php` başarısız), bu yüzden hiçbir birim testi çalıştırılamadı, `php -l` sözdizimi kontrolü yapılamadı ve tutar yardımcıları canlı olarak doğrulanamadı — bunların davranışı satır satır elle izlendi (özellikle nicepay_normalize_amount'ın yuvarlama ve sprintf('%.8F') + rtrim davranışı). MySQL/MariaDB de yok, bu nedenle MONEY-001'deki dbDelta ve strict-mode analizi çalıştırılarak değil, WordPress'in `wpdb::set_sql_mode()` uyumsuz-mod listesi, `wpdb::query()`'nin `flush()` ile last_error'ı sıfırlaması ve dbDelta'nın yalnızca tip/DEFAULT karşılaştırması gibi belgelenmiş davranışlardan çıkarıldı; gerçek bir 1.x tablosuyla doğrulanması şiddetle önerilir. assets/js/*.js dosyaları yalnızca para akışını etkileyen noktalar açısından tarandı, satır satır incelenmedi (UX boyutuna bırakıldı). docs/analysis/03-payment-flow-and-money-correctness.md bilinçli olarak kanıt kaynağı olarak kullanılmadı; tüm bulgular üretim kodundan doğrulandı.

**Açık sorular**

- NICEPAY PG-Web auth-return POST'unda Amt alanı sabit 12 byte sıfır dolgulu mu geliyor, yoksa kanonik mi? Onay yanıtı için sıfır dolgulu format açıkça destekleniyor (NicePayInboundValidatorTest.php:332) ama auth dönüşü katı normalizasyon kullanıyor — üretici fixture'ı ile netleştirilmeli (MONEY-006).
- v1.x'ten yükseltme senaryosu için gerçek bir üretim veritabanı örneği üzerinde regresyon testi var mı? tests/integration/schema-migration.php yalnızca yeni şema alanlarıyla satır ekliyor (satır 85-97); flow/mid/mode/captured_amount içermeyen gerçek legacy satırlarla iade akışı hiç test edilmemiş.
- captured_amount/remaining_amount sütunlarının 'authoritative' mi yoksa 'türetilmiş' mi olduğu tasarımca kararlaştırılmış mı? process_refund türetiyor, claim SQL'i saklanan sütuna bakıyor — hangisi sözleşme?
- VBANK için ürün kararı ne? Kaldırılacak mı, yoksa deposit webhook'u ile tamamlanacak mı? Şemadaki beş vbank_* sütunu ve idx_vbank_expires_at indeksi hangi plana göre eklendi?
- needs_reconciliation kayıtlarının kapatılması için hedeflenen operasyon modeli nedir — doğrudan SQL mi bekleniyor, yoksa arayüz aksiyonu yol haritasında mı? Mevcut haliyle sayaç asla sıfırlanamıyor.
- Retention MIN_DAYS = 1 bilinçli bir seçim mi? Kore'de elektronik ticaret kayıtları için yasal saklama süresi (5 yıl) ile bu alt sınır nasıl bağdaşıyor?
- WooCommerce'te 'Refund manually' kullanımı ürün olarak destekleniyor mu? Destekleniyorsa defterin bunu modellemesi gerekir; desteklenmiyorsa iade ekranında bu düğmenin devre dışı bırakılması veya en azından belgelenmesi gerekir (MONEY-004).
- MONEY-001: Gerçek bir 1.x kurulumunda (tid varchar(50) NOT NULL DEFAULT '', birden çok boş tid'li satır) yükseltme çalıştırıldığında dbDelta uniq_tid'i gerçekten oluşturamıyor mu, yoksa MariaDB sürümüne göre farklı mı davranıyor? Bu, üretim öncesi tek bir disposable veritabanında mutlaka doğrulanmalı (tests/integration/run-schema-migration.sh'e 1.x fixture'ı eklenerek).
- MONEY-009: NICEPAY merchant hesabının auth ReturnURL POST'unda Amt alanını sabit 12 bayta sıfırla doldurup doldurmadığı sandbox'ta teyit edildi mi? docs/API-REFERENCE.md yalnızca approval yanıtı için dolgu uyarısı veriyor; auth yanıtı için aynı garanti nereden geliyor?
- BANK (실시간계좌이체) kısmi iadesi için NICEPAY sözleşmesi iade hesabı bilgisi (RefundAcctNo/RefundBankCd/RefundAcctNm) talep ediyor mu? Kod CARD ve CELLPHONE için kısmi iade kapıları koymuş ama BANK kısmi iadesini hiçbir yetenek kontrolü olmadan geçiriyor (class-nicepay-gateway.php:763-776) ve request_cancel ek parametreleri bilinçli olarak boşaltıyor (class-nicepay-api.php:587).
- nicepay_abort_authenticated_payment, onayın BAŞARIYLA tamamlandığı ama yerel yazımın başarısız olduğu yolda da NetCancel (망취소) kullanıyor (class-nicepay-gateway.php:606-626). NICEPAY protokolü tamamlanmış bir approval için NetCancel'i kabul ediyor mu, yoksa bu durumda cancel_process.jsp ile normal iptal mi çağrılmalı? Yanlış API kullanımı 'unknown' sonucu üretip gereksiz mutabakat yaratabilir.
- docs/API-REFERENCE.md:167-175'te açıklama paragrafı tablonun ortasına girdiği için TID/AuthCode/AuthDate/PayMethod satırları bozuk render oluyor — dokümantasyon boyutuna ait olsa da protokol alanlarının okunabilirliğini etkiliyor.

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 28 |

## Öncelikli aksiyon listesi

1. **MONEY-015** — Scrub sırasını düzelt ve boş TID'leri de bloklayıcı kontrole al:
1) dbDelta'dan önce sütunu nullable yap: `if ( column_exists(tid) ) { $wpdb->query("ALTER TABLE {$table} MODIFY tid varchar(50) NULL DEFAULT NULL"); }` ardından `UPDATE {$table} SET tid = NULL WHERE tid = ''`.
2) duplicate_tid_count_sql'e boş/çoklu '' durumunu da ekle veya scrub sonrası `SELECT COUNT(*) FROM {$table} WHERE tid = ''` 
2. **MONEY-001** — NicePay_Installer içinde, unique indeksler eklenmeden önce çalışan idempotent bir backfill ekleyin ve şema sürümünü artırın: (a) `UPDATE {$table} SET flow = 'woocommerce' WHERE flow = '' AND wc_order_id IS NOT NULL`, (b) `UPDATE {$table} SET flow = 'standalone' WHERE flow = '' AND wc_order_id IS NULL AND moid <> ''`, (c) `UPDATE {$table} SET currency = 'KRW' WHERE currency = ''`, (d) `UPDATE {$tab
3. **MONEY-003** — Kilidin ömrünü sınırlayın ve operatöre bir kaçış yolu verin. (1) Bayat onay sorgusuna `active_attempt_key = NULL` ekleyin ama aynı UPDATE'te siparişi de kilitleyin — cron çalıştıktan sonra ilgili WC siparişini 'on-hold' + 'needs_reconciliation' notu ile işaretleyen bir kanca ekleyin ki müşteri boş yere denemesin. (2) nicepay_get_mismatched_approval_audit()'e de `'active_attempt_key' => null` ekley
4. **MONEY-005** — Ondalık politikasını fail-closed hale getirin. (1) is_available() içine ekleyin: `if ( function_exists( 'wc_get_price_decimals' ) && 0 !== (int) wc_get_price_decimals() ) { return false; }` — KRW zero-decimal olduğu için bu doğru davranıştır. (2) Admin readiness listesine (admin/class-nicepay-admin.php:427-440) 'Price decimals must be 0 for KRW' satırı ekleyin ve düzeltme bağlantısı verin. (3) gen
5. **MONEY-016** — Mutabakat için gerçek bir operatör iş akışı ekle: (a) admin/class-nicepay-transactions.php'ye nonce+cap korumalı 'Mutabakatı kapat' eylemi — operatörün NICEPAY konsolundaki sonucu seçmesini iste (para çekilmedi → status='failed', reconciliation_status='not_required', active_attempt_key=NULL; para çekildi → status='paid', captured_amount/remaining_amount doldur, siparişi payment_complete ile kapat)
6. **MONEY-017** — KRW'yi uçtan uca tam sayı olarak zorla: (1) is_available() içinde veya bir admin uyarısında `wc_get_price_decimals() !== 0` ise gateway'i kapat/uyar; ayrıca `add_filter('wc_get_price_decimals', ...)` yerine yöneticiye açık bir 'KRW için ondalık sayısını 0 yapın' bildirimi göster. (2) generate_payment_form'da toplamın tam sayı olmadığını tespit edip ödemeyi reddet: `if ( (string) $amount !== rtrim(
7. **MONEY-018** — Installer'a idempotent bir backfill ekle ve şema sürümünü artır:

    if ( self::column_exists( $wpdb, $table, 'flow' ) ) {
        $queries[] = "UPDATE {$table} SET flow = 'woocommerce', source_ref = CAST(wc_order_id AS CHAR)
                      WHERE flow = '' AND wc_order_id IS NOT NULL";
        $queries[] = "UPDATE {$table} SET flow = 'standalone' WHERE flow = '' AND wc_order_id IS NULL";
 
