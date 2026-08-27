# 09 — Yönetim Paneli: İşlemler, Rapor, Export

> PR #3 sistematik review · düşmanca doğrulamalı · 16 bulgu

## Özet

Yönetim paneli güvenlik açısından olgun: her yazma işlemi yetenek + nonce ile korunuyor, filtreler allowlist'ten geçip prepare ile sabit şekilli SQL'e bağlanıyor, CSV formül enjeksiyonuna karşı korunuyor ve cursor tabanlı akışla yazılıyor, detay görünümü PII/kimlik bilgisi sızdırmayan dar bir allowlist üzerine kurulu ve bu davranışların çoğu testle sabitlenmiş. Ancak ekran bir operasyon aracı olarak eksik: needs_reconciliation durumuna düşmüş bir kaydı panelden çözmenin hiçbir yolu yok, kırmızı uyarı bandı ve devre dışı kalan iade butonu kalıcı hale geliyor. Tarih filtreleri ile ekranda gösterilen/CSV'ye yazılan zaman damgaları farklı zaman dilimi sözleşmeleri varsayıyor, bu da Kore saatinde çalışan bir satıcıda gün bazlı mutabakatı sessizce yanlış yapıyor. Ayarlar ekranı girdileri sessizce kırpıp zorluyor ve kullanıcıya hiçbir geri bildirim vermiyor; canlı/test anahtarlarını doğrulamanın hiçbir yolu yok. Son olarak admin_notices her wp-admin sayfasında kapatılamaz uyarı basıyor ve her işlem sayfası yüklemesinde indekslenmemiş tam tablo taramaları artı satır başına wc_get_order() çağrısı yapılıyor.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **B** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 2 |
| 🟡 Orta | 9 |
| 🔵 Düşük | 5 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 0 |
| Bağımsız doğrulama kararı alan bulgu | 2 / 16 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 5 |

## Güçlü yönler

- CSV formül enjeksiyonu gerçekten kapatılmış: sanitize_csv_cell() (admin/class-nicepay-transactions.php:226-236) baştaki Unicode boşluk/kontrol karakterlerini de hesaba katan bir desenle tek tırnak öneki ekliyor, CR/LF'yi boşluğa çeviriyor ve hücreyi 500 bayta kırpıyor.
- CSV gerçekten stream ediliyor: write_csv_export() (:128-218) 250'lik partiler halinde (created_at, id) keyset cursor'u ile ilerliyor, her partide fflush() yapıyor ve SELECT * yerine 16 sütunluk sabit allowlist kullanıyor; bellek patlaması yok.
- Export uç noktası doğru sırayla korunuyor: yetenek (:242), check_admin_referer (:246), şema hazırlık (:248), filtre geçerliliği (:252); hepsi tek bir header() çağrısından önce, ardından nocache_headers + nosniff + exit (:257-272).
- Filtreler tek bir kanonik yerden geçiyor (parse_filters, :34-88) ve SQL tarafında filter_query_values() (:313-349) filtreleri BİR KEZ DAHA doğruluyor; FILTER_SQL sabit şekilli, her istek türevli değer bağlanmış, LIKE için esc_like kullanılmış. Doğrudan get_financial_summary()/write_csv_export() çağrısı bile allowlist dışına çıkamıyor.
- Detay görünümü (get_safe_detail_fields, :360-402) auth_token/payment_data/card_no/buyer_email/buyer_tel/vbank_num'a hiç dokunmuyor; redact_sensitive_text() (:508-532) PAN, e-posta, token şekillerini ayrıca maskeliyor ve NicePayTransactionsAdminTest bunu hem davranış hem kaynak-dizgi düzeyinde sabitliyor.
- Sistem raporu (get_system_report_data, :222-307) katı bir allowlist; MID, merchant key, DB bilgisi, dosya yolu, URL veya sunucu başlığı içermiyor, her değer sanitize_report_value() ile 100 bayta kırpılıyor ve textarea esc_textarea ile basılıyor.
- Modal/toast JS tamamen DOM API ile kuruluyor (element() + textContent, assets/js/nicepay-admin.js:16-25); innerHTML hiç kullanılmıyor. tests/js/nicepay-admin.test.js:121-142 yerelleştirilmiş metinlerin bile çalıştırılabilir markup üretemediğini doğruluyor.
- Modal odak tuzağı, aria-modal/aria-busy/role=alert, kapatıldığında odağın tetikleyen butona dönmesi ve yükleme sırasında kapatmanın engellenmesi düzgün uygulanmış (nicepay-admin.js:99-238) ve testle doğrulanmış.
- İade AJAX'ı istemciden tutar almıyor: ajax_cancel_transaction() (:969-1027) satırı id ile yeniden okuyor, TID'yi sipariş meta ile hash_equals ile eşleştiriyor, durum/mutabakat/cancel_status kapılarını yeniden uyguluyor ve tutarı yalnızca remaining_amount'tan türetiyor.
- Merchant key alanları type=password + autocomplete=new-password, değer HİÇ geri basılmıyor, boş bırakılınca korunuyor ve ayrı bir 'temizle' onay kutusu var (admin/class-nicepay-admin.php:658-691).
- Sipariş ekranı özeti woocommerce_admin_order_data_after_order_details üzerinden bağlanmış (:16), böylece HPOS ve legacy düzenleyicilerin ikisinde de ekran-ID varsayımı olmadan çalışıyor.
- Saklama (retention) ayarı, sektörde nadir görülen bir titizlikle ele alınmış: kalıcı silmenin yasal sonuçları, korunan kayıt sınıfları ve 'önce CSV al' uyarısı açıkça yazılmış, ayrıca zorunlu bir onay kutusu ve add_settings_error ile gerçek doğrulama geri bildirimi var (:522-602).

## Bulgular

### ADMIN-001 — Tarih aralığı filtresi ile ekranda gösterilen/CSV'ye yazılan zaman damgaları farklı zaman dilimlerinde; gün bazlı mutabakat sessizce yanlış

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | correctness |
| **Konum** | [admin/class-nicepay-transactions.php:793](../../../admin/class-nicepay-transactions.php#L793) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
Listeleme satırı zaman damgasını GMT kabul edip site saatine çeviriyor:

    $created_at = function_exists( 'get_date_from_gmt' )
        ? get_date_from_gmt( $item->created_at, 'Y-m-d H:i:s' )
        : $item->created_at;

Ama aynı ekrandaki tarih filtresi ham tarihe saat ekleyip DÖNÜŞTÜRÜLMEMİŞ sütunla karşılaştırıyor (filter_query_values, :339-342):

    '' !== $from ? $from . ' 00:00:00' : '',
    '' !== $to   ? $to   . ' 23:59:59' : '',

ve nicepay_get_transactions() aynısını yapıyor (includes/nicepay-functions.php:1213-1218). Üstelik created_at hiçbir zaman PHP tarafından yazılmıyor: şemada 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP' (class-nicepay-transaction-schema.php:112) ve prepare_write() created_at'i 'database_owned' olarak yazımlardan çıkarıyor (:258). CURRENT_TIMESTAMP MySQL oturum zaman dilimini kullanır; WordPress MySQL session time_zone'u UTC'ye ayarlamaz. Yani get_date_from_gmt()'in GMT varsayımı da garanti değil. CSV ise created_at/approved_at'i hiç dönüştürmeden ham yazıyor (:144) ve dosyada hiçbir zaman dilimi notu yok.
```

**Başarısızlık senaryosu**

Site zaman dilimi Asia/Seoul. 2026-08-27 08:00 KST'de 100.000 KRW'lik bir ödeme onaylanıyor; DB'ye (UTC varsayımıyla) 2026-08-26 23:00 yazılıyor. İşlemler ekranı satırı '2026-08-27 08:00' olarak gösteriyor. Satıcı date_from=2026-08-27 & date_to=2026-08-27 filtreliyor: SQL created_at BETWEEN '2026-08-27 00:00:00' AND '2026-08-27 23:59:59' oluyor, kayıt DIŞARIDA kalıyor. Aynı satıcı 27 Ağustos CSV'sini indirdiğinde bu 100.000 KRW hiç görünmüyor, ama 27 Ağustos 09:00 KST'den sonraki kayıtların yanında 28 Ağustos sabahına ait kayıtlar görünüyor.

**Etki**

Kore'de (UTC+9) çalışan bir satıcıda gün bazlı filtreleme ve CSV mutabakatı sistematik olarak 9 saat kayıyor. Ekranda listelenen bir satır kendi tarihiyle filtrelenince kaybolabiliyor; günlük/aylık ciro dökümü hatalı çıkıyor. Muhasebe/vergi mutabakatında doğrudan para tutarsızlığına yol açar.

**Öneri**

Tek bir zaman dilimi sözleşmesi belirleyin ve her yerde uygulayın. Önerilen: created_at/updated_at'i açıkça UTC olarak PHP tarafından yazın (schema'da DEFAULT CURRENT_TIMESTAMP yerine prepare_write üzerinden gmdate('Y-m-d H:i:s')), sonra filtreyi site saatinden UTC'ye çevirin:

    $from_utc = get_gmt_from_date( $from . ' 00:00:00', 'Y-m-d H:i:s' );
    $to_utc   = get_gmt_from_date( $to . ' 23:59:59', 'Y-m-d H:i:s' );

CSV'ye ayrıca 'Created At (UTC)' / 'Created At (site)' başlıkları koyun veya en azından başlıkta zaman dilimini açıkça belirtin, ve filtre alanlarının yanına 'Tarihler site saatine göredir' açıklaması ekleyin.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: İşlemler ekranı `created_at` sütununu iki çelişkili sözleşmeyle kullanıyor: görüntülemede GMT->site dönüşümü uygulanıyor (admin/class-nicepay-transactions.php:793-795, :921), buna karşılık tarih filtresi (FILTER_SQL :19-20, filter_query_values :339-342), liste sorgusu (includes/nicepay-functions.php:1211-1218), ekrandaki ciro özeti (:563-565) ve CSV export (:144, :167) ham sütunla karşılaştırıyor. Site zaman dilimi UTC olmadığı sürece — hedef pazar Kore, Asia/Seoul (UTC+9) — ekranda gösterilen zaman damgası ile filtrelenen değer daima ofset kadar sapar; her günün 9 saatlik dilimine düşen kayıtlar yanlış güne kovalanır. Ayrıca tek bir satırın iki zaman sütunu farklı sözleşmede: `approved_at` PHP tarafından açıkça UTC yazılıyor (class-nicepay-gateway.php:601, class-nicepay-return-handler.php:187, gmdate()) ama `created_at` DB'nin CURRENT_TIMESTAMP'ından geliyor (schema:112; prepare_write:258 onu database_owned olarak yazımlardan dışlıyor) ve MySQL oturum zaman dilimi ne plugin ne de WordPress tarafından ayarlanıyor. DÜZELTME: Orijinal iddianın "DB UTC yazıyor, dolayısıyla ekranda görünen satır filtrede kaybolur" yönü kanıtlanamaz — MySQL yerel saat yazıyorsa sapma ters yöne gider (filtre doğru, ekran +9 saat yanlış). Garanti olan şey sapmanın YÖNÜ değil, ekran/filtre/CSV arasındaki tutarsızlığın kendisi ve created_at ile approved_at'in aynı CSV'de etiketsiz olarak farklı zaman dilimlerinde basılması. UI'da hiçbir zaman dilimi açıklaması yok (grep "timezone" -> 0 sonuç).
- Gerekçe: Kodu satır satır okudum; iddianın ÇEKİRDEĞİ doğru ve tüm satır referansları dosyaların şu anki haliyle birebir eşleşiyor. Ancak istismar edilebilirlik lensinden bakınca failure_scenario'nun bir öncülü kanıtlanmamış, buna karşılık gerçek kusur iddia edilenden DAHA sağlam (daha az koşula bağlı) ve ek bir boyutu atlanmış.

DOĞRULANAN ÇEKİRDEK — koşulsuz ekran/filtre tutarsızlığı:
Aynı ekranda tek bir `created_at` sütunu iki farklı sözleşmeyle kullanılıyor. Görüntüleme sütunu GMT->site dönüşümü uyguluyor (:793-795, :921), buna karşılık filtre predikatı (FILTER_SQL :19-20 + filter_query_values :339-342), nicepay_get_transactions() (:1213-1218) ve CSV export (:144, :167) HAM sütunla karşılaştırıyor. Bu, veritabanının hangi zaman diliminde yazdığından BAĞIMSIZ olarak, site zaman dilimi UTC olmadığı sürece garantili bir uyumsuzluk üretir: ekrandaki değer ile filtrelenen değer daima ofset kadar farklıdır. Asia/Seoul'de bu 9 saattir; yani her günün 24 saatinin 9'una (%37,5) denk gelen kayıtlar yanlış güne düşer. `get_financial_summary()` de aynı `$filter_state['filters']` ile çalıştığı (:563-565) için ekrandaki para toplamları da aynı ofsetle yanlış kovalanır — sadece CSV değil.

ULAŞILABİLİRLİK — gerçek ve tetiklenmesi önemsiz:
- Rol: `manage_woocommerce`/admin. Ölü kod yolu değil; nicepay_get_transactions() gerçekten çağrılıyor (admin/class-nicepay-transactions.php:558).
- İstek 1: GET /wp-admin/admin.php?page=nicepay-transactions&date_from=2026-08-27&date_to=2026-08-27 — parse_filters (:64-77) tarihi doğruluyor, filter_query_values ham ' 00:00:00'/' 23:59:59' ekliyor.
- İstek 2: GET /wp-admin/admin-post.php?action=nicepay_export_transactions&date_from=...&date_to=...&_wpnonce=... — aynı filtreler, dönüşümsüz CSV.
Ek adım, özel yetki, yarış koşulu veya nadir yapılandırma gerekmiyor. Ürünün hedef pazarı KRW/Kore olduğu için site tz = Asia/Seoul varsayılan senaryo, kenar durum değil.

DÜZELTİLEN NOKTA 1 (öncül kanıtlanmamış):
failure_scenario "DB'ye (UTC varsayımıyla) 2026-08-26 23:00 yazılıyor" diyor. Bu KANITLANAMAZ: created_at gerçekten DB-sahipli (schema :112 DEFAULT CURRENT_TIMESTAMP; prepare_write :258 'created_at'i database_owned olarak yazımlardan atıyor) ve CURRENT_TIMESTAMP MySQL oturum zaman dilimine bağlı. Repo'da `SET time_zone` / `@@session` araması sonuçsuz — plugin bunu ayarlamıyor, WordPress de ayarlamıyor. Yani DB pekâlâ sunucu yerel saatini (KST) yazıyor olabilir; o durumda ekran +9 saat İLERİ yanlış gösterir ve filtre DOĞRU çalışır — yani senaryodaki "ekranda görünen satır filtrede kayboluyor" yönü tersine döner ("filtreye giren satır ekranda yanlış saatle görünür, ertesi günün kayıtları o güne sızar"). Sonuç yine yanlış mutabakat, ama iddianın anlattığı SPESİFİK yönü garanti değil; garanti olan şey yön değil tutarsızlığın kendisi.

DÜZELTİLEN NOKTA 2 (iddianın atladığı, daha kesin kanıt):
Aynı satırda `approved_at` PHP tarafından AÇIKÇA UTC yazılıyor — class-nicepay-gateway.php:601 ve class-nicepay-return-handler.php:187 `gmdate('Y-m-d H:i:s')`. `created_at` ise DB CURRENT_TIMESTAMP. Yani MySQL oturumu UTC değilse tek bir satırın iki zaman sütunu farklı zaman dilimindedir; CSV bunları yan yana "Created At" / "Approved At" başlıklarıyla (:149-152) etiketsiz basar ve approved_at ekranda hiç dönüştürülmez. Bu, "zaman dilimi sözleşmesi yok" iddiasının spekülasyona ihtiyaç duymayan kanıtıdır ve kanıt bölümünde eksikti.

SEVERITY: `high` korunmalı, abartı değil. Gerekçe: (a) sessiz — hiçbir uyarı/etiket yok, admin/class-nicepay-transactions.php içinde "timezone" geçen tek satır yok, filtre inputlarının (:681) yanında açıklama yok; (b) sistematik ve tekrarlanabilir, rastgele değil; (c) doğrudan muhasebe çıktısını (CSV + ekran ciro özeti) etkiliyor. Tek hafifletici: para hareketi bozulmuyor, gerçek kayıt WooCommerce siparişleri ve NICEPAY merchant portalinde duruyor, yani veri kaybı değil yanlış raporlama. Bu onu `critical` yapmaz ama `medium`'a da indirmez — mutabakat ürününün birincil işlevi yanlış çalışıyor.

Öneri de teknik olarak sağlam; tek eklemem gereken: create_at'i PHP-yazımlı UTC'ye çevirmek prepare_write'ın database_owned listesinden created_at'i çıkarmayı ve mevcut satırların geriye dönük yorumunu (migration/kabul) ele almayı gerektirir — aksi halde eski satırlar bir sözleşmede, yeniler diğerinde kalır.

---

### ADMIN-002 — needs_reconciliation durumundaki kaydı panelden çözmenin hiçbir yolu yok; uyarı bandı ve kilitli iade butonu kalıcı

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | missing-workflow |
| **Konum** | [admin/class-nicepay-transactions.php:634](../../../admin/class-nicepay-transactions.php#L634) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```php
Ekran kaydı kilitliyor:

    $requires_reconciliation = 'needs_reconciliation' === (string) $item->status ||
        ( isset( $item->reconciliation_status ) && 'required' === (string) $item->reconciliation_status );
    $can_refund = $order && ! empty( $item->tid ) && ! $requires_reconciliation && ...   (:800-805)

ve ajax_cancel_transaction() aynı kapıyı sunucuda tekrar uyguluyor (:997-1003). Uyarı bandı (:634-649) 'requires manual reconciliation before retrying any payment or refund' diyor. Ancak `grep -rn "'reconciliation_status'" includes admin` sonucunda bu alanı 'required'dan çıkaran TEK yol, yeni bir başarılı akış (class-nicepay-return-handler.php:190, class-nicepay-gateway.php:604/862) — ki kilitli kayıt için böyle bir akış artık tetiklenemez. Panelde hiçbir 'çözüldü olarak işaretle' butonu, AJAX handler'ı veya admin_post eylemi yok. Ayrıca NicePay_Retention bu satırları silmekten açıkça koruyor, yani süresiz birikiyorlar.

Ayrıca operatöre gösterilen tek ipucu ham iç hata kodu:

    <br><small><?php echo esc_html( isset( $item->reconciliation_note ) ? $item->reconciliation_note : ... ); ?></small>   (:917)

yani 'nicepay_approval_binding_failed' veya 'refund_confirmed_ledger_update_failed' gibi çevrilmemiş, açıklamasız bir dize.
```

**Başarısızlık senaryosu**

Bir CARD ödemesinde onay isteği zaman aşımına uğruyor, net-cancel yanıtı da alınamıyor. nicepay_abort_authenticated_payment() satırı status='needs_reconciliation', reconciliation_status='required', reconciliation_note='nicepay_approval_timeout' yapıyor. Satıcı NICEPAY konsolunda kontrol ediyor, işlemin gerçekten iptal edildiğini görüyor. Panele dönüyor: satırda 'nicepay_approval_timeout' yazıyor, Refund butonu yok, ekranın tepesindeki kırmızı '1 transaction requires manual reconciliation' bandı kalıcı. Kaydı temizlemek için hiçbir arayüz yok; 6 ay sonra bant '43 transactions' diyor ve gerçek yeni olaylar gürültüde kayboluyor.

**Etki**

Ağ hatası ya da NICEPAY tarafındaki geçici bir sorun sonrası kilitlenen her sipariş için satıcının hiçbir kurtarma yolu yok. İade edilemez, uyarı bandı asla kapanmaz ve her yeni olay sayacı artırır. Tek çözüm doğrudan SQL çalıştırmak. Bir ödeme eklentisi için operasyonel çıkmaz.

**Öneri**

1) Yetenek + nonce korumalı bir 'Mutabakatı çözüldü olarak işaretle' eylemi ekleyin (admin_post veya wp_ajax). Eylem operatörden serbest metin bir çözüm notu istesin, reconciliation_status='not_required' yapsın, ayrı bir reconciled_by/reconciled_at/reconciliation_resolution alanına kim-ne zaman-neden yazsın ve ilgili WooCommerce siparişine sipariş notu düşsün. Para durumunu (status) DEĞİŞTİRMESİN — sadece kilidi kaldırsın.
2) reconciliation_note'u kullanıcıya gösterirken bir kod->açıklama eşlemesinden geçirin, örn:

    function nicepay_get_reconciliation_help( $code ) {
        $map = array(
            'nicepay_approval_timeout' => __( 'Onay isteği yanıtsız kaldı. NICEPAY konsolunda TID durumunu doğrulayın.', 'nicepay-payment-gateway' ),
            ...
        );
        return isset( $map[ $code ] ) ? $map[ $code ] : $code;
    }

ve yanına 'Ne yapmalıyım?' yönergesi koyun.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddiadaki her satır numarası dosyanın şu anki haliyle birebir eşleşiyor ve iddiayı çürütecek hiçbir kaçış yolu (admin eylemi, AJAX handler, cron, WP-CLI, filtre kancası) yok.

1) Kilit gerçekten çift katmanlı: UI (:800-805) ve sunucu (:997-1002). Ek olarak WooCommerce'in kendi sipariş ekranından yapılan iade de aynı kapıya takılıyor (class-nicepay-gateway.php:710-713, `'required' === $reconciliation` -> WP_Error). Yani "sipariş ekranından iade edebilir" gibi bir kaçış yolu da yok — bu, iddiayı zayıflatmak yerine güçlendiriyor.

2) `reconciliation_status`'ı 'required' dışına çıkaran TEK yazma noktaları: return-handler:125/190, gateway:519/604/862 ve nicepay-functions.php:252. Bunların hepsi ya yeni bir onay/iade akışının BAŞARILI dalı ya da kaydın ilk oluşturulması. Kilitli bir satırda onay akışı yeniden tetiklenemez (satır zaten abort edilmiş/finalize edilmiş) ve iade akışı yukarıdaki üç kapı tarafından bloke ediliyor. Dolayısıyla döngü kapalı: 'required' -> iade bloke -> 'not_required' yapacak akış tetiklenemez.

3) `grep -rn "wp_ajax_\|admin_post"` tüm eklentide yalnızca 6 handler döndürüyor; admin tarafında sadece `nicepay_cancel_transaction` ve `nicepay_export_transactions` var. "Mutabakatı çözüldü işaretle" diye bir eylem, buton veya nonce yok.

4) NicePay_Retention gerçekten bu satırları silmekten koruyor (eligible_row_predicate: `AND ledger.status <> 'needs_reconciliation' AND ledger.reconciliation_status <> 'required'`), yani süresiz birikiyorlar — banner sayacı monoton artıyor.

5) Operatöre gösterilen metin gerçekten ham iç kod: `reconciliation_note` yazılan tüm değerler ya sabit iç dizeler ('woocommerce_payment_recovery_failed', 'stale_approval_attempt', 'refund_confirmed_ledger_update_failed', 'nicepay_refund_response_mismatch') ya da `$result->get_error_code()` — hiçbiri çevrilebilir/açıklayıcı değil, :917'de doğrudan esc_html ile basılıyor.

Tek küçük genişletme (iddiayı çürütmez, kapsamı büyütür): banner sayacı nicepay_get_reconciliation_count() (:1032-1042) `cancel_status = 'unknown' OR net_cancel_status = 'unknown'` satırlarını da sayıyor; bu satırlar için de hiçbir panel çözüm yolu yok, yani kalıcı banner sorunu iddia edilenden biraz daha geniş bir küme için geçerli. Severity 'high' yerinde: para kaybı yok ama ödeme eklentisi için SQL gerektiren operasyonel çıkmaz.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: `needs_reconciliation` / `reconciliation_status='required'` eklenti içinde terminal bir durumdur: kilidi kaldıran üç yazma noktası (return-handler.php:190, gateway.php:604, gateway.php:862) yalnızca yeni bir başarılı onay veya iade akışında çalışır, ancak her iki claim sorgusu da (nicepay-functions.php:697-699 `status='pending'`, :736 `reconciliation_status <> 'required'`) kilitli satırı dışlar — dolayısıyla kilit bir kez kurulduğunda eklenti içinden ASLA kalkamaz. Panelde çözüm eylemi (AJAX/admin_post/WP-CLI/cron) yoktur, retention kuralı bu satırları silmekten korur (retention:309-311), bant (transactions:634-647) kalıcı olarak büyür ve satırdaki tek ipucu çevrilmemiş ham hata kodudur (transactions:917). Not: iade butonu "kilitli" değil, hiç render edilmez (transactions:920-928) ve operatöre nedeni açıklanmaz. Tek çözüm doğrudan SQL'dir; ancak satıcının NICEPAY konsolundan iade + WooCommerce "Refund manually" ile dışarıdan telafi yolu bulunduğundan ve fail-closed davranış kasıtlı tasarım olduğundan (docs/ARCHITECTURE.md:357), etki güvenlik/para kaybı değil operasyonel çıkmazdır.
- Gerekçe: Çekirdek iddia kod tarafından tamamen doğrulanıyor: `needs_reconciliation` / `reconciliation_status='required'` durumu eklenti içinde TERMİNAL bir durumdur; paneli, AJAX'ı, admin_post'u, cron'u veya WP-CLI komutu ile temizlemenin hiçbir yolu yok.

Doğruladığım zincir:
1) Kilidi kuran yollar: nicepay-functions.php:214-217 (binding failure), :250-253 (abort/net-cancel unknown), :971-974 (30 dk sonrası "stale_approval_attempt" cron'u), gateway.php:195-197, :517-520, :649-651, :815-817, :890-893.
2) Kilidi kaldıran TEK üç yol: return-handler.php:190, gateway.php:604 (yeni başarılı onay) ve gateway.php:862 (başarılı iade).
3) Bu üç yolun hiçbiri kilitli satır için tetiklenemez:
   - Onay claim'i `status='pending' AND approval_state='pending'` şartı arıyor (nicepay-functions.php:670-680 ve :697-699). Kilitli satırın status'ü `needs_reconciliation` olduğu için asla tekrar "approving"e giremez.
   - İade claim'i açıkça `AND reconciliation_status <> 'required'` diyor (nicepay-functions.php:736); ayrıca process_refund gate'i gateway.php:709-712'de aynı kapıyı erkenden kapatıyor.
   Yani gateway.php:862'ye ulaşmak matematiksel olarak imkânsız → kilit kalıcı.
4) Panelde çözüm eylemi yok: admin dizinindeki TÜM kayıtlı eylemler sadece `wp_ajax_nicepay_cancel_transaction` (class-nicepay-transactions.php:24) ve `admin_post_nicepay_export_transactions` (:25). "Mark reconciled" tarzı hiçbir handler yok (dosyanın sonu :1026'da `new NicePay_Transactions();`).
5) Retention satırları silmekten koruyor: class-nicepay-retention.php:309-311.
6) Operatöre gösterilen tek ipucu ham hata kodu: class-nicepay-transactions.php:916-917. api.php:421 / :438'de bu kodun `nicepay_approval_reconciliation_required` gibi ham bir dize olduğu doğrulanıyor.

DÜZELTMELER (bu yüzden "confirmed" değil "partially-confirmed"):
a) "kilitli iade butonu" ifadesi yanlış: buton disabled render edilmiyor, HİÇ render edilmiyor (:920-928 `<?php if ( $can_refund ) : ?>` bloğu). Operatöre "neden yok" açıklaması da yok — durum iddiadakinden biraz daha kötü ama teknik tarif hatalı.
b) Severity `high` abartılı. Bu bir güvenlik açığı değil, para kaybı da değil ve fail-closed tasarım kasıtlı görünüyor (docs/ARCHITECTURE.md:351,357 `needs_reconciliation --> [*] : Merchant review required`; README.md:203 "Do not retry blindly"). Satıcının dış yolları var: NICEPAY konsolundan iade + WooCommerce'in "Refund manually" (process_refund'u çağırmaz) ile muhasebe kaydı. Somut zarar = eklenti içi iade yolunun o sipariş için kalıcı kapanması, kapanmayan kırmızı bant gürültüsü, satırların hiç purge edilememesi ve tek çözümün doğrudan SQL olması. Bu operasyonel/UX ciddiyeti `medium` seviyesinde.

Üretilebilirlik (lens gereği somut adımlar): önkoşullar gerçekçi ve saldırgan gerektirmiyor. En kolay deterministik repro: `wp_nicepay_transactions` satırını `status='approving', approval_state='approving', approval_started_at = UTC_TIMESTAMP() - INTERVAL 31 MINUTE` yap, sonra pending-expiry cron'unu çalıştır (nicepay-functions.php:971-978) → satır `needs_reconciliation` + `reconciliation_status='required'` + note `stale_approval_attempt` olur. Sonrasında `manage_woocommerce` yetkili bir admin olarak /wp-admin/admin.php?page=nicepay-transactions'a git: Refund butonu yok, `stale_approval_attempt` yazıyor, bant kalıcı; wp-admin'den satırı düzeltecek hiçbir istek yok (mevcut tek POST `action=nicepay_cancel_transaction` ve o da :999'da reddediyor).

---

### ADMIN-003 — Yapılandırma uyarıları her wp-admin sayfasında kapatılamaz şekilde basılıyor; shop manager rolü ise test-modu uyarısını hiç görmüyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | admin-ux |
| **Konum** | [nicepay-payment-gateway.php:123](../../../nicepay-payment-gateway.php#L123) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
if ( is_admin() ) {
        add_filter( 'plugin_action_links_' . NICEPAY_PLUGIN_BASENAME, ... );
        add_action( 'admin_notices', array( $this, 'schema_error_notice' ) );
        add_action( 'admin_notices', array( $this, 'configuration_notice' ) );
    }   (:123-127)

Hiçbir get_current_screen() kontrolü, hiçbir is-dismissible sınıfı, hiçbir kapatma kalıcılığı yok. configuration_notice() ayrıca her admin sayfa yüklemesinde `new NicePay_API()` kuruyor ve woocommerce_nicepay_settings + nicepay_standalone_enabled option'larını okuyor (includes/nicepay-functions.php:535-546).

Ayrıca yetki kapısı asimetrik:

    public function configuration_notice() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }   (:258)

oysa işlemler ekranının yetkisi nicepay_current_user_can_manage_payments() = manage_options VEYA manage_woocommerce (includes/nicepay-functions.php:484-486).
```

**Başarısızlık senaryosu**

Bir mağaza sahibi eklentiyi kuruyor, standalone formları test modunda açık bırakıyor. Ertesi gün wp-admin'in her sayfasında sarı uyarı beliriyor; iki hafta sonra bunu görmezden gelmeyi öğreniyor. Canlıya geçince MID'i giriyor ama merchant key'i giremiyor; artık KIRMIZI 'live_credentials_missing' uyarısı beliriyor, ama aynı bantta aynı yerde durduğu için fark etmiyor ve ödeme formlarının neden hiç görünmediğini anlamıyor. Bu arada mağazayı fiilen yöneten shop_manager kullanıcısı hiçbir uyarı görmüyor.

**Etki**

Test modu açıkken (yani her geliştirme kurulumunda ve canlıya geçmeyi unutmuş her sitede) satıcı, panelde nereye giderse gitsin — yazılar, medya, eklentiler, başka eklentilerin ekranları — kapatılamayan sarı bir uyarı görüyor. Bu WordPress.org eklenti yönergelerine aykırı ve uyarı körlüğü yaratarak GERÇEK hataların (live_credentials_missing) fark edilmemesine yol açıyor. Ters yönde: NicePay panelini kullanan shop_manager rolü 'test modunda gerçek para toplanmıyor' uyarısını hiç görmüyor.

**Öneri**

Uyarıları ilgili ekranlara daraltın ve kapatılabilir yapın:

    add_action( 'admin_notices', function () {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $allowed = array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings' );
        if ( ! $screen || ( false === strpos( $screen->id, 'nicepay' ) && ! in_array( $screen->id, $allowed, true ) ) ) { return; }
        ...
    } );

'error' tipini her yerde göstermeye devam edin ama 'warning' tipini is-dismissible yapıp kapatmayı kullanıcı meta'sında (uyarı koduna göre) kalıcılaştırın. Yetki kapısını nicepay_current_user_can_manage_payments() ile değiştirin ki shop manager da test-modu uyarısını görsün.

---

### ADMIN-004 — CSV export 10.000 satırda sessizce kesiliyor; dosyada hiçbir kesilme işareti yok ve devam etme yolu sunulmuyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | data-integrity |
| **Konum** | [admin/class-nicepay-transactions.php:263](../../../admin/class-nicepay-transactions.php#L263) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
const CSV_MAX_ROWS   = 10000;                                  (:13)
    while ( $scanned < $max_rows ) { ... }                          (:157)
    header( 'X-NicePay-Export-Row-Limit: ' . self::CSV_MAX_ROWS );  (:263)
    self::write_csv_export( $stream, $filter_state['filters'] );    (:270)
    fclose( $stream );
    exit;

write_csv_export() kaç satır yazdığını döndürüyor ($exported, :217) ama handle_csv_export() bu değeri tamamen yok sayıyor. Kesilme sinyali yalnızca bir HTTP başlığında — hiçbir tarayıcı bunu kullanıcıya göstermez. İndirilen dosyanın içinde uyarı satırı yok, sondaki satırın tarihi de yok. Ekranda tek ipucu filtre formunun yanındaki statik metin (:692-700): 'Exports up to 10,000 matching rows.' — bu bile export'un GERÇEKTEN kesilip kesilmediğini söylemiyor.

Ayrıca ekranda toplam sayı zaten biliniyor ($total, :561) ama export butonu bu sayıyla karşılaştırılıp uyarı verilmiyor.
```

**Başarısızlık senaryosu**

Günde ~600 işlem alan bir mağaza yıl sonu için filtresiz export alıyor. Tablo 180.000 satır içeriyor; dosya en yeni 10.000 satırı (yaklaşık son 17 gün) içeriyor ve hiçbir uyarı yok. Satıcı bunu muhasebecisine gönderiyor. Aynı satıcı retention'ı 365 güne ayarlayıp onaylıyor — 'export aldım' diye. Ertesi gün cron eski kayıtları kalıcı olarak siliyor ve export'ta hiç bulunmayan 170.000 işlem geri dönülemez şekilde kayboluyor.

**Etki**

Orta ölçekli bir mağazada aylık export sessizce eksik çıkıyor. CSV geçerli görünüyor, başlıkları var, satırları var — ama muhasebe/vergi mutabakatı eksik veriyle yapılıyor ve fark ancak toplamlar tutmadığında (ya da hiç) ortaya çıkıyor. Bu, saklama ayarı ekranının 'silmeyi açmadan önce filtrelenmiş CSV'yi export edin' tavsiyesiyle (admin/class-nicepay-admin.php:551) birleştiğinde veri kaybına dönüşebilir.

**Öneri**

1) Kesilmeyi dosyanın İÇİNDE bildirin: write_csv_export() limite ulaştıysa son satır olarak `# TRUNCATED at 10000 rows — narrow the date filter and export again from <son created_at>` yazın.
2) Butonun yanında gerçek sayıyı gösterin ve aşıldığında uyarın:

    <?php if ( $total > self::CSV_MAX_ROWS ) : ?>
      <span class="notice notice-warning inline"><?php printf( esc_html__( 'Bu filtre %1$s satır eşleştiriyor; export yalnızca en yeni %2$s satırı içerecek. Tarih aralığını daraltın.', 'nicepay-payment-gateway' ), number_format_i18n( $total ), number_format_i18n( self::CSV_MAX_ROWS ) ); ?></span>
    <?php endif; ?>

3) Uzun vadede cursor'u (son created_at + id) bir sonraki export bağlantısına taşıyarak sayfalı export sunun veya arka planda Action Scheduler ile tam export üretip e-posta ile gönderin.

---

### ADMIN-005 — Tek tıklık iade kontrolü: terminoloji 'Cancel' ile 'Refund' arasında gidip geliyor, onay kutusunda tutar yok ve yalnızca tam kalan bakiye iade edilebiliyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | admin-ux |
| **Konum** | [admin/class-nicepay-transactions.php:924](../../../admin/class-nicepay-transactions.php#L924) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Aynı kontrol dört farklı isimle anılıyor:

    <button ... class="button button-small nicepay-cancel-btn"
            aria-label="<?php echo esc_attr( $cancel_transaction_label ); ?>">   // 'Cancel transaction %s' (:816-822)
        <?php esc_html_e( 'Refund', ... ); ?>                                     // buton metni (:929)

Modal metinleri (admin/class-nicepay-admin.php:81-87): cancelTitle='Cancel Transaction', cancelMessage='This action cannot be undone. The payment will be reversed.', cancelConfirm='Cancel Transaction'. AJAX eylem adı 'nicepay_cancel_transaction'. Başarıda satır 'REFUNDED' yapılıyor (assets/js/nicepay-admin.js:277).

Onay diyaloğunda ne tutar, ne sipariş numarası, ne TID var (nicepay-admin.js:249-254) — yalnızca genel bir cümle. Ve sunucu her zaman kalanın TAMAMINI iade ediyor:

    $remaining = nicepay_normalize_ledger_amount( ... );
    $amount = (float) $remaining;                                 (:1005-1011)

Kısmi iade process_refund() tarafından destekleniyor (CARD + cc_part_cl) ama bu ekrandan erişilemiyor.
```

**Başarısızlık senaryosu**

Operatör 'partially_refunded' durumundaki 500.000 KRW'lik bir siparişi görüyor; müşteri 50.000 KRW'lik bir kalem daha iade istiyor. Satırdaki 'Refund' butonuna basıyor. Modal 'Cancel Transaction — This action cannot be undone' diyor; operatör bunu 'siparişi iptal et' sanıp veya kısmi tutar girebileceğini varsayıp onaylıyor. Sunucu kalan 450.000 KRW'nin TAMAMINI NICEPAY'e iade ediyor. Geri alınamaz.

**Etki**

WooCommerce'te 'cancel' (siparişi iptal) ile 'refund' (para iadesi) farklı şeylerdir; ikisini karıştırmak operatörün geri dönüşü olmayan bir para hareketini yanlışlıkla tetiklemesine yol açabilir. Onayda tutar gösterilmediği için operatör hangi siparişin ne kadarının iade edileceğini teyit edemiyor — özellikle listede birden çok satır varken. Kısmi iade seçeneğinin olmaması, en yaygın gerçek senaryoyu (tek kalemin iadesi) bu ekranın dışına itiyor.

**Öneri**

1) Terminolojiyi tek bir kelimede sabitleyin (bu ekranda yapılan iş iadedir): buton, aria-label, modal başlığı, onay butonu ve i18n anahtarları hep 'Refund'. AJAX eylem adını da nicepay_refund_transaction yapıp eskisini geriye dönük tutun.
2) Tutarı ve hedefi onayda gösterin — sunucudan gelen veriyi kullanın:

    data-remaining="<?php echo esc_attr( nicepay_format_amount( $remaining, $currency ) ); ?>"
    data-order="<?php echo esc_attr( $order_number ); ?>"

    message: sprintf( i18n.refundConfirmMessage, btn.data('remaining'), btn.data('order') )
    // '#1042 siparişi için 450.000 KRW iade edilecek. Bu işlem geri alınamaz.'

3) Modala isteğe bağlı bir tutar alanı ekleyin (varsayılan: kalan bakiye), sunucuda kalanı aşmadığını ve kısmi iadenin bu yöntem için izinli olduğunu (CELLPHONE yasak, CARD cc_part_cl) doğrulayın; izinli değilse alanı readonly gösterip nedenini yazın.

---

### ADMIN-006 — Yönetici para ve veri işlemleri için denetim izi yok: iadeyi kim başlattı, CSV'yi kim indirdi, reddedilen deneme oldu mu kayıt edilmiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | auditability |
| **Konum** | [admin/class-nicepay-transactions.php:969](../../../admin/class-nicepay-transactions.php#L969) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
ajax_cancel_transaction() (:969-1027) baştan sona tek bir nicepay_log() çağrısı içermiyor — ne başarıda, ne 'Unauthorized' dalında (:974-977), ne 'not in a safe state' dalında (:997-1003). handle_csv_export() (:241-273) de finansal veri indirilirken hiçbir kayıt tutmuyor.

refund_columns() (includes/class-nicepay-transaction-schema.php:145-163) şu alanları içeriyor: transaction_id, wc_order_id, tid, cancel_moid, requested_amount, currency, reason, status, result_code, result_msg, response_data, requested_at, completed_at, created_at. HİÇBİR kullanıcı/aktör alanı yok. Panelde gösterilen iade denemesi listesi de (:894-906) yalnızca durum/tutar/kod gösteriyor.

Ayrıca 'reason' istemci tarafında zorunlu (assets/js/nicepay-admin.js:256-259) ama sunucuda değil:

    $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';   (:971)

boş reason ile doğrudan POST atılırsa iade boş gerekçeyle geçiyor.
```

**Başarısızlık senaryosu**

İki shop_manager var. Bir müşteri 300.000 KRW iade aldığını, oysa hiç talep etmediğini bildiriyor. Mağaza sahibi NicePay > Transactions ekranına giriyor: satır 'refunded', iade denemesi 'confirmed — 300.000 KRW — code 2001' diyor. Kimin yaptığı, hangi gerekçeyle yaptığı hiçbir yerde yazmıyor. Sunucu error log'unda da hiçbir NicePay girdisi yok (bu yol log çağırmıyor). Olay çözülemiyor.

**Etki**

Birden fazla yöneticinin olduğu bir mağazada 'bu iadeyi kim yaptı, neden?' sorusunun eklenti içinde cevabı yok. WooCommerce akışında wc_create_refund refunded_by'ı kaydediyor, ama standalone akışta ve NicePay defterinde hiçbir iz yok. Reddedilen yetkisiz denemeler hiç loglanmadığı için güvenlik olayı incelemesi de imkânsız. Finansal CSV'nin ne zaman/kim tarafından indirildiği de bilinmiyor — KVKK/PIPA türü veri erişim denetimlerinde sorun.

**Öneri**

1) reason'ı sunucuda zorunlu kılın ve uzunluğunu şemayla (varchar 100) hizalayın:

    if ( '' === $reason ) {
        wp_send_json_error( array( 'message' => __( 'İade gerekçesi zorunludur.', 'nicepay-payment-gateway' ) ), 400 );
        return;
    }

2) refund_attempts tablosuna initiated_by (bigint UNSIGNED) ve initiated_via (varchar 20: 'admin_screen'|'wc_order'|'api') ekleyip nicepay_save_refund_attempt() içinde get_current_user_id() ile doldurun; iade denemesi listesinde kullanıcı adını gösterin.
3) Her yönetici para/veri eylemini loglayın — başarılı, reddedilen ve yetkisiz olanları:

    nicepay_log( 'Admin refund requested', array( 'transaction_id' => $id, 'user_id' => get_current_user_id() ), 'info' );
    nicepay_log( 'Transaction CSV exported', array( 'user_id' => get_current_user_id(), 'filters' => $filter_state['filters'], 'rows' => $exported ), 'info' );

(nicepay_redact_log_data() zaten PII'yi temizliyor.)

---

### ADMIN-007 — Ayar alanları sessizce kırpılıyor/zorlanıyor, kullanıcıya hiçbir geri bildirim yok; merchant key hiçbir şekilde doğrulanamıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | admin-ux |
| **Konum** | [admin/class-nicepay-admin.php:171](../../../admin/class-nicepay-admin.php#L171) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Sanitize geri çağrılarının hiçbiri add_settings_error() kullanmıyor; hepsi sessizce düzeltiyor:

    public function sanitize_mid( $value ) {
        $value = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
        return substr( $value, 0, 20 );          // sessiz kırpma + karakter silme (:171-174)
    }
    public function sanitize_mode( $value ) {
        return in_array( $value, array( 'test','live' ), true ) ? $value : 'test';   // sessiz 'test'e düşürme (:159-161)
    }
    public function sanitize_expiry_days( $value ) {
        return max( 1, min( 30, absint( $value ) ) );   // sessiz kelepçeleme (:213-215)
    }
    public function sanitize_enabled_methods( $value ) { ... array_intersect( $allowed, $value ) ... }   // sessiz eleme (:198-207)

Karşılaştırma olarak NicePay_Retention::sanitize_settings() DOĞRU yapıyor ve add_settings_error() çağırıyor (includes/class-nicepay-retention.php:82-90) — yani kalıp ekipte zaten mevcut, sadece bu alanlara uygulanmamış.

Merchant key için hiçbir format doğrulaması yok, sadece 512 bayta kırpma (:190-195). Ekranda 'bağlantıyı test et' butonu yok; grep ile ne bir test uç noktası ne de bir doğrulama akışı bulunuyor. Ayrıca sanitize_merchant_key() sanitize_option_* filtresinde çalıştığı için, kod/WP-CLI ile update_option('nicepay_live_merchant_key','') çağrısı $_POST olmadığından sessizce yok sayılıyor (:190-193).
```

**Başarısızlık senaryosu**

Satıcı NICEPAY'den gelen e-postadan canlı MID'i kopyalıyor: 'nicepay00m ' (sondaki boşlukla) ve merchant key yerine yanlışlıkla API şifresini yapıştırıyor. sanitize_mid boşluğu siliyor (bu tesadüfen doğru), key ise olduğu gibi kaydediliyor. Readiness paneli 'Active credentials: Ready' diyor, hazırlık kontrollerinin hepsi yeşil. Satıcı canlıya geçiyor. İlk müşteri ödeme penceresini açıyor, imza doğrulaması NICEPAY'de başarısız oluyor ve müşteri anlaşılmaz bir hata görüyor. Panelde hiçbir tanı ipucu yok.

**Etki**

Satıcı canlı MID'i panele yapıştırdığında bir boşluk veya nokta varsa karakterler sessizce siliniyor; 20 karakterden uzunsa sessizce kesiliyor. Ekran 'Settings saved' diyor ve MID alanı görünüşte doğru. Hata ancak ilk gerçek ödeme denemesinde, müşteri tarafında imza uyuşmazlığı olarak ortaya çıkıyor. Aynı şekilde yanlış modun anahtarı girildiğinde de hiçbir uyarı yok — readiness paneli sadece anahtarın BOŞ OLMADIĞINI kontrol ediyor (:436).

**Öneri**

1) Her sanitize geri çağrısında girdi düzeltildiyse kullanıcıyı bilgilendirin:

    public function sanitize_mid( $value ) {
        $raw   = trim( (string) $value );
        $clean = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $raw ), 0, 20 );
        if ( $clean !== $raw ) {
            add_settings_error( 'nicepay_api', 'nicepay_mid_adjusted',
                __( 'MID yalnızca harf, rakam, _ ve - içerebilir ve en fazla 20 karakterdir. Girdiğiniz değer düzeltildi; NICEPAY sözleşmenizdeki değerle karşılaştırın.', 'nicepay-payment-gateway' ), 'warning' );
        }
        return $clean;
    }

Aynısını sanitize_mode, sanitize_currency, sanitize_expiry_days ve sanitize_enabled_methods için uygulayın (settings_errors() zaten :362'de çağrılıyor).
2) API Credentials sekmesine nonce korumalı bir 'Bağlantıyı doğrula' butonu ekleyin: aktif MID/key ile bilinen bir hata döndürecek küçük bir istek (veya vendor'ın sağladığı doğrulama uç noktası) atıp, dönen hata kodunun 'imza geçersiz' mi yoksa 'MID bilinmiyor' mu olduğunu ayırt ederek sonucu gösterin. Kimlik bilgisini asla yanıta yansıtmayın.
3) Merchant key alanına en azından beklenen uzunluk/alfabe kontrolü koyun ve saklanan anahtarın son 4 karakterini veya sha256 parmak izinin ilk 8 hane'sini gösterin ki satıcı 'doğru anahtar mı' sorusunu cevaplayabilsin.

---

### ADMIN-008 — Her işlem sayfası yüklemesinde iki indekssiz tam tablo taraması ve sayfa başına 20 adet wc_get_order() çağrısı

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | performance |
| **Konum** | [includes/nicepay-functions.php:1036](../../../includes/nicepay-functions.php#L1036) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Mutabakat sayacı, dört sütun üzerinde OR ile tarama yapıyor:

    $sql = "SELECT COUNT(*) FROM {$table}
              WHERE status = 'needs_reconciliation'
                 OR reconciliation_status = 'required'
                 OR cancel_status = 'unknown'
                 OR net_cancel_status = 'unknown";   (includes/nicepay-functions.php:1036-1040)

indexes() (includes/class-nicepay-transaction-schema.php:122-138) yalnızca idx_status_created ve idx_reconciliation_status içeriyor; cancel_status ve net_cancel_status için indeks YOK. MySQL index_merge union tüm dallar indeksliyse kullanılabilir; iki dal indekssiz olduğu için sorgu tam tablo taramasına düşüyor.

İkinci tarama: get_financial_summary() (:96-117) filtresiz durumda tüm tabloyu SUM(captured_amount)/SUM(refunded_amount)/SUM(remaining_amount) ile currency'ye göre grupluyor — LIMIT yok. Her ikisi de render()'ın başında koşulsuz çağrılıyor (:563-566).

Üçüncüsü, satır başına N+1:

    $order = ! empty( $item->wc_order_id ) && function_exists( 'wc_get_order' )
        ? wc_get_order( $item->wc_order_id )
        : null;   (:780-782)

yalnızca sipariş numarası ve düzenleme bağlantısı için 20 tam WC_Order nesnesi (HPOS'ta 20 sorgu + meta yüklemesi) kuruluyor.
```

**Başarısızlık senaryosu**

Günde 2.000 deneme alan bir mağazada tablo bir yılda ~700.000 satıra ulaşıyor. Ödeme sağlayıcısında bir kesinti oluyor ve satıcı İşlemler ekranını art arda yeniliyor. Her yenilemede iki tam tablo taraması (COUNT + GROUP BY SUM) ve 20 sipariş yüklemesi tetikleniyor; sayfa 5-10 saniyede açılıyor ve DB zaten kesinti nedeniyle artan yazma yüküyle boğuşuyor.

**Etki**

Tablo büyüdükçe (bu tablo hem başarısız hem terk edilmiş denemeleri de tuttuğu için hızlı büyür) İşlemler ekranı yavaşlıyor; birkaç yüz bin satırda sayfa başına saniyeler mertebesinde sorgu süresi ve gereksiz DB yükü oluşuyor. Bu ekran mağazanın ödeme sorunlarını incelediği yer olduğu için, yükün en yüksek olduğu anda en yavaş çalışıyor.

**Öneri**

1) cancel_status ve net_cancel_status için indeks ekleyin (index_merge'i mümkün kılar) veya daha iyisi, tek bir türetilmiş `needs_review tinyint(1)` sütunu tutup onu indeksleyin. Şema sürümünü artırmayı unutmayın.
2) Mutabakat sayacını kısa süreli önbelleğe alın:

    $count = get_transient( 'nicepay_reconciliation_count' );
    if ( false === $count ) {
        $count = ...; set_transient( 'nicepay_reconciliation_count', $count, 5 * MINUTE_IN_SECONDS );
    }

ve mutabakat durumunu yazan her yerde delete_transient() çağırın.
3) Finansal özeti yalnızca en az bir filtre uygulandığında hesaplayın; filtresiz durumda 'Toplamları görmek için bir tarih aralığı seçin' bağlantısı gösterin.
4) N+1'i kaldırın: sipariş numarası ve düzenleme URL'si için tek seferde toplu yükleme yapın (wc_get_orders( array( 'post__in' => $ids, 'limit' => -1 ) )) ya da yalnızca id gösterip düzenleme URL'sini admin_url ile elle kurun — wc_get_order() bu ekranda sadece iki alan için kullanılıyor.

---

### ADMIN-009 — Sistem raporu 'Kopyala' butonunun yedek yolu tek satırlık input kullanıyor; çok satırlı rapor tek satıra çöküyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | correctness |
| **Konum** | [assets/js/nicepay-admin.js:367](../../../assets/js/nicepay-admin.js#L367) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
Genel kopyalama işleyicisi (:354-378) navigator.clipboard yoksa şu yedeğe düşüyor:

    const temp = $(element('input')).val(text).appendTo('body').select();
    var ok = document.execCommand('copy');

Oysa kopyalanan içerik format_system_report()'un ürettiği ÇOK SATIRLI metin (admin/class-nicepay-admin.php:315-325, satırlar "\n" ile birleştiriliyor) ve buton bunu data-copy içinde taşıyor (:622-626). HTMLInputElement'in değer sanitizasyon algoritması type=text için CR/LF karakterlerini kaldırır; yani panoya 20 satır yerine tek bir uzun satır gider. Karşılaştırma olarak kısayol kartı kopyalama işleyicisi doğru elemanı kullanıyor: `$(element('textarea')).val(text)` (:308).

Ayrıca navigator.clipboard yalnızca secure context'te (HTTPS/localhost) tanımlıdır — HTTP üzerinden çalışan bir wp-admin'de her zaman bu bozuk yedek yola girilir. Kısayol üreticisindeki kopyalama butonunun ise hiç yedeği yok, doğrudan 'Copy failed' gösteriyor (assets/js/nicepay-shortcode-admin.js:246-249).
```

**Başarısızlık senaryosu**

Satıcı sitesini http://staging.example.com/wp-admin üzerinden yönetiyor. NicePay > Settings > System Report sekmesine gidip 'Copy system report' butonuna basıyor; yeşil 'Copied to clipboard!' toast'ı çıkıyor. Destek e-postasına yapıştırdığında şunu görüyor: 'NicePay System Reportnicepay_plugin_version: 2.0.0wordpress_version: 6.7woocommerce_version: 9.4...' — alan ayrımı olmadan tek satır. Destek ekibi ayrıştıramıyor.

**Etki**

Destek talebi açan satıcı, sistem raporunu HTTP üzerinden çalışan bir panelde kopyaladığında okunamaz tek satırlık bir yığın gönderiyor. Bu, raporun tek amacını (destek ekibine düzgün tanı verisi ulaştırmak) boşa çıkarıyor. Aynı işleyici TID kopyalamada da kullanılıyor (tek satır olduğu için orada sorun çıkmıyor), yani hata yalnızca en önemli kullanımda görünür oluyor.

**Öneri**

Genel kopyalama yedeğinde textarea kullanın ve seçim/temizlik yolunu düzeltin:

    const temp = $(element('textarea'))
        .val(text)
        .css({ position: 'fixed', top: '-1000px', opacity: 0 })
        .appendTo('body');
    temp[0].select();
    var ok = document.execCommand('copy');
    temp.remove();

Ayrıca sistem raporu için gerçekten güvenilir bir yol sunun: metin zaten görünür bir readonly textarea içinde (#nicepay-system-report, :620), butonu doğrudan o elemanı seçip kopyalayacak şekilde bağlayın (aria-controls zaten işaret ediyor) — böylece data-copy niteliğine hiç ihtiyaç kalmaz. Kısayol üreticisindeki #sc-copy-btn için de aynı yedeği ekleyin.

---

### ADMIN-010 — Sistem raporu WooCommerce Status Report ve Site Health ile entegre değil ve destek için kritik birkaç alanı içermiyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | supportability |
| **Konum** | [admin/class-nicepay-admin.php:280](../../../admin/class-nicepay-admin.php#L280) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
`grep -rn "system_status|site_health|debug_information" includes admin nicepay-payment-gateway.php` hiçbir sonuç döndürmüyor. Yani ne woocommerce_system_status_report ne de WordPress'in debug_information filtresi bağlanmış. Rapor yalnızca NicePay > Settings > System Report sekmesinde, elle kopyalanarak erişilebilir (:612-630).

Raporun içeriği (:280-300) 19 alan içeriyor; eksikler: sunucu ve site zaman dilimi (ADMIN-001 göz önüne alındığında en kritik tanı verisi), PHP openssl/curl/mbstring eklentileri (imza ve HTTP için gerekli), aktif tema, WP_DEBUG durumu, çok siteli olup olmadığı, tablo satır sayıları, mutabakat bekleyen kayıt sayısı, ve son cron çalışma zamanı (retention için LAST_RUN_OPTION zaten okunuyor ama rapora girmiyor).
```

**Başarısızlık senaryosu**

Satıcı 'CSV'deki tarihler yanlış' diye destek talebi açıyor. Destek WooCommerce sistem raporunu istiyor; rapor geliyor ama içinde NicePay bölümü yok. Destek eklenti içi raporu istiyor; gelen raporda ne site zaman dilimi ne MySQL zaman dilimi var. Sorunun kök nedeni (DB sunucusunun time_zone ayarı) hiçbir turda görünmüyor ve talep haftalarca gidip geliyor.

**Etki**

Destek akışının standart ilk adımı 'WooCommerce > Status > Get system report' çıktısını istemektir. Bu çıktıda NicePay hakkında tek satır bilgi yer almıyor, dolayısıyla destek ekibi satıcıdan ayrıca eklenti içi rapora gitmesini istemek zorunda kalıyor — ve ADMIN-009 nedeniyle o rapor da bozuk gelebiliyor. Zaman dilimi ve PHP eklenti bilgisi olmadığı için ADMIN-001 türü sorunların uzaktan teşhisi neredeyse imkânsız.

**Öneri**

1) Mevcut allowlist'i doğrudan WooCommerce raporuna bağlayın (ek kod neredeyse sıfır):

    add_action( 'woocommerce_system_status_report', function () {
        $rows = NicePay_Admin::get_system_report_data();
        echo '<table class="wc_status_table widefat" cellspacing="0"><thead><tr><th colspan="3" data-export-label="NicePay"><h2>NicePay</h2></th></tr></thead><tbody>';
        foreach ( $rows as $key => $value ) {
            printf( '<tr><td data-export-label="%1$s">%1$s</td><td>&nbsp;</td><td>%2$s</td></tr>', esc_html( $key ), esc_html( $value ) );
        }
        echo '</tbody></table>';
    } );

Aynı diziyi debug_information filtresiyle Site Health > Info'ya da ekleyin.
2) Rapora şu alanları ekleyin: site_timezone (wp_timezone_string()), db_timezone (SELECT @@session.time_zone), php_extensions (openssl/curl/mbstring var/yok), wp_debug, multisite, transaction_row_count, reconciliation_pending, retention_last_run. Hepsi sanitize_report_value()'dan geçtiği için sızıntı riski yok.

---

### ADMIN-011 — İşlem listesi elle yazılmış: sıralanabilir sütun yok, per-page seçeneği yok, istenen tutar hiç gösterilmiyor ve aralık dışı sayfa 'kayıt yok' gibi görünüyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | admin-ux |
| **Konum** | [admin/class-nicepay-transactions.php:749](../../../admin/class-nicepay-transactions.php#L749) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Tablo `wp-list-table widefat fixed striped` sınıflarını taklit ediyor ama WP_List_Table kullanmıyor (:749-946). Sonuçları:

- Sıralama: nicepay_get_transactions() bir allowlist ile orderby destekliyor (includes/nicepay-functions.php:1233-1234: created_at, amount, status, payment_method) ama render() bunu ne istekten okuyor ne de sütun başlıklarını tıklanabilir yapıyor (:752-762). Yani özellik yazılmış ama erişilemez.
- Sayfa boyutu: `$per_page = 20;` sabit (:544), Screen Options yok.
- Sayfalama yalnızca altta (:948-964); üstte tablonav yok.
- $current_page hiçbir yerde $total_pages'e kelepçelenmiyor (:545-547, :562). paged=999 ile gelindiğinde tablo boş kalıyor ve 'No transactions found — Transactions will appear here once payments are made.' boş-durum kutusu çıkıyor (:765-776) — oysa kayıt VAR, sadece sayfa aralık dışı.
- İstenen tutar (`amount`) sorguda seçiliyor (includes/nicepay-functions.php:1249) ama Balance hücresinde yalnızca captured/refunded/remaining gösteriliyor (:868-879). captured_amount şemada DEFAULT 0 olduğu için pending/failed/abandoned satırlarında Balance sütunu '0 KRW' yazıyor.
- Filtre geçersizse gösterilen mesaj hangi alanın hatalı olduğunu söylemiyor: 'One or more transaction filters are invalid.' (:653) — parse_filters() hangi alanın bozulduğunu biliyor ama bu bilgi atılıyor (:36, :51, :60, :70, :76).
```

**Başarısızlık senaryosu**

Satıcı 'dün büyük bir sipariş ödeme ekranında kaldı' diye araştırıyor. filter_status=abandoned seçiyor, 40 satır dönüyor. Balance sütununun tamamı '0 KRW' gösteriyor, sıralama yok, sayfa başına 20 satır. Hangi denemenin büyük tutarlı olduğunu bulmak için satırları tek tek Details'e tıklamak zorunda — ve Details ekranı da tutarı hiç göstermiyor (get_safe_detail_fields yalnızca result_code/result_msg/mode/instrument döndürüyor, :360-402). Bilgi ekranda hiçbir yerden erişilemiyor.

**Etki**

Operasyon ekranı en temel envanter işlerini yapamıyor: 'en büyük tutarlı başarısız denemeleri göster', 'sayfada 100 satır göster', 'tutara göre sırala'. Terk edilmiş bir 5.000 KRW denemesi ile 5.000.000 KRW denemesi listede ayırt edilemiyor çünkü ikisi de '0 KRW' gösteriyor. Aralık dışı sayfada yanıltıcı boş-durum metni, satıcıya verilerin silindiğini düşündürebilir.

**Öneri**

1) Ekranı WP_List_Table'a taşıyın: get_columns/get_sortable_columns/prepare_items ile sıralama, Screen Options ile per-page, üst+alt tablonav ve toplu eylemler ücretsiz gelir; nicepay_get_transactions() zaten uyumlu bir arayüz sunuyor. Kısa vadede en azından orderby/order'ı istekten okuyup allowlist'e karşı doğrulayın ve başlıkları <a> yapın.
2) $current_page'i kelepçeleyin ve aralık dışı durumu ayırt edin:

    if ( $total > 0 && $current_page > $total_pages ) {
        // 'Bu sayfa mevcut değil' notice'ı + son sayfaya bağlantı
    }

3) Balance hücresine istenen tutarı ekleyin (özellikle henüz yakalanmamış satırlar için):

    <?php if ( '0' === nicepay_normalize_ledger_amount( $captured ) ) : ?>
        <strong><?php echo esc_html( nicepay_format_amount( $item->amount, $currency ) ); ?></strong>
        <small><?php esc_html_e( 'İstenen (henüz yakalanmadı)', 'nicepay-payment-gateway' ); ?></small>
    <?php endif; ?>

ve tutarı get_safe_detail_fields()'a da ekleyin (finansal alan, PII değil).
4) parse_filters()'ın hangi alanın geçersiz olduğunu döndürmesini sağlayıp mesajı alan adıyla yazın: 'Bitiş tarihi başlangıç tarihinden önce olamaz.' gibi.

---

### ADMIN-012 — İade başarılı olduğunda satırın bakiye rakamları ve finansal toplamlar bayat kalıyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | admin-ux |
| **Konum** | [assets/js/nicepay-admin.js:274](../../../assets/js/nicepay-admin.js#L274) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```javascript
if ( response && response.success ) {
        modal.close();
        NicePayToast.show( response.data.message, 'success' );
        btn.closest('tr').find('.nicepay-status')
            .removeClass('nicepay-status-paid nicepay-status-partially_refunded')
            .addClass('nicepay-status-refunded')
            .text( adminI18n.statusRefunded || 'REFUNDED' );
        btn.remove();
    }   (:271-278)

Yalnızca durum rozeti ve buton güncelleniyor. Aynı satırdaki Balance hücresi (admin/class-nicepay-transactions.php:868-879) hâlâ eski 'Refunded: 0 KRW · Remaining: 450.000 KRW' değerlerini gösteriyor; sayfanın üstündeki 'Captured/Refunded/Remaining total' özet tablosu (:716-747) ve iade denemesi <details> listesi de hiç yenilenmiyor. Ayrıca `response.data.message` savunmasız — data yoksa TypeError fırlatıp modal'ı açık ve yükleme durumunda bırakır.
```

**Başarısızlık senaryosu**

Operatör 450.000 KRW'lik kalan bakiyeyi iade ediyor. Toast 'Refund completed successfully' diyor, rozet REFUNDED oluyor, ama hemen yanında 'Refunded: 0 KRW · Remaining: 450.000 KRW' yazıyor ve üstteki 'Refunded total' hâlâ 0. Operatör iadenin defterle senkronize olmadığını düşünüp destek talebi açıyor veya WooCommerce sipariş ekranından ikinci bir iade denemesi başlatıyor.

**Etki**

İade sonrası ekran kendisiyle çelişiyor: durum 'REFUNDED' ama kalan bakiye hâlâ tam tutarı gösteriyor ve sayfa üstündeki toplamlar değişmiyor. Operatör iadenin gerçekten geçip geçmediğinden emin olamıyor ve genellikle ikinci kez denemeye ya da sayfayı yenilemeye yöneliyor.

**Öneri**

Sunucudan güncel rakamları döndürün ve satırı gerçek verilerle yenileyin:

    wp_send_json_success( array(
        'message'   => __( 'Refund completed successfully.', 'nicepay-payment-gateway' ),
        'status'    => $updated->status,
        'statusLabel' => nicepay_get_status_label( $updated->status ),
        'refunded'  => nicepay_format_amount( $updated->refunded_amount, $currency ),
        'remaining' => nicepay_format_amount( $updated->remaining_amount, $currency ),
    ) );

JS tarafında rozeti response.data.statusLabel ile, Balance hücresini refunded/remaining ile güncelleyin. Yanıt erişimlerini savunmalı yapın (`response.data && response.data.message`, ki hata dalında zaten öyle yapılıyor, :280). Toplam özet tablosu için ya sayfayı yenileyin ya da 'Toplamlar güncel değil — yenileyin' uyarısı gösterin.

---

### ADMIN-013 — Test/canlı mod geçişi uçuşta olan tüm bekleyen ödemeleri sessizce geçersiz kılıyor; ayarlar ekranı hiçbir uyarı vermiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | admin-ux |
| **Konum** | [admin/class-nicepay-admin.php:480](../../../admin/class-nicepay-admin.php#L480) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Mod seçici hiçbir uyarı içermiyor; tek açıklama 'Use Test mode for development. Switch to Live for production.' (:490-492). Oysa gelen doğrulayıcı her dönüşte AKTİF MID'i talep ediyor:

    $stored_mid = self::transaction_value( $transaction, 'mid' );
    if ( '' === $stored_mid ||
        ! hash_equals( $stored_mid, $payload['MID'] ) ||
        ! hash_equals( $stored_mid, $api_mid ) ) {
        return self::error( 'nicepay_inbound_mid_mismatch', ... );   (includes/class-nicepay-inbound-validator.php:85-90; aynısı onay yanıtı için :145-149)

Yani mod değiştiği anda MID değişiyor ve eski mod altında başlatılmış her `pending` satırın dönüşü nicepay_inbound_mid_mismatch ile reddediliyor. API sekmesindeki uyarı da yalnızca genel bir 'You are currently in Live mode' bilgisi (:638-646); bekleyen işlem sayısı ne gösteriliyor ne kontrol ediliyor (`grep -n "pending" admin/class-nicepay-admin.php` yalnızca cron adı eşleşmeleri döndürüyor).
```

**Başarısızlık senaryosu**

Satıcı öğle saatinde, sekiz müşterinin NICEPAY ödeme penceresi açıkken modu test'ten live'a alıyor. Sekizi de kartını onaylıyor; dönüşte MID uyuşmazlığı nedeniyle onay reddediliyor, siparişler on-hold'a düşüyor veya başarısız oluyor. Satıcı panelde sekiz başarısız işlem görüyor, mod değişikliğiyle ilişkilendiremiyor ve NICEPAY'e 'canlı MID'imiz çalışmıyor' diye başvuruyor.

**Etki**

Davranış güvenli tarafa düşüyor (ödeme onaylanmıyor, gerekirse net-cancel yapılıyor) ama satıcıya hiçbir uyarı verilmiyor. Yoğun bir mağazada canlıya geçiş anında ödeme penceresi açık olan her müşteri anlaşılmaz bir hatayla karşılaşıyor ve satıcı bunun kendi ayar değişikliğinden kaynaklandığını bilmiyor.

**Öneri**

Mod alanına, bekleyen deneme sayısını gerçek zamanlı gösteren bir uyarı ekleyin ve kaydetmeden önce onay isteyin:

    $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','approving')" );
    if ( $pending > 0 ) : ?>
      <div class="notice notice-warning inline"><p><?php printf(
        esc_html__( 'Şu anda %d ödeme denemesi devam ediyor. Modu değiştirmek bunların hepsinin onayını reddedecektir; devam etmeden önce bu denemelerin sonuçlanmasını bekleyin.', 'nicepay-payment-gateway' ), $pending ); ?></p></div>
    <?php endif;

Ayrıca sanitize_mode() içinde mod gerçekten değiştiyse add_settings_error() ile bilgilendirici bir mesaj bırakın ve nicepay_log( 'Operating mode changed', array( 'from'=>..., 'to'=>..., 'user_id'=>get_current_user_id() ), 'info' ) yazın (ADMIN-006 ile aynı denetim izi).

---

### ADMIN-014 — Kısayol üreticisi geçersiz bir ?edit= kimliğinde sessizce boş forma düşüyor ve var olmayan bir yapılandırmaya işaret eden kopyalanabilir kısayol üretiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | admin-ux |
| **Konum** | [admin/class-nicepay-admin.php:830](../../../admin/class-nicepay-admin.php#L830) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
$edit_id   = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
    $edit_data = $edit_id ? nicepay_get_saved_shortcode( $edit_id ) : null;   (:830-831)

$edit_data null olduğunda hiçbir hata mesajı, hiçbir yönlendirme yok. $edit_id yine de builder'a basılıyor:

    <div class="nicepay-sc-builder" data-edit-id="<?php echo esc_attr( $edit_id ); ?>">   (:866)

JS bunu doğruluyor kabul edip kısayolu üretiyor ve kopyalama butonunu etkinleştiriyor:

    return editId ? '[nicepay_payment id="' + editId + '"]' : text( config, 'referenceShortcode', ... );   (assets/js/nicepay-shortcode-admin.js:112)
    $('#sc-copy-btn').prop('disabled', messages.length > 0 || !editId);                                     (:169)

Ayrıca $edit_id, kaydetme yolundaki `^[A-Za-z0-9_-]+$` + 64 karakter kuralına (nicepay-payment-gateway.php:671-674) karşı hiç doğrulanmıyor — yalnızca sanitize_text_field'dan geçiyor.
```

**Başarısızlık senaryosu**

Satıcı Shortcodes sekmesinden 'cfg_a1b2c3d4e5f6a7b8' kayıtını siliyor, sonra tarayıcı geçmişinden eski düzenleme bağlantısına dönüyor: admin.php?page=nicepay-settings&tab=shortcode-generator&edit=cfg_a1b2c3d4e5f6a7b8. Boş bir form ve sağda '[nicepay_payment id="cfg_a1b2c3d4e5f6a7b8"]' görüyor, kopyalayıp bir sayfaya yapıştırıyor ve yayınlıyor. Sayfada hiçbir ödeme butonu görünmüyor; satıcı sorunun kaynağını bulamıyor.

**Etki**

Bir yapılandırma silindikten sonra elde kalmış bir 'Edit' bağlantısına tıklayan satıcı, boş bir form ve geçerli görünen ama hiçbir şeye çözülmeyen bir kısayol görüyor. Bu kısayolu bir sayfaya yapıştırırsa ön yüzde ödeme formu hiç render edilmez ve neden olduğu belli olmaz. Formu doldurup Kaydet'e bastığında ise ancak o zaman 'Shortcode not found.' hatası alıyor ve girdiği tüm veriler ekranda kalıyor ama kaydedilmiyor.

**Öneri**

Geçersiz/eksik kimliği açıkça ele alın:

    $edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
    if ( '' !== $edit_id && ( strlen( $edit_id ) > 64 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $edit_id ) ) ) {
        $edit_id = '';
    }
    $edit_data = '' !== $edit_id ? nicepay_get_saved_shortcode( $edit_id ) : null;
    if ( '' !== $edit_id && null === $edit_data ) {
        echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Düzenlemek istediğiniz ödeme yapılandırması bulunamadı; muhtemelen silinmiş. Aşağıdan yeni bir tane oluşturabilirsiniz.', 'nicepay-payment-gateway' ) . '</p></div>';
        $edit_id = '';   // yeni kayıt moduna düş
    }

Ayrıca kaydetme sırasında kullanıcı verisinin kaybolmaması için, 'Shortcode not found.' hatasında JS'in formu temizlememesi ve kullanıcıya 'yeni olarak kaydet' seçeneği sunması yerinde olur.

---

### ADMIN-015 — CSV'de UTF-8 BOM yok ve başlıklar çevrilmemiş; bugün yalnızca tesadüfen (hiçbir sütunun ASCII dışı olmaması sayesinde) güvenli

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | i18n |
| **Konum** | [admin/class-nicepay-transactions.php:151](../../../admin/class-nicepay-transactions.php#L151) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
header( 'Content-Type: text/csv; charset=UTF-8' );   (:260)
    ...
    $headers = array( 'ID', 'TID', 'WooCommerce Order ID', ... );   (:146-150)
    fputcsv( $stream, $headers );                                    (:151)

Akışa hiçbir BOM ("\xEF\xBB\xBF") yazılmıyor. Excel, BOM'suz bir CSV'yi varsayılan olarak sistem kod sayfasıyla açar, UTF-8 ile değil. Bugün bu sorun görünmüyor çünkü export sütun allowlist'i (:141-145) yalnızca ASCII üretebilecek alanlardan oluşuyor: id, tid, wc_order_id, moid, flow, currency ve tutar/tarih/kod alanları. Ekranda aranabilen buyer_name ve goods_name (FILTER_SQL, :21) kasıtlı olarak export dışında bırakılmış.

Ayrıca başlıklar sabit İngilizce; arayüzün geri kalanı tamamen çevrilmiş olmasına rağmen indirilen dosya değil.
```

**Başarısızlık senaryosu**

Bir sonraki sürümde CSV'ye 'Buyer Name' ve 'Product' sütunları ekleniyor (mutabakat için makul bir istek, ADMIN-011'de de gündeme geliyor). Korece bir müşteri adı '김민준' Excel'de '媛��쇱��' gibi görünüyor. Satıcı verinin bozuk kaydedildiğini sanıp veri tabanı sorunu bildiriyor; oysa dosya doğru, yalnızca BOM eksik.

**Etki**

Kırılganlık: export'a ileride buyer_name, goods_name, pay_method_name, result_msg veya çevrilmiş status etiketi gibi tek bir alan eklendiği anda, Korece/Türkçe metinler Excel'de sessizce bozuk karakterlere dönüşür ve bunu fark etmek zordur. Bugünkü haliyle de Korece konuşan bir satıcı için başlıkların İngilizce olması tutarsız bir deneyim.

**Öneri**

BOM'u şimdiden yazın (mevcut ASCII içerikte hiçbir zararı yok, gelecekteki her eklemeyi korur):

    $stream = fopen( 'php://output', 'w' );
    fwrite( $stream, "\xEF\xBB\xBF" );

Başlıklar için makine-okunur kararlılık ile yerelleştirme arasında bilinçli bir seçim yapın: ya başlıkları __() ile çevirip belgede 'başlıklar site diline göre değişir' notu düşün, ya da mevcut İngilizce başlıkları koruyup export butonunun yanında bunu açıkça belirtin. Hangi seçim yapılırsa yapılsın, ADMIN-001'deki zaman dilimi etiketlemesiyle birlikte ele alın.

---

### ADMIN-016 — NicePay_Transactions iki kez örnekleniyor; AJAX/admin_post kancaları her işlem sayfası render'ında yeniden kaydediliyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | maintainability |
| **Konum** | [admin/class-nicepay-transactions.php:1030](../../../admin/class-nicepay-transactions.php#L1030) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```php
Dosya sonunda bir singleton kuruluyor:

    new NicePay_Transactions();   (:1030)

ve yapıcı kancaları kaydediyor:

    public function __construct() {
        add_action( 'wp_ajax_nicepay_cancel_transaction', array( $this, 'ajax_cancel_transaction' ) );
        add_action( 'admin_post_nicepay_export_transactions', array( $this, 'handle_csv_export' ) );
    }   (:23-26)

Ama sayfa render'ı yeni bir örnek daha kuruyor:

    public function render_transactions_page() {
        $transactions_page = new NicePay_Transactions();
        $transactions_page->render();
    }   (admin/class-nicepay-admin.php:1114-1117)

WordPress farklı nesne örneklerini farklı callback kimlikleri sayar, dolayısıyla her iki callback de ikinci kez kaydedilir. Bugün zararsız çünkü admin_post ve wp_ajax dispatch'i sayfa render'ından önce gerçekleşir ve handle_csv_export() exit ile biter.
```

**Başarısızlık senaryosu**

Bir sonraki sürümde yapıcıya `add_action( 'admin_notices', array( $this, 'export_result_notice' ) )` ekleniyor. İşlem sayfasında uyarı iki kez basılıyor. Geliştirici sebebini bulmak için admin_notices'e kayıtlı tüm eklentileri araştırıyor; kök neden ikinci `new NicePay_Transactions()` çağrısı.

**Etki**

Gizli bir çift-çalıştırma tuzağı. İleride yapıcıya idempotent olmayan bir kanca eklenirse (örn. admin_notices, admin_init, veya sayaç artıran bir işlem) render sırasında iki kez çalışır ve teşhisi zor bir hataya dönüşür. Ayrıca her sayfa render'ında gereksiz bir nesne + iki filtre kaydı yapılıyor.

**Öneri**

Kanca kaydını örneklemeden ayırın veya tekil örnek kullanın:

    class NicePay_Transactions {
        private static $instance = null;
        public static function instance() {
            if ( null === self::$instance ) { self::$instance = new self(); }
            return self::$instance;
        }
        ...
    }
    NicePay_Transactions::instance();   // dosya sonunda

ve admin tarafında:

    public function render_transactions_page() {
        NicePay_Transactions::instance()->render();
    }

Alternatif olarak render()'ı statik yapın; zaten örnek durumu kullanmıyor.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İncelenen dosyalar (tamamı baştan sona okundu): admin/class-nicepay-admin.php (1199 satır), admin/class-nicepay-transactions.php (1030 satır), assets/js/nicepay-admin.js (380), assets/js/nicepay-shortcode-admin.js (312), tests/js/nicepay-admin.test.js, tests/js/nicepay-shortcode-admin.test.js, tests/unit/NicePayTransactionsAdminTest.php (tamamı) ve tests/unit/NicePayAdminOperationsTest.php (test adları + kapsam eşlemesi). Destekleyici kanıt için ayrıca okundu: includes/nicepay-functions.php (nicepay_get_transactions, nicepay_get_reconciliation_count, nicepay_get_refund_attempts_for_transactions, nicepay_format_amount, nicepay_get_status_label, nicepay_current_user_can_manage_payments, nicepay_get_configuration_warnings, nicepay_get_approval_error_audit, nicepay_abort_authenticated_payment, expiry cron), includes/class-nicepay-transaction-schema.php (sütunlar/indeksler/prepare_write), includes/class-nicepay-inbound-validator.php (MID bağlama), includes/class-nicepay-gateway.php (iade + mutabakat yazımları), includes/class-nicepay-return-handler.php (mutabakat yazımları), includes/class-nicepay-retention.php (cutoff SQL), includes/nicepay-icons.php, nicepay-payment-gateway.php (kanca kaydı, admin_notices, kısayol AJAX'ları). Her satır numarası dosyaların şu anki hali (branch: development) ile doğrulandı. İNCELENEMEYEN / DOĞRULANAMAYAN: (1) Testler çalıştırılmadı — PHPUnit/Node bağımlılıklarını kurmadım, bulguların hiçbiri test çıktısına değil kod okumasına dayanıyor. (2) Gerçek bir MySQL örneğinde CURRENT_TIMESTAMP'in hangi zaman diliminde yazdığını deneysel olarak doğrulayamadım; ADMIN-001'in dayanağı şema tanımı + prepare_write'ın created_at'i database_owned sayması + get_date_from_gmt kullanımı arasındaki sözleşme çelişkisidir ve bu çelişki zaman diliminden bağımsız olarak (site TZ ile UTC arasında) zaten mevcuttur. (3) MySQL optimizer'ın FILTER_SQL içindeki `(%s = '' OR col = %s)` kalıbını sabit-katlayıp indeks kullanıp kullanmadığını EXPLAIN ile ölçemedim; bu nedenle bunu bulgu olarak raporlamadım, yalnızca ADMIN-008'de indekssiz OR taramasını raporladım. (4) assets/css/nicepay-admin.css içeriğini tam okumadım (yalnızca .nicepay-order-payment-summary için grepledim, eşleşme yok); görsel/CSS kalitesi bu raporun kapsamı dışında bırakıldı. (5) Çok siteli (multisite) ağ yönetici ekranı davranışı test edilmedi.

**Açık sorular**

- created_at/updated_at'in DEFAULT CURRENT_TIMESTAMP ile DB'ye bırakılması bilinçli bir karar mı? Eğer öyleyse hedef sözleşme nedir — MySQL sunucu saati mi UTC mi? Bu, ADMIN-001'in düzeltme yönünü tamamen belirliyor (ya PHP tarafında gmdate ile yazmak, ya da okuma tarafındaki get_date_from_gmt varsayımını kaldırmak).
- needs_reconciliation kaydını çözmek için bilinçli olarak arayüz sunulmadı mı (ör. 'yalnızca destek/SQL ile' politikası), yoksa gözden mi kaçtı? Eğer politikaysa bunun docs/ ve ekran metninde açıkça yazılması gerekir; şu anki metin ('requires manual reconciliation before retrying') satıcıya panelde bir eylem olduğunu ima ediyor.
- CSV'den buyer_name/goods_name'in çıkarılması gizlilik gerekçesiyle mi yapıldı? Öyleyse ekrandaki arama filtresinin bu alanlarda çalışması (FILTER_SQL:21) tutarsız bir beklenti yaratıyor — kullanıcı 'aradığım satırlar CSV'de olacak' varsayıyor. Bu kararın bir yerde belgelenmesi gerekir.
- CSV_MAX_ROWS = 10000 sınırı hangi ölçüme dayanıyor (bellek, PHP max_execution_time, hosting)? Cursor tabanlı akış zaten bellek dostu olduğuna göre sınır set_time_limit(0) + periyodik flush ile daha yükseğe çekilebilir mi, yoksa sabit bir ürün kararı mı?
- nicepay_manage_transactions_capability() filtresiyle işlemler ekranı manage_woocommerce'e açılırken Settings alt menüsü manage_options'ta bırakılmış. Shop manager rolünün mod/kimlik bilgisi uyarılarını görmesi isteniyor mu (ADMIN-003), yoksa bu bilinçli bir yetki ayrımı mı?
- İade ekranından kısmi iade sunulmaması bilinçli bir risk azaltma kararı mı (operatörü WooCommerce sipariş ekranına yönlendirmek), yoksa eksik özellik mi? Eğer bilinçliyse buton metni ve modal, operatörü açıkça oraya yönlendirmeli.

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 16 |

## Öncelikli aksiyon listesi

1. **ADMIN-001** — Tek bir zaman dilimi sözleşmesi belirleyin ve her yerde uygulayın. Önerilen: created_at/updated_at'i açıkça UTC olarak PHP tarafından yazın (schema'da DEFAULT CURRENT_TIMESTAMP yerine prepare_write üzerinden gmdate('Y-m-d H:i:s')), sonra filtreyi site saatinden UTC'ye çevirin:

    $from_utc = get_gmt_from_date( $from . ' 00:00:00', 'Y-m-d H:i:s' );
    $to_utc   = get_gmt_from_date( $to . ' 23:59
2. **ADMIN-002** — 1) Yetenek + nonce korumalı bir 'Mutabakatı çözüldü olarak işaretle' eylemi ekleyin (admin_post veya wp_ajax). Eylem operatörden serbest metin bir çözüm notu istesin, reconciliation_status='not_required' yapsın, ayrı bir reconciled_by/reconciled_at/reconciliation_resolution alanına kim-ne zaman-neden yazsın ve ilgili WooCommerce siparişine sipariş notu düşsün. Para durumunu (status) DEĞİŞTİRMESİN 
